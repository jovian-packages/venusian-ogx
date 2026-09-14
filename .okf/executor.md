---
type: Component
title: OpenGLExecutor
description: One program, VAO, VBO, EBO and staging block per executor, made at the first frame after the host made its context current; draws into the bound framebuffer.
resource: src/OpenGLExecutor.php
tags: [opengl, gles, executor, glsl, staging]
status: draft
generated: { by: cursor-grok-4.6/cursor, at: "2026-09-14T02:20:00Z" }
sources:
  - id: executor
    resource: src/OpenGLExecutor.php
    title: OpenGLExecutor
  - id: shaders
    resource: src/Shaders/painter.glsl.php
    title: Painter GLSL
  - id: spec
    resource: ../../venusian/surface/docs/superpowers/specs/2026-09-13-gpu-drawing-slice2-opengl-design.md
    title: Slice 2 design
  - id: bridge
    resource: ../../ogx/src/Runtime/Bridge.php
    title: jovian/ogx Bridge — alloc registry
---

# Lifecycle

`attach()` builds the executor with no GL call. The first `beginFrame()`
calls `gl->makeCurrent()`, then `Bridge::load()` (so the version gate samples
this context), reads `GL_VERSION` to pick the dialect, compiles and links the
painter program with attributes bound by index (no `layout()` in GLSL 1.50 or
ES 3.00), mints the VAO/VBO/EBO with attribute pointers set once (stride 36),
enables `BLEND` `SRC_ALPHA / ONE_MINUS_SRC_ALPHA`, and allocates the staging
block.[^executor]

Every frame: `makeCurrent`, `glGetIntegerv(DRAW_FRAMEBUFFER_BINDING)` → the
frame's target (GtkGLArea's FBO, or 0 on AppKit), viewport, unscissor, clear.
`beginFrame()` always answers true — GL has no "no drawable" tick.
`endFrame()`: `glFlush`, `present()`.

# Decisions

- **Bound FBO, never 0.** GtkGLArea renders into its own framebuffer; binding
  0 draws nowhere.
- **Two dialects, one template.** `ES_300` + `precision mediump float;` when
  `GL_VERSION` starts with `OpenGL ES`, else `CORE_150`. `CORE_140` absent.
- **GLES-safe intersection only.** The ext gate cannot see GLES; no desktop-only names.
- **Three flips, no clip flip.** `viewport()`, `scissor()` and
  `readPixels()` flip against the drawable height; Surface's orthographic
  already maps y-down pixels to y-up NDC.
- **One staging block.** 16 B scratch, 64 B transform, payload grown by
  doubling (`grownSize`); freed in `release()`.
- **Blending on.** The first engine where Painter alpha is real.
- **Textures are RGBA8, no swizzle.** `UNPACK_ALIGNMENT 1`, `LINEAR`,
  `CLAMP_TO_EDGE` (package enum — ogx has no wrap enum).
- **`Bridge::read` / `write` take the alloc base.** ext-opengl tracks
  buffers by the exact pointer `alloc()` returned. Derived bits
  (`$staging + StagingRegion::PAYLOAD`) are valid for GL calls
  (`glBufferData`, `glReadPixels`, …) and invalid for `Bridge::read` /
  `write` — those use `$this->staging` plus the region offset. A derived
  pointer returns `null` / `false`.[^bridge]

[^executor]: OpenGLExecutor
[^bridge]: jovian/ogx Bridge — alloc registry
