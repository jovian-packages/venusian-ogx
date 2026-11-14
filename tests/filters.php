<?php
/*
 * G1: each filter step on the GPU (OpenGLFilters) against PHPLandFilters::run(), Velvet's reference, on the same
 * premultiplied pixels. Opens no window.
 *
 *   zhp tests/filters.php /path/to/venusian/topside
 *
 * Steps whose arithmetic is integer in Velvet (Gaussian blurs, flood, table, hash noise) must give Velvet's bytes:
 * compared as straight colour, through the same unpremultiply Velvet's run() ends with, so equal premultiplied
 * results give equal bytes and any difference shows. Steps in floating point are compared as premultiplied colour,
 * within the tolerance written beside each (float32 on the GPU against PHP's doubles).
 * Prints one line a check, then "FILTERS-OK <n> checks" only when every check passed.
 */
use Jovian\Engines\OpenGL\OpenGLContext;
use Jovian\Engines\OpenGL\OpenGLFilters;
use Surface\Contracts\Graphics\Backend;
use Surface\Contracts\Rendering\Framebuffers\Region;
use Surface\Contracts\Graphics\Effects\{Blend, BlendMode, Blur, BlurSampling, Channel, ColorMatrix, Composite, CompositeOperator, Displace, EdgeMode, Field, FieldKind, FilterChain, Flood, Noise, NoiseType, Table};
use Surface\Contracts\Rendering\Framebuffers\FormatSpec;
use Surface\NutsAndBolts\Color;
use Surface\VelvetGE\Framebuffers\PHPLand\PHPLandFilters;

$topside = $argv[1] ?? throw new RuntimeException('Give the path to venusian/topside.');
$loader = require "{$topside}/vendor/autoload.php";
$loader->addPsr4('Jovian\\Engines\\OpenGL\\', dirname(__DIR__).'/src');
require "{$topside}/tests/Pest.php";
require_once "{$topside}/tests/Fixtures/FakeManagers.php";

[$w, $h, $ox, $oy] = [37, 23, 5, 7];
mt_srand(11);
$image = '';
for ($i = 0; $i < $w * $h; $i++) {
    $a = [0, 255, 255, 128, mt_rand(1, 254)][mt_rand(0, 4)];
    $image .= chr(intdiv(mt_rand(0, 255) * $a + 127, 255)).chr(intdiv(mt_rand(0, 255) * $a + 127, 255)).chr(intdiv(mt_rand(0, 255) * $a + 127, 255)).chr($a);
}
$map = framebuffers(['rendering.framebuffer.default' => 'native'])->driver()->full(FormatSpec::rgba8(), 20, 30);
$mapBytes = '';
for ($i = 0; $i < 600; $i++) {
    $mapBytes .= chr(mt_rand(0, 255)).chr(mt_rand(0, 255)).chr(mt_rand(0, 255)).chr(mt_rand(0, 255));
}
$map->writeRgba8($mapBytes, 20, 30);

$device = new OpenGLContext(Backend::GPU);
$filters = new OpenGLFilters($device);
$straight = function (string $premultiplied): string {
    $out = '';
    foreach (str_split($premultiplied, 4) as $p) {
        $a = ord($p[3]);
        $out .= $a === 0 ? "\0\0\0\0" : chr(min(255, intdiv(ord($p[0]) * 255 + intdiv($a, 2), $a))).chr(min(255, intdiv(ord($p[1]) * 255 + intdiv($a, 2), $a))).chr(min(255, intdiv(ord($p[2]) * 255 + intdiv($a, 2), $a))).chr($a);
    }

    return $out;
};
$premultiply = fn (string $straight): string => implode('', array_map(fn (string $p): string => chr(intdiv(ord($p[0]) * ord($p[3]) + 127, 255)).chr(intdiv(ord($p[1]) * ord($p[3]) + 127, 255)).chr(intdiv(ord($p[2]) * ord($p[3]) + 127, 255)).$p[3], str_split($straight, 4)));
$gpu = function (FilterChain $chain) use ($device, $filters, $image, $w, $h, $ox, $oy): string {
    $source = $filters->upload($image, $w, $h);
    $result = $filters->run($chain, $source, $w, $h, $ox, $oy);
    glBindFramebuffer(GL_READ_FRAMEBUFFER, $filters->framebufferOf($result));
    $bytes = $device->readPixels(new Region(0, 0, $w, $h));
    $filters->release();

    return $bytes;
};
[$ok, $bad] = [0, 0];
/** @param int|null $tolerance null: exact, as straight colour; a number: premultiplied colour within it */
$check = function (string $name, FilterChain $chain, ?int $tolerance) use (&$ok, &$bad, $gpu, $straight, $premultiply, $image, $w, $h, $ox, $oy): void {
    try {
        $mine = $gpu($chain);
        $velvet = PHPLandFilters::run($chain, $image, $w, $h, $ox, $oy, true);
        [$a, $b] = is_null($tolerance) ? [$straight($mine), $velvet] : [$mine, $premultiply($velvet)];
        [$differ, $worst] = [0, 0];
        for ($i = 0, $n = strlen($a); $i < $n; $i++) {
            $d = abs(ord($a[$i]) - ord($b[$i]));
            [$differ, $worst] = [$differ + ($d > 0 ? 1 : 0), max($worst, $d)];
        }
        $passed = $worst <= ($tolerance ?? 0);
        $note = sprintf('%d of %d bytes differ, worst %d; allowed %s', $differ, strlen($a), $worst, is_null($tolerance) ? 'none (exact)' : $tolerance);
    } catch (Throwable $e) {
        [$passed, $note] = [false, get_class($e).': '.$e->getMessage()];
    }
    $passed ? $ok++ : $bad++;
    echo $passed ? 'ok   ' : 'FAIL ', $name, ' — ', $note, "\n";
};

$g = BlurSampling::GAUSSIAN;
$check('Gaussian blur, deviation 3, edges transparent', FilterChain::of(new Blur($g, 3.0, 3.0)), null);
$check('Gaussian blur, deviation 2.5 by 4, edges held', FilterChain::of(new Blur($g, 2.5, 4.0, edge: EdgeMode::DUPLICATE)), null);
$check('Gaussian blur, deviation 6 across only', FilterChain::of(new Blur($g, 6.0, 0.0)), null);
$check('Gaussian blur, small kernel, deviation 1.2', FilterChain::of(new Blur($g, 1.2, 1.2)), null);
$check('Gaussian blur, small kernel 0.7, edges held', FilterChain::of(new Blur($g, 0.7, 1.9, edge: EdgeMode::DUPLICATE)), null);
$check('spiral blur, radius 4, 16 samples', FilterChain::of(new Blur(BlurSampling::SPIRAL, 4.0, 4.0)), 2);
$check('spiral blur, radius 7.5, 24 samples, edges held', FilterChain::of(new Blur(BlurSampling::SPIRAL, 7.5, 7.5, 24, EdgeMode::DUPLICATE)), 2);
$check('colour matrix: saturate 0.3', FilterChain::of(ColorMatrix::saturate(0.3)), 1);
$check('colour matrix: hue rotate 40', FilterChain::of(ColorMatrix::hueRotate(40.0)), 1);
$check('colour matrix: luminance to alpha', FilterChain::of(ColorMatrix::luminanceToAlpha()), 1);
$check('colour matrix: brightness 1.3 + 0.05', FilterChain::of(ColorMatrix::brightness(1.3, 0.05)), 1);
$check('flood', FilterChain::of(new Flood(Color::rgba(40, 200, 120, 0.4))), null);
$check('table: three channels and alpha', FilterChain::of(new Table(array_map(fn ($i) => 255 - $i, range(0, 255)), null, array_map(fn ($i) => intdiv($i * $i, 255), range(0, 255)), array_map(fn ($i) => min(255, $i + 30), range(0, 255)))), null);
foreach (CompositeOperator::cases() as $operator) {
    $check("composite {$operator->name}", FilterChain::of(new Blur($g, 2.0, 2.0, result: 'soft'), new Composite('soft', $operator, 0.5, 0.3, 0.4, 0.05, in: 'source')), 1);
}
foreach (BlendMode::cases() as $mode) {
    $check("blend {$mode->name}", FilterChain::of(new Flood(Color::rgba(200, 80, 30, 0.6), result: 'paint'), new Blend('paint', $mode, in: 'source')), 1);
}
$check('hash noise', FilterChain::of(new Noise(NoiseType::HASH, seed: 9)), null);
$check('turbulence, 2 octaves', FilterChain::of(new Noise(NoiseType::TURBULENCE, 0.05, 0.08, 2, 4)), 2);
$check('fractal noise, 3 octaves, stitched', FilterChain::of(new Noise(NoiseType::FRACTAL, 0.07, 0.07, 3, 17, true)), 2);
$check('field: rounded box', FilterChain::of(new Field(FieldKind::ROUNDED_BOX, [10.0, 9.0, 35.0, 26.0], [6.0, 2.0, 0.0, 9.0], -4.0, 4.0)), 1);
$check('field: inside', FilterChain::of(new Field(FieldKind::INSIDE, [10.0, 9.0, 35.0, 26.0], [6.0, 2.0, 0.0, 9.0])), 1);
$check('field: ramp', FilterChain::of(new Field(FieldKind::RAMP, null, from: 8.0, to: 30.0)), 1);
$check('displace by a framebuffer, one band', FilterChain::of(new Displace($map, 9.0, Channel::R, Channel::B)), 2);
$check('displace by a framebuffer, five bands, spread', FilterChain::of(new Displace($map, 7.0, Channel::G, Channel::A, 5, 0.4)), 2);
$check('displace by a named map both engines make exactly', FilterChain::of(new Field(FieldKind::ROUNDED_BOX, [6.0, 4.0, 40.0, 30.0], [8.0, 8.0, 8.0, 8.0], -10.0, 10.0, 'shape'), new Displace('shape', 8.0, Channel::G, Channel::B, in: 'source')), null);

// Turbulence on the GPU is float32 against PHP's doubles: a map byte can be 1 off. Where the map's alpha is low, its
// straight colour moves far for 1 step, and so does the displacement. So the check here is the cause, not a bound:
// every displaced pixel that differs lies where the turbulence map itself differs.
(function () use (&$ok, &$bad, $gpu, $premultiply, $image, $w, $h, $ox, $oy): void {
    $pixels = function (string $a, string $b): array {
        $p = [];
        for ($i = 0, $n = strlen($a); $i < $n; $i += 4) {
            if (substr($a, $i, 4) !== substr($b, $i, 4)) {
                $p[intdiv($i, 4)] = true;
            }
        }

        return $p;
    };
    $turbulence = FilterChain::of(new Noise(NoiseType::TURBULENCE, 0.08, 0.08, 1, 2));
    $chain = FilterChain::of(new Noise(NoiseType::TURBULENCE, 0.08, 0.08, 1, 2, result: 'waves'), new Displace('waves', 6.0, in: 'source'));
    $map = $pixels($gpu($turbulence), $premultiply(PHPLandFilters::run($turbulence, $image, $w, $h, $ox, $oy, true)));
    $out = $pixels($gpu($chain), $premultiply(PHPLandFilters::run($chain, $image, $w, $h, $ox, $oy, true)));
    $elsewhere = count(array_diff_key($out, $map));
    $passed = $elsewhere === 0;
    $passed ? $ok++ : $bad++;
    echo $passed ? 'ok   ' : 'FAIL ', 'displace by a named turbulence map — ', count($out), ' pixels differ, ', count($out) - $elsewhere, ' of them where the map differs, ', $elsewhere, " elsewhere (allowed 0)\n";
})();
$check('a chain of named results', FilterChain::of(new Blur($g, 3.0, 3.0, result: 'blurred'), ColorMatrix::saturate(1.6, 'blurred', 'vivid'), new Flood(Color::rgba(0, 0, 0, 0.5), result: 'dark'), new Composite('dark', CompositeOperator::IN, in: 'vivid', result: 'shadowed'), new Blend('shadowed', BlendMode::SCREEN, in: 'source')), 2);

echo $bad === 0 ? "FILTERS-OK {$ok} checks\n" : "FILTERS-FAILED {$bad} of ".($ok + $bad)."\n";
exit($bad === 0 ? 0 : 1);
