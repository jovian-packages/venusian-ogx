<?php

declare(strict_types=1);

use Surface\Contracts\Drawing\RenderingEngine;
use Surface\Contracts\Framebuffers\Filter;
use Surface\Contracts\Framebuffers\FormatSpec;
use Surface\Contracts\Framebuffers\Region;
use Surface\Contracts\Rasterize\Edges;
use Surface\Contracts\Rasterize\FillRule;
use Surface\Drawing\Gpu\GpuRenderingEngine;
use Surface\Framebuffers\Native\NativeFullFramebuffer;
use Surface\NutsAndBolts\Color;
use Venusian\Surface\Tests\Support\GpuParity\GpuParity;

/*
 * OpenGLDevice::draw(), through the engine: every operation of a DrawList on the
 * real GPU, held to Velvet's arithmetic pixel by pixel where the parity rule is
 * exact, and to its own promises elsewhere.
 */

/** Velvet's source-over for one channel. */
function overOf(int $s, int $d, int $a): int
{
    return intdiv($s * $a + $d * (255 - $a) + 127, 255);
}

function hexOf(int $r, int $g, int $b, int $a = 255): string
{
    return sprintf('%02x%02x%02x%02x', $r, $g, $b, $a);
}

it('clears to the colour, alpha included', function (int $samples): void {
    $engine = openglEngine(8, 4, $samples === 4 ? Edges::ANTIALIASED : Edges::HARD);

    $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgba(16, 32, 48, 128 / 255)));

    expect($engine->framebuffer()->toRgba8())->toBe(str_repeat("\x10\x20\x30\x80", 32));
})->with([1, 4]);

it('blends as Velvet does, byte for byte', function (int $a, int $samples): void {
    $engine = openglEngine(8, 4, $samples === 4 ? Edges::ANTIALIASED : Edges::HARD);

    $engine->frame(function (RenderingEngine $g) use ($a): void {
        $g->clear(Color::rgb(30, 60, 90));
        $g->fillRect(2, 1, 4, 2, Color::rgba(200, 100, 50, $a / 255));
    });

    expect(pixelOf($engine->framebuffer()->toRgba8(), 8, 3, 1))
        ->toBe(hexOf(overOf(200, 30, $a), overOf(100, 60, $a), overOf(50, 90, $a), overOf(255, 255, $a)))
        ->and(pixelOf($engine->framebuffer()->toRgba8(), 8, 1, 1))->toBe(hexOf(30, 60, 90));
})->with([1, 127, 128, 254, 255])->with([1, 4]);

it('fills by the winding rule and by even-odd', function (): void {
    $overlapping = [[[2, 2], [12, 2], [12, 12], [2, 12]], [[6, 6], [16, 6], [16, 16], [6, 16]]];
    $winding = openglEngine(20, 20);
    $even_odd = openglEngine(20, 20);

    $winding->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 0, 0))->fillPath($overlapping, Color::rgb(255, 255, 255)));
    $even_odd->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 0, 0))->fillPath($overlapping, Color::rgb(255, 255, 255), FillRule::EVEN_ODD));

    expect(pixelOf($winding->framebuffer()->toRgba8(), 20, 9, 9))->toBe('ffffffff')
        ->and(pixelOf($winding->framebuffer()->toRgba8(), 20, 4, 4))->toBe('ffffffff')
        ->and(pixelOf($even_odd->framebuffer()->toRgba8(), 20, 9, 9))->toBe('000000ff')
        ->and(pixelOf($even_odd->framebuffer()->toRgba8(), 20, 4, 4))->toBe('ffffffff')
        ->and(pixelOf($even_odd->framebuffer()->toRgba8(), 20, 14, 14))->toBe('ffffffff');
});

it('leaves the stencil clean between paths', function (): void {
    $engine = openglEngine(20, 20);

    $engine->frame(function (RenderingEngine $g): void {
        $g->clear(Color::rgb(0, 0, 0));
        $g->fillRect(2, 2, 8, 8, Color::rgb(255, 0, 0));
        $g->fillRect(6, 6, 8, 8, Color::rgb(0, 0, 255));
        $g->fillTriangle(1, 18, 19, 18, 10, 10, Color::rgba(0, 255, 0, 0.5));
    });

    $frame = $engine->framebuffer()->toRgba8();
    expect(pixelOf($frame, 20, 3, 3))->toBe('ff0000ff')
        ->and(pixelOf($frame, 20, 13, 7))->toBe('0000ffff')
        ->and(pixelOf($frame, 20, 8, 8))->toBe('0000ffff')
        ->and(pixelOf($frame, 20, 17, 2))->toBe('000000ff')
        ->and(pixelOf($frame, 20, 10, 16))->toBe(hexOf(0, overOf(255, 0, 128), 0));
});

it('keeps a pixel whose centre is inside with hard edges, and blends the edge with anti-aliased ones', function (): void {
    $hard = openglEngine(16, 16, Edges::HARD);
    $smooth = openglEngine(16, 16, Edges::ANTIALIASED);
    $draw = fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 0, 0))->fillEllipse(8.5, 8.5, 4.75, 4.75, Color::rgb(255, 255, 255));

    $hard->frame($draw);
    $smooth->frame($draw);

    // Centres: (8.5, 8.5) in; (12.5, 8.5) is 4.0 out of 4.75, in; (13.5, 8.5) is 5.0 out. The edge at x = 13.25 cuts pixel 13.
    expect(pixelOf($hard->framebuffer()->toRgba8(), 16, 8, 8))->toBe('ffffffff')
        ->and(pixelOf($hard->framebuffer()->toRgba8(), 16, 12, 8))->toBe('ffffffff')
        ->and(pixelOf($hard->framebuffer()->toRgba8(), 16, 13, 8))->toBe('000000ff')
        ->and(pixelOf($smooth->framebuffer()->toRgba8(), 16, 8, 8))->toBe('ffffffff')
        ->and(pixelOf($smooth->framebuffer()->toRgba8(), 16, 13, 8))->not->toBe('ffffffff')
        ->and(pixelOf($smooth->framebuffer()->toRgba8(), 16, 13, 8))->not->toBe('000000ff')
        ->and(pixelOf($smooth->framebuffer()->toRgba8(), 16, 0, 0))->toBe('000000ff');
});

it('strokes a ring and leaves its middle alone', function (): void {
    $engine = openglEngine(24, 24, Edges::HARD);

    $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 0, 0))->strokeEllipse(12, 12, 8, 8, Color::rgb(255, 255, 0), 4));

    $frame = $engine->framebuffer()->toRgba8();
    expect(pixelOf($frame, 24, 12, 12))->toBe('000000ff')
        ->and(pixelOf($frame, 24, 20, 12))->toBe('ffff00ff')
        ->and(pixelOf($frame, 24, 16, 12))->toBe('000000ff')
        ->and(pixelOf($frame, 24, 23, 12))->toBe('000000ff');
});

it('draws a nearest image on whole pixels exactly, at full and half opacity', function (): void {
    $engine = openglEngine(32, 16);
    $tile = GpuParity::tile();

    $engine->frame(function (RenderingEngine $g) use ($tile): void {
        $g->clear(Color::rgb(0, 0, 0));
        $g->image($tile, 2, 2);
        $g->image($tile, 16, 4, opacity: 128 / 255);
    });

    $frame = $engine->framebuffer()->toRgba8();
    $want = $tile->toRgba8();
    expect($engine->framebuffer()->readRgba8(new Region(2, 2, 8, 8)))->toBe($want)
        ->and(pixelOf($frame, 32, 1, 1))->toBe('000000ff')
        ->and(pixelOf($frame, 32, 10, 2))->toBe('000000ff');
    [$r, $g, $b] = [ord($want[(3 * 8 + 5) * 4]), ord($want[(3 * 8 + 5) * 4 + 1]), ord($want[(3 * 8 + 5) * 4 + 2])];
    expect(pixelOf($frame, 32, 21, 7))->toBe(hexOf(overOf($r, 0, 128), overOf($g, 0, 128), overOf($b, 0, 128)));
});

it('draws a linear image as Velvet does on its flat interior', function (): void {
    $engine = openglEngine(32, 16);
    $velvet = GpuParity::velvet(32, 16, Edges::ANTIALIASED);
    $flat = new NativeFullFramebuffer(FormatSpec::rgba8(), 4, 4);
    $flat->fill(0x3060C0FF);
    $draw = fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 0, 0))->image($flat, 3.5, 2.5, 16, 8, 0.75, Filter::LINEAR);

    $engine->frame($draw);
    $velvet->frame($draw);

    expect(pixelOf($engine->framebuffer()->toRgba8(), 32, 10, 6))->toBe(pixelOf($velvet->framebuffer()->toRgba8(), 32, 10, 6))
        ->and(pixelOf($engine->framebuffer()->toRgba8(), 32, 10, 6))->not->toBe('000000ff');
});

it('clips to the scissor', function (): void {
    $engine = openglEngine(16, 8);

    $engine->frame(function (RenderingEngine $g): void {
        $g->clear(Color::rgb(0, 0, 0));
        $g->clip(new Region(4, 2, 4, 4));
        $g->fillRect(0, 0, 16, 8, Color::rgb(255, 255, 255));
    });

    $frame = $engine->framebuffer()->toRgba8();
    expect(pixelOf($frame, 16, 5, 3))->toBe('ffffffff')
        ->and(pixelOf($frame, 16, 3, 3))->toBe('000000ff')
        ->and(pixelOf($frame, 16, 8, 3))->toBe('000000ff');
});

it('draws a partial frame over the kept target', function (): void {
    $engine = openglEngine(32, 16);
    $draw = fn (int $x) => fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 0, 0))->fillRect($x, 4, 4, 4, Color::rgb(255, 255, 255))->fillRect(24, 4, 4, 4, Color::rgb(255, 0, 0));

    $engine->frame($draw(2));
    $engine->frame($draw(10));

    $frame = $engine->framebuffer()->toRgba8();
    expect(pixelOf($frame, 32, 3, 5))->toBe('000000ff')
        ->and(pixelOf($frame, 32, 11, 5))->toBe('ffffffff')
        ->and(pixelOf($frame, 32, 25, 5))->toBe('ff0000ff')
        ->and($engine->damage())->not->toEqual([new Region(0, 0, 32, 16)]);
});

it('keeps an upload through the next partial frame', function (): void {
    $engine = openglEngine(32, 16, Edges::ANTIALIASED);
    $draw = fn (int $x) => fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 0, 0))->fillRect($x, 4, 4, 4, Color::rgb(255, 255, 255));
    $engine->frame($draw(2));

    $engine->framebuffer()->setPixel(30, 14, 0xFF0000FF);
    $engine->frame($draw(10));

    $frame = $engine->framebuffer()->toRgba8();
    expect(pixelOf($frame, 32, 30, 14))->toBe('ff0000ff')
        ->and(pixelOf($frame, 32, 11, 5))->toBe('ffffffff')
        ->and(pixelOf($frame, 32, 3, 5))->toBe('000000ff');
});

it('takes another engine\'s framebuffer as an image source', function (): void {
    $source = openglEngine(8, 8);
    $source->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 128, 255)));
    $engine = openglEngine(16, 16);

    $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 0, 0))->image($source->framebuffer(), 4, 4));

    expect(pixelOf($engine->framebuffer()->toRgba8(), 16, 6, 6))->toBe('0080ffff')
        ->and(pixelOf($engine->framebuffer()->toRgba8(), 16, 2, 2))->toBe('000000ff');
});

it('draws its own framebuffer as an image: the frame before, the right way up', function (int $samples): void {
    $engine = openglEngine(16, 8, $samples === 4 ? Edges::ANTIALIASED : Edges::HARD);
    $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 0, 0))->fillRect(0, 0, 4, 2, Color::rgb(255, 0, 0)));

    $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 0, 255))->image($engine->framebuffer(), 8, 4));

    $frame = $engine->framebuffer()->toRgba8();
    expect(pixelOf($frame, 16, 8, 4))->toBe('ff0000ff')
        ->and(pixelOf($frame, 16, 11, 5))->toBe('ff0000ff')
        ->and(pixelOf($frame, 16, 8, 7))->toBe('000000ff')
        ->and(pixelOf($frame, 16, 0, 0))->toBe('0000ffff');
})->with([1, 4]);

it('re-makes its target at another size and sample count', function (): void {
    $engine = openglEngine(16, 8, Edges::ANTIALIASED);
    $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(255, 0, 0)));
    $device = $engine->device();

    $target = $device->target(8, 4, 1);
    $engine2 = new GpuRenderingEngine($device, 8, 4, Edges::HARD);
    $engine2->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 0, 255)));

    expect(pixelOf($engine->framebuffer()->toRgba8(), 16, 1, 1))->toBe('ff0000ff')
        ->and(pixelOf($engine2->framebuffer()->toRgba8(), 8, 1, 1))->toBe('0000ffff')
        ->and($engine2->framebuffer())->not->toBe($target);
});

it('draws many frames without waiting between them', function (): void {
    $engine = openglEngine(64, 32);
    $started = hrtime(true);

    foreach (range(0, 59) as $i) {
        $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 0, 0))->fillEllipse(8 + $i % 48, 16, 6, 6, Color::rgb(255, 255, 255)));
    }
    $frame = $engine->framebuffer()->toRgba8();

    expect(pixelOf($frame, 64, 8 + 59 % 48, 16))->toBe('ffffffff')
        ->and((hrtime(true) - $started) / 1e6)->toBeLessThan(4000.0);
});

it('reads a frame back only once it has landed, with other devices at work', function (): void {
    // Sixteen contexts in one process, each read back right after its frame.
    $engines = [];
    $missed = 0;
    foreach (range(1, 16) as $i) {
        $engines[] = $engine = openglEngine(8, 4);
        $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(30, 60, 90)));
        $missed += pixelOf($engine->framebuffer()->toRgba8(), 8, 0, 0) === '1e3c5aff' ? 0 : 1;
    }

    expect($missed)->toBe(0);
});

it('binds a texture to the sampler for every draw, so the driver has nothing to complain of', function (): void {
    // The fragment program declares a sampler; a draw with no texture on its
    // unit is one Apple's GL logs ("unit 0 … is unloadable … using zero texture").
    $script = tempnam(sys_get_temp_dir(), 'opengl').'.php';
    file_put_contents($script, '<?php require '.var_export(dirname(__DIR__).'/vendor/autoload.php', true).';
        $engine = new Surface\Drawing\Gpu\GpuRenderingEngine(new Jovian\Engines\OpenGL\OpenGLDevice, 8, 4);
        $engine->frame(fn ($g) => $g->clear(Surface\NutsAndBolts\Color::rgb(1, 2, 3))->fillRect(1, 1, 4, 2, Surface\NutsAndBolts\Color::rgba(9, 8, 7, 0.5))->fillEllipse(4, 2, 2, 1, Surface\NutsAndBolts\Color::rgb(0, 0, 0)));
        echo bin2hex(substr($engine->framebuffer()->toRgba8(), 0, 4));');

    $process = proc_open([PHP_BINARY, '-d', 'memory_limit=128M', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    $code = proc_close($process);
    unlink($script);

    expect($code)->toBe(0, $err)->and($err)->toBe('')->and($out)->toBe('010203ff');
});

it('fills a path after an ellipse or a ring, the stencil marked in full', function (string $before): void {
    // The stencil pass runs the fragment program too: with the oval's Paint
    // still pushed, it would discard outside the oval and leave the path unmarked.
    $engine = openglEngine(64, 32);

    $engine->frame(function (RenderingEngine $g) use ($before): void {
        $g->clear(Color::hex('#101820'));
        $before === 'ellipse'
            ? $g->fillEllipse(20, 12, 7, 5, Color::rgba(40, 200, 255, 0.5))
            : $g->strokeEllipse(46, 16, 12, 9, Color::rgb(60, 60, 255), 4);
        $g->fillTriangle(34, 1, 62, 2, 40, 30, Color::rgb(255, 0, 255));
    });

    expect(pixelOf($engine->framebuffer()->toRgba8(), 64, 40, 3))->toBe('ff00ffff');
})->with(['ellipse', 'ring']);

it('cancels a frame it cannot encode, and draws the next', function (): void {
    // An image source wider than any texture: the upload fails mid-encode.
    $engine = openglEngine(16, 8);
    $wide = new NativeFullFramebuffer(FormatSpec::rgba8(), 20000, 1);
    $largest = $engine->device()->maxTextureSize();
    $before = residentMb();

    foreach (range(1, 40) as $i) {
        expect(fn () => $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 0, 0))->image($wide, 0, 0)))
            ->toThrow(Surface\Contracts\Drawing\DrawingException::class, "opengl: 20000 × 1 is past the largest texture this context makes, {$largest} × {$largest}.");
    }
    $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 128, 255)));

    expect(pixelOf($engine->framebuffer()->toRgba8(), 16, 1, 1))->toBe('0080ffff')
        ->and(residentMb() - $before)->toBeLessThan(8.0);
});
