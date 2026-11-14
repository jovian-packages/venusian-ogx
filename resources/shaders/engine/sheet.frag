#version 410 core
// units: source

// A layer: its contents (a sheet, premultiplied, its top-left at place.xy on the surface) laid down once a
// sample inside the layer's rounded box, at the layer's opacity (place.z, 0..1). The texel is the pixel's (in surface pixels,
// as `at` is), whichever sample asks. Push: the box x0 y0 x1 y1; radii tl tr br bl; place x, y, opacity, 0; the contents' width and height
// (a pooled image may be larger: nothing past them is read). 56 bytes.
sample in vec2 at;
uniform sampler2D source;
layout(std140) uniform Paint { vec4 box; vec4 radii; vec4 place; vec2 size; } s;
layout(location = 0) out vec4 color;

float corner(float cx, float cy, float r, float y, float side)
{
    float t = (y - cy) / r;
    float h = r * sqrt(max(0.0, 1.0 - t * t));
    return side < 0.0 ? cx - h : cx + h;
}

// Where line y crosses a rounded box: [ends.x, ends.y), empty when ends.y <= ends.x.
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

bool inside(vec2 ends, float x)
{
    return ends.y > ends.x && x >= ends.x && x < ends.y;
}

void main()
{
    if (!inside(across(s.box, s.radii, at.y), at.x)) {
        discard;
    }
    ivec2 local = ivec2(floor(at)) - ivec2(s.place.xy);
    ivec2 size = ivec2(s.size);
    if (local.x < 0 || local.y < 0 || local.x >= size.x || local.y >= size.y) {
        discard;
    }
    color = texelFetch(source, local, 0) * s.place.z;
}
