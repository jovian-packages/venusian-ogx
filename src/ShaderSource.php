<?php

declare(strict_types=1);

namespace Jovian\Venusian\OpenGL;

use Jovian\Venusian\OpenGL\Enums\GLSLDialect;

/** Loads the embedded GLSL pair for a dialect. */
final class ShaderSource
{
    /** @return array{vertex: string, fragment: string} */
    public static function for(GLSLDialect $dialect): array
    {
        /** @var array<string, array{vertex: string, fragment: string}> $all */
        $all = require __DIR__.'/Shaders/painter.glsl.php';

        return $all[$dialect->value];
    }
}
