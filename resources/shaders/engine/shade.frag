#version 410 core

// SHADOW, once a pixel at its centre: 1 - smoothstep(-blur, blur, d), d the signed distance to the
// rounded box. Push: the colour; the box x0 y0 x1 y1; 0 0 0 0; 0, 0, blur, radius.
in vec2 at;
layout(std140) uniform Paint { uvec4 rgba; vec4 box; vec4 radii; vec4 band; } b;
layout(location = 0) out vec4 color;

void main()
{
    vec2 p = floor(at) + 0.5;
    vec2 c = (b.box.xy + b.box.zw) / 2.0;
    vec2 h = (b.box.zw - b.box.xy) / 2.0;
    float r = b.band.w;
    float blur = b.band.z;
    vec2 q = abs(p - c) - h + r;
    vec2 o = max(q, vec2(0.0));
    float d = min(max(q.x, q.y), 0.0) + length(o) - r;
    float cover;
    if (blur > 0.0) {
        float t = clamp((d + blur) / (2.0 * blur), 0.0, 1.0);
        cover = 1.0 - t * t * (3.0 - 2.0 * t);
    } else {
        cover = d < 0.0 ? 1.0 : 0.0;
    }
    if (cover <= 0.0) {
        discard;
    }
    float a = float(b.rgba.a) / 255.0 * cover;
    color = vec4(vec3(b.rgba.rgb) / 255.0 * a, a);
}
