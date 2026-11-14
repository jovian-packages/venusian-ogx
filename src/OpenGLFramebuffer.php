<?php

namespace Jovian\Engines\OpenGL;

use Surface\Contracts\Rendering\Framebuffers\DamageGranularity;
use Surface\Contracts\Rendering\Framebuffers\FormatSpec;
use Surface\Contracts\Rendering\Framebuffers\Region;
use Surface\Contracts\Rendering\Painting\PaintTarget;
use Surface\Graphics\GEFramebuffer;
use Surface\NutsAndBolts\Color;

/**
 * The opengl engine's framebuffer: an RGBA8 texture holding premultiplied colour, and a four-sample
 * twin (renderbuffers) that antialiased shapes are drawn into and resolved from. read() and write()
 * take straight colour, as every GEFramebuffer does: the GPU converts on the way out and in.
 *
 * A write to the texture leaves the twin behind; it is filled from the texture again before the next
 * antialiased draw. Row 0 of the texture is the surface's top row (place.vert), so GL's window
 * coordinates are surface pixels and nothing is flipped.
 *
 * A transient framebuffer (a layer's sheet) is only ever drawn from transparent black, in one pass at
 * a time: it holds its texture alone, and draws through the engine's scratch samples and stencil.
 */
class OpenGLFramebuffer extends GEFramebuffer implements PaintTarget
{
    /** Samples a pixel when edges are antialiased. */
    public const int SAMPLES = 4;

    /** The single-sample texture and its framebuffer, which carries the hard-edge stencil. */
    protected int $texture;

    protected int $framebuffer;

    /** The hard-edge stencil, and the four-sample twin's colour and stencil and its framebuffer: 0 when transient. */
    protected int $stencil = 0;

    protected int $samples = 0;

    protected int $sampleStencil = 0;

    protected int $sampled = 0;

    /** Straight colour on its way in or out, and its framebuffer: made the first time it is needed. */
    protected int $staging = 0;

    protected int $stagingFramebuffer = 0;

    /** Whether the twin is behind the texture: written to since it was last filled. */
    protected bool $stale = false;

    /** The surface over the view its stage lent, when it is shown in one. */
    protected ?OpenGLSurface $surface = null;

    /**
     * Where the texture is behind the twin: drawn into with samples and not yet resolved. A resolve is a
     * pass of its own in GL, so it waits until something reads the texture (resolve()).
     */
    protected ?Region $behind = null;

    public function __construct(
        protected readonly OpenGLContext $device,
        protected readonly int $width,
        protected readonly int $height,
        protected readonly bool $transient = false,
    ) {
        parent::__construct($device);

        $device->current();
        $this->texture = OpenGLContext::texture($width, $height);
        glGenFramebuffers(1, $framebuffers);
        $this->framebuffer = $framebuffers[0];
        glBindFramebuffer(GL_FRAMEBUFFER, $this->framebuffer);
        glFramebufferTexture2D(GL_FRAMEBUFFER, GL_COLOR_ATTACHMENT0, GL_TEXTURE_2D, $this->texture, 0);
        if ($transient) {
            self::complete('the sheet');
            $this->clearBoth(0.0, 0.0, 0.0, 0.0);

            return;
        }

        glGenRenderbuffers(3, $renderbuffers);
        [$this->stencil, $this->samples, $this->sampleStencil] = $renderbuffers;
        glBindRenderbuffer(GL_RENDERBUFFER, $this->stencil);
        glRenderbufferStorage(GL_RENDERBUFFER, GL_DEPTH24_STENCIL8, $width, $height);
        glBindRenderbuffer(GL_RENDERBUFFER, $this->samples);
        glRenderbufferStorageMultisample(GL_RENDERBUFFER, self::SAMPLES, GL_RGBA8, $width, $height);
        glBindRenderbuffer(GL_RENDERBUFFER, $this->sampleStencil);
        glRenderbufferStorageMultisample(GL_RENDERBUFFER, self::SAMPLES, GL_DEPTH24_STENCIL8, $width, $height);
        glFramebufferRenderbuffer(GL_FRAMEBUFFER, GL_DEPTH_STENCIL_ATTACHMENT, GL_RENDERBUFFER, $this->stencil);
        self::complete('the framebuffer');

        glGenFramebuffers(1, $framebuffers);
        $this->sampled = $framebuffers[0];
        glBindFramebuffer(GL_FRAMEBUFFER, $this->sampled);
        glFramebufferRenderbuffer(GL_FRAMEBUFFER, GL_COLOR_ATTACHMENT0, GL_RENDERBUFFER, $this->samples);
        glFramebufferRenderbuffer(GL_FRAMEBUFFER, GL_DEPTH_STENCIL_ATTACHMENT, GL_RENDERBUFFER, $this->sampleStencil);
        self::complete('the four-sample framebuffer');

        // a new texture's bytes are undefined until written: it starts transparent black, as every framebuffer does
        $this->clearBoth(0.0, 0.0, 0.0, 0.0);
    }

    public function __destruct()
    {
        $this->free();
    }

    /** Deletes what it holds on the GPU, while the engine is open. GL keeps what a queued draw still reads until it has run. */
    public function free(): void
    {
        if ($this->texture === 0 || ! $this->device->isOpen()) {
            return;
        }
        $this->device->current();
        glDeleteFramebuffers(1, [$this->framebuffer]);
        glDeleteTextures(1, [$this->texture]);
        if (! $this->transient) {
            glDeleteFramebuffers(1, [$this->sampled]);
            glDeleteRenderbuffers(3, [$this->stencil, $this->samples, $this->sampleStencil]);
        }
        if ($this->staging !== 0) {
            glDeleteFramebuffers(1, [$this->stagingFramebuffer]);
            glDeleteTextures(1, [$this->staging]);
        }
        $this->texture = 0;
    }

    public function width(): int
    {
        return $this->width;
    }

    public function height(): int
    {
        return $this->height;
    }

    public function format(): FormatSpec
    {
        return FormatSpec::rgba8();
    }

    // PaintTarget: what a painter needs to know of what it draws on

    public function viewportWidth(): int
    {
        return $this->width;
    }

    public function viewportHeight(): int
    {
        return $this->height;
    }

    public function damageGranularity(): DamageGranularity
    {
        return DamageGranularity::pixel($this->width, $this->height);
    }

    /** The texture keeps what was drawn until it is drawn over. */
    public function preservesContentsOnPresent(): bool
    {
        return true;
    }

    /**
     * Moves the pixels of $region by ($dx, $dy) on the GPU: what moves past the region's edge is
     * dropped, and the strip left behind keeps its old pixels. Blitted out to a texture of its own and
     * back, since a blit within one framebuffer may not overlap itself.
     */
    public function scroll(Region $region, int $dx, int $dy): void
    {
        $area = Region::wholeSurface($this->width, $this->height)->intersect($region);
        $from = $area?->intersect(new Region($area->x - $dx, $area->y - $dy, $area->width, $area->height));
        if (is_null($from) || $from->isEmpty() || ($dx === 0 && $dy === 0)) {
            return;
        }
        $this->device->current();
        $this->device->plain();
        $this->resolve();
        $copy = OpenGLContext::texture($from->width, $from->height);
        glGenFramebuffers(1, $framebuffers);
        glBindFramebuffer(GL_FRAMEBUFFER, $framebuffers[0]);
        glFramebufferTexture2D(GL_FRAMEBUFFER, GL_COLOR_ATTACHMENT0, GL_TEXTURE_2D, $copy, 0);

        $this->blit($this->framebuffer, $from, $framebuffers[0], new Region(0, 0, $from->width, $from->height));
        $this->blit($framebuffers[0], new Region(0, 0, $from->width, $from->height), $this->framebuffer, new Region($from->x + $dx, $from->y + $dy, $from->width, $from->height));

        glDeleteFramebuffers(1, $framebuffers);
        glDeleteTextures(1, [$copy]);
        $this->stale = true;
    }

    public function clear(Color $color): static
    {
        // the colour as its bytes, then premultiplied, as the texture holds it
        $alpha = round($color->alpha * 255) / 255;
        $byte = fn (float $channel): float => round($channel * 255) / 255 * $alpha;

        $this->device->current();
        $this->clearBoth($byte($color->red), $byte($color->green), $byte($color->blue), $alpha);

        return $this;
    }

    /**
     * Binds what draws go to and clears its stencil over $area: the four-sample twin for antialiased
     * edges (filled from the texture first when it is behind), the texture for hard ones. With $clear it
     * starts from transparent black over $area. The viewport covers the framebuffer and the scissor is
     * $area. Then upload the draws' blocks (frameBlock() first) and vertices, draw, and end().
     *
     * @internal
     */
    public function begin(bool $antialiased, Region $area, bool $clear = false): void
    {
        $this->device->current();
        $this->device->plain();
        if (! $antialiased) {
            $this->resolve();
        } elseif ($this->stale && ! $clear) {
            $this->fill();
        }
        glBindFramebuffer(GL_FRAMEBUFFER, $this->drawn($antialiased));
        glViewport(0, 0, $this->width, $this->height);
        glEnable(GL_SCISSOR_TEST);
        glScissor($area->x, $area->y, $area->width, $area->height);
        glStencilMask(0xFF);
        glClearStencil(0);
        glClearColor(0.0, 0.0, 0.0, 0.0);
        glClear(GL_STENCIL_BUFFER_BIT | ($clear ? GL_COLOR_BUFFER_BIT : 0));
    }

    /**
     * After antialiased draws, the texture is behind the samples over $area: resolved when it is next read,
     * or at once for a transient framebuffer, whose samples the next one draws over. After hard draws, the
     * twin is behind the texture.
     *
     * @internal
     */
    public function end(bool $antialiased, Region $area): void
    {
        $this->device->plain();
        if ($antialiased) {
            $this->behind = is_null($this->behind) ? $area : $this->behind->union($area);
            if ($this->transient) {
                $this->resolve();
            }
        } elseif (! $this->transient) {
            $this->stale = true;
        }
    }

    /** Brings the texture up to the samples where it is behind them. Everything that reads the texture comes after this. */
    public function resolve(): void
    {
        if (! is_null($this->behind)) {
            $this->device->current();
            $this->device->plain();
            $this->blit($this->drawn(true), $this->behind, $this->framebuffer, $this->behind);
            $this->behind = null;
        }
    }

    /** @internal The Frame block's bytes: this framebuffer's size, starting at ($ox, $oy) on the surface. */
    public function frameBlock(int $ox = 0, int $oy = 0): string
    {
        return pack('g4', $this->width, $this->height, $ox, $oy);
    }

    /**
     * Shows it in $surface from now on.
     *
     * @internal
     */
    public function showIn(OpenGLSurface $surface): static
    {
        $this->surface = $surface;

        return $this;
    }

    /**
     * In a surface over the view the stage lent, the GPU draws the texture into the view's back buffer and
     * swaps it in: nothing comes back to the CPU. Otherwise as pixels, through the stage. On a paced stage
     * this runs once a refresh.
     */
    protected function show(): void
    {
        if (is_null($this->surface)) {
            parent::show();

            return;
        }
        $this->surface->show($this);
    }

    /** @internal The texture, resolved: what a draw from this framebuffer samples. */
    public function textureName(): int
    {
        $this->resolve();

        return $this->texture;
    }

    /** @internal The single-sample framebuffer, over the texture. */
    public function framebufferName(): int
    {
        return $this->framebuffer;
    }

    protected function readRgba8(Region $region): string
    {
        $this->device->current();
        $this->resolve();
        $this->convert('unpremultiply.frag', $this->texture, $this->stagingFramebuffer(), $region);
        glBindFramebuffer(GL_READ_FRAMEBUFFER, $this->stagingFramebuffer);

        return $this->device->readPixels($region);
    }

    protected function writeRgba8(string $rgba8, Region $region): void
    {
        $this->device->current();
        $this->resolve();
        $this->stagingFramebuffer();
        glBindTexture(GL_TEXTURE_2D, $this->staging);
        glPixelStorei(GL_UNPACK_ALIGNMENT, 4);
        glTexSubImage2D(GL_TEXTURE_2D, 0, $region->x, $region->y, $region->width, $region->height, GL_RGBA, GL_UNSIGNED_BYTE, $rgba8);
        $this->convert('premultiply.frag', $this->staging, $this->framebuffer, $region);
        $this->stale = true;
    }

    /**
     * The framebuffer a draw goes to: the samples (its own, or the engine's scratch when transient) for
     * antialiased edges; the texture's for hard ones, with the scratch stencil attached when transient.
     */
    protected function drawn(bool $antialiased): int
    {
        if (! $this->transient) {
            return $antialiased ? $this->sampled : $this->framebuffer;
        }
        [$samples, $stencil] = $this->device->scratch($this->width, $this->height);
        if ($antialiased) {
            return $samples;
        }
        glBindFramebuffer(GL_FRAMEBUFFER, $this->framebuffer);
        glFramebufferRenderbuffer(GL_FRAMEBUFFER, GL_DEPTH_STENCIL_ATTACHMENT, GL_RENDERBUFFER, $stencil);

        return $this->framebuffer;
    }

    /** Draws $fragment over $region of $target, reading $source at the same pixels, with no blend. */
    protected function convert(string $fragment, int $source, int $target, Region $region): void
    {
        $this->device->plain();
        glBindFramebuffer(GL_FRAMEBUFFER, $target);
        glViewport(0, 0, $this->width, $this->height);
        glUseProgram($this->device->program('place.vert', $fragment));
        [$offset] = $this->device->blocks([$this->frameBlock()]);
        $this->device->block(OpenGLContext::FRAME, $offset, 16);
        glActiveTexture(GL_TEXTURE0);
        glBindTexture(GL_TEXTURE_2D, $source);
        $this->device->vertices(OpenGLContext::quad($region));
        glDrawArrays(GL_TRIANGLES, 0, 6);
    }

    /** The twin made what the texture holds, at every sample. */
    protected function fill(): void
    {
        $this->convert('copy.frag', $this->texture, $this->sampled, Region::wholeSurface($this->width, $this->height));
        $this->stale = false;
    }

    /** Copies $from of one framebuffer to $to of another, pixel for pixel (a resolve when $source has samples). */
    protected function blit(int $source, Region $from, int $target, Region $to): void
    {
        glBindFramebuffer(GL_READ_FRAMEBUFFER, $source);
        glBindFramebuffer(GL_DRAW_FRAMEBUFFER, $target);
        glBlitFramebuffer($from->x, $from->y, $from->x + $from->width, $from->y + $from->height, $to->x, $to->y, $to->x + $to->width, $to->y + $to->height, GL_COLOR_BUFFER_BIT, GL_NEAREST);
        glBindFramebuffer(GL_FRAMEBUFFER, 0);
    }

    protected function clearBoth(float $red, float $green, float $blue, float $alpha): void
    {
        $this->device->plain();
        glClearColor($red, $green, $blue, $alpha);
        glClearStencil(0);
        glStencilMask(0xFF);
        foreach (array_filter([$this->framebuffer, $this->sampled]) as $framebuffer) {
            glBindFramebuffer(GL_FRAMEBUFFER, $framebuffer);
            glClear(GL_COLOR_BUFFER_BIT | ($this->transient ? 0 : GL_STENCIL_BUFFER_BIT));
        }
        [$this->stale, $this->behind] = [false, null];
    }

    protected function stagingFramebuffer(): int
    {
        if ($this->staging === 0) {
            $this->staging = OpenGLContext::texture($this->width, $this->height);
            glGenFramebuffers(1, $framebuffers);
            $this->stagingFramebuffer = $framebuffers[0];
            glBindFramebuffer(GL_FRAMEBUFFER, $this->stagingFramebuffer);
            glFramebufferTexture2D(GL_FRAMEBUFFER, GL_COLOR_ATTACHMENT0, GL_TEXTURE_2D, $this->staging, 0);
            self::complete('the staging framebuffer');
        }

        return $this->stagingFramebuffer;
    }

    /** @throws OpenGLException When the bound framebuffer cannot be drawn to. */
    protected static function complete(string $what): void
    {
        $status = glCheckFramebufferStatus(GL_FRAMEBUFFER);
        if ($status !== GL_FRAMEBUFFER_COMPLETE) {
            throw OpenGLException::because(sprintf('%s is not complete (0x%04X).', $what, $status));
        }
    }
}
