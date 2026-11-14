---
type: Module
title: Engine
description: OpenGLContext and its four resources on Topside - a CGL 4.1 core context of its own; framebuffers with a four-sample twin resolved when read; stencil-then-cover with a convex fast path; a painter that draws a pass from one vertex and one block upload; filters, glass and layers on the GPU; a raycaster walked by a fragment pass; frames shown through an NSOpenGLView of its own.
resource: src/
tags: [opengl, cgl, gpu, graphics, topside]
status: draft
generated: { by: claude-opus/5.5, at: 2026-11-14T00:00:00Z }
sources:
  - id: context
    resource: src/OpenGLContext.php
    title: OpenGLContext
  - id: framebuffer
    resource: src/OpenGLFramebuffer.php
    title: OpenGLFramebuffer
  - id: painter
    resource: src/OpenGLPainter.php
    title: OpenGLPainter
  - id: caster
    resource: src/OpenGLCaster.php
    title: OpenGLCaster
  - id: surface
    resource: src/OpenGLSurface.php
    title: OpenGLSurface
---

# Context

`OpenGLContext` is the engine Graphics hands out as `opengl`, on the GPU backend only. It opens a CGL OpenGL 4.1 core context of its own, offscreen, and makes it current before every use [^context]. On Linux it throws: an EGL context is not built.

* Uniform blocks carry what a draw needs: `Frame` (binding 0: the target's size and where it starts on the surface), `Paint` (binding 1: one draw's data), `Walls` (binding 2: a raycast scenery's wall table). A pass uploads all its blocks in one buffer, each at the offset alignment, and binds each by range.
* A shader names its samplers on a `// units:` line; `program()` gives them texture units 0, 1, ... in that order.
* Pixels come back through `glReadPixels` into ext-appkit's `CFData`, kept and grown. Without ext-appkit, reading throws, naming it.
* Row 0 of every texture is the surface's top: `place.vert` maps pixel y 0 to window row 0, so window coordinates are surface pixels and nothing is flipped until a frame is shown.

# Framebuffer

An RGBA8 texture of premultiplied colour, a four-sample colour and stencil twin, and a hard-edge stencil [^framebuffer]. The texture is behind the twin after antialiased draws until something reads it: `resolve()` blits the region drawn, and every reader calls it first (`textureName()`, read, write, scroll, a hard-edged draw). A resolve is a blit of its own in GL, measured at 0.048 ms of CPU whatever its size. A transient framebuffer (a layer's sheet) holds its texture alone and draws through the engine's one scratch twin, which grows to the largest sheet: a sheet is drawn in one pass, resolved at its end, before another's starts.

# Painting

The rasterizer and painter stencil a path's fans (counting up and down for non-zero, flipping for even-odd), then cover its box. A path of one convex contour (every turn the same way, once round) is drawn as its fan in one step: its triangles never overlap, so every sample is coloured once, the same bytes as stencil-then-cover [^painter].

The painter's shaders are the vulkan engine's, converted to GLSL 4.10 core: push constants became the `Paint` block (every member a vec4 or scalar, so std140 places them where std430 did; the colour matrix is `vec4 m[5]`), descriptor bindings became named units. Filter steps read their data from R32UI textures, 1024 words a row, written with `glTexSubImage2D`.

# Raycasting

OpenGL 4.1 has no compute, so the hits pass is a fragment pass over an RGBA32F target, one pixel a ray's row, walking Velvet's grid from the ray's cell and its fraction [^caster]. The grid's cells and the wall textures are R32UI textures 1024 words a row: a grid is limited by its cells, not by a texture side. A 1x1 RG32F stands in for rays with no directions of their own, so the driver never validates a sampler against no texture.

# Showing

On a stage that lends an `NSView`, the engine adds a child `NSOpenGLView` at full resolution, whose double-buffered context shares the engine's textures and programs: `NSOpenGLContext::initWithFormatShareContext` over `NSOpenGLContext::initWithCGLContextObj` of the engine's own context [^surface]. Showing resolves and flushes the frame in the engine's context, draws its texture flipped into the view's back buffer, and swaps without waiting for the display.

[^context]: OpenGLContext
[^framebuffer]: OpenGLFramebuffer
[^painter]: OpenGLPainter
[^caster]: OpenGLCaster
[^surface]: OpenGLSurface
