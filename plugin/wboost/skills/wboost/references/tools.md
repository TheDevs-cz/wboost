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
| `templates:export` | `export_variant` (implies `templates:read`) |
| `templates:design` | *no tools in this release* |
| `gallery:write` | *no tools in this release* |

`get_context` echoes the granted scopes back in `scopes[]`. If a tool the user
expects is missing from the list, their token lacks its scope — a new token is
the fix, not a retry.

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
the per-variant `grouped` flag is the one that matters.

A `projectId` the account cannot see reports the *same* failure as one that does
not exist — that is deliberate, not a bug to work around.

---

## `describe_variant(variantId)`

The only source of the ids `render_variant` / `export_variant` are keyed by.

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
    "containerId"          // nullable — which flow this text is in
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
    "id",              // ← the value an images map takes
    "name",            // stored file name = id + format extension, NOT a caption
    "url",
    "width", "height"  // null for SVG (vector) and for unreadable bytes
  }]
}
```

Use `width` / `height` against the slot's `frame` — a portrait photo in a
landscape slot is cropped, not letterboxed.

Deleted pictures sit in a trash bin for a few days and are **never** listed here;
they also cannot be used in a fill. Nothing in the gallery can be created,
deleted, moved or renamed from this connector.

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

## Fill vocabulary (identical for both image tools, and to the REST export)

### `inputs` — keyed by `inputs[].id`

| value | meaning |
|---|---|
| `"text"` | plain text |
| `{"value": "text"}` | the same, long form |
| `{"value": "text", "hide": false}` | explicit |
| `{"hide": true}` | blank a `hidable` input |
| `{"runs": [...], "lines": [...]}` | rich text; `richText` inputs only |

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
| `Input "…" exceeds max length of N characters.` | shorten the value; it is not truncated for you |
| `Input "…" overflows its container by N px` / `The texts of container … overflow` | shorten a text, hide a hidable one, or the designer raises `maxHeight` |
| `rich_text_not_allowed` / `lists_not_allowed` / `checkbox_lists_not_allowed` | the input does not have that capability — check `describe_variant` |
| `font_not_allowed` (with `Allowed values — …`) | use a family from that list verbatim |
| `invalid_color` | a colour must be a hex value |
| `…cannot be rotated / resized / moved` | the slot forbids it; drop the transform |
| `…is not available for this placeholder` | the picture is outside the slot's folders |
| `The image renderer is busy and did not answer in time.` | transient; retry the same call in a few seconds |
| `Rendering / Exporting this variant failed: …` | a broken design or asset, not your values — tell the user to open it in the editor |

Every "not found or not yours" wording is deliberately identical to "does not
exist", so these tools cannot be used to discover what other accounts hold.
