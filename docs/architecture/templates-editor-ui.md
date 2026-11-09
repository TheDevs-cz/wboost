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
| `canvas_editor_controller` | Orchestrator. Owns the Fabric `Canvas` (retina scaling OFF above ~4M logical px — a print-size canvas would repaint dpr²× the pixels on every frame, the "editor is laggy" report), loads/saves canvas JSON, marks the form dirty, broadcasts selection changes. Also owns **backdrop targeting**: an UNLOCKED image covering ≥90 % of the canvas (`isBackdropCovering`/`applyBackdropState` in `canvas_custom_properties.js`) is click-through while unselected — dragging over it rubber-bands and a marquee never pulls it in (an evented full-canvas image would join EVERY marquee) — but unlike `editorLocked` no lock flags are set: a plain CLICK (mouse:up, no movement, nothing hit) selects it Canva-style and it becomes fully movable until deselected (Esc discards the selection — and so does a press on empty bare grey editor surface, the only CLICK off a selected backdrop, since its pixels leave no empty canvas to click). **The bare surface around the canvas element acts as more canvas** (`forwardSurfacePress` / `hoverSurface`): a press on the grey viewport/stage/wrapper is re-dispatched to Fabric's upper canvas at the same client point — Fabric's pointer math and `_checkTarget` have no element-bounds check — so a press on an object's part lying out there selects + drags it, and a press on nothing discards the selection; hovering such a part shows the move cursor. Stage chrome (toolbar, popovers, rulers, guides, SVG handles + move grip) has its own targets and is untouched. The backdrop click-select (`maybeSelectBackdrop`) applies out there too, consistently with the canvas. Swept on every mutation/selection event; layers-panel selection gets the same active exemption. Pointer modifiers (`applyPointerModifiers`, on `mouse:down:before`): **Alt/⌥+drag** always rubber-bands even over objects (skipTargetFind for the press; a press on a transform handle keeps Alt = centered scaling), **Ctrl/⌘+drag** always grabs the object under the cursor — promotes a passthrough backdrop for the press (Photoshop Move-tool auto-select; composes with the ⌘ snap bypass). Cheatsheet in the "Nápověda" modal (`#editorHelpModal`, opened from the tool row). |
| `canvas_history_controller` | Undo/redo stack — full-canvas-JSON snapshots, restored via the orchestrator's loader. |
| `canvas_clipboard_controller` | Copy / paste / duplicate (keyboard + buttons). |
| `canvas_zoom_controller` | CSS-transform-only visual zoom of the wrapper element. Dispatches `canvas-zoom:changed` so the floating toolbar re-anchors. |
| `canvas_navigator_controller` | Photoshop-style mini-map (always visible, fixed bottom-right): live thumbnail blit + visible-area rectangle; click/drag on the map pans the `.canvas-viewport`. The panel is MOVABLE (2026-10-22, "it is in my way"): the grip bar drags it (pointer capture, clamped inside the window), the spot persists per browser in `localStorage` (`wboost.canvasNavigator.position`, as right/bottom offsets so it keeps hugging its corner on resize; storage failures fall back to the corner), and a double-click on the grip resets it. Re-clamped on every `refresh()` because the panel's size follows the canvas aspect. |
| `canvas_text_toolbar_controller` | Font / size / colour / alignment / decoration / max-length controls for the active textbox (populate + mutate only — visibility is owned by the floating toolbar). |
| `canvas_input_properties_controller` | Editor-side input metadata (name, description, locked, hidable, uppercase). Persists onto the canvas object as custom properties via `CANVAS_CUSTOM_PROPERTIES`. Exposes `toggleLocked` for the mini-toolbar. |
| `canvas_image_properties_controller` | Image-placeholder metadata (placeholder flag, name/description, allowMove/Resize/Rotate, hidable, allowedDirectoryIds). Exposes `togglePlaceholder` for the mini-toolbar. Also the popover's **"Velikost a poloha"** section (see below). |
| `canvas_shape_properties_controller` | Vector-shape styling — fill (solid or gradient), stroke, corner radius, opacity, editor lock. See the dedicated section below. |
| `canvas_opacity_controller` | Admin-set **opacity** ("Průhlednost", 0–100 %) for texts and images (placeholders included) plus a compact slider in the **multi-select bar** that writes every selected member (shapes too). See "Element opacity" below. |
| `canvas_alignment_controller` | Multi-object align ops, z-order, delete. `updateButtonStates` is `has*Target`-guarded since its buttons live in the floating chrome. |
| `canvas_layers_controller` | Photoshop-style layers panel ("Vrstvy"): topmost-first rows, hover outline, click-select, SortableJS restack, and a per-layer **visibility eye**. Row label = the object's `name` (every popover — text, image, shape — has a "Název" field; the image one is offered for EVERY image since 2026-09-03, not just placeholders, and doubles as the fill-page field tag once the image becomes one), else a per-type fallback **numbered in stack order, bottom = 1** ("Obrázek 1", "Obrázek 2 (placeholder)", "Obdélník 1"; a text falls back to its first line, the single "Pozadí" is never numbered) — `canvas_layer_labels.js`, pure and node-testable. The ordinal is display-only: blank names stay blank in the canvas JSON, so nothing reaches `imageInputs[].name` or the API. The eye is PERSISTED (`visible: false` rides the canvas JSON): hidden layers vanish from renders/exports/thumbnails, are **excluded from fillable inputs** (`buildVariantPayload` + `TextInputObjectBinder` skip invisible objects — the positional textbox↔input contract counts only VISIBLE textboxes), drop out of container membership at layout time (`collectMembers` skips them; fill-time `hide` still collapses), are filtered from API `containers[].memberInputIds`, and propagate through group sync (`visible` ∈ META_KEYS). Also a per-layer **padlock**, which is the element's OWN popover lock and therefore means two different things (each row's title says which): an image — the background included — flips `editorLocked` (editor lock: click-through, immovable), a text flips `locked` (fill-time: the user can't overwrite it). When the row is the active object it delegates to `canvas-input-properties#toggleLocked` / `canvas-image-properties#toggleEditorLock` (+ `refreshContextToggle`) so the popover checkbox and mini-toolbar stay in step; otherwise it flips the flag directly. Selecting a row opens the popover WITHOUT focusing a field (`openPopoverKeepFocus`) — a focused input makes `handleKeydown` bail, which used to swallow Delete for anything selected from the panel. The eye and the padlock are `filter`ed OUT of the row's SortableJS drag (`preventOnFilter: false`) and the list has `fallbackTolerance: 4`: in `forceFallback` mode Sortable owns the pointer from pointerdown and its default 0 px tolerance turned any sub-pixel wobble of a click into a drag whose onEnd rebuild replaced the row before the click landed (2026-09-03, "the eye sometimes does nothing"). |
| `canvas_floating_toolbar_controller` | The element-anchored floating UX (see below). |
| `canvas_container_controller` | Containers ("smart text areas") — see the dedicated section below. |

**Element opacity (2026-11-08)**

- **Carrier = Fabric's NATIVE `opacity` (0…1)** on the object — no custom
  property, so `canvas_payload.js` serializes it untouched, undo snapshots and
  group sync (`opacity` ∈ `STYLE_KEYS`) carry it, the PHP projector copies it
  verbatim, and every surface that enlivens the canvas JSON paints it: the
  headless render (incl. the rich-text block stack, whose children inherit the
  replaced object's opacity), the fill page's echo canvas (enlivens the
  designed textbox verbatim) and — the one client-side picture that is NOT the
  designed object — the interactive image-fill canvas, which reads the slot's
  `opacity` from `AbstractVariantFiller::imagePlaceholders()` and applies it in
  `_replaceObject` to the stand-in AND the user's pick. Server-side the fill
  never resets it (`ImagePlacement` merges src + placement only).
  `ObjectOpacityRenderTest` (group `gotenberg`) pins text + filled placeholder.
- **Admin-only design property**: no fill-page control, no API field. Offered
  on text + image (shapes have had their own slider in the shape popover since
  2026-08-11); NEVER on the background layer (`isBackground` — the row hides,
  the multi bar skips it) and not on containers.
- **UI**: `canvas-opacity` targets `row` / `slider` / `value`, one set per
  surface (text popover, image popover, multi bar). `input` repaints live,
  `change` fires the synthetic `object:modified` = ONE undo step. In a
  multi-selection the value goes to each MEMBER, never the ActiveSelection
  (its own opacity would only tint the transient group). Mixed values read
  "různá".
- **Collisions checked**: suppression everywhere (slices, print passes, echo
  base `transparentTextInputIds`) overwrites `opacity` to 0 on a COPY of the
  canvas; layer visibility is `visible`; pasteboard dimming is painted on the
  bitmap — none of them read or write the designed opacity.
- **Print PDF**: the raster passes flatten transparency in the browser, so the
  PDF/X-1a file holds no live transparency. A TRANSLUCENT black shape
  overprints (K tint over the picture) instead of knocking out — see
  `BlackInkObjects` in `templates-render.md`.
- **MCP**: `text` and `image` elements take `opacity` (fraction, default 1);
  the compiler emits the key only when < 1 so older designs recompile
  byte-identically, the decompiler reads it back loss-free (only a background's
  opacity is still a `style_dropped` loss).

**Image size & position — "Velikost a poloha" (2026-11-09)**

The admin twin of the fill page's image-adjust panel (`_fill_image_adjust.html.twig`,
templates-image-placeholders.md "Image adjust"): a section in the IMAGE popover
(`.ed-geo`, `_editor_stage.html.twig`) driven by `canvas-image-properties`, pure
math in `canvas_geometry.js` (node-testable; unit helpers re-exported from
`image_placement.js`).

- **Controls**: the gallery `.sz-ctl` size slider + "NN %", Š / 🔗 / V, X / Y,
  rotation (°), Vyplnit / Přizpůsobit / Vycentrovat, plus a hint line
  (placeholder: "rozměr rámečku…", editor-locked: "odemkněte"). Typed fields are
  `type=text inputmode=decimal` (a Czech decimal comma works); they apply on
  `change` / Enter (Enter `preventDefault`s — the editor sits in the save
  form), ↑/↓ step one display unit (Shift ×10), a focused field is never
  overwritten by a sync.
- **Numbers = the UNROTATED displayed box** (`width × |scaleX|`, top-left =
  centre − size/2, trim-relative scene coords) in the variant's unit — px, or
  mm / cm at 300 DPI (`pxPerUnit`). The unit is the canvas-editor `unit` value
  (`data-canvas-editor-unit-value`); the group editor switches it per tab via
  `setDesignSize(w, h, bleed, unit)` from `variantsData[].unit`.
- **Slider: 100 % = Přizpůsobit = contained in the TRIM** (the fill page's
  100 % = contained in its frame; in the editor the frame is the canvas).
  LOGARITHMIC (0…1000 positions) between 1 % and max(400 %, 2× cover), both
  widened to include the current size — a logo at 5 % and a full-bleed photo
  at 120 % live on the same canvas. Zoom is about the CENTRE, from the size at
  drag start (no drift accumulation).
- **Typed Š / V anchor at the TOP-LEFT** (X / Y stay true, Figma convention);
  the 🔗 aspect lock is ON for every newly selected image and keeps the box's
  CURRENT ratio (a stretched image stays stretched); off = one axis only.
  Rotation is about the centre and normalised to (-180, 180].
- **Vyplnit** covers the BLEED box (a full-bleed picture must reach the cut),
  **Přizpůsobit** contains in the trim — both centre and restore proportions;
  **Vycentrovat** centres on the trim. Rotation is kept.
- **Writes only native Fabric geometry** (`scaleX/scaleY/angle` +
  `setPositionByOrigin` centre) — the same thing the handles write, so no
  canvas-JSON key, no MCP DSL mirror, group sync / containers / layers see an
  ordinary resize.
- **Undo contract**: slider `input` only repaints (+ `canvas-image-properties:geometry-live`
  → `canvas-floating-toolbar#reposition` so the mini-toolbar follows) and calls
  NO `markUnsaved` — the group editor turns every `dirty` into a debounced
  history push, so a slow drag would land several undo points. Slider
  `change`, each typed value / arrow step and each button = `markUnsaved` +
  ONE synthetic `object:modified`. Verified: single editor undo restores the
  exact pre-drag box; the group editor's undo too (note: group undo restores
  the snapshot's `activeVariantId`, so after a tab switch it can jump back to
  the tab the previous snapshot was taken on — pre-existing).
- **Popover placement**: the image popover now sits BESIDE the element like the
  text / shape ones (it used to drop down from the toolbar), and the first use
  of a size control PINS it (`pinPopover`, popover_drag.js) — re-anchoring on
  every slider tick would pull the slider out from under the pointer.
- **Excluded**: the background layer (`isBackground` — automatic bleed cover,
  `recoverForBleed` relies on it) hides the section; an `editorLocked` image
  shows it disabled. Text and shapes have no exact-geometry fields (a textbox
  resizes by width + reflow, a different contract).

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
  `shapeKind` is a custom prop carried so the layers panel can name the row
  (a čára and a čtverec are both a `Rect`) — and, since 2026-11, so a STYLED
  čára can draw itself (next bullet).
- **Dotted / dashed čára = the LINE is dotted, not its outline** (2026-11,
  the IDML "Canned Dotted" caterpillar). Fabric dashes a Rect's border, i.e.
  the path traced AROUND the bar: on a 4 px bar with a 4 px dotted border that
  was two rows of overlapping dots over a solid bar. The shared classic
  patch `assets/editor/fabric_line_style.js` (`WBoostFabricLineStyle.enable(Rect)`)
  overrides `Rect._render` for `shapeKind === 'line'` + a non-empty
  `strokeDashArray`: it draws the bar's BODY as round dots (diameter = the
  bar's displayed short side, centre spacing ≈ 3× = a two-dot gap) or 3 : 2
  dashes, fitted so the first and last sit flush with the ends, painted with
  the line's fill (border colour if fill-less); the border itself is not
  drawn. Only the STYLE is read off the array (first entry 0 = dotted, as
  `dashStyleOf` / `CanvasShapeStroke::fromDashArray`) — the numbers are
  ignored, so pre-fix lines with a border-width pattern, resized lines and
  group-projected copies all draw right without a data repair. Geometry is in
  displayed units (scale undone) so dots stay round. Loaded on TWO surfaces:
  the editor (`_editor_scripts.html.twig`, enabled at import in
  `canvas_shapes.js`) and the render template (inlined by
  `TemplateVariantImageRenderer`, part of its asset fingerprint) — every
  export (PNG/WebP, print PDF raster, MCP preview) goes through the latter.
  The fill pages never draw shapes client-side. For a čára the editor's
  `_applyDashStyle` and `CanvasShapeStroke::dashArrayFor()` write the pattern
  at the line's thickness (`[0, 3t]` / `[3t, 2t]`); every other kind keeps
  dashing its BORDER by stroke width (`[0, 2w]` / `[3w, 2w]`). Gotenberg-pinned
  by `tests/Golden/DottedLineRenderTest.php`.
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

**Hex colour picker (`color_picker_controller.js`, 2026-11-08).** There is NO
native `<input type="color">` in the admin editor any more — Safari's native
picker has no hex field, so a brand hex could not be typed or pasted. Every
free-colour affordance (text colour, shape solid fill, every gradient stop,
shape stroke, the "Vzorový text" WYSIWYG run colour) is an
`<input type="button" data-controller="color-picker">` whose `value` is the
`#rrggbb`. Contract that kept the owning controllers untouched:

- The controller emits exactly what the native input did: it writes the
  trigger's `value` and dispatches a bubbling **`input`** (rAF-throttled while
  dragging / on every valid keystroke in the HEX field) and ONE **`change`**
  when the popover closes with a different colour (outside click, Enter,
  "Hotovo"). **Escape reverts** to the opening colour (an `input` back, no
  `change`). Owners sync it with a plain `trigger.value = hex` — on an input
  of type button that reflects to the `value` attribute, which the controller
  observes to repaint `paint`ed triggers (gradient stops show their colour; the
  rainbow "custom" chips keep their ring).
- `change` = the undo step: `canvas-text-toolbar#commitColor` /
  `canvas-shape-properties#commitColor` fire `object:modified` (fill + stroke;
  gradient stops already landed history on `change`). Before this, a free
  colour pick landed NO undo point of its own.
- HEX field accepts `#RRGGBB`, `RRGGBB`, `#RGB`, `RGB` (`parseHex`); partial
  input is ignored silently, an invalid value on Enter/blur marks the field
  `is-invalid` + an error line and keeps the last valid colour.
- One shared popover, `position: fixed` + viewport clamp, appended to `<body>`
  — or to the trigger's `.modal` (Bootstrap's focus trap would steal focus
  from the HEX field otherwise). Its keydown/click/mousedown stop propagating,
  so the editor's window shortcuts (Delete, arrows, ⌘Z), the floating
  popover's Escape and the fill overlay's outside-click never see them.
- **Rich-text selection**: focusing the HEX field moves the document
  selection out of the contenteditable, and `rich_text_editor#applyCustomColor`
  colours the LIVE selection (whole text when collapsed). The picker saves the
  contenteditable range on open and restores it right before `change`.
- Quick swatches = `swatches` value, else the editor's project palette
  (`data-canvas-editor-brand-colors-value` on an ancestor) — palette as the
  colour source of truth.
- Krytí / Pozice of gradient stops stay separate fields; the picker only
  handles RGB.
- **Fill pages too (2026-11-09)**: the WYSIWYG free colour
  (`_fill_text_popover`) and a recolourable shape's free colour
  (`_fill_shape_popover`) — single, group and document fill all include these
  partials. `variant_fill_overlay#pickShapeColor` recognises the picker by
  `data-shape-picker` (it used to test `type === "color"`). No other native
  colour input exists in wboost (forms take hex as text); the only remaining
  one is GrapesJS's own UI in the vendored e-mail-signature editor.

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

**Transform handles (`canvas_handles.js`, 2026-11-01).** The selection border
and handles are an **SVG layer in the unscaled `.canvas-stage`** (z 998), not
Fabric's drawing: Fabric paints them on the CSS-`scale()`-zoomed canvas (they
shrank to 2–3 screen px at a print auto-fit) and clips them at the element
edge (an object hanging past the pasteboard lost its handles). Fabric's own
chrome is off via `canvas.skipControlsDrawing` (Fabric saves/restores it around
`toCanvasElement`); `after:render` repaints the SVG from `obj.oCoords`
(viewport px → stage px through the live element rect; multi-selection
members via `getCoords()` × viewport, dashed). Contracts:

- **Interaction stays Fabric's.** A press on an SVG handle is re-dispatched as
  a `mousedown` on `upperCanvasEl` at the same client point; Fabric's pointer
  math has no bounds check, so `findControl` resolves the control even outside
  the element and its document-level move/up listeners run the transform
  (snapping, events, undo unchanged). While `_currentTransform` is set,
  `html.canvas-transforming` pins the transform cursor page-wide.
- **Moving from outside the element**: a second SVG (`.canvas-handles--body`,
  z 950 — below the rulers so guides can still be dragged out of a ruler the
  object covers) holds a transparent copy of the active object's box, CLIPPED
  (evenodd `clipPath`) to the part outside the canvas element; a press there
  is forwarded the same way and Fabric starts a normal move. Inside the
  element it is clipped away on purpose, so Fabric keeps hover, dblclick and
  text editing natively. Only the ACTIVE object gets a grip — an unselected
  object outside the element is reached via the layers panel.
- **Hit areas are Fabric's control polygons**, recomputed per frame for the
  ACTIVE object (`applyHitAreas` in `before:render`; only the active object
  hit-tests): corners `cornerHitFor(minSide)` = 10–24 screen px (shrinks on
  small objects), side handles a 20 px strip along the WHOLE edge between the
  corners. Fabric tests controls in reverse order (rotate, corners, then
  sides), so fixed 24 px corners used to swallow the side handles of a 30 px
  tall box.
- **Cursors** come from the control's nominal axis + object angle
  (`scaleCursor` → two-way `ew/ns/nwse/nesw`). Fabric 7 derives them from the
  centre→handle direction, so the corner of a wide box read `w-resize`.
- **Textboxes** keep only `ml`/`mr`: their `lockScalingX` (width-only resize,
  `applyTextboxDefaults`) made Fabric paint `not-allowed` over the working
  width handles, and the corner/top/bottom/rotate handles were dead. While a
  text is being edited Fabric clears `hasControls` → only its border shows.
- **Textbox width drag is TOP-anchored** (`anchorWidthHandleTop`, 2026-11):
  Fabric anchors a side handle at the opposite edge's vertical CENTRE
  (transform `originY` 'center' / 0.5), so every re-wrap (line-count change)
  grew the box around its middle — the top jumped half a line per wrap and a
  container then re-flowed/re-ordered it on release. The `ml`/`mr` action
  handlers of every textbox run with `originY: 'top'` (originX untouched →
  Alt stays symmetric): opposite edge + top fixed, height grows down.
- **Undo snapshots run in a microtask** (`canvas_history_controller`): the
  container controller normalizes the flow on `object:modified` AFTER the
  history listener, so a synchronous snapshot stored pre-normalization
  positions and undo/redo restored a layout never shown on screen.
- Do not set `cornerSize`/`cornerStyle` on created objects — overwritten anyway.

**Editor chrome layout (Nocturne, 2026-11-02 — P7 of the redesign).** Both
editors render, inside the `.editor-shell` root, an `.ed-head`
(`data-editor-header` — the floating toolbar keeps chrome below it, the stacked
zoom fit subtracts it) with three bars, then the `.editor-shell-row` (left
panel 264 px | stage). Styling lives in `assets/styles/nocturne/editor.css`
(loaded after app.css, chrome only — canvas bitmap, SVG handles, guides,
outlines and zones keep their colours).

- **Top bar** `.ed-top`: back, eyebrow `.breadcrumb.ed-crumb` (project · Šablony
  [· document · Strana N] — `TemplateDocumentPagesControllerTest` reads
  `.breadcrumb`), `h1.ed-name`, undo/redo, the lavender **clean-preview eye**
  (`editor-chrome#toggleClean` → `.ed-is-clean` on the shell hides outlines,
  zones, rulers, guides, bleed guide via CSS; nothing persisted), save state
  ("Vše uloženo" is the CSS complement of canvas-editor's
  `unsavedChangesMessage` badge), "Zobrazení", "Vyplnit a exportovat", Save.
- **Format tabs** `.ed-vars`: single editor = the template's ungrouped
  variants as links (+ "Přidat rozměr" → add_template_variant), or the
  document page strip (`document-page-strip`) on a document page; group
  editor = the variant rail itself (`railCard`/`rail`/`card`/`includeToggle`/
  `badge`/`dirtyDot` targets unchanged) + "Přidat rozměr" + the multi-edit
  button styled as the "Upravovat všechny formáty najednou" switch
  (`aria-pressed`; the controller's btn-primary toggling is neutralised).
- **Tool row** `editor/_editor_tools.html.twig`: text, image, shape picker,
  checklist, Pozadí, Nápověda (#editorHelpModal) + the zoom group
  (`zoomOutButton`/`scaleDisplay`/`zoomInButton`). These used to be the left
  panel's buttons — same actions, same controller root.
- **Left panel** `_editor_left_panel.html.twig`: "Vrstvy n / Kontejnery n"
  tabs are two radios + `:has()` (no JS) and the counts are CSS counters over
  the controller-built rows (the tab strip comes after the lists in the DOM
  and is moved up with `order`; the hidden pane uses `visibility`, not
  `display`, so it still counts). Layer rows use mask icons and a lavender
  **client-input dot** (`canvas-layer-row__input`: unlocked text, image
  placeholder, recolourable shape).
- **Inspector** = the existing floating popovers restyled (`.ed-insp`): header
  tile, "Vyplňuje klient" switch card (image: the placeholder checkbox itself;
  text: `clientFills`, an inverted proxy of `locked` kept in step by
  canvas-input-properties — the "Uzamčeno" checkbox stays in the DOM, hidden),
  section headings, Duplikovat / Smazat footer. Popover positioning and
  `openPopover`'s first-field focus are unchanged.
- **Rulers** read `--ruler-off/-bg/-tick/-text/-font` from the bar element
  (editor.css sets them per theme) and repaint on a `data-theme` change.

**Overlay chrome toggles** (`templates/editor/_editor_toggles.html.twig`, the
top bar shared by the single-variant and the group editor). They live behind
ONE button ("Zobrazení") as a Bootstrap dropdown with
`data-bs-auto-close="outside"`, styled as the prototype's check menu (each
checkbox is restyled into the ✓ mark; still a real `<input role="switch">`).
The menu stays in the DOM while closed, which is
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
| `#navigator-visible-control` | editor-chrome `toggleNavigator` | the navigator panel (`.ed-nav-off`; hidden < 768 px anyway) |

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

**Pasteboard (2026-11-01) — `canvas_viewport.js`**

Around the bleed box the element carries a further `pasteboardFor(w, h)` =
12 % of the design's longer edge on every side (`canvas.wboostPasteboard`), so
handles of objects flush with or hanging over the design edge are drawn and
grabbable, and the overhang stays visible. `applyBleed` sizes it and shifts the
viewport by `viewOffset()` = bleed + pasteboard; everything below that says
"bleed shift" is really this offset (`designWidth`, `trimOriginRect`, the
thumbnail crop all use `viewOffset`; `bleedOf` stays the BLEED alone — covers,
the bleed guide, the snap line). Contracts:

- The dim (and the bleed band tint) is painted ON THE BITMAP in Fabric's
  overlay pass (`patchPasteboardPaint`). A DOM mask sat above Fabric's
  lower-canvas handles and washed them out — moot since the handles moved to
  the SVG stage layer, but the bitmap dim is also what keeps the DOM chrome
  stack simple. The save
  thumbnail crops to the trim, so neither ever reaches a preview.
- Ruler bars hug the ELEMENT edge, not the trim (a bar flush with the trim
  sat right over the handles); 0 stays at the trim corner, the pasteboard
  reads negative / past the design length on a darker strip.
- `canvas_zoom_controller` re-fits on a `ResizeObserver` of the wrapper: the
  element grows in `canvas-editor#connect` after the zoom may have fitted it,
  and an empty canvas fires no `canvas:loaded`. Auto-fit therefore includes the
  pasteboard (the design shows ~20 % smaller than before).
- The navigator minimap draws the whole element, dim included.

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
- The zone's LINES are a DOM overlay inside Fabric's container
  (`.canvas-bleed-guide` over the bleed box: red outline = bleed edge, trim
  outline); line widths divide by the zoom (`--bleed-line`). The band tint is
  painted on the bitmap under the handles (see Pasteboard). The
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

### Image popover — "Složky galerie pro uživatele" folder tree (2026-11-09)

The placeholder's folder allow-list renders as the gallery's folder TREE
(recursive `editor/_image_folder_branch.html.twig`, chevron disclosure, guide
lines) instead of a flat list. Ticking a folder opens its whole sub-tree
server-side, so descendants show ticked + disabled ("zahrnuto"); only the
top-most ticked folders are stored. Contract, restore-on-untick memory and
expansion rules: `templates-image-placeholders.md` ("Admin editor tree").
