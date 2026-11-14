#version 410 core

// ROUNDRECT and ROUNDRING, once a sample: in when inside the rounded box and, for a ring, outside the box
// inset by band.x. Push: the colour; the box x0 y0 x1 y1; radii tl tr br bl; inset, ring (1), 0, 0.
sample in vec2 at;
layout(std140) uniform Paint { uvec4 rgba; vec4 box; vec4 radii; vec4 band; } b;
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
    if (!inside(across(b.box, b.radii, at.y), at.x)) {
        discard;
    }
    if (b.band.y > 0.5) {
        float inset = b.band.x;
        vec4 hole = vec4(b.box.xy + inset, b.box.zw - inset);
        if (hole.z > hole.x && hole.w > hole.y && inside(across(hole, max(b.radii - inset, vec4(0.0)), at.y), at.x)) {
            discard;
        }
    }
    float a = float(b.rgba.a) / 255.0;
    color = vec4(vec3(b.rgba.rgb) / 255.0 * a, a);
}
