# wboost MCP tools — field reference

Companion to `SKILL.md`. Everything here is the shape the shipped tools actually
return or accept. Read `SKILL.md` first for the judgement; come here for a field
name.

All ids are UUID strings. All coordinates are **canvas pixels, top-left origin,
axis-aligned** — the same space `width` / `height` are expressed in, so mapping a
frame onto a rendered preview is one scale factor
(`renderedWidth / variant.width`). A rotated placeholder reports its upright
bounding box.

---

## Scopes

A token carries scopes; they **narrow** what its user could already do, never
widen it. A tool the token lacks the scope for is not listed and is refused if
called anyway.

| scope | tools |
|---|---|
| `templates:read` | `get_context`, `find_templates`, `describe_variant`, `list_gallery`, `render_variant` |
| `templates:export` | `export_variant` |
| `templates:design` | `preview_design`, `set_design` |
| `gallery:write` | `upload_image` |

Each row lists the tools that **declare** that scope. Implication is separate and
runs one way: `templates:export` and `templates:design` each also grant
`templates:read`, so either of them brings all five read tools with it.
`gallery:write` implies nothing — a gallery-only token sees `upload_image` and
nothing else.

`get_context` echoes the granted scopes back in `scopes[]`. If a tool the user
expects is missing from the list, the connection lacks its scope: re-authorize
(OAuth) or mint a new token (PAT). Scopes cannot be edited on an existing token.

Authorisation is scope **∩** what the account itself may do. Notably: designing
requires *owning* the project. A project merely shared with the user grants
viewing, rendering and exporting, but `set_design` and `preview_design` refuse
with a message saying exactly that.

---

## `get_context()`

No arguments. Returns:

```jsonc
{
  "user": { "id", "email", "name", "role" },       // role: ROLE_ADMIN | ROLE_DESIGNER | ROLE_USER
  "scopes": ["templates:read", "templates:export"],
  "projects": [{
    "id", "name",
    "templateCount", "variantCount",
    "fonts":  ["Rubik (Rubik)", "Rubik (Rubik Bold)"],   // exact face families
    "colors": ["#0a7d3f", "#1c1c1c"],                     // lowercase #rrggbb
    "dimensions": [{
      "label",        // "1:1" for a social preset, "210 × 297 mm" for print
      "preset",       // "1:1" | "4:5" | "9:16", else null
      "unit",         // "px" | "mm" | "cm"  (mm/cm rasterize at 300 DPI)
      "unitWidth", "unitHeight",
      "width", "height",      // canvas pixels
      "variantCount"
    }]
  }]
}
```

Projects are the ones the account can VIEW — owned, shared, or all of them for an
admin. Cached server-side for 60 s per user.

`fonts[]` is the whitelist for a design element's `font` and its
`input.allowedFonts`. At FILL time the whitelist is per input —
`describe_variant`'s `inputs[].fontOptions` — for a rich run's `fontFamily`
and for the whole-text `fontFamily` alike. `colors[]` is a suggestion list,
not a whitelist.

---

## `find_templates(projectId, query?)`

`query` is an optional case-insensitive substring matched against the template
name **and its category name** (designers file by category, so "Instagram" may
only appear there). Omit it to list everything.

```jsonc
{
  "projectId", "projectName",
  "query",                        // echoed back, normalized (blank → null)
  "templates": [{
    "id", "name",
    "categoryId", "categoryName", // nullable
    "grouped",                    // template belongs to a synchronized group
    "groupId", "groupName",       // nullable
    "variants": [{
      "id",
      "dimension": { "label", "preset", "unit", "unitWidth", "unitHeight", "width", "height" },
      "thumbnailUrl",             // rendered preview, else background image, else null
      "inputCount",               // text inputs, locked ones included
      "grouped"                   // ← check THIS, not the template's flag
    }]
  }]
}
```

A grouped template can still hold ungrouped variants somebody added by hand, so
the per-variant `grouped` flag is the one that matters — and it is the flag that
predicts a `set_design` refusal before you make the call.

A `projectId` the account cannot see reports the *same* failure as one that does
not exist — that is deliberate, not a bug to work around.

---

## `describe_variant(variantId)`

The only source of the ids `render_variant` / `export_variant` are keyed by, and
of the canvas size a design must be authored at.

```jsonc
{
  "variantId", "templateId", "templateName", "projectId", "projectName",
  "grouped",
  "dimension": { "label", "preset", "unit", "unitWidth", "unitHeight", "width", "height" },

  "inputs": [{
    "id",                  // ← the inputs map key
    "name", "description", // nullable; name is NOT unique and NOT an address
    "maxLength",           // nullable int — rejects, never truncates
    "uppercase",           // applied server-side
    "locked",              // cannot be written
    "hidable",             // {"hide": true} accepted
    "richText",            // {"runs": …} accepted
    "lists",               // "lines" accepted (already ANDed with richText)
    "listCheckboxes",      // "cb"/"cbx" line types accepted (ANDed with lists)
    "checklist": null,     // or { "toggle", "editText", "addItems", "removeItems" }
    "sampleValue",         // designer default; omit the id to render it
    "frame": { "x", "y", "width", "height" },   // nullable
    "containerId",         // nullable — which flow this text is in
    "fontOptions"          // nullable — faces this input may be filled in
                           //   (designed first); {"fontFamily": …} accepted
  }],

  "imageInputs": [{
    "id",                  // ← the images map key
    "name", "description",
    "isBackground",        // whole-canvas cover; send the id alone, no transform
    "allowMove", "allowResize", "allowRotate",
    "hidable",
    "directories": [{ "id", "name" }],   // folders this slot accepts
    "includesRoot",                       // gallery root also accepted
    "frame": { "x", "y", "width", "height" }
  }],

  "containers": [{
    "id",
    "maxHeight",           // px of content allowed
    "y",                   // top of the highest designed member in the tree
    "memberInputIds", "memberContainerIds",
    "gap", "spaceAfter",   // nullable px
    "nested"               // true = it flows inside another container
  }],

  "richTextOptions": {     // null unless a fillable richText input exists
    "fonts":  ["Rubik (Rubik Bold)", …],  // the ONLY families a run may name
    "colors": ["#0a7d3f", …]              // suggestions; any hex is accepted
  }
}
```

**This describes the fillable surface, not the design.** There is no tool that
returns a variant's design as a DSL document — see `SKILL.md` on why that makes
`set_design` an authoring tool rather than an editing one.

**`includesRoot: true` is the marker of an UNRESTRICTED slot**: it is true exactly
when the designer left the allow-list empty, and then `directories[]` already
lists every folder in the project. A restricted slot always reports
`includesRoot: false` and only the folders the designer picked — an explicit
allow-list can only name folders, so choosing any folder excludes the root.
An empty allow-list is never "no folders".

Only a **root** container's `maxHeight` bounds anything; a nested one grows with
its content. An overflow is always reported against the root.

---

## `list_gallery(projectId, directoryId?)`

One level at a time. Omit `directoryId` for the root.

```jsonc
{
  "projectId", "projectName",
  "directoryId", "directoryName", "parentDirectoryId",   // all null at the root
  "path": [{ "id", "name" }],        // breadcrumb, root → current; empty at root
  "directories": [{ "id", "name" }], // folders directly inside the current one
  "images": [{
    "id",              // ← the value an images map or a design `asset` takes
    "name",            // stored file name = id + format extension, NOT a caption
    "url",
    "width", "height"  // null for SVG (vector) and for unreadable bytes
  }]
}
```

Use `width` / `height` against the slot's `frame` — a portrait photo in a
landscape slot is cropped, not letterboxed.

Deleted pictures sit in a trash bin for a few days and are **never** listed here;
they also cannot be used in a fill or named by a design. Nothing in the gallery
can be deleted, moved or renamed from this connector — `upload_image` is the one
way to change it, and it only ever adds.

---

## `upload_image(projectId, imageBase64, filename, directoryId?)`

Adds a picture to the project's gallery. Scope `gallery:write`.

```jsonc
{
  "imageId",         // ← the same value list_gallery reports; an images map and a design `asset` take it
  "url",
  "width", "height"  // of the STORED bytes; null for SVG
}
```

- **base64 only.** URLs are never fetched (fetching a caller-supplied address
  from inside wboost's network would be an SSRF), so download the picture
  yourself and send the bytes. A `data:` URI is accepted; its header is stripped.
- **Keep it under ~3 MB.** base64 inflates bytes by a third and one MCP request
  body is capped at 4 MiB, so a bigger photo fails at the transport with a bare
  HTTP 413. wboost's own limit is 10 MB (10 000 000 bytes, decimal).
- PNG / JPEG / GIF / WebP / SVG are stored as they are; a phone photo
  (HEIC/HEIF) is converted to JPEG with its rotation baked in. Anything that is
  not a picture is refused and nothing is stored. The stored object is named
  after its own id with the extension its BYTES require — a file name cannot
  make a picture something it is not.
- Omit `directoryId` to file it at the gallery root (a real location). Each call
  adds a new picture; nothing is ever overwritten, and nothing can be removed
  from here.

This is also the repair for the commonest `set_design` overwrite loss: a
background that has no gallery id cannot be named in a design, but the same
picture uploaded here can.

---

## `render_variant(variantId, inputs?, images?)`

Reply = a JSON summary block **followed by** the image block.

```jsonc
{
  "variantId", "templateName", "projectName",
  "format": "image/webp",
  "width", "height",              // the PICTURE returned (≤ 1200 px long edge)
  "canvasWidth", "canvasHeight",  // the variant's real size = what export gives
  "downscaled": true,
  "warnings": []
}
```

Lossy, downscaled, not counted as an export, and **lenient about container
overflow** — an overflowing fill still returns a picture plus a warning that ends
"…but export_variant will refuse this fill."

Everything else is as strict as the export.

---

## `export_variant(variantId, inputs?, images?)`

Same arguments, same fill vocabulary. Reply = JSON summary + the PNG.

```jsonc
{
  "variantId", "templateName", "projectName",
  "format": "image/png",
  "width", "height",   // the variant's designed size
  "sizeBytes",
  "warnings": []
}
```

Strict: container overflow is a refusal, nothing is produced, and nothing is
recorded. A successful export **is** recorded in the project's usage report.

---

## `preview_design(variantId, design)`

Compiles a design document and draws it against a **detached copy** of the
variant. Nothing is persisted: not the canvas, not the thumbnail, not the inputs,
and no export is recorded. Scope `templates:design`, and the account must be able
to EDIT the variant. A grouped variant is accepted here (previewing changes
nothing).

Reply = a JSON summary, then the image **when one was drawn**.

```jsonc
{
  "variantId", "templateName", "projectName",
  "rendered": true,          // ← read this first
  "status",                  // short verdict string
  "errorCount", "warningCount",
  "issues": [ … ],           // see below
  "format": "image/webp",    // null when nothing was drawn
  "width", "height",         // the PICTURE (≤ 1200 px long edge); null when not drawn
  "downscaled",              // null when not drawn
  "canvasWidth", "canvasHeight"   // the variant's real size
}
```

`rendered: false` ⇒ the reply is flagged as an error, carries no image, and
`issues[]` says what blocked it. Only `severity: "error"` blocks; warnings always
come back **with** the picture.

---

## `set_design(variantId, design, acknowledgeLosses?)`

The commit: replaces the variant's whole canvas, its text and image inputs and
its thumbnail, and returns the picture that was stored. Same document, same
pipeline as `preview_design` — a document that previewed cleanly cannot be
refused here for a reason the preview did not surface. Scope `templates:design` +
EDIT. A **grouped** variant is refused (its design is shared across the group and
is authored only in the group editor).

```jsonc
{
  "variantId", "templateName", "projectName",
  "saved": true,             // ← read this first
  "status",
  "errorCount", "warningCount",
  "issues": [ … ],
  "editorUrl",               // where a human opens the result
  "thumbnailUpdated",
  "format": "image/png",     // PNG here, not WebP: it IS the stored render
  "width", "height", "downscaled",
  "canvasWidth", "canvasHeight"
}
```

`saved: false` ⇒ **nothing was written** and the variant still has its previous
design.

Container overflow is a **warning** here, not a refusal — a design in progress is
still worth saving. `export_variant` still refuses to produce a file from it.

**`acknowledgeLosses`** defaults to false and belongs to the `overwrite` stage
alone. Read `SKILL.md` rule 7 before using it: it repairs nothing, changes
nothing about what is written, and only downgrades "this save destroys X" errors
into warnings. The order inside the tool is: resolve the variant → overwrite
guard → preflight the document → render → write, with the guard's and the
document's findings merged into one `issues[]` so a bad key and an unnameable
background are both reported in one turn.

### The `issues[]` entry

```jsonc
{
  "severity",   // "error" (blocks) | "warning" (advisory)
  "stage",      // "parse" | "variant" | "lint" | "compile" | "overwrite"
  "code",       // machine-readable, e.g. "font_not_allowed", "object_dropped"
  "slug",       // nullable — the element id it is about
  "path",       // e.g. "elements[2].font"
  "message",
  "allowed": [] // present on some codes, e.g. the allowed font faces
}
```

| stage | the question it answers | how you fix it |
|---|---|---|
| `parse` | is this a well-formed DSL document? | re-read the grammar below |
| `variant` | was it written for THIS variant? | set `canvas` to the variant's size |
| `lint` | is it a good design? | judgement — usually advisory |
| `compile` | does this project HAVE what it names? | `get_context` / `list_gallery` |
| `overwrite` | what does WRITING this destroy? | keep the thing, or acknowledge |

`parse` and `compile` produce only errors; `variant` produces only warnings;
`lint` and `overwrite` produce both. The single lint **error** is
`font_not_allowed` — an unknown face would be silently substituted by the
renderer, so it stops the call before anything is drawn. Other lint codes
(`out_of_canvas_bounds`, `text_overlap`, `color_not_in_palette`,
`font_size_too_small`, `container_overflow_predicted`, `container_too_few_items`,
`image_without_asset_or_placeholder`, `max_length_below_stand_in`) are warnings.

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
| `text` element | `kind`, `id`, `text`, `font`, `size`, `color`, `align`, `lineHeight`, `at`, `x`, `y`, `width`, `input` |
| `text` input block | `name`, `maxLength`, `uppercase`, `hidable`, `locked`, `richText`, `sampleValue`, `allowedFonts` |
| `image` element | `kind`, `id`, `asset`, `at`, `x`, `y`, `width`, `height`, `input` |
| `image` input block | `name`, `placeholder`, `allowMove`, `allowResize`, `allowRotate`, `hidable`, `allowedDirectories` |
| `shape` element | `kind`, `id`, `shape`, `fill`, `stroke`, `strokeWidth`, `strokeStyle`, `cornerRadius`, `opacity`, `name`, `locked`, `at`, `x`, `y`, `width`, `height` |
| `shape` gradient fill | `type`, `angle`, `from`, `to` |
| `background` element | `kind`, `id`, `asset`, `fillable` |
| `container` element | `kind`, `id`, `members`, `children`, `maxHeight`, `gap`, `spaceAfter` |

### Enumerated values

| where | accepted values |
|---|---|
| `kind` | `text`, `image`, `shape`, `background`, `container` |
| `at.area` | `top`, `upper`, `middle`, `lower`, `bottom`, `full` |
| `align` | `left`, `center`, `right`, `justify` |
| `shape` | `rectangle`, `square`, `circle`, `ellipse`, `triangle`, `line`, `star` |
| `strokeStyle` | `solid`, `dashed`, `dotted` |
| gradient `fill.type` | `linear`, `radial` |

### Required, and defaults

- **root**: `canvas` and `elements` are both required; `elements` may be `[]`.
- **`canvas`**: `width` and `height` required, each 1…20000 canvas px, and both
  must equal the variant's own size. Print sizes are authored at their 300 DPI
  raster size (A4 = 2480 × 3508), never in millimetres. `background` is optional;
  `background.fill` is a flat colour that may be combined with a `background`
  element, but `background.image` and a `background` element are mutually
  exclusive — they define the same layer.
- **`text`**: `text`, `font` and `size` are required, plus a placement.
  `color` defaults to `#000000`, `align` to `left`, `lineHeight` to `1.16`.
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
  `name` `null`, `locked` `false`. `fill` is either a colour string or a two-stop
  gradient object `{"type": "linear", "angle": 45, "from": "#…", "to": "#…"}` —
  `angle` is degrees clockwise, 0 = left to right, 90 = top to bottom, and is
  ignored for a `radial` gradient, which is always centre-out. `cornerRadius`
  applies to `rectangle` / `square` / `line` only; on the others it must stay
  `0`, because those shapes' radii ARE their size. `opacity` is a fraction
  (`0.6` = 60 % opaque), not a percentage. `locked` is the EDITOR lock — it makes
  the shape click-through for the designer and never affects the export. A shape
  is decorative: it is never a fillable input and adds nothing to
  `describe_variant`, but it MAY be a container member.
- **`background`**: at most **one** per document; it compiles to the single
  background layer pinned to the bottom of the stack. `fillable: true` makes it a
  fillable whole-canvas cover slot.
- **`container`**: needs at least 2 referenced items counting `members` and
  `children` together. `members` are texts, shapes and **decorative** images — never
  a
  fillable image placeholder, never the background, never another container
  (nest via `children`). A container has exactly one parent and the graph must be
  a tree. **A top-level container must carry `maxHeight`**; a nested one may omit
  it, because only the root's bound gates overflow. `gap` replaces every designed
  inter-item gap with a uniform spacing; `spaceAfter` is guaranteed clearance
  below.
- **colours**: `#rrggbb` or `#rgb` shorthand, normalized to lowercase. No alpha.
- **`asset` / `allowedDirectories[]`**: gallery UUIDs from `list_gallery` or
  `upload_image` — never a file name or a URL.

A worked document that exercises all five kinds is in `SKILL.md`.

---

## Fill vocabulary (identical for both image tools, and to the REST export)

### `inputs` — keyed by `inputs[].id`

| value | meaning |
|---|---|
| `"text"` | plain text |
| `{"value": "text"}` | the same, long form |
| `{"value": "text", "hide": false}` | explicit |
| `{"hide": true}` | blank a `hidable` input |
| `{"runs": [...], "lines": [...]}` | rich text; `richText` inputs only |
| `{"value": "…", "fontFamily": "…"}` | the whole text in one of the input's `fontOptions` (also next to `runs`); refused where `fontOptions` is null |

A run is `{"text", "fontFamily", "color", "underline"}`; `fontFamily` and `color`
may be `null` (inherit the designed style). `lines` has one entry per
`\n`-separated line of the concatenated run text: `"p"`, `"ul"`, `"ol"`, `"cb"`,
`"cbx"`.

Omitting an id renders its `sampleValue`. Sending `""` renders nothing for it.
Locked inputs are ignored (and warned about).

### `images` — keyed by `imageInputs[].id`

| value | meaning |
|---|---|
| `"<imageId>"` | centered object-contain fit |
| `{"imageId": "…", "scale": 1, "offsetX": 0, "offsetY": 0, "rotation": 0}` | placed |
| `{"hide": true}` | blank a `hidable` slot |

`offsetX` / `offsetY` are canvas px; `offsetXRatio` / `offsetYRatio` are accepted
as an alternative, expressed as a fraction of the slot frame. A transform without
an `imageId` is refused. Omitted slots keep the designer's stand-in picture.

---

## Error wordings you should recognise

| message starts with | what to do |
|---|---|
| `"…" is not a valid project id / template variant id / folder id` | you sent a name or the wrong kind of id — go back one tool |
| `Project X was not found, or this account cannot access it` | the account cannot see it; do not probe for others |
| `Template variant X was not found, or this account cannot access it` | same |
| `Template variant X can be read by this account but not designed on` | designing needs project ownership; sharing only grants viewing |
| `Template variant X belongs to the synchronized template group "…"` | `set_design` cannot touch it; the message links the group editor |
| `Input "…" exceeds max length of N characters.` | shorten the value; it is not truncated for you |
| `Input "…" overflows its container by N px` / `The texts of container … overflow` | shorten a text, hide a hidable one, or raise `maxHeight` |
| `rich_text_not_allowed` / `lists_not_allowed` / `checkbox_lists_not_allowed` | the input does not have that capability — check `describe_variant` |
| `font_not_allowed` (with `Allowed values — …`) | use a family from that list verbatim |
| `invalid_color` | a colour must be a hex value |
| `…cannot be rotated / resized / moved` | the slot forbids it; drop the transform |
| `…is not available for this placeholder` | the picture is outside the slot's folders |
| `The image renderer is busy and did not answer in time.` | transient; retry the same call in a few seconds |
| `Rendering / Exporting this variant failed: …` | a broken design or asset, not your values — tell the user to open it in the editor |

Every "not found or not yours" wording is deliberately identical to "does not
exist", so these tools cannot be used to discover what other accounts hold. The
one deliberate exception is the EDIT gate: a variant you can already see reports
the real reason ("readable but not designable"), because pretending it vanished
would only send you hunting for a wrong id.
