<?php

declare(strict_types=1);

namespace Jovian\Venusian\OpenGL;

use Jovian\Bindings\OpenGL\Enums\GL\BlendingFactor;
use Jovian\Bindings\OpenGL\Enums\GL\BlitFramebufferFilter;
use Jovian\Bindings\OpenGL\Enums\GL\BufferTargetARB;
use Jovian\Bindings\OpenGL\Enums\GL\BufferUsageARB;
use Jovian\Bindings\OpenGL\Enums\GL\ClearBufferMask;
use Jovian\Bindings\OpenGL\Enums\GL\DrawElementsType;
use Jovian\Bindings\OpenGL\Enums\GL\EnableCap;
use Jovian\Bindings\OpenGL\Enums\GL\GetPName;
use Jovian\Bindings\OpenGL\Enums\GL\InternalFormat;
use Jovian\Bindings\OpenGL\Enums\GL\PixelFormat;
use Jovian\Bindings\OpenGL\Enums\GL\PixelStoreParameter;
use Jovian\Bindings\OpenGL\Enums\GL\PixelType;
use Jovian\Bindings\OpenGL\Enums\GL\PrimitiveType;
use Jovian\Bindings\OpenGL\Enums\GL\ProgramPropertyARB;
use Jovian\Bindings\OpenGL\Enums\GL\ShaderParameterName;
use Jovian\Bindings\OpenGL\Enums\GL\ShaderType;
use Jovian\Bindings\OpenGL\Enums\GL\StringName;
use Jovian\Bindings\OpenGL\Enums\GL\TextureParameterName;
use Jovian\Bindings\OpenGL\Enums\GL\TextureTarget;
use Jovian\Bindings\OpenGL\Enums\GL\TextureUnit;
use Jovian\Bindings\OpenGL\Enums\GL\VertexAttribPointerType;
use Jovian\Bindings\OpenGL\GL\GL10;
use Jovian\Bindings\OpenGL\GL\GL11;
use Jovian\Bindings\OpenGL\GL\GL13;
use Jovian\Bindings\OpenGL\GL\GL15;
use Jovian\Bindings\OpenGL\GL\GL20;
use Jovian\Bindings\OpenGL\GL\GL30;
use Jovian\Bindings\OpenGL\GL\GL31;
use Jovian\Bindings\OpenGL\Runtime\Bridge;
use Jovian\Bindings\OpenGL\Values\ContextVersion;
use Jovian\Venusian\OpenGL\Contracts\OpenGLDrawing;
use Jovian\Venusian\OpenGL\Enums\GLSLDialect;
use Jovian\Venusian\OpenGL\Enums\PainterAttribute;
use Jovian\Venusian\OpenGL\Enums\StagingRegion;
use Jovian\Venusian\OpenGL\Enums\TextureWrap;
use Jovian\Venusian\OpenGL\Exceptions\OpenGLDrawingException;
use Surface\Contracts\Drawing\Executor;
use Surface\Contracts\Drawing\ExecutorCapabilities;
use Surface\Contracts\Drawing\GLSurface;
use Surface\Contracts\Drawing\TextureHandle;
use Surface\Contracts\Drawing\Topology;
use Surface\Contracts\Drawing\Transform;
use Surface\Contracts\NativeWindows\Views\Color;

/**
 * One program, one VAO, one VBO, one EBO, one staging block — made lazily at
 * the first beginFrame(), after the host made its context current. Draws into
 * whatever framebuffer is bound when the frame begins. GL's bottom-left origin
 * is paid in viewport(), scissor() and readPixels(); clip space needs no flip
 * because Surface's orthographic already maps y-down pixels to y-up NDC.
 */
final class OpenGLExecutor implements Executor, OpenGLDrawing
{
    private bool $initialised = false;

    private bool $released = false;

    private bool $in_frame = false;

    private int $pixel_width;

    private int $pixel_height;

    private int $program = 0;

    private int $vertex_shader = 0;

    private int $fragment_shader = 0;

    private int $vao = 0;

    private int $vbo = 0;

    private int $ebo = 0;

    private int $u_transform = -1;

    private int $u_textured = -1;

    private int $u_texture = -1;

    private int $framebuffer = 0;

    private ?GLSLDialect $dialect = null;

    private ?ContextVersion $context_version = null;

    private int $max_texture_size = 2048;

    /** Raw pointer bits of the staging block; 0 until init. */
    private int $staging = 0;

    private int $staging_size = 0;

    /** @var array<int, int> Surface texture id => GL texture name */
    private array $textures = [];

    private int $next_texture_id = 1;

    public function __construct(
        private readonly GLSurface $gl,
        int $pixel_width,
        int $pixel_height,
    ) {
        $this->pixel_width = max(1, $pixel_width);
        $this->pixel_height = max(1, $pixel_height);
    }

    // ---- pure policy, provable without a context

    public static function declaredCapabilities(int $max_texture_size = 2048): ExecutorCapabilities
    {
        return new ExecutorCapabilities(
            blending: true,
            depth: false,
            instancing: true,
            readback: true,
            max_texture_size: $max_texture_size,
        );
    }

    /**
     * Top-left pixel scissor → GL bottom-left, clamped inside the target.
     *
     * @return array{int, int, int, int}
     */
    public static function scissorRect(int $x, int $y, int $width, int $height, int $target_width, int $target_height): array
    {
        $x = max(0, min($x, $target_width - 1));
        $y = max(0, min($y, $target_height - 1));
        $width = max(1, min($width, $target_width - $x));
        $height = max(1, min($height, $target_height - $y));

        return [$x, $target_height - $y - $height, $width, $height];
    }

    /** glReadPixels answers bottom row first; Surface wants top row first. */
    public static function flipRows(string $rgba8, int $width, int $height): string
    {
        $row = $width * 4;
        $out = '';
        for ($i = $height - 1; $i >= 0; $i--) {
            $out .= substr($rgba8, $i * $row, $row);
        }

        return $out;
    }

    /** Size for a block that must hold $needed payload bytes after the fixed regions; 4 KiB floor, doubling. */
    public static function grownSize(int $current, int $needed): int
    {
        $required = StagingRegion::PAYLOAD->value + $needed;
        if ($current >= $required && $current > 0) {
            return $current;
        }

        return max(4096, $current * 2, $required);
    }

    // ---- OpenGLDrawing

    public function program(): int
    {
        return $this->program;
    }

    public function vao(): int
    {
        return $this->vao;
    }

    public function dialect(): ?GLSLDialect
    {
        return $this->dialect;
    }

    public function contextVersion(): ?ContextVersion
    {
        return $this->context_version;
    }

    public function framebuffer(): int
    {
        return $this->framebuffer;
    }

    public function surface(): GLSurface
    {
        return $this->gl;
    }

    // ---- Executor

    public function capabilities(): ExecutorCapabilities
    {
        return self::declaredCapabilities($this->max_texture_size);
    }

    public function resize(int $width, int $height): void
    {
        $this->assertAlive();
        $this->pixel_width = max(1, $width);
        $this->pixel_height = max(1, $height);
    }

    /** @return array{int, int} */
    public function drawableSize(): array
    {
        return [$this->pixel_width, $this->pixel_height];
    }

    public function beginFrame(Color $clear): bool
    {
        $this->assertAlive();
        if ($this->in_frame) {
            $this->endFrame();
        }

        $this->gl->makeCurrent();
        $this->initialise();

        GL10::glGetIntegerv(GetPName::DRAW_FRAMEBUFFER_BINDING, $this->staging + StagingRegion::SCRATCH->value);
        $this->framebuffer = $this->readScratchInt();

        $this->in_frame = true;
        GL20::glUseProgram($this->program);
        GL30::glBindVertexArray($this->vao);
        GL10::glViewport(0, 0, $this->pixel_width, $this->pixel_height);
        $this->unscissor();
        GL10::glClearColor($clear->red, $clear->green, $clear->blue, $clear->alpha);
        GL10::glClear(ClearBufferMask::COLOR_BUFFER_BIT->value);

        return true;
    }

    /** Top-left pixels in, GL bottom-left out — the same flip as scissor(). */
    public function viewport(int $x, int $y, int $width, int $height): void
    {
        $this->assertInFrame('viewport');
        [$gx, $gy, $gw, $gh] = self::scissorRect($x, $y, $width, $height, $this->pixel_width, $this->pixel_height);
        GL10::glViewport($gx, $gy, $gw, $gh);
    }

    public function scissor(int $x, int $y, int $width, int $height): void
    {
        $this->assertInFrame('scissor');
        [$gx, $gy, $gw, $gh] = self::scissorRect($x, $y, $width, $height, $this->pixel_width, $this->pixel_height);
        GL10::glEnable(EnableCap::SCISSOR_TEST);
        GL10::glScissor($gx, $gy, $gw, $gh);
    }

    public function unscissor(): void
    {
        $this->assertInFrame('unscissor');
        GL10::glDisable(EnableCap::SCISSOR_TEST);
    }

    public function texture(string $rgba8, int $width, int $height): TextureHandle
    {
        $this->assertAlive();
        $width = max(1, $width);
        $height = max(1, $height);
        $this->gl->makeCurrent();
        $this->initialise();

        GL11::glGenTextures(1, $this->staging + StagingRegion::SCRATCH->value);
        $name = $this->readScratchInt();
        GL13::glActiveTexture(TextureUnit::TEXTURE0);
        GL11::glBindTexture(TextureTarget::TEXTURE_2D, $name);
        GL10::glPixelStorei(PixelStoreParameter::UNPACK_ALIGNMENT, 1);
        $payload = $this->stagePayload($rgba8);
        GL10::glTexImage2D(TextureTarget::TEXTURE_2D, 0, InternalFormat::RGBA8, $width, $height, 0, PixelFormat::RGBA, PixelType::UNSIGNED_BYTE, $payload);
        GL10::glTexParameteri(TextureTarget::TEXTURE_2D, TextureParameterName::TEXTURE_MIN_FILTER, BlitFramebufferFilter::LINEAR->value);
        GL10::glTexParameteri(TextureTarget::TEXTURE_2D, TextureParameterName::TEXTURE_MAG_FILTER, BlitFramebufferFilter::LINEAR->value);
        GL10::glTexParameteri(TextureTarget::TEXTURE_2D, TextureParameterName::TEXTURE_WRAP_S, TextureWrap::CLAMP_TO_EDGE->value);
        GL10::glTexParameteri(TextureTarget::TEXTURE_2D, TextureParameterName::TEXTURE_WRAP_T, TextureWrap::CLAMP_TO_EDGE->value);

        $id = $this->next_texture_id++;
        $this->textures[$id] = $name;

        return new TextureHandle($id, $width, $height);
    }

    public function releaseTexture(TextureHandle $texture): void
    {
        $name = $this->textures[$texture->id] ?? null;
        if (is_null($name) || $this->released) {
            return;
        }
        $this->gl->makeCurrent();
        $this->deleteName($name, GL11::glDeleteTextures(...));
        unset($this->textures[$texture->id]);
    }

    public function draw(Topology $topology, string $vertices, int $vertex_count, Transform $transform, ?TextureHandle $texture = null, int $instances = 1): void
    {
        $this->assertInFrame('draw');
        if ($vertex_count < 1) {
            return;
        }

        $this->bindDraw($vertices, $transform, $texture);
        $mode = $this->primitive($topology);
        if ($instances > 1) {
            GL31::glDrawArraysInstanced($mode, 0, $vertex_count, $instances);

            return;
        }
        GL11::glDrawArrays($mode, 0, $vertex_count);
    }

    public function drawIndexed(Topology $topology, string $vertices, int $vertex_count, string $indices, int $index_count, Transform $transform, ?TextureHandle $texture = null, int $instances = 1): void
    {
        $this->assertInFrame('drawIndexed');
        if ($index_count < 1) {
            return;
        }

        $this->bindDraw($vertices, $transform, $texture);
        $payload = $this->stagePayload($indices);
        GL15::glBindBuffer(BufferTargetARB::ELEMENT_ARRAY_BUFFER, $this->ebo);
        GL15::glBufferData(BufferTargetARB::ELEMENT_ARRAY_BUFFER, strlen($indices), $payload, BufferUsageARB::STREAM_DRAW);

        $mode = $this->primitive($topology);
        if ($instances > 1) {
            GL31::glDrawElementsInstanced($mode, $index_count, DrawElementsType::UNSIGNED_SHORT, 0, $instances);

            return;
        }
        GL11::glDrawElements($mode, $index_count, DrawElementsType::UNSIGNED_SHORT, 0);
    }

    public function readPixels(): string
    {
        $this->assertInFrame('readPixels');
        $bytes = $this->pixel_width * $this->pixel_height * 4;
        $payload = $this->reservePayload($bytes);
        GL10::glFinish();
        GL10::glReadPixels(0, 0, $this->pixel_width, $this->pixel_height, PixelFormat::RGBA, PixelType::UNSIGNED_BYTE, $payload);
        $bottom_up = Bridge::read($this->staging, StagingRegion::PAYLOAD->value, $bytes);
        if (is_null($bottom_up)) {
            throw new OpenGLDrawingException('readback block could not be read');
        }

        return self::flipRows($bottom_up, $this->pixel_width, $this->pixel_height);
    }

    public function endFrame(): void
    {
        if (! $this->in_frame) {
            return;
        }
        GL10::glFlush();
        $this->in_frame = false;
        $this->gl->present();
    }

    public function release(): void
    {
        if ($this->released) {
            return;
        }
        if ($this->initialised) {
            $this->gl->makeCurrent();
            $this->in_frame = false;
            foreach ($this->textures as $name) {
                $this->deleteName($name, GL11::glDeleteTextures(...));
            }
            $this->deleteName($this->vbo, GL15::glDeleteBuffers(...));
            $this->deleteName($this->ebo, GL15::glDeleteBuffers(...));
            $this->deleteName($this->vao, GL30::glDeleteVertexArrays(...));
            GL20::glDeleteProgram($this->program);
            GL20::glDeleteShader($this->vertex_shader);
            GL20::glDeleteShader($this->fragment_shader);
            Bridge::free($this->staging);
        }
        $this->textures = [];
        $this->program = $this->vao = $this->vbo = $this->ebo = $this->vertex_shader = $this->fragment_shader = 0;
        $this->staging = 0;
        $this->staging_size = 0;
        $this->released = true;
    }

    // ---- lazy init

    /** Called with the context current. Compiles once; every later call is a no-op. */
    private function initialise(): void
    {
        if ($this->initialised) {
            return;
        }
        if (! Bridge::load()) {
            throw new OpenGLDrawingException('Bridge::load() could not open an OpenGL library');
        }
        $this->context_version = Bridge::contextVersion();
        $this->staging_size = self::grownSize(0, 0);
        $this->staging = Bridge::alloc($this->staging_size);
        if ($this->staging === 0) {
            throw new OpenGLDrawingException('staging block allocation failed');
        }

        $version = GL10::glGetString(StringName::VERSION) ?? '';
        $this->dialect = GLSLDialect::fromVersionString($version);
        $pair = ShaderSource::for($this->dialect);

        $this->vertex_shader = $this->compile(ShaderType::VERTEX_SHADER, $pair['vertex'], 'vertex');
        $this->fragment_shader = $this->compile(ShaderType::FRAGMENT_SHADER, $pair['fragment'], 'fragment');
        $this->program = GL20::glCreateProgram();
        GL20::glAttachShader($this->program, $this->vertex_shader);
        GL20::glAttachShader($this->program, $this->fragment_shader);
        foreach (PainterAttribute::cases() as $attribute) {
            GL20::glBindAttribLocation($this->program, $attribute->value, $attribute->glslName());
        }
        GL20::glLinkProgram($this->program);
        GL20::glGetProgramiv($this->program, ProgramPropertyARB::LINK_STATUS, $this->staging + StagingRegion::SCRATCH->value);
        if ($this->readScratchInt() !== 1) {
            throw OpenGLDrawingException::linkFailed($this->programLog());
        }
        GL20::glUseProgram($this->program);
        $this->u_transform = GL20::glGetUniformLocation($this->program, 'uTransform');
        $this->u_textured = GL20::glGetUniformLocation($this->program, 'uTextured');
        $this->u_texture = GL20::glGetUniformLocation($this->program, 'uTexture');
        GL20::glUniform1i($this->u_texture, 0);

        GL30::glGenVertexArrays(1, $this->staging + StagingRegion::SCRATCH->value);
        $this->vao = $this->readScratchInt();
        GL30::glBindVertexArray($this->vao);
        GL15::glGenBuffers(1, $this->staging + StagingRegion::SCRATCH->value);
        $this->vbo = $this->readScratchInt();
        GL15::glGenBuffers(1, $this->staging + StagingRegion::SCRATCH->value);
        $this->ebo = $this->readScratchInt();
        GL15::glBindBuffer(BufferTargetARB::ARRAY_BUFFER, $this->vbo);
        foreach (PainterAttribute::cases() as $attribute) {
            GL20::glEnableVertexAttribArray($attribute->value);
            GL20::glVertexAttribPointer($attribute->value, $attribute->components(), VertexAttribPointerType::FLOAT, false, PainterAttribute::stride(), $attribute->offset());
        }

        GL10::glEnable(EnableCap::BLEND);
        GL10::glBlendFunc(BlendingFactor::SRC_ALPHA, BlendingFactor::ONE_MINUS_SRC_ALPHA);

        GL10::glGetIntegerv(GetPName::MAX_TEXTURE_SIZE, $this->staging + StagingRegion::SCRATCH->value);
        $this->max_texture_size = max(2048, $this->readScratchInt());

        $this->initialised = true;
    }

    private function compile(ShaderType $stage, string $source, string $label): int
    {
        $shader = GL20::glCreateShader($stage);
        if ($shader === 0) {
            throw OpenGLDrawingException::compileFailed($label, 'glCreateShader returned 0');
        }
        GL20::glShaderSource($shader, 1, [$source], 0);
        GL20::glCompileShader($shader);
        GL20::glGetShaderiv($shader, ShaderParameterName::COMPILE_STATUS, $this->staging + StagingRegion::SCRATCH->value);
        if ($this->readScratchInt() === 1) {
            return $shader;
        }
        GL20::glGetShaderiv($shader, ShaderParameterName::INFO_LOG_LENGTH, $this->staging + StagingRegion::SCRATCH->value);
        $length = max(1, $this->readScratchInt());
        $log = $this->reservePayload($length);
        GL20::glGetShaderInfoLog($shader, $length, 0, $log);

        throw OpenGLDrawingException::compileFailed($label, (string) Bridge::read($this->staging, StagingRegion::PAYLOAD->value, $length));
    }

    private function programLog(): string
    {
        GL20::glGetProgramiv($this->program, ProgramPropertyARB::INFO_LOG_LENGTH, $this->staging + StagingRegion::SCRATCH->value);
        $length = max(1, $this->readScratchInt());
        $log = $this->reservePayload($length);
        GL20::glGetProgramInfoLog($this->program, $length, 0, $log);

        return (string) Bridge::read($this->staging, StagingRegion::PAYLOAD->value, $length);
    }

    // ---- per-draw

    private function bindDraw(string $vertices, Transform $transform, ?TextureHandle $texture): void
    {
        $payload = $this->stagePayload($vertices);
        GL15::glBindBuffer(BufferTargetARB::ARRAY_BUFFER, $this->vbo);
        GL15::glBufferData(BufferTargetARB::ARRAY_BUFFER, strlen($vertices), $payload, BufferUsageARB::STREAM_DRAW);

        Bridge::write($this->staging, StagingRegion::TRANSFORM->value, $transform->toPacked());
        GL20::glUniformMatrix4fv($this->u_transform, 1, false, $this->staging + StagingRegion::TRANSFORM->value);

        if (is_null($texture)) {
            GL20::glUniform1i($this->u_textured, 0);

            return;
        }
        $name = $this->textures[$texture->id] ?? null;
        if (is_null($name)) {
            throw new OpenGLDrawingException('texture handle is not held by this executor');
        }
        GL13::glActiveTexture(TextureUnit::TEXTURE0);
        GL11::glBindTexture(TextureTarget::TEXTURE_2D, $name);
        GL20::glUniform1i($this->u_textured, 1);
    }

    private function primitive(Topology $topology): PrimitiveType
    {
        return match ($topology) {
            Topology::POINTS => PrimitiveType::POINTS,
            Topology::LINES => PrimitiveType::LINES,
            Topology::LINE_STRIP => PrimitiveType::LINE_STRIP,
            Topology::TRIANGLES => PrimitiveType::TRIANGLES,
            Topology::TRIANGLE_STRIP => PrimitiveType::TRIANGLE_STRIP,
        };
    }

    // ---- staging block

    /** Pointer to a payload region of at least $bytes, growing the block (and preserving nothing — payload is per-call). */
    private function reservePayload(int $bytes): int
    {
        $size = self::grownSize($this->staging_size, $bytes);
        if ($size !== $this->staging_size) {
            $grown = Bridge::alloc($size);
            if ($grown === 0) {
                throw new OpenGLDrawingException("staging block growth to {$size} bytes failed");
            }
            Bridge::free($this->staging);
            $this->staging = $grown;
            $this->staging_size = $size;
        }

        return $this->staging + StagingRegion::PAYLOAD->value;
    }

    /** Copy bytes into the payload region and answer its pointer. */
    private function stagePayload(string $bytes): int
    {
        $payload = $this->reservePayload(max(4, strlen($bytes)));
        Bridge::write($this->staging, StagingRegion::PAYLOAD->value, $bytes === '' ? pack('V', 0) : $bytes);

        return $payload;
    }

    private function readScratchInt(): int
    {
        return unpack('l', (string) Bridge::read($this->staging, StagingRegion::SCRATCH->value, 4))[1];
    }

    /** Write one GL name into scratch and hand the pointer to a glDelete*(1, ptr). */
    private function deleteName(int $name, callable $delete): void
    {
        if ($name === 0) {
            return;
        }
        Bridge::write($this->staging, StagingRegion::SCRATCH->value, pack('l', $name));
        $delete(1, $this->staging + StagingRegion::SCRATCH->value);
    }

    // ---- guards

    private function assertAlive(): void
    {
        if ($this->released) {
            throw OpenGLDrawingException::released();
        }
    }

    private function assertInFrame(string $operation): void
    {
        $this->assertAlive();
        if (! $this->in_frame) {
            throw OpenGLDrawingException::outsideFrame($operation);
        }
    }
}
