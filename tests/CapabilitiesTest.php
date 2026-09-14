<?php

declare(strict_types=1);

use Jovian\Venusian\OpenGL\OpenGLExecutor;
use Venusian\Tests\Support\HeadlessGLSurface;

it('declares GL capabilities honestly: blending on, depth off, instancing and readback on', function () {
    $capabilities = OpenGLExecutor::declaredCapabilities();

    expect($capabilities->blending)->toBeTrue()
        ->and($capabilities->depth)->toBeFalse()
        ->and($capabilities->instancing)->toBeTrue()
        ->and($capabilities->readback)->toBeTrue()
        ->and($capabilities->max_texture_size)->toBe(2048);
});

it('answers the GLES 3 floor for max texture size before the first frame', function () {
    $executor = new OpenGLExecutor(new HeadlessGLSurface(8, 8), 8, 8);

    expect($executor->capabilities()->max_texture_size)->toBe(2048)
        ->and($executor->program())->toBe(0)
        ->and($executor->vao())->toBe(0)
        ->and($executor->dialect())->toBeNull()
        ->and($executor->contextVersion())->toBeNull();
});

it('readPixels outside a frame throws before any GL call', function () {
    $executor = new OpenGLExecutor(new HeadlessGLSurface(8, 8), 8, 8);

    expect(fn () => $executor->readPixels())->toThrow(\Jovian\Venusian\OpenGL\Exceptions\OpenGLDrawingException::class);
});
