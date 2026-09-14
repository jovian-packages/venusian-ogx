<?php

declare(strict_types=1);

use Jovian\Venusian\OpenGL\Enums\GLSLDialect;

it('picks ES 3.00 for an OpenGL ES version string', function () {
    expect(GLSLDialect::fromVersionString('OpenGL ES 3.1 Mesa 24.2.8-1~bpo12+rpt1'))->toBe(GLSLDialect::ES_300);
});

it('picks core 1.50 for a desktop version string', function () {
    expect(GLSLDialect::fromVersionString('4.1 Metal - 89.4'))->toBe(GLSLDialect::CORE_150)
        ->and(GLSLDialect::fromVersionString('3.1 Mesa 24.2.8'))->toBe(GLSLDialect::CORE_150);
});

it('spells the version and precision lines per dialect', function () {
    expect(GLSLDialect::ES_300->versionLine())->toBe('#version 300 es')
        ->and(GLSLDialect::ES_300->precisionLine())->toBe('precision mediump float;')
        ->and(GLSLDialect::CORE_150->versionLine())->toBe('#version 150 core')
        ->and(GLSLDialect::CORE_150->precisionLine())->toBe('');
});
