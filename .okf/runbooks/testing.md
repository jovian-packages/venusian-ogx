---
type: Runbook
title: Testing
description: Four check scripts on the real GPU with no window, each given a venusian/topside checkout; zhp on the Mac.
resource: tests/
tags: [opengl, testing]
status: draft
generated: { by: claude-opus/5.5, at: 2026-11-14T00:00:00Z }
sources:
  - id: smoke
    resource: tests/smoke.php
    title: tests/smoke.php
---

# Steps

* Every check runs on the real GPU and opens no window. Each takes the path to a `venusian/topside` checkout and uses its `vendor/` and test helpers [^smoke].
* `zhp tests/smoke.php <topside>`: framebuffers, the rasterizer, the painter (shapes, gradients, images, layers, filters, glass), presenting into a bare `NSView`. 65 checks.
* `zhp tests/filters.php <topside>`: each filter step against `PHPLandFilters`. 35 checks.
* `zhp tests/glass.php <topside>`: Kawase levels and panes against `PHPLandGlass`, byte for byte, and pyramids built only for panes a frame redraws.
* `zhp tests/raycast.php <topside>`: rays, fans, `casts()` and views against Velvet's PHP marcher. 16 checks.
* Velvet's picture is a loose check: antialiased edges come from four samples a pixel, so edge bytes differ from Velvet's exact coverage. Each script exits 1 when a check fails.
* The old engine's Pest suite is parked in `deprecated/tests`.

[^smoke]: tests/smoke.php
