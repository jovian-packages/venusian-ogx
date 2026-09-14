# Agent guidelines — jovian/venusian-ogx

## Knowledge Bundle (OKF)

This package ships an Open Knowledge Format bundle at [`.okf/`](.okf/)
(excluded from the Composer dist via `.gitattributes` `export-ignore`).
Before changing code or advising on this package: read
[`.okf/index.md`](.okf/index.md) first, open only the concepts the task
needs, prefer `status: stable` over `draft`. When you learn something
durable, update the affected concept(s) and append [`.okf/log.md`](.okf/log.md);
new or changed concepts stay `status: draft` until a human verifies them.

Do **not** create `.okf` folders under `src/` — knowledge for this package
lives at the package root only.

## Where this package sits

`ext-opengl` (1:1 binding + glue) → `jovian/ogx` (enums + typed projection) →
**`jovian/venusian-ogx`** (composition) → `venusian/surface` (cross-platform
abstraction). Mac **and** Linux.

**This is the layer where opinion is allowed.** `jovian/ogx` may only
project one extension call per method, and Surface may not know OpenGL
exists, so everything that bundles GL calls into a frame, a program, or
a staging block belongs here.

Never import `Jovian\Bindings\AppKit\*`, `Jovian\Bindings\Gtk\*`,
`Jovian\Venusian\AppKit\*` or `Jovian\Venusian\GTK\*`. The host owns the GL
context; this package only ever receives it through
`Surface\Contracts\Drawing\GLSurface` on the `GPUHost`.

Never build a cross-platform abstraction here — that is Surface's job.

## Package rules (quick) — 0.8.x

- Composer: `jovian/venusian-ogx` **0.8.0**. PHP `^8.4|^8.5|^8.6`. Requires
  `jovian/ogx`, `surface/contracts`, `surface/drawing`, `venusian/surface`,
  `venusian-voyager/contracts`, `venusian-voyager/nuts-and-bolts`.
- Namespace root is `Jovian\Venusian\OpenGL\` at `src/`.
- **The provider binds `gpu.opengl`.** Do not rename it.
- **Host owns the context; engine owns GL state.** `attach()` refuses a
  `GPUHost` with no `gl`. The executor makes current before every frame and
  presents after it; it never creates, destroys or shares a context.
- **Lazy init at the first `beginFrame()`.** No host has a usable context
  before then. `Bridge::load()` is called *after* `makeCurrent()` so the
  version gate samples the right context.
- **Draw into the bound framebuffer.** `beginFrame()` reads
  `DRAW_FRAMEBUFFER_BINDING`; GtkGLArea renders into its own FBO and binding
  0 draws nowhere.
- **Two dialects, one template.** `GLSLDialect::fromVersionString()` picks
  `ES_300` when `GL_VERSION` starts with `OpenGL ES`, else `CORE_150`.
  `CORE_140` is deliberately absent.
- **GLES-safe calls only.** The version gate cannot see GLES. Never call
  `glPolygonMode`, `glClearDepth`, `glMapBuffer`, fences, or anything else
  outside the GLES 3.1 intersection.
- **One VAO per executor** — core profile has no default VAO.
- **One staging block per executor** (`Bridge::alloc`), grown on demand,
  freed in `release()`. `glShaderSource` is the only array-marshalling call;
  every other pointer is block bits. **`Bridge::read` / `write` take the
  alloc base plus a `StagingRegion` offset** — ext-opengl registries the
  exact `alloc()` pointer. Derived bits (`$staging + PAYLOAD`) are for GL
  calls only (`glBufferData`, `glReadPixels`, …).
- **Blending is on.** `capabilities()->blending` is true — Painter alpha is
  real on this engine before Metal.
- **GL origin is bottom-left.** `viewport()`, `scissor()` and `readPixels()`
  flip against the drawable height; clip space does not.
- **Missing enums are package enums.** `TextureWrap::CLAMP_TO_EDGE` (0x812F);
  min/mag filter is `BlitFramebufferFilter::LINEAR->value`.
- Enums are int- or string-backed with FULLY UPPERCASE cases. **No class
  constants anywhere.** Prefer `is_null($var)` over `$var === null`.

## Verification

```bash
vendor/bin/pest            # pure tests always; Feature/ needs ext-opengl
php -l src/OpenGLExecutor.php
composer validate
```

Mac: export `HERD_PHP_84_INI_SCAN_DIR` first so the CLI sees `ext-opengl`.
Pi: `Feature/` runs on an EGL surfaceless context; windowed proof is the
`orbit` sketch under `XDG_RUNTIME_DIR=/run/user/1000 DISPLAY=:0`.
