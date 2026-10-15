<?php

declare(strict_types=1);

namespace Jovian\Engines\OpenGL;

use Surface\Framebuffers\HdrImage;

/**
 * @internal RGBA8 read as sRGB, as the linear half floats an HDR target holds
 * for those bytes: colour decoded to linear light (SDR white 1.0), alpha as
 * it is.
 */
final class LinearHalves
{
    public static function fromRgba8(string $rgba8, int $width, int $height): string
    {
        static $colour = null;
        if (is_null($colour)) {
            $colour = [];
            for ($v = 0; $v < 256; $v++) {
                $c = $v / 255;
                $colour[$v] = $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
            }
        }
        $floats = [];
        foreach (array_values(unpack('C*', $rgba8)) as $i => $byte) {
            $floats[] = $i % 4 === 3 ? $byte / 255 : $colour[$byte];
        }

        return HdrImage::fromFloats($floats, $width, $height)->rgba16f();
    }
}
