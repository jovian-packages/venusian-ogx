<?php

namespace Jovian\Engines\OpenGL;

use Surface\Contracts\Rendering\Framebuffers\Region;
use Surface\Contracts\Rendering\Raycasting\Camera;
use Surface\Contracts\Rendering\Raycasting\Grid;
use Surface\Contracts\Rendering\Raycasting\RaycastException;
use Surface\Contracts\Rendering\Raycasting\Scenery;
use Surface\Rendering\Raycasting\Raycaster;
use WeakMap;

/**
 * The opengl engine's raycaster. Rendering checks every call and turns angles into vectors, in double,
 * as it does for every driver. Then:
 *
 *  - Rays are walked by a pass over a hits target, one pixel a ray's row (raycast_hits.frag): OpenGL 4.1
 *    has no compute.
 *  - A view painted on a framebuffer stays on the GPU: the view is drawn from the hits
 *    (raycast_view.frag), and nothing comes back, so the CPU does not wait.
 *  - What comes back to the CPU (cast, casts, fan; a view as bytes) is one pass and one read of its
 *    hits, so many rays go in one call (casts, fan), never one call a ray.
 *
 * The grid's cells and the wall textures are R32UI textures, 1024 words a row; the wall table is a
 * uniform block. A ray walks from its cell and where in that cell it is, so float32 holds small numbers
 * only; the cell, wall and face of a hit are the walk's own integers.
 */
class OpenGLCaster extends Raycaster
{
    /** Words a row of a data texture. */
    protected const int WORDS = 1024;

    /** The grid's cells, four to a word. */
    protected int $cells = 0;

    /** @var array<int, true> the wall ids the grid holds */
    protected readonly array $ids;

    /** @var array{int, int, int, int}|null the hits target: texture, framebuffer, width, height; kept while ray lists are the same size */
    protected ?array $hits = null;

    /** @var array{int, int, int, int}|null a view read out as bytes, kept while views are the same size */
    protected ?array $out = null;

    /** @var array{int, int, int}|null rays' own directions (RG32F): texture, width, height; kept while ray lists are the same size */
    protected ?array $directions = null;

    /** The 1x1 that stands in for no directions: the pass reads a texture either way. */
    protected int $noDirections = 0;

    /** @var WeakMap<Scenery, array{int, int}> each scenery's wall table (a uniform buffer) and textures */
    protected WeakMap $sceneries;

    /** @throws OpenGLException When the grid's cells need a texture taller than the GPU holds. */
    public function __construct(
        protected readonly OpenGLContext $device,
        Grid $grid,
    ) {
        parent::__construct($grid);

        $device->current();
        $this->cells = $this->words($grid->cells, "a grid {$grid->width}x{$grid->height}");
        $this->noDirections = OpenGLContext::texture(1, 1, GL_RG32F, GL_RG, GL_FLOAT);
        glTexSubImage2D(GL_TEXTURE_2D, 0, 0, 0, 1, 1, GL_RG, GL_FLOAT, pack('g2', 0.0, 0.0));
        $ids = [];
        foreach (str_split(count_chars($grid->cells, 3)) as $byte) {
            if ($byte !== "\0" && $byte !== '') {
                $ids[ord($byte)] = true;
            }
        }
        $this->ids = $ids;
        $this->sceneries = new WeakMap;
    }

    public function __destruct()
    {
        if (! $this->device->isOpen()) {
            return;
        }
        $this->device->current();
        foreach (array_filter([$this->hits, $this->out]) as [$texture, $framebuffer]) {
            glDeleteFramebuffers(1, [$framebuffer]);
            glDeleteTextures(1, [$texture]);
        }
        $textures = array_filter([$this->cells, $this->noDirections, $this->directions[0] ?? 0]);
        foreach ($this->sceneries as [$walls, $texels]) {
            glDeleteBuffers(1, [$walls]);
            $textures[] = $texels;
        }
        glDeleteTextures(count($textures), array_values($textures));
    }

    public function driver(): string
    {
        return 'opengl';
    }

    /** A view as RGBA8. The scenery's wall table and textures are kept for the next call with it. */
    public function view(Camera $camera, int $width, int $height, Scenery $scenery, float $reach = INF): string
    {
        return $this->viewBytes(self::pictureOf($camera, $width, $height, $scenery, $reach), $this->scenery($scenery));
    }

    /**
     * A view on an opengl framebuffer at $where, drawn on the GPU from the GPU's hits.
     *
     * @internal
     *
     * @throws RaycastException When a ray hits a wall the scenery has no entry for: nothing is drawn.
     */
    public function paintOn(Camera $camera, Scenery $scenery, OpenGLFramebuffer $target, Region $where, float $reach): void
    {
        [$x, $y, $dirX, $dirY, $planeX, $planeY, $width, $height, $table, $ceiling, $floor, $shade, $reach] = self::pictureOf($camera, $where->width, $where->height, $scenery, $reach);
        $set = $this->scenery($scenery);
        $perRow = $this->hitsPass($x, $y, $dirX, $dirY, $planeX, $planeY, $width, $reach);
        $this->checked($perRow, $width, $table);

        // the view replaces what is under it: drawn into the texture, as a hard-edged draw is
        $target->begin(false, $where);
        $this->viewPass($set, $target->width(), $target->height(), self::viewBytesOf($where->x, $where->y, $width, $height, $ceiling, $floor, $shade, $perRow, false), OpenGLContext::quad($where));
        $target->end(false, $where);
    }

    /**
     * Rays from ($x, $y), one an angle: their Hits records, in order. Checked as cast() checks one ray, and each
     * angle becomes its vector in double, as cast() makes it. Walked in one GPU pass.
     *
     * @param list<float> $angles
     *
     * @throws RaycastException
     */
    public function casts(float $x, float $y, array $angles, float $reach = INF): string
    {
        foreach (['x' => $x, 'y' => $y] as $what => $value) {
            if (! is_finite($value)) {
                throw RaycastException::notFinite("A ray's {$what}");
            }
            if (abs($value) > self::LIMIT) {
                throw RaycastException::pastLimit("A ray's {$what}", $value);
            }
        }
        if (! ($reach > 0.0)) {
            throw RaycastException::reach($reach);
        }
        if ($angles === []) {
            return '';
        }
        foreach ($angles as $angle) {
            if (! is_finite($angle)) {
                throw RaycastException::notFinite("A ray's angle");
            }
        }

        $directions = '';
        foreach ($angles as $angle) {
            $directions .= pack('g2', cos($angle), sin($angle));
        }
        $perRow = $this->hitsPass($x, $y, 0.0, 0.0, 0.0, 0.0, count($angles), $reach, $directions);

        return $this->records($perRow, count($angles));
    }

    protected function ray(float $x, float $y, float $dx, float $dy, float $reach): string
    {
        return $this->records($this->hitsPass($x, $y, $dx, $dy, 0.0, 0.0, 1, $reach), 1);
    }

    protected function rays(float $x, float $y, float $dirX, float $dirY, float $planeX, float $planeY, int $columns, float $reach): string
    {
        return $this->records($this->hitsPass($x, $y, $dirX, $dirY, $planeX, $planeY, $columns, $reach), $columns);
    }

    protected function picture(float $x, float $y, float $dirX, float $dirY, float $planeX, float $planeY, int $width, int $height, array $walls, int $ceiling, int $floor, int $shade, float $reach): string
    {
        // a table with no scenery to keep it by: made for this call, gone with it
        $set = $this->pack($walls);
        try {
            return $this->viewBytes([$x, $y, $dirX, $dirY, $planeX, $planeY, $width, $height, $walls, $ceiling, $floor, $shade, $reach], $set);
        } finally {
            glDeleteBuffers(1, [$set[0]]);
            glDeleteTextures(1, [$set[1]]);
        }
    }

    /**
     * A view's straight RGBA8 bytes, from an offscreen target.
     *
     * @param array{float, float, float, float, float, float, int, int, array, int, int, int, float} $arguments pictureOf()'s
     * @param array{int, int} $scenery what pack() made
     *
     * @throws RaycastException When a ray hits a wall with no entry.
     */
    protected function viewBytes(array $arguments, array $scenery): string
    {
        [$x, $y, $dirX, $dirY, $planeX, $planeY, $width, $height, $table, $ceiling, $floor, $shade, $reach] = $arguments;
        $perRow = $this->hitsPass($x, $y, $dirX, $dirY, $planeX, $planeY, $width, $reach);
        $this->checked($perRow, $width, $table);

        $this->out = $this->target($this->out, $width, $height, GL_RGBA8, GL_RGBA, GL_UNSIGNED_BYTE);
        $this->device->plain();
        glBindFramebuffer(GL_FRAMEBUFFER, $this->out[1]);
        $this->viewPass($scenery, $width, $height, self::viewBytesOf(0, 0, $width, $height, $ceiling, $floor, $shade, $perRow, true), OpenGLContext::quad(new Region(0, 0, $width, $height)));
        glBindFramebuffer(GL_READ_FRAMEBUFFER, $this->out[1]);

        return $this->device->readPixels(new Region(0, 0, $width, $height));
    }

    /**
     * The view pass over the bound framebuffer ($width x $height), drawn over the $quad's pixels.
     *
     * @param array{int, int} $scenery
     */
    protected function viewPass(array $scenery, int $width, int $height, string $view, string $quad): void
    {
        $device = $this->device;
        glViewport(0, 0, $width, $height);
        glUseProgram($device->program('place.vert', 'raycast_view.frag'));
        foreach ([$scenery[1], $this->hits[0]] as $unit => $texture) {
            glActiveTexture(GL_TEXTURE0 + $unit);
            glBindTexture(GL_TEXTURE_2D, $texture);
        }
        glBindBufferRange(GL_UNIFORM_BUFFER, OpenGLContext::WALLS, $scenery[0], 0, 256 * 16);
        [$frame, $paint] = $device->blocks([pack('g4', $width, $height, 0.0, 0.0), $view]);
        $device->block(OpenGLContext::FRAME, $frame, 16);
        $device->block(OpenGLContext::PAINT, $paint, OpenGLContext::size($view));
        $device->vertices($quad);
        glDrawArrays(GL_TRIANGLES, 0, 6);
    }

    /**
     * The hits pass: $columns rays, one pixel a ray's row.
     *
     * @return int how many rays a pair of the hits target's rows holds
     *
     * @throws OpenGLException When the rays need a hits target taller than the GPU holds.
     */
    protected function hitsPass(float $x, float $y, float $dirX, float $dirY, float $planeX, float $planeY, int $columns, float $reach, ?string $directions = null): int
    {
        $device = $this->device;
        $device->current();
        $side = $device->maxSide();
        $perRow = min($columns, $side);
        $rows = 2 * intdiv($columns + $perRow - 1, $perRow);
        if ($rows > $side) {
            throw OpenGLException::because("{$columns} rays need a hits target {$perRow}x{$rows}: past the GPU's textures, {$side} a side.");
        }
        $this->hits = $this->target($this->hits, $perRow, $rows, GL_RGBA32F, GL_RGBA, GL_FLOAT);

        // Velvet walks nothing from outside the grid: every ray misses
        $inside = $x >= 0.0 && $y >= 0.0 && $x < $this->grid->width && $y < $this->grid->height;
        $cellX = $inside ? (int) floor($x) : 0;
        $cellY = $inside ? (int) floor($y) : 0;
        // past what float32 holds, a reach stops nothing a grid this size can show
        $reaches = is_finite($reach) && $reach < 3.0e38;
        $flags = ($reaches ? 1 : 0) | ($inside ? 2 : 0) | (is_null($directions) ? 0 : 4);
        $cast = pack('l2g6V3gl2', $cellX, $cellY, $inside ? $x - $cellX : 0.0, $inside ? $y - $cellY : 0.0, $dirX, $dirY, $planeX, $planeY, $columns, $perRow, $flags, $reaches ? $reach : 0.0, $this->grid->width, $this->grid->height);

        $device->plain();
        glBindFramebuffer(GL_FRAMEBUFFER, $this->hits[1]);
        glViewport(0, 0, $perRow, $rows);
        glUseProgram($device->program('cover.vert', 'raycast_hits.frag'));
        glActiveTexture(GL_TEXTURE0);
        glBindTexture(GL_TEXTURE_2D, $this->cells);
        glActiveTexture(GL_TEXTURE0 + 1);
        glBindTexture(GL_TEXTURE_2D, is_null($directions) ? $this->noDirections : $this->directionsOf($directions, $perRow, intdiv($rows, 2)));
        [$paint] = $device->blocks([$cast]);
        $device->block(OpenGLContext::PAINT, $paint, OpenGLContext::size($cast));
        $device->cover();

        return $perRow;
    }

    /** @throws RaycastException When a ray hits a wall $table has no entry for. */
    protected function checked(int $perRow, int $columns, array $table): void
    {
        if (array_diff_key($this->ids, $table) === []) {
            return;
        }
        $records = $this->records($perRow, $columns);
        for ($i = 0; $i < $columns; $i++) {
            $wall = ord($records[$i * 22 + 20]);
            if ($wall !== 0 && ! isset($table[$wall])) {
                throw RaycastException::noWall($wall);
            }
        }
    }

    /** The hits target's rays as Hits records: 22 bytes each, all zero for a miss. */
    protected function records(int $perRow, int $columns): string
    {
        $rows = 2 * intdiv($columns + $perRow - 1, $perRow);
        glBindFramebuffer(GL_READ_FRAMEBUFFER, $this->hits[1]);
        $floats = unpack('g*', $this->device->readPixels(new Region(0, 0, $perRow, $rows), GL_RGBA, GL_FLOAT, 16));

        $out = '';
        for ($i = 0; $i < $columns; $i++) {
            // unpack counts from 1
            $first = ((2 * intdiv($i, $perRow)) * $perRow + $i % $perRow) * 4 + 1;
            $second = $first + $perRow * 4;
            $wall = (int) $floats[$second];
            $out .= $wall === 0
                ? str_repeat("\0", 22)
                : pack('eevvCC', $floats[$first], $floats[$first + 1], (int) $floats[$first + 2], (int) $floats[$first + 3], $wall, (int) $floats[$second + 1]);
        }

        return $out;
    }

    /** The rays' directions in an RG32F texture, $perRow a row: the kept one when it is that size. */
    protected function directionsOf(string $directions, int $perRow, int $rows): int
    {
        if (is_null($this->directions) || $this->directions[1] !== $perRow || $this->directions[2] !== $rows) {
            if (! is_null($this->directions)) {
                glDeleteTextures(1, [$this->directions[0]]);
            }
            $this->directions = [OpenGLContext::texture($perRow, $rows, GL_RG32F, GL_RG, GL_FLOAT), $perRow, $rows];
        }
        glBindTexture(GL_TEXTURE_2D, $this->directions[0]);
        glPixelStorei(GL_UNPACK_ALIGNMENT, 4);
        glTexSubImage2D(GL_TEXTURE_2D, 0, 0, 0, $perRow, $rows, GL_RG, GL_FLOAT, str_pad($directions, $perRow * $rows * 8, "\0"));

        return $this->directions[0];
    }

    /**
     * The scenery on the GPU, made the first time it is drawn and never written again.
     *
     * @return array{int, int} what pack() made
     */
    protected function scenery(Scenery $scenery): array
    {
        return $this->sceneries[$scenery] ??= $this->pack($scenery->table());
    }

    /**
     * The wall table (per wall id: kind, colour, first texel word, width | height << 16) as a uniform buffer, and
     * every wall texture's straight RGBA8 bytes, one after another, as an R32UI texture.
     *
     * @param array<int, int|array{string, int, int}> $table
     * @return array{int, int} the wall table's buffer, the textures' texture
     *
     * @throws OpenGLException When a texture is past 65535 a side, or the textures need a texture taller than the GPU holds.
     */
    protected function pack(array $table): array
    {
        $walls = str_repeat("\0", 256 * 16);
        $texels = '';
        foreach ($table as $id => $wall) {
            if (is_int($wall)) {
                $entry = pack('V4', 1, $wall, 0, 0);
            } else {
                [$bytes, $width, $height] = $wall;
                if ($width > 0xFFFF || $height > 0xFFFF) {
                    throw OpenGLException::because("wall {$id}'s texture {$width}x{$height} is past 65535 a side.");
                }
                $entry = pack('V4', 2, 0, intdiv(strlen($texels), 4), $width | ($height << 16));
                $texels .= $bytes;
            }
            $walls = substr_replace($walls, $entry, $id * 16, 16);
        }

        $this->device->current();
        $textures = $this->words($texels, "the scenery's wall textures");
        glGenBuffers(1, $buffers);
        glBindBuffer(GL_UNIFORM_BUFFER, $buffers[0]);
        glBufferData(GL_UNIFORM_BUFFER, strlen($walls), $walls, GL_STATIC_DRAW);

        return [$buffers[0], $textures];
    }

    /**
     * $bytes as an R32UI texture, 1024 words a row, zero past them.
     *
     * @throws OpenGLException When it needs more rows than the GPU's textures hold.
     */
    protected function words(string $bytes, string $what): int
    {
        $rows = max(1, intdiv(strlen($bytes) + self::WORDS * 4 - 1, self::WORDS * 4));
        if ($rows > $this->device->maxSide()) {
            throw OpenGLException::because("{$what} is ".strlen($bytes).' bytes: past the '.(self::WORDS * 4 * $this->device->maxSide()).' a texture 1024 words wide holds.');
        }
        $texture = OpenGLContext::texture(self::WORDS, $rows, GL_R32UI, GL_RED_INTEGER, GL_UNSIGNED_INT);
        glPixelStorei(GL_UNPACK_ALIGNMENT, 4);
        glTexSubImage2D(GL_TEXTURE_2D, 0, 0, 0, self::WORDS, $rows, GL_RED_INTEGER, GL_UNSIGNED_INT, str_pad($bytes, self::WORDS * 4 * $rows, "\0"));

        return $texture;
    }

    /**
     * $target if it is already $width x $height, else a new texture of that size and format, with its framebuffer.
     *
     * @param array{int, int, int, int}|null $target
     * @return array{int, int, int, int}
     */
    protected function target(?array $target, int $width, int $height, int $internal, int $format, int $type): array
    {
        if (! is_null($target) && $target[2] === $width && $target[3] === $height) {
            return $target;
        }
        if (! is_null($target)) {
            glDeleteFramebuffers(1, [$target[1]]);
            glDeleteTextures(1, [$target[0]]);
        }
        $texture = OpenGLContext::texture($width, $height, $internal, $format, $type);
        glGenFramebuffers(1, $framebuffers);
        glBindFramebuffer(GL_FRAMEBUFFER, $framebuffers[0]);
        glFramebufferTexture2D(GL_FRAMEBUFFER, GL_COLOR_ATTACHMENT0, GL_TEXTURE_2D, $texture, 0);

        return [$texture, $framebuffers[0], $width, $height];
    }

    protected static function viewBytesOf(int $x, int $y, int $width, int $height, int $ceiling, int $floor, int $shade, int $perRow, bool $straight): string
    {
        return pack('l4V5', $x, $y, $width, $height, $ceiling, $floor, $shade, $perRow, $straight ? 1 : 0);
    }
}
