<?php

declare(strict_types=1);

/**
 * Embedded GLSL for the Surface painter, one template for both dialects.
 * Only the version line and the ES precision line differ; GLSL 1.50 and
 * ES 3.00 both spell attributes/varyings `in`/`out`. Transform is projection
 * only and GL clip space is y-up like Metal's, so no flip here.
 *
 * @return array<string, array{vertex: string, fragment: string}> keyed by GLSLDialect value
 */
$pair = static function (string $version, string $precision): array {
    $head = $version."\n".($precision === '' ? '' : $precision."\n");

    return [
        'vertex' => $head.<<<'GLSL'
in vec3 aPosition;
in vec4 aColor;
in vec2 aUV;
uniform mat4 uTransform;
out vec4 vColor;
out vec2 vUV;
void main()
{
    gl_Position = uTransform * vec4(aPosition, 1.0);
    vColor = aColor;
    vUV = aUV;
}
GLSL,
        'fragment' => $head.<<<'GLSL'
in vec4 vColor;
in vec2 vUV;
uniform int uTextured;
uniform sampler2D uTexture;
out vec4 fragColor;
void main()
{
    fragColor = vColor * (uTextured != 0 ? texture(uTexture, vUV) : vec4(1.0));
}
GLSL,
    ];
};

return [
    'es300' => $pair('#version 300 es', 'precision mediump float;'),
    'core150' => $pair('#version 150 core', ''),
];
