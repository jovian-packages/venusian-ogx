#version 410 core
// units: blur, sharp

// A glass pane, PHPLandGlass::pane() at the pixel's centre: the blurred backdrop (a pyramid level, straight colour,
// `scale` times smaller, starting at origin) bent near the edge, mixed with the sharp one by the frost, then
// saturation, tint, light, rim and grain; covered by 0.5 - d of the rounded box, at the pane's alpha. Straight in,
// premultiplied out. look: frost, refraction, bevel, saturation; look2: light, grain, alpha (0..255), scale.
// colours: tint and rim as 0xRRGGBBAA, whether there is a sharp level, 0. sizes: the level's, the sharp one's.
// 104 bytes, inside the 112 the fragment stage has.
in vec2 at;
uniform sampler2D blur;
uniform sampler2D sharp;
layout(std140) uniform Paint { vec4 box; vec4 radii; vec4 look; vec4 look2; uvec4 colours; ivec4 sizes; vec2 origin; } g;
layout(location = 0) out vec4 color;

float levelSample(sampler2D level, ivec2 size, float u, float v, int k)
{
    float fu = floor(u);
    float fv = floor(v);
    float tx = u - fu;
    float ty = v - fv;
    int x0 = clamp(int(fu), 0, size.x - 1);
    int x1 = clamp(int(fu) + 1, 0, size.x - 1);
    int y0 = clamp(int(fv), 0, size.y - 1);
    int y1 = clamp(int(fv) + 1, 0, size.y - 1);
    float a = floor(texelFetch(level, ivec2(x0, y0), 0)[k] * 255.0 + 0.5);
    float b = floor(texelFetch(level, ivec2(x1, y0), 0)[k] * 255.0 + 0.5);
    float c = floor(texelFetch(level, ivec2(x0, y1), 0)[k] * 255.0 + 0.5);
    float d = floor(texelFetch(level, ivec2(x1, y1), 0)[k] * 255.0 + 0.5);
    float top = a + (b - a) * tx;
    float under = c + (d - c) * tx;
    return top + (under - top) * ty;
}

uint grainOf(int x, int y)
{
    uint h = uint(x) * 374761393u + uint(y) * 668265263u;
    h = (h ^ (h >> 13)) * 1274126177u;
    h ^= h >> 16;
    return h & 0xFFFFu;
}

vec4 bytesOf(uint rgba)
{
    return vec4(float((rgba >> 24) & 0xFFu), float((rgba >> 16) & 0xFFu), float((rgba >> 8) & 0xFFu), float(rgba & 0xFFu));
}

void main()
{
    ivec2 p = ivec2(floor(at));
    float px = float(p.x) + 0.5;
    float py = float(p.y) + 0.5;
    float cx = (g.box.x + g.box.z) / 2.0;
    float cy = (g.box.y + g.box.w) / 2.0;
    float hx = (g.box.z - g.box.x) / 2.0;
    float hy = (g.box.w - g.box.y) / 2.0;
    float frost = g.look.x;
    float refraction = g.look.y;
    float bevel = g.look.z;
    float saturation = g.look.w;
    float light = g.look2.x;
    float grainAmount = g.look2.y;
    float alpha = g.look2.z;
    float scale = g.look2.w;
    float band = bevel > 0.0 ? bevel : (refraction > 0.0 ? refraction : 1.0);
    float r = px < cx ? (py < cy ? g.radii.x : g.radii.w) : (py < cy ? g.radii.y : g.radii.z);
    float dx = abs(px - cx) - (hx - r);
    float dy = abs(py - cy) - (hy - r);
    float d;
    float nx;
    float ny;
    if (dx > 0.0 && dy > 0.0) {
        float len = sqrt(dx * dx + dy * dy);
        d = len - r;
        nx = dx / len;
        ny = dy / len;
    } else if (dx > dy) {
        d = dx - r;
        nx = 1.0;
        ny = 0.0;
    } else {
        d = dy - r;
        nx = 0.0;
        ny = 1.0;
    }
    if (d >= 0.5) {
        discard;
    }
    if (px < cx) { nx = -nx; }
    if (py < cy) { ny = -ny; }
    float e = clamp(1.0 + d / band, 0.0, 1.0);
    float fe = e * e;
    float sx = px - nx * refraction * fe;
    float sy = py - ny * refraction * fe;
    float u = (sx - g.origin.x) / scale - 0.5;
    float v = (sy - g.origin.y) / scale - 0.5;
    vec3 c = vec3(levelSample(blur, g.sizes.xy, u, v, 0), levelSample(blur, g.sizes.xy, u, v, 1), levelSample(blur, g.sizes.xy, u, v, 2));
    if (g.colours.z != 0u && frost < 1.0) {
        float su = sx - g.origin.x - 0.5;
        float sv = sy - g.origin.y - 0.5;
        for (int k = 0; k < 3; k++) {
            float s = levelSample(sharp, g.sizes.zw, su, sv, k);
            c[k] = s + (c[k] - s) * frost;
        }
    }
    float luma = 0.2126 * c.r + 0.7152 * c.g + 0.0722 * c.b;
    float lit = 0.5 + 0.5 * (nx * -0.6 + ny * -0.8);
    float grain = grainAmount * 0.04 * (float(grainOf(p.x, p.y)) / 65536.0 - 0.5) * 255.0;
    vec4 tint = bytesOf(g.colours.x);
    vec4 rim = bytesOf(g.colours.y);
    vec3 o;
    for (int k = 0; k < 3; k++) {
        float ck = luma + (c[k] - luma) * saturation;
        ck = ck + (tint[k] - ck) * (tint.a / 255.0);
        ck = ck + light * 255.0 + rim[k] * (rim.a / 255.0) * fe * lit + grain;
        o[k] = clamp(floor(ck + 0.5), 0.0, 255.0);
    }
    float cover = min(0.5 - d, 1.0);
    float a = floor(cover * alpha + 0.5);
    if (a <= 0.0) {
        discard;
    }
    float k = a / 255.0;
    color = vec4(o / 255.0 * k, k);
}
