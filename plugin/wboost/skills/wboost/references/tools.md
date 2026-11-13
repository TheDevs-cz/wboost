# wboost MCP tools — field reference

Companion to `SKILL.md`. Everything here is the shape the shipped tools actually
return or accept. Read `SKILL.md` first for the judgement; come here for a field
name. The calendar tools are in `calendars.md`, the fill vocabulary in
`filling.md`, the design DSL in `design.md`; `tools.json` lists every tool's
parameters as the server registers them.

Contents: [Scopes](#scopes) · [`get_context`](#get_contextskillversion) ·
[`find_templates`](#find_templatesprojectid-query) ·
[`describe_variant`](#describe_variantvariantid) ·
[`list_gallery`](#list_galleryprojectid-directoryid) ·
[`upload_image`](#upload_imageprojectid-imagebase64-filename-directoryid) ·
[`upload_font`](#upload_fontprojectid-fontbase64-filename) ·
[`render_variant`](#render_variantvariantid-inputs-images-shapecolors-rows-offsets) ·
[`export_variant`](#export_variantvariantid-inputs-images-shapecolors-format-rows-offsets-allowunfilled) ·
[`export_document`](#export_documentdocumentid-fields-pages-format-versionid-allowunfilled) ·
[`document_history`](#document_historyaction-documentid-versionid-name-fields-pages) ·
[`manage_document`](#manage_documentaction-projectid-documentid-name-dimension-templateids-position-fields-pageid-bleedmm-cropmarks-categoryid) ·
[`set_print_setup`](#set_print_setupvariantid-bleedmm-cropmarks) ·
[`create_template`](#create_templateprojectid-name-dimension-categoryid) ·
[`scan_design`](#scan_designaction---ai-scan-a-picture-becomes-a-template-or-a-calendar-layout) ·
[`preview_design`](#preview_designvariantid-design) ·
[`set_design`](#set_designvariantid-design-acknowledgelosses) ·
[Error wordings](#error-wordings-you-should-recognise)

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
| `templates:export` | `export_variant`, `export_document`, `document_history` |
| `templates:design` | `preview_design`, `set_design`, `manage_document`, `upload_font`, `set_print_setup`, `create_template` |
| `gallery:write` | `upload_image` |
| `ai:scan` | `scan_design` |
| `calendars:read` | `list_calendars`, `get_calendar`, `render_calendar_page` |
| `calendars:write` | `manage_calendar`, `set_calendar_layout`, `upload_calendar_asset`, `update_calendar_content` |

Each row lists the tools that **declare** that scope. Implication is separate and
runs one way: `templates:export` and `templates:design` each also grant
`templates:read`, so either of them brings all five read tools with it.
`gallery:write` implies nothing — a gallery-only token sees `upload_image` and
nothing else. The calendar pair stands apart from the template scopes:
`calendars:write` grants `calendars:read`, and neither grants (nor is granted
by) anything else — a calendar-only token sees the calendar tools and no
`get_context`.

`get_context` echoes the granted scopes back in `scopes[]`. If a tool the user
expects is missing from the list, the connection lacks its scope: re-authorize
(OAuth) or mint a new token (PAT). Scopes cannot be edited on an existing token.

Authorisation is scope **∩** what the account itself may do. Notably: designing
requires *owning* the project. A project merely shared with the user grants
viewing, rendering and exporting, but `set_design` and `preview_design` refuse
with a message saying exactly that.

---

## `get_context(skillVersion?)`

`skillVersion`: the version your wboost skill states (`"0.5.2"`); omit it when
you have no wboost skill. Changes nothing. Returns:

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
      "printFormat",  // true for mm / cm (they export a print PDF)
      "bleedMm", "bleedPx", "cropMarks",   // print setup; 0 / false for px
      "variantCount"
    }]
  }],
  "skill": {                                        // the version check — see SKILL.md
    "latestVersion": "0.5.2", "installedVersion",   // null when no skillVersion was sent
    "status",                                       // "current" | "outdated" | "unknown"
    "message", "whatsNew",                          // null when current; tell the user once
    "updateClaudeCode", "updateClaudeAi"            // the update routes; null when current
  }
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
    "documentId",                 // nullable — the template is a page of that document
    "variants": [{
      "id",
      "dimension": { "label", "preset", "unit", "unitWidth", "unitHeight", "width", "height",
                     "printFormat", "bleedMm", "bleedPx", "cropMarks" },
      "thumbnailUrl",             // rendered preview, else background image, else null
      "inputCount",               // text inputs, locked ones included
      "grouped"                   // ← check THIS, not the template's flag
    }]
  }],
  "documents": [ … ]              // multi-page documents — see documents-and-print.md
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
  "dimension": { "label", "preset", "unit", "unitWidth", "unitHeight", "width", "height",
                 "printFormat", "bleedMm", "bleedPx", "cropMarks" },

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
    "fontOptions",         // nullable — faces this input may be filled in
                           //   (designed first); {"fontFamily": …} accepted
    "colorOptions"         // rich inputs: null = any hex, [] = colour locked,
                           //   a list = the only "#rrggbb" a run may carry
  }],

  "imageInputs": [{
    "id",                  // ← the images map key
    "name", "description",
    "isBackground",        // whole-canvas cover; send the id alone, no transform
    "allowMove", "allowResize", "allowRotate",
    "hidable",
    "directories": [{ "id", "name" }],   // folders this slot accepts
    "includesRoot",                       // gallery root also accepted
    "frame": { "x", "y", "width", "height" },  // the DESIGNED box
    "containerId"          // nullable — the flow this slot moves in
  }],

  "containers": [{
    "id",
    "maxHeight",           // px of content allowed
    "y",                   // top of the highest designed member in the tree
    "memberInputIds", "memberContainerIds",
    "memberImageInputIds", // image slots flowing in it (texts stay in memberInputIds)
    "gap", "spaceAfter",   // nullable px
    "nested",              // true = it flows inside another container
    "hidable",             // true = the WHOLE container may be hidden (see below)
    "name",                // nullable label of that hide toggle
    "repeat",              // nullable {max, min, default} — a repeatable ROW (see below)
    "fitsRows"             // nullable — how many rows fit by designed geometry (≤ max)
  }],

  "richTextOptions": {     // null unless a fillable richText input exists
    "fonts":  ["Rubik (Rubik Bold)", …],  // the ONLY families a run may name
    "colors": ["#0a7d3f", …]              // suggestions; any hex is accepted
  },

  "shapeInputs": [{        // shapes the designer lets you recolour ([] = none)
    "id",                  // ← the shapeColors map key
    "name",                // nullable layer name
    "target",              // "fill" | "stroke" | "both" — what ONE colour repaints
    "defaultColor",        // the designed colour (= colorOptions[0])
    "colorOptions",        // the "#rrggbb" values accepted …
    "freeColor",           // … unless true: then any hex is accepted
    "frame": { "x", "y", "width", "height" }   // nullable
  }],

  "repeatableRows": [{     // rows the `rows` argument fills ([] = none)
    "containerId",         // ← the rows map key (= a containers[].id with repeat)
    "parentContainerId",   // the container the rows stack in
    "name",                // nullable label
    "repeat": { "max", "min", "default" },
    "fitsRows",            // nullable — rows that fit with the sample texts
    "members": [{ "id", "kind", "name" }],  // ← the keys of ONE row object;
                           //   kind "text" | "image" | "shape" | "container" (hide only)
    "slots": [{ "index", "containerId", "ids": { "<member id>": "<id in that row>" } }]
                           //   one per row up to max; index 0 = the template row
  }],

  "movableElements": [{    // elements the `offsets` argument may move ([] = none)
    "id",                  // ← the offsets map key
    "kind",                // "text" | "shape" | "image" | "container" (a whole top-level one)
    "name",                // nullable designer name
    "frame": { "x", "y", "width", "height" },  // the DESIGNED box; add your offset yourself
    "vertical", "horizontal",                  // the axes it may move along
    "limits": { "up", "down", "left", "right" }, // designer max in px; null = to the artwork edge
    "range": { "minX", "maxX", "minY", "maxY" }, // what the server accepts — stay inside it
    "memberIds"            // a container's members (they move with it, never alone)
  }]
}
```

`repeatableRows[]` and `movableElements[]` are, field for field, the REST
listing's `variants[].repeatableRows[]` / `movableElements[]`.

**This describes the fillable surface, not the design.** There is no tool that
returns a variant's design as a DSL document — see `SKILL.md` on why that makes
`set_design` an authoring tool rather than an editing one.

**`includesRoot: true` is the marker of an UNRESTRICTED slot**: it is true exactly
when the designer left the allow-list empty, and then `directories[]` already
lists every folder in the project. A restricted slot always reports
`includesRoot: false` and only the folders the designer picked — an explicit
allow-list can only name folders, so choosing any folder excludes the root.
An empty allow-list is never "no folders". Allowing a folder also allows its
whole sub-tree, and `directories[]` lists those sub-folders too.

Only a **root** container's `maxHeight` bounds anything; a nested one grows with
its content. An overflow is always reported against the root.

A container with `hidable: true` can be hidden as a unit: put
`"<container id>": {"hide": true}` into the same `inputs` map you send to
`render_variant` / `export_variant`. The container and EVERYTHING in its tree
(texts, decorative images, shapes, nested containers) disappear and collapse;
the members' own `hide` values are kept for when it is shown again. A container
entry accepts only `hide`, and hiding one without `hidable` is refused
(`container_not_hidable`). It is the cheapest fix for a container overflow when
the designer offered it. A hidable container may list `memberInputIds: []` —
it holds only decorative images / shapes; hiding it still works.

A container with `repeat` is a **repeatable row** ("Opakovatelný řádek", e.g.
Skóre + Soupeř, or one scorer): the person filling the template shows between
`min` and `max` copies of it (`default` on a fresh fill), stacked in its
parent. Only the template row is listed — its inputs once. `fitsRows` is the
server's estimate of how many fit the root's `maxHeight` / the page by the
designed geometry; the fill page stops adding rows at the smaller of `max` and
what really fits. Fill rows with the `rows` argument (see "Fill vocabulary");
without it a render shows `default` rows, each with the template row's sample.

An image slot with a `containerId` flows like a text box of fixed height: a
long text above it pushes it down (its picture keeps its place inside the
frame) and hiding it closes the gap. Its `frame` is where it was DESIGNED —
check the render for where it lands.

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
    "originalName",    // the uploaded file name the web shows (null before 2026-09)
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

## `upload_font(projectId, fontBase64, filename)`

Scope `templates:design`; you must be able to edit the project. Adds a TTF,
OTF or WOFF (not WOFF2) to the project's fonts, filed under the family the
file's name table declares. A **variable font** (`fvar` axes) is expanded into
one static face per named instance — exactly as if each cut had been uploaded
alone (Inter 3.x/4.x → Thin…Black, each also Italic). base64 only, keep it
under ~3 MB (4 MiB request cap); wboost's limit is 10 MB.

```jsonc
{
  "family": "Inter",                       // null for a static file already present
  "variable": true,
  "created": ["Inter (Inter Thin)", …],     // faces this call added
  "alreadyPresent": ["Inter (Inter Bold)"],// kept untouched — re-sending is harmless
  "fonts": ["Inter (Inter Thin)", …],       // the whole family afterwards
  "message": "Variable font: 17 face(s) created, 1 already present."
}
```

All three lists use the exact `font` strings a design element and
`input.allowedFonts` take. A file that is not a readable font is a tool error
naming the file; nothing is stored. Nothing is ever replaced or deleted.

## `render_variant(variantId, inputs?, images?, shapeColors?, rows?, offsets?)`

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

`shapeColors` is `{"<shapeInputs[].id>": "#rrggbb"}` — repaints that shape's
fill, border or both (its `target`). A colour outside `colorOptions` on a shape
without `freeColor` is refused (`shape_color_not_allowed`, naming the allowed
colours); a non-hex value is `invalid_shape_color`. Omit an id (or send `""`)
to keep the designed colour.

---

## `export_variant(variantId, inputs?, images?, shapeColors?, format?, rows?, offsets?, allowUnfilled?)`

Same arguments, same fill vocabulary. Reply = JSON summary + the file.

`format`: `"png"` (default) — the lossless PNG at the variant's real size; or
`"pdf"` — the print PDF of an mm / cm variant (PDF/X-1a, CMYK FOGRA39, with the
bleed and crop marks `set_print_setup` stores), returned as an embedded
resource with `format: "application/pdf"`. A px variant is refused for `"pdf"`.

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
recorded. A successful export **is** recorded in the project's usage report,
and in the variant's export history with its rows (every row's count) and
moves — the web fill page re-opens it exactly as exported.

**Unfilled pictures.** A template made from an AI scan may still show a grey
stand-in picture where a picture was never supplied — a decorative image
nobody replaced in the editor, or a full-page background you pass no picture
for in `images`. Such an export is refused with a message starting
`unfilled_pictures:` that says how many there are and gives the `editorUrl`
where the user fills them. Tell the user; pass `allowUnfilled: true` (default
`false`) only when they want the file with the grey stand-ins anyway.

---

## `export_document(documentId, fields?, pages?, format?, versionId?, allowUnfilled?)`

`format`: `"pdf-screen"` (default) | `"pdf"` (print; mm / cm only, ≤ 8 pages
here) | `"png"` (ZIP). `fields`: shared fieldId → a text value in the field's
`textMode` shape (string, `{runs}`, …) | image id or `{imageId, hide}` (image)
| `"#rrggbb"` (shape). `pages`: pageId → `{inputs, images, shapeColors, rows,
offsets}`, exactly `export_variant`'s body for the page's variant (its ids:
`describe_variant` with the page's `variantId`); a shared value wins
over the page's own TEXT for its bound input (the page's own `hide` /
`fontFamily` for it are kept); `rows` and `offsets` are page-only. `versionId`
(from `document_history`) starts from that stored fill; `fields` / `pages`
override it key by key — per input / image / shape id, per moved element
id, and per row container id (the WHOLE list of rows is replaced). Every page
is resolved before any renders; a refusal names the page. Every export lands
in the document's history. `allowUnfilled` works as in `export_variant`: pages
still showing grey stand-in pictures left by an AI scan refuse the export with
`unfilled_pictures:` (the count is summed over the pages, `editorUrl` is the
first such page's) unless it is `true`.

Returns a text summary (`documentId`, `documentName`, `projectName`, `format`,
`pageCount`, `sizeBytes`, `fileName`, `warnings[]`) plus the file as an
embedded resource blob. Files over 25 MB are refused with the web fill URL.

## `document_history(action, documentId, versionId?, name?, fields?, pages?)`

The document's "Historie exportů" — exports AND saved drafts, the list the
web fill page shows (pinned first, then freshest; unpinned beyond 100 are
pruned). Scope `templates:export`; anyone who may fill the document.
- `list` → `versions[]`: `{id, name, pinned, draft, lastExportedAt,
  exportCount, channel, summary: {texts: [{label, value}], pictures, hidden},
  loadUrl}`.
- `get` (`versionId`) → `version` with `fill: {fields, pages: {pageId:
  {inputs, images, shapeColors, offsets, rows}}}` in `export_document`'s
  shape (seeded leniently against the current design; a row's values come
  back in `rows`, never under the rows' slot ids in `inputs`).
  To re-export: `export_document(documentId, versionId, …only what changes)`.
- `rename` (`versionId`, `name`; blank clears), `pin` / `unpin` (`versionId`).
- `save_draft` (`fields?`, `pages?`, `versionId?`) stores the fill WITHOUT
  rendering as a "Rozpracováno" draft; hand the user its `loadUrl` to finish
  on the web. With a draft's `versionId` that draft is overwritten; with an
  export's, the new draft starts from it. Nothing is validated except `rows`,
  which must be translated onto the rows' ids and are refused like an export
  refuses them.

Every reply also carries `historyUrl` (the web history page) and `warnings[]`.
Nothing deletes.

## `manage_document(action, projectId?, documentId?, name?, dimension?, templateIds?, position?, fields?, pageId?, bleedMm?, cropMarks?, categoryId?)`

The actions:
- `create`: `projectId`, `name`, `dimension` — either `{unit: "mm"|"cm"|"px",
  unitWidth, unitHeight, bleedMm?, cropMarks?}` or `{preset: "4:5"|"9:16"}`
  (`1:1` is retired — send px 1080 × 1080) — and `templateIds?` (each needs a
  variant of exactly that size). Needs the designer role and the right to
  edit the project. For mm / cm include `bleedMm` + `cropMarks` (below).
- `rename`: `documentId`, `name?`, `categoryId?` (`find_templates`
  `templates[].categoryId`; `""` removes it).
- `set_pages`: `documentId`, `templateIds` (the whole ordered list).
- `add_page`: `documentId`, `name?`, `position?`.
- `set_fields`: `documentId`, `fields: [{id?, name, kind: "text"|"image"|"shape",
  bindings: [{pageId, inputId}]}]`. `text` binds any editable text input
  (plain, rich, list, checklist); the field takes the richest value every
  binding accepts — its `fields[].textMode` (`plain` | `rich` | `list` |
  `checklist`; a checklist binds only to checklists). `shape` binds
  recolourable shapes: one `#rrggbb` for all.
- `duplicate_page`: `documentId`, `pageId`, `position?` (default: right
  after the original). The copy is its own template, same design and input
  ids; values stay per page.
- `set_print`: `documentId`, `bleedMm?` (0–10), `cropMarks?` — for the
  document and every page; mm / cm documents only.

**Print setup (mm / cm only).** `bleedMm` ("spadávka", 0–10 mm per side) is
how far the artwork runs past the cut; `cropMarks` adds cut marks: crop marks sit on the trim lines, each a fixed 3 mm from the trim and 5 mm long (PDF page = trim + 2 × max(bleed, 8 mm) with marks, trim + 2 × bleed without). When
the user wants a print document and has not said which bleed, **ask them**
before `create` — suggest 3 mm with crop marks, the common default. A print
`create` without `bleedMm` gets the default 5 mm and no crop marks; fix it
with `set_print`. Screen formats (px, presets) have no bleed:
a bleed > 0 or `cropMarks: true` there is refused
(`print_setup_not_available`); out-of-range values are refused
(`invalid_bleed`, `invalid_crop_marks`), never clamped.

Every action answers the document:
- `id`, `name`, `categoryName`, `categoryId`, `dimension`, `printFormat`
- `pages[]`: `{pageId, position, templateId, templateName, variantId,
  thumbnailUrl}`
- `fields[]`: `{id, name, kind, bindings[], textMode}`
- `editorUrl`, `fillUrl`

Refusals name the invariant: `template_grouped`, `template_not_found`,
`no_matching_variant`, `template_in_other_document`, `duplicate_page`,
`too_many_pages` (30), `invalid_binding`.

## `set_print_setup(variantId, bleedMm?, cropMarks?)`

Bleed + crop marks of ONE standalone print variant (mm / cm,
`dimension.printFormat` true) — what `export_variant` `format: "pdf"` prints
with. `bleedMm` ("spadávka", 0–10 mm per side) is how far the artwork runs
past the cut; `cropMarks` adds cut marks — crop marks sit on the trim lines, each a fixed 3 mm from the trim and 5 mm long (PDF page = trim + 2 × max(bleed, 8 mm) with marks, trim + 2 × bleed without). An omitted value keeps its setting. **If the user has not said which
bleed, ask** — suggest 3 mm with crop marks. Changing the bleed re-fits the
background layer; other edge-reaching artwork must extend `dimension.bleedPx`
past the edges (`set_design`).

Answers `{variantId, templateName, projectName, grouped, dimension, status}`
(`dimension` as in `describe_variant`). A grouped variant is accepted and only
this dimension changes (a group has no shared print setup). Refusals:
`invalid_bleed` (outside 0–10, never clamped), `invalid_crop_marks`,
`missing_print_setup`, `print_setup_not_available` (px / social),
`document_page` (a document page follows its document — use
`manage_document` `set_print` with the named `documentId`).

## `create_template(projectId, name, dimension, categoryId?)`

Creates a NEW standalone template with its first variant — the web's "Nová
šablona" form. Scope `templates:design`; the account must be able to edit the
project (owner or admin — a shared project is view-only). The template starts
**empty** (no design, no background, no inputs) and uncategorised unless
`categoryId` (a category of the same project, `find_templates`
`templates[].categoryId`) is given. Next step: `set_design` on the returned
`variantId` with `canvas.width` / `canvas.height` = the returned
`dimension.width` / `dimension.height`.

- `name`: 3–30 characters.
- `dimension`, one of:
  - `{"unit": "px"|"mm"|"cm", "width", "height"}` — the size in that unit;
    each side must come to 16–10000 canvas px (mm / cm at 300 DPI).
    `unitWidth` / `unitHeight` are synonyms and win, so a `dimension` copied
    from `get_context` / `describe_variant` works unchanged.
  - `{"preset": "4:5"|"9:16"}` — the social formats (`1:1` is retired: send
    px 1080 × 1080).
  - mm / cm only: `bleedMm` (0–10 per side, 5 when omitted, 0 = none) and
    `cropMarks` (false when omitted). **Ask the user for the bleed** when they
    have not said. px / presets take neither.

Answers `{templateId, variantId, templateName, projectId, projectName,
categoryId, categoryName, dimension, editorUrl, status}` (`dimension` as in
`describe_variant`; `editorUrl` is absolute, for the human).

Refusals start with their code and create nothing: `project_not_found` (also
a project the account cannot see), `project_not_editable`, `invalid_name`,
`invalid_dimension` (unit, size, range; a print-setup problem also names
`invalid_bleed` / `invalid_crop_marks` / `print_setup_not_available`),
`unknown_preset`, `unknown_category` (also a category of another project).
A document page is made with `manage_document` `add_page` instead; nothing
here deletes.

## `scan_design(action, …)` — AI scan: a picture becomes a template or a calendar layout

Scope `ai:scan` (implies nothing; a paid feature WBoost switches on per
project). A flat picture of a finished design (JPG, PNG, WebP, HEIC, or a PDF —
its first page is scanned; ≤ 10 MB, sides ≤ 8000 px, ≤ 36 Mpx, shorter side
≥ 600 px for a template, ≥ 300 px for a calendar page) becomes a **proposal**:
texts, shapes, colours and **places for pictures** (pictures are never taken
out of the image). Nothing exists among the templates until `apply`. A scan
runs in the background, usually 1–3 minutes (up to about 12).

| action | arguments | returns |
|---|---|---|
| `start` | `projectId`; `imageId` (a gallery picture) **or** `imageBase64` (≤ ~3 MB); `requestId?` (idempotency key); `kind?` `template` (default) \| `calendar`; template: `format?` `{unit: px\|mm\|cm, width, height}` — same aspect ratio as the picture; calendar: `calendarId` (a 7-day calendar of the project) | `scanId`, `status`, `creditsLeft`, `queuePosition`, `estimatedStartSeconds`, `estimatedFinishSeconds`, `pollAfterSeconds` |
| `status` | `scanId` | `status`, `waiting`, `restarted`, `stage`, `note`, `round`, `elapsedSeconds`, queue fields, `expiresAt`, `unfinished`, `errorCode` + `errorSentence`, `retriesLeft`, `canRetry`, `canContinue`, `pollAfterSeconds` (null once ended) |
| `result` | `scanId`, `image?` | `name`, `counts`, `toDecide`, `elements[]`, `placeholders[]`, `decisions[]` (each with the `fixes[]` it accepts), `information[]`, `fonts[]` (+ the render with `image: true`) |
| `apply` | template: `scanId`, `fixes?[]`, `placeholders?[]` `{id, assetId\|null, kind?: fixed\|input, fit?: contain\|cover}`, `name?` (3–30) | `templateId`, `variantId`, `editorUrl`, `unfilledPictures`, `created` — creates the template; idempotent |
| `apply` | calendar: `scanId`, `features?[]` (keys of `featuresOffered` to switch on) | `calendarId`, `calendarUrl`, `revision`, `features`, `unfilledPictures`, `restoreUntil`, `created` — SAVES the layout into the calendar; idempotent (a repeat answers `created: false` and the number of places that are empty now) |
| `restore` | `scanId` (an applied calendar scan), `confirm?` | `calendarId`, `calendarUrl`, `revision`, `restored` — the previous layout, layout choice and switches come back |
| `list` | `projectId`, `status?` | `scans[]` |
| `cancel` | `scanId` | `status`, `counted` (true when it was already running) |
| `retry` | `scanId` (failed) | `status`, `retriesLeft` — at most twice per upload |
| `continue` | `scanId` (stopped with `photo_of_print`) | `status` — once per upload |

`status` values: `checking`, `queued`, `running`, `ready`, `accepted`,
`discarded`, `cancelled`, `stopped`, `failed`, `refused`, `expired`.

`fixes[]` types: `choose_font {group, face}`, `keep_font {group}`,
`confirm_text {id, text?}`, `set_text {id, text}`, `set_input {id, input}`,
`rename {id, name}`, `add_placeholder {omitted}`, `keep_omitted {omitted}`,
`remove_placeholder {id}`, `merge_placeholders {ids}`,
`placeholder_to_shape {id, shape: rectangle|circle|star, colour?}`,
`undo_shrink`, `revert_palette`.

**What counts** against the project's monthly limit: a scan that delivers a
proposal (whatever you then do with it) and a scan cancelled while `running`.
Stopped, failed, refused and scans cancelled while waiting do not.

Refusals are tool errors that start with a code — nothing is charged for a
refused `start`: `not_enabled`, `consent_required` (the user must agree to the
data-processing text in the wboost web app; you cannot agree for them),
`limit_reached`, `daily_starts_reached`, `project_queue_full`, `queue_full`,
`temporarily_unavailable`, `project_paused`, `no_fonts`, `unsupported_image`,
`unsupported_kind` (`template` or `calendar`), `source_unscannable`, `no_retries_left`,
`wrong_state` (the action does not apply in the scan's state), `invalid_fix`
(names the entry; nothing is created), `stale_references`, `renderer_busy`.

Places left empty show a grey stand-in in the new template
(`unfilledPictures`); `export_variant` / `export_document` then refuse with
`unfilled_pictures` until they are filled or `allowUnfilled: true` is passed.

### Calendar scans (`kind: "calendar"`)

A picture of **one page of a desk calendar** becomes the proposed
**layout** of an existing calendar — nothing of the calendar changes
until `apply`. The layout is built for the calendar's own type
(`list_calendars` → `type`): a 7-day calendar (`week`) gets a page with one
week column, a 14-day one (`fortnight`) the two-sided page — a week column on
each side of the photo (`preset: "twoSided"`, with the second frames `cal2`,
`month2`, `wk2` in `layout.boxes`). So scan a two-week page for a 14-day
calendar and a one-week page for a 7-day one. The scan takes over where the photo, the day rows
(Kalendárium), the header, the month, the week number, the caption and the
logo sit, their colours, and fonts chosen among the project's (a project
without fonts is fine: the built-in face is used). It never reads the page's
texts, days or photos — the calendar generates those itself — and never takes
a picture out of the image.

The picture of a calendar page may be small: its shorter side must be at
least **300 px** (a template scan needs 600 px). Under about 600 px the layout
may come out less precise — say so when the user has a larger original.

`result` of a calendar scan:

```jsonc
{
  "kind": "calendar", "calendarId", "expiresAt",
  "hasLayout": true,            // the calendar has a layout that apply REPLACES
  "preset": "photoRight",
  "layout": { "v": 1, "boxes": {…}, "order": […], "styles": {…} },   // what the calendar would get now (mm, pt)
  "recognised": [ { "element": "photo|cap|cal|month|wk|head|logo|qr|bg",
                    "state": "found|approximate|absent",
                    "kept": false } ],   // kept: the calendar keeps its current setting (element not on the page)
  "featuresOffered": ["qr", "events"],   // switches the page shows and the calendar has off
  "pictures": [ { "slot": "logo|header|background", "current": false } ],   // places whose picture you must supply
  "fonts": [ { "group": "num", "pattern": "measured_match|nearest_available|undetermined", "face" } ],
  "notExpressible": [ { "code": "ornament|header_text|second_photo|more_logos|days_in_grid|rotated_text|month_colours|multilingual_names", "box" } ],
  "notes": [ { "code": "fortnight_left_half|ratio_adapted|colours_estimated|page_box_set" } ],
  "applied": null               // after apply: { restoreUntil, restorable, restored, changed, features }
}
```

Rules:

- `apply` **saves at once** and replaces the calendar's layout; tell the user
  first when `hasLayout` is true. It needs the designer's role on the calendar
  (`designer_only` otherwise). Texts, photos and page content are never
  touched. A scan only ever switches features ON, and only those you pass in
  `features[]`.
- `restore` puts back the layout, layout choice and switches the calendar had
  before `apply` — until `restoreUntil` (the scan's `expiresAt`, not 30 days
  from applying). If the layout or switches were edited after `apply` it
  refuses with `layout_changed`: those edits would be lost — ask the user,
  then repeat with `confirm: true`.
- Pictures: for each `pictures[]` slot without `current`, upload the file with
  `upload_calendar_asset` (`logo` → kind `logo`; `header` / `background` →
  kind `image`) after `apply` and set it with `set_calendar_layout`
  (`styles: {"logo": {"asset": id}}`, `{"head": {"bg": {"asset": id, "imageOn": true}}}`,
  `{"bg": {"asset": id, "imageOn": true}}`).
- When the page and the calendar disagree, `notes[]` says so and only one
  week of the picture is used: `fortnight_left_half` (a two-week page for a
  7-day calendar — the left week was rebuilt, the right one is not in the
  layout) and `week_page_on_fortnight` (a one-week page for a 14-day
  calendar — the second week column mirrors the first). Tell the user, and
  offer to scan for a calendar of the other type instead of applying.
- A calendar whose type was changed after the scan cannot take the proposal
  (`wrong_state` at `apply`) — scan again.
- Look at the result with `render_calendar_page` after `apply`.

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
alone. Read `references/design.md` ("acknowledgeLosses is a decision") before using it: it repairs nothing, changes
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
`lint` and `overwrite` produce both. The lint **errors** are
`font_not_allowed` — an unknown face would be silently substituted by the
renderer, so it stops the call before anything is drawn — and the
repeatable-row structure codes `repeat_not_nested`, `repeat_multiple_in_parent`
and `repeat_inside_repeat` (the saved design would silently lose that
`repeat`). Other lint codes
(`out_of_canvas_bounds`, `text_overlap`, `color_not_in_palette`,
`font_size_too_small`, `container_overflow_predicted`, `container_too_few_items`,
`container_hidable_without_name`, `image_without_asset_or_placeholder`,
`max_length_below_stand_in`, `user_move_on_container_member`,
`user_move_on_nested_container`, `repeat_max_overflows`, `missing_background`,
`background_drawn_as_element`, `canvas_color_under_background`) are warnings.

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
