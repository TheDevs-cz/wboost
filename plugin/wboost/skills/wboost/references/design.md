# Authoring a design — the workflow, the overwrite guard, the DSL

Read this before `preview_design` / `set_design`. Contents:

- [Preview until it is right, then commit once](#preview-until-it-is-right-then-commit-once)
- [`acknowledgeLosses` is a decision, not a retry](#acknowledgelosses-is-a-decision-not-a-retry)
- [The design DSL, in brief](#the-design-dsl-in-brief) and [a worked document](#a-worked-document)
- [The exhaustive key tables](#the-design-dsl--the-exhaustive-key-tables) (keys per block, enumerated values, required + defaults)

## Preview until it is right, then commit once

`preview_design` compiles your design document, draws it against a **detached
copy** of the variant and saves nothing — not the canvas, not the thumbnail, not
the inputs. It is free to call repeatedly and is where the work happens.
`set_design` is the same pass with a write at the end.

Read **`rendered`** (preview) / **`saved`** (set) first. False means nothing was
drawn or written, and `issues[]` says why. Every issue carries:

- **`severity`** — `error` blocks, `warning` never does. A reply with a picture
  and three warnings means "this drew, but read these".
- **`stage`** — which gate objected, and therefore what kind of fix it takes:
  `parse` (grammar), `variant` (written for the wrong canvas size), `lint`
  (design review), `compile` (this project has no such font or picture),
  `overwrite` (see rule 7).
- **`path`** like `elements[2].font`, and a message saying what to change.

Two things that trip up authoring agents:

- **An unknown font face is an error, not a warning**, and it stops the call
  before anything is rendered. That is deliberate: the render would not fail on
  it — headless Chromium would silently substitute another face and hand back a
  confident picture of a design that does not exist. Take faces from
  `get_context` verbatim.
- **`canvas.width` / `canvas.height` must be the variant's own size**, which
  `describe_variant` reports. The render always uses the variant's size, so a
  mismatch silently misplaces everything. Print sizes are authored at their
  300 DPI raster size — A4 is `2480 × 3508`, never `210 × 297`.

**Slugs are identity.** An element whose `id` matches an input the variant
already has keeps that input's UUID, so saved fills, API consumers and container
membership survive a re-save. Rename a slug and you mint a new input; keep it and
you keep the history.


## `acknowledgeLosses` is a decision, not a retry

This is the most consequential thing in the connector. Read it before you ever
call `set_design`.

`set_design` **replaces a variant's whole canvas**. Before writing, it decompiles
the design that is *already there* and asks a question none of the other stages
ask: *can this DSL express what is about to be overwritten?* When the answer is
no, the call is refused with `stage: "overwrite"` issues, and each one names
something real that saving would **destroy**.

The commonest case is not exotic. A background uploaded through the
add/edit-variant form is stored with **no gallery row at all**, so the DSL — which
names pictures by gallery id — cannot name it; writing any document over it
leaves the variant with **no background**. Design-hidden layers and groups (and
any object that is not a text, image or vector shape) disappear the same way; per-character styling, shadows and list
configuration go more quietly.

**This is the one refusal in the connector that is not about your document.**
Nothing you change in your design will clear it — and that is the trap, because
the reflex it invites is exactly wrong. `acknowledgeLosses: true` fixes nothing
and changes not one pixel of what gets written. It only downgrades those errors
to warnings so the write proceeds, destroying precisely what the refusal listed.
Passing it because a call failed is how somebody loses an afternoon's work.

So:

1. **Read the findings out to the user.** Say plainly what would be lost.
2. **Prefer keeping the thing.** Most losses have a repair, and the messages name
   it: upload that background to the gallery with `upload_image` and reference it
   by id in your document, and the loss stops existing.
3. **Only pass `acknowledgeLosses: true` once the user has seen the list and
   wants the replacement anyway.** If you are authoring on your own initiative
   and nobody asked for a wholesale rewrite, stop and ask. "The user asked for a
   new poster design" is not consent to delete the background of the variant you
   happened to pick.

The flag has no reason to exist until a refusal has already enumerated what it
covers. On the acknowledged call the same findings come back as warnings, so the
transcript records what was destroyed.

One reassurance: a canvas this tool wrote decompiles losslessly, so the guard
fires **at most once per variant** — exactly at the boundary between a
browser-authored design and a DSL-authored one, and never again while you
iterate. A second `set_design` on your own design is clean.

Non-destructive findings (today: the canvas-level background of a legacy
variant, which lives on the row and survives any canvas write) are reported as
warnings whether acknowledged or not. They never block.


## The design DSL, in brief

A design document is `{"canvas": {...}, "elements": [...]}`, with `elements` in
**stack order, bottom to top**. Each element has a `kind` (`text`, `image`,
`shape`, `background`, `container`) and a short slug `id` you choose
(`^[a-z0-9][a-z0-9_-]*$`, ≤ 64 chars, unique in the document).

**Unknown keys are rejected, not ignored** — a typo like `fontSize` for `size` is
reported with the nearest valid key suggested, rather than silently applying a
default and producing a design that is wrong in a way nobody can see. Every
problem in the document is reported at once, so fix them as a batch.

**No control characters.** No string or key of the document — a text, a
`sampleValue`, a name, a font, an id — may hold a control character other than
a line break (`\n`, `\r`) or a tab: U+0000–U+001F, U+007F and U+0080–U+009F
are refused by `preview_design` and `set_design` alike (`stage: "parse"`,
`code: "invalid_value"`, the `path` of the string, the message names the
character as `U+000B`; at most ten are listed). They draw nothing and usually
ride in with text copied from a PDF or an office document (a vertical tab is
PowerPoint's soft line break) — replace them with `\n` or delete them, do not
resend. Zero-width and bidi characters (emoji joiners, Arabic / Hebrew copy)
are fine.

Placement is either **semantic** (`at`, preferred — it adapts to any canvas size)
or **absolute** (`x`, `y`, `width`, and `height` for images), in canvas pixels
from the top-left. Semantic placement puts the element in a vertical `area`
(`top`, `upper`, `middle`, `lower`, `bottom`, `full`) across a span of a
**12-column grid** (`col: [start, end]`, inclusive, default `[1, 12]`), with
optional `marginX` / `marginY` and `offsetX` / `offsetY` nudges.

**Every `text` element is a fillable input** — a visible text box always is; the
`input` block only configures it, and `"input": {"locked": true}` makes it
fixed copy nobody can overwrite (headings like "Kontakt", legal lines). An `image` element with an `input` block is a fillable
picture slot; without one it is decorative. A `shape` element is a vector block —
`rectangle`, `square`, `circle`, `ellipse`, `triangle`, `line` or `star` — filled
with a flat colour or a gradient (2–8 stops, each with its own opacity — e.g.
a white 0 → 100 % fade as an overlay over a photo); it is decorative unless you give it a
`userColor` block, which lets whoever fills the template recolour it (it then
shows up in `describe_variant` `shapeInputs[]`). Shapes are how you author panels,
rules and scrims rather than uploading a picture of one. A `container` groups `members` (texts, shapes and images —
decorative ones and fillable image slots alike) and `children` (nested containers) into one vertical flow — a **top-level
container must carry `maxHeight`**, a nested one must not need it. A nested container with
`repeat: {"max": 8}` is a row the person filling it repeats ("+ Přidat řádek") — design ONE row,
never number rows by hand.

**Every design should have exactly one `background` element** — the editor's
locked "Pozadí" layer at the bottom, always covering the whole canvas incl. the
print bleed: a picture (`"asset": "<gallery id>"`) or a colour (`"fill":
"#ffffff"`, or a gradient object like a shape's). Never draw the background as a
full-canvas `shape` — that is an ordinary layer, not "Pozadí", and the linter
says so (`background_drawn_as_element`); no background at all is linted as
`missing_background` (it exports transparent).


## A worked document

This document parses, and exercises all five kinds:

```json
{
  "canvas": { "width": 1080, "height": 1350 },
  "elements": [
    { "kind": "background", "id": "bg", "asset": "6f9619ff-8b86-d011-b42d-00cf4fc964ff", "fillable": true },
    {
      "kind": "text",
      "id": "headline",
      "text": "SLEVA 50 %",
      "at": { "area": "top", "col": [1, 12], "marginX": 80, "offsetY": 40 },
      "font": "Rubik (Rubik Bold)",
      "size": 96,
      "color": "#ffffff",
      "align": "left",
      "lineHeight": 1.16,
      "input": { "name": "Nadpis", "maxLength": 24, "uppercase": true, "sampleValue": "SLEVA 50 %" }
    },
    {
      "kind": "text",
      "id": "subhead",
      "text": "Na vše do konce týdne",
      "at": { "area": "upper", "col": [1, 8], "marginX": 80 },
      "font": "Rubik (Rubik)",
      "size": 42,
      "align": "left",
      "input": { "name": "Podnadpis", "richText": true }
    },
    {
      "kind": "shape",
      "id": "scrim",
      "shape": "rectangle",
      "at": { "area": "upper", "col": [1, 12] },
      "height": 320,
      "fill": { "type": "linear", "angle": 90, "from": "#111111", "to": "#c8102e" },
      "cornerRadius": 24,
      "opacity": 0.85
    },
    {
      "kind": "container",
      "id": "body",
      "members": ["headline", "subhead"],
      "maxHeight": 400,
      "gap": 24,
      "spaceAfter": 60
    },
    {
      "kind": "image",
      "id": "photo",
      "at": { "area": "bottom", "col": [1, 12] },
      "height": 480,
      "asset": "3f2504e0-4f89-11d3-9a0c-0305e82c3301",
      "input": { "name": "Foto", "placeholder": true, "allowResize": false, "hidable": true, "allowedDirectories": [] }
    }
  ]
}
```

The `asset` values are **gallery image ids** from `list_gallery` or
`upload_image` — never a file name or a URL. The exhaustive key list for every
block is below; it is the parser's own, so it cannot drift.

---

## The design DSL — the exhaustive key tables

The parser is **strict**: a key not listed here is rejected, with the nearest
valid key suggested. Every problem in a document is reported at once.

Element `id` is a slug: `^[a-z0-9][a-z0-9_-]*$`, at most 64 characters, unique
within the document. Slugs carry input identity across saves.

### Keys per block

| block | keys the parser accepts |
|---|---|
| document root | `canvas`, `elements` |
| `canvas` | `width`, `height`, `background` |
| `canvas.background` | `image`, `fill` |
| `at` | `area`, `col`, `marginX`, `marginY`, `offsetX`, `offsetY` |
| `text` element | `kind`, `id`, `text`, `font`, `size`, `color`, `align`, `lineHeight`, `letterSpacing`, `opacity`, `at`, `x`, `y`, `width`, `input`, `userMove` |
| `text` input block | `name`, `maxLength`, `uppercase`, `hidable`, `locked`, `richText`, `sampleValue`, `allowedFonts`, `fontChoice`, `allowedColors` |
| `image` element | `kind`, `id`, `asset`, `at`, `x`, `y`, `width`, `height`, `input`, `mirror`, `opacity`, `userMove` |
| `image` input block | `name`, `placeholder`, `allowMove`, `allowResize`, `allowRotate`, `hidable`, `allowedDirectories` |
| `shape` element | `kind`, `id`, `shape`, `fill`, `stroke`, `strokeWidth`, `strokeStyle`, `cornerRadius`, `opacity`, `name`, `locked`, `userColor`, `at`, `x`, `y`, `width`, `height`, `userMove` |
| `shape` gradient fill | `type`, `angle`, `from`, `to`, `stops` |
| `shape` gradient stop | `at`, `color`, `opacity` |
| `shape` userColor block | `target`, `colors`, `free` |
| `background` element | `kind`, `id`, `asset`, `fill`, `fillable` |
| `container` element | `kind`, `id`, `members`, `children`, `maxHeight`, `gap`, `spaceAfter`, `hidable`, `name`, `userMove`, `repeat` |
| `repeat` block | `max`, `min`, `default`, `slots` |
| `userMove` block | `vertical`, `horizontal`, `maxUp`, `maxDown`, `maxLeft`, `maxRight` |

### Enumerated values

| where | accepted values |
|---|---|
| `kind` | `text`, `image`, `shape`, `background`, `container` |
| `at.area` | `top`, `upper`, `middle`, `lower`, `bottom`, `full` |
| `align` | `left`, `center`, `right`, `justify` |
| `shape` | `rectangle`, `square`, `circle`, `ellipse`, `triangle`, `line`, `star` |
| `strokeStyle` | `solid`, `dashed`, `dotted` |
| `userColor.target` | `fill`, `stroke`, `both` |
| gradient `fill.type` | `linear`, `radial` |

### Required, and defaults

- **root**: `canvas` and `elements` are both required; `elements` may be `[]`.
- **`canvas`**: `width` and `height` required, each 1…20000 canvas px, and both
  must equal the variant's own size. Print sizes are authored at their 300 DPI
  raster size (A4 = 2480 × 3508), never in millimetres. `background` is an
  optional shorthand for the background layer: `background.image` is the same
  as a `background` element with that `asset` (never both), and
  `background.fill` with no picture is the same as a `background` element with
  that `fill`. Prefer the element. A `background.fill` UNDER a picture
  background stays a canvas-level colour no editor panel shows (linted).
- **`text`**: `text`, `font` and `size` are required, plus a placement.
  `color` defaults to `#000000`, `align` to `left`, `lineHeight` to `1.16`,
  `opacity` to `1` (a fraction 0…1, like a shape's — `0.6` = 60 % opaque).
  `letterSpacing` is tracking in thousandths of an em, added after every
  character (`50` = 0.05 em, `-20` tightens; −200 … 1000, default `0`): use it
  for spaced-out capitals instead of a larger `size` or spaces between letters.
- **`text` `input`**: `maxLength` refuses longer fills, `uppercase` shouts for
  the user, `hidable` lets them blank it, `locked` makes it fixed copy,
  `sampleValue` is what renders until filled, `richText` allows styled runs.
  `allowedFonts` (faces from `get_context`) are the extra faces the user may
  switch the WHOLE text to, next to the designed one; with `richText`,
  `fontChoice: true` limits the per-run faces to the designed one +
  `allowedFonts`. `allowedColors`: `null` = any colour, `[]` = colour locked,
  a list = only those colours for styled runs.
- **placement**: give either `at`, **or** all of `x`, `y` and `width`
  (`height` too for an `image`). `at.col` defaults to `[1, 12]` on the
  12-column grid, start ≤ end; margins and offsets default to `0`.
- **`image`**: `asset` and `input` are both optional, but an image with neither
  draws nothing and is linted. With `input` it is a fillable slot; without, it is
  decorative. Its input defaults: `placeholder` true, `allowMove` true,
  `allowResize` true, `allowRotate` false, `hidable` false,
  `allowedDirectories` `[]` (= the whole gallery, never "none").
- **`shape`**: only `shape` is required, plus a placement (`height` included —
  a shape is whatever size you author it at; omitted, it is square). Everything
  else defaults to a plain black block: `fill` `#000000`, `stroke` `null`,
  `strokeWidth` `0`, `strokeStyle` `solid`, `cornerRadius` `0`, `opacity` `1`,
  `name` `null`, `locked` `false`. `fill` is either a colour string or a gradient
  object: two opaque colours as `{"type": "linear", "angle": 45, "from": "#…", "to": "#…"}`,
  or 2–8 stops with their own opacity as `{"type": "linear", "angle": 90, "stops":
  [{"at": 0, "color": "#ffffff", "opacity": 0}, {"at": 1, "color": "#ffffff", "opacity": 1}]}`
  (a fade-in overlay over a photo) — `at` and `opacity` are fractions 0…1,
  `opacity` defaults to `1`; use `from`/`to` OR `stops`, not both. `angle` is
  degrees clockwise, 0 = left to right, 90 = top to bottom, and is ignored for a
  `radial` gradient, which is always centre-out (`at` 0 = centre). `cornerRadius`
  applies to `rectangle` / `square` / `line` only; on the others it must stay
  `0`, because those shapes' radii ARE their size. `opacity` is a fraction
  (`0.6` = 60 % opaque), not a percentage. `locked` is the EDITOR lock — it makes
  the shape click-through for the designer and never affects the export. A shape
  is decorative by default, and it MAY be a container member. `userColor`
  (default `null`) opens it up for recolouring by whoever fills the template:
  `{"target": "fill"|"stroke"|"both", "colors": ["#…"], "free": false}` —
  `colors` are the swatches offered next to the designed colour (default `[]`),
  `free: true` accepts any colour. Such a shape then appears in
  `describe_variant` `shapeInputs[]`. A gradient `fill` (or a border with
  `strokeWidth` 0) cannot be recoloured, so that part is simply not offered.
- **`image` `opacity`**: a fraction 0…1 (default `1`), decorative or
  fillable. A filled placeholder keeps it: the user's picture renders at the
  designed opacity. Opacity is a design property only — nobody filling the
  template can change it. The `background` has none.
- **`image` `mirror`**: `true` flips a **decorative** image horizontally (default
  `false`). Rejected on a fillable placeholder — filling re-places the picture
  and resets any mirroring.
- **`background`**: **every design should have exactly one** — it is the
  editor's locked "Pozadí" layer, pinned to the bottom of the stack and always
  covering the whole canvas **including the print bleed** (it has no placement).
  It is a picture **or** a colour, never both: `asset` (a gallery image,
  cover-fitted) or `fill` (a colour string or a gradient object, exactly like a
  shape's `fill`). `{"kind": "background", "id": "pozadi", "fill": "#ffffff"}` is
  the plain white page. `fillable: true` (pictures only) makes it a fillable
  whole-canvas cover slot. With neither `asset` nor `fill` it is a deliberately
  transparent background. **Never draw the background as a full-canvas
  `shape` or `image`** — that is an ordinary clickable layer, not "Pozadí"
  (lint `background_drawn_as_element`); a design with no background at all
  exports transparent (lint `missing_background`).
- **`container`**: needs at least 2 referenced items counting `members` and
  `children` together. `members` are texts, shapes and images — decorative ones
  AND fillable image placeholders (a slot flows as a fixed box: a wrapping text
  above moves it, the picture keeps its place inside the frame, hiding it
  closes the gap) — never the background, never another container (nest via
  `children`). A container has exactly one parent and the graph must be
  a tree. **A top-level container must carry `maxHeight`**; a nested one may omit
  it, because only the root's bound gates overflow. `gap` replaces every designed
  inter-item gap with a uniform spacing; `spaceAfter` is guaranteed clearance
  below. `hidable: true` lets the user (fill page) and API/MCP callers hide the
  WHOLE container with one toggle — give it a `name` (the toggle's label, e.g.
  `"Bublina s citací"`).
- **`repeat`** ("Opakovatelný řádek") on a **nested** `container` makes it a
  row the user repeats: `{"max": 8, "min": 1, "default": 1}` — whole numbers,
  `1 ≤ min ≤ default ≤ max ≤ 30`; `min` defaults to 1 and `default` to `min`.
  Design ONE row (e.g. `"members": ["skore", "souper"]`) inside a parent that
  is the list (the root's `maxHeight` bounds all rows together); a repeatable
  row may hold a single text, and its parent may hold nothing but the row.
  Lint errors: `repeat_not_nested` (on a top-level container),
  `repeat_multiple_in_parent` (one repeatable row per parent),
  `repeat_inside_repeat` (no rows inside rows). `repeat_max_overflows` warns
  when `max` rows do not fit and says how many do — `describe_variant` reports
  the same estimate as `fitsRows` after saving.
  A row the designer MERGED from hand-numbered copies ("Sloučit do
  opakovaného řádku" in the editor) also carries `slots`: one object per row
  2, 3, … mapping the row's CANVAS ids (input UUIDs / container ids — not your
  slugs) to the ids those copies had, so old exports and
  callers still land in their row. Never author it. (No tool returns a saved design,
  so you meet it only if a user pastes such a document — keep it verbatim
  then, or rows 2+ of every old export are lost.)
- **`userMove`** ("Uživatel může posunout") on a `text` (locked ones too), a
  `shape`, a **decorative** `image` or a **top-level** `container`: the person
  filling the template may move that element (a container: the whole block).
  `{"vertical": true, "horizontal": false, "maxUp": 120, "maxDown": 0}` — the
  two axes are toggled separately and each DIRECTION has its own optional
  limit in canvas px: `null` / omitted = anywhere, but the element stays on
  the artwork; `0` = not that way (the example: only upwards, at most 120 px).
  A limit on an axis that is not allowed is refused, and so is a block that
  allows neither axis. Refused on a fillable placeholder (its
  `input.allowMove` pans the picture inside the frame). On a container
  member or a nested container it is stored but does nothing (lint
  `user_move_on_container_member` / `user_move_on_nested_container`). In a
  template group the limits are per size: other sizes scale them by their
  height (up/down) and width (left/right) ratio when the designer syncs.
  Whoever fills the template then moves it with `offsets` on `render_variant`
  / `export_variant` / `export_document` (`describe_variant`
  `movableElements[]`), and the web fill page offers the same drag.
- **colours**: `#rrggbb` or `#rgb` shorthand, normalized to lowercase. No alpha.
- **`asset` / `allowedDirectories[]`**: gallery UUIDs from `list_gallery` or
  `upload_image` — never a file name or a URL.
- **Dotted / dashed lines**: on a `line`, `strokeStyle` styles the LINE itself —
  a `dotted` line is a row of round dots as big as the line is thick (its
  `height` for a horizontal line, `width` for a vertical one), spaced with a
  two-dot gap and fitted to start and end on a dot; `dashed` is 3 : 2 dashes.
  The dots take the `fill` colour, and the border (`stroke` / `strokeWidth`)
  is not drawn on a styled line, so leave it out. An InDesign 1 pt dotted rule
  on a 300 DPI canvas is `{"shape": "line", "height": 4, "fill": "#…",
  "strokeStyle": "dotted"}`. On every other shape `strokeStyle` dashes the
  border and needs `stroke` + `strokeWidth`.

