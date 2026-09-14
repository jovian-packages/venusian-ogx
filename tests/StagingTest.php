<?php

declare(strict_types=1);

use Jovian\Venusian\OpenGL\Enums\StagingRegion;
use Jovian\Venusian\OpenGL\OpenGLExecutor;

it('keeps the block when the payload fits', function () {
    expect(OpenGLExecutor::grownSize(4096, 1000))->toBe(4096);
});

it('doubles the block, or jumps straight to the need, whichever is larger', function () {
    expect(OpenGLExecutor::grownSize(4096, 4096 - StagingRegion::PAYLOAD->value + 1))->toBe(8192)
        ->and(OpenGLExecutor::grownSize(4096, 100000))->toBe(100000 + StagingRegion::PAYLOAD->value);
});

it('never shrinks below the fixed regions', function () {
    expect(OpenGLExecutor::grownSize(0, 0))->toBe(4096);
});
