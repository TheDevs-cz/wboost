# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Development Commands

**Local Development:**
```bash
docker compose up  # Runs application at http://localhost:8080
```

**User Management:**
```bash
docker compose exec web web bin/console app:user:register <email> <password>  # Create user
```

**Code Quality:**
```bash
docker compose exec web composer phpstan          # Run PHPStan static analysis (level max)
docker compose exec web vendor/bin/phpunit       # Run PHPUnit tests
```

**Asset Management:**
```bash
docker compose exec web bin/console importmap:install      # Install frontend assets
docker compose exec web bin/console asset-map:compile     # Compile assets for production
```

## Architecture Overview

This is a **Symfony 7** application for brand manual management, using:

- **CQRS Pattern**: Commands/Queries with dedicated handlers in `Message/` and `MessageHandler/`
- **Domain-Driven Design**: Entities represent core business concepts (Manual, Project, User, etc.)
- **Event-Driven Architecture**: Domain events via `EntityWithEvents` trait
- **Dockerized Environment**: Full stack with PostgreSQL, Redis, Minio S3, and MailCatcher

### Template editor ("Šablony" — the unified templates module)

The largest feature in the codebase. A `Template` is a Fabric.js canvas that an
admin authors once and end-users / API consumers fill with their own copy.
Entities: `Template` / `TemplateVariant` / `TemplateCategory` (+ `TemplateGroup`;
tables `template`, `template_variant`, `template_category`, `template_group`) —
the 2026-08 merge of the former social-network and custom-template modules (see
the merge-history section below). This section is the post-migration shape
(Stages 1–7) — older patterns (text canvas column, positional input binding,
monolithic Stimulus controller, Fabric v5) have been retired.

**Data model — `template_variant`**

- `canvas`: **JSONB** (Stage 1). The serialized Fabric document. Empty rows
  are stored as `'{}'` (never `''`) and the renderer synthesizes a minimal
  Fabric document with just a background image when it sees an empty canvas
  (canvas-mode rows only — see Backgrounds below).
- `preview_image_path`: nullable string (Stage 1). Path to a PNG in Minio
  (rendered server-side after each admin save). Replaces the legacy
  `preview_image` BLOB column. The full URL is built via the upload helper.
- `inputs`: JSONB array of `EditorTextInput` value objects, persisted via
  `EditorTextInputsDoctrineType`. Each entry carries its `inputId` (UUID v4).
- **inputId UUID binding (Stage 2)**: every textbox / image on the canvas
  carries a custom property `inputId` minted at admin-time, and every
  `EditorTextInput` row mirrors that same id. Overrides (text content, hidden
  flag) are looked up by id, not by index — so two inputs may legitimately
  share a `name`, and reordering objects on the canvas no longer rebinds
  inputs. The `EditorTextInput::fromArray` factory keeps a defensive UUID-mint
  fallback for legacy rows; once the migration has run on prod, no live row
  hits it.

**Backgrounds — canvas mode vs LAYER mode (`background_mode` column)**

Two styles, discriminated by the `background_mode` column (`BackgroundMode`
enum on `template_variant`). **Canvas mode** ('canvas', every pre-rework
row): the background is Fabric's canvas-level `backgroundImage`, mirrored from
the NOT-NULL-in-practice `background_image` column, center-cover re-applied on
every load (editor connect + render template), set via the editor's "Pozadí"
gallery pick + `persistBackgroundPath` side-channel POST. Untouched forever.
**Layer mode** ('layer', every NEWLY created variant): the background is a
regular image object in `objects[]` marked `isBackground: true`, initially
placed **cover-fit anchored top-left** (`scale = max(cw/iw, ch/ih)`, overflow
crops bottom-right) at stack index 0 — reorderable, undoable, snapping-excluded,
styled distinctly in the layers panel ("Pozadí" row), and **click-through on
the canvas surface** (`applyEditorLock`: a full-canvas evented object would
swallow every mousedown — no rubber-band multi-select, and when editor-locked
it painted the not-allowed cursor everywhere; select it via the layers panel
instead — the same click-through rule applies to `editorLocked` images).
Key facts:

- **Optional**: add-variant/group forms no longer require a background; a
  layer-mode variant without one renders a TRANSPARENT PNG (renderer calls
  Gotenberg `omitBackground()`; editor/fill show a checkerboard). The
  `background_image` column is now NULLABLE and in layer mode is a
  denormalized pointer to the layer's `assetPath` (synced in
  `EditTemplateVariantCanvasHandler` — the single canvas-save chokepoint, both
  the single-variant editor and the group editor dispatch
  `EditTemplateVariantCanvasEditor`; feeds thumbnails + nullable API
  `backgroundImageUrl`).
- **Server-side authoring** via `Services/Editor/BackgroundLayer` (build /
  replace-in-place / extractAssetPath): Add handlers seed the layer, the
  canvas-save handler swaps it in place (empty input NEVER removes; a new
  upload replaces the picture but preserves stack index + slot metadata), Copy
  inherits the source's mode, `CanvasDesignProjector` re-covers (never
  rx-scales) the layer per dimension and stamps NO canvas-level block for
  layer-mode sources. Editor-side the "Pozadí" pick calls `setBackgroundLayer`
  (ordinary dirty canvas edit, NO side-channel POST).
- **Group editor**: backgrounds are strictly per-dimension. `isBackground`
  objects are excluded from the whole group_sync engine (baseline/diff,
  projectNewObject, removeObject, resync, z-order — `isSyncable()`), the
  `object:added/removed` handlers gate on the flag, and layer-mode variants
  get `backgroundUrl: null` in variantsData so every canvas-level re-apply
  site no-ops. Cover fit is absolute per (image, canvas size) — propagating it
  relatively would compound drift, and group-seeded siblings SHARE the
  background's inputId, so a resync would clobber per-dimension covers.
- **Fillable (Phase B)**: the designer can mark the layer `imagePlaceholder` →
  it flows into `imageInputs` with `isBackground: true` (new `EditorImageInput`
  field; `allowMove/Resize/Rotate` forced false — the fill is a deterministic
  cover, `ImagePlacement::computeCover` + JS mirrors). Unfilled → designed
  background renders (stand-in contract); background slots' `frame` = the full
  canvas rect (the designed object's bbox overflows it). API docs +
  consumer-prompt.md describe the contract; mfkfm is ported.
- `'isBackground'` is in `CANVAS_CUSTOM_PROPERTIES` (and stripped on
  clipboard paste — only ever ONE background layer per canvas).

**Admin editor — Stimulus controllers (Stage 4)**

The legacy monolithic canvas controller (`social_network_canvas_controller`,
pre-merge naming) was split along responsibility boundaries. All siblings reach
the orchestrator via Stimulus 3 **outlets**
(`static outlets = ["canvas-editor"]`) to read its `this.canvas` Fabric
instance, and listen to a `canvas-editor:selection:changed` window event the
orchestrator dispatches on Fabric's selection lifecycle:

| Controller | Responsibility |
|---|---|
| `canvas_editor_controller` | Orchestrator. Owns the Fabric `Canvas` (retina scaling OFF above ~4M logical px — a print-size canvas would repaint dpr²× the pixels on every frame, the "editor is laggy" report), loads/saves canvas JSON, marks the form dirty, broadcasts selection changes. Also owns **backdrop targeting**: an UNLOCKED image covering ≥90 % of the canvas (`isBackdropCovering`/`applyBackdropState` in `canvas_custom_properties.js`) is click-through while unselected — dragging over it rubber-bands and a marquee never pulls it in (an evented full-canvas image would join EVERY marquee) — but unlike `editorLocked` no lock flags are set: a plain CLICK (mouse:up, no movement, nothing hit) selects it Canva-style and it becomes fully movable until deselected (Esc discards the selection — the only way off a selected backdrop, since its pixels leave no empty spot to click). Swept on every mutation/selection event; layers-panel selection gets the same active exemption. Pointer modifiers (`applyPointerModifiers`, on `mouse:down:before`): **Alt/⌥+drag** always rubber-bands even over objects (skipTargetFind for the press; a press on a transform handle keeps Alt = centered scaling), **Ctrl/⌘+drag** always grabs the object under the cursor — promotes a passthrough backdrop for the press (Photoshop Move-tool auto-select; composes with the ⌘ snap bypass). Cheatsheet hint under the stage in `_editor_stage.html.twig`. |
| `canvas_history_controller` | Undo/redo stack — full-canvas-JSON snapshots, restored via the orchestrator's loader. |
| `canvas_clipboard_controller` | Copy / paste / duplicate (keyboard + buttons). |
| `canvas_zoom_controller` | CSS-transform-only visual zoom of the wrapper element. Dispatches `canvas-zoom:changed` so the floating toolbar re-anchors. |
| `canvas_text_toolbar_controller` | Font / size / colour / alignment / decoration / max-length controls for the active textbox (populate + mutate only — visibility is owned by the floating toolbar). |
| `canvas_input_properties_controller` | Editor-side input metadata (name, description, locked, hidable, uppercase). Persists onto the canvas object as custom properties via `CANVAS_CUSTOM_PROPERTIES`. Exposes `toggleLocked` for the mini-toolbar. |
| `canvas_image_properties_controller` | Image-placeholder metadata (placeholder flag, name/description, allowMove/Resize/Rotate, hidable, allowedDirectoryIds). Exposes `togglePlaceholder` for the mini-toolbar. |
| `canvas_alignment_controller` | Multi-object align ops, z-order, delete. `updateButtonStates` is `has*Target`-guarded since its buttons live in the floating chrome. |
| `canvas_layers_controller` | Photoshop-style layers panel ("Vrstvy"): topmost-first rows, hover outline, click-select, SortableJS restack, and a per-layer **visibility eye**. The eye is PERSISTED (`visible: false` rides the canvas JSON): hidden layers vanish from renders/exports/thumbnails, are **excluded from fillable inputs** (`buildVariantPayload` + `TextInputObjectBinder` skip invisible objects — the positional textbox↔input contract counts only VISIBLE textboxes), drop out of container membership at layout time (`collectMembers` skips them; fill-time `hide` still collapses), are filtered from API `containers[].memberInputIds`, and propagate through group sync (`visible` ∈ META_KEYS). |
| `canvas_floating_toolbar_controller` | The element-anchored floating UX (see below). |
| `canvas_container_controller` | Containers ("smart text areas") — see the dedicated section below. |

**Containers — smart text areas (document-like vertical reflow)**

A container groups members into a vertical flow: at render time a filled text
that wraps to more lines pushes the flow items below it down, hidden items
collapse (take no space), and the flow of a TOP-LEVEL container is bounded by
a designer-set `maxHeight` (from the first item's designed top downward). Each
member stays an ordinary independent input. Since the 2026-08 nesting rework:

- **Members** are texts, DECORATIVE images and CHILD CONTAINERS. A decorative
  image (never a fillable placeholder / the background layer) that vertically
  overlaps a text/child item becomes its ATTACHMENT — fixed offset, rides
  along, force-hidden when the item collapses (checklist icon ↔ its line);
  a non-overlapping image flows standalone (separator). Texts/children are
  each their own flow item — designed gaps between items are preserved
  (negative ones included: overlapping texts keep their designed overlap).
- **Nesting**: `memberContainerIds` on the parent (one parent per child, no
  cycles — enforced at save + defensively in the engine). A child is laid out
  first (bottom-up) and flows in the parent as ONE item; a nested container's
  own maxHeight is NOT a bound (it grows with content) — only the ROOT's
  maxHeight gates overflow, and the overflow 400 always reports the root id.
- **`gap`** (nullable px, per container): non-null replaces every designed
  inter-item gap of THAT container with a uniform spacing — member vertical
  positions then only determine flow ORDER, and the editor NORMALIZES the
  design to those positions after every change (Notion-like: drag to
  reorder). Null (default + all pre-rework rows) = designed gaps.
- **Sibling collision-push** (2026-08-05, the Pokojný malíř follow-up):
  TOP-LEVEL containers never overlap — walked in designed-top order, a root
  whose content would run into a lower, HORIZONTALLY-overlapping root pushes
  it (whole tree, chained) down by the excess. Whitespace absorbs growth
  first, no pull-up; side-by-side columns (disjoint x-ranges) never interact.
  This is what makes two independent section containers interact WITHOUT
  explicit nesting.
- **`spaceAfter`** (nullable px, per container): guaranteed clearance BELOW —
  the landing distance when pushing (enforced as a minimum even at designed
  positions), the floor of the following gap when nested, and the page-bottom
  margin: with the canvas height known (`opts.canvasHeight` — passed by the
  render template, the editor and the fill overlay), root content ending
  below `canvasHeight − spaceAfter` counts as overflowPx → strict export
  400s on the container that fell off the page.

- **Data model**: persisted as a top-level `containers` key INSIDE the canvas
  JSONB — `[{id, maxHeight, memberInputIds, memberContainerIds, gap}]`,
  memberInputIds in flow order (the editor re-derives the order from member
  tops on every save; sanitization also validates child refs / cycles /
  one-parent and drops degenerate containers to a fixpoint —
  `canvas_payload.js sanitizedContainers`). No DB column/migration; PHP parses
  it via the defensive `CanvasContainer` VO (`src/Value/CanvasContainer.php`)
  — inert definitions (missing members, non-positive height, <2 members
  counting children) are dropped, never crash a render.
- **Shared algorithm**: `assets/editor/container_layout.js`
  (`window.WBoostContainerLayout`) is the single source of truth, deliberately
  a dependency-free CLASSIC script: inlined verbatim into the headless render
  template by the renderer, loaded via `<script src>` by the editor page and
  the fill component. Designed geometry is snapshotted BEFORE overrides
  (phase A, `prepareFabricContainers` — builds the container FOREST), reflow
  runs AFTER them (phase B, `applyFabricLayout`) on the re-wrapped heights;
  the two-phase API accepts live Fabric objects OR plain geometry POJOs (the
  fill overlay feeds POJOs). Root results carry `textFlow` (deep text member
  tops in flow order, null = hidden) and only roots can report overflowPx.
  The Textbox break-word patch lives next to it
  (`assets/editor/fabric_break_word.js`) and is applied on ALL THREE surfaces
  — measurement parity is what reflow correctness rests on.
- **Overflow contract**: API export renders STRICT
  (`renderer->render(..., strictContainerOverflow: true)`): the render template
  throws an uncaught `Error("CONTAINER_OVERFLOW:{json}")`, Gotenberg
  (failOnConsoleExceptions) fails with the exception text in its 409 body
  (reachable only via the wrapped HttpClient exception's response — the bundle
  swallows it otherwise), `TemplateVariantImageRenderer` parses it into
  `ContainerOverflow`, and the API `ExportProcessor` answers **400**
  `{error, code: "container_overflow", containerId, overflowPx}` (a documented
  public contract — OpenAPI + consumer-prompt.md). Web fill preview/download
  render LENIENT (overflow shown, fill page blocks the Export button instead).
- **Editor UX** (`canvas_container_controller.js`): "Vytvořit kontejner" on the
  multi-select bar accepts 2+ texts/decorative images — and selected objects
  that ALREADY belong to a container bring their whole ROOT container in as a
  nested child (that is how nesting is authored: build sections, select them,
  group). Dashed DOM zones in the unscaled stage layer (never on the canvas
  bitmap): top-level zones have a bottom handle for maxHeight + overflow
  warning; NESTED zones (`--nested`, dotted) hug their content with no height
  handle. Both have LEFT/RIGHT side handles that resize the container width
  (textbox lefts + wrap widths rescale proportionally, non-text members only
  follow with their left edge), a ⧉ duplicate button (clones the whole tree —
  objects incl. design-hidden members + definitions with fresh ids, spacing
  copied, nested originals join the same parent as a sibling; the +20/+20
  offset copy settles below the original via the normalize pass), a ⚙ button
  opening the per-zone settings popover (gap, spaceAfter, "Vnořit do
  kontejneru" parent select — the discoverable nesting control; maxHeight on
  top-level; "Zrušit kontejner"), and an × button that drops the
  definition — members of a nested container are PROMOTED to the parent so
  the flow survives (top-level × frees the members; both undoable). Members
  are dragged INDIVIDUALLY (plain Fabric drag); the whole container (deep)
  moves by dragging the zone's label — snapped through the shared snapping
  machine's DOM-gesture API (`canvas.wboostSnapping`:
  beginGesture/snapGestureRect/endGesture on `canvas_snapping_controller`,
  same guides/hysteresis/⌘-bypass as Fabric drags; ROOT container zone boxes
  — member union, bottom extended to maxHeight — are snap TARGETS for every
  drag too). A plain CLICK on the label (≤3px slop) SELECTS the container's
  visible members as an ActiveSelection; Shift+click ADDS them to the current
  selection — label-click A + shift-label-click B is how two containers are
  selected for nesting via "Vytvořit kontejner". Typing reflows live through
  the whole tree. After every design change the controller re-derives flow order and
  NORMALIZES positions through the shared engine (identity unless a gap is
  set / a child shifted); normalization is deferred while an ActiveSelection
  is live (its members carry relative coords) and runs when it clears.
  Containers live on the Fabric instance as `canvas.wboostContainers`;
  `submitForm` writes the sanitized list into the canvas JSON, history
  snapshots carry a deep copy, `loadCanvasWithoutHistory` restores them and
  dispatches `canvas-editor:canvas:loaded`. NOTE: controller state MUST be
  initialized in Stimulus `initialize()` — outlet callbacks can fire before
  `connect()`.
- **Fill page**: `textLayoutData()` on the filler ships per-input designed
  frames + text metrics + containers + `decorations` (designed frames of
  decorative image members); the overlay measures wrapped heights with
  offscreen Fabric Textboxes (same committed bundle, project fonts explicitly
  loaded) and reruns the shared two-phase layout over geometry POJOs so
  boxes/pencils track the server render pixel-exactly (nesting, gaps and
  icon attachments included); overflow shows an inline alert and disables
  Export. Coalesced via setTimeout, NOT requestAnimationFrame (rAF never
  fires in hidden tabs).
- **API listing**: `variants[].containers[]` `{id, maxHeight, y,
  memberInputIds, memberContainerIds, gap, nested}` + per-input `containerId`
  and `textStyle {fontFamily, fontSize, lineHeight, charSpacing}` so
  consumers can mirror the reflow (`y` = highest designed member in the
  container's tree; decorative members are deliberately NOT exposed — the
  server preview is authoritative for them); `frame` stays the DESIGNED
  position. The mfkfm backoffice consumes this (zones + structured-400
  highlighting, no client reflow — its preview IS the server render).

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
envelope with all-'cb' lines; per-item checked defaults are authored later
via the Vzorový text WYSIWYG) + the four capability toggles. Text popover:
checklist inputs show a capabilities section and HIDE the rich/lists/
checkbox enable-toggles (forced on; unchecking would silently break
rendering). Fill page: `checklist_editor_controller.js` replaces the
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
API consumer who merely omitted the input (per-input `$lenient` flag). Fill
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

**Floating element toolbar.** Selection-contextual editing is NOT in the left
panel — it floats next to the selected object (Canva/Slides style).
`canvas_floating_toolbar_controller` owns *when* and *where* the chrome shows;
the property controllers above only populate/mutate their (relocated) fields.

- The chrome lives in an **unscaled** overlay: `.canvas-stage` (position:relative)
  wraps the CSS-`scale()`-zoomed `.canvas-wrapper` and is the `layer` target.
  The stage itself sits inside the `.canvas-viewport` SCROLL CONTAINER
  (`overflow: auto`), which flexes to fill the **app shell**: on lg+ the editor
  root (`.editor-shell`) is sized by `canvas_zoom_controller` to end at the
  window bottom and the page is PINNED (`body.editor-shell-page {overflow:
  hidden}`, class toggled by the same controller, released below
  `MIN_SHELL_HEIGHT` so a short window can still scroll). Do NOT try to derive
  the height from `document.scrollHeight` — the shell's own overflow, and in
  dev a whole exception page the debug toolbar appends when its request errors,
  both read as "chrome below the shell" and collapse it to its floor. Panning a
  zoomed-in canvas scrolls the viewport, never the page, so the left panel and
  the toolbar stay put (that is also why the editors drop the `page_title_row`
  block and the pointer-modifier cheatsheet lives in the "Nápověda" modal
  instead of under the canvas — with a pinned shell every pixel above the stage
  is canvas height the designer loses) — the
  navigator pans by scrolling it, and floating chrome must clamp within the
  viewport box (`_chromeBounds()`), because anything positioned past its edges
  is clipped by the overflow. The `.canvas-wrapper` carries `vertical-align:
  top` — baseline-aligned, the stage's line box would grow to the wrapper's
  internal baseline (Fabric's in-flow lower-canvas puts it at the UNSCALED
  canvas bottom) and the zoom controller's negative-margin compensation only
  trims below-baseline space. A pinned page is also **programmatically**
  scrollable (`overflow: hidden` stops the user, not `focus()`), and the user
  cannot scroll back — so the zoom controller snaps any stray document scroll
  back to 0 while pinned. The known offender was Fabric's hidden textarea:
  `canvas_hidden_textarea.js` patches `IText` so it is `position: fixed` in
  VIEWPORT coordinates (Fabric computes them in unscaled canvas px on the body
  — `top: 3035px` for a caret 480 px down at 26 % zoom — then focuses it,
  which scrolled the shell into a blank band until reload) and focuses with
  `preventScroll`. Anything else that positions a focusable element off the
  shell must do the same.
  Screen position is derived from the live canvas rect:
  `scale = fabricContainerRect.width / canvasEl.width`, then
  `objX = (contRect.left - layerRect.left) + obj.getBoundingRect().left * scale`.
- A single-selection **mini-toolbar** (pencil + duplicate / context-toggle / z-order
  / delete) and a **multi-select bar** (6-way align + z-order + delete, for
  `activeSelection`). Quick actions are wired by `data-action` to the existing
  clipboard/alignment/property controllers. The **pencil** opens a floating
  **popover** holding the full text / image property form (the relocated
  `#font-controls` / `#image-controls` markup — **`id="font-family-control"` must
  stay**, the orchestrator's `populateFontSelect` does `getElementById`).
- It re-anchors on Fabric `object:moving/scaling/rotating/modified`,
  `canvas-editor:selection:changed`, `canvas-zoom:changed` and window resize;
  hides during inline text editing (`text:editing:entered/exited`); flips the
  popover above/below to stay in the viewport and below the sticky header
  (`[data-editor-header]`).
- The top-bar toggle "Zobrazit editovatelné prvky" (`toggleHighlight`) draws
  one **DOM** `.editable-outline` per selectable object (NOT on the canvas
  bitmap, so it never leaks into the saved preview thumbnail or the server PNG).

**Overlay chrome toggles** (`templates/editor/_editor_toggles.html.twig`, the
top bar shared by the single-variant and the group editor). Element IDs are the
contract — each controller reads its switch's initial state with
`querySelector('#…')` on connect, so a renamed id silently starts the chrome
OFF with no error anywhere. None of them persist: every reload starts all-on.

| switch | controller | what it hides |
|---|---|---|
| `#highlight-editable-control` | canvas-floating-toolbar `toggleHighlight` | the dashed `.editable-outline` frames |
| `#caption-visible-control` | canvas-floating-toolbar `toggleCaptions` | the `.editable-outline__name` field tags |
| `#container-zones-control` | canvas-container `toggleZones` | the dashed `.container-zone` chrome |
| `#snap-enabled-control` | canvas-snapping | drag snapping |
| `#ruler-enabled-control` | canvas-rulers | rulers + guides |

Frames and captions are the two INDEPENDENT halves of one overlay: all four
combinations are reachable, so ONE `.editable-outline` element per object is
built whenever EITHER is on (`_overlayOn()`), the frame is a CSS modifier away
(`--frameless`) and the tag is simply not appended. The expensive part — the
`after:render` positioning loop — stays unaware of the toggles.
Hiding container zones is chrome-only: the definitions still reflow the design,
still list in the "Kontejnery" panel and still save. It is CSS-driven
(`.container-zones-off`) because `_positionZones()` rewrites
`zone.style.display` every render frame and would fight inline styles — and
while zones are hidden `_openSettings()` is a no-op, since the popover anchors
to a zone rect that now measures 0×0 (the panel row still selects the
container).

**User-fill flow — Live Component (Stage 5)**

`Template:VariantFiller` (`src/Twig/Components/Template/VariantFiller.php`, the
only subclass of `AbstractVariantFiller` — see the merge-history section)
replaces the old client-side Fabric runtime on the user-fill page. The preview
image is rendered by the same Gotenberg path the API uses; the server resolves
overrides via `ResolveTextOverrides`; download is a regular controller action.

**Click-into-preview overlay (`variant_fill_overlay_controller.js`).** Editing
happens ON the preview: every text + image placeholder shows an always-visible
icon cluster — **pencil** (text → floating popover with the replace input; image
→ a gallery **modal** with thumbnails + folder select + upload) and an **eye**
(hide, only when `hidable`). Two independent toggles gate the chrome, mirroring
the admin editor's pair and both on by default: "Zobrazit oblasti k vyplnění"
(`#fill-highlight-toggle` → `fill-highlight-on`) controls the dashed border AND
the icon clusters, "Popisky prvků" (`#fill-captions-toggle` → `fill-captions-on`)
the `.fill-box__name` field tags. Both classes are also stamped on the form in
the Twig markup — the CSS gates on them, so dropping them there loads the page
with the chrome invisible. An OVERFLOWING box keeps its red border and its tag
regardless of either toggle: that is a validation signal, not a hint. Box
positions come from the designer frame scaled to the displayed preview
(`scale = previewWidth / variant.dimension.width()`). The overlay, popovers and
modals all live in `data-live-ignore` subtrees so a Live re-render never wipes
open state or the picker's chosen folder — but the **text popovers sit in their
own `.fill-popovers` wrapper OUTSIDE the zoom-scaled `.fill-stage` / scrolling
`.fill-viewport`** (still inside the controller root) and are `position: fixed`,
positioned in viewport coords with an unconditional final clamp fully on-screen
(below the box → flip above → clamp), so no ancestor overflow/transform can ever
clip them at any zoom. The ONLY Live-bound fields
are **visually-hidden mirror inputs** (`data-text-mirror` / `data-hide-mirror`,
with the form `name`): the popover text input writes into the mirror and
dispatches `input` (`syncText`); the eye flips the mirror / a `data-image-hide`
checkbox and dispatches `change` (`toggleHide`) so the debounced backdrop
re-render fires and the form POST carries the values. **Enter in a fill field is
blocked from submitting** (`blockEnter`) — only the Export button downloads; the
preview updates live via the debounce. Progressive enhancement: without
`.fill-js` (added on connect) the popovers are a plain stacked editable list. The
text-only branch drives its ignored preview `<img>` from a Live-updated
`previewSource` span via MutationObserver (the image branch keeps the existing
`variant-image-fill` Fabric canvas underneath).

Per-placeholder geometry comes from `AbstractVariantFiller::textPlaceholders()`
(mirrors `imagePlaceholders()`), which derives a per-input `frame {x,y,width,height}`
(canvas px, axis-aligned) via `TextInputObjectBinder`. The binder owns the
**positional textbox↔input contract** (i-th Textbox = inputs[i]) — the single
source of truth also used by the renderer's `alignTextboxInputIds`, so a box a
consumer draws and the text the export substitutes can never disagree (textbox
`inputId` props are unreliable post-v7-migration; image placeholders still key by
their own reliable `inputId`).

**Project image gallery — Live Component (Stage 7 → 8)**

`Project:ImageGallery` (`src/Twig/Components/Project/ImageGallery.php`) is the
per-project, per-`FileSource` asset library shown in the admin editor's "Add
image" / "Set background" modal. Image **selection** stays a DOM
`CustomEvent("asset-selected")` (with `{ url, path, id }`) so the host Stimulus
controller routes the chosen URL to `addImageToCanvas` or `setBackgroundImage`
without a server round-trip.

Stage 8 added a **filesystem-like nested folder tree** on top:

- `FileDirectory` entity (`src/Entity/FileDirectory.php`) — nullable self-ref
  `parent` (null = root), scoped by `project` + `source`. `FileUpload` gained a
  nullable `directory` FK (`ON DELETE SET NULL`).
- Navigation/CRUD are **LiveActions** on the component (`openDirectory`,
  `openRoot`, `createDirectory`, `startRename`/`renameDirectory`,
  `deleteDirectory`, `deleteFile`, `moveFile`), each dispatching a CQRS message under
  `Message/Image/` (`CreateFileDirectory`, `RenameFileDirectory`,
  `DeleteFileDirectory`, `DeleteFileUpload`, `MoveFileUpload`). `$currentDirectoryId`
  is a (server-set) LiveProp. **Deleting a folder only removes an EMPTY folder** —
  a folder that still holds images or sub-folders is refused (the handler throws
  `FileDirectoryNotEmpty`; the component pre-checks and shows the transient
  non-LiveProp `$folderActionError` notice), so contents are never silently
  relocated or discarded; the user empties it first. **Deleting an image moves
  it to the Koš (trash bin)** — `deleteFile` → `DeleteFileUpload` sets
  `deleted_at`, DETACHES the file from its folder (remembered in
  `restore_directory_id`, SET NULL) and needs no confirm; the bin is a
  read-only special directory at the gallery root (both hosts) showing a
  per-file purge countdown with only Obnovit (`RestoreFileUpload` — back to
  the original folder, root when it's gone) and Smazat ihned
  (`PurgeFileUpload`, confirmed). Trashed images are invisible/unusable
  everywhere: every live listing in `FileUploadRepository` filters
  `deletedAt IS NULL` (load-bearing at the ROOT — detached bin entries would
  otherwise surface as root files), `ResolveImageOverrides` rejects trashed
  ids explicitly (the unrestricted-slot root branch would accept them), and
  `MoveFileUploadHandler` refuses to move a trashed file (a move would
  silently un-trash it). `PurgeFileUploadHandler` is the only place in the
  app that hard-deletes gallery storage (row + object; an image in use as a
  template background/placeholder default loses its source there). The purge
  cron is `app:gallery:purge-trash` (daily; retention =
  `FileUpload::TRASH_RETENTION_DAYS` = 7). **`#[LiveArg]`
  names must be lowercase** (e.g. `#[LiveArg('directoryid')]` / `#[LiveArg('fileid')]`)
  to match the HTML-lowercased `data-live-*-param`.
- Uploads still POST to `project_upload_file`; the modal's upload form carries a
  hidden `directoryId` (= `$currentDirectoryId`) so new files land in the open
  folder. **The upload form's field prefix is `upload_project_file_form[...]`**
  (the form's block prefix from `UploadProjectFileFormType`), and it must include
  a `_token` (`csrf_token('submit')`) — the form is submitted via
  `new FormData(form)` by the `gallery-uploader` controller.
- Authorisation: **no class-level `#[IsGranted]`** (its subject can't resolve from
  a LiveProp during a LiveAction — that 500s). Access is enforced in
  `#[PostMount]` + a `guard()` helper called by every render method and action;
  client-supplied folder/file ids are re-checked via `ownedDirectory()`.

The component root merges its controller via
`{{ attributes.defaults({'data-controller': 'image-gallery'}) }}` — writing a
second literal `data-controller` next to `{{ attributes }}` silently loses it
(duplicate attribute; browser keeps `live`).

The same component is also rendered **standalone** on a management page
(`ProjectGalleryController` → `/project/{projectId}/gallery`, linked from the
left navigation as "Galerie" and from the templates module pages). A
`bool $modal` LiveProp (default `true`) toggles the modal header/close chrome
and the click-to-select image buttons; pass `:modal="false"` to render plain
thumbnails where folders + upload + move are the management surface.

The gallery is **project-wide** (one library shared by the variant editor, the
group editor and every fill surface): the `FileSource` enum case is
`ProjectImage = 'project_image'` (renamed from the original
`social_network_image`; the rename migration updated `file_upload.source` +
`file_directory.source` in place).

**Render path — Gotenberg + identical Fabric runtime**

Image export (admin preview, user download, API export, group fill/export) all
flow through `Services/Editor/TemplateVariantImageRenderer` (its signatures
take a `TemplateVariant`; the group fill surfaces wrap it via
`Services/TemplateGroup/GroupFillRenderer`). It builds the canvas JSON
(inlining the background image as a base64 data URI so headless Chromium
needs no Minio access), renders `templates/api/template_variant_render.html.twig`
through Gotenberg, and waits for `window.canvasRendered === true`. The Twig
template runs the **same Fabric v7 build** the editor uses, so admin and
export **layout** match — note they are no longer byte-identical, because
on-screen previews use a different output format (below). Post-Stage 6 the
Fabric UMD bundle is committed at
`assets/fabric/fabric-7.3.1.min.js` and inlined as a `<script>` tag — the
renderer no longer fetches Fabric from jsDelivr at render time.

**Output format — previews are WebP, exports are PNG (`Value/RenderImageFormat`)**

`render()` / `renderToBytes()` take a trailing `RenderImageFormat` that
**defaults to PNG, and that default must stay.** `renderToBytes()` is not an
"internal preview" method — it also feeds the group ZIP export and the Meta
publish path, where the bytes go to a third party under an `image/png` label.

Only screen paths opt in to WebP:

| path | format | why |
|---|---|---|
| fill-page preview / backdrop (`AbstractVariantFiller`) | **WebP** | hot path: 2–3 renders per keystroke, base64'd into the Live response. ~25–36 % faster and ~90 % smaller (A4: 1.00 s / 327 KB vs 1.56 s / 4467 KB) |
| overlay slices (background-less `CanvasSlice`) | **PNG** | flat transparent layers, where PNG is *faster* to encode (0.147 s vs 0.220 s) for ~7 KB more |
| group fill-preview endpoint | **WebP** | its JS does `blob()` + `createObjectURL`, so it is format-agnostic |
| API export, web download, group ZIP, Meta publish | **PNG** | contract + lossless. `docs/api/consumer-prompt.md` promises "raw PNG binary" |

Two constraints worth knowing before touching this:
- **WebP has no quality knob here.** Gotenberg ignores the `quality` field for
  WebP (measured: q50/q85/unset are byte-identical); the bundle's docblock says
  jpeg-only. WebP is Chromium's default lossy encode, and previews being lossy
  is a deliberate, accepted trade-off — exports are what must stay lossless.
- **JPEG is unrepresentable in the enum on purpose:** the `omitBackground()`
  paths need alpha.

The tests lock both halves: export/download/ZIP/publish assert
`format === 'png'`, and `FakeTemplateVariantImageRenderer` emits *format-matching*
bytes so a PNG magic-byte assertion cannot pass while WebP was requested.

**Slice cache — only renders that provably cannot change (`cache.gotenberg_preview`)**

The fill page renders 2–3 times per keystroke, and the transparent overlay slices
are usually byte-identical every time. `renderToBytes()` therefore caches a render
**only when it can prove the result is independent of everything the user can
type**; otherwise it renders fresh exactly as before. The proof is one rule, in
`sliceIsOverrideIndependent()`:

> If no object inside the slice carries an `inputId`, no text / rich-text / hide
> override can change a single pixel of it.

That holds because (a) `buildCanvasJson()` never receives the text overrides —
text is applied in the browser from the separate `text_overrides` context key;
(b) suppression outside a slice is `opacity: 0`, not `visible: false`, precisely
so hidden objects keep their layout influence; and (c) the only remaining way a
text edit can *move* something is container reflow, and `CanvasContainer`
addresses its members **by `inputId`** — so an object without one cannot be a
member. A full render (`$slice === null`), a slice containing a bound input, or a
canvas that will not decode all return null → render every time.

Practical effect: decorative overlays (a logo locked above a photo slot) are
rendered once per canvas version instead of once per keystroke; the backdrop,
which holds the fillable text, still re-renders — correctly.

Key = hash of everything else that can change the pixels (canvas hash, background,
dimension, background mode, input definitions, image overrides, slice, strict flag,
format, a font fingerprint, and an asset fingerprint for the inlined JS bundles),
so a stale hit is not reachable even before tag invalidation. The
`template_variant_render_<id>` tag is dropped on canvas save as housekeeping.
Entries over 1 MiB are returned but never stored.

The pool is separate from `cache.app` for namespace/TTL/tag isolation — note it
still shares the Redis *server*, because `maxmemory` and `allkeys-lru` are
server-wide in Redis, so a separate logical DB would not stop preview blobs
evicting application keys. Capacity is what does; Redis on the box was sized up
accordingly (infra repo, `apps/wboost/compose.yaml`).

**Render capacity is a hard dependency of user-facing pages, so failures are
typed.** Gotenberg is a synchronous dependency: nothing is queued, and one fill
page draws 2–3 renders per edit (backdrop + one per overlay slice). The
`gotenberg.client` scoped client therefore caps the call
(`timeout: 20` / `max_duration: 25` in `config/packages/sensiolabs_gotenberg.yaml`)
and the renderer classifies the failure — `isRendererOverloaded()`: a
client-side timeout (TransportException as `previous`) or Gotenberg's own
429/503/504 → `TemplateRenderUnavailable` (`#[WithHttpStatus]` 503), anything
else stays a render error. Without that cap an overloaded renderer consumed
PHP's whole 30 s `max_execution_time` and died as a FATAL mid-request, holding
the session row lock for those 30 s so every other request of the same user
queued behind it and timed out in turn — one slow render took whole sessions
down for an hour (Sentry WEB-2B, 2026-08-05; the trigger was the Gotenberg
container OOM-killing Chromium inside its 2 GiB cgroup, fixed in the infra
repo). The export controllers answer with `export_failed.html.twig` + 503, and
the fill component degrades to "no preview this round"
(`AbstractVariantFiller::renderUnavailable()`) rather than 503-ing the Live
re-render, which would strand the page on its spinner.

### One "Šablony" module — merge history + dimension model

Until 2026-08 the app had three sibling sections: "Sociální sítě" (fixed-format
social templates), "Šablony" (free-form custom templates) and "Skupiny šablon"
(cross-module sync groups). They are now ONE "Šablony" module: entities
`Template` / `TemplateVariant` / `TemplateCategory` / `TemplateGroup`, CQRS
under `Message/Template/` + `Message/TemplateGroup/`, web controllers under
`Controller/Template/` + `Controller/TemplateGroup/`. Every
`SocialNetworkTemplate*` entity/controller/message/API class is DELETED, and
the frozen `social_network_*` tables were dropped by `Version20260804210000`
(the data was merged into the unified tables first; a pre-merge backup lives
on lily).

**Migration chain** (`Version20260804080000` / `081500` / `090000`):

1. Renamed the `custom_template*` tables → `template*` (hand-named group
   indexes/constraints follow) and folded stored `'custom_template'`
   discriminator values into `'template'` (`export_event.template_type`,
   `storage_object.category`). Historical `'social_network'` rows keep their
   value.
2. Added nullable `template_variant.dimension_preset`.
3. THE data merge: copied the whole social-network stack into the unified
   tables — same UUIDs, JSONB canvas columns copied verbatim; categories
   merged **by exact name per project**; each group's social+custom member
   template pair consolidated into ONE template (the custom-side row wins and
   absorbs the social side's variants), so a group maps 1:1 to one template
   with mixed-dimension variants. Down migration restores the renames but the
   copy/consolidation is NOT reversed.

**Dimensions — `WBoost\Web\Value\TemplateDimension`** (Doctrine embeddable,
column prefix `dimension_`): a `DimensionUnit` (px / mm / cm; physical units
rasterize at **300 DPI** = `DimensionUnit::PRINT_DPI`) + `unitWidth` /
`unitHeight` + a NULLABLE `preset` — the `DimensionPreset` enum `'1:1'` /
`'4:5'` / `'9:16'`, i.e. the fixed 1080×1080 / 1080×1350 / 1080×1920 Instagram
formats (`TemplateDimension::fromPreset()`). `width()`/`height()` return canvas
pixels everywhere; `label()` returns the ratio for preset variants and
"210 × 297 mm"-style for free-form ones. NOTE: the stored properties are
`unitWidth`/`unitHeight` on purpose — a public `width` property would shadow
the `width()` px method in Twig attribute lookup. The add-variant form offers
A5/A4/A3 one-click mm presets AND Instagram preset buttons; the latter stamp a
hidden `preset` field which ANY manual unit/size edit clears
(`template_dimension_controller.js`; the group add-dimension form mirrors this
via `template_group_dimension_controller.js` + `TemplateGroupDimensionFormData`).
A "social format" is nothing more than a preset dimension now.

**Publish to FB/IG is preset-gated.** `TemplateVariantPublishController`
(`POST /template-variant/{variantId}/publish`, route `template_variant_publish`)
answers 400 when `dimension->preset === null`; the fill page renders its
publish chrome via `AbstractVariantFiller::canPublish()` (the same check).

**Template groups — "synchronized templates".** A `TemplateGroup` maps 1:1 to
ONE `Template` whose variants span multiple dimensions; membership is a
nullable `group` FK on `template` AND `template_variant` (`ON DELETE SET NULL`
— deleting the group row only un-groups), and only GROUP-CREATED variants
carry the FK: manually added variants on a grouped template keep
`group = null`. The standalone group listing is GONE — grouped templates
appear on the unified listing (`templates.html.twig` / `_template.html.twig`)
as **"Synchronizováno"** cards: the card body/menu leads to the group editor
(`template_group_editor`) for designers and to the group fill page
(`template_group_fill`) for everyone else, and delete opens a **two-mode
modal** (`deleteTemplates=0` "Zrušit pouze synchronizaci" un-groups only,
`=1` "Smazat včetně šablony" → `DeleteTemplateGroup`). Individual variant
editing is BLOCKED for group-created variants —
`TemplateVariantEditorController` redirects to the group editor when
`variant->group !== null` (a single-variant edit would be clobbered by the
next group save). Authorisation: `TemplateGroupVoter` — **`VIEW`** (fill,
fill-preview, export, placeholder upload) delegates to `ProjectVoter::VIEW`
(admin, owner, any shared user); **`EDIT`** (group editor, add-dimension)
stays a designer/owner/admin tool.

**Web routes** live under `/project/{projectId}/templates` (name `templates`),
`/template/{templateId}/…`, `/template-variant/{variantId}/…` and
`/template-group/{groupId}/…`. The old social/custom URL families are GONE
with **no redirects** — a deliberate decision.

**Namespaces that deliberately did NOT move**: the shared fill-engine services
still live under `Services/SocialNetwork/*` (`ResolveTextOverrides`,
`ResolveImageOverrides`, `ImagePlacement`, `PlaceholderImageUploader`,
`PlaceholderAllowedDirectories`, `TextInputObjectBinder`, `AssetInliner`,
`ResolveRichTextOptions`, `CanvasPlaceholderGeometry`, `FillFormRequestParser`)
— only their variant unions were narrowed to `TemplateVariant`. Group-only
services live under `Services/TemplateGroup/` (`CanvasDesignProjector`,
`GroupFillRenderer`, `GroupFillPlaceholders`). Storage key prefixes are
likewise unmoved — see the storage section.

**Single-module surfaces** (formerly "shared" between two modules):
`templates/template_variant_editor.html.twig` is rendered by
`TemplateVariantEditorController` (labels/routes still arrive as template vars
— `menu_item`, `module_label`, `dimension_label`); the group editor renders its
own `template_group_editor.html.twig` but saves through the same
`EditTemplateVariantCanvasEditor` message per variant. The user-fill engine is
`src/Twig/Components/AbstractVariantFiller.php` + the single template
`templates/components/VariantFiller.html.twig`; **`Template:VariantFiller`**
is its only subclass, binding the entity-typed `$variant` LiveProp,
`TemplateVariantVoter::VIEW`, and the `template_variant_download` /
`template_variant_placeholder_upload` / `template_variant_publish` routes.

**Template API** (`src/Api/Templates/`): canonical
`GET /api/projects/{projectId}/templates` (variants expose the `dimension`
label — the ratio for presets — plus nullable `preset`, `unit`, `unitWidth`,
`unitHeight` and px `width`/`height`),
`POST /api/template-variants/{id}/export`,
`GET|POST /api/template-variants/{variantId}/placeholders/{inputId}/images`,
`GET /api/template-variants/{variantId}/thumbnail`. The old
`…custom-template…` AND `…social-network-template…` path families keep working
as **deprecated aliases** over the very same providers/processors/controllers
(`deprecationReason` on the API Platform operations; `*_legacy_custom` /
`*_legacy_social` route names on the thumbnail + upload controllers). mfkfm is
already ported to the canonical paths.

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

### Upload size limit — 10 MB, decimal

Every image upload is capped at **10 MB**, expressed server-side as
`maxSize: '10m'` on the `Image`/`File` constraint in the form types (14
occurrences across 9 `src/FormType/*` classes — manual images/logos, mockup
pages, template + variant backgrounds, template groups, email signatures,
the project gallery) plus the `Image` attribute on
`FormData/ProjectFormData::$icon` (the custom project icon).

**Symfony reads the `m` suffix as DECIMAL megabytes — 10 000 000 bytes, not
10 MiB.** Every mirror of the limit must use that same number or a file in the
gap is accepted by one layer and rejected by the next:
`gallery_uploader_controller.js` (`maxSize` Stimulus value),
`mockup_page_editor_controller.js` (fed by
`_mockup_page_form_editor.html.twig`), and
`PlaceholderImageUploader::MAX_FILE_SIZE_BYTES`.

The placeholder upload endpoints (web + API + group fill, 3 controllers —
`TemplateVariantPlaceholderUploadController` (API),
`TemplateVariantPlaceholderUploadWebController`,
`TemplateGroupPlaceholderUploadController`) take the raw `UploadedFile` off
the request with no form behind them, so the cap lives in
`PlaceholderImageUploader::upload()` — the single chokepoint they all share —
and surfaces as a `400`. Documented in `docs/api/consumer-prompt.md`.

PHP/Caddy allow 50 MB (`upload_max_filesize`/`post_max_size` come from the
`ghcr.io/thedevs-cz/php` base image, not this repo), so the app-level limit is
the only thing in play.

### Gallery uploads are format-normalized (`NormalizeImageFormat`)

A gallery picture has to survive THREE readers: `getimagesizefromstring()` (the
natural-size read behind every placeholder fill), a browser `<img>` (thumbnails,
the fill page's live Fabric preview) and Gotenberg's headless Chromium (the
export). PNG / JPEG / GIF / WebP pass all three; **HEIC/HEIF — the default
iPhone camera format — passes none of them** (PHP sizes a real iPhone capture to
`false`, Chrome paints nothing), which shipped as a broken thumbnail plus a 400
"could not be read or is not a supported raster image" at export time.

So `UploadFileHandler` — the single chokepoint behind BOTH the gallery form and
the placeholder upload endpoints — runs every upload through
`Services/Image/NormalizeImageFormat`: web-safe rasters pass byte-for-byte,
anything else Imagick can decode is transcoded (PNG when it has alpha, else
JPEG q90, EXIF orientation baked in and the ICC profile preserved), and the
stored extension always describes the BYTES, never the client's file name. SVG
is deliberately refused by the normalizer and stored untouched — it stays vector
everywhere (logos, backgrounds). `AssetInliner::inlineImageWithDimensions` runs
the same normalizer at render time, so files uploaded before this (and any other
format Chromium can't paint) still export.

### Storage inventory — admin usage/orphan report

`/admin/usage` carries a **Úložiště** section (bytes per project, rolled up per
client) plus a file drill-down at `/admin/storage/files`. Both are
`#[IsGranted(User::ROLE_ADMIN)]`.

File types are **columns on each client/project row**, not a separate global
by-type table — the per-project split is what says *why* a client is big, which
one merged "all clients by type" table cannot. The column set is derived from
the data (only categories actually present get a column, ordered by total size
desc), the same way the usage table above derives its month columns; cells link
through to the file list pre-filtered by project + category. Objects whose
project is gone render as one "Bez projektu" row carrying the identical
breakdown (`StorageOverview::$unattributed`), never folded into a client.

It is backed by `storage_object` — one row per object that actually exists in
the Minio bucket, **derived data rebuilt by a scan, never written at upload
time**. That is the whole point: an inventory built from what the app believes
it wrote could not surface the objects it forgot about, and those dominate
(deleting a project/manual/template has never removed its files, and every
`Edit*` handler that re-uploads writes a new timestamped key and abandons the
old one — on the dev mirror that is ~70 % of all bytes).

**Storage keys survived the templates merge UNMOVED.** Former-social files stay
under `social-networks/…` forever (merged canvases still embed those URLs);
the unified template handlers write `custom-templates/…`. Consequences:
`BuildStorageReferenceIndex` keeps BOTH prefixes in its `PATH_PREFIXES` regex,
`CollectProjectStoragePaths` emits both key families per template/variant, and
`StorageCategory` maps `custom-templates/` → `Template` (`'template'`, the
folded discriminator — same fold as `export_event.template_type` /
`ExportedTemplateType`) while the `SocialNetwork` (`'social_network'`) case
survives purely for the untouched `social-networks/…` keys and historical rows.

- `ScanStorage` (`src/Services/Storage/`) lists the bucket and cross-references
  `BuildStorageReferenceIndex`, which enumerates **every** column that stores a
  path: plain strings, the JSON documents (`manual.logo`,
  `manual_mockup_page.images`, `font.faces`), the canvas JSONB **and the e-mail
  signature `code`** — the last two EMBED paths inside full public URLs rather
  than storing the key, so they are matched by storage-prefix regex (the base
  URL is environment-specific; the prefix is the stable anchor). **A writer
  missing from that list makes a live file look like an orphan** — add new ones
  there. The signature `code` case was found the hard way, by diffing a prod
  scan against a full `pg_dump`; if you ever act on the orphan list in bulk, do
  that diff first rather than trusting the flag.
- Unreferenced objects are still attributed to a project by
  `ResolveStorageOwnerByPath`, which pulls the first UUID out of the key and
  looks it up across every namespacing entity. Objects whose entity is gone stay
  unattributed and are reported as their own bucket, never folded into a client.
- Transient by-products (`social-publish/`, `thumbnails/`) are inventoried but
  never flagged as orphans — they are unreferenced by design.
- Writes are UPSERTs on `path` followed by `DELETE WHERE scan_id <> :scanId`.
  The delete keys on a **per-run `scan_id`, not `scanned_at`**: that column is
  `TIMESTAMP(0)`, so two scans in the same second could not tell each other
  apart and stale rows would survive.
- Refresh: `app:storage:scan` (idempotent; the one-time backfill and the
  recurring job) or the "Načíst znovu" button. The scan **only reads** from
  storage — there is no delete action, because a copied template variant shares
  its source's background key (`reference_count > 1`, shown as a `×N` badge), so
  "delete what looks unused" is not safe to automate.
- The scan also reports **dangling references** — DB rows pointing at keys that
  are not in the bucket (broken images), the mirror image of an orphan.

**Deleting a project deletes its storage.** `DeleteProjectHandler` collects the
project's key namespaces via `CollectProjectStoragePaths` **before** removing
the row, flushes so the DELETE + cascades actually execute, and only then calls
`DeleteProjectStorage`. Order is load-bearing: most namespaces are keyed by a
CHILD entity id (`manuals/{manualId}`, `custom-templates/{variantId}` +
`social-networks/{variantId}`, …), not by the project id, so the cascade
destroys the only record of what belonged where — and an image can never be
referenced from another project, so there is no later chance to reattach it.
Two details that must not be "simplified": per-variant previews are deleted as
individual FILES because `custom-templates/preview/` and
`social-networks/preview/` are shared by every project, and an email-signature
template clears BOTH `emails/{id}/` and `manuals/{id}/` (the ADD and EDIT
handlers disagree on the prefix). Storage failures are logged and swallowed —
the row is already gone, so throwing would only turn a leaked file into a failed
request.

### Core Domain Entities

- **Manual**: Brand manuals with colors, fonts, logos, and mockup pages
- **Project**: Container for brand manuals with sharing capabilities
- **Template**: Fabric-canvas templates (the unified module — social preset
  and free-form print dimensions alike) with variants, categories and
  synchronization groups (`TemplateVariant`, `TemplateCategory`,
  `TemplateGroup`)
- **EmailSignatureTemplate**: Email signature templates with variants
- **User**: User management with authentication and profiles

### Key Architectural Patterns

- **Message Bus**: All write operations go through Symfony Messenger with dedicated handlers
- **Repository Pattern**: Custom repositories for complex queries (e.g., `ManualRepository`)
- **Value Objects**: Rich domain types in `Value/` directory (Color, Logo, etc.)
- **Security Voters**: Authorization logic in `Services/Security/` 
- **Form Data Objects**: Separate DTOs for form handling in `FormData/`

### External Services

- **S3/Minio**: File storage for uploads and generated assets
- **ImageMagick**: Image processing via PHP Imagick extension
- **Doctrine ORM**: Database layer with migrations and custom types
- **Twig Components**: Live components for interactive UI elements

### Testing Strategy

- PHPUnit with database transactions (`DAMA\DoctrineTestBundle`)
- Controller tests for HTTP endpoints
- Separate test database configuration
- Test fixtures in `tests/DataFixtures/`

### Development Services

- **Adminer**: http://localhost:8000 (postgres/postgres/wboost)
- **MailCatcher**: http://localhost:8025
- **Minio**: http://localhost:19001 (wboost/wboostminio)

Always run any commands in corresponding Docker container - like PHPStan: `docker compose exec web composer run phpstan`

## API (`src/Api/`)

The application exposes a REST API at `/api` powered by API Platform 4. The API is intended for service-to-service communication and is protected by OAuth2 with the `client_credentials` grant.

### Strict DTO rule

**Doctrine entities (`src/Entity/*`) are NEVER exposed as API resources** — neither as request bodies nor as responses. Entities are domain objects; transport shape is decoupled.

Each API feature lives in its own folder under `src/Api/<Feature>/`:

```
src/Api/
└── Projects/
    ├── ProjectResponse.php       ← read DTO carrying #[ApiResource]
    └── ProjectsProvider.php      ← API Platform State Provider (ProviderInterface)
```

A read DTO is a `final readonly class` with public scalar / value-object properties. It carries `#[ApiResource]` plus operation attributes (e.g. `#[GetCollection]`).

A State Provider implements `ApiPlatform\State\ProviderInterface` and is the **only** place that touches the database for that resource — usually via DBAL or a Doctrine repository. It returns one or more DTO instances. It MAY read the authenticated user from `Symfony\Bundle\SecurityBundle\Security` to scope results.

For write operations (none today): use a Request DTO + State Processor (`ApiPlatform\State\ProcessorInterface`) that dispatches a CQRS Message — never mutate entities directly from the processor.

### Adding a new API resource

1. Create `src/Api/<Feature>/<Feature>Response.php` — DTO with `#[ApiResource]`.
2. Create `src/Api/<Feature>/<Feature>Provider.php` — implements `ProviderInterface`.
3. Reference the provider in the operation: `provider: <Feature>Provider::class`.
4. Apply security: `security: "is_granted('IS_AUTHENTICATED_FULLY')"` for service-to-service.
5. Verify the route: `docker compose exec web bin/console debug:router | grep '/api'`.
6. Run `docker compose exec web composer phpstan` and `vendor/bin/phpunit`.

Service-loader convention: only `*Provider.php` and `*Processor.php` files under `src/Api/` are autowired. DTOs are not services.

### OAuth2 (client_credentials)

> **Full guide:** [`docs/api/authentication.md`](docs/api/authentication.md) — the
> end-to-end flow (create credentials → obtain token → call the API), the JWT
> claim layout, why there are no refresh tokens, an error reference, and the
> configuration table. The summary below is the quick version.

The API is protected by JWT (RS256) issued via the `client_credentials` grant. Service consumers POST to `/api/token`:

```bash
curl -sX POST https://example.com/api/token \
    -d 'grant_type=client_credentials' \
    -d 'client_id=...' \
    -d 'client_secret=...'
```

The returned bearer token's `sub` claim contains the linked **App User's UUID**. The `api` firewall reads that claim, loads the matching `User` via `api_user_provider`, and the State Provider scopes data to that user.

The link between an OAuth2 client and an App User is a row in `oauth2_client_user` (`client_identifier` → `user_id`), populated by `app:oauth-client:create`.

RSA keys are stored **directly in env vars** as base64-encoded PEM (decoded by Symfony's `%env(base64:...)%` processor); no key files on disk. See `.env` and `.env.local` for the four `OAUTH2_*` variables.

### Managing OAuth2 clients

```bash
# Create a client linked to a user (prints plaintext secret ONCE)
docker compose exec web bin/console app:oauth-client:create user@example.com --name=service-name

# List all clients with their linked users
docker compose exec web bin/console app:oauth-client:list

# Deactivate a client and revoke its outstanding tokens
docker compose exec web bin/console app:oauth-client:revoke <client-id>
```

### API testing

API tests extend `WBoost\Web\Tests\Api\ApiTestCase` (default `Accept: application/json` header; JSON-LD/Hydra is disabled, so collections come back as flat JSON arrays). To obtain a real access token in a test, use `WBoost\Web\Tests\TestingApiAuthentication::getAccessToken($client, $clientId, $clientSecret)` — it goes through the live `/api/token` endpoint, which is the contract being exercised. Fixture credentials live as constants on `tests/DataFixtures/TestDataFixture.php` (`OAUTH2_CLIENT_ID`, `OAUTH2_CLIENT_SECRET`).

### Template variant export endpoint

`POST /api/template-variants/{id}/export` returns a PNG (deprecated aliases:
`POST /api/custom-template-variants/{id}/export` and
`POST /api/social-network-template-variants/{id}/export` — same processor,
same contract). The request body is `ExportRequest` and its `inputs` map is
**keyed by inputId UUID** (Stage 2): `{ "inputs": { "<uuid>": "Hello", "<uuid>": { "value": "World", "hide": false } } }`.
Discover the available input ids via
`GET /api/projects/{projectId}/templates` — each
`variants[].inputs[].id` is the same UUID accepted here. Unknown ids
are silently ignored; locked inputs cannot be overridden; `hide` only
applies to inputs with `hidable: true`.

Each `variants[].inputs[]` also carries a nullable **`frame {x,y,width,height}`**
(canvas px, top-left origin, axis-aligned — same shape/space as
`imageInputs[].frame` and the variant's `width`/`height`), so a consumer can draw
a highlight box over the rendered preview and edit in place. `null` when the
textbox can't be located → fall back to the flat field list. Mapping to screen px
is a single scale factor (`renderedPreviewWidth / variant.width`) because the
export PNG is the variant's exact dimensions. The mfkfm backoffice consumer
(`~/www/mfkfm/backoffice`) implements this overlay; see
[`docs/api/consumer-prompt.md`](docs/api/consumer-prompt.md). v1 limitation:
rotated placeholders are reported as their axis-aligned bounding box.

### Image placeholders

The image counterpart of text inputs (mirrors the same architecture). A designer
marks a canvas image as a fillable **placeholder** (custom prop
`imagePlaceholder`), sets per-slot limits (`allowMove`/`allowResize`/`allowRotate`,
`hidable`) and toggles which gallery folders feed it (`allowedDirectoryIds`); these
persist as an `EditorImageInput[]` JSONB column `image_inputs` on
`template_variant` (via `EditorImageInputsDoctrineType`), alongside
the textbox `inputs`.

**`allowedDirectoryIds` semantics — empty = UNRESTRICTED (the WHOLE gallery:
every project folder PLUS the gallery root), never "none".** An explicit
allow-list can only name folders, so picking any folder implicitly excludes the
root. The single source of truth is `PlaceholderAllowedDirectories`
(`resolve()` / `resolveIds()` / `includesRoot()` / pure static `effectiveIds()`),
used by **all four** interpretation sites — `PlaceholderImageUploader` (upload
target), `ResolveImageOverrides` (render-time validation), `VariantFiller` (web
pick list + `canUpload` flag), `PlaceholderGalleryProvider` (API pick list) — so
they can never disagree. The admin editor warns the designer when they leave a
placeholder's folders empty ("uživateli bude otevřená celá galerie"). The only
remaining dead end is a restricted slot whose every picked folder was deleted.

**Upload target is the UPLOADER'S choice (web + API), never an arbitrary
fallback.** `PlaceholderImageUploader::resolveTargetDirectory` resolves an
omitted `directoryId` only when unambiguous: a restricted slot with exactly one
folder → that folder; an unrestricted slot → the gallery root (null directory);
a restricted slot with several folders → 400 (the caller must choose). The web
fill page renders a per-slot folder `<select>` (inside `data-live-ignore` so the
choice survives Live re-renders) whenever more than one target exists, and the
API exposes resolved `directories` ({id, name}) + `includesRoot` on each
`imageInputs[]` entry so consumers can render the same choice. The upload
response's `directoryId` is null for root uploads.

- **Render core**: `ResolveImageOverrides` validates the fill (the chosen `imageId`
  must be a `FileUpload` in one of the slot's allowed folders; move/resize/rotate
  are 400'd when the slot forbids them), inlines the picture as base64 and reads its
  natural size; `ImagePlacement` (a pure, unit-tested helper) computes the
  object-contain fit + `scale`/`offset`/`rotation` into an absolute Fabric transform
  plus an `absolutePositioned` `clipPath` rect = the designer's frame. The renderer
  bakes this into the canvas JSON in `buildCanvasJson` — and now inlines **all**
  canvas image srcs (decorative + stand-ins), not just the background, so headless
  Chromium never reaches Minio. The headless Twig template needs no image-specific JS.
- **API**: `POST /api/template-variants/{id}/export` accepts an `images` map keyed
  by `imageInputId` — either `"<fileId>"` (centered object-contain) or
  `{ imageId, scale, offsetX, offsetY, rotation }` / `{ hide: true }`. The
  templates listing exposes `variants[].imageInputs[]` (`id`, limits,
  `allowedDirectoryIds`, `frame`, `defaultImageUrl`).
  `GET /api/template-variants/{variantId}/placeholders/{inputId}/images` lists a
  slot's pickable images; `POST` (multipart) to the same path uploads one into an
  allowed folder (both with the deprecated custom/social path aliases). Both
  upload paths (OAuth API + web session) share `PlaceholderImageUploader`.
- **Web fill (hybrid, z-order preserving)**: `Template:VariantFiller` renders
  the design BELOW the lowest image placeholder as the server **backdrop**
  (placeholders hidden, background included) and floats the chosen pictures as
  live Fabric objects (`variant_image_fill_controller.js`) the user moves/resizes/
  rotates within the limits, clipped to the frame. Design content the admin stacked
  ABOVE a placeholder (locked image, title over a photo) renders as transparent
  **overlay slices** — one per placeholder gap that actually holds content
  (`AbstractVariantFiller::overlaySlices()` → `CanvasSlice` → the renderer's
  `sliceCanvas`) — that the controller paints directly over that placeholder's live
  object; `_restack()` keeps placeholders + overlays in designed stack order
  (`layerIndex` in the placeholder payload). Slicing suppresses out-of-range objects
  via `opacity: 0`, NEVER `visible: false` (invisible objects fall out of the
  positional textbox binding and container membership → per-slice reflow drift), and
  stubs sliced-out image srcs (headless Chromium can't reach Minio). Placements
  mirror into hidden `images[<uuid>][...]` form fields so the plain download POST
  drives the same server render. The canvas + placement fields live in a
  `data-live-ignore` subtree; the backdrop + overlay sources are re-read from
  Live-updated elements via `MutationObserver`. The export is **always** the full
  (un-sliced) server render, so the PNG is authoritative regardless of the live
  preview.
