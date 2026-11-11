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
| `calendar` / `Entity/Calendar` | one row = one calendar: the **design** (`name`, `year`, `type` week\|fortnight, `page`, `print`, `features` JSONB, `layout_preset`, `layout` JSONB, `link_elements`, `step`, `finished`) + the **content** JSONB + `client_token` (64 hex, unique) + `revision` (+1 per save) |
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
| `calendar_export_request` / `_status` / `_download` | POST `…/export`, GET `…/export/{exportId}`, GET `…/export/{exportId}/download` | `CalendarVoter::VIEW` — whoever opens a calendar may export it |
| `calendar_document_text` / `calendar_layout_import` / `calendar_client_link` / `calendar_delete` | POST `…/document-text`, `…/layout-import`, `…/client-link`, `…/delete` | `CalendarVoter::EDIT` |
| `calendar_client` (+ `_save`, `_asset`, `_history`, `_export_request` / `_export_status` / `_export_download`) | `/k/{token}` (+ `/save`, `/asset`, `/history`, `/export…`) | public; the token is the credential |

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
| `ui/` | DOM modules: `dom.js` kit, `topbar`, `step1`, `step2`, `step4`, `step5` (+ overlay, popover), `pagelist`, `pagepanel`, `thumb`, `warnings`, `history`, `client-link`, `editor/*` (stage, overlay, parts overlay, keys, layers, props, props-kal), `controls/*`, `tools/*` | DOM |

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
  not fit the grid, event overflow — all derived from geometry + the asset
  map, nothing stored, never blocking.

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

## PDF export — „Stáhnout PDF“ (2026-11-11)

Whoever can open a calendar can take it away as a PDF: designer, admin,
read-only member, the client on `/k/{token}`. Two variants
(`Value/Calendar/CalendarExportVariant`):

| Variant | Pages | Photos | For |
|---|---|---|---|
| `print` „Pro tisk“ | what step 5 shows the designer: the bleed when it is on, crop marks when they are on. Sheet = the view box of `pageViewBox(design, { bleed, marks })` — trim + 2 × bleed, with marks trim + 2 × (bleed + 2 mm gap + 5 mm mark + 1 mm) = **336 × 171 mm** for the default page with 5 mm bleed | the uploaded file, byte for byte | the print shop |
| `approval` „Náhled k odsouhlasení“ | trimmed (310 × 145 mm), no bleed, no marks | the ≤ 1600 px preview, as JPEG | e-mail |

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
frame, as on screen). The hole row is drawn in both, as step 5 draws it.

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
4. **Merge** (`CalendarPdfMerger`): Gotenberg's `/forms/pdfengines/merge`
   (qpdf) — structural, nothing re-encoded; files as streams both ways. The
   application image has no PDF merger (Ghostscript would re-interpret every
   page).
5. **Page boxes** (`Services/Print/PdfPageBoxes`): an **incremental update**
   appended to the merged file gives every page an exact MediaBox / CropBox, a
   TrimBox, a BleedBox, and the file an Info dictionary (title, creator,
   date). Not a byte of the existing file changes. Best effort: a structure it
   does not read (`PdfStructureNotSupported` — a cross-reference stream, an
   earlier update, encryption) leaves the file as Chromium made it and logs a
   warning.
6. **Store** under `calendars/{calendarId}/exports/{40 hex}.pdf` with
   `ContentType` + `ContentDisposition` on the object, mark the row `done`,
   prune: the newest `CalendarPdfExporter::KEEP` (6) exports of a calendar stay,
   older rows and their files go (a pending job is never pruned).

### The job

- `CalendarExports::request()` dispatches `RequestCalendarExport` (command bus:
  locks the calendar row, so two requests at once end in ONE job; refuses a
  calendar without a layout; writes the history line `Stáhl PDF pro tisk` /
  `Stáhl PDF k odsouhlasení`; creates the row unless one is reusable) and then
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

POST `…/export` (`_token`, `variant`) → `{ export }`; GET `…/export/{id}` →
`{ export }` (polled every 1.5 s); GET `…/export/{id}/download`.
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

### UI — `ui/export.js`, one dialog, four doors

`openExport({ name, url, csrf, toast, unsaved? })` needs addresses only, so it
opens from: the step-5 toolbar (`[data-cal-action="export-pdf"]`, first of the
actions, every role — also the client page and the read-only member), the
step-4 toolbar (designer), every **list card** whose calendar has a layout
(„PDF“, title „Stáhnout PDF“), and the Dokončit toast on the list
(`sessionStorage['wb-cal-toast-calendar']` names the calendar; `toast()` takes
an optional `{ label, onClick }` action). Radio cards (`.rpick > .rcard`) pick
the variant; with unsaved changes the dialog says the export uses the saved
state and the primary action becomes „Uložit a exportovat“. Progress
„Připravuji PDF… 12 z 55 stran“; the download starts through a hidden
**iframe** (a navigation would trip the workspace's `beforeunload` guard) and
a „Stáhnout“ link stays. Closing the dialog only stops the polling. In step 5
the actions are pinned and only the tools scroll (two rows under 960 px).

### Numbers (dev stack, Apple silicon, 2026-11-11)

| Calendar | Variant | Pages | Time | File | PHP peak | Gotenberg container peak |
|---|---|---|---|---|---|---|
| 53 photos of ~3.2 MB (3000 × 2000, one 3600 × 2400), 172 MB of uploads | print | 56 | 17.6 s | 165 MB | 69 MB | ~730 MiB |
| the same | approval | 56 | 6.0 s | 17 MB (noise-heavy test photos; real ones are about half) | 70 MB | — |
| 3 photos | print | 56 | 3.3 s | 8.1 MB | — | — |
| everything switched on (background picture at 35 %, gradients, shadow) | print | 56 | 21 s | 17 MB | 71 MB | — |

### Limits and traps

- **Chromium rounds a PDF page to whole points** (336 mm came out as
  952.08 pt, 171 mm as 485.04 pt). The page is therefore printed on the next
  whole point UP (`@page` in pt) with the exact sheet in its top-left corner,
  and `PdfPageBoxes` cuts the MediaBox back, anchored at the top (the
  MediaBox then starts at `y = 0.32`, not 0 — legal; poppler, Ghostscript
  and qpdf read it). If the boxes cannot be set, the page is ≤ 1 pt larger at the
  right / bottom and has no TrimBox.
- **Colours are RGB** (sRGB-tagged photos, DeviceRGB vectors). No CMYK, no
  PDF/X, no output intent — see "Known gaps".
- **Every Gotenberg call must fit its limits**: the scoped client's
  `timeout: 20` / `max_duration: 25` and Gotenberg's own 30 s API timeout. A
  chunk takes ~0.3 s and the whole 165 MB export (merge included) 17.6 s. A print file
  far beyond that (a year of 10 MB photos ≈ 550 MB) may not merge in time —
  and production's Gotenberg `/tmp` is a 1 GB tmpfs holding the inputs and the
  output of the merge. Such an export fails with `BUSY`; nothing was measured
  above 165 MB.
- **One consumer, one export at a time** — and e-mails / domain events queue
  behind a running export (seconds to a minute). A second consumer on a
  dedicated transport would isolate them; not done.
- **Pictures the layout shares** (a header or background picture) are
  embedded once per CHUNK, not once per file — qpdf does not deduplicate
  across merged files.
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
| `update_calendar_content(calendarId, operations[])` | write | item operations `{collection, id, value\|null, label?}` re-encoded as the web payload's `content` list |

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
  (`calendarId`, `variant`) and `export_status` (`calendarId`, `exportId`) —
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
| PHP | `tests/Controller/Calendar` (export: `CalendarExportControllerTest`), `tests/MessageHandler/Calendar`, `tests/Services/Calendar` (`CalendarPdfProducerTest`; the real export through Gotenberg is `CalendarPdfProducerGotenbergTest`, `--group gotenberg`), `tests/Services/Print/PdfPageBoxesTest.php`, `tests/Mcp/Tool/Calendar{Tools,DesignTools}Test.php`; fixtures `CALENDAR_{1,2,3}_ID` (+ tokens) in `TestDataFixture`, helpers `CalendarTesting` | `docker compose exec web vendor/bin/phpunit tests/Controller/Calendar …` |
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
- **CMYK / PDF/X print file.** The export is RGB. A 2026-11-11 spike converted
  the vector PDF with Ghostscript (`pdfwrite`, `-sColorConversionStrategy=CMYK`,
  FOGRA39 output profile): text stays text, fonts stay embedded and the photo
  becomes a CMYK JPEG — but pure black text comes out as rich black
  (`#000000` → 89 / 78 / 62 / 97, 326 % ink), brand colours go through the ICC
  profile instead of `Services/Print/BrandCmykPalette`, and the only switch
  that gives K-only text (`-dBlackText -dBlackVector`) turns EVERY text and
  vector black. A clean variant needs our own colour pass over the content
  streams (fills / strokes by palette, near-black → K 100) before or instead
  of Ghostscript; not built.
- **Bigger print files**: nothing above 165 MB was measured; see the limits
  of § "PDF export" (Gotenberg timeouts and tmpfs, one consumer).
- **Cover, back and special pages** are drawn as far as the prototype goes;
  their content is not editable beyond the special-page settings (layout
  variant, position, name, uploaded graphic / text).
- **Gallery as a photo source** — uploads are from disk only; a calendar asset
  is not a gallery `FileUpload`.
- **Page size UI**, an **asset sweeper** (unreferenced uploads — also the only
  way a calendar that reached the 2 GB client cap could get room again), **client
  autosave** (explicit save can lose work on mobile browsers that skip
  `beforeunload`), **design conflict detection**.
