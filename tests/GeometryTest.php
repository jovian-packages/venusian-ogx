<?php

declare(strict_types=1);

use Jovian\Venusian\OpenGL\OpenGLExecutor;

it('flips a top-left scissor into GL bottom-left space', function () {
    // 100x80 target; a 30x20 box at (10, 5) from the top → its bottom edge is 80-5-20 = 55 from the bottom.
    expect(OpenGLExecutor::scissorRect(10, 5, 30, 20, 100, 80))->toBe([10, 55, 30, 20]);
});

it('clamps a scissor to the target like the Metal executor does', function () {
    expect(OpenGLExecutor::scissorRect(-5, -5, 500, 500, 100, 80))->toBe([0, 0, 100, 80])
        ->and(OpenGLExecutor::scissorRect(99, 79, 10, 10, 100, 80))->toBe([99, 0, 1, 1]);
});

it('flips readback rows so row 0 is the top', function () {
    $row0 = str_repeat(pack('C4', 1, 1, 1, 1), 2);
    $row1 = str_repeat(pack('C4', 2, 2, 2, 2), 2);
    $row2 = str_repeat(pack('C4', 3, 3, 3, 3), 2);

    expect(OpenGLExecutor::flipRows($row0.$row1.$row2, 2, 3))->toBe($row2.$row1.$row0);
});
