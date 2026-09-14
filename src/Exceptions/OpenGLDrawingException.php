<?php

declare(strict_types=1);

namespace Jovian\Venusian\OpenGL\Exceptions;

use Surface\Contracts\Drawing\DrawingException;

class OpenGLDrawingException extends DrawingException
{
    public static function outsideFrame(string $operation): self
    {
        return new self("{$operation} is only legal between beginFrame() and endFrame().");
    }

    public static function released(): self
    {
        return new self('executor has been released');
    }

    public static function noSurface(): self
    {
        return new self('the OpenGL engine needs a GLSurface on the GPUHost; the window engine minted none');
    }

    public static function compileFailed(string $stage, string $log): self
    {
        return new self("{$stage} shader did not compile:\n".rtrim($log, "\0\n"));
    }

    public static function linkFailed(string $log): self
    {
        return new self("painter program did not link:\n".rtrim($log, "\0\n"));
    }
}
