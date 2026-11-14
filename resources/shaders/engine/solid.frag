#version 410 core

// One colour, given as its bytes, straight: written premultiplied for the blend
// (source + destination x (1 - source alpha)). The stencil step uses it with colour writes off.
layout(std140) uniform Paint { uvec4 rgba; };
layout(location = 0) out vec4 color;

void main()
{
    float a = float(rgba.a) / 255.0;
    color = vec4(vec3(rgba.rgb) / 255.0 * a, a);
}
