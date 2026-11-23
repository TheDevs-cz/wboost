# Changelog — wboost plugin and skill

The skill is a copy on your machine; the MCP tools update on the server with
every deploy. When the server is newer than your skill, Claude tells you once
per session how to update (see the README). Versions follow `x.y.z`: a new or
changed tool or parameter is at least a minor bump.

## 0.9.0 — 2026-11-24

**Multilingual calendars.**

New parameters and behaviour
- `manage_calendar` `create` / `configure` take `languages`: the extra languages
  of the printed month / weekday / week-number names — any of `sk`, `pl`, `de`,
  `en`; Czech is always printed first; `[]` = Czech only. Printed in the fixed
  order cs, sk, pl, de, en („LEDEN / STYCZEŃ / JANUARY 2027“); a week across two
  months gets a two-line month header. `get_calendar` and every
  `manage_calendar` answer return `languages`. Reason code `unknown_language`.
- `set_calendar_layout` `styles.cal.dayFormat` takes `auto` (the default of new
  layouts): full weekday names where they fit the row, else short ones.
- New page warnings `dayNames` (the weekday names do not fit the row) and
  `month` (a multilingual month header needs more room than its frame).

## 0.8.0 — 2026-11-24

**Inner shadow and glows for calendar pictures** (no new tool or parameter).

New parameters and behaviour
- The effect lists of the calendar pictures (`set_calendar_layout` styles
  `photo / logo / head.bg / bg / specialPhoto .effects`, content `pageEffects`)
  take three more kinds: `innerShadow` (Vnitřní stín — the keys of a
  `dropShadow`, inside the picture, `spread` = choke), `outerGlow` (Vnější
  záře) and `innerGlow` (Vnitřní záře) — `{on, blend, opacity, color, spread,
  size}`. Stacking bottom → top: drop shadows, outer glows, the picture,
  gradients, inner glows, inner shadows.

## 0.7.0 — 2026-11-24

**A page's own layer effects** (no new tool; a new content collection).

New parameters and behaviour
- `update_calendar_content` collection `pageEffects` (designer only, id = page
  key): `{photo, logo, head, bg}`, each `null` (the picture follows the
  layout's effect list) or a full effect list that REPLACES the style's list on
  that page (`[]` = no effects there). `photo` is the week photo, on the cover /
  intro / contacts page that page's photo. Listed in „Upraveno“ and removed by
  „Vrátit na styl“ like the other page overrides.

## 0.6.0 — 2026-11-24

**Layer effects on calendar pictures** (no new tool or parameter; the calendar
layout styles changed).

New parameters and behaviour
- `set_calendar_layout` `styles`: every picture of a calendar takes a list of
  Photoshop-like layer effects — `photo.effects` (the week photo),
  `logo.effects` (the logo, also on the cover and back), `head.bg.effects` /
  `bg.effects` (the header / background picture, a page's own one too) and
  `specialPhoto.effects` (the photos of the cover, intro and contacts pages).
  Kinds: `dropShadow` (Vržený stín: blend, opacity, color, light angle,
  distance, spread, size) and `gradient` (Překrytí přechodem, several per
  picture: blend, opacity, type linear / radial / reflected, angle, scale,
  centre x / y, stops). 16 blend modes — the ones a PDF keeps; a wrong kind,
  key or blend is refused with `invalid_effect`.
- `styles.photo.shadow` is gone: a stored shadow became the first drop shadow
  of `photo.effects`; sending `photo.shadow` is refused with a pointer to the
  new key.

## 0.5.4 — 2026-11-14

**Control characters are refused** (no new tool or parameter).

New parameters and behaviour
- `preview_design` / `set_design`: no string or key of a design document may
  hold a control character other than a line break or a tab (U+0000–U+001F,
  U+007F–U+009F). The call answers `rendered` / `saved: false` with issues of
  `stage: "parse"`, `code: "invalid_value"` naming the path and the character
  (e.g. `U+000B`, the vertical tab of text pasted from an office document).
  Such a design used to fail on save.
- `render_variant` / `export_variant` / `export_document` (and
  `document_history` "save_draft"): a fill value or key holding U+0000 is
  refused with `invalid_value`, naming the path — before, the export was
  delivered but missing from the export history. Other control characters in
  a fill are still accepted.

Skill
- `references/design.md` (the DSL in brief) and `references/filling.md` (text
  values, the refusal table) document both rules.

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
