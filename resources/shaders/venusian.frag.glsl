// One program, a mode per operation. The colour out is straight (not
// premultiplied) with the alpha Velvet would blend at; the pipeline's blend
// state does the source-over. SOLID and COPY run with blending off.
in vec2 at;
out vec4 color;
uniform sampler2D source;
// ext-opengl sets no unsigned vectors: the two uvec4 arrive as floats holding
// whole numbers 0..255 and are rebuilt at the top of main().
uniform vec4 mode_opacity;    // mode, opacity, linear, 0
uniform vec4 rgba_in;         // r, g, b, a
uniform vec4 shape;           // cx, cy, rx, ry
uniform vec4 band;            // stroke, hole, samples, 0
uniform vec4 abcd;            // the inverse placement
uniform vec4 efwh;            // e, f, source width, source height

const uint SOLID = 0u;
const uint FILL = 1u;
const uint OVAL = 2u;
const uint IMAGE = 3u;
const uint COPY = 4u;

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

void main()
{
    uvec4 mode_rgba[2];
    mode_rgba[0] = uvec4(mode_opacity);
    mode_rgba[1] = uvec4(rgba_in);
    uint mode = mode_rgba[0].x;
    vec4 rgba = vec4(mode_rgba[1]) / 255.0;
    vec2 centre = floor(at) + 0.5;

    if (mode == SOLID) {
        color = rgba;
        return;
    }
    if (mode == FILL) {
        color = rgba;
        return;
    }
    if (mode == COPY) {
        color = texelFetch(source, ivec2(centre), 0);
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
        color = vec4(rgba.rgb, shaped(mode_rgba[1].w, coverage));
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
