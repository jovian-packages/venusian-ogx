#version 410 core
// units: frame

// Reading: premultiplied colour as straight colour.
uniform sampler2D frame;
layout(location = 0) out vec4 color;

void main()
{
    vec4 c = texelFetch(frame, ivec2(gl_FragCoord.xy), 0);
    color = c.a > 0.0 ? vec4(c.rgb / c.a, c.a) : vec4(0.0);
}
