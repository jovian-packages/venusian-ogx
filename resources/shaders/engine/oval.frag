#version 410 core

// ELLIPSE and RING. `at` is read once a sample (sampleRateShading), so a curved edge is as fine as the
// samples: a sample is in when it lies inside the outer ellipse and, for a ring, outside the inner one.
// Push: the colour's bytes, straight; cx, cy, rx, ry; stroke, ring (1), 0, 0.
sample in vec2 at;
layout(std140) uniform Paint { uvec4 rgba; vec4 shape; vec4 band; } oval;
layout(location = 0) out vec4 color;

void main()
{
    float halfStroke = oval.band.x / 2.0;
    vec2 q = (at - oval.shape.xy) / (oval.shape.zw + halfStroke);
    if (dot(q, q) > 1.0) {
        discard;
    }
    if (oval.band.y > 0.5) {
        vec2 inner = oval.shape.zw - halfStroke;
        if (inner.x > 0.0 && inner.y > 0.0) {
            vec2 r = (at - oval.shape.xy) / inner;
            if (dot(r, r) <= 1.0) {
                discard;
            }
        }
    }
    float a = float(oval.rgba.a) / 255.0;
    color = vec4(vec3(oval.rgba.rgb) / 255.0 * a, a);
}
