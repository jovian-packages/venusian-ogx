<?php

declare(strict_types=1);

use Surface\Drawing\Gpu\GpuRenderingEngine;
use Venusian\Surface\Tests\Support\GpuParity\GpuParity;

it('boots with Surface\'s GPU engine and its parity suite in reach', function (): void {
    expect(class_exists(GpuRenderingEngine::class))->toBeTrue()
        ->and(class_exists(GpuParity::class))->toBeTrue()
        ->and(extension_loaded('opengl'))->toBeTrue();
});
