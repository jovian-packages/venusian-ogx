// Glass (Surface's drawing-path spec, G4): one pass of the backdrop's dual-filter (Kawase) pyramid over a level of its
// own size. Every texel is read exactly, as whole numbers 0..255, so the levels are ext-fb's fb_kawase_down() and
// fb_kawase_up() byte for byte. A level's memory row is its row: the passes read and write rows the same way.
out vec4 color;
uniform sampler2D src;
uniform vec4 kind;          // kind: 0 capture, 1 down, 2 up; 0, 0, 0
uniform vec4 reg;           // capture: the region's x, y; down, up: the source's width, height

ivec4 texel(int x, int y, ivec2 size)
{
    return ivec4(floor(texelFetch(src, ivec2(clamp(x, 0, size.x - 1), clamp(y, 0, size.y - 1)), 0) * 255.0 + 0.5));
}

// 16 x the bilinear value at (x4, y4), in quarter pixels from the source's pixel centres.
ivec4 tap16(int x4, int y4, ivec2 size)
{
    int ix = int(floor(float(x4) / 4.0));
    int iy = int(floor(float(y4) / 4.0));
    int fx = x4 - 4 * ix;
    int fy = y4 - 4 * iy;
    return (4 - fy) * ((4 - fx) * texel(ix, iy, size) + fx * texel(ix + 1, iy, size))
        + fy * ((4 - fx) * texel(ix, iy + 1, size) + fx * texel(ix + 1, iy + 1, size));
}

void main()
{
    ivec2 at = ivec2(floor(gl_FragCoord.xy));
    ivec2 size = ivec2(reg.xy);
    int k = int(kind.x + 0.5);
    ivec4 v;
    if (k == 0) {
        v = ivec4(floor(texelFetch(src, at + ivec2(reg.xy), 0) * 255.0 + 0.5));
    } else if (k == 1) {
        // one level down: (4 x the 2x2 centre + the 4x4 block + 16) / 32, reads past the edge taking it
        ivec2 o = at * 2 - 1;
        ivec4 all = ivec4(0);
        ivec4 centre = ivec4(0);
        for (int b = 0; b < 4; b++) {
            for (int a = 0; a < 4; a++) {
                ivec4 t = texel(o.x + a, o.y + b, size);
                all += t;
                if ((a == 1 || a == 2) && (b == 1 || b == 2)) {
                    centre += t;
                }
            }
        }
        v = (4 * centre + all + 16) / 32;
    } else {
        // one level up: the 8-tap tent, edge midpoints at +/-1 source pixel (weight 1), diagonals at +/-1/2 (weight 2), / 192
        ivec2 q = at * 2 - 1;
        ivec4 sum = tap16(q.x - 4, q.y, size) + tap16(q.x + 4, q.y, size)
            + tap16(q.x, q.y - 4, size) + tap16(q.x, q.y + 4, size)
            + 2 * (tap16(q.x - 2, q.y - 2, size) + tap16(q.x + 2, q.y - 2, size)
                + tap16(q.x - 2, q.y + 2, size) + tap16(q.x + 2, q.y + 2, size));
        v = (sum + 96) / 192;
    }
    color = vec4(v) / 255.0;
}
