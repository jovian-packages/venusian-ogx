<?php

namespace Jovian\Engines\OpenGL\Providers;

use Jovian\Engines\OpenGL\OpenGLContext;
use Surface\Contracts\Graphics\Backend;
use Surface\Graphics\Gfx;
use Surface\Rendering\Recorders;
use Voyager\NutsAndBolts\ServiceProvider;

/** Registers the 'opengl' engine with Graphics, on the GPU backend. */
class VenusianOpenGLServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        if (extension_loaded('opengl')) {
            Gfx::manager()->extend('opengl', fn (Backend $backend) => new OpenGLContext($backend, recorder: fn () => Recorders::driver()->recorder()), Backend::GPU);
        }
    }
}
