# jovian/venusian-ogx

OpenGL composition for Venusian Surface GPU drawing — the same engine on the
Mac (GL 4.1 core in an `NSOpenGLView`) and the Pi (GLES 3.1 in a `GtkGLArea`).

```
ext-opengl → jovian/ogx → venusian-ogx → Surface
  1:1          typed       composition   cross-platform
```

The host window engine owns the GL context and lends it through
`Surface\Contracts\Drawing\GLSurface`; this package owns everything inside it.

## Install

```bash
composer require jovian/venusian-ogx
```

Registers `VenusianOpenGLServiceProvider`, which binds `OpenGLEngine` as the
`gpu.opengl` singleton. Surface resolves that alias and nothing else.

## The shape of it

```php
$window->gpu('scene', 'opengl', 20, 20, 640, 560)
    ->onDraw(fn (Drawing2D $g, Frame $f) => $g->fillCircle(100.0, 100.0, 40.0, Color::hex('#ff8c00')));

$gpu->executor() instanceof Jovian\Venusian\OpenGL\Contracts\OpenGLDrawing; // bespoke half
$gpu->executor()->program(); // non-zero after the first frame
```

A frame is `makeCurrent` → read the bound FBO → viewport/clear → draws staged
through one `Bridge::alloc` block → `glFlush` → `present`. Blending is on, so
Painter alpha is real here.
