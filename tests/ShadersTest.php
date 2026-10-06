<?php

declare(strict_types=1);

use Jovian\Engines\OpenGL\OpenGLDevice;

it('ships both shader bodies without a version line', function (): void {
    $vertex = file_get_contents(OpenGLDevice::VERTEX_SHADER);
    $fragment = file_get_contents(OpenGLDevice::FRAGMENT_SHADER);

    expect($vertex)->not->toContain('#version')
        ->and($fragment)->not->toContain('#version')
        ->and($fragment)->not->toContain('layout(set')
        ->and($vertex)->toContain('uniform vec2 size')
        ->and($fragment)->toContain('uniform vec4 mode_opacity')
        ->and($fragment)->toContain('uniform sampler2D source');
});

it('names the two dialect headers', function (): void {
    expect(OpenGLDevice::HEADERS)->toBe([
        'core' => "#version 150 core\n",
        'es' => "#version 300 es\nprecision highp float;\nprecision highp int;\nprecision highp sampler2D;\n",
    ]);
});
