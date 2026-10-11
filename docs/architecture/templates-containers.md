# Templates — containers (smart text areas)

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
