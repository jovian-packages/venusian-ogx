---
okf_version: "0.2"
---

# jovian/venusian-ogx — knowledge bundle

OpenGL composition for Venusian Surface GPU drawing on macOS and Linux.
`jovian/ogx` projects `ext-opengl` one call at a time; this package owns the
frame, the painter program, and the staging block. Surface talks to it only
through `gpu.opengl`; the host lends its context through `GLSurface`.

Read this index first, then open only the concepts the task needs. Every
concept here is `status: draft` until a human verifies it.

# Concepts

* [executor.md](/executor.md) - lazy init, the bound-FBO rule, the two GLSL
  dialects, the staging block, and the three origin flips
* [seam.md](/seam.md) - the host owns the context and lends it; what
  `GLSurface` promises and what this package never does with it

# Related bundles

* [jovian/ogx](../../ogx/.okf/index.md) - the typed projection this package composes
* [venusian/surface](../../../venusian/surface/.okf/index.md) - the engine-free contracts and Painter
