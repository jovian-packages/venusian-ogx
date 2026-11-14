#version 410 core
// units: source

// An image: another framebuffer's image (premultiplied colour) under the inverse placement of the pixel's centre,
// nearest or a blend of the four texels around the point. abcd and efwh: the inverse placement and the source's
// size. options: opacity 0..255; linear (1) or nearest (0); the tint as 0xRRGGBBAA; flags (1 rounded, 2 window).
// win: the window u0 v0 u1 v1. box and radii (tl tr br bl): the rounded box. 96 bytes, inside the 112 the
// fragment stage has.
sample in vec2 at;
uniform sampler2D source;
layout(std140) uniform Paint { vec4 abcd; vec4 efwh; uvec4 options; vec4 win; vec4 box; vec4 radii; } p;
layout(location = 0) out vec4 color;

float corner(float cx, float cy, float r, float y, float side)
{
    float t = (y - cy) / r;
    float h = r * sqrt(max(0.0, 1.0 - t * t));
    return side < 0.0 ? cx - h : cx + h;
}

vec2 across(vec4 box, vec4 r, float y)
{
    if (y < box.y || y >= box.w) {
        return vec2(0.0, 0.0);
    }
    float left = box.x;
    if (y < box.y + r.x) {
        left = corner(box.x + r.x, box.y + r.x, r.x, y, -1.0);
    } else if (y > box.w - r.w) {
        left = corner(box.x + r.w, box.w - r.w, r.w, y, -1.0);
    }
    float right = box.z;
    if (y < box.y + r.y) {
        right = corner(box.z - r.y, box.y + r.y, r.y, y, 1.0);
    } else if (y > box.w - r.z) {
        right = corner(box.z - r.z, box.w - r.z, r.z, y, 1.0);
    }
    return vec2(left, right);
}

void main()
{
    vec2 centre = floor(at) + 0.5;
    float u = p.abcd.x * centre.x + p.abcd.z * centre.y + p.efwh.x;
    float v = p.abcd.y * centre.x + p.abcd.w * centre.y + p.efwh.y;
    float w = p.efwh.z;
    float h = p.efwh.w;
    if (!(u >= 0.0 && u < w && v >= 0.0 && v < h)) {
        discard;
    }
    // the window crops; the texels beside it are still read by the smooth filter
    if ((p.options.w & 2u) != 0u && !(u >= p.win.x && u < p.win.z && v >= p.win.y && v < p.win.w)) {
        discard;
    }
    // rounded corners: a sample is in when it lies inside the rounded box
    if ((p.options.w & 1u) != 0u) {
        vec2 ends = across(p.box, p.radii, at.y);
        if (!(ends.y > ends.x && at.x >= ends.x && at.x < ends.y)) {
            discard;
        }
    }

    vec4 c;
    if (p.options.y == 0u) {
        c = texelFetch(source, ivec2(int(floor(u)), int(floor(v))), 0);
    } else {
        vec2 q = vec2(u, v) - 0.5;
        vec2 o = floor(q);
        vec2 t = q - o;
        vec2 last = vec2(w, h) - 1.0;
        ivec2 a = ivec2(clamp(o, vec2(0.0), last));
        ivec2 b = ivec2(clamp(o + 1.0, vec2(0.0), last));
        c = mix(mix(texelFetch(source, a, 0), texelFetch(source, ivec2(b.x, a.y), 0), t.x),
                mix(texelFetch(source, ivec2(a.x, b.y), 0), texelFetch(source, b, 0), t.x), t.y);
    }
    // the tint multiplies the straight colour and the alpha; then the opacity
    uint tint = p.options.z;
    vec4 k4 = vec4(float((tint >> 24) & 255u), float((tint >> 16) & 255u), float((tint >> 8) & 255u), float(tint & 255u)) / 255.0;
    float k = k4.a * float(p.options.x) / 255.0;
    color = vec4(c.rgb * k4.rgb * k, c.a * k);
}
