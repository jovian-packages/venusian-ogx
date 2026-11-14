#version 410 core
// units: frame

// The image as it is: fills the four-sample twin from it.
uniform sampler2D frame;
layout(location = 0) out vec4 color;

void main()
{
    color = texelFetch(frame, ivec2(gl_FragCoord.xy), 0);
}
