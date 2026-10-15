# jovian/venusian-opengl

The `opengl` rendering engine for Venusian Surface. OpenGL 4.1 core through CGL on the Mac, OpenGL ES 3.1 through surfaceless EGL on Linux. `dialect()` says which.

## Where it sits

```
DrawingManager → GpuRenderingEngine (Surface) → OpenGLDevice (this package) → ext-opengl → CGL | EGL → OpenGL
```

The engine's framebuffer is an `OpenGLFramebuffer`, a Surface `GLFramebuffer`.

## Requirements

- macOS: OpenGL 4.1 core through CGL. Apple deprecates it; SDK 15.4 still ships it.
- Linux: EGL 1.5 with `EGL_MESA_platform_surfaceless` and OpenGL ES 3.1. Anti-aliased edges need `GL_MAX_SAMPLES` ≥ 4; a context with fewer refuses them by name.
- PHP 8.4, `venusian-surface/drawing` ^0.10, ext-opengl ^0.10 with the pixel-store skip constants and, on Linux, `eglGetCurrentDisplay` / `eglGetCurrentSurface`.
- No window, no display server: the engine's own context is offscreen. A window's context comes from a toolkit canvas as a `LentSurface`.

## Install

```
composer require jovian/venusian-opengl
```

Provider discovered through `extra.venusian.providers`. It registers `opengl` on `app('drawing')`.

## Usage

Offscreen. Run as a script; printed values are its output (Apple M1 Pro).

```php
use Surface\Contracts\Drawing\RenderingEngine;
use Surface\Contracts\Framebuffers\FormatSpec;
use Surface\NutsAndBolts\Color;

$engine = app('drawing')->renderer('opengl', ['width' => 320, 'height' => 240]);
$engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(16, 24, 32))->fillEllipse(160, 120, 60, 40, Color::rgb(255, 128, 0)));
$rgba = $engine->framebuffer()->flush(FormatSpec::rgba8());

echo $engine->device()->dialect(), "\n";                       // core  (es on the Pi)
echo bin2hex(substr($rgba, (120 * 320 + 160) * 4, 4)), "\n";   // ff8000ff  ellipse centre
echo bin2hex(substr($rgba, 0, 4)), "\n";                       // 101820ff  corner
```

On a display. The display reads back what changed and sends it in its own format. ST7796 480 × 320 on the Pi 5 at 10 MHz: 56.5 fps of partial frames (see Measured).

```php
$display = app('displays')->panel('st7796', direct: true);
$engine = app('drawing')->renderer('opengl', ['output' => $display]);
```

On a canvas. AppKit, GTK and Qt canvases lend a GL context from slice 10b; the device adopts it and the canvas copies the frame into its view with `present()` inside its render callback.

```php
$engine = app('drawing')->renderer('opengl', ['output' => $canvas]);
```

Before a canvas lends `gl-context`, the refusal reads `This window cannot host 'opengl': it lends … It can host: …`.

## Presenting and HDR

Present = show pass into bound framebuffer: `presentRect()`, window's filter, black bars, encoded. Host's GL context = SDR only (host picks pixel format). HDR / P3: make engine on a GLFW or SDL3 staged window with `format` / `colorspace` → device gets native window (`'native'` handle, lend carries `'context' => 0`), makes own surface:

- macOS: NSOpenGLContext, half floats, EDR view, extended-linear window colour space (HDR10 shown through EDR: no PQ GL surface on macOS); needs ext-appkit.
- Linux: EGL window surface in `EGL_GL_COLORSPACE` (scRGB linear half floats, BT.2020 PQ 10-bit + SMPTE 2086 / CTA 861.3 metadata, Display P3) where the display lists the extension; else refused naming it. Wayland swaps at interval 0. Pi 5 Mesa lists none (measured): refused there.

    $engine = $drawing->engine('opengl', ['output' => $stagedWindow, 'format' => TargetFormat::Rgba16Float]);

Offscreen device draws every pair (readback, `hdrSnapshot()`).

## What it draws

| Operation | Draws |
|---|---|
| CLEAR | the colour over the whole target, no blend |
| SCISSOR | clip rectangle for what follows |
| SOLID | flat-colour triangles, no blend |
| STENCIL_FILL + COVER | a path: triangle lists into the stencil (winding: front faces increment-wrap, back faces decrement-wrap; even-odd: invert), then a cover quad blended where the stencil is non-zero, zeroing it |
| ELLIPSE, RING | coverage of the outer (and inner) ellipse in the fragment shader, folded into alpha as Velvet folds it |
| UPLOAD + IMAGE | a framebuffer as a texture, fetched texel by texel at the inverse placement of the pixel centre, nearest or Velvet's 256ths bilinear |
| RECTS | rectangles blended at the colour's alpha |

Edges: four samples resolved with `glBlitFramebuffer` (anti-aliased) or one (hard).

## Blending

Fixed-function source-over: colour `GL_SRC_ALPHA, GL_ONE_MINUS_SRC_ALPHA`, alpha `GL_ONE, GL_ONE_MINUS_SRC_ALPHA`. The shader hands the blender Velvet's alpha: `(α·coverage + 127) / 255` for shapes, `(sₐ·opacity + 127) / 255` for images. Measured: Velvet's bytes. `GpuParity` 21/21 on Apple M1 Pro (CGL 4.1 core) and on the Pi 5 (V3D 7.1, EGL ES 3.1), no tolerance; translucent fills at α 1, 127, 128, 254, 255 match byte for byte with one sample and four.

## Shaders

`resources/shaders/venusian.vert.glsl` and `venusian.frag.glsl`: venusian-sdl3's GLSL bodies with the Vulkan `layout(...)` qualifiers removed and no `#version` line. The device compiles each under one of two headers, picked from `GL_SHADING_LANGUAGE_VERSION`:

| Dialect | Header |
|---|---|
| `core` | `#version 150 core` |
| `es` | `#version 300 es` with `highp` float, int and sampler2D (the bilinear path's unsigned products need 32-bit ints) |

Uniforms: `size` (vec2, target pixels), `mode_opacity` (mode, opacity, linear, 0) and `rgba_in` (r, g, b, a) as floats holding whole numbers, rebuilt into `uvec4 mode_rgba[2]` at the top of `main()` (ext-opengl sets no unsigned vectors); `shape` (cx, cy, rx, ry), `band` (stroke, hole, samples, 0), `abcd`, `efwh` (the inverse placement, source width and height); `source` (sampler2D, unit 0). Attribute `point` at location 0. Modes: SOLID 0, FILL 1, OVAL 2, IMAGE 3, COPY 4.

## Reference

- `OpenGLDevice`: `__construct()` (own offscreen context), `adopting()` (no context until `adopt()`), `dialect()`, `maxSamples()`, `maxTextureSize()`, `program()`, `current()`, `target()`, `draw()`, `surfaces()` (`[gl-context]`), `handles()` (`[]`), `adopt()`, `present()` (into the view's bound framebuffer), `blitTo(int $framebuffer, int $width, int $height)`, `release()`, `read()`, `write()`, `forget()`, `finish()`.
- `OpenGLFramebuffer`: `device()`, `texture()`, the `GLFramebuffer` methods.
- `VenusianOpenGLServiceProvider::extend(DrawingManager)`, `deviceFor(array $args)`: an adopting device when `output` is a window (a window that lends no `gl-context` is refused before any context is made), an offscreen one otherwise.

## Behaviour

- Rows: GL's row 0 is the bottom. The vertex shader puts target row 0 at the top of clip space, so the target texture holds the frame bottom-up; `read()` and `write()` flip at that boundary and an `OpenGLFramebuffer` is top-down like every Surface framebuffer. The scissor's y is flipped the same way.
- The fragment shader samples sources top-down. A CPU framebuffer is uploaded as it is; a texture of this device (the target for the restore, or this device's framebuffer drawn as an image) is sampled through an upright copy made by a flipped blit on the GPU.
- Every call makes the device's context current unless it already is, and puts back the context, display and surfaces that were current before. A context that cannot be made current (EGL: current on another thread) is a `DrawingException`; no GL call runs elsewhere. An adopted context is switched the same way when the canvas does not have it current, and is never destroyed.
- An adopted context is the engine's while lent: the device sets the GL state it draws with on every frame and leaves the program, vertex array, array buffer and textures unbound after it; other state it sets (blend, stencil, scissor, viewport, pixel store) stays as the last frame left it.
- An adopted device frees everything it made when the canvas releases the `LentSurface` (`onRelease()`), which the canvas does while the context still exists: GTK's and Qt's contexts share objects with contexts that outlive them, so nothing is left behind. After that the device is released and makes no GL call again; a later `release()` does nothing.
- `blitTo()` copies the resolved target into a GL framebuffer the canvas names, stretched to its size, nearest; rows stay the right way up, both being GL framebuffers. The framebuffer is left bound in the canvas's context, and a context current before is put back. A device with its own context refuses it.
- `present()` copies the target into the framebuffer the view has bound for drawing, at the lent surface's size, and leaves it bound with no pixel-pack buffer: a GL canvas calls it inside its render callback. A device with its own context, or a released surface, answers false.
- A pixel call between frames costs a readback, an upload and, with anti-aliased edges, a restore pass before the next frame.
- The engine's own framebuffer drawn as an image shows the frame before the current one.
- Image sources are uploaded every frame they are drawn. Every texture a frame makes (sources, the restore's copy) is deleted when the frame ends.
- Every draw that samples nothing has a 1 × 1 blank texture on unit 0: a draw with none bound is one Apple's GL logs.
- Pixel store state is set before every read and upload (no pixel buffer bound, alignment 1, row length 0, skip rows and pixels 0): state left by another caller does not stride or offset them.
- Textures stop at `GL_MAX_TEXTURE_SIZE` (16384 on Apple M1 Pro, 4096 on V3D 7.1): a larger target or image source throws `DrawingException`, the frame cancelled before anything is drawn.
- Reads and uploads are bounded by the target, and an upload's bytes must fill its region exactly; otherwise `DrawingException`.
- A GL error after `target()`, `draw()`, `read()`, `write()`, `present()` or `blitTo()` is a `DrawingException` naming the call.
- `release()` waits for the GPU, deletes the target, every texture of a framebuffer still held, the program and the vertex array, and destroys the context only when it is the device's own; the caller's current context stays current. It runs once; a later read, upload, `target()` or `current()` throws `opengl: this device was released.`. A device with its own context runs `release()` when it is destroyed, so one an engine refused (a target past the texture limit, four samples on a context with fewer) frees its context.
- A re-made target is a new `OpenGLFramebuffer`; the old one keeps its own texture.

## Measured

320 × 240, 100 ellipses and a line of text, anti-aliased, peak memory 6.0 MB:

| Machine | Whole redraw | Partial frames |
|---|---|---|
| Apple M1 Pro, CGL 4.1 core, php84 (NTS) | 1.49 ms | 4.81 ms |
| Apple M1 Pro, CGL 4.1 core, ZTS | 0.63 ms | 1.51 ms |
| Raspberry Pi 5, V3D 7.1 EGL ES 3.1 | 1.21 ms | 2.29 ms |

ST7796 480 × 320 on the Pi's SPI at 10 MHz through `DirectEDisplay`, a moving dot, a frame and a label:

| Engine | Whole frame: draw + present | Partial frame: draw + present | Rate | Bytes a partial frame | Peak |
|---|---|---|---|---|---|
| velvet | 3.8 + 246.8 ms | 0.6 + 15.6 ms | 61.7 fps | 15,949 | 14.0 MB |
| opengl | 1.7 + 250.0 ms | 0.7 + 17.0 ms | 56.5 fps | 16,067 | 18.0 MB |

## Testing

Real GPU only. `tests/Pest.php` requires Surface's `GpuParity` from the sibling checkout; set `SURFACE_TESTS` to a Surface checkout's `tests` directory to move it. Install with a temporary path repository to `<surface>/src/Surface/*`, run `php -d memory_limit=128M vendor/bin/pest` on `php84`, ZTS and the Pi. Runbook: [.okf/runbooks/testing.md](.okf/runbooks/testing.md).

## Security

See [SECURITY.md](SECURITY.md).

## License

MIT.
