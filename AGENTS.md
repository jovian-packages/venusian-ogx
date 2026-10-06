# AGENTS.md

1. Bindings are ext-opengl's, 1:1. This package holds the engine, never GL calls ext-opengl does not bind.
2. The shader body is venusian-sdl3's maths: change both or neither. Only the qualifiers and the uniform declarations differ. See [.okf/architecture/engine.md](.okf/architecture/engine.md).
3. The parity suite is the gate, on the Mac (CGL) and the Pi (EGL): `tests/ParityTest.php` through Surface's `GpuParity`.
4. No window code here. Lending a GL context lives in the toolkit packages; they call `blitTo()`.
5. Tests run on the GPU, never skipped.
6. Publish prep is part of done: README examples run, `.okf` validated.
