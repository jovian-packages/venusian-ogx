// The present: each framebuffer pixel outside the placed rect is black;
// inside it, the target nearest or bilinear, then encoded for the surface:
// 0 as stored, 1 extended linear (× SDR white), 2 HDR10 (BT.2020, ST 2084,
// a value of 1.0 at `nits`). GL's window origin is bottom-left and the rect's
// top-left, so rows turn over here; the target holds the frame bottom-up, so
// its rows turn over too. ext-opengl sets no unsigned vectors: modes arrive
// as floats holding whole numbers.
out vec4 color;
uniform sampler2D target;
uniform vec4 rect;         // x, y, width, height in framebuffer pixels, origin top-left
uniform vec4 size_light;   // target width, height, SDR white, PQ white nits
uniform vec4 modes;        // linear, transform, framebuffer height, 0

vec3 pq(vec3 nits)
{
    mat3 to2020 = mat3(vec3(0.6274040, 0.0690970, 0.0163916), vec3(0.3292820, 0.9195400, 0.0880132), vec3(0.0433136, 0.0113612, 0.8955950));
    vec3 y = clamp(to2020 * nits / 10000.0, 0.0, 1.0);
    vec3 p = pow(y, vec3(0.1593017578125));
    return pow((0.8359375 + 18.8515625 * p) / (1.0 + 18.6875 * p), vec3(78.84375));
}

vec4 texel(vec2 q, float last_row)
{
    return texelFetch(target, ivec2(int(q.x), int(last_row - q.y)), 0);
}

void main()
{
    vec2 at = vec2(gl_FragCoord.x, modes.z - gl_FragCoord.y);
    if (at.x < rect.x || at.y < rect.y || at.x >= rect.x + rect.z || at.y >= rect.y + rect.w) {
        color = vec4(0.0, 0.0, 0.0, 1.0);
        return;
    }
    vec2 size = size_light.xy;
    vec2 p = (at - rect.xy) / rect.zw * size;
    vec2 last = size - 1.0;
    vec4 c;
    if (modes.x < 0.5) {
        c = texel(clamp(floor(p), vec2(0.0), last), last.y);
    } else {
        vec2 f = p - 0.5;
        vec2 o = floor(f);
        vec2 t = f - o;
        vec2 a = clamp(o, vec2(0.0), last);
        vec2 b = clamp(o + 1.0, vec2(0.0), last);
        c = mix(mix(texel(a, last.y), texel(vec2(b.x, a.y), last.y), t.x), mix(texel(vec2(a.x, b.y), last.y), texel(b, last.y), t.x), t.y);
    }
    if (modes.y > 1.5) {
        c = vec4(pq(c.rgb * size_light.w), 1.0);
    } else if (modes.y > 0.5) {
        c.rgb *= size_light.z;
    }
    color = c;
}
