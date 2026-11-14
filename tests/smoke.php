<?php
/*
 * Checks the opengl engine on this machine's GPU. Opens no window.
 *
 *   zhp tests/smoke.php /path/to/venusian/topside
 *
 * Topside's own vendor/ and test helpers are used. Prints one line a check, then "ok N, failed M".
 * Exits 1 when a check fails.
 *
 * Velvet's pixels are a loose check that both drew the same picture, not a target: each engine
 * draws its own way. Hard edges may differ by a pixel where an edge passes exactly through a
 * pixel's centre. Antialiased edges come from four samples a pixel, so a byte on an edge may be
 * up to about a quarter step off Velvet's exact coverage, and only edges may differ.
 */
use Jovian\Engines\OpenGL\OpenGLContext;
use Surface\Contracts\Graphics\Backend;
use Surface\Contracts\Graphics\GraphicsException;
use Surface\Contracts\Graphics\Resource;
use Surface\Contracts\Graphics\StrokeStyle;
use Surface\Contracts\Rendering\Framebuffers\FormatSpec;
use Surface\Contracts\Rendering\Framebuffers\Region;
use Surface\Contracts\Rendering\Rasterization\Edges;
use Surface\Contracts\Rendering\Rasterization\FillRule;
use Surface\Graphics\GEPainter;
use Surface\NutsAndBolts\Color;
use Surface\NutsAndBolts\Path;
use Surface\VelvetGE\VelvetDevice;
use Surface\VelvetGE\Recording\PHPLandQueue;
use Surface\VelvetGE\Recording\VelvetRecorder;

$topside = $argv[1] ?? throw new RuntimeException('Give the path to venusian/topside.');
$loader = require "{$topside}/vendor/autoload.php";
$loader->addPsr4('Jovian\\Engines\\OpenGL\\', dirname(__DIR__).'/src');
require "{$topside}/tests/Pest.php";

[$ok, $bad] = [0, 0];
$check = function (string $what, Closure $pass) use (&$ok, &$bad): void {
    try {
        [$passed, $note] = [(bool) $pass(), ''];
    } catch (Throwable $e) {
        [$passed, $note] = [false, ' ('.get_class($e).': '.$e->getMessage().')'];
    }
    $passed ? $ok++ : $bad++;
    echo $passed ? 'ok   ' : 'FAIL ', $what, $note, "\n";
};

$opengl = new OpenGLContext(Backend::GPU, recorder: fn () => new VelvetRecorder(new PHPLandQueue()));
$velvet = new VelvetDevice(Backend::CPU, framebuffers(['rendering.framebuffer.default' => 'native']), rasterize(), painters(), null, ['rasterizer' => 'native', 'framebuffer' => 'native', 'painter' => 'native']);
echo 'gpu: ', $opengl->modules()['gpu'], "\n";

/** @return array{int, int} bytes that differ, and the worst difference */
$compare = function (Closure $draw, Edges $edges) use ($opengl, $velvet): array {
    $out = [];
    foreach (['opengl' => $opengl, 'velvet' => $velvet] as $name => $engine) {
        $frame = $engine->framebuffer(100, 100);
        $frame->clear(Color::hex('#101820'));
        $draw($frame, $engine->rasterizer($edges));
        $out[$name] = $frame->read();
    }
    [$differ, $worst] = [0, 0];
    for ($i = 0; $i < 40000; $i++) {
        $d = abs(ord($out['opengl'][$i]) - ord($out['velvet'][$i]));
        [$differ, $worst] = [$differ + ($d > 0 ? 1 : 0), max($worst, $d)];
    }

    return [$differ, $worst];
};

$star = (new Path)->moveTo(50, 5)->lineTo(61, 38)->lineTo(95, 38)->lineTo(67, 58)->lineTo(78, 92)->lineTo(50, 71)->lineTo(22, 92)->lineTo(33, 58)->lineTo(5, 38)->lineTo(39, 38)->close();
$wave = new Path;
for ($x = 4.0; $x <= 96; $x += 4) {
    $y = 50 + 30 * sin($x / 9);
    $x === 4.0 ? $wave->moveTo($x, $y) : $wave->lineTo($x, $y);
}
$scenes = [
    'a translucent star, non-zero' => fn ($f, $r) => $r->fill($f, $star, Color::rgba(255, 107, 53, 0.8)),
    'a star, even-odd' => fn ($f, $r) => $r->fill($f, $star, Color::hex('#40c8ff'), FillRule::EVEN_ODD),
    'a stroked wave' => fn ($f, $r) => $r->stroke($f, $wave, Color::hex('#f7f052'), new StrokeStyle(4.0)),
    'a star cut by a clip' => fn ($f, $r) => $r->fill($f, $star, Color::hex('#ff0000'), clip: new Region(20, 20, 40, 40)),
    'a star over written pixels' => function ($f, $r) use ($star) {
        $f->write(str_repeat("\x10\x20\x30\xff", 10000));
        $r->fill($f, $star, Color::rgba(255, 255, 255, 0.5));
    },
];

$check('it is the GPU backend only, with a framebuffer, a rasterizer and a painter', fn () => $opengl->name() === 'opengl'
    && $opengl->supports(Resource::FRAMEBUFFER) && $opengl->supports(Resource::RASTERIZER) && $opengl->supports(Resource::PAINTER));
$check('it refuses the CPU backend', function () {
    try {
        new OpenGLContext(Backend::CPU);
    } catch (GraphicsException $e) {
        return str_contains($e->getMessage(), 'has no cpu backend');
    }

    return false;
});
$check('a new framebuffer is transparent black', fn () => $opengl->framebuffer(3, 2)->read() === str_repeat("\0", 24));
// it holds premultiplied colour, so a nearly transparent pixel keeps its alpha but not all of its colour
$check('it reads back what was written, and a cleared colour as its bytes', function () use ($opengl) {
    $frame = $opengl->framebuffer(4, 4)->clear(Color::rgba(10, 20, 30, 0.5));
    $frame->write("\xc8\x64\x32\xff\x80\x40\x20\x80", new Region(1, 2, 2, 1));
    $got = [bin2hex($frame->read(new Region(0, 0, 1, 1))), bin2hex($frame->read(new Region(1, 2, 2, 1)))];
    echo '     cleared ', $got[0], ', written ', $got[1], "\n";

    return $got === ['0a141e80', 'c86432ff80402080'];
});
$check('a write to part of it leaves the rest', function () use ($opengl) {
    $frame = $opengl->framebuffer(4, 2)->clear(Color::rgb(1, 2, 3));
    $frame->write("\xff\x00\x00\xff", new Region(2, 1, 1, 1));

    return $frame->read() === str_repeat("\x01\x02\x03\xff", 6)."\xff\x00\x00\xff\x01\x02\x03\xff";
});
$check('it holds RGBA8 only', function () use ($opengl) {
    try {
        $opengl->framebuffer(4, 4, FormatSpec::bgra8());
    } catch (GraphicsException) {
        return true;
    }

    return false;
});
$check('a shape refuses another engine\'s framebuffer', function () use ($opengl, $velvet, $star) {
    try {
        $opengl->rasterizer()->fill($velvet->framebuffer(4, 4), $star, Color::hex('#fff'));
    } catch (GraphicsException) {
        return true;
    }

    return false;
});
foreach ($scenes as $name => $draw) {
    $check("hard edges: {$name} is the picture Velvet draws", function () use ($compare, $draw) {
        [$differ, $worst] = $compare($draw, Edges::HARD);
        echo "     {$differ} bytes differ, worst {$worst}\n";

        return $differ <= 12;
    });
}
foreach ($scenes as $name => $draw) {
    $check("antialiased: {$name} is the picture Velvet draws, but for its edges", function () use ($compare, $draw) {
        [$differ, $worst] = $compare($draw, Edges::ANTIALIASED);
        echo "     {$differ} bytes differ, worst {$worst}\n";

        return $differ < 2000 && $worst <= 72;
    });
}
$check('many framebuffers and more submissions than are let in flight', function () use ($opengl, $star) {
    $r = $opengl->rasterizer();
    for ($n = 0; $n < 6; $n++) {
        $frame = $opengl->framebuffer(64, 64);
        for ($i = 0; $i < 20; $i++) {
            $r->fill($frame, $star, Color::rgba(255, $i * 10, 0, 0.5));
        }
        if (strlen($frame->read()) !== 64 * 64 * 4) {
            return false;
        }
    }

    return true;
});

$check('it scrolls a region on the GPU, leaving the strip behind as it was', function () use ($opengl) {
    $frame = $opengl->framebuffer(4, 1);
    $frame->write("\x01\x00\x00\xff\x02\x00\x00\xff\x03\x00\x00\xff\x04\x00\x00\xff");
    $frame->scroll(new Region(0, 0, 4, 1), 1, 0);

    return bin2hex($frame->read()) === '010000ff010000ff020000ff030000ff';
});
$check('a vertical scroll moves rows, top row first', function () use ($opengl) {
    $frame = $opengl->framebuffer(1, 4);
    $frame->write("\x01\x00\x00\xff\x02\x00\x00\xff\x03\x00\x00\xff\x04\x00\x00\xff");
    $frame->scroll(new Region(0, 0, 1, 4), 0, 1);

    return bin2hex($frame->read()) === '010000ff010000ff020000ff030000ff';
});
// the painter: every shape it carries out, against Velvet's painter
$scene = function (GEPainter $g): void {
    $g->clear(Color::hex('#101820'));
    $g->shadow(10, 14, 60, 60, 10, 8, Color::rgba(0, 0, 0, 0.6));
    $g->roundRect(10, 10, 60, 60, [10, 10, 10, 10], Color::hex('#ff6b35'), Color::hex('#000000'), 3);
    $g->fillEllipse(70, 50, 24, 18, Color::rgba(64, 200, 255, 0.6));
    $g->strokeEllipse(70, 50, 30, 30, Color::hex('#ffffff'), 2);
    $g->fillRect(80, 4, 14, 14, Color::hex('#40ff80'));
    $g->line(4, 90, 96, 70, Color::hex('#ffffff'), 3);
    $g->push()->clip(new Region(0, 0, 50, 100))->fillPolygon([[20, 80], [45, 95], [70, 75]], Color::hex('#f7f052'))->pop();
};
$painted = function (Closure $draw) use ($opengl, $velvet): array {
    $out = [];
    foreach (['opengl' => $opengl, 'velvet' => $velvet] as $name => $engine) {
        $frame = $engine->framebuffer(100, 100);
        $engine->painter($frame)->frame($draw);
        $out[$name] = $frame->read();
    }
    [$differ, $worst] = [0, 0];
    for ($i = 0; $i < 40000; $i++) {
        $d = abs(ord($out['opengl'][$i]) - ord($out['velvet'][$i]));
        [$differ, $worst] = [$differ + ($d > 0 ? 1 : 0), max($worst, $d)];
    }

    return [$differ, $worst];
};
$check("the painter's shapes are Velvet's but for their edges", function () use ($painted, $scene) {
    [$differ, $worst] = $painted($scene);
    echo "     {$differ} bytes differ, worst {$worst}\n";

    // four samples a pixel, as Metal: a curved edge through a pixel's middle counts 0 or 1 of 4 where Velvet measures half
    return $differ < 3000 && $worst <= 128;
});
$check('the painter redraws only what changed, and reports it', function () use ($opengl) {
    $frame = $opengl->framebuffer(100, 100);
    $painter = $opengl->painter($frame);
    $painter->frame(fn (GEPainter $g) => $g->clear(Color::hex('#000000'))->fillRect(10, 10, 10, 10, Color::hex('#ff0000')));
    $painter->frame(fn (GEPainter $g) => $g->clear(Color::hex('#000000'))->fillRect(50, 50, 10, 10, Color::hex('#ff0000')));
    $damage = $painter->damage();

    return bin2hex($frame->read(new Region(15, 15, 1, 1))) === '000000ff' && bin2hex($frame->read(new Region(55, 55, 1, 1))) === 'ff0000ff' && count($damage) >= 1;
});
// gradients: each kind and spread, against Velvet's
$star = (new Path)->moveTo(50, 5)->lineTo(61, 38)->lineTo(95, 38)->lineTo(67, 58)->lineTo(78, 92)->lineTo(50, 71)->lineTo(22, 92)->lineTo(33, 58)->lineTo(5, 38)->lineTo(39, 38)->close();
$stops = [[0.0, Color::hex('#ff6b35')], [0.5, Color::rgba(64, 200, 255, 0.5)], [1.0, Color::hex('#f7f052')]];
$gradients = [
    'a linear gradient' => new Surface\NutsAndBolts\LinearGradient(10, 10, 90, 60, $stops),
    'a radial gradient' => new Surface\NutsAndBolts\RadialGradient(50, 50, 40, $stops),
    'a repeating linear gradient' => new Surface\NutsAndBolts\LinearGradient(20, 0, 40, 0, $stops, Surface\NutsAndBolts\GradientSpread::REPEAT),
    'a reflected radial gradient' => new Surface\NutsAndBolts\RadialGradient(50, 50, 15, $stops, Surface\NutsAndBolts\GradientSpread::REFLECT),
];
foreach ($gradients as $name => $gradient) {
    $check("{$name} fills a path as Velvet's does, but for its edges", function () use ($painted, $star, $gradient) {
        [$differ, $worst] = $painted(fn (GEPainter $g) => $g->clear(Color::hex('#101820'))->rotate(0.2)->fill($star, $gradient));
        echo "     {$differ} bytes differ, worst {$worst}\n";

        return $differ < 3000 && $worst <= 128;
    });
}

// images: a picture drawn by each engine onto one of its own framebuffers, then drawn as an image
$images = function (Closure $draw) use ($opengl, $velvet): array {
    $out = [];
    foreach (['opengl' => $opengl, 'velvet' => $velvet] as $name => $engine) {
        $picture = $engine->framebuffer(20, 12);
        $rows = '';
        for ($y = 0; $y < 12; $y++) {
            for ($x = 0; $x < 20; $x++) {
                $rows .= ($x + $y) % 3 === 0 ? "\x00\x00\x00\x00" : chr($x * 12).chr($y * 20).chr(200 - $x * 5).(($x % 4) === 1 ? "\x80" : "\xff");
            }
        }
        $picture->write($rows);
        $frame = $engine->framebuffer(100, 100);
        $engine->painter($frame)->frame(fn (GEPainter $g) => $draw($g->clear(Color::hex('#203040')), $picture));
        $out[$name] = $frame->read();
    }
    [$differ, $worst] = [0, 0];
    for ($i = 0; $i < 40000; $i++) {
        $d = abs(ord($out['opengl'][$i]) - ord($out['velvet'][$i]));
        [$differ, $worst] = [$differ + ($d > 0 ? 1 : 0), max($worst, $d)];
    }

    return [$differ, $worst];
};
$pictures = [
    'at its own size' => [fn (GEPainter $g, $p) => $g->image($p, 10, 10), 2],
    'four times the size, nearest' => [fn (GEPainter $g, $p) => $g->image($p, 10, 10, 80, 48), 2],
    'four times the size, smooth' => [fn (GEPainter $g, $p) => $g->image($p, 10, 10, 80, 48, filter: Surface\Contracts\Rendering\Framebuffers\Filter::LINEAR), 6],
    'half see-through and tinted' => [fn (GEPainter $g, $p) => $g->image($p, 10, 10, 80, 48, opacity: 0.5, tint: Color::rgba(255, 128, 64, 0.8)), 4],
    'a part of it only' => [fn (GEPainter $g, $p) => $g->image($p, 10, 10, 60, 60, sourceRect: [4, 2, 8, 8]), 2],
    'with rounded corners' => [fn (GEPainter $g, $p) => $g->image($p, 10, 10, 80, 48, radii: [10, 10, 10, 10]), 128],
    'turned and cut by a clip' => [fn (GEPainter $g, $p) => $g->clip(new Region(20, 20, 50, 50))->rotate(0.3)->image($p, 30, 0, 60, 36), 128],
];
foreach ($pictures as $name => [$draw, $allowed]) {
    $check("an image {$name} is Velvet's, within {$allowed}", function () use ($images, $draw, $allowed) {
        [$differ, $worst] = $images($draw);
        echo "     {$differ} bytes differ, worst {$worst}\n";

        return $worst <= $allowed;
    });
}
$check("the painter draws an image from its own engine's framebuffers only, and not a framebuffer onto itself", function () use ($opengl) {
    $frame = $opengl->framebuffer(8, 8);
    try {
        $opengl->painter($frame)->frame(fn (GEPainter $g) => $g->image($frame, 0, 0));
    } catch (Surface\Contracts\Graphics\DrawingException $e) {
        return str_contains($e->getMessage(), 'onto itself');
    }

    return false;
});
// layers: drawn apart and laid down at an opacity, against Velvet's
$shapes = fn (GEPainter $g) => $g->fillRect(0, 0, 60, 40, Color::hex('#ff6b35'))->fillEllipse(40, 30, 25, 20, Color::rgba(64, 200, 255, 0.7));
$layers = [
    'at half opacity' => fn (GEPainter $g) => $g->layer(10, 10, 70, 60, $shapes, 0.5),
    'with rounded corners' => fn (GEPainter $g) => $g->layer(10, 10, 70, 60, $shapes, 1.0, [12, 12, 12, 12]),
    'moved, and partly off the surface' => fn (GEPainter $g) => $g->translate(60, 70)->layer(0, 0, 70, 60, $shapes, 0.8),
    'cut by a clip' => fn (GEPainter $g) => $g->clip(new Region(30, 20, 30, 60))->layer(10, 10, 70, 60, $shapes, 0.6),
    'holding a layer' => fn (GEPainter $g) => $g->layer(5, 5, 90, 90, fn (GEPainter $g) => $g->fillRect(0, 0, 90, 90, Color::rgba(255, 255, 255, 0.4))->layer(10, 10, 70, 60, $shapes, 0.5, [8, 8, 8, 8]), 0.7),
    'twice, the same size' => fn (GEPainter $g) => $g->layer(0, 0, 50, 50, $shapes, 0.5)->layer(40, 40, 50, 50, $shapes, 0.9),
];
foreach ($layers as $name => $draw) {
    $check("a layer {$name} is Velvet's, but for its edges", function () use ($painted, $draw) {
        [$differ, $worst] = $painted(fn (GEPainter $g) => $draw($g->clear(Color::hex('#203040'))));
        echo "     {$differ} bytes differ, worst {$worst}\n";

        return $differ < 3000 && $worst <= 128;
    });
}
$inner = fn ($painter) => (fn () => $this->inner)->call($painter);
$check('a layer that changes size a little keeps its sheet', function () use ($opengl, $shapes, $inner) {
    $painter = $opengl->painter($opengl->framebuffer(200, 100));
    $made = [];
    for ($i = 0; $i < 30; $i++) {
        $painter->frame(fn (GEPainter $g) => $g->clear(Color::hex('#000000'))->layer(0, 0, 70 + $i, 50, $shapes, 0.5));
        $made[spl_object_id((fn () => $this->sheets)->call($inner($painter))['128x64'][0])] = true;
    }

    return count($made) === 1;
});
$check('layers of a moving frame keep their sheets from frame to frame', function () use ($opengl, $shapes, $inner) {
    $frame = $opengl->framebuffer(100, 100);
    $painter = $opengl->painter($frame);
    for ($i = 0; $i < 20; $i++) {
        $painter->frame(fn (GEPainter $g) => $g->clear(Color::hex('#000000'))->layer($i, 10, 50, 50, $shapes, 0.5));
    }
    $sheets = array_map('count', (fn () => $this->sheets)->call($inner($painter)));
    echo '     sheets held after 20 frames: '.json_encode($sheets)."\n";

    return $sheets === ['64x64' => 1] && bin2hex($frame->read(new Region(90, 90, 1, 1))) === '000000ff' && bin2hex($frame->read(new Region(21, 12, 1, 1))) !== '000000ff';
});
$check('a layer whose sheet size changes past a step lets the old sheet go and draws on', function () use ($opengl, $shapes, $inner) {
    $frame = $opengl->framebuffer(300, 100);
    $painter = $opengl->painter($frame);
    for ($i = 0; $i < 12; $i++) {
        $painter->frame(fn (GEPainter $g) => $g->clear(Color::hex('#000000'))->layer(0, 0, 40 + $i * 20, 50, $shapes, 1.0));
    }
    $sheets = array_map('count', (fn () => $this->sheets)->call($inner($painter)));

    return array_keys($sheets) === ['320x64'] && bin2hex($frame->read(new Region(5, 5, 1, 1))) === 'ff6b35ff';
});
// A frame redrawn in many regions: the shared Painter hands the painter a copy of the layer for each region; OpenGL
// draws its contents once a frame and reads them in each. Frame by frame against Velvet.
$check("a layer in a frame redrawn in many regions is drawn once and is Velvet's", function () use ($opengl, $velvet, $inner) {
    $scene = fn (int $i) => fn (GEPainter $g) => $g->clear(Color::hex('#203040'))
        ->fillRect(2, 2, 5, 5, Color::rgb(($i * 40) % 256, 0, 0))->fillRect(93, 2, 5, 5, Color::rgb(0, ($i * 40) % 256, 0))
        ->fillRect(2, 93, 5, 5, Color::rgb(0, 0, ($i * 40) % 256))->fillRect(93, 93, 5, 5, Color::rgb(($i * 40) % 256, ($i * 40) % 256, 0))
        ->layer(0, 0, 100, 100, fn (GEPainter $g) => $g->fillRect(10, 10, 40, 40, Color::hex('#ff6b35'))->fillRect(30, 30, 40, 40, Color::rgba(64, 200, 255, 0.7)), 0.8);
    $frames = [];
    foreach (['opengl' => $opengl, 'velvet' => $velvet] as $name => $engine) {
        $frame = $engine->framebuffer(100, 100);
        $painter = $engine->painter($frame);
        for ($i = 0; $i < 6; $i++) {
            $painter->frame($scene($i));
            $frames[$name][$i] = $frame->read();
        }
        $last[$name] = $painter;
    }
    $counts = $inner($last['opengl'])->layerCounts;
    $worst = 0;
    foreach ($frames['opengl'] as $i => $bytes) {
        for ($b = 0, $n = strlen($bytes); $b < $n; $b++) {
            $worst = max($worst, abs(ord($bytes[$b]) - ord($frames['velvet'][$i][$b])));
        }
    }
    echo '     last frame: '.count($last['opengl']->damage())." regions, layer drawn {$counts['drawn']}, reused {$counts['reused']}; worst byte over 6 frames {$worst}\n";

    return $counts['drawn'] === 1 && $counts['reused'] > 0 && $worst <= 1;
});
// filters, backdrops and glass, through the painter, against Velvet's painter. A scene with something to blur under it.
use Surface\Contracts\Graphics\Effects\{Blur as FxBlur, BlurSampling as FxSampling, ColorMatrix as FxMatrix, FilterChain as FxChain, Glass as FxGlass};
$under = function (GEPainter $g): GEPainter {
    $g->clear(Color::hex('#203040'));
    for ($i = 0; $i < 10; $i++) {
        $g->fillRect($i * 10, 0, 5, 100, Color::rgb(($i * 70) % 256, ($i * 130 + 60) % 256, (255 - $i * 25) % 256));
    }

    return $g->fillEllipse(70, 70, 22, 18, Color::hex('#f7f052'))->fillRect(10, 60, 30, 30, Color::rgba(255, 255, 255, 0.5));
};
$blurry = FxChain::of(new FxBlur(FxSampling::GAUSSIAN, 3.0, 3.0));
$scenes = [
    'a layer with a filter' => fn (GEPainter $g) => $under($g)->layer(15, 15, 70, 60, fn (GEPainter $g) => $g->fillRect(5, 5, 40, 30, Color::hex('#ff6b35'))->fillEllipse(45, 35, 15, 15, Color::hex('#40c8ff')), 0.9, null, FxChain::of(new FxBlur(FxSampling::GAUSSIAN, 2.5, 2.5), FxMatrix::saturate(1.5))),
    'a layer with a backdrop' => fn (GEPainter $g) => $under($g)->layer(15, 15, 70, 60, fn (GEPainter $g) => $g->fillRect(0, 0, 70, 60, Color::rgba(255, 255, 255, 0.25)), 1.0, null, null, $blurry),
    'a rounded layer with a filter and a backdrop' => fn (GEPainter $g) => $under($g)->layer(10, 20, 80, 50, fn (GEPainter $g) => $g->fillEllipse(40, 25, 30, 18, Color::rgba(255, 107, 53, 0.8)), 0.8, [12, 12, 12, 12], FxChain::of(FxMatrix::hueRotate(90.0)), FxChain::of(new FxBlur(FxSampling::SPIRAL, 5.0, 5.0))),
    'a layer holding a layer with a backdrop' => fn (GEPainter $g) => $under($g)->layer(5, 5, 90, 90, fn (GEPainter $g) => $g->fillRect(0, 0, 45, 90, Color::hex('#80ff80'))->layer(20, 20, 50, 50, fn (GEPainter $g) => $g->fillRect(0, 0, 50, 50, Color::rgba(0, 0, 0, 0.2)), 1.0, [8, 8, 8, 8], null, $blurry), 0.9),
    'a glass pane' => fn (GEPainter $g) => $under($g)->glass(15, 20, 70, 55, [14, 14, 14, 14], new FxGlass(blur: 6.0)),
    'a glass pane, half frosted, bent, tinted, lit, grained and shadowed' => fn (GEPainter $g) => $under($g)->glass(15, 20, 70, 55, [14, 14, 14, 14], new FxGlass(12.0, Color::rgba(40, 120, 255, 0.3), 1.4, 0.05, Color::rgba(255, 255, 255, 0.6), 6.0, 5.0, 0.5, 0.4, 6.0, Color::rgba(0, 0, 0, 0.5)), 0.9),
    'two glass panes that share a capture' => fn (GEPainter $g) => $under($g)->glass(5, 5, 40, 40, [8, 8, 8, 8], new FxGlass(blur: 4.0))->glass(55, 55, 40, 40, [8, 8, 8, 8], new FxGlass(blur: 10.0)),
    'two glass panes with a shape between them' => fn (GEPainter $g) => $under($g)->glass(5, 5, 50, 50, [8, 8, 8, 8], new FxGlass(blur: 5.0))->fillEllipse(50, 50, 20, 20, Color::hex('#ff0080'))->glass(40, 40, 50, 50, [8, 8, 8, 8], new FxGlass(blur: 5.0)),
    'a glass pane inside a layer' => fn (GEPainter $g) => $under($g)->layer(5, 5, 90, 90, fn (GEPainter $g) => $g->fillRect(0, 0, 90, 45, Color::hex('#ff6b35'))->glass(10, 20, 70, 50, [10, 10, 10, 10], new FxGlass(blur: 5.0)), 0.95),
];
// The same, with nothing antialiased: pixel-aligned rects only, layers without rounded corners. What differs then is
// the filter and glass arithmetic itself (float32 against PHP's doubles), not edge coverage.
$square = function (GEPainter $g): GEPainter {
    $g->clear(Color::hex('#203040'));
    for ($i = 0; $i < 10; $i++) {
        $g->fillRect($i * 10, 0, 5, 100, Color::rgb(($i * 70) % 256, ($i * 130 + 60) % 256, (255 - $i * 25) % 256));
    }

    return $g->fillRect(55, 55, 30, 25, Color::hex('#f7f052'))->fillRect(10, 60, 30, 30, Color::rgba(255, 255, 255, 0.5));
};
$exact = [
    'a layer with a filter' => fn (GEPainter $g) => $square($g)->layer(15, 15, 70, 60, fn (GEPainter $g) => $g->fillRect(5, 5, 40, 30, Color::hex('#ff6b35'))->fillRect(40, 30, 20, 20, Color::hex('#40c8ff')), 0.9, null, FxChain::of(new FxBlur(FxSampling::GAUSSIAN, 2.5, 2.5), FxMatrix::saturate(1.5))),
    'a layer with a backdrop' => fn (GEPainter $g) => $square($g)->layer(15, 15, 70, 60, fn (GEPainter $g) => $g->fillRect(0, 0, 70, 60, Color::rgba(255, 255, 255, 0.25)), 1.0, null, null, $blurry),
    'a layer with a spiral backdrop and a filter' => fn (GEPainter $g) => $square($g)->layer(10, 20, 80, 50, fn (GEPainter $g) => $g->fillRect(10, 10, 50, 30, Color::rgba(255, 107, 53, 0.8)), 0.8, null, FxChain::of(FxMatrix::hueRotate(90.0)), FxChain::of(new FxBlur(FxSampling::SPIRAL, 5.0, 5.0))),
    'a layer holding a layer with a backdrop' => fn (GEPainter $g) => $square($g)->layer(5, 5, 90, 90, fn (GEPainter $g) => $g->fillRect(0, 0, 45, 90, Color::hex('#80ff80'))->layer(20, 20, 50, 50, fn (GEPainter $g) => $g->fillRect(0, 0, 50, 50, Color::rgba(0, 0, 0, 0.2)), 1.0, null, null, $blurry), 0.9),
    'a glass pane' => fn (GEPainter $g) => $square($g)->glass(15, 20, 70, 55, [14, 14, 14, 14], new FxGlass(blur: 6.0)),
    'a glass pane, half frosted, bent, tinted, lit and grained' => fn (GEPainter $g) => $square($g)->glass(15, 20, 70, 55, [14, 14, 14, 14], new FxGlass(12.0, Color::rgba(40, 120, 255, 0.3), 1.4, 0.05, Color::rgba(255, 255, 255, 0.6), 6.0, 5.0, 0.5, 0.4), 0.9),
    'two glass panes that share a capture' => fn (GEPainter $g) => $square($g)->glass(5, 5, 40, 40, [8, 8, 8, 8], new FxGlass(blur: 4.0))->glass(55, 55, 40, 40, [8, 8, 8, 8], new FxGlass(blur: 10.0)),
    'two glass panes with a shape between them' => fn (GEPainter $g) => $square($g)->glass(5, 5, 50, 50, [8, 8, 8, 8], new FxGlass(blur: 5.0))->fillRect(40, 40, 25, 25, Color::hex('#ff0080'))->glass(40, 40, 50, 50, [8, 8, 8, 8], new FxGlass(blur: 5.0)),
    'a glass pane inside a layer' => fn (GEPainter $g) => $square($g)->layer(5, 5, 90, 90, fn (GEPainter $g) => $g->fillRect(0, 0, 90, 45, Color::hex('#ff6b35'))->glass(10, 20, 70, 50, [10, 10, 10, 10], new FxGlass(blur: 5.0)), 0.95),
];
foreach ($exact as $name => $draw) {
    $check("{$name}, nothing antialiased, is Velvet's within 2", function () use ($painted, $draw) {
        [$differ, $worst] = $painted($draw);
        echo "     {$differ} bytes differ, worst {$worst}\n";

        return $worst <= 2;
    });
}
// A frame redrawn in many regions: the shared Painter hands the painter a copy of the filtered layer for each region,
// cut only at its last clip; OpenGL draws its contents once and takes them for the rest. Frame by frame against Velvet.
$check('a filtered layer in a frame redrawn in many regions is drawn once and is Velvet\'s', function () use ($opengl, $velvet, $square) {
    $scene = fn (int $i) => fn (GEPainter $g) => $g->clear(Color::hex('#203040'))
        // four small squares far apart change colour each frame: four regions, each reaching the layer
        ->fillRect(2, 2, 5, 5, Color::rgb(($i * 40) % 256, 0, 0))->fillRect(93, 2, 5, 5, Color::rgb(0, ($i * 40) % 256, 0))
        ->fillRect(2, 93, 5, 5, Color::rgb(0, 0, ($i * 40) % 256))->fillRect(93, 93, 5, 5, Color::rgb(($i * 40) % 256, ($i * 40) % 256, 0))
        ->layer(0, 0, 100, 100, fn (GEPainter $g) => $g->fillRect(10, 10, 40, 40, Color::hex('#ff6b35'))->fillRect(30, 30, 40, 40, Color::rgba(64, 200, 255, 0.7)), 0.8, null, FxChain::of(new FxBlur(FxSampling::GAUSSIAN, 2.5, 2.5)));
    $frames = [];
    foreach (['opengl' => $opengl, 'velvet' => $velvet] as $name => $engine) {
        $frame = $engine->framebuffer(100, 100);
        $painter = $engine->painter($frame);
        for ($i = 0; $i < 6; $i++) {
            $painter->frame($scene($i));
            $frames[$name][$i] = $frame->read();
        }
        $last[$name] = $painter;
    }
    $inner = (fn () => $this->inner)->call($last['opengl']);
    $worst = 0;
    foreach ($frames['opengl'] as $i => $bytes) {
        for ($b = 0, $n = strlen($bytes); $b < $n; $b++) {
            $worst = max($worst, abs(ord($bytes[$b]) - ord($frames['velvet'][$i][$b])));
        }
    }
    echo "     last frame: ".count($last['opengl']->damage())." regions, layer drawn {$inner->layerCounts['drawn']}, reused {$inner->layerCounts['reused']}; worst byte over 6 frames {$worst}\n";

    return $inner->layerCounts['drawn'] === 1 && $inner->layerCounts['reused'] > 0 && $worst <= 1;
});
foreach ($scenes as $name => $draw) {
    $check("{$name} is Velvet's, but for edges", function () use ($painted, $draw) {
        [$differ, $worst] = $painted($draw);
        echo "     {$differ} bytes differ, worst {$worst}\n";

        return $differ < 3000 && $worst <= 128;
    });
}

$check("the painter refuses another engine's framebuffer", function () use ($opengl, $velvet) {
    try {
        $opengl->painter($velvet->framebuffer(4, 4));
    } catch (GraphicsException) {
        return true;
    }

    return false;
});

// A stage that lends an NSView: a bare one, in no window, so nothing is shown on screen. Only where ext-appkit is
// loaded: without it a stage is shown on as pixels.
if (class_exists(NSView::class, false) && class_exists(NSOpenGLContext::class, false)) {
    $stage = new class implements Surface\Contracts\Graphics\ActorStage {
        public NSView $view;

        public int $shown = 0;

        public function __construct()
        {
            $this->view = NSView::initWithFrame(new NSRect(0.0, 0.0, 120.0, 60.0));
        }

        public function pixelSize(): array
        {
            return [240, 120];
        }

        public function pixelScale(): float
        {
            return 2.0;
        }

        public function display(string $rgba8, int $width, int $height): void
        {
            $this->shown++;
        }

        public function nativeSurface(): ?object
        {
            return $this->view;
        }
    };
    $check('on a stage that lends an NSView, frames go to an NSOpenGLView over the view, not through display()', function () use ($opengl, $stage) {
        $frame = $opengl->attach($stage);
        $painter = $opengl->painter($frame);
        for ($i = 0; $i < 5; $i++) {
            $painter->frame(fn (GEPainter $g) => $g->clear(Color::hex('#101820'))->fillEllipse(60 + $i, 30, 20, 20, Color::hex('#ff6b35')));
            $frame->present();
        }
        $again = $opengl->attach($stage);
        $again->present();
        $added = count($stage->view->subviews());
        $opengl->release();

        $read = [bin2hex($frame->read(new Region(4, 4, 1, 1))), bin2hex($frame->read(new Region(64, 30, 1, 1)))];
        echo "     display() calls {$stage->shown}, views added {$added}, left after release ".count($stage->view->subviews()).', pixels '.implode(' ', $read)."\n";

        return $stage->shown === 0 && $added === 1 && count($stage->view->subviews()) === 0 && $read === ['101820ff', 'ff6b35ff'];
    });
} else {
    echo "skip the NSView stage check: ext-appkit is not loaded\n";
}

echo "ok {$ok}, failed {$bad}\n";
exit($bad > 0 ? 1 : 0);
