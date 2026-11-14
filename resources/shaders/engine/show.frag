#version 410 core
// units: frame

// Showing: the frame's premultiplied texture into a window's back buffer. The texture's row 0 is the
// surface's top; a window's row 0 is its bottom, so rows are read from the end.
uniform sampler2D frame;
layout(location = 0) out vec4 color;

void main()
{
    ivec2 p = ivec2(gl_FragCoord.xy);
    color = texelFetch(frame, ivec2(p.x, textureSize(frame, 0).y - 1 - p.y), 0);
}
