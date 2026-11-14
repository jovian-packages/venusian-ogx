<?php

namespace Jovian\Engines\OpenGL;

use Surface\Contracts\Graphics\DrawingException;
use Surface\Contracts\Graphics\Effects\Glass;
use Surface\Contracts\Rendering\Framebuffers\Filter;
use Surface\Contracts\Rendering\Framebuffers\Region;
use Surface\Contracts\Rendering\Painting\PaintTarget;
use Surface\Contracts\Rendering\Rasterization\Edges;
use Surface\Contracts\Rendering\Rasterization\FillRule;
use Surface\Contracts\Rendering\Recording\Recorder;
use Surface\NutsAndBolts\GradientSpread;
use Surface\Rendering\Painters\GlassGroups;
use Surface\Rendering\Painters\Painter;
use Surface\Rendering\Rasterization\Stroker;

/**
 * The opengl engine's painter. Recording, frame comparison and damage are the shared Painter's; this
 * class carries out a frame's commands on the GPU: every vertex of a pass in one upload, every block of
 * a pass in another, then one draw (or two, stencil and cover) a command. Nothing waits for the GPU.
 *
 * Each command's clip is a scissor rect. Shapes with curved edges (ellipses, rings, rounded boxes) are
 * one rectangle each, with a fragment that runs once a sample and decides whether that sample is inside;
 * paths and polylines are stencilled, then covered. Shadows are worked out once a pixel.
 *
 * A gradient fill is a path's cover drawn through the gradient's 256-colour ramp, kept as a 256 x 1
 * texture by its bytes, so a gradient drawn every frame is uploaded once. An image is another of the
 * engine's framebuffers, drawn as two triangles that read its texture.
 *
 * A layer's contents are drawn first, in a pass of their own, into a sheet the size of the layer
 * (sizes rounded up to 64, so a layer that changes size a little keeps its sheet); the layer is then one
 * rectangle that reads the sheet, inside its rounded box, at its opacity. A frame redrawn in several
 * regions draws a layer's contents once and reads them in each. Sheets are kept from frame to frame; one
 * drawn in a single pass holds its texture alone.
 *
 * A layer's filter runs over its contents on the GPU (OpenGLFilters), and its backdrop over what is
 * under it so far. A glass pane reads what is drawn under it, taken down a Kawase pyramid shared by the
 * panes of its group, as Velvet's painter builds it. A command that reads what is drawn under it ends
 * the pass there; the next pass loads what it left.
 */
class OpenGLPainter extends Painter
{
    /** How many gradient ramps are kept as textures between frames. */
    protected const int RAMPS = 32;

    /** Sheet sizes are rounded up to this. */
    protected const int STEP = 64;

    /** @var array<string, int> ramp textures by the ramp's bytes, the oldest first */
    protected array $ramps = [];

    /** @var array<string, list<OpenGLFramebuffer>> what layers are drawn in, by their rounded size, kept from frame to frame */
    protected array $sheets = [];

    /** @var array<string, int> how many sheets of each size this frame has taken */
    protected array $lent = [];

    /** @var list<array{array<int, mixed>, int}> the layers this frame drew, and the texture that holds each one's contents */
    protected array $layers = [];

    /** How many layers' contents the last frame drew, and how many it took from a copy drawn for another region. */
    public array $layerCounts = ['drawn' => 0, 'reused' => 0];

    /** How many glass pyramids the last frame built. */
    public int $pyramidsBuilt = 0;

    protected OpenGLFilters $filters;

    public function __construct(
        protected readonly OpenGLContext $device,
        protected readonly OpenGLFramebuffer $target,
        protected readonly Edges $edges = Edges::ANTIALIASED,
        ?Recorder $recorder = null,
    ) {
        if (! is_null($recorder)) {
            $this->recordWith($recorder);
        }
        $this->filters = new OpenGLFilters($device);
    }

    public function __destruct()
    {
        if ($this->ramps !== [] && $this->device->isOpen()) {
            $this->device->current();
            glDeleteTextures(count($this->ramps), array_values($this->ramps));
        }
        $this->ramps = [];
    }

    public function name(): string
    {
        return 'opengl';
    }

    public function framebuffer(): PaintTarget
    {
        return $this->target;
    }

    /**
     * @param list<array<int, mixed>> $commands
     *
     * @throws DrawingException When a command is one this painter does not know.
     */
    protected function execute(array $commands): void
    {
        [$this->lent, $this->layers, $this->layerCounts, $this->pyramidsBuilt] = [[], [], ['drawn' => 0, 'reused' => 0], 0];
        $this->run($commands, $this->target);

        // a sheet no layer of this frame was drawn in goes: GL keeps it until what was queued has read it
        foreach ($this->sheets as $size => $sheets) {
            foreach (array_slice($sheets, $this->lent[$size] ?? 0) as $sheet) {
                $sheet->free();
            }
            $this->sheets[$size] = array_slice($sheets, 0, $this->lent[$size] ?? 0);
        }
        $this->sheets = array_filter($this->sheets);
        $this->filters->release();
    }

    /**
     * Carries out $commands as passes over $onto, after the passes of the layers among them. A pass ends
     * where a command reads what is drawn so far, and the next begins after it.
     *
     * @param list<array<int, mixed>> $commands
     * @param int $ox where on the surface $onto starts: 0 for the framebuffer itself, a layer's corner for its sheet
     * @param bool $clear whether $onto starts from transparent black, as a sheet does
     *
     * @throws DrawingException
     */
    protected function run(array $commands, OpenGLFramebuffer $onto, int $ox = 0, int $oy = 0, bool $clear = false): void
    {
        $antialiased = $this->edges === Edges::ANTIALIASED;
        $whole = new Region(0, 0, $this->target->width(), $this->target->height());

        // every command's vertices in one list, and its draws: [fragment, stencil, first, count, paint bytes, clip, blend, textures to read]
        $floats = [];
        $draws = [];
        $vertices = 0;
        $add = function (array $points) use (&$floats, &$vertices): array {
            array_push($floats, ...$points);
            $start = $vertices;
            $vertices += intdiv(count($points), 2);

            return [$start, $vertices - $start];
        };
        $box = fn (float $x0, float $y0, float $x1, float $y1): array => $add([$x0, $y0, $x1, $y0, $x1, $y1, $x0, $y0, $x1, $y1, $x0, $y1]);
        $first = true;
        $flush = function () use (&$floats, &$draws, &$vertices, &$first, $onto, $antialiased, $ox, $oy, $clear): void {
            if ($draws === [] && ! ($first && $clear)) {
                return;
            }
            $this->pass($onto, $antialiased, $floats, $draws, $ox, $oy, $first && $clear);
            [$floats, $draws, $vertices, $first] = [[], [], 0, false];
        };

        // glass panes that can share a capture, and which group each pane is in
        $groups = GlassGroups::of($commands, $this->surface(), $this->commandBox(...));
        $groupOf = [];
        foreach ($groups as $g => $group) {
            foreach ($group['members'] as $member) {
                $groupOf[$member] = $g;
            }
        }
        $built = [];

        foreach ($commands as $index => $command) {
            $kind = $command[0];
            switch ($kind) {
                case 'clear':
                    $draws[] = ['solid', null, ...$box(0, 0, $whole->width, $whole->height), self::paint($command[1]), $command[2] ?? $whole, false, []];
                    break;
                case 'path':
                    [, $contours, $rule, $rgba, $clip] = $command;
                    $this->stencilled($draws, $add, $contours, $rule, $rgba, $clip);
                    break;
                case 'polyline':
                    [, $points, $stroke, $closed, $rgba, $clip] = $command;
                    $outline = array_map(fn (array $flat): array => array_chunk($flat, 2), Stroker::outline($points, $stroke, $closed));
                    $this->stencilled($draws, $add, $outline, FillRule::NON_ZERO, $rgba, $clip);
                    break;
                case 'ellipse':
                case 'ring':
                    [$cx, $cy, $rx, $ry] = array_slice($command, 1, 4);
                    [$stroke, $rgba, $clip] = $kind === 'ring' ? array_slice($command, 5, 3) : [0.0, $command[5], $command[6]];
                    $reach = $stroke / 2 + 1;
                    $oval = pack('V4g4g4', ...self::bytes($rgba), ...[$cx, $cy, $rx, $ry, $stroke, $kind === 'ring' ? 1.0 : 0.0, 0.0, 0.0]);
                    $draws[] = ['oval', null, ...$box($cx - $rx - $reach, $cy - $ry - $reach, $cx + $rx + $reach, $cy + $ry + $reach), $oval, $clip, true, []];
                    break;
                case 'roundrect':
                case 'roundring':
                    [$x0, $y0, $x1, $y1, $radii] = array_slice($command, 1, 5);
                    [$inset, $rgba, $clip] = $kind === 'roundring' ? array_slice($command, 6, 3) : [0.0, $command[6], $command[7]];
                    $round = pack('V4g4g4g4', ...self::bytes($rgba), ...[$x0, $y0, $x1, $y1], ...$radii, ...[$inset, $kind === 'roundring' ? 1.0 : 0.0, 0.0, 0.0]);
                    $draws[] = ['roundbox', null, ...$box($x0, $y0, $x1, $y1), $round, $clip, true, []];
                    break;
                case 'shadow':
                    [, $x0, $y0, $x1, $y1, $radius, $blur, $rgba, $clip] = $command;
                    $shade = pack('V4g4g4g4', ...self::bytes($rgba), ...[$x0, $y0, $x1, $y1], ...[0.0, 0.0, 0.0, 0.0], ...[0.0, 0.0, $blur, $radius]);
                    $draws[] = ['shade', null, ...$box($x0 - $blur - 1, $y0 - $blur - 1, $x1 + $blur + 1, $y1 + $blur + 1), $shade, $clip, true, []];
                    break;
                case 'spans':
                    // whole pixels: a rectangle a span
                    [, $bytes, $rgba, $clip] = $command;
                    [$start, $count] = [$vertices, 0];
                    for ($at = 0, $end = strlen($bytes); $at < $end; $at += 7) {
                        ['y' => $y, 'x' => $x, 'length' => $length] = unpack('vy/vx/vlength', $bytes, $at);
                        $count += $box($x, $y, $x + $length, $y + 1)[1];
                    }
                    if ($count > 0) {
                        $draws[] = ['solid', null, $start, $count, self::paint($rgba), $clip, true, []];
                    }
                    break;
                case 'gradpath':
                    [, $contours, $rule, $gradient, $clip] = $command;
                    $inverse = $gradient['inverse'];
                    $uniform = pack('g8', $inverse->a, $inverse->b, $inverse->c, $inverse->d, $inverse->e, $inverse->f, 0.0, 0.0)
                        .pack('V4', $gradient['kind'] === 'radial' ? 1 : 0, match ($gradient['spread']) {
                            GradientSpread::PAD => 0, GradientSpread::REPEAT => 1, GradientSpread::REFLECT => 2,
                        }, 0, 0);
                    $this->stencilled($draws, $add, $contours, $rule, 0, $clip, ['gradient', $uniform, [$this->ramp($gradient['ramp'])]]);
                    break;
                case 'image':
                    [, $source, $placement, $opacity, $filter, $style, $clip] = $command;
                    if (! $source instanceof OpenGLFramebuffer || $source->engine() !== $this->device) {
                        throw new DrawingException('The opengl painter draws an image from one of its own engine\'s framebuffers, got '.get_debug_type($source).'.');
                    }
                    if ($source === $this->target) {
                        throw new DrawingException('The opengl painter cannot draw a framebuffer onto itself.');
                    }
                    $inverse = $placement->inverse();
                    if (is_null($inverse)) {
                        break;
                    }
                    [$sw, $sh] = [(float) $source->width(), (float) $source->height()];
                    [$tint, $window, $round] = [$style['tint'] ?? 0xFFFFFFFF, $style['window'] ?? null, $style['round'] ?? null];
                    $uniform = pack('g8', $inverse->a, $inverse->b, $inverse->c, $inverse->d, $inverse->e, $inverse->f, $sw, $sh)
                        .pack('V4', $opacity, $filter === Filter::LINEAR ? 1 : 0, $tint & 0xFFFFFFFF, (is_null($round) ? 0 : 1) | (is_null($window) ? 0 : 2))
                        .pack('g4', ...($window ?? [0.0, 0.0, 0.0, 0.0]))
                        .pack('g8', ...(is_null($round) ? [0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0] : [$round[0], $round[1], $round[2], $round[3], ...$round[4]]));
                    // the source's four corners where the placement puts them: two triangles
                    [$a, $b, $c, $d] = [$placement->apply(0.0, 0.0), $placement->apply($sw, 0.0), $placement->apply($sw, $sh), $placement->apply(0.0, $sh)];
                    $draws[] = ['image', null, ...$add([...$a, ...$b, ...$c, ...$a, ...$c, ...$d]), $uniform, $clip, true, [$source->textureName()]];
                    break;
                case 'layer':
                    [, $area, $geometry, $children, $alpha, $filter, $backdrop, $clip] = $command;
                    if ($area->isEmpty()) {
                        break;
                    }
                    [$x0, $y0, $x1, $y1, $radii] = $geometry;
                    if (! is_null($backdrop)) {
                        // what is under the layer so far, filtered, laid down inside its box
                        $flush();
                        $outer = self::grown($area, $backdrop->reach(), $this->surface());
                        $under = $this->filters->grab($onto->textureName(), $onto->width(), $onto->height(), $outer->x - $ox, $outer->y - $oy, $outer->width, $outer->height, false);
                        $under = $this->filters->run($backdrop, $under, $outer->width, $outer->height, $outer->x, $outer->y);
                        $draws[] = ['sheet', null, ...$box($x0, $y0, $x1, $y1), pack('g14', $x0, $y0, $x1, $y1, ...$radii, ...[(float) $outer->x, (float) $outer->y, 1.0, 0.0, (float) $outer->width, (float) $outer->height]), $clip, true, [$under]];
                    }
                    // its contents, in a sheet of their own, drawn before this pass. A copy of a layer already drawn
                    // this frame (the same children, area and filter, another region) takes its contents; contents that
                    // read what is under them (glass, a backdrop) depend on the region drawn first, so they are never taken.
                    $reads = self::reads($children);
                    $same = [$area->x, $area->y, $area->width, $area->height, $children, $filter];
                    $texture = null;
                    if (! $reads) {
                        foreach ($this->layers as [$drawn, $held]) {
                            if ($drawn === $same) {
                                [$texture, $this->layerCounts['reused']] = [$held, $this->layerCounts['reused'] + 1];
                                break;
                            }
                        }
                    }
                    if (is_null($texture)) {
                        // a sheet whose contents read what is under them is drawn in more than one pass: it keeps its samples
                        $sheet = $this->sheet($area->width, $area->height, transient: ! $reads);
                        $this->run($children, $sheet, $area->x, $area->y, true);
                        $texture = $sheet->textureName();
                        if (! is_null($filter)) {
                            $texture = $this->filters->run($filter, $texture, $area->width, $area->height, $area->x, $area->y);
                        }
                        $this->layerCounts['drawn']++;
                        if (! $reads) {
                            $this->layers[] = [$same, $texture];
                        }
                    }
                    $draws[] = ['sheet', null, ...$box($x0, $y0, $x1, $y1), pack('g14', $x0, $y0, $x1, $y1, ...$radii, ...[(float) $area->x, (float) $area->y, $alpha / 255, 0.0, (float) $area->width, (float) $area->height]), $clip, true, [$texture]];
                    break;
                case 'glass':
                    [, $area, $geometry, $look, $blur, $shadow, $clip] = $command;
                    $g = $groupOf[$index];
                    if (! isset($built[$g])) {
                        $flush();
                        $built[$g] = $this->pyramid($groups[$g], $onto, $ox, $oy);
                    }
                    $panes = $built[$g];
                    if (! is_null($shadow)) {
                        // the shadow, after the backdrop was read and before the pane
                        [, $sx0, $sy0, $sx1, $sy1, $radius, $spread, $rgba, $shadowClip] = $shadow;
                        $shade = pack('V4g4g4g4', ...self::bytes($rgba), ...[$sx0, $sy0, $sx1, $sy1], ...[0.0, 0.0, 0.0, 0.0], ...[0.0, 0.0, $spread, $radius]);
                        $draws[] = ['shade', null, ...$box($sx0 - $spread - 1, $sy0 - $spread - 1, $sx1 + $spread + 1, $sy1 + $spread + 1), $shade, $shadowClip, true, []];
                    }
                    [$x0, $y0, $x1, $y1, $radii] = $geometry;
                    [$frost, $refraction, $bevel, $saturation, $light, $grain, $tint, $rim, $paneAlpha] = $look;
                    [$level, $levelWidth, $levelHeight] = $panes['samples'][Glass::levels($blur)];
                    [$sharp, $sharpWidth, $sharpHeight] = $panes['sharp'];
                    $uniform = pack('g4', $x0, $y0, $x1, $y1).pack('g4', ...$radii)
                        .pack('g4', $frost, $refraction, $bevel, $saturation).pack('g4', $light, $grain, $paneAlpha, 2.0)
                        .pack('V4', $tint & 0xFFFFFFFF, $rim & 0xFFFFFFFF, $frost < 1.0 ? 1 : 0, 0)
                        .pack('l4', $levelWidth, $levelHeight, $sharpWidth, $sharpHeight)
                        .pack('g2', (float) $panes['region']->x, (float) $panes['region']->y);
                    $draws[] = ['pane', null, ...$box(floor($x0), floor($y0), ceil($x1), ceil($y1)), $uniform, $clip, true, [$level, $sharp]];
                    break;
                default:
                    throw new DrawingException("The opengl painter does not know the command '{$kind}'.");
            }
        }
        $flush();
    }

    /**
     * One pass over $onto: its vertices and blocks uploaded once, then each draw with its scissor, program,
     * stencil, blend and textures. Resolves what the draws covered at its end.
     *
     * @param list<float> $floats
     * @param list<array{string, ?string, int, int, string, Region, bool, list<int>}> $draws
     */
    protected function pass(OpenGLFramebuffer $onto, bool $antialiased, array $floats, array $draws, int $ox, int $oy, bool $clear): void
    {
        $device = $this->device;
        $bounds = new Region($ox, $oy, $onto->width(), $onto->height());

        // what the pass covers, in $onto's pixels: the union of its draws' clips, or all of it when it starts from black
        $scissors = [];
        $covered = null;
        foreach ($draws as $i => $draw) {
            $clip = $draw[5]->intersect($bounds);
            if (is_null($clip) || $clip->isEmpty() || $draw[3] === 0) {
                continue;
            }
            $scissors[$i] = new Region($clip->x - $ox, $clip->y - $oy, $clip->width, $clip->height);
            $covered = is_null($covered) ? $scissors[$i] : $covered->union($scissors[$i]);
        }
        if ($clear) {
            $covered = new Region(0, 0, $onto->width(), $onto->height());
        }
        if (is_null($covered)) {
            return;
        }

        $onto->begin($antialiased, $covered, $clear);
        $blocks = [$onto->frameBlock($ox, $oy)];
        foreach ($draws as $draw) {
            $blocks[] = $draw[4] === '' ? str_repeat("\0", 16) : $draw[4];
        }
        $offsets = $device->blocks($blocks);
        $device->block(OpenGLContext::FRAME, $offsets[0], 16);
        $device->vertices(pack('g*', ...($floats ?: [0.0, 0.0])));
        glEnable(GL_BLEND);
        glBlendFunc(GL_ONE, GL_ONE_MINUS_SRC_ALPHA);
        $blending = true;

        foreach ($draws as $i => [$fragment, $stencil, $start, $count, $paint, , $blend, $textures]) {
            if (! isset($scissors[$i])) {
                continue;
            }
            $scissor = $scissors[$i];
            glScissor($scissor->x, $scissor->y, $scissor->width, $scissor->height);
            glUseProgram($device->program('place.vert', "{$fragment}.frag"));
            foreach ($textures as $unit => $texture) {
                glActiveTexture(GL_TEXTURE0 + $unit);
                glBindTexture(GL_TEXTURE_2D, $texture);
            }
            $device->block(OpenGLContext::PAINT, $offsets[$i + 1], OpenGLContext::size($blocks[$i + 1]));
            if ($blend !== $blending) {
                $blend ? glEnable(GL_BLEND) : glDisable(GL_BLEND);
                $blending = $blend;
            }
            match ($stencil) {
                null => glDisable(GL_STENCIL_TEST),
                'cover' => self::cover(),
                default => self::mark($stencil === 'invert'),
            };
            glDrawArrays(GL_TRIANGLES, $start, $count);
            if (! is_null($stencil) && $stencil !== 'cover') {
                glColorMask(true, true, true, true);
            }
        }
        $onto->end($antialiased, $covered);
    }

    /** The stencil marked by a path's fans, no colour written: flipped for even-odd, counted up and down for non-zero. */
    protected static function mark(bool $invert): void
    {
        glEnable(GL_STENCIL_TEST);
        glStencilMask(0xFF);
        glColorMask(false, false, false, false);
        glStencilFunc(GL_ALWAYS, 0, 0xFF);
        if ($invert) {
            glStencilOp(GL_KEEP, GL_KEEP, GL_INVERT);
        } else {
            glStencilOpSeparate(GL_FRONT, GL_KEEP, GL_KEEP, GL_INCR_WRAP);
            glStencilOpSeparate(GL_BACK, GL_KEEP, GL_KEEP, GL_DECR_WRAP);
        }
    }

    /** Coloured where the stencil is marked, and the stencil set back to 0 there for the next path. */
    protected static function cover(): void
    {
        glEnable(GL_STENCIL_TEST);
        glStencilMask(0xFF);
        glStencilFunc(GL_NOTEQUAL, 0, 0xFF);
        glStencilOp(GL_KEEP, GL_KEEP, GL_ZERO);
    }

    /** A sheet at least that size that no layer of this frame has yet: one kept from the last frame, or a new one. */
    protected function sheet(int $width, int $height, bool $transient = true): OpenGLFramebuffer
    {
        $width = intdiv($width + self::STEP - 1, self::STEP) * self::STEP;
        $height = intdiv($height + self::STEP - 1, self::STEP) * self::STEP;
        $size = ($transient ? '' : 'kept:')."{$width}x{$height}";
        $index = $this->lent[$size] ?? 0;
        $this->lent[$size] = $index + 1;

        return $this->sheets[$size][$index] ??= new OpenGLFramebuffer($this->device, $width, $height, transient: $transient);
    }

    /**
     * The backdrop of a group of glass panes: its region read from what $onto holds so far, straight, and
     * taken down a Kawase pyramid (1/2, 1/4, 1/8, 1/16 as the panes' blurs need), then up to half size for
     * each depth a pane uses, as Velvet's painter builds it. Its levels come from the frame's pool.
     *
     * @param array{region: Region, depths: list<int>, members: list<int>} $group
     * @return array{region: Region, samples: array<int, array{int, int, int}>, sharp: array{int, int, int}}
     */
    protected function pyramid(array $group, OpenGLFramebuffer $onto, int $ox, int $oy): array
    {
        ['region' => $region, 'depths' => $depths] = $group;
        $this->pyramidsBuilt++;

        $sharp = $this->filters->grab($onto->textureName(), $onto->width(), $onto->height(), $region->x - $ox, $region->y - $oy, $region->width, $region->height, true);
        $down = [[$sharp, $region->width, $region->height]];
        for ($k = 1, $deepest = max($depths); $k <= $deepest; $k++) {
            [$source, $w, $h] = $down[$k - 1];
            $down[$k] = [$this->filters->kawaseDown($source, $w, $h), intdiv($w + 1, 2), intdiv($h + 1, 2)];
        }
        $samples = [];
        foreach ($depths as $depth) {
            $up = $down[$depth];
            for ($k = $depth - 1; $k >= 1; $k--) {
                [, $w, $h] = $down[$k];
                $up = [$this->filters->kawaseUp($up[0], $up[1], $up[2], $w, $h), $w, $h];
            }
            $samples[$depth] = $up;
        }

        return ['region' => $region, 'samples' => $samples, 'sharp' => $down[0]];
    }

    /** Whether any of $commands reads what is drawn under it: then the pass over their sheet has to end there. */
    protected static function reads(array $commands): bool
    {
        foreach ($commands as $command) {
            if ($command[0] === 'glass' || ($command[0] === 'layer' && ! is_null($command[6]))) {
                return true;
            }
        }

        return false;
    }

    /** A gradient's ramp as a texture: 256 x 1, straight colour as the recorder made it. Kept by its bytes. */
    protected function ramp(string $ramp): int
    {
        if (isset($this->ramps[$ramp])) {
            return $this->ramps[$ramp];
        }
        $this->device->current();
        $texture = OpenGLContext::texture(256, 1);
        glPixelStorei(GL_UNPACK_ALIGNMENT, 4);
        glTexSubImage2D(GL_TEXTURE_2D, 0, 0, 0, 256, 1, GL_RGBA, GL_UNSIGNED_BYTE, $ramp);
        if (count($this->ramps) >= self::RAMPS) {
            glDeleteTextures(1, [array_shift($this->ramps)]);
        }

        return $this->ramps[$ramp] = $texture;
    }

    /**
     * A path stencilled, then covered: in its colour, or by $cover.
     *
     * @param list<list<array{float, float}>> $contours
     * @param array{string, string, list<int>}|null $cover the fragment, its bytes and the textures it reads, in place of the colour
     */
    protected function stencilled(array &$draws, \Closure $add, array $contours, FillRule $rule, int $rgba, Region $clip, ?array $cover = null): void
    {
        // one convex contour: its fan covers it once, so it is drawn in one step, with no stencil
        if (count($contours) === 1 && OpenGLContext::convex($contours[0])) {
            $contour = $contours[0];
            $fan = [];
            for ($i = 1, $count = count($contour); $i < $count - 1; $i++) {
                array_push($fan, ...$contour[0], ...$contour[$i], ...$contour[$i + 1]);
            }
            $draws[] = is_null($cover)
                ? ['solid', null, ...$add($fan), self::paint($rgba), $clip, true, []]
                : [$cover[0], null, ...$add($fan), $cover[1], $clip, true, $cover[2]];

            return;
        }

        $fans = [];
        [$left, $top, $right, $bottom] = [INF, INF, -INF, -INF];
        foreach ($contours as $contour) {
            $count = count($contour);
            if ($count < 3) {
                continue;
            }
            for ($i = 1; $i < $count - 1; $i++) {
                array_push($fans, ...$contour[0], ...$contour[$i], ...$contour[$i + 1]);
            }
            foreach ($contour as [$x, $y]) {
                [$left, $top, $right, $bottom] = [min($left, $x), min($top, $y), max($right, $x), max($bottom, $y)];
            }
        }
        if ($fans === []) {
            return;
        }
        $draws[] = ['solid', $rule === FillRule::EVEN_ODD ? 'invert' : 'winding', ...$add($fans), '', $clip, true, []];
        $bounds = $add([$left, $top, $right, $top, $right, $bottom, $left, $top, $right, $bottom, $left, $bottom]);
        $draws[] = is_null($cover)
            ? ['solid', 'cover', ...$bounds, self::paint($rgba), $clip, true, []]
            : [$cover[0], 'cover', ...$bounds, $cover[1], $clip, true, $cover[2]];
    }

    /** @return array{int, int, int, int} 0xRRGGBBAA as its four bytes */
    protected static function bytes(int $rgba): array
    {
        return [($rgba >> 24) & 0xFF, ($rgba >> 16) & 0xFF, ($rgba >> 8) & 0xFF, $rgba & 0xFF];
    }

    /** The colour's four bytes as uvec4. */
    protected static function paint(int $rgba): string
    {
        return pack('V4', ...self::bytes($rgba));
    }
}
