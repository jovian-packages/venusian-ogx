<?php

declare(strict_types=1);

namespace Jovian\Venusian\OpenGL\Contracts;

use Jovian\Bindings\OpenGL\Values\ContextVersion;
use Jovian\Venusian\OpenGL\Enums\GLSLDialect;
use Surface\Contracts\Drawing\GLSurface;

/**
 * The OpenGL-only half a sketch reaches beside the Painter. Handles are 0
 * and dialect/version null until the first frame initialises the context.
 */
interface OpenGLDrawing
{
    public function program(): int;

    public function vao(): int;

    public function dialect(): ?GLSLDialect;

    public function contextVersion(): ?ContextVersion;

    /** The framebuffer this frame draws into (GtkGLArea's own FBO, or 0 on AppKit). */
    public function framebuffer(): int;

    public function surface(): GLSurface;
}
