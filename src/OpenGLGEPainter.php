<?php

namespace Jovian\Engines\OpenGL;

use Surface\Contracts\Rendering\Painting\PaintTarget;
use Surface\Graphics\GEFramebuffer;
use Surface\Graphics\GEPainter;

/** The opengl engine's painter, as Graphics hands it out. */
class OpenGLGEPainter extends GEPainter
{
    protected function source(GEFramebuffer $framebuffer): PaintTarget
    {
        /** @var OpenGLFramebuffer $framebuffer the base checked it is this engine's */
        return $framebuffer;
    }
}
