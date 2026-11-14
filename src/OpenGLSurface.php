<?php

namespace Jovian\Engines\OpenGL;

use CGLContextObj;
use NSOpenGLContext;
use NSOpenGLContextParameter;
use NSOpenGLPixelFormat;
use NSOpenGLView;
use NSView;

/**
 * What the opengl engine adds to a view a stage lends it on macOS: a child NSOpenGLView of its own, the
 * size of the stage's, drawn by a double-buffered context that shares the engine's textures and programs.
 * The stage's own view is left as it was; release() takes the child view away again.
 *
 * The views and contexts are ext-appkit's. ext-appkit is not a dependency of this package: a surface is
 * only made where it is loaded.
 *
 * Showing a frame is one draw on the GPU: the frame's texture, flipped to GL's bottom-up window, into the
 * view's back buffer, then a flush. It is not waited for. The view's context swaps without waiting for
 * the display: the stage paces showing.
 */
class OpenGLSurface
{
    protected readonly NSOpenGLView $view;

    protected readonly NSOpenGLContext $context;

    protected readonly CGLContextObj $cgl;

    /** A vertex array of the view's context: vertex arrays are not shared between contexts. */
    protected int $vao = 0;

    /** @var array{int, int} the drawable's size, in pixels */
    protected array $size = [0, 0];

    protected bool $released = false;

    protected function __construct(
        protected readonly OpenGLContext $device,
        protected readonly NSView $stage,
        NSOpenGLPixelFormat $format,
        NSOpenGLContext $context,
    ) {
        $this->context = $context;
        $this->cgl = CGLContextObj::fromPointer($context->CGLContextObj());
        $this->view = NSOpenGLView::initWithFramePixelFormat($stage->bounds(), $format);
        $this->view->setWantsBestResolutionOpenGLSurface(true);
        $this->view->setOpenGLContext($context);
        $context->setValuesForParameter([0], NSOpenGLContextParameter::SWAP_INTERVAL);
        $stage->addSubview($this->view);
    }

    /**
     * A surface over $stage, or null where AppKit makes no context sharing the engine's: the stage is then
     * shown on as pixels.
     *
     * @internal
     */
    public static function over(OpenGLContext $device, NSView $stage): ?self
    {
        $format = NSOpenGLPixelFormat::initWithAttributes([NSOpenGLPFAOpenGLProfile, NSOpenGLProfileVersion4_1Core, NSOpenGLPFAAccelerated, NSOpenGLPFADoubleBuffer, NSOpenGLPFAColorSize, 24, NSOpenGLPFAAlphaSize, 8]);
        $share = NSOpenGLContext::initWithCGLContextObj($device->cgl()->pointer());
        $context = is_null($format) || is_null($share) ? null : NSOpenGLContext::initWithFormatShareContext($format, $share);
        if (is_null($context)) {
            return null;
        }

        return new self($device, $stage, $format, $context);
    }

    /**
     * Shows $frame: its texture, resolved and flushed in the engine's context, drawn into the view's back
     * buffer, then swapped in. The engine's context is current again after.
     *
     * @internal
     */
    public function show(OpenGLFramebuffer $frame): void
    {
        $device = $this->device;
        $device->current();
        $texture = $frame->textureName();
        $program = $device->program('cover.vert', 'show.frag');
        // what the engine's context drew reaches another context only once it is flushed
        glFlush();

        $this->view->setFrame($this->stage->bounds());
        CGLSetCurrentContext($this->cgl);
        if ($this->size !== [$frame->width(), $frame->height()]) {
            $this->context->update();
            $this->size = [$frame->width(), $frame->height()];
        }
        if ($this->vao === 0) {
            glGenVertexArrays(1, $arrays);
            $this->vao = $arrays[0];
        }
        glBindFramebuffer(GL_FRAMEBUFFER, 0);
        $device->plain();
        glViewport(0, 0, $frame->width(), $frame->height());
        glUseProgram($program);
        glActiveTexture(GL_TEXTURE0);
        glBindTexture(GL_TEXTURE_2D, $texture);
        glBindVertexArray($this->vao);
        glDrawArrays(GL_TRIANGLES, 0, 3);
        $this->context->flushBuffer();
        $device->current();
    }

    /** Takes the child view away, and lets the view's context go. */
    public function release(): void
    {
        if ($this->released) {
            return;
        }
        $this->released = true;
        if ($this->vao !== 0) {
            CGLSetCurrentContext($this->cgl);
            glDeleteVertexArrays(1, [$this->vao]);
            $this->vao = 0;
            $this->device->current();
        }
        $this->context->setView(null);
        $this->view->removeFromSuperview();
    }
}
