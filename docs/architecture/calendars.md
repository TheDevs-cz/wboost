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
stores and guards.** The server never builds a page, knows no page size and no
feature default. It validates shapes, enforces roles, merges content item by
item under a row lock, records history, stores files.

## Data model

Three tables, one migration (`Version20261111131800`), all `ON DELETE CASCADE` from
`project`.

| Table / entity | Holds |
|---|---|
| `calendar` / `Entity/Calendar` | one row = one calendar: the **design** (`name`, `year`, `type` week\|fortnight, `page`, `print`, `features` JSONB, `layout_preset`, `layout` JSONB, `link_elements`, `step`, `finished`) + the **content** JSONB + `client_token` (64 hex, unique) + `revision` (+1 per save) |
| `calendar_asset` / `CalendarAsset` | every uploaded file: `kind` (`photo logo icon qr image page`), `path` = `calendars/{calendarId}/{assetId}.{ext}`, optional `preview_path` (`…-preview.webp`, only when the long edge > 1600 px), pixel `width`/`height` (null for SVG), embedded `description` (EXIF / XMP caption), uploader kind + user |
| `calendar_history` / `CalendarHistoryEntry` | "Historie": `author_kind` (designer\|client), `user_id` (SET NULL), a ready Czech sentence, `created_at` |

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

- `boxes`: `photo cap logo head month cal wk qr` (+ `cal2` iff the type is
  `fortnight`) — `{ x, y, w, h, hidden? }`. `hidden` only on `logo month wk qr`;
  the caption's visibility is `features.captions` (one switch for step 1 and
  the Vrstvy eye, which is why the undo snapshot carries it).
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
- **Fully materialised**: `normalizeDocument()` runs on every bootstrap and
  every save response, deep-merges `defaultStyles(env)` under the stored styles
  (a new style key needs no migration), clamps to `LIMITS`, repairs `order` and
  `cal2`. It is pure and idempotent (suite `normalize`). `migrate()` is the
  `switch (v)` hook for a future version.

The server validates the layout **structurally only**
(`Services/Calendar/CalendarDesignRules`): exact top-level keys, known box
keys with finite numbers, `order` without duplicates, `styles` any JSON with
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

Rules worth knowing:

- Ids are client-generated (`newId()`, 16 base-62 chars; server pattern
  `[A-Za-z0-9_-]{1,40}`, minus `CalendarContentRules::RESERVED_IDS` —
  `__proto__`, `constructor`, `toString`… — because the JavaScript keeps every
  collection in a plain object; the rule covers item ids, id references and
  the id inside a `sp:<id>` page key). The `page*` collections are keyed by **page key**
  (`pre`, `w01`…`w53`, `post`, `cover`, `back`, `sp:<id>`). The generated
  special pages use the fixed ids `intro contacts year waste`; a missing one
  means "default, off".
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
the three thin controllers and answer 429 + `Retry-After` in the `{ error }`
shape (`CalendarResponses::overBudget`). An unknown token is a 404 before
anything is counted. The `/calendar/{id}/…` twins have no budget.

| What | Limit | Where |
|---|---|---|
| uploads per link + address | 200 / hour | `calendar_client_upload` |
| uploads per link, any address | 500 / hour | `calendar_client_upload_link` |
| saves per link | 600 / hour | `calendar_client_save` |
| history reads per link | 600 / hour | `calendar_client_history` |
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
| `calendar_document_text` / `calendar_layout_import` / `calendar_client_link` / `calendar_delete` | POST `…/document-text`, `…/layout-import`, `…/client-link`, `…/delete` | `CalendarVoter::EDIT` |
| `calendar_client` (+ `_save`, `_asset`, `_history`) | `/k/{token}` (+ `/save`, `/asset`, `/history`) | public; the token is the credential |

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
`calendars` in `PATH_PREFIXES`), `ResolveStorageOwnerByPath`,
`StorageCategory::Calendar`, `CollectProjectStoragePaths`
(`calendars/{calendarId}` per calendar), `DeleteCalendarHandler` (collects the
directory first, removes the row, then `deleteDirectory`),
`RewriteFontReferences` (`replaceInCalendars`: rewrites `calendar.layout`,
`revision` + 1, fresh `updated_at`), `GetFontUsage` (calendar layouts count on
the Fonty page — `templates-text-inputs.md`). A new path-storing column
needs all the storage ones — `storage-inventory.md`.

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
| `ui/` | DOM modules: `dom.js` kit, `topbar`, `step1`, `step2`, `step4`, `step5` (+ overlay, popover), `pagelist`, `pagepanel`, `thumb`, `warnings`, `history`, `client-link`, `editor/*` (stage, overlay, parts overlay, keys, layers, props, props-kal), `controls/*`, `tools/*` | DOM |

**Rule: nothing outside `ui/` and `state/api.js` touches `window`, `document`,
`fetch` or timers** (exception: `createCanvasMeasurer()` /
`ensureFontsLoaded()`). Everything else must import cleanly in node — that is
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
  `[data-field="x|y|w|h"]`, `[data-prop="<path in styles>"]`, `[data-tool]`,
  `[data-page-thumb]`, `[data-day-plus]` / `[data-day-row]`,
  `.cal-pop[data-cal-popover]`, `.cal-dialog` + `[data-cal-choice]`,
  `.toast-n`. The full table is `docs/calendar/architecture.md` §D.10; each
  file's header lists its own.

### Generation in one paragraph

`buildPages(doc)`: cover → front special pages → week pages from the Monday of
the week containing 1 January until 31 December is covered (7 or 14 days per
page; 2027 = 53 / 27 week pages) → back special pages → back. **Page keys are
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
  causing undo step.
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
  not fit the grid, event overflow — all derived from geometry + the asset
  map, nothing stored, never blocking.

## MCP — `src/Mcp/Tool/{ListCalendars,GetCalendar,UpdateCalendarContent}Tool.php`

Scopes `calendars:read` and `calendars:write` (write ⇒ read; **no implication
to or from the template scopes**, so a calendar-only token cannot call
`get_context` — hence `list_calendars` without a project lists every reachable
project's calendars).

- `list_calendars(projectId?)`, `get_calendar(calendarId)` — the latter is
  `CalendarDocument` minus `layout.styles`, minus everything of the browser
  session, and **never the client token** (it grants anonymous write access).
- `update_calendar_content(calendarId, operations[])` — operations
  `{collection, id, value|null, label?}` are re-encoded as the web payload's
  `content` list and go through `CalendarSavePayload::fromJson` and the
  `SaveCalendar` message: **one write path**, same validation, merge, history,
  revision. The payload has no `design` and no `finish`, and an operation with
  any other key is refused, so a design change cannot be phrased. A missing
  `value` is refused rather than read as a delete.
- Actor = `CalendarAccess::forUser` (the web rule): EDIT → designer, a member
  with only VIEW → **client**, held to the client whitelist by the handler.
- Refusals are `message (reason)`: `invalid_calendar_id`, `calendar_not_found`
  (identical for "not yours"), `invalid_project_id`, `project_not_found`,
  `no_operations`, `invalid_operation`, `invalid_content` (quotes the Czech
  `InvalidCalendarChange` text), `designer_only`.
- Not module-gated and not preview-gated (voters only). What limits exposure
  before launch: the scopes are not in `User::DEFAULT_MCP_SCOPES`, so an OAuth
  grant gets them only after an admin ticks them for the user; PATs are minted
  by the operator. The public `/ai` page and the consent screen do describe
  them (`DescribeMcpCapabilities`, `DescribeConsentScopes` — both exhaustive).
- Adding a tool or scope touches: `McpScope::grants()`, `DescribeConsentScopes`,
  `DescribeMcpCapabilities`, `EditUserFormType` + `admin/edit_user.html.twig`,
  `plugin/wboost/skills/wboost/SKILL.md` + `references/tools.md`,
  `config/packages/mcp.php` instructions, `docs/mcp/connect.md`,
  `McpScopeTest`. Tests: `tests/Mcp/Tool/CalendarToolsTest.php`.

No MCP tool creates, renames, deletes or redesigns a calendar, uploads a file
or renders a page (rendering is browser-only).

## Tests

| Layer | Where | Run |
|---|---|---|
| PHP | `tests/Controller/Calendar`, `tests/MessageHandler/Calendar`, `tests/Services/Calendar`, `tests/Mcp/Tool/CalendarToolsTest.php`; fixtures `CALENDAR_{1,2,3}_ID` (+ tokens) in `TestDataFixture`, helpers `CalendarTesting` | `docker compose exec web vendor/bin/phpunit tests/Controller/Calendar …` |
| Node suites (every pure rule) | `tests/Fixtures/calendar/suites/*.mjs` + `harness.mjs`, `helpers.mjs` | in CI through `tests/Calendar/CalendarJsTest.php` (data provider globs the suites; a new suite file needs no PHP edit, a suite without a test fails); by hand `node tests/Fixtures/calendar/harness.mjs --all` or `… <suite>` |
| Contract | `tests/Calendar/CalendarSeedContractTest.php` (PHP seed vs `BUILTIN_WASTE` via `dump-defaults.mjs`) | CI |
| e2e (real pointer, asserts in mm) | `tests/e2e/calendar/*.e2e.cjs`, `lib/harness.cjs`, `lib/editor.cjs` | `node tests/e2e/calendar/run.cjs [filter]` — **not in CI** |

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
- **`ensureFixture()` / the e2e runner write to the dev database** — into their
  own project only. Do not point `BASE` at anything that is not a dev stack.

## Known gaps — second iteration

Deliberately not built (handoff §2), with the room left for them:

- **AI design scan** ("Naskenovat design", Premium) and **layout import from a
  PDF or image** — the Nahrát layout menu has the two rule-based items only.
- **Excel import** for Odpady and Akce a spolky (rule-based, postponed).
- **Print PDF export.** The renderer is dimensionally exact (mm viewBox, bleed,
  crop marks), but nothing produces a file; `Services/Print/` (PDF/X-1a,
  FOGRA39 — `templates-render.md`) is the pipeline to reuse, and rendering is
  browser-only today, so a server export needs the page SVGs from the client
  or a headless render.
- **Month and week number per column on 14-day pages.** Reference calendars
  print them once per column; there is one Měsíc and one Číslo týdne
  (`1. a 2. týden`). The box model already has second frames (`cal2`), so
  `month2` / `wk2` can be added the same way without a migration.
- **Cover, back and special pages** are drawn as far as the prototype goes;
  their content is not editable beyond the special-page settings (layout
  variant, position, name, uploaded graphic / text).
- **Gallery as a photo source** — uploads are from disk only; a calendar asset
  is not a gallery `FileUpload`.
- **Page size UI**, an **asset sweeper** (unreferenced uploads — also the only
  way a calendar that reached the 2 GB client cap could get room again), **client
  autosave** (explicit save can lose work on mobile browsers that skip
  `beforeunload`), **design conflict detection**.
