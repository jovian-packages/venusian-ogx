<?php

declare(strict_types=1);

namespace Jovian\Venusian\OpenGL;

use Jovian\Venusian\OpenGL\Exceptions\OpenGLDrawingException;
use Surface\Contracts\Drawing\GPUAttachment;
use Surface\Contracts\Drawing\GPUEngine;
use Surface\Contracts\Drawing\GPUEngineDriver;
use Surface\Contracts\Drawing\GPUHost;
use Surface\Contracts\Drawing\SurfaceKind;

/**
 * Surface's OpenGL driver. Holds nothing per process — GL state lives per
 * context, so per executor. The window engine mints the context; this
 * package draws inside it.
 */
final class OpenGLEngine implements GPUEngineDriver
{
    public function engine(): GPUEngine
    {
        return GPUEngine::OPENGL;
    }

    public function surfaceKind(): SurfaceKind
    {
        return SurfaceKind::GL_CONTEXT;
    }

    public function attach(GPUHost $host): GPUAttachment
    {
        if (is_null($host->gl)) {
            throw OpenGLDrawingException::noSurface();
        }

        $width = max(1, (int) round($host->width * $host->scale));
        $height = max(1, (int) round($host->height * $host->scale));

        return new GPUAttachment(new OpenGLExecutor($host->gl, $width, $height));
    }
}
