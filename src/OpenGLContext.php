<?php

namespace Jovian\Engines\OpenGL;

use CFData;
use CFRange;
use CGLContextObj;
use Closure;
use NSView;
use Surface\Contracts\Graphics\ActorStage;
use Surface\Contracts\Graphics\Backend;
use Surface\Contracts\Graphics\GraphicsException;
use Surface\Contracts\Graphics\Resource;
use Surface\Contracts\Rendering\Framebuffers\FormatSpec;
use Surface\Contracts\Rendering\Framebuffers\Region;
use Surface\Contracts\Rendering\Rasterization\Edges;
use Surface\Contracts\Rendering\Raycasting\Grid;
use Surface\Graphics\GEFramebuffer;
use Surface\Graphics\GEPainter;
use Surface\Graphics\GERasterizer;
use Surface\Graphics\GERaycaster;
use Surface\Graphics\GraphicsEngine;
use Throwable;
use WeakMap;

/**
 * The opengl engine: one OpenGL 4.1 core context of its own, offscreen, and what it makes. Every
 * framebuffer and rasterizer it hands out works in that context, which is made current before each
 * use and left current after.
 *
 * It draws on the GPU only. GL queues what it is given and runs it in order; the CPU waits only
 * where it reads pixels back.
 *
 * On macOS the context is CGL's. Reading pixels back writes them to an address, so it needs memory
 * there: ext-appkit's CFData, used when ext-appkit is loaded and never declared in composer.json.
 */
class OpenGLContext extends GraphicsEngine
{
    /** The uniform block a vertex stage reads: the target's size and where it starts. */
    public const int FRAME = 0;

    /** The uniform block a fragment stage reads: what one draw paints with. */
    public const int PAINT = 1;

    /** The uniform block a raycast view reads: the scenery's wall table. */
    public const int WALLS = 2;

    /** The GPU's largest texture side. */
    protected int $maxSide = 0;

    protected ?CGLContextObj $context = null;

    protected string $renderer = '';

    /** Uniform buffer offsets are a multiple of this. */
    protected int $alignment = 256;

    protected int $vao = 0;

    protected int $vertexBuffer = 0;

    protected int $uniformBuffer = 0;

    /** A vertex array with no attributes: cover.vert makes its triangle from the vertex index. */
    protected int $empty = 0;

    /** @var array<string, int> linked programs, by their shaders */
    protected array $programs = [];

    /** @var array<string, list<string>> each program's samplers, in the order of their texture units */
    protected array $units = [];

    /** Memory glReadPixels writes to: grown when a read needs more. */
    protected ?CFData $memory = null;

    /** @var array{int, int, int, int, int, int}|null the scratch: four-sample framebuffer, its colour and stencil, the hard-edge stencil, width, height */
    protected ?array $scratch = null;

    /** @var WeakMap<NSView, OpenGLSurface> the surfaces made over views stages lent */
    protected WeakMap $surfaces;

    /** @throws GraphicsException When the backend is not the GPU, or the context cannot be made. */
    public function __construct(Backend $backend = Backend::GPU, protected readonly ?Closure $recorder = null)
    {
        if ($backend !== Backend::GPU) {
            throw GraphicsException::backendNotOffered('opengl', $backend, [Backend::GPU]);
        }
        parent::__construct($backend);

        try {
            $this->open();
        } catch (Throwable $failure) {
            $this->close();

            throw $failure;
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    public function name(): string
    {
        return 'opengl';
    }

    /** The GPU it draws on, by the name its driver gives it. */
    public function modules(): array
    {
        return ['gpu' => $this->renderer];
    }

    public function supports(Resource $resource): bool
    {
        return in_array($resource, [Resource::FRAMEBUFFER, Resource::RASTERIZER, Resource::RAYCASTER, Resource::PAINTER], true);
    }

    /**
     * A raycaster over $grid: its cells are held on the GPU, in a texture 1024 words wide.
     *
     * @throws OpenGLException When the grid's cells need a texture taller than the GPU holds.
     */
    public function raycaster(Grid $grid): GERaycaster
    {
        return new OpenGLRaycaster($this, new OpenGLCaster($this, $grid));
    }

    /** The GPU's largest texture side. */
    public function maxSide(): int
    {
        return $this->maxSide;
    }

    /** @throws GraphicsException When $onto is another engine's framebuffer. */
    public function painter(GEFramebuffer $onto): GEPainter
    {
        if ($onto->engine() !== $this || ! $onto instanceof OpenGLFramebuffer) {
            throw GraphicsException::foreignResource('opengl', $onto->engine()->name());
        }

        return new OpenGLGEPainter($this, $onto, new OpenGLPainter($this, $onto, recorder: is_null($this->recorder) ? null : ($this->recorder)()));
    }

    /**
     * An RGBA8 texture on the GPU, with a four-sample twin that antialiased shapes are drawn into.
     *
     * @throws GraphicsException When the size is below 1x1, or $format is not RGBA8: the only format it holds.
     */
    public function framebuffer(int $width, int $height, ?FormatSpec $format = null): GEFramebuffer
    {
        if ($width < 1 || $height < 1) {
            throw GraphicsException::badSize($width, $height);
        }
        if (! is_null($format) && ! $format->equals(FormatSpec::rgba8())) {
            throw OpenGLException::because('a framebuffer holds RGBA8 only.');
        }

        return new OpenGLFramebuffer($this, $width, $height);
    }

    /**
     * A framebuffer the stage's size, shown on it. A stage that lends an NSView is shown in through a child
     * NSOpenGLView of the engine's, when ext-appkit is loaded; otherwise as pixels, through the stage.
     *
     * @throws GraphicsException When the stage has no size yet.
     */
    public function attach(ActorStage $stage, ?FormatSpec $format = null): GEFramebuffer
    {
        [$width, $height] = $stage->pixelSize();
        if ($width < 1 || $height < 1) {
            throw GraphicsException::stageHasNoSize();
        }

        /** @var OpenGLFramebuffer $framebuffer */
        $framebuffer = $this->framebuffer($width, $height, $format)->onto($stage);
        $view = $stage->nativeSurface();
        if ($view instanceof NSView && class_exists(\NSOpenGLContext::class, false)) {
            $this->surfaces ??= new WeakMap;
            $surface = $this->surfaces[$view] ?? null;
            if (is_null($surface)) {
                $surface = OpenGLSurface::over($this, $view);
                if (! is_null($surface)) {
                    $this->surfaces[$view] = $surface;
                }
            }
            if (! is_null($surface)) {
                $framebuffer->showIn($surface);
            }
        }

        return $framebuffer;
    }

    /** Takes away what it added to the views stages lent it. */
    public function release(): void
    {
        foreach ($this->surfaces ?? [] as $surface) {
            $surface->release();
        }
        $this->surfaces = new WeakMap;
    }

    public function rasterizer(Edges $edges = Edges::ANTIALIASED): GERasterizer
    {
        return new OpenGLRasterizer($this, $edges);
    }

    /**
     * Whether the context is still open: what it made may only be deleted while it is. At the end of a
     * script PHP may destroy the engine before what it made.
     */
    public function isOpen(): bool
    {
        return ! is_null($this->context);
    }

    /** Makes the engine's context current, if it is not. Every GL call the engine makes comes after this. */
    public function current(): void
    {
        if (CGLGetCurrentContext()?->pointer() !== $this->context->pointer()) {
            CGLSetCurrentContext($this->context);
        }
    }

    /** The context, for a window's context to share its textures with. */
    public function cgl(): CGLContextObj
    {
        return $this->context;
    }

    /**
     * The linked program over resources/shaders/engine/$vertex and $fragment, made the first time it
     * is asked for. Its `Frame` block reads binding FRAME and its `Paint` block PAINT; the samplers its
     * fragment names on its `// units:` line read texture units 0, 1, ... in that order.
     *
     * @throws OpenGLException When a shader does not compile or the program does not link.
     */
    public function program(string $vertex, string $fragment): int
    {
        $key = $vertex.'|'.$fragment;
        if (isset($this->programs[$key])) {
            return $this->programs[$key];
        }

        $samplers = [];
        $program = glCreateProgram();
        $shaders = [$this->shader(GL_VERTEX_SHADER, $vertex), $this->shader(GL_FRAGMENT_SHADER, $fragment, $samplers)];
        foreach ($shaders as $shader) {
            glAttachShader($program, $shader);
        }
        glBindAttribLocation($program, 0, 'point');
        glLinkProgram($program);
        foreach ($shaders as $shader) {
            glDeleteShader($shader);
        }
        glGetProgramiv($program, GL_LINK_STATUS, $linked);
        if ($linked !== GL_TRUE) {
            glGetProgramiv($program, GL_INFO_LOG_LENGTH, $size);
            glGetProgramInfoLog($program, max(1, $size), $length, $log);
            glDeleteProgram($program);

            throw OpenGLException::because("{$vertex} and {$fragment} do not link: {$log}");
        }

        foreach (['Frame' => self::FRAME, 'Paint' => self::PAINT, 'Walls' => self::WALLS] as $block => $binding) {
            $index = glGetUniformBlockIndex($program, $block);
            if ($index !== GL_INVALID_INDEX) {
                glUniformBlockBinding($program, $index, $binding);
            }
        }
        glUseProgram($program);
        foreach ($samplers as $unit => $name) {
            glUniform1i(glGetUniformLocation($program, $name), $unit);
        }
        $this->units[$key] = $samplers;

        return $this->programs[$key] = $program;
    }

    /**
     * The samplers of the program over $vertex and $fragment, in the order of their texture units.
     *
     * @return list<string>
     */
    public function units(string $vertex, string $fragment): array
    {
        $this->program($vertex, $fragment);

        return $this->units[$vertex.'|'.$fragment];
    }

    /** Draws cover.vert's one triangle over the viewport, from no vertex data. */
    public function cover(): void
    {
        glBindVertexArray($this->empty);
        glDrawArrays(GL_TRIANGLES, 0, 3);
        glBindVertexArray($this->vao);
    }

    /**
     * The samples and stencils a transient framebuffer draws through, at least $width x $height: one set
     * for the engine, grown when a larger one is asked for. A transient framebuffer is drawn in one pass,
     * resolved at its end, before any other's starts, so one set serves them all.
     *
     * @return array{int, int} the four-sample framebuffer, and the hard-edge stencil renderbuffer
     */
    public function scratch(int $width, int $height): array
    {
        if (! is_null($this->scratch) && $this->scratch[4] >= $width && $this->scratch[5] >= $height) {
            return [$this->scratch[0], $this->scratch[3]];
        }
        [$width, $height] = [max($width, $this->scratch[4] ?? 0), max($height, $this->scratch[5] ?? 0)];
        $this->dropScratch();
        glGenRenderbuffers(3, $renderbuffers);
        [$color, $stencil, $hard] = $renderbuffers;
        glBindRenderbuffer(GL_RENDERBUFFER, $color);
        glRenderbufferStorageMultisample(GL_RENDERBUFFER, OpenGLFramebuffer::SAMPLES, GL_RGBA8, $width, $height);
        glBindRenderbuffer(GL_RENDERBUFFER, $stencil);
        glRenderbufferStorageMultisample(GL_RENDERBUFFER, OpenGLFramebuffer::SAMPLES, GL_DEPTH24_STENCIL8, $width, $height);
        glBindRenderbuffer(GL_RENDERBUFFER, $hard);
        glRenderbufferStorage(GL_RENDERBUFFER, GL_DEPTH24_STENCIL8, $width, $height);
        glGenFramebuffers(1, $framebuffers);
        glBindFramebuffer(GL_FRAMEBUFFER, $framebuffers[0]);
        glFramebufferRenderbuffer(GL_FRAMEBUFFER, GL_COLOR_ATTACHMENT0, GL_RENDERBUFFER, $color);
        glFramebufferRenderbuffer(GL_FRAMEBUFFER, GL_DEPTH_STENCIL_ATTACHMENT, GL_RENDERBUFFER, $stencil);
        $this->scratch = [$framebuffers[0], $color, $stencil, $hard, $width, $height];

        return [$framebuffers[0], $hard];
    }

    protected function dropScratch(): void
    {
        if (! is_null($this->scratch)) {
            glDeleteFramebuffers(1, [$this->scratch[0]]);
            glDeleteRenderbuffers(3, [$this->scratch[1], $this->scratch[2], $this->scratch[3]]);
            $this->scratch = null;
        }
    }

    /** What a uniform block of these bytes is bound as: std140 rounds a block up to 16 bytes. */
    public static function size(string $block): int
    {
        return max(16, intdiv(strlen($block) + 15, 16) * 16);
    }

    /** A texture, read texel for texel and clamped at its edges, with nothing written yet. */
    public static function texture(int $width, int $height, int $internal = GL_RGBA8, int $format = GL_RGBA, int $type = GL_UNSIGNED_BYTE): int
    {
        glGenTextures(1, $textures);
        glBindTexture(GL_TEXTURE_2D, $textures[0]);
        glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_MIN_FILTER, GL_NEAREST);
        glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_MAG_FILTER, GL_NEAREST);
        glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_WRAP_S, GL_CLAMP_TO_EDGE);
        glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_WRAP_T, GL_CLAMP_TO_EDGE);
        glTexImage2D(GL_TEXTURE_2D, 0, $internal, $width, $height, 0, $format, $type, null);

        return $textures[0];
    }

    /** Hands $bytes (float32 points) to the vertex stage: attribute 0, two floats a point. */
    public function vertices(string $bytes): void
    {
        glBindVertexArray($this->vao);
        glBindBuffer(GL_ARRAY_BUFFER, $this->vertexBuffer);
        glBufferData(GL_ARRAY_BUFFER, strlen($bytes), $bytes, GL_STREAM_DRAW);
    }

    /**
     * Uploads one buffer of uniform blocks, each at an offset that is a multiple of the alignment, and
     * answers those offsets. Bind one with block().
     *
     * @param list<string> $blocks
     *
     * @return list<int>
     */
    public function blocks(array $blocks): array
    {
        [$bytes, $offsets] = ['', []];
        foreach ($blocks as $block) {
            $offsets[] = strlen($bytes);
            $bytes .= str_pad($block, (int) (ceil(max(1, strlen($block)) / $this->alignment) * $this->alignment), "\0");
        }
        glBindBuffer(GL_UNIFORM_BUFFER, $this->uniformBuffer);
        glBufferData(GL_UNIFORM_BUFFER, strlen($bytes), $bytes, GL_STREAM_DRAW);

        return $offsets;
    }

    /** Binds the block at $offset ($size bytes) of the last blocks() upload to $binding. */
    public function block(int $binding, int $offset, int $size): void
    {
        glBindBufferRange(GL_UNIFORM_BUFFER, $binding, $this->uniformBuffer, $offset, $size);
    }

    /**
     * Whether $contour is one convex polygon: every turn the same way, and once round. Its fan from the
     * first point then covers it with no triangle over another, so it needs no stencil.
     *
     * @param list<array{float, float}> $contour
     */
    public static function convex(array $contour): bool
    {
        $count = count($contour);
        if ($count > 3 && $contour[0] === $contour[$count - 1]) {
            $count--;
        }
        if ($count < 3) {
            return false;
        }
        [$sign, $turning] = [0, 0.0];
        for ($i = 0; $i < $count; $i++) {
            [$ax, $ay] = $contour[$i];
            [$bx, $by] = $contour[($i + 1) % $count];
            [$cx, $cy] = $contour[($i + 2) % $count];
            [$ux, $uy, $vx, $vy] = [$bx - $ax, $by - $ay, $cx - $bx, $cy - $by];
            $cross = $ux * $vy - $uy * $vx;
            if ($cross !== 0.0) {
                $turn = $cross > 0 ? 1 : -1;
                if ($sign !== 0 && $turn !== $sign) {
                    return false;
                }
                $sign = $turn;
            }
            $turning += atan2($cross, $ux * $vx + $uy * $vy);
        }

        return $sign !== 0 && abs(abs($turning) - 2 * M_PI) < 1e-6;
    }

    /** The six points of two triangles over $region, as float32 bytes. */
    public static function quad(Region $region): string
    {
        [$l, $t, $r, $b] = [$region->x, $region->y, $region->x + $region->width, $region->y + $region->height];

        return pack('g12', $l, $t, $r, $t, $r, $b, $l, $t, $r, $b, $l, $b);
    }

    /**
     * $region of the framebuffer bound for reading, top row first: RGBA8 bytes, or $format and $type at
     * $bytesPerPixel. GL writes them to an address: ext-appkit's CFData is that memory, kept from call to call.
     *
     * @throws OpenGLException When ext-appkit is not loaded.
     */
    public function readPixels(Region $region, int $format = GL_RGBA, int $type = GL_UNSIGNED_BYTE, int $bytesPerPixel = 4): string
    {
        if (! class_exists(CFData::class)) {
            throw OpenGLException::because('reading pixels back needs ext-appkit: GL writes them to an address, and its CFData is the memory there.');
        }
        $total = $region->width * $region->height * $bytesPerPixel;
        $this->memory ??= CFData::createMutable(null, 0);
        if ($this->memory->getLength() < $total) {
            $this->memory->setLength($total);
        }
        glPixelStorei(GL_PACK_ALIGNMENT, 4);
        glReadPixels($region->x, $region->y, $region->width, $region->height, $format, $type, $this->memory->getMutableBytePtr());
        $this->memory->getBytes(new CFRange(0, $total), $bytes);

        return $bytes;
    }

    /** The state every draw starts from: no blending, stencil, scissor, depth or culling, all colours written. */
    public function plain(): void
    {
        glDisable(GL_BLEND);
        glDisable(GL_STENCIL_TEST);
        glDisable(GL_SCISSOR_TEST);
        glDisable(GL_DEPTH_TEST);
        glDisable(GL_CULL_FACE);
        glColorMask(true, true, true, true);
    }

    /**
     * @param list<string> $samplers set to the names on the shader's `// units:` line
     *
     * @throws OpenGLException
     */
    protected function shader(int $type, string $name, array &$samplers = []): int
    {
        $path = dirname(__DIR__).'/resources/shaders/engine/'.$name;
        $source = @file_get_contents($path);
        if ($source === false) {
            throw OpenGLException::because("no shader {$name}.");
        }
        if (preg_match('~^// units: (.+)$~m', $source, $line) === 1) {
            $samplers = array_map(trim(...), explode(',', $line[1]));
        }
        $shader = glCreateShader($type);
        glShaderSource($shader, 1, [$source], null);
        glCompileShader($shader);
        glGetShaderiv($shader, GL_COMPILE_STATUS, $compiled);
        if ($compiled !== GL_TRUE) {
            glGetShaderiv($shader, GL_INFO_LOG_LENGTH, $size);
            glGetShaderInfoLog($shader, max(1, $size), $length, $log);
            glDeleteShader($shader);

            throw OpenGLException::because("{$name} does not compile: {$log}");
        }

        return $shader;
    }

    /** @throws OpenGLException */
    protected function open(): void
    {
        if (PHP_OS_FAMILY !== 'Darwin') {
            throw OpenGLException::because('the engine opens a CGL context; an EGL one on Linux is not built yet.');
        }
        $attributes = [kCGLPFAOpenGLProfile, kCGLOGLPVersion_GL4_Core, kCGLPFAAccelerated, kCGLPFAColorSize, 24, kCGLPFAAlphaSize, 8, 0];
        $error = CGLChoosePixelFormat($attributes, $pixelFormat, $count);
        if ($error !== kCGLNoError || is_null($pixelFormat)) {
            throw OpenGLException::because('no OpenGL 4.1 core pixel format: '.CGLErrorString($error));
        }
        $error = CGLCreateContext($pixelFormat, null, $context);
        CGLDestroyPixelFormat($pixelFormat);
        if ($error !== kCGLNoError || is_null($context)) {
            throw OpenGLException::because('no OpenGL 4.1 core context: '.CGLErrorString($error));
        }
        $this->context = $context;
        $this->current();

        $this->renderer = (string) glGetString(GL_RENDERER);
        glGetIntegerv(GL_MAX_TEXTURE_SIZE, $side);
        $this->maxSide = (int) $side[0];
        glGetIntegerv(GL_UNIFORM_BUFFER_OFFSET_ALIGNMENT, $alignment);
        $this->alignment = max(16, (int) $alignment[0]);
        glGenVertexArrays(2, $arrays);
        [$this->vao, $this->empty] = $arrays;
        glGenBuffers(2, $buffers);
        [$this->vertexBuffer, $this->uniformBuffer] = $buffers;
        glBindVertexArray($this->vao);
        glBindBuffer(GL_ARRAY_BUFFER, $this->vertexBuffer);
        glEnableVertexAttribArray(0);
        glVertexAttribPointer(0, 2, GL_FLOAT, false, 8, 0);
    }

    protected function close(): void
    {
        if (is_null($this->context)) {
            return;
        }
        $this->release();
        $this->current();
        foreach ($this->programs as $program) {
            glDeleteProgram($program);
        }
        $this->programs = [];
        $this->dropScratch();
        if ($this->vao !== 0) {
            glDeleteVertexArrays(2, [$this->vao, $this->empty]);
            glDeleteBuffers(2, [$this->vertexBuffer, $this->uniformBuffer]);
        }
        CGLSetCurrentContext(null);
        CGLReleaseContext($this->context);
        $this->context = null;
    }
}
