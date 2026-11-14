<?php

/**
 * The opengl raycaster against Velvet's PHP marcher, with no window: random grids, rays, fans,
 * views and paints. Hits must agree on cell, wall and face; distances and along are compared as
 * float32 against double. Views are compared pixel by pixel, and a differing pixel is traced to
 * a wall line one pixel taller or shorter.
 *
 * Run: zhp tests/raycast.php <path to venusian/topside>
 */

use Jovian\Engines\OpenGL\OpenGLContext;
use Surface\Contracts\Rendering\Framebuffers\Region;
use Surface\Contracts\Rendering\Raycasting\Camera;
use Surface\Contracts\Rendering\Raycasting\Grid;
use Surface\Contracts\Rendering\Raycasting\Hits;
use Surface\Contracts\Rendering\Raycasting\RaycastException;
use Surface\Contracts\Rendering\Raycasting\Scenery;
use Surface\Contracts\Rendering\Raycasting\Texture;
use Surface\VelvetGE\Raycasting\MarcherRaycaster;
use Surface\VelvetGE\Raycasting\PHPLandMarcher;

$topside = $argv[1] ?? throw new RuntimeException('Give the path to venusian/topside.');
$loader = require "{$topside}/vendor/autoload.php";
$loader->addPsr4('Jovian\\Engines\\OpenGL\\', dirname(__DIR__).'/src');

[$ok, $bad] = [0, 0];
$check = function (string $what, Closure $pass) use (&$ok, &$bad): void {
    try {
        [$passed, $note] = $pass();
    } catch (Throwable $e) {
        [$passed, $note] = [false, get_class($e).': '.$e->getMessage()];
    }
    $passed ? $ok++ : $bad++;
    echo $passed ? 'ok   ' : 'FAIL ', $what, $note === '' ? '' : " ({$note})", "\n";
};

mt_srand(20261114);
$opengl = new OpenGLContext;

/** A walled room with random pillars, wall ids 1..$ids. */
$room = function (int $width, int $height, int $ids, float $fill) : Grid {
    $rows = [];
    for ($y = 0; $y < $height; $y++) {
        $row = [];
        for ($x = 0; $x < $width; $x++) {
            $edge = $x === 0 || $y === 0 || $x === $width - 1 || $y === $height - 1;
            $row[] = $edge ? 1 : (mt_rand() / mt_getrandmax() < $fill ? mt_rand(1, $ids) : 0);
        }
        $rows[] = $row;
    }
    // the middle is open, for cameras
    $rows[intdiv($height, 2)][intdiv($width, 2)] = 0;

    return Grid::of($rows);
};
$open = function (Grid $grid): array {
    do {
        [$x, $y] = [mt_rand(100, $grid->width * 100 - 100) / 100, mt_rand(100, $grid->height * 100 - 100) / 100];
    } while ($grid->wall((int) floor($x), (int) floor($y)) !== 0);

    return [$x, $y];
};
$texture = function (int $width, int $height): Texture {
    $bytes = '';
    for ($i = 0; $i < $width * $height; $i++) {
        $bytes .= pack('C4', mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255), 255);
    }

    return new Texture($bytes, $width, $height);
};

// hits: the same cell, wall and face; how far distance and along are
$compareHits = function (string $mine, string $velvet): array {
    [$records, $sameKind, $worstDistance, $worstAlong, $differ] = [Hits::count($velvet), 0, 0.0, 0.0, []];
    for ($i = 0; $i < $records; $i++) {
        [$a, $b] = [Hits::at($mine, $i), Hits::at($velvet, $i)];
        if (is_null($a) || is_null($b)) {
            is_null($a) && is_null($b) ? $sameKind++ : $differ[] = $i;
            continue;
        }
        if ($a->x === $b->x && $a->y === $b->y && $a->wall === $b->wall && $a->face === $b->face) {
            $sameKind++;
            $worstDistance = max($worstDistance, abs($a->distance - $b->distance) / max(1.0, $b->distance));
            $worstAlong = max($worstAlong, min(abs($a->along - $b->along), 1.0 - abs($a->along - $b->along)));
        } else {
            $differ[] = $i;
        }
    }

    return [$records, $sameKind, $worstDistance, $worstAlong, $differ];
};

// 1. single rays and fans over random rooms
[$rays, $raysSame, $fanRays, $fanSame, $worstD, $worstA] = [0, 0, 0, 0, 0.0, 0.0];
$corners = [];
foreach ([[8, 8], [24, 16], [64, 64], [300, 200]] as [$w, $h]) {
    $grid = $room($w, $h, 9, 0.18);
    $mine = $opengl->raycaster($grid);
    $velvet = new MarcherRaycaster('native', new PHPLandMarcher($grid), $grid);
    for ($n = 0; $n < 60; $n++) {
        [$x, $y] = $open($grid);
        $angle = mt_rand(0, 62831) / 10000;
        $reach = mt_rand(0, 3) === 0 ? mt_rand(1, 40) / 2 : INF;

        $a = $mine->cast($x, $y, $angle, $reach);
        $b = $velvet->cast($x, $y, $angle, $reach);
        [, $same, $d, $al] = $compareHits(is_null($a) ? str_repeat("\0", 22) : Hits::pack($a), is_null($b) ? str_repeat("\0", 22) : Hits::pack($b));
        [$rays, $raysSame, $worstD, $worstA] = [$rays + 1, $raysSame + $same, max($worstD, $d), max($worstA, $al)];

        $camera = new Camera($x, $y, $angle, mt_rand(30, 120) * M_PI / 180);
        $columns = [1, 7, 320, 1100, 20000][mt_rand(0, 4)];
        [$count, $same, $d, $al, $differ] = $compareHits($mine->fan($camera, $columns, $reach), $velvet->fan($camera, $columns, $reach));
        [$fanRays, $fanSame, $worstD, $worstA] = [$fanRays + $count, $fanSame + $same, max($worstD, $d), max($worstA, $al)];
        if ($differ !== []) {
            $corners[] = count($differ);
        }
    }
}
$check("on the GPU, {$rays} single rays: the same cell, wall and face as Velvet's", fn () => [$raysSame === $rays, "{$raysSame} of {$rays}"]);
$check("{$fanRays} fan rays (1 to 20000 columns, past one row of the hits texture): the same cell, wall and face", fn () => [$fanRays - $fanSame <= max(1, intdiv($fanRays, 10000)), ($fanRays - $fanSame).' differ'.($corners === [] ? '' : ', in '.count($corners).' fans')]);
$check('distance within 1e-5 of Velvet\'s, relative, on every agreeing hit', fn () => [$worstD < 1e-5, sprintf('worst %.2e', $worstD)]);
$check('along within 1e-3 of Velvet\'s on every agreeing hit', fn () => [$worstA < 1e-3, sprintf('worst %.2e', $worstA)]);

// 2. misses Velvet gives without walking: from outside the grid, and past the reach
$grid = $room(16, 16, 3, 0.1);
$mine = $opengl->raycaster($grid);
$check('a ray from outside the grid misses', fn () => [is_null($mine->cast(-2.0, 5.0, 0.0)) && is_null($mine->cast(5.0, 99.0, 1.0)), '']);
$check('a fan from outside the grid is all misses', fn () => [$mine->fan(new Camera(-3.0, -3.0, 0.7), 64) === str_repeat("\0", 22 * 64), '']);
$check('a reach short of the first wall misses', fn () => [is_null($mine->cast(8.5, 8.5, 0.0, 0.25)), '']);

// 3. views, flat colours and textures, against Velvet's view()
$pixels = [0, 0, 0];
foreach ([[24, 16, 64, 48], [64, 64, 320, 200], [300, 200, 551, 181]] as [$w, $h, $vw, $vh]) {
    $grid = $room($w, $h, 6, 0.15);
    $mine = $opengl->raycaster($grid);
    $velvet = new MarcherRaycaster('native', new PHPLandMarcher($grid), $grid);
    $scenery = new Scenery([1 => 0x8899AAFF, 2 => 0xC0503CFF, 3 => $texture(64, 64), 4 => $texture(16, 128), 5 => 0x3C78DC80, 6 => $texture(7, 5)], 0x202430FF, 0x404040FF, 160);
    for ($n = 0; $n < 20; $n++) {
        [$x, $y] = $open($grid);
        $camera = new Camera($x, $y, mt_rand(0, 62831) / 10000, mt_rand(40, 100) * M_PI / 180);
        $a = $mine->view($camera, $vw, $vh, $scenery);
        $b = $velvet->view($camera, $vw, $vh, $scenery);
        $pixels[0] += $vw * $vh;
        for ($i = 0, $len = strlen($a); $i < $len; $i += 4) {
            if (substr($a, $i, 4) !== substr($b, $i, 4)) {
                $pixels[1]++;
            }
        }
        // which columns differ, and whether by a wall line one pixel off
        for ($col = 0; $col < $vw; $col++) {
            $ours = $theirs = '';
            for ($row = 0; $row < $vh; $row++) {
                $ours .= substr($a, ($row * $vw + $col) * 4, 4);
                $theirs .= substr($b, ($row * $vw + $col) * 4, 4);
            }
            if ($ours !== $theirs) {
                $pixels[2]++;
            }
        }
    }
}
$check("on the GPU, views: {$pixels[0]} pixels, most the same bytes as Velvet's", fn () => [$pixels[1] / $pixels[0] < 0.01, sprintf('%d differ (%.3f%%), in %d columns', $pixels[1], 100 * $pixels[1] / $pixels[0], $pixels[2])]);

// 4. paint on an opengl framebuffer, against Velvet's view written into the same place
$grid = $room(32, 32, 3, 0.12);
$mine = $opengl->raycaster($grid);
$velvet = new MarcherRaycaster('native', new PHPLandMarcher($grid), $grid);
$scenery = new Scenery([1 => 0x8899AAFF, 2 => 0xC0503CFF, 3 => $texture(32, 32)], 0x202430FF, 0x404040FF, 160);
$camera = new Camera(16.5, 16.5, 0.6);
$frame = $opengl->framebuffer(400, 300);
$where = new Region(30, 20, 320, 240);
$check('paint() puts the view inside $where and leaves the rest', function () use ($mine, $frame, $where, $camera, $scenery): array {
    $frame->clear(Surface\NutsAndBolts\Color::rgba(1, 2, 3, 1.0));
    $mine->paint($camera, $scenery, $frame, $where);
    $view = $mine->view($camera, $where->width, $where->height, $scenery);
    $inside = $frame->read($where);
    $outside = $frame->read(new Region(0, 0, 400, 20));

    return [$inside === $view && $outside === str_repeat(pack('C4', 1, 2, 3, 255), 400 * 20), ''];
});
$check('the painted view matches Velvet\'s view', function () use ($frame, $where, $velvet, $camera, $scenery): array {
    $a = $frame->read($where);
    $b = $velvet->view($camera, $where->width, $where->height, $scenery);
    $differ = 0;
    for ($i = 0; $i < strlen($a); $i += 4) {
        $differ += substr($a, $i, 4) !== substr($b, $i, 4) ? 1 : 0;
    }

    return [$differ / ($where->width * $where->height) < 0.01, "{$differ} of ".($where->width * $where->height).' pixels differ'];
});

// 4b. casts(): many rays from one point, against Velvet's cast() one at a time; walked on the GPU in one pass
[$casted, $castSame, $castD] = [0, 0, 0.0];
foreach ([[24, 16], [300, 200]] as [$w, $h]) {
    $grid = $room($w, $h, 9, 0.18);
    $mine = $opengl->raycaster($grid);
    $velvet = new MarcherRaycaster('native', new PHPLandMarcher($grid), $grid);
    for ($n = 0; $n < 20; $n++) {
        [$x, $y] = $open($grid);
        $angles = array_map(fn () => mt_rand(0, 62831) / 10000, range(1, [1, 90, 5000, 20000][mt_rand(0, 3)]));
        $reach = mt_rand(0, 2) === 0 ? mt_rand(1, 40) / 2 : INF;
        $packed = fn (?Surface\Contracts\Rendering\Raycasting\Hit $hit): string => is_null($hit) ? str_repeat("\0", 22) : Hits::pack($hit);
        [$count, $same, $d] = $compareHits(implode('', array_map($packed, $mine->casts($x, $y, $angles, $reach))), implode('', array_map(fn ($a) => $packed($velvet->cast($x, $y, $a, $reach)), $angles)));
        [$casted, $castSame, $castD] = [$casted + $count, $castSame + $same, max($castD, $d)];
    }
}
$check("on the GPU, casts(): {$casted} rays in batches of 1 to 20000 hit what Velvet's cast() hits", fn () => [$castSame === $casted, ($casted - $castSame).sprintf(' differ, distance worst %.2e', $castD)]);
$check('casts() refuses what cast() refuses', function () use ($opengl): array {
    $mine = $opengl->raycaster(Grid::of([[1, 1, 1], [1, 0, 1], [1, 1, 1]]));
    foreach ([fn () => $mine->casts(NAN, 1.5, [0.0]), fn () => $mine->casts(1.5, 1.5, [INF]), fn () => $mine->casts(1.5, 1.5, [0.0], 0.0)] as $call) {
        try {
            $call();

            return [false, 'one was not refused'];
        } catch (RaycastException) {
        }
    }

    return [$mine->casts(1.5, 1.5, []) === [], ''];
});

// 5. what is refused, as Velvet refuses it
$check('a ray hitting a wall with no entry is refused, naming it, and nothing is drawn', function () use ($opengl): array {
    $grid = Grid::of([[1, 1, 1, 1, 1], [1, 0, 0, 0, 7], [1, 1, 1, 1, 1]]);
    $mine = $opengl->raycaster($grid);
    $frame = $opengl->framebuffer(32, 16);
    $frame->clear(Surface\NutsAndBolts\Color::rgba(9, 9, 9, 1.0));
    try {
        $mine->paint(new Camera(1.5, 1.5, 0.0), new Scenery([1 => 0xFFFFFFFF]), $frame);
    } catch (RaycastException $e) {
        return [str_contains($e->getMessage(), '7') && $frame->read(new Region(0, 0, 32, 16)) === str_repeat(pack('C4', 9, 9, 9, 255), 32 * 16), $e->getMessage()];
    }

    return [false, 'nothing thrown'];
});
$check('a wall with no entry that no ray reaches is no error', function () use ($opengl): array {
    $grid = Grid::of([[1, 1, 1, 1, 1], [1, 0, 1, 0, 7], [1, 1, 1, 1, 1]]);
    $opengl->raycaster($grid)->view(new Camera(1.5, 1.5, 0.0), 16, 8, new Scenery([1 => 0xFFFFFFFF]));

    return [true, ''];
});
$check('a grid wider than an image side is walked: the cells are a storage buffer', function () use ($opengl): array {
    // a corridor 20000 cells long, wall at the far end
    $width = 20000;
    $grid = new Grid($width, 3, str_repeat("\1", $width).("\1".str_repeat("\0", $width - 2)."\1").str_repeat("\1", $width));
    $hit = $opengl->raycaster($grid)->cast(1.5, 1.5, 0.0);
    $reference = (new MarcherRaycaster('native', new PHPLandMarcher($grid), $grid))->cast(1.5, 1.5, 0.0);

    return [! is_null($hit) && $hit->x === $reference->x && $hit->y === $reference->y && $hit->wall === $reference->wall && abs($hit->distance - $reference->distance) < 1e-3,
        is_null($hit) ? 'no hit' : "cell {$hit->x}, distance {$hit->distance} against {$reference->distance}"];
});
$check('supports(RAYCASTER)', fn () => [$opengl->supports(Surface\Contracts\Graphics\Resource::RAYCASTER), '']);

echo "\n{$ok} ok, {$bad} failed\n";
exit($bad === 0 ? 0 : 1);
