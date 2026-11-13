# Changelog — wboost plugin and skill

The skill is a copy on your machine; the MCP tools update on the server with
every deploy. When the server is newer than your skill, Claude tells you once
per session how to update (see the README). Versions follow `x.y.z`: a new or
changed tool or parameter is at least a minor bump.

## 0.5.3 — 2026-11-13

**AI scan of a calendar page — smaller pictures, restore order** (`scan_design`;
no new tool or parameter).
- A calendar page must be at least **640 × 280 px** (longer × shorter side;
  the rule was "shorter side ≥ 300 px" and refused a 640 × 299 px page by one
  pixel). Templates stay at 600 px on the shorter side.
- Several scans applied to one calendar are restored in reverse order: only
  the scan applied last can be restored — `result` says so in
  `applied.restorable` / `applied.superseded`, an earlier scan answers
  `wrong_state`. Restoring the last one brings back the earlier scan's layout,
  and that scan's own `restore` works again.

## 0.5.2 — 2026-11-13

**AI scan — 14-day calendars** (`scan_design`; no new tool or parameter).
- `kind: "calendar"` takes a 14-day calendar (`type: "fortnight"`) too: the
  proposal is its two-sided layout — a week column on each side of the photo
  (the skill said 14-day calendars cannot be scanned).
- Scan a two-week page for a 14-day calendar and a one-week page for a 7-day
  one. When they disagree the result's `notes` say so
  (`fortnight_left_half`, `week_page_on_fortnight`) and only one week of the
  picture is used.

## 0.5.1 — 2026-11-13

**AI scan — corrected limits** (`scan_design`; no new tool or parameter).
- The shorter side of the picture must be at least 600 px for a template and
  at least **300 px for a calendar page** (the skill said 600 px for both).
- A PDF is accepted as the picture: its first page is scanned.
- A repeated `apply` of a calendar scan (`created: false`) reports in
  `unfilledPictures` how many of the scan's picture places are empty in the
  calendar now — it used to answer 0.

## 0.5.0 — 2026-11-13

**Letter spacing in the design DSL.**
- A `text` element takes `letterSpacing`: tracking in thousandths of an em,
  added after every character (`50` = 0.05 em; −200 … 1000; default 0). It is
  em-relative, so it survives a change of `size`.
- `get_design` returns it for a text that has it (no more `style_dropped`
  loss for letter spacing), the linter counts it when it estimates wrapped
  lines, and the AI scan's "refit" keeps it.

## 0.4.0 — 2026-11-13

**AI scan for desk calendars** (`scan_design`, scope `ai:scan`).
- `start` takes `kind: "calendar"` + `calendarId` (a 7-day calendar of the
  project): a picture of one calendar page becomes a proposed **layout** —
  where the photo, the day rows, the header, the month and the logo sit,
  colours, fonts chosen from the project. A project font is not required.
- `result` of a calendar scan: `layout`, `preset`, `recognised[]`,
  `featuresOffered[]`, `pictures[]`, `fonts[]`, `notExpressible[]`, `notes[]`.
- `apply` takes `features[]` (switches to turn on) and saves the layout into
  the calendar at once; the previous layout is kept until `restoreUntil`.
- `restore` (new behaviour, parameter `confirm`) puts the previous layout,
  layout choice and switches back; `layout_changed` without `confirm: true`
  when the layout was edited since.
- `status` / `list` rows carry `calendarId`.

## 0.3.0 — 2026-11-12

Everything the server learned since 0.2.0 (2026-08-07), now taught by the skill.

**New tools**
- Desk calendars (Kalendáře), scopes `calendars:read` / `calendars:write`:
  `list_calendars`, `get_calendar`, `render_calendar_page`, `manage_calendar`
  (create, configure, finish, client link, import a layout, PDF export
  `print` / `print_cmyk` / `approval` + `export_status`),
  `set_calendar_layout`, `upload_calendar_asset`, `update_calendar_content`.
- Multi-page documents: `export_document` (screen PDF, print PDF, PNG ZIP),
  `document_history` (list, reload, rename, pin, save a draft),
  `manage_document` (create, rename, set pages, add / duplicate a page, shared
  fields, print setup).
- `upload_font` — TTF / OTF / WOFF; a variable font becomes one face per cut.
- `set_print_setup` — bleed (0–10 mm) and crop marks of a standalone print variant.
- `create_template` — a new, empty standalone template with its first variant.
- `scan_design` (scope `ai:scan`, a paid per-project feature) — a picture of a
  finished design becomes an editable template proposal, then a template.

**New parameters and behaviour**
- `export_variant` `format: "pdf"` — the PDF/X-1a CMYK print PDF of an mm / cm variant.
- `render_variant` / `export_variant`: `shapeColors` (recolourable shapes),
  `rows` (repeatable rows), `offsets` (elements the designer lets the user move);
  hiding a whole container with `{"hide": true}`.
- `export_variant` / `export_document`: `allowUnfilled` — grey stand-in pictures
  left by an AI scan refuse the export unless the user wants them.
- `get_context` / `list_calendars`: `skillVersion` — the version check; the
  answer's `skill` says whether this skill is current and how to update.
- Design DSL: vector shapes with multi-stop gradients, `userColor`
  (recolourable shapes), `opacity` on texts and images, `mirror`,
  `userMove`, repeatable rows (`repeat`), image slots inside containers,
  `allowedFonts` / `fontChoice` / `allowedColors`, the `background` element
  (the "Pozadí" layer), dotted / dashed lines.

**Skill**
- Restructured: a short `SKILL.md` with the rules that make runs succeed, and
  references for filling, design, documents and print, calendars and every
  tool's fields; `references/tools.json` is generated from the server.
- Corrected: the print bleed is 0–10 mm (5 mm by default on a new print
  document) and crop marks sit 3 mm from the trim; every visible text is a
  fillable input (lock fixed copy); moving elements is live; calendars export
  CMYK too.
- New command `/wboost:calendar`; `/wboost:projects` lists calendars and
  documents; `/wboost:export` exports print PDFs.
- `name`/`description`/`license`/`metadata` frontmatter only, so the same
  folder uploads to claude.ai.

## 0.2.0 — 2026-08-07

OAuth sign-in (no token to paste) and the design tools: `upload_image`,
`preview_design`, `set_design`, the design DSL and the `acknowledgeLosses`
overwrite guard, next to the read and export tools `get_context`,
`find_templates`, `describe_variant`, `list_gallery`, `render_variant`,
`export_variant`.
