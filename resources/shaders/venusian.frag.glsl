// One program, a mode per operation. The colour out is straight (not
// premultiplied) with the alpha Velvet would blend at; the pipeline's blend
// state does the source-over. SOLID and COPY run with blending off.
in vec2 at;
in vec2 atlas_at;
out vec4 color;
uniform sampler2D source;
// ext-opengl sets no unsigned vectors: the two uvec4 arrive as floats holding
// whole numbers 0..255 and are rebuilt at the top of main().
uniform vec4 mode_opacity;    // mode, opacity, linear, flags (1 HDR target, 2 half-float source)
uniform vec4 rgba_in;         // r, g, b, a
uniform vec4 shape;           // cx, cy, rx, ry
uniform vec4 band;            // OVAL: stroke, hole, samples; ROUND: inset, ring, samples; SHADE: blur, radius, samples
uniform vec4 radii;           // ROUND: [tl, tr, br, bl]; shape is then the box x0, y0, x1, y1
uniform vec4 abcd;            // the inverse placement
uniform vec4 efwh;            // e, f, source width, source height

const uint SOLID = 0u;
const uint FILL = 1u;
const uint OVAL = 2u;
const uint IMAGE = 3u;
const uint COPY = 4u;
const uint ROUND = 5u;
const uint SHADE = 6u;
const uint GLYPH = 7u;

// Velvet's alpha for a shape: (α · coverage + 127) / 255, coverage in 0..255.
float shaped(uint alpha, float coverage)
{
    uint c = uint(round(clamp(coverage, 0.0, 1.0) * 255.0));
    return float((alpha * c + 127u) / 255u) / 255.0;
}

// Coverage of an ellipse at this pixel: 1 inside, 0 outside, a ramp one pixel
// wide across the edge; with one sample, in or out by the pixel's centre.
float ellipse(vec2 p, vec2 centre, vec2 radii, bool hard)
{
    vec2 q = (p - centre) / radii;
    float d = (length(q) - 1.0) * min(radii.x, radii.y);
    return hard ? (d <= 0.0 ? 1.0 : 0.0) : clamp(0.5 - d, 0.0, 1.0);
}

// Where line y crosses a rounded box, as Rasterize's roundAcross(): [ends.x, ends.y), empty when ends.y <= ends.x.
float corner(float cx, float cy, float r, float y, float side)
{
    float t = (y - cy) / r;
    float h = r * sqrt(max(0.0, 1.0 - t * t));
    return side < 0.0 ? cx - h : cx + h;
}

vec2 across(vec4 box, vec4 r, float y)
{
    if (y < box.y || y >= box.w) {
        return vec2(0.0);
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

// Coverage of a rounded box at this pixel: with one sample, in or out by the pixel's centre as Rasterize decides;
// otherwise 1 inside, 0 outside, a ramp one pixel wide across the edge (as ellipse()).
float roundbox(vec2 p, vec4 box, vec4 r, bool hard)
{
    if (hard) {
        vec2 ends = across(box, r, p.y);
        return ends.y > ends.x && p.x >= ends.x && p.x < ends.y ? 1.0 : 0.0;
    }
    vec2 c = (box.xy + box.zw) / 2.0;
    vec2 h = (box.zw - box.xy) / 2.0;
    float k = p.x < c.x ? (p.y < c.y ? r.x : r.w) : (p.y < c.y ? r.y : r.z);
    vec2 q = abs(p - c) - h + k;
    float d = min(max(q.x, q.y), 0.0) + length(max(q, vec2(0.0))) - k;
    return clamp(0.5 - d, 0.0, 1.0);
}

// sRGB-encoded channels to linear light: what an HDR target holds, SDR white at 1.0.
vec3 linear_light(vec3 c)
{
    return mix(c / 12.92, pow((c + 0.055) / 1.055, vec3(2.4)), step(vec3(0.04045), c));
}

void main()
{
    uvec4 mode_rgba[2];
    mode_rgba[0] = uvec4(mode_opacity);
    mode_rgba[1] = uvec4(rgba_in);
    uint mode = mode_rgba[0].x;
    vec4 rgba = vec4(mode_rgba[1]) / 255.0;
    vec2 centre = floor(at) + 0.5;
    bool hdr = (mode_rgba[0].w & 1u) != 0u;

    if (mode == SOLID) {
        color = hdr ? vec4(linear_light(rgba.rgb), rgba.a) : rgba;
        return;
    }
    if (mode == FILL) {
        color = hdr ? vec4(linear_light(rgba.rgb), rgba.a) : rgba;
        return;
    }
    if (mode == COPY) {
        color = texelFetch(source, ivec2(centre), 0);
        return;
    }
    if (mode == ROUND) {
        bool hard = band.z < 2.0;
        float coverage = roundbox(centre, shape, radii, hard);
        if (band.y > 0.5) {
            float inset = band.x;
            vec4 hole = vec4(shape.xy + inset, shape.zw - inset);
            if (hole.z > hole.x && hole.w > hole.y) {
                coverage -= roundbox(centre, hole, max(radii - inset, vec4(0.0)), hard);
            }
        }
        if (coverage <= 0.0) {
            discard;
        }
        color = vec4(hdr ? linear_light(rgba.rgb) : rgba.rgb, shaped(mode_rgba[1].w, coverage));
        return;
    }
    if (mode == SHADE) {
        // Once a pixel, at its centre: Rasterize's roundDistance() and shade(), in the same order.
        vec2 c = (shape.xy + shape.zw) / 2.0;
        vec2 h = (shape.zw - shape.xy) / 2.0;
        float r = band.y;
        float blur = band.x;
        vec2 q = abs(centre - c) - h + r;
        vec2 o = max(q, vec2(0.0));
        float d = min(max(q.x, q.y), 0.0) + sqrt(o.x * o.x + o.y * o.y) - r;
        float cover;
        if (blur > 0.0) {
            float t = clamp((d + blur) / (2.0 * blur), 0.0, 1.0);
            cover = 1.0 - t * t * (3.0 - 2.0 * t);
        } else {
            cover = d < 0.0 ? 1.0 : 0.0;
        }
        uint v = uint(floor(cover * 255.0 + 0.5));
        if (band.z < 2.0) {
            v = v >= 128u ? 255u : 0u;
        }
        uint a = (mode_rgba[1].w * v + 127u) / 255u;
        if (a == 0u) {
            discard;
        }
        color = vec4(hdr ? linear_light(rgba.rgb) : rgba.rgb, float(a) / 255.0);
        return;
    }
    if (mode == GLYPH) {
        // The atlas (bound as source, single channel) under the texel, nearest; blended as paintSpans() blends it.
        uint c = uint(round(texelFetch(source, ivec2(floor(atlas_at)), 0).r * 255.0));
        uint a = (mode_rgba[1].w * c + 127u) / 255u;
        if (a == 0u) {
            discard;
        }
        color = vec4(hdr ? linear_light(rgba.rgb) : rgba.rgb, float(a) / 255.0);
        return;
    }
    if (mode == OVAL) {
        bool hard = band.z < 2.0;
        float half_stroke = band.x / 2.0;
        float coverage = ellipse(centre, shape.xy, shape.zw + half_stroke, hard);
        if (band.y > 0.5) {
            vec2 inner = shape.zw - half_stroke;
            if (inner.x > 0.0 && inner.y > 0.0) {
                coverage -= ellipse(centre, shape.xy, inner, hard);
            }
        }
        if (coverage <= 0.0) {
            discard;
        }
        color = vec4(hdr ? linear_light(rgba.rgb) : rgba.rgb, shaped(mode_rgba[1].w, coverage));
        return;
    }

    // IMAGE: the source pixel under the inverse placement of the pixel centre.
    float u = abcd.x * centre.x + abcd.z * centre.y + efwh.x;
    float v = abcd.y * centre.x + abcd.w * centre.y + efwh.y;
    float w = efwh.z;
    float h = efwh.w;
    if (!(u >= 0.0 && u < w && v >= 0.0 && v < h)) {
        discard;
    }
    if (hdr) {
        // In floats: nearest, or bilinear weighed by alpha; sRGB decoded unless the source is half floats.
        vec4 f;
        if (mode_rgba[0].z == 0u) {
            f = texelFetch(source, ivec2(int(floor(u)), int(floor(v))), 0);
        } else {
            vec2 p = vec2(u, v) - 0.5;
            vec2 o = floor(p);
            vec2 t = p - o;
            ivec2 last = ivec2(int(w) - 1, int(h) - 1);
            ivec2 lo = clamp(ivec2(o), ivec2(0), last);
            ivec2 hi = clamp(ivec2(o) + 1, ivec2(0), last);
            vec4 p00 = texelFetch(source, lo, 0);
            vec4 p10 = texelFetch(source, ivec2(hi.x, lo.y), 0);
            vec4 p01 = texelFetch(source, ivec2(lo.x, hi.y), 0);
            vec4 p11 = texelFetch(source, hi, 0);
            vec4 m = mix(mix(vec4(p00.rgb * p00.a, p00.a), vec4(p10.rgb * p10.a, p10.a), t.x),
                         mix(vec4(p01.rgb * p01.a, p01.a), vec4(p11.rgb * p11.a, p11.a), t.x), t.y);
            if (m.a <= 0.0) {
                discard;
            }
            f = vec4(m.rgb / m.a, m.a);
        }
        vec3 light = (mode_rgba[0].w & 2u) != 0u ? f.rgb : linear_light(f.rgb);
        color = vec4(light, f.a * float(mode_rgba[0].y) / 255.0);
        return;
    }
    uvec4 s;
    if (mode_rgba[0].z == 0u) {
        s = uvec4(round(texelFetch(source, ivec2(int(floor(u)), int(floor(v))), 0) * 255.0));
    } else {
        float fx = u - 0.5;
        float x0 = floor(fx);
        uint tx = uint(floor((fx - x0) * 256.0));
        float fy = v - 0.5;
        float y0 = floor(fy);
        uint ty = uint(floor((fy - y0) * 256.0));
        int last_x = int(w) - 1;
        int last_y = int(h) - 1;
        int xa = clamp(int(x0), 0, last_x);
        int xb = clamp(int(x0) + 1, 0, last_x);
        int ya = clamp(int(y0), 0, last_y);
        int yb = clamp(int(y0) + 1, 0, last_y);
        uint weights[4] = uint[4]((256u - tx) * (256u - ty), tx * (256u - ty), (256u - tx) * ty, tx * ty);
        ivec2 taps[4] = ivec2[4](ivec2(xa, ya), ivec2(xb, ya), ivec2(xa, yb), ivec2(xb, yb));
        uint sum = 0u;
        uvec3 rgb = uvec3(0u);
        for (int i = 0; i < 4; i++) {
            uvec4 t = uvec4(round(texelFetch(source, taps[i], 0) * 255.0));
            uint weighed = weights[i] * t.w;
            sum += weighed;
            rgb += weighed * t.xyz;
        }
        if (sum == 0u) {
            discard;
        }
        uint half_sum = sum / 2u;
        s = uvec4((rgb + half_sum) / sum, (sum + 32768u) >> 16);
    }
    uint a = (s.w * mode_rgba[0].y + 127u) / 255u;
    color = vec4(vec3(s.xyz) / 255.0, float(a) / 255.0);
}
