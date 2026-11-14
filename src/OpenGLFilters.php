<?php

namespace Jovian\Engines\OpenGL;

use Surface\Contracts\Graphics\Effects\Blend;
use Surface\Contracts\Graphics\Effects\BlendMode;
use Surface\Contracts\Graphics\Effects\Blur;
use Surface\Contracts\Graphics\Effects\BlurSampling;
use Surface\Contracts\Graphics\Effects\Channel;
use Surface\Contracts\Graphics\Effects\ColorMatrix;
use Surface\Contracts\Graphics\Effects\Composite;
use Surface\Contracts\Graphics\Effects\CompositeOperator;
use Surface\Contracts\Graphics\Effects\Displace;
use Surface\Contracts\Graphics\Effects\EdgeMode;
use Surface\Contracts\Graphics\Effects\Field;
use Surface\Contracts\Graphics\Effects\FieldKind;
use Surface\Contracts\Graphics\Effects\FilterChain;
use Surface\Contracts\Graphics\Effects\FilterStep;
use Surface\Contracts\Graphics\Effects\Flood;
use Surface\Contracts\Graphics\Effects\Noise;
use Surface\Contracts\Graphics\Effects\NoiseType;
use Surface\Contracts\Graphics\Effects\Table;
use Surface\Contracts\Rendering\Framebuffers\Framebuffer;
use Surface\VelvetGE\Framebuffers\PHPLand\PHPLandFilters;

/**
 * Filter chains, glass captures and the glass pyramid on the GPU. Every image is an RGBA8 texture; each step is
 * one draw of a triangle over the image that works out each pixel from its inputs (shaders/engine: one fragment a
 * step). What a step reads past its uniform block (blur weights, tables, spiral points, band weights, a noise
 * lattice) is a data texture: 32-bit words, 1024 a row, R32UI.
 *
 * Images live for one frame: they come from a pool by size, rounded up to 64 pixels, and go back to it when the
 * frame ends (release()); one no frame used is deleted then. A noise lattice is written once a seed and kept.
 *
 * Inputs and results are texture names.
 */
class OpenGLFilters
{
    /** An image's sides are multiples of this many pixels in the pool. */
    protected const int STEP = 64;

    /** Words a row of a data texture. */
    protected const int WORDS = 1024;

    /** @var array<string, list<array{int, int}>> [texture, framebuffer] by rounded size */
    protected array $pool = [];

    /** @var array<string, int> how many of each size this frame has taken */
    protected array $taken = [];

    /** @var array<int, list<int>> data textures free to take, by rows */
    protected array $data = [];

    /** @var list<array{int, int}> data textures this frame took: [rows, texture] */
    protected array $lent = [];

    /** @var array<int, int> each seed's noise lattice */
    protected array $lattices = [];

    public function __construct(protected readonly OpenGLContext $device) {}

    public function __destruct()
    {
        if (! $this->device->isOpen()) {
            return;
        }
        $this->device->current();
        foreach ($this->pool as $images) {
            foreach ($images as [$texture, $framebuffer]) {
                glDeleteFramebuffers(1, [$framebuffer]);
                glDeleteTextures(1, [$texture]);
            }
        }
        $data = [...array_merge(...array_values($this->data) ?: [[]]), ...array_column($this->lent, 1), ...array_values($this->lattices)];
        if ($data !== []) {
            glDeleteTextures(count($data), $data);
        }
        [$this->pool, $this->data, $this->lent, $this->lattices] = [[], [], [], []];
    }

    /**
     * Runs $chain over a $width x $height image, the chain's source. Every step works on premultiplied colour, as
     * PHPLandFilters::run() does; the result is premultiplied too.
     *
     * @param int $source the texture: premultiplied colour, the image at its top left
     * @param int $originX where the image's top left lies on the surface: noise and fields are functions of position
     */
    public function run(FilterChain $chain, int $source, int $width, int $height, int $originX, int $originY): int
    {
        $results = [FilterStep::SOURCE => $source];
        $previous = $source;
        foreach ($chain->steps as $step) {
            $input = fn (?string $name): int => $name === null || $name === '' ? $previous : $results[$name];
            $made = match (true) {
                $step instanceof Blur => $this->blur($input($step->in), $width, $height, $step),
                $step instanceof ColorMatrix => $this->pass('color_matrix', $width, $height, [$input($step->in)], pack('g20', ...$step->values)),
                $step instanceof Flood => $this->pass('flood', $width, $height, [], pack('V4', ...self::floodPixel($step))),
                $step instanceof Composite => $this->pass('composite', $width, $height, [$input($step->in), $input($step->in2)], pack('V4g4', self::operator($step->operator), 0, 0, 0, $step->k1, $step->k2, $step->k3, $step->k4)),
                $step instanceof Blend => $this->pass('blend', $width, $height, [$input($step->in), $input($step->in2)], pack('V', self::mode($step->mode))),
                $step instanceof Noise => $this->noise($step, $width, $height, $originX, $originY),
                $step instanceof Displace => $this->displace($input($step->in), $width, $height, $step, is_string($step->map) ? $results[$step->map] : null),
                $step instanceof Field => $this->field($step, $width, $height, $originX, $originY),
                $step instanceof Table => $this->pass('table', $width, $height, [$input($step->in)], '', self::tables($step)),
            };
            $previous = $made;
            if (($name = $step->named()) !== null) {
                $results[$name] = $made;
            }
        }

        return $previous;
    }

    /**
     * A $width x $height region of what $source holds at ($x, $y) as an image of its own; past the source's own
     * $sourceWidth x $sourceHeight, transparent. With $straight, unpremultiplied, as Velvet's glass reads its backdrop.
     */
    public function grab(int $source, int $sourceWidth, int $sourceHeight, int $x, int $y, int $width, int $height, bool $straight): int
    {
        return $this->pass('grab', $width, $height, [$source], pack('l4V', $x, $y, $sourceWidth, $sourceHeight, $straight ? 1 : 0));
    }

    /** One level down of a $width x $height region at the top left of $source: PHPLandGlass::down(). */
    public function kawaseDown(int $source, int $width, int $height): int
    {
        return $this->pass('kawase_down', intdiv($width + 1, 2), intdiv($height + 1, 2), [$source], pack('l4', 0, 0, $width, $height));
    }

    /** One level up of a $sourceWidth x $sourceHeight level, $width x $height: PHPLandGlass::up(). */
    public function kawaseUp(int $source, int $sourceWidth, int $sourceHeight, int $width, int $height): int
    {
        return $this->pass('kawase_up', $width, $height, [$source], pack('l2', $sourceWidth, $sourceHeight));
    }

    /** The frame is over: every image and data texture goes back to its pool; an image the frame did not use is deleted. */
    public function release(): void
    {
        $gone = [];
        foreach ($this->pool as $size => $images) {
            array_push($gone, ...array_slice($images, $this->taken[$size] ?? 0));
            $this->pool[$size] = array_slice($images, 0, $this->taken[$size] ?? 0);
        }
        $this->pool = array_filter($this->pool);
        foreach ($this->lent as [$rows, $texture]) {
            $this->data[$rows][] = $texture;
        }
        [$this->lent, $this->taken] = [[], []];
        if ($gone !== []) {
            $this->device->current();
            foreach ($gone as [$texture, $framebuffer]) {
                glDeleteFramebuffers(1, [$framebuffer]);
                glDeleteTextures(1, [$texture]);
            }
        }
    }

    /**
     * $rgba8 ($width x $height, rows from the top, straight) as an image for this frame.
     *
     * @internal
     */
    public function upload(string $rgba8, int $width, int $height): int
    {
        [$texture] = $this->take($width, $height);
        glBindTexture(GL_TEXTURE_2D, $texture);
        glPixelStorei(GL_UNPACK_ALIGNMENT, 4);
        glTexSubImage2D(GL_TEXTURE_2D, 0, 0, 0, $width, $height, GL_RGBA, GL_UNSIGNED_BYTE, $rgba8);

        return $texture;
    }

    /**
     * The framebuffer that draws into a texture this frame's pool made, for reading it back.
     *
     * @internal
     */
    public function framebufferOf(int $texture): ?int
    {
        foreach ($this->pool as $images) {
            foreach ($images as [$image, $framebuffer]) {
                if ($image === $texture) {
                    return $framebuffer;
                }
            }
        }

        return null;
    }

    protected function blur(int $image, int $width, int $height, Blur $blur): int
    {
        $edge = $blur->edge === EdgeMode::DUPLICATE ? 1 : 0;
        if ($blur->sampling === BlurSampling::SPIRAL) {
            if ($blur->x <= 0.0) {
                return $image;
            }
            $offsets = '';
            foreach (PHPLandFilters::spiralOffsets($blur->x, $blur->samples) as [$dx, $dy]) {
                $offsets .= pack('g2', $dx, $dy);
            }

            return $this->pass('spiral_blur', $width, $height, [$image], pack('l2V2', $width, $height, $blur->samples, $edge), $offsets);
        }
        foreach ([[true, $blur->x], [false, $blur->y]] as [$horizontal, $sigma]) {
            if ($sigma <= 0.0) {
                continue;
            }
            if ($sigma < 2.0) {
                $weights = PHPLandFilters::kernelWeights($sigma);
                $radius = intdiv(count($weights) - 1, 2);
                $image = $this->pass('kernel_blur', $width, $height, [$image], pack('l2V3', $width, $height, $horizontal ? 1 : 0, $radius, $edge), pack('l*', ...$weights));

                continue;
            }
            foreach (PHPLandFilters::boxes($sigma) as [$size, $left]) {
                $image = $this->pass('box_blur', $width, $height, [$image], pack('l2V2lV', $width, $height, $horizontal ? 1 : 0, $size, $left, $edge));
            }
        }

        return $image;
    }

    protected function noise(Noise $noise, int $width, int $height, int $originX, int $originY): int
    {
        $type = match ($noise->type) {
            NoiseType::TURBULENCE => 0,
            NoiseType::FRACTAL => 1,
            NoiseType::HASH => 2,
        };
        [$frequencyX, $frequencyY] = PHPLandFilters::stitched($noise, $width, $height);
        $stitch = [0, 0, 0, 0];
        if ($noise->stitch) {
            $w = (int) ($width * $frequencyX + 0.5);
            $h = (int) ($height * $frequencyY + 0.5);
            $stitch = [$w, $h, (int) ($originX * $frequencyX + 4096 + $w), (int) ($originY * $frequencyY + 4096 + $h)];
        }
        $bytes = pack('l2lV', $originX, $originY, $noise->seed, $type).pack('g2', $frequencyX, $frequencyY).pack('V2', $noise->octaves, $noise->stitch ? 1 : 0).pack('l4', ...$stitch);

        // the lattice: 514 selector entries (uint16, 1028 bytes), then 4 x 514 gradients (float2). A seed's lattice
        // never changes: written once, by the frame that first needs it, and kept
        if (! isset($this->lattices[$noise->seed])) {
            $tables = PHPLandFilters::noiseTables($noise->seed);
            $rows = self::rows(strlen($tables));
            $this->lattices[$noise->seed] = $this->dataTexture($rows);
            $this->write($this->lattices[$noise->seed], $tables, $rows);
        }

        return $this->pass('noise', $width, $height, [], $bytes, null, $this->lattices[$noise->seed]);
    }

    protected function field(Field $field, int $width, int $height, int $originX, int $originY): int
    {
        $kind = match ($field->kind) {
            FieldKind::ROUNDED_BOX => 0,
            FieldKind::INSIDE => 1,
            FieldKind::RAMP => 2,
        };
        $bytes = pack('l2V2', $originX, $originY, $kind, 0).pack('g4', ...($field->box ?? [0.0, 0.0, 0.0, 0.0])).pack('g4', ...$field->radii).pack('g2', $field->from, $field->to);

        return $this->pass('field', $width, $height, [], $bytes);
    }

    /** @param int|null $named the map an earlier step made, premultiplied; null: the step's own framebuffer */
    protected function displace(int $image, int $width, int $height, Displace $step, ?int $named): int
    {
        if (is_null($named)) {
            /** @var Framebuffer $framebuffer */
            $framebuffer = $step->map;
            [$mapWidth, $mapHeight] = [$framebuffer->viewportWidth(), $framebuffer->viewportHeight()];
            $map = $this->upload($framebuffer->toRgba8(), $mapWidth, $mapHeight);
        } else {
            [$map, $mapWidth, $mapHeight] = [$named, $width, $height];
        }
        $channel = fn (Channel $c): int => match ($c) {
            Channel::R => 0,
            Channel::G => 1,
            Channel::B => 2,
            Channel::A => 3,
        };
        $weights = '';
        foreach ($step->weights ?? PHPLandFilters::bandWeights($step->bands) as [$r, $g, $b]) {
            $weights .= pack('g4', $r, $g, $b, 0.0);
        }
        $bytes = pack('l4', $width, $height, $mapWidth, $mapHeight).pack('g2', $step->scale, $step->spread).pack('V4', $channel($step->xChannel), $channel($step->yChannel), $step->bands, is_null($named) ? 0 : 1);

        return $this->pass('displace', $width, $height, [$image, $map], $bytes, $weights);
    }

    /**
     * One draw: $fragment over a $width x $height image, into an image of the pool. Its samplers read $inputs in
     * the order its `// units:` line names them, and `data` reads its data texture.
     *
     * @param list<int> $inputs textures
     * @param string $paint the fragment's uniform block
     * @param string|null $data its data texture's words
     * @param int|null $kept a data texture already written (a lattice)
     */
    protected function pass(string $fragment, int $width, int $height, array $inputs, string $paint = '', ?string $data = null, ?int $kept = null): int
    {
        $device = $this->device;
        $device->current();
        if (! is_null($data)) {
            $rows = self::rows(strlen($data));
            $kept = empty($this->data[$rows]) ? $this->dataTexture($rows) : array_pop($this->data[$rows]);
            $this->lent[] = [$rows, $kept];
            $this->write($kept, $data, $rows);
        }
        [$out, $framebuffer] = $this->take($width, $height);

        $program = $device->program('cover.vert', "{$fragment}.frag");
        $device->plain();
        glBindFramebuffer(GL_FRAMEBUFFER, $framebuffer);
        glViewport(0, 0, $width, $height);
        glUseProgram($program);
        foreach ($device->units('cover.vert', "{$fragment}.frag") as $unit => $name) {
            glActiveTexture(GL_TEXTURE0 + $unit);
            glBindTexture(GL_TEXTURE_2D, $name === 'data' ? ($kept ?? 0) : (array_shift($inputs) ?? 0));
        }
        if ($paint !== '') {
            [$offset] = $device->blocks([$paint]);
            $device->block(OpenGLContext::PAINT, $offset, OpenGLContext::size($paint));
        }
        $device->cover();

        return $out;
    }

    /** @return array{int, int} [texture, framebuffer] at least $width x $height, from the pool */
    protected function take(int $width, int $height): array
    {
        [$w, $h] = [self::round($width), self::round($height)];
        $size = "{$w}x{$h}";
        $index = $this->taken[$size] ?? 0;
        $this->taken[$size] = $index + 1;
        if (isset($this->pool[$size][$index])) {
            return $this->pool[$size][$index];
        }

        $texture = OpenGLContext::texture($w, $h, GL_RGBA8, GL_RGBA, GL_UNSIGNED_BYTE);
        glGenFramebuffers(1, $framebuffers);
        glBindFramebuffer(GL_FRAMEBUFFER, $framebuffers[0]);
        glFramebufferTexture2D(GL_FRAMEBUFFER, GL_COLOR_ATTACHMENT0, GL_TEXTURE_2D, $texture, 0);

        return $this->pool[$size][$index] = [$texture, $framebuffers[0]];
    }

    protected function dataTexture(int $rows): int
    {
        return OpenGLContext::texture(self::WORDS, $rows, GL_R32UI, GL_RED_INTEGER, GL_UNSIGNED_INT);
    }

    /** Writes $bytes into the $rows-row data texture, zero past them. */
    protected function write(int $texture, string $bytes, int $rows): void
    {
        glBindTexture(GL_TEXTURE_2D, $texture);
        glPixelStorei(GL_UNPACK_ALIGNMENT, 4);
        glTexSubImage2D(GL_TEXTURE_2D, 0, 0, 0, self::WORDS, $rows, GL_RED_INTEGER, GL_UNSIGNED_INT, str_pad($bytes, self::WORDS * 4 * $rows, "\0"));
    }

    protected static function rows(int $bytes): int
    {
        $rows = max(1, intdiv($bytes + self::WORDS * 4 - 1, self::WORDS * 4));

        return 1 << (int) ceil(log($rows, 2));
    }

    /** @return array{int, int, int, int} a flood's colour, premultiplied as PHPLandFilters::flood() does */
    protected static function floodPixel(Flood $flood): array
    {
        $a = (int) round($flood->color->alpha * 255);
        $p = fn (float $c): int => intdiv((int) round($c * 255) * $a + 127, 255);

        return [$p($flood->color->red), $p($flood->color->green), $p($flood->color->blue), $a];
    }

    /** Four tables of 256 bytes, red, green, blue, alpha; an identity for one the step left out. */
    protected static function tables(Table $table): string
    {
        $bytes = '';
        foreach ([$table->red, $table->green, $table->blue, $table->alpha] as $entries) {
            $bytes .= pack('C256', ...($entries ?? range(0, 255)));
        }

        return $bytes;
    }

    protected static function operator(CompositeOperator $operator): int
    {
        return match ($operator) {
            CompositeOperator::OVER => 0,
            CompositeOperator::IN => 1,
            CompositeOperator::OUT => 2,
            CompositeOperator::ATOP => 3,
            CompositeOperator::XOR => 4,
            CompositeOperator::ARITHMETIC => 5,
        };
    }

    protected static function mode(BlendMode $mode): int
    {
        return match ($mode) {
            BlendMode::NORMAL => 0,
            BlendMode::MULTIPLY => 1,
            BlendMode::SCREEN => 2,
            BlendMode::DARKEN => 3,
            BlendMode::LIGHTEN => 4,
        };
    }

    protected static function round(int $side): int
    {
        return max(self::STEP, intdiv($side + self::STEP - 1, self::STEP) * self::STEP);
    }
}
