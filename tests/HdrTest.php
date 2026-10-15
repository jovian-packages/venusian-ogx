<?php

declare(strict_types=1);

use Jovian\Engines\OpenGL\OpenGLDevice;
use Surface\Contracts\Drawing\ColorSpace;
use Surface\Contracts\Drawing\DrawingException;
use Surface\Contracts\Drawing\LentSurface;
use Surface\Contracts\Drawing\RenderingEngine;
use Surface\Contracts\Drawing\SurfaceKind;
use Surface\Contracts\Drawing\TargetFormat;
use Surface\Contracts\Framebuffers\Region;
use Surface\Contracts\Rasterize\Edges;
use Surface\Drawing\Gpu\GpuRenderingEngine;
use Surface\Framebuffers\HdrImage;
use Surface\NutsAndBolts\Color;

/*
 * HDR targets: linear half floats (SDR white 1.0) in any context that renders
 * them; shown only in a window surface the device made itself in the right
 * colour space.
 */

function glHdrEngine(int $width, int $height, ColorSpace $space = ColorSpace::ExtendedLinearSrgb): GpuRenderingEngine
{
    return new GpuRenderingEngine(new OpenGLDevice, $width, $height, Edges::HARD, format: $space === ColorSpace::Hdr10Pq ? TargetFormat::Rgb10A2 : TargetFormat::Rgba16Float, colorspace: $space);
}

it('lists HDR and Display P3 targets', function (): void {
    $expected = OPENGL_MAC && ! class_exists(NSColorSpace::class) ? [] : [
        [TargetFormat::Rgba16Float, ColorSpace::ExtendedLinearSrgb],
        [TargetFormat::Rgba16Float, ColorSpace::ExtendedLinearDisplayP3],
        [TargetFormat::Rgb10A2, ColorSpace::Hdr10Pq],
        [TargetFormat::Rgba8, ColorSpace::DisplayP3],
    ];

    expect(OpenGLDevice::adopting()->targetFormats())->toBe($expected);
});

it('draws SDR colours as linear light with SDR white at 1.0, and reads them back as they were', function (ColorSpace $space): void {
    $engine = glHdrEngine(4, 4, $space);
    $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(255, 255, 255)));
    $white = $engine->hdrSnapshot()->rgba16f();
    $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(128, 64, 0)));

    expect($white)->toBe(str_repeat(pack('v4', 0x3C00, 0x3C00, 0x3C00, 0x3C00), 16))
        ->and(pixelOf($engine->framebuffer()->toRgba8(), 4, 2, 2))->toBe('804000ff');
})->with([ColorSpace::ExtendedLinearSrgb, ColorSpace::Hdr10Pq]);

it('draws an HdrImage brighter than SDR white', function (): void {
    $engine = glHdrEngine(2, 1);
    $hdr = HdrImage::fromFloats([4.0, 2.0, 1.0, 1.0, 0.5, 0.5, 0.5, 1.0], 2, 1);
    $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 0, 0))->image($hdr, 0, 0));

    expect($engine->hdrSnapshot()->rgba16f())->toBe(pack('v8', 0x4400, 0x4000, 0x3C00, 0x3C00, 0x3800, 0x3800, 0x3800, 0x3C00));
});

it('writes RGBA8 into an HDR target as linear light', function (): void {
    $engine = glHdrEngine(1, 1);
    $engine->framebuffer()->uploadRgba8("\xFF\xFF\xFF\xFF", new Region(0, 0, 1, 1));

    expect($engine->hdrSnapshot()->rgba16f())->toBe(pack('v4', 0x3C00, 0x3C00, 0x3C00, 0x3C00));
});

it('asks for a native window when made for a colour space past sRGB', function (): void {
    expect(OpenGLDevice::adopting()->handles())->toBe([])
        ->and(OpenGLDevice::adopting(ColorSpace::ExtendedLinearSrgb)->handles())->toBe(['native' => 1]);
});

it('refuses HDR in a host-swapped context, naming what it needs', function (): void {
    $device = OpenGLDevice::adopting(ColorSpace::ExtendedLinearSrgb);
    $device->adopt(lentContext(8, 8));

    expect(fn () => $device->targetAs(8, 8, 1, TargetFormat::Rgba16Float, ColorSpace::ExtendedLinearSrgb))
        ->toThrow(DrawingException::class, 'opengl: a GL context lent by its host shows RGBA8 sRGB only; a extended-linear-srgb target needs a window that hands over its native window (a GLFW or SDL3 staged window).');
    $device->release();
});

it('makes its own EDR surface on a macOS view, shows into it and swaps', function (): void {
    $window = NSWindow::initWithContentRectStyleMaskBackingDefer(new NSRect(0.0, 0.0, 64.0, 48.0), NSWindowStyleMask::TITLED->value, NSBackingStoreType::BUFFERED, false);
    $window->setReleasedWhenClosed(false);
    $window->makeKeyAndOrderFront(null);
    $view = $window->contentView();
    $surface = new LentSurface(SurfaceKind::GL_CONTEXT, ['context' => 0, 'view' => $view->pointer()], fn (): array => [64, 48]);
    $device = OpenGLDevice::adopting(ColorSpace::ExtendedLinearSrgb);
    $device->adopt($surface);
    $engine = new GpuRenderingEngine($device, 32, 24, Edges::HARD, format: TargetFormat::Rgba16Float, colorspace: ColorSpace::ExtendedLinearSrgb);
    $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(255, 255, 255)));

    $shown = $device->present($surface);

    expect($shown)->toBeTrue()
        ->and($view->wantsExtendedDynamicRangeOpenGLSurface())->toBeTrue()
        ->and($window->colorSpace()?->localizedName())->toBe(NSColorSpace::initWithCGColorSpace(CGColorSpace::createWithName('kCGColorSpaceExtendedLinearSRGB'))->localizedName())
        ->and($device->transform()[0])->toBe(1)
        ->and(fn () => $device->targetAs(8, 8, 1, TargetFormat::Rgba8, ColorSpace::DisplayP3))->toThrow(DrawingException::class, "opengl: this device's window surface was made for extended-linear-srgb, not display-p3.");
    $surface->release();
    expect($view->wantsExtendedDynamicRangeOpenGLSurface())->toBeFalse()
        ->and($window->colorSpace()?->localizedName())->not->toBe(NSColorSpace::initWithCGColorSpace(CGColorSpace::createWithName('kCGColorSpaceExtendedLinearSRGB'))->localizedName());
    $window->close();
})->skip(! OPENGL_MAC || ! class_exists(NSColorSpace::class), 'macOS with ext-appkit');

it('makes its own EGL window surface in the colour space, or refuses naming what the display lacks', function (ColorSpace $space, string $extension): void {
    glfwInitHint(GLFW_WAYLAND_LIBDECOR, GLFW_WAYLAND_DISABLE_LIBDECOR);
    glfwInit() || throw new RuntimeException('glfwInit');
    glfwDefaultWindowHints();
    glfwWindowHint(GLFW_CLIENT_API, GLFW_NO_API);
    $window = glfwCreateWindow(64, 48, 'venusian-opengl window surface');
    $wayland = glfwGetPlatform() === GLFW_PLATFORM_WAYLAND;
    $handles = $wayland
        ? ['context' => 0, 'wl_display' => glfwGetWaylandDisplay(), 'wl_surface' => glfwGetWaylandWindow($window)]
        : ['context' => 0, 'x11_display' => glfwGetX11Display(), 'x11_window' => glfwGetX11Window($window)];
    $surface = new LentSurface(SurfaceKind::GL_CONTEXT, $handles, fn (): array => [64, 48]);
    $display = eglGetPlatformDisplay($wayland ? EGL_PLATFORM_WAYLAND_KHR : EGL_PLATFORM_X11_KHR, $wayland ? glfwGetWaylandDisplay() : glfwGetX11Display(), null);
    eglInitialize($display, $major, $minor);
    $listed = str_contains((string) eglQueryString($display, EGL_EXTENSIONS), $extension);
    $device = OpenGLDevice::adopting($space);

    if (! $listed) {
        expect(fn () => $device->adopt($surface))->toThrow(DrawingException::class, "opengl: this EGL display shows no {$space->value} surface: it lacks {$extension}");
    } else {
        $device->adopt($surface);
        $engine = new GpuRenderingEngine($device, 32, 24, Edges::HARD, format: $space === ColorSpace::Hdr10Pq ? TargetFormat::Rgb10A2 : TargetFormat::Rgba16Float, colorspace: $space);
        $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(255, 255, 255)));
        expect($device->present($surface))->toBeTrue();
        $surface->release();
    }
    glfwDestroyWindow($window);
})->with([
    'extended linear' => [ColorSpace::ExtendedLinearSrgb, 'EGL_EXT_gl_colorspace_scrgb_linear'],
    'HDR10' => [ColorSpace::Hdr10Pq, 'EGL_EXT_gl_colorspace_bt2020_pq'],
])->skip(OPENGL_MAC || ! extension_loaded('glfw') || getenv('WAYLAND_DISPLAY') === false, 'needs Linux, ext-glfw and the Wayland session');
