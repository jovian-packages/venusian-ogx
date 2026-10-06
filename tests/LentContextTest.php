<?php

declare(strict_types=1);

use Jovian\Engines\OpenGL\OpenGLDevice;
use Surface\Contracts\Drawing\DrawingException;
use Surface\Contracts\Drawing\LentSurface;
use Surface\Contracts\Drawing\RenderingEngine;
use Surface\Contracts\Drawing\SurfaceKind;
use Surface\Drawing\Gpu\GpuRenderingEngine;
use Surface\NutsAndBolts\Color;

/*
 * A canvas lends its GL context: the device adopts it instead of making its
 * own, and the canvas copies the target into its view with blitTo() inside
 * its render callback. Here a second offscreen context stands in for the view's.
 */

/** A context like a canvas would lend, made current; its handles as a LentSurface would carry them. */
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

it('has no dialect or target before a context is adopted', function (): void {
    $device = OpenGLDevice::adopting();

    expect(fn () => $device->dialect())->toThrow(DrawingException::class, 'opengl: no context yet: adopt() a surface first.')
        ->and(fn () => $device->target(8, 8, 1))->toThrow(DrawingException::class, 'opengl: no context yet: adopt() a surface first.');
});

it('adopts a lent context and draws in it', function (): void {
    $surface = lentContext();
    $device = OpenGLDevice::adopting();
    $device->adopt($surface);
    $engine = new GpuRenderingEngine($device, 32, 16);

    $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 128, 255))->fillRect(4, 4, 8, 8, Color::rgb(255, 0, 0)));

    expect($device->dialect())->toBe(OPENGL_MAC ? 'core' : 'es')
        ->and(pixelOf($engine->framebuffer()->toRgba8(), 32, 1, 1))->toBe('0080ffff')
        ->and(pixelOf($engine->framebuffer()->toRgba8(), 32, 6, 6))->toBe('ff0000ff');
});

it('copies into the framebuffer bound for drawing at the surface\'s size, and leaves it bound', function (): void {
    $surface = lentContext(32, 16);
    $device = OpenGLDevice::adopting();
    $device->adopt($surface);
    $engine = new GpuRenderingEngine($device, 16, 8);
    $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 0, 0))->fillRect(0, 0, 16, 2, Color::rgb(255, 255, 255)));

    // A stand-in for the view's framebuffer, bound as a view would bind it before its callback.
    [$view] = glGenTextures(1);
    glBindTexture(GL_TEXTURE_2D, $view);
    glTexImage2D(GL_TEXTURE_2D, 0, GL_RGBA8, 32, 16, 0, GL_RGBA, GL_UNSIGNED_BYTE, null);
    [$fbo] = glGenFramebuffers(1);
    glBindFramebuffer(GL_DRAW_FRAMEBUFFER, $fbo);
    glFramebufferTexture2D(GL_DRAW_FRAMEBUFFER, GL_COLOR_ATTACHMENT0, GL_TEXTURE_2D, $view, 0);

    $copied = $device->present($surface);

    glGetIntegerv(GL_DRAW_FRAMEBUFFER_BINDING, $bound);
    glBindFramebuffer(GL_READ_FRAMEBUFFER, $fbo);
    $pixels = glReadPixels(0, 0, 32, 16, GL_RGBA, GL_UNSIGNED_BYTE, null);
    expect($copied)->toBeTrue()
        ->and($bound)->toBe([$fbo])
        ->and(pixelOf($pixels, 32, 5, 15))->toBe('ffffffff')
        ->and(pixelOf($pixels, 32, 5, 12))->toBe('ffffffff')
        ->and(pixelOf($pixels, 32, 5, 11))->toBe('000000ff')
        ->and(glGetError())->toBe(GL_NO_ERROR);
    glBindFramebuffer(GL_FRAMEBUFFER, 0);
    glDeleteFramebuffers([$fbo]);
    glDeleteTextures([$view]);
});

it('leaves the view\'s framebuffer bound and no pack buffer after present()', function (): void {
    $surface = lentContext(8, 8);
    $device = OpenGLDevice::adopting();
    $device->adopt($surface);
    $engine = new GpuRenderingEngine($device, 8, 8);
    $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 0, 0)));
    $engine->framebuffer()->toRgba8();                        // a readback: the pack path ran
    [$view] = glGenTextures(1);
    glBindTexture(GL_TEXTURE_2D, $view);
    glTexImage2D(GL_TEXTURE_2D, 0, GL_RGBA8, 8, 8, 0, GL_RGBA, GL_UNSIGNED_BYTE, null);
    [$fbo] = glGenFramebuffers(1);
    glBindFramebuffer(GL_DRAW_FRAMEBUFFER, $fbo);
    glFramebufferTexture2D(GL_DRAW_FRAMEBUFFER, GL_COLOR_ATTACHMENT0, GL_TEXTURE_2D, $view, 0);

    $device->present($surface);

    glGetIntegerv(GL_DRAW_FRAMEBUFFER_BINDING, $draw);
    glGetIntegerv(GL_PIXEL_PACK_BUFFER_BINDING, $pack);
    expect($draw)->toBe([$fbo])
        ->and($pack)->toBe([0])
        ->and(glGetError())->toBe(GL_NO_ERROR);
    glBindFramebuffer(GL_FRAMEBUFFER, 0);
    glDeleteFramebuffers([$fbo]);
    glDeleteTextures([$view]);
});

it('presents nothing from a device with its own context', function (): void {
    $engine = openglEngine(8, 8);
    $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 0, 0)));
    $surface = new LentSurface(SurfaceKind::GL_CONTEXT, ['context' => 1], fn (): array => [8, 8]);

    expect($engine->device()->present($surface))->toBeFalse();
});

it('blits the target into a framebuffer the canvas names, at the canvas\'s size, rows the right way up', function (): void {
    $surface = lentContext();
    $device = OpenGLDevice::adopting();
    $device->adopt($surface);
    $engine = new GpuRenderingEngine($device, 16, 8);
    $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 0, 0))->fillRect(0, 0, 16, 2, Color::rgb(255, 255, 255)));

    // A stand-in for the view's framebuffer: a texture twice the size.
    [$view] = glGenTextures(1);
    glBindTexture(GL_TEXTURE_2D, $view);
    glTexImage2D(GL_TEXTURE_2D, 0, GL_RGBA8, 32, 16, 0, GL_RGBA, GL_UNSIGNED_BYTE, null);
    [$fbo] = glGenFramebuffers(1);
    glBindFramebuffer(GL_FRAMEBUFFER, $fbo);
    glFramebufferTexture2D(GL_FRAMEBUFFER, GL_COLOR_ATTACHMENT0, GL_TEXTURE_2D, $view, 0);

    $device->blitTo($fbo, 32, 16);

    glBindFramebuffer(GL_READ_FRAMEBUFFER, $fbo);
    $pixels = glReadPixels(0, 0, 32, 16, GL_RGBA, GL_UNSIGNED_BYTE, null);
    // GL row 15 is the top: the white band (surface rows 0-1) covers GL rows 12-15 at 2x.
    expect(pixelOf($pixels, 32, 5, 15))->toBe('ffffffff')
        ->and(pixelOf($pixels, 32, 5, 12))->toBe('ffffffff')
        ->and(pixelOf($pixels, 32, 5, 11))->toBe('000000ff')
        ->and(pixelOf($pixels, 32, 5, 0))->toBe('000000ff')
        ->and(glGetError())->toBe(GL_NO_ERROR);
    glBindFramebuffer(GL_FRAMEBUFFER, 0);
    glDeleteFramebuffers([$fbo]);
    glDeleteTextures([$view]);
});

it('lets go of everything on release(), and leaves a lent context alive', function (): void {
    $surface = lentContext();
    $device = OpenGLDevice::adopting();
    $device->adopt($surface);
    $engine = new GpuRenderingEngine($device, 8, 8);
    $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 0, 0)));

    $engine->release();

    $current = OPENGL_MAC ? CGLGetCurrentContext() : eglGetCurrentContext();
    expect($current)->not->toBeNull()
        ->and($current->pointer())->toBe($surface->handle('context'))
        ->and(fn () => $device->target(8, 8, 1))->toThrow(DrawingException::class, 'opengl: this device was released.');
});

it('releases an owned context with the device', function (): void {
    $engine = openglEngine(8, 8);
    $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 0, 0)));

    $engine->release();

    expect(fn () => $engine->device()->target(8, 8, 1))->toThrow(DrawingException::class, 'opengl: this device was released.');
});

it('draws in an adopted context the canvas does not have current, and puts back the one that was', function (): void {
    $surface = lentContext();
    $device = OpenGLDevice::adopting();
    $device->adopt($surface);
    $engine = new GpuRenderingEngine($device, 16, 8);
    $other = new OpenGLDevice;
    $other->current();
    $before = OPENGL_MAC ? CGLGetCurrentContext()?->pointer() : eglGetCurrentContext()?->pointer();

    $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 128, 255)));

    $after = OPENGL_MAC ? CGLGetCurrentContext()?->pointer() : eglGetCurrentContext()?->pointer();
    expect(pixelOf($engine->framebuffer()->toRgba8(), 16, 3, 3))->toBe('0080ffff')
        ->and($after)->toBe($before)
        ->and($after)->not->toBe($surface->handle('context'));
});

it('leaves the caller\'s context current when it destroys its own', function (): void {
    $engine = openglEngine(8, 8);
    $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 0, 0)));
    $other = new OpenGLDevice;
    $other->current();
    $before = OPENGL_MAC ? CGLGetCurrentContext()?->pointer() : eglGetCurrentContext()?->pointer();

    $engine->release();

    expect(OPENGL_MAC ? CGLGetCurrentContext()?->pointer() : eglGetCurrentContext()?->pointer())->toBe($before)
        ->and($before)->not->toBeNull();
});

it('makes no GL call once the canvas has taken its context back', function (): void {
    $surface = lentContext();
    $device = OpenGLDevice::adopting();
    $device->adopt($surface);
    $engine = new GpuRenderingEngine($device, 16, 8);
    $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 0, 0)));
    $framebuffer = $engine->framebuffer();
    unset($engine);

    // The canvas reclaims the surface and destroys its view's context.
    $surface->release();
    if (OPENGL_MAC) {
        CGLReleaseContext(CGLContextObj::fromPointer($surface->handle('context')));
    } else {
        eglDestroyContext(EGLDisplay::fromPointer($surface->handle('display')), EGLContext::fromPointer($surface->handle('context')));
    }
    unset($framebuffer);
    $device->release();

    expect(fn () => $device->target(8, 8, 1))->toThrow(DrawingException::class, 'opengl: this device was released.');
});

it('blits inside the canvas context and puts back the one that was current', function (): void {
    $surface = lentContext();
    $device = OpenGLDevice::adopting();
    $device->adopt($surface);
    $engine = new GpuRenderingEngine($device, 16, 8);
    $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 128, 255)));
    // The view's framebuffer, made in the canvas's context while it is current.
    [$view] = glGenTextures(1);
    glBindTexture(GL_TEXTURE_2D, $view);
    glTexImage2D(GL_TEXTURE_2D, 0, GL_RGBA8, 16, 8, 0, GL_RGBA, GL_UNSIGNED_BYTE, null);
    [$fbo] = glGenFramebuffers(1);
    glBindFramebuffer(GL_FRAMEBUFFER, $fbo);
    glFramebufferTexture2D(GL_FRAMEBUFFER, GL_COLOR_ATTACHMENT0, GL_TEXTURE_2D, $view, 0);
    $other = new OpenGLDevice;
    $other->current();
    $before = OPENGL_MAC ? CGLGetCurrentContext()?->pointer() : eglGetCurrentContext()?->pointer();

    $device->blitTo($fbo, 16, 8);

    expect(OPENGL_MAC ? CGLGetCurrentContext()?->pointer() : eglGetCurrentContext()?->pointer())->toBe($before);
    $device->current();
    glBindFramebuffer(GL_READ_FRAMEBUFFER, $fbo);
    expect(bin2hex(glReadPixels(0, 0, 1, 1, GL_RGBA, GL_UNSIGNED_BYTE, null)))->toBe('0080ffff');
    glBindFramebuffer(GL_FRAMEBUFFER, 0);
    glDeleteFramebuffers([$fbo]);
    glDeleteTextures([$view]);
});

it('blits only from a context a canvas lent', function (): void {
    $engine = openglEngine(8, 8);
    $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 0, 0)));

    $engine->device()->blitTo(0, 8, 8);
})->throws(DrawingException::class, 'opengl: blitTo() copies into a canvas\'s framebuffer, in the context the canvas lent; this device has its own.');

it('frees its objects in the lent context when the canvas releases the surface', function (): void {
    $surface = lentContext();
    $device = OpenGLDevice::adopting();
    $device->adopt($surface);
    $engine = new GpuRenderingEngine($device, 16, 8);
    $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 0, 0)));
    $program = $device->program();
    $framebuffer = $engine->framebuffer();

    // The canvas releases the surface before it takes the context away.
    $surface->release();

    glGetError();
    glUseProgram($program);
    expect(glGetError())->toBe(0x0501) // GL_INVALID_VALUE: no such program
        ->and(fn () => $device->target(8, 8, 1))->toThrow(DrawingException::class, 'opengl: this device was released.')
        ->and(fn () => $framebuffer->toRgba8())->toThrow(DrawingException::class, 'opengl: this device was released.');
});
