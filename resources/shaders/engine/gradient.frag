#version 410 core
// units: ramp

// A path's cover in a gradient: the ramp (256 x 1, straight colour) at the position of the pixel's centre along
// the gradient, nearest. abcd and ef take the centre to the gradient's own space; options: kind (0 linear,
// 1 radial), spread (0 pad, 1 repeat, 2 reflect).
in vec2 at;
uniform sampler2D ramp;
layout(std140) uniform Paint { vec4 abcd; vec4 ef; uvec4 options; } g;
layout(location = 0) out vec4 color;

void main()
{
    vec2 centre = floor(at) + 0.5;
    float u = g.abcd.x * centre.x + g.abcd.z * centre.y + g.ef.x;
    float v = g.abcd.y * centre.x + g.abcd.w * centre.y + g.ef.y;
    float t = g.options.x == 0u ? u : length(vec2(u, v));
    if (g.options.y == 0u) {
        t = clamp(t, 0.0, 1.0);
    } else if (g.options.y == 1u) {
        t = t - floor(t);
    } else {
        float m = t - 2.0 * floor(t / 2.0);
        t = m > 1.0 ? 2.0 - m : m;
    }
    vec4 c = texelFetch(ramp, ivec2(int(floor(t * 255.0 + 0.5)), 0), 0);
    color = vec4(c.rgb * c.a, c.a);
}
