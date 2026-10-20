# Templates — user fill pages + client-side text echo

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
  (thumb + picker button + eye) — the SAME editor instances, no second copy of any field, floating
  chrome (`.fill-popover__chrome`: grip / Uložit / ×) hidden, the panel-only
  `.fill-popover__eye` shown. z-order 1041: above the page, below the
  pickers (`.fill-modal` 1300, Bootstrap 1055). The single page's card thumb
  follows `variant-image-fill:picked` ({inputId, url}, also fired on a
  version restore).
- **Group image placement opens FROM THE PICTURE (2026-10-11)**, not from
  the panel: each dimension's `.fill-surface` carries one hidden
  `.group-fill-placement-toolbar` (target `placementToolbar`) per adjustable
  slot whose frame exists in THAT dimension — zoom / rotation ranges (only
  what the slot allows), "Vycentrovat", ×. Selecting the picture's stand-in
  (`.group-fill-ghost`, pointerdown or focus) opens that one toolbar,
  anchored under the frame (flips above, last resort inside the bottom
  edge) by `_positionToolbars`, which every `renderGhosts` re-runs; a press
  outside ghost + toolbar, Escape or × closes it, and it hides while the
  picture is dragged. Stand-ins exist only for PICKED pictures, so the
  designed picture never gets one (`ResolveImageOverrides` 400s a transform
  without `imageId`). The `imagePlacements[<variantId>][<inputId>][…]`
  hidden fields sit with the text mirrors at the top of the form, one per
  variant × adjustable slot × allowed field. History: rows on the cards
  (c175859) → beside the picture in the old side panel (5de549a) → buried
  behind "Všechna pole" by the 2026-09-03 unification (users stopped finding
  them) → an always-visible strip under each preview (260f9cd, rejected:
  a pick button there made no sense) → this toolbar.
- **Panel field order is admin-arranged (2026-10-11).** Both panels render
  text popovers and image cards as ONE sequence (`panel_fields` /
  `panelFields` → `arrange_fill_fields` Twig filter → `FillFieldOrder`) in the
  order stored on `Template::$fillFieldOrder` (JSONB list of inputIds; the
  group reads its 1:1 template via `GetTemplateGroupMembers::template()`).
  Unlisted ids (fields added later) follow in natural order, stale ids are
  ignored — nothing is ever dropped. ROLE_ADMIN viewers get
  `fill_field_order_controller.js` on the `.fill-popovers` container (SortableJS,
  `forceFallback` + `fallbackOnBody` — the panel is `transform`ed) and a
  `[data-fill-field-order-handle]` per card (panel mode only); a drop POSTs
  `{order}` to `template_fill_field_order` (`ArrangeTemplateFillFields`).
  Nothing may look popovers up by DOM position — only by `data-inputid`.
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
  (`AbstractVariantFiller::fillStateHash`) and JS
  (`assets/controllers/fill_state_hash.js`, shared with the overlay) are
  pinned byte-identical by test (Czech diacritics covered).
- **"A settle landed" = Live's `render:finished` hook (2026-09-08)**, wired
  through `assets/controllers/live_component.js` in BOTH the echo controller
  and `variant_fill_overlay_controller` (the render veil); the
  MutationObservers on the source span stay as the belt. A `data-src`
  mutation alone is NOT a settle signal: on the image branch the typed text
  usually sits in an overlay slice, so the backdrop's bytes come back
  identical and the morph touches no attribute — the veil then sat until its
  20 s safety net (the "stuck on the spinner" prod report; same for a text
  typed and deleted again). The veil is gated on the SAME hash rule as the
  echo's rest (`_settleRendered`): a stale settle keeps it, `response:error`
  drops it. Two CSS traps found with it: the transparent-wrapper
  checkerboard must exclude `.fill-echo-canvas` (a StaticCanvas carries
  `lower-canvas` too and is a direct child of the same wrapper — with the
  checkerboard it was an opaque sheet over the whole preview while typing),
  and `.fill-echo-active .fill-spinner` needs `.is-active` to outrank the
  later, equal-specificity `.fill-spinner.is-active`.
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
- **Recolourable shapes (2026-10-20)** — the third field kind next to text
  and image. `Services/SocialNetwork/FillShapePlaceholders` (shared by both
  pages) builds one row per `ShapeColorInput` (de-duplicated by inputId over
  a group's members) + per-dimension `frames()`; each row gets an amber
  `fill-box--shape` box (palette button → `openPopover`) and a
  `_fill_shape_popover.html.twig` card (also a "Všechna pole" row, ordered by
  `fillFieldOrder` like the rest): the designed colour first (writes "" = no
  override), then the admin's list, plus a free `<input type=color>` when the
  shape allows any colour. `variant-fill-overlay#pickShapeColor` writes the
  `[data-shape-color-mirror]` field — Live-bound `shapeColorValues[…]`
  (debounce 400) on the single page, a plain field with
  `input->group-fill#changed` on the group page — and marks the swatch. No
  client echo: a colour is a server render (spinner). State hash: `S:<id>=`
  lines after the fonts in BOTH `fillStateHash()` and `fill_state_hash.js`.
  Known v1 gap: a recolourable shape that is a CONTAINER member keeps its box
  at the designed position (the overlay reflow moves texts only).
