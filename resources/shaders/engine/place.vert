#version 410 core

// Every vertex is a point in surface pixels, origin top-left. Pixel y = 0 goes to clip -1, which is
// window row 0 and the texture's row 0: the image reads back top row first, with no flip. A target that
// holds only part of the surface (a layer's sheet) says where it starts: frame.zw.
layout(location = 0) in vec2 point;
layout(std140) uniform Frame { vec4 frame; };   // xy: the target's size; zw: where on the surface it starts
out vec2 at;

void main()
{
    vec2 q = point - frame.zw;
    gl_Position = vec4(q / frame.xy * 2.0 - 1.0, 0.0, 1.0);
    at = point;
}
