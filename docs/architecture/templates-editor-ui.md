# Templates — admin editor UI (Stimulus controllers, shapes, floating toolbar, chrome toggles)

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
| `canvas_navigator_controller` | Photoshop-style mini-map (always visible, fixed bottom-right): live thumbnail blit + visible-area rectangle; click/drag on the map pans the `.canvas-viewport`. The panel is MOVABLE (2026-10-22, "it is in my way"): the grip bar drags it (pointer capture, clamped inside the window), the spot persists per browser in `localStorage` (`wboost.canvasNavigator.position`, as right/bottom offsets so it keeps hugging its corner on resize; storage failures fall back to the corner), and a double-click on the grip resets it. Re-clamped on every `refresh()` because the panel's size follows the canvas aspect. |
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
- **Decorative by default.** Shapes never reach the `textInputs` /
  `imageInputs` save payload (filtered by type in `buildVariantPayload`). They
  DO carry an `inputId` — the join key group sync propagates on and containers
  address members by — minted in `createShapeObject` and defensively in
  `restoreCustomProperties`.
- **Recolourable shapes ("Barva pro uživatele", 2026-10-20).** The shape
  popover can open a shape up for END-USER recolouring: custom props
  `colorChoice` (`null` | `'fill'` | `'stroke'` | `'both'` — one pick repaints
  that part, `both` paints fill AND border in one colour), `allowedColors` (the
  swatch list: the manual's colours pre-ticked when switched on, plus custom
  hexes; the designed colour is always allowed and is not a chip) and
  `colorFree` ("Povolit i libovolnou barvu" — the list becomes a suggestion).
  **No column and no payload key**: `Value/ShapeColorInput` derives the
  definition from the canvas on demand (`collectionFromCanvas[Json]`), which
  keeps save, copy, group sync (`colorChoice`/`colorFree` ∈ META_KEYS) and the
  MCP compiler on their existing paths. It is also the single authority on
  eligibility: visible shapes only, and only a SOLID part is paintable — a
  gradient fill (until gradient recolouring lands) or a width-less border
  drops out, `both` degrading to whichever part is left (the popover warns via
  the JS mirror `_syncColorChoiceWarning`). Picks are validated by
  `ResolveShapeColorOverrides` (strict 400 `invalid_shape_color` /
  `shape_color_not_allowed` + `allowedColors`, tagged `shapeInputId`; lenient
  drop; the designed colour = no override) into
  `ResolvedInputOverrides::$shapeColors`, which the renderer bakes into the
  canvas JSON (`TemplateVariantImageRenderer::applyShapeColors`) before the
  Gotenberg render — the headless template needs no shape code. Slice-cache
  safe: a bound (= every) shape in range already makes a render
  override-dependent.
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
- **Multi-stop gradients with per-stop opacity** (2026-11-01): 2…8 stops
  (`GRADIENT_MAX_STOPS` ↔ `CanvasShapeGradient::MAX_STOPS`), each `{offset,
  colour, opacity}`, edited as rows (Barva / Krytí / Pozice, "+ Přidat barvu",
  reverse button) under a checkerboard preview bar. **The alpha lives INSIDE
  the stop colour** — `#rrggbb` when opaque, `rgba(r,g,b,a)` otherwise —
  because Fabric's `toLive` passes `color` straight to `addColorStop` and
  ignores a separate `opacity` key. That string is a byte contract between
  `gradientStopColor` (JS) and `CanvasShapeGradient::stopColor` (PHP); offsets
  and alphas are rounded to whole percent on both sides. Opaque two-stop
  gradients serialize exactly as before, and the DSL keeps `{from, to}` for
  them (`stops: [{at, color, opacity}]` otherwise). Gotenberg-verified by
  `ShapeColorRenderTest::testATransparentGradientStopFadesOverWhatIsBelow`.
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
- **Mirror** (`mdi-flip-horizontal`, "Zrcadlit vodorovně", 2026-10-22):
  `canvas-image-properties#toggleMirror` flips plain Fabric `flipX` — no
  custom prop; it serializes with the canvas JSON and the headless render
  honours it, and Fabric flips around the centre so the box is unchanged.
  DECORATIVE images only: `refreshContextToggle` hides it on placeholders and
  the background, because a fill re-places a placeholder's picture and resets
  its flip (`ImagePlacement`); marking a mirrored image as a placeholder
  un-mirrors it. Group sync carries `flipX` as an absolute STYLE_KEYS copy
  (mode-gated like any edit; "zrcadlení" in the re-sync toast). MCP DSL mirror:
  image `mirror: true` (rejected on placeholders; the decompiler round-trips a
  decorative `flipX` and still reports `flipY` / a placeholder flip as lost).
- It re-anchors on Fabric `object:moving/scaling/rotating/modified`,
  `canvas-editor:selection:changed`, `canvas-zoom:changed` and window resize;
  hides during inline text editing (`text:editing:entered/exited`); flips the
  popover above/below to stay in the viewport and below the sticky header
  (`[data-editor-header]`).
- The top-bar toggle "Zobrazit editovatelné prvky" (`toggleHighlight`) draws
  one **DOM** `.editable-outline` per selectable object (NOT on the canvas
  bitmap, so it never leaks into the saved preview thumbnail or the server PNG).
  An outline follows its object's `visible` flag live in the `after:render`
  loop (`display:none` while the layers eye hides it) — the eye fires no
  add/remove, so the rebuild path never sees it.

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

**Print bleed zone ("spadávka", 2026-10-30) — `canvas_viewport.js`**

A variant with a print bleed (`TemplateDimension::bleedPixels()` > 0) is
edited over its BLEED BOX: `_editor_stage` renders `<canvas id="c">` at
trim + 2 × bleed, and `applyBleed()` shifts Fabric's viewport by the bleed so
canvas coordinates stay TRIM-relative (saved JSON, renders, fill pages and
the MCP DSL never change). The group editor re-applies it per tab
(`canvas-editor#setDesignSize`, `variantsData[].bleed`). Contracts every
editor module must keep:

- `canvas.getWidth()/getHeight()` are the ELEMENT size — design-size logic
  (backdrop coverage, shape defaults, image drop, container page height,
  snapping canvas edges, ruler length/limits) uses `designWidth()/designHeight()`.
- `obj.getBoundingRect()` is in scene space, so every DOM-chrome `_metrics()`
  (floating toolbar, containers, layers, rulers, snapping) returns
  `trimOriginRect(...)` — the element rect re-based on the trim corner. Any new
  overlay must do the same or it drifts by the bleed.
- **Fabric's `_getPointerImpl` inverts the viewport BEFORE the CSS-scale
  correction**, i.e. it treats the viewport translation as CSS px. Our zoom is a
  CSS transform, so on a bleed variant every click landed ~bleed/zoom off.
  `applyBleed` patches the canvas to derive the scene point from the (correct)
  viewport point. Verified in headless Chrome: synthetic drag on an A4 + 3 mm
  variant moves the object by exactly the dragged distance; outlines sit on
  their objects; the 1:1 variant of the same group is unaffected.
- The zone itself is a DOM overlay inside Fabric's container
  (`.canvas-bleed-guide`: red outline = bleed edge, trim outline, tinted band),
  never on the bitmap; line widths divide by the zoom (`--bleed-line`). The
  save-time thumbnail crops to the trim. The bleed edge is a snap target, and
  the group rail's "Prvky mimo plátno" badge tolerates objects inside the bleed.
- Backgrounds cover the bleed box everywhere: `coverForDimensions(..., bleed)`
  (editor + group sync), `BackgroundLayer::buildObject(bleed:)` (add / edit /
  group create + add-dimension / projector), the render template's canvas-mode
  cover (in EVERY render, so PNG and PDF crop the same placement), and the
  background SLOT fill (`PlaceholderFrame::backgroundCover()` — renderer, fill
  page, API frame with a negative origin). Changing the bleed on "Tiskové
  nastavení" re-covers a layer background only if it still sits at its
  automatic cover (`BackgroundLayer::recoverForBleed`) — a hand-placed one is
  the designer's composition.
