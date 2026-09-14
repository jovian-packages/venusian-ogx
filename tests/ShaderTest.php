<?php

declare(strict_types=1);

use Jovian\Venusian\OpenGL\Enums\GLSLDialect;
use Jovian\Venusian\OpenGL\Enums\PainterAttribute;
use Jovian\Venusian\OpenGL\ShaderSource;

it('starts every shader with the dialect version line', function (GLSLDialect $dialect) {
    $pair = ShaderSource::for($dialect);

    expect(strtok($pair['vertex'], "\n"))->toBe($dialect->versionLine())
        ->and(strtok($pair['fragment'], "\n"))->toBe($dialect->versionLine());
})->with([GLSLDialect::ES_300, GLSLDialect::CORE_150]);

it('declares the three attributes and three uniforms by their contract names', function (GLSLDialect $dialect) {
    $pair = ShaderSource::for($dialect);

    foreach (PainterAttribute::cases() as $attribute) {
        expect($pair['vertex'])->toContain('in vec'.$attribute->components().' '.$attribute->glslName().';');
    }
    expect($pair['vertex'])->toContain('uniform mat4 uTransform;')
        ->and($pair['fragment'])->toContain('uniform int uTextured;')
        ->and($pair['fragment'])->toContain('uniform sampler2D uTexture;')
        ->and($pair['fragment'])->toContain('vColor * (uTextured != 0 ? texture(uTexture, vUV) : vec4(1.0))');
})->with([GLSLDialect::ES_300, GLSLDialect::CORE_150]);

it('adds the precision line only on ES', function () {
    expect(ShaderSource::for(GLSLDialect::ES_300)['fragment'])->toContain('precision mediump float;')
        ->and(ShaderSource::for(GLSLDialect::CORE_150)['fragment'])->not->toContain('precision');
});

it('lays vertices out at stride 36 with the Surface offsets', function () {
    expect(PainterAttribute::stride())->toBe(36)
        ->and(PainterAttribute::POSITION->offset())->toBe(0)
        ->and(PainterAttribute::COLOR->offset())->toBe(12)
        ->and(PainterAttribute::UV->offset())->toBe(28);
});
