# Templates — data model + backgrounds (canvas vs layer mode)

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

**User-movable elements — `userMove` (2026-11-09, axis-move plan M0/M1)**

"Uživatel může posunout": the designer lets the END USER move an element on
the fill page / via the API. M0/M1 authored the allowance; M2 (below) is the
server pipeline that bakes the user's moves; the fill-page move tool (M3),
group / document UI (M4) and the API / MCP `offsets` input (M5) follow.
Contract:

```json
"userMove": {"vertical": true, "horizontal": false,
             "maxUp": 120, "maxDown": 0, "maxLeft": null, "maxRight": null}
```

- On a Fabric object (text — locked included —, shape, DECORATIVE image) as a
  custom prop, or on a TOP-LEVEL `containers[]` definition (moves the whole
  deep tree). Emitted only when at least one axis is allowed — fixed elements
  keep their exact JSON. Never on the background; on a fillable placeholder,
  a container member or a nested container it is inert (kept, so dissolving /
  un-nesting restores it; the editor and the MCP linter say so).
- The two axes are toggled separately; each DIRECTION has its own optional
  limit (Jan 2026-11-09: separate up/down/left/right, not a symmetric ±), in
  canvas px of the variant that stores it, rounded to 1/100. `null` = no
  limit, but the element's bounding box stays on the artwork (an element the
  design already hangs over an edge may not go further out); `0` = not that
  way. A disabled axis always carries null maxima.
- Single source of maths: PHP `Value/ElementMoveAllowance` (`fromArray`
  lenient normalisation, `bounds`, `clamp`, `scaled`) + `Value/ElementOffsets::bake`
  (translate `left`/`top` + an `absolutePositioned` clipPath; container keys
  move every deep member; keys add) ≡ JS `assets/editor/element_move.js`.
  Rounding is spelled out with `floor(abs·100+0.5)` on both sides (PHP's
  `round()` and `Math.round` differ on negatives); no `-0`. Parity test:
  `tests/Value/ElementMoveParityTest.php`.
- No column, no migration. `CanvasContainer` parses it (`$userMove`) and,
  since M2, `toArray()` emits it (only when set — every other container
  payload keeps its exact shape).
- Groups: stored ALREADY SCALED per variant (up/down × height ratio,
  left/right × width ratio) by `group_sync.js` and `CanvasDesignProjector`.
- MCP DSL: `userMove` with the same keys on text / image (refused on a
  placeholder) / shape / container; a block allowing no axis, a limit on a
  disabled axis or a negative limit is refused; lint warnings
  `user_move_on_container_member`, `user_move_on_nested_container`.

**User-moved elements — the server pipeline (2026-11-09, axis-move plan M2)**

The user's moves are FILL STATE: element id → `{x, y}` in canvas px of the
rendered variant, relative to the designed position. No UI yet (M3/M4), no
API / MCP input yet (M5) — but every web surface already carries, renders and
records them, and with no offsets everything is byte/pixel-identical.

- **Eligibility + clamp — `Services/SocialNetwork/ResolveElementOffsets`**
  (the single source; `Value/MovableElement` per element). Movable = carries
  `userMove` AND is a free text (locked too) / free shape / free decorative
  image, or a TOP-LEVEL container (whole deep tree). Refused (reason codes
  of the strict mode): the background layer and image placeholders
  (`not_allowed`), container members and nested containers — repeatable rows
  and their slots included — (`in_container`), design-hidden objects or a
  container with no visible member (`hidden`), no allowance (`not_allowed`).
  **Frame** = Fabric's `getBoundingRect()` of the stored object (stroke
  included — added before scaling, after it for `strokeUniform` —, rotated
  about the origin anchor); a container's = the union of its design-visible
  deep members' boxes. Measured on the canvas the render uses, i.e. the
  EXPANDED variant: a root holding a repeatable row is framed with all `max`
  rows, so "unlimited" keeps it on the artwork for any row count (an
  explicit maximum allows more). The artwork is the TRIM box (`dimension`
  w × h) — print passes are trim-relative, so an unlimited move never enters
  the bleed / crop-mark band.
  - **lenient** (web, previews, seeders, renderer belt): clamp per direction
    (`ElementMoveAllowance::clamp`), disallowed axis → 0, unknown /
    ineligible / non-numeric dropped; **strict** (M5 API/MCP): 400
    `unknown_element` / `move_not_allowed` (`reason`, `axis`) /
    `offset_out_of_range` (`axis`, `min`, `max`, `value`; 0.005 px
    tolerance) via `InvalidExportValue`. Zero offsets on anything are fine.
  - Output canonical: 1/100 px, zero moves omitted, keys sorted.
- **Bake.** `ResolvedInputOverrides::$offsets` (`withOffsets()`) →
  `TemplateVariantImageRenderer::buildCanvasJson()` re-clamps against the
  canvas it draws (`ResolveElementOffsets::bake`, idempotent belt) and
  translates (`ElementOffsets::bake`) AFTER the row expansion and the textbox
  id stamp, BEFORE slicing / print layers / placeholder placement (a slot in a
  moved root keeps its picture in the moved frame) and before the template's
  container layout — a moved root collision-pushes the roots below it and can
  overflow (`container_overflow` stays the strict signal). `PrintPdfRenderer`
  bakes the black-ink scan canvas the same way.
- **Fill state.** Single page: writable Live prop `offsetValues` — ONE entry
  per movable element (`{x: 0, y: 0}` when unmoved, so Live knows every
  `offsetValues[<id>][x|y]` path; empty for every other design), normalised in
  `PostHydrate` through the lenient resolver; mirrors `data-offset-mirror` +
  `data-offset-axis` in `VariantFiller.html.twig`; the download / publish /
  draft POSTs resolve the same fields (`FillFormRequestParser::parseOffsets`).
  Group + document pages: per SIZE (Jan) — plain hidden fields
  `offsets[<variantId>][<elementId>][x|y]` (`input->group-fill#changed`), one
  pair per movable element of each member / page; `GroupFillRenderer` picks
  ITS variant's map (each member clamps against its own, group-scaled
  allowance). Documents key elements by FILL KEY (`pageInputIds()` lists the
  movable ids, `valuesForPage()` translates `offsets` like `imagePlacements`).
- **State hash.** `O:<id>=<x>,<y>` lines after `R:` (order T H F S R O), one
  per MOVED element only — unmoved / immovable states hash as before. Numbers:
  1/100-rounded, 2 decimals, trailing zeros + bare "." dropped
  (`AbstractVariantFiller::hashNumber` ≡ `hashNumber` in fill_state_hash.js;
  the JS reads the mirrors like the server: numeric → round2, else 0).
  `FillStateHashOffsetsTest` runs the real module in node over the rendered
  mirrors.
- **Cache.** Offsets join the slice-cache key only when present (offset-less
  keys unchanged); the per-object proof already keeps a moved VISIBLE element
  out of the cache (every movable element is bound) — see templates-render.md.
- **Versions.** `ExportFillValues::$offsets` = variantId → elementId →
  `{x, y}` (uniform for single / group / document), emitted only when
  non-empty. Single fills record the RESOLVED map under their own variant
  id; group / document forms record the posted per-size maps normalised
  (rounded, zero-free — the `imagePlacements` convention, so a ZIP and a
  per-dimension export of one form still dedupe). Seeders re-clamp to the
  CURRENT allowance (`offsetValues` single, `offsets` group / document in
  fill-key space). The versions API view does not expose them yet (M5).
- **Echo / overlay (M3, 2026-11-09):** both bake the offsets client-side
  with the render's own maths (`WBoostElementMove.deltas` in
  element_move.js, now loaded by the single fill page) BEFORE the container
  phase A — the overlay translates its text / decoration POJOs, the echo
  re-prepares phase A over the pristine design at the new place. The client
  clamps with the SERVER's bounds (`FillMovableElements` → `data-movable`),
  never its own. UI contract: templates-fill.md "Move tool".
- Tests: `ResolveElementOffsetsTest`, `ExportFillValuesTest`,
  `TemplateVariantImageRendererCacheTest`, `FillStateHashOffsetsTest`,
  `ElementOffsetsFillTest` (single / group / document, versions, re-clamped
  seed), `ElementOffsetsEntryPointsTest` (static inventory of every
  render / resolve call site + print + rows-before-bake),
  `ElementOffsetsRenderTest` (Gotenberg: moved text + moved root ≡ the design
  drawn there, the push; an allowance alone moves nothing).

**Backgrounds — canvas mode vs LAYER mode (`background_mode` column)**

Two styles, discriminated by the `background_mode` column (`BackgroundMode`
enum on `template_variant`). **Canvas mode** ('canvas', every pre-rework
row): the background is Fabric's canvas-level `backgroundImage`, mirrored from
the NOT-NULL-in-practice `background_image` column, center-cover re-applied on
every load (editor connect + render template), set via the editor's "Pozadí"
gallery pick + `persistBackgroundPath` side-channel POST. **No prod row is
canvas-mode any more (2026-11-02):** a one-shot command (commit c1e85383,
removed again right after the run) converted all 177 legacy variants to
layer mode — the canvas-level block became a locked `isBackground` layer at
index 0, CENTRE-covered from the picture's natural size (exactly what the
editor and the render template re-applied on every load — the saved block
geometry was never what rendered), group siblings sharing one background
inputId; sampled exports were pixel-identical before/after. The canvas-mode
code paths below are kept (fixtures + the enum default still use them), but
no real data reaches them; pre-run table dump on lily in
`/root/db-backups/template_variant-before-bg-layer-20261102-1845.sql.gz`.
**Layer mode** ('layer', every NEWLY created variant): the background is a
regular image object in `objects[]` marked `isBackground: true`, initially
placed **cover-fit anchored top-left** (`scale = max(cw/iw, ch/ih)`, overflow
crops bottom-right) at stack index 0 — reorderable, undoable, snapping-excluded,
styled distinctly in the layers panel ("Pozadí" row), and **an ordinary
lockable layer**: it is seeded `editorLocked: true` (`setBackgroundLayer` /
`BackgroundLayer::buildObject`, and `restoreCustomProperties` defaults
pre-existing flag-less backgrounds to locked) and therefore click-through —
a full-canvas evented object would swallow every mousedown, killing
rubber-band multi-select — but **unlocking it via the mini-toolbar padlock
makes it move and resize like any other picture**. `isBackground` itself no
longer gates interaction anywhere (`applyEditorLock` / `applyBackdropState` /
the backdrop-targeting sweeps key on `editorLocked` alone), so an unlocked
background gets the normal backdrop treatment: passthrough while unselected,
plain-click to select, fully manipulable while active. It used to be
force-locked, which meant the designer could never reposition it at all.
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
- **Form-picked backgrounds come from the PROJECT GALLERY (2026-08-10).** The
  add-template / add-variant / add-group / add-group-dimension forms (and the
  edit-variant AJAX form) carry NO raw background file input any more — a
  classic upload bypassed the gallery (the user expected it there) and an
  existing gallery image was unpickable. Each background field is the
  `background-picker` Stimulus widget (`_background_gallery_picker.html.twig`
  hidden `backgroundImageId` + thumb + buttons) opening ONE shared
  `#backgroundGalleryModal` per page (`_background_gallery_modal.html.twig`
  hosting `Project:ImageGallery`; only the picker that OPENED the modal
  consumes `asset-selected` — armed flag). Uploads inside the modal go through
  the regular gallery uploader → they land IN the gallery. The wire value is
  the FileUpload id; handlers resolve it via
  `Services/Editor/ResolveGalleryBackground` (project + not-trashed guarded,
  invalid id degrades to "no background") and REFERENCE the gallery path —
  the editor-"Pozadí" shape, no byte copy. Messages carry
  `backgroundImageId: null|string` (`AddTemplateVariant`,
  `AddTemplateGroupDimension`, `EditTemplateVariant` — which also keeps
  `backgroundImagePath` for the editor side-channel — and
  `GroupVariantSelection`). The group add-dimension pick is now OPTIONAL
  (null = inherit the group background, the handler's documented fallback);
  the create-from-source copy branch still copies bytes per variant. Note the
  standing trash caveat now applies to these too: purging a gallery file used
  as a background loses its bytes.
- **Group editor**: background GEOMETRY is strictly per-dimension, the
  background PICTURE follows the "Úprava více variant" mode (2026-08-10 —
  shared while the mode is on, single-variant while off, so per-variant
  backgrounds are authorable). `isBackground` objects are excluded from the
  whole group_sync engine (baseline/diff, projectNewObject, removeObject,
  resync, z-order — `isSyncable()`), the `object:added/removed` handlers gate
  on the flag, and layer-mode variants get `backgroundUrl: null` in
  variantsData so every canvas-level re-apply site no-ops. Cover fit is
  absolute per (image, canvas size) — propagating it relatively would compound
  drift, and group-seeded siblings SHARE the background's inputId, so a resync
  would clobber per-dimension covers. But excluding the *picture* too left a
  dimension able to end up with NO background at all: it rendered over
  transparency and whatever full-canvas artwork sat lowest read as the
  background, so the stack looked scrambled even though its object order was
  identical to every other dimension (the 2026-08-06 "group export ignores
  layer order" report). Three paths therefore fan the picture out, each
  recomputing the cover fit from scratch for the target's own canvas:
  `GroupSync.projectBackgroundLayer` on a layer-mode "Pozadí" pick (replacing
  each included LAYER-mode target's background in place, at the slot it
  occupied — index 0 when it had none, metadata copied from the active layer
  so the shared inputId stays the join key; canvas-mode siblings of a mixed
  group are skipped — an isBackground object next to their canvas-level slot
  would render as a second background), the controller's
  `_projectCanvasBackground` on a CANVAS-mode pick (2026-08-12: legacy groups
  used to never propagate at all — each included canvas-mode target gets the
  canvas-level cover on its shadow + a per-variant edit-endpoint POST, because
  the background_image COLUMN is what renders), and
  `AddTemplateGroupDimensionHandler` when a new dimension is added with no
  pick of its own. **Dispatch-ordering contract (2026-08-12, browser-verified
  root cause of "background ignores the mode"): `setBackgroundLayer` is async
  (it awaits the image fetch BEFORE adding the layer), so
  `canvas-editor:background:changed` must only be dispatched AFTER it
  resolves** — `onAssetSelected` awaits it, and the group editor's handler
  resolves the source layer lazily inside the queued op as the belt. An
  un-awaited dispatch made the handler find the OLD layer (or none) and the
  pick silently never propagated. The rail's "Prvky mimo plátno" badge
  ignores `isBackground` objects — a cover fit always overflows one axis.
- **Fillable (Phase B)**: the designer can mark the layer `imagePlaceholder` →
  it flows into `imageInputs` with `isBackground: true` (new `EditorImageInput`
  field; `allowMove/Resize/Rotate` forced false — the fill is a deterministic
  cover, `ImagePlacement::computeCover` + JS mirrors). Unfilled → designed
  background renders (stand-in contract); background slots' `frame` = the full
  canvas rect (the designed object's bbox overflows it). API docs +
  consumer-prompt.md describe the contract; mfkfm is ported.
- `'isBackground'` is in `CANVAS_CUSTOM_PROPERTIES` (and stripped on
  clipboard paste — only ever ONE background layer per canvas).
- **Colour background layer (2026-11-08)**: the background layer may also be a
  flat-colour / gradient **`Rect`** marked `isBackground` (seeded
  `editorLocked: true`, `shapeKind: 'rectangle'`, no `assetPath`), covering
  the bleed box at (-bleed, -bleed) — built ONLY by
  `BackgroundLayer::buildColorObject()` (`isColorLayer()` detects it). It is
  what the MCP design DSL compiles a colour background to
  (`{"kind": "background", "fill": …}` and the legacy
  `canvas.background.fill` shorthand when there is no picture background) —
  root cause of the 2026-11-05 "Poukaz linie" report: the compiler used to
  write that colour as Fabric's canvas-level `background`, which no editor
  panel shows or edits, so Vrstvy had no "Pozadí" and Claude then hand-drew a
  full-bleed rect instead. Contracts: the layers panel labels it "Pozadí"
  (background wins over the shape label); the editor's "Pozadí" gallery pick
  replaces it in place with a picture (`setBackgroundLayer` swaps any
  `isBackground` object); `CanvasDesignProjector` re-fits it to the target's
  bleed box when the target has no picture of its own (a picture still
  replaces it); it never becomes an `imageInputs` slot and
  `background_image` stays null. The canvas-level `background` key is now
  written by the compiler only for a colour UNDER a picture background (linted
  `canvas_color_under_background`). The design linter warns
  `missing_background` / `background_drawn_as_element` so agents self-correct;
  the DSL side is documented in `plugin/wboost/skills/wboost/` and the
  `preview_design` / `set_design` descriptions + server instructions.
