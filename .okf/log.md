# Log

## 2026-09-13
* **Initialization**: Bundle seeded with the package — slice 2 of GPU drawing.
* **Creation**: [OpenGLExecutor](/executor.md), [Host owns the context](/seam.md).
* **Note**: `Bridge::read` / `write` key off the `alloc()` pointer; payload
  GL calls use derived bits, PHP copies use the base plus `StagingRegion`.
  The same landmine is now in `AGENTS.md` next to the staging-block bullet.
