// A point in target pixels, origin top-left, to clip space. `at` is the point
// itself, interpolated, so the fragment knows its pixel.
in vec2 point;
uniform vec2 size;
out vec2 at;

void main()
{
    gl_Position = vec4(point.x / size.x * 2.0 - 1.0, 1.0 - point.y / size.y * 2.0, 0.0, 1.0);
    at = point;
}
