#version 410 core
// units: frame

// Writing: straight colour as premultiplied colour.
uniform sampler2D frame;
layout(location = 0) out vec4 color;

void main()
{
    vec4 c = texelFetch(frame, ivec2(gl_FragCoord.xy), 0);
    color = vec4(c.rgb * c.a, c.a);
}
