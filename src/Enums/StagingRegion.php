<?php

declare(strict_types=1);

namespace Jovian\Venusian\OpenGL\Enums;

/**
 * Byte offsets inside the executor's one staging block: 16 bytes of scratch
 * for glGen and glGetiv answers, 64 bytes for the mat4, then the payload
 * (vertices, indices, texels, readback) which grows on demand.
 */
enum StagingRegion: int
{
    case SCRATCH = 0;
    case TRANSFORM = 16;
    case PAYLOAD = 80;
}
