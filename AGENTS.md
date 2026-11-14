# AGENTS.md

1. Bindings are ext-opengl's, 1:1. This package holds the engine, never GL calls ext-opengl does not bind.
2. OpenGL draws its own way: hardware blending, premultiplied storage. Velvet's picture is a loose check (edges and one step of colour may differ), never a byte target. Shaders are GLSL 4.10 core in `resources/shaders/engine`, compiled by the driver at run time.
3. The gate is `tests/smoke.php`, on the GPU with no window, and cost is part of it: time at the caller's real usage against Velvet, Metal and Vulkan.
4. No window or toolkit code here, and no Velvet extension (ext-fb, ext-rasterize, ext-paint, ext-raycast). A stage lends its platform handle through `nativeSurface()`; the engine builds its own surface on it. On macOS that is an `NSOpenGLContext` over the engine's CGL context, on the lent `NSView`. Reading pixels back writes them to an address: ext-appkit's `CFData` is that memory. ext-appkit is used only when loaded and never declared in `composer.json`, so the package installs on Linux unchanged. The context is CGL's; an EGL one on Linux is not built yet.
5. Tests run on the GPU, never skipped, except the presenting tests off macOS.
6. Publish prep is part of done: README examples run, `.okf` validated.
