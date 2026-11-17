<?php

namespace Jovian\Engines\OpenGL;

use Surface\Contracts\Graphics\ActorStage;
use Surface\Contracts\Rendering\Framebuffers\FormatSpec;
use Surface\Contracts\Rendering\Framebuffers\Region;
use Surface\Graphics\PainterSurface;

/**
 * An opengl surface: a framebuffer on the GPU and the painter that draws into it. On a stage that
 * lends an NSView it is shown by the GPU, in the engine's NSOpenGLView over it; on any other stage
 * as pixels, through the stage.
 */
class OpenGLSurface extends PainterSurface
{
    public function __construct(
        OpenGLContext $engine,
        protected readonly OpenGLFramebuffer $framebuffer,
        OpenGLPainter $painter,
        ?ActorStage $stage = null,
        protected readonly ?OpenGLView $view = null,
    ) {
        parent::__construct($engine, $painter, $stage);
    }

    public function width(): int
    {
        return $this->framebuffer->width();
    }

    public function height(): int
    {
        return $this->framebuffer->height();
    }

    public function format(): FormatSpec
    {
        return $this->framebuffer->format();
    }

    public function read(): string
    {
        return $this->framebuffer->pixels(Region::wholeSurface($this->width(), $this->height()));
    }

    /** The framebuffer on the GPU. */
    public function paintTarget(): OpenGLFramebuffer
    {
        return $this->framebuffer;
    }

    /**
     * In the view, by the GPU: nothing comes back to the CPU. On a paced stage this runs once a
     * refresh. Without a view, as pixels through the stage.
     */
    protected function show(): void
    {
        if (is_null($this->view)) {
            parent::show();

            return;
        }
        $this->view->show($this->framebuffer);
        $this->shown++;
    }
}
