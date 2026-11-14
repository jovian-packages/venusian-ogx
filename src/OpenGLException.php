<?php

namespace Jovian\Engines\OpenGL;

use Surface\Contracts\Graphics\GraphicsException;
use Throwable;

/** What OpenGL refused or failed at, in the opengl engine's words. */
class OpenGLException extends GraphicsException
{
    public static function because(string $what, ?Throwable $previous = null): self
    {
        return new self("opengl: {$what}", 0, $previous);
    }
}
