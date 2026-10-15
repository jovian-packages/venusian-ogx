<?php

declare(strict_types=1);

use Jovian\Engines\OpenGL\OpenGLDevice;
use Surface\Contracts\Drawing\LentSurface;
use Surface\Contracts\Drawing\SurfaceKind;
use Surface\Contracts\Rasterize\Edges;
use Surface\Drawing\DrawingManager;
use Surface\Drawing\Gpu\GpuRenderingEngine;
use Surface\Framebuffers\FramebufferManager;
use Surface\Rasterize\RasterizeManager;

if (! extension_loaded('opengl')) {
    throw new RuntimeException('venusian-opengl tests need ext-opengl loaded.');
}

const OPENGL_MAC = PHP_OS_FAMILY === 'Darwin';

// The parity suite every engine runs lives in the Surface checkout (export-ignored from its package).
$parity = (getenv('SURFACE_TESTS') ?: dirname(__DIR__, 3).'/venusian/surface/tests').'/Support/GpuParity/GpuParity.php';
if (! is_file($parity)) {
    throw new RuntimeException("The GPU parity suite was not found at {$parity}: set SURFACE_TESTS to a Surface checkout's tests directory.");
}
require_once $parity;

/** An OpenGL engine with no output, over its own offscreen context. */
function openglEngine(int $width = 64, int $height = 32, Edges $edges = Edges::ANTIALIASED): GpuRenderingEngine
{
    return new GpuRenderingEngine(new OpenGLDevice, $width, $height, $edges);
}

/** The RGBA8 pixel at ($x, $y) of a tightly packed frame, as hex. */
function pixelOf(string $rgba8, int $width, int $x, int $y): string
{
    return bin2hex(substr($rgba8, ($y * $width + $x) * 4, 4));
}

/** A config repository over an array, as Surface's own suite builds one. */
function openglConfig(array $items): object
{
    return new class($items)
    {
        public function __construct(private readonly array $items) {}

        public function get(string $key, mixed $default = null): mixed
        {
            return $this->items[$key] ?? $default;
        }
    };
}

/** A drawing manager without a container: Velvet registered, nothing else. */
function openglDrawing(): DrawingManager
{
    $framebuffers = new class extends FramebufferManager
    {
        public function __construct()
        {
            $this->config = openglConfig([]);
        }
    };
    $rasterize = new class extends RasterizeManager
    {
        public function __construct()
        {
            $this->config = openglConfig([]);
        }
    };

    return new DrawingManager(openglConfig([]), $framebuffers, $rasterize);
}

/**
 * The process's memory now, in MB, GPU allocations included: macOS's
 * phys_footprint (private GPU textures count there, not in RSS); on Linux
 * VmRSS plus each DRM client's drm-total-* (V3D's buffers are not in VmRSS).
 */
function residentMb(): float
{
    if (PHP_OS_FAMILY === 'Darwin') {
        preg_match('/phys_footprint:\s+([\d.]+)\s+(KB|MB|GB)/', (string) shell_exec('footprint -p '.getmypid().' 2>/dev/null'), $m)
            || throw new RuntimeException('footprint gave no phys_footprint for this process.');

        return (float) $m[1] * ['KB' => 1 / 1024, 'MB' => 1, 'GB' => 1024][$m[2]];
    }
    preg_match('/^VmRSS:\s+(\d+) kB/m', (string) file_get_contents('/proc/self/status'), $m)
        || throw new RuntimeException('/proc/self/status gave no VmRSS.');
    $kib = (int) $m[1];
    $clients = [];
    // An fd can close between the listing and the read: that entry is skipped, not reported.
    set_error_handler(fn (): bool => true);
    try {
        $infos = array_map(fn (string $fdinfo): string => (string) file_get_contents($fdinfo), glob('/proc/self/fdinfo/*') ?: []);
    } finally {
        restore_error_handler();
    }
    foreach ($infos as $info) {
        if (preg_match('/^drm-client-id:\s+(\d+)/m', $info, $client) && ! isset($clients[$client[1]])) {
            preg_match_all('/^drm-total-\S+:\s+(\d+) KiB/m', $info, $totals);
            $clients[$client[1]] = array_sum(array_map('intval', $totals[1]));
        }
    }

    return ($kib + array_sum($clients)) / 1024;
}

/** A context of the platform's dialect, made current, lent as a canvas lends one. */
function lentContext(int $width = 32, int $height = 16): LentSurface
{
    if (OPENGL_MAC) {
        CGLChoosePixelFormat([kCGLPFAOpenGLProfile, kCGLOGLPVersion_GL4_Core, kCGLPFAAccelerated, kCGLPFAColorSize, 24, kCGLPFAAlphaSize, 8, 0], $pixelFormat, $count);
        CGLCreateContext($pixelFormat, null, $context);
        CGLDestroyPixelFormat($pixelFormat);
        CGLSetCurrentContext($context);

        return new LentSurface(SurfaceKind::GL_CONTEXT, ['context' => $context->pointer()], fn (): array => [$width, $height]);
    }
    $display = eglGetPlatformDisplay(EGL_PLATFORM_SURFACELESS_MESA, EGL_DEFAULT_DISPLAY, null);
    eglInitialize($display, $major, $minor);
    eglBindAPI(EGL_OPENGL_ES_API);
    eglChooseConfig($display, [EGL_RENDERABLE_TYPE, EGL_OPENGL_ES3_BIT, EGL_SURFACE_TYPE, EGL_PBUFFER_BIT, EGL_NONE], $configs, 1, $count);
    $context = eglCreateContext($display, $configs[0], null, [EGL_CONTEXT_MAJOR_VERSION, 3, EGL_CONTEXT_MINOR_VERSION, 1, EGL_NONE]);
    eglMakeCurrent($display, null, null, $context);

    return new LentSurface(SurfaceKind::GL_CONTEXT, ['context' => $context->pointer(), 'display' => $display->pointer()], fn (): array => [$width, $height]);
}
