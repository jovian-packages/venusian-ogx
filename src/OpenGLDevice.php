<?php

namespace Jovian\Engines\OpenGL;

use CGLContextObj;
use Closure;
use NSColorSpace;
use Surface\Framebuffers\HdrImage;
use Surface\Drawing\Gpu\TargetsFormats;
use Surface\Drawing\Gpu\DamageHistory;
use Surface\Contracts\Drawing\TargetFormat;
use Surface\Contracts\Drawing\ColorSpace;
use EGLContext;
use EGLDisplay;
use FbBuffer;
use FbFormat;
use Surface\Contracts\Drawing\DrawingException;
use Surface\Contracts\Drawing\LentSurface;
use Surface\Contracts\Drawing\SurfaceKind;
use Surface\Contracts\Framebuffers\Filter;
use Surface\Contracts\Framebuffers\Framebuffer;
use Surface\Contracts\Framebuffers\GLFramebuffer;
use Surface\Contracts\Framebuffers\Region;
use Surface\Contracts\Rasterize\FillRule;
use Surface\Contracts\Windows\ScaleFilter;
use Surface\Drawing\Gpu\DrawList;
use Surface\Drawing\Gpu\DrawsGlass;
use Surface\Drawing\Gpu\FilterPlan;
use Surface\Drawing\Gpu\GpuDevice;
use Surface\Drawing\Gpu\Op;

/**
 * The opengl engine's device: one GL context (its own, offscreen, or the one a
 * canvas lends), a persistent target, and the program that draws a DrawList
 * into it.
 *
 * The target is an RGBA8 texture ($resolved) in a framebuffer object
 * ($resolvedFbo): read back, uploaded to, and shown from
 * (the show pass) or blitted from (a canvas's blitTo()). With four samples
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
 *
 * HDR: every HDR target is an RGBA16F texture of linear light in sRGB
 * primaries, SDR white at 1.0, drawn in any context that renders half
 * floats. A host's context is swapped by the host in its own SDR format, so
 * a target past RGBA8 sRGB is shown only where the window handed over its
 * native window (handles() asks with 'native'): the device then makes a
 * context and window surface in the colour space itself (WindowSurface),
 * presents through the show pass and swaps, naming the changed rects.
 */
class OpenGLDevice implements GpuDevice, TargetsFormats, DrawsGlass
{
    public const string VERTEX_SHADER = __DIR__.'/../resources/shaders/venusian.vert.glsl';

    public const string FRAGMENT_SHADER = __DIR__.'/../resources/shaders/venusian.frag.glsl';

    public const string FILTER_FRAGMENT_SHADER = __DIR__.'/../resources/shaders/filter.frag.glsl';

    public const string GLASS_FRAGMENT_SHADER = __DIR__.'/../resources/shaders/glass.frag.glsl';

    public const string SHOW_VERTEX_SHADER = __DIR__.'/../resources/shaders/show.vert.glsl';

    public const string SHOW_FRAGMENT_SHADER = __DIR__.'/../resources/shaders/show.frag.glsl';

    /** The dialect header prepended to each body: by GL_SHADING_LANGUAGE_VERSION, 'es' when it names "ES". */
    public const array HEADERS = [
        'core' => "#version 150 core\n",
        'es' => "#version 300 es\nprecision highp float;\nprecision highp int;\nprecision highp sampler2D;\n",
    ];

    protected const int SOLID = 0;

    protected const int GLASS = 9;

    protected const int FILL = 1;

    protected const int OVAL = 2;

    protected const int IMAGE = 3;

    protected const int COPY = 4;

    protected const int ROUND = 5;

    protected const int SHADE = 6;

    protected const int GLYPH = 7;

    protected const int GRADIENT = 8;

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

    /** The colour space a window engine was made for: anything past sRGB asks the window for its native handles. */
    private ColorSpace $wants = ColorSpace::Srgb;

    /** The target's colour space. */
    private ColorSpace $space = ColorSpace::Srgb;

    /** The window surface this device made on a window's native handles; null in a host's context or its own offscreen one. */
    private ?WindowSurface $window = null;

    private ?DamageHistory $history = null;

    /** @var array<int, true> Half-float source textures, by name. */
    private array $floatTextures = [];

    private ?string $dialect = null;

    private ?int $maxSamples = null;

    private ?int $maxTextureSize = null;

    private int $program = 0;

    /** @var array<string, int> Uniform locations by name. */
    private array $uniforms = [];

    /** The vertex array and buffer this frame draws with: the ring's current slot. */
    private int $vao = 0;

    private int $vbo = 0;

    /** Vertex buffers in the ring (G3): one per frame in flight, each with a vertex array of its own. */
    private const int RING = 3;

    /** The smallest a ring buffer is made: 256 KiB holds about 32,000 vertices. */
    private const int RING_MIN = 262144;

    /** @var list<array{vao: int, vbo: int, size: int}> */
    private array $ring = [];

    private int $ringAt = 0;

    /** Source textures kept for each size, and the sizes kept. */
    private const int POOL_PER_SIZE = 8;

    private const int POOL_SIZES = 24;

    /** @var array<string, list<int>> Free source textures by "w x h x float", the size used last at the end. */
    private array $pool = [];

    /** The show pass's program, its uniforms, and the empty vertex array it draws its triangle with. */
    private int $showProgram = 0;

    /** @var array<string, int> */
    private array $showUniforms = [];

    private int $showVao = 0;

    /** The filter program (a pass of Surface's FilterPlan a draw), its uniforms, and the framebuffer its passes render into. */
    private int $filterProgram = 0;

    /** @var array<string, int> */
    private array $filterUniforms = [];

    private int $filterFbo = 0;

    /** The glass passes' program (the pyramid's capture, down and up) and its uniforms. */
    private int $glassProgram = 0;

    /** @var array<string, int> */
    private array $glassUniforms = [];

    /** Pyramid levels no frame holds, by size, to be taken again; over GLASS_POOL_BYTES the sizes used longest ago go first. */
    private const int GLASS_POOL_BYTES = 134217728;

    /** @var array<string, list<int>> Texture names by "WxH". */
    private array $glassPool = [];

    private int $glassBytes = 0;

    /** @var array<int, array{panes: array{sharp: int, samples: array<int, int>}, textures: list<array{int, int, int}>}> The last frame's glass backdrops by their place in the frame, kept while Lowering says so. */
    private array $glassKept = [];

    /** @var array<int, array{texture: int, fbo: int, msaaFbo: int, msaaColor: int, stencil: int}> Layer targets by number (1 up), made on first use at the target's size and kept until it changes. */
    private array $slots = [];

    /** @var array<int, true> The layer targets whose texture is the answer already: a backdrop copied into it, or a filter run over it; resolving the multisampled drawing again would write it over. */
    private array $baked = [];

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

    /** @var array<int, int> Glyph atlases (single-channel textures) by number, kept across draws until remade or released. */
    private array $atlases = [];

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
            $display = eglGetPlatformDisplay(EGL_PLATFORM_SURFACELESS_MESA, null, null)
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

    /** A device with no context of its own: adopt() hands it the window's, or the window's native handles for $space past sRGB. */
    public static function adopting(ColorSpace $space = ColorSpace::Srgb): static
    {
        $device = (new \ReflectionClass(static::class))->newInstanceWithoutConstructor();
        $device->wants = $space;

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
        return $this->wants === ColorSpace::Srgb ? [] : ['native' => 1];
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

    private function make(int $width, int $height, int $samples, ColorSpace $space): GLFramebuffer
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

        return $this->with(function () use ($width, $height, $samples, $space): OpenGLFramebuffer {
            $this->releaseTarget();
            $this->space = $space;
            $this->samples = $samples;
            $this->stale = false;

            $this->resolved = $this->texture($width, $height, $space->isHdr());
            if ($space->isHdr()) {
                $this->floatTextures[$this->resolved] = true;
            }

            [$this->resolvedFbo] = self::names('glGenFramebuffers');
            glBindFramebuffer(GL_FRAMEBUFFER, $this->resolvedFbo);
            glFramebufferTexture2D(GL_FRAMEBUFFER, GL_COLOR_ATTACHMENT0, GL_TEXTURE_2D, $this->resolved, 0);

            [$this->stencil] = self::names('glGenRenderbuffers');
            glBindRenderbuffer(GL_RENDERBUFFER, $this->stencil);
            if ($samples === 4) {
                [$this->msaaFbo] = self::names('glGenFramebuffers');
                [$this->msaaColor] = self::names('glGenRenderbuffers');
                glBindRenderbuffer(GL_RENDERBUFFER, $this->msaaColor);
                glRenderbufferStorageMultisample(GL_RENDERBUFFER, 4, $space->isHdr() ? GL_RGBA16F : GL_RGBA8, $width, $height);
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
                throw new DrawingException(sprintf('opengl: the %d × %d %s target is incomplete (0x%04x)%s.', $width, $height, $space->isHdr() ? 'half-float' : 'RGBA8', $status, $space->isHdr() ? ': this context does not render into half floats' : ''));
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

            return $this->framebuffer = new OpenGLFramebuffer($this, $this->resolved, $width, $height, $space->isHdr());
        });
    }

    public function target(int $width, int $height, int $samples): GLFramebuffer
    {
        return $this->make($width, $height, $samples, ColorSpace::Srgb);
    }

    public function targetFormats(): array
    {
        if (PHP_OS_FAMILY === 'Darwin' && ! class_exists(NSColorSpace::class)) {
            return [];
        }

        return [
            [TargetFormat::Rgba16Float, ColorSpace::ExtendedLinearSrgb],
            [TargetFormat::Rgba16Float, ColorSpace::ExtendedLinearDisplayP3],
            [TargetFormat::Rgb10A2, ColorSpace::Hdr10Pq],
            [TargetFormat::Rgba8, ColorSpace::DisplayP3],
        ];
    }

    public function targetAs(int $width, int $height, int $samples, TargetFormat $format, ColorSpace $space): GLFramebuffer
    {
        if (! in_array([$format, $space], $this->targetFormats(), true)) {
            throw new DrawingException("opengl makes no {$format->value} {$space->value} target.");
        }
        if ($this->adopted && is_null($this->window)) {
            throw new DrawingException("opengl: a GL context lent by its host shows RGBA8 sRGB only; a {$space->value} target needs a window that hands over its native window (a GLFW or SDL3 staged window).");
        }
        if (! is_null($this->window) && $space !== $this->wants) {
            throw new DrawingException("opengl: this device's window surface was made for {$this->wants->value}, not {$space->value}.");
        }

        return $this->make($width, $height, $samples, $space);
    }

    /** @internal OpenGLFramebuffer's readback: $region of $texture as a frame string, rows top-down. */
    public function read(int $texture, Region $region, bool $float = false): string
    {
        $this->live();

        return $this->with(function () use ($texture, $region, $float): string {
            [$fbo] = self::names('glGenFramebuffers');
            try {
                glBindFramebuffer(GL_READ_FRAMEBUFFER, $fbo);
                glFramebufferTexture2D(GL_READ_FRAMEBUFFER, GL_COLOR_ATTACHMENT0, GL_TEXTURE_2D, $texture, 0);
                glReadBuffer(GL_COLOR_ATTACHMENT0);
                self::tightlyPacked(pack: true);
                $height = $this->heightOf($texture);
                // ext-opengl writes into an address only: an ext-fb buffer of RGBA8 cells, two a texel for half floats, holds the rows.
                $rows = new FbBuffer(new FbFormat(FB_LAYOUT_RGBA8888, channelOrder: FB_CHANNELS_RGBA), $region->width * ($float ? 2 : 1), $region->height);
                glReadPixels($region->x, $height - $region->bottom(), $region->width, $region->height, GL_RGBA, $float ? GL_HALF_FLOAT : GL_UNSIGNED_BYTE, $rows->pointer());
                $bytes = $rows->bytes();
            } finally {
                glBindFramebuffer(GL_READ_FRAMEBUFFER, 0);
                glDeleteFramebuffers(1, [$fbo]);
            }
            $this->check('read()');

            return self::flip($bytes, $region->width * ($float ? 8 : 4));
        });
    }

    /** @internal OpenGLFramebuffer's upload: $rgba8 (rows top-down) over $region of $texture. */
    public function write(int $texture, string $texels, Region $region, bool $float = false): void
    {
        $this->live();
        $this->with(function () use ($texture, $texels, $region, $float): void {
            self::tightlyPacked(pack: false);
            glBindTexture(GL_TEXTURE_2D, $texture);
            $height = $this->heightOf($texture);
            glTexSubImage2D(GL_TEXTURE_2D, 0, $region->x, $height - $region->bottom(), $region->width, $region->height, GL_RGBA, $float ? GL_HALF_FLOAT : GL_UNSIGNED_BYTE, self::flip($texels, $region->width * ($float ? 8 : 4)));
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
        unset($this->textureWidths[$texture], $this->textureHeights[$texture], $this->floatTextures[$texture]);
        glDeleteTextures(1, [$texture]);
    }

    /** Wait for the GPU to finish everything issued so far. */
    public function finish(): void
    {
        $this->live();
        $this->with(fn () => glFinish());
    }

    /** The operations draw() handles. */
    private const array OPS = [Op::CLEAR, Op::SCISSOR, Op::SOLID, Op::STENCIL_FILL, Op::COVER, Op::COVER_GRADIENT, Op::RECTS, Op::ELLIPSE, Op::RING, Op::UPLOAD, Op::IMAGE, Op::ATLAS, Op::MASK, Op::GLYPHS, Op::ROUNDRECT, Op::ROUNDRING, Op::SHADOW, Op::TARGET, Op::BACKDROP, Op::FILTER, Op::LAYER_SOURCE, Op::GLASS_BACKDROP, Op::GLASS];

    /**
     * Every operation of $list is one draw() handles: checked before any GPU work, so an unknown one fails the
     * frame loudly instead of being skipped.
     *
     * @throws DrawingException On the first operation it does not handle.
     */
    private static function known(DrawList $list): void
    {
        foreach ($list->operations as $operation) {
            if (! in_array($operation[0], self::OPS, true)) {
                $op = $operation[0] instanceof Op ? $operation[0]->name : get_debug_type($operation[0]);

                throw new DrawingException("opengl: unknown draw op {$op}");
            }
        }
    }

    public function draw(DrawList $list): void
    {
        $this->live();
        $target = $this->framebuffer ?? throw new DrawingException('opengl: target() comes before draw().');
        $width = $target->width();
        $height = $target->height();
        self::known($list);

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
        $this->ringAt = ($this->ringAt + 1) % self::RING;
        $slot = &$this->ring[$this->ringAt];
        [$this->vao, $this->vbo] = [$slot['vao'], $slot['vbo']];
        glBindVertexArray($this->vao);
        glBindBuffer(GL_ARRAY_BUFFER, $this->vbo);
        if ($slot['size'] < strlen($vertices)) {
            $slot['size'] = max(self::RING_MIN, 1 << (int) ceil(log(strlen($vertices), 2)));
            glBufferData(GL_ARRAY_BUFFER, $slot['size'], null, GL_STREAM_DRAW);
        }
        glBufferSubData(GL_ARRAY_BUFFER, 0, strlen($vertices), $vertices);
        unset($slot);

        glUseProgram($this->program);
        glUniform2f($this->uniforms['size'], (float) $width, (float) $height);
        glUniform1i($this->uniforms['source'], 0);
        glUniform1i($this->uniforms['sharp'], 1);
        glActiveTexture(GL_TEXTURE0 + 1);
        glBindTexture(GL_TEXTURE_2D, $this->blank);
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
        $current = 0;
        /** @var array{sharp: int, samples: array<int, int>}|null $panes The backdrop of the panes drawn now. */
        $panes = null;
        /** @var array<int, true> $glassUsed The backdrops this frame drew, by place. */
        $glassUsed = [];

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

                case Op::COVER_GRADIENT:
                    // As COVER, in the gradient: the ramp is the source texture, the shader reads its entry for each pixel.
                    [, $first, $texture, $kind, $inverse, $spread] = $operation;
                    $ramp = $textures[$texture];
                    glEnable(GL_STENCIL_TEST);
                    glStencilFunc(GL_NOTEQUAL, 0, 0xFF);
                    glStencilOp(GL_KEEP, GL_KEEP, GL_ZERO);
                    $this->paint(self::GRADIENT, 0, blend: true);
                    glBindTexture(GL_TEXTURE_2D, $ramp);
                    glUniform4f($this->uniforms['abcd'], $inverse->a, $inverse->b, $inverse->c, $inverse->d);
                    glUniform4f($this->uniforms['efwh'], $inverse->e, $inverse->f, 0.0, 0.0);
                    glUniform4f($this->uniforms['band'], (float) $kind, (float) $spread, (float) $this->samples, 0.0);
                    glDrawArrays(GL_TRIANGLES, $first, 6);
                    glBindTexture(GL_TEXTURE_2D, $this->blank);
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

                case Op::ATLAS:
                    // Single-channel, zeroed (a new texture's bytes are undefined). GL keeps the old one while a frame reads it.
                    [, $atlas, $atlasWidth, $atlasHeight] = $operation;
                    $this->fits($atlasWidth, $atlasHeight);
                    if (isset($this->atlases[$atlas])) {
                        glDeleteTextures(1, [$this->atlases[$atlas]]);
                        unset($this->textureWidths[$this->atlases[$atlas]], $this->textureHeights[$this->atlases[$atlas]]);
                    }
                    [$made] = self::names('glGenTextures');
                    glBindTexture(GL_TEXTURE_2D, $made);
                    glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_MIN_FILTER, GL_NEAREST);
                    glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_MAG_FILTER, GL_NEAREST);
                    glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_WRAP_S, GL_CLAMP_TO_EDGE);
                    glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_WRAP_T, GL_CLAMP_TO_EDGE);
                    self::tightlyPacked(pack: false);
                    glTexImage2D(GL_TEXTURE_2D, 0, GL_R8, $atlasWidth, $atlasHeight, 0, GL_RED, GL_UNSIGNED_BYTE, str_repeat("\0", $atlasWidth * $atlasHeight));
                    glBindTexture(GL_TEXTURE_2D, $this->blank);
                    $this->atlases[$atlas] = $made;
                    // Kept with the device's textures, so release() deletes it.
                    $this->textureWidths[$made] = $atlasWidth;
                    $this->textureHeights[$made] = $atlasHeight;
                    break;

                case Op::MASK:
                    // Rows top-down as the shader reads them: only regions no earlier frame drew from.
                    [, $atlas, $region, $bytes] = $operation;
                    self::tightlyPacked(pack: false);
                    glBindTexture(GL_TEXTURE_2D, $this->atlases[$atlas]);
                    glTexSubImage2D(GL_TEXTURE_2D, 0, $region->x, $region->y, $region->width, $region->height, GL_RED, GL_UNSIGNED_BYTE, $bytes);
                    glBindTexture(GL_TEXTURE_2D, $this->blank);
                    break;

                case Op::GLYPHS:
                    // The texels come from the frame's vertices again: attribute 1, offset so vertex k reads $texels + k.
                    [, $first, $count, $texels, $atlas, $rgba] = $operation;
                    $this->paint(self::GLYPH, $rgba, blend: true);
                    glBindTexture(GL_TEXTURE_2D, $this->atlases[$atlas]);
                    glEnableVertexAttribArray(1);
                    glVertexAttribPointer(1, 2, GL_FLOAT, false, 8, ($texels - $first) * 8);
                    glDrawArrays(GL_TRIANGLES, $first, $count);
                    glDisableVertexAttribArray(1);
                    glBindTexture(GL_TEXTURE_2D, $this->blank);
                    break;

                case Op::ROUNDRECT:
                case Op::ROUNDRING:
                    $ring = $operation[0] === Op::ROUNDRING;
                    [, $first, $x0, $y0, $x1, $y1, $r] = $operation;
                    $this->paint(self::ROUND, $operation[$ring ? 8 : 7], blend: true);
                    glUniform4f($this->uniforms['shape'], $x0, $y0, $x1, $y1);
                    glUniform4f($this->uniforms['radii'], (float) $r[0], (float) $r[1], (float) $r[2], (float) $r[3]);
                    glUniform4f($this->uniforms['band'], $ring ? (float) $operation[7] : 0.0, $ring ? 1.0 : 0.0, (float) $this->samples, 0.0);
                    glDrawArrays(GL_TRIANGLES, $first, 6);
                    break;

                case Op::SHADOW:
                    [, $first, $x0, $y0, $x1, $y1, $radius, $blur, $rgba] = $operation;
                    $this->paint(self::SHADE, $rgba, blend: true);
                    glUniform4f($this->uniforms['shape'], $x0, $y0, $x1, $y1);
                    glUniform4f($this->uniforms['band'], (float) $blur, (float) $radius, (float) $this->samples, 0.0);
                    glDrawArrays(GL_TRIANGLES, $first, 6);
                    break;

                case Op::TARGET:
                    // draws go to another target from here: a layer's own, or back to the frame's; each begins unscissored
                    [, $current, $cleared] = $operation;
                    $this->ensureSlot($current);
                    unset($this->baked[$current]);
                    glBindFramebuffer(GL_FRAMEBUFFER, $this->drawFramebuffer($current));
                    glDisable(GL_SCISSOR_TEST);
                    if (! is_null($cleared)) {
                        glClearStencil(0);
                        glStencilMask(0xFF);
                        glClear(GL_STENCIL_BUFFER_BIT);
                        glEnable(GL_SCISSOR_TEST);
                        glScissor($cleared->x, $height - $cleared->bottom(), $cleared->width, $cleared->height);
                        glClearColor(0.0, 0.0, 0.0, 0.0);
                        glColorMask(true, true, true, true);
                        glClear(GL_COLOR_BUFFER_BIT);
                    }
                    $scissor = [0, 0, $width, $height];
                    glEnable(GL_SCISSOR_TEST);
                    glScissor(...$scissor);
                    break;

                case Op::BACKDROP:
                    // [, $from, $into, $region]: the region of one target copied into another, no blending
                    [, $from, $into, $region] = $operation;
                    $this->ensureSlot($into);
                    $this->resolve($from);
                    $this->baked[$into] = true;
                    glBindFramebuffer(GL_READ_FRAMEBUFFER, $this->resolveFramebuffer($from));
                    glBindFramebuffer(GL_DRAW_FRAMEBUFFER, $this->resolveFramebuffer($into));
                    glDisable(GL_SCISSOR_TEST);
                    glBlitFramebuffer($region->x, $height - $region->bottom(), $region->right(), $height - $region->y, $region->x, $height - $region->bottom(), $region->right(), $height - $region->y, GL_COLOR_BUFFER_BIT, GL_NEAREST);
                    $this->afterPasses($current, $width, $height, $scissor);
                    break;

                case Op::FILTER:
                    $this->filter($operation, $textures, $width, $height);
                    $this->afterPasses($current, $width, $height, $scissor);
                    break;

                case Op::GLASS_BACKDROP:
                    // [, $from, $region, $depths, $n, $keep]: the region read once and taken down and up the pyramid, or last frame's kept
                    [, $from, $region, $depths, $n, $keep] = $operation;
                    $glassUsed[$n] = true;
                    if ($keep && isset($this->glassKept[$n])) {
                        $panes = $this->glassKept[$n]['panes'];
                        break;
                    }
                    $made = [];
                    $panes = $this->glassBackdrop($from, $region, $depths, $width, $height, $made);
                    foreach ($this->glassKept[$n]['textures'] ?? [] as [$texture, $w, $h]) {
                        $this->releaseGlass($texture, $w, $h);
                    }
                    $this->glassKept[$n] = ['panes' => $panes, 'textures' => $made];
                    $this->afterPasses($current, $width, $height, $scissor);
                    break;

                case Op::GLASS:
                    [, $first, $geometry, $look, $depth, $region] = $operation;
                    if ($panes === null) {
                        break;
                    }
                    [$frost, $refraction, $bevel, $saturation, $glow, $grain, $tint, $rimColour, $alpha] = $look;
                    $this->paint(self::GLASS, $tint, blend: true);
                    glUniform4f($this->uniforms['shape'], (float) $geometry[0], (float) $geometry[1], (float) $geometry[2], (float) $geometry[3]);
                    glUniform4f($this->uniforms['radii'], (float) $geometry[4][0], (float) $geometry[4][1], (float) $geometry[4][2], (float) $geometry[4][3]);
                    glUniform4f($this->uniforms['abcd'], (float) $frost, (float) $refraction, (float) $bevel, (float) $saturation);
                    glUniform4f($this->uniforms['efwh'], (float) $glow, (float) $grain, (float) $region->x, (float) $region->y);
                    glUniform4f($this->uniforms['win'], 2.0, $frost < 1.0 ? 1.0 : 0.0, (float) $alpha, 0.0);
                    glUniform4f($this->uniforms['rim'], (float) (($rimColour >> 24) & 0xFF), (float) (($rimColour >> 16) & 0xFF), (float) (($rimColour >> 8) & 0xFF), (float) ($rimColour & 0xFF));
                    glActiveTexture(GL_TEXTURE0 + 1);
                    glBindTexture(GL_TEXTURE_2D, $panes['sharp']);
                    glActiveTexture(GL_TEXTURE0);
                    glBindTexture(GL_TEXTURE_2D, $panes['samples'][$depth]);
                    glDrawArrays(GL_TRIANGLES, $first, 6);
                    glBindTexture(GL_TEXTURE_2D, $this->blank);
                    glActiveTexture(GL_TEXTURE0 + 1);
                    glBindTexture(GL_TEXTURE_2D, $this->blank);
                    glActiveTexture(GL_TEXTURE0);
                    break;

                case Op::LAYER_SOURCE:
                    // [, $texture, $slot]: what the layer target holds, rows top-down, for the IMAGE that lays it down
                    [, $texture, $slot] = $operation;
                    $this->resolve($slot);
                    $textures[$texture] = $this->sourceTextures[] = $this->upright($this->slotTexture($slot));
                    $this->afterPasses($current, $width, $height, $scissor);
                    break;

                case Op::IMAGE:
                    [, $first, $texture, $inverse, $opacity, $filter, $tint, $window, $round] = $operation;
                    $source = $textures[$texture];
                    $this->paint(self::IMAGE, $tint, blend: true, opacity: $opacity, linear: $filter === Filter::LINEAR, floatSource: isset($this->floatTextures[$source]));
                    glBindTexture(GL_TEXTURE_2D, $source);
                    glUniform4f($this->uniforms['abcd'], $inverse->a, $inverse->b, $inverse->c, $inverse->d);
                    glUniform4f($this->uniforms['efwh'], $inverse->e, $inverse->f, (float) $this->textureWidths[$source], (float) $this->textureHeights[$source]);
                    glUniform4f($this->uniforms['band'], is_null($round) ? 0.0 : 1.0, is_null($window) ? 0.0 : 1.0, (float) $this->samples, 0.0);
                    glUniform4f($this->uniforms['win'], (float) ($window[0] ?? 0.0), (float) ($window[1] ?? 0.0), (float) ($window[2] ?? 0.0), (float) ($window[3] ?? 0.0));
                    if (! is_null($round)) {
                        [$x0, $y0, $x1, $y1, $r] = $round;
                        glUniform4f($this->uniforms['shape'], $x0, $y0, $x1, $y1);
                        glUniform4f($this->uniforms['radii'], (float) $r[0], (float) $r[1], (float) $r[2], (float) $r[3]);
                    }
                    glDrawArrays(GL_TRIANGLES, $first, 6);
                    glBindTexture(GL_TEXTURE_2D, $this->blank);
                    break;
            }
        }

        foreach (array_diff_key($this->glassKept, $glassUsed) as $n => $kept) {
            foreach ($kept['textures'] as [$texture, $w, $h]) {
                $this->releaseGlass($texture, $w, $h);
            }
            unset($this->glassKept[$n]);
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

    /** The framebuffer a target's operations draw into: the multisampled one where there are samples. */
    private function drawFramebuffer(int $slot): int
    {
        if ($slot === 0) {
            return $this->samples === 4 ? $this->msaaFbo : $this->resolvedFbo;
        }

        return $this->samples === 4 ? $this->slots[$slot]['msaaFbo'] : $this->slots[$slot]['fbo'];
    }

    /** The framebuffer that holds a target's single-sample texture. */
    private function resolveFramebuffer(int $slot): int
    {
        return $slot === 0 ? $this->resolvedFbo : $this->slots[$slot]['fbo'];
    }

    private function slotTexture(int $slot): int
    {
        return $slot === 0 ? $this->resolved : $this->slots[$slot]['texture'];
    }

    /** With samples, a target's drawing resolved into its texture, so the texture can be read. */
    private function resolve(int $slot): void
    {
        if ($this->samples !== 4 || isset($this->baked[$slot])) {
            return;
        }
        $width = $this->textureWidths[$this->resolved];
        $height = $this->textureHeights[$this->resolved];
        glBindFramebuffer(GL_READ_FRAMEBUFFER, $slot === 0 ? $this->msaaFbo : $this->slots[$slot]['msaaFbo']);
        glBindFramebuffer(GL_DRAW_FRAMEBUFFER, $this->resolveFramebuffer($slot));
        glDisable(GL_SCISSOR_TEST);
        glBlitFramebuffer(0, 0, $width, $height, 0, 0, $width, $height, GL_COLOR_BUFFER_BIT, GL_NEAREST);
    }

    /**
     * A layer target, made on first use: RGBA8 the size of the target, with a stencil, multisampled as the target is.
     *
     * @throws DrawingException On a target in an HDR colour space.
     */
    private function ensureSlot(int $slot): void
    {
        if ($slot === 0 || isset($this->slots[$slot])) {
            return;
        }
        if ($this->space->isHdr()) {
            throw new DrawingException('opengl: layers are not drawn on a target in an HDR colour space yet.');
        }
        $width = $this->textureWidths[$this->resolved];
        $height = $this->textureHeights[$this->resolved];
        $texture = $this->texture($width, $height);
        [$fbo, $msaaFbo] = self::names('glGenFramebuffers', 2);
        [$stencil, $msaaColor] = self::names('glGenRenderbuffers', 2);
        glBindFramebuffer(GL_FRAMEBUFFER, $fbo);
        glFramebufferTexture2D(GL_FRAMEBUFFER, GL_COLOR_ATTACHMENT0, GL_TEXTURE_2D, $texture, 0);
        glBindRenderbuffer(GL_RENDERBUFFER, $stencil);
        if ($this->samples === 4) {
            glRenderbufferStorageMultisample(GL_RENDERBUFFER, 4, GL_DEPTH24_STENCIL8, $width, $height);
            glBindRenderbuffer(GL_RENDERBUFFER, $msaaColor);
            glRenderbufferStorageMultisample(GL_RENDERBUFFER, 4, GL_RGBA8, $width, $height);
            glBindFramebuffer(GL_FRAMEBUFFER, $msaaFbo);
            glFramebufferRenderbuffer(GL_FRAMEBUFFER, GL_COLOR_ATTACHMENT0, GL_RENDERBUFFER, $msaaColor);
            glFramebufferRenderbuffer(GL_FRAMEBUFFER, GL_DEPTH_STENCIL_ATTACHMENT, GL_RENDERBUFFER, $stencil);
        } else {
            glRenderbufferStorage(GL_RENDERBUFFER, GL_DEPTH24_STENCIL8, $width, $height);
            glBindFramebuffer(GL_FRAMEBUFFER, $fbo);
            glFramebufferRenderbuffer(GL_FRAMEBUFFER, GL_DEPTH_STENCIL_ATTACHMENT, GL_RENDERBUFFER, $stencil);
        }
        glBindRenderbuffer(GL_RENDERBUFFER, 0);
        $status = glCheckFramebufferStatus(GL_FRAMEBUFFER);
        glBindFramebuffer(GL_FRAMEBUFFER, 0);
        if ($status !== GL_FRAMEBUFFER_COMPLETE) {
            throw new DrawingException(sprintf('opengl: the layer target is incomplete (0x%04x).', $status));
        }
        $this->slots[$slot] = ['texture' => $texture, 'fbo' => $fbo, 'msaaFbo' => $msaaFbo, 'msaaColor' => $msaaColor, 'stencil' => $stencil];
    }

    /** The state the drawing operations expect, put back after a pass that changed it. */
    private function afterPasses(int $slot, int $width, int $height, array $scissor): void
    {
        glBindFramebuffer(GL_FRAMEBUFFER, $this->drawFramebuffer($slot));
        glUseProgram($this->program);
        glBindVertexArray($this->vao);
        glBindBuffer(GL_ARRAY_BUFFER, $this->vbo);
        glViewport(0, 0, $width, $height);
        glActiveTexture(GL_TEXTURE0 + 1);
        glBindTexture(GL_TEXTURE_2D, $this->blank);
        glActiveTexture(GL_TEXTURE0);
        glBindTexture(GL_TEXTURE_2D, $this->blank);
        glEnable(GL_SCISSOR_TEST);
        glScissor(...$scissor);
        glBlendEquation(GL_FUNC_ADD);
        glBlendFuncSeparate(GL_SRC_ALPHA, GL_ONE_MINUS_SRC_ALPHA, GL_ONE, GL_ONE_MINUS_SRC_ALPHA);
        glColorMask(true, true, true, true);
        glStencilMask(0xFF);
    }

    /**
     * The backdrop under a list's glass panes: $region of target $from copied out once, taken down the dual-filter pyramid to
     * the deepest depth asked, and up to half size for each depth: small passes of the glass program, each into a pooled level
     * of its own size, the first reading an upright copy of the target.
     *
     * @param  list<int>  $depths
     * @param  list<array{int, int, int}>  $made  The levels made, [texture, width, height], for the pool when nothing keeps them.
     * @return array{sharp: int, samples: array<int, int>}
     */
    private function glassBackdrop(int $from, Region $region, array $depths, int $width, int $height, array &$made): array
    {
        $this->resolve($from);
        $source = $this->sourceTextures[] = $this->upright($this->slotTexture($from));

        glUseProgram($this->glassProgram);
        glBindVertexArray($this->showVao);
        glBindFramebuffer(GL_FRAMEBUFFER, $this->filterFbo);
        glDisable(GL_BLEND);
        glDisable(GL_STENCIL_TEST);
        glDisable(GL_SCISSOR_TEST);
        glColorMask(true, true, true, true);
        glActiveTexture(GL_TEXTURE0);
        $pass = function (int $kind, int $input, int $w, int $h, float $a, float $b) use (&$made): int {
            $out = $this->glassLevel($w, $h);
            $made[] = [$out, $w, $h];
            glFramebufferTexture2D(GL_FRAMEBUFFER, GL_COLOR_ATTACHMENT0, GL_TEXTURE_2D, $out, 0);
            glViewport(0, 0, $w, $h);
            glUniform4f($this->glassUniforms['kind'], (float) $kind, 0.0, 0.0, 0.0);
            glUniform4f($this->glassUniforms['reg'], $a, $b, 0.0, 0.0);
            glBindTexture(GL_TEXTURE_2D, $input);
            glDrawArrays(GL_TRIANGLES, 0, 3);

            return $out;
        };

        $sharp = $pass(0, $source, $region->width, $region->height, (float) $region->x, (float) $region->y);
        $down = [[$sharp, $region->width, $region->height]];
        for ($k = 1, $deepest = max($depths); $k <= $deepest; $k++) {
            [$input, $w, $h] = $down[$k - 1];
            $down[$k] = [$pass(1, $input, intdiv($w + 1, 2), intdiv($h + 1, 2), (float) $w, (float) $h), intdiv($w + 1, 2), intdiv($h + 1, 2)];
        }
        $samples = [];
        foreach ($depths as $depth) {
            [$up, $w, $h] = $down[$depth];
            for ($k = $depth - 1; $k >= 1; $k--) {
                $up = $pass(2, $up, $down[$k][1], $down[$k][2], (float) $w, (float) $h);
                [$w, $h] = [$down[$k][1], $down[$k][2]];
            }
            $samples[$depth] = $up;
        }
        glBindTexture(GL_TEXTURE_2D, 0);
        glBindFramebuffer(GL_FRAMEBUFFER, 0);

        return ['sharp' => $sharp, 'samples' => $samples];
    }

    /** A pyramid level of this size: a free one from the pool, or a new one. */
    private function glassLevel(int $width, int $height): int
    {
        $key = "{$width}x{$height}";
        if (! empty($this->glassPool[$key])) {
            $this->glassBytes -= $width * $height * 4;

            return array_pop($this->glassPool[$key]);
        }

        return $this->texture($width, $height);
    }

    /** A level nothing keeps, for the next backdrop; over GLASS_POOL_BYTES the sizes used longest ago are deleted first. */
    private function releaseGlass(int $texture, int $width, int $height): void
    {
        $key = "{$width}x{$height}";
        $list = $this->glassPool[$key] ?? [];
        $list[] = $texture;
        unset($this->glassPool[$key]);
        $this->glassPool[$key] = $list;
        $this->glassBytes += $width * $height * 4;
        while ($this->glassBytes > self::GLASS_POOL_BYTES && $this->glassPool !== []) {
            $oldest = array_key_first($this->glassPool);
            if ($this->glassPool[$oldest] === []) { // a size taken empty elsewhere holds nothing to free
                unset($this->glassPool[$oldest]);

                continue;
            }
            [$w, $h] = array_map('intval', explode('x', $oldest));
            $gone = array_shift($this->glassPool[$oldest]);
            unset($this->textureWidths[$gone], $this->textureHeights[$gone]);
            glDeleteTextures(1, [$gone]);
            $this->glassBytes -= $w * $h * 4;
            if ($this->glassPool[$oldest] === []) {
                unset($this->glassPool[$oldest]);
            }
        }
    }

    /**
     * A filter chain over a region of a layer target: FilterPlan's passes, each a draw of the filter program over the
     * region into a texture of its own (a pixel's memory row is its row on the surface, so the target's rows are turned
     * over going in and coming out), the last pass's straight pixels written back into the target.
     *
     * @param  array<int, int>  $textures  Source texture names by the numbers the list gave them.
     */
    private function filter(array $operation, array $textures, int $width, int $height): void
    {
        [, $slot, $region, $chain, $premultiplied, $maps, $tables] = $operation;
        $passes = FilterPlan::of($chain, $premultiplied, $maps, $tables, $region->width, $region->height);
        $this->resolve($slot);
        $this->baked[$slot] = true;
        $results = [0 => $this->sourceTextures[] = $this->upright($this->slotTexture($slot))];

        glUseProgram($this->filterProgram);
        glBindVertexArray($this->showVao);
        glBindFramebuffer(GL_FRAMEBUFFER, $this->filterFbo);
        glViewport(0, 0, $width, $height);
        glDisable(GL_BLEND);
        glDisable(GL_STENCIL_TEST);
        glColorMask(true, true, true, true);
        glEnable(GL_SCISSOR_TEST);
        glScissor($region->x, $region->y, $region->width, $region->height);
        glUniform4f($this->filterUniforms['reg'], (float) $region->x, (float) $region->y, (float) $region->right(), (float) $region->bottom());

        foreach ($passes as $i => $pass) {
            $out = $this->sourceTextures[] = $this->texture($width, $height);
            glFramebufferTexture2D(GL_FRAMEBUFFER, GL_COLOR_ATTACHMENT0, GL_TEXTURE_2D, $out, 0);
            $p = array_pad($pass['params'], 8, 0.0);
            glUniform4f($this->filterUniforms['kind'], (float) $pass['kind'], 0.0, 0.0, 0.0);
            glUniform4f($this->filterUniforms['pa'], $p[0], $p[1], $p[2], $p[3]);
            glUniform4f($this->filterUniforms['pb'], $p[4], $p[5], $p[6], $p[7]);
            $inputs = [0 => $results[$pass['in']], 1 => $results[$pass['in2']], 2 => $pass['data'] === [] ? $this->blank : $this->numbers($pass['data']), 3 => $pass['map'] < 0 ? $this->blank : $textures[$pass['map']]];
            foreach ($inputs as $unit => $texture) {
                glActiveTexture(GL_TEXTURE0 + $unit);
                glBindTexture(GL_TEXTURE_2D, $texture);
            }
            glDrawArrays(GL_TRIANGLES, 0, 3);
            $results[$i + 1] = $out;
        }

        // the last result's region into the target, rows turned over again
        glBindFramebuffer(GL_READ_FRAMEBUFFER, $this->filterFbo);
        glBindFramebuffer(GL_DRAW_FRAMEBUFFER, $this->resolveFramebuffer($slot));
        glDisable(GL_SCISSOR_TEST);
        glBlitFramebuffer($region->x, $region->y, $region->right(), $region->bottom(), $region->x, $height - $region->y, $region->right(), $height - $region->bottom(), GL_COLOR_BUFFER_BIT, GL_NEAREST);
        foreach ([3, 2, 1] as $unit) {
            glActiveTexture(GL_TEXTURE0 + $unit);
            glBindTexture(GL_TEXTURE_2D, 0);
        }
    }

    /**
     * A pass's numbers as a texture, a float32's four bytes to a texel, 512 a row, read with texelFetch (the pipeline has no float texture formats).
     *
     * @param  list<float>  $numbers
     */
    private function numbers(array $numbers): int
    {
        $rows = intdiv(count($numbers) + 511, 512);
        [$texture] = self::names('glGenTextures');
        $this->sourceTextures[] = $texture;
        glBindTexture(GL_TEXTURE_2D, $texture);
        glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_MIN_FILTER, GL_NEAREST);
        glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_MAG_FILTER, GL_NEAREST);
        glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_WRAP_S, GL_CLAMP_TO_EDGE);
        glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_WRAP_T, GL_CLAMP_TO_EDGE);
        self::tightlyPacked(pack: false);
        glTexImage2D(GL_TEXTURE_2D, 0, GL_RGBA8, 512, $rows, 0, GL_RGBA, GL_UNSIGNED_BYTE, pack('g*', ...array_pad($numbers, $rows * 512, 0.0)));
        glBindTexture(GL_TEXTURE_2D, 0);

        return $texture;
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
    private function paint(int $mode, int $rgba, bool $blend, int $opacity = 255, bool $linear = false, bool $floatSource = false): void
    {
        $flags = ($this->space->isHdr() ? 1 : 0) | ($floatSource ? 2 : 0);
        glUniform4f($this->uniforms['mode_opacity'], (float) $mode, (float) $opacity, $linear ? 1.0 : 0.0, (float) $flags);
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
        $float = $source instanceof HdrImage && $this->space->isHdr();
        $texture = $this->pooled($width, $height, $float);
        self::tightlyPacked(pack: false);
        glBindTexture(GL_TEXTURE_2D, $texture);
        glTexSubImage2D(GL_TEXTURE_2D, 0, 0, 0, $width, $height, GL_RGBA, $float ? GL_HALF_FLOAT : GL_UNSIGNED_BYTE, $float ? $source->rgba16f() : $source->toRgba8());
        glBindTexture(GL_TEXTURE_2D, 0);

        return $texture;
    }

    /** A copy of this device's $texture with its rows top-down: a flipped blit on the GPU. */
    private function upright(int $texture): int
    {
        $width = $this->textureWidths[$texture];
        $height = $this->textureHeights[$texture];
        // A half-float texture's upright copy stays half floats: the target of an HDR engine, or a float source.
        $float = isset($this->floatTextures[$texture]) || ($texture === $this->resolved && $this->space->isHdr());
        $copy = $this->pooled($width, $height, $float);
        [$read, $draw] = self::names('glGenFramebuffers', 2);
        glBindFramebuffer(GL_READ_FRAMEBUFFER, $read);
        glFramebufferTexture2D(GL_READ_FRAMEBUFFER, GL_COLOR_ATTACHMENT0, GL_TEXTURE_2D, $texture, 0);
        glBindFramebuffer(GL_DRAW_FRAMEBUFFER, $draw);
        glFramebufferTexture2D(GL_DRAW_FRAMEBUFFER, GL_COLOR_ATTACHMENT0, GL_TEXTURE_2D, $copy, 0);
        glDisable(GL_SCISSOR_TEST);
        glBlitFramebuffer(0, 0, $width, $height, 0, $height, $width, 0, GL_COLOR_BUFFER_BIT, GL_NEAREST);
        glBindFramebuffer(GL_FRAMEBUFFER, 0);
        glDeleteFramebuffers(2, [$read, $draw]);

        return $copy;
    }

    /** An RGBA8 texture of this size, contents undefined, sampled texel for texel. */
    private function texture(int $width, int $height, bool $float = false): int
    {
        [$texture] = self::names('glGenTextures');
        glBindTexture(GL_TEXTURE_2D, $texture);
        glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_MIN_FILTER, GL_NEAREST);
        glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_MAG_FILTER, GL_NEAREST);
        glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_WRAP_S, GL_CLAMP_TO_EDGE);
        glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_WRAP_T, GL_CLAMP_TO_EDGE);
        glTexImage2D(GL_TEXTURE_2D, 0, $float ? GL_RGBA16F : GL_RGBA8, $width, $height, 0, GL_RGBA, $float ? GL_HALF_FLOAT : GL_UNSIGNED_BYTE, null);
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

    /** A texture of this size for a source: one a finished frame used, or a new one. Contents undefined; GL orders the GPU's reads before this frame's writes. */
    private function pooled(int $width, int $height, bool $float): int
    {
        $key = "{$width}x{$height}x".($float ? 'f' : 'b');
        $texture = ! empty($this->pool[$key]) ? array_pop($this->pool[$key]) : null;
        if (is_null($texture)) {
            $texture = $this->texture($width, $height, $float);
        } else {
            $this->textureWidths[$texture] = $width;
            $this->textureHeights[$texture] = $height;
        }
        $float && $this->floatTextures[$texture] = true;

        return $texture;
    }

    /** The frame's own textures (sources, the restore's copy) are kept by size for the next frame; the sizes used longest ago are deleted first. */
    private function dropSources(): void
    {
        if ($this->sourceTextures === []) {
            return;
        }
        foreach (array_unique($this->sourceTextures) as $texture) {
            if (! isset($this->textureHeights[$texture])) {
                // already let go by a pass that finished with it
                glDeleteTextures(1, [$texture]);

                continue;
            }
            $key = "{$this->textureWidths[$texture]}x{$this->textureHeights[$texture]}x".(isset($this->floatTextures[$texture]) ? 'f' : 'b');
            unset($this->textureWidths[$texture], $this->textureHeights[$texture], $this->floatTextures[$texture]);
            $list = $this->pool[$key] ?? [];
            $list[] = $texture;
            unset($this->pool[$key]);
            $this->pool[$key] = $list;
            while (count($this->pool[$key]) > self::POOL_PER_SIZE) {
                glDeleteTextures(1, [array_shift($this->pool[$key])]);
            }
        }
        while (count($this->pool) > self::POOL_SIZES) {
            $oldest = array_key_first($this->pool);
            glDeleteTextures(count($this->pool[$oldest]), $this->pool[$oldest]);
            unset($this->pool[$oldest]);
        }
        $this->sourceTextures = [];
    }

    /**
     * The $n names a glGen* function writes.
     *
     * @param  callable(int, ?array): void  $generate  glGenBuffers, glGenTextures, …
     * @return list<int>
     */
    private static function names(callable $generate, int $n = 1): array
    {
        $generate($n, $names);

        return $names;
    }

    /** A shader's or program's info log, sized by its GL_INFO_LOG_LENGTH. */
    private static function infoLog(int $object, bool $shader): string
    {
        if ($shader) {
            glGetShaderiv($object, GL_INFO_LOG_LENGTH, $size);
            glGetShaderInfoLog($object, $size, $length, $log);
        } else {
            glGetProgramiv($object, GL_INFO_LOG_LENGTH, $size);
            glGetProgramInfoLog($object, $size, $length, $log);
        }

        return $log;
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
        if (array_intersect_key($surface->handles(), ['view' => 0, 'wl_surface' => 0, 'x11_window' => 0]) !== []) {
            $this->window = new WindowSurface($surface->handles(), $this->wants, $surface->size(), $surface->hdr());
            $this->cgl = $this->window->cgl();
            $this->eglDisplay = $this->window->eglDisplay();
            $this->egl = $this->window->eglContext();
            $this->history = new DamageHistory(2);
        } elseif (PHP_OS_FAMILY === 'Darwin') {
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
     * In a lent context: draw the resolved target into the framebuffer bound
     * for drawing with the show pass, inside the surface's presentRect() with
     * its filter, the bars black, and leave it bound. A GL canvas or stager
     * calls this where its framebuffer is bound; it swaps. A device with its
     * own offscreen context has nothing to present into. In the device's own
     * window surface it draws into the window's framebuffer and swaps itself.
     */
    public function present(LentSurface $surface): bool
    {
        if (! $this->adopted || $surface->released()) {
            return false;
        }
        $this->live();
        $this->with(function () use ($surface): void {
            [$across, $down] = $surface->size();
            if (is_null($this->window)) {
                glGetIntegerv(GL_DRAW_FRAMEBUFFER_BINDING, $bound);
                $this->show($bound[0] ?? 0, $across, $down, $surface);

                return;
            }
            $target = $this->framebuffer ?? throw new DrawingException('opengl: target() comes before present().');
            $this->window->fit([$across, $down]);
            $placed = $this->show(0, $across, $down, $surface);
            $changed = $this->history->record($target->damage(), $target->width(), $target->height(), $placed, $across, $down);
            $this->window->swap($surface->vsync(), DamageHistory::flipped($changed, $down));
        });

        return true;
    }

    /**
     * @internal The show pass's encoding for the current target: the
     * transform code (0 as stored, 1 extended linear, 2 PQ), the SDR white
     * level, and the nits an HDR10 value of 1.0 shows at.
     *
     * @return array{int, float, float}
     */
    public function transform(): array
    {
        $level = $this->surface?->hdr()?->sdrWhiteLevel ?? 1.0;
        $white = $level > 0.0 ? $level : 1.0;

        return [$this->transformCode(), $white, max(203.0, 80.0 * $white)];
    }

    /** The transform code: the device's own window surface encodes for its colour space; a host's context shows the target as stored. */
    private function transformCode(): int
    {
        return $this->window?->transform() ?? 0;
    }

    /** The show pass into GL framebuffer $framebuffer of $across × $down, left bound; the rect the target landed in. */
    private function show(int $framebuffer, int $across, int $down, LentSurface $surface): Region
    {
        $target = $this->framebuffer ?? throw new DrawingException('opengl: target() comes before present().');
        [$filter, $fit] = $surface->scaling();
        $placed = $fit->rect($across, $down, $target->width(), $target->height());
        [$transform, $white, $nits] = $this->transform();
        glBindFramebuffer(GL_FRAMEBUFFER, $framebuffer);
        glViewport(0, 0, $across, $down);
        glDisable(GL_SCISSOR_TEST);
        glDisable(GL_BLEND);
        glDisable(GL_STENCIL_TEST);
        glColorMask(true, true, true, true);
        glUseProgram($this->showProgram);
        glActiveTexture(GL_TEXTURE0);
        glBindTexture(GL_TEXTURE_2D, $this->resolved);
        glUniform1i($this->showUniforms['target'], 0);
        glUniform4f($this->showUniforms['rect'], (float) $placed->x, (float) $placed->y, (float) $placed->width, (float) $placed->height);
        glUniform4f($this->showUniforms['size_light'], (float) $target->width(), (float) $target->height(), $white, $nits);
        glUniform4f($this->showUniforms['modes'], $filter === ScaleFilter::Linear ? 1.0 : 0.0, (float) $transform, (float) $down, 0.0);
        glBindVertexArray($this->showVao);
        glDrawArrays(GL_TRIANGLES, 0, 3);
        glBindVertexArray(0);
        glBindTexture(GL_TEXTURE_2D, 0);
        glUseProgram(0);
        $this->check('present()');

        return $placed;
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
                    glDeleteTextures(count($this->textureHeights), array_keys($this->textureHeights));
                    $this->textureWidths = $this->textureHeights = [];
                }
                $this->atlases = [];
                if ($this->ring !== []) {
                    glDeleteVertexArrays(count($this->ring), array_column($this->ring, 'vao'));
                    glDeleteBuffers(count($this->ring), array_column($this->ring, 'vbo'));
                    $this->ring = [];
                    $this->vao = $this->vbo = 0;
                }
                foreach ($this->pool as $free) {
                    glDeleteTextures(count($free), $free);
                }
                $this->pool = [];
                if ($this->program !== 0) {
                    glDeleteProgram($this->program);
                    $this->program = 0;
                }
                if ($this->filterProgram !== 0) {
                    glDeleteProgram($this->filterProgram);
                    glDeleteFramebuffers(1, [$this->filterFbo]);
                    $this->filterProgram = $this->filterFbo = 0;
                }
                if ($this->glassProgram !== 0) {
                    glDeleteProgram($this->glassProgram);
                    $this->glassProgram = 0;
                }
                $this->glassPool = $this->glassKept = [];
                $this->glassBytes = 0;
                if ($this->showProgram !== 0) {
                    glDeleteProgram($this->showProgram);
                    glDeleteVertexArrays(1, [$this->showVao]);
                    $this->showProgram = $this->showVao = 0;
                }
            });
            // Destroyed, not un-made-current: whatever the caller had current stays.
            $this->window?->release();
            $this->window = null;
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
        glBindAttribLocation($this->program, 1, 'texel');
        glLinkProgram($this->program);
        glGetProgramiv($this->program, GL_LINK_STATUS, $ok);
        if ($ok !== GL_TRUE) {
            $log = self::infoLog($this->program, shader: false);
            glDeleteProgram($this->program);
            $this->program = 0;

            throw new DrawingException('opengl: the program did not link: '.$log);
        }
        foreach (['size', 'mode_opacity', 'rgba_in', 'shape', 'band', 'radii', 'abcd', 'efwh', 'win', 'rim', 'source', 'sharp'] as $name) {
            $this->uniforms[$name] = glGetUniformLocation($this->program, $name);
        }

        $this->blank = $this->texture(1, 1);
        glBindTexture(GL_TEXTURE_2D, $this->blank);
        glTexSubImage2D(GL_TEXTURE_2D, 0, 0, 0, 1, 1, GL_RGBA, GL_UNSIGNED_BYTE, "\0\0\0\0");
        glBindTexture(GL_TEXTURE_2D, 0);

        $this->ring = [];
        for ($i = 0; $i < self::RING; $i++) {
            [$vao] = self::names('glGenVertexArrays');
            [$vbo] = self::names('glGenBuffers');
            glBindVertexArray($vao);
            glBindBuffer(GL_ARRAY_BUFFER, $vbo);
            glEnableVertexAttribArray(0);
            glVertexAttribPointer(0, 2, GL_FLOAT, false, 8, 0);
            $this->ring[] = ['vao' => $vao, 'vbo' => $vbo, 'size' => 0];
        }
        glBindVertexArray(0);
        [$this->vao, $this->vbo] = [$this->ring[0]['vao'], $this->ring[0]['vbo']];
        $this->showProgram = glCreateProgram();
        foreach ([GL_VERTEX_SHADER => self::SHOW_VERTEX_SHADER, GL_FRAGMENT_SHADER => self::SHOW_FRAGMENT_SHADER] as $type => $path) {
            $shader = $this->compile($type, $path);
            glAttachShader($this->showProgram, $shader);
            glDeleteShader($shader);
        }
        glLinkProgram($this->showProgram);
        glGetProgramiv($this->showProgram, GL_LINK_STATUS, $ok);
        if ($ok !== GL_TRUE) {
            throw new DrawingException('opengl: the show program did not link: '.self::infoLog($this->showProgram, shader: false));
        }
        foreach (['target', 'rect', 'size_light', 'modes'] as $name) {
            $this->showUniforms[$name] = glGetUniformLocation($this->showProgram, $name);
        }
        [$this->showVao] = self::names('glGenVertexArrays');

        $this->filterProgram = glCreateProgram();
        foreach ([GL_VERTEX_SHADER => self::SHOW_VERTEX_SHADER, GL_FRAGMENT_SHADER => self::FILTER_FRAGMENT_SHADER] as $type => $path) {
            $shader = $this->compile($type, $path);
            glAttachShader($this->filterProgram, $shader);
            glDeleteShader($shader);
        }
        glLinkProgram($this->filterProgram);
        glGetProgramiv($this->filterProgram, GL_LINK_STATUS, $ok);
        if ($ok !== GL_TRUE) {
            throw new DrawingException('opengl: the filter program did not link: '.self::infoLog($this->filterProgram, shader: false));
        }
        foreach (['src', 'src2', 'data', 'map', 'reg', 'kind', 'pa', 'pb'] as $name) {
            $this->filterUniforms[$name] = glGetUniformLocation($this->filterProgram, $name);
        }
        glUseProgram($this->filterProgram);
        foreach (['src' => 0, 'src2' => 1, 'data' => 2, 'map' => 3] as $name => $unit) {
            glUniform1i($this->filterUniforms[$name], $unit);
        }
        glUseProgram(0);
        [$this->filterFbo] = self::names('glGenFramebuffers');

        $this->glassProgram = glCreateProgram();
        foreach ([GL_VERTEX_SHADER => self::SHOW_VERTEX_SHADER, GL_FRAGMENT_SHADER => self::GLASS_FRAGMENT_SHADER] as $type => $path) {
            $shader = $this->compile($type, $path);
            glAttachShader($this->glassProgram, $shader);
            glDeleteShader($shader);
        }
        glLinkProgram($this->glassProgram);
        glGetProgramiv($this->glassProgram, GL_LINK_STATUS, $ok);
        if ($ok !== GL_TRUE) {
            throw new DrawingException('opengl: the glass program did not link: '.self::infoLog($this->glassProgram, shader: false));
        }
        foreach (['src', 'kind', 'reg'] as $name) {
            $this->glassUniforms[$name] = glGetUniformLocation($this->glassProgram, $name);
        }
        glUseProgram($this->glassProgram);
        glUniform1i($this->glassUniforms['src'], 0);
        glUseProgram(0);
        $this->check('context setup');
    }

    private function compile(int $type, string $path): int
    {
        $shader = glCreateShader($type);
        glShaderSource($shader, 2, [self::HEADERS[$this->dialect], file_get_contents($path)], null);
        glCompileShader($shader);
        glGetShaderiv($shader, GL_COMPILE_STATUS, $ok);
        if ($ok !== GL_TRUE) {
            $log = self::infoLog($shader, shader: true);
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
        if (! is_null($this->window)) {
            $this->window->makeCurrent();

            return;
        }
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
        foreach ($this->slots as $slot) {
            glDeleteFramebuffers(2, [$slot['fbo'], $slot['msaaFbo']]);
            glDeleteRenderbuffers(2, [$slot['stencil'], $slot['msaaColor']]);
            glDeleteTextures(1, [$slot['texture']]);
            unset($this->textureWidths[$slot['texture']], $this->textureHeights[$slot['texture']]);
        }
        $this->slots = [];
        $this->baked = [];
        if ($this->resolvedFbo !== 0) {
            $framebuffers = array_values(array_filter([$this->resolvedFbo, $this->msaaFbo]));
            glDeleteFramebuffers(count($framebuffers), $framebuffers);
        }
        $renderbuffers = array_values(array_filter([$this->stencil, $this->msaaColor]));
        if ($renderbuffers !== []) {
            glDeleteRenderbuffers(count($renderbuffers), $renderbuffers);
        }
        $this->resolvedFbo = $this->msaaFbo = $this->stencil = $this->msaaColor = 0;
        $this->resolved = 0;
        $this->framebuffer = null;
    }
}
