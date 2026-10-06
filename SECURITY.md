# Security Policy

## Supported versions

Venusian is pre-1.0. No 0.x release receives security fixes or advisories; fixes land in the
next release line. Security support starts with 1.0.

| Version | Security fixes |
|---------|----------------|
| < 1.0   | No             |

## Reporting a vulnerability

Please don't open a public issue for a security problem.

Report it privately through GitHub: the **Report a vulnerability** button on this repository's
**Security** tab. If that isn't available, email **info@projectsaturnstudios.com**.

Include what you found, the affected version, and steps to reproduce. Reports are read and
weighed for the release line in development; before 1.0 there is no response-time commitment.

## Security model

- The shaders are the package's own files, never user input, compiled at context setup under the device's own dialect header.
- A `LentSurface` handle is an address trusted to be a `CGLContextObj` or `EGLContext` (and an `EGLDisplay`), ext-opengl's `fromPointer` rule. It comes only from a toolkit package's canvas.
- Image sources are read through `toRgba8()` and uploaded at the source framebuffer's own size; a source or target past `GL_MAX_TEXTURE_SIZE` is refused before GL sees it.
- Reads and uploads are sized by the region: `OpenGLFramebuffer` refuses a region outside the target and an upload whose bytes do not fill its region exactly, and the device sets pack and unpack alignment 1, row length 0 and skips 0 first, so GL never reads or writes past either. Readback regions for a display come from `GLFramebuffer::flushRegion()`.
- `blitTo()` takes a framebuffer name the canvas owns and runs only in the context the canvas lent.
- Once the canvas releases its surface the device makes no GL call, so a context the toolkit has destroyed is never made current.
