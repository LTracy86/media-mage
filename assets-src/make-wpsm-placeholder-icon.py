"""
PLACEHOLDER icon for WP Smooth Moves, built to ICON-SPEC.md v1 + the 2026-08-10
amendments. Emits a 64x64 pixel sprite as SVG (one <rect> run per horizontal
span, shape-rendering:crispEdges) so it scales to any size on a web page
without an image-rendering:pixelated dependency.

This exists so the landing page has something on-system to sit behind while the
real sprite gets drawn by hand in Aseprite. It follows the spec's geometry so
the real one can drop in without the layout moving.

    python make-wpsm-placeholder-icon.py > wpsm-icon-placeholder.svg

Subject: an archive crate mid-move with a motion arc. Chosen because the
silhouette survives 32px, which is the test the hat's brim was designed around.

Palette slots, per the amendment (subject shifted one step up the ramp):
    0 #1E2225  plate ground, internal separator lines
    1 #3A3A3C  plate rim only
    2 #14569B  shadow side
    3 #00AEEE  body, the large lit mass, and the motion arc
    4 #FFFFFF  focal glyph only, budget 5%
"""

import sys

N = 64
SLOT = {0: '#1E2225', 1: '#3A3A3C', 2: '#14569B', 3: '#00AEEE', 4: '#FFFFFF'}

grid = [[0] * N for _ in range(N)]


def px(x, y, slot):
    if 0 <= x < N and 0 <= y < N:
        grid[y][x] = slot


def rect(x0, y0, x1, y1, slot):
    for y in range(y0, y1 + 1):
        for x in range(x0, x1 + 1):
            px(x, y, slot)


# --- plate -----------------------------------------------------------------
# Slot 0 full bleed is the array default. 2px stair chamfer: remove the pixel
# triangle (0,0) (1,0) (0,1) and its three mirrors. -1 marks "no pixel at all".
for cx, cy in ((0, 0), (N - 1, 0), (0, N - 1), (N - 1, N - 1)):
    sx = 1 if cx == 0 else -1
    sy = 1 if cy == 0 else -1
    for dx, dy in ((0, 0), (1, 0), (0, 1)):
        grid[cy + dy * sy][cx + dx * sx] = -1

# 1px slot 1 rim tracing the chamfered outline.
for i in range(N):
    for x, y in ((i, 0), (i, N - 1), (0, i), (N - 1, i)):
        if grid[y][x] != -1:
            grid[y][x] = 1
# the chamfer's diagonal step needs the rim carried across it
for cx, cy in ((0, 0), (N - 1, 0), (0, N - 1), (N - 1, N - 1)):
    sx = 1 if cx == 0 else -1
    sy = 1 if cy == 0 else -1
    px(cx + 2 * sx, cy + 0 * sy, 1)
    px(cx + 1 * sx, cy + 1 * sy, 1)
    px(cx + 0 * sx, cy + 2 * sy, 1)


# --- subject: a moving box travelling right, inside x8..55 y8..55 -----------
# Flat-on, not isometric. An isometric cube was tried first and read as a
# faceted gem: three planes plus crate banding is more information than 48px
# can carry, and the banding lattice destroyed the silhouette. A flat box with
# a lid and a tape seam survives 32px, which is the only test that matters.
#
# Sits low and right: bounding box y18..52 against a zone of 8..55. The trailing
# speed lines need the left margin, so the box is not centred in it.

BOX_L, BOX_R = 22, 52     # body
BOX_T, BOX_B = 26, 50
LID_L, LID_R = 19, 55     # lid overhangs the body by 3px each side
LID_T = 18

# Lid and body, both the large lit mass.
rect(LID_L, LID_T, LID_R, BOX_T - 1, 3)
rect(BOX_L, BOX_T, BOX_R, BOX_B, 3)

# Shadow: the right-hand strip of each, the face turned away from a top-left
# light. Separated from the lit mass by a slot 0 cut, per the separator rule.
rect(BOX_R - 6, BOX_T, BOX_R, BOX_B, 2)
px(BOX_R - 7, BOX_T, 0)
for y in range(BOX_T, BOX_B + 1):
    px(BOX_R - 7, y, 0)
rect(LID_R - 6, LID_T, LID_R, BOX_T - 1, 2)
for y in range(LID_T, BOX_T):
    px(LID_R - 7, y, 0)

# Seam between lid and body, and the tape seam down the front.
for x in range(LID_L, LID_R + 1):
    px(x, BOX_T - 1, 0)
for y in range(BOX_T, BOX_B + 1):
    px(BOX_L + 13, y, 0)
    px(BOX_L + 14, y, 0)

# --- focal glyph: a white arrow across the box front, budget 5% -------------
# Points the way the box is travelling. Sits on the lit face because that is
# where the eye lands first at small sizes.
AY = 38
for x in range(27, 43):                      # shaft
    px(x, AY, 4)
    px(x, AY + 1, 4)
for i in range(6):                           # head
    px(42 - i, AY - i, 4)
    px(43 - i, AY - i, 4)
    px(42 - i, AY + 1 + i, 4)
    px(43 - i, AY + 1 + i, 4)

# --- motion cue: slot 3 speed lines trailing behind ------------------------
# Left of the box, so the box reads as having moved away from them.
for ly, length in ((24, 9), (32, 12), (40, 9)):
    for x in range(11, 11 + length):
        px(x, ly, 3)
        px(x, ly + 1, 3)


# --- emit -------------------------------------------------------------------
def emit():
    out = [
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" '
        'width="64" height="64" role="img" aria-label="WP Smooth Moves placeholder icon" '
        'shape-rendering="crispEdges">',
        '<title>WP Smooth Moves (placeholder icon)</title>',
    ]
    # Greedy rectangle packing. Row-runs alone emitted 16.6 KB, which is too
    # much markup to inline on a page for one 64px mark; most of this sprite is
    # solid blocks, so growing each run downward while the rows match collapses
    # it by roughly 6x.
    for slot in (0, 1, 2, 3, 4):
        taken = [[False] * N for _ in range(N)]
        rects = []
        for y in range(N):
            x = 0
            while x < N:
                if grid[y][x] != slot or taken[y][x]:
                    x += 1
                    continue
                x1 = x
                while x1 < N and grid[y][x1] == slot and not taken[y][x1]:
                    x1 += 1
                w = x1 - x
                h = 1
                while y + h < N and all(
                        grid[y + h][cx] == slot and not taken[y + h][cx]
                        for cx in range(x, x1)):
                    h += 1
                for cy in range(y, y + h):
                    for cx in range(x, x1):
                        taken[cy][cx] = True
                rects.append((x, y, w, h))
                x = x1
        if not rects:
            continue
        out.append('<g fill="%s">' % SLOT[slot])
        for x, y, w, h in rects:
            out.append('<rect x="%d" y="%d" width="%d" height="%d"/>' % (x, y, w, h))
        out.append('</g>')
    out.append('</svg>')
    return '\n'.join(out)


if __name__ == '__main__':
    svg = emit()
    counts = {}
    for row in grid:
        for v in row:
            counts[v] = counts.get(v, 0) + 1
    total = N * N - counts.get(-1, 0)
    sys.stderr.write('pixels %d  white %.1f%% (budget 5)  slot3 %.1f%%\n'
                     % (total, 100 * counts.get(4, 0) / total, 100 * counts.get(3, 0) / total))
    sys.stdout.write(svg)
