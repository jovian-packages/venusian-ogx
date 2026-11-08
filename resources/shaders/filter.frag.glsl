// One filter pass over a layer target: the arithmetic of Surface's NativeFilters, one kind a pass (FilterPlan lists the
// kinds and what their numbers mean). Every texture is read exactly, with texelFetch, as whole numbers 0..255, and a
// pixel's memory row is its row on the surface. ext-opengl sets no arrays and no unsigned vectors: the numbers a pass
// needs beyond four floats come in `data`, one float a texel (its four bytes), 512 a row.
out vec4 color;
uniform sampler2D src;      // result `in`
uniform sampler2D src2;     // result `in2`
uniform sampler2D data;     // the pass's numbers
uniform sampler2D map;      // a displacement map, straight RGBA8
uniform vec4 reg;           // the region: x0, y0, x1, y1 (x1, y1 past the last pixel)
uniform vec4 kind;          // kind, 0, 0, 0
uniform vec4 pa;            // the pass's first four numbers
uniform vec4 pb;            // the next four

const int PREMULTIPLY = 0;
const int STRAIGHTEN = 1;
const int BOX = 2;
const int KERNEL = 3;
const int SPIRAL = 4;
const int MATRIX = 5;
const int FLOOD = 6;
const int COMPOSITE = 7;
const int BLEND = 8;
const int HASH = 9;
const int TURBULENCE = 10;
const int DISPLACE = 11;
const int FIELD = 12;
const int TABLE = 13;

// 2 to the integer power k, k in -127..127, by squaring: exact, where exp2() may not be.
float pow2(int k)
{
    float base = k < 0 ? 0.5 : 2.0;
    int n = k < 0 ? -k : k;
    float result = 1.0;
    for (int bit = 0; bit < 7; bit++) {
        if (((n >> bit) & 1) != 0) {
            result *= base;
        }
        base *= base;
    }
    return result;
}

// The i-th number: a float32 kept as its four bytes, little endian, in one RGBA8 texel (no float texture formats here).
float num(int i)
{
    ivec4 b = ivec4(floor(texelFetch(data, ivec2(i & 511, i >> 9), 0) * 255.0 + 0.5));
    int e = ((b.a & 127) << 1) | (b.b >> 7);
    if (e == 0) {
        return 0.0;
    }
    float v = (1.0 + float(((b.b & 127) << 16) | (b.g << 8) | b.r) / 8388608.0) * pow2(e - 127);
    return (b.a >> 7) != 0 ? -v : v;
}

ivec4 whole(sampler2D t, ivec2 p)
{
    return ivec4(floor(texelFetch(t, p, 0) * 255.0 + 0.5));
}

// A pixel of the region; one off it is nothing (edge 0) or the nearest the region has (edge 1).
ivec4 edged(sampler2D t, ivec2 p, int edge)
{
    ivec2 lo = ivec2(reg.xy);
    ivec2 hi = ivec2(reg.zw);
    if (p.x < lo.x || p.x >= hi.x || p.y < lo.y || p.y >= hi.y) {
        if (edge == 0) {
            return ivec4(0);
        }
        p = clamp(p, lo, hi - 1);
    }
    return whole(t, p);
}

// The four pixels around (u, v), weights in 1/256ths, rounded to nearest.
ivec4 bilinear(sampler2D t, float u, float v, int edge)
{
    float fx = clamp(u - 0.5, -1.0e9, 1.0e9);
    float fy = clamp(v - 0.5, -1.0e9, 1.0e9);
    int x0 = int(floor(fx));
    int y0 = int(floor(fy));
    int tx = int(floor((fx - float(x0)) * 256.0));
    int ty = int(floor((fy - float(y0)) * 256.0));
    ivec4 sum = ivec4(0);
    sum += (256 - tx) * (256 - ty) * edged(t, ivec2(x0, y0), edge);
    sum += tx * (256 - ty) * edged(t, ivec2(x0 + 1, y0), edge);
    sum += (256 - tx) * ty * edged(t, ivec2(x0, y0 + 1), edge);
    sum += tx * ty * edged(t, ivec2(x0 + 1, y0 + 1), edge);
    return (sum + 32768) >> 16;
}

float unit(float v)
{
    return clamp(v, 0.0, 1.0);
}

int byte(float v)
{
    return int(floor(unit(v) * 255.0 + 0.5));
}

// A premultiplied pixel of 0..1 floats: clamped, the colours held to the alpha, rounded to nearest.
ivec4 stored(vec4 p)
{
    int a = byte(p.a);
    return ivec4(min(byte(p.r), a), min(byte(p.g), a), min(byte(p.b), a), a);
}

// Raven's sd_round_box_normal (ui.frag:42-57): x the distance to the rounded box (negative inside), yz the direction out of it.
vec3 box_distance(vec2 pt, vec4 box, vec4 radii)
{
    float hx = (box.z - box.x) / 2.0;
    float hy = (box.w - box.y) / 2.0;
    vec2 q0 = pt - vec2((box.x + box.z) / 2.0, (box.y + box.w) / 2.0);
    float radius = q0.x < 0.0 ? (q0.y < 0.0 ? radii.x : radii.w) : (q0.y < 0.0 ? radii.y : radii.z);
    radius = min(min(radius, hx), hy);
    float sx = q0.x < 0.0 ? -1.0 : 1.0;
    float sy = q0.y < 0.0 ? -1.0 : 1.0;
    float qx = abs(q0.x) - hx + radius;
    float qy = abs(q0.y) - hy + radius;
    float mx = max(qx, 0.0);
    float my = max(qy, 0.0);
    float corner = sqrt(mx * mx + my * my);
    float nx = 0.0;
    float ny = 0.0;
    if (qx > 0.0 && qy > 0.0) {
        nx = sx * (qx / max(corner, 1.0e-4));
        ny = sy * (qy / max(corner, 1.0e-4));
    } else if (qx > qy) {
        nx = sx;
    } else {
        ny = sy;
    }
    return vec3(min(max(qx, qy), 0.0) + corner - radius, nx, ny);
}

// A channel through its 256-entry table, when the mask has one: the tables lie one after another in `data`, red first.
int table_pick(int mask, int channel, int value)
{
    if (((mask >> channel) & 1) == 0) {
        return value;
    }
    int base = 0;
    for (int c = 0; c < channel; c++) {
        if (((mask >> c) & 1) != 0) {
            base += 256;
        }
    }
    return int(num(base + value) + 0.5);
}

float lattice_sel(int i)
{
    return num(i);
}

vec2 lattice_grad(int channel, int i)
{
    int at = 514 + (channel * 514 + i) * 2;
    return vec2(num(at), num(at + 1));
}

float noise2(int channel, float x, float y)
{
    int ix = int(floor(x));
    int iy = int(floor(y));
    float rx0 = x - float(ix);
    float ry0 = y - float(iy);
    float rx1 = rx0 - 1.0;
    float ry1 = ry0 - 1.0;
    int bx0 = ix & 255;
    int bx1 = (bx0 + 1) & 255;
    int by0 = iy & 255;
    int by1 = (by0 + 1) & 255;
    int i = int(lattice_sel(bx0));
    int j = int(lattice_sel(bx1));
    int b00 = int(lattice_sel(i + by0));
    int b10 = int(lattice_sel(j + by0));
    int b01 = int(lattice_sel(i + by1));
    int b11 = int(lattice_sel(j + by1));
    float sx = rx0 * rx0 * (3.0 - 2.0 * rx0);
    float sy = ry0 * ry0 * (3.0 - 2.0 * ry0);
    vec2 g00 = lattice_grad(channel, b00);
    vec2 g10 = lattice_grad(channel, b10);
    vec2 g01 = lattice_grad(channel, b01);
    vec2 g11 = lattice_grad(channel, b11);
    float u = rx0 * g00.x + ry0 * g00.y;
    float v = rx1 * g10.x + ry0 * g10.y;
    float a = u + sx * (v - u);
    u = rx0 * g01.x + ry1 * g01.y;
    v = rx1 * g11.x + ry1 * g11.y;
    float b = u + sx * (v - u);
    return a + sy * (b - a);
}

void main()
{
    int k = int(kind.x);
    ivec2 p = ivec2(gl_FragCoord.xy);
    ivec4 out_ = ivec4(0);

    if (k == PREMULTIPLY) {
        ivec4 c = whole(src, p);
        out_ = ivec4((c.rgb * c.a + 127) / 255, c.a);
    } else if (k == STRAIGHTEN) {
        ivec4 c = whole(src, p);
        out_ = c.a == 0 ? ivec4(0) : ivec4(min(ivec3(255), (c.rgb * 255 + c.a / 2) / c.a), c.a);
    } else if (k == BOX) {
        int axis = int(pa.x);
        int size = clamp(int(pa.y), 1, 128);
        int left = clamp(int(pa.z), 0, 128);
        int edge = int(pa.w);
        ivec4 sum = ivec4(0);
        for (int j = -left; j <= -left + size - 1; j++) {
            sum += edged(src, axis == 0 ? ivec2(p.x + j, p.y) : ivec2(p.x, p.y + j), edge);
        }
        out_ = (sum + size / 2) / size;
    } else if (k == KERNEL) {
        int axis = int(pa.x);
        int radius = clamp(int(pa.y), 0, 32);
        int edge = int(pa.z);
        ivec4 sum = ivec4(0);
        for (int n = 0; n < 2 * radius + 1; n++) {
            int j = n - radius;
            sum += int(num(n)) * edged(src, axis == 0 ? ivec2(p.x + j, p.y) : ivec2(p.x, p.y + j), edge);
        }
        out_ = (sum + 128) >> 8;
    } else if (k == SPIRAL) {
        int samples = clamp(int(pa.x), 1, 64);
        int edge = int(pa.y);
        ivec4 sum = ivec4(0);
        for (int n = 0; n < samples; n++) {
            sum += bilinear(src, float(p.x) + 0.5 + num(2 * n), float(p.y) + 0.5 + num(2 * n + 1), edge);
        }
        out_ = (sum + samples / 2) / samples;
    } else if (k == MATRIX) {
        ivec4 c = whole(src, p);
        float r = 0.0;
        float g = 0.0;
        float b = 0.0;
        if (c.a != 0) {
            r = float(min(255, (c.r * 255 + c.a / 2) / c.a)) / 255.0;
            g = float(min(255, (c.g * 255 + c.a / 2) / c.a)) / 255.0;
            b = float(min(255, (c.b * 255 + c.a / 2) / c.a)) / 255.0;
        }
        float alpha = float(c.a) / 255.0;
        float nr = unit(num(0) * r + num(1) * g + num(2) * b + num(3) * alpha + num(4));
        float ng = unit(num(5) * r + num(6) * g + num(7) * b + num(8) * alpha + num(9));
        float nb = unit(num(10) * r + num(11) * g + num(12) * b + num(13) * alpha + num(14));
        float na = unit(num(15) * r + num(16) * g + num(17) * b + num(18) * alpha + num(19));
        out_ = ivec4(int(floor(nr * na * 255.0 + 0.5)), int(floor(ng * na * 255.0 + 0.5)), int(floor(nb * na * 255.0 + 0.5)), int(floor(na * 255.0 + 0.5)));
    } else if (k == FLOOD) {
        ivec4 c = ivec4(pa);
        out_ = ivec4((c.rgb * c.a + 127) / 255, c.a);
    } else if (k == COMPOSITE) {
        vec4 a = vec4(whole(src, p)) / 255.0;
        vec4 b = vec4(whole(src2, p)) / 255.0;
        int op = int(pa.x);
        vec4 r;
        if (op == 0) {
            r = a + b * (1.0 - a.a);
        } else if (op == 1) {
            r = a * b.a;
        } else if (op == 2) {
            r = a * (1.0 - b.a);
        } else if (op == 3) {
            r = a * b.a + b * (1.0 - a.a);
        } else if (op == 4) {
            r = a * (1.0 - b.a) + b * (1.0 - a.a);
        } else {
            r = pa.y * a * b + pa.z * a + pa.w * b + pb.x;
        }
        out_ = stored(r);
    } else if (k == BLEND) {
        vec4 a = vec4(whole(src, p)) / 255.0;
        vec4 b = vec4(whole(src2, p)) / 255.0;
        int mode = int(pa.x);
        vec3 r;
        if (mode == 0) {
            r = (1.0 - a.a) * b.rgb + a.rgb;
        } else if (mode == 1) {
            r = (1.0 - a.a) * b.rgb + (1.0 - b.a) * a.rgb + a.rgb * b.rgb;
        } else if (mode == 2) {
            r = b.rgb + a.rgb - a.rgb * b.rgb;
        } else if (mode == 3) {
            r = min((1.0 - a.a) * b.rgb + a.rgb, (1.0 - b.a) * a.rgb + b.rgb);
        } else {
            r = max((1.0 - a.a) * b.rgb + a.rgb, (1.0 - b.a) * a.rgb + b.rgb);
        }
        out_ = stored(vec4(r, 1.0 - (1.0 - a.a) * (1.0 - b.a)));
    } else if (k == HASH) {
        uint seed = (uint(pa.x) << 16) | uint(pa.y);
        uint h = uint(p.x) * 374761393u + uint(p.y) * 668265263u + seed * 2246822519u;
        h = (h ^ (h >> 13)) * 1274126177u;
        int v = int((h ^ (h >> 16)) & 255u);
        out_ = ivec4(v, v, v, 255);
    } else if (k == TURBULENCE) {
        bool fractal = pa.x > 0.5;
        int octaves = clamp(int(pa.y), 1, 4);
        ivec4 straight = ivec4(0);
        for (int ch = 0; ch < 4; ch++) {
            float vx = float(p.x) * pa.z;
            float vy = float(p.y) * pa.w;
            float ratio = 1.0;
            float sum = 0.0;
            for (int o = 0; o < octaves; o++) {
                float n = noise2(ch, vx, vy);
                sum += (fractal ? n : abs(n)) / ratio;
                vx *= 2.0;
                vy *= 2.0;
                ratio *= 2.0;
            }
            float value = fractal ? (sum * 255.0 + 255.0) / 2.0 : sum * 255.0;
            straight[ch] = clamp(int(floor(value + 0.5)), 0, 255);
        }
        out_ = ivec4((straight.rgb * straight.a + 127) / 255, straight.a);
    } else if (k == FIELD) {
        int kd = int(pa.x);
        vec2 pt = vec2(float(p.x) + 0.5, float(p.y) + 0.5);
        float from = num(8);
        float span = num(9) - from;
        span = abs(span) < 1.0e-30 ? 1.0e-30 : span;
        if (kd == 2) {
            int v = byte((pt.y - from) / span);
            out_ = ivec4(v, v, v, 255);
        } else {
            vec3 f = box_distance(pt, vec4(num(0), num(1), num(2), num(3)), vec4(num(4), num(5), num(6), num(7)));
            if (kd == 1) {
                int v = f.x < 0.0 ? 255 : 0;
                out_ = ivec4(v, v, v, 255);
            } else {
                out_ = ivec4(byte((f.x - from) / span), byte(0.5 + 0.5 * f.y), byte(0.5 + 0.5 * f.z), 255);
            }
        }
    } else if (k == TABLE) {
        ivec4 c = whole(src, p);
        int a = c.a;
        ivec3 st = a == 0 ? ivec3(0) : min(ivec3(255), (c.rgb * 255 + a / 2) / a);
        int mask = int(pa.x);
        int na = table_pick(mask, 3, a);
        ivec3 t = ivec3(table_pick(mask, 0, st.r), table_pick(mask, 1, st.g), table_pick(mask, 2, st.b));
        out_ = ivec4((t * na + 127) / 255, na);
    } else if (k == DISPLACE) {
        float scale = pa.x;
        int xch = int(pa.y);
        int ych = int(pa.z);
        int bands = clamp(int(pa.w), 1, 16);
        float spread = pb.x;
        float dx = 0.0;
        float dy = 0.0;
        // the map lies on the region's top left corner and travels with it
        ivec2 size = textureSize(map, 0);
        ivec2 q = p - ivec2(reg.xy);
        if (pb.y > 0.5) {
            // the map is an earlier result, premultiplied: straight here
            ivec4 m = whole(src2, p);
            ivec4 sm = m.a == 0 ? ivec4(0) : ivec4(min(ivec3(255), (m.rgb * 255 + m.a / 2) / m.a), m.a);
            dx = scale * (float(sm[xch]) / 255.0 - 0.5);
            dy = scale * (float(sm[ych]) / 255.0 - 0.5);
        } else if (q.x < size.x && q.y < size.y) {
            ivec4 m = whole(map, q);
            dx = scale * (float(m[xch]) / 255.0 - 0.5);
            dy = scale * (float(m[ych]) / 255.0 - 0.5);
        }
        if (bands == 1) {
            out_ = bilinear(src, float(p.x) + 0.5 + dx, float(p.y) + 0.5 + dy, 0);
        } else {
            vec3 rgb = vec3(0.0);
            for (int i = 0; i < bands; i++) {
                float t = float(i) / float(bands - 1) * 2.0 - 1.0;
                float f = 1.0 + spread * t;
                ivec4 s = bilinear(src, float(p.x) + 0.5 + dx * f, float(p.y) + 0.5 + dy * f, 0);
                rgb += vec3(s.rgb) * vec3(num(i * 3), num(i * 3 + 1), num(i * 3 + 2));
            }
            int alpha = bilinear(src, float(p.x) + 0.5 + dx, float(p.y) + 0.5 + dy, 0).a;
            out_ = ivec4(min(ivec3(floor(rgb + 0.5)), ivec3(alpha)), alpha);
        }
    }
    color = vec4(out_) / 255.0;
}
