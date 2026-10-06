<?php

namespace Jovian\Engines\OpenGL;

use CGLContextObj;
use Closure;
use EGLContext;
use EGLDisplay;
use Surface\Contracts\Drawing\DrawingException;
use Surface\Contracts\Drawing\LentSurface;
use Surface\Contracts\Drawing\SurfaceKind;
use Surface\Contracts\Framebuffers\Filter;
use Surface\Contracts\Framebuffers\Framebuffer;
use Surface\Contracts\Framebuffers\GLFramebuffer;
use Surface\Contracts\Framebuffers\Region;
use Surface\Contracts\Rasterize\FillRule;
use Surface\Drawing\Gpu\DrawList;
use Surface\Drawing\Gpu\GpuDevice;
use Surface\Drawing\Gpu\Op;

/**
 * The opengl engine's device: one GL context (its own, offscreen, or the one a
 * canvas lends), a persistent target, and the program that draws a DrawList
 * into it.
 *
 * The target is an RGBA8 texture ($resolved) in a framebuffer object
 * ($resolvedFbo): read back, uploaded to, and blitted from. With four samples
 * a second framebuffer ($msaaFbo) holds a multisampled colour renderbuffer and
 * a multisampled depth-stencil renderbuffer; every frame draws there and is
 * resolved into the texture with glBlitFramebuffer. With one sample the
 * texture itself is the colour attachment and the stencil is a single-sample
 * renderbuffer on the same framebuffer. An upload leaves the samples behind,
 * and the next draw restores them from the texture first.
 *
 * Rows: the vertex shader puts target row 0 at the top of clip space, so the
 * texture holds the frame bottom-up as GL reads it; read() and write() flip at
 * that boundary. The fragment shader samples a source with row 0 on top: a CPU
 * source is uploaded as it is (top-down), and a texture of this device (the
 * target itself for the restore, or a framebuffer of it drawn as an image) is
 * sampled through an upright copy, a flipped blit on the GPU.
 *
 * Every public entry makes this device's context current, unless it already
 * is, and on the way out puts back whatever was current before: a sketch
 * holding a canvas's context is not disturbed. An adopted context is switched
 * the same way when the canvas does not have it current, and is never
 * destroyed: release() lets go of the device's objects in it and nothing else.
 */
class OpenGLDevice implements GpuDevice
{
    public const string VERTEX_SHADER = __DIR__.'/../resources/shaders/venusian.vert.glsl';

    public const string FRAGMENT_SHADER = __DIR__.'/../resources/shaders/venusian.frag.glsl';

    /** The dialect header prepended to each body: by GL_SHADING_LANGUAGE_VERSION, 'es' when it names "ES". */
    public const array HEADERS = [
        'core' => "#version 150 core\n",
        'es' => "#version 300 es\nprecision highp float;\nprecision highp int;\nprecision highp sampler2D;\n",
    ];

    protected const int SOLID = 0;

    protected const int FILL = 1;

    protected const int OVAL = 2;

    protected const int IMAGE = 3;

    protected const int COPY = 4;

    /** The context: made here, or adopted from a canvas (then not this device's to destroy). Null before either. */
    private ?CGLContextObj $cgl = null;

    private ?EGLContext $egl = null;

    private ?EGLDisplay $eglDisplay = null;

    /** True once a context is in hand: own or adopted. */
    private bool $hasContext = false;

    /** True when the context is a canvas's. */
    private bool $adopted = false;

    /** The surface the context was adopted from: once the canvas releases it, the context may be gone and no GL call is made. */
    private ?LentSurface $surface = null;

    private ?string $dialect = null;

    private ?int $maxSamples = null;

    private ?int $maxTextureSize = null;

    private int $program = 0;

    /** @var array<string, int> Uniform locations by name. */
    private array $uniforms = [];

    private int $vao = 0;

    private int $vbo = 0;

    /** A 1 × 1 transparent texture on the sampler's unit for every draw that samples nothing: a draw with none bound is one drivers log. */
    private int $blank = 0;

    private ?OpenGLFramebuffer $framebuffer = null;

    private int $resolved = 0;

    private int $resolvedFbo = 0;

    private int $msaaFbo = 0;

    private int $msaaColor = 0;

    private int $stencil = 0;

    private int $samples = 1;

    private bool $stale = false;

    private bool $released = false;

    /** @var list<int> Textures made for this frame's sources and restore, deleted when it ends. */
    private array $sourceTextures = [];

    /** @var array<int, int> Width and height by texture name: the resolved texture of every live framebuffer, and this frame's sources. */
    private array $textureWidths = [];

    private array $textureHeights = [];

    /**
     * A device over its own offscreen context: CGL 4.1 core on macOS, surfaceless
     * EGL with an OpenGL ES 3.1 context on Linux.
     *
     * @throws DrawingException When no context can be made.
     */
    public function __construct()
    {
        if (PHP_OS_FAMILY === 'Darwin') {
            $attribs = [kCGLPFAOpenGLProfile, kCGLOGLPVersion_GL4_Core, kCGLPFAAccelerated, kCGLPFAColorSize, 24, kCGLPFAAlphaSize, 8, 0];
            $error = CGLChoosePixelFormat($attribs, $pixelFormat, $count);
            if ($error !== kCGLNoError || is_null($pixelFormat)) {
                throw new DrawingException('opengl: no OpenGL 4.1 core pixel format: '.CGLErrorString($error).'.');
            }
            $error = CGLCreateContext($pixelFormat, null, $context);
            CGLDestroyPixelFormat($pixelFormat);
            if ($error !== kCGLNoError || is_null($context)) {
                throw new DrawingException('opengl: no OpenGL context could be made: '.CGLErrorString($error).'.');
            }
            $this->cgl = $context;
        } else {
            $display = eglGetPlatformDisplay(EGL_PLATFORM_SURFACELESS_MESA, EGL_DEFAULT_DISPLAY, null)
                ?? throw new DrawingException('opengl: no surfaceless EGL display (Mesa with EGL_MESA_platform_surfaceless is needed).');
            if (! eglInitialize($display, $major, $minor)) {
                throw new DrawingException('opengl: eglInitialize failed: '.sprintf('0x%04x', eglGetError()).'.');
            }
            eglBindAPI(EGL_OPENGL_ES_API);
            eglChooseConfig($display, [EGL_RENDERABLE_TYPE, EGL_OPENGL_ES3_BIT, EGL_SURFACE_TYPE, EGL_PBUFFER_BIT, EGL_NONE], $configs, 1, $count);
            if (($configs[0] ?? null) === null) {
                throw new DrawingException('opengl: no EGL config renders OpenGL ES 3.');
            }
            $context = eglCreateContext($display, $configs[0], null, [EGL_CONTEXT_MAJOR_VERSION, 3, EGL_CONTEXT_MINOR_VERSION, 1, EGL_NONE])
                ?? throw new DrawingException('opengl: no OpenGL ES 3.1 context could be made: '.sprintf('0x%04x', eglGetError()).'.');
            $this->eglDisplay = $display;
            $this->egl = $context;
        }
        $this->hasContext = true;
        $this->with(fn () => $this->prepareContext());
    }

    /** An owned context goes with the device; an adopted one is the canvas's and is let go by release() alone. */
    public function __destruct()
    {
        if (! $this->adopted) {
            $this->release();
        }
    }

    /** A device with no context of its own: adopt() hands it the canvas's. */
    public static function adopting(): static
    {
        $device = (new \ReflectionClass(static::class))->newInstanceWithoutConstructor();

        return $device;
    }

    public function name(): string
    {
        return 'opengl';
    }

    public function surfaces(): array
    {
        return [SurfaceKind::GL_CONTEXT];
    }

    public function handles(): array
    {
        return [];
    }

    /** 'core' (OpenGL 4.1) or 'es' (OpenGL ES 3). */
    public function dialect(): string
    {
        return $this->dialect ?? throw new DrawingException('opengl: no context yet: adopt() a surface first.');
    }

    public function maxSamples(): int
    {
        return $this->maxSamples ?? throw new DrawingException('opengl: no context yet: adopt() a surface first.');
    }

    /** The widest and tallest texture this context makes (GL_MAX_TEXTURE_SIZE). */
    public function maxTextureSize(): int
    {
        return $this->maxTextureSize ?? throw new DrawingException('opengl: no context yet: adopt() a surface first.');
    }

    /** The linked program. */
    public function program(): int
    {
        return $this->program;
    }

    /** Make this device's context current, unless it already is. Callers that hold GL state across calls pair it with their own restore. */
    public function current(): void
    {
        $this->live();
        if (! $this->isCurrent()) {
            $this->makeCurrent();
        }
    }

    public function target(int $width, int $height, int $samples): GLFramebuffer
    {
        if ($samples !== 1 && $samples !== 4) {
            throw new DrawingException("opengl draws with 1 or 4 samples, got {$samples}.");
        }
        if ($width < 1 || $height < 1) {
            throw new DrawingException("opengl needs a target of at least 1 × 1, got {$width} × {$height}.");
        }
        $this->live();
        if ($samples === 4 && $this->maxSamples() < 4) {
            throw new DrawingException("opengl: this context renders at most {$this->maxSamples()} samples; anti-aliased edges need 4.");
        }
        $this->fits($width, $height);

        return $this->with(function () use ($width, $height, $samples): OpenGLFramebuffer {
            $this->releaseTarget();
            $this->samples = $samples;
            $this->stale = false;

            $this->resolved = $this->texture($width, $height);

            [$this->resolvedFbo] = glGenFramebuffers(1);
            glBindFramebuffer(GL_FRAMEBUFFER, $this->resolvedFbo);
            glFramebufferTexture2D(GL_FRAMEBUFFER, GL_COLOR_ATTACHMENT0, GL_TEXTURE_2D, $this->resolved, 0);

            [$this->stencil] = glGenRenderbuffers(1);
            glBindRenderbuffer(GL_RENDERBUFFER, $this->stencil);
            if ($samples === 4) {
                [$this->msaaFbo] = glGenFramebuffers(1);
                [$this->msaaColor] = glGenRenderbuffers(1);
                glBindRenderbuffer(GL_RENDERBUFFER, $this->msaaColor);
                glRenderbufferStorageMultisample(GL_RENDERBUFFER, 4, GL_RGBA8, $width, $height);
                glBindRenderbuffer(GL_RENDERBUFFER, $this->stencil);
                glRenderbufferStorageMultisample(GL_RENDERBUFFER, 4, GL_DEPTH24_STENCIL8, $width, $height);
                glBindFramebuffer(GL_FRAMEBUFFER, $this->msaaFbo);
                glFramebufferRenderbuffer(GL_FRAMEBUFFER, GL_COLOR_ATTACHMENT0, GL_RENDERBUFFER, $this->msaaColor);
                glFramebufferRenderbuffer(GL_FRAMEBUFFER, GL_DEPTH_STENCIL_ATTACHMENT, GL_RENDERBUFFER, $this->stencil);
            } else {
                glRenderbufferStorage(GL_RENDERBUFFER, GL_DEPTH24_STENCIL8, $width, $height);
                glFramebufferRenderbuffer(GL_FRAMEBUFFER, GL_DEPTH_STENCIL_ATTACHMENT, GL_RENDERBUFFER, $this->stencil);
            }
            glBindRenderbuffer(GL_RENDERBUFFER, 0);
            $status = glCheckFramebufferStatus(GL_FRAMEBUFFER);
            if ($status !== GL_FRAMEBUFFER_COMPLETE) {
                glBindFramebuffer(GL_FRAMEBUFFER, 0);
                $this->forgetTexture($this->resolved);
                $this->releaseTarget();
                throw new DrawingException(sprintf('opengl: the %d × %d target is incomplete (0x%04x).', $width, $height, $status));
            }

            // Defined contents from the start: transparent black, in both framebuffers.
            glDisable(GL_SCISSOR_TEST);
            glClearColor(0.0, 0.0, 0.0, 0.0);
            glClearStencil(0);
            glStencilMask(0xFF);
            glClear(GL_COLOR_BUFFER_BIT | GL_STENCIL_BUFFER_BIT);
            if ($samples === 4) {
                glBindFramebuffer(GL_FRAMEBUFFER, $this->resolvedFbo);
                glClear(GL_COLOR_BUFFER_BIT);
            }
            glBindFramebuffer(GL_FRAMEBUFFER, 0);
            $this->check('target()');

            return $this->framebuffer = new OpenGLFramebuffer($this, $this->resolved, $width, $height);
        });
    }

    /** @internal OpenGLFramebuffer's readback: $region of $texture as a frame string, rows top-down. */
    public function read(int $texture, Region $region): string
    {
        $this->live();

        return $this->with(function () use ($texture, $region): string {
            [$fbo] = glGenFramebuffers(1);
            try {
                glBindFramebuffer(GL_READ_FRAMEBUFFER, $fbo);
                glFramebufferTexture2D(GL_READ_FRAMEBUFFER, GL_COLOR_ATTACHMENT0, GL_TEXTURE_2D, $texture, 0);
                glReadBuffer(GL_COLOR_ATTACHMENT0);
                self::tightlyPacked(pack: true);
                $height = $this->heightOf($texture);
                $bytes = glReadPixels($region->x, $height - $region->bottom(), $region->width, $region->height, GL_RGBA, GL_UNSIGNED_BYTE, null)
                    ?? throw new DrawingException('opengl: the readback returned nothing.');
            } finally {
                glBindFramebuffer(GL_READ_FRAMEBUFFER, 0);
                glDeleteFramebuffers([$fbo]);
            }
            $this->check('read()');

            return self::flip($bytes, $region->width * 4);
        });
    }

    /** @internal OpenGLFramebuffer's upload: $rgba8 (rows top-down) over $region of $texture. */
    public function write(int $texture, string $rgba8, Region $region): void
    {
        $this->live();
        $this->with(function () use ($texture, $rgba8, $region): void {
            self::tightlyPacked(pack: false);
            glBindTexture(GL_TEXTURE_2D, $texture);
            $height = $this->heightOf($texture);
            glTexSubImage2D(GL_TEXTURE_2D, 0, $region->x, $height - $region->bottom(), $region->width, $region->height, GL_RGBA, GL_UNSIGNED_BYTE, self::flip($rgba8, $region->width * 4));
            glBindTexture(GL_TEXTURE_2D, 0);
            $this->check('write()');
            if ($texture === $this->resolved && $this->samples === 4) {
                $this->stale = true;
            }
        });
    }

    /** @internal OpenGLFramebuffer lets go of its texture here, when the PHP object goes. */
    public function forget(int $texture): void
    {
        if ($this->released || ! $this->hasContext || $this->lost() || ! isset($this->textureHeights[$texture])) {
            return;
        }
        $this->with(fn () => $this->forgetTexture($texture));
    }

    private function forgetTexture(int $texture): void
    {
        unset($this->textureWidths[$texture], $this->textureHeights[$texture]);
        glDeleteTextures([$texture]);
    }

    /** Wait for the GPU to finish everything issued so far. */
    public function finish(): void
    {
        $this->live();
        $this->with(fn () => glFinish());
    }

    public function draw(DrawList $list): void
    {
        $this->live();
        $target = $this->framebuffer ?? throw new DrawingException('opengl: target() comes before draw().');
        $width = $target->width();
        $height = $target->height();

        $this->with(function () use ($list, $width, $height): void {
            try {
                $this->encode($list, $width, $height);
            } finally {
                $this->dropSources();
            }
        });
    }

    /** One frame: sources up, the samples restored after an upload, every operation, the resolve. */
    private function encode(DrawList $list, int $width, int $height): void
    {
        // Sources first: one too large cancels the frame before anything is drawn.
        /** @var array<int, int> $textures */
        $textures = [];
        foreach ($list->operations as $operation) {
            if ($operation[0] !== Op::UPLOAD) {
                continue;
            }
            $source = $operation[2];
            $textures[$operation[1]] = $this->sourceTextures[] = $source instanceof OpenGLFramebuffer && $source->device() === $this && isset($this->textureHeights[$source->texture()])
                ? $this->upright($source->texture())
                : $this->upload($source);
        }

        // The list's vertices, then one quad over the whole target for CLEAR and the restore.
        $whole = $list->vertexCount();
        $vertices = $list->vertices.self::quad(0.0, 0.0, (float) $width, (float) $height);
        glBindVertexArray($this->vao);
        glBindBuffer(GL_ARRAY_BUFFER, $this->vbo);
        glBufferData(GL_ARRAY_BUFFER, strlen($vertices), $vertices, GL_STREAM_DRAW);

        glUseProgram($this->program);
        glUniform2f($this->uniforms['size'], (float) $width, (float) $height);
        glUniform1i($this->uniforms['source'], 0);
        glActiveTexture(GL_TEXTURE0);
        glBindTexture(GL_TEXTURE_2D, $this->blank);
        glViewport(0, 0, $width, $height);
        glDisable(GL_CULL_FACE);
        glDisable(GL_DEPTH_TEST);
        glDisable(GL_STENCIL_TEST);
        glColorMask(true, true, true, true);
        glBlendEquation(GL_FUNC_ADD);
        glBlendFuncSeparate(GL_SRC_ALPHA, GL_ONE_MINUS_SRC_ALPHA, GL_ONE, GL_ONE_MINUS_SRC_ALPHA);
        glStencilMask(0xFF);

        if ($this->stale) {
            $this->restore($whole, $this->sourceTextures[] = $this->upright($this->resolved));
        }
        glBindFramebuffer(GL_FRAMEBUFFER, $this->samples === 4 ? $this->msaaFbo : $this->resolvedFbo);
        glDisable(GL_SCISSOR_TEST);
        glClearStencil(0);
        glClear(GL_STENCIL_BUFFER_BIT);
        glEnable(GL_SCISSOR_TEST);
        // GL's scissor origin is the bottom-left: [x, y, width, height] in GL rows.
        $scissor = [0, 0, $width, $height];
        glScissor(...$scissor);

        foreach ($list->operations as $operation) {
            switch ($operation[0]) {
                case Op::CLEAR:
                    glScissor(0, 0, $width, $height);
                    $this->paint(self::SOLID, $operation[1], blend: false);
                    glDrawArrays(GL_TRIANGLES, $whole, 6);
                    glScissor(...$scissor);
                    break;

                case Op::SCISSOR:
                    $scissor = [$operation[1]->x, $height - $operation[1]->bottom(), $operation[1]->width, $operation[1]->height];
                    glScissor(...$scissor);
                    break;

                case Op::SOLID:
                    $this->paint(self::SOLID, $operation[2], blend: false);
                    glDrawArrays(GL_TRIANGLES, $operation[1], 6);
                    break;

                case Op::STENCIL_FILL:
                    // No colour; SOLID never discards, so every fragment of the path reaches the stencil.
                    glColorMask(false, false, false, false);
                    glEnable(GL_STENCIL_TEST);
                    glStencilFunc(GL_ALWAYS, 0, 0xFF);
                    if ($operation[3] === FillRule::NON_ZERO) {
                        glStencilOpSeparate(GL_FRONT, GL_KEEP, GL_KEEP, GL_INCR_WRAP);
                        glStencilOpSeparate(GL_BACK, GL_KEEP, GL_KEEP, GL_DECR_WRAP);
                    } else {
                        glStencilOp(GL_KEEP, GL_KEEP, GL_INVERT);
                    }
                    $this->paint(self::SOLID, 0, blend: false);
                    glDrawArrays(GL_TRIANGLES, $operation[1], $operation[2]);
                    glColorMask(true, true, true, true);
                    glDisable(GL_STENCIL_TEST);
                    break;

                case Op::COVER:
                    // Drawn where the stencil is not zero, zeroing it for the next path.
                    glEnable(GL_STENCIL_TEST);
                    glStencilFunc(GL_NOTEQUAL, 0, 0xFF);
                    glStencilOp(GL_KEEP, GL_KEEP, GL_ZERO);
                    $this->paint(self::FILL, $operation[2], blend: true);
                    glDrawArrays(GL_TRIANGLES, $operation[1], 6);
                    glDisable(GL_STENCIL_TEST);
                    break;

                case Op::RECTS:
                    $this->paint(self::FILL, $operation[3], blend: true);
                    glDrawArrays(GL_TRIANGLES, $operation[1], $operation[2]);
                    break;

                case Op::ELLIPSE:
                    [, $first, $cx, $cy, $rx, $ry, $rgba] = $operation;
                    $this->paint(self::OVAL, $rgba, blend: true);
                    glUniform4f($this->uniforms['shape'], $cx, $cy, $rx, $ry);
                    glUniform4f($this->uniforms['band'], 0.0, 0.0, (float) $this->samples, 0.0);
                    glDrawArrays(GL_TRIANGLES, $first, 6);
                    break;

                case Op::RING:
                    [, $first, $cx, $cy, $rx, $ry, $stroke, $rgba] = $operation;
                    $this->paint(self::OVAL, $rgba, blend: true);
                    glUniform4f($this->uniforms['shape'], $cx, $cy, $rx, $ry);
                    glUniform4f($this->uniforms['band'], $stroke, 1.0, (float) $this->samples, 0.0);
                    glDrawArrays(GL_TRIANGLES, $first, 6);
                    break;

                case Op::UPLOAD:
                    break;

                case Op::IMAGE:
                    [, $first, $texture, $inverse, $opacity, $filter] = $operation;
                    $source = $textures[$texture];
                    $this->paint(self::IMAGE, 0, blend: true, opacity: $opacity, linear: $filter === Filter::LINEAR);
                    glBindTexture(GL_TEXTURE_2D, $source);
                    glUniform4f($this->uniforms['abcd'], $inverse->a, $inverse->b, $inverse->c, $inverse->d);
                    glUniform4f($this->uniforms['efwh'], $inverse->e, $inverse->f, (float) $this->textureWidths[$source], (float) $this->textureHeights[$source]);
                    glDrawArrays(GL_TRIANGLES, $first, 6);
                    glBindTexture(GL_TEXTURE_2D, $this->blank);
                    break;
            }
        }

        glDisable(GL_SCISSOR_TEST);
        glDisable(GL_BLEND);
        if ($this->samples === 4) {
            glBindFramebuffer(GL_READ_FRAMEBUFFER, $this->msaaFbo);
            glBindFramebuffer(GL_DRAW_FRAMEBUFFER, $this->resolvedFbo);
            glBlitFramebuffer(0, 0, $width, $height, 0, 0, $width, $height, GL_COLOR_BUFFER_BIT, GL_NEAREST);
        }
        glBindFramebuffer(GL_FRAMEBUFFER, 0);
        glBindVertexArray(0);
        glBindBuffer(GL_ARRAY_BUFFER, 0);
        glBindTexture(GL_TEXTURE_2D, 0);
        glUseProgram(0);
        glFlush();
        $this->check('draw()');
    }

    /** The resolved target into GL framebuffer $framebuffer at $width × $height, nearest; $framebuffer left bound, no pack buffer. */
    private function copyInto(int $framebuffer, int $width, int $height, string $where): void
    {
        $target = $this->framebuffer ?? throw new DrawingException("opengl: target() comes before {$where}.");
        glBindFramebuffer(GL_READ_FRAMEBUFFER, $this->resolvedFbo);
        glBindFramebuffer(GL_DRAW_FRAMEBUFFER, $framebuffer);
        glBindBuffer(GL_PIXEL_PACK_BUFFER, 0);
        glDisable(GL_SCISSOR_TEST);
        glBlitFramebuffer(0, 0, $target->width(), $target->height(), 0, 0, $width, $height, GL_COLOR_BUFFER_BIT, GL_NEAREST);
        glBindFramebuffer(GL_FRAMEBUFFER, $framebuffer);
        $this->check($where);
    }

    /** Mode and colour uniforms, blending on or off. */
    private function paint(int $mode, int $rgba, bool $blend, int $opacity = 255, bool $linear = false): void
    {
        glUniform4f($this->uniforms['mode_opacity'], (float) $mode, (float) $opacity, $linear ? 1.0 : 0.0, 0.0);
        glUniform4f($this->uniforms['rgba_in'], (float) (($rgba >> 24) & 0xFF), (float) (($rgba >> 16) & 0xFF), (float) (($rgba >> 8) & 0xFF), (float) ($rgba & 0xFF));
        if ($blend) {
            glEnable(GL_BLEND);
        } else {
            glDisable(GL_BLEND);
        }
    }

    /** The target's last frame, from its upright copy, drawn back into the multisampled framebuffer after an upload. */
    private function restore(int $whole, int $upright): void
    {
        glBindFramebuffer(GL_FRAMEBUFFER, $this->msaaFbo);
        glDisable(GL_SCISSOR_TEST);
        $this->paint(self::COPY, 0, blend: false);
        glBindTexture(GL_TEXTURE_2D, $upright);
        glDrawArrays(GL_TRIANGLES, $whole, 6);
        glBindTexture(GL_TEXTURE_2D, $this->blank);
        $this->stale = false;
    }

    /** An image source as an RGBA8 texture, rows top-down as the fragment shader samples them. */
    private function upload(Framebuffer $source): int
    {
        $width = $source->viewportWidth();
        $height = $source->viewportHeight();
        $this->fits($width, $height);
        $texture = $this->texture($width, $height);
        glBindTexture(GL_TEXTURE_2D, $texture);
        self::tightlyPacked(pack: false);
        glTexSubImage2D(GL_TEXTURE_2D, 0, 0, 0, $width, $height, GL_RGBA, GL_UNSIGNED_BYTE, $source->toRgba8());
        glBindTexture(GL_TEXTURE_2D, 0);

        return $texture;
    }

    /** A copy of this device's $texture with its rows top-down: a flipped blit on the GPU. */
    private function upright(int $texture): int
    {
        $width = $this->textureWidths[$texture];
        $height = $this->textureHeights[$texture];
        $copy = $this->texture($width, $height);
        [$read, $draw] = glGenFramebuffers(2);
        glBindFramebuffer(GL_READ_FRAMEBUFFER, $read);
        glFramebufferTexture2D(GL_READ_FRAMEBUFFER, GL_COLOR_ATTACHMENT0, GL_TEXTURE_2D, $texture, 0);
        glBindFramebuffer(GL_DRAW_FRAMEBUFFER, $draw);
        glFramebufferTexture2D(GL_DRAW_FRAMEBUFFER, GL_COLOR_ATTACHMENT0, GL_TEXTURE_2D, $copy, 0);
        glDisable(GL_SCISSOR_TEST);
        glBlitFramebuffer(0, 0, $width, $height, 0, $height, $width, 0, GL_COLOR_BUFFER_BIT, GL_NEAREST);
        glBindFramebuffer(GL_FRAMEBUFFER, 0);
        glDeleteFramebuffers([$read, $draw]);

        return $copy;
    }

    /** An RGBA8 texture of this size, contents undefined, sampled texel for texel. */
    private function texture(int $width, int $height): int
    {
        [$texture] = glGenTextures(1);
        glBindTexture(GL_TEXTURE_2D, $texture);
        glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_MIN_FILTER, GL_NEAREST);
        glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_MAG_FILTER, GL_NEAREST);
        glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_WRAP_S, GL_CLAMP_TO_EDGE);
        glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_WRAP_T, GL_CLAMP_TO_EDGE);
        glTexImage2D(GL_TEXTURE_2D, 0, GL_RGBA8, $width, $height, 0, GL_RGBA, GL_UNSIGNED_BYTE, null);
        glBindTexture(GL_TEXTURE_2D, 0);
        $this->textureWidths[$texture] = $width;
        $this->textureHeights[$texture] = $height;

        return $texture;
    }

    /** @throws DrawingException When $width × $height is past this context's texture limit. */
    private function fits(int $width, int $height): void
    {
        $largest = $this->maxTextureSize();
        if ($width > $largest || $height > $largest) {
            throw new DrawingException("opengl: {$width} × {$height} is past the largest texture this context makes, {$largest} × {$largest}.");
        }
    }

    /** The frame's own textures (sources, the restore's copy) go when it ends; GL keeps them until the GPU is done. */
    private function dropSources(): void
    {
        if ($this->sourceTextures === []) {
            return;
        }
        foreach ($this->sourceTextures as $texture) {
            unset($this->textureWidths[$texture], $this->textureHeights[$texture]);
        }
        glDeleteTextures($this->sourceTextures);
        $this->sourceTextures = [];
    }

    /** Six vertices: x0,y0 x1,y0 x1,y1 x0,y0 x1,y1 x0,y1, as Lowering lays a quad. */
    private static function quad(float $x0, float $y0, float $x1, float $y1): string
    {
        return pack('g12', $x0, $y0, $x1, $y0, $x1, $y1, $x0, $y0, $x1, $y1, $x0, $y1);
    }

    /**
     * Take the canvas's context as this device's: its 'context' handle (a
     * CGLContextObj or EGLContext address) and, on EGL, its 'display'. The
     * device prepares it (dialect, program, vertex array) and never destroys it.
     */
    public function adopt(LentSurface $surface): void
    {
        if ($this->hasContext) {
            throw new DrawingException('opengl: this device already has a context.');
        }
        if (PHP_OS_FAMILY === 'Darwin') {
            $this->cgl = CGLContextObj::fromPointer($surface->handle('context'));
        } else {
            $this->eglDisplay = EGLDisplay::fromPointer($surface->handle('display'));
            $this->egl = EGLContext::fromPointer($surface->handle('context'));
        }
        $this->adopted = true;
        $this->surface = $surface;
        $this->hasContext = true;
        $this->with(fn () => $this->prepareContext());
        // The canvas releases the surface while the context still exists: everything goes then.
        $surface->onRelease(fn () => $this->letGo(true));
    }

    /**
     * In a lent context: copy the resolved target into the framebuffer bound for
     * drawing, at the surface's size, and leave it bound. A GL canvas calls this
     * inside its render callback, where the toolkit has bound the view's
     * framebuffer. A device with its own context has nothing to present into.
     */
    public function present(LentSurface $surface): bool
    {
        if (! $this->adopted || $surface->released()) {
            return false;
        }
        $this->live();
        $this->with(function () use ($surface): void {
            glGetIntegerv(GL_DRAW_FRAMEBUFFER_BINDING, $bound);
            [$width, $height] = $surface->size();
            $this->copyInto($bound[0] ?? 0, $width, $height, 'present()');
        });

        return true;
    }

    /**
     * Copy the resolved target into GL framebuffer $framebuffer, stretched to
     * $width × $height, inside the view's render callback (its context current).
     * Rows stay the right way up: both are GL framebuffers, row 0 at the bottom.
     * $framebuffer is left bound.
     */
    public function blitTo(int $framebuffer, int $width, int $height): void
    {
        $this->live();
        if (! $this->adopted) {
            throw new DrawingException("opengl: blitTo() copies into a canvas's framebuffer, in the context the canvas lent; this device has its own.");
        }
        $this->with(fn () => $this->copyInto($framebuffer, $width, $height, 'blitTo()'));
    }

    /**
     * Delete the target, every texture of a framebuffer still alive, the program
     * and the vertex array, and destroy the context when it is this device's own.
     * A lent context stays as it was, current or not.
     */
    public function release(): void
    {
        $this->letGo(! $this->lost());
    }

    /**
     * release()'s work. $live: the context still exists, so the device's objects are deleted in
     * it; an adopted device gets this from the surface's release, which the canvas runs before it
     * takes the context away (GTK and Qt contexts share objects with contexts that outlive them).
     */
    private function letGo(bool $live): void
    {
        if ($this->released) {
            return;
        }
        if ($this->hasContext && $live) {
            $this->with(function (): void {
                glFinish();
                $this->releaseTarget();
                $this->dropSources();
                if ($this->textureHeights !== []) {
                    glDeleteTextures(array_keys($this->textureHeights));
                    $this->textureWidths = $this->textureHeights = [];
                }
                if ($this->vao !== 0) {
                    glDeleteVertexArrays([$this->vao]);
                    glDeleteBuffers([$this->vbo]);
                    $this->vao = $this->vbo = 0;
                }
                if ($this->program !== 0) {
                    glDeleteProgram($this->program);
                    $this->program = 0;
                }
            });
            // Destroyed, not un-made-current: whatever the caller had current stays.
            if (! $this->adopted && ! is_null($this->cgl)) {
                CGLReleaseContext($this->cgl);
            }
            if (! $this->adopted && ! is_null($this->egl)) {
                eglDestroyContext($this->eglDisplay, $this->egl);
            }
        }
        // Released without $live: the context went first, and only it can free what was in it.
        $this->cgl = $this->egl = $this->eglDisplay = null;
        $this->surface = null;
        $this->released = true;
    }

    /** Dialect, sample limit, program, vertex array: once per context. */
    protected function prepareContext(): void
    {
        $language = glGetString(GL_SHADING_LANGUAGE_VERSION) ?? '';
        $this->dialect = str_contains($language, 'ES') ? 'es' : 'core';
        glGetIntegerv(GL_MAX_SAMPLES, $samples);
        $this->maxSamples = $samples[0] ?? 1;
        glGetIntegerv(GL_MAX_TEXTURE_SIZE, $largest);
        $this->maxTextureSize = $largest[0] ?? 0;

        $this->program = glCreateProgram();
        // Shaders are flagged for deletion once attached: they go with the program.
        foreach ([GL_VERTEX_SHADER => self::VERTEX_SHADER, GL_FRAGMENT_SHADER => self::FRAGMENT_SHADER] as $type => $path) {
            try {
                $shader = $this->compile($type, $path);
            } catch (DrawingException $e) {
                glDeleteProgram($this->program);
                $this->program = 0;

                throw $e;
            }
            glAttachShader($this->program, $shader);
            glDeleteShader($shader);
        }
        glBindAttribLocation($this->program, 0, 'point');
        glLinkProgram($this->program);
        glGetProgramiv($this->program, GL_LINK_STATUS, $ok);
        if ($ok !== GL_TRUE) {
            $log = glGetProgramInfoLog($this->program);
            glDeleteProgram($this->program);
            $this->program = 0;

            throw new DrawingException('opengl: the program did not link: '.$log);
        }
        foreach (['size', 'mode_opacity', 'rgba_in', 'shape', 'band', 'abcd', 'efwh', 'source'] as $name) {
            $this->uniforms[$name] = glGetUniformLocation($this->program, $name);
        }

        $this->blank = $this->texture(1, 1);
        glBindTexture(GL_TEXTURE_2D, $this->blank);
        glTexSubImage2D(GL_TEXTURE_2D, 0, 0, 0, 1, 1, GL_RGBA, GL_UNSIGNED_BYTE, "\0\0\0\0");
        glBindTexture(GL_TEXTURE_2D, 0);

        [$this->vao] = glGenVertexArrays(1);
        [$this->vbo] = glGenBuffers(1);
        glBindVertexArray($this->vao);
        glBindBuffer(GL_ARRAY_BUFFER, $this->vbo);
        glEnableVertexAttribArray(0);
        glVertexAttribPointer(0, 2, GL_FLOAT, false, 8, 0);
        glBindVertexArray(0);
        $this->check('context setup');
    }

    private function compile(int $type, string $path): int
    {
        $shader = glCreateShader($type);
        glShaderSource($shader, [self::HEADERS[$this->dialect], file_get_contents($path)]);
        glCompileShader($shader);
        glGetShaderiv($shader, GL_COMPILE_STATUS, $ok);
        if ($ok !== GL_TRUE) {
            $log = glGetShaderInfoLog($shader);
            glDeleteShader($shader);

            throw new DrawingException('opengl: '.basename($path).' did not compile under '.trim(self::HEADERS[$this->dialect]).': '.$log);
        }

        return $shader;
    }

    /**
     * Run $gl with this device's context current, then put back the context
     * that was current before (or none).
     *
     * @template T
     *
     * @param  Closure(): T  $gl
     * @return T
     */
    protected function with(Closure $gl): mixed
    {
        if ($this->isCurrent()) {
            return $gl();
        }
        if (! is_null($this->cgl)) {
            $previous = CGLGetCurrentContext();
            $this->makeCurrent();
            try {
                return $gl();
            } finally {
                CGLSetCurrentContext($previous);
            }
        }
        if (! is_null($this->egl)) {
            // A context is current on its own display with its own surfaces: all four go back.
            $previous = eglGetCurrentContext();
            $display = eglGetCurrentDisplay();
            $draw = eglGetCurrentSurface(EGL_DRAW);
            $read = eglGetCurrentSurface(EGL_READ);
            $this->makeCurrent();
            try {
                return $gl();
            } finally {
                is_null($previous)
                    ? eglMakeCurrent($this->eglDisplay, null, null, null)
                    : eglMakeCurrent($display, $draw, $read, $previous);
            }
        }

        throw new DrawingException('opengl: no context yet: adopt() a surface first.');
    }

    /** @throws DrawingException When the context cannot be made current here (on EGL: current on another thread). */
    private function makeCurrent(): void
    {
        if (! is_null($this->cgl)) {
            $error = CGLSetCurrentContext($this->cgl);
            if ($error !== kCGLNoError) {
                throw new DrawingException('opengl: the context could not be made current: '.CGLErrorString($error).'.');
            }

            return;
        }
        if (! eglMakeCurrent($this->eglDisplay, null, null, $this->egl)) {
            throw new DrawingException(sprintf('opengl: the context could not be made current (0x%04x).', eglGetError()));
        }
    }

    /** True when this device's context is the one current on this thread. */
    private function isCurrent(): bool
    {
        if (! is_null($this->cgl)) {
            return CGLGetCurrentContext()?->pointer() === $this->cgl->pointer();
        }
        if (! is_null($this->egl)) {
            return eglGetCurrentContext()?->pointer() === $this->egl->pointer();
        }

        return false;
    }

    /** @throws DrawingException Naming $where when GL recorded an error. */
    protected function check(string $where): void
    {
        $error = glGetError();
        if ($error !== GL_NO_ERROR) {
            throw new DrawingException(sprintf('opengl: %s left GL error 0x%04x.', $where, $error));
        }
    }

    /** Rows of whole RGBA8 pixels, back to back, from the start of the string: no buffer bound, alignment 1, no row length, no skips. */
    private static function tightlyPacked(bool $pack): void
    {
        glBindBuffer($pack ? GL_PIXEL_PACK_BUFFER : GL_PIXEL_UNPACK_BUFFER, 0);
        glPixelStorei($pack ? GL_PACK_ALIGNMENT : GL_UNPACK_ALIGNMENT, 1);
        glPixelStorei($pack ? GL_PACK_ROW_LENGTH : GL_UNPACK_ROW_LENGTH, 0);
        glPixelStorei($pack ? GL_PACK_SKIP_ROWS : GL_UNPACK_SKIP_ROWS, 0);
        glPixelStorei($pack ? GL_PACK_SKIP_PIXELS : GL_UNPACK_SKIP_PIXELS, 0);
    }

    private function heightOf(int $texture): int
    {
        return $this->textureHeights[$texture] ?? throw new DrawingException('opengl: unknown texture.');
    }

    /** Rows of $rowBytes each, reversed: GL's bottom-up to Surface's top-down and back. */
    private static function flip(string $rows, int $rowBytes): string
    {
        return implode('', array_reverse(str_split($rows, $rowBytes)));
    }

    private function requireContext(): void
    {
        if (! $this->hasContext) {
            throw new DrawingException('opengl: no context yet: adopt() a surface first.');
        }
    }

    private function lost(): bool
    {
        return $this->surface?->released() === true;
    }

    private function live(): void
    {
        if ($this->released) {
            throw new DrawingException('opengl: this device was released.');
        }
        $this->requireContext();
        if ($this->lost()) {
            throw new DrawingException('opengl: the canvas has taken its GL context back; release() the engine.');
        }
    }

    /** The target's objects; the resolved texture is its OpenGLFramebuffer's. */
    private function releaseTarget(): void
    {
        if ($this->resolvedFbo !== 0) {
            glDeleteFramebuffers(array_values(array_filter([$this->resolvedFbo, $this->msaaFbo])));
        }
        $renderbuffers = array_values(array_filter([$this->stencil, $this->msaaColor]));
        if ($renderbuffers !== []) {
            glDeleteRenderbuffers($renderbuffers);
        }
        $this->resolvedFbo = $this->msaaFbo = $this->stencil = $this->msaaColor = 0;
        $this->resolved = 0;
        $this->framebuffer = null;
    }
}
