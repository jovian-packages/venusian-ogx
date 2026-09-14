<?php

declare(strict_types=1);

use Jovian\Venusian\OpenGL\Exceptions\OpenGLDrawingException;
use Jovian\Venusian\OpenGL\OpenGLEngine;
use Jovian\Venusian\OpenGL\OpenGLExecutor;
use Surface\Contracts\Drawing\GPUEngine;
use Surface\Contracts\Drawing\GPUHost;
use Surface\Contracts\Drawing\SurfaceKind;
use Venusian\Tests\Support\HeadlessGLSurface;

it('names the opengl engine and asks for a GL context', function () {
    $engine = new OpenGLEngine;

    expect($engine->engine())->toBe(GPUEngine::OPENGL)
        ->and($engine->surfaceKind())->toBe(SurfaceKind::GL_CONTEXT);
});

it('refuses a host that carries no GL surface', function () {
    expect(fn () => (new OpenGLEngine)->attach(new GPUHost(0, 64, 64, 1.0)))
        ->toThrow(OpenGLDrawingException::class);
});

it('attaches without touching GL: no layer, pixel size = points x scale, surface held', function () {
    $gl = new HeadlessGLSurface(640, 480);
    $attachment = (new OpenGLEngine)->attach(new GPUHost(0, 320, 240, 2.0, $gl));

    expect($attachment->layer_pointer)->toBe(0)
        ->and($attachment->layer_class)->toBe('')
        ->and($attachment->executor)->toBeInstanceOf(OpenGLExecutor::class)
        ->and($attachment->executor->drawableSize())->toBe([640, 480])
        ->and($attachment->executor->surface())->toBe($gl)
        ->and($gl->made_current)->toBe(0);
});
