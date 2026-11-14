#version 410 core
// units: textures, hits

// Raycasting, the view pass: what a column's hit shows at a pixel, as PHPLandMarcher::picture() works it out, in
// integers where it is.

layout(location = 0) out vec4 color;

// Per wall id: x the kind (0 none, 1 a colour, 2 a texture), y the colour 0xRRGGBBAA, z the first word of its texture
// in the textures, w its size (width | height << 16).
layout(std140) uniform Walls { uvec4 walls[256]; };

// Every wall texture's straight RGBA8 texels, a word each (R in the low byte), one texture after another, 1024 a row.
uniform highp usampler2D textures;

// The hits pass's target.
uniform sampler2D hits;

// Where the view is on the surface and its size; the ceiling and floor 0xRRGGBBAA; the shade; rays a pair of rows of
// the hits image; 1 to write straight colour (a view read out as bytes), 0 premultiplied (painted on a framebuffer).
layout(std140) uniform Paint {
    ivec2 origin;
    ivec2 size;
    uint ceiling;
    uint floor;
    uint shade;
    uint per_row;
    uint straight;
} v;

// Velvet's tallest wall line, before it is cut to the view.
const float MAX_LINE = 16777216.0;

uint dimmed(uint rgba, uint dim)
{
    uint r = ((rgba >> 24) & 0xFFu) * dim / 255u;
    uint g = ((rgba >> 16) & 0xFFu) * dim / 255u;
    uint b = ((rgba >> 8) & 0xFFu) * dim / 255u;

    return (r << 24) | (g << 16) | (b << 8) | (rgba & 0xFFu);
}

// a * b / c in 64 bits, as Velvet works it out: a row is up to a wall's whole line, a texture up to 65535 tall. The
// quotient is below the texture's height, so it fits 32 bits.
uint mulDiv(uint a, uint b, uint c)
{
    uint high;
    uint low;
    umulExtended(a, b, high, low);
    if (high == 0u) {
        return low / c;
    }

    uint quotient = 0u;
    uint remainder = 0u;
    for (int i = 63; i >= 0; i--) {
        uint bit = i >= 32 ? (high >> uint(i - 32)) & 1u : (low >> uint(i)) & 1u;
        remainder = (remainder << 1) | bit;
        quotient <<= 1;
        if (remainder >= c) {
            remainder -= c;
            quotient |= 1u;
        }
    }

    return quotient;
}

void main()
{
    ivec2 p = ivec2(gl_FragCoord.xy) - v.origin;
    int column = p.x;
    int row = p.y;
    int height = v.size.y;
    int horizon = height / 2;

    uint rgba = row < horizon ? v.ceiling : v.floor;

    ivec2 at = ivec2(uint(column) % v.per_row, (uint(column) / v.per_row) * 2u);
    vec4 first = texelFetch(hits, at, 0);
    vec4 second = texelFetch(hits, at + ivec2(0, 1), 0);
    uint wall = uint(second.x);

    if (wall != 0u) {
        float distance = first.x;
        float tall = distance > 0.0 ? float(height) / distance : MAX_LINE;
        if (!(tall < MAX_LINE)) {
            tall = MAX_LINE;
        }
        int line = int(tall);
        int start = horizon - line / 2;
        int from = max(start, 0);
        int to = min(start + line, height);

        if (row >= from && row < to) {
            uint face = uint(second.y);
            uint dim = face >= 2u ? v.shade : 255u;
            uvec4 entry = walls[wall];

            if (entry.x == 1u) {
                rgba = dimmed(entry.y, dim);
            } else if (entry.x == 2u) {
                int textureWidth = int(entry.w & 0xFFFFu);
                int textureHeight = int(entry.w >> 16);
                int texelX = int(first.y * float(textureWidth));
                if (texelX > textureWidth - 1) {
                    texelX = textureWidth - 1;
                }
                if (face == 1u || face == 2u) {
                    texelX = textureWidth - 1 - texelX;
                }
                int texelY = int(mulDiv(uint(row - start), uint(textureHeight), uint(line)));
                uint word = entry.z + uint(texelY * textureWidth + texelX);
                uint texel = texelFetch(textures, ivec2(int(word & 1023u), int(word >> 10)), 0).r;
                uint packed = ((texel & 0xFFu) << 24) | (((texel >> 8) & 0xFFu) << 16) | (((texel >> 16) & 0xFFu) << 8) | (texel >> 24);
                rgba = dimmed(packed, dim);
            }
        }
    }

    vec4 straight = vec4(float((rgba >> 24) & 0xFFu), float((rgba >> 16) & 0xFFu), float((rgba >> 8) & 0xFFu), float(rgba & 0xFFu)) / 255.0;
    color = v.straight != 0u ? straight : vec4(straight.rgb * straight.a, straight.a);
}
