<?php

declare(strict_types=1);

use Jovian\Engines\OpenGL\OpenGLDevice;
use Jovian\Engines\OpenGL\OpenGLFramebuffer;
use Surface\Contracts\Drawing\DrawingException;
use Surface\Contracts\Drawing\SurfaceKind;
use Surface\Contracts\Framebuffers\FormatSpec;
use Surface\Contracts\Framebuffers\GLFramebuffer;
use Surface\Contracts\Framebuffers\Region;
use Surface\Drawing\Gpu\GpuDevice;

it('is a GpuDevice named opengl over its own offscreen context, in this platform\'s dialect', function (): void {
    $device = new OpenGLDevice;

    expect($device)->toBeInstanceOf(GpuDevice::class)
        ->and($device->name())->toBe('opengl')
        ->and($device->dialect())->toBe(OPENGL_MAC ? 'core' : 'es')
        ->and($device->maxSamples())->toBeGreaterThanOrEqual(4)
        ->and($device->surfaces())->toBe([SurfaceKind::GL_CONTEXT])
        ->and($device->handles())->toBe([]);
});

it('makes its context current for its own calls and puts the previous one back', function (): void {
    $first = new OpenGLDevice;
    $second = new OpenGLDevice;
    $first->current();
    $before = OPENGL_MAC ? CGLGetCurrentContext()?->pointer() : eglGetCurrentContext()?->pointer();

    $second->target(8, 8, 1);

    $after = OPENGL_MAC ? CGLGetCurrentContext()?->pointer() : eglGetCurrentContext()?->pointer();
    expect($before)->not->toBeNull()
        ->and($after)->toBe($before);
});

it('makes a transparent black RGBA8 target of the asked size', function (int $samples): void {
    $target = (new OpenGLDevice)->target(24, 8, $samples);

    expect($target)->toBeInstanceOf(OpenGLFramebuffer::class)
        ->and($target)->toBeInstanceOf(GLFramebuffer::class)
        ->and([$target->viewportWidth(), $target->viewportHeight()])->toBe([24, 8])
        ->and($target->hostFormat())->toEqual(FormatSpec::rgba8())
        ->and($target->toRgba8())->toBe(str_repeat("\x00\x00\x00\x00", 24 * 8))
        ->and($target->texture())->toBeGreaterThan(0);
})->with([1, 4]);

it('draws with one sample or four', function (): void {
    (new OpenGLDevice)->target(8, 8, 2);
})->throws(DrawingException::class, 'opengl draws with 1 or 4 samples, got 2.');

it('refuses four samples when the context has fewer', function (): void {
    $device = new class extends OpenGLDevice
    {
        public function maxSamples(): int
        {
            return 2;
        }
    };

    $device->target(8, 8, 4);
})->throws(DrawingException::class, 'opengl: this context renders at most 2 samples; anti-aliased edges need 4.');

it('refuses an empty target', function (): void {
    (new OpenGLDevice)->target(0, 8, 4);
})->throws(DrawingException::class, 'opengl needs a target of at least 1 × 1, got 0 × 8.');

it('uploads a region and reads it back, tightly packed, rows top-down', function (int $samples): void {
    $target = (new OpenGLDevice)->target(16, 8, $samples);
    $block = "\x10\x20\x30\xff\x11\x21\x31\xff\x12\x22\x32\xff".str_repeat("\x40\x50\x60\xff", 3);

    $target->uploadRgba8($block, new Region(5, 1, 3, 2));

    expect($target->readRgba8(new Region(5, 1, 3, 2)))->toBe($block)
        ->and(strlen($target->readRgba8(new Region(0, 0, 16, 8))))->toBe(16 * 8 * 4)
        ->and(pixelOf($target->toRgba8(), 16, 5, 1))->toBe('102030ff')
        ->and(pixelOf($target->toRgba8(), 16, 7, 1))->toBe('122232ff')
        ->and(pixelOf($target->toRgba8(), 16, 6, 2))->toBe('405060ff')
        ->and(pixelOf($target->toRgba8(), 16, 4, 1))->toBe('00000000');
})->with([1, 4]);

it('serves the pixel calls of a Framebuffer through the GPU', function (): void {
    $target = (new OpenGLDevice)->target(16, 8, 4);

    $target->setPixel(2, 2, 0xFF0000FF)->fill(0x0000FFFF)->setSegment(0, 0, 4, 1, 0x00FF00FF);

    expect(pixelOf($target->toRgba8(), 16, 2, 2))->toBe('0000ffff')
        ->and(pixelOf($target->toRgba8(), 16, 1, 0))->toBe('00ff00ff')
        ->and($target->getPixel(1, 0))->toBe(0x00FF00FF)
        ->and($target->damage())->toEqual([new Region(0, 0, 16, 8)]);
});

it('keeps an old framebuffer readable after the target is re-made', function (): void {
    $device = new OpenGLDevice;
    $old = $device->target(8, 8, 4);
    $old->fill(0xFF0000FF);

    $new = $device->target(12, 6, 1);

    expect($new)->not->toBe($old)
        ->and([$new->viewportWidth(), $new->viewportHeight()])->toBe([12, 6])
        ->and(pixelOf($old->toRgba8(), 8, 3, 3))->toBe('ff0000ff')
        ->and(pixelOf($new->toRgba8(), 12, 3, 3))->toBe('00000000');
});

it('compiles the program on this GPU', function (): void {
    $device = new OpenGLDevice;
    $device->current();

    expect($device->program())->toBeGreaterThan(0)
        ->and(glGetError())->toBe(GL_NO_ERROR);
});

it('reads frame after frame back without holding on to GPU memory', function (): void {
    // 1 MB a readback at 512 × 512, and a texture a frame for the restore after each upload.
    $engine = openglEngine(512, 512);
    $frame = function (int $i) use ($engine): void {
        $engine->frame(fn (Surface\Contracts\Drawing\RenderingEngine $g) => $g->clear(Surface\NutsAndBolts\Color::rgb($i % 256, 0, 0)));
        $engine->framebuffer()->toRgba8();
        $engine->framebuffer()->setPixel(1, 1, 0xFFFFFFFF);
    };
    foreach (range(1, 20) as $i) {
        $frame($i);
    }
    $before = residentMb();

    foreach (range(1, 150) as $i) {
        $frame($i);
    }

    expect(residentMb() - $before)->toBeLessThan(48.0);
});

it('refuses an upload whose bytes do not fill the region', function (): void {
    (new OpenGLDevice)->target(16, 8, 1)->uploadRgba8("\xff\x00\x00\xff", new Region(0, 0, 16, 8));
})->throws(DrawingException::class, 'opengl: 4 bytes do not fill a 16 × 8 region (512 bytes).');

it('refuses a region outside the target', function (string $call): void {
    $target = (new OpenGLDevice)->target(16, 8, 1);
    $region = new Region(10, 4, 16, 8);

    $call === 'read' ? $target->readRgba8($region) : $target->uploadRgba8(str_repeat("\x00", 16 * 8 * 4), $region);
})->with(['read', 'upload'])->throws(DrawingException::class, 'opengl: the region 16 × 8 at (10, 4) lies outside the 16 × 8 target.');

it('refuses a target past the largest texture', function (): void {
    $device = new OpenGLDevice;
    $largest = $device->maxTextureSize();

    expect(fn () => $device->target($largest + 1, 8, 1))
        ->toThrow(DrawingException::class, 'opengl: '.($largest + 1)." × 8 is past the largest texture this context makes, {$largest} × {$largest}.");
});

it('uploads and reads back tightly packed whatever pixel-store state another caller left', function (): void {
    $device = new OpenGLDevice;
    $target = $device->target(16, 8, 4);
    $device->current();
    foreach ([GL_PACK_ALIGNMENT => 8, GL_UNPACK_ALIGNMENT => 8, GL_PACK_ROW_LENGTH => 7, GL_UNPACK_ROW_LENGTH => 7,
        GL_PACK_SKIP_ROWS => 2, GL_PACK_SKIP_PIXELS => 2, GL_UNPACK_SKIP_ROWS => 2, GL_UNPACK_SKIP_PIXELS => 2] as $name => $value) {
        glPixelStorei($name, $value);
    }
    $block = "\x10\x20\x30\xff\x11\x21\x31\xff\x12\x22\x32\xff".str_repeat("\x40\x50\x60\xff", 3);

    $target->uploadRgba8($block, new Region(5, 1, 3, 2));

    expect($target->readRgba8(new Region(5, 1, 3, 2)))->toBe($block)
        ->and(pixelOf($target->toRgba8(), 16, 4, 1))->toBe('00000000');
});

it('refuses calls on a released device with its own message', function (): void {
    $device = new OpenGLDevice;
    $device->release();

    $device->current();
})->throws(DrawingException::class, 'opengl: this device was released.');
