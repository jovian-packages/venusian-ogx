<?php

namespace Jovian\Engines\OpenGL;

use Surface\Contracts\Graphics\StrokeStyle;
use Surface\Contracts\Rendering\Framebuffers\Region;
use Surface\Contracts\Rendering\Rasterization\Edges;
use Surface\Contracts\Rendering\Rasterization\FillRule;
use Surface\Graphics\GEFramebuffer;
use Surface\Graphics\GERasterizer;
use Surface\NutsAndBolts\Color;
use Surface\NutsAndBolts\Path;
use Surface\Rendering\Rasterization\Stroker;

/**
 * The opengl engine's rasterizer: a path is filled on the GPU in two steps. Its contours are drawn as
 * triangle fans that only mark the stencil (counting up and down for non-zero, flipping for even-odd),
 * then one rectangle over the path's box is coloured where the stencil is marked and blended over what
 * is there. Overlaps and holes come out of the stencil, so the path needs no tessellation.
 *
 * Antialiased edges come from four samples a pixel; hard edges from the pixel's centre.
 */
class OpenGLRasterizer extends GERasterizer
{
    public function __construct(
        protected readonly OpenGLContext $device,
        protected readonly Edges $edges,
    ) {
        parent::__construct($device);
    }

    public function edges(): Edges
    {
        return $this->edges;
    }

    protected function fillPath(GEFramebuffer $onto, Path $path, Color $color, FillRule $rule, Region $clip): void
    {
        $this->paint($onto, array_column($path->flatten(), 'points'), $color, $rule, $clip);
    }

    /** A stroke is filled as its outline, wound non-zero: the outline comes from Rendering's Stroker. */
    protected function strokePath(GEFramebuffer $onto, Path $path, Color $color, StrokeStyle $style, Region $clip): void
    {
        $contours = [];
        foreach ($path->flatten() as ['points' => $points, 'closed' => $closed]) {
            $pieces = $style->dash === []
                ? [['points' => $points, 'closed' => $closed, 'direction' => null]]
                : Stroker::dashed($points, $closed, $style->dash, $style->dashOffset);
            foreach ($pieces as $piece) {
                foreach (Stroker::outline($piece['points'], $style->width, $piece['closed'], $style->cap, $style->join, $style->miterLimit, $piece['direction']) as $flat) {
                    $contours[] = array_chunk($flat, 2);
                }
            }
        }
        $this->paint($onto, $contours, $color, FillRule::NON_ZERO, $clip);
    }

    /** @param list<list<array{float, float}>> $contours */
    protected function paint(GEFramebuffer $onto, array $contours, Color $color, FillRule $rule, Region $clip): void
    {
        // the fans, then the covering rectangle, as one list of points
        $points = [];
        [$left, $top, $right, $bottom] = [INF, INF, -INF, -INF];
        foreach ($contours as $contour) {
            $count = count($contour);
            if ($count < 3) {
                continue;
            }
            for ($i = 1; $i < $count - 1; $i++) {
                array_push($points, ...$contour[0], ...$contour[$i], ...$contour[$i + 1]);
            }
            foreach ($contour as [$x, $y]) {
                [$left, $top, $right, $bottom] = [min($left, $x), min($top, $y), max($right, $x), max($bottom, $y)];
            }
        }
        if ($points === []) {
            return;
        }
        $fan = intdiv(count($points), 2);
        array_push($points, $left, $top, $right, $top, $right, $bottom, $left, $top, $right, $bottom, $left, $bottom);

        // the draw covers the shape's box inside the clip and no more
        $area = $clip->intersect(new Region((int) floor($left) - 1, (int) floor($top) - 1, (int) ceil($right) - (int) floor($left) + 2, (int) ceil($bottom) - (int) floor($top) + 2));
        if (is_null($area) || $area->isEmpty()) {
            return;
        }

        /** @var OpenGLFramebuffer $onto the base checked it is this engine's */
        $antialiased = $this->edges === Edges::ANTIALIASED;
        $onto->begin($antialiased, $area);
        $rgba = pack('V4', (int) round($color->red * 255), (int) round($color->green * 255), (int) round($color->blue * 255), (int) round($color->alpha * 255));
        [$frame, $paint] = $this->device->blocks([$onto->frameBlock(), $rgba]);
        $this->device->block(OpenGLContext::FRAME, $frame, 16);
        $this->device->block(OpenGLContext::PAINT, $paint, 16);
        $this->device->vertices(pack('g*', ...$points));
        glUseProgram($this->device->program('place.vert', 'solid.frag'));
        glEnable(GL_BLEND);
        glBlendFunc(GL_ONE, GL_ONE_MINUS_SRC_ALPHA);

        // one convex contour: its fan covers it once, so it is drawn in one step, with no stencil
        if (count($contours) === 1 && OpenGLContext::convex($contours[0])) {
            glDrawArrays(GL_TRIANGLES, 0, $fan);
            $onto->end($antialiased, $area);

            return;
        }

        // the stencil (begin() cleared it over the area), marked by the fans with no colour written
        glEnable(GL_STENCIL_TEST);
        glStencilMask(0xFF);
        glColorMask(false, false, false, false);
        glStencilFunc(GL_ALWAYS, 0, 0xFF);
        if ($rule === FillRule::EVEN_ODD) {
            glStencilOp(GL_KEEP, GL_KEEP, GL_INVERT);
        } else {
            glStencilOpSeparate(GL_FRONT, GL_KEEP, GL_KEEP, GL_INCR_WRAP);
            glStencilOpSeparate(GL_BACK, GL_KEEP, GL_KEEP, GL_DECR_WRAP);
        }
        glDrawArrays(GL_TRIANGLES, 0, $fan);

        // the cover: coloured and blended where the stencil is marked
        glColorMask(true, true, true, true);
        glStencilFunc(GL_NOTEQUAL, 0, 0xFF);
        glStencilOp(GL_KEEP, GL_KEEP, GL_KEEP);
        glDrawArrays(GL_TRIANGLES, $fan, 6);

        $onto->end($antialiased, $area);
    }
}
