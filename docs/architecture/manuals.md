# Manuals — mockup pages, logo variants/widths, colours, fonts, page texts, content pages

### Manual mockup pages — canonical grid geometry

A manual's mockup pages (`ManualMockupPage`, `MockupPageLayout` enum with 11
layouts) all live on ONE canonical grid: 3 columns × 2 rows, page ratio
1380 : 798, uniform gap 36 units — a slot spans whole tracks
(`MockupPageLayout::slots()` → `MockupPageSlot {column, columnSpan, row,
rowSpan}`). This single source of truth drives every surface: the public
manual render + admin listing thumbnails (`templates/_mockup_page.html.twig`,
CSS grid + `object-fit: cover`, gap/radius in `cqw` so it scales), the layout
picker mini-previews, and the interactive add/edit editor
(`templates/_mockup_page_form_editor.html.twig` +
`assets/controllers/mockup_page_editor_controller.js`). Geometry reaches JS
via `MockupPageLayout::exportGeometry()` serialized into a Stimulus value.

**Downloadable attachments (2026-09-03).** A page can carry files the manual
reader takes away — the print-ready PDF behind a mockup, packaged assets. Two
independent levels, both optional: `download_file` (JSONB, the WHOLE page) and
`image_downloads` (JSONB list, positionally aligned with `images`, null holes
kept). Both hold the `MockupPageDownload` VO — `path` + the `fileName` the
admin uploaded under + `size` + `mimeType` — because the stored key is
timestamped and the point of the feature is handing the file back under its
own name. `DownloadMockupPageFileController`
(`/stahnout-mockup/{pageId}/{slot}`, slot = `stranka` or the slot index) is
PUBLIC like the manual it hangs off and serves the bytes BUFFERED through PHP
(a `Content-Disposition` is the only way to restore the name; a flushing
StreamedResponse would corrupt the next request on resident FrankenPHP). The
manual render shows a corner button on each slot that carries a file and a
"Stáhnout" button in the page heading for the page-level one. Attachments are
NOT images, so the cap is `ManualMockupPageFormType::DOWNLOAD_MAX_SIZE` =
20 MB decimal (mirrored client-side), and any file type is accepted. Both
columns are enumerated in `BuildStorageReferenceIndex` — without that the
files read as orphans.

**Editor UX: the per-image attachment lives ON the image.** A bar along the
bottom of each stage slot (`.mockup-editor-slot-file`) picks / shows / clears
the file that rides with THAT picture; the file for the whole page is a
separate card below the stage, rendered server-side. It began as a list of
"Obrázek 1 / 2 / 3" rows under the stage, where nothing told the designer
which row was which picture. Three things there are load-bearing:

- the bar is **always visible**, not hover-only: an attachment leaves no trace
  in the artwork, so hiding the control hides the only evidence a file exists;
- **`z-index: 3`** on the bar — `.mockup-editor-slot-actions` is `inset: 0`
  and still takes pointer events at `opacity: 0`, so without it the bar is
  unclickable on any slot that has an image;
- the whole-page card is **Twig, not JS**. It used to be built by the
  controller, and an empty invisible button was exactly the symptom when that
  controller crashed on connect (below).

**Stimulus Object values and Twig's empty hash.** `existing_page_download ??
{}` looked harmless but Twig encodes an empty hash as `[]`, and Stimulus'
`Object` reader THROWS on an array — which aborted `connect()` half-way, so
the page-level row was never refreshed AND the editor's dirty tracking,
beforeunload guard and name listener were all dead, silently. The page
attachment now travels as two scalars (`pageDownloadName` / `pageDownloadSize`).
Prefer a scalar or an Array value over an Object one whose empty case comes
from Twig.

The editor: click/drop a segment → instant local preview + fit verdict
(ratio crop %, low-resolution vs `recommendedWidth/Height` = 2× unit size,
over the upload limit rejected client-side to match the server `Image`
constraint — see **Upload size limit** below). Files sit
in the regular Symfony form file inputs — always
`MockupPageLayout::maxUploadInputsCount()` (6) of them so the layout can be
switched client-side without losing picks; both controllers `array_slice` to
the chosen layout's slot count before dispatching. Layout is a form field on
ADD only (edit keeps it fixed). Edit supports per-slot image REMOVAL via
hidden `removeImages[]` flags ('1' → `EditManualMockupPage::$removeImages`;
a new upload for the same slot wins over the flag). Slot order == persisted
`images` array indexes. NOTE: Stimulus reuses controller instances on
reconnect — slot state is reset in `connect()`, not `initialize()`.

### Manual logo variants — "Zobrazovat variantu na samostatné stránce"

Per logo TYPE variant (`SvgImage::$ownPage`, a key in the `manual.logo` JSONB
document — no migration, absent reads as false) the admin can promote a variant
out of the pages that mix two variants onto **a page of its own**, showing that
variant in every colour variant it has (plain + all five `LogoColorVariant`
cases, `_manual_logo_variant_page.html.twig`).

The two halves are one invariant: `Manual::logoOwnPageVariants()` drives the
dedicated pages and **`Manual::logoOnSharedPage()` is the guard every shared
page uses**, so a promoted variant is rendered exactly once. Both the
page-level `{% if %}` and the per-variant inner guards of the shared pages
(basic_logos, logos_with_claim, the two *_backgrounds, the two *_monochrome and
the symbol page) go through it; a shared page whose every variant was promoted
disappears.

**`protection_zone` and `minimum_dimensions` deliberately keep guarding on
`manual.logo.<variant>`**: they are not colour-variant pages (every card there
is the plain logo, annotated with zones or dimensions), so promoting a variant
must not strip it from them.

**Re-flow contract (2026-11-09, user feedback "u sekce bez sloganu by se to
mělo přeskládat").** A shared page that loses one of its two variants must not
keep an empty half (`manual_preview.html.twig`, Bootstrap grid only — no CSS):
- `horizontal_backgrounds` / `vertical_backgrounds`: both variants = one
  `col-sm-6` column per variant with its 3 cards stacked (unchanged); one
  variant left = each of its 3 cards gets its own `col-sm-6` cell (2 + 1 grid,
  every card `mb-4`).
- `basic_logos`: vertical + symbol share a row (`col-6` each); only one of
  them left = `col-12`. `logos_with_claim`: verticalWithClaim stays the
  half-width `col-6` under the horizontal card; alone (horizontalWithClaim
  promoted) = `col-12`.
- `*_monochrome` need nothing: their 2×2 grid of `col-6` cells already wraps
  the remaining two cards into one full row. `symbol` has a single variant.
- A re-flowed row carries `manual-logo-reflow` (test hook only). With both
  variants present the markup renders the same layout as before —
  screenshots of all six pages were pixel-identical, pinned by
  `ManualPreviewOwnPageReflowTest`. Card ids and slot ids are unchanged.

Each dedicated page has its own `ManualPage` case (`horizontal_page`,
`horizontal_with_claim_page`, `vertical_page`, `vertical_with_claim_page`,
`symbol_page`, reached from Twig via `manual_logo_page(variant)`), so its
heading and description are admin-editable like any other page's, and its cards
carry `<page_key>.<variant>.<colorVariant|base>` slot ids for the width
cascade.

### Logo colour mapping — "Mapování barev loga" + Krytí (opacity, 2026-11-08)

Per logo type × `LogoColorVariant` the pencil modal (`LogoColorsMappingModal`,
opened by the `LogoColorsMapping` pencil on the card — saved in place, see
**Pencil modals save in place** below) maps each colour the SVG contains (FROM = the SVG's literal, no `#`
— the palette's `logo_hex`, see below) to a target HEX, plus one "Pozadí"
(card background). Stored in the `manual.logo` JSONB as
`colorsMapping.<colorVariant> = {background, colors: {FROM: HEX}, opacities: {FROM: percent}}`
(`ColorMapping`). Targets are plain HEX snapshots, NOT palette links — the
palette only supplies the FROM keys and the defaults (`DefaultLogoColors`,
`color(1..3)`).

**Krytí (opacity)** is per logo-colour row only — **the background has none
(decided with Jan 2026-11-08)**. `opacities` keeps ONLY translucent entries
(0–99, keys upper-cased); absent key / absent `opacities` (every row written
before) = 100 %, so no migration and old rows render byte-identically.

- `Manual::logoColorTargets()` = HEX map (default overlaid by custom) — what
  the form edits; `logoColorOpacities()` = the translucent percents;
  **`logoColorMapping()` = the RENDER map**: an opaque target stays HEX, a
  translucent one becomes `rgba(r,g,b,a)` (`RgbaColor::fromHex()->css()`).
  Every surface (manual preview cards incl. own-page variants via
  `_manual_logo_image.html.twig` → `remap_svg_colors`, and the download links
  in `DownloadManualImage`) takes that map, so no template had to change.
- **Download file names** (`DownloadLogoImageController`, 2026-11-09):
  `<manual> - <LogoTypeVariant::label()>[ - <LogoColorVariant::label()>].<ext>`.
  The colour part comes from the optional `colorVariant` query param, which
  `twig:DownloadManualImage colorVariant="…"` adds — every coloured card in
  `manual_preview.html.twig` and `_manual_logo_variant_page.html.twig` passes
  it, the plain white base cards don't (pinned by
  `ManualPreviewDownloadLinksTest`).
- `SvgColorsMapper::map()`: HEX target = the legacy bare-literal
  `str_ireplace` (the SVG's `#` stays); `rgba()` target replaces the WHOLE
  literal, `#` included, with a `(?![0-9a-f])` guard. Anything else is
  dropped — the download route takes the map from the query string and it
  lands inside the SVG.
- **Downloads never carry rgba()**: Inkscape 1.4 (our PNG/JPG rasteriser) and
  desktop editors paint `rgba()` and `#RRGGBBAA` SOLID BLACK (tested). So
  `DownloadLogoImageController` runs `inlineSvg()` + `SvgPortableAlpha` for
  every PNG/JPG, and for an SVG download when the map has a translucent
  target: paint → HEX + `fill-/stroke-/stop-/flood-opacity`, multiplied into
  an existing opacity. PNG keeps real alpha; **JPG cannot** — it is
  composited onto the colour variant's background (`?background=`), which is
  what the card shows anyway. There is no PDF / CMYK logo download, no API or
  template/canvas use of mapped logos (templates use gallery images).
- UI: the target swatch is `.logo-map-swatch` — the colour via the
  `--swatch` custom property (set by `custom_color_controller.js` when the row
  has an `opacity` target) over a checkerboard. CSS lives in
  `assets/styles/app.css`, NOT Nocturne: the manual preview uses the
  `app_plain` entrypoint (always light, Hyper loads after app.css → rules are
  scoped under `.modal`).
- Pinned by `tests/Value/RgbaColorTest.php`, `tests/Value/ColorMappingTest.php`,
  `tests/Services/Manual/SvgColorsMapperTest.php`,
  `tests/Services/Manual/SvgPortableAlphaTest.php`,
  `tests/Entity/ManualLogoColorOpacityTest.php`.

### Manual colours — HEX, CMYK, Pantone, RAL

`ManualColor` (the read-model VO — since F7 R2 built from the palette, see below)
carries `ral` alongside `pantone` (`project_color.ral`). Every code is
printed by `_manual_color_swatch.html.twig` — extracted from the two
copy-pasted palette blocks — and only when the designer filled it, so a manual
with no RAL looks exactly as before. Since F7 P3 the codes are edited only on
the Paleta barev cards (the per-manual colours form is gone).

### Project palette — the only storage for colours (F7; P1a R1 → P1b R2 2026-11-05 → P5 2026-11-06)

Plan + decisions: `docs/redesign/palette-plan.md`. Two tables (`Version20261105170659`
CREATE + the one-off backfill from the old JSONB columns). **Since P1b (release R2)
they are what everything reads; since P5 they are the only colour storage** — the
old per-manual JSONB columns `manual.detected_colors` / `custom_colors` were a
mirror for R1 code until P5a stopped writing + unmapped them and P5b
(`Version20261106120000`) dropped them. The backfill code (`PaletteBackfillPlanner`,
`PaletteBackfillSql`) went with them: `Version20261105170659` now REFUSES to run on
a database that has manuals (only a pre-R1 dump would need it — migrate that with
commit `54b4226a` first, see the class comment):

- `project_color` (`ProjectColor`): one palette colour — `hex` (literal, no `#`),
  `hex_key` (normalised `#rrggbb`, the dedupe key; indexed with `project_id`),
  `role` (D2: ONE role per project colour, `ManualColorType`), `cmyk` (jsonb tuple),
  `pantone`, `ral`, `name` ("Název", edited on Paleta barev since P2), `position` (palette order). Project
  CASCADE.
- `manual_palette_color` (`ManualPaletteColor`): a manual's use of one, `position` =
  the manual's colour order (logo segment first, then added colours = what `color(n)`
  indexes), `logo_hex` non-null = "Z logotypu", the SVG's LITERAL (recolouring
  `str_ireplace`s it), `UNIQUE (manual_id, logo_hex) WHERE logo_hex IS NOT NULL`.
  Manual CASCADE; FK to `project_color` is **ON DELETE CASCADE since P1b**
  (`Version20261105193000`). P1a's NO ACTION broke deleting a project whenever
  Postgres fired the `project_color` cascade before the `manual` one — RI triggers run
  in trigger-NAME order (OID digits compared as text), and NO ACTION is checked right
  after the cascaded palette delete, while the links still exist (the test DB had that
  order; prod's depends on its OIDs). Deleting a palette colour manuals still use is
  guarded in the app (P2, see "Paleta barev page" below).

**Read model (R2).** `Manual::$paletteLinks` (OneToMany, `fetch: EAGER` = one batch
query per hydration, palette colour EAGER-joined — no N+1 on listings / avatars, no
fetch-join needed) feeds the unchanged method API: `detectedColors()` = logo links
with their literal `logo_hex`, `customColors()` (a METHOD now — Twig
`manual.customColors` still resolves) = the other links with the palette HEX spelling
(§7), type = the palette role, CMYK/Pantone/RAL from the palette; `color(n)` = logo
segment ++ custom; `colorsCount()`; `primaryColors()` / `secondaryColors()` = links in
manual order filtered by the palette role. `ManualPaletteColor::manualColor()` builds
the `ManualColor` VO.

**Project-wide colours** = `Query/GetProjectPalette::forProject()` / `colorsForProject()`:
palette order (D5) and **only colours ≥1 manual links** (R2 decision: R1 never
deleted palette colours and the old colours page (gone since P3) split / abandoned them, so
leftovers must not become swatches or win the print "first CMYK"; the P2 palette page
lists them as "Nepoužitá" via `ProjectColorRepository::forProject()`, which returns
all). Feeds `ResolveRichTextOptions::computeColors()` (now takes the palette
`ManualColor`s — editors, group editor, fill, API, MCP `get_context` /
`describe_variant`), `LintContext::forProject()` and `BrandCmykPalette::fromPalette()`.

**Write path = `Services/Palette/ManualPaletteWriter`** (a manual's links) and
`ProjectPaletteWriter` (palette colours, below) — links only, nothing is mirrored
(P5a). Callers: the "Barvy v manuálech" box (P3, see below) → `addColors()` /
`removeColor()` / `sortColors()`, and `UpdateManualImagesHandler` →
`syncLogoColors()` after flushing the new logo (D3). The logo sync reads the
STORED `manual.logo` literals (image order of `Logo::getDetectedColors()`, `#`
stripped, case kept, non-hex detections such as `rgb(…)` skipped) and rebuilds
the logo segment: logo links whose literal is still in the logo stay (id, order,
palette colour), the others are deleted (the palette colour stays, "Nepoužitá"
when nothing else links it), new literals are appended in logo order — linked
to the first palette colour of the same `hex_key` by palette position, else a
new palette colour at the END of the palette without role / print data. Literals
compare EXACTLY (recolouring `str_ireplace`s that spelling), so `FFF` → `ffffff`
swaps the logo link (same palette colour). Added links follow; positions are
renumbered 0…n. Roles and print data are never touched by a logo sync, palette
colours never deleted by it. `Manual::editLogo()` only sets the logo.

DBAL on purpose, project row `FOR UPDATE`, one transaction. **Afterwards
`refreshManaged()` detaches the project's managed `ProjectColor` /
`ManualPaletteColor` entities and gives every managed `Manual` an uninitialised
link collection** — so the writing request reads what it wrote. NOT
`EntityManager::refresh()`: it throws "Attempting to change readonly property
::$createdAt" on these entities. A new writer of manual colours MUST go through
`ManualPaletteWriter` / `ProjectPaletteWriter`.

**Tools:** `app:palette:snapshot <json>` (every colour-derived output through the
public read model, deterministic — diff two runs around a data change) and
`app:palette:merge-duplicates [--project=<id>] [--apply]` ("Sloučit" from the
CLI, dry run by default). The JSONB comparison commands (`app:palette:plan`,
`app:palette:verify`, `app:palette:reconcile`) were retired in P5a together with
the columns they compared. No path columns — `BuildStorageReferenceIndex` is
unaffected. Pinned by `tests/Services/Palette/PaletteSyncTest.php` (D3, consumers,
linked-only swatches, cascades) and `tests/ConsoleCommands/PaletteCommandsTest.php`.

### Paleta barev page — editing the palette itself (F7 P2, 2026-11-05)

`/project/{id}/paleta` (`project_palette`, `Controller/Palette/`, template
`project_palette.html.twig`, `project_palette_controller.js`, CSS
`nocturne/palette.css` on top of the `.pal-*` kit in `manual.css`). Lists
**every** palette colour (`ProjectColorRepository::forProject()`, unlinked ones
as "Nepoužitá") in two groups: **Z logotypu** (≥1 LOGO link — HEX locked, lock
icon, title "HEX určuje logotyp") and **Přidané barvy**; palette order inside
each. "Použito v:" chips = `GetProjectPaletteUsage` (one row per manual, logo
mark when its link is a logo link). VIEW to see; `project_edit` to change.

- **Autosave per field**: `project_palette_color_edit` (`/paleta/barva/{id}`,
  POST `field` = hex|name|role|cmyk|pantone|ral + `value` / `cmyk[]`, CSRF
  `project_palette`) → `EditProjectColor` → `ProjectPaletteWriter::editColor()`;
  answers the stored colour as JSON, the page toasts "Uloženo". The JS queues
  requests one at a time. Input normalisation = `PaletteColorInput` (HEX 3/6
  digits without `#`, case kept; blank = null; CMYK four free strings ≤ 10
  chars, all blank = null — the same shape the backfill writes).
- **One edit, every linked manual (option C).** A role change moves the colour
  between the primary / secondary pages of EVERY manual linking it (D2);
  print data and an added colour's HEX are what they all print. The writer
  locks the project row, updates `project_color`, then
  `ManualPaletteWriter::refreshManaged()` — every linking manual reads the
  edit through its links.
- **Reorder** (`project_palette_sort`, JSON `{sorted, _token}`; drag via
  SortableJS forceFallback on `.pal-grip`, or ⋯ Posunout výš / níž) permutes
  the given colours **among the positions they already hold** — a group's drag
  never moves the other group's colours in the palette order (D5: templates,
  print, API). Manual colour order (`color(n)`) is untouched.
- **Přidat barvu** (`project_palette_add`, form POST) appends an unlinked
  colour with a starter HEX and redirects to `?nova=<id>#pal-<id>` (card
  flashed, HEX selected). Unlinked ⇒ not a template swatch yet
  (`GetProjectPalette` is linked-only) — P3 lets manuals pick it.
- **Delete guard** (`project_palette_color_delete`, per-card Bootstrap modal):
  any LOGO link → refused ("změní se až s novým logem", `ProjectColorInLogo`);
  added to manuals → the dialog lists them and posts `removeFromManuals=1`
  ("Odebrat z manuálů a smazat"; their links go, later colours move up a place);
  unused → plain confirm. Templates keep their HEX
  snapshots (D4) — since P4 the dialog NAMES the templates using the HEX
  ("HEX #… používá 3 šablony (A, B, C) — šablony si ho ponechají"); template
  use never blocks a delete (nothing in a template points at the palette
  colour, so there is nothing to break).
- **Read-only members** (§692): `.pal-page.is-readonly`, disabled fields, empty
  print fields dashed peach (`.ro-miss`), strip with HEX labels instead of the
  toolbar, no add / menu / grip, `.alert.c-warning` "Některé hodnoty u barev
  nejsou specifikované … Požádejte grafika o doplnění".
- **Overview + cards**: dashboard "Barevná paleta" chips = first 12 palette
  colours, pencil (edit) / eye (view) → the page; `GetProjectCardFacts`
  `has_palette` = `EXISTS project_color`. `app:palette:snapshot` (v2) mirrors
  both and adds a `palette` list per project.
- The old per-manual colours page (`manual_colors`) is gone since P3 —
  `/manual/{id}/colors` redirects to the box below.
- Pinned by `tests/Controller/Palette/ProjectPaletteControllerTest.php` and
  `tests/MessageHandler/Palette/ProjectPaletteHandlersTest.php`.

### Barvy v manuálech — a manual's colours, on Paleta barev (F7 P3, 2026-11-06)

The manual's own **Barevná paleta tab is gone** (`_manual_nav.html.twig`);
which palette colours a manual uses, and in which order, is edited in the
**"Barvy v manuálech"** box (`#manual-colors`, one `#manual-colors-{manualId}`
section per manual — `ProjectPaletteController::manualAnchor()`) at the bottom
of Paleta barev (D6, mirrors "Písma v manuálech").

- Every manual (logo AND brand — both have colours), `created_at, id` order,
  only while the Manuály module is on. Sections sit in a 2-column grid on wide
  screens (`.pal-man-grid`).
- Per manual two ordered segments: **Z logotypu** (logo links — the logo adds
  them, reorder only, no remove: the row menu says why) and **Další barvy**
  (added links). Number badges 1…n across both = `color(n)`; 1–3 highlighted
  (they are the default logo recolouring keys, `DefaultLogoColors`). Each row:
  swatch, HEX (a logo link shows its LITERAL), Název, print summary (CMYK ·
  Pantone · RAL — tells split duplicates apart until someone merges them) and the
  palette colour's **role as a read-only pill** linking to its card (D2: the
  role is per project, edited on the card).
- "Manuál potřebuje alespoň 3 barvy" (`colorsCount() < 3`) is shown in the
  manual's section (moved from the old page / tab warning icon).
- Writes (`manual_edit`, CSRF `project_palette`), all CQRS →
  `ManualPaletteWriter` (project row FOR UPDATE, one transaction, then
  `refreshManaged()`):
  - **Přidat z palety** (`manual_palette_add`, POST `/manual/{id}/paleta/pridat`,
    `colors[]`, Bootstrap dialog `#mc-add-{manualId}` listing the palette
    colours the manual does not link yet, multi-select) →
    `AddManualPaletteColors` → `addColors()`: appended after the manual's last
    colour as added links; already-linked / foreign / unknown ids skipped. A
    palette colour linked this way becomes a template swatch
    (`GetProjectPalette` is linked-only) — that is how "Přidat barvu" colours
    reach templates.
  - **Odebrat z manuálu** (`manual_palette_remove`, POST
    `/manual/{id}/paleta/{linkId}/odebrat`) → `RemoveManualPaletteColor` →
    `removeColor()`: deletes the link (the palette colour stays, maybe
    "Nepoužitá"); a logo link throws `ManualPaletteColorFromLogo` (flash
    warning), a link of another manual `ManualPaletteColorNotFound` (404).
  - **Reorder** (`manual_palette_sort`, JSON `{sorted: [linkId…], _token}`;
    drag on `.pal-grip` per segment `<ol>` via SortableJS forceFallback, or
    ⋯ Posunout výš / níž) → `SortManualPaletteColors` → `sortColors()`:
    logo links and added links are each permuted among the positions THEY
    hold, so the logo segment stays first whatever is posted. Changes
    `color(n)` → the default recolouring and the colour pages' order of that
    manual only; the palette order (templates, print, API) is untouched.
    Answers `{order}`; the page renumbers the badges.
- Read-only members: same rows, no grip / menu / add dialog, plain manual
  name (the manual pages are editors-only).
- `/manual/{id}/colors` (`manual_colors`, `ManualVoter::VIEW`) only REDIRECTS
  (302 — target depends on the project and the viewer; a 301 would be cached
  for good) to `project_palette#manual-colors-{id}`. The old form stack
  (`ManualColorsFormType` / `ManualColorFormType` / FormData, the template,
  `colors_dragula_controller.js`) is deleted; `custom_color_controller.js`
  only paints the live swatch of `LogoColorsMappingModal` now.
- Pinned by `tests/Controller/Palette/ManualPaletteColorsControllerTest.php`.

### Paleta barev — template usage + "Sloučit" duplicates (F7 P4, 2026-11-06)

- **Template usage** — "Použito v:" ends with a `.pal-tpl` chip "N šablon"
  (Czech plural `PaletteTemplateUsage::countLabel()`: 1 šablona, 2–4 šablony,
  0/5+ šablon; title lists the names). `Query/GetPaletteTemplateUsage` = ONE
  DBAL query over every variant of the project (canvas + inputs JSONB), scanned
  in PHP, keyed by the normalised `#rrggbb` (`hex_key`). Templates store
  colours as HEX snapshots — never palette ids, never `color(n)` (that index
  is the manual's logo recolouring) — so a template "uses" a palette colour
  when its HEX appears as a paint (`fill` / `stroke` / `backgroundColor` /
  `textBackgroundColor` / canvas `background` / gradient stops / textbox
  `styles`, nested groups included), in an allowlist (text inputs'
  `allowedColors` — column and canvas mirror — and recolourable shapes'
  `allowedColors`) or as a rich `sampleValue` run colour. Text inputs with NO
  allowlist (legacy brand swatches + picker) are not counted: they offer the
  linked palette dynamically. Counted per template; a multi-page document
  counts once (its name), like the Šablony listing. Skipped (empty) while the
  Šablony module is off. Same-HEX colours report the same templates.
- **"Stejný HEX" + Sloučit** (editors only — read-only members see neither):
  colours sharing a `hex_key` get a peach `.pal-dup` pill ("Stejný HEX má i
  další barva", title = the others' print data) + "Sloučit" opening ONE dialog
  per group (`#pal-merge-{hex_key without #}`): radio "which one stays"
  (default = the colour the most manuals use → fewest manuals change, then
  more filled fields, then the earlier palette position —
  `PaletteColorMerge::defaultSurvivor()`), and a server-rendered preview per
  possible survivor (`PaletteColorMerge::preview()`; `mergeChoice` in
  `project_palette_controller.js` swaps them): the resulting colour + what it
  gains, every manual that changes (print data, role, HEX spelling of added
  colours, "barva v něm zůstane jen jednou") and whether template print PDFs
  get other CMYK (`BrandCmykPalette` first-wins).
- **Merge** (`project_palette_merge`, POST `/project/{id}/paleta/sloucit`,
  `survivor` + `colors[]`, CSRF `project_palette`, `ProjectVoter::EDIT`; the
  survivor must belong to the URL's project, else 404) → `MergeProjectColors` →
  `ProjectPaletteWriter::mergeColors()`: refused (`ProjectColorsNotDuplicates`,
  flash) unless every loser is a colour of the survivor's project with the
  same `hex_key`. In one transaction under the project lock: **fill the gap**
  (`PaletteColorMerge::mergedFields()` — the survivor's empty CMYK tuple /
  Pantone / RAL / Název take the first filled loser value in palette order; a
  filled survivor field is never overwritten; role + HEX spelling stay the
  survivor's), the survivor takes the group's EARLIEST palette position
  (swatch order unchanged), every loser link moves to the survivor (logo
  links keep `logo_hex`), a manual that now links it twice keeps its logo
  link(s) and drops added ones — with only added links it keeps one at the
  earliest of their positions (two logo literals of one HEX both stay: each is
  a recolouring key) — then the losers are deleted + `refreshManaged()`. Redirects to `#pal-{survivor}`.
- Pinned by `tests/MessageHandler/Palette/MergeProjectColorsHandlerTest.php`,
  `tests/Controller/Palette/MergeProjectColorsControllerTest.php`,
  `tests/Query/GetPaletteTemplateUsageTest.php`,
  `tests/Services/Palette/PaletteColorMergeTest.php`.

### Manual fonts — edited on the project Fonty page (F8, 2026-11-05)

A brand manual's `ManualFont`s (role primary/secondary, sample colour,
enabled cuts `fontFaces`, `position`) feed the customer manual preview's font
pages and the editor's font defaults (`ResolveEditorFontDefaults`, see
`templates-text-inputs.md`). The manual's own **Fonty tab is gone**
(`_manual_nav.html.twig` = Logotypy · Stránky s mockupy — the Barevná
paleta tab went in F7 P3, see "Barvy v manuálech"):
they are edited in the **"Písma v manuálech"** box (`#manual-fonts`, one
`#manual-fonts-{manualId}` section per manual) at the bottom of the project
Fonty page (`FontsController` → `fonts.html.twig`).

- Only BRAND manuals are listed (logo manuals have no fonts), and only while
  the Manuály module is on. Sections are ordered by manual `createdAt`.
- Per row: font name in its first face, role pill, colour dot + hex, the
  cuts as toggle chips (`manual-font-faces-toggle` → `update_manual_font_faces`,
  saves on change), drag grip (`dragula` → `sort_manual_fonts`), ⋯ Upravit
  (`edit_manual_font`) / Odebrat (`delete_manual_font`, ConfirmModal
  `mf-{id}`). Read-only members see disabled chips and no actions
  (`manual_edit` / `manual_font_edit`).
- The routes stay: `manual_fonts` (`/manual/{id}/fonts`) only REDIRECTS to
  `fonts_list#manual-fonts-{manualId}`; add / edit / delete redirect there
  too, and `add_manual_font` / `edit_manual_font` are Fonty sub-pages
  (`active_menu_item = 'fonts'`, back trail Project → Fonty).
- `ManualFont::edit()` resets `fontFaces` only when the FONT changes (F11:
  it used to compare with its own id, so every save wiped the cuts).
- **A manual font starts with EVERY cut enabled** (`AddManualFontHandler`
  passes `Font::faceNames()`; switching the font in `edit()` enables all of
  the new font's cuts). The preview (`manual_preview.html.twig`) skips a font
  with no enabled cut, so the old `[]` default made a freshly added font
  invisible in the manual (Sagena, 2026-11-08 — the chip row reads like a
  list of cuts, not toggles that are off). The Fonty row now warns
  (`.fn-mrow-warn`) when no cut is on. `Version20261108120000` gave the
  pre-existing empty rows all their font's cuts (one row on prod).
- **Cuts are switched OFF, never on, by the designer**: a face uploaded later
  to a font a manual already presents is enabled there too
  (`AddFontHandler` → `ManualFont::enableAddedFontFace()`, list kept in the
  font's face order, cuts switched off stay off); deleting a face drops it
  from every manual's list (`DeleteFontFaceHandler` →
  `dropFontFace()`). Renaming a font renames only the family — face names, the
  key of `fontFaces`, do not change. Pinned by
  `UploadFontControllerTest::testANewFaceIsEnabledInManualsUsingTheFont…`.
- Pinned by `tests/Controller/Font/FontsPageManualFontsTest.php` (incl. add →
  preview shows every cut), `tests/Entity/ManualFontTest.php`.

### Manual logo width — a three-level cascade

How big a logo renders inside one manual card resolves through
`Manual::logoDisplayWidth($slot, $logoVariant)`, highest priority first:

1. the width set on **that card** (`manual.logo_slot_widths`, a JSON map keyed
   by slot id) — edited from a pencil on the card itself (see **Editing a
   card's width** below);
2. the width of the **logo variant** (`manual.logo -> <variant> ->
   displayWidth`, the "Šířka loga v manuálu" slider on the Loga page);
3. nothing — the stylesheet decides.

**The value is a % of the logo's DEFAULT size in that card (since
2026-11-09, `Value/LogoScale`)** — 100 % = výchozí, 50 % = half, 200 % =
double; range **10–200 %** (decided with Jan: 0 would hide the logo). Before,
it was "% of the frame" (0/empty = default, 1–100 = frame %), which left no
way to say "half the default" (user feedback). Rules:

- `LogoScale::normalize()`: null / ≤ 0 = no value, else clamped to 10–200.
  The variant level stores 100 as null (it IS the default); a CARD stores 100
  (it overrides a scaled variant back to default for that one card).
- **Rendering is CSS only.** `_manual_logo_image.html.twig` emits
  `style="--logo-scale: 0.5"` (`Manual::logoDisplayScale()`, nothing for
  null/100). app.css: every card type declares only a height cap
  `--logo-cap` on the wrapper (200 px, 120 px for horizontal / vertical-big);
  `.manual-image-wrapper img` = `width: calc(100% * min(scale, 1))`,
  `max-height: calc(cap * scale)`. The default box is "card width × cap" and
  the SVG letterboxes inside it, so scaling both scales the VISIBLE logo
  exactly, for any aspect ratio, with no PHP knowledge of the geometry.
  **Growing is clamped to the frame** (the width factor stops at 1) —
  `LogoScale::renderedWidth()` = min(default × scale, frame) is the contract,
  pinned by `tests/Value/LogoScaleTest.php` and verified in Chromium (50 % /
  150 % measured 50.0 / 150.0 % of the default). Never set `max-height` on
  the img per card type again — set the wrapper's `--logo-cap`, or the scale
  stops applying.
- **Protective-zone card ("Ochranná zóna", reworked 2026-11-09,
  `LogoScale::protectedZone()` is the contract).** The white logo area hugs
  the logo edge to edge and the grey zone is **1/3 of the logo HEIGHT on
  every side**. Root cause of the old bugs (white bands beside the logo, a
  fixed 72 px zone, and >100 % doing nothing): both inner divs shrink-wrapped
  an `<img>` whose box, for a viewBox-only SVG, is the 300 px default object
  width — the logo was a % of that same box, so it could shrink but never
  grow. Now everything lives on the img: content box = the logo (width
  `--pz-w`, height auto), white `content-box` background; padding = the zone
  (`--pz-w / ratio / 3`), transparent over the grey div (whose `p-5` and the
  white div's inline background are neutralised in app.css). `--pz-w` =
  default × scale, clamped so logo + both side zones fit the frame
  (`100cqw`, the frame is an inline-size container); default = `--logo-cap`
  × ratio, at most 2/3 of that room so 150 % can always grow in the
  half-width cards. The ratio is `--logo-ratio` on every logo `<img>`
  (`LogoAspectRatio`, read from the SVG root's viewBox — every prod
  protection-zone logo has one, surveyed 2026-11-09; missing = treated as
  square). Zone labels are sized in % of the zone box (top / bottom = 20 %
  of its height, sides = the same via `aspect-ratio: 1`); the download and
  width pencil buttons hang just outside the box. The per-slug kozelsky
  hacks set `--pz-w` instead of `width`. Verified in Chromium: zone / logo
  height = 0.333, white area = drawn logo ±1 px, live preview = saved =
  reloaded, for horizontal / vertical / symbol at 50–200 %.
- **Legacy data** was converted by `Version20261109010000` with values
  MEASURED per card on the prod previews (the default is a layout result, not
  computable in SQL): 14 slot rows changed + 1 pinned card
  (`minimum_dimensions.verticalWithClaim.base` = 111 in logo-spolecne, whose
  variant-level 85 means something else there). Re-verified by swapping the
  new app.css into the live prod pages: 126 cards, worst drift 1.9 px.
  Guarded by the old value, so it is idempotent.
- UI: Loga page slider (`range_sync_controller.js`) and the per-card bar
  (`manual_logo_width_controller.js`) both run 10–200 with a **detent**
  (±4 snaps onto 100), a datalist tick at 100 and a "Výchozí" reset; the
  number field always shows a number (empty variant = 100).

**Slot ids** are `<manualPage>.<logoVariant>.<colorVariant|base>`
(`protection_zone.vertical.base`, `symbol.symbol.darkBackground`) — stable
under adding or removing logos, unlike an ordinal. They are literals in
`manual_preview.html.twig`; changing one orphans its stored override.

Every logo card renders through **`_manual_logo_image.html.twig`** (which
replaced the file's `logo_image` macro) — that is what makes the variant-level
setting reach all of them. Before, three sites emitted a raw `<img>` and
ignored the width entirely: the vertical + symbol cards of the
protected-zone page and every card of `_logo_min_dimensions.html.twig`.

History (pre-2026-11-09, "% of the frame"): the partial wrote `width: N%
!important; max-height: none !important` — `!important` to beat the per-slug
`width … !important` hacks, `max-height: none` because the 120/200 px cap
SQUASHES an explicitly-width'd `<img>` instead of scaling it. Both are gone:
the scale variable multiplies the cap instead of lifting it, and the hacks
themselves multiply by `--logo-scale` (the protective-zone ones via `--pz-w`).

**Editing a card's width — one live-preview bar, no reload (2026-10-11).**
It began as a Live component + Bootstrap modal PER CARD whose save redirected
to `manual_preview`: the backdrop hid the logo being sized, and every save
threw the admin back to the top of a manual dozens of screens long. Now:

- the pencil is a plain button rendered by `_manual_logo_image.html.twig`
  (slot / own width / variant width as Stimulus action params); the ONE bar
  (`_manual_logo_width_bar.html.twig`) is mounted once by
  `manual_preview.html.twig`, together with `data-controller=
  "manual-logo-width"` on the manual root — only when `manual_edit` is granted;
- `manual_logo_width_controller.js` previews on the card's `<img>` (found by
  `data-logo-slot`) by setting the SAME `--logo-scale` the partial
  renders; Zrušit / Escape restore the original inline style; Uložit (or
  Enter) POSTs to `EditManualLogoSlotWidthController`
  (`/manual/{manualId}/sirka-loga`, CSRF `manual_logo_slot_width`), which
  answers JSON and the page stays put;
- that endpoint **validates the slot id against the three enums**
  (`ManualPage`, `LogoTypeVariant`, `LogoColorVariant`|`base`) — the old Live
  prop was server-signed, a POST field is not, and without the check any key
  could be planted in `logo_slot_widths`;
- the slider starts from the card's effective value (its own, else the
  variant's, else 100) — no measuring needed since the value is relative to
  the default;
- the bar is **anchored above the card being sized** (2026-10-13, user
  feedback — it was fixed to the viewport bottom): `position: absolute`
  against the manual root (`.manual-logo-width-root { position: relative }`,
  added only when `manual_edit` is granted), top/left computed by
  `placeAboveCard()` — centred on the card, clamped to the content column,
  re-run on window resize and after every live preview (a growing logo can
  move a vertically centred card). It scrolls with the page;
- the bar is `.card.manual-logo-width-bar` on purpose — the theme's
  `.card { position: relative }` loads after app.css and beat a plain
  `.manual-logo-width-bar { position: … }` (browser-verified).

### Manual page texts — admin-overridable, code-authored defaults

Every fixed page of a manual (`manual_preview.html.twig`) used to carry its
heading and its descriptive paragraph as literal Twig, so every brand/logo
manual read identically. The wording now lives in the **`ManualPage` enum**
(`defaultTitle()` / `defaultDescription()`) and an admin can override either
half per manual: `manual.page_texts` is a JSONB map keyed by the enum VALUE
(renaming a case orphans its overrides) holding `{title, description}` with
either half nullable — blank input is normalized to null, and an entry with
nothing in it is dropped rather than stored.

- **Two shared partials** replace the per-page markup:
  `_manual_page_heading.html.twig` (number + manual name + title + the admin
  pencil) and `_manual_page_description.html.twig`. Pages address themselves
  through the `manual_page('<key>')` Twig function; the font pages derive the
  key from the font's TYPE (`manual_font.type.value ~ '_font'`), so primary
  and secondary carry their own texts.
- **The escaping asymmetry is load-bearing**: a DEFAULT is developer-authored
  HTML (the monochrome pages ship `<p>`/`<strong>`) and is rendered `|raw`; an
  OVERRIDE is plain text typed by a user and is always escaped, with
  blank-line-separated paragraphs and single newlines as `<br>`. There is no
  HTML sanitizer in this project — do not "unify" the two branches by rendering
  an override raw.
- **Editing** = `Twig/Components/ManualPageTextComponent` (`ManualPageText`),
  a pencil + Bootstrap modal (plain Twig component since 2026-11-09, saved in
  place — see **Pencil modals save in place** below). It renders NOTHING
  unless `manual_edit` is granted, which is what lets the public manual use
  the same markup. The textarea is pre-filled via
  `defaultDescriptionAsPlainText()`, so an admin tweaks the stock wording
  rather than retyping it.
- The protected-zone page's default title is the literal `'Základní
  logotypy'` — the same heading the basic-logos page uses. That is today's
  wording kept verbatim so no live manual changed appearance on deploy; it
  reads like a copy/paste slip and an admin can now fix it per manual, which
  is precisely the point of the feature.

### Pencil modals save in place — Turbo Streams (2026-11-09)

Both pencil modals of the preview used to be Live components whose save
REDIRECTED to `manual_preview` — every edit threw the admin back to the top of
a manual dozens of screens long (Jan's feedback, sagena). They are now plain
Twig components with ordinary Symfony forms posting through Turbo; the logo
width bar was already in place (JSON, see above).

- **Endpoints**: `EditManualPageTextController`
  (`POST /manual/{manualId}/texty-stranky/{page}`, `manual_page_text_edit`) and
  `EditManualLogoColorsMappingController`
  (`POST /manual/{manualId}/mapovani-barev-loga/{logoVariant}/{colorVariant}`,
  `manual_logo_colors_mapping_edit`, 404 for a logo variant without image),
  both `ManualVoter::EDIT`. The forms come from `Services/Manual/ManualEditForms`
  (shared by the components that render them and the controllers that handle
  them), NAMED per page / per variant pair (`manual_page_text_<page>`,
  `logo_colors_<variant>_<colorVariant>`) so dozens of modals don't share
  field ids. CSRF = the forms' own stateless `_token`; the modal body partials
  carry `form_rest`, so a re-rendered body brings its own token.
- **Opt-in**: the `<form>` has `data-turbo="true"` (site is
  `<html data-turbo="false">`); the submit button is inside it, so the
  submitter is navigatable too. Turbo POSTs with the stream type in `Accept`.
- **Answer** (`Services/Manual/ManualPreviewTurboStream`,
  `_manual_preview_regions.stream.html.twig`): `replace` actions with
  `targets="#id"`. Regions OUTSIDE the modal are cut out of a full render of
  `manual_preview.html.twig` (PHP 8.4 `Dom\HTMLDocument`, `regions()`), NOT
  re-rendered from partials — the ~30 logo cards are inlined with per-page
  wrapper classes and the description's column class comes from the caller,
  so the page is the only source of their exact markup. (Every card reads
  its OWN mapping's background — until 2026-11-09 the horizontalWithClaim
  light/oneColor cards painted the HORIZONTAL one while their downloads used
  their own; fixed on Jan's call, it changed mas/mas-manual and
  ostravice/ostravice-logomanual on prod.) The modal BODY is rendered from its partial
  (`_manual_page_text_form_body`, `_manual_logo_colors_form_body`): fresh after
  a save, with errors + status 422 on a validation failure. Targets: page texts
  → `#manual-page-title-<page>`, `#manual-page-description-<page>` (a `d-none`
  placeholder when the page has no text — intro — so there is always a
  target), `#manual-page-text-form-<page>`; colours →
  `#logo-card-<variant>-<colorVariant>` (whole card: background, recoloured
  logo, download links, pencils), `#logo-colors-form-<variant>-<colorVariant>`.
- **The modal itself is never streamed.** Replacing an open Bootstrap modal
  leaves its backdrop and `body.modal-open` behind. So the colour modals are
  rendered OUTSIDE the cards, once, at the end of the preview
  (`_manual_logo_colors_modals.html.twig`, 5 colour variants × every logo with
  an image), the card only holds the pencil (`components/LogoColorsMapping` —
  anonymous, button only). `#logo-card-*` must never contain a `.modal`
  (pinned). The page-text modal stays in the heading — only the title span and
  the description are replaced. `modal_controller.js` hides the modal on
  `turbo:submit-end` with `detail.success` (2xx); a 422 keeps it open.
- **No-JS fallback**: plain POST → redirect to the preview anchored on the
  edited element (`#manual-page-title-<page>` / `#logo-card-…`).
- Pinned by `tests/Controller/Manual/ManualPreviewInPlaceEditTest.php`.
  Browser-verified 2026-11-09 (puppeteer, 1440 + 390 px, both theme
  preferences — the preview is always light): scroll and the edited element's
  viewport position unchanged, no backdrop left, reopen + save again, 422 keeps
  the modal open, Escape closes it.

### Manual content pages — editable intro (P1, 2026-11-09)

Plan + phases: `docs/plans/2026-11-manual-content-pages.md` (P2 custom pages
— see the next section; P3 gallery — the section after it; P4 cleanup — the
legacy `manual.intro_image` column and the form upload are GONE, see below).
All phases delivered 2026-11-09.

- **Model.** `ManualContentPage` (`manual_content_page`): `role`
  (`ManualContentPageRole`: `intro` | `custom`), `name` (plain title — printed
  in the page heading row "NN · manual · NAME", NOT a content element),
  `layout` (`MockupPageLayout`, the mockup grid's 11 layouts), `segments`
  (JSONB via `ManualContentSegmentsDoctrineType`), `position` (custom pages:
  the position space shared with mockup pages, see P2 below; the intro is
  always first and keeps 0), timestamps. Manual CASCADE; **at most one intro
  per manual** = partial unique index `uniq_manual_content_page_intro … WHERE
  role = 'intro'` (also declared on the entity so the test schema has it).
  `ManualContentPage::fitSegments()` keeps exactly one segment per layout
  slot — a shorter layout moves the dropped segments' elements into the last
  slot, never deletes them (the JS editor does the same on a layout switch).
- **Value objects** (`src/Value/ManualContent/`), strict on decode
  (`InvalidManualContent`, 422): `ContentSegment {verticalAlign top|center|bottom,
  elements}`, elements discriminated by `type` — `HeadingElement {id, level
  1–3, text}`, `RichTextElement {id, doc}`, `ImageElement {id, path,
  galleryImageId (P3: the gallery row; path = its key snapshot), fit
  natural|cover, alt}`. Element ids are
  client-generated (`[A-Za-z0-9_-]{1,64}`). Image `path` must be a bare key
  `manuals/<uuid>/…` or `file-upload/<uuid>/…` (no URL, no `..`); the save
  handler resolves `galleryImageId` (same project, its key replaces the
  path) and otherwise requires the key under THIS manual / project (P3). `ManualContentPagePayload` = the whole
  editor POST (`{name, layout, segments}`, one segment per slot, ≤ 120
  elements, ≤ 1 MB).
- **Rich text = ProseMirror JSON, never HTML** (`RichTextDoc`). Allow-list:
  `paragraph`, `bullet_list`, `ordered_list` (attrs.order), `list_item`
  (paragraph first), `text`, `hard_break`; marks `link` (http/https/mailto only,
  no whitespace/control chars), `bold`, `italic`, `underline`, `font`
  (attrs.family), `color` (attrs.color `#rrggbb`). Unknown keys are dropped,
  marks come out in `RichTextDoc::MARK_ORDER` — the JS schema declares its
  marks in the same order so `toJSON()` matches. Which fonts / colours are
  allowed is NOT a structural rule (it changes): `ResolveManualContentStyles`
  = every face of the manual's fonts as `Font::faceFamily()` (`"Rubik (Rubik
  Bold)"`, the family the manual's `@font-face` uses) + the project palette
  (`GetProjectPalette::forProject()`); the save handler drops disallowed
  font/colour marks (`restrictStyles`) and the renderer re-checks at render.
- **Render.** `RichTextHtmlRenderer` (Twig `manual_rich_text(doc, styles)`)
  escapes everything, re-validates hrefs/colours/fonts, emits font names as
  CSS-escaped strings. Tests incl. XSS attempts:
  `tests/Services/Manual/RichTextHtmlRendererTest.php`,
  `tests/Value/ManualContent/RichTextDocTest.php`. **Twig gotcha:** call
  `doc.isEmpty()` explicitly — `doc.empty` resolves to the STATIC factory
  `RichTextDoc::empty()` (always truthy).
- **One partial** `_manual_content_page.html.twig` (+ heading row
  `_manual_content_page_heading.html.twig`) for the public manual (the
  editor's "Náhled v manuálu" panel was removed 2026-11-10 — the editor paper
  already is the 845 px page). CSS `.mcp-*` in `app.css` (both
  entrypoints), own typography/colours. Grid = the mockup 3 × 2 tracks on a
  **fixed 845 px page since 2026-11-10** (`--mcp-h`, ≥ 768 px viewports; below
  that auto height): two equal rows, text past a slot's height is cut off. The
  editor stage has `min-height` 845 px instead, growing only when its editing
  chrome needs it. Image `natural` = own ratio, `max-width:100%` + a max-height
  of its slot (`.mcp-rows-1|2`, scaled, never cut), centred;
  `cover` = slot width cropped to the slot's canonical ratio (inline
  `aspect-ratio`).
- **Manual numbering: "no content → no page".** The intro renders (and takes
  page 01) only when `hasContent()` (an image, a non-empty heading or rich
  text). The old intro page texts (`ManualPage::Intro` overrides) no longer
  render anywhere: the title became the page `name`, the description a
  rich-text element. An admin sees a pencil on the heading row linking to the
  editor.
- **Editor** `manual_intro_page` (`/manual/{id}/uvodni-stranka`, "Úvodní
  stránka" tab, first in `_manual_nav`, both manual types, `manual_edit`;
  template `manual_content_page_editor.html.twig` + `ManualContentPageEditor`,
  shared with custom pages since P2).
  A manual without an intro page opens with `IntroContentPageDefaults`
  (title override or "Úvod", description override as rich text, no
  picture) — nothing is written until Save.
  `manual_content_editor_controller.js` (**lazy** Stimulus controller — the
  ProseMirror bundle loads only here): layout picker (mockup mini-grids),
  segments on white "paper" (light tokens re-scoped on `.mce-paper`, the
  manual is always light), "+ Přidat prvek" (Nadpis / Text / Obrázek),
  per-segment vertical align, element delete, SortableJS within and between
  segments (`group`, `forceFallback: true`, own ghost/drag classes —
  element DOM nodes are only MOVED, never rebuilt, so ProseMirror views keep
  caret + undo), heading = auto-height textarea, inline toolbar (one node MOVED into the focused text `.mce-el` under its header, parked hidden otherwise, closes on a press outside; B/I/U, lists,
  link popover, manual-font select, palette swatches; toolbar buttons
  `preventDefault` on mousedown to keep the selection). Save = ONE POST
  (`manual_intro_page_save` / `manual_content_page_save`, form fields
  `_token` + `payload` JSON) →
  `SaveManualContentPage` (upsert; for the intro the existing intro wins over
  the id) → JSON `{state, preview}`; dirty tracking compares key-sorted JSON,
  `beforeunload` guard, Cmd/Ctrl+S. Pictures upload separately
  (`manual_content_image_upload`, `UploadManualContentImage`) to
  `manuals/{manualId}/content/{uuid7}.{ext}`, 10 MB DECIMAL (`'10m'`, mirrored
  in the template), normalised like gallery uploads (HEIC → JPEG/PNG, SVG
  kept); an upload never saved stays an orphan for the storage scan.
- **ProseMirror delivery**: `assets/vendor-pinned/prosemirror.bundle.js`, ONE
  committed esbuild ESM bundle imported by relative path (no importmap pins —
  jsDelivr `+esm` non-determinism). Versions + rebuild:
  `resources/prosemirror-bundle/README.md`.
- **Legacy upload — removed (P4, 2026-11-09).** The add/edit manual form
  (`ManualFormType`: name + type only) has no "Úvodní obrázek" any more; a
  `.man-formhint` box points at the "Úvodní stránka" tab (a link on edit).
  `IntroContentPageImage` is gone, `Manual::__construct()` / `edit()` lost
  the `introImage` argument, and `Version20261109220000` dropped
  `manual.intro_image` (before the drop all 18 non-empty prod values were
  verified to be referenced by their intro page image element, by path AND
  gallery row; its `down()` re-adds the column and backfills it from the
  intro page's first image element). The only remaining reader of the old
  column is the historical migration `Version20261109140100` (via
  `IntroContentPageMigration`), which runs before the drop.
- **Data migration** `Version20261109140100` (plan in
  `IntroContentPageMigration`, tested): every manual with `intro_image` got
  its intro page (layout-7; description override → rich text; picture,
  natural, alt "Úvod"); manuals without one got nothing. Idempotent.
- **Storage**: image paths are enumerated by `BuildStorageReferenceIndex`
  (`manual_content_page.segments`); the keys live under `manuals/{manualId}/`,
  so project deletion already removes them.
- Pinned by `tests/Controller/Manual/ManualIntroPageTest.php`,
  `tests/Controller/Manual/ManualFormControllerTest.php` (P4: no upload, hint),
  `tests/Entity/ManualContentPageTest.php`,
  `tests/Value/ManualContent/ManualContentPagePayloadTest.php`,
  `tests/Services/Manual/IntroContentPageMigrationTest.php`,
  `ScanStorageTest::testImageOfAContentPageIsNotAnOrphan`. Browser-verified
  2026-11-09 (puppeteer, local): layout switch, add heading/text/image,
  bold + manual font + palette colour + link + ordered list + undo, drag
  between and within segments, upload, save, reload round-trip, public render,
  dirty flag; screenshots 390/820/1440 × dark/light.

### Manual content pages — custom pages + shared page order (P2, 2026-11-09)

- **Stránky tab** (`manual_mockup_pages`, `/manual/{id}/mockup-pages` — route
  name/path kept; tab renamed "Stránky", count = mockup + custom pages via
  `Manual::pagesCount()`; brand manuals only, like the mockup pages always
  were). ONE sortable list (`GetManualPageList` → `ManualPageListItem`) of
  mockup pages AND custom content pages; the intro is not in it (own tab,
  pinned first). Each card has a kind pill ("Mockupy" / "Obsah", plus
  "Prázdná" for a content page with no content — it is not shown in the
  manual). Content page thumbnail = `_manual_content_page_thumb.html.twig`:
  the same canonical grid as `_mockup_page.html.twig`, a slot shows its first
  picture / stylised text lines / the placeholder (NOT a scaled render —
  cheap at rail size). "Přidat stránku" is a dropdown: Stránka s mockupy
  (the old add page) / Obsahová stránka (dialog: name + layout).
- **Shared order contract.** `manual_mockup_page.position` and
  `manual_content_page.position` (role `custom`) form ONE position space per
  manual. Read: `GetManualPageList::forManual()` merges both, sorted by
  `(position, created_at, id)`. Write: `SortManualPagesController`
  (`sort_manual_pages`, POST `/manual/{manualId}/stranky/poradi`,
  `{sorted: ["mockup:<uuid>", "content:<uuid>", …]}`) → `SortManualPages` →
  position = index in the posted list, both tables in the bus' one Doctrine
  transaction. Items are TYPED ids (`ManualPageListItem::typedId()` /
  `parseTypedId()`: the two tables' ids are independent); malformed, untyped,
  foreign-manual ids and the intro are skipped (the voter only checked the
  manual in the URL). New pages of either kind append:
  `ManualPagePositions::next()` = max over both tables + 1 (used by
  `AddManualMockupPageHandler`, `AddManualContentPageHandler` and a custom
  page created by `SaveManualContentPage`). The old mockup-only
  `/sort-manual-mockup-pages/{id}` + `SortManualMockupPages` are gone; both
  JS controllers (`dragula_controller.js` grid, `manual_mockup_pages_controller.js`
  rail, `forceFallback: true`) just post the cards' `data-entity-id`.
- **Migration** `Version20261109170000` renumbered every manual's mockup
  pages 0…n-1 in their rendered order (`ManualPagePositions::RENUMBER_MOCKUP_PAGES_SQL`,
  ties by `created_at, id` — prod had one tie and one list starting at 1).
- **Routes.** Custom pages are addressed by page id, the intro by its manual
  (it may not exist yet): `add_manual_content_page` POST
  `/manual/{manualId}/stranky/nova` (name + layout, CSRF
  `manual_content_page`) → redirect to `manual_content_page` GET
  `/manual/stranka/{id}` (editor; an intro id redirects to
  `manual_intro_page`), `manual_content_page_save` POST same path,
  `rename_manual_content_page` POST `/manual/stranka/{id}/prejmenovat`,
  `delete_manual_content_page` POST `/manual/stranka/{id}/smazat` (confirm
  dialog, CSRF; the intro → `IntroContentPageCannotBeDeleted`, 400; uploaded
  pictures stay in storage as orphans for the scan). All gated by
  `ManualContentPageVoter::EDIT` (= `ManualVoter::EDIT` on the page's manual),
  add/sort by `ManualVoter::EDIT`. Name rules shared:
  `ManualContentPagePayload::name()`.
- **Editor** = the intro's (`ManualContentPageEditor` builds the parameters
  and handles the one-POST save for both roles). The save answers `{state}` only.
- **Public render** (`manual_preview.html.twig`, `manual_pages` from
  `ManualPreviewTurboStream::previewParameters()`): after the built-in pages,
  the list in its shared order — mockup pages as before, content pages via
  the same heading + body partials as the intro (admin pencil → the page's
  editor). Empty content pages are skipped and take no number; `#page-N`
  anchors stay consecutive. The manual has no TOC/nav to update.
- **Storage**: `BuildStorageReferenceIndex` enumerates image elements of
  every content page regardless of role (`ScanStorageTest` covers both).
- Pinned by `tests/MessageHandler/Manual/ManualContentPagesTest.php` (add /
  rename / delete / intro not deletable / mixed sort / renumber SQL / typed
  ids) and `tests/Controller/Manual/ManualContentPagesControllerTest.php`
  (routes, voter, list, sort, public order + numbering).

### Manual pictures in the project gallery (P3, 2026-11-09)

Full contract in `gallery-and-uploads.md` "Manual pictures live in the
gallery". In short:

- Every manual picture is a gallery picture in `Manuál / <manual>` (nested
  folders, found by `file_directory.system_key`). Rows REFERENCE the manual's
  keys — nothing was copied; content image elements carry `galleryImageId`,
  mockup slots stay plain keys (matched to rows by key).
- `app:manual:import-images-to-gallery [--dry-run]` did the existing pictures
  (idempotent; missing keys skipped + listed).
- **Content editor**: "+ Přidat prvek" offers "Obrázek z galerie" /
  "Nahrát obrázek"; an image element has "Vybrat jiný z galerie" + upload.
  Uploads still go to `manuals/{id}/content/{uuid}` and register in the
  gallery (`galleryImageId` = that UUID). The save handler re-resolves every
  `galleryImageId` (project check, row key replaces the client path; a row
  whose key is not a manual/gallery key is refused — the element decode is
  strict). Editor state carries a display-only `unavailable`.
- **Mockup editor**: each slot has "Vybrat z galerie" / "Z galerie" →
  hidden `galleryImages[i]` (form field) → `MockupPageSlotImages`: upload >
  gallery pick (live row of the manual's project, else ignored) > remove flag
  > keep. New uploads keep their `manuals/{id}/pages/{pageId}/…` key and
  register in the gallery. The picker modal is rendered OUTSIDE the form.
- **Koš / purge**: a picture a manual shows can't be purged (not even
  forced); a trashed one disappears for readers, editors see a stand-in
  (`_manual_image_standin.html.twig`; CSS `.manual-img-standin` in app.css).
  **Reader visibility (P4):** a content page (intro or custom) whose only
  content is an unavailable picture (Koš / gallery row gone) is empty FOR
  READERS — skipped, no page number (`manual_content_page_shown(page,
  can_edit)` Twig fn → `ManualContentPage::hasContentShowing()` with
  `ManualImageAvailability`). Editors still get the page (with its
  stand-in), so their numbering can differ from the readers'. The editor's
  "Prázdná" badge in the page list still means "no element at all".
- Mockup slot uploads go through `NormalizeImageFormat` like gallery
  uploads (P4): HEIC/TIFF → JPEG/PNG, web-safe formats byte-for-byte, SVG
  kept (`tests/Services/Manual/MockupPageSlotImagesTest.php`).
- Pinned by `tests/Services/Manual/ManualGalleryImportTest.php`,
  `tests/MessageHandler/Image/PurgeManualReferencedFileTest.php`,
  `tests/Controller/Manual/ManualGalleryPickerTest.php`.
