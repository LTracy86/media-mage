# TDM Pixel Icon System, v1

Source of truth for the Media Mage plugin icon and for every TDM dev-plugin
icon that follows it. Aseprite is the authoring tool. Nothing in this folder
ships: `assets-src/` is excluded by both `.distignore` and `.gitattributes`.

Red Pen is exempt from this system. It keeps its nib mark and its red, which is
one of the two documented palette exceptions.

---

## 1. Palette

The five locked TDM colors, ordered as a value ramp. Load them into the Aseprite
palette in exactly this order so the index numbers below mean the same thing in
every plugin's sprite.

| Slot | Hex | L\* | Role |
|------|-----|-----|------|
| 0 | `#1E2225` | 13 | Plate ground, void, internal separator line |
| 1 | `#3A3A3C` | 24 | Shadow side, occlusion, leather, unlit surfaces |
| 2 | `#14569B` | 37 | Body midtone. The brand anchor. Most of the subject. |
| 3 | `#00AEEE` | 66 | Lit edge, glow, arcane accent, glyphs |
| 4 | `#FFFFFF` | 100 | Specular sparkle only. Keep under 2% of pixels. |

No sixth color, no dithering between slots to fake intermediate tones. Five
steps is enough for a 64px sprite and the discipline is what makes the family
look deliberate.

### Amendment, 2026-08-10: the subject is lit, not mid

Section 3 says the subject body is slot 2 and its shadow is slot 1. Built and
rendered, that is wrong, and the Media Mage hat is the proof. Slot 2 (`#14569B`,
L\* 37) against the slot 0 plate (L\* 13) is only a 24-point separation, and the
slot 1 shadow (L\* 24) lands almost exactly between them. The result is a muddy
subject whose shadowed side dissolves into the plate.

What works, and what the hat now uses:

| Region | Slot | Hex |
|--------|------|-----|
| Body, the large lit mass | 3 | `#00AEEE` |
| Shadow side | 2 | `#14569B` |
| Internal fold and separator lines | 0 | `#1E2225` |
| Focal glyph | 4 | `#FFFFFF` |

Slot 1 (`#3A3A3C`) is then free for the plate rim, where its closeness to the
ground is an advantage rather than a problem. **Shift the whole subject one step
up the ramp.** Slot 2 is the shadow, not the body.

A consequence worth stating: with the body already at slot 3, there is no
brighter step left for a lit edge except white, and a white rim light reads as
blowout and fights the focal glyph. Tested at 7.7% white and rejected. The
silhouette is carried by body-against-plate contrast instead, which at a
24-point L\* gap is more than enough.

### Amendment: the white budget

Section 1 caps slot 4 at 2% of pixels. That was written for a sparkle. A focal
glyph, like the hat's star, runs about 4% and should. Keep the 2% cap for
incidental sparkle and allow a single focal element up to 5%. Past that it stops
being focal.

### The separator rule

Slot 1 (shadow) and slot 0 (ground) are close in value, so where a slot-1 region
touches another slot-1 region they merge into a blob. Separate them with a 1px
line of slot 0. Using the ground color as an internal cut is standard pixel-art
practice and it is a system rule here, not a one-off fix.

---

## 2. The plate

Every TDM plugin icon is built on the same plate. This is what makes them read as
a set at 128px in the wp.org grid.

- Canvas: **64 x 64**, no larger. Detail beyond this does not survive downscaling
  to the WordPress admin plugin list.
- Fill: slot 0 (`#1E2225`), full bleed.
- Corners: a 2px stair chamfer. At each corner remove the pixel triangle
  `(0,0) (1,0) (0,1)` and its three mirrors. This reads as a cut sprite tile
  rather than a flat rectangle and it is the single most recognizable family cue.
- Rim: 1px slot 1 (`#3A3A3C`) tracing the chamfered outline.
- Subject zone: **x 8..55, y 8..55** (48 x 48). Every subject lives inside this
  box. The 8px margin is what keeps icons from colliding with each other in a
  dense grid.

---

## 3. Lighting

Single light source, **top-left**, for every icon in the family.

- Surfaces facing up-left take a 1px slot 3 (`#00AEEE`) lit edge.
- Surfaces facing down-right take slot 1 (`#3A3A3C`).
- Everything between is slot 2 (`#14569B`).
- Slot 4 (`#FFFFFF`) appears only as a specular point or a sparkle, never as a
  fill.

No gradients, no ambient occlusion ramps. Hard-edged three-tone shading.

---

## 4. Media Mage: the hat

A pointed wizard hat with a curled tip, a leather band, and a media glyph on the
band. Coordinates are in 64-space, origin top-left, x right, y down.

### Optical centering

A cone-plus-brim silhouette is top-heavy in perceived weight, so it must sit
low to look centered. Subject bounding box is **y 10..54**, not 8..55.

### Construction order (bottom layer to top)

1. **Brim.** Ellipse, widest span `x 8..55` at `y 48`, about 6px thick. Top
   surface slot 2, front and under edge slot 1. This is the widest element in
   the sprite and it is what makes the silhouette readable at 32px.

2. **Cone.** Base `x 20..43` at `y 44`. Rises with a lean to the right. Left
   edge runs from `(20,44)` up toward `(40,16)`. Body slot 2. A 3px column of
   slot 1 down the right side. A 1px slot 3 lit edge along the left contour.

3. **Curled tip.** The top ~12px of the cone curls right, ending with the tip
   pointing down-right at roughly `(49,15)`. The curl is the character of the
   thing. A straight cone reads as a traffic cone and reads as generic; the
   asymmetry is what makes it a wizard hat at a glance.

4. **Band.** `x 19..44`, `y 40..46`, overhanging the cone by 1px each side.
   Fill slot 1. Put a 1px slot 0 separator line at `y 39` between band and cone,
   or the band's slot 1 merges with the cone's slot 1 shadow column.

5. **Media glyph** on the band interior (`x 20..43, y 41..45`), in slot 3:
   - Mountain: base `y 45`, spanning `x 28..37`, apex around `(32,41)`.
   - Sun: 2x2 block at `(26,42)`.

   This is the entire "media" signal. At 32px it will blur into a bright smear on
   the band, which is fine. The hat silhouette carries small sizes; the glyph
   rewards anyone looking at 128px or larger.

6. **Sparkle.** One 3px plus-shape in slot 4 just off the hat tip, around
   `(53,10)`. Optionally a single slot 3 pixel diagonally out from it. This is
   the only white in the sprite.

### What actually got drawn, 2026-08-10

Lincoln drew the hat by hand in Aseprite and it shipped with two deviations from
the plan above, both kept on purpose.

**A star, not a band and media glyph.** The star sits where the band was
specified. It is a stronger read at small sizes than a 5px band with a mountain
on it would have been, and it is the better drawing. The cost is real and worth
naming: nothing in the icon now says *media*, only *mage*. The band belongs on
the 128 and 256 exports, below the star, where there is room for both.

**Silhouette drawn at 32, delivered at 64.** The file is 64x64 but is an exact
2x of a 32x32 design, so every pixel is a 2x2 block. That is why the plate rim
here is 1px on the 32 grid, which lands as 2px at 64 and happens to match the
specified corner chamfer exactly. Keep new detail on the same 2px grid or it
will read as a different resolution pasted on top. Drawing the sibling icons at
true 64 is fine, but then do not mix the two fidelities inside one sprite.

The silhouette itself needed no correction. It passed the 32px squint test on
the first attempt, which is the only part of this that is hard.

### The band and glyph, as actually built (2026-08-11)

Section 4 puts the band at `y 40..46` with the glyph on it. With the star drawn
in, that space does not exist: the star's bounding box is `y 24..41` and the brim
flares at `y 44`, leaving two rows between them. Measure before drawing.

What shipped instead, on the 128, 256 and 1024 exports only:

- **Band** at `y 42..48` in 64-space, following the silhouette, inset 2px each
  side, filled slot 2 (`#14569B`) against the slot 3 body. It crosses the base of
  the cone and the top of the brim flare, which is where a real hat band sits.
- **Separator** of 1px slot 0 on the row above and the row below. Without it the
  band's slot 2 merges into the shadow regions already in the brim.
- **Glyph** in slot 3 (`#00AEEE`), centred in the band: two mountain peaks, the
  second at 60% of the first, with a round sun to their upper left.

Three things had to be got right and each was wrong on the first attempt:

1. **The sun must be a circle, drawn with a radius test.** A 3x3 or 4x4 block
   reads as a square, and a square next to a triangle says nothing. Rendered, it
   looked like a stray pixel.
2. **The second peak is what makes it a landscape.** One triangle plus a circle
   reads as an abstract mark. Two peaks of different heights reads as scenery,
   which is what carries the meaning.
3. **Every glyph pixel must stay inside the band.** The first version put the sun
   above the band on the cyan body, cyan on cyan, and it disappeared. The drawing
   helper now clamps to the band's rows rather than trusting the arithmetic.

**The 64 master and the plugin's own `assets/icon.png` deliberately do NOT carry
the band.** Below about 128 the glyph is a smudge and the band costs contrast on
the silhouette, which is the thing that has to survive at 14px in the menu title.
Progressive detail by export size is the intent, not an oversight.

Not worth repeating: the glyph is invisible on the Tracy Digital Media portfolio
card, because that card lays a text scrim over the bottom third of the image and
the band sits at 66 to 75% down. The canonical icon is used there anyway rather
than maintaining a second variant.

### The test that matters

Export at 32x32 and squint. If the hat is not instantly a hat, the silhouette is
wrong and no amount of band detail will save it. Fix the brim width and the tip
curl before touching anything else.

---

## 5. Export

Aseprite: **Sprite > Resize** with `Nearest Neighbor`, or export with an integer
scale. Never let it interpolate. Both target sizes are clean multiples of 64.

| Output | Scale | Purpose |
|--------|-------|---------|
| `icon-128x128.png` | 2x | wp.org directory grid |
| `icon-256x256.png` | 4x | wp.org retina |
| `assets/icon.png` (32x32) | 0.5x, hand-corrected | The plugin's own admin header. See note. |

The 32x32 in-plugin icon cannot be produced by downscaling, because halving a
64px sprite averages pixels and destroys the hard edges. Either hand-redraw the
silhouette at 32x32, or ship the 64x64 unscaled and let CSS size it down (the
plugin already renders it at 14px in the menu title and larger in the header, so
a 64px source is the safer choice).

### Banner, as actually built (2026-08-11)

`assets-src/make-banner.php` renders both sizes from one layout defined in
386x125 banner space, so the two exports are the same design rather than one
resampled from the other. Verified: downscaling the 4x to the 2x size gives a
mean channel difference of 2.0.

Three decisions worth keeping:

- **The plate is stripped off the hat.** With it, the icon reads as a thumbnail
  pasted onto the banner; without it, the hat sits on the panel as a logo. Only
  the *exterior* is dropped, by flood-fill from the border, because the same
  slot-0 tone also draws the interior fold lines.
- **The hat renders at 1.5x banner space**, which is a whole number at both
  export scales (3 and 6). Any non-integer multiple destroys pixel art, so the
  size was chosen to fit the scales rather than the other way round.
- **One tagline, not three lines.** The first draft had a third line in slot 1
  (`#3A3A3C`) on the dark ground and it was unreadable. Both lines auto-fit
  against a 24px banner-space right margin, so changing the copy cannot push
  text off the edge.

The background is a slow wash from slot 0 to slot 2 across the width. It never
mixes outside those two, so the banner stays inside the palette.

### Banner

Separate canvas, **386 x 125**. Export 2x for `banner-772x250.png` and 4x for
`banner-1544x500.png`. Place the 64x64 hat at 1x with the wordmark beside it;
the 4x export puts the hat at 256px in a 1544px banner, which is correct weight.

### Where these files go

wp.org keeps directory assets **outside** the plugin. In the SVN checkout:

```
/assets/          <- icon-128x128.png, icon-256x256.png, banner-*.png, screenshot-*.png
/trunk/           <- the plugin itself
/trunk/assets/    <- the plugin's OWN icon.png, used by its admin UI
```

Two folders named `assets`. They are not the same folder and the directory
listing will not show your icon if you put it in the wrong one.

---

## 6. Applying the system to the rest of the family

Same plate, same ramp, same top-left light, same 48x48 subject zone. Only the
subject changes.

- **WP Smooth Moves** - a moving crate or a packed box with a motion trail.
- **wp-field-forge** - an anvil, or a hammer over a form field bracket.
- **Chiptune Codex** - a book with a waveform on the cover.

Draw each subject to fill the 48x48 zone as fully as its shape allows. Icons
that under-fill their zone look weak next to ones that do not, and inconsistent
optical weight is the fastest way to make a family stop looking like one.
