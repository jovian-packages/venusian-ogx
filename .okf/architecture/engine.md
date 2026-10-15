---
type: Module
title: Engine
description: OpenGLDevice - one GL context (own offscreen or a canvas's), persistent target in two framebuffer objects, one program in core or ES dialect, fixed-function source-over with Velvet's alpha, stencil-then-cover, blit resolve, restore after upload, blitTo into a canvas's framebuffer.
resource: src/
tags: [opengl, opengl-es, cgl, egl, gpu, drawing]
status: draft
generated: { by: claude-opus/5.5, at: 2026-10-05T12:00:00Z }
sources:
  - id: device
    resource: src/OpenGLDevice.php
    title: OpenGLDevice
  - id: framebuffer
    resource: src/OpenGLFramebuffer.php
    title: OpenGLFramebuffer
  - id: frag
    resource: resources/shaders/venusian.frag.glsl
    title: venusian.frag.glsl
---

# Overview

`OpenGLDevice implements Surface\Drawing\Gpu\GpuDevice` over ext-opengl. `GpuRenderingEngine` lowers drawing calls to a `DrawList`; `draw()` renders it in one pass. `OpenGLFramebuffer extends GLFramebuffer` over the resolved texture.[^device][^framebuffer]

# Context

| Made by | macOS | Linux | Destroyed by `release()` |
|---|---|---|---|
| `new OpenGLDevice` | CGL, `kCGLOGLPVersion_GL4_Core`, accelerated | `eglGetPlatformDisplay(EGL_PLATFORM_SURFACELESS_MESA)`, ES 3.1 context, no surface | yes |
| `adopting()` + `adopt()` | `CGLContextObj::fromPointer(handle('context'))` | `EGLContext` from `handle('context')`, `EGLDisplay` from `handle('display')` | no |

`prepareContext()` runs once per context: dialect from `GL_SHADING_LANGUAGE_VERSION` (`es` when it names ES), `GL_MAX_SAMPLES`, `GL_MAX_TEXTURE_SIZE`, the program, the 1 × 1 blank texture, the vertex array and buffer. `with()` wraps every GL use: when the device's context is not current it saves the current context (EGL: display, draw and read surfaces too), makes its own current, and restores after. When it is current, nothing switches: a canvas's context stays current with its own surfaces. A failed make-current throws before any GL call. An adopted device keeps its `LentSurface` and registers `onRelease()`: the canvas releases the surface before it takes the context away, and the device then deletes everything it made in that context and marks itself released. No GL call is made after; a later `release()` does nothing.

# Target

| Object | Storage | Samples | Role |
|---|---|---|---|
| `resolved` texture in `resolvedFbo` | RGBA8 | 1 | read back, uploaded to, resolved into, blitted from |
| `msaaColor` renderbuffer in `msaaFbo` | RGBA8 | 4 | drawn into; absent with 1 sample |
| `stencil` renderbuffer | DEPTH24_STENCIL8 | 1 or 4 | on `resolvedFbo` (1 sample) or `msaaFbo` (4); cleared every frame, left at zero by cover |
| `blank` texture | RGBA8, 1 × 1 | 1 | on unit 0 for every draw that samples nothing |

`target()` accepts 1 or 4 samples (4 needs `maxSamples()` ≥ 4), 1 × 1 up to `GL_MAX_TEXTURE_SIZE`, and clears both framebuffers to transparent black. The resolved texture belongs to its `OpenGLFramebuffer`, deleted by `forget()` when that object goes; a re-made target leaves the old one readable.

# Rows

GL's row 0 is the bottom. The vertex shader maps target pixel y = 0 to clip y = +1, so the target texture is bottom-up. Reads and uploads first set the pixel store to tightly packed (alignment 1, row length 0, skips 0, no pixel buffer). `read()` reads `y = height − bottom()` and reverses the rows; `write()` reverses them and writes at the same y. `Op::SCISSOR` flips its y the same way. The fragment shader samples a source with `texelFetch(source, (u, v))`, v the Surface row: a CPU source is uploaded top-down as it is, and a texture of this device (restore, own framebuffer as image) goes through `upright()`, a `glBlitFramebuffer` with the destination rows reversed into a new texture.

# Frame

`draw()`: sources (too large cancels before drawing; each gets a texture), vertices plus one whole-target quad into the vertex buffer, program and uniforms, viewport, blank on unit 0; the restore when `stale`; bind `msaaFbo` (4) or `resolvedFbo` (1), clear the stencil, scissor on; every operation; `glBlitFramebuffer` `msaaFbo` → `resolvedFbo` with 4 samples; `glFlush`; `glGetError` checked; the frame's textures deleted.

| Operation | State |
|---|---|
| CLEAR | blend off, whole-target scissor, SOLID over the whole quad, then the current scissor back |
| SOLID | blend off |
| STENCIL_FILL | colour mask off, stencil always; NON_ZERO: front `GL_INCR_WRAP`, back `GL_DECR_WRAP`; EVEN_ODD: `GL_INVERT`; SOLID paint (never discards) |
| COVER | stencil not-equal 0, pass `GL_ZERO`; FILL, blend on |
| RECTS | FILL, blend on |
| ELLIPSE, RING | OVAL, blend on; `band.z` = samples |
| IMAGE | IMAGE, blend on, the source texture on unit 0 |

Source-over: `glBlendFuncSeparate(GL_SRC_ALPHA, GL_ONE_MINUS_SRC_ALPHA, GL_ONE, GL_ONE_MINUS_SRC_ALPHA)`, `GL_FUNC_ADD`.[^frag]

# Restore

`write()` into `resolved` with 4 samples sets `stale`. The next `draw()`, after its sources and before its operations, draws the upright copy of `resolved` into `msaaFbo` in COPY mode, scissor and blend off.

# Canvas

A GL canvas calls `present($surface)` in its view's render callback: an adopted device reads `GL_DRAW_FRAMEBUFFER_BINDING` (the view's framebuffer the toolkit bound) and copies into it at `$surface->size()`, answering true; a device with its own context, or a released surface, answers false. `blitTo($framebuffer, $width, $height)` is the same copy into a named framebuffer (adopted devices only). Both run `copyInto()` through `with()`: `glBlitFramebuffer` from `resolvedFbo`, stretched, nearest, scissor off, no pixel-pack buffer, the destination left bound. Both are GL framebuffers, so rows need no flip.

# Release

`release()` runs once (and from `__destruct` for an owned context): `glFinish`, then deletes the framebuffer objects, renderbuffers, every tracked texture (live framebuffers', the blank), vertex array, buffer and program, then destroys the context only when this device made it, without un-making it current first. Read, write, `target()`, `current()` and `blitTo()` afterwards throw `opengl: this device was released.`. Shaders are deleted once attached; a compile or link failure deletes what it made.

[^device]: src/OpenGLDevice.php
[^framebuffer]: src/OpenGLFramebuffer.php
[^frag]: resources/shaders/venusian.frag.glsl

# Presenting and HDR

Present = show pass into bound framebuffer: `presentRect()`, window's filter, black bars, encoded. Host's GL context = SDR only (host picks pixel format). HDR / P3: make engine on a GLFW or SDL3 staged window with `format` / `colorspace` → device gets native window (`'native'` handle, lend carries `'context' => 0`), makes own surface:

- macOS: NSOpenGLContext, half floats, EDR view, extended-linear window colour space (HDR10 shown through EDR: no PQ GL surface on macOS); needs ext-appkit.
- Linux: EGL window surface in `EGL_GL_COLORSPACE` (scRGB linear half floats, BT.2020 PQ 10-bit + SMPTE 2086 / CTA 861.3 metadata, Display P3) where the display lists the extension; else refused naming it. Wayland swaps at interval 0. Pi 5 Mesa lists none (measured): refused there.

    $engine = $drawing->engine('opengl', ['output' => $stagedWindow, 'format' => TargetFormat::Rgba16Float]);

Offscreen device draws every pair (readback, `hdrSnapshot()`).
