# Manuals — mockup pages, logo variants/widths, colours, fonts, page texts

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

Each dedicated page has its own `ManualPage` case (`horizontal_page`,
`horizontal_with_claim_page`, `vertical_page`, `vertical_with_claim_page`,
`symbol_page`, reached from Twig via `manual_logo_page(variant)`), so its
heading and description are admin-editable like any other page's, and its cards
carry `<page_key>.<variant>.<colorVariant|base>` slot ids for the width
cascade.

### Manual colours — HEX, CMYK, Pantone, RAL

`ManualColor` (the read-model VO — since F7 R2 built from the palette, see below;
the JSONB mirror stores it via `ManualColorsDoctrineType`) carries `ral` alongside
`pantone` (`project_color.ral`; in the JSONB the key is simply absent on older rows
and reads back as null). Every code is
printed by `_manual_color_swatch.html.twig` — extracted from the two
copy-pasted palette blocks — and only when the designer filled it, so a manual
with no RAL looks exactly as before. `ManualColorsFormType::isBlankColor()`
counts `ral` too, otherwise a row holding nothing but a RAL code would be
dropped on save.

### Project palette — the source of truth for colours (F7; P1a R1 → P1b R2, 2026-11-05)

Plan + decisions: `docs/redesign/palette-plan.md`. Two tables (`Version20261105170659`
CREATE + backfill). **Since P1b (release R2) they are what everything reads**; the
JSONB columns `manual.detected_colors` / `custom_colors` are a MIRROR kept for R1 code
(blue-green overlap, a code-revert rollback) until R5 drops them:

- `project_color` (`ProjectColor`): one palette colour — `hex` (literal, no `#`),
  `hex_key` (normalised `#rrggbb`, the dedupe key; indexed with `project_id`),
  `role` (D2: ONE role per project colour, `ManualColorType`), `cmyk` (jsonb tuple),
  `pantone`, `ral`, `name` (unused until P2), `position` (palette order). Project
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
  guarded in the app (P2).

**Read model (R2).** `Manual::$paletteLinks` (OneToMany, `fetch: EAGER` = one batch
query per hydration, palette colour EAGER-joined — no N+1 on listings / avatars, no
fetch-join needed) feeds the unchanged method API: `detectedColors()` = logo links
with their literal `logo_hex`, `customColors()` (a METHOD now — Twig
`manual.customColors` still resolves) = the other links with the palette HEX spelling
(§7), type = the palette role, CMYK/Pantone/RAL from the palette; `color(n)` = logo
segment ++ custom; `colorsCount()`; `primaryColors()` / `secondaryColors()` = links in
manual order filtered by the palette role. `ManualPaletteColor::manualColor()` builds
the `ManualColor` VO. The JSONB is reachable only as `jsonbDetectedColors()` /
`jsonbCustomColors()` for `Services/Palette/JsonbManualColors` — the FROZEN pre-R2
read model (old `detectedColors()` incl. its loose `in_array`, old primary → secondary
→ untyped walk, old print-CMYK first-wins) used by verify / reconcile / parity tests.

**Project-wide colours** = `Query/GetProjectPalette::forProject()` / `colorsForProject()`:
palette order (D5) and **only colours ≥1 manual links** (R2 decision: R1 never
deleted palette colours and the old colours page still splits / abandons them, so
leftovers must not become swatches or win the print "first CMYK"; the P2 palette page
lists them as "Nepoužitá" via `ProjectColorRepository::forProject()`, which returns
all). Feeds `ResolveRichTextOptions::computeColors()` (now takes the palette
`ManualColor`s — editors, group editor, fill, API, MCP `get_context` /
`describe_variant`), `LintContext::forProject()` and `BrandCmykPalette::fromPalette()`.

**Write path (R2) = `Services/Palette/ManualPaletteWriter`** — palette + links FIRST,
then `mirrorProject()` rewrites EVERY manual of the project's JSONB from its links
(`detected_colors` = logo links, `custom_colors` = the rest, palette role + print data
— the shape R1 reads, so a role / print change reaches the other manuals' mirrors
too). Callers: `EditManualColorsHandler` → `editColors()` (the old colours page, still
live until P3) and `UpdateManualImagesHandler` → `syncLogoColors()` after flushing
the new logo (D3: colours that left the logo lose their link and stay in the palette;
new literals are appended to the logo segment without a role, reusing a same-hex
palette colour — first by position — or appending one). `Manual::editLogo()` only
sets the logo now; `Manual::mirrorJsonbColors()` only sets the mirror (fixtures /
tests). A posted colour maps to a palette colour of its hex key:

1. its previous palette colour (logo row by literal, added row = the n-th earlier
   added link of that hex) when the print data is unchanged;
2. else that previous colour EDITED IN PLACE when no other manual links it (a cleared
   Pantone stays cleared, nothing is orphaned);
3. else the backfill's §7 rule (`PaletteBackfillPlanner::matchPrint`: first colour
   carrying its print data, else first compatible one, whose gaps it fills) — so a
   CMYK edit of a SHARED colour splits it (the other manual keeps the old one);
4. else a new palette colour at the end of the palette.

Roles (D2) change only when THIS write changed the manual's role for the colour (the
old page re-posts every type), never from a logo sync. Palette colours are never
deleted. DBAL on purpose (DELETE-then-INSERT vs the partial unique index), project
row `FOR UPDATE`, one transaction. **Afterwards `refreshManaged()` detaches the
project's managed `ProjectColor` / `ManualPaletteColor` entities and gives every
managed `Manual` an uninitialised link collection + its stored mirror** — so the
writing request reads what it wrote. NOT `EntityManager::refresh()`: it throws
"Attempting to change readonly property ::$createdAt" on these entities. A new
writer of manual colours MUST go through `ManualPaletteWriter`.

**Checks (prod-safe):** `app:palette:verify [--report=<csv>]` compares
`JsonbManualColors` (what R1 code would show) with the links read model and
classifies every difference (`PaletteParityDiff::ACCEPTED_KINDS`: D2 role / role
pages, §7 HEX spelling + gap fill, whitespace, D5 palette order, unused colours,
CMYK first-wins order). Since R2 any per-manual difference also yields
`jsonb_mirror_stale` (unexpected → exit 1). `app:palette:reconcile [--dry-run]`
re-projects such manuals FROM their JSONB with the R1 projector
(`ProjectManualColors::reproject`, no roles — the JSONB was written by R1 code, so it
is the newer truth) and then re-mirrors the project; on the first R2 deploy that is
what turns the owner-accepted differences into a mirror. Run it after every deploy
that overlapped with R1 code. No path columns — `BuildStorageReferenceIndex` is
unaffected.

### Manual fonts — edited on the project Fonty page (F8, 2026-11-05)

A brand manual's `ManualFont`s (role primary/secondary, sample colour,
enabled cuts `fontFaces`, `position`) feed the customer manual preview's font
pages and the editor's font defaults (`ResolveEditorFontDefaults`, see
`templates-text-inputs.md`). The manual's own **Fonty tab is gone**
(`_manual_nav.html.twig` = Logotypy · Barevná paleta · Stránky s mockupy):
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
- `ManualFont::edit()` clears `fontFaces` only when the FONT changes (F11:
  it used to compare with its own id, so every save wiped the cuts).
- Pinned by `tests/Controller/Font/FontsPageManualFontsTest.php`,
  `tests/Entity/ManualFontTest.php`.

### Manual logo width — a three-level cascade

How big a logo renders inside one manual card resolves through
`Manual::logoDisplayWidth($slot, $logoVariant)`, highest priority first:

1. the width set on **that card** (`manual.logo_slot_widths`, a JSON map keyed
   by slot id) — edited from a pencil on the card itself (see **Editing a
   card's width** below);
2. the width of the **logo variant** (`manual.logo -> <variant> ->
   displayWidth`, the "Šířka loga v manuálu (%)" field on the Loga page);
3. nothing — the stylesheet decides.

**Slot ids** are `<manualPage>.<logoVariant>.<colorVariant|base>`
(`protection_zone.vertical.base`, `symbol.symbol.darkBackground`) — stable
under adding or removing logos, unlike an ordinal. They are literals in
`manual_preview.html.twig`; changing one orphans its stored override.

Every logo card renders through **`_manual_logo_image.html.twig`** (which
replaced the file's `logo_image` macro) — that is what makes the variant-level
setting reach all of them. Before, three sites emitted a raw `<img>` and
ignored the width entirely: the vertical + symbol cards of the
protected-zone page and every card of `_logo_min_dimensions.html.twig`.

Two things in that partial's inline style are load-bearing, both verified in
real Chromium against a live manual:

- **`!important`** — a few manuals carry per-slug `width: … !important` hacks
  in `app.css` from before this control existed (e.g.
  `.manual-kozelsky-koupelny-logomanual .manual-image-vertical-big
  .protected-zone-wrapper img`), and an admin's explicit number has to win
  over them. Those hacks still apply where no width is set.
- **`max-height: none`** — `.manual-image-wrapper img` caps the height
  (120/200px) while the stylesheet also sets `width: 100%`. With an explicit
  width that cap does NOT scale the picture down, it SQUASHES it (the browser
  clamps the height and keeps the width — measured: a 100×125 logo in a 500px
  card renders 500×120), so an admin asking for a wide logo would get a
  distorted one. Lifting the cap lets a card grow, which is the honest reading
  of "width = N % of the frame".

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
  `data-logo-slot`) with the SAME two `!important` declarations the partial
  renders; Zrušit / Escape restore the original inline style; Uložit (or
  Enter) POSTs to `EditManualLogoSlotWidthController`
  (`/manual/{manualId}/sirka-loga`, CSRF `manual_logo_slot_width`), which
  answers JSON and the page stays put;
- that endpoint **validates the slot id against the three enums**
  (`ManualPage`, `LogoTypeVariant`, `LogoColorVariant`|`base`) — the old Live
  prop was server-signed, a POST field is not, and without the check any key
  could be planted in `logo_slot_widths`;
- the slider starts from the logo's **visible** width: with no explicit width
  the stylesheet stretches the `<img>` box across the card and caps its height,
  and the SVG letterboxes inside it, so the box width (≈100 %) overstates the
  logo and the first nudge would make it jump;
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
  a pencil + Bootstrap modal Live component modelled on `LogoColorsMapping`
  (the pattern already on the logo cards of the same page). It renders NOTHING
  unless `manual_edit` is granted, which is what lets the public manual use
  the same markup. The textarea is pre-filled via
  `defaultDescriptionAsPlainText()`, so an admin tweaks the stock wording
  rather than retyping it.
- The protected-zone page's default title is the literal `'Základní
  logotypy'` — the same heading the basic-logos page uses. That is today's
  wording kept verbatim so no live manual changed appearance on deploy; it
  reads like a copy/paste slip and an admin can now fix it per manual, which
  is precisely the point of the feature.
