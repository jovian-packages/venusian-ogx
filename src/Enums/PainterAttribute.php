<?php

declare(strict_types=1);

namespace Jovian\Venusian\OpenGL\Enums;

/**
 * The Surface vertex `x y z r g b a u v` (nine floats, stride 36) as GL
 * attribute slots. Bound by index before link — neither dialect floor has layout().
 */
enum PainterAttribute: int
{
    case POSITION = 0;
    case COLOR = 1;
    case UV = 2;

    public function glslName(): string
    {
        return match ($this) {
            self::POSITION => 'aPosition',
            self::COLOR => 'aColor',
            self::UV => 'aUV',
        };
    }

    public function components(): int
    {
        return match ($this) {
            self::POSITION => 3,
            self::COLOR => 4,
            self::UV => 2,
        };
    }

    /** Byte offset inside one vertex. */
    public function offset(): int
    {
        return match ($this) {
            self::POSITION => 0,
            self::COLOR => 12,
            self::UV => 28,
        };
    }

    public static function stride(): int
    {
        return 36;
    }
}
