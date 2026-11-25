# Kalendáře — desk calendars (module `calendars`)

Built 2026-11 from a clickable prototype. The build-time documents live in
`docs/calendar/` and are **history**: `handoff.md` (product rules — still the
reference for *intent*), `architecture.md` (the design the phases were built
from: every default number, the full TypeScript shapes, the algorithms in
formula form, the Czech copy introduced by the build in §G.3),
`prototype-map.md`, `prototype/`, `reference/`. This file is the current
truth; when the two disagree, the code and this file win.

**What it is.** A project module: list page → five-step workspace (1 Typ,
2 Rozvržení, 3 Úpravy prvků, 4 Obsah, 5 Náhled) → client view. A designer
("Grafik") sets the look once on a sample page; every page of the year is
generated with Czech dates, ISO weeks, name days, public holidays, moon phases;
the client ("Klient") fills in photos, captions, waste collection, events,
names, comments. **One style, many pages**: pages carry content overrides
only, never their own layout.

**The one sentence to remember: the browser generates and renders, the server
stores and guards.** PHP never builds a page, knows no page size and no
feature default (where the server needs them — the MCP tools — it runs the
calendar's own JavaScript: § "Server-side model and headless render"). It validates shapes, enforces roles, merges content item by
item under a row lock, records history, stores files.

## Data model

Four tables (`Version20261111131800`; `calendar_export` came with
`Version20261111210000`), all `ON DELETE CASCADE` from `project`.

| Table / entity | Holds |
|---|---|
| `calendar` / `Entity/Calendar` | one row = one calendar: the **design** (`name`, `year`, `type` week\|fortnight, `page`, `print`, `features` JSONB, `languages` JSONB list, `layout_preset`, `layout` JSONB, `link_elements`, `step`, `finished`) + the **content** JSONB + `client_token` (64 hex, unique) + `revision` (+1 per save) + `layout_origin` (`scan` when the layout was taken from an AI scan, else null — NOT part of the design, § "Scanned layouts") |
| `calendar_asset` / `CalendarAsset` | every uploaded file: `kind` (`photo logo icon qr image page`), `path` = `calendars/{calendarId}/{assetId}.{ext}`, optional `preview_path` (`…-preview.webp`, only when the long edge > 1600 px), pixel `width`/`height` (null for SVG), embedded `description` (EXIF / XMP caption), uploader kind + user |
| `calendar_history` / `CalendarHistoryEntry` | "Historie": `author_kind` (designer\|client), `user_id` (SET NULL), a ready Czech sentence, `created_at` |
| `calendar_export` / `CalendarExport` | one PDF export job and its file: `variant` (print\|approval), `status` (queued\|running\|done\|failed), `progress_done` / `progress_total` (pages), `path` = `calendars/{calendarId}/exports/{40 hex}.pdf`, `size_bytes`, `error` (a Czech sentence), `calendar_revision` + `renderer_hash` (what the file was made from), requester kind + user, `created_at` / `updated_at` (heartbeat) / `finished_at` — § "PDF export" |

JSON refers to files by **asset id** only (never a path), so the storage
reference index needs no JSON walk — see `storage-inventory.md`.

**Design vs content** is the load-bearing split:

- *Design* is designer-only and saved as a **patch of top-level keys** (each
  present key replaces the stored value whole; last write wins per key).
- *Content* is `{ v: 1, <collection>: { <id>: item } }` and is saved as a list
  of **item operations** merged under a row lock — a designer and a client
  changing different items never overwrite each other.

`page`, `print`, `features` are `{}` until the first design save; the JS fills
its defaults. That is why the server needs no defaults and why **310 × 145 mm
exists in exactly one file, `assets/calendar/model/defaults.js`** (the
`dimensions` node suite greps `assets/calendar/` for the literals).

### Units

Everything stored and shown is in real units of the printed page: geometry in
**mm from the top-left trim corner** (rounded to 0.1 mm, `roundMm`), type and
line weights in **pt**, colours `#RRGGBB` upper case or `null`, dates
`YYYY-MM-DD`. Page size, binding band and hole pattern are *properties of the
calendar* (`design.page`), not constants — there is no UI for them yet, but
nothing may assume a size or ratio (scope-guard suite `dimensions`: a
420 × 200 page). Boxes may reach into the bleed (`B = print.bleed ?
print.bleedMm : 0`), never beyond `[-B, W+B] × [-B, H+B]`. Mm-valued editor
constants (min box 6 × 4, nudge 0.3 / 3, link tolerance 0.15, row paddings…)
are `K` and the control ranges `LIMITS`, both in `model/defaults.js`.

### Layout (`calendar.layout`) — defined by `model/layouts.js`, `model/normalize.js`

`{ v: 1, boxes, order, styles, layers }` — `layers` and their `u:<id>` boxes /
order entries are the own layers (§ "Own layers").

- `boxes`: `photo cap logo head month cal wk qr` (+ the **second frames**
  `cal2 month2 wk2` iff the type is `fortnight`) — `{ x, y, w, h, hidden? }`.
  `hidden` only on `logo month wk qr`; the caption's visibility is
  `features.captions` (one switch for step 1 and the Vrstvy eye, which is why
  the undo snapshot carries it).
- **Second frames** (`model/elements.js`: `SECOND_FRAMES`, `boxKeysOf`,
  `elementOfBox`, `frameOfBox`, `frameAlign`). On a 14-day page three elements
  have one frame per week column: Kalendárium (`cal` / `cal2`), and — since
  2026-11-11, as on the client's printed calendars — Měsíc (`month` /
  `month2`) and Číslo týdne (`wk` / `wk2`). The element is still ONE: one key
  in `order`, one row in Vrstvy, one entry in `styles`. A second frame is a
  box of its own for everything geometric (selection `{ el, frame: 0 | 1 }`,
  move, resize, nudge, snap target, link trigger and follower, bleed
  adaptation, auto-grow), shares every style with the first one and is drawn
  **horizontally mirrored**: `cal2` under `flipRowStyles()`, `month2` / `wk2`
  with `frameAlign(styles.<el>.align, 1)` (start ↔ end, middle stays) — so the
  stored `align` is the FIRST week's and both columns read towards the outer
  page edges. A second frame never carries `hidden`: the eye of the element is
  the flag of its first frame and hides both; `features.weekNumbers` hides
  both `wk` frames.
- `order`: element keys without `bg`, index 0 = top layer. Pozadí has no box
  and always paints first; both Kalendárium frames paint at the position of
  `cal`.
- `styles`: everything the step-3 editor changes — text styles per part
  (`num numSat numSun numHol day name hol ev time shift placeText`), `cal`
  (number side, icon grid, cell sizes, `bg` and `numBg` — „Pozadí čísel“, see the row
  layout below…), fills (`head.bg`, `month.bg`, `bg`), the layer effects of the
  pictures (`photo.effects`, `logo.effects`, `head.bg.effects`, `bg.effects`,
  `specialPhoto.effects` — § "Image effects"),
  `icons.order`, `moon`, `waste.kinds[id]` (colour / icon overrides — the
  icon of a kind, built-in or own, is set in step 3 › Ikony › Odpady and in
  the step-4 tool Odpady („Nahrát ikonu“ / „Původní“, designer only: it is
  design and an `icon` upload; a client sees it read-only. Any kind has one,
  an own kind added in step 4 too. An uploaded SVG / PNG prints as uploaded,
  never tinted by the kind's colour — K10a),
  `offsets[part]` (part nudges, mm), `ev.box` (the event box, **flow-anchored**:
  `x` from the text column's zone-side edge — so it needs no change when the
  layout is mirrored). A text style's `family` is exactly
  `Font::faceFamily()` (`"Rubik (Rubik Bold)"`) or `null` = built-in fallback;
  `Services/Font/RewriteFontReferences` rewrites `calendar.layout` on a font
  rename (and bumps `revision`), and `Query/GetFontUsage` counts the layout
  as a user of the face.
- Presets: `photoLeft`, `photoRight` (= `mirrorLayout(photoLeft)`), `twoSided`
  (the only 14-day layout: first week left, photo in the middle, second week
  right). Frame `cal2` is rendered from the *same* styles under
  `flipRowStyles()` — the very function that mirrors left ↔ right; stored
  styles are never changed by it.
- Default `twoSided` boxes in mm on the default page (`B` = bleed; every other
  page size scales them): `head` −B, −B, 310 + 2B, 27 + B · `cal` −B, 27,
  84 + B, 112 · `cal2` 226, 27, 84 + B, 112 · `photo` 84, 27, 142, 112 ·
  `month` 6, 12.5, 120, 14.5 · `month2` 184, 12.5, 120, 14.5 · `wk` 6, 139,
  72, 4.5 · `wk2` 232, 139, 72, 4.5 · `cap` 89, 139.5, 132, 4.5 · `logo` 143,
  15, 24, 24 (centred on x = 155, between the two month texts) · `qr` 199.5,
  113, 21, 21. Every second frame is the mirror image of its first one
  (`x' = W − x − w`). **Coinciding lines** (Propojit prvky depends on them,
  suites `layouts` / `link` assert them): `y = 27` head bottom / cal top /
  cal2 top / photo top / month bottom / month2 bottom; `y = 139` cal bottom /
  cal2 bottom / photo bottom / wk top / wk2 top; `x = 84` cal right / photo
  left; `x = 226` photo right / cal2 left; `x = 6` month left / wk left;
  `x = 304` month2 right / wk2 right. So a resize of either Kalendárium
  frame's top or bottom takes the month / week-number frames along exactly as
  in the 7-day layouts; the inner edges of `month` / `month2` / `wk` / `wk2`
  coincide with nothing and move alone.
- **Fully materialised**: `normalizeDocument()` runs on every bootstrap and
  every save response, deep-merges `defaultStyles(env)` under the stored styles
  (a new style key needs no migration), clamps to `LIMITS`, repairs `order` and
  the second frames. It is pure and idempotent (suite `normalize`). `migrate()`
  is the `switch (v)` hook for a future version.
- **Migration of 14-day layouts stored before 2026-11-11** (one `month`, one
  `wk`; `normalizeLayout` → `deriveSecondFrame`, also for layout files and
  „Nahrát layout“ from such a calendar). `layout.v` stays `1` — the server
  refuses any other version and an old tab must still be able to save — the
  marker is the missing key, per pair: `month2` (or `wk2`) absent while
  `month` (`wk`) is stored. Then, with `W` = page width and `A` = the stored
  box: (1) if `A` crosses the vertical centre line (`x < W/2 < x + w` — the
  "month across the whole header" case) it is first cut to the half that holds
  its centre, `K.SECOND_FRAME_GAP` (2 mm) short of the line, never narrower
  than `K.MIN_W`; (2) `M` = `A` mirrored, `x' = W − x − w`; (3) the first
  week's frame is whichever of `A`, `M` lies on the side of `cal` (by box
  centres; `cal` left of `cal2` → the left one). When that is `M`, the stored
  box becomes the SECOND frame and `styles.<el>.align` is flipped, so the text
  that was on the page stays where it was — this is the old default week
  number (bottom right, `align: end` → now `wk2`, stored `align: start`);
  (4) `hidden` stays on the first key. An old default layout migrates to
  exactly today's default. Nothing is written back until the designer changes
  the layout (opening an old calendar leaves it "Vše uloženo"); every pass
  after the first is a no-op. A layout that has the keys is never re-mirrored,
  however asymmetric.

The server validates the layout **structurally only**
(`Services/Calendar/CalendarDesignRules`): exact top-level keys (+ the own
layers of `CalendarLayerRules`), known box keys with finite numbers (`month2` / `wk2` only beside `cal2`, and like `cal2`
never with `hidden`), `order` without duplicates, `styles` any JSON with
strings ≤ 255, depth ≤ 8, ≤ 200 000 bytes, every asset id it mentions
(`CalendarLayoutAssets::ids`) uploaded to *this* calendar. It does not
understand a style.

### Content — defined by `model/content.js` and `Services/Calendar/CalendarContentRules`

`CalendarContentRules::validate()` **rebuilds every item from its known keys**
(exact key set, types, caps), so what is stored is the agreed shape and nothing
else. The two files must agree; `CalendarSeedContractTest` pins the seeded
waste kinds to the JS table.

| Collection | Item | Client may write |
|---|---|---|
| `events` | `{date, name ≤80, club: clubId\|null, icon: asset\|null}` | yes |
| `clubs` | `{code ≤6, name ≤60, pos}` | yes |
| `names` | `{date, name ≤40}` — extra names beside the name day | yes |
| `wasteKinds` | `{builtin, name, on, place, mode: rule\|dates, weekdays[0–6], interval: w\|2e\|2o\|4w\|4w2\|m, from, to, dates[], pos}` | yes |
| `wasteExtras` | `{kind, date}` — single collections added in step 5 | yes |
| `comments` | `{date, text ≤500, author, at}` | yes (insert only) |
| `pagePhotos` | `{asset, zoom 1–5, px, py 0–1, rotate?, flip?}` — the crop, § "Photo crop" | yes |
| `pageCaptions` | `{text ≤80}` | yes |
| `pageImages` | `{head, bg}` | no |
| `pageQr` | `{asset}` | no |
| `pageEffects` | `{photo, logo, head, bg}` — each `null` (the style's effect list) or an effect list that REPLACES it on this page (§ "Image effects") | no |
| `moonEdits` | `{op: add\|remove, date, phase 0–3}` | no |
| `shiftOps` | `{name, shifts[1–4]{letter, from, to}, crews[4], pos}` | no |
| `specialPages` | `{kind, on, layout, position, nextYear, name, asset, text ≤6000, fileName, pos}` | no |
| `pageSetup` | arrangement of a page, keyed by `cover` · `back` · `sp:intro` · `sp:contacts` — § "Content of the cover, back and special pages" | no |
| `pageContent` | texts + photo of a page, keyed by `cover` · `back` · `sp:intro` · `sp:contacts` · `sp:year` · `sp:waste` · `sp:notes` — one shape per key | yes |
| `pageGroups` | `{page: sp:contacts\|sp:waste, heading ≤80, lines[≤30]{label ≤40, value ≤200}, highlight, pos}` — ≤ 60 per calendar | yes |

Rules worth knowing:

- Ids are client-generated (`newId()`, 16 base-62 chars; server pattern
  `[A-Za-z0-9_-]{1,40}`, minus `CalendarContentRules::RESERVED_IDS` —
  `__proto__`, `constructor`, `toString`… — because the JavaScript keeps every
  collection in a plain object; the rule covers item ids, id references and
  the id inside a `sp:<id>` page key). The `page*` collections are keyed by **page key**
  (`pre`, `w01`…`w53`, `post`, `cover`, `back`, `sp:<id>`). The generated
  special pages use the fixed ids `intro contacts year waste notes`; a missing
  one means "default, off". `pageContent` / `pageSetup` are keyed by a **fixed
  set** of page keys (`CONTENT_PAGE_KEYS` / `SETUP_PAGE_KEYS`) and are
  deliberately NOT in `PAGE_KEYED` / `PAGE_COLLECTIONS`: they are the page's
  content, not an override of the style, so „Vrátit na styl“ and the
  „Upraveno“ dot ignore them.
- Dates must be real and inside `[year−1-12-01, year+1-01-31]`, checked against
  the year the calendar has **after** the design patch of the same request.
- `comments` are append-only; the server stamps `author` and `at`. Two
  deliberate exceptions: re-sending a stored comment is an *unchanged op* (a
  retried save must not fail), and a designer may move a comment's **date**
  (a year change shifts every dated item).
- `moonEdits` are edits **over the computed list**, not the list — the phases
  come from `generate/moon.js`; a year change clears them.
- A new calendar's content is `CalendarContentSeed::initial()`: four built-in
  waste kinds, switched off. Seeding in JS would need items that exist before
  they are saved, which breaks item-wise merging.
- Content of a switched-off feature is kept, just not drawn.
- Caps: item ≤ 16 kB (`specialPages` 32 kB), ≤ 400 waste dates, whole content
  ≤ 2 MB, ≤ 2000 ops and ≤ 2 MB per request.
- **Control characters** (`Services/Calendar/CalendarText`): PostgreSQL refuses
  `\u0000` in JSONB and NUL / invalid UTF-8 in text, which used to end a save
  as a 500 after the row lock. Every content text, the history `label`, the
  calendar name, uploaded file names and extracted document text are
  *stripped* of C0 controls (tab / LF / CR survive in content texts; labels
  and names are single-line) — so the stored item can differ from the sent
  one. Layout style strings are stored as sent and therefore *refused* (422).

### Content of the cover, back and special pages (2026-11-12)

The prototype drew these pages as wireframes; they are editable since
2026-11-12 (no design existed — every decision is listed in
`docs/calendar/report.md` §4a). Three collections, split by **who may write
them**:

| Collection | Key → item | Writer |
|---|---|---|
| `pageContent` | `cover` `{title, subtitle, note ≤120, photo}` · `sp:intro` `{heading, salutation, body ≤4000, signName, signRole, photo}` · `sp:contacts` `{heading, notesHeading ≤40, photo}` · `sp:year` / `sp:waste` `{heading, note ≤200}` · `sp:notes` `{heading}` · `back` `{heading, footer ≤200, name, address ≤300 in ≤ 6 lines, phone ≤60, email, web, imprint ≤200}` — one-line texts ≤ 80 unless noted; `photo` = `null \| {asset, zoom 1–5, px, py, rotate?, flip?}` (the crop model of `pagePhotos`) | designer + client |
| `pageGroups` | random id → `{page, heading, lines[{label, value}], highlight, pos}` — a group of the contacts page, or of the texts beside the waste grid | designer + client |
| `pageSetup` | `cover` `{layout 0–3, logo: none\|tl\|tc\|tr\|title, logoSize: s\|m\|l}` · `sp:intro` `{align: left\|justify}` · `sp:contacts` `{clubLegend, wasteLegend, notes}` · `back` `{logo}` | designer |

- **The client whitelist is per collection, on purpose.** The owner's intent:
  the client fills in local content instead of proofreading it — texts, the
  cover / portrait photo, contact groups. What a page LOOKS like (`pageSetup`)
  and which pages exist (`specialPages`: `on`, `layout`, `position`, order)
  stays with the designer. A field's collection says who owns it; there is no
  per-field rule inside an item — the client re-sends whole items, so such a
  rule would refuse an honest save whenever the designer changed a layout in
  between. A forged client op on `pageSetup` / `specialPages` is a 403 for the
  whole request; a `layout` key smuggled into a `pageContent` item is a 422
  (exact key sets).
- **A missing item = empty** (`pageContentOf` / `pageSetupOf` /
  `pageGroupsOf` in `model/content.js` fill the defaults and drop unknown
  keys). The UI removes an item that is back at its defaults. Nothing is
  dated, keys never hold a year or a type → a year change, a type change and a
  layout import leave all of it alone. No migration.
- **Empty title / heading = the default one** (`Kalendář {rok}`, the page name
  in capitals, `PŘEHLED ROKU {rok}`); a typed one is printed as typed.
- **`body` is plain text with light marks** (`parseRichText`): an empty line
  separates paragraphs, a single line break stays, `**bold**`, `*italic*`
  (they nest), `\*` is a literal asterisk; a mark without its partner in the
  same paragraph is text. Malformed input never fails and never loses a
  character (suite `special`). It is never HTML.
- One-line fields are stored single-line (`CalendarText::singleLine`); the
  body and the address keep their line breaks.
- History: `Upravil texty strany — {strana}` (or `Nahrál / Vyměnil / Posunul /
  Odebral fotku — {strana}` when the photo is all that changed), `Upravil
  vzhled strany — {strana}`, `Přidal / Upravil / Odebral skupinu „…“ —
  Důležité kontakty`, `Změnil pořadí skupin — …` (once per save).

**Rendering — `render/special.js`** (pure; draws in the 1000 × 468 design units
mapped onto the real page, so every page size works — the `special` suite
renders three):

- Fonts and colours come from the style: headings in the Hlavička text style
  (`paperHead` = its colour, or ink when it is white), body in the Kalendárium
  `name` style, group headings and the highlighted block in `ev.color`.
  Bold / italic are the **sibling cuts of the project font**
  (`specialFamilies(doc, fonts)` lists them; synthesised by the browser when
  the font has none). The workspace controller loads them before the first
  render and `CalendarRenderAssets::fontFaces` ships every cut of a used font
  to the headless page — a cut the layout does not name used to fall back to
  Chromium's default face in the PDF.
- **Nothing invented is printed.** An unfilled page shows grey bars / photo
  slots / a dashed logo while `placeholders !== 'none'` (editor, thumbnails,
  approval PDF) and nothing in the print PDF.
- **Auto-fit, then a warning — never a silent cut.** Intro: the largest of
  seven sizes that fits, then more columns (balanced, no widows / orphans,
  one-letter prepositions glued to the next word, justified lines spread by
  `word-spacing` up to half the type size). Groups: the largest of seven sizes
  at which whole groups fit the balanced columns (`spreadColumns`), a group is
  split between lines only when nothing else works; a heading never parts
  from its first line, the highlighted block never splits. One-line fields
  shrink to 62 %. What still does not fit ends with `…` and is reported by
  `specialPageIssues(doc, page, opts)` — the very layout run, so it cannot
  disagree with the picture — which `render/warnings.js` turns into a warning
  `{ kind: 'overflow', target: { el: 'page' } }` (thumbnail badge, chip,
  page panel, Upozornění, MCP).
- Cover layouts (`COVER_LAYOUTS`): 0 photo with a title band, 1 framed photo
  with the title below, 2 no photo, 3 photo only (a finished cover graphic).
  The photo of 0 / 3 covers the bleed box **including the binding band**; the
  logo (the style's `logo.asset`) is painted after the photo and the band.
- Contacts layouts: 0 two, 1 three, 2 four columns, 3 two columns + photo (the
  wireframe's "Tabulka" and "Seznam + fotka" are gone — a stored `layout` 1 / 2
  now means three / four columns). The club legend („Vysvětlivky“, ≤ 8, only
  while events are on) and the waste legend („Svoz odpadů“) are **generated
  groups** appended to the user's and flow with them (`data-legend`);
  `pageSetup` switches them. A waste kind with an own icon shows that icon
  instead of its colour swatch in „Svoz odpadů“ (it is what the day rows
  print); the waste plan's legend keeps the swatch (it keys the grid's
  colours) and puts the icon beside it (`data-waste-icon`). `notes` reserves the right 24–30 % for ruled
  lines with their heading in the header band.
- The waste plan's layout 1 („Mřížka + texty“) sets the `pageGroups` of
  `sp:waste` beside the grid; layout 0 has no room and ignores them.
- `specialPhotoBox(doc, page)` = the frame a page's photo is cropped into (mm,
  bleed included), `null` when the layout shows none — the crop control uses
  its aspect ratio.
- Debug / e2e attributes: `data-field="title|subtitle|note|heading|
  notes-heading|body|sign-name|sign-role|group-heading|line|line-label|
  line-value|name|address|reach|imprint"`, `g[data-group][data-column]`,
  `g[data-el="groups"][data-size][data-columns]`, `[data-highlight]`,
  `g[data-el="page-photo"|"page-logo"|"cover"|"ruled"|"side"]`,
  `g[data-field="body"][data-size][data-columns][data-overflow]`.

**Editing — `ui/pagecontent.js`** (`hasPageContent(page)`,
`mountPageContent(root, ctx, { page })`): one set of field sections on two
surfaces.

- **Step 4**: the page's right panel (`ui/pagepanel.js` mounts it once per page
  and re-attaches the same node on every rebuild, so a field keeps its focus
  when a warning appears while typing). The Speciální stránky panel keeps
  kind / layout / position / order and gets „Upravit obsah“, which opens the
  page; its layout thumbnails show the real content.
- **Step 5**, designer and client: „Upravit obsah stránky“
  (`[data-cal-action="page-content"]`, shown on pages that have content) opens
  the same sections in a wide popover (`openPopover({ wide: true })` —
  draggable on a desktop, a sheet docked to the bottom under 700 px so the
  page stays visible). It closes when another page is shown.
- Fields write on every keystroke (`putItem`, never undoable); the store event
  redraws the page (step 4 coalesces into one frame). The sections rebuild
  only when their structure changes (layout, a group or line added / removed /
  moved) — the signature in `signatureOf()`.
- A client gets no `pageSetup` section at all (cover layouts, logo, alignment,
  legends, notes switch) — a mirror of the server rule.
- Groups: collapsible cards, SortableJS (`forceFallback`) for groups and for
  the lines of a group, Enter in the last line adds a line, caps disable the
  add buttons. The photo field: upload tile (kind `photo`, so a client may
  use it), a crop preview dragged with the pointer, a zoom slider and
  „Upravit výřez“ — the photo editor inline under the field (§ "Photo crop").

### Image effects („Efekty“, 2026-11-24)

Photoshop-like layer effects on the calendar's pictures (K2; plan and the
measurements behind it: `docs/calendar/effects-plan.md`). Model
`model/effects.js`, renderer `render/effects.js`, control
`ui/controls/effects.js`.

- **Where**: an effect LIST in the layout styles = all pages, designer only,
  undoable, part of the design patch and of layout files:
  `photo.effects` (the week photo, also its empty frame on screen),
  `logo.effects` (the logo on week pages, the cover and the back),
  `head.bg.effects` / `bg.effects` (the header / background picture — the
  style's or a page's own `pageImages` one; not the colour or gradient layers
  of the fill), `specialPhoto.effects` (the photos of the cover, the intro and
  the contacts page; edited in the photo field of `ui/pagecontent.js`,
  designer only). QR codes and event icons have none, on purpose.
- **Effects**: `{kind: 'dropShadow', on, blend, opacity, color, angle, distance,
  spread, size}` (Vržený stín; `angle` is Photoshop's LIGHT angle — 120° casts
  down right, offset = (−cos a, sin a) × distance; σ = size × (1 − spread) / 2,
  the shape grown by spread × size) and `{kind: 'gradient', on, blend, opacity,
  type: linear | radial | reflected, angle, scale 10–150 %, x, y (centre in %
  of the picture), stops[2–8]}` (Překrytí přechodem, several allowed; real-mm
  `userSpaceOnUse` geometry — 45° is a real 45°, radial is a circle of
  scale × half the longer side; unlike the fills' unit-square gradients).
  Since P3: `innerShadow` (Vnitřní stín — the keys of a drop shadow, inside
  the picture, `spread` = Zúžení), `outerGlow` / `innerGlow` (Vnější / Vnitřní
  záře — `{on, blend, opacity, color, spread, size}`, no offset). On a
  rectangle an inner effect is an even-odd frame around the (offset, choked)
  hole, blurred and clipped by a vector clip path; on an outline it is the
  inverted alpha of the copy inside a filter.
  Ranges: `LIMITS.fx*` + `percent`; `normalizeEffects` clamps, drops unknown
  kinds, caps the list at 10. Stacking is by kind as in Photoshop (bottom →
  top: drop shadows, outer glows, THE PICTURE, gradient overlays, inner glows,
  inner shadows); within a kind the first effect is the
  top one; the panel lists overlays first and moves an effect only within its
  kind.
- **Blend modes**: only the 16 a PDF has natively (`BLEND_MODES`, Czech
  Photoshop names, Photoshop's groups); dissolve, linear burn / dodge, darker /
  lighter colour, vivid / linear / pin light, hard mix, subtract and divide are
  not offered — Chromium writes `plus-lighter` / `plus-darker` as `/BM /Normal`
  and the rest has no CSS / PDF form.
- **Print rules — load-bearing** (measured through Gotenberg + the CMYK pass):
  the picture itself never gets a `filter` (Chromium would rasterise the whole
  photo at 300 ppi, losslessly, and drop the uploaded JPEG) and never a `mask`
  (Skia writes a soft mask with a PostScript transfer function — the CMYK pass
  refuses it). Every effect is an element of its own: a JPEG picture's effects
  are drawn from its rectangle (a vector gradient rect, a blurred rect); a
  picture that may be transparent (`mayBeTransparent`: not a JPEG — the logo,
  PNG / WebP / SVG pictures) is painted once more inside a filter
  (`SourceAlpha` → morphology / blur / offset / flood, or a gradient
  `feImage` with a **data URI** — an `href="#id"` is misplaced by Chromium's
  print). The picture and its overlays are one `isolation:isolate` group, so
  an overlay blends with the picture only. In the PDF a filter's output is a
  300 ppi picture + soft mask; a gradient over a rectangle stays vector.
  `effects` node suite asserts "no `filter` / `mask` on any `<image>`".
- **Migration**: a layout stored before 2026-11-24 has `photo.shadow {on, blur,
  opacity, dx, dy, color}` and no `photo.effects`: `normalizeLayout` turns an
  ON shadow into the first drop shadow with `blend: normal` (draws what it
  drew), the key disappears; MCP `photo.shadow` is refused with a pointer to
  `effects`.
- **This page only** (P2, 2026-11-24): content collection `pageEffects`,
  page-keyed, designer only — `{photo, logo, head, bg}`, each `null` (= the
  style's list) or a list that REPLACES the style's list for that picture on
  that page (`[]` = none there). `photo` is the week photo, on the cover /
  intro / contacts page that page's photo (falls back to
  `specialPhoto.effects`). The renderer asks `effectsFor(doc, page, target)`
  everywhere. Edited in the step-4 page panel (`ui/page-effects.js`):
  „Podle stylu · N efektů“ + „Upravit jen pro tuto stranu“ (copies the list
  the page shows now) or the page's own editor + „Zpět na styl“; never
  undoable (content). Being page-keyed, „Upraveno“ and „Vrátit na styl“ cover
  it. Step 3 says under „Efekty“ how many pages have their own list. PHP
  mirror: `CalendarEffectRules` (exact keys per kind, blend / type lists,
  ranges) — `CalendarEffectContractTest` pins it to `model/effects.js` via
  `tests/Fixtures/calendar/dump-effects.mjs`. A page's own list is content, so
  Fotka vlevo ↔ vpravo does not mirror it (only the style's lists).
- **Mirroring** (Fotka vlevo ↔ vpravo, `mirrorLayout`): angles flip
  (`180 − a`), gradient centres `x → 100 − x` — a darkened corner stays with
  the logo.
- MCP: `set_calendar_layout` styles take the lists (validated in
  `resources/calendar/model.mjs` `checkEffects`: kinds, keys, blends, types,
  stops — `invalid_effect`; ranges clamped with a note).
- Performance (dev stack, 2026-11-24, e2e `effects`): a slider step on the
  stage redraws within the same two frames with or without effects (median
  16.7 ms); step 4 with 55 thumbnails mounts in < 0.1 s (thumbnails are lazy).

### Own layers („Vlastní vrstvy“, feedback K20 P1 + P2, 2026-11-24)

Pictures and shapes the designer adds on top of (or under) the built-in
elements. Plan, phases and Jan's decisions D1–D5 (all approved as
recommended): `docs/calendar/custom-layers-plan.md`. P1 (own layers on every
week page) and P2 (page sets, the cover / back / special pages, „Prázdná
strana“) are built; effects / gradient / crop / lock are P3, a static text
layer P4.

- **Where (D1 — design, never a per-page override)**: `layout.layers` =
  `{ "<id>": Layer }`, the geometry is the box `layout.boxes["u:<id>"]`
  (`{x, y, w, h, hidden?}` — every box-generic function takes it for free:
  drag, snap, clamp, nudge, bleed adaptation, `mirrorBoxes`, layout-file
  scaling), the z-position is the entry `"u:<id>"` in `layout.order` — one list
  with the built-ins. **Invariant** (normalize repairs, the server refuses):
  every layer id has exactly one `u:` box and one `u:` order entry, nothing
  else starts with `u:`; at most 50 layers; ids `[A-Za-z0-9_-]{1,40}` minus
  the `Object.prototype` names (`newId()` in the browser). `normalizeLayout`
  always materialises `layers` (`{}` for a calendar without any); a layout
  without them renders **byte for byte** as before (suite `layers`).
- **The item** (`model/layers.js`, `normalizeLayer` — pure, idempotent,
  clamps, drops unknown keys): `{kind: image | rect | ellipse | line, name ≤ 60
  ('' = „Obdélník 2“, numbered per kind from the bottom of the stack), pages:
  'weeks' | 'all' | [pageKey…] (D2: new layers 'weeks'; P1 never writes
  anything else), except: [pageKey…] (only beside a set), opacity 0–100,
  blend (the 16 PDF modes)}` + per kind: image `{asset, fit contain | cover,
  radius mm}`, rect `{fill {on, color, opacity}, stroke {on, color, width pt,
  style solid | dash | dot}, radius mm}` (a „Zaoblený obdélník“ is a rect with
  a radius, not a kind), ellipse `{fill, stroke}`, line `{stroke {color, width,
  style, cap butt | round}}` — a line is a BOX drawn through its middle
  (horizontal when `w ≥ h`), so it snaps and resizes like everything else; no
  diagonals. Ranges `LIMITS.layerRadius` (0–100 mm), `layerStroke` (0.1–20 pt),
  `percent`. `layerOnPage(layer, page)` is the page-set rule (a key without a
  page is simply not shown).
- **Page sets (P2)**: `pages` `'weeks'` (D2: the default of a new layer) |
  `'all'` (cover, front special pages, weeks, back special pages, back) | a
  list of page keys (`cover`, `w05`, `sp:<id>`… — „jen tato strana“ is a list
  of one); `except` (only beside a set) hides a layer on single pages — step
  4 „Skrýt zde“. Nothing is rewritten on a year / type change or when a
  special page goes: a key without a page draws nothing, the panel counts it
  („2 vybrané strany v kalendáři nejsou“). Helpers in `model/layers.js`:
  `pageLayerBands`, `isLayerUnder`, `pagesOfLayer`, `missingPageKeys`,
  `pageSetText`, `showLayerOnPage` (toggle a page in `except` / the list),
  `dropForeignPageKeys`. All of it is DESIGN: undoable, one layout save, never
  an „Upraveno“ override, untouched by „Vrátit na styl“.
- **Cover, back and special pages (P2)** have no element stack, so
  `renderPage` draws two bands around `renderCover` / `renderBack` /
  `renderSpecial`: a layer **below every built-in element** in `order`
  (`isLayerUnder`) is drawn UNDER the page's content, every other one OVER
  it, both bottom first; the binding holes stay on top. Without layers the
  markup is byte for byte as before (suite `layers`). Vrstvy shows the
  divider `[data-layer-under]` „pod obsahem ostatních stran“ under the lowest
  built-in row while any layer is on such a page.
- **„Prázdná strana“ (P2, D5)** — `specialPages` kind `blank`
  (`CalendarContentRules::SPECIAL_PAGE_KINDS`, `OWN_SPECIAL_KINDS` in
  `generate/pages.js` with `image` / `text`): no content of its own (an empty
  `<g data-el="special" data-kind="blank">`), subtitle „Vlastní strana“, made
  by „+“ › „Prázdná strana“ (`[data-special-add-blank]`, `addBlankPage`) at
  any K13 insertion point or the plus menu; its Speciální stránky card has
  „Vrstvy“ (`[data-special-layers]` → step 3 on that page) instead of
  „Vyměnit soubor“. Deleting it leaves its layers in Vrstvy (on no page).
  History: „Přidal vlastní stránku …“.
- **The stage page (P2, `editor/stage-page.js` + `ui/editor/stage-page.js`)**:
  `ui.stagePage` = a page key or null (the sample page). The stage bar's
  switcher `select[data-stage-page]` lists the sample, every page that is not
  a week page and the week pages some layer names in its list. On a week
  page everything is as before; on the cover / back / special pages
  `isElementVisible(design, key, page)` / `hitTest(…, page)` /
  `visibleBoxKeys(design, page)` see only own layers in their page set (and
  Pozadí) — a built-in selection is dropped, the hint says „jen vlastní
  vrstvy“, built-in rows are dimmed (`.away`) and choosing one takes the
  stage back to the sample. A selected own layer that is not on the page
  shown moves the stage to the first page it is on; the switcher drops a
  selection the chosen page does not draw. A layer added while a page is
  chosen gets `pages: [thatKey]`, on the sample `'weeks'`. `?krok=3&strana=`
  addresses the stage page (`state/url.js`); a stage page whose page went
  away falls back to the sample (`clampView`).
- **„Na stránkách“** in the layer panel: `select[data-prop="layer.pages"]` —
  Všechny týdenní strany / Všechny strany kalendáře / Vybrané strany… /
  Jen tato strana (the page on the stage); „Vybrané“ opens the checklist of
  every page (`[data-layer-page="<key>"]`); a set with `except` says on how
  many pages it is hidden + „Zobrazit všude“ (`layer-except-clear`). Rows of
  Vrstvy show the set when it is not the default (`[data-layer-pages]`).
- **Step 4 page panel (P2)**: „Vlastní vrstvy na této straně“
  (`[data-page-layers]`) lists the layers whose set holds the page (also
  those hidden right here) — `[data-page-layer-toggle]` Skrýt zde / Zobrazit
  zde, `[data-page-layer-edit]` Upravit (step 3, that page, the layer
  selected), `[data-cal-action="page-layer-add"]` „Přidat vrstvu jen na tuto
  stranu“ (step 3 on that page with the „Přidat“ menu open —
  `ui.layerAddOpen`). Step 4 is the designer's; step 5 and the client link
  only draw layers.
- **Render** (`render/layers.js`, dispatched from `renderWeekPage`'s `order`
  loop): `<g data-el="layer" data-layer="<id>" opacity mix-blend-mode>`;
  rect / ellipse / line are plain vector elements (`re` / Béziers in the
  PDF), strokes sit INSIDE the box, dashes are the Kalendárium lines' (`4w
  3w`, dots `0.01 2.6w` round-capped); a picture is one `<image>` with its own
  bytes, clipped (cover always, contain only for a radius) — never a `filter`
  or `mask`. An image layer without a picture shows the grey slot only with
  `placeholders: 'all'` (the editor) and prints nothing. **CMYK**: nothing new
  for the pass — a palette colour prints with the palette's CMYK to the digit,
  black as K only (`CalendarCmykExportGotenbergTest` reads both back). P1 draws
  layers on WEEK pages only; the cover / back / special pages (under / over
  bands) come with P2.
- **Editor rules**: `elements.js` knows `u:` keys (`boxKeysOf`,
  `isElementVisible` = the layer exists and its eye is open, `elementLabel(key,
  layout)`); `visibleBoxKeys` = the built-ins + the visible own layers (the
  snap targets: own layers snap to everything and everything snaps to them);
  **`linkBoxKeys` = the built-ins only — Propojit prvky never moves an own
  layer and an own layer never drags a neighbour** (`beginDrag({ linkKeys })`,
  `positionSection`). The hit test walks `order`, so a layer above the photo
  is hit first.
- **Layout operations**: Fotka vlevo ↔ vpravo mirrors the boxes (the layers
  follow the composition); a type change (`rebaseLayoutForType`) and a preset
  card over a scanned layout KEEP them (`carryLayers` — they are not part of a
  preset); „Nahrát layout“ (file / sibling) and MCP `import_layout` REPLACE
  them with the file's (the file is the design; its picture assets are copied
  as every layout asset — `CalendarLayoutAssets` walks `layers[*].asset`;
  P2: the source's `sp:<id>` page keys that are not special pages here are
  dropped — the generated ids `intro contacts year waste notes` and the
  target's own special pages stay; `dropForeignPageKeys` in `parseLayoutFile`
  (with the calendar's content) and `ImportCalendarLayout::dropForeignPageKeys`
  on the server, which is authoritative);
  **the AI scan's accept keeps them** (`replace_layout` with `keepLayers:
  "scan"` — `AcceptCalendarScanHandler`; since K21 a scan proposes layers of
  its own, ids `scan_…`, which come with it and replace an earlier scan's —
  `carryLayers(…, { scan: true })`; a scan's CANDIDATE is drawn without the
  calendar's layers, `dropLayers`), its restore brings back the stored layout
  with its own. An old browser tab (pre-K20 JS) drops `layers` on its next layout
  save — the accepted risk of every new layout key.
- **MCP** (skill 0.15.0): `set_calendar_layout` `layers` = `{"<id>": item |
  null}` — a new id (the agent's, `isLayerId`) adds a layer on top, centred,
  with the kind's defaults (shapes in the accent); an existing id is patched
  (`fill` / `stroke` merge one level, the kind cannot change); `null`
  removes. `resources/calendar/model.mjs` `checkLayer` refuses unknown keys
  by name, wrong enums / colours / types (`invalid_layer`, `invalid_color`),
  a picture that is not an asset of this calendar, `pages` other than
  `'weeks'` and a non-empty `except` (page sets are P2); removing an unknown
  id is `unknown_layer`, the 51st layer `too_many_layers`; ranges are clamped
  with a note. `boxes` take `u:<id>` (with `hidden`), `order` must list every
  element AND every own layer. A preset `reset` keeps the own layers
  (`replace_layout` / `replaceLayout(…, { keepLayers })`), `import_layout`
  replaces them. `get_calendar` returns them in `layout.layers`,
  `render_calendar_page` draws them (`CalendarDesignToolsTest`). Since skill
  0.16.0 (P2) `pages` takes `'all'` or a list of page keys (a key that is not
  a page now is kept with a note), `except` a list beside a set; the
  `specialPages` kind `blank` makes a page for layers.
- **UI — step 3 › Vrstvy** (`ui/editor/layers.js`, also in step 4 „Styl
  stránek“): „Přidat“ `[data-cal-action="layer-add"]` → menu
  `[data-layer-add="image|gallery|rect|rounded|ellipse|line"]` (Obrázek —
  nahrát = kind `image` upload, Z galerie only with the gallery modal). A new
  layer is centred on the page, ON TOP of the stack, selected, one undo step;
  sizes are page relative (`defaultLayerBox`: a shape ¼ of the short trim
  edge, a line ½ of the width, a picture ⅓ of the height with its own aspect);
  a shape takes `env.accent` (the project's first brand colour). A picture
  layer appears only when its upload / gallery copy answered — no empty
  picture layers from the UI. Rows `[data-layer="u:<id>"]` (kind icon, name or
  the numbered fallback, dimmed while hidden), the eye
  `[data-layer-eye="u:<id>"]`, the row menu `[data-layer-menu-of="u:<id>"]` →
  `[data-layer-action="rename|duplicate|delete"]` (rename in place:
  `input[data-layer-name]`, Enter / blur saves, Esc cancels; duplicate = right
  above, 3 mm right / down, selected); reorder with the built-ins by the same
  SortableJS list. Delete / Backspace on the stage deletes the selected own
  layer (`ui/editor/keys.js`; undoable, so no confirm — a built-in is never
  deleted). The properties (`ui/editor/props-layer.js`, mounted by
  `props.js` for `u:` selections; `[data-props="u:<id>"]`, controls
  `[data-prop="layer.<path>"]`): Název, Pozice a velikost, per kind Obrázek
  (upload / Z galerie / Odebrat, Přizpůsobit | Vyplnit, Zaoblení) · Výplň
  (on, colour, Krytí) + Obrys (on, colour, pt, Plná | Čárkovaná | Tečkovaná)
  + Zaoblení rohů (rect) · Barva, Tloušťka, Styl, Zakončení (line); Vrstva:
  Krytí vrstvy, Prolnutí; Duplikovat, Smazat vrstvu. Every write goes through
  `normalizeLayer`. e2e `user-layers.e2e.cjs` (P1), `layer-pages.e2e.cjs`
  (P2: blank page, stage pages, page sets, step 4, print PDF read back).
- **Server** (`CalendarDesignRules` + `CalendarLayerRules`, the PHP mirror of
  `model/layers.js` — `CalendarLayerContractTest` pins kinds, keys, enums,
  caps and ranges via `tests/Fixtures/calendar/dump-layers.mjs`): top-level
  `layers` allowed (an empty list `[]` = none — a map that went through PHP
  arrays), `u:<id>` boxes (`hidden` allowed) and order entries only for
  existing layers, the invariant, ≤ 50, per item: `kind` required, only that
  kind's keys, `#RRGGBB`, enums, ranges, page keys by pattern, a one-line
  clean name ≤ 60. Keys of later phases (effects, crop, gradient) are refused
  until the rules know them. **A client never writes a layer** (D3): any
  `design` key from a client is 403 (`CalendarClientTest` „own layers“); the
  client sees them drawn in step 5, `/k/{token}`, thumbnails and PDFs.

### Page list — adding and arranging pages (feedback K13, 2026-11-23)

The designer adds, moves and removes special pages right in the page list of
steps 4 and 5 (`opts.arrange` of `ui/pagelist.js`; never a client — the same
rule as `specialPages` on the server). The Speciální stránky panel and the
plus menu at the top of step 4 stay; all three edit the same items.

- **Insertion points** (`.cal-thins[data-slot="front:<n>|back:<n>"]`, „+“ =
  `[data-cal-action="page-insert"]`): after the cover, after every front
  special page, after the last week page, after every back special page —
  never between week pages (generated by date). The „+“ opens the chooser of
  the top plus menu (generated kinds not in the calendar yet, Nahrát grafiku,
  Text z Wordu); the page is switched on / created **at that slot** and
  selected (step 4: its page panel; step 5: the large page).
- **Placement is pure** — `state/special-slots.js`: a slot is `{ position,
  index }`, `index` counting the *shown* special pages of that section;
  `placeSpecial(doc, id, slot, item?)` puts the page next to its shown
  neighbour in the one `pos` order of every special page (front / back, on /
  off) and renumbers with `reorder()`, returning only the changed items (`{}`
  when the page already sits there); `slotInSequence(tokens, id)` reads a drop
  from the list order (after any week page = back). Suite `special-slots`.
  `placeSpecialPage(ctx, id, slot, item?)` (`ui/tools/special.js`) switches
  the feature on and writes the ops in one `applyOps` — one save like any
  content edit, never undoable.
- **Moving**: a special page's thumbnail sits in `.cal-thw[data-special-thumb]`
  with a drag handle `.cal-grip` (SortableJS, `forceFallback`; the insertion
  points are drop targets too — the only way into an empty section) and a menu
  `[data-cal-action="page-menu"]`: Posunout výš / níž (`[data-page-move="up|down"]`,
  inside the section), Přesunout za titulní stranu / před zadní stranu
  (`front|back`), Odebrat stránku (`[data-page-remove]`, `confirmDialog`; a
  generated page goes `on: false` and keeps its texts, an uploaded one is
  deleted). Cover, week pages and the back have no handle and no menu.
- The **shown page stays shown** (by page key) when pages are added, moved or
  removed before it — the views select by index; a removed shown page hands
  over to the page that took its place.
- History (`DescribeCalendarChange::specialPage`): `Přidal stránku „…“` /
  `Odebral stránku „…“` when a page is switched on / off (generated kinds by
  their kind name), `Přesunul stránku „…“ za titulní stranu` / `před zadní
  stranu` on a section change, `Upravil speciální stránky` (once per save) for
  order and layout.
- Not built: a special page **between week pages** (e.g. mid-year). It would
  need an anchor in the item (`position: 'week'` + the week page key it
  follows, valid across year / type changes like the page keys), a third
  section in `buildPages` + `special-slots.js`, a mirror in
  `CalendarContentRules`, and slots between week pages.

### Photo crop and „Upravit fotku“ (feedback K14, 2026-11-24)

One crop model for every photo — `pagePhotos[pageKey]` and
`pageContent.{cover|sp:intro|sp:contacts}.photo` — and one module for its
maths, `model/photo-crop.js` (pure; suite `photo-crop`). Plan and decisions:
`docs/calendar/photo-editor-plan.md`.

- `{ asset, zoom, px, py, rotate?, flip? }`: `zoom` 1–5 (`LIMITS.photoZoom`;
  PHP `CalendarContentRules::PHOTO_ZOOM_MAX`) × the cover scale; `px` / `py`
  the share of the free travel along the picture's own axes (0.5 = centred);
  `rotate` −180…180°; `flip: true` = mirrored horizontally about the
  picture's centre. **The optional keys are written only when not default**
  (`cropItem`), and the server refuses `flip: false` — so an unedited photo
  keeps the pre-K14 item, old tabs / MCP callers sending four keys stay valid,
  and an unedited photo renders byte-identical markup.
- Geometry: `E` = the frame's extents along the picture's axes
  (`w·|cos θ| + h·|sin θ|`, `w·|sin θ| + h·|cos θ|`), cover scale
  `k0 = max(Ex / width, Ey / height)`, drawn size = pixels × `k0 · zoom`, centre
  offset `((0.5 − px)(dw − Ex), (0.5 − py)(dh − Ey))` in the picture's axes,
  turned by θ onto the page. **The frame is always covered** at any angle /
  zoom / pan (a turned photo is drawn larger, and its dpi drops — `photoDpi`
  and the `dpi` warning include the turn; `portrait` reads the orientation as
  turned). For θ = 0 the expressions are literally the pre-K14 ones.
- Render (`render/svg.js` `photoParts`): the same `<image>` inside the frame's
  clip, plus `transform="translate(cx cy) rotate(θ) scale(-1 1)"` when turned
  or mirrored. **Never a filter**: a transform keeps the uploaded JPEG byte
  for byte in the print PDF (§ "PDF export"); colour adjustments are
  deliberately not offered.
- UI: `ui/photo-editor.js` — the stage shows the whole picture with the frame
  as a window (outside dimmed, the trim dashed where the frame runs into the
  bleed), zoom 100–500 % (about the frame centre; wheel / pinch about the
  pointer), „Otočit o 90°“ left / right, „Narovnat“ ±45°, „Zrcadlit“, „Na
  střed“, „Obnovit“, pixels · dpi (rose under `PHOTO_MIN_DPI`). **A draft**:
  nothing is written until „Hotovo“ (ONE `putItem` → one History line „Upravil
  fotku“ when the turn or the mirror changed, else „Posunul fotku“), Esc /
  „Zrušit“ / ✕ discard. Keys: arrows 0.3 / 3 mm, + / −, [ / ]. Surfaces:
  step 5 toolbar „Upravit fotku“ (`[data-photo-edit]`, a floating panel; the
  page behind previews the draft through `previewCrop`), step 4 page panel
  „Upravit“ (`[data-page-edit="photo"]`), „Fotky a popisky“ (a pencil per row,
  the editor INLINE under the row — a floating panel would sit under the
  Bootstrap modal and outside its focus trap), the content photo field
  („Upravit výřez“, inline). Designer and client alike (the crop is content).
  The quick zoom slider of step 5 / the content field and the drag on the page
  keep the turn and the mirror (`zoomTo`, `panBy`).

## Server

Namespace layout follows the app: one invokable controller per action in
`Controller/Calendar/`, messages + handlers in `Message|MessageHandler/Calendar/`,
values in `Value/Calendar/`, everything else in `Services/Calendar/`.

### Roles

| Role | Who | Resolved by |
|---|---|---|
| designer | `ProjectVoter::EDIT` (owner, admin) | `CalendarAccess::forUser` → `CalendarActor::designer` |
| client (member) | a logged-in user with only `VIEW` (project shared) — client mode *inside the app shell*, `/calendar/{id}/…` endpoints, never learns the token | `CalendarAccess::forUser` → `client` |
| client (link) | anonymous holder of `/k/{token}` | `CalendarAccess::forToken` → `client` |

`CalendarVoter` (`calendar_view` / `calendar_edit`) delegates to the project.
The client gets step 5 with four tools. **What the server refuses a client**
(the whole request, nothing applied, 403 `Tuto změnu může udělat jen grafik.`):
any `design` key, `finish`, any op on a collection outside
`CalendarContentRules::CLIENT_WRITABLE`; on uploads every kind but `photo`
(so a client can never store an SVG — a photo is allow-listed by its sniffed
MIME type before it is looked at any closer); and it gets no layout import, document
text, client link or delete. The UI hides the same things, but the server rule
is the one that counts (`CalendarClientTest`, e2e `client`).

### Save contract — `CalendarSaver` → `SaveCalendar` → `SaveCalendarHandler`

Request: multipart `_token` (CSRF id `calendar`) + `payload` (JSON string):
`{ "design"?: {…top-level keys}, "content"?: [{ "c", "id", "v": item|null, "label"? }], "finish"?: bool }`.
The payload must be a JSON object (`{}` is a valid save that only bumps
`revision`). `label` is display text for the history sentence (page title,
`1. 6.`) — the server cannot compute page titles; it is length-capped and
trusted for nothing else.

Handler, inside the Messenger transaction: `getForUpdate` (row lock,
`PESSIMISTIC_WRITE` — **this is what makes the item merge safe**) → role checks
→ validate the design patch → look up every referenced asset at once →
validate each op against the working copy → **only then** apply (a refused
request stores nothing) → history → `revision++`, `updatedAt`.

- Ops equal to what is stored are skipped silently (`Calendar::same` ignores
  key order — jsonb reorders keys — and `5` vs `5.0`).
- The collection key is kept when its last item goes (`{}`).
- History (`DescribeCalendarChange`, pure): one sentence per applied op, at
  most 30 per request + `… a dalších {n} změn`; some sentences coalesce (moon
  edits, generated special pages); a design patch that really changed
  something writes `Upravil vzhled a nastavení` unless the newest entry is that
  same sentence by the same role < 10 minutes old; a patch of only `step`
  writes nothing. `finish` → `Dokončil kalendář` the first time.
- Answer: `CalendarDocument::saved()` = `{ calendar, assets, savedAt }`.
  Errors are always `{ "error": <Czech text> }`: 403 CSRF / forbidden,
  422 `InvalidCalendarChange` (**its message is the user-facing text**),
  404 unknown calendar / token. `CalendarResponses::guard` unwraps
  `HandlerFailedException`.

- **The marker of a scanned layout** (`calendar.layout_origin`, since
  2026-11-13): a save that changes `preset` clears it; a payload with
  top-level `"layoutOrigin": null` clears it explicitly (a designer picked the
  preset card that equals the stored `preset` over a scanned layout — no
  design key changes then); any other value, or the key in a client's
  request, is refused. `SaveCalendar` also takes `setLayoutOrigin` /
  `layoutOrigin` and `designSentence` from server-side callers — the AI scan's
  accept and restore (`Převzal rozvržení ze skenu` / `Vrátil původní
  rozvržení`, always recorded, never coalesced). A DESIGNER's save answer
  carries `layoutOrigin` beside `calendar` and the designer's bootstrap
  `env.layoutOrigin`; a client's answer and bootstrap carry neither.

**Design is last-write-wins** (two designers, or two tabs). Only changed keys
are sent, which narrows the window; nothing detects the conflict. Content
merging is the only concurrency guarantee.

### Uploads — `CalendarAssetUploader` (both route families)

`_token`, `kind`, `file`. **10 MB decimal** like the rest of the app
(`UploadCalendarAssetController::MAX_SIZE = '10m'`, JS mirror
`CalendarDocument::MAX_FILE_SIZE` → `env.maxFileSize`; listed in
`gallery-and-uploads.md`). `ReadImageDescription` reads the embedded caption
from the **original** bytes, then `NormalizeImageFormat` (HEIC → JPEG, and a
sideways phone photo — EXIF orientation — rotated upright, so `width` /
`height`, the stored file and the preview all agree); SVG is
stored as is and refused for `photo`; a PDF is accepted for `kind = page` only
and rasterised (`RasterizePdfPage`, first page, Imagick + Ghostscript: the
page is measured at 4 dpi first, then rendered at 200 dpi or less so the long
edge stays ≤ 4000 px — a PDF may declare a 5 m page). Long edge > 1600 px → a
WebP preview (WebP, not JPEG, so a logo
keeps its transparency); the full file is used only on the large step-5 page
and for the dpi warning. Uploading never changes the calendar document — the
client references the id and saves.
**Every path that puts a photo on a week** — „Fotky a popisky“ (single row and
the multi-upload „Nahrát všechny fotky najednou“, input or drop), the step-4 page
panel (upload, „Z galerie“), step 5 („Nahrát / Vyměnit fotku“, „Z galerie“) and
the warning fix „Vyměnit fotku“ — goes through `setPagePhoto` (`ui/tools/photos.js`):
the caption comes from the embedded description; failing that, a week with no
caption gets one from the cleaned file name (`model/photo-caption.js`: the file
name without its extension, `_`/`-` and an ordering prefix go; a generic word is
a caption too — `Foto.jpg` → „Foto“; only camera / generated names such as
`IMG_1234`, `DSC_0001`, `PXL_…`, `Snímek obrazovky…`, `WhatsApp Image…` give
none). A caption that is already there is never replaced by a file name — it
stays the user's editable text (feedback K11, K18). In step 5 the caption pencil
of a week with a photo and no caption opens with the file-name suggestion
prefilled; only „Uložit“ writes it. **Not at render time**: an empty caption
prints nothing — the file name becomes a caption only by being written into
`pageCaptions` (so it can be edited or deleted, and an export never shows text
nobody saw). Photos from before K18 were filled once by
`app:calendar:backfill-photo-captions` (dry run by default; `--apply`,
`--calendar=<id>`): description, else the file name through the same JS rule
(`resources/calendar/photo-captions.mjs`, `CalendarModelBridge::captionsFromFileNames`),
only empty captions of calendars with captions on, written by
`BackfillCalendarPhotoCaptions` under the row lock with one history line
„Doplnil popisky z názvů souborů (N)“.
Rows of „Fotky a popisky“ name the file
(`asset.name`), the pixel size and the low-dpi hint (`render/warnings.js`
`lowPhotoDpi` / `PHOTO_MIN_DPI`, shared with the page warnings).
Assets are never deleted individually (replaced photos stay until the calendar
goes — they count as *referenced* in the storage report).

**„Z galerie“ — a picture of the project's gallery (feedback K14 P2,
2026-11-24).** The designer's door only: `fileId` instead of `file`
(`CalendarAssetUploader::storeGalleryFile`) — the gallery file must be a
`ProjectImage` of the calendar's own project, not in the Koš (else 422
`GALLERY_PICTURE_GONE`); its **bytes are copied** through the very rules of an
upload (`storeFile`: size, sniffing, pixel cap, normalisation, preview, embedded
caption; the gallery's file name becomes `asset.name`), so a later Koš / purge /
rename in the gallery changes nothing in the calendar and the gallery does not
change. The client link answers 403 (`/k/{token}` is no project member). UI:
`ui/gallery-pick.js` (`hasGallery`, `pickGalleryAsset`) opens the
`Project:ImageGallery` modal that `workspace.html.twig` renders for the
designer's workspace (lazy — the component renders when the modal first opens;
shared with the calendar scan, `#calScanGalleryModal`) — „Z galerie“ in the
step-5 photo toolbar (`[data-photo-gallery]`), the step-4 page panel
(`[data-page-gallery="photo"]`) and the content photo field
(`[data-content-gallery]`). Not in „Fotky a popisky“: that tool is a Bootstrap
modal itself, and a second modal over it is not supported.

What an upload is refused for, in order (all 422 `{ error }` unless noted):
client storage full → a `photo` whose **sniffed** MIME type is not in
`CalendarAssetUploader::PHOTO_MIME_TYPES` (JPEG, PNG, WebP + the phone formats
HEIC / HEIF / AVIF, which are stored as JPEG) — so an SVG however padded or
named, XML, GIF, TIFF and everything ImageMagick would pass to Inkscape or
Ghostscript is refused before anything decodes → not an image (Symfony
`Image`) → more than `ImageDecodeLimits::MAX_PIXELS` (60 MP) **by the
header**, before any decode → unreadable. A designer's SVG (logo, icon) is stored **unsanitised** and served
as `image/svg+xml` from the storage origin — the same policy as the gallery
and the manuals (the app has no SVG sanitizer); the JavaScript only ever
places it through `<image>`, which runs no script.

`ExtractDocumentText` (.docx / .txt → text of a special page) checks the size
the zip **declares** for `word/document.xml` (≤ 5 MB) before inflating it.

### Import from a spreadsheet — `CalendarTableReader` (K10b, 2026-11-24)

„Nahrát z Excelu“ in Odpady and Akce a spolky; plan and decisions in
`docs/calendar/excel-import-plan.md`. The server only READS the table; the
browser maps, previews and writes ordinary content ops (no new collection,
no new content contract, nothing stored).

- `POST …/import/table` (`_token`, `file`; both route families — importing is
  filling content, which a client may do) → `{ sheets: [{ name, rows }],
  truncated, name }`. `CalendarImportHttp::table` → `CalendarTableReader`
  (PhpSpreadsheet, `phpoffice/phpspreadsheet`): `.xlsx` / `.xls` / `.ods` /
  `.csv` `.tsv` `.txt` by extension, else by content (binary formats only).
  CSV: UTF-8 (± BOM) when valid, else **Windows-1250** (Czech Excel's CSV);
  separator = the most frequent of `;` TAB `,` in the first 20 lines (`;`
  wins a tie). A cell is a string: a date cell (number + date format) →
  ISO `YYYY-MM-DD`; other numbers in their number format; a formula → its
  cached value (never evaluated); ≤ 300 chars, single-line. Row index = sheet
  row − 1 (empty rows kept as `[]`, so errors name the file's row numbers);
  ≤ 10 sheets × 2000 rows × 60 columns, more → cut + `truncated: true`.
  Not a table → 422 `CalendarTableReader::UNREADABLE`, no data → `EMPTY`.
  10 MB decimal like every upload (`CalendarDocument::MAX_FILE_SIZE`). The
  file is read from PHP's upload temp file — no `calendar_asset`, no storage,
  no history, `revision` unchanged. The client link pays one upload of BOTH
  upload budgets per table.
- `GET …/import/template/{waste|events}` „Stáhnout vzor“ →
  `CalendarImportTemplate`: `odpady-vzor-{rok}.xlsx` (`Datum · Druh odpadu ·
  Místo svozu`) / `akce-vzor-{rok}.xlsx` (`Datum · Název akce · Zkratka spolku
  · Název spolku`), example rows as real date cells of the calendar's year,
  the calendar's kinds / club codes as a drop-down from the hidden sheet
  „Seznamy“ (other text allowed), a sheet „Návod“. Buffered response.
- Bootstrap: `env.urls.importTable`, `importTemplateWaste`,
  `importTemplateEvents` for every role.
- Fixtures: `tests/Fixtures/calendar/import/` (built by its `generate.php`,
  committed): realistic municipal tables of every shape and format — no real
  municipal file was available; try real ones when they come.
- **Recognition and the write — `state/import.js` (outside `model/` on purpose: the export fingerprint)** (pure, node suite
  `import`, input = `tests/Fixtures/calendar/import/tables.json`, which
  `CalendarTableReaderTest` pins to the reader's output — re-run
  `generate.php` after a reader change). Dates: ISO, `1. 3. 2027`,
  `01.03.2027`, `1.3.`, `1/3/2027`, `1. března`, `1. 3. 27`, Excel serials
  30000–80000; no year = the calendar's; impossible dates (30. 2.) refused.
  Waste shapes (`detectWasteShape`, overridable in the review): `rows` (A:
  the date column + the text column filled on its rows = kind, the next text
  column = place; rows before the first date are title / header), `columns`
  (B: ≥ 2 date columns, kind = the header cell above), `months` (C: a column
  of ≥ 3 month names, cells `7., 21.` / `3. a 17.`), `dates` (D: one column;
  its title row names the kind). Kinds are matched by name, then by the
  built-in aliases (`builtinOf`: PLASTY, směsný, Bioodpad, … — `new:<builtin>`
  when the built-in is not in the calendar yet), else „Nový druh: <název>“;
  D without a recognisable title goes to the kind open in the tool.
  `planWaste`: per target kind REPLACE (default) or ADD (union) its dates,
  `mode: 'dates'`, `on: true`, rule fields kept, place filled from the file
  when the kind has none and the file has one place for it; > 400 dates →
  that kind is refused, not written. Out-of-range / unreadable rows are
  listed with the file's row numbers, never written.
- **UI — `ui/tools/import.js`**: `importBar` on top of the tool („Nahrát z
  Excelu“ · „xlsx, xls, ods nebo csv“ · „Stáhnout vzor“), the answer opens
  `mountImportReview` IN PLACE of the tool body (`.cal-wt[hidden]`): sheet,
  shape, per group a target select, „Nahradit termíny“ / „Přidat k termínům“,
  „Po načtení“ per target kind (a switched-on rule kind is flagged „místo
  pravidelného svozu“), skipped rows in a `<details>`. Nothing is written
  before „Načíst N termínů“ → ONE `store.applyOps` (content ops are not
  undoable — the preview is the safety) → toast, the first imported kind is
  selected. Hotovo / Esc / Zrušit discard the review. e2e
  `content-tools` (K10b checks, 390 px fit).
- **Events** (Akce a spolky, same bar and review): columns by header words
  (`detectEventColumns`: datum / název·akce / pořadatel·spolek / zkratka;
  without a header the text column with most different values is the name,
  the next the organiser) — changeable in the review. A multi-day `12.–14. 6.`
  goes on its FIRST day (noted); extra columns (čas, místo) are ignored; the
  per-event icon is never imported. Organisers: one group per code column /
  all-caps first word (`SDH Testov` and `SDH` are one) / name, matched to a
  club by code, then name (case + accents ignored), else „Nový spolek“ with
  a proposed code (its own, the caps word, else initials; unique, ≤ 6,
  editable) or „Bez pořadatele“. `planEvents` only ADDS; an exact duplicate
  (date + normalised name + club) of a stored event or of an earlier row is
  skipped and listed; a new club no imported event uses is not created.
  Ops carry the usual `czDate` labels. Client link: e2e `client-tools`.

### Budgets of the public client link

The link needs no account, so everything behind `/k/{token}` is bounded.
Limiters live in `config/packages/rate_limiter.php` (one Redis pool,
`cache.calendar_client`; in-memory storage in tests), are consumed by hand in
the thin controllers and answer 429 + `Retry-After` in the `{ error }`
shape (`CalendarResponses::overBudget`). An unknown token is a 404 before
anything is counted. The `/calendar/{id}/…` twins have no budget.

| What | Limit | Where |
|---|---|---|
| uploads per link + address | 200 / hour | `calendar_client_upload` |
| uploads per link, any address | 500 / hour | `calendar_client_upload_link` |
| saves per link | 600 / hour | `calendar_client_save` |
| history reads per link | 600 / hour | `calendar_client_history` |
| NEW PDF renders per link (a request the existing file answers is free) | 10 / hour | `calendar_client_export` |
| export status polls + downloads per link | 3000 / hour | `calendar_client_export_read` |
| stored bytes of all **client** uploads of one calendar (link holders + read-only members; main files, previews not counted) | 2 GB → 422 | `CalendarAssetUploader::MAX_CLIENT_BYTES` |
| history lines kept per calendar | newest 2000 | `CalendarHistoryWriter::MAX_ENTRIES`, pruned by `SaveCalendarHandler` / `RecordCalendarHistoryHandler` after they record |
| pixels of an uploaded picture | 60 MP | `ImageDecodeLimits::MAX_PIXELS` |

The byte cap is checked before the upload, without a lock — concurrent
uploads can overshoot it by a few files. Since assets are never deleted, a
calendar that hits it stays full for clients; the designer still uploads.

**Layout import** (`ImportCalendarLayoutController`, designer): from a sibling
calendar of the same project and type, or from a layout file
(`exportLayoutFile` / `parseLayoutFile`). `ImportCalendarLayout::plan` rewrites
asset ids and emits one `CopyCalendarAsset` per picture; the calendar row is
**not** modified — the client applies the layout as one undo step and saves. A
file from another project loads without pictures.

### Routes

| Route | Path | Guard |
|---|---|---|
| `calendars` | GET `/project/{projectId}/calendars` | `ProjectVoter::VIEW` + `#[RequiresProjectModule(Calendars)]` |
| `calendar_create` | POST `/project/{projectId}/calendars/create` | `ProjectVoter::EDIT` + CSRF |
| `calendar_workspace` | GET `/calendar/{calendarId}` | `CalendarVoter::VIEW` (mode from `CalendarAccess`) |
| `calendar_save` / `calendar_asset_upload` / `calendar_history` / `calendar_card` | POST `…/save`, POST `…/asset`, GET `…/history`, GET `…/card` | `CalendarVoter::VIEW` |
| `calendar_export_current` / `_request` / `_status` / `_download` | GET `…/export`, POST `…/export`, GET `…/export/{exportId}`, GET `…/export/{exportId}/download` | `CalendarVoter::VIEW` — whoever opens a calendar may export it |
| `calendar_import_table` / `calendar_import_template` (K10b) | POST `…/import/table`, GET `…/import/template/{waste\|events}` | `CalendarVoter::VIEW` |
| `calendar_document_text` / `calendar_layout_import` / `calendar_client_link` / `calendar_delete` | POST `…/document-text`, `…/layout-import`, `…/client-link`, `…/delete` | `CalendarVoter::EDIT` |
| `calendar_scan` | POST `…/scan/{scanId}/{accept\|restore\|picture}` | `CalendarVoter::EDIT` + the AI scan's USE; a scan of another calendar is 404 — § "Scanned layouts". **No `/k/{token}` twin** |
| `calendar_client` (+ `_save`, `_asset`, `_history`, `_export_current` / `_export_request` / `_export_status` / `_export_download`, `_import_table` / `_import_template` — K10b) | `/k/{token}` (+ `/save`, `/asset`, `/history`, `/export…`, `/import/table`, `/import/template/{kind}`) | public; the token is the credential |

`{token}` = `[a-f0-9]{64}`, unknown → 404; regenerating it
(`RegenerateCalendarClientLink`) kills the old URL. The token addresses the
calendar by itself — never combine it with the uuid. The `/k/…` routes have
their own thin controllers (a public route cannot share the `#[IsGranted]` of
its twin); both families call the same three services (`CalendarSaver`,
`CalendarAssetUploader`, `CalendarHistoryList`). `security.php`'s public-path
regex carries `k/[a-f0-9]{64}`.

**The list page embeds no calendar document.** Next to each card there are
only its addresses (`{ urls: { workspace, history, card } }`); the
`calendar-list` controller fetches `calendar_card` (`{ calendar, assets }` —
`CalendarDocument::card`, never the client token) when the card scrolls into
view and then fills the summary line and the thumbnail. The page's size and
query count therefore do not depend on what the calendars hold (it used to
carry every document — up to 2 MB each — and run one assets query per card).
`data-cal-ready="1"` on the list = every card in view at load is complete.

Templates: `templates/calendar/list.html.twig` (works without JS: create and
delete are plain forms; the summary then is the server's short `Týdenní ·
2027`), `workspace.html.twig` (empty `page_title_row`,
`inner-wide`, forced rail — `calendar_workspace` is in `base.html.twig`'s
`shell_force_rail` list), `client.html.twig` (standalone, `noindex`),
`_not_ready.html.twig` (a client before a layout exists: no controller starts).
All inner DOM of the workspace and the client page is built by JS from one
`<script type="application/json">` bootstrap = `CalendarDocument::forCalendar`.

### Storage and font registrations (done; keep in sync)

`BuildStorageReferenceIndex` (`calendar_asset.path`, `.preview_path`,
`calendar_export.path`, `calendars` in `PATH_PREFIXES`), `ResolveStorageOwnerByPath`,
`StorageCategory::Calendar`, `CollectProjectStoragePaths`
(`calendars/{calendarId}` per calendar), `DeleteCalendarHandler` (collects the
directory first, removes the row, then `deleteDirectory`),
`RewriteFontReferences` (`replaceInCalendars`: rewrites `calendar.layout`,
`revision` + 1, fresh `updated_at`), `GetFontUsage` (calendar layouts count on
the Fonty page — `templates-text-inputs.md`). A new path-storing column
needs all the storage ones — `storage-inventory.md`. Export files live INSIDE
the calendar's directory (`calendars/{id}/exports/`), so the owner lookup, the
project path collection and the calendar deletion cover them as they are.

### Module switch

Kalendáře launched publicly on 2026-11-11 (the build-time admin-only preview
gate `ProjectModule::isInPreview()` is gone). It is an ordinary opt-in module,
not in `ProjectModule::defaults()`. Nothing beyond the list page is gated: `#[RequiresProjectModule]` sits on
the list page only — the workspace, the `/k/{token}` link and the MCP tools
follow the voters alone, like every other module's item pages (see
`app-shell.md`).

## Front end — `assets/calendar/`

Plain ES modules, relative imports with `.js`, no bundler, no new dependency
(SortableJS from the importmap). Two lazy Stimulus controllers
(`calendar_list_controller.js`, `calendar_workspace_controller.js`) own
everything else. ~15 000 lines.

| Dir | Role | Purity |
|---|---|---|
| `model/` | defaults + limits (`defaults.js`), element / part vocabulary (`elements.js`), fonts, presets + mirroring (`layouts.js`), `normalizeDocument` + layout files (`normalize.js`), content collections (`content.js`) | pure |
| `generate/` | dates (UTC, ISO weeks, Easter), Czech tables (name days, holidays, plurals), page list + page keys (`pages.js`), moon (Meeus ch. 49, Czech civil date), DST + seasons (`time.js`: equinoxes / solstices by Meeus ch. 27, Czech civil date — never a fixed date), waste rules, shifts, `dayInfo` (`day.js`) | pure |
| `render/` | `renderPage(doc, page, opts) → SVG string` (`page.js`), week geometry (`week-layout.js`) and markup (`week.js`), cover / back / special pages (`special.js`), built-in icons, `measure.js`, `warnings.js` | pure except the two marked functions in `measure.js` |
| `editor/` | geometry, hit test, snap, link, drag sessions, part bars, bleed adaptation, auto-grow, zoom | pure |
| `state/` | `store.js`, `undo.js`, `payload.js`, `url.js` (the address, see below) (pure, node-tested) and `api.js` (fetch) | pure except `api.js` |
| `ui/` | DOM modules: `dom.js` kit, `topbar`, `step1`, `step2`, `step4`, `step5` (+ overlay, popover), `pagelist`, `pagepanel`, `pagecontent`, `thumb`, `warnings`, `history`, `client-link`, `editor/*` (stage, overlay, parts overlay, keys, layers, props, props-kal), `controls/*`, `tools/*` | DOM |

**Rule: nothing outside `ui/` and `state/api.js` touches `window`, `document`,
`fetch` or timers** (exceptions: `createCanvasMeasurer()` /
`ensureFontsLoaded()`, and `headless/render.js` — the Gotenberg entry, which
is handed its root element and waits for pictures with a timer). Everything else must import cleanly in node — that is
what the node suites rely on. Pure functions never mutate their arguments.
Months are 1-based, weekday `0 = Monday`, dates go through `generate/dates.js`
(never `new Date(y, m, d)` local time). All user text is escaped through
`esc()`. No native `alert` / `confirm` / `prompt` (they freeze browser
automation) — `confirmDialog()` / `choiceDialog()`.

The exact contract of each `ui/` file is the comment block at its top.

### Load-bearing contracts

- **Renderer purity + injectable measurer.** The page SVG (viewBox in mm,
  `font-size` in mm) contains only the page — never editor chrome. Text is
  measured through a `Measurer { width(text, font) }`: canvas in the browser,
  a deterministic estimator in node. The layout never measures the DOM. Widths
  are wrong before the project fonts load, so `ensureFontsLoaded()` gates the
  first stage render and thumbnails re-render on `document.fonts.ready`.
  `idPrefix` must be unique per rendered instance (gradient / clip / filter
  ids) — `renderThumb` makes a fresh one per call.
- **Geometry object, not SVG hit-testing.** `layoutWeekPage()` returns a
  `WeekGeometry` (frames, rows, part rects, event boxes, icon cells). The
  selection frame, handles, guides, part highlights, part bars and the step-5
  buttons are an **HTML overlay positioned from that object × scale** — never
  from `getBoundingClientRect` of SVG nodes; the pointer is converted to mm and
  goes through `hitTest` / `partAt`. `data-el`, `data-part`, `data-date` in the
  SVG are for debugging and e2e only.
- **Store change API** (`state/store.js`; pure, the `payload` suite runs it
  against a fake api). `getState()` is live and read-only; every change
  replaces the touched objects, so identity comparison detects change. Events
  (`layout {live}`, `design {keys}`, `content`, `ui`, `status`, `undo`,
  `replace`) are emitted synchronously after the state is consistent.
  One undoable step = `change(fn)`; a drag / slider =
  `beginChange()` → `updateLive(fn)`… → `commitChange()` (or `cancelChange()`)
  — one undo entry however many events. Content edits (`putItem`, `removeItem`,
  `applyOps`) are **never** on the undo stack. `setUi` is view state, never
  saved. Layout changes throw in client mode (a mirror of the server rule).
- **Undo snapshot** = `JSON.stringify({ layout, captions, preset })` — `preset`
  so undoing the step-2 mirroring returns Fotka vlevo / vpravo, `captions`
  because the eye of Popisek is `features.captions`. Limit 100. A type change
  clears it; bleed changes are not undo steps (restored snapshots are clamped
  to the current bleed). Two editor shortcuts write *content* and are therefore
  not undoable (Šichtovnice columns, Odpady kinds) — they confirm instead.
- **Saving** (`state/payload.js`): dirty tracking is per design key and per
  content item with a `seq`; a value back at the last saved value is not dirty
  (undo returns the status to `clean`). `applyResponse` overlays still-dirty
  local items / keys on the response, so edits made while a save is in flight
  survive. One request at a time. Saving is **explicit** for both roles
  (`beforeunload` + leave dialog); only the step number is stored silently when
  nothing else is unsaved, and the store never toasts — the caller does.
  **Moving between steps saves** (`ui/topbar.js`, feedback K8, 2026-11-23):
  Pokračovat, Zpět and a step of the stepper with unsaved changes call
  `store.save()` first (the button shows „Ukládám…“ and takes no second
  click) and then `setStep`; a failed save stays on the step and toasts why.
  Steps 3–5 without a layout are **never a silent `disabled`**: the step
  buttons and Pokračovat carry `aria-disabled="true"` + `.blocked` with the
  reason as tooltip (`NEEDS_LAYOUT`), and a click goes to step 2 and toasts
  „Nejdřív vyberte rozvržení — …“. (The K8 report — „Pokračovat“ grey in
  step 2 — was this: no layout yet, a scan proposal not applied; the
  „Neuložené změny“ beside it were step-1 edits — prod history shows a design
  save 3 s before the accept — which a step change did not save.)
- **Address** (`state/url.js`, owned by the workspace controller; feedback
  K9). The URL says where you are: `?krok=N` (1–5, designer only) and, in
  steps 4 and 5, `&strana=<pageKey>` — the year/type-independent keys of
  `generate/pages.js` (`cover`, `pre`, `w01`…`w53`, `post`, `back`,
  `sp:<id>`, URL-encoded); step 4 writes a page only while a page is open
  (not on „Styl stránek“ / „Speciální stránky“). Kept current on every store
  event with **`history.replaceState`**, never `pushState` — the workspace
  has its own Zpět / Pokračovat and a leave guard, and per-step history
  entries would fight both. The tab title follows the step
  („<name> · Obsah | WBoost“; the client page keeps its title). On load a link
  is **navigation only**: `store.openStep(n)` shows the step without a dirty
  mark and without a save, and a later save answer (which carries the stored
  step) does not move the view back; the first `setStep` (the user moves)
  hands the step back to the usual silent-save rule. So the stored step —
  what the list card and a plain `/calendar/{id}` open — changes only when
  the user moves. Rules: invalid / unknown values are ignored silently;
  steps 3–5 need a layout (`effectiveStep()` wins: `?krok=4` without a layout
  shows the stored step); a client (`/k/{token}`, the read-only member) is
  always on step 5, so only `strana` counts there (`/k/{token}?strana=w05`);
  `?sken=` (ui/scan.js) wins over `krok` when the scan is available — scan.js
  removes `sken` and moves to step 2, `krok` follows. The e2e helper
  `h.seed()` drops `krok` / `strana` before its reload so the seeded step
  wins. Tests: node suite `address`, e2e `address.e2e.cjs`.
- **`Ctx`** — what every `mountX(root, ctx)` gets from the workspace
  controller: `{ store, env, api, measurer, toast, confirm, openTool,
  setStageProbe, actions: { save, leave, finish } }`. Modules return
  `{ destroy() }`, unsubscribe there and leave `root` empty. The step module is
  remounted only when the shown step changes — never on a save.
- **Stage probe.** Whichever module shows "the page on the stage" (editor
  stage, the step-4 page, the large step-5 page) calls
  `ctx.setStageProbe({ scale(), toClient(xMm, yMm), page() })` and `null` when
  it goes away. The test hook reads it.
- **Panels never rebuild for a value.** Controls carry `setValue()`; a change
  that came from the panel itself must not rebuild it (the field keeps focus).
  Panels rebuild on selection change, `undo`, `replace`. Renders are coalesced
  into a **microtask**, not an animation frame — the DOM is current as soon as
  the store call returns, also in a background tab (rAF and timers are frozen
  there).
- **`window.__calendar`** — read-only test hook installed by the workspace
  controller: `ready` (promise: first render with fonts), `state()` (deep copy:
  design, content, revision, selection, zoom, status, mode, canUndo, canRedo),
  `boxes()` (visible boxes, mm), `page()`, `scale()` (px per mm, 0 without a
  stage), `toClient(xMm, yMm)`, `pages()`, `geometry(pageIndex?)`,
  `warnings()`. e2e scripts assert in mm through it; production code must not
  read it.
- **DOM hooks** are a contract with `tests/e2e/calendar/`: `[data-cal-step]`,
  `[data-cal-action="…"]`, `.cal-ws[data-cal-ready="1"][data-cal-shown-step]`,
  `[data-cal-status]` (clean / dirty / saving / error), `[data-layer]`,
  `[data-handle]`, `[data-guide]`, `[data-part-box]`, `[data-evbox]`,
  `[data-field="x|y|w|h"]`, `[data-prop="<path in styles>"]` (+ the frame
  switch `[data-prop="cal.frame|month.frame|wk.frame"]` on 14 days; in the page
  SVG `[data-el="cal|month|wk"][data-frame="0|1"]`), `[data-tool]`,
  `[data-page-thumb]`, `[data-day-plus]` / `[data-day-row]`,
  warning badges `[data-photo-warning]` / `[data-day-warning="<date>"]` /
  `[data-page-warn="<index>"]` (buttons; popover `[data-cal-popover="warning"]`),
  `.cal-warn[data-warning-kind]` + `[data-warning-action="<id>"]`,
  `.cal-pop[data-cal-popover]`, `.cal-dialog` + `[data-cal-choice]`,
  `.toast-n`. The full table is `docs/calendar/architecture.md` §D.10; each
  file's header lists its own.

### Languages of the printed names (K15b, 2026-11-24)

`design.languages` — the **extra** languages of the month / weekday / week-number
words, any of `sk pl de en` (`generate/languages.js` `EXTRA_LANGUAGES`, PHP
`CalendarDesignRules::LANGUAGES`), Czech always first and implied; `[]` = Czech
only (column default, `Version20261124120000`, so every stored calendar is
unchanged). Set in step 1 „Jazyky názvů“ (`ui/step1.js`: Čeština checked and
disabled, then the four extras as checkboxes, no cap; `store.setLanguages` —
design patch `languages` + `layout`, re-runs the auto-grow, no undo step like
the feature switches) and by MCP `manage_calendar` `languages` (`model.mjs`
refuses `cs` / unknown codes with `unknown_language`, notes a reordering);
`get_calendar` / `manage_calendar` answer `languages`. An AI scan that reads further
languages on the page offers the printable ones in its review („Vícejazyčné názvy:
polština, angličtina — zapneme je“, pre-ticked) and its accept adds them
(`ai-scan.md` § the proposal's `features`). Step 3 › Kalendárium
„Názvy dnů“: Celé · Zkrácené · Automaticky (+ a note under Automaticky).
Rules — load-bearing:

- **Fixed print order cs → sk → pl → de → en**, joined by ` / `, nominative
  forms (`grudzień`, not `grudnia`); identical words repeat (`Sobota / Sobota`).
  The server refuses a list out of that order / with unknown or repeated codes;
  `normalize` repairs.
- **Only three texts**: `monthText` (`LEDEN / STYCZEŃ / JANUARY 2027`; a week
  across months is **two lines** joined by `\n`, the year on line 1 only when
  the years differ), `weekText` forms a / b (`3. týden / tydzień / week`,
  `Týden / Tydzień / Week 3`; `T3` has no word) and `DayInfo.weekday /
  weekdayShort` (`Pondělí / Poniedziałek / Monday`, `Po / Pn / Mo`). Name days,
  holidays, DST / season texts, special pages, the editor UI and all content
  stay Czech. A Czech-only calendar produces byte-identical texts.
- **Měsíc of several lines** (`week-layout.js` `placeMonthText`): a line per
  month, and with extra languages a line wider than the frame breaks after a
  ` / ` (a name is never broken; a Czech-only text never wraps); one line sits
  exactly where it always did, more lines are a centred block of
  `MONTH_LINE_HEIGHT` × size. Auto-grow (below) grows the frame's height.
  Whatever still does not fit is drawn anyway (never clipped) → page warning.
- **„Názvy dnů“** `styles.cal.dayFormat` full | short | **auto** (new layouts;
  stored ones keep their value): judged once per frame (E.6.3a) — beside the
  number / on compact rows the widest label must fit the text column the full
  icon zone leaves, above the number it may widen the number column to half
  the row at most. auto = full when they fit, else short; **a Czech-only
  calendar keeps full under auto** (exactly its old output). A form that does
  not fit → `frame.dayOverflow` → page warning.
- **Warnings** (`render/warnings.js`, wording in `warning-text.js`): `dayNames`
  (target `{ el: 'cal', frame }`, facts `{ format, multilingual }`, once per week
  column of a page) and, for multilingual calendars only, `month` (target
  `{ el: 'month', frame }`, facts `{ lines }`) when `placeMonthText` reports an
  overflow. Both offer the designer the `element` action.

### Generation in one paragraph

`buildPages(doc)`: cover → front special pages → week pages from the Monday of
the week containing 1 January until 31 December is covered (7 or 14 days per
page; 2027 = 53 / 27 week pages) → back special pages → back. A week page
carries one text **per week column**: `week.monthTexts[f]` (the month(s) of
that column's own seven days — `PROSINEC 2026 / LEDEN 2027` | `LEDEN 2027` on
the first 14-day page of 2027) and `week.weekTexts[f]` (`53. týden` |
`1. týden`, in the chosen Tvar); a 7-day page has one of each. Only the page
*name* in the lists (`title`) still covers both weeks (`53. a 1. týden`). **Page keys are
year- and type-independent**: `wNN` from the ISO week of the page's Monday,
`pre` / `post` when that Monday belongs to the neighbouring ISO year — so
photos and captions survive a year change (they stay with the week number) and
a type change (for weeks that still start a page); a key without a page is
simply not shown. A year change (`store.changeYear`) shifts every dated item
by the year difference, **drops 29 February** events / names / extras with a
toast, moves ranges and comment dates to 28 February, clears `moonEdits`.
Waste rhythms follow ISO week parity (so they shift across a 53-week year).

### Editor algorithms (pure, `editor/*` + `render/week-layout.js`)

- **Drag pipeline** (`drag.js`): `snapshot → move/resize → min size → snap →
  clamp → round → link`, always computed from the session's base boxes, never
  accumulated — dragging out and back restores exactly.
- **Snap** (`snap.js`): targets are the trim edges, page centre, the binding
  band line, the bleed edges and the edges of every *visible* other box;
  threshold `SNAP_PX / scale` mm (zooming in allows finer placement); a move
  snaps edges and centre, a resize only the dragged edges; Alt switches it off.
- **Link** ("Propojit prvky", `link.js`, handoff §7 is authoritative): only a
  *resize* links; a visible neighbour whose edge coincides (≤ 0.15 mm,
  collinear is enough) with a moved edge of A follows by being **resized,
  never shifted**; no chain reaction; an edge on the trim / in the bleed never
  links; followers stop at their minimum while A continues. The `link` suite
  has one test per row of handoff §7.4.
- **Clamp** (`geometry.js`): `clampMove` keeps the size, `clampResize` clamps
  each edge; `roundBox` rounds the four **edges** (not w/h), so a fixed edge
  stays exactly where it was. Nudge never links or snaps.
- **Bleed adaptation** (`bleed.js`): on a bleed change every box edge that sat
  on the trim or on the old bleed edge moves to the new bleed edge; nothing is
  stretched otherwise; then everything is clamped into the new bounds.
- **Auto-grow** (`autogrow.js`): Měsíc and Číslo týdne grow (never shrink) to
  the widest text of **every** week page, away from their alignment anchor;
  runs after a committed change, never during a live drag, and is part of the
  causing undo step. On 14 days each frame grows on its own — to the widest
  text of ITS column, away from its own (mirrored) anchor; `maxTextWidths` and
  `WeekGeometry.textWidths` are keyed by box key (`month month2 wk wk2`).
  With extra languages a Měsíc of several lines also grows in **height**
  (`maxTextWidths().monthLines(key, room)`), towards the page middle but never
  over the Kalendárium / photo below it; it is never shifted (it only grows away
  from its anchor and wraps instead), and on 14 days each Měsíc frame stays on
  its own half of the page (`K.SECOND_FRAME_GAP` short of the centre line).
  Czech-only calendars: unchanged (width only, shift-then-shrink).
- **Kalendárium row layout** (`week-layout.js`): rows share the frame height;
  under `K.COMPACT_BELOW` the row is *compact* (one baseline, row-mode icons,
  no event box). A row has a number column, a text column anchored towards the
  number, and a *zone* from the other edge (shift columns, icon grid, place
  labels). Coordinates are computed on a **flow axis** from the zone edge, so
  the mirrored frame is the same code. Holiday names are **never truncated** —
  they wrap; a compact row first drops the name day, then widens the wrap.
- **Pozadí čísel** (`cal.numBg {color|null, opacity, grow}`, `numberBandOf` →
  `frame.numberBand`, drawn first in the frame as `rect[data-band="numbers"]`,
  above `cal.bg`, below the rows; off = `color: null`, the default). A band over the
  whole frame height (`box.y … box.y + box.h`) from `K.NUM_BAND_PAD` (2 mm)
  before the number column to the frame edge on the number side; when that
  edge lies on the trim or in the bleed (≤ `K.LINK_TOL` from the trim) it runs
  on to the bleed edge (`−B` / `W + B`), so the printed band goes off the page.
  Numbers in the middle: the column ± the pad, never extended. The side is the
  DRAWN one, so the mirrored 14-day frame (`flipRowStyles`) gets its band at
  the other outer edge with no extra code. A flat fill (+ fill-opacity, Normal
  blend) — the CMYK pass handles it like `cal.bg`. Panel: block panel under
  „Číslo dne“ and the number part panel (same control).
  **Rozšíření** (`grow`, K5b; mm, `LIMITS.numBandGrow` 0–60, step 0.5,
  default 0 = the K5 band exactly — stored calendars without the key
  normalise to 0 and draw as before) moves only the INNER edge that far
  towards the day names; the outer edge stays on the frame / bleed edge. It
  never passes the frame's other edge (clamped to `box.x` / `box.x + box.w`).
  Numbers in the middle: half of it on each side. One value for the layout —
  the 14-day frames share their styles, so both columns widen towards the
  middle and still mirror. A relative width on purpose: the band keeps
  covering the numbers when their size changes, and its minimum is always the
  K5 width. The band stays a BACKGROUND — the day names, name days and holiday
  texts are drawn over it (the designer picks the colour + Krytí for contrast);
  slider „Rozšíření“ under Krytí in both panels.
- **Event box**: default = one page-wide box in the free area beside the widest
  *weekday / name-day line* of the page (both 14-day frames share it, so they
  mirror exactly; `frame.evDefault`). A row whose holiday or time text is wider
  than every name line gets a narrower box of its own — one long holiday must
  not narrow the events of the whole page (the sample page holds 1 January).
  Grabbing a bar of any default box stores the page-wide one; a stored
  `ev.box` is one box for every row, used as placed; overflow is a warning,
  text is never clipped.
- **Warnings** (`render/warnings.js`): portrait photo, dpi < 200, icons that do
  not fit the grid, event overflow — and, on the cover / back / special pages,
  a text that does not fit (`overflow`) — all derived from geometry + the
  asset map, nothing stored, never blocking. Each carries `facts` (pixels,
  dpi, frame mm, zoom, date, counts, the special page's sentence); the WORDING
  is one pure function, `describeWarning(warning, { designer })` in
  `render/warning-text.js` → `{ title, text, hint, actions: [{ id, label }] }`
  (numbers the Czech way: `1 200 × 800 px`, `37 %`, nbsp). `warning.message`
  (= `warningMessage`: title. facts. fix — designer wording) is what MCP's
  `render_calendar_page` returns and the step-4 panel's change signature.
  Every surface renders the same card (`ui/warnings.js` `warningCard`): the
  badge on the step-5 photo / day row, the thumbnail badge (a button BESIDE
  the thumbnail button, in `.cal-thw`), the Upozornění list (cards under a
  „Přejít na stranu“ head) and the step-4 page panel. Badges open a
  `ui/popover.js` popover on mouse hover (no focus taken) and on click / tap /
  Enter (pinned, focus on the first action, outside click or Esc closes).
  Actions: `photo-replace` (file picker → `setPagePhoto`), `photo-zoom-reset`,
  `events` (Akce a spolky on the warning's month, only when the role has the
  tool), `element` (designer: step 3 with `{ el: 'cal', part: 'icon' | 'ev' }`
  selected) are built in; `photo-crop`, `day` (the day's plus menu, its
  collections / events removable) and `page-content` come from the step
  (step 5 shows the page first; step 4 sends crop / day to step 5 and keeps
  page content in its panel). A client never gets `element`. Colour: peach
  (`--pch`) — design-system §7 rule 3; rose would read as an error (K12).

### Calendar scan — UI (P5)

„Naskenovat design“ in step 2 (ux.md §6; backend: § "Scanned layouts" below
and `ai-scan.md` § "Calendar scans"). 7-day and 14-day calendars (the scan
targets the calendar's own type), designers only.

| File | Role |
|---|---|
| `ui/scan.js` | `createScan(ctx)` → ONE `CalendarScan` per workspace (`ctx.scan`; null for a client or without `env.scan`). Owns the entry facts, the current scan and its proposal; polls `status` while a scan is on its way **in any step** (2 s with the panel on screen, 10 s otherwise); `mount(root)` draws the action card or the panel into step 2's `.cal-scan`. Apply / restore, pictures, cancel / discard / retry |
| `ui/scan/views.js` | markup strings of the card and of the panel's screens (`upload`, `chosen`, `wait`, `fail`, `gone`, `review`, `pictures`), the comparison slider, the outlines of `Ukázat vrstvy` (`layerBoxes`), the numbered places and their crops (`sourceBox`), the rating block, the privacy, access-request and „Naskenovat znovu“ dialogs |
| `ui/scan/preview.js` | the live render of the proposal (`previewDocument` / `previewMarkup`: the calendar's own renderer on a document that exists only for the preview), `cropPicture` (the one canvas), `elementFact` — the phrase beside a recognised element, derived from the layout (since K21 also the photo's „zaoblené rohy“ / „rámeček“, the picture effects of the photo and the logo — „stín“ dropShadow, „záře“ outerGlow, „ztmavení“ an overlay / inner kind — and the Kalendárium's „pozadí čísel“) |
| `ui/step2.js` | mounts `ctx.scan` under the cards — of a 7-day and of a 14-day calendar; the radio `[data-preset="scan"]` „Naskenovaný design“ (the third card for 7 days; for 14 days the static card „Dvoustranné rozvržení“ becomes a radio group of two once the layout came from a scan — the layout card then puts the default two-sided layout back, one undo returns the scan) |
| `ui/topbar.js` | the peach dot on step 2 (`[data-cal-scan-dot]`), tertiary `Pokračovat` |
| `assets/ai-scan/copy.js` `CAL` | every Czech sentence of the calendar scan |

- **Rendering** is the template wizard's: strings + `morph()` (regions
  `data-region`, imported from `assets/ai-scan/page/app.js`), the same `as-*`
  classes (`ai-scan.css`); the calendar-only rules (`as-act`, `as-cpanel`,
  `as-cgrid`, `as-rec`, `as-cantl`, `as-stepdot`, `cal-scan*`) are in
  `calendar.css`. Events are delegated: `data-act` (click), `data-chg`
  (change). Everything not written in the code goes through `esc()`.
- **The waiting and result screens consume the status document as the
  template wizard does** (`ai-scan.md` § "The status / poll contract as
  consumed"): the uploaded picture is `image/{picture}` (`working` |
  `original` | null → the frame's own "no picture" text) — there is no
  fallback chain and no `onerror` swap, so a 404 on a picture is a bug; the
  running render is `image/candidate?n={previewCandidate}&v={previewSeq}`
  (`previewSeq` only says "changed" — it is NOT a candidate number; after an
  automatic restart the last render shown stays, `scan.lastPreview`); the
  duration of every finished stage and the note it ended with come from
  `stages`, the server's stage log (the browser keeps no durations; a live
  note that belongs to an earlier stage stays with that row). `observe()` /
  `needsRender()` are imported from `assets/ai-scan/page/wait.js`.
  **„Tyto texty jsme našli (n)“** — the outlines of the lines the reading
  found (`textBoxes` of the status document: `{width, height, boxes}` in
  pixels of the working copy; the calendar adapter sends them with
  `texts_found`, as the template adapter does) — are drawn in the
  „Průběžný náhled“ frame while the scan runs, the working copy exists and
  no render came yet: `views.foundTexts()` with the wizard's `boxStyle()`
  and `.as-found` / `.as-found-frame` (`[data-cal-scan-found="n"]`); the
  first render replaces them. Between 641 and
  1000 px (one column) the upload and the running render sit side by side
  (`.as-cpics`) and a lone picture is at most 480 px wide (`.as-cpic-one`) —
  a calendar page is a wide picture.
- **The proposal is drawn live**, never from the candidate picture:
  `proposal.layout` on the sample week `proposal.page`, the ticked switches
  on, a held picture as a temporary asset entry (`scan-preview-{slot}`) and
  the photo region of the upload (cut by a canvas into a blob URL) as the
  page's photo. The cut is the WHOLE proposed photo box, bleed included
  (`photoCutBox`: layout mm → source px through `pageBox`; the bleed part
  filled with the picture's stretched edge pixels), at the box's mm ratio —
  so the cover fit places it 1:1. The scanner's `photoBox` is clipped to the
  picture; laid into a box that runs into the bleed it was stretched ~4.5 %
  and shifted, and the source's own caption plate showed beside the proposed
  one (K16). Nothing of it enters the store or a save. The SVG is
  put into `[data-proposal]` after each render (outside the patched markup)
  and redrawn only when its signature changes.
- **Boxes over the pictures** are percent boxes computed from the proposed
  layout's millimetres (`mmStyle`, cut at the page edge): the outlines of
  `Ukázat vrstvy` (`.as-box`, letter tags F fotografie · H hlavička ·
  K kalendárium · M měsíc · T číslo týdne · P popisek · L logo · Q QR kód;
  an element that is hidden or whose switch is off — and not ticked — has
  none) with the legend under the viewer, and the numbered places of the
  pictures step (`.as-numbox` + `.as-num`; an empty place shows the uploaded
  page's own pixels dimmed, `sourceBox()` = layout mm → pixels of the working
  copy through `pageBox`). Hover or focus of a row of „Co jsme rozpoznali“
  or of a picture card (`data-hl`) lights the box with the same `data-box`.
- **„Co kalendář nepřevezme“** (feedback K15a, 2026-11-24; was „Tohle
  kalendář neumí“): the report's `notExpressible` grouped by CODE — one row
  per code with all its crops (≤ 3), the name, „· 3 místa“ when repeated,
  and one sentence what it is and what to do (`CAL.notExpressible[code] =
  {title, text}`: ornament / second_photo / more_logos → an own layer,
  „Úpravy prvků › Vrstvy › Přidat“ (since K21 — the K20 layers; the scan
  does not build them yet, `docs/ai-scan/round-k21-plan.md` Phase 3);
  header_text → part of the header picture or the caption;
  days_in_grid, rotated_text, month_colours → what the calendar does
  instead; multilingual_names → kept only for a language the calendar
  cannot print — sk / pl / de / en are offered as „Vícejazyčné názvy“
  in the review since K15b). A lead says it is information; no
  warning colours. Unknown codes are left out. `li[data-cant="<code>"]`.
- **Pictures are optional, not a stop** (feedback K7 / K8, 2026-11-23). From
  the review the primary is „Použít rozvržení · n obrázků doplníte později“;
  „Doplnit obrázky teď“ (tertiary, `[data-cal-action="scan-pictures"]`) opens
  the pictures step for whoever wants it — everything below still works there.
  After an apply with places left empty the toast says „Rozvržení převzato ze
  skenu · 1 obrázek doplníte v Úpravách prvků“ and step 3 opens with the
  first empty place selected (`logo` / `head` / `bg`, `placeOfSlot()`), whose
  panel holds the upload and the size line (§ K6 below). When every place
  has a picture the multi-drop zone gives way to the note
  `[data-cal-scan-full]` („Všechna místa mají obrázek. Obrázek vyměníte
  tlačítkem Změnit…“); a file dropped on one card replaces that card's
  picture; files that find no empty place toast `CAL.picturesNoneEmpty`
  (it used to say „Žádné prázdné místo — obrázky jsme nepřiřadili“).
- **The backdrop picture is encouraged in the review** (feedback K7b,
  2026-11-24): after K7 the uploads sat behind „Doplnit obrázky teď“ and got
  lost — yet without the header / page-background picture the applied layout
  shows only the colour the scanner guessed (the scanner never sets `imageOn`
  for a picture it reports, `system.calendar.md`). When the proposal has a
  `header` or `background` place (`views.BACKDROP_SLOTS`, background first),
  the review's side column shows right under the summary a card
  `[data-cal-scan-backdrop="<slot>"]` (`CAL.backdrop[slot]`): the crop of the
  page at that place, the size line (K6, bleed included), „Doporučujeme
  nahrát pozadí — bez něj bude záhlaví prázdné.“ (page background: „…stránka
  prázdná.“), a dropzone `[data-k="bddrop<slot>"]` (click = file chooser, a
  dropped file = `holdPicture`) and „Vybrat z galerie“; once held, the same
  state + Změnit / Nahrát jiný / Odebrat as on a picture card. The other
  places follow as `[data-cal-scan-more]` „Další obrázky (n) — můžete doplnit
  i později“ — compact rows with Nahrát / Vybrat z galerie. „Použít
  rozvržení“ stays enabled; while the backdrop place is empty a line under
  it says so (`[data-cal-scan-bd-note="<slot>"]`, also in the pictures step)
  — no dialog. An apply that leaves it empty selects THAT place first in
  step 3 (before the logo) and toasts „Rozvržení převzato ze skenu ·
  nahrajte obrázek do hlavičky v Úpravách prvků“ (`CAL.appliedBackdrop`;
  kept as short as K7's toast — a three-line toast covered the step-2
  card's „Nahrát obrázek“). In step 3 the header / background panel's size line
  (`kit.size(place, prefix, fillPath)`) then reads „Obrázek do hlavičky:
  zaškrtněte Obrázek a nahrajte ho · Rozměr …“ while the fill's „Obrázek“ is
  off (the place has the guessed colour until a picture comes); the page
  background's size line is shown always now, not only with „Obrázek“ on. A
  proposal without a backdrop place shows none of this (K7 as before).
- **Pictures for the places** (`holdPicture(slot, File | {fileId})`): a file
  input, a file dropped on a card (`data-dropzone="slot"`), several files on
  the multi-drop zone or on a card (`holdMany` — the places without a
  picture in order; shown when the proposal has more than one place), or the
  project's gallery: the `Project:ImageGallery` modal `#calScanGalleryModal`
  of `workspace.html.twig` — rendered only for whoever may START a scan
  (`ai_scan_start`), outside the workspace's root — answers with the window
  event `asset-selected`, and the server copies the gallery file's bytes to
  the scan (`fileId`). JPG, PNG, WebP or SVG. The upload zone and the
  chosen-file card take a dropped page the same way (`data-dropzone="page"`).
- **The scan is built for the calendar as stored** (feedback K4, 2026-11-23):
  `start()` saves unsaved changes first (`store.save()`; a failed save does
  not start the scan) — a type switched in step 1 and not saved used to give
  a 7-day proposal for a calendar that became 14-day on the next save. A
  proposal whose `builtFor` is not the type on screen (`views.typeChanged()`)
  shows the alert `[data-cal-scan-type-changed]` (`CAL.typeChanged`) above
  the review and the pictures step instead of the page-vs-calendar note,
  „Použít / Nahradit rozvržení“ is disabled with a hint, and an accept that
  still answers `wrong_state` + `reason: calendar_type` toasts that, never
  „Návrh už není k dispozici.“ e2e: `scan.e2e.cjs` (the K4 checks).
- **„Nejprve si připravte“** (feedback K1, 2026-11-23) — the upload screen
  opens with a card of four things to have ready (`views.prepare()`, copy
  `CAL.prepare`, `.cal-prep*` in `calendar.css`): the logo as SVG or PNG (the
  scan never cuts the logo out of the picture — it marks its place, the file
  goes in on the pictures step, from the disk or the gallery), the fonts the
  calendar uses in the project's Fonty (the scan picks the nearest project
  face; without one the built-in face), the colour palette (colours are taken
  from the picture; the palette's colours are the swatches of step 3's
  pickers — the scanner also receives it, as `brandColours`) and the picture
  itself: a working version of the first WEEK page showing every element and
  icon the calendar will have. It informs, never blocks, and is on the
  `upload` view only (gone once a file is chosen). Fonts and palette show a
  live state — `V projektu jsou 2 písma` / `Projekt zatím nemá žádné písmo`,
  `V paletě je 5 barev` / `Paleta je prázdná` — from `entry.fonts.families`
  and `entry.palette.colours` (the count of `CalendarDocument::palette()`,
  i.e. what the pickers offer); the entry is read again when the panel opens
  on the upload screen and on `visibilitychange` back to the tab while that
  screen shows, because the links (`Nahrát písma` → `env.scan.urls.fonts`,
  `Otevřít paletu` → `env.scan.urls.palette`) open a new tab — the workspace
  guards unsaved changes.
- **Every place says its size** (feedback K6, 2026-11-23; `views.placeSize()`):
  the place's box in the PROPOSED layout, cut at the bleed edge, in mm — a
  box that reaches over the trim holds the bleed and says „včetně spadávky
  5 mm“ (`bleedOf(design.print)`); with the bleed off a box at the page edge
  says „(spadávka je vypnutá)“; the background place is the whole page plus
  bleed. Then the pixels at 300 dpi (ideal) and 200 dpi (minimum) —
  „Rozměr 320 × 32 mm včetně spadávky 5 mm · ideálně 3780 × 378 px, nejméně
  2520 × 252 px“. The logo is fitted inside its box: „Místo pro logo 34 × 11
  mm · SVG, nebo PNG s průhledností (ideálně 402 × 130 px)“. A held picture
  (not an SVG) under 200 dpi in its place (cover: `photoDpi`; contain:
  fitted inside) gets the editor's hint „Nízká kvalita obrázku — 48 dpi (pro
  tisk aspoň 300 dpi)“. The 200 / 300 are MIRRORS of `render/warnings.js`
  (kept in `ui/picture-size.js`: a constant moved into `render/` would change
  the export fingerprint). **Step 3 says the same where the picture is
  actually chosen** (K7): `pictureSize()` of `ui/picture-size.js` under the
  logo upload, under the header's fill and under the page background (K7b:
  with „Obrázek“ off it adds „zaškrtněte Obrázek a nahrajte ho“; `kit.size()` of `ui/editor/props.js`,
  `p.cal-psize[data-picture-size="logo|head|bg"]`, follows the box while it
  is moved or resized).
- **Upload checks**: `inspectFiles(files, {minShortSide: 280, minLongSide:
  640, goodShortSide: 600})` — a calendar page is taken from 640 × 280 px,
  longer × shorter side (a page 640 px wide is 299 px high; the template
  wizard: a 600 px short side — the mirrors of the size are listed in
  `ai-scan.md` § "Upload" and pinned by `AiScanValuesTest`); under 600 px the chosen-file checks say „Malý obrázek —
  rozvržení může vyjít méně přesně“ and the review's „Pro informaci“ carries
  the low-resolution line (from `proposal.source`, nothing stored).
- **„Naskenovat znovu“** asks (`rescanDialog`): `Stejný obrázek znovu` puts
  the stored original back on the chosen-file screen (the browser fetches
  `image/original`; a new scan, with the credit line in front of the user),
  `Nahrát lepší obrázek` opens the upload zone. The proposal stays in the
  history either way; a link `?sken=…` brings it back.
- **Rating** „Jak se sken povedl?“ → `POST ai_scan_feedback` (`rating`,
  after 👎 an optional `note`); `proposal.rating` says whether it was given.
- **The marker** `layoutOrigin` (`'scan'` | null) is `store.getState().layoutOrigin`
  — beside the document, never in `doc.design` (the export fingerprint and
  `normalizeDocument` do not know it). Initial value `env.layoutOrigin`; every
  save answer that carries `layoutOrigin` updates it (a client's does not).
  `store.choosePreset()` over a scanned layout REPLACES the layout with the
  preset's default (no mirror), clears the marker and is one undo step — the
  undo snapshot carries `origin` while the marker is on, and an undo brings
  it back only while the server still has it (`serverLayoutOrigin`).
  `buildPayload()` adds top-level `"layoutOrigin": null` when the server has
  the marker and the store dropped it (server-side `model.mjs` never does:
  its env has no marker). Node suite `scan-marker`.
- **`store.load(response)`** takes a document saved through another door (the
  answer of accept / restore: `{ calendar, assets, layoutOrigin }`): replaces
  doc and server copy, clears dirty marks and the undo stack, emits
  `replace`. Callers make sure the store is clean first — apply SAVES first
  (`scan.apply()`: the button reads „Uložit a použít rozvržení…“ while the
  calendar is dirty, a failed save does not apply and says why — K8),
  restore says so in a toast.
- **„Použít rozvržení“ changes the step in one piece.** The accepted document
  comes back at step 3 (the model's `replace_layout` advances it), so the
  `replace` event of `store.load()` is what swaps step 2's module for the
  editor. A store listener that unsubscribed while an event is being
  delivered is NOT called for that event (`emit()` checks the live set;
  node suite `scan-marker`) — step 2's listener used to answer the same
  `replace` after its module was destroyed and drew step 2 again over the
  editor, under a top bar that said step 3 (found on production,
  2026-11-13). `mountStep2` also ignores events once destroyed.
- **Scans applied one after the other are restored in reverse order**
  (`ai-scan.md` § "Restore"): `entry.applied` names the scan applied LAST
  whose snapshot lives. After a restore the panel reads the entry again, so
  when what came back is itself a scanned layout (scan A, then B, B
  restored → A's layout with the marker `scan`) the card offers A's
  „Vrátit původní rozvržení“ at once and the toast says „Vráceno rozvržení
  z předchozího skenu“ instead of „Původní rozvržení vráceno“. An applied
  scan opened from its link shows the pill „Vráceno původní rozvržení“ once
  restored, and says so when a newer scan was applied over it
  (`applied.superseded`: no restore button until that one is put back).
- **Links** `/calendar/{id}?sken={scanId}` (e-mails, MCP, history, the shell's
  toast) and `?sken=1` (the list pill) open step 2 with the panel; the
  parameter is removed from the address at once.
- **Top bar**: `scan.primaryShown()` (views `chosen`, `review`, `pictures`,
  `fail`) → `Pokračovat` is `btn-tertiary`; `scan.unfinished()` /
  `scan.attention()` → the dot. A proposal that becomes ready while the panel
  is not on screen is a toast `Návrh rozvržení je připravený` · `Zkontrolovat`
  in any step. `<html data-as-scan>` names the workspace's scan so the
  shell's own toast (`ai_scan_shell_controller`, which now links calendar
  scans to their calendar) stays quiet for it.
- **List** (`calendar_list_controller.js`): `scan` of `urls.card` → one pill
  `a.cal-scan-pill[data-cal-scan-pill]`. **History**
  (`templates/ai_scan/history.html.twig`): chips `Šablony` / `Kalendáře`,
  calendar rows open their calendar, `Vrátit původní rozvržení`
  (`assets/ai-scan/lists.js` `restoreCalendarLayout`, asks on `layout_changed`)
  — offered on the row of the scan applied last only. An applied row says
  `Rozvržení použito`, after its restore `Vráceno původní rozvržení`
  (`row.layoutRestored`). Calendars are often left with the default name, so
  the caption of a calendar row also says which calendar it is and what was
  scanned: `Kalendáře · 14 dní · 2027 · stranka.png · kdo · kdy`
  (`row.calendar {type, year}`, `[data-calendar-facts]`, `row.fileName`).
- **Who**: an editor of a project with AI sken switched on is the ordinary
  user of all of this (decision 16) — the card's state is then `enabled`;
  an administrator on a project without the switch sees `admin_preview` and
  the line saying so; an editor of a project without it sees `locked` →
  „Požádat o přístup“ → `request_sent`.
- **A finished scan opened from a link** (`?sken=…`): step 2 may be on
  screen before the scan's proposal arrived. The panel then shows the view
  `loading` („Načítáme sken…“) — it used to say „Původní rozvržení už vrátit
  nejde“ / „Sken už není k dispozici“ until the answer came.
- **DOM hooks**: `[data-cal-scan-card][data-state]`,
  `[data-cal-scan-panel][data-view]`, `[data-cal-action="scan-open|scan-close|
  scan-start|scan-pictures|scan-apply|scan-restore|scan-discard|scan-rescan"]`,
  `[data-preset="scan"]`, `[data-cal-scan-dot]`, `[data-proposal]`,
  `[data-cal-scan-prep]` with `[data-prep="logo|fonts|palette|page"][data-state="info|ok|todo"]`,
  `[data-place-size="logo|header|background"]` / `[data-place-dpi]` on a picture card,
  `[data-cal-scan-backdrop="header|background"]` (`[data-cal-scan-bd-state="empty|done"]`),
  `[data-cal-scan-more]`, `[data-cal-scan-bd-note]` (K7b),
  `[data-cal-scan-fonts="ok|warn|bad"]` (`#calScanFonts`) with rows `[data-font-state]` and pills
  `[data-font-count]`, `[data-cal-scan-attn="fonts"]` in the summary,
  `#calScanFile`, `#calScanPic`, `#calScanPics` (always in the panel's markup), `[data-slot]`
  on a picture card, `[data-dropzone="page|slot|pictures"]`, `[data-box]` /
  `[data-hl]`, `[data-k="layers|fbu|fbd|picg{slot}|pic{slot}|picdrop{slot}"]`,
  `#calScanGalleryModal`. Test hooks: `window.__calendarScan` (`view()`, `scan()`,
  `proposal()`, `entry()`), `window.__calendar.state().layoutOrigin`.
- **e2e**: `tests/e2e/calendar/scan.e2e.cjs` with the fake scanner
  (`tests/e2e/ai-scan/lib/fake-scanner.cjs`, calendar branch; it takes only
  the e2e project's scans — a real local scanner would take them too: run
  the script against an app container of its own, `E2E_WEB_CONTAINER` /
  `E2E_DB` / `BASE` / `SCANNER_TOKEN`, README there). Every 404 answer of the
  run fails its last check. The script works as the PLAIN e2e user, an editor of a project
  with AI sken switched on (the customer of decision 16); its fixture
  switches the entitlement of the e2e project on and switches a local
  „admins only“ off. It walks the card states (locked → request sent, limit;
  service-waiting from a rewritten `entry` answer — the service is not
  paused), the whole flow (the picture the status names, the render it names,
  the server's stage durations), layers, rating, numbered places, drop, gallery,
  rescan, an applied scan opened from its link, the history row's restore
  and the history of a project with only the Kalendáře module; since
  2026-11-13 also the outlines of the found texts, a 640 × 299 px page (taken) and a 600 × 280 px one (refused), „Zamítnout“ of an access request by an administrator, the
  step change of „Použít rozvržení“ (editor on screen, nothing of step 2),
  and two scans applied one after the other and restored in reverse order
  (card, toast, history pills, the rows' calendar facts).

Deviations from `docs/ai-scan/ux.md` §6:

- **No in-review font decisions.** Font rows are information („Písmo čísel
  dnů: nejbližší dostupné — …“, changed in step 3); „Nahrát správné písmo“ /
  „Vybrat jiné písmo“ / „Nechat“ are not built. Without a project font the
  item links to the Fonty page (new tab). **But the card is not quiet**
  (feedback K3, 2026-11-23): every row carries its state — `undetermined`
  (or an unknown pattern) a rose `alert-circle`, `nearest_available` a peach
  `alert`, `measured_match` a mint tick (`CAL.fontStates`); the head shows the
  counts as pills („2 nerozpoznaná“ rose, „4 nahrazená“ peach, „1 odpovídá“
  mint); with any problem the card's border is rose (something undetermined)
  or peach (stand-ins only), a box says what to do — upload the source's font
  to Fonty (`Nahrát písma`, new tab) and pick it in step 3, the same story as
  „Nejprve si připravte“ — and the summary card gets the line „Písma
  neodpovídají předloze: … · Zobrazit písma“ (scrolls to and focuses
  `#calScanFonts`). Nothing of it blocks „Použít rozvržení“.
- The header of the summary card says `Návrh rozvržení je připravený.` — there
  is nothing to count as "to decide".
- The stage names of a calendar scan are the calendar's own (`CAL.stages`).
- A picture for a place has no fit choice — the layout's own (`logo.fit`,
  the fill's `fit`) applies and is changed in step 3; there is no
  „Vybrat víc z galerie“ (a proposal has at most three places — the gallery
  is picked per place).
- „Naskenovat znovu“ → „Stejný obrázek znovu“ puts the picture back on the
  chosen-file screen instead of starting at once (as in the template wizard).
- **14-day pages** (built 2026-11-13). A scan targets the calendar's own
  type: on a 14-day calendar (`type: fortnight`) the proposal is the
  `twoSided` layout with the second frames `cal2` / `month2` / `wk2`
  (`ai-scan.md` § "14-day calendars": the capability `mm14`, the candidate,
  accept / restore). In the review `Ukázat vrstvy` outlines both frames of
  Kalendárium, Měsíc and Číslo týdne under one letter (`layerBoxes`), and
  the facts of „Co jsme rozpoznali“ speak of two columns (`elementFact`:
  „dva sloupce, čísla u okrajů stránky“ | „… u fotografie“, „nad oběma
  sloupci“, „u obou sloupců“). **The alert above the review appears only
  when the page and the calendar disagree** (`mismatchAlert`,
  `CAL.mismatch[code]`, `[data-cal-scan-fortnight="<code>"]`) instead of a
  line in „Pro informaci“: note `fortnight_left_half` — „Na obrázku jsou dva
  týdny, tento kalendář je týdenní“ (the left week + photo were rebuilt) —
  and `week_page_on_fortnight` — „Na obrázku je jeden týden, tento kalendář
  je čtrnáctidenní“ (the second column mirrors the first). Both name the
  other calendar type as the way to take the page over whole.
- The service-waiting / limit / locked cards carry the template card's copy
  (ux.md T1); the calendar card has no separate wording.
- The sidebar's AI-scan badge still counts template scans only (Šablony
  item); the Kalendáře item carries none.
- After „Vrátit původní“ from the scan's history row the page reloads; the
  open-calendar tab of another window is not told.
- A recognised element that is `approximate` says „přibližně“ once: the
  visible fact starts with the word, so the icon's hidden label is left out
  for that state (it read „přibližně přibližně“).

## Server-side model and headless render

Two things let a calendar be authored and looked at WITHOUT a browser. Neither
moves a rule out of `assets/calendar/`.

**The model in node — `Services/Calendar/CalendarModelBridge` →
`resources/calendar/model.mjs`.** PHP pipes `{ calendar, assets, env: { fonts,
palette, currentYear }, commands }` into the script; the script builds the pure
store (`state/store.js`, designer mode, estimator measurer, an api that never
sends) from `normalizeDocument()`, runs the commands as store calls and answers
the payload `buildPayload()` would POST, the materialised design, optionally
the page list, the warnings and the mm view box of a page. Commands:
`configure` (`changeType`, `changeYear`, `setPrint`, `setFeature`,
`setDesign`), `preset` (`choosePreset`, or `replaceLayout(defaultLayout())`
for a reset), `layout` (a validated patch of boxes / order / styles applied in
one `store.change()`, normalised and clamped), `replace_layout`
(`replaceLayout`), `step`. A refused command answers `{ ok: false, code,
message }` → `CalendarModelRefused`; a broken run → `CalendarModelUnavailable`
(503). The bridge **never writes**: the caller sends the payload through
`CalendarSavePayload::fromJson` → `SaveCalendar`.

- Node is part of the application image (`ghcr.io/thedevs-cz/php:8.5-wboost`,
  the base of dev, CI and production; production runs node 26, checked
  2026-11-11) and `resources/` is copied into it (`tests/` is not — the script
  must not move there). Needs node ≥ 22.7, like the suites.
- One spawn ≈ 60–100 ms, once per MCP calendar call. No web request uses it.
- `cal.numberSide` takes `start` / `middle` / `end` on the patch path since
  2026-11-13 (the editor always offered „Na střed“; the enum lacked it).
- What only the script knows (keep in sync with the UI when a control is
  added): the `ENUMS` table of word-valued style keys and the patch rules
  (unknown key → refusal naming the key and its siblings; `family` must be a
  project face; `*color` keys are `#RRGGBB`; `null` only where
  `defaultStyles()` has `null`; `OPEN_MAPS` entries merge one level, `null`
  removes). Box and style **keys** are read from the calendar's own layout at
  run time — nothing is listed twice.
- **Auto-grow is estimated there.** Měsíc / Číslo týdne grow to their widest
  text with the estimator measurer (no fonts in node); the browser regrows them
  with real metrics at the next committed change.
- JSON objects that pass through PHP arrays come back as lists when empty or
  keyed `0…n` (`iconCells["4"] = {"0": …}`); the script reads such lists as
  maps (`asMap`). The payload itself is decoded as `stdClass`, so stored maps
  stay objects.

**`renderDocument()` — a page of an UNSAVED document** (since 2026-11-13).
`CalendarPageRenderer::renderDocument($calendar, $document, $pageKey, …)`
draws a calendar document that is not what is stored — the shape of
`CalendarDocument::calendar()`, with a design changed in memory; the row
still supplies the project (fonts, palette) and the uploaded pictures (those
the document's own layout names are inlined too). `render()` IS
`renderDocument()` with the stored document, so the two cannot drift
(`CalendarRenderDocumentGotenbergTest`). Its one caller besides `render()` is
the AI scan's candidate (`Services/AiScan/Calendar/CalendarCandidate`).

**The renderer in Chromium — `Services/Calendar/CalendarPageRenderer`.** One
page as PNG / WebP through Gotenberg, the vehicle of the template renders:
`templates/calendar/headless_render.html.twig` loads
`assets/calendar/headless/render.js` (`normalizeDocument` → `buildPages` →
`renderPage` with the **canvas** measurer after `ensureFontsLoaded`) — the
workspace's pipeline, so the picture is what the web shows. Self-contained:
`CalendarModuleInliner` rewrites every relative import of `model/ generate/
render/ editor/ headless/` to a bare `wbcal/…` specifier and ships the sources
as an import map of `data:` URLs (Gotenberg flattens uploaded files and
`file://` modules are blocked — an import map needs neither); fonts and the
pictures the page shows are data URIs (`AssetInliner`; the preview derivative
when there is one). The page list, the view box (→ viewport size) and the
warnings come from the node model, because a screenshot call can hand no data
back. A render error is an uncaught exception → Gotenberg 409
(`failOnConsoleExceptions`); a busy renderer → `TemplateRenderUnavailable`,
classified by `TemplateVariantImageRenderer::isRendererOverloaded`. Buffered
bytes only, never a streamed response. Without project fonts the fallback face
is Chromium's sans-serif, not Outfit (not shipped to Gotenberg).

## Scanned layouts — AI scan „Naskenovat design“ (2026-11-13)

Wizard step 2 of a 7-day calendar can take its layout from a picture of a
calendar page: the AI scan (`docs/architecture/ai-scan.md` § "Calendar
scans" — the job, the proposal, accept, restore, the scanner's side). What
the calendar module itself carries:

- **`calendar.layout_origin`** — `scan` after a scanned layout was applied;
  the rules of the marker are in "Save contract". Step 2 shows the third
  radio „Naskenovaný design“ while it is set; choosing a preset card then
  REPLACES the layout with that preset's default (never mirrors the scanned
  one) and clears the marker.
- **Accept and restore are saves.** `AcceptCalendarScan` /
  `RestoreCalendarScan` lock the row, compute the design in the model
  (`replace_layout`, switches on) and go through `SaveCalendar` — same
  validation, revision, history. Content is never part of them, so a client's
  save in between is neither blocked nor lost. Accept keeps the previous
  layout, layout choice, switches and marker with the scan until the scan's
  `expires_at`; restore writes them back and asks for confirmation when the
  layout or the switches changed since (`layout_changed`). Scans applied one
  after the other form a stack — only the one applied last can be restored,
  and restoring it brings back the earlier scan's layout WITH its marker
  (`CalendarScanRestoreOrder`, `ai-scan.md` § "Restore").
- **A candidate is never saved.** The scanner's renders go through
  `renderDocument()`; the calendar gets no revision, history line or asset
  before „Použít rozvržení“. Pictures added in the review are held with the
  scan and become assets (kinds `logo` / `image`) at accept only.
- **The client link cannot do any of it**: `calendar_scan` needs
  `calendar_edit`, has no `/k/{token}` twin, and the workspace bootstrap
  carries `env.scan` (the scan addresses) for designers only.
- **The export fingerprint did not move**: nothing under
  `assets/calendar/{model,generate,render,editor,headless}/` knows about
  scans; `CalendarExportFingerprintTest` pins it. The scan's browser code is
  `ui/scan.js` (+ `ui/step2.js`, `ui/topbar.js`) and the marker lives in the
  store beside `doc`, never in `doc.design`.

## PDF export — „Stáhnout PDF“ (2026-11-11)

Whoever can open a calendar can take it away as a PDF: designer, admin,
read-only member, the client on `/k/{token}`. Three variants
(`Value/Calendar/CalendarExportVariant`; the CMYK one since 2026-11-12, see
"CMYK variant" below):

| Variant | Pages | Photos | For |
|---|---|---|---|
| `print` „Pro tisk“ | what step 5 shows the designer: the bleed when it is on, crop marks when they are on. Sheet = the view box of `pageViewBox(design, { bleed, marks })` — trim + 2 × bleed, with marks trim + 2 × (bleed + 2 mm gap + 5 mm mark + 1 mm) = **336 × 171 mm** for the default page with 5 mm bleed | the uploaded file, byte for byte | the print shop |
| `approval` „Náhled k odsouhlasení“ | trimmed (310 × 145 mm), no bleed, no marks | the ≤ 1600 px preview, as JPEG | e-mail |
| `print_cmyk` „Pro tisk (CMYK)“ | the `print` sheets, every colour DeviceCMYK, PDF/X-4 with the Coated FOGRA39 output intent; crop marks in the registration colour | the uploaded pixels, re-encoded as CMYK JPEG (ICC) | a print shop that asks for CMYK |

**The file is vector.** The page SVG is *printed* by Gotenberg's Chromium, not
screenshotted (`templates/calendar/headless_print.html.twig` →
`renderHeadlessSheets()` in `headless/render.js`: one `.sheet` per page, exact
in mm). Chromium's PDF backend (Skia) writes SVG text as text with the fonts
embedded (subset, Identity-H, with ToUnicode — the text can be selected and
searched), shapes, gradients, clip paths, opacity and dashes as vector
operators, and every `<image>` as an image object with the pixels of its
file; **a JPEG is copied in with its own data** (`pdfimages -list` shows
`3600 × 2400 jpeg` for a 3600 px upload — also under the `transform` of a turned or
mirrored photo: a 3816 × 5400 JPEG under `rotate(7)` / `rotate(90)` / `scale(-1 1)` /
all three came out with the identical md5, while a CSS `filter: brightness()` on it
rasterised it to 1892 × 1184 at 300 ppi, K14 spike). Measured on a page using everything
the renderer can draw at once: the only thing Chromium rasterises is the
element under an SVG **filter** — a drop shadow (`feGaussianBlur`) becomes
one 300 ppi RGB image + soft mask of the blurred rectangle; the photo above
it, the texts and every other shape stay as they are. That is why the layer
effects never filter a picture itself (§ "Image effects").

Print gets `placeholders: 'none'` (nothing invented goes on paper — an empty
photo frame stays empty), approval `'frames'` (the grey "a photo is missing"
frame, as on screen). **The row of binding holes is NOT drawn in the print
file** (`holes: false` of `renderPage`, set by `CalendarPdfRenderer` for the
print variant only): the holes are punched at the bindery, and white squares
printed over the header art would be a print defect. The approval file shows
the finished product, so it keeps them — as does every screen (the page
renderer's default stays `holes: true`).

### Pipeline — `Services/Calendar/`

`CalendarPdfExporter::run(exportId)` (the job) → `CalendarPdfProducerInterface`
(`CalendarPdfProducer`; tests swap in `tests/Fakes/FakeCalendarPdfProducer`):

1. **Plan** (`CalendarPdfRenderer::plan` → `CalendarPdfPlan`): the page list and
   the sheet size come from the calendar's JavaScript (`CalendarModelBridge`,
   `view`), never from PHP; the font faces are read once.
2. **Chunks** (`CalendarPdfProducer::chunks`): consecutive pages, at most 6
   pages and 16 MB of page-bound pictures per chunk (a heavier page is a chunk
   of its own). A year is never one HTML document — 55 photos of up to 10 MB.
3. **Render a chunk** (`CalendarPdfRenderer::renderChunk`): the HTML is written
   to a temp file piece by piece and the uploaded photos are **streamed** into
   it from the storage through a base64 stream filter
   (`CalendarRenderAssets::inlined(…, $deferred)` puts a token where the data
   URI goes; `writeDataUri()` fills it), sent to Gotenberg as a file
   (`contentFile`), the answer written to a file (`FileProcessor`). PHP's
   memory stays flat (~70 MB peak for a 165 MB export) whatever the photos
   weigh. `CalendarPictureSource` says which file an asset gives: `Preview`
   (screenshots), `PreviewJpeg` (approval), `Original` (print).
4. **Assemble** (`Services/Print/PdfChunkMerger`, called by
   `CalendarPdfProducer::assemble`): the chunk files are copied into ONE file
   on the local disk — every object under a new number, its dictionary
   rewritten only where it names another object, its stream copied byte for
   byte, the chunks' page trees hung under a new root. Constant memory, ~1.3 s
   for 527 MB, no size limit but the disk. While copying it
   - gives every page its exact MediaBox / CropBox, TrimBox and BleedBox
     (`PdfPageBoxes::withBoxes`) and the file one Info dictionary;
   - **deduplicates**: a stream object whose bytes an earlier chunk already
     brought (the header / background picture every chunk carries, its colour
     profile, a font file) is written once. A year with a 4 MB background and
     a 1 MB header picture went from 796 MB to 527 MB.
5. **Fallback**: if a chunk is not what the merger reads
   (`PdfStructureNotSupported` — a cross-reference stream, an earlier update,
   encryption: a future Chromium), the chunks go through Gotenberg's
   `/forms/pdf/merge` (litho, Gotenberg 9 — posted directly by
   `CalendarPdfMerger`: gotenberg-bundle 1.x knows only Gotenberg 8's
   `/forms/pdfengines/merge`, which 9 answers with a 404) and
   `PdfPageBoxes::apply` appends the boxes as an incremental update — the
   2026-11-11 first version of this step, good up to roughly 400 MB (the whole
   file twice in Gotenberg's `/tmp`, inside one HTTP call). A warning is
   logged.
6. **Store** under `calendars/{calendarId}/exports/{40 hex}.pdf` with
   `ContentType` + `ContentDisposition` on the object, mark the row `done`,
   prune: the newest `CalendarPdfExporter::KEEP` (6) exports of a calendar stay,
   older rows and their files go (a pending job is never pruned).

### The job

- `CalendarExports::request()` dispatches `RequestCalendarExport` (command bus:
  locks the calendar row, so two requests at once end in ONE job; refuses a
  calendar without a layout; writes the history line `Stáhl PDF pro tisk` /
  `Stáhl PDF pro tisk (CMYK)` / `Stáhl PDF k odsouhlasení`; creates the row unless one is reusable) and then
  `RunCalendarExport` for a row it created.
- **Reuse**: a `done` (or still pending) export of the same variant with the
  same `calendar.revision` and `renderer_hash`
  (`CalendarModuleInliner::fingerprint()` — a hash of the page modules and the
  two headless templates, so a deploy that changes the renderer invalidates
  old files) answers the request at once. Every request still writes its
  history line.
- **`RunCalendarExport` runs on the `job_bus`** (`config/packages/messenger.php`):
  routed to the `async` transport, and on a bus WITHOUT `doctrine_transaction`,
  because the job flushes its status and progress for the polling browser
  while it runs — inside the command bus's transaction nothing would be
  visible until the end. Handlers of such jobs say
  `#[AsMessageHandler(bus: 'job_bus')]`; inject the bus with
  `#[Autowire(service: 'job_bus')]`.
- The job renders what is saved **when it runs** and writes that revision into
  the row. It never throws: a failure is a `failed` row with a Czech sentence
  (`CalendarExports::FAILED` / `BUSY` / `NO_LAYOUT`) + an error log — no
  Messenger retry (the user has „Zkusit znovu“). A busy Gotenberg is retried
  three times per chunk first.
- **A stalled job** (consumer restarted mid-export, or none running): a
  non-final row silent for `CalendarExport::STALE_AFTER_SECONDS` (10 min)
  *reads* as failed (`STALLED`) and no longer blocks a new request. Nothing
  rewrites the row.
- Dev and CI run no consumer. Tests route the job `sync`
  (`config/packages/test/messenger.php`), so a POST answers the finished
  export; in dev run `bin/console messenger:consume async` (the e2e script
  starts its own) or export in the foreground:
  `bin/console app:calendar:export-pdf <calendarId> [print|approval]` — it
  reports time, size and PHP's peak memory.

### Endpoints — `CalendarExportHttp` behind both route families

GET `…/export` → `{ exports: { print, approval } }` (per variant the export
that answers a request right now — a job on its way or the finished file of
the saved calendar — else null); POST `…/export` (`_token`, `variant`) →
`{ export }`; GET `…/export/{id}` → `{ export }` (polled every 1.5 s);
GET `…/export/{id}/download`.
`export = { id, variant, status, done, total, error, sizeBytes, fileName,
urls: { status, download } }`. An export is always looked up **inside its
calendar** (`CalendarExportRepository::getOfCalendar`) — another calendar's
export id is a 404 through any address. The download is a **buffered**
`Response` up to `CalendarExports::BUFFERED_DOWNLOAD_BYTES` (32 MB) and a 302
to the storage URL above it (the key is unguessable and the object carries
its own `Content-Disposition`) — a full print year is 150–500 MB, which no PHP
response should hold, and a flushing `StreamedResponse` is forbidden
(CLAUDE.md). File name: `Value/DownloadFileName` — `{název} - tisk.pdf` /
`{název} - náhled.pdf`.

### CMYK variant — „Pro tisk (CMYK)“ (2026-11-12)

The print file again, for offset print: **the same vector pages** — live text
with the embedded fonts, shapes, gradients, live transparency, photos with
the pixels they were uploaded with — and **not one RGB colour left**. Nothing
is rasterised and nothing is drawn a second time: the RGB chunks Chromium
prints are rewritten colour by colour (`Services/Print/Cmyk/`), then merged
into a PDF/X-4 frame. A 2026-11-11 spike had shown why Ghostscript cannot do
it (`pdfwrite` + FOGRA39: `#000000` text → 89 / 78 / 62 / 97, no brand
palette, and `-dBlackText -dBlackVector` blackens every shape).

**The colour rules** (`CmykColorRules`; colours are compared as 8-bit values —
Chromium writes n / 255 with four decimals, so a hex match is exact):

| # | A vector colour (fill, stroke, text, gradient stop, colour-table entry) that… | prints as |
|---|---|---|
| 1 | is **neutral**, R = G = B | **black ink only**: 0 / 0 / 0 / K, K = 1 − value / 255 (`#000000` K 100, `#808080` K 49.8, `#FFFFFF` no ink). A palette entry of that hex whose CMYK is K-only gives its own K (`#C6C6C6` = 0/0/0/30); a palette entry with C, M or Y is ignored — neutral is never four-colour |
| 2 | equals a **project palette** colour with a usable CMYK (`BrandCmykPalette`, hex match) | the palette's CMYK, to the digit |
| 3 | paints **text** and is nearly neutral — R, G, B within 16 of each other (`TEXT_NEUTRAL_SPREAD`) | **black ink only**, K = 1 − luma / 255 (0.299 R + 0.587 G + 0.114 B). The calendar's default text colours are `#2B2830` and `#6E6977` — tinted greys the profile would spread over four plates (`#2B2830` → 83 / 82 / 52 / 75); as text they are K 83.6 and K 57.6. Fills and strokes keep their tint (the lavender paper stays lavender). **For K 100 text set the text to `#000000`** |
| 4 | is anything else | **ICC**: sRGB → Coated FOGRA39, relative colorimetric + black point compensation (`IccColorTransform`, lcms through Imagick — the conversion of the template print pipeline), each colour once per export |

Pictures: a **JPEG** is decoded, converted through the profile (its own
embedded profile is honoured, else sRGB) and re-encoded as a CMYK JPEG —
same pixels, quality = the source's own (clamped to 80–95: a higher one only
stores the source's artefacts more faithfully, at 3 × the bytes), no chroma
subsampling (ImageMagick can only subsample the chroma together with the
BLACK plate, which would halve the resolution of the shadows — so a 4:2:0
upload grows about 2.3 ×);
ImageMagick writes Adobe-style inverted YCCK, so the image gets `/Decode
[1 0 1 0 1 0 1 0]`. A **losslessly stored** picture (PNG upload, with or
without alpha) stays lossless (Flate, DeviceCMYK); if EVERY pixel of it is
neutral — a QR code, the black rectangle Chromium rasterises for the photo
shadow — it becomes **DeviceGray**, black ink only. Soft masks are kept as
they are. A JPEG with one channel stays DeviceGray.

**What Chromium (Skia/PDF, Gotenberg 8) writes, and what the pass does with
it** — found by dumping a page that uses every feature at once
(`qpdf --qdf`), then by letting the pass fail on whatever it did not know:

| Construct in the chunk | Where it comes from | Rule |
|---|---|---|
| `r g b RG r g b rg` (always both, 4 decimals) | every fill, stroke and text | `c m y k K` / `k` by the rules above; `BT … ET` under a nearly neutral fill gets the K-only colour in front of `BT` and the tinted colour again behind `ET` |
| `/Pattern CS/Pattern cs/P8 SCN/P8 scn` + shading pattern (`PatternType 2`, `ShadingType 2` axial / `3` radial, `/ColorSpace /DeviceRGB`, `FunctionType 2` with `C0` / `C1`, or `FunctionType 3` stitching for more stops) | gradients (header, background, month, SVG logos) | space → DeviceCMYK, every stop converted like a colour; between stops the inks interpolate linearly |
| tiling pattern (`PatternType 1`, `PaintType 1`) wrapping `gs` + a shading pattern | a gradient with **stop opacity** | content rewritten, resources walked |
| ExtGState `/SMask << /S /Luminosity /G form >>`, the form a transparency group `/CS /DeviceRGB` painted with a GREY gradient (`C0 [1 1 1]`, `C1 [.6 .6 .6]`) | the opacity of those stops | the group becomes `/CS /DeviceGray`, its colours the PDF luminosity of the RGB ones (0.30 R + 0.59 G + 0.11 B) — the mask's opacity is exact |
| ExtGState `/ca`, `/CA`, `/BM /<mode>`, `/LW /LC /LJ /ML /SA` | opacity (kalendárium, caption, month backgrounds, pictures), line styles, the blend mode of a layer effect (all 16 PDF modes: Normal … Luminosity — measured 2026-11-23) | copied; an unknown key or a blend mode outside the 16 fails |
| Form XObject, `/Group << /S /Transparency /I true >>` | a picture with opacity, clipped groups | content rewritten; a group `/CS` → DeviceCMYK |
| Image `/DCTDecode`, `/ColorSpace [/ICCBased n]` (N 3), `/ColorTransform 0` | a JPEG upload, bytes untouched | CMYK JPEG, see above; the RGB profile object is dropped |
| Image `/FlateDecode` `/DeviceRGB` + `/SMask` (DeviceGray) | the filter-rasterised photo shadow (300 ppi), PNG uploads | DeviceGray if all-neutral, else DeviceCMYK; the mask untouched |
| Type 0 fonts (`CIDFontType2`, Identity-H, ToUnicode, `FontFile2`) | text | copied byte for byte |
| dashes, clip paths, `cm`, `W n`, `re`, Béziers | everything else | untouched |
| page `/StructParents`, `/Tabs` | accessibility leftovers of the print | dropped |

Also handled by rule, though Chromium was not seen writing them: `sc` / `scn`
in DeviceRGB or a named RGB space (ICCBased N = 3, CalRGB), `sh`, sampled
functions (`FunctionType 0`, 8-bit), `Indexed` colour tables (their entries
follow the vector rules), Type 3 fonts (their glyph procedures are content
streams), stencil masks, `/Background` of a shading, spot colours with a
CMYK / grey alternate.

**What makes the export fail** (`CmykConversionFailed` → the row fails with
`CalendarExports::CMYK_FAILED`, „Převod do CMYK se nepodařil. Použijte prosím
variantu „Pro tisk“ (RGB) — tiskárna si barvy převede sama.“; the reason is in
the log): an operator that is not a PDF operator, an inline image, a
PostScript calculator function (`FunctionType 4`), a mesh shading (types
4–7), an uncoloured tiling pattern, Lab / spot colours that fall back to
RGB, a picture that is JPEG 2000 / colour-key masked / not 8-bit / has
`/Decode` or a pre-multiplied (`/Matte`) soft mask, a colour picture inside a
luminosity mask, transfer / black-generation / halftone entries in an
ExtGState, an unknown blend mode, a form without resources of its own, an
unknown entry of a page / group / pattern / shading / resource dictionary —
and a chunk that is not a classic-xref file. **There is no fallback to a
raster and none to Ghostscript**: a CMYK file that is wrong about colour is
worse than the RGB one.

**Pipeline** (`CalendarPdfProducer`, steps 3–4 of the list above changed):
each chunk is converted right after it was rendered
(`PdfCmykConverter::convert`: read with `PdfReader`, walk from the pages
through everything that carries colour **by type**, write with `PdfWriter`;
what the walk does not reach is not written) and the RGB chunk is deleted.
One `CmykPass` serves the export: the colour table, and the converted
pictures by the digest of their source stream — a header / background
picture every chunk repeats is converted once (kept while consecutive chunks
ask for it; a page photo is dropped with the next chunk). Pictures go file →
file, one at a time; ImageMagick's pixel cache is capped at 64 MB, the rest
lives in its disk cache inside the job's work directory. `PdfChunkMerger` then merges with `consume` (the work
directory never holds the file twice) and `printCondition`: header
`%PDF-1.6`, `/OutputIntents` with the embedded profile
(`GTS_PDFX`, `FOGRA39`, „Coated FOGRA39 (ISO 12647-2:2004)“), XMP metadata
(`pdfxid:GTS_PDFXVersion` PDF/X-4, title, dates, document id = the trailer
`/ID`), `/Trapped /False`.

**Crop marks are the registration colour** (`/Separation /All`, tint 1,
0.25 pt): on all four plates, as print shops expect of marks — a black-only
mark would say nothing about the other three plates. The page therefore
leaves the mark band EMPTY for this variant (`drawMarks: false` of
`renderPage`, boot key `drawMarks`) and the pass strokes the eight lines in a
content stream of its own; `CalendarPdfPlan::cropMarks()` repeats the
geometry of `cropMarks()` in `render/page.js` (`MARKS` → `MARK_GAP_MM`,
`MARK_LENGTH_MM`, `MARK_WEIGHT_PT`; `CalendarPdfPlanTest` reads the two JS
files and fails when they move). Chromium's page content changes the
coordinate system outside any `q … Q`, so the page's `/Contents` becomes
`[ q, the page, Q + marks ]`.

**The verifier** (`PdfRgbVerifier::assertNoRgb`, run by the producer on the
MERGED file — a failure fails the export): it shares no decision with the
converter and looks at every object, reachable or not — no `/DeviceRGB`,
`/CalRGB`, `/Lab` name anywhere, every `/ICCBased` profile has 1 or 4
components, every content stream (page contents, forms, tiling patterns,
Type 3 glyphs) is lexed and holds no `rg` / `RG` / `/DeviceRGB cs` / inline
image, every JPEG has the component count its colour space needs. Constant
memory (pictures are never loaded). `PdfRgbVerifierTest` hands it eleven
files with ONE leftover each.

**PDF/X-4 — what is and is not claimed.** Present: PDF 1.6; output intent
`GTS_PDFX` with the embedded CMYK profile; only DeviceCMYK, DeviceGray and
`/Separation /All`; every font embedded (Chromium); MediaBox ⊇ BleedBox ⊇
TrimBox on every page; `/Trapped`; XMP with `pdfxid:GTS_PDFXVersion`
matching Info; trailer `/ID`; no encryption, JavaScript, annotations,
transfer functions, halftones, OPI, alternates, LZW; live transparency
(allowed in X-4) with the 16 standard PDF blend modes (the layer effects'
`mix-blend-mode`; a transparency group blends in DeviceCMYK, so the
non-separable modes Hue / Saturation / Color / Luminosity and Difference /
Exclusion follow the PDF rules for CMYK and a RIP may differ slightly from
the screen — proof before a run that relies on them). **Not validated by a preflight
tool** — none of the free ones checks X-4 (veraPDF: PDF/A and UA only;
Ghostscript only *writes* X; qpdf checks syntax). Checked instead: `qpdf
--check` clean, Ghostscript renders every page without a warning, poppler
reads fonts / text / images, our own verifier, and the separations
(`gs -sDEVICE=tiffsep`) looked at. Before the first real print job the file
should go through Acrobat Preflight or callas once; the likely findings, if
any: the absent `xmpMM:History`, or a RIP's opinion on DeviceGray inside
luminosity groups.

**Numbers** (dev stack, Apple silicon, 2026-11-12; the worst case through the
real async path — request over HTTP, a consumer started as production's with
`--memory-limit 256M`; the RGB figures of the same calendars are in the table
below):

| Calendar | Pages / chunks | Total | of it the colour pass | File (RGB print file) | Consumer RSS peak | Work directory peak |
|---|---|---|---|---|---|---|
| **worst case**: 53 photos of 5400 × 3600, 9.8 MB each (4:2:0, quality 81) + a 4.2 MB background and a 1 MB header picture | 55 / 53 | **370 s** | 260 s (4.9 s per photo; the two shared pictures converted once, 104 times taken from the pass) | **1 295 MB** (527 MB) | **225 MB** | 2 052 MB |
| the stress calendar: every colour-bearing feature, 3 photos, a background picture on every page | 59 / 10 | 27 s | 5 s | 21 MB (10 MB) | — (99 MB PHP peak in the foreground command) | 41 MB |

- **Time**: the pass adds ~5 s per 19 MP photo (decode, lcms, encode) — a
  year takes 6 minutes instead of 2. The row is touched after every chunk
  (≤ 10 s apart), so the 10-minute "stalled" rule is far away.
- **Size**: ~2.5 × the RGB file for 4:2:0 uploads (four channels, no chroma
  subsampling, and ImageMagick's non-optimised Huffman tables). With
  `KEEP = 6` exports per calendar a calendar at the upload cap can hold
  several GB of exports — the storage report shows them.
- **Memory**: bounded by the 64 MB pixel-cache cap, not by the photo. The
  first version (cap 192 MB + 192 MB mapped) peaked at **605 MB** resident in
  the consumer — against a 768 MB container; with the cap at 64 MB it is 225
  MB. PHP's own peak was 89–192 MB (the higher figure in the dev
  environment, whose debug logger keeps records), under the consumer's soft
  `--memory-limit 256M`.
- **Disk**: the CMYK chunk files lie side by side until the merge consumes
  them, each with its own converted copy of the shared pictures (53 ×
  (4.2 + 1) MB × 2.3 here), plus one decoded photo in ImageMagick's disk
  cache (16 bytes per pixel: ~310 MB for 19 MP, ~1 GB for 60 MP).
  `assertWorkSpace` plans 2.5 × (page pictures + shared pictures × chunks) +
  16 B × the pixels of the largest picture + 200 MB ≈ 2.5 GB for this
  calendar
  and refuses the job up front (`NO_SPACE`) when the disk has less.
- Not measured: the production host (slower disk, the consumer's real
  container limit) and a 60 MP photo in the CMYK pass.

**Traps.**
- The palette is read when the job starts (`BrandCmykPalette::forProject`):
  only colours a manual links, only those with a plausible CMYK. A brand
  colour without CMYK goes through the profile.
- **No overprint.** K 100 text knocks out the colour under it (see "Known
  gaps").
- A gradient between a K-only stop and a coloured stop interpolates the INKS
  (black → brand red passes through a dark red with K), not the RGB values:
  the midpoint differs a little from the screen.
- The grey of rule 1 / 3 is linear in the sRGB value. It is consistent with
  the profile's black point compensation within a few L\*, not a measured
  tone curve.
- `Services/**` is loaded as services: the classes of `Services/Print/Cmyk`
  that are values or per-run objects carry `#[Exclude]` — without it the
  container fails to compile (it did, for every request of the dev stack).
- New Chromium → run `ChromiumChunkCmykTest`; regenerate its fixture with
  `CALENDAR_CMYK_FIXTURE=1 … --group gotenberg --filter CalendarCmyk` and
  read what fails.

### UI — `ui/export.js`, one dialog, four doors

`openExport({ name, url, csrf, toast, unsaved? })` needs addresses only, so it
opens from: the step-5 toolbar (`[data-cal-action="export-pdf"]`, first of the
actions, every role — also the client page and the read-only member), the
step-4 toolbar (designer), every **list card** whose calendar has a layout
(„PDF“, title „Stáhnout PDF“), and the Dokončit toast on the list
(`sessionStorage['wb-cal-toast-calendar']` names the calendar; `toast()` takes
an optional `{ label, onClick }` action). Three radio cards (`.rpick > .rcard`: Pro tisk, Pro tisk (CMYK), Náhled k
odsouhlasení) pick the variant; with unsaved changes the dialog says the export uses the saved
state and the primary action becomes „Uložit a exportovat“. Progress
„Připravuji PDF… 12 z 55 stran“; the download starts through a hidden
**iframe** (a navigation would trip the workspace's `beforeunload` guard) and
a „Stáhnout“ link stays. Closing the dialog only stops the polling: **on
opening, the dialog asks what is there** (GET of the request address) — a job
on its way, whoever started it and wherever, is picked up and its progress
shown without a click; a finished file is marked on its card („Připraveno ·
527 MB“) and the action reads „Stáhnout PDF“. Two people asking for the same
variant at the same time share one job (the request handler locks the
calendar row and finds the pending export). In step 5
the actions are pinned and only the tools scroll (two rows under 960 px).

### Numbers (dev stack, Apple silicon, 2026-11-11)

Through the real async path — a consumer started as production's
(`messenger:consume async --memory-limit 256M`), the request and the polling
over HTTP. **Worst case**: every week photo a 5400 × 3600 JPEG of 9.8–9.9 MB
(just under the 10 MB upload cap; 9.9 MB is accepted and 10.1 MB refused with
„Soubor je větší než 10 MB.“ on both route families), plus a 4.2 MB background
and a 1 MB header picture on every page.

| Calendar | Variant | Pages / chunks | Total | Slowest chunk | Assemble | Store („Dokončuji…“) | File | PHP peak (consumer RSS) | Gotenberg peak |
|---|---|---|---|---|---|---|---|---|---|
| 7 days, 53 photos, 521 MB of uploads | print | 55 / 53 | 118 s | 4.4 s | 1.3 s | 5.9 s | 527 MB | 89 MB (114 MB) | 835 MiB |
| the same | approval | 55 / 10 | 21 s | 2.5 s | 0.2 s | 0.9 s | 21 MB | 74 MB (128 MB) | 411 MiB |
| 14 days, 27 photos, 265 MB | print | 29 / 27 | 62 s | 3.9 s | 0.5 s | 2.7 s | 271 MB | 87 MB (110 MB) | 735 MiB |
| the same | approval | 29 / 5 | 11 s | 2.5 s | 0.1 s | 0.7 s | 11 MB | 72 MB (116 MB) | 335 MiB |
| 53 photos of ~3.2 MB (the "ordinary" year) | print | 56 | 18 s | — | — | — | 165 MB | 69 MB | ~730 MiB |
| two 9000 × 6600 (59 MP) photos in one chunk | print | 55 / 10 | 6.5 s | 2.1 s | 0.1 s | — | 14 MB | 78 MB | — |

Against production's limits (read 2026-11-11): the consumer's soft
`--memory-limit 256M` and its 768 MB container — 89 MB peak, the worker is not
restarted; Gotenberg's 5 GB — under 1 GB; its 30 s API timeout and the
client's 20 / 25 s cap — the slowest call took 4.4 s; its 1 GB `/tmp` tmpfs —
one chunk at a time (~15 MB in, ~15 MB out; `du /tmp` there stayed at 300–430
MB, of which ~300 MB were Chromium's own profile directories before the
export started). The work directory of the job peaked at 963 MB (the chunks
plus the growing merged file). The longest time the progress stood still was
6 s; the 10-minute "stalled" rule is nowhere near. The 527 MB download (a
redirect to the storage object) arrived in Chrome in 3 s. The file: 55 pages
of 336 × 171 mm with TrimBox / BleedBox, 214 font entries all embedded, 162
image placements over 55 image objects (`5400×3600 jpeg` × 53 + the two shared
pictures, once each), `qpdf --check` clean.

### Limits and traps

- **Chromium rounds a PDF page to whole points** (336 mm came out as
  952.08 pt, 171 mm as 485.04 pt). The page is therefore printed on the next
  whole point UP (`@page` in pt) with the exact sheet in its top-left corner,
  and `PdfPageBoxes` cuts the MediaBox back, anchored at the top (the
  MediaBox then starts at `y = 0.32`, not 0 — legal; poppler, Ghostscript
  and qpdf read it). If the boxes cannot be set, the page is ≤ 1 pt larger at the
  right / bottom and has no TrimBox.
- **`print` and `approval` are RGB** (sRGB-tagged photos, DeviceRGB
  vectors, no output intent). The CMYK file is the third variant — see
  "CMYK variant".
- **Every Gotenberg call must fit its limits**: the scoped client's
  `timeout: 20` / `max_duration: 25` and Gotenberg's own 30 s API timeout.
  Only chunks travel there now (≤ 6 pages, ≤ 16 MB of page photos — a 10 MB
  photo is a chunk of its own): 0.3–4.4 s each. A busy renderer is retried
  three times per chunk.
- **The ceiling that is left is the disk of the job's work directory**
  (`sys_get_temp_dir()` of the consumer): the chunk files and, for a moment,
  the merged file beside them — about twice the calendar's pictures, 963 MB
  for the worst case above. `CalendarPdfProducer::assertWorkSpace` checks
  `disk_free_space` BEFORE rendering (2 × the bytes of the pictures the
  calendar shows + 200 MB; for the CMYK variant 2.5 × (the page pictures +
  the shared pictures ONCE PER CHUNK — every CMYK chunk file carries its own
  converted copy of the header / background picture until the merge writes
  them once) + 200 MB) and fails the job at once with
  `CalendarExports::NO_SPACE` („Na serveru teď není dost místa pro tak velké
  PDF. Zkuste to prosím později, nebo si stáhněte Náhled k odsouhlasení.“).
  Nothing else scales with the file: PHP's memory is flat, the upload to the
  storage is a stream.
- **Heartbeats**: the row is touched after every chunk, after the assembly
  and before the upload, so the "stalled" rule (10 min of silence) cannot
  fire on a healthy job. Once every page is drawn (`done = total`, still
  `running`) the dialog says „Dokončuji soubor…“.
- **One consumer, one export at a time** — and e-mails / domain events queue
  behind a running export (seconds to a minute). A second consumer on a
  dedicated transport would isolate them; not done.
- **Pictures the layout shares** (a header or background picture) travel in
  every chunk, but the file holds them once (the merger's deduplication). On
  the Gotenberg fallback path they would be in the file once per chunk.
- **Glyphs missing from the project font** fall back to Chromium's default
  face in the PDF, while the workspace falls back to Outfit (a Google font the
  headless page cannot load). A font without Czech diacritics therefore looks
  different in the PDF; a complete font does not.
- **A WebP / PNG is stored losslessly by Chromium's PDF backend** (a 300 kB
  WebP preview took 3–4 MB). That is why the approval variant re-encodes
  previews as JPEG (`CalendarRenderAssets::opaqueAsJpeg`, pictures with
  transparency excepted) — and why a PNG *upload* is heavy in the print file.
- `Browser.setDownloadBehavior` is per browser context — an e2e script that
  watches downloads in an anonymous context must pass its `browserContextId`.

## MCP — `src/Mcp/Tool/*Calendar*Tool.php`

Seven tools; a calendar can be authored end to end from an AI client. Scopes
`calendars:read` and `calendars:write` (write ⇒ read; **no implication to or
from the template scopes**, so a calendar-only token cannot call `get_context`
— hence `list_calendars` without a project lists every reachable project's
calendars and `get_calendar` carries the project's fonts and palette).

| Tool | Scope | Does |
|---|---|---|
| `list_calendars(projectId?)` | read | the calendars of one / every reachable project |
| `get_calendar(calendarId, historyLimit?)` | read | `CalendarDocument` materialised by the model: settings, layout **with styles**, `pages` (page keys), content, assets, fonts, palette, history (≤ 200). Never the client token. Falls back to the stored values (`pages: null`) when the model cannot run |
| `render_calendar_page(calendarId, page, bleed?, marks?, sampleTexts?)` | read | JSON summary + WebP (1600 px) of one stored page |
| `manage_calendar(action, …)` | write | `create` (`CreateCalendar`, then the settings), `configure` (name, year, type, features, print, linkElements), `finish`, `client_link` (get = `RecordCalendarHistory` LINK_COPIED, `regenerate` = `RegenerateCalendarClientLink`), `import_layout` (`ImportCalendarLayout::plan` + `CopyCalendarAsset` + history, then `replace_layout`), `export_pdf` (`CalendarExports::request` — the web's own request; answers `export`) and `export_status` |
| `set_calendar_layout(calendarId, preset?, reset?, layers?, boxes?, order?, styles?)` | write | preset → default layout (or mirror / reset), then a validated patch (own layers first, so `boxes["u:<id>"]` / `order` can place a layer added in the same call) |
| `upload_calendar_asset(calendarId, kind, fileBase64, filename)` | write | `CalendarAssetUploader::storeFile()` — the method both web routes end in |
| `update_calendar_content(calendarId, operations[])` | write | item operations `{collection, id, value\|null, label?}` re-encoded as the web payload's `content` list — including `pageContent` / `pageGroups` (and `pageSetup` for a designer); shapes, caps and examples are in the tool's description and `references/tools.md` |

- **One write path.** Design tools phrase model commands; `Mcp/Fill/CalendarWriter`
  runs the model, parses its payload with `CalendarSavePayload::fromJson` and
  dispatches `SaveCalendar` — same validation (`CalendarDesignRules`, asset
  ownership), row lock, history (`Upravil vzhled a nastavení`, coalesced per
  10 minutes), revision. Content goes the same way without the model. No tool
  mutates a `Calendar`. A command that changes nothing saves nothing.
- **Roles are the web's** (`CalendarAccess::forUser`): EDIT → designer; a
  member with only VIEW → **client** — refused every design call
  (`designer_only`, checked in `CalendarWriter::designer()` and again by the
  handler), held to the client collections and to `photo` uploads.
  `create` needs `ProjectVoter::EDIT`.
- **`create` validates before it creates**: the settings run on a blank
  document (`runDocument`) first, so a refused request leaves no calendar. If
  the server still refuses the save afterwards, the error names the created
  calendar's id.
- **The client link** is handed out by `manage_calendar` `client_link` only —
  write scope + designer role, recorded in the history — never by a read.
- **PDF export** is two actions of `manage_calendar` — `export_pdf`
  (`calendarId`, `variant`: `print` | `print_cmyk` | `approval`) and `export_status` (`calendarId`, `exportId`) —
  answering `export { exportId, variant, status, pagesDone, pagesTotal,
  revision, fileName, sizeBytes, downloadUrl, error }`. **Any role** may call
  them (the only actions of the tool that are not the designer's), but the
  tool's scope is `calendars:write`: scopes are per tool class, and an export
  is not a look — it writes a history line and stores a file. `downloadUrl`
  is the file's storage address (an AI client has no web session): an
  unguessable key, to be handed to the user only.
- **No delete**, by rule: no tool removes a calendar or an asset.
- Refusals are `message (reason)`. Loader: `invalid_calendar_id`,
  `calendar_not_found` (identical for "not yours"), `invalid_project_id`,
  `project_not_found`. Content: `no_operations`, `invalid_operation`,
  `invalid_content` (quotes the Czech `InvalidCalendarChange` text),
  `designer_only`. Design: the model's codes (`unknown_preset`,
  `preset_type_mismatch`, `no_layout`, `unknown_box`, `invalid_box`,
  `not_hideable`, `invalid_order`, `unknown_layer`, `invalid_layer`,
  `too_many_layers`, `unknown_style_key`,
  `invalid_style_value`, `unknown_font`, `invalid_color`, `unknown_feature`,
  `invalid_print`, `invalid_year`, `invalid_type`, `invalid_name`),
  `invalid_design` (the server's rule, Czech text), `model_unavailable`, and
  per tool `invalid_action`, `missing_argument`, `nothing_to_do`,
  `not_a_sibling`, `layout_type_mismatch`, `source_has_no_layout`,
  `invalid_variant`, `export_not_found`, `invalid_kind`, `invalid_file`, `file_too_large`, `upload_refused`,
  `unknown_page`, `renderer_busy`, `render_failed`.
- Not module-gated (voters only): a calendar created in a project whose
  Kalendáře module is off exists and is reachable by its URL, but the project
  navigation does not show the list until the module is switched on.
- Exposure: the scopes are not in `User::DEFAULT_MCP_SCOPES`, so an OAuth grant
  gets them only after an admin ticks them for the user; PATs are minted by
  the operator. **`calendars:write` meant "content only" until 2026-11-11** —
  a grant made before then now also covers the design (role permitting); the
  consent screen and `/ai` texts say so (`DescribeConsentScopes`,
  `DescribeMcpCapabilities` — both exhaustive).
- Adding a tool or scope touches: `McpScope::grants()`, `DescribeConsentScopes`,
  `DescribeMcpCapabilities`, `EditUserFormType` + `admin/edit_user.html.twig`,
  `plugin/wboost/skills/wboost/SKILL.md` + `references/tools.md`,
  `config/packages/mcp.php` instructions, `docs/mcp/connect.md`,
  `McpScopeTest`, `ScopeFilteringTest`. Tests: `tests/Mcp/Tool/CalendarToolsTest.php`
  (read + content), `CalendarDesignToolsTest.php` (design, uploads, render —
  the render itself is `#[Group('gotenberg')]`), helpers `CalendarMcpTesting`;
  `tests/Services/Calendar/CalendarModuleInlinerTest.php`.

**Not MCP-controllable**, and why: deleting a calendar or an asset (rule);
the page size (no web UI either — `design.page` is not offered); a layout
*file* (`exportLayoutFile` / `parseLayoutFile` are a browser download / upload
— an agent reads `layout` and writes it back instead) and therefore a layout
from another project; `.docx` → text for a special page (send the text; the
`page` upload takes an image or a PDF); the editor's undo stack, selection,
zoom, snap and link behaviour (interaction, not data).

## Tests

| Layer | Where | Run |
|---|---|---|
| PHP | `tests/Controller/Calendar` (export: `CalendarExportControllerTest`; AI scan: `CalendarScanTest`), `tests/MessageHandler/Calendar`, `tests/Services/Calendar` (`CalendarPdfProducerTest`; the real export through Gotenberg is `CalendarPdfProducerGotenbergTest`, `--group gotenberg`), `tests/Services/Print/PdfChunkMergerTest.php` + `PdfPageBoxesTest.php`, the CMYK colour pass in `tests/Services/Print/Cmyk/*` (hand-built PDFs, the Chromium chunk fixture `tests/Fixtures/calendar/pdf/chromium-week-chunk.pdf`, the RGB verifier) and its real export `CalendarCmykExportGotenbergTest` (`--group gotenberg --filter CalendarCmyk`: inks read back through Ghostscript), `tests/Value/Calendar/CalendarPdfPlanTest.php`, `tests/Mcp/Tool/Calendar{Tools,DesignTools}Test.php`; fixtures `CALENDAR_{1,2,3}_ID` (+ tokens) in `TestDataFixture`, helpers `CalendarTesting` | `docker compose exec web vendor/bin/phpunit tests/Controller/Calendar …` |
| Node suites (every pure rule) | `tests/Fixtures/calendar/suites/*.mjs` + `harness.mjs`, `helpers.mjs` | in CI through `tests/Calendar/CalendarJsTest.php` (data provider globs the suites; a new suite file needs no PHP edit, a suite without a test fails); by hand `node tests/Fixtures/calendar/harness.mjs --all` or `… <suite>` |
| Contract | `tests/Calendar/CalendarSeedContractTest.php` (PHP seed vs `BUILTIN_WASTE` via `dump-defaults.mjs`) | CI |
| e2e (real pointer, asserts in mm) | `tests/e2e/calendar/*.e2e.cjs`, `lib/harness.cjs`, `lib/editor.cjs` | `node tests/e2e/calendar/run.cjs [filter]` — **not in CI** |

Image effects: node suite `effects` (normalising, migration of the old
„Stín“, geometry, the print rules — no `filter` / `mask` on any picture —,
ids), `CalendarDesignToolsTest::testPicturesTakeLayerEffects`, the CMYK
export `CalendarCmykExportGotenbergTest` (effects in multiply / color /
screen on the photo and a transparent logo; the photo stays the uploaded
JPEG) and its chunk fixture for `ChromiumChunkCmykTest`, e2e `effects`.

Content of the cover / back / special pages: node suite `special` (every kind ×
layout × empty / typical / overflowing content at three page sizes, markup
parsing, balancing, auto-fit, the warning, escaping), `CalendarContentRulesTest`
(per-key shapes, caps, asset refs, whitelist), `DescribeCalendarChangeTest`,
`CalendarClientTest::testClientFillsInTheTextsOfTheCoverAndTheSpecialPages` +
the forbidden payloads, `CalendarToolsTest::testAnAgentFillsTheCoverIntroContactsAndBackPages`,
e2e `special-content` (designer in step 4, client in step 5, forged requests,
overflow warning, the approval PDF read with `pdftotext`).

- The node harness needs **node ≥ 22.7** (ES-module syntax detection for
  `.js` in a tree without `"type": "module"`); older node prints `{"skip"}` and
  PHPUnit marks the test skipped. The dev / CI image ships node 24. Never put a
  non-suite file into `suites/`.
- e2e: puppeteer-core lives **outside the repo** (`PUPPETEER_DIR`, default
  `~/.cache/wboost-e2e`), env `BASE` (default `http://localhost:8099`),
  `CHROME`, `SHOTS`, `HEADFUL`, `E2E_NO_FIXTURE`. `ensureFixture()` creates a
  dedicated user + project through `docker compose exec` and writes the module
  straight into `project.modules` (it is preview-gated); the scripts never touch
  other projects — the dev DB is usually a prod copy. Setup:
  `tests/e2e/calendar/README.md`.
- Visual checks at 390 / 820 / 1440 px in both themes with
  `docs/redesign/tools/shoot.cjs`, as for every screen.

## Traps and invariants

- **`calendar.layout` is Doctrine type `json_object`**
  (`Doctrine/JsonObjectDoctrineType`, hydrates `stdClass`), not `Types::JSON`.
  The layout holds maps that are empty or keyed by digits (`styles.offsets`,
  `styles.cal.iconCells["4"]["0"]`); a PHP array would send them back as JSON
  **lists** and the document would not survive save → load. For the same
  reason `CalendarSavePayload` keeps `design` as an object tree, and
  `CalendarDocument` casts every other map (`page`, `print`, `features`,
  `content`, each collection, `assets`) to an object before encoding — the
  "Twig / PHP empty hash encodes as `[]`" rule of CLAUDE.md. Anything new that
  emits calendar JSON (an API resource, an MCP response) must do the same.
- **Never put `/preview` into a calendar URL.** `security.php`'s public-path
  regex contains an unanchored `.*/preview`, so any path containing it is
  public at the access-control level. Every calendar route also carries its own
  `#[IsGranted]`; do not rely on access control alone.
- **Never pass a possibly-`null` node to native `Element.append()` /
  `replaceChildren()`** — it prints the text "null". `h()` in `ui/dom.js` skips
  `null` / `false` children; use it.
- **Stale assets in dev.** After JS / CSS edits: `rm -rf var/cache/dev
  public/assets` in the container (a compiled `public/assets` silently disables
  the live dev server; stale digests otherwise). An e2e run against stale
  assets fails in confusing ways.
- **Page keys must stay stable across year and type** — they are the join key
  of four content collections. Never put the year, the date range or the type
  into a key.
- **Comments are insert-only** on the server; keep the two exceptions (retry =
  unchanged, designer date shift) or a retried save / a year change starts
  failing with 422.
- **Uploads are 10 MB decimal** (10 000 000 bytes), one PHP constant + one JS
  mirror. A 310 mm photo at 300 dpi is ~3–8 MB as JPEG; TIFF / PNG exports from
  print workflows will be refused — expect the support question.
- **Design is last-write-wins**; do not promise otherwise in UI copy.
- **A refused save stores nothing** — validation runs fully before
  `replaceContent` / `applyDesign`. Keep that order when adding a rule; MCP's
  atomicity and the client-forbidden test both depend on it.
- **The handler must run inside the bus transaction** (`getForUpdate` locks the
  row). Never mutate a `Calendar` from a controller or a tool directly.
- **The seeded waste kinds are a PHP ↔ JS contract** (`CalendarContentSeed` ↔
  `BUILTIN_WASTE`), and so is `CalendarContentRules` ↔ `model/content.js`
  (`COLLECTIONS`, client whitelist). Change both sides in one commit.
- **Stacked Bootstrap modals** (a confirm from inside a tool dialog) are
  repaired by the private `showModal` in `ui/dom.js` (z-index, `modal-open`,
  focus trap). The client-link regeneration confirms *inside* its dialog for
  the same reason. Do not open a second plain Bootstrap modal on top of one.
- **Step-5 compact rows**: where a day row would be lower than 20 px (phones,
  14-day pages) the row itself becomes the button (`[data-day-row]`, no
  `[data-day-plus]`); the decision is made at the size the page *would* have
  with buttons, so it cannot flip back and forth.
- **Sample texts** (`Popisek fotografie, autor`, `QR`) are drawn only with
  `placeholders: 'all'` — designer style surfaces. Never in steps 4–5, the
  client view or thumbnails.
- **Moon phases are computed astronomically** on their Czech civil date (the
  prototype's mean-cycle approximation was off by a day too often). The `moon`
  suite pins published 2026–2028 instants. **Season starts are computed the
  same way** (`seasonInstants`, suite `generation`: Meeus' own example + the
  24 instants of 2025–2030): autumn begins on 23 September in 2026, 2027 and
  2030, summer on 20 June in 2028. `time.js` carries its own copy of the two
  small conversions (`deltaT`, Czech UTC offset) that are private in
  `moon.js` — change both together.
- **Never hand uploaded bytes to Imagick unchecked** — not even
  `pingImageBlob`. ImageMagick renders SVG through Inkscape and runs MVG / MSL;
  go through `NormalizeImageFormat` / `ImageDecodeLimits::pixelSize`, which
  refuse text first, and check the pixel count before a decode.
- **`CalendarHistoryWriter::prune()` flushes** (so the pending lines count as
  the newest). Call it last in a handler, inside the bus transaction.
- **PDF special pages need Ghostscript** behind Imagick. Verified in the dev
  image only; without it the endpoint answers 422 and images still work.
- **Content of a page vs overrides of a page.** `pageContent` / `pageSetup` /
  `pageGroups` must never join `PAGE_COLLECTIONS` (JS) — „Vrátit na styl“
  deletes everything in those. And `CalendarContentRules` ↔ `model/content.js`
  now also mirror `CONTENT_PAGE_KEYS`, `SETUP_PAGE_KEYS`, `GROUP_PAGE_KEYS`
  and the caps (`CONTENT_LIMITS`).
- **A picture inside a content item must be known to
  `CalendarRenderAssets`** (`PER_PAGE_COLLECTIONS` + `idsOf`): the headless
  page gets a data URI only for assets that class finds — a new nested
  reference (`pageContent[*].photo.asset`) renders in the workspace and is
  missing in the PDF otherwise. The picture of an own layer on a LIST of
  pages (K20 P2) is a per-page picture of those pages
  (`CalendarRenderAssets::layoutAssets` — shared only when something shown
  everywhere uses it too), so a sticker on one week is not inlined into every
  chunk of the PDF.
- **`ensureFixture()` / the e2e runner write to the dev database** — into their
  own project only. Do not point `BASE` at anything that is not a dev stack.

## Known gaps — second iteration

Deliberately not built (handoff §2), with the room left for them:

- **Layout import from a PDF or image in step 3** — the Nahrát layout menu has
  the two rule-based items only. (The AI design scan of step 2 exists since
  2026-11-13 — § "Scanned layouts"; a PDF's first page and 14-day calendars
  are part of it since the same day.)
- **CMYK print file — what is left** (the variant exists since 2026-11-12,
  "CMYK variant"): no overprint is set (K 100 text KNOCKS OUT what lies under
  it — most RIPs overprint 100 % black themselves, ours does not ask for it);
  no formal PDF/X-4 preflight was run (no preflight tool at hand: callas /
  Acrobat); K-only greys are K = 1 − grey level, not a measured tone curve;
  flat-colour PNG logos go through the ICC profile like photos (brand CMYK
  and K-only need an SVG); OpenType-CFF fonts (Type 3 in Chromium's PDF) are
  handled by rule but were not seen in a real export; a print shop has not
  yet proofed a file.
- **Cover, back and special pages**: content is editable since 2026-11-12.
  Not built: free positioning of anything on these pages (they are layouts,
  not a canvas), more than one photo per page, per-group columns or tables
  with more than label + value, hyphenation, a separate logo for the cover,
  the municipality's crest as an own asset (the style's logo is used).
- **Gallery as a photo source** — uploads are from disk only; a calendar asset
  is not a gallery `FileUpload`.
- **Page size UI**, an **asset sweeper** (unreferenced uploads — also the only
  way a calendar that reached the 2 GB client cap could get room again), **client
  autosave** (explicit save can lose work on mobile browsers that skip
  `beforeunload`), **design conflict detection**.
