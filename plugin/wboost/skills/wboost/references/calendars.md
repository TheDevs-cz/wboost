# Desk calendars (Kalendáře) — workflow, rules and tool reference

Read this before any calendar work. Contents:

- [The module](#the-module) — scopes, design vs content, the loop
- [Design rules](#design-rules) · [Content rules](#content-rules) · [Examples](#examples)
- Tool reference: [`list_calendars`](#list_calendarsprojectid-skillversion) ·
  [`get_calendar`](#get_calendarcalendarid-historylimit) ·
  [`manage_calendar`](#manage_calendaraction-projectid-calendarid-name-year-type-features-print-preset-linkelements-fromcalendarid-regenerate-variant-exportid) ·
  [`set_calendar_layout`](#set_calendar_layoutcalendarid-preset-reset-boxes-order-styles) ·
  [`upload_calendar_asset`](#upload_calendar_assetcalendarid-kind-filebase64-filename) ·
  [`render_calendar_page`](#render_calendar_pagecalendarid-page-bleed-marks-sampletexts) ·
  [`update_calendar_content`](#update_calendar_contentcalendarid-operations) (collections, page texts, waste rules)

## The module

A separate module from templates, with its own scopes: `calendars:read`
(`list_calendars`, `get_calendar`, `render_calendar_page`) and
`calendars:write` (`manage_calendar`, `set_calendar_layout`,
`upload_calendar_asset`, `update_calendar_content`; implies read). Neither
implies a template scope and no template scope implies them — a calendar-only
connection cannot call `get_context`, which is why `list_calendars` works
without a project id and `get_calendar` carries the project's fonts and colours.

A desk calendar is a spiral-bound calendar a designer builds for a client
(typically a municipality): one page per week (`type: "week"`) or per two weeks
(`"fortnight"`). The system generates every page with its dates, Czech name
days and public holidays. It has two halves:

- the **design** — name, year, type, feature switches, print setup, and ONE
  layout (element boxes, stacking order, styles) shared by every week page.
  The designer's: `manage_calendar` and `set_calendar_layout`.
- the **content** — events, clubs, extra names, waste collection, page photos
  and captions, comments, shift tables, special pages, and the texts of the
  cover / intro / contacts / back pages (`pageContent`, `pageGroups`; their
  arrangement is `pageSetup`): `update_calendar_content`.

```
list_calendars                → every calendar this account can reach (or one project's)
manage_calendar "create"      → a new calendar (settings + optionally a preset in one call)
  └ get_calendar              → settings, layout + styles, pages, ALL content, assets, fonts
      ├ manage_calendar "configure"  → name, year, type, feature switches, print
      ├ set_calendar_layout          → preset, then boxes / order / styles patches
      ├ upload_calendar_asset        → a file → assetId (then reference it)
      ├ update_calendar_content      → add / replace / delete content items
      ├ render_calendar_page         → LOOK at a page after every change
      ├ manage_calendar "finish"     → done; "client_link" → the link for the client
      └ manage_calendar "export_pdf" → the whole calendar as a PDF; poll "export_status"
```

Authoring a calendar from nothing, in order: `create` (with `preset`) →
`configure` the feature switches the content needs → `set_calendar_layout`
styles → uploads + content → `render_calendar_page` on `w01`, `cover` and a

## Design rules

Design rules:

- **A calendar has no pages until it has a layout.** `set_calendar_layout`
  with a preset creates it — `photoLeft` / `photoRight` for 7 days, `twoSided`
  for 14. Styling before that is refused (`no_layout`).
- **Patch, do not rebuild.** `boxes` and `styles` name only what changes;
  nested objects merge. Read `get_calendar` `layout` first — it is the exact
  tree. An unknown key is refused by name (`unknown_style_key`,
  `unknown_box`), never ignored.
- **Units.** Boxes in mm from the top-left TRIM corner (the page is 310 × 145
  unless `page` says otherwise; a box may reach `bleedMm` past each edge).
  Type sizes and line widths in pt. Colours `#RRGGBB`. A font is a
  `get_calendar` `fonts[].family` string verbatim, or `null` for the built-in
  face.
- **Read `notes`.** Values outside their limits are brought into range, a
  preset switch mirrors rather than resets, a year change moves dated items —
  the answer says so in `notes[]` instead of failing.
- **Features gate printing.** Switch `events`, `waste`, `shifts`,
  `specialPages`, `qr`, `timeChange`, `seasons` on with `manage_calendar`
  `configure` before judging a render — content of an off feature is stored
  but invisible.
- **Assets belong to one calendar.** Upload with `upload_calendar_asset`, then
  reference the returned `assetId` (its `next` field says where). A gallery
  image id (`upload_image`, `list_gallery`) is NOT a calendar asset.
- **Look.** `render_calendar_page` draws what is stored with the real fonts.
  Call it after layout, style and content changes; act on `warnings[]`.
- **Designer only.** With `role: "client"` (project only shared with the
  account) every design call is refused with `designer_only`; content of the
  client collections and photo uploads still work.
- **PDF.** `manage_calendar` `export_pdf` with `variant: "print"` (every page
  with the bleed and crop marks the calendar's print settings switch on,
  photos at uploaded resolution, no binding-hole row — for the print shop),
  `"print_cmyk"` (the same pages in CMYK for offset print — PDF/X-4 with the
  Coated FOGRA39 output intent: neutral colours (R = G = B) and nearly
  neutral text in black ink only (`#000000` text is K 100),
  palette colours in the palette's own CMYK, the rest and the photos through
  the ICC profile; choose it when the print shop asks for CMYK — slower,
  about twice the size) or
  `"approval"` (trimmed pages as the finished calendar looks, small file — for
  e-mail). Text and shapes are vector; `print` and `approval`
  are RGB. It exports what is SAVED and runs in the background (about a second
  per page): poll `export_status` with `export.exportId` every few seconds
  until `status` is `done`, then give `export.downloadUrl` to the user (a
  secret link — the user only). `failed` carries a Czech `error`; ask again to
  retry. An unchanged calendar is answered at once with the existing file.
  Any role may export; it is recorded in the history.
- **The client link is a secret** — `manage_calendar` `client_link` returns
  it; give it to the user only. `regenerate: true` only when asked: the old
  link dies at once.


## Content rules

- **Whole items, never fields.** An operation is
  `{collection, id, value, label?}` and `value` is the complete item — every
  key, no extras — or `null` to delete. To change one field, copy the item from
  `get_calendar` `content` and send it back whole. Collections and shapes:
  [`update_calendar_content`](#update_calendar_contentcalendarid-operations) below.
- **Ids.** A new item gets an id you invent (16 random letters and digits).
  Page items (`pageCaptions`, `pagePhotos`, …) are keyed by the **page key**:
  `w05` = the page whose Monday is ISO week 5, `pre` / `post` = a page whose
  Monday belongs to the neighbouring ISO year, `cover`, `back`, `sp:<id>`.
  Page keys do not contain the year.
- **Dates** are `YYYY-MM-DD`, within 1 Dec of the previous year … 31 Jan of the
  next. Weekdays are `0` = Monday … `6` = Sunday.
- **Atomic and merged.** One wrong operation refuses the whole call and
  nothing is saved. Accepted items are merged one by one, so you never
  overwrite somebody else's change to a *different* item; re-sending a stored
  item is `unchanged`.
- **It is recorded.** Every real change adds a Czech line to the calendar's
  "Historie" under your role — the answer returns them in `recorded[]`.
- **Role.** `get_calendar` `role` is `designer` (the account may edit the
  project) or `client` (the project is only shared with it). A client may write
  only `writableCollections`; anything else is refused with `designer_only`.
- **Switched-off features are stored, not printed.** `get_calendar`
  `features` says what the calendar shows (`events`, `waste`, `shifts`, …). An
  event added while `events` is off is saved but invisible — switch the
  feature on (`manage_calendar` `configure`, designer) or say so instead of
  reporting success.
- **Cover, intro, contacts, back are filled like any content.** The cover,
  the back page and the generated special pages (`intro`, `contacts`, `year`,
  `waste`, `notes` — switched on through `specialPages` + the feature) carry
  texts in `pageContent` (id = `cover`, `back`, `sp:intro`, …; one shape per
  id) and the contact groups in `pageGroups` (heading + lines, a line is free
  text or label + value). Both are open to a client. How a page is arranged —
  `pageSetup` (cover layout, logo position, alignment, legends, the notes
  column) and a special page's `layout` — is the designer's. An intro `body`
  is plain text: empty line = new paragraph, `**bold**`, `*italic*`. Text that
  does not fit is set smaller, then reported as a Czech sentence in the
  `warnings[]` of `render_calendar_page` — always render `cover`, `sp:intro`, `sp:contacts`
  and `back` after filling them. Shapes, caps and a full example: below.
- **Comments are permanent**: insert only, never edited or deleted; the server
  sets author and time.
- **Not available:** deleting a calendar or an uploaded file; changing the page size; importing a layout from a
  layout *file* or from another project; extracting text from a .docx for a
  special page (send the text itself); the web editor's undo.


## Examples

```jsonc
// set_calendar_layout — a restyle: bigger day numbers in the brand colour, month in lower case
{
  "calendarId": "…",
  "styles": {
    "num": { "size": 30, "color": "#1F4E79" },
    "month": { "upper": false, "family": "Rubik (Rubik Bold)" },
    "lines": { "style": "dot" }
  },
  "boxes": { "logo": { "x": 170, "y": 30, "w": 22, "h": 22 }, "qr": { "hidden": true } }
}
```

```jsonc
// update_calendar_content — add an event and switch on bio-waste collection
{
  "calendarId": "…",
  "operations": [
    { "collection": "events", "id": "Ab3dEf6hIj9kLm2n", "label": "1. 6.",
      "value": { "date": "2027-06-01", "name": "Den dětí", "club": null, "icon": null } },
    { "collection": "wasteKinds", "id": "bio",
      "value": { "builtin": "bio", "name": "Bio odpad", "on": true, "place": "",
                 "mode": "rule", "weekdays": [2], "interval": "2e",
                 "from": "2027-04-01", "to": "2027-10-31", "dates": [], "pos": 1 } }
  ]
}
```

---

## `list_calendars(projectId?, skillVersion?)`

Scope `calendars:read`. Desk calendars (module Kalendáře) of one project — or,
without `projectId`, of every project the account can reach (by project name,
newest calendar first). `skillVersion` is the version check (see `SKILL.md`);
the answer then carries the same `skill` object as `get_context`. Only projects
that already hold a calendar appear — with no calendar yet, ask the user for the
project (or take it from `get_context` when the connection has `templates:read`).

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
`source_has_no_layout`, `invalid_variant`, `export_not_found`,
`invalid_design`, `model_unavailable`.

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
  second-week frames of a 14-day layout: `cal2`, `month2`, `wk2`). `hidden`
  only on `logo`, `month`, `wk`, `qr` — hiding `month` / `wk` hides both
  frames; the `*2` frames take no `hidden`.
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
with `…` and comes back as a Czech sentence in `warnings[]` of
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
