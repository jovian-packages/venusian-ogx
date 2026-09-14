<?php

declare(strict_types=1);

namespace Venusian\Tests\Support;

use Surface\Contracts\Drawing\GLSurface;

/** A surface for a context that is already current (the Pest headless recipe) — nothing to make current, nothing to swap. */
final class HeadlessGLSurface implements GLSurface
{
    public int $made_current = 0;

    public int $presented = 0;

    public function __construct(private int $width, private int $height) {}

    public function makeCurrent(): void
    {
        $this->made_current++;
    }

    public function present(): void
    {
        $this->presented++;
    }

    public function drawableSize(): array
    {
        return [$this->width, $this->height];
    }
}
