#version 410 core
// units: data
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

layout(std140) uniform Paint { uvec4 rgba; } f;

void main()
{
    color = unbyte(f.rgba);
}
