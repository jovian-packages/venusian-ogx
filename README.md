# jovian/venusian-opengl

The `opengl` graphics engine for Venusian Topside. OpenGL 4.1 core through CGL on the Mac, through ext-opengl. It hands out the four resources Graphics knows, on the GPU: framebuffers, a rasterizer, a raycaster and a painter.

## Where it sits

```
Gfx::engine('opengl') → OpenGLContext (this package) → ext-opengl → CGL → OpenGL
```

Each resource is the engine's own version of a Graphics type: `OpenGLFramebuffer` is a `GEFramebuffer`, `OpenGLRasterizer` a `GERasterizer`, `OpenGLRaycaster` a `GERaycaster`, `OpenGLGEPainter` a `GEPainter`. Resources from one engine work only with each other.

## Requirements

- macOS: OpenGL 4.1 core through CGL. Apple deprecates it; SDK 15.4 still ships it.
- PHP `~8.4.27||^8.5.12`, ext-opengl ^0.11 (uniform blocks and the integer and float texture formats), `venusian/topside` ^0.11.5.
- ext-appkit, when loaded, for two things only: reading pixels back (GL writes them to an address, and ext-appkit's `CFData` is the memory there) and showing frames in a view a stage lends. It is not declared: the package installs anywhere.
- Linux: the engine opens a CGL context; an EGL one is not built yet, and constructing the engine on Linux throws saying so.

## Install

```
composer require jovian/venusian-opengl
```

The provider is discovered through `extra.venusian.providers`. It registers `opengl` with Graphics on the GPU backend when ext-opengl is loaded.

## Usage

In an application, ask Graphics for it: `Gfx::engine('opengl')`. Standalone, make it yourself. Run as a script; printed values are its output (Apple M1 Pro):

```php
<?php

use Jovian\Engines\OpenGL\OpenGLContext;
use Surface\Contracts\Graphics\Backend;
use Surface\Contracts\Rendering\Framebuffers\Region;
use Surface\Graphics\GEPainter;
use Surface\NutsAndBolts\Color;
use Surface\VelvetGE\Recording\PHPLandQueue;
use Surface\VelvetGE\Recording\VelvetRecorder;

require 'vendor/autoload.php';

$gl = new OpenGLContext(Backend::GPU, recorder: fn () => new VelvetRecorder(new PHPLandQueue()));
$frame = $gl->framebuffer(200, 100);
$gl->painter($frame)->frame(fn (GEPainter $g) => $g
    ->clear(Color::hex('#101820'))
    ->roundRect(10, 10, 120, 80, [12, 12, 12, 12], Color::hex('#ff6b35'))
    ->fillEllipse(150, 50, 30, 30, Color::rgba(64, 200, 255, 0.6)));

echo $gl->modules()['gpu'], "\n";                           // Apple M1 Pro
echo bin2hex($frame->read(new Region(40, 40, 1, 1))), "\n"; // ff6b35ff
echo bin2hex($frame->read(new Region(150, 50, 1, 1))), "\n"; // 2d82a6ff
```

## What it does

- **Framebuffer**: an RGBA8 texture of premultiplied colour, with a four-sample twin antialiased shapes are drawn into. `read()` and `write()` take straight colour. The texture is resolved from the samples when something reads it (a read, a write, a scroll, being shown, being drawn as an image, a backdrop or glass reading it), not after every draw: a resolve is a blit of its own in GL.
- **Rasterizer**: a path is stencilled, then covered; a path of one convex contour is drawn in one step with no stencil. Hard edges come from the pixel's centre, antialiased ones from four samples a pixel.
- **Painter**: recording and damage are Topside's shared painter's. A pass uploads its vertices once and its uniform blocks once, then draws each command with its scissor. Curved shapes and shadows are one rectangle each; gradients read a 256-colour ramp kept by its bytes; images read another of the engine's framebuffers. Layers are drawn into sheets rounded up to 64; filters, backdrops and glass run on the GPU, with Velvet's integer arithmetic where Velvet's is integer.
- **Raycaster**: rays are walked by a pass over a hits target, one pixel a ray's row (OpenGL 4.1 has no compute). A view painted on a framebuffer stays on the GPU; what comes back to PHP (`cast`, `casts`, `fan`, a view as bytes) is one pass and one read.

## Showing frames

On a stage that lends an `NSView`, `attach()` adds a child `NSOpenGLView` of the engine's, the stage's size, at full resolution, drawn by a context that shares the engine's textures. Showing a frame draws its texture into the view's back buffer and swaps; nothing comes back to the CPU. The stage paces showing. `release()` takes the view away. A stage that lends nothing is shown as pixels, through `display()`.

## Measured

Apple M1 Pro, ms, with no window; the timings end with a read, so unfinished GPU work counts.

| | OpenGL | Metal | Vulkan | Velvet (C) |
|---|---|---|---|---|
| Kitchen sink shapes scene, 1100 × 360, a frame | 0.40 | 0.40 | 0.43 | 1.20 |
| The same, every frame resolved | 0.48 | | | |
| Stroked wave, 1024 × 768, a call | 0.76 | 0.78 | 0.80 | 3.68 |
| A layer, added to a frame | 0.08 | 0.025 | 0.06 | |
| Paint a raycast view, 1100 × 360 | 0.077 | 0.106 | 0.158 | |
| 90 rays back to PHP (`casts`) | 0.36–0.38 | 0.36 | 0.35–0.36 | |

A layer costs one resolve blit of the sheet it is drawn in (0.048 ms of CPU whatever its size), since the frame reads it straight away.

## Testing

Real GPU, no window. Each script takes the path to a `venusian/topside` checkout and uses its `vendor/` and test helpers:

```
zhp tests/smoke.php   /path/to/venusian/topside   # 65: framebuffers, rasterizer, painter, presenting into an NSView
zhp tests/filters.php /path/to/venusian/topside   # 35: each filter step against Velvet's
zhp tests/glass.php   /path/to/venusian/topside   # Kawase levels and panes, byte for byte Velvet's
zhp tests/raycast.php /path/to/venusian/topside   # 16: rays and views against Velvet's marcher
```

Runbook: [.okf/runbooks/testing.md](.okf/runbooks/testing.md).

## Security

See [SECURITY.md](SECURITY.md).

## License

MIT.
