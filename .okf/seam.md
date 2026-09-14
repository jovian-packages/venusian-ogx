---
type: Architecture
title: Host owns the context, engine owns GL state
description: Why the OpenGL engine receives a GLSurface instead of making a context, and what it may and may not do with it.
resource: src/OpenGLEngine.php
tags: [opengl, seam, glsurface, layering]
status: draft
generated: { by: cursor-grok-4.6/cursor, at: "2026-09-14T02:20:00Z" }
sources:
  - id: engine
    resource: src/OpenGLEngine.php
    title: OpenGLEngine
---

# The shape

Metal hands the window engine a layer. OpenGL is the other way round: the
`NSOpenGLContext` and the `GdkGLContext` can only be made by a window
toolkit, which this package may not import. So `GPUEngineDriver::surfaceKind()`
answers `GL_CONTEXT`, the window engine mints the surface first, passes it
as `GPUHost->gl`, and `attach()` refuses a null `gl` with
`OpenGLDrawingException`.

`GLSurface` promises three things: `makeCurrent()` before a frame,
`present()` after it, `drawableSize()` in pixels. On GTK `present()` is a
no-op — GTK swaps when the render signal returns.

# Never

Create, destroy, share or query a context; import any AppKit or GTK symbol;
assume framebuffer 0.
