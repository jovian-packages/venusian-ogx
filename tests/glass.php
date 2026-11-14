<?php
/*
 * G2 and G7: the glass pyramid and panes on the GPU against Velvet's glass. Opens no window.
 *
 *   zhp tests/glass.php /path/to/venusian/topside
 *
 * G2: each Kawase level down and up is byte-exact with PHPLandGlass on the same straight pixels, and a pane drawn
 *     over pixel-aligned content gives the bytes of Velvet's PHP painter (which paints panes with PHPLandGlass::pane).
 * G7: a frame builds a pyramid only for a pane it redraws, and the pane is right after a change under it.
 * Prints one line a check, then "GLASS-OK <n> checks" and "PYRAMID-REDRAW-OK" only when every check passed.
 */
use Jovian\Engines\OpenGL\OpenGLContext;
use Jovian\Engines\OpenGL\OpenGLFilters;
use Surface\Contracts\Graphics\Backend;
use Surface\Contracts\Graphics\Effects\Glass;
use Surface\Contracts\Rendering\Framebuffers\Region;
use Surface\Graphics\GEPainter;
use Surface\NutsAndBolts\Color;
use Surface\VelvetGE\Framebuffers\PHPLand\PHPLandGlass;
use Surface\VelvetGE\Recording\PHPLandQueue;
use Surface\VelvetGE\Recording\VelvetRecorder;
use Surface\VelvetGE\VelvetDevice;

$topside = $argv[1] ?? throw new RuntimeException('Give the path to venusian/topside.');
$loader = require "{$topside}/vendor/autoload.php";
$loader->addPsr4('Jovian\\Engines\\OpenGL\\', dirname(__DIR__).'/src');
require "{$topside}/tests/Pest.php";
require_once "{$topside}/tests/Fixtures/FakeManagers.php";

$device = new OpenGLContext(Backend::GPU, recorder: fn () => new VelvetRecorder(new PHPLandQueue()));
$velvet = new VelvetDevice(Backend::CPU, framebuffers(['rendering.framebuffer.default' => 'native']), rasterize(), painters(), null, ['rasterizer' => 'native', 'framebuffer' => 'native', 'painter' => 'native']);
[$ok, $bad] = [0, 0];
$check = function (string $what, Closure $pass) use (&$ok, &$bad): bool {
    try {
        [$passed, $note] = [(bool) $pass(), ''];
    } catch (Throwable $e) {
        [$passed, $note] = [false, ' ('.get_class($e).': '.$e->getMessage().')'];
    }
    $passed ? $ok++ : $bad++;
    echo $passed ? 'ok   ' : 'FAIL ', $what, $note, "\n";

    return $passed;
};
$read = function (OpenGLFilters $filters, int $texture, int $w, int $h) use ($device): string {
    glBindFramebuffer(GL_READ_FRAMEBUFFER, $filters->framebufferOf($texture));

    return $device->readPixels(new Region(0, 0, $w, $h));
};
$distance = function (string $a, string $b): array {
    [$differ, $worst] = [0, 0];
    for ($i = 0, $n = strlen($a); $i < $n; $i++) {
        $d = abs(ord($a[$i]) - ord($b[$i]));
        [$differ, $worst] = [$differ + ($d > 0 ? 1 : 0), max($worst, $d)];
    }

    return [$differ, $worst];
};

// G2: the pyramid, level by level, on odd sizes
mt_srand(5);
foreach ([[45, 31], [64, 64], [17, 90]] as [$w, $h]) {
    $image = '';
    for ($i = 0; $i < $w * $h * 4; $i++) {
        $image .= chr(mt_rand(0, 255));
    }
    $check("Kawase levels of a {$w}x{$h} image, down 4 and up again, are PHPLandGlass's bytes", function () use ($device, $read, $distance, $image, $w, $h) {
        $filters = new OpenGLFilters($device);
        $gpu = [[$filters->upload($image, $w, $h), $w, $h]];
        $php = [[$image, $w, $h]];
        for ($k = 1; $k <= 4; $k++) {
            [$t, $sw, $sh] = $gpu[$k - 1];
            $gpu[$k] = [$filters->kawaseDown($t, $sw, $sh), intdiv($sw + 1, 2), intdiv($sh + 1, 2)];
            $php[$k] = [PHPLandGlass::down($php[$k - 1][0], $sw, new Region(0, 0, $sw, $sh)), intdiv($sw + 1, 2), intdiv($sh + 1, 2)];
        }
        $up = [$gpu[4]];
        $phpUp = [$php[4]];
        for ($k = 3; $k >= 1; $k--) {
            [, $dw, $dh] = $gpu[$k];
            [$t, $sw, $sh] = end($up);
            $up[] = [$filters->kawaseUp($t, $sw, $sh, $dw, $dh), $dw, $dh];
            [$p, $psw, $psh] = end($phpUp);
            $phpUp[] = [PHPLandGlass::up($p, $psw, $psh, $dw, $dh), $dw, $dh];
        }
        $levels = 0;
        foreach ([[$gpu, $php], [$up, $phpUp]] as [$mine, $theirs]) {
            foreach ($mine as $i => [$t, $lw, $lh]) {
                if ($i === 0 && $mine === $gpu) {
                    continue;
                }
                [$differ] = $distance($read($filters, $t, $lw, $lh), $theirs[$i][0]);
                if ($differ !== 0) {
                    echo "     level {$i} ({$lw}x{$lh}): {$differ} bytes differ\n";

                    return false;
                }
                $levels++;
            }
        }
        $filters->release();
        echo "     {$levels} levels, every byte equal\n";

        return true;
    });
}

// G2: panes over pixel-aligned content, through each painter
$square = function (GEPainter $g): GEPainter {
    $g->clear(Color::hex('#203040'));
    for ($i = 0; $i < 16; $i++) {
        $g->fillRect($i * 8, 0, 4, 120, Color::rgb(($i * 70) % 256, ($i * 130 + 60) % 256, (255 - $i * 15) % 256));
    }

    return $g->fillRect(60, 60, 40, 30, Color::hex('#f7f052'))->fillRect(10, 70, 40, 40, Color::rgba(255, 255, 255, 0.5));
};
$painted = function (Closure $draw) use ($device, $velvet, $distance): array {
    $out = [];
    foreach (['vulkan' => $device, 'velvet' => $velvet] as $name => $engine) {
        $frame = $engine->framebuffer(128, 120);
        $engine->painter($frame)->frame($draw);
        $out[$name] = $frame->read();
    }

    return $distance($out['vulkan'], $out['velvet']);
};
$panes = [
    'blur 2 (one level)' => new Glass(blur: 2.0),
    'blur 6 (two levels)' => new Glass(blur: 6.0),
    'blur 12 (three levels)' => new Glass(blur: 12.0),
    'blur 30 (four levels)' => new Glass(blur: 30.0),
    'frost 0.3 (the sharp capture mixed in)' => new Glass(blur: 8.0, frost: 0.3),
    'bent by refraction 6 over a bevel of 10' => new Glass(blur: 8.0, bevel: 10.0, refraction: 6.0),
    'tinted, saturated, lit, rimmed and grained' => new Glass(10.0, Color::rgba(255, 60, 120, 0.35), 0.4, 0.08, Color::rgba(255, 255, 255, 0.7), 5.0, 2.0, 0.8),
];
foreach ($panes as $name => $glass) {
    $check("a pane, {$name}, is Velvet's PHP painter's bytes", function () use ($painted, $square, $glass) {
        [$differ, $worst] = $painted(fn (GEPainter $g) => $square($g)->glass(12, 15, 100, 90, [16, 4, 20, 9], $glass, 0.85));
        echo "     {$differ} bytes differ, worst {$worst}\n";

        return $differ === 0;
    });
}
$g2 = $bad === 0;

// G7: pyramids are built only for panes a frame redraws
$check('a frame builds a pyramid only for a pane it redraws, and the pane is right after a change under it', function () use ($device, $velvet, $distance) {
    $scene = fn (string $far, string $under) => fn (GEPainter $g) => $g->clear(Color::hex('#203040'))->fillRect(10, 10, 60, 60, Color::hex($under))->fillRect(170, 70, 20, 20, Color::hex($far))->glass(5, 5, 80, 80, [10, 10, 10, 10], new Glass(blur: 6.0));
    $frame = $device->framebuffer(200, 100);
    $painter = $device->painter($frame);
    $inner = (fn () => $this->inner)->call($painter);
    $reference = $velvet->framebuffer(200, 100);
    $velvetPainter = $velvet->painter($reference);
    $expected = ['first frame' => 1, 'a change away from the pane' => 0, 'a change under the pane' => 1];
    $steps = [['first frame', '#ff0000', '#00ff00'], ['a change away from the pane', '#0000ff', '#00ff00'], ['a change under the pane', '#0000ff', '#ffff00']];
    foreach ($steps as [$name, $far, $under]) {
        $inner->pyramidsBuilt = 0;
        $painter->frame($scene($far, $under));
        $velvetPainter->frame($scene($far, $under));
        [$differ] = $distance($frame->read(), $reference->read());
        echo "     {$name}: {$inner->pyramidsBuilt} built (expected {$expected[$name]}), {$differ} bytes differ from Velvet\n";
        if ($inner->pyramidsBuilt !== $expected[$name] || $differ !== 0) {
            return false;
        }
    }

    return true;
});
$g7 = $bad === 0;

echo $g2 ? "GLASS-OK {$ok} checks\n" : "GLASS-FAILED\n";
echo $g7 ? "PYRAMID-REDRAW-OK\n" : "PYRAMID-REDRAW-FAILED\n";
exit($bad === 0 ? 0 : 1);
