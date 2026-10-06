<?php

declare(strict_types=1);

use Jovian\Engines\OpenGL\OpenGLDevice;
use Jovian\Engines\OpenGL\Providers\VenusianOpenGLServiceProvider;
use Surface\Contracts\Drawing\DrawingException;
use Surface\Contracts\Drawing\LentSurface;
use Surface\Contracts\Drawing\RenderingEngine;
use Surface\Contracts\Drawing\SurfaceBorrower;
use Surface\Contracts\Drawing\SurfaceKind;
use Surface\Contracts\Drawing\WindowOutput;
use Surface\Contracts\Framebuffers\FormatSpec;
use Surface\Contracts\Framebuffers\Framebuffer;
use Surface\Drawing\Gpu\GpuRenderingEngine;
use Surface\NutsAndBolts\Color;

it('registers the opengl engine on the drawing manager', function (): void {
    $drawing = openglDrawing();

    VenusianOpenGLServiceProvider::extend($drawing);
    $engine = $drawing->renderer('opengl', ['width' => 16, 'height' => 8, 'edges' => 'hard']);

    expect($drawing->engines())->toBe(['velvet', 'opengl'])
        ->and($engine)->toBeInstanceOf(GpuRenderingEngine::class)
        ->and($engine->name())->toBe('opengl')
        ->and($engine->device())->toBeInstanceOf(OpenGLDevice::class)
        ->and([$engine->width(), $engine->height()])->toBe([16, 8]);

    $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(255, 255, 255)));
    expect(pixelOf($engine->framebuffer()->toRgba8(), 16, 0, 0))->toBe('ffffffff');
});

it('refuses the Velvet-only arguments by name', function (): void {
    $drawing = openglDrawing();
    VenusianOpenGLServiceProvider::extend($drawing);

    $drawing->renderer('opengl', ['width' => 16, 'height' => 8, 'mode' => 'ring']);
})->throws(DrawingException::class, "opengl does not take 'mode'. It takes: output, width, height, edges.");

/** An output that says it lends a GL context; deviceFor() reads nothing else of it. */
final class ContextLendingOutput implements WindowOutput
{
    public function surfaces(): array
    {
        return [SurfaceKind::GL_CONTEXT];
    }

    public function boundFramebuffer(): ?Framebuffer { throw new LogicException('not in this test'); }

    public function framebuffer(): Framebuffer { throw new LogicException('not in this test'); }

    public function present(): static { throw new LogicException('not in this test'); }

    public function canPipe(Framebuffer $framebuffer): bool { throw new LogicException('not in this test'); }

    public function pixelSize(): array { throw new LogicException('not in this test'); }

    public function pixelFormat(): FormatSpec { throw new LogicException('not in this test'); }

    public function lend(SurfaceKind $kind, SurfaceBorrower $to): LentSurface { throw new LogicException('not in this test'); }

    public function lent(): ?LentSurface { throw new LogicException('not in this test'); }

    public function reclaim(): void { throw new LogicException('not in this test'); }
}

it('builds an adopting device for an output that lends a GL context', function (): void {
    $canvas = new ContextLendingOutput;

    expect(VenusianOpenGLServiceProvider::deviceFor(['output' => $canvas]))->toBeInstanceOf(OpenGLDevice::class)
        ->and(fn () => VenusianOpenGLServiceProvider::deviceFor(['output' => $canvas])->dialect())->toThrow(DrawingException::class, 'no context yet')
        ->and(VenusianOpenGLServiceProvider::deviceFor(['width' => 8, 'height' => 8])->dialect())->toBe(OPENGL_MAC ? 'core' : 'es');
});

it('builds no context for a window that lends none: the refusal comes before anything is made', function (): void {
    $canvas = new class implements WindowOutput
    {
        public function surfaces(): array { return [SurfaceKind::METAL_LAYER]; }

        public function boundFramebuffer(): ?Framebuffer { throw new LogicException('not in this test'); }

        public function framebuffer(): Framebuffer { throw new LogicException('not in this test'); }

        public function present(): static { throw new LogicException('not in this test'); }

        public function canPipe(Framebuffer $framebuffer): bool { throw new LogicException('not in this test'); }

        public function pixelSize(): array { throw new LogicException('not in this test'); }

        public function pixelFormat(): FormatSpec { throw new LogicException('not in this test'); }

        public function lend(SurfaceKind $kind, SurfaceBorrower $to): LentSurface { throw new LogicException('not in this test'); }

        public function lent(): ?LentSurface { throw new LogicException('not in this test'); }

        public function reclaim(): void { throw new LogicException('not in this test'); }
    };

    expect(fn () => VenusianOpenGLServiceProvider::deviceFor(['output' => $canvas])->dialect())->toThrow(DrawingException::class, 'no context yet');
});

