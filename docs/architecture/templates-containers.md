# Templates — containers (smart text areas)

**Containers — smart text areas (document-like vertical reflow)**

A container groups members into a vertical flow: at render time a filled text
that wraps to more lines pushes the flow items below it down, hidden items
collapse (take no space), and the flow of a TOP-LEVEL container is bounded by
a designer-set `maxHeight` (from the first item's designed top downward). Each
member stays an ordinary independent input. Since the 2026-08 nesting rework:

- **Members** are texts, DECORATIVE images, shapes, FILLABLE IMAGE SLOTS
  (since 2026-11, plan P2a — see "Image slots as members" below) and CHILD
  CONTAINERS. A non-text member (never the background layer) that vertically
  overlaps a text/child item becomes its ATTACHMENT — fixed offset, rides
  along, force-hidden when the item collapses (checklist icon ↔ its line,
  club logo ↔ its row); a non-overlapping one flows standalone (separator,
  photo). Texts/children are each their own flow item — designed gaps
  between items are preserved (negative ones included: overlapping texts
  keep their designed overlap).
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
  multi-select bar accepts 2+ texts/images (decorative or fillable slots)/shapes — and selected objects
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
- **One pencil per container (2026-11-09)**: on the fill pages a top-level
  container with ≥ 2 editable fields in its tree is ONE box + pencil opening
  a "container sheet" (its fields, nested containers as sections, the
  container + member hide eyes) — `FillContainerSheets`, full contract in
  templates-fill.md "One pencil per container". The pencil level (top-level
  vs per row) is the single constant `FillContainerSheets::PENCIL_LEVEL`.
- **Safety net for repeatable rows (2026-11-09, plan P0)**: the two prod
  fixtures (`tests/Fixtures/containers/`: "Výsledky turnaje" — a root of
  five nested 2-field rows; "Výsledek" — two columns of four single
  scorers) are pinned by `tests/Golden/ContainerFixtureLayoutTest`
  (`layoutData()` + box frames byte-for-byte, and the REAL engine run in
  node through `layout-harness.cjs`: designed / wrapped / hidden / overflow
  scenarios; regenerate with `UPDATE_GOLDEN=1`) and the invariant rows will
  rest on — a HIDDEN row lays out (engine) and renders (Gotenberg,
  `ContainerFixtureRenderTest`, group `gotenberg`) exactly like the same
  design WITHOUT that row.
- **API listing**: `variants[].containers[]` `{id, maxHeight, y,
  memberInputIds, memberContainerIds, gap, nested}` + per-input `containerId`
  and `textStyle {fontFamily, fontSize, lineHeight, charSpacing}` so
  consumers can mirror the reflow (`y` = highest designed member in the
  container's tree; decorative members are deliberately NOT exposed — the
  server preview is authoritative for them); `frame` stays the DESIGNED
  position. The mfkfm backoffice consumes this (zones + structured-400
  highlighting, no client reflow — its preview IS the server render).

**Image slots as members (2026-11-09, container-rows plan P2a)** — a fillable
image placeholder can join a container (Jan: a club logo per result row, the
prerequisite of repeatable rows). It flows exactly like a decorative image:
a FIXED box (the designer's frame), attachment of the text it overlaps or a
standalone item, collapsing when hidden. The ONLY engine change is the type
filter (`isImageSlotObject` joins `isDecorationObject` → `isMemberCandidate`;
the background slot stays out). No stored design had a slot member (checked
local + prod: before P2a the editor sanitizer and the MCP compiler stripped
them), so every existing layout is unchanged — the P0 goldens did not move.
Per surface:

- **Render** (`template_variant_render.html.twig`): a FILLED slot's object is
  the picture (ImagePlacement: centre origin, the user's zoom/pan/rotation)
  with the frame as an `absolutePositioned` clipPath. The engine is handed a
  **frame proxy** instead (`slotFrameProxy`: geometry = the clip rect; a top
  write translates picture AND clip by the same delta — the placement is
  frame-relative, so the picture stays where the user put it inside the
  moved frame; `visible` reads/writes the picture). Stand-ins need nothing.
- **Hidden member slot = fill-time collapse.** A pre-load `visible: false`
  reads as DESIGN-hidden to the engine (no anchor, no gap = "absent"), which
  differs from a hidden text under designed gaps. So for a slot that is a
  container member `applyImagePlaceholders` keeps the designed box with a
  transparent stub src and the renderer adds it to `hidden_overrides`
  (`hiddenMemberSlots`) — hidden after phase A, like a text. Non-member slots
  keep the old `visible: false`.
- **Overlay / echo / harness** get the slot through `layoutData().decorations`
  with `slot: true` (only on slot entries — designs without slot members ship
  byte-identical data). The overlay feeds them as POJOs, reads back the final
  top / visibility and publishes per surface `{dy, collapsed}` to the page's
  picture owner (`slotsLaidOut()`): the single page's
  `variant_image_fill_controller` translates the live picture + its clip and
  places everything against the LIVE frame (`_frame()`); the group / document
  page's `group_fill_controller` shifts its ghost + toolbar frame
  (`_frame(variantId, inputId)`; a collapsed slot has no ghost). Stored
  placement fields are frame-relative and never rewritten by a move.
- **Single-page backdrop**: the slices hide every placeholder by opacity
  anyway; a FLOWING slot is no longer `hidden` there (that collapsed the
  texts around it), unless the user hid it — mirrored into Live by an
  UNNAMED `hiddenValues[<slotId>]` checkbox (`data-slot-hide-mirror`,
  `flowingSlotHideIds()`; the POST still carries `images[<id>][hide]`, so
  ExportFillValues / version hashes see nothing new; toggleHide and undo/redo
  flip it with the picture checkbox).
- **Echo**: a tree holding a slot is not "clean" (`EchoCapableTextInputs`
  container guard — the slot is not an echo-capable text), so its texts stay
  settle-rendered; no echo change.
- **Editor**: automatic via `isMemberCandidate` — "Vytvořit kontejner" accepts
  slots (a text is still required), the image popover shows the "Kontejner"
  section, `sanitizedContainers` keeps them, group sync / ⧉ duplicate /
  nesting / normalize treat them like decorative images.
- **API / MCP**: `containers[].memberImageInputIds` (direct slot members;
  `memberInputIds` stays text-only), slot frames count for `y`, a slot-only
  tree is a publishable flow item; `imageInputs[].containerId`. DSL:
  `members` may name a placeholder image (parser, compiler, decompiler,
  linter all mirror the engine filter).
- Tests: `ContainerImageSlotLayoutTest` (engine via `layout-harness.cjs`
  `reportObjects`), `ContainerImageSlotRenderTest` (Gotenberg: moved filled
  slot keeps its picture window, stand-in moves, hidden ≡ absent under a
  uniform gap and ≡ a hidden text under designed gaps), API
  `TemplateContainerImageSlotTest`, `ResolvedCanvasContainerTest`.

**Whole-container hide (2026-11-09)** — opt-in per container, the
"bubble + its text" request: the designer ticks "Lze skrýt celý kontejner"
(+ a label) in the ⚙ popover → `hidable: true` / `name` on the definition
(emitted only when set — `sanitizedContainers`, `CanvasContainer::toArray`,
the MCP compiler all omit them otherwise, so untouched JSON stays
byte-identical). Group sync copies both as METADATA (absolute, every
dimension); the server projector keeps unknown keys; the ⧉ duplicate copies
them. Contract, in order of the pipeline:

- **The toggle IS an input hide.** Fill pages render one
  `hiddenValues[<containerId>]` checkbox (`data-hide-mirror` +
  `data-container-hide`) per hidable container (`FillContainerHides`) and a
  "Všechna pole" row (`_fill_container_hide_card.html.twig`, eye →
  `variant-fill-overlay#toggleHide`). Riding the existing `hiddenValues`
  family means the Live prop, the state hash (PHP + `fill_state_hash.js`),
  the download/draft POSTs, `ExportFillValues.hidden` (dedup hash) and the
  version seed (`ExportVersionSeeder` re-seeds it while the container is
  still hidable) all carry it with no new field. The API/MCP use the same
  `inputs` map (`{"<containerId>": {"hide": true}}`, strict validation in
  `ValidateContainerHides`).
- **Resolution:** `ResolveTextOverrides` collects every truthy hide addressed
  to a NON-input id into `ResolvedInputOverrides::$hiddenContainerIds`
  (unvalidated). The renderer keeps only HIDABLE containers of the rendered
  variant and expands them deep (`CanvasContainer::hiddenMemberInputIds` —
  own members + every nested container's) into the per-object
  `hidden_overrides` (`ResolvedInputOverrides::effectiveHidden`). Hence NO
  render-template and NO `container_layout.js` change: the template's
  existing hide loop sets `visible:false` on texts, decorative images and
  shapes alike (all carry an inputId) AFTER phase A, and the engine's
  existing rules collapse them — a nested container whose items are all
  hidden collapses in its parent, a root whose items are all hidden takes no
  flow space (it still anchors at its designed top, so its `spaceAfter`
  clearance behaves exactly like a root whose members were hidden one by
  one). Overflow is recomputed on the collapsed flow (hiding can FIX an
  overflow).
- **Precedence (decided 2026-11-09):** the container hide WINS; the members'
  own hide/text state is untouched, so showing the container restores it.
  A hidden container hides even non-hidable / locked members (the designer
  opted the container in, not each member).
- **Client mirrors:** `layoutData()` and both echo payloads ship
  `containerHides` (hidable container id → deep member ids, the server's
  expansion); the overlay reflow, the single-page echo and the group echo
  treat those members as hidden while the container mirror is checked. A
  container toggle is NOT an echo edit (it also hides decorative pixels the
  echo cannot draw): the single page just repaints an already-active echo,
  the group page invalidates its echo bases like a picture change.
- **Documents:** a hidable container is a per-page FILL KEY
  (`DocumentFillKeys::pageInputIds`, `DocumentPageView` re-keys container
  `id` + `memberContainerIds`), never a shared field.
- **Decoration-only hidable containers** (only images / shapes, no fillable
  text) are published by `ResolvedCanvasContainer::collection()` (REST
  listing + MCP describe_variant) for their toggle: anchored at the highest
  decorative member (`FillTextPlaceholders::decorativeMemberTops`), empty
  `memberInputIds`, and NOT added to the parent's `memberContainerIds` — so
  every non-hidable container and every parent tree publishes exactly what it
  did before. A container with no locatable member at all stays omitted.

**Repeatable rows — the server-side row model (2026-11-09, container-rows plan P2)**
— a NESTED container can be marked as a row the end user repeats ("Skóre +
Soupeř" × N, a column of scorers). P2 is the model only: no editor control
(P3), no fill +/− UI (P4), no API/MCP `rows` (P5), no merge action (P6). With
no container marked, everything is byte/pixel-identical (all P0/P2a goldens
unchanged).

- **Data model.** `"repeat": {"max": 8, "min": 1, "default": 1}` on the ROW's
  definition, emitted only when set (`ContainerRepeat` VO, `CanvasContainer
  ::$repeat`, JS twin `normalizeRepeat` in `canvas_payload.js` — an editor
  save keeps it). Rules: integers `1 ≤ min ≤ default ≤ max ≤ 30` (min
  defaults to 1, default to min; anything else = not repeatable); the row
  must be NESTED (its parent is the flow and the bound), at most ONE
  repeatable child per parent (the first in member order wins), never inside
  another repeatable tree (v1). `CanvasContainer::collectionFromCanvas` and
  `sanitizedContainers` both enforce them by DROPPING `repeat`. A repeatable
  row may be a SINGLE text (the Výsledek columns), and a parent may hold
  nothing but its repeatable row (a list root) — every other container still
  needs 2+ members (a lone child container counts only when it is a valid
  repeatable row).
- **Expansion** (`Services/Template/RepeatableRows::expand()`, pure,
  idempotent, returns the SAME instance for every design without rows): a
  CLONE of the variant with `max` materialised rows — the
  documents' clone-first rule, never persisted (the fill page flushes
  mid-request). Steps: stamp textbox inputIds from the positional binding;
  per row clone its deep member objects (texts, decorations, image slots,
  shapes, nested children) for slots 1…max−1, translated by `k × pitch`
  (`pitch` = row extent + parent `gap` ?? the designed gap to the next item
  below ?? 0) and inserted right after the row's last member; every item
  below the row in its parent — and below the branch in each ancestor —
  moves down `(max−1) × pitch` (designed order + the footer's designed gap
  to the LAST row are kept: the engine measures a gap against the designed
  predecessor, hidden or not); slot definitions get derived ids +
  `repeatSlotOf: <rowId>`, join the parent's `memberContainerIds` after the
  row; the row gets `repeatSlots: [rowId, slot1Id, …]` (the idempotency
  marker and the hide lookup); `inputs[]` is REBUILT in textbox object order
  (the i-th visible textbox ↔ `inputs[i]` invariant holds by construction);
  cloned text / image inputs carry the template row's settings and SAMPLE —
  a new row starts from the template row's sample text (Jan).
- **Derived ids** (`RepeatableRows::slotId`): slot 0 keeps the ORIGINAL ids;
  slot k ≥ 1 = `uuid5(RepeatableRows::SLOT_ID_NAMESPACE, "<rowId>#<k>#<originalId>")`
  for member inputIds, image slots (their `images[<id>]` / placement keys
  follow), shapes, decorations and container ids. Computed only in PHP (the
  markup carries them). The namespace is a fill-state key space — never
  change it (stored versions).
- **Unused rows = hidden rows.** `ResolvedInputOverrides::$rowCounts`
  (`withRowCounts()`) → `effectiveHidden()` adds the deep members of every
  slot ≥ count (`CanvasContainer::inactiveRowMemberIds`; count clamped to
  `[min, max]`, the default when absent — so an API / MCP render without
  counts shows `default` rows). An unused row wins over everything. No
  render-template or engine change: the existing hide loop + collapse rules
  (P0: hidden row ≡ absent row) do it; the canvas is the same for every
  count.
- **Where expansion happens.** Design-time readers never expand (editor, API
  listing, MCP describe/decompile, group projector, font usage). Every
  fill / render / resolve entry point does — the filler's `variantEntity()`
  (memoised; `fillInputs()` feeds the mirror loop), download / publish /
  draft controllers (resolve + render the clone, RECORD against the managed
  entity), `ApiExportRenderer`, `GroupFillRenderer`, the group fill /
  preview / upload controllers, `DocumentPageView::rekeyed` (expands FIRST,
  re-keys `repeatSlots` / `repeatSlotOf` too), `DocumentFillKeys::pageInputIds`,
  `VariantFill` (MCP), `CandidateRenderer`, `PrintPdfRenderer::renderPage`,
  `PlaceholderImageUploader`, the seeders and `ExportVersionApiView` — plus
  the BELT in `TemplateVariantImageRenderer::renderToBytes` (and the test
  fake). `RepeatableRowsEntryPointsTest` scans `src/` for every renderer /
  resolver call site: it expands, or it is listed with the chokepoint it
  delegates to — a new call site fails until reviewed.
- **Fill state.** `rowCounts[<rowId>]` next to the value maps: a writable
  Live prop on the single page (seeded with the defaults, normalised —
  clamped ints, unknown ids dropped — in `PostHydrate`), plain hidden fields
  on the group / document pages (`data-row-count-mirror`,
  `input->group-fill#changed`), parsed by `FillFormRequestParser::parseRowCounts`
  / `RepeatableRows::normalizeCounts`. Row values live in the EXISTING maps
  under the derived ids. State hash: an `R:<rowId>=<count>` group after
  `S:` in BOTH `fillStateHash()` and `fill_state_hash.js`, present only for
  designs with rows (every existing hash unchanged). Overlay:
  `layoutData().repeatRows` (row id → `{min, max, default, slots: [deep
  member ids per slot]}`, only when present) + the mirror →
  `_containerHiddenIds` hides the unused slots like the render. Echo: a tree
  holding a repeatable row is never "clean" (`EchoCapableTextInputs`) — its
  texts settle-render until P4 teaches the painters the row hides.
- **Versions.** `ExportFillValues::$rows` (`withRows()`), emitted only when
  non-empty — existing canonical forms and dedup hashes unchanged (pinned in
  `ExportFillValuesTest`). The seeders expand and return `rowCounts`
  clamped to the CURRENT limits (defaults for a version without counts).
- **Groups:** ONE shared count per row for every dimension (shared ids —
  group sync keeps `repeat` identical). "Can add a row":
  `RepeatableRows::canAddRow($members, $rowId, $count)` — below `max` AND
  within every member's `designedCapacity()` (the SMALLEST dimension
  decides). Capacity is the largest count whose root neither exceeds its
  `maxHeight` nor runs past the page bottom on DESIGNED geometry
  (`DesignedContainerFlow`, a PHP twin of the engine without text growth,
  parity-pinned against the node harness) — the server's estimate for P4's
  gate and P3's "fits N" prefill; the fill page measures real wraps.
- **Documents:** rows are PER PAGE — the row container id and every derived
  id are fill keys (`pageInputIds`), `valuesForPage` translates `rowCounts`;
  a repeatable row's members are never bindable to a shared field
  (`TemplateDocumentRules::isBindable`, the field picker filters them).
- **Seam for M2 (user-movable offsets):** expansion happens BEFORE anything
  in `buildCanvasJson`, so the offset bake there sees the materialised rows;
  rows are nested (never movable themselves), a moved top-level parent
  carries every slot. Hash order: `T H F S R O`.
- **P4 notes:** `FillContainerHides` / `FillContainerSheets` currently list
  every materialised slot (a hidable row gives max toggles; the sheet shows
  max sections) — P4 groups them as rows and hides inactive ones; drop the
  echo guard once the painters read `repeatRows`.
- Tests: `RepeatableRowsTest` (expansion, ids, inputs order with a
  design-hidden textbox + rows mid-`objects[]`, hides, validation),
  `ContainerRowsLayoutTest` (node engine: 1…max rows ≡ the hand-numbered
  designs; designed-flow parity; capacity + the group gate),
  `ContainerRowsRenderTest` (Gotenberg: 5 / 3 repeated Turnaj rows ≡ the prod
  hand design; scorers ≡ hand columns), `RepeatableRowsFillTest` (single /
  group / document pages, hash parity, versions, stored design untouched),
  `RepeatableRowsEntryPointsTest`.
