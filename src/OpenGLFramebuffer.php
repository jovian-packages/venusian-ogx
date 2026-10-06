<?php

namespace Jovian\Engines\OpenGL;

use Surface\Contracts\Drawing\DrawingException;
use Surface\Contracts\Framebuffers\Region;
use Surface\Framebuffers\GLFramebuffer;

/**
 * The opengl engine's framebuffer: the single-sample RGBA8 texture its device
 * resolves each frame into (or draws into with hard edges). Rows in the texture
 * run bottom-up as GL has them; this class answers and takes them top-down as
 * every Surface framebuffer does.
 */
final class OpenGLFramebuffer extends GLFramebuffer
{
    public function __construct(private readonly OpenGLDevice $device, private readonly int $texture, private readonly int $w, private readonly int $h)
    {
        parent::__construct();
    }

    /** The resolved texture is this framebuffer's to delete: a re-made target leaves the old one readable. */
    public function __destruct()
    {
        $this->device->forget($this->texture);
    }

    /** The device whose context holds the texture. */
    public function device(): OpenGLDevice
    {
        return $this->device;
    }

    /** The GL texture name. */
    public function texture(): int
    {
        return $this->texture;
    }

    public function width(): int
    {
        return $this->w;
    }

    public function height(): int
    {
        return $this->h;
    }

    public function readRgba8(Region $region): string
    {
        $this->inside($region);

        return $this->device->read($this->texture, $region);
    }

    public function uploadRgba8(string $rgba8, Region $region): void
    {
        $this->inside($region);
        $size = $region->width * $region->height * 4;
        if (strlen($rgba8) !== $size) {
            throw new DrawingException('opengl: '.strlen($rgba8)." bytes do not fill a {$region->width} × {$region->height} region ({$size} bytes).");
        }
        $this->device->write($this->texture, $rgba8, $region);
    }

    /** GL reads and writes whatever region it is given: anything past the texture is refused here. */
    private function inside(Region $region): void
    {
        if ($region->x < 0 || $region->y < 0 || $region->width < 1 || $region->height < 1
            || $region->x + $region->width > $this->w || $region->y + $region->height > $this->h) {
            throw new DrawingException("opengl: the region {$region->width} × {$region->height} at ({$region->x}, {$region->y}) lies outside the {$this->w} × {$this->h} target.");
        }
    }
}
