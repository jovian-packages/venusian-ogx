<?php

declare(strict_types=1);

namespace Jovian\Engines\OpenGL;

use CGColorSpace;
use CGLContextObj;
use EGLConfig;
use EGLContext;
use EGLDisplay;
use EGLSurface;
use NSColorSpace;
use NSOpenGLContext;
use NSOpenGLContextParameter;
use NSOpenGLPixelFormat;
use NSView;
use Surface\Contracts\Drawing\ColorSpace;
use Surface\Contracts\Drawing\DrawingException;
use Surface\Contracts\Drawing\VSync;
use Surface\Contracts\Framebuffers\Region;
use Surface\Contracts\Windows\Hdr;

/**
 * @internal A GL context and window surface the opengl device makes itself on
 * a window's native handles, in the colour space its target needs: a host's
 * own context carries the host's pixel format and colour space, which are
 * SDR. macOS: an NSOpenGLContext on the view, half floats and extended
 * dynamic range for HDR, the window in extended-linear sRGB (HDR) or Display
 * P3; macOS has no PQ GL surface, so HDR10 shows the same light through EDR.
 * Linux: EGL on the Wayland or X11 display, a window surface in
 * EGL_GL_COLORSPACE (scRGB linear in half floats, BT.2020 PQ in 10 bits with
 * SMPTE 2086 / CTA 861.3 metadata, Display P3), refused naming the extension
 * the display lacks. On Wayland every swap is at interval 0: a swap waiting
 * for vsync waits for a frame callback a hidden window never gets, and the
 * compositor shows whole frames at its refresh either way.
 */
final class WindowSurface
{
    private ?NSOpenGLContext $ns = null;

    private ?NSView $view = null;

    private ?EGLDisplay $display = null;

    private ?EGLContext $context = null;

    private ?EGLSurface $surface = null;

    private int $wlWindow = 0;

    private bool $wayland = false;

    /** @var list<string> The EGL display's extensions. */
    private array $extensions = [];

    private ?int $interval = null;

    /** @var array{int, int} */
    private array $size;

    /**
     * @param  array<string, int>  $handles  'view' (macOS), 'wl_display' + 'wl_surface' (Wayland), or 'x11_display' + 'x11_window' (X11).
     * @param  array{int, int}  $size  The surface's pixel size.
     *
     * @throws DrawingException When the platform shows no surface in $space.
     */
    public function __construct(array $handles, private readonly ColorSpace $space, array $size, ?Hdr $hdr)
    {
        $this->size = $size;
        isset($handles['view']) ? $this->makeCocoa($handles['view']) : $this->makeEgl($handles, $hdr);
    }

    public function cgl(): ?CGLContextObj
    {
        return is_null($this->ns) ? null : CGLContextObj::fromPointer($this->ns->CGLContextObj());
    }

    public function eglDisplay(): ?EGLDisplay
    {
        return $this->display;
    }

    public function eglContext(): ?EGLContext
    {
        return $this->context;
    }

    /** The context current with this window surface as its drawable. */
    public function makeCurrent(): void
    {
        if (! is_null($this->ns)) {
            $this->ns->makeCurrentContext();

            return;
        }
        if (! eglMakeCurrent($this->display, $this->surface, $this->surface, $this->context)) {
            throw new DrawingException(sprintf('opengl: the window surface could not be made current (0x%04x).', eglGetError()));
        }
    }

    /** The show pass's transform for this surface: extended linear for both HDR spaces on macOS (EDR), PQ for HDR10 on EGL. */
    public function transform(): int
    {
        return match (true) {
            in_array($this->space, [ColorSpace::ExtendedLinearSrgb, ColorSpace::ExtendedLinearDisplayP3], true) => 1,
            $this->space === ColorSpace::Hdr10Pq => is_null($this->ns) ? 2 : 1,
            default => 0,
        };
    }

    /** @param  array{int, int}  $size  Follow the surface's pixel size. */
    public function fit(array $size): void
    {
        if ($size === $this->size) {
            return;
        }
        $this->size = $size;
        if (! is_null($this->ns)) {
            $this->ns->update();
        } elseif ($this->wlWindow !== 0) {
            wl_egl_window_resize($this->wlWindow, max(1, $size[0]), max(1, $size[1]), 0, 0);
        }
    }

    /**
     * Swap at the vsync's interval (0 on Wayland), naming on EGL the rects
     * that changed, origin bottom-left. The context is current.
     *
     * @param  list<Region>  $changed
     */
    public function swap(VSync $vsync, array $changed): void
    {
        $interval = $this->wayland ? 0 : match ($vsync) {
            VSync::On, VSync::Adaptive => 1,
            VSync::Off, VSync::Mailbox => 0,
        };
        if (! is_null($this->ns)) {
            if ($interval !== $this->interval) {
                $this->ns->setValuesForParameter([$interval], NSOpenGLContextParameter::SWAP_INTERVAL);
                $this->interval = $interval;
            }
            $this->ns->flushBuffer();

            return;
        }
        if ($interval !== $this->interval) {
            eglSwapInterval($this->display, $interval);
            $this->interval = $interval;
        }
        if ($changed !== [] && in_array('EGL_KHR_swap_buffers_with_damage', $this->extensions, true)) {
            $rects = [];
            foreach ($changed as $rect) {
                array_push($rects, $rect->x, $rect->y, $rect->width, $rect->height);
            }
            if (eglSwapBuffersWithDamageKHR($this->display, $this->surface, $rects)) {
                return;
            }
        }
        eglSwapBuffers($this->display, $this->surface);
    }

    /** Everything made here, gone; the window and view as they were. */
    public function release(): void
    {
        if (! is_null($this->ns)) {
            NSOpenGLContext::clearCurrentContext();
            $this->ns->setView(null);
            $this->view?->setWantsExtendedDynamicRangeOpenGLSurface(false);
            $this->view?->window()?->setColorSpace(null);
            $this->ns = $this->view = null;

            return;
        }
        if (! is_null($this->display)) {
            if (eglGetCurrentContext()?->pointer() === $this->context?->pointer()) {
                eglMakeCurrent($this->display, null, null, null);
            }
            if (! is_null($this->surface)) {
                eglDestroySurface($this->display, $this->surface);
            }
            if (! is_null($this->context)) {
                eglDestroyContext($this->display, $this->context);
            }
            if ($this->wlWindow !== 0) {
                wl_egl_window_destroy($this->wlWindow);
            }
            // Not terminated: the display is the toolkit's too.
            $this->display = $this->context = $this->surface = null;
            $this->wlWindow = 0;
        }
    }

    private function makeCocoa(int $view): void
    {
        $float = $this->space->isHdr();
        $attribs = [NSOpenGLPFAOpenGLProfile, NSOpenGLProfileVersion4_1Core, NSOpenGLPFAAccelerated, NSOpenGLPFADoubleBuffer];
        array_push($attribs, ...($float ? [NSOpenGLPFAColorFloat, NSOpenGLPFAColorSize, 64, NSOpenGLPFAAlphaSize, 16] : [NSOpenGLPFAColorSize, 24, NSOpenGLPFAAlphaSize, 8]));
        $attribs[] = 0;
        $format = NSOpenGLPixelFormat::initWithAttributes($attribs)
            ?? throw new DrawingException('opengl: macOS offers no '.($float ? 'half-float' : '8-bit').' OpenGL 4.1 pixel format.');
        $this->ns = NSOpenGLContext::initWithFormatShareContext($format, null)
            ?? throw new DrawingException('opengl: macOS made no OpenGL context for the window.');
        $this->view = NSView::fromPointer($view);
        $this->view->setWantsBestResolutionOpenGLSurface(true);
        $this->view->setWantsExtendedDynamicRangeOpenGLSurface($float);
        $space = match ($this->space) {
            ColorSpace::Srgb => null,
            ColorSpace::DisplayP3 => NSColorSpace::displayP3ColorSpace(),
            default => NSColorSpace::initWithCGColorSpace(CGColorSpace::createWithName('kCGColorSpaceExtendedLinearSRGB')),
        };
        $this->view->window()?->setColorSpace($space);
        $this->ns->setView($this->view);
    }

    /** @param  array<string, int>  $handles */
    private function makeEgl(array $handles, ?Hdr $hdr): void
    {
        $this->wayland = isset($handles['wl_surface']);
        $display = eglGetPlatformDisplay(
            $this->wayland ? EGL_PLATFORM_WAYLAND_KHR : EGL_PLATFORM_X11_KHR,
            $this->wayland ? $handles['wl_display'] : $handles['x11_display'],
            null,
        ) ?? throw new DrawingException(sprintf('opengl: no EGL display for the window (0x%04x).', eglGetError()));
        if (! eglInitialize($display, $major, $minor)) {
            throw new DrawingException(sprintf('opengl: eglInitialize failed for the window (0x%04x).', eglGetError()));
        }
        eglBindAPI(EGL_OPENGL_ES_API);
        $this->display = $display;
        $this->extensions = explode(' ', (string) eglQueryString($display, EGL_EXTENSIONS));
        $needs = match ($this->space) {
            ColorSpace::Srgb => [],
            ColorSpace::DisplayP3 => ['EGL_KHR_gl_colorspace', 'EGL_EXT_gl_colorspace_display_p3'],
            ColorSpace::ExtendedLinearSrgb, ColorSpace::ExtendedLinearDisplayP3 => ['EGL_KHR_gl_colorspace', 'EGL_EXT_gl_colorspace_scrgb_linear', 'EGL_EXT_pixel_format_float'],
            ColorSpace::Hdr10Pq => ['EGL_KHR_gl_colorspace', 'EGL_EXT_gl_colorspace_bt2020_pq'],
        };
        $missing = array_values(array_diff($needs, $this->extensions));
        if ($missing !== []) {
            $listed = array_filter($this->extensions, fn (string $e): bool => str_contains($e, 'colorspace') || str_contains($e, 'pixel_format_float'));

            throw new DrawingException("opengl: this EGL display shows no {$this->space->value} surface: it lacks ".implode(', ', $missing).'. It lists: '.($listed === [] ? 'no colour-space extensions' : implode(', ', $listed)).'.');
        }

        $config = $this->config($display);
        $this->context = eglCreateContext($display, $config, null, [EGL_CONTEXT_MAJOR_VERSION, 3, EGL_CONTEXT_MINOR_VERSION, 1, EGL_NONE])
            ?? throw new DrawingException(sprintf('opengl: no OpenGL ES 3.1 context for the window (0x%04x).', eglGetError()));
        if ($this->wayland) {
            $this->wlWindow = wl_egl_window_create($handles['wl_surface'], max(1, $this->size[0]), max(1, $this->size[1]));
            if ($this->wlWindow === 0) {
                throw new DrawingException('opengl: libwayland-egl made no window on the surface.');
            }
        }
        $attribs = $this->space === ColorSpace::Srgb ? null : [EGL_GL_COLORSPACE_KHR, match ($this->space) {
            ColorSpace::DisplayP3 => EGL_GL_COLORSPACE_DISPLAY_P3_EXT,
            ColorSpace::Hdr10Pq => EGL_GL_COLORSPACE_BT2020_PQ_EXT,
            default => EGL_GL_COLORSPACE_SCRGB_LINEAR_EXT,
        }, EGL_NONE];
        $this->surface = eglCreateWindowSurface($display, $config, $this->wayland ? $this->wlWindow : $handles['x11_window'], $attribs)
            ?? throw new DrawingException(sprintf("opengl: EGL made no {$this->space->value} window surface (0x%04x).", eglGetError()));
        if ($this->space === ColorSpace::Hdr10Pq) {
            $this->hdr10Metadata($hdr);
        }
    }

    /** The window config with exactly the bits the colour space needs: 16-bit float, 10-bit, or 8-bit. */
    private function config(EGLDisplay $display): EGLConfig
    {
        $extended = in_array($this->space, [ColorSpace::ExtendedLinearSrgb, ColorSpace::ExtendedLinearDisplayP3], true);
        $bits = match (true) {
            $extended => 16,
            $this->space === ColorSpace::Hdr10Pq => 10,
            default => 8,
        };
        $attribs = [EGL_RENDERABLE_TYPE, EGL_OPENGL_ES3_BIT, EGL_SURFACE_TYPE, EGL_WINDOW_BIT, EGL_RED_SIZE, $bits, EGL_GREEN_SIZE, $bits, EGL_BLUE_SIZE, $bits];
        if ($extended) {
            array_push($attribs, EGL_COLOR_COMPONENT_TYPE_EXT, EGL_COLOR_COMPONENT_TYPE_FLOAT_EXT);
        }
        $attribs[] = EGL_NONE;
        eglChooseConfig($display, $attribs, $configs, 32, $count);
        // Configs come deeper colour first and take the sizes as minimums: the first with exactly $bits is the one.
        foreach ($configs ?? [] as $config) {
            eglGetConfigAttrib($display, $config, EGL_RED_SIZE, $red);
            if ($red === $bits) {
                return $config;
            }
        }

        throw new DrawingException("opengl: this EGL display has no {$bits}-bit window config for {$this->space->value}.");
    }

    /** HDR10 static metadata (Shared definitions) on the surface, where the display takes it. */
    private function hdr10Metadata(?Hdr $hdr): void
    {
        $level = $hdr?->sdrWhiteLevel ?? 1.0;
        $nits = max(203.0, 80.0 * ($level > 0.0 ? $level : 1.0));
        $peak = ($hdr?->headroom ?? 1.0) > 1.0 ? $nits * $hdr->headroom : 1000.0;
        $scale = EGL_METADATA_SCALING_EXT;
        if (in_array('EGL_EXT_surface_SMPTE2086_metadata', $this->extensions, true)) {
            foreach ([
                EGL_SMPTE2086_DISPLAY_PRIMARY_RX_EXT => 0.708, EGL_SMPTE2086_DISPLAY_PRIMARY_RY_EXT => 0.292,
                EGL_SMPTE2086_DISPLAY_PRIMARY_GX_EXT => 0.170, EGL_SMPTE2086_DISPLAY_PRIMARY_GY_EXT => 0.797,
                EGL_SMPTE2086_DISPLAY_PRIMARY_BX_EXT => 0.131, EGL_SMPTE2086_DISPLAY_PRIMARY_BY_EXT => 0.046,
                EGL_SMPTE2086_WHITE_POINT_X_EXT => 0.3127, EGL_SMPTE2086_WHITE_POINT_Y_EXT => 0.3290,
                EGL_SMPTE2086_MAX_LUMINANCE_EXT => $peak, EGL_SMPTE2086_MIN_LUMINANCE_EXT => 0.005,
            ] as $attribute => $value) {
                eglSurfaceAttrib($this->display, $this->surface, $attribute, (int) round($value * $scale));
            }
        }
        if (in_array('EGL_EXT_surface_CTA861_3_metadata', $this->extensions, true)) {
            eglSurfaceAttrib($this->display, $this->surface, EGL_CTA861_3_MAX_CONTENT_LIGHT_LEVEL_EXT, (int) round($peak * $scale));
            eglSurfaceAttrib($this->display, $this->surface, EGL_CTA861_3_MAX_FRAME_AVERAGE_LEVEL_EXT, (int) round($nits * $scale));
        }
    }
}
