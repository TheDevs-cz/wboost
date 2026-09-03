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

### App shell — left navigation only, NO topbar (2026-08-08)

`base.html.twig` renders the Hyper theme's `.leftside-menu` and nothing above
the content: the theme's `.navbar-custom` was **deleted**, and the two things it
carried moved into the left navigation.

- **Account menu** = `.leftbar-account`, a drop-UP pinned to the sidebar's
  bottom edge (avatar + display name + the same Můj účet / Propojené aplikace /
  Odhlásit se items). It lives OUTSIDE `#leftside-menu-container` so simplebar
  can never clip the menu, and it occupies the bottom padding the sidebar
  already reserved — `padding-bottom` is retargeted to
  `--wb-leftbar-account-height`, which keeps the container's `h-100` resolving
  to exactly the space above it (percentages resolve against the content box),
  so the scrolling menu and the block can never overlap. Icon-only sidebar
  states (`condensed`, `compact`, unhovered `sm-hover`) hide the name + caret
  and the 19px left padding centers the 32px avatar on the 70px rail.
- **Drawer toggle**: the sidebar still collapses below xl (fully off-canvas
  under 768px), so `.mobile-topbar` (`d-xl-none`) carries the theme's
  `.button-toggle-menu` — the theme binds the FIRST one it finds in the DOM, so
  keep exactly one. It takes the SIDEBAR's colours, because the repo ships one
  logo asset whose wordmark is white.
- Nothing else depended on the topbar's height: `.leftside-menu` is
  `position: fixed; top: 0`, `.content-page` never had a top padding, and the
  editor shell measures its own offset (`canvas_zoom_controller`) rather than
  subtracting a constant. The one hard-coded mirror was
  `.editor-left-panel-card`'s below-lg sticky `top`, now the mobile bar's 56px.

**CSS ordering gotcha**: `importmap('app')` injects `assets/styles/app.css`
**before** the theme's `app-saas.min.css`, so an equal-specificity rule of ours
LOSES to the theme (and to Bootstrap). Rules that compete with theme styles must
be scoped one level deeper — `.leftside-menu .leftbar-account` beats
`.dropup{position:relative}`, `.wrapper .leftside-menu` beats the theme's
`padding-bottom`. This is the same trick already noted for `.canvas-stage` and
`.dropdown-menu.editor-display-menu`.

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
| `canvas_shape_properties_controller` | Vector-shape styling — fill (solid or gradient), stroke, corner radius, opacity, editor lock. See the dedicated section below. |
| `canvas_alignment_controller` | Multi-object align ops, z-order, delete. `updateButtonStates` is `has*Target`-guarded since its buttons live in the floating chrome. |
| `canvas_layers_controller` | Photoshop-style layers panel ("Vrstvy"): topmost-first rows, hover outline, click-select, SortableJS restack, and a per-layer **visibility eye**. Row label = the object's `name` (every popover — text, image, shape — has a "Název" field; the image one is offered for EVERY image since 2026-09-03, not just placeholders, and doubles as the fill-page field tag once the image becomes one), else a per-type fallback **numbered in stack order, bottom = 1** ("Obrázek 1", "Obrázek 2 (placeholder)", "Obdélník 1"; a text falls back to its first line, the single "Pozadí" is never numbered) — `canvas_layer_labels.js`, pure and node-testable. The ordinal is display-only: blank names stay blank in the canvas JSON, so nothing reaches `imageInputs[].name` or the API. The eye is PERSISTED (`visible: false` rides the canvas JSON): hidden layers vanish from renders/exports/thumbnails, are **excluded from fillable inputs** (`buildVariantPayload` + `TextInputObjectBinder` skip invisible objects — the positional textbox↔input contract counts only VISIBLE textboxes), drop out of container membership at layout time (`collectMembers` skips them; fill-time `hide` still collapses), are filtered from API `containers[].memberInputIds`, and propagate through group sync (`visible` ∈ META_KEYS). Also a per-layer **padlock**, which is the element's OWN popover lock and therefore means two different things (each row's title says which): an image — the background included — flips `editorLocked` (editor lock: click-through, immovable), a text flips `locked` (fill-time: the user can't overwrite it). When the row is the active object it delegates to `canvas-input-properties#toggleLocked` / `canvas-image-properties#toggleEditorLock` (+ `refreshContextToggle`) so the popover checkbox and mini-toolbar stay in step; otherwise it flips the flag directly. Selecting a row opens the popover WITHOUT focusing a field (`openPopoverKeepFocus`) — a focused input makes `handleKeydown` bail, which used to swallow Delete for anything selected from the panel. The eye and the padlock are `filter`ed OUT of the row's SortableJS drag (`preventOnFilter: false`) and the list has `fallbackTolerance: 4`: in `forceFallback` mode Sortable owns the pointer from pointerdown and its default 0 px tolerance turned any sub-pixel wobble of a click into a drag whose onEnd rebuild replaced the row before the click landed (2026-09-03, "the eye sometimes does nothing"). |
| `canvas_floating_toolbar_controller` | The element-anchored floating UX (see below). |
| `canvas_container_controller` | Containers ("smart text areas") — see the dedicated section below. |

**Vector shapes ("Přidat tvar", 2026-08-11)**

The third "put something on the stage" primitive next to text and images, in
BOTH editors (the left panel is one shared partial). A picker dropdown — not a
modal — drops a shape centred on the canvas; everything about it is then edited
on the canvas through its own floating popover, Canva-style.

- **Kinds** (`SHAPE_KINDS` in `assets/controllers/canvas_shapes.js`): obdélník,
  čtverec, kruh, elipsa, trojúhelník, čára, hvězda → Fabric `Rect` / `Circle` /
  `Ellipse` / `Triangle` / `Polygon`. **"Čára" is a thin Rect on purpose**: a
  real Fabric `Line` has zero height on one axis, which makes it un-resizable
  there and hands the snapping engine and the container flow a degenerate box.
  As a Rect it snaps, scales, rounds its ends and projects like everything else.
  `shapeKind` is a custom prop carried only so the layers panel can name the row
  (a čára and a čtverec are both a `Rect`); nothing renders off it.
- **Sizes are canvas-relative** (¼ of the short edge), because the same editor
  serves 1080×1080 posts and A4-at-300dpi (2480×3508) — a fixed 300 px default
  is half of one design and a speck in the other. New shapes take the project's
  first brand colour, cascaded a few percent per add so the second one is not
  hidden behind the first.
- **Decorative by definition.** Shapes carry no fillable-input metadata, so the
  `textInputs` / `imageInputs` filters in `buildVariantPayload` exclude them by
  type and **the API contract is untouched**. They DO carry an `inputId` — the
  join key group sync propagates on and containers address members by — minted
  in `createShapeObject` and defensively in `restoreCustomProperties`.
- **Nothing server-side has to know how to draw them.** The render template's
  `canvas.loadFromJSON` enlivens every built-in Fabric type, and fill / stroke /
  `rx` / `opacity` / `strokeDashArray` are native serialized props. The PHP that
  does care reasons about an object's ROLE, not its pixels, and shares one type
  list: `src/Value/CanvasShape.php` (mirrored by `SHAPE_FABRIC_TYPES` in
  `canvas_shapes.js` and, again, by the dependency-free copy inside
  `container_layout.js` — that module must stay a classic script).
- **Gradients are `gradientUnits: 'percentage'`** (coords 0…1 of the object's
  own box). A pixel-unit gradient would be baked to whatever size the shape had
  when it was picked and would slide off under the designer's resize, the group
  projector's rescale and the print-resolution export alike. Linear angle ⇒
  coords via cos/sin; radial is centre-out at r2 = 0.5. Verified end-to-end
  through Gotenberg.
- **`strokeUniform: true`** — the border keeps a constant on-screen weight while
  the designer resizes, as in every design tool. The cost is that stroke does
  NOT ride the object's scale, so `strokeWidth` travels as GEOMETRY (projected
  by the width ratio like a textbox's `fontSize`) in `snapshotGeometry` /
  `projectGeometry` / `applyGeometryDelta` and in the PHP mirror
  `CanvasDesignProjector::projectObject`. Everything else about a shape
  (`stroke`, `strokeDashArray`, `strokeLineCap`, `rx`, `ry`, `opacity`) is an
  absolute copy in `STYLE_KEYS`; `fill` was already there.
  - **Gradient fills forced two group-sync fixes**: `snapshotProps` /
    `syncPass` / `resync` now deep-copy paint values (`clonePaintValue` — a
    Fabric `Gradient` is a live object, and sharing one instance across every
    sibling's shadow canvas aliases state the engine treats as per-canvas), and
    `propsEqual` compares objects by CONTENT (a gradient is re-instantiated by
    every canvas load, so identity comparison reported a change on every pass
    after an undo and fanned a no-op edit to every dimension).
- **Backdrop targeting covers shapes**, not just images: a full-bleed colour
  rectangle is the same pointer trap as a full-canvas photo — evented, it
  swallows every mousedown and leaves nowhere to start a rubber-band from.
  `refreshBackdropStates` and the click-to-select / ⌘-drag promotion loops share
  `_backdropCandidate`. The editor padlock (`editorLocked` / `applyEditorLock`)
  is the same flag images use, so the layers-panel row and the mini-toolbar mean
  the same thing for both — but shapes get their OWN mini-toolbar lock button
  and popover, because the action has to reach `canvas-shape-properties` for the
  checkbox to follow.
- **Shapes can be container members**, exactly like decorative images (a rule
  under a heading rides along as an attachment; a standalone one is its own flow
  item). `isMemberCandidate` = text ∪ `isDecorationObject` (image ∪ shape) in
  `container_layout.js`, mirrored in `DesignCompiler::isMemberCandidate` and in
  `AbstractVariantFiller::decorativeMemberFrames` (the fill overlay needs their
  frames to reflow pixel-identically to the server render).
- **The MCP design DSL words shapes too** (`{"kind": "shape", …}`, 2026-08-12):
  `ElementKind::Shape` + `Dsl/ShapeElement` + `DslParser::SHAPE_KEYS` /
  `SHAPE_FILL_KEYS`, `DesignCompiler::compileShapeObject` (+
  `SHAPE_CUSTOM_PROPERTIES`), a `shapeElement()` branch in the decompiler, and
  bounds linting. The vocabulary is the EDITOR's, not a second model —
  `src/Value/CanvasShapeKind` / `CanvasShapeStroke` / `CanvasShapeGradient`
  mirror `canvas_shapes.js`, so `shape: "line"` in is `shape: "line"` out and a
  compiled canvas is indistinguishable from an editor save. Notes:
  - **Geometry is the DISPLAYED box.** The compiler emits base dimensions at
    scale 1 and uses a scale only where the Fabric type forces it (a `Circle`
    has ONE radius, so a non-square box — what a designer gets by dragging a
    corner handle — needs `scaleY`). A star's `points` are normalised to span
    the authored box exactly, which is what makes an editor-made star
    recompile to itself.
  - **`cornerRadius` is refused on non-Rect kinds**, not ignored: on an
    `Ellipse` the very same `rx`/`ry` ARE the radii, so honouring it would
    silently resize the shape.
  - **Three shared-style losses became conditional.** `reportSharedStyles` used
    to flag opacity / stroke / `editorLocked` on every object; a shape words all
    three, and reporting them would tell the agent it is about to destroy
    something the next `set_design` writes back unchanged.
  - The `layer-shapes` round-trip fixture is a REAL browser-authored canvas and
    is **lossless** — the only such one besides the empty write target, because
    the grammar was written against what the editor already emits.

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
top bar shared by the single-variant and the group editor). All five live
behind ONE gear button ("Zobrazení") as a Bootstrap dropdown with
`data-bs-auto-close="outside"` — they are set occasionally and were costing the
widest row of editor chrome. The menu stays in the DOM while closed, which is
what keeps the connect-time reads below working. Element IDs are the
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

**ONE fill UX on both fill pages (2026-09-03).** The group fill page
(`template_group_fill.html.twig`) used to be a different product: a bare
side panel of single-line `<input>`s (no WYSIWYG, no checklist, no font
select — the "no rich text on synchronized templates" report), while the
single page had the click-into-preview overlay. Both pages now render the
SAME editing surface from shared partials — `_fill_overlay_toggles.html.twig`
(the two switches + the "Všechna pole" button), `_fill_overlay_boxes.html.twig`
(the boxes of ONE surface), `_fill_text_popover.html.twig` (one input's
popover: textarea / WYSIWYG / checklist / font select), `_fill_layers.html.twig`
— built server-side by ONE builder, `Services/SocialNetwork/FillTextPlaceholders`
(`placeholders()` / `layoutData()` / `layers()` / `fontOptions()` /
`richTextToolbar()`; `AbstractVariantFiller` delegates, `GroupFillPlaceholders`
unifies over the member dimensions first-wins by inputId). Contracts:

- **Surfaces.** `variant_fill_overlay_controller` draws over N `surface`
  targets, each carrying `data-canvas-width` + `data-layout`
  (= `layoutData()` of THAT dimension) and containing its preview + its
  `.fill-overlay` boxes; the single page has one (the zoomable `.fill-stage`,
  target `"stage surface"`), the group page one per dimension (`.fill-surface`
  wrapping the preview frame). Boxes scale per surface, container reflow runs
  per surface with its own measurement boxes, the worst overflow of any
  dimension gates the export button(s).
- **Popovers are per INPUT, not per box**: the pencil on any dimension opens
  the one popover of that input, anchored to the clicked box (`_anchorBox`;
  a pencil of the same input on another dimension RE-ANCHORS instead of
  toggling closed). The group page therefore has the single page's mirror
  structure: one hidden `textValues[…]` / `fontValues[…]` /
  `hiddenValues[…]` field per input carrying `data-text-mirror` /
  `data-font-mirror` / `data-hide-mirror` (+ `data-input-id` for the echo)
  and `data-action="input|change->group-fill#changed"` — the popovers, the
  WYSIWYG and the checklist editor write those (they look the mirror up by
  `[data-text-mirror]` globally), and every dimension re-renders. Image
  slots: the group's `images[<id>][hide]` checkbox carries `data-image-hide`,
  its Bootstrap picker modal `data-image-modal="<id>"` — `openImageModal`
  falls back to `bootstrap.Modal` when no `.fill-modal` target matches.
- **"Všechna pole" panel** (`togglePanel` → `fill-panel-open` on the form):
  the `.fill-popovers` container (target `panel`) docks into a centred modal
  listing every text popover + one `.fill-popover--image` card per slot
  (thumb + picker button + eye; the group adds its per-dimension placement
  rows) — the SAME editor instances, no second copy of any field, floating
  chrome (`.fill-popover__chrome`: grip / Uložit / ×) hidden, the panel-only
  `.fill-popover__eye` shown. z-order 1041: above the page, below the
  pickers (`.fill-modal` 1300, Bootstrap 1055). The single page's card thumb
  follows `variant-image-fill:picked` ({inputId, url}, also fired on a
  version restore).
- **Text semantics are unified to the single page's**: the group fields
  start pre-filled with the sample text and an EMPTY field blanks the text
  in every dimension (`GroupFillRenderer` no longer drops empty strings,
  `ExportFillValues::fromGroupWebForm` keeps them, the seeder re-loads
  them). Untouched sample-less inputs export blank — the single page's
  behaviour since day one; the group's old "empty = designed text" is gone.

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

**Client-side text echo — instant typing, server truth at rest (2026-09-01)**

Typing on the fill surfaces no longer waits for Gotenberg: the ECHO-CAPABLE
text inputs are drawn locally on a transparent Fabric canvas layered over a
text-transparent BASE render, and the lazily debounced server render (the
"settle") remains the displayed truth at rest and the only exported pixels.
The WYSIWYG contract is therefore untouched — the API and every export path
never see any of this.

- **`EchoCapableTextInputs`** (Services/Editor) is the single source of the
  set that is BOTH drawn client-side and rendered transparent in the base:
  non-locked, non-lists (a block stack cannot be blanked by opacity — its
  replacement children are fresh objects), positionally locatable, minus a
  z-guard (visible non-echo content stacked above and overlapping, rotated
  AABBs, conservative) and a container guard (a tree with any baked member —
  a decorative icon, a settle-only text — stays settle-rendered whole). Rules
  run to a fixpoint; eligibility is PER DIMENSION on groups.
- **Base renders** = the same render with `transparentTextInputIds` (opacity
  0, layout influence kept — the sliceCanvas convention; applied in
  `buildCanvasJson` after `alignTextboxInputIds`). The override-independence
  proof generalizes (`renderIsOverrideIndependent`): a bound TEXT in the
  transparent set cannot leak an override into the pixels, so base renders —
  the full previewSources() render included — are Redis cache hits whenever
  every bound object in range is a transparent text. Slices/backdrops whose
  range holds no echoed text reuse the settle bytes outright.
- **The painter** is `assets/editor/fill_text_echo.js` — a CLASSIC script
  (the container_layout.js pattern) sharing the render template's exact
  override pipeline: clear-styles-before-text, rich runs via
  WBoostRichTextRuns, two-phase container reflow prepared ONCE over the
  pristine designed state and re-applied per update (the snapshot anchors on
  designed geometry, so repeated applies cannot drift). Value resolution
  mirrors ResolveTextOverrides (code-point truncation via Array.from ≙
  mb_substr, then locale-independent uppercase); `{designed: true}` restores
  the pristine text + per-char styles (the group page's "empty keeps the
  designed text" semantics, sample first).
- **Single page** (`variant_text_echo_controller.js`): mirrors' edits flip
  `fill-echo-active` (settle img hides under base img + echo canvas; on the
  image branch `variant_image_fill` swaps its backdrop/overlay sources to
  their `data-base-src` variants via the `variant-text-echo:mode` event).
  Echo-capable mirrors debounce 2500ms, the rest keep 600ms. A settle whose
  `data-state-hash` equals the client hash of the mirrors AS THEY ARE NOW
  rests the echo; a stale settle keeps it up (Live guarantees a final
  re-render). The hash is djb2 over the canonical UTF-8 fill state — PHP
  (`AbstractVariantFiller::fillStateHash`) and JS (`_clientHash`) are pinned
  byte-identical by test (Czech diacritics covered).
- **Group page** (`group_fill_controller.js`): per-dimension activation (an
  edit lights up dimensions where the input is capable), base fetched LAZILY
  on first edit via `?base=1` on the fill-preview endpoint (page load stays at
  one settle per dimension; image picks/placements invalidate cached bases),
  rest via an edit-sequence check against the settle's POST snapshot. Settle
  debounce relaxes to 1500ms when anything is echo-capable.
- **The golden proof**: `tests/Golden/FillEchoParityGoldenTest.php` (group
  `gotenberg`, real renderer — `vendor/bin/phpunit --group gotenberg --filter
  FillEchoParity`) screenshots the echo composite through the REAL inlined
  painter+resolver in Gotenberg's Chromium and asserts MSE < 1e-4 against the
  full server render — truncate+uppercase, rich runs, container push and hide
  in one scene, with a positive control (base ≠ full). What it cannot prove —
  a non-Chromium glyph rasterizer — is exactly why the settle stays the
  displayed truth at rest.
- **Morph-inert scripts landmine**: the fill component mounts with
  `loading="defer"`, and `<script>` elements inserted by a Live morph NEVER
  execute — the classic scripts + @font-face block load from the PAGE
  template (`template_variant_export.html.twig`), not the component. Anything
  new the component needs as a window global must go there too.

**Project image gallery — Live Component (Stage 7 → 8)**

`Project:ImageGallery` (`src/Twig/Components/Project/ImageGallery.php`) is the
per-project, per-`FileSource` asset library shown in the admin editor's "Add
image" / "Set background" modal. Image **selection** stays a DOM
`CustomEvent("asset-selected")` (with `{ url, path, id, name, width, height }`
— the last three nullable, added 2026-09-03) so the host Stimulus controller
routes the chosen URL to `addImageToCanvas` or `setBackgroundImage` without a
server round-trip.

**Tile caption — file name + pixel size (2026-09-03).** Every tile (modal,
standalone, Koš) carries an always-visible caption under the thumbnail: the
uploaded file name (base + extension in separate spans, so a long name
truncates in the middle and keeps its tail) and `1080 × 1350 px` with a format
badge (`PNG`/`JPG`/…; an SVG reads "Vektor"); the tooltip repeats it with the
upload date. Two look-alike pictures of different sizes were otherwise
indistinguishable. Backed by three nullable `file_upload` columns:
`original_name` (the client name, sanitised in `UploadFileHandler`; NULL for
uploads before the column — nothing to backfill it from, the tile says "Bez
názvu") and `width`/`height` (recorded at upload from the normalised bytes, so
a transcoded HEIC reports its JPEG size). Old rows get their size **backfilled
lazily on first sight** — `Services/Image/FileUploadPixelSizeBackfill` (a
bounded header read via `ReadStoredImagePixelSize`, persisted, never repeated;
an unreadable object stays NULL and is retried, no marker column) is called by
the gallery listings AND the MCP `list_gallery` tool (which no longer reads
headers itself) — plus `app:gallery:backfill-image-size` for a one-shot sweep
(run once after deploy; non-zero exit lists unreadable rows). The MCP
`GalleryImageResponse` gained `originalName`; `name` stays the stored basename
(= format). The caption fields are computed once in `ImageGallery::describe()`
(`name`, `nameBase`, `nameExt`, `sizeLabel`, `format`, `tooltip`).

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
  read-only special directory listed in EVERY folder, not just the root
  (2026-09-03 — a root-only card read as "this folder has no bin"; opening
  it keeps `currentDirectoryId` so the breadcrumb leads back), showing a
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

**Export versioning — "Historie exportů" (2026-09-01).** Every SUCCESSFUL
export snapshots its fill values as a re-usable version so users can re-load
what they exported before. Entity `TemplateExportVersion` (table
`template_export_version`, JSONB `fill_values` via
`ExportFillValuesDoctrineType` → the `ExportFillValues` VO) — LIVE functional
data with real FKs, unlike the denormalised `ExportEvent` analytics row that
is written right next to it: variant/group/template deletion CASCADEs the
history away, a deleted user only SET-NULLs `exported_by`. Exactly one of
`variant`/`group` is set — the ZIP export AND the per-dimension group
download both snapshot the same group fill form onto ONE group-scoped
version.

- **Recording**: `Services/Template/RecordExportVersion` (the
  RecordExportUsage twin — swallow+log, dispatched AFTER a successful render)
  is called at all six chokepoints with the surface's raw values;
  `ExportFillValues::fromVariantWebForm/fromGroupWebForm/fromApiRequest`
  normalise them into the WEB WIRE SHAPE (API rich `runs` re-encode as the
  envelope STRING the mirrors carry); both web forms KEEP empty strings
  (= "blank the text" — the group form dropped them as "keep designed"
  until the 2026-09-03 fill-page unification). `toArray()` is canonical (recursive ksort, floats
  rounded to 4 decimals) and `hash()` (sha256 over it) is the dedup key: the
  handler (`RecordTemplateExportVersionHandler`) bumps
  `lastExportedAt`/`exportCount`/`exportedBy`/`channel` on a same-(subject,
  hash) row instead of inserting, and prunes each subject to
  `MAX_VERSIONS` (30). No unique constraint on purpose — a lost race merely
  duplicates a history row, a violation would abort the export's transaction.
  An all-defaults export still records (dedup collapses the repeats).
- **Re-loading** (`?version=<id>` on both fill pages; invalid/foreign/pruned
  ids silently ignored): `Services/Template/ExportVersionSeeder` is LENIENT
  where the render resolvers 400 — deleted inputs drop, a rich envelope on a
  now-plain input degrades to its text concat, a trashed/purged/other-project
  picture or one moved out of the slot's allowed folders falls back to the
  designed stand-in, and transform fields the slot no longer permits are
  stripped (a seeded page must always be exportable again). Single-variant
  page: texts/hides seed as `:textValues`/`:hiddenValues` mount props
  (postMount's `??=` fills only what the version left blank) and image state
  as `:seedImageValues`, folded into the `imagePlaceholders()` payload —
  the twig pre-fills the hidden `images[…]` fields + hide checkbox and
  `variant_image_fill_controller._restoreSeed()` redraws the picked pictures
  with their transforms on connect (no activation, fields left as
  server-rendered). Group page: server-rendered form values + a
  `data-group-fill-seed-value` JSON that `group_fill_controller._applySeed()`
  replays AFTER connect()'s neutral-placement reset (server-rendered
  placement fields alone would be wiped by it).
- **UI**: shared partials `_export_history_menu.html.twig` (dropdown on both
  fill pages: freshest first, user, non-web channel badge, ×N export count,
  "Zpět na výchozí hodnoty") + `_export_history_banner.html.twig` (loaded
  state). Listing surfaces read `Query/GetExportVersions`
  (`latestForProjectTemplates` / `latestForTemplateVariants`, Postgres
  DISTINCT ON): template cards show "Naposledy exportováno", variant tiles
  add a "Načíst poslední export" menu item.

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

**Group editor propagation — two target sets (2026-08-07).** `GroupSync` is
handed `targets()` AND `allTargets()`, and the split is what the rail's UI
means:

- **EDITS** (moves, resizes, styles, metadata, z-order, containers — i.e.
  `syncPass`) go to `targets()`: EMPTY when the designer turns off
  **"Úprava více variant"** (the mode button in the variant rail — ON by
  default with every variant included since 2026-08-10; was OFF by default),
  otherwise the variants whose per-variant switch is on. Those switches are
  chrome of the mode and only render with it (CSS `--multi` on the rail); the
  ACTIVE variant's is forced on + disabled. The template's server-rendered
  initial chrome must stay in lockstep with the controller's `multiEdit`
  default (`_refreshRail` only syncs after hydration). Because the mode is
  live from the first paint, the debounced edit pass DEFERS while shadows
  hydrate (`_shadowsHydrated`) and fans out once at boot-end via
  `_flushPendingSync` — flushing (or plain-rebaselining) earlier would fan
  the diff to the hydrated subset only, or consume it entirely.
- **STRUCTURE** (`projectNewObject`, `removeObject` and the explicit
  per-element "Srovnat podle skupiny" `resync`) goes to
  `allTargets()` — every dimension, no opt-out, mode or not. The background
  PICTURE is the exception since 2026-08-10: `projectBackgroundLayer` goes to
  `targets()` (mode-gated) so per-variant backgrounds stay authorable — with
  the mode on and everything included (the default) a pick still reaches
  every dimension; turning the mode off makes it a single-variant change.
  The object set
  MUST stay identical across dimensions: one silently missing an element (or
  background) renders as a scrambled stack, the exact failure the group model
  exists to prevent. Delete mirrors add so an always-fanned-out object stays
  removable in one go; per-dimension "not shown here" is the layers-panel eye,
  which travels as an ordinary (gated) edit.

Flipping the mode settles the debounced pass under the OLD mode and then
rebaselines — otherwise edits made while it was off would fan out
retroactively on the next mutation. The rail carries NO per-variant preview
and no whole-variant re-sync button (both retired 2026-08-07): it is
navigation (chips: label, dirty dot, overflow badge), the stage is the
preview.

**Structural reliability (2026-08-10).** The "object set identical across
dimensions" invariant used to be enforceable only at the moment of the action,
and three windows let an add/delete land on a subset of variants (the prod
"object added to one variant only" → "image fill exported on one variant only"
report): pre-2026-08-07 adds respected the include switches (divergence from
then is persisted in the DB), the Fabric hooks were live before the sibling
shadows hydrated (`allTargets()` silently filters shadow-less variants), and a
failed `clone()` (image src fetch) aborted the fan-out loop mid-way with no
catch. Now all propagation ops (add / remove / background pick / "Srovnat
podle skupiny") run through `_enqueueStructural` — ONE serialized promise
chain gated on `_shadowsReady` — with per-target try/catch in
`projectNewObject`/`projectBackgroundLayer` (one failing variant never strands
the rest). Tab switch, undo/redo and save first `_drainStructuralOps()` (a
clone landing in a shadow after it was serialized would be clobbered on the
next switch-away), and `_activate` settles the debounced edit pass BEFORE
setting `_switching` (the old in-`try` flush was dead — `_quiet` blocked it).
Persisted divergence is healed by `GroupSync.reconcileStructure()` — ADD-ONLY
(an object missing on the active canvas but present on a sibling heals when
THAT variant is activated; deleting would destroy work) — which runs after
boot, after every tab switch and before save, so a divergent group repairs
itself progressively as a designer opens tabs and saves. Reconcile passes are
`pushHistory: false` (no undo points of their own) but DO mark healed variants
dirty, so the heal rides the next save. A variant whose shadow hydration
throws is nulled back to `shadow: null` — fully inert (excluded from targets,
save and tab switching) — because a half-hydrated shadow would be serialized
over the variant's real saved canvas on save.

**Network-flake hardening (2026-08-31, the "add sometimes misses a variant"
report).** Root mechanism, verified against the committed Fabric 7.3.1 build
in headless Chrome: **`loadFromJSON` does NOT reject when an image src fails
to fetch — it resolves with that object silently DROPPED from the canvas.**
Every canvas (re)load re-fetches its image srcs over the network, so one
transient flake during shadow hydration / tab-switch reload / history restore
produced a shadow missing an object with only a console line as evidence (no
JS Sentry exists — hence "nothing in Sentry, seems random"), and the next
save persisted the loss. Worse, `restoreCustomProperties` mapped source→
loaded objects by POSITIONAL INDEX, so a mid-stack drop re-stamped every
object above the gap with the previous entry's `inputId` and scrambled the
inputId-keyed propagation for that variant. A prod audit found real persisted
divergence (group `019fd23a-ac3b…`: two image placeholders in one variant
only, the sibling's canvas empty). Hardening, all browser-verified via the
same-origin static-harness recipe with an injected broken-src image:

- `restoreCustomProperties` is **drop-aware**: pure index map only when the
  counts agree; on mismatch it console.errors and aligns source→loaded as an
  ordered subsequence on type + position, so the scramble is impossible.
- `_loadShadow` **throws when the loaded object count falls short** of the
  document's, turning the silent drop into a real failure; a lossy shadow is
  never left in play (it would propagate wrong AND save over the variant).
- `_hydrateShadowWithRetry` (boot) retries 3× with 500/1500 ms backoff; a
  variant that still fails is `loadFailed` → visible **"Nenačteno" rail
  badge** (precedence over overflow/off-canvas), excluded from targets and
  save (its stored canvas stays intact). Clicking the chip re-hydrates
  through the structural chain and reconciles FROM the current active variant
  BEFORE activating (reconcile is add-only from the active side). The
  `_loadShadow` calls in `_activate` (switch-away reserialization) and
  `_restoreSnapshot` catch per-variant: `variant.canvas` keeps the serialized
  truth, the shadow is nulled + `loadFailed` for the badge's re-hydration.
- The INTERACTIVE canvas load gets the mirrored guard: `_activate` retries
  the incoming load once when it comes up short of the shadow's count, and
  boot runs `_healActiveCanvasDrop` (reload from the intact shadow) — a lossy
  active canvas would otherwise serialize the loss back over the variant.
- The per-target clone in `projectNewObject`/`projectBackgroundLayer` retries
  once after 400 ms behind an idempotent `attempt()` (presence check inside).
- `submitForm` drains the op chain after its reconcile (everything to the
  fetch is then synchronous) and runs `_structuralGaps()` — active-canvas
  syncable inputIds vs each sibling shadow (`isSyncable` is exported from
  group_sync.js for this) — and ALERTS with the affected variant labels
  instead of silently saving divergence; the save still proceeds (blocking
  would hold the healthy variants' work hostage).

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
"Stáhnout" button in the page heading for the page-level one; the editor's
"Soubory ke stažení" rows (page + one per slot of the chosen layout) mirror
the images' pick/remove/restore flags, and an attached slot gets a paperclip
badge on the stage. Attachments are NOT images, so the cap is
`ManualMockupPageFormType::DOWNLOAD_MAX_SIZE` = 20 MB decimal (mirrored
client-side), and any file type is accepted. Both columns are enumerated in
`BuildStorageReferenceIndex` — without that the files read as orphans.

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

### Manual colours — HEX, CMYK, Pantone, RAL

`ManualColor` (in the JSONB `manual.detected_colors` / `.custom_colors` via
`ManualColorsDoctrineType`) carries `ral` alongside `pantone`. No migration:
the key is simply absent on older rows and reads back as null. Every code is
printed by `_manual_color_swatch.html.twig` — extracted from the two
copy-pasted palette blocks — and only when the designer filled it, so a manual
with no RAL looks exactly as before. `ManualColorsFormType::isBlankColor()`
counts `ral` too, otherwise a row holding nothing but a RAL code would be
dropped on save.

### Manual logo width — a three-level cascade

How big a logo renders inside one manual card resolves through
`Manual::logoDisplayWidth($slot, $logoVariant)`, highest priority first:

1. the width set on **that card** (`manual.logo_slot_widths`, a JSON map keyed
   by slot id) — edited by a pencil on the card itself;
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

A mockup page's downloadable ATTACHMENT is deliberately outside this limit —
it is not an image and a print PDF routinely exceeds 10 MB. It has its own
20 MB decimal cap (`ManualMockupPageFormType::DOWNLOAD_MAX_SIZE`), mirrored by
`_mockup_page_form_editor.html.twig` into `mockup_page_editor_controller.js`.

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
  rotates within the limits, clipped to the frame. **Arrow keys nudge the selected
  picture by 1 canvas px** on BOTH fill pages (2026-09-03, the editor's
  `moveSelectedObject` semantics: per-axis `lockMovement*` respected, inert while
  a fill field / WYSIWYG has focus or a picker modal is open). The single page
  listens on `document` (Fabric's upper canvas is not focusable) and fires
  `object:modified` so the hidden fields + overlay box follow; the group page's
  ghost box converts the px step to a ratio of THAT dimension's frame and takes
  focus on pointerdown — cancelling pointerdown cancels focus-on-press, so
  before that the box was Tab-only and arrows scrolled the page after a click.
  Design content the admin stacked
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
