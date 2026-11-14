#version 410 core
// units: src, map, data
#define DATA
// Shared by the filter passes. A pass draws one triangle over the image's viewport and a fragment works out one
// pixel from its inputs. Images are RGBA8 premultiplied unless a note says straight; a value is read as its byte and
// written as one, so where Velvet's arithmetic is in integers (blurs, Kawase, tables, floods, the hash) this is too,
// step for step. PHPLandFilters and PHPLandGlass are the reference. floor(x + 0.5) throughout: GLSL leaves round()'s
// halves to the driver.
//
// Inputs: set 0 and set 1 are images, set 2 the pass's data, 32-bit words, 1024 a row.

layout(location = 0) out vec4 color;

ivec2 pixel()
{
    return ivec2(floor(gl_FragCoord.xy));
}

uvec4 byte4(vec4 c)
{
    return uvec4(floor(c * 255.0 + 0.5));
}

vec4 unbyte(uvec4 v)
{
    return vec4(v) / 255.0;
}

uint straightOf(uint c, uint a)
{
    return a == 0u ? 0u : min(255u, (c * 255u + a / 2u) / a);
}

uint premultiplyOf(uint c, uint a)
{
    return (c * a + 127u) / 255u;
}

#ifdef DATA
uniform usampler2D data;

uint word(int i)
{
    return texelFetch(data, ivec2(i & 1023, i >> 10), 0).r;
}

float floatAt(int i)
{
    return uintBitsToFloat(word(i));
}
#endif

// A pixel of an image of `size`, or past its edge: transparent (edge 0) or the nearest edge pixel (edge 1).
uvec4 fetchOf(sampler2D t, ivec2 p, ivec2 size, uint edge)
{
    if (p.x < 0 || p.y < 0 || p.x >= size.x || p.y >= size.y) {
        if (edge == 0u) {
            return uvec4(0u);
        }
        p = clamp(p, ivec2(0), size - 1);
    }
    return byte4(texelFetch(t, p, 0));
}

// PHPLandFilters::bilinear(): the four pixels around (u, v), weights in 1/256ths, rounded to nearest.
uvec4 bilinear(sampler2D t, ivec2 size, float u, float v, uint edge)
{
    float fx = clamp(u - 0.5, -1.0e9, 1.0e9);
    float fy = clamp(v - 0.5, -1.0e9, 1.0e9);
    int x0 = int(floor(fx));
    int y0 = int(floor(fy));
    uint tx = uint(floor((fx - float(x0)) * 256.0));
    uint ty = uint(floor((fy - float(y0)) * 256.0));
    uvec4 sum = uvec4(0u);
    ivec2 taps[4] = ivec2[4](ivec2(x0, y0), ivec2(x0 + 1, y0), ivec2(x0, y0 + 1), ivec2(x0 + 1, y0 + 1));
    uint weights[4] = uint[4]((256u - tx) * (256u - ty), tx * (256u - ty), (256u - tx) * ty, tx * ty);
    for (int i = 0; i < 4; i++) {
        if (weights[i] != 0u) {
            sum += weights[i] * fetchOf(t, taps[i], size, edge);
        }
    }
    return (sum + 32768u) >> 16;
}

// A premultiplied pixel of 0..1 floats as bytes: clamped, the colours held to the alpha, rounded to nearest.
vec4 stored(vec4 v)
{
    uint a = uint(floor(clamp(v.a, 0.0, 1.0) * 255.0 + 0.5));
    uvec3 c = min(uvec3(a), uvec3(floor(clamp(v.rgb, 0.0, 1.0) * 255.0 + 0.5)));
    return unbyte(uvec4(c, a));
}

uniform sampler2D src;
uniform sampler2D map;
layout(std140) uniform Paint { ivec2 size; ivec2 mapSize; float scale; float spread; uint xChannel; uint yChannel; uint bands; uint mapPremultiplied; } d;

// The map is straight (a framebuffer's) or premultiplied (a step's), read where the pixel is; past its edge, no
// shift. The data: a band's weights (r, g, b, 0) a band.
void main()
{
    ivec2 p = pixel();
    float dx = 0.0;
    float dy = 0.0;
    if (p.x < d.mapSize.x && p.y < d.mapSize.y) {
        uvec4 m = byte4(texelFetch(map, p, 0));
        if (d.mapPremultiplied != 0u) {
            m = uvec4(straightOf(m.r, m.a), straightOf(m.g, m.a), straightOf(m.b, m.a), m.a);
        }
        dx = d.scale * (float(m[d.xChannel]) / 255.0 - 0.5);
        dy = d.scale * (float(m[d.yChannel]) / 255.0 - 0.5);
    }
    float u = float(p.x) + 0.5;
    float v = float(p.y) + 0.5;
    uvec4 middle = bilinear(src, d.size, u + dx, v + dy, 0u);
    if (d.bands == 1u) {
        color = unbyte(middle);
        return;
    }
    vec3 rgb = vec3(0.0);
    for (uint i = 0u; i < d.bands; i++) {
        float k = float(i) / float(d.bands - 1u) * 2.0 - 1.0;
        float f = 1.0 + d.spread * k;
        uvec4 t = bilinear(src, d.size, u + dx * f, v + dy * f, 0u);
        int w = int(4u * i);
        rgb += vec3(t.rgb) * vec3(floatAt(w), floatAt(w + 1), floatAt(w + 2));
    }
    uvec3 c = min(uvec3(middle.a), uvec3(floor(rgb + 0.5)));
    color = unbyte(uvec4(c, middle.a));
}
