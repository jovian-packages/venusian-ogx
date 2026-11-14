# Log

## 2026-11-14

* The engine is rebuilt on Topside: `OpenGLContext` with framebuffers, a rasterizer, a painter and a raycaster on the GPU, frames shown through an `NSOpenGLView` of its own. The old engine is parked in `deprecated/`. See [engine](architecture/engine.md) and [testing](runbooks/testing.md).

## 2026-11-08

* G3 reuse (Surface drawing-path spec): the vertex buffer is a ring of three (a vertex array each) written in place, and the frame's source textures (uploads, the restore's copy) go back to a pool by size and kind (8 a size, 24 sizes) instead of being deleted; a texture a pass already let go of is not pooled. Parity: all 63 Surface scenes pass; `OpenGLDeviceTest` draws six frames through the ring and pool with a growth between. Data and Lists tabs at scale 2: 42 and 21 ms CPU a frame before and after; the device's share of a frame is small.

## 2026-11-07

* Glass (Surface drawing-path spec, G4): `OpenGLDevice` is `DrawsGlass`. `GLASS_BACKDROP` takes an upright copy of the target, then the glass program (`glass.frag.glsl`: capture, down, up; integer texel reads, ext-fb's levels byte for byte) draws each pyramid level into a pooled texture of its own size; the pyramid is kept across frames while Lowering says nothing under it changed. `GLASS` is a mode of the main program (`venusian.frag.glsl`, mode 9): Velvet's per-pixel steps at the pixel centre, edge by the same distance ramp ROUND uses. Against Velvet+ext on a glass scene: max channel difference 11, 0.15 % of channels over 2; the kept pyramid draws the same. Glass tab, CPU time per frame: 4.9 ms at scale 1, 5.4 ms at scale 2.
* Filter passes FIELD (an image from the pixel position: a rounded box's windowed distance and direction, its inside mask, a ramp) and TABLE (a 256-entry lookup per straight channel), and a displacement whose map is an earlier pass's result (`params[5]`). Parity: the new scenes `layer fields`, `layer tables` and `layer displacement by a made map` pass on this engine (`venusian/surface/scripts/gpu-parity.php`).

* Layers: the TARGET, BACKDROP, FILTER and LAYER_SOURCE operations. A layer target is an RGBA8 texture with a stencil, multisampled as the frame's is, made on first use and kept until the target changes; BACKDROP and FILTER write a target's texture directly, so a resolve after them would write the multisampled drawing over it (`$baked`). A filter chain is Surface's `FilterPlan`: one draw of `resources/shaders/filter.frag.glsl` per pass, over the region, into a texture of its own, rows top-down (a pixel's memory row is its surface row); the numbers a pass needs beyond four floats go in a texture, a float32's four bytes to an RGBA8 texel (this context has no float texture formats, ext-opengl no uniform arrays), rebuilt exactly in the shader. 57 parity tests pass on Homebrew PHP 8.4 (Mac, CGL 4.1): the layer, filter, backdrop, noise and displacement scenes are byte for byte Velvet's; the rounded-layer scene differs only at the rounded edge, as every rounded shape does. Layers on an HDR target throw.

## 2026-10-28

* **Version**: 0.11.0 with the Venusian 0.11 ecosystem: `dev-main` → `0.11.x-dev`, its extensions, `venusian-surface/*`, `venusian-voyager/*` and the jovian siblings at `^0.11.0`, PHP `~8.4.27||^8.5.12`, homepage `0.11.x`. No API change. 105 passed, 2 skipped on Homebrew PHP 8.4 NTS and ZTS; 106 passed, 1 skipped on the Pi (8.4 ZTS).

## 2026-10-08

* Staged windows phase 6: show-pass presents, vsync, frames in flight, HDR, hidden windows, see [architecture](architecture/engine.md).

## 2026-10-05

* venusian-opengl 0.10.0: [engine](architecture/engine.md), [testing](runbooks/testing.md).
