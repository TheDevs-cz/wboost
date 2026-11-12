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
An empty allow-list is never "no folders".

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

Nothing deletes.

## `list_calendars(projectId?)`

Scope `calendars:read`. Desk calendars (module Kalendáře) of one project — or,
without `projectId`, of every project the account can reach (by project name,
newest calendar first).

```jsonc
{
  "projectId": null,                    // the project the listing was narrowed to
  "calendars": [{
    "id", "projectId", "projectName", "name",
    "year",                             // 2027
    "type",                             // "week" (7 days / page) | "fortnight" (14 days / page)
    "finished",                         // false = the designer is still setting up the look
    "hasLayout",                        // false = no page layout yet
    "updatedAt",
    "lastChangeBy", "lastChangeAt",     // "designer" | "client" | null — newest history entry
    "url"                               // the web workspace, for the user
  }]
}
```

Read-only. `manage_calendar` creates and configures; deleting stays on the web.

## `get_calendar(calendarId, historyLimit?)`

Scope `calendars:read`. One calendar as the browser sees it — every default
filled in by the calendar model.

```jsonc
{
  "id", "projectId", "projectName", "name", "year", "type", "finished",
  "step",                               // the web workspace step it opens in: 1 Typ … 5 Náhled
  "revision",                           // +1 per save
  "updatedAt",
  "role",                               // "designer" | "client" — this account, for this calendar
  "writableCollections": ["events", "…"],
  "preset",                             // "photoLeft" | "photoRight" | "twoSided" | null
  "features": { "captions", "weekNumbers", "moon", "timeChange", "seasons",
                "waste", "events", "shifts", "specialPages", "qr" },   // all ten, booleans
  "print": { "bleed", "bleedMm", "marks" },
  "page":  { "width", "height", "bandHeight", "holes" },             // mm
  "layout": { "v": 1,
              "boxes": { "photo": { "x", "y", "w", "h" }, "logo": { "…", "hidden"? }, "…" },   // mm from the top-left trim corner
              "order": ["qr", "logo", "…"],                           // stacking, top first
              "styles": { /* see set_calendar_layout */ } },          // null = no layout yet
  "pages": [{ "index", "key", "kind", "title", "subtitle", "from"?, "to"? }],   // null only if the page list could not be computed
  "content": { "v": 1, "<collection>": { "<id>": { /* item */ } } },
  "assets": { "<assetId>": { "url", "previewUrl", "width", "height", "mime", "name", "description", "kind" } },
  "fonts": [{ "family", "fontName", "faceName", "weight", "style" }],  // family = the exact string a text style takes
  "palette": ["#RRGGBB"],
  "history": [{ "who", "what", "at" }], // newest first; "what" is a Czech sentence; historyLimit 0–200 (default 20)
  "url"
}
```

Maps are objects even when empty; a collection never written is absent. The
client link is never returned here (`manage_calendar` `client_link`).
`pages[].kind` is `cover` | `special` | `week` | `back`; `pages[].key` is the
page key content and `render_calendar_page` use.

## `manage_calendar(action, projectId?, calendarId?, name?, year?, type?, features?, print?, preset?, linkElements?, fromCalendarId?, regenerate?, variant?, exportId?)`

Scope `calendars:write`, **designer role** (the two export actions: any
role). Every action is the web's own message; the answer is the design as
stored now.

| action | arguments | does |
|---|---|---|
| `create` | `projectId` + optional `name`, `year`, `type`, `features`, `print`, `preset` | a new calendar ("Stolní kalendář ‹next year›", `week`, no layout) with the given settings applied; `preset` gives it a layout at once. Settings are checked first — a refused call creates nothing |
| `configure` | `calendarId` + any of `name`, `year`, `type`, `features`, `print`, `linkElements` | changes only what is named |
| `finish` | `calendarId` | "Dokončit": `finished: true`, the web opens it in step 5; needs a layout |
| `client_link` | `calendarId`, `regenerate?` | answers `clientUrl` (anonymous write access to the content — a secret); `regenerate: true` kills the old link |
| `import_layout` | `calendarId`, `fromCalendarId` | replaces the layout by that of another calendar of the same project and type; its pictures are copied |
| `export_pdf` | `calendarId`, `variant` (`print` \| `print_cmyk` \| `approval`) | starts a PDF export of the whole SAVED calendar (or answers the existing file of an unchanged one) → `export`. `print`: bleed + crop marks as the print settings say, photos at uploaded resolution, no binding-hole row (punched at the bindery); `approval`: trimmed pages as the finished calendar looks (holes drawn), screen-size photos; `print_cmyk`: the `print` pages converted to CMYK for offset print (PDF/X-4, output intent Coated FOGRA39; neutral colours (R = G = B) and nearly neutral text → black ink only, palette colours → the palette's CMYK, the rest and photos → ICC; registration-colour crop marks) — several seconds per photo page, about twice the size; a failed conversion answers `failed` with a Czech `error` that points to `print`. Vector text and shapes; `print` / `approval` are RGB. Recorded in the history. Needs a layout |
| `export_status` | `calendarId`, `exportId` | → `export` of that job |

`export`: `{ "exportId", "variant", "status": "queued" | "running" | "done" | "failed",
"pagesDone", "pagesTotal", "revision", "fileName", "sizeBytes", "downloadUrl", "error" }` —
`downloadUrl` (only when `done`) is the PDF itself, reachable without an account: give it to the
user, nobody else. `error` (only when `failed`) is a Czech sentence; call `export_pdf` again to
retry. Poll `export_status` every few seconds — about a second per page. Only the newest six
exports of a calendar are kept (`export_not_found` afterwards).

- `name` 1–60 chars. `year` 2000–2100 — a change shifts every dated item,
  drops 29 February items when the new year has none and clears moon edits.
- `type`: `week` (presets `photoLeft`, `photoRight`) | `fortnight` (`twoSided`).
  A change resets element positions to the new type's default; styles stay.
- `features`: any of the ten switches. Content of an off feature is stored,
  not printed.
- `print`: `{bleed?, bleedMm? 0–10, marks?}`.
- The page size has no setting.

```jsonc
{
  "calendarId", "projectId", "name", "year", "type", "finished", "step", "revision", "updatedAt",
  "preset", "features", "print", "page", "layout",   // as in get_calendar
  "changed": ["created", "name", "layout", "…"],     // [] = nothing changed, nothing saved
  "notes": ["…"],                                    // clamped values, a reset / mirrored layout, dropped items — read them
  "recorded": [{ "who", "what", "at" }],
  "url",
  "clientUrl"                                        // only from "client_link"; else null
}
```

Reason codes: `invalid_action`, `missing_argument`, `nothing_to_do`,
`invalid_project_id`, `project_not_found`, `invalid_calendar_id`,
`calendar_not_found`, `designer_only`, `invalid_name`, `invalid_year`,
`invalid_type`, `unknown_feature`, `invalid_print`, `unknown_preset`,
`preset_type_mismatch`, `no_layout`, `not_a_sibling`, `layout_type_mismatch`,
`source_has_no_layout`, `invalid_design`, `model_unavailable`.

## `set_calendar_layout(calendarId, preset?, reset?, boxes?, order?, styles?)`

Scope `calendars:write`, **designer role**. One layout serves every week page.
Arguments are applied in this order; the call is atomic.

- `preset`: `photoLeft` | `photoRight` (7 days), `twoSided` (14 days). No
  layout yet → the browser's default layout for it. Has one → `photoLeft` ↔
  `photoRight` mirrors it and keeps the styling; `reset: true` applies the
  untouched default instead.
- `boxes`: `{"<element>": {x?, y?, w?, h?, hidden?}}` — mm from the top-left
  trim corner, 0.1 mm steps, inside `[-bleed, size + bleed]`, min 6 × 4.
  Elements `photo`, `cap`, `logo`, `head`, `month`, `cal`, `wk`, `qr` (+ the
  second-week frames a 14-day layout lists). `hidden` only on `logo`, `month`,
  `wk`, `qr`.
- `order`: every key of `layout.order` once, top layer first.
- `styles`: a partial `layout.styles` — objects merge, scalars and lists
  replace. Sizes and line widths in pt (type 3–60), lengths in mm, opacity
  0–100, colours `#RRGGBB`, `family` = a `get_calendar` `fonts[].family` or
  `null`.

| style group | keys |
|---|---|
| text styles `num` `numSat` `numSun` `numHol` `day` `name` `hol` `ev` `time` `shift` `placeText` `month` `wk` `cap` `head.text` | `family`, `weight`, `size`, `color` (+ `hol.wrapWidth`; `ev.align/valign/box`; `time.position/align/valign`; `shift.bgMode/bg`; `month.align/upper/pad/bg`; `wk.align/form`; `cap.align/valign/pad/bg`) |
| `cal` | `numberSide`, `numAlign`, `dayFormat`, `dayPosition`, `dayAlign`, `satColor`, `sunColor`, `bg {color, opacity}`, `iconSize`, `iconGrid`, `iconCellW`, `iconCells`, `placeLabels`, `emptyIconSlot` |
| `lines` | `on`, `width`, `style`, `color` |
| fills `bg`, `head.bg`, `month.bg`, `cap.bg` | `colorOn`, `color`, `colorOpacity`, `gradient {on, type, angle, stops[{color, opacity, pos}]}`, `imageOn`, `asset`, `fit`, `imageOpacity` |
| `photo` | `source`, `radius`, `border {on, width, color}`, `shadow {on, blur, opacity, dx, dy, color}` |
| `logo`, `qr` | `logo {asset, fit}`, `qr {perPage, asset}` |
| `moon` | `color`, `size`, `valign`, `phases[4]`, `icons[4]` |
| `icons` | `order` (of `waste`, `moon`, `ev`, `time`, `season`), `ev {name, asset, perEvent}`, `time {asset, color}`, `season {mode, asset, assets[4], color}` |
| `waste.kinds`, `offsets` | maps keyed by waste kind id / part name: an entry merges one level, `null` removes it |

Enumerations: `align` `start`\|`middle`\|`end`; `valign` `top`\|`middle`\|`bottom`;
`numberSide` `start`\|`end`; `dayFormat` `full`\|`short`; `dayPosition`
`side`\|`above`; `iconGrid` `4`\|`2x2`\|`1+2`\|`2+1`; `lines.style`
`solid`\|`dash`\|`dot`; `wk.form` `a`\|`b`\|`c`; `time.position` `text`\|`sep`;
`shift.bgMode` `alt`\|`all`\|`none`; `fit` `cover`\|`contain`; `photo.source`
`client`\|`self`; `icons.season.mode` `four`\|`one`; `gradient.type`
`linear`\|`radial`.

Answer: the `manage_calendar` shape. Reason codes: `invalid_calendar_id`,
`calendar_not_found`, `designer_only`, `nothing_to_do`, `unknown_preset`,
`preset_type_mismatch`, `no_layout`, `unknown_box`, `invalid_box`,
`not_hideable`, `invalid_order`, `unknown_style_key`, `invalid_style_value`,
`unknown_font`, `invalid_color`, `invalid_design`, `model_unavailable`.

## `upload_calendar_asset(calendarId, kind, fileBase64, filename)`

Scope `calendars:write`. `kind`: `photo` (JPEG / PNG / WebP / phone HEIC; never
SVG), `logo`, `icon`, `qr`, `image` (header / background picture), `page` (a
special page's graphic; a PDF's first page is rasterised). base64 only (a
`data:` URI is tolerated), about 3 MB over MCP, 10 MB hard limit. A `client`
role uploads `photo` only. Uploading changes nothing in the calendar.

```jsonc
{
  "calendarId", "assetId", "kind", "url", "previewUrl",
  "width", "height",                    // of what was stored; null for SVG
  "mime", "name", "description",        // description = the caption embedded in the file
  "next"                                // where to reference the id
}
```

Reason codes: `invalid_calendar_id`, `calendar_not_found`, `invalid_kind`,
`invalid_file`, `file_too_large`, `upload_refused`, `designer_only`.

## `render_calendar_page(calendarId, page, bleed?, marks?, sampleTexts?)`

Scope `calendars:read`. `page` = a `get_calendar` `pages[].key`. Returns a JSON
block, then a WebP (1600 px long edge) of the page as stored.

```jsonc
{
  "calendarId", "calendarName",
  "page", "kind", "title", "subtitle", "from", "to",
  "format": "image/webp", "width", "height",
  "viewMm": { "x", "y", "w", "h" },     // what the picture shows; negative x / y with bleed or marks
  "pxPerMm",
  "warnings": ["…"],                    // Czech: portrait photo, photo under 200 dpi, icons / events that do not fit
  "pageKeys": ["cover", "pre", "w01", "…"]
}
```

Reason codes: `invalid_calendar_id`, `calendar_not_found`, `no_layout`,
`unknown_page`, `model_unavailable`, `renderer_busy`, `render_failed`.

## `update_calendar_content(calendarId, operations)`

Scope `calendars:write`. `operations`: 1–2000 of
`{ "collection", "id", "value", "label"? }`, applied in order, all or nothing.
`value` is the WHOLE item (all keys, no others) or `null` to delete. `label` is
display text for the history line (`"1. 6."`, `"5. týden"`).

Ids: `A–Z a–z 0–9 _ -`, up to 40 characters; invent 16 random ones for a new
item. Page-keyed collections use the page key as the id: `wNN` (ISO week of the
page's Monday, `w05`), `pre`, `post`, `cover`, `back`, `sp:<specialPageId>`.
Dates `YYYY-MM-DD` within `[year−1-12-01, year+1-01-31]`. Weekdays `0` Monday
… `6` Sunday. Assets are ids of files uploaded to this calendar
(`upload_calendar_asset`; listed in `get_calendar` `assets`).

| collection | item | who |
|---|---|---|
| events | `{date, name ≤80, club: clubId\|null, icon: assetId\|null}` | client, designer |
| clubs | `{code ≤6, name ≤60, pos}` | client, designer |
| names | `{date, name ≤40}` | client, designer |
| wasteKinds | `{builtin: "kom"\|"bio"\|"plast"\|"papir"\|"sklo"\|null, name ≤30, on, place ≤40, mode: "rule"\|"dates", weekdays: [0–6]+, interval: "w"\|"2e"\|"2o"\|"4w"\|"4w2"\|"m", from\|null, to\|null, dates: [date], pos}` | client, designer |
| wasteExtras | `{kind: wasteKindId, date}` | client, designer |
| comments | `{date, text ≤500}` — insert only; author + time set by the server | client, designer |
| pageCaptions | `{text ≤80}` (id = page key) | client, designer |
| pagePhotos | `{asset, zoom 1–3, px 0–1, py 0–1}` (id = page key) | client, designer |
| pageContent | texts + photo of the cover / back / generated special pages — id is one of `cover`, `back`, `sp:intro`, `sp:contacts`, `sp:year`, `sp:waste`, `sp:notes` and decides the shape (below) | client, designer |
| pageGroups | `{page: "sp:contacts"\|"sp:waste", heading ≤80, lines: [{label ≤40, value ≤200}] (≤30), highlight, pos}` — ≤ 60 per calendar | client, designer |
| moonEdits | `{op: "add"\|"remove", date, phase 0–3}` | designer |
| shiftOps | `{name ≤30, shifts: [{letter ≤2, from "HH:MM", to "HH:MM"}] (1–4), crews: [4 × ≤2], pos}` | designer |
| specialPages | `{kind: "intro"\|"contacts"\|"year"\|"waste"\|"notes"\|"image"\|"text", on, layout 0–20, position: "front"\|"back", nextYear, name ≤60, asset\|null, text ≤6000\|null, fileName ≤120\|null, pos}` | designer |
| pageImages | `{head: asset\|null, bg: asset\|null}` (id = page key) | designer |
| pageQr | `{asset}` (id = page key) | designer |
| pageSetup | arrangement of a page — id `cover` `{layout 0–3, logo: "none"\|"tl"\|"tc"\|"tr"\|"title", logoSize: "s"\|"m"\|"l"}`, `sp:intro` `{align: "left"\|"justify"}`, `sp:contacts` `{clubLegend, wasteLegend, notes}`, `back` `{logo}` | designer |

**`pageContent` shapes** (all keys required; `""` = empty — an empty `title` /
`heading` prints the default; `photo` is `null` or `{asset, zoom 1–3, px 0–1,
py 0–1}`):

| id | item |
|---|---|
| `cover` | `{title ≤80, subtitle ≤80, note ≤120, photo}` — default title `Kalendář <year>` |
| `sp:intro` | `{heading ≤80, salutation ≤80, body ≤4000, signName ≤80, signRole ≤80, photo}` |
| `sp:contacts` | `{heading ≤80, notesHeading ≤40, photo}` — the contacts are `pageGroups` |
| `sp:year`, `sp:waste` | `{heading ≤80, note ≤200}` |
| `sp:notes` | `{heading ≤80}` |
| `back` | `{heading ≤80, footer ≤200, name ≤80, address ≤300 (≤ 6 lines, "\n"), phone ≤60, email ≤80, web ≤80, imprint ≤200}` |

`body` is plain text with light marks: an empty line separates paragraphs, a
single line break stays, `**bold**`, `*italic*`, `\*` = a literal asterisk; an
unclosed mark is printed as it is. Never HTML.

A `pageGroups` line with `label: ""` is free text; with a label the group's
values align in a column; `{label: "", value: ""}` is a small gap.
`highlight: true` = the emphasised block (emergency numbers). Groups of
`sp:waste` are drawn beside the grid in the waste plan's layout 1 only.

Special-page layouts (`specialPages[*].layout`): intro `0` photo + text, `1`
text only, `2` portrait + two columns · contacts `0` two, `1` three, `2` four
columns, `3` two columns + photo · year `0` 6 × 2 months, `1` 4 × 3, `2` two
years · waste `0` grid, `1` grid + texts. Cover (`pageSetup.cover.layout`): `0`
photo with a title band, `1` framed photo with the title below, `2` no photo,
`3` photo only (a finished cover graphic; no texts).

Texts auto-fit (smaller type, then more columns). What still does not fit ends
with `…` and comes back as a warning of kind `overflow` from
`render_calendar_page` — shorten the text or pick a roomier layout.

Filling the four pages of a municipal calendar:

```json
{ "operations": [
  { "collection": "pageContent", "id": "cover", "label": "Titulní strana",
    "value": { "title": "Kalendář 2027", "subtitle": "Obec Chotěbuz", "note": "www.chotebuz.cz",
               "photo": { "asset": "<assetId>", "zoom": 1, "px": 0.5, "py": 0.5 } } },
  { "collection": "pageContent", "id": "sp:intro", "label": "Úvodní slovo",
    "value": { "heading": "", "salutation": "Milí občané,",
               "body": "rok utekl jako voda…\n\nDruhý odstavec s **důrazem**.",
               "signName": "Ing. David Harok", "signRole": "starosta obce", "photo": null } },
  { "collection": "pageGroups", "id": "grpObecniUrad001", "label": "Důležité kontakty",
    "value": { "page": "sp:contacts", "heading": "Obecní úřad", "highlight": false, "pos": 0,
               "lines": [ { "label": "Po, St", "value": "7 – 12, 13 – 17" },
                          { "label": "", "value": "" },
                          { "label": "T:", "value": "558 733 131" } ] } },
  { "collection": "pageGroups", "id": "grpTisnovaCisla1", "label": "Důležité kontakty",
    "value": { "page": "sp:contacts", "heading": "Důležitá telefonní čísla", "highlight": true, "pos": 1,
               "lines": [ { "label": "Policie ČR", "value": "158" }, { "label": "Hasiči", "value": "150" } ] } },
  { "collection": "pageContent", "id": "back", "label": "Zadní strana",
    "value": { "heading": "", "footer": "", "name": "Obec Chotěbuz", "address": "Chotěbuzská 250\n735 61 Chotěbuz",
               "phone": "+420 558 733 131", "email": "chotebuz@volny.cz", "web": "www.chotebuz.cz",
               "imprint": "Vydala obec Chotěbuz · Grafika: …" } }
] }
```

Waste intervals: `w` every week, `2e` / `2o` even / odd ISO weeks, `4w` /
`4w2` every fourth week (ISO week % 4 = 1 / 3), `m` the first such weekday of
the month. `from` / `to` null = the whole calendar year. A new calendar already
holds `kom`, `bio`, `plast`, `papir` switched off.

```jsonc
{
  "calendarId", "revision", "updatedAt",
  "role",                               // the role the changes were recorded under
  "results": [{ "collection", "id", "status" }],   // created | updated | deleted | unchanged
  "content": { "<touched collection>": { "<id>": { /* item */ } } },
  "recorded": [{ "who", "what", "at" }] // the history lines this call added
}
```

Refusals end with a reason code in parentheses:

| reason | meaning |
|---|---|
| `invalid_calendar_id` | not a UUID |
| `calendar_not_found` | no such calendar, or the account cannot see its project |
| `no_operations` | `operations` is empty |
| `invalid_operation` | an operation is not `{collection, id, value, label?}` — a missing `value` is not a delete, any other key (e.g. a design key) is refused |
| `invalid_content` | the item breaks the rules above; the quoted Czech sentence names the collection and field (`Neplatná data kalendáře (events › date).`) |
| `designer_only` | a `client` role touched a designer-only collection |

This tool never changes the design or uploads a file — see `manage_calendar`,
`set_calendar_layout`, `upload_calendar_asset`. Nothing deletes a calendar.

## `manage_document(action, projectId?, documentId?, name?, dimension?, templateIds?, position?, fields?, pageId?, bleedMm?, cropMarks?, categoryId?)`

The actions:
- `create`: `projectId`, `name`, `dimension` — either `{unit: "mm"|"cm"|"px",
  unitWidth, unitHeight, bleedMm?, cropMarks?}` or `{preset}` — and
  `templateIds?`. For mm / cm include `bleedMm` + `cropMarks` (below).
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
- `set_print`: `documentId`, `bleedMm?` (0–20), `cropMarks?` — for the
  document and every page; mm / cm documents only.

**Print setup (mm / cm only).** `bleedMm` ("spadávka", 0–20 mm per side) is
how far the artwork runs past the cut; `cropMarks` adds cut marks in a fixed
5 mm margin outside the bleed (PDF page = trim + 2 × bleed + 2 × 5 mm). When
the user wants a print document and has not said which bleed, **ask them**
before `create` — suggest 3 mm with crop marks, the common default. A print
`create` without `bleedMm` gets 0 mm and no marks and answers
`warnings: [{code: "missing_bleed", message}]`; relay it and fix it with
`set_print` if they want a bleed. Screen formats (px, presets) have no bleed:
a bleed > 0 or `cropMarks: true` there is refused
(`print_setup_not_available`); out-of-range values are refused
(`invalid_bleed`, `invalid_crop_marks`), never clamped.

Every action answers the document:
- `id`, `name`, `categoryName`, `categoryId`, `dimension`, `printFormat`
- `pages[]`: `{pageId, position, templateId, templateName, variantId,
  thumbnailUrl}`
- `fields[]`: `{id, name, kind, bindings[], textMode}`
- `editorUrl`, `fillUrl`
- `warnings[]`: `{code, message}` — only `missing_bleed` after a print
  `create` without `bleedMm`; empty otherwise

Refusals name the invariant: `template_grouped`, `template_not_found`,
`no_matching_variant`, `template_in_other_document`, `duplicate_page`,
`too_many_pages` (30), `invalid_binding`.

## `set_print_setup(variantId, bleedMm?, cropMarks?)`

Bleed + crop marks of ONE standalone print variant (mm / cm,
`dimension.printFormat` true) — what `export_variant` `format: "pdf"` prints
with. `bleedMm` ("spadávka", 0–20 mm per side) is how far the artwork runs
past the cut; `cropMarks` adds cut marks in a fixed 5 mm margin outside the
bleed. An omitted value keeps its setting. **If the user has not said which
bleed, ask** — suggest 3 mm with crop marks. Changing the bleed re-fits the
background layer; other edge-reaching artwork must extend `dimension.bleedPx`
past the edges (`set_design`).

Answers `{variantId, templateName, projectName, grouped, dimension, status}`
(`dimension` as in `describe_variant`). A grouped variant is accepted and only
this dimension changes (a group has no shared print setup). Refusals:
`invalid_bleed` (outside 0–20, never clamped), `invalid_crop_marks`,
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


## `scan_design(action, …)` — AI scan: a picture becomes a template

Scope `ai:scan` (implies nothing; a paid feature WBoost switches on per
project). A flat picture of a finished design (JPG, PNG, WebP, HEIC; ≤ 10 MB,
sides ≤ 8000 px, ≤ 36 Mpx, shorter side ≥ 600 px) becomes a **proposal**:
texts, shapes, colours and **places for pictures** (pictures are never taken
out of the image). Nothing exists among the templates until `apply`. A scan
runs in the background, usually 1–3 minutes (up to about 12).

| action | arguments | returns |
|---|---|---|
| `start` | `projectId`; `imageId` (a gallery picture) **or** `imageBase64` (≤ ~3 MB); `requestId?` (idempotency key); `format?` `{unit: px\|mm\|cm, width, height}` — same aspect ratio as the picture | `scanId`, `status`, `creditsLeft`, `queuePosition`, `estimatedStartSeconds`, `estimatedFinishSeconds`, `pollAfterSeconds` |
| `status` | `scanId` | `status`, `waiting`, `restarted`, `stage`, `note`, `round`, `elapsedSeconds`, queue fields, `expiresAt`, `unfinished`, `errorCode` + `errorSentence`, `retriesLeft`, `canRetry`, `canContinue`, `pollAfterSeconds` (null once ended) |
| `result` | `scanId`, `image?` | `name`, `counts`, `toDecide`, `elements[]`, `placeholders[]`, `decisions[]` (each with the `fixes[]` it accepts), `information[]`, `fonts[]` (+ the render with `image: true`) |
| `apply` | `scanId`, `fixes?[]`, `placeholders?[]` `{id, assetId\|null, kind?: fixed\|input, fit?: contain\|cover}`, `name?` (3–30) | `templateId`, `variantId`, `editorUrl`, `unfilledPictures`, `created` — creates the template; idempotent |
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
`unsupported_kind` (only `template`), `source_unscannable`, `no_retries_left`,
`wrong_state` (the action does not apply in the scan's state), `invalid_fix`
(names the entry; nothing is created), `stale_references`, `renderer_busy`.

Places left empty show a grey stand-in in the new template
(`unfilledPictures`); `export_variant` / `export_document` then refuse with
`unfilled_pictures` until they are filled or `allowUnfilled: true` is passed.

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
`user_move_on_nested_container`, `repeat_max_overflows`) are warnings.

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
| `text` element | `kind`, `id`, `text`, `font`, `size`, `color`, `align`, `lineHeight`, `opacity`, `at`, `x`, `y`, `width`, `input`, `userMove` |
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
  callers still land in their row. Never author it — but keep it verbatim when
  you send a decompiled design back, or rows 2+ of every old export are lost.
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
  The fill page / API side of moving is not live yet — today the DSL only
  records the allowance.
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

### `rows` — keyed by `repeatableRows[].containerId`

```jsonc
"rows": {
  "<containerId>": [                                  // one object per row — the
    { "<members[].id>": "TJ Kelč", "<crest id>": "<imageId>" },  // list length IS the count
    {},                                               // a row with the template samples
    { "<members[].id>": {"value": "FK Nový Jičín"}, "<row containerId>": {"hide": true} }
  ]
}
```

Values take the shapes of `inputs` (text members, `{"hide": true}` for a
`kind: "container"` member), `images` (image slots) and `shapeColors` (shapes).
The count must stay within `repeat.min`…`repeat.max`. A row left out of `rows`
shows its `default` count. A member of a row you send in `rows` must NOT also
be addressed through `inputs` / `images` / `shapeColors` (not even by a
`slots[].ids` id) — that is `ambiguous_row_value`. A value refused inside a
row names `rowContainerId` and `rowIndex` (0-based) next to the member id you
sent. Rows that do not fit are a container overflow, as for long texts.

### `offsets` — keyed by `movableElements[].id`

`{"<id>": {"x": 0, "y": -24}}` — canvas px from the designed position, +x right,
+y down; a missing axis is 0. Stay inside the element's `range`. A zero move is
no move (and is not recorded). One map per variant; on a document, one
per page.

### Row and move refusals (same codes as the REST API)

The message is the REST sentence, then the code in parentheses, then the REST
context as `key: value` pairs.

| code | meaning |
|---|---|
| `not_repeatable` | a `rows` key that is no `repeatableRows[].containerId` |
| `too_many_rows` / `too_few_rows` | the list is longer than `max` / shorter than `min` (`count`, `min`, `max`) |
| `unknown_row_input` | a row object key that is no `members[].id` (`rowIndex`, `inputId`) |
| `ambiguous_row_value` | that row member is also in `inputs` / `images` / `shapeColors` |
| `unknown_element` | an `offsets` key that is no element of the variant |
| `move_not_allowed` | `reason`: `not_allowed` (axis given: that axis only), `in_container` (move the container), `hidden` |
| `offset_out_of_range` | past the `range` (`axis`, `min`, `max`, `value`) |
| `invalid_value` | a malformed `rows` / `offsets` value |

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
