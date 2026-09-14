<?php

declare(strict_types=1);

namespace Jovian\Venusian\OpenGL\Enums;

/** jovian/ogx has no TextureWrapMode enum; GL_CLAMP_TO_EDGE is glcorearb.h 0x812F. */
enum TextureWrap: int
{
    case CLAMP_TO_EDGE = 33071;
}
