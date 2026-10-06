<?php

namespace Jovian\Engines\OpenGL\Providers;

use Jovian\Engines\OpenGL\OpenGLDevice;
use Surface\Contracts\Drawing\WindowOutput;
use Surface\Drawing\DrawingManager;
use Surface\Drawing\Gpu\GpuRenderingEngine;
use Voyager\NutsAndBolts\ServiceProvider;

/**
 * Registers the 'opengl' engine with the drawing manager. An engine on a canvas
 * that lends a GL context draws in that context; every other engine gets its own
 * offscreen one.
 */
class VenusianOpenGLServiceProvider extends ServiceProvider
{
    public function register(): void
    {

    }

    public function boot(): void
    {
        self::extend($this->app->get('drawing'));
    }

    /** The engine's creator, on any drawing manager. */
    public static function extend(DrawingManager $drawing): void
    {
        $drawing->extend('opengl', fn (array $args, DrawingManager $drawing): GpuRenderingEngine => GpuRenderingEngine::from(self::deviceFor($args), $args, $drawing));
    }

    /**
     * The device for these arguments: adopting when the output is a window (the
     * engine asks it to lend a GL context, and adopt() takes it), its own
     * offscreen context otherwise.
     *
     * @param  array<string, mixed>  $args
     */
    public static function deviceFor(array $args): OpenGLDevice
    {
        $output = $args['output'] ?? null;
        // A window that lends no GL context is refused by the engine before adopt(): nothing is made for it.
        if ($output instanceof WindowOutput) {
            return OpenGLDevice::adopting();
        }

        return new OpenGLDevice;
    }
}
