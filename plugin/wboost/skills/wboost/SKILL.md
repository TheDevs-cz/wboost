---
name: wboost
description: Use when working with wboost brand templates — listing the user's wboost projects, brand fonts and colours; finding a template variant; filling its text and image placeholders; previewing and exporting the finished PNG; uploading a picture to a project gallery; or authoring a variant's design from scratch. Covers the nine wboost MCP tools (get_context, find_templates, describe_variant, list_gallery, render_variant, export_variant, upload_image, preview_design, set_design), the ids-never-names rule, container overflow, the design DSL, and the acknowledgeLosses overwrite guard.
---

# wboost templates

wboost is a brand-manual and template platform. A designer authors a **template
variant** — a canvas with text placeholders, picture slots and brand assets —
and it is filled with the user's copy and exported as a finished image.

This connector gives you nine tools across three jobs:

- **read** what the account holds (`get_context`, `find_templates`,
  `describe_variant`, `list_gallery`);
- **fill and deliver** a design somebody already made (`render_variant`,
  `export_variant`);
- **author** — add pictures to the gallery and write a variant's design yourself
  (`upload_image`, `preview_design`, `set_design`).

Which of those you can actually do depends on the scopes the connection was
granted; `get_context` echoes them back and a tool you lack the scope for is not
offered at all. Deleting is available nowhere: nothing in this connector removes
a template, a variant or a picture.

## The two loops

**Filling** an existing design — by far the common case:

```
get_context          → which projects, which brand fonts, which brand colours
  └ find_templates   → which templates and variants exist in one project
      └ describe_variant  → the input ids, their rules, the containers
          ├ list_gallery      → picture ids for image slots (only if needed)
          ├ render_variant    → cheap preview; iterate here
          └ export_variant    → the deliverable, once
```

**Authoring** a design onto a variant:

```
get_context          → the exact font faces and brand colours you may use
  └ find_templates   → the variant to design on, and its size
      └ describe_variant  → its canvas size, and what it holds today
          ├ list_gallery / upload_image  → picture ids your design references
          ├ preview_design   → compile + draw WITHOUT saving; iterate here
          └ set_design       → the commit, once
```

Every step feeds the next with **ids**. Never skip `get_context`, and never skip
`describe_variant` — there is no other source for the ids a fill is keyed by, or
for the canvas size a design must be written at.

There is **no tool that reads a variant's design back as a DSL document.**
`describe_variant` reports the fillable surface (inputs, slots, containers,
frames), not the design. So `set_design` is for authoring a design you composed,
not for making a small edit to one somebody else drew — for that, send the user
to the wboost editor. This matters, and the guard in rule 7 is what enforces it.

## The rules

### 1. `get_context` first, always

It returns who the connection acts as, which scopes it carries, and for every
project the user can reach: `id`, `name`, `fonts[]`, `colors[]`, `dimensions[]`
and counts.

The `fonts[]` strings are the **exact** face families the renderer registers —
e.g. `"Rubik (Rubik Bold)"`. A styled run or a design's `font` must name one of
them byte for byte; an unknown family is rejected, never substituted. The
`colors[]` are the brand palette in lowercase `#rrggbb`. Prefer them over
inventing hex values (both fill and design accept any hex, but the point of a
brand manual is not to).

Call it once per session. Call it again only if the user says they have just
added a project, font or colour.

### 2. Ids, never names — and read the warnings

Every tool takes UUIDs. `inputs` and `images` maps are keyed by **inputId** from
`describe_variant` (`inputs[].id`, `imageInputs[].id`), not by the input's
`name`. Two inputs may legitimately share a name, so names are not addresses.

An id that matches nothing is **silently ignored** by the render — the picture
comes back looking untouched. The tools surface this as a `warnings[]` entry in
the JSON summary that precedes the image:

> These inputs ids match no text input on this variant and were ignored: …

**Read `warnings[]` on every render and export.** An empty picture with a warning
is not "the tool failed"; it is "you keyed the fill wrong". Locked inputs are
warned about the same way.

### 3. `render_variant` to iterate, `export_variant` once

|  | `render_variant` | `export_variant` |
|---|---|---|
| output | lossy WebP, ≤ 1200 px long edge | lossless PNG at the variant's real size |
| container overflow | **warned**, picture returned | **refused**, nothing produced |
| usage report | not counted | counted as a real export |
| scope | `templates:read` | `templates:export` |

An A4 variant is 2480 × 3508 px. One export per phrasing attempt is slow, burns
the context window, and pollutes the user's export statistics. So: shape the copy
with `render_variant`, look at the picture, adjust, and call `export_variant`
exactly once when it is right.

`render_variant` enforces *everything else* strictly — maxLength, the font
whitelist, per-slot image limits. A value it accepts cannot then be refused by
the export for those reasons. Overflow is the one contract it relaxes.

### 4. Container overflow is the failure you will actually hit

A **container** is a smart text area: its members reflow vertically, so a text
that wraps to more lines pushes everything below it down. The designer sets a
`maxHeight`. Content past that is an overflow.

`export_variant` refuses it:

> The texts of container `<uuid>` overflow its max height of 700 px by 42.5 px.
> Its fillable text inputs are "Nadpis" (id …), "Perex" (id …) — shorten one of
> them, hide a hidable one, or have the designer raise the container maxHeight in
> the wboost editor. Which one is too long cannot be told apart here: they share
> one vertical flow.

Two things follow:

- **The server cannot tell you which text is too long** when several share one
  flow, and it deliberately does not guess a character count — overflow is
  measured in pixels of *wrapped* text, which only the browser knows. Do not ask
  it for a target length; shorten the text you judge most compressible and
  re-render.
- **The fixes are:** shorten a text; hide an input that `describe_variant` marks
  `hidable: true` (send `{"hide": true}`); or tell the user their designer must
  raise `maxHeight` in the editor. When *you* authored the design, you have a
  fourth: raise the container's `maxHeight` in your own document and
  `set_design` again.

`describe_variant` reports `containers[]` and a `containerId` on each member
input, so you can see which texts share a flow *before* you fill them.

Note the asymmetry: `set_design` **saves** a design whose containers are
predicted to overflow (it is a warning — a design in progress is still worth
saving), but `export_variant` still refuses to produce a file from it.

### 5. Respect what the designer locked down

From `describe_variant`, per text input:

- **`locked: true`** — cannot be written at all. Addressing it is warned about and
  ignored. Do not retry it a different way.
- **`hidable: true`** — may be blanked with `{"hide": true}`. `hidable: false`
  means `hide` is ignored.
- **`maxLength`** — **rejects, it does not truncate.** An over-long value is a
  refusal from both render and export ("Input "Nadpis" exceeds max length of 24
  characters."). Count characters before sending.
- **`uppercase: true`** — applied server-side. Send natural casing; do not
  pre-shout.
- **`sampleValue`** — the designer's default. **Omit the id** and that renders.
  Send `""` and nothing renders for it. Those are different: leave inputs you
  are not filling out of the map entirely.
- **`grouped: true`** on the variant — it belongs to a synchronized template
  group, one design maintained across several dimensions. Filling, rendering,
  exporting and *previewing a design* work exactly as normal; **`set_design` is
  refused**, because every variant of a group shares one design and a
  single-variant save would be clobbered by the next group save. Group design
  happens in the wboost group editor; the refusal links it.

### 6. Authoring: preview until it is right, then commit once

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

### 7. `acknowledgeLosses` is a decision, not a retry

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
leaves the variant with **no background**. Design-hidden layers, Rects, Paths and
Groups disappear the same way; per-character styling, shadows and list
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

## Fill value shapes

Text (`inputs`, keyed by `inputs[].id`):

```jsonc
"a1b2…": "Plain text"                          // the common case
"a1b2…": { "value": "Plain text", "hide": false }
"a1b2…": { "hide": true }                      // hidable inputs only
```

Rich text — **only** for an input `describe_variant` marks `richText: true`:

```jsonc
"a1b2…": {
  "runs": [
    { "text": "Bold bit",  "fontFamily": "Rubik (Rubik Bold)", "color": "#0a7d3f", "underline": false },
    { "text": " and rest", "fontFamily": null, "color": null, "underline": false }
  ],
  "lines": ["p"]                               // one entry per \n-separated line
}
```

- `fontFamily` must be one of `richTextOptions.fonts` on that variant (or null).
- `lines` needs `lists: true`; values are `"p"`, `"ul"`, `"ol"`, and — with
  `listCheckboxes: true` — `"cb"` (unchecked) / `"cbx"` (checked). All-`"p"` is
  the same as sending no `lines` at all.
- A non-null `checklist` object means the input *is* a checkbox list; its four
  flags say what the end user is allowed to change.
- Sending `runs` to a non-rich input, or `ul` to a non-lists input, is a refusal
  naming exactly that.

Pictures (`images`, keyed by `imageInputs[].id`):

```jsonc
"c3d4…": "<gallery image id from list_gallery or upload_image>"
"c3d4…": { "imageId": "…", "scale": 1, "offsetX": 0, "offsetY": 0, "rotation": 0 }
"c3d4…": { "hide": true }
```

- A slot with `allowMove` / `allowResize` / `allowRotate` false **refuses** that
  adjustment rather than ignoring it.
- `isBackground: true` slots are always a deterministic cover of the whole canvas
  — send the id alone, no transform.
- The picture must sit in one of the slot's `directories[]` (or, when
  `includesRoot: true`, at the gallery root). `list_gallery` walks that tree one
  level at a time; omit `directoryId` for the root, which holds real pictures and
  is not merely a folder container.
- Slots you leave out keep the designer's stand-in picture.

## The design DSL, in brief

A design document is `{"canvas": {...}, "elements": [...]}`, with `elements` in
**stack order, bottom to top**. Each element has a `kind` (`text`, `image`,
`shape`, `background`, `container`) and a short slug `id` you choose
(`^[a-z0-9][a-z0-9_-]*$`, ≤ 64 chars, unique in the document).

**Unknown keys are rejected, not ignored** — a typo like `fontSize` for `size` is
reported with the nearest valid key suggested, rather than silently applying a
default and producing a design that is wrong in a way nobody can see. Every
problem in the document is reported at once, so fix them as a batch.

Placement is either **semantic** (`at`, preferred — it adapts to any canvas size)
or **absolute** (`x`, `y`, `width`, and `height` for images), in canvas pixels
from the top-left. Semantic placement puts the element in a vertical `area`
(`top`, `upper`, `middle`, `lower`, `bottom`, `full`) across a span of a
**12-column grid** (`col: [start, end]`, inclusive, default `[1, 12]`), with
optional `marginX` / `marginY` and `offsetX` / `offsetY` nudges.

A `text` element with an `input` block becomes a fillable placeholder; without
one it is fixed copy. An `image` element with an `input` block is a fillable
picture slot; without one it is decorative. A `shape` element is a vector block —
`rectangle`, `square`, `circle`, `ellipse`, `triangle`, `line` or `star` — filled
with a flat colour or a two-stop gradient; it is always decorative, never
fillable, and it is how you author panels, rules and scrims rather than uploading
a picture of one. A `container` groups `members` (texts, shapes and decorative
images) and `children` (nested containers) into one vertical flow — a **top-level
container must carry `maxHeight`**, a nested one must not need it.

This document parses, and exercises all five kinds:

```json
{
  "canvas": { "width": 1080, "height": 1350, "background": { "fill": "#111111" } },
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
block is in `references/tools.md`; it is the parser's own, so it cannot drift.

## A worked example

> "Make me an A4 poster for the spring workshop — 14 March, at the Brno studio."

1. **`get_context`** → project `Studio Brno` (id `01915…`), fonts
   `["Rubik (Rubik)", "Rubik (Rubik Bold)"]`, colours `["#0a7d3f", "#1c1c1c"]`.
2. **`find_templates`** with that `projectId` and `query: "plakát"` → template
   *Plakát A4*, one variant, `dimension.label` `"210 × 297 mm"`,
   `width/height` 2480 × 3508, `grouped: false`.
3. **`describe_variant`** on the variant id → four inputs: `Nadpis`
   (`maxLength: 28`, `uppercase: true`), `Podtitul`, `Datum`, `Místo`
   (`hidable: true`); one image slot `Foto`; one container holding `Nadpis` +
   `Podtitul` with `maxHeight: 700`.
4. **`render_variant`** keyed by those four ids:
   `{"<nadpis-id>": "Jarní workshop", "<podtitul-id>": "…", "<datum-id>": "14. března", "<misto-id>": "Studio Brno"}`.
   The summary says `warnings: []` and the picture looks right — except the
   subtitle wraps to three lines and the warning names the container.
5. Shorten `Podtitul`, **`render_variant`** again → no warnings.
6. **`export_variant`** with the same map → 2480 × 3508 PNG. Done, once.

If step 3 had shown the photo slot as unfilled and the user wanted a specific
picture, `list_gallery` on the project sits between 3 and 4 — and if the picture
they want is not in the gallery yet, `upload_image` puts it there and hands back
the id to use.

If instead there had been **no** suitable template, authoring is the other path:
`describe_variant` for the target variant's canvas size, compose a document,
`preview_design` until it looks right, then `set_design` once — reading rule 7
before that last call.

## Common mistakes

1. **Keying the fill by input name.** Renders the untouched design and only says
   so in `warnings[]`.
2. **Skipping `describe_variant`** and guessing ids from a previous variant. Ids
   are per variant.
3. **Exporting to look at it.** That is `render_variant`'s job; the export is
   counted in the user's usage report.
4. **Ignoring `warnings[]`** in the JSON block that precedes the image.
5. **Sending `""` for inputs you are not filling.** That blanks them. Omit the
   key instead and the designer's `sampleValue` renders.
6. **Trying to shorten a text to a character count the tool "implied".** It never
   implies one; overflow is pixels of wrapped text.
7. **Inventing a font or colour.** Use `get_context` fonts / colours and
   `richTextOptions.fonts` verbatim — an unknown face is an error everywhere,
   and in a design it stops the render before anything is drawn.
8. **Writing to a `locked` input**, or sending a transform to a slot that forbids
   it, and retrying with a variation. Both are designer decisions.
9. **Assuming an empty gallery root.** The root holds pictures; look there before
   telling the user there are none.
10. **Reaching for `set_design` to make a small edit** to a design you did not
    author. Nothing reads a design back as a document, so you would be replacing
    it wholesale, blind. Send the user to the wboost editor.
11. **Passing `acknowledgeLosses: true` because the previous call failed.** See
    rule 7. It destroys what the refusal listed and fixes nothing.
12. **Authoring a design at the wrong canvas size**, or in millimetres. Use the
    variant's own `width` / `height` in pixels.

## Reference

Field-by-field response shapes, scopes, the exhaustive DSL key tables, and error
wordings: `references/tools.md` next to this file.
