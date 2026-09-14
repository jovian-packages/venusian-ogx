<?php

declare(strict_types=1);

use Jovian\Bindings\OpenGL\Enums\GL\FramebufferAttachment;
use Jovian\Bindings\OpenGL\Enums\GL\FramebufferStatus;
use Jovian\Bindings\OpenGL\Enums\GL\FramebufferTarget;
use Jovian\Bindings\OpenGL\Enums\GL\InternalFormat;
use Jovian\Bindings\OpenGL\Enums\GL\RenderbufferTarget;
use Jovian\Bindings\OpenGL\GL\GL30;
use Jovian\Venusian\OpenGL\Enums\GLSLDialect;
use Jovian\Venusian\OpenGL\OpenGLEngine;
use Jovian\Venusian\OpenGL\OpenGLExecutor;
use Surface\Contracts\Drawing\GPUHost;
use Surface\Contracts\Drawing\Topology;
use Surface\Contracts\Drawing\Transform;
use Surface\Contracts\NativeWindows\Views\Color;
use Venusian\Tests\Support\HeadlessGLSurface;

/** @return array{fbo: int, rbo: int} */
function oglOffscreenTarget(int $size): array
{
    $out = oglBuffer(4);
    GL30::glGenFramebuffers(1, $out);
    $fbo = oglReadInt($out);
    GL30::glBindFramebuffer(FramebufferTarget::FRAMEBUFFER, $fbo);
    GL30::glGenRenderbuffers(1, $out);
    $rbo = oglReadInt($out);
    GL30::glBindRenderbuffer(RenderbufferTarget::RENDERBUFFER, $rbo);
    GL30::glRenderbufferStorage(RenderbufferTarget::RENDERBUFFER, InternalFormat::RGBA8, $size, $size);
    GL30::glFramebufferRenderbuffer(FramebufferTarget::FRAMEBUFFER, FramebufferAttachment::COLOR_ATTACHMENT0, RenderbufferTarget::RENDERBUFFER, $rbo);
    expect(GL30::glCheckFramebufferStatus(FramebufferTarget::FRAMEBUFFER))->toBe(FramebufferStatus::FRAMEBUFFER_COMPLETE->value);

    return ['fbo' => $fbo, 'rbo' => $rbo];
}

/** One Surface vertex: x y z r g b a u v. */
function oglVertex(float $x, float $y, float $r, float $g, float $b, float $a = 1.0): string
{
    return pack('g9', $x, $y, 0.0, $r, $g, $b, $a, 0.0, 0.0);
}

it('initialises at the first frame, draws a solid triangle, and reads back the ogx proof colour', function () {
    oglContext();
    $size = 64;
    $target = oglOffscreenTarget($size);

    $gl = new HeadlessGLSurface($size, $size);
    /** @var OpenGLExecutor $executor */
    $executor = (new OpenGLEngine)->attach(new GPUHost(0, $size, $size, 1.0, $gl))->executor;

    expect($executor->beginFrame(new Color(0.0, 0.0, 0.0, 1.0)))->toBeTrue()
        ->and($gl->made_current)->toBe(1)
        ->and($executor->program())->toBeGreaterThan(0)
        ->and($executor->vao())->toBeGreaterThan(0)
        ->and($executor->framebuffer())->toBe($target['fbo'])
        ->and($executor->dialect())->toBe(PHP_OS_FAMILY === 'Darwin' ? GLSLDialect::CORE_150 : GLSLDialect::CORE_150)
        ->and($executor->contextVersion()?->atLeast(3, 1))->toBeTrue()
        ->and($executor->capabilities()->max_texture_size)->toBeGreaterThanOrEqual(2048);

    // Same triangle as the ogx proof: (0, .8) (-.8, -.8) (.8, -.8) in pixels of a 64x64 top-left target.
    $half = $size / 2.0;
    $vertices = oglVertex($half, $half - 0.8 * $half, 1.0, 0.5, 0.25)
        .oglVertex($half - 0.8 * $half, $half + 0.8 * $half, 1.0, 0.5, 0.25)
        .oglVertex($half + 0.8 * $half, $half + 0.8 * $half, 1.0, 0.5, 0.25);
    $executor->draw(Topology::TRIANGLES, $vertices, 3, Transform::orthographic($size, $size));

    $pixels = $executor->readPixels();
    expect(strlen($pixels))->toBe($size * $size * 4);
    $centre = array_values(unpack('C4', substr($pixels, (intdiv($size, 2) * $size + intdiv($size, 2)) * 4, 4)));
    $corner = array_values(unpack('C4', substr($pixels, 0, 4)));

    // The shader writes (1.0, 0.5, 0.25, 1.0) → ~255,128,64,255; the clear is opaque black.
    expect($centre[0])->toBeGreaterThanOrEqual(240)
        ->and($centre[1])->toBeBetween(100, 155)
        ->and($centre[2])->toBeBetween(48, 80)
        ->and($centre[3])->toBe(255)
        ->and($corner)->toBe([0, 0, 0, 255]);

    $executor->endFrame();
    expect($gl->presented)->toBe(1);

    $executor->release();
    expect(fn () => $executor->beginFrame(new Color(0.0, 0.0, 0.0, 1.0)))
        ->toThrow(\Jovian\Venusian\OpenGL\Exceptions\OpenGLDrawingException::class);
});

it('blends: a half-alpha white quad over black reads back mid grey', function () {
    oglContext();
    $size = 16;
    oglOffscreenTarget($size);
    $executor = (new OpenGLEngine)->attach(new GPUHost(0, $size, $size, 1.0, new HeadlessGLSurface($size, $size)))->executor;
    $executor->beginFrame(new Color(0.0, 0.0, 0.0, 1.0));

    $quad = oglVertex(0, 0, 1, 1, 1, 0.5).oglVertex(16, 0, 1, 1, 1, 0.5).oglVertex(0, 16, 1, 1, 1, 0.5).oglVertex(16, 16, 1, 1, 1, 0.5);
    $executor->drawIndexed(Topology::TRIANGLES, $quad, 4, pack('v*', 0, 1, 2, 2, 1, 3), 6, Transform::orthographic($size, $size));

    $centre = array_values(unpack('C4', substr($executor->readPixels(), (8 * $size + 8) * 4, 4)));
    expect($centre[0])->toBeBetween(120, 136)->and($centre[1])->toBeBetween(120, 136);

    $executor->endFrame();
    $executor->release();
});

it('a scissor in top-left pixels clips the bottom half, not the top', function () {
    oglContext();
    $size = 16;
    oglOffscreenTarget($size);
    $executor = (new OpenGLEngine)->attach(new GPUHost(0, $size, $size, 1.0, new HeadlessGLSurface($size, $size)))->executor;
    $executor->beginFrame(new Color(0.0, 0.0, 0.0, 1.0));

    $executor->scissor(0, 8, 16, 8); // bottom half in Surface's top-left space
    $quad = oglVertex(0, 0, 1, 0, 0).oglVertex(16, 0, 1, 0, 0).oglVertex(0, 16, 1, 0, 0).oglVertex(16, 16, 1, 0, 0);
    $executor->drawIndexed(Topology::TRIANGLES, $quad, 4, pack('v*', 0, 1, 2, 2, 1, 3), 6, Transform::orthographic($size, $size));

    $pixels = $executor->readPixels();
    $top = array_values(unpack('C4', substr($pixels, (2 * $size + 8) * 4, 4)));     // row 2 from the top
    $bottom = array_values(unpack('C4', substr($pixels, (13 * $size + 8) * 4, 4))); // row 13 from the top
    expect($top)->toBe([0, 0, 0, 255])->and($bottom[0])->toBe(255);

    $executor->endFrame();
    $executor->release();
});

it('uploads an RGBA8 texture and samples it', function () {
    oglContext();
    $size = 8;
    oglOffscreenTarget($size);
    $executor = (new OpenGLEngine)->attach(new GPUHost(0, $size, $size, 1.0, new HeadlessGLSurface($size, $size)))->executor;
    $executor->beginFrame(new Color(0.0, 0.0, 0.0, 1.0));

    $texture = $executor->texture(str_repeat(pack('C4', 0, 255, 0, 255), 4), 2, 2);
    $v = fn (float $x, float $y, float $u, float $t) => pack('g9', $x, $y, 0.0, 1.0, 1.0, 1.0, 1.0, $u, $t);
    $quad = $v(0, 0, 0, 0).$v(8, 0, 1, 0).$v(0, 8, 0, 1).$v(8, 8, 1, 1);
    $executor->drawIndexed(Topology::TRIANGLES, $quad, 4, pack('v*', 0, 1, 2, 2, 1, 3), 6, Transform::orthographic($size, $size), $texture);

    $centre = array_values(unpack('C4', substr($executor->readPixels(), (4 * $size + 4) * 4, 4)));
    expect($centre)->toBe([0, 255, 0, 255]);

    $executor->releaseTexture($texture);
    $executor->endFrame();
    $executor->release();
});
