# Templates — text inputs (font choice, rich text, lists, checklists, sample text)

**Font choice — "Uživatel může přepínat písmo" (`allowedFonts` on
`EditorTextInput`, 2026-09-03).** Per text input the designer may open the
FONT to the end user: `allowedFonts` = the EXTRA face families (exact
`"<Font> (<Face>)"` strings, the canvas' own vocabulary) on top of the
designed one; empty = no choice. Single source of the per-input offer is
`ResolveRichTextOptions` (now injected with `TextInputObjectBinder`):
`computeInputFonts()` puts the designed font first — a RICH input gets every
face of its family (B/I keep working), a PLAIN input its exact face — then the
picks in project-font order (stale picks drop), and a rich input with nothing
resolvable falls back to all project fonts (the pre-choice behaviour).
`RichTextOptions` carries `fontsByInput` + `designedByInput`; `fonts` is now
the UNION over the rich inputs (the API's `richTextOptions.fonts`, kept for
`@font-face` loading) and **validation is per input** —
`ResolveTextOverrides` checks a run's `fontFamily` AND the new whole-text
`fontFamily` override against `allowedFamiliesFor($inputId)` (strict: 400
`font_not_allowed` with the input's list, lenient: dropped). Consequence for
existing rich inputs: the WYSIWYG's face menu narrowed from "every canvas
font" to the designed family unless the designer ticks more.

- **Wire**: the whole-text pick is `fontFamily` on the value —
  `{ value, hide, fontFamily }` / `{ runs, hide, fontFamily }` (API + MCP),
  `fontValues[<inputId>]` on the web forms ("" = designed). It lands in
  `ResolvedInputOverrides::$fonts` → the renderer's `font_overrides`, applied
  in the render template BEFORE the text (plain re-wraps, unstyled runs
  inherit, a list stack takes it as base) and after phase A; the override
  families join the inlined `@font-face` narrowing. An object carrying only
  `hide`/`fontFamily` keeps the SAMPLE as the text. Export versions store
  `fonts` (emitted in `toArray()` only when non-empty — hash stability for
  stored font-less versions), the seeder re-validates picks against the
  current offer.
- **Fill pages**: `AbstractVariantFiller::$fontValues` LiveProp + a
  `data-font-mirror` hidden field per plain input with a choice (same
  debounce class as text); the popover shows a font select (designed face
  labelled "(výchozí)", switchable faces grouped — `textPlaceholders()` ships
  `fontChoice/fontGroups/fontDefaultLabel/fontValue`); the WYSIWYG's face
  menu is `ph.fontOptions` (per input). The echo painter applies
  `values[id].fontFamily` before the text (designed font restored when
  empty), `fillStateHash` / `_clientHash` add `F:<id>=<family>` lines after
  the hides, the overlay re-measures with the picked face. Group page:
  `GroupFillPlaceholders::fontOptions()` → a `fontValues[…]` select per
  opened-up input, `GroupFillRenderer::render(..., rawFontValues:)`.
- **API/MCP**: `inputs[].fontOptions` (nullable list, designed first; rich
  always, plain only when `RichTextOptions::offersFontSwitch()`), MCP
  `describe_variant` mirrors it as family strings; DSL `input.allowedFonts`
  (validated like `font`, path `elements[i].input.allowedFonts[j]`).
- **Configured faces + colour allowlist (2026-09-03, the "admin decides
  which cuts/styles and colours the user may use" follow-up).** `fontChoice`
  (bool) on `EditorTextInput` marks the face offer as admin-CONFIGURED: a
  rich input then offers the designed face + `allowedFonts` ONLY (with no
  picks = the user cannot change the face, B/I disabled) instead of its
  whole family; unconfigured rich inputs keep the whole-family offer
  (`restrictsFaces()` = flag OR non-empty picks, so rows saved before the
  flag keep their meaning). The popover toggle pre-ticks the rest of the
  family on a rich input so the designer only narrows down.
  `allowedColors` (null|list) is the rich input's colour allowlist: null =
  brand swatches + free picker (legacy), `[]` = colour locked (no swatch row
  at all), list = only those swatches (no picker); enforced per run in
  `RichText::fromRaw` (strict 400 `color_not_allowed` with `allowedColors`,
  lenient strip) via `RichTextOptions::allowedColorsFor()`. Surfaces: the
  popover's "Barvy pro uživatele" mode select (libovolná / jen vybrané /
  nelze měnit, brand + custom hex chips), the fill WYSIWYG + admin sample
  modal render per input, API/MCP `inputs[].colorOptions`, DSL
  `input.fontChoice` / `input.allowedColors`.
- **Manual-driven defaults + weight axis (2026-09-03).**
  `Services/Editor/ResolveEditorFontDefaults` reads the brand manuals: the
  PRIMARY manual font's regular cut (upright, weight closest to 400, among
  the faces the manual enables) is the face a NEW text / checklist starts
  in (`data-canvas-editor-default-font-family-value`, also the text
  toolbar's `defaultFontFamily`), falling back to the first project font's
  regular cut; `manualFaces` (every face a manual enables) backs the
  "Použít řezy z manuálu" preset button in the font allowlist. The editor
  font selects (popover + "Přidat text" modal) group faces by font
  (`data-canvas-editor-font-options-value` = every project face with
  metadata) and preview each option in its face. `Font::addFontFace`
  inserts by weight (uprights Thin→Black, then italics) while the list is
  still weight-ordered, appends once the designer dragged an order of their
  own. The fonts page shows a 100–900 weight axis per family (filled =
  upright, slanted mark = italic).
- **Font usage scan (2026-09-03).** `Query/GetFontUsage::forProject()` scans
  every variant document of the project — textbox `fontFamily` (per-char
  `styles` included), `inputs[].allowedFonts`, rich `sampleValue` runs —
  into a `FontUsage` VO (family → `FontUsageSite`s; `missing` = referenced
  families no project face or bare font name satisfies, e.g. a deleted face
  or the editor's "Arial" default). Nothing is stored: fonts are referenced
  by STRING, so the scan is the only truthful source; it runs on the fonts
  page (per-face "N šablon" chips, delete confirms listing the templates,
  the "Písma mimo projekt" card linking to the editors) and the project
  dashboard (the Fonty card's "N chybí" badge). Deleting a face/font also
  removes the storage object(s) since the fonts rework.
- **Family rename with propagation (2026-09-03).** `RenameFont` →
  `RenameFontHandler` renames `Font::name` (refusing a name another font of
  the project has — `FontNameTaken`) and `Services/Font/RewriteFontReferences`
  rewrites every stored reference in the same transaction: text replacement
  over the JSONB columns `template_variant.canvas` / `.inputs` and
  `template_export_version.fill_values`, scoped to the project — the quoted
  face strings `"Old (Face)"` → `"New (Face)"` in both their direct
  (`"key": "value"`, jsonb's text form) and envelope-nested (`\"…\"`)
  spellings, the bare family name only in a `"fontFamily": …` position
  (it is ordinary text otherwise). Face NAMES never change. Page:
  `/font/{id}/rename` from the family card's menu.
- **Admin editor**: `canvas_font_choice.js` (pure planner + checklist DOM:
  fonts as tri-state group headers, faces previewed in their own face, the
  designed row locked-checked with a "výchozí" badge — the designed font is
  never persisted, so it follows the canvas font) used by the text popover
  (toggle under "Písmo"), the "Přidat text" modal (which also gained a font
  select) and to narrow the "Vzorový text" WYSIWYG menu per input. The
  editor pages' `rich_toolbar` is now `forProject()` = EVERY project face
  (`data-canvas-input-properties-font-faces-value`). `allowedFonts` ∈
  `CANVAS_CUSTOM_PROPERTIES` / `META_KEYS` / `TEXT_CUSTOM_PROPERTIES`.

**Rich text (WYSIWYG) placeholders**

A text input flagged `richText: true` (admin checkbox "Formátovatelný text",
persisted on `EditorTextInput` + as a canvas custom prop) is filled through a
simple hand-rolled WYSIWYG instead of a plain field: font-FACE switch (bold/
italic are separate face families — `"Rubik (Rubik Bold)"` — so B/I buttons
just swap `fontFamily`), brand-color swatches + free picker, underline.

- **Value model = "runs"**: `[{text, fontFamily|null, color|null, underline}]`;
  concatenation = plain text (drives `maxLength`, truncate-then-`uppercase`
  applied PER RUN — case mapping can change length). PHP side:
  `src/Value/RichText(Run).php` (strict parse for API / lenient sanitize for
  web), resolved by `ResolveTextOverrides` into
  `ResolvedInputOverrides::$richTexts` alongside the plain `$texts` concat.
  The web mirror fields smuggle runs as a `{"runs":[...]}` JSON envelope,
  detected ONLY for rich inputs inside `ResolveTextOverrides::parseValue()`;
  unstyled values stay plain strings.
- **Runs→Fabric = `assets/editor/rich_text_runs.js`** (classic script, the
  container_layout.js pattern; inlined into the render template, `<script src>`
  on the fill page). Two load-bearing contracts: style ranges are
  **grapheme-indexed** (mirrors `fabric.util.stylesFromArray` segmentation) and
  application order is styles-BEFORE-text + explicit `initDimensions()`
  (`applyToTextbox`). EVERY text override — plain or rich — clears per-char
  styles first (Fabric never remaps them on programmatic text set).
- **Options = `ResolveRichTextOptions`** (single source of truth, the
  PlaceholderAllowedDirectories pattern): fonts = canvas-used families expanded
  to ALL their faces (fallback: all project fonts when nothing matches), colors
  = manual brand colors across `GetManuals::allForProject` (primary →
  secondary → untyped, deduped lowercase `#rrggbb`; swatches are SUGGESTIONS —
  export accepts any hex). Consumed by the fill component, the API listing
  provider (`variants[].richTextOptions`, emitted only when a rich input
  exists), the `ExportProcessor` (font whitelist) and the download controller.
- **Fill page**: `rich_text_editor_controller.js` (per-popover contenteditable;
  collapsed caret = apply to whole text; IME composition guard; paste as plain
  text; runs-snapshot undo; envelope→mirror sync + `rich-text-editor:changed`);
  the overlay measures with per-char styles applied to its offscreen Textboxes
  (a bold face wraps wider — container overflow gating must match the server).
  **B/I reliability (2026-08-10):** face-axis detection is metadata-first with
  a NAME fallback (`style` OR `faceName` matching /italic|oblique|kurz/ resp.
  weight ≥ 600 OR /bold|tučn/ — FontLib subfamily metadata is best-effort and
  real uploads miss it, which made the buttons silent no-ops); a toggle with
  NO mappable face is DISABLED with the reason in its title instead of doing
  nothing; and on connect the editor warns when a run references a family no
  longer among the options (renamed/removed face) — it still renders here
  (the page registers every project face) but export leniently strips it
  (`RichText` whitelist nulls the family), the one place preview and PNG can
  legitimately disagree.
- **API**: `inputs[].richText` flag; export accepts `{runs, hide}` (strict:
  structured 400s `rich_text_not_allowed` / `invalid_rich_text` /
  `font_not_allowed` (+`allowedFonts`) / `invalid_color` via
  `InvalidRichTextValue`, the ContainerOverflow pattern). Documented in
  OpenAPI + consumer-prompt.md; mfkfm consumes it.

**Lists inside rich text (`lists` on `EditorTextInput`, 2026-08-05).** A rich
input the admin flags "Povolit seznamy" accepts per-LINE list types in the
envelope: `{runs, lines: ["p","ul","ol",...]}` — one entry per `\n`-separated
line of the concatenated runs (`RichText::$lineTypes`, all-'p' ≡ no lists ≡
the pre-lists value; list structure routes to the rich path even when runs
are unstyled). Rendering = **block stack** replacing the designed textbox:
consecutive 'p' lines merge into ONE paragraph textbox (byte-identical to the
flat rendering), consecutive 'ul'/'ol' lines become individually-wrapped item
textboxes at `indent` (hanging indent) with a bullet object each (char •/–/✓
in the item's lead color/face, `ol` ordinals, or a gallery image bullet
inlined by the renderer). Admin config on the input (`listBullet`,
`listBulletImage`, `listIndent`, `listItemSpacing`, `listBlockSpacing`; null =
derived defaults — single source `ResolvedListStyle`, JS mirror none: servers
resolve everywhere). Shared layout = `assets/editor/rich_text_blocks.js`
(classic script, third sibling of container_layout/rich_text_runs; the
`measure()` callback runs once per text element IN ELEMENT ORDER — callers
queue their Fabric boxes on it). **`geom.lineLeading` is load-bearing**:
Fabric's `calcTextHeight()` omits the LAST line's leading (a one-line
Textbox is `fontSize × _fontSizeMult` tall at ANY lineHeight; further lines
advance by `× lineHeight`), and every stack element is its own Textbox — so
without re-inserting `fontSize × _fontSizeMult × (lineHeight − 1)` BETWEEN
elements (never after the last) the whole stack renders at line height 1 and
the designed spacing silently disappears (the 2026-08-05 "line height
ignored in export" bug). Both callers derive it from the live box's
`_fontSizeMult`; the same glyph-box height (NOT `fontSize × lineHeight`)
centers bullet images + checkboxes, since Fabric puts a line's leading BELOW
its glyphs. Render template wraps the stack in a Fabric
Group carrying the textbox's inputId and stamps
`textbox.wboostReplacedBy = group` — the container engine resolves that
indirection in `displayedHeight`/`setObjectProps` (phase A snapshotted the
designed textbox; phase B measures/moves the stack), and fill-hide visibility
lands on BOTH objects. The fill overlay measures stacks via the same module
over its cached offscreen box (`textLayoutData` ships `lists` + resolved
`listStyle`). WYSIWYG: line-DIV rendering (`div.rt-line[data-type]`, CSS
bullets, JS-stamped `ol` ordinals), selection offsets count one implicit
`\n` per line boundary, Enter inherits the item type / exits on an empty
item, ul/ol toolbar toggles. API: `inputs[].lists` + resolved `listStyle`
(bullet, bulletImageUrl, indent, itemSpacing, blockSpacing); strict 400
`lists_not_allowed`. Group sync copies list props exactly (px values don't
rescale — keep them null on grouped templates so the font-derived defaults
track each dimension).

**Checkbox lists (`listCheckboxes` on `EditorTextInput`, 2026-08-05).** A
second admin toggle INSIDE the lists config ("Povolit zaškrtávací seznam",
implies `lists`) adds two line types: `'cb'` (unchecked) / `'cbx'` (checked)
— ONE block family (`RichText::CHECKBOX_LINE_TYPES`; a checklist mixes
states, `groupBlocks` groups them as `{type:'cb', items, checked[]}`).
Layout = identical to 'ul' items; the marker is drawn per state: admin-picked
gallery images (`listCheckboxImage` unchecked / `listCheckboxCheckedImage`
checked, picked via gallery mode 'checkboxImage', clearable per state) or —
when null — the DEFAULT drawn checkbox: `fabric.Rect` rounded square
(0.9×fontSize, rx 22 %) filled with the item's LEAD TEXT COLOR, checked adds
a white `fabric.Path` check centered in it (font-independent — a ✓ glyph is
NOT used on purpose; real-Chromium verified via the Gotenberg DIAG recipe).
Strictness: cb/cbx on a lists-disabled input → `lists_not_allowed`; on a
lists-enabled but checkbox-disabled input → 400 `checkbox_lists_not_allowed`
(lenient: degrade cb/cbx → 'ul', list structure survives — mirrored in the
WYSIWYG's `_fitTypes`/`_parseDom`). WYSIWYG: `mdi-format-list-checks` toolbar
toggle (`toggleCheckboxList` — non-family lines become fresh 'cb', existing
'cbx' keep state), CLICKING the line's marker gutter toggles cb↔cbx in place
(`editorClick`, no re-render), Enter after a checked item inherits 'cb'
(never 'cbx'), CSS markers mirror the drawn default via currentColor +
inline-SVG white check. API: `inputs[].listCheckboxes` + `listStyle.
checkboxImageUrl`/`checkboxCheckedImageUrl` (null = drawn default). Overlay
measurement needs nothing checkbox-specific (cb geometry ≡ ul geometry).

**Checklist COMPONENT (`checklist` + 4 capability flags on
`EditorTextInput`, 2026-08-05).** "Přidat zaškrtávací seznam" in the left
panel adds an input that IS one checkbox list — under the hood a plain
textbox with `richText`+`lists`+`listCheckboxes` forced true (same value
model / render pipeline / group sync), plus `checklist: true` and
`checklistToggle`/`checklistEditText`/`checklistAdd`/`checklistRemove`
(default true). `#addChecklistModal` takes the default items (textarea, one
per line — they become BOTH the designed stand-in text and the sampleValue
envelope with all-'cb' lines) + the four capability toggles. Text popover:
checklist inputs show a capabilities section and HIDE the rich/lists/
checkbox enable-toggles (forced on; unchecking would silently break
rendering).

**Admin item editing — canvas text and sample are kept in LOCKSTEP**
(`assets/controllers/canvas_checklist_sample.js`, 2026-08-05). A checklist's
canvas `text` is only a stand-in; what the export draws is its `sampleValue`
envelope (nothing is provided for the input, so `ResolveTextOverrides` falls
back to the sample). Two sources of truth for one item list — so an inline
canvas edit used to change only `text` and the PNG kept the ORIGINAL items,
while the editor preview (which draws from `_textLines`) happily showed the
new ones. The rule now: **the canvas text owns the items, the sample owns the
checked states**, carried over BY LINE INDEX (lines the sample doesn't reach
default to 'cb'). The reconciliation is idempotent, so it runs on every
`text:changed`, on canvas load (heals rows saved before this — deliberately
WITHOUT marking the form dirty; it rides along with the next save) and right
after the item editor writes. The admin item editor is the popover's
`Vzorový text` button relabeled **"Položky seznamu"** for checklist inputs:
the same `#sampleTextModal` hosting the fill page's
`checklist_editor_controller` with all four capabilities forced ON (the flags
gate the USER, not the designer authoring defaults) and no clear button — an
empty sample would export the stand-in as plain paragraphs, checkboxes gone.
Saving writes BOTH faces and fires a synthetic `text:changed` so container
reflow and group sync treat it like typing.

Fill page: `checklist_editor_controller.js` replaces the
WYSIWYG — one row per item (checkbox disabled unless toggle, text readonly
unless editText, × only with remove, add button/Enter-inserts only with
add), value synced as the ordinary checkbox-list envelope; removing all
items writes explicit `''` (suppresses the sample). Overlay reflow rides
the shared `checklist-editor:changed → richTextChanged` wiring.
Enforcement: capabilities are a UI contract EXCEPT all-four-off =
read-only server-side (`ResolveTextOverrides` ignores provided overrides,
sample renders). API: `inputs[].checklist` = null | `{toggle, editText,
addItems, removeItems}` (presence ⇔ component).

**Vzorový text (`sampleValue` on `EditorTextInput`, 2026-08-05).** Per-input
admin-authored DEFAULT FILL, stored in the exact wire format a fill value
uses (plain string or the `{"runs","lines"}` envelope — full rich feature
set incl. lists). Render: `ResolveTextOverrides` falls back to it when the
input key is ABSENT from providedValues (an explicit `""` suppresses it), and
a sample is ALWAYS parsed leniently — a stale stored sample must never 400 an
API consumer who merely omitted the input (per-input `$lenient` flag).
**Sample ↔ canvas lockstep, generalized to every text input (2026-08-10;
`canvas_checklist_sample.js` despite the name).** The sample is what exports,
so a stand-in showing different text is an editor preview that lies (prod
report). Modal save → `applySampleToCanvasText` points the canvas text at the
sample's plain text (uppercased for display; rich styling stays invisible on
the single-styled stand-in — the fill preview/export is where it shows) and
fires `text:changed` so reflow + group propagation ride along. Inline canvas
edit of a SAMPLED input → `syncTextSample` rewrites the sample: plain stays
plain, an envelope keeps its LEAD run's whole-text style (a multi-run partial
mapping has nothing to attach to after a rewrite), list line types carry by
index 'p'-filled; blanking the text clears the sample. It no-ops when texts
already agree (uppercase compared in DISPLAY form), which is what preserves
multi-run styling right after a modal save. Deliberately NO canvas-load sweep
— legacy samples routinely differ from the designed text and a sweep would
bulk-rewrite admin-authored samples. Fill
page: `postMount` seeds `textValues` from it, so field + preview + untouched
export agree. Admin UX: "Vzorový text" button in the text popover opens
`#sampleTextModal` — rich inputs get a FRESH fill-page WYSIWYG per open
(canvas_input_properties clones the `<template>` skeleton in
`_editor_stage.html.twig`, stamps the textbox's values, Stimulus connects on
insert; the editor writes its wire value into the `[data-sample-mirror]`
hidden input via the usual `data-text-mirror` lookup), plain inputs get a
textarea. Both editor controllers pass `rich_toolbar`
(`RichTextOptions::toToolbarArray()`, also used by the fill page) for the
modal's toolbar markup. API: `inputs[].sampleValue` (raw wire string).
