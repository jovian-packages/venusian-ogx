---
type: Runbook
title: Testing
description: Real-GPU Pest suite on the Mac (CGL) and the Pi (surfaceless EGL) with Surface's GpuParity; temporary path repository; php84, zhp, Pi php.
resource: tests/
tags: [opengl, pest]
status: draft
generated: { by: claude-opus/5.5, at: 2026-10-05T12:00:00Z }
sources:
  - id: pest
    resource: tests/Pest.php
    title: tests/Pest.php
---

# Steps

* The suite runs the real GPU. `tests/Pest.php` fails with a message without ext-opengl. No window or display server: contexts are offscreen (CGL; EGL surfaceless on Linux). Nothing skips.
* `tests/Pest.php` requires Surface's `GpuParity` from the sibling checkout (`dev/venusian/surface/tests`); `SURFACE_TESTS` overrides the directory.
* Surface's 0.10 splits are not on Packagist: add a path repository to `<surface>/src/Surface/*` for the run (`composer config repositories.surface '{"type":"path","url":"…/src/Surface/*","options":{"symlink":true}}'`), `composer update`.
* Mac: `php84 -d memory_limit=128M vendor/bin/pest`, then the same on ZTS (`zhp`).
* Pi: copy this package and the Surface checkout's `src` and `tests` without `vendor/`, `.git` and locks (`tar` piped into `fnk`), path repository `../surface/src/Surface/*`, then `SURFACE_TESTS=<surface>/tests php -d memory_limit=128M vendor/bin/pest`. ext-opengl on the Pi needs `eglGetCurrentDisplay` and `eglGetCurrentSurface`.
* Panel: an app requiring this package, `dept-of-scrapyard-robotics/st77xx` and `microscrap/scrapyard-linux`; `config/circuits/st7796.php` as st77xx's quick start; a script that boots the console kernel and, for `velvet` then `opengl`, makes `app('displays')->panel('st7796', direct: true)`, `app('drawing')->renderer($name, ['output' => $display])`, draws one whole frame and 60 partial ones with `present()` after each, and prints the times and bytes.
* After: remove `vendor/`, `composer.lock` and the repository entry; `git diff composer.json` is empty at commit time.
