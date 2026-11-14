#version 410 core
// units: cells, directions

// Raycasting, the hits pass: Velvet's grid walk (PHPLandMarcher, Amanatides and Woo), one pixel a ray's row. OpenGL 4.1
// has no compute: a pass over the hits target does what a dispatch does, each pixel walking its ray.
//
// The GPU has no doubles, so a ray does not start from its absolute position: it starts from its cell (an integer)
// and where in that cell it is (a fraction), worked out by the CPU in double. A boundary's distance is worked out
// from how many boundaries were crossed (one rounding each), not added up step by step. So float32 only ever holds
// small numbers, and a hit's cell, wall and face are the walk's own integers.
//
// Ray i is at x = i % per_row, rows 2(i / per_row) and the next. The first row holds distance, along, cell x, cell y;
// the second wall, face. A miss is all zero (wall 0).

// The grid's cells, a byte each, four to a word, 1024 words a row.
uniform highp usampler2D cells;

// Each ray's own direction, when bit 2 of flags says so: ray i at (i % per_row, i / per_row).
uniform sampler2D directions;

layout(location = 0) out vec4 color;

// The eye's cell and where in it; the centre ray's direction and the camera plane (zero for one ray); how many rays;
// how many a pair of rows holds; bit 0: there is a reach, bit 1: the eye is inside the grid, bit 2: each ray's
// direction is its own; the reach; the grid's size.
layout(std140) uniform Paint {
    ivec2 cell;
    vec2 frac;
    vec2 dir;
    vec2 plane;
    uint columns;
    uint per_row;
    uint flags;
    float reach;
    ivec2 grid;
} c;

uint cellAt(ivec2 at)
{
    uint i = uint(at.y) * uint(c.grid.x) + uint(at.x);
    uint word = i >> 2;

    return (texelFetch(cells, ivec2(int(word & 1023u), int(word >> 10)), 0).r >> ((i & 3u) * 8u)) & 0xFFu;
}

void main()
{
    ivec2 p = ivec2(gl_FragCoord.xy);
    uint row = uint(p.y) & 1u;
    uint index = uint(p.y / 2) * c.per_row + uint(p.x);
    color = vec4(0.0);
    if (index >= c.columns || (c.flags & 2u) == 0u) {
        return;
    }

    vec2 d;
    if ((c.flags & 4u) != 0u) {
        d = texelFetch(directions, ivec2(int(index % c.per_row), int(index / c.per_row)), 0).xy;
    } else {
        float across = float(2u * index + 1u) / float(c.columns) - 1.0;
        d = c.dir + c.plane * across;
    }

    bool moveX = d.x != 0.0;
    bool moveY = d.y != 0.0;
    if (!moveX && !moveY) {
        return;
    }

    // how far along the ray one whole cell is, in x and in y
    float deltaX = moveX ? abs(1.0 / d.x) : 0.0;
    float deltaY = moveY ? abs(1.0 / d.y) : 0.0;
    int stepX = d.x < 0.0 ? -1 : 1;
    int stepY = d.y < 0.0 ? -1 : 1;

    // how far along the ray the first boundary is, in x and in y
    float firstX = moveX ? (d.x < 0.0 ? c.frac.x : 1.0 - c.frac.x) * deltaX : 0.0;
    float firstY = moveY ? (d.y < 0.0 ? c.frac.y : 1.0 - c.frac.y) * deltaY : 0.0;

    bool reaches = (c.flags & 1u) != 0u;
    ivec2 cell = c.cell;
    int crossedX = 0;
    int crossedY = 0;
    // a ray inside the grid crosses at most width + height boundaries before it leaves
    int limit = c.grid.x + c.grid.y + 2;
    for (int i = 0; i < limit; i++) {
        precise float nextX = fma(float(crossedX), deltaX, firstX);
        precise float nextY = fma(float(crossedY), deltaY, firstY);

        // a tie steps in y, as Velvet's does
        bool acrossX = moveX && (!moveY || nextX < nextY);
        float distance;
        if (acrossX) {
            distance = nextX;
            crossedX++;
            cell.x += stepX;
        } else {
            distance = nextY;
            crossedY++;
            cell.y += stepY;
        }

        if ((reaches && distance > c.reach) || cell.x < 0 || cell.y < 0 || cell.x >= c.grid.x || cell.y >= c.grid.y) {
            return;
        }

        uint wall = cellAt(cell);
        if (wall == 0u) {
            continue;
        }

        // where the ray meets the face, from the eye's cell: its fraction is the same as from the grid's origin
        float where = acrossX ? c.frac.y + distance * d.y : c.frac.x + distance * d.x;
        uint face = acrossX ? (stepX > 0 ? 0u : 1u) : (stepY > 0 ? 2u : 3u);
        color = row == 0u ? vec4(distance, where - floor(where), float(cell.x), float(cell.y)) : vec4(float(wall), float(face), 0.0, 0.0);

        return;
    }
}
