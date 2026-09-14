<?php

declare(strict_types=1);

namespace Jovian\Venusian\OpenGL\Providers;

use Jovian\Venusian\OpenGL\OpenGLEngine;
use Voyager\NutsAndBolts\ServiceProvider;

/** Publishes the OpenGL GPU engine under the alias Surface looks for. */
class VenusianOpenGLServiceProvider extends ServiceProvider
{
    /** Bind the engine as a singleton behind 'gpu.opengl' — the whole seam to Surface. */
    public function register(): void
    {
        $this->app->singleton(OpenGLEngine::class);
        $this->app->alias(OpenGLEngine::class, 'gpu.opengl');
    }

    /** Nothing to boot. GL state lives per context, so per executor, made at first frame. */
    public function boot(): void {}
}
