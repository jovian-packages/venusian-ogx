<?php

declare(strict_types=1);

namespace Jovian\Venusian\OpenGL\Enums;

/**
 * The two GLSL dialects a windowed host hands out: GLES 3.x on the Pi's
 * GtkGLArea, GL 4.1 core on the Mac's NSOpenGLView. CORE_140 is deliberately
 * absent — no windowed host gives a 3.1 desktop context.
 */
enum GLSLDialect: string
{
    case ES_300 = 'es300';
    case CORE_150 = 'core150';

    /** GL_VERSION starts with "OpenGL ES" on an ES context; anything else is desktop. */
    public static function fromVersionString(string $version): self
    {
        return str_starts_with($version, 'OpenGL ES') ? self::ES_300 : self::CORE_150;
    }

    public function versionLine(): string
    {
        return match ($this) {
            self::ES_300 => '#version 300 es',
            self::CORE_150 => '#version 150 core',
        };
    }

    /** ES fragment shaders have no default float precision. */
    public function precisionLine(): string
    {
        return match ($this) {
            self::ES_300 => 'precision mediump float;',
            self::CORE_150 => '',
        };
    }
}
