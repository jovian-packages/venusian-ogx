<?php

declare(strict_types=1);

/*
| Pest bootstrap for jovian/venusian-ogx.
|
| Extension-dependent suites skip when ext-opengl is absent. A skipped test
| is not evidence the GL path works — run those on the Mac (CGL) or the Pi
| (EGL surfaceless) with the extension loaded.
*/

use Jovian\Bindings\OpenGL\CGL\CGL;
use Jovian\Bindings\OpenGL\EGL\EGL;
use Jovian\Bindings\OpenGL\Enums\CGL\CGLError;
use Jovian\Bindings\OpenGL\Enums\CGL\CGLOpenGLProfile;
use Jovian\Bindings\OpenGL\Enums\CGL\CGLPixelFormatAttribute;
use Jovian\Bindings\OpenGL\Enums\EGL\EGLVersion10;
use Jovian\Bindings\OpenGL\Enums\EGL\EGLVersion12;
use Jovian\Bindings\OpenGL\Enums\EGL\EGLVersion14;
use Jovian\Bindings\OpenGL\Enums\EGL\EGLVersion15;
use Jovian\Bindings\OpenGL\Runtime\Bridge;

function oglExtensionLoaded(): bool
{
    return extension_loaded('opengl');
}

/** Mesa's surfaceless platform (EGL_PLATFORM_SURFACELESS_MESA): not EGL core, so in no enum. */
function oglSurfacelessPlatform(): int
{
    return 0x31DD;
}

function oglRequireExtension(): void
{
    if (! oglExtensionLoaded()) {
        test()->skip('ext-opengl is not loaded');
    }
}

/**
 * A current, headless, core-profile GL context — created once per process.
 * CGL on Darwin, EGL surfaceless on Linux. The only platform branch in the suite.
 *
 * @return array{api: string, handle: int}
 */
function oglContext(): array
{
    static $context = null;
    static $failed = null;

    oglRequireExtension();

    if (! is_null($failed)) {
        test()->skip($failed);
    }
    if (! is_null($context)) {
        return $context;
    }
    if (! Bridge::load()) {
        $failed = 'Bridge::load() could not open an OpenGL library';
        test()->skip($failed);
    }

    $context = PHP_OS_FAMILY === 'Darwin' ? oglCglContext() : oglEglContext();
    if (is_null($context)) {
        $failed = 'no headless core-profile context on this box';
        test()->skip($failed);
    }

    Bridge::load();

    return $context;
}

/** @return array{api: string, handle: int}|null */
function oglCglContext(): ?array
{
    foreach ([CGLOpenGLProfile::CGLOGLP_VERSION_GL4_CORE, CGLOpenGLProfile::CGLOGLP_VERSION_3_2_CORE] as $profile) {
        $attribs = oglIntBuffer([
            CGLPixelFormatAttribute::CGLPFA_OPEN_GL_PROFILE->value, $profile->value,
            CGLPixelFormatAttribute::CGLPFA_ACCELERATED->value,
            0,
        ]);
        $pixOut = oglBuffer(8);
        $nOut = oglBuffer(4);
        if (CGL::CGLChoosePixelFormat($attribs, $pixOut, $nOut) !== CGLError::CGL_NO_ERROR->value) {
            continue;
        }
        $pix = oglReadPtr($pixOut);
        if ($pix === 0) {
            continue;
        }
        $ctxOut = oglBuffer(8);
        if (CGL::CGLCreateContext($pix, 0, $ctxOut) !== CGLError::CGL_NO_ERROR->value) {
            continue;
        }
        $ctx = oglReadPtr($ctxOut);
        if ($ctx === 0 || CGL::CGLSetCurrentContext($ctx) !== CGLError::CGL_NO_ERROR->value) {
            continue;
        }

        return ['api' => 'CGL', 'handle' => $ctx];
    }

    return null;
}

/** @return array{api: string, handle: int}|null */
function oglEglContext(): ?array
{
    $none = EGLVersion10::FALSE->value;
    $dpy = EGL::eglGetPlatformDisplay(oglSurfacelessPlatform(), EGLVersion14::DEFAULT_DISPLAY->value, 0);
    if ($dpy === 0) {
        return null;
    }
    $majOut = oglBuffer(4);
    $minOut = oglBuffer(4);
    if (! EGL::eglInitialize($dpy, $majOut, $minOut) || ! EGL::eglBindAPI(EGLVersion14::OPENGL_API->value)) {
        return null;
    }
    $configAttribs = oglIntBuffer([
        EGLVersion10::SURFACE_TYPE->value, EGLVersion10::PBUFFER_BIT->value,
        EGLVersion12::RENDERABLE_TYPE->value, EGLVersion14::OPENGL_BIT->value,
        EGLVersion10::NONE->value,
    ]);
    $configOut = oglBuffer(8);
    $nOut = oglBuffer(4);
    if (! EGL::eglChooseConfig($dpy, $configAttribs, $configOut, 1, $nOut) || oglReadInt($nOut) < 1) {
        return null;
    }
    $contextAttribs = oglIntBuffer([
        EGLVersion15::CONTEXT_MAJOR_VERSION->value, 3,
        EGLVersion15::CONTEXT_MINOR_VERSION->value, 1,
        EGLVersion15::CONTEXT_OPENGL_PROFILE_MASK->value, EGLVersion15::CONTEXT_OPENGL_CORE_PROFILE_BIT->value,
        EGLVersion10::NONE->value,
    ]);
    $ctx = EGL::eglCreateContext($dpy, oglReadPtr($configOut), $none, $contextAttribs);
    if ($ctx === 0 || ! EGL::eglMakeCurrent($dpy, $none, $none, $ctx)) {
        return null;
    }

    return ['api' => 'EGL', 'handle' => $ctx];
}

function oglBuffer(int $bytes): int
{
    $ptr = Bridge::alloc($bytes);
    expect($ptr)->not->toBe(0);

    return $ptr;
}

/** @param list<int> $values */
function oglIntBuffer(array $values): int
{
    $bytes = '';
    foreach ($values as $value) {
        $bytes .= pack('l', $value);
    }
    $ptr = oglBuffer(max(4, strlen($bytes)));
    Bridge::write($ptr, 0, $bytes);

    return $ptr;
}

function oglReadInt(int $ptr, int $offset = 0): int
{
    return unpack('l', (string) Bridge::read($ptr, $offset, 4))[1];
}

function oglReadPtr(int $ptr, int $offset = 0): int
{
    return unpack('P', (string) Bridge::read($ptr, $offset, 8))[1];
}
