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
| `calendar` / `Entity/Calendar` | one row = one calendar: the **design** (`name`, `year`, `type` week\|fortnight, `page`, `print`, `features` JSONB, `layout_preset`, `layout` JSONB, `link_elements`, `step`, `finished`) + the **content** JSONB + `client_token` (64 hex, unique) + `revision` (+1 per save) + `layout_origin` (`scan` when the layout was taken from an AI scan, else null — NOT part of the design, § "Scanned layouts") |
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

`{ v: 1, boxes, order, styles }`.

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
  (number side, icon grid, cell sizes…), fills (`head.bg`, `month.bg`, `bg`),
  `icons.order`, `moon`, `waste.kinds[id]` (colour / icon overrides),
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
(`Services/Calendar/CalendarDesignRules`): exact top-level keys, known box
keys with finite numbers (`month2` / `wk2` only beside `cal2`, and like `cal2`
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
| `pagePhotos` | `{asset, zoom 1–3, px, py 0–1}` | yes |
| `pageCaptions` | `{text ≤80}` | yes |
| `pageImages` | `{head, bg}` | no |
| `pageQr` | `{asset}` | no |
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
| `pageContent` | `cover` `{title, subtitle, note ≤120, photo}` · `sp:intro` `{heading, salutation, body ≤4000, signName, signRole, photo}` · `sp:contacts` `{heading, notesHeading ≤40, photo}` · `sp:year` / `sp:waste` `{heading, note ≤200}` · `sp:notes` `{heading}` · `back` `{heading, footer ≤200, name, address ≤300 in ≤ 6 lines, phone ≤60, email, web, imprint ≤200}` — one-line texts ≤ 80 unless noted; `photo` = `null \| {asset, zoom 1–3, px, py}` (the crop model of `pagePhotos`) | designer + client |
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
  `pageSetup` switches them. `notes` reserves the right 24–30 % for ruled
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
  use it), a crop preview dragged with the pointer, a zoom slider.

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
Assets are never deleted individually (replaced photos stay until the calendar
goes — they count as *referenced* in the storage report).

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
| `calendar_document_text` / `calendar_layout_import` / `calendar_client_link` / `calendar_delete` | POST `…/document-text`, `…/layout-import`, `…/client-link`, `…/delete` | `CalendarVoter::EDIT` |
| `calendar_scan` | POST `…/scan/{scanId}/{accept\|restore\|picture}` | `CalendarVoter::EDIT` + the AI scan's USE; a scan of another calendar is 404 — § "Scanned layouts". **No `/k/{token}` twin** |
| `calendar_client` (+ `_save`, `_asset`, `_history`, `_export_current` / `_export_request` / `_export_status` / `_export_download`) | `/k/{token}` (+ `/save`, `/asset`, `/history`, `/export…`) | public; the token is the credential |

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
| `state/` | `store.js`, `undo.js`, `payload.js` (pure, node-tested) and `api.js` (fetch) | pure except `api.js` |
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
  `.cal-pop[data-cal-popover]`, `.cal-dialog` + `[data-cal-choice]`,
  `.toast-n`. The full table is `docs/calendar/architecture.md` §D.10; each
  file's header lists its own.

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
- **Kalendárium row layout** (`week-layout.js`): rows share the frame height;
  under `K.COMPACT_BELOW` the row is *compact* (one baseline, row-mode icons,
  no event box). A row has a number column, a text column anchored towards the
  number, and a *zone* from the other edge (shift columns, icon grid, place
  labels). Coordinates are computed on a **flow axis** from the zone edge, so
  the mirrored frame is the same code. Holiday names are **never truncated** —
  they wrap; a compact row first drops the name day, then widens the wrap.
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
  asset map, nothing stored, never blocking.

### Calendar scan — UI (P5)

„Naskenovat design“ in step 2 (ux.md §6; backend: § "Scanned layouts" below
and `ai-scan.md` § "Calendar scans"). Weekly calendars, designers only.

| File | Role |
|---|---|
| `ui/scan.js` | `createScan(ctx)` → ONE `CalendarScan` per workspace (`ctx.scan`; null for a client or without `env.scan`). Owns the entry facts, the current scan and its proposal; polls `status` while a scan is on its way **in any step** (2 s with the panel on screen, 10 s otherwise); `mount(root)` draws the action card or the panel into step 2's `.cal-scan`. Apply / restore, pictures, cancel / discard / retry |
| `ui/scan/views.js` | markup strings of the card and of the panel's screens (`upload`, `chosen`, `wait`, `fail`, `gone`, `review`, `pictures`), the comparison slider, the outlines of `Ukázat vrstvy` (`layerBoxes`), the numbered places and their crops (`sourceBox`), the rating block, the privacy, access-request and „Naskenovat znovu“ dialogs |
| `ui/scan/preview.js` | the live render of the proposal (`previewDocument` / `previewMarkup`: the calendar's own renderer on a document that exists only for the preview), `cropPicture` (the one canvas), `elementFact` — the phrase beside a recognised element, derived from the layout |
| `ui/step2.js` | mounts `ctx.scan` under the cards; the third radio `[data-preset="scan"]` |
| `ui/topbar.js` | the peach dot on step 2 (`[data-cal-scan-dot]`), tertiary `Pokračovat` |
| `assets/ai-scan/copy.js` `CAL` | every Czech sentence of the calendar scan |

- **Rendering** is the template wizard's: strings + `morph()` (regions
  `data-region`, imported from `assets/ai-scan/page/app.js`), the same `as-*`
  classes (`ai-scan.css`); the calendar-only rules (`as-act`, `as-cpanel`,
  `as-cgrid`, `as-rec`, `as-cantl`, `as-stepdot`, `cal-scan*`) are in
  `calendar.css`. Events are delegated: `data-act` (click), `data-chg`
  (change). Everything not written in the code goes through `esc()`.
- **The proposal is drawn live**, never from the candidate picture:
  `proposal.layout` on the sample week `proposal.page`, the ticked switches
  on, a held picture as a temporary asset entry (`scan-preview-{slot}`) and
  the photo region of the upload (`photoBox`, cut by a canvas into a blob URL)
  as the page's photo. Nothing of it enters the store or a save. The SVG is
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
- **Upload checks**: `inspectFiles(files, {minShortSide: 300, goodShortSide:
  600})` — a calendar page is taken from a 300 px short side (the template
  wizard: 600); under 600 px the chosen-file checks say „Malý obrázek —
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
  `replace`. Callers make sure the store is clean first — apply is disabled
  with „Nejdřív uložte rozpracované změny.“ + `Uložit`, restore says so in a
  toast.
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
  (`assets/ai-scan/lists.js` `restoreCalendarLayout`, asks on `layout_changed`).
- **Who**: an editor of a project with AI sken switched on is the ordinary
  user of all of this (decision 16) — the card's state is then `enabled`;
  an administrator on a project without the switch sees `admin_preview` and
  the line saying so; an editor of a project without it sees `locked` →
  „Požádat o přístup“ → `request_sent`.
- **DOM hooks**: `[data-cal-scan-card][data-state]`,
  `[data-cal-scan-panel][data-view]`, `[data-cal-action="scan-open|scan-close|
  scan-start|scan-pictures|scan-apply|scan-restore|scan-discard|scan-rescan"]`,
  `[data-preset="scan"]`, `[data-cal-scan-dot]`, `[data-proposal]`,
  `#calScanFile`, `#calScanPic`, `#calScanPics` (always in the panel's markup), `[data-slot]`
  on a picture card, `[data-dropzone="page|slot|pictures"]`, `[data-box]` /
  `[data-hl]`, `[data-k="layers|fbu|fbd|picg{slot}|pic{slot}|picdrop{slot}"]`,
  `#calScanGalleryModal`. Test hooks: `window.__calendarScan` (`view()`, `scan()`,
  `proposal()`, `entry()`), `window.__calendar.state().layoutOrigin`.
- **e2e**: `tests/e2e/calendar/scan.e2e.cjs` with the fake scanner
  (`tests/e2e/ai-scan/lib/fake-scanner.cjs`, calendar branch; it takes only
  the e2e project's scans — stop a real local scanner first, it would take
  them too). The script works as the PLAIN e2e user, an editor of a project
  with AI sken switched on (the customer of decision 16); its fixture
  switches the entitlement of the e2e project on and switches a local
  „admins only“ off. It walks the card states (locked → request sent, limit;
  service-waiting from a rewritten `entry` answer — the service is not
  paused), the whole flow, layers, rating, numbered places, drop, gallery,
  rescan, an applied scan opened from its link, the history row's restore
  and the history of a project with only the Kalendáře module.

Deviations from `docs/ai-scan/ux.md` §6:

- **No in-review font decisions.** Font rows are information („Písmo čísel
  dnů: nejbližší dostupné — …“, changed in step 3); „Nahrát správné písmo“ /
  „Vybrat jiné písmo“ / „Nechat“ are not built. Without a project font the
  item links to the Fonty page (new tab).
- The header of the summary card says `Návrh rozvržení je připravený.` — there
  is nothing to count as "to decide".
- The stage names of a calendar scan are the calendar's own (`CAL.stages`);
  S3's „Tyto řádky jsme našli“ overlay is not drawn (as for templates).
- A picture for a place has no fit choice — the layout's own (`logo.fit`,
  the fill's `fit`) applies and is changed in step 3; there is no
  „Vybrat víc z galerie“ (a proposal has at most three places — the gallery
  is picked per place).
- „Naskenovat znovu“ → „Stejný obrázek znovu“ puts the picture back on the
  chosen-file screen instead of starting at once (as in the template wizard).
- The service-waiting / limit / locked cards carry the template card's copy
  (ux.md T1); the calendar card has no separate wording.
- The sidebar's AI-scan badge still counts template scans only (Šablony
  item); the Kalendáře item carries none.
- After „Vrátit původní“ from the scan's history row the page reloads; the
  open-calendar tab of another window is not told.

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
  layout or the switches changed since (`layout_changed`).
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
`3600 × 2400 jpeg` for a 3600 px upload). Measured on a page using everything
the renderer can draw at once: the only thing Chromium rasterises is the
element under an SVG **filter** — the photo shadow (`feGaussianBlur`) becomes
one 300 ppi RGB image + soft mask of the blurred rectangle; the photo above
it, the texts and every other shape stay as they are.

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
   `/forms/pdfengines/merge` (qpdf; `CalendarPdfMerger`) and
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
| ExtGState `/ca`, `/CA`, `/BM /Normal`, `/LW /LC /LJ /ML /SA` | opacity (kalendárium, caption, month backgrounds, pictures), line styles | copied; an unknown key or blend mode fails |
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
(allowed in X-4) with normal blend mode only. **Not validated by a preflight
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
| `set_calendar_layout(calendarId, preset?, reset?, boxes?, order?, styles?)` | write | preset → default layout (or mirror / reset), then a validated patch |
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
  `not_hideable`, `invalid_order`, `unknown_style_key`,
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
  missing in the PDF otherwise.
- **`ensureFixture()` / the e2e runner write to the dev database** — into their
  own project only. Do not point `BASE` at anything that is not a dev stack.

## Known gaps — second iteration

Deliberately not built (handoff §2), with the room left for them:

- **Layout import from a PDF or image in step 3** — the Nahrát layout menu has
  the two rule-based items only. (The AI design scan of step 2 exists since
  2026-11-13 — § "Scanned layouts"; PDF input and 14-day calendars are not
  part of it.)
- **Excel import** for Odpady and Akce a spolky (rule-based, postponed).
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
