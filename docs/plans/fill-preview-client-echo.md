# Fill preview: client-side echo, server truth at rest

Status: **proposed** (Phase 0 shipped 2026-08-31; this document is Phase 1,
awaiting review before implementation).

## Why

The fill pages render the live preview through Gotenberg on every debounced
edit. The cost curve is structural: `renders ∝ typing × dimensions × users`,
each render seconds of wall time, synchronously inside HTTP requests. Every
tuning knob (debounce, coalescing, queueing, a bigger renderer) only moves
the cliff — preview latency stays "seconds + queue" and one active user can
occupy most of the renderer. The 2026-08-31 MaxExecutionTimeError burst
(Sentry WEB-2B/2C/2E) was one user typing on a group fill page.

Phase 0 (shipped) stopped the fatals: no session locking (LOCK_NONE) +
early session save on render routes, `set_time_limit` reset per render,
`loading="defer"` on the fill component, single-flight/bounded-concurrency
previews on the group page. Demand is still per-edit, though.

The real fix changes the curve: **typing must cost zero server renders.**

## The WYSIWYG constraint (hard requirement)

What the user sees at rest, and what they export, MUST be the server render
— the API consumers' pixels and the web preview may never disagree. A pure
client-side preview cannot guarantee bit-level identity: Fabric measures
text via the browser's `measureText`, and Safari/Core Text vs headless
Chromium/HarfBuzz can differ by accumulated sub-pixel advances — enough, at
a wrap boundary, to flip a word to the next line. (The codebase already
bets on this same cross-engine agreement for overlay geometry and the
client-side export-overflow gate, but a painted preview raises the stakes.)

Hence the hybrid: the client layer is only the **optimistic in-between
frames** while typing; a lazily debounced **server render remains the
displayed truth at rest** and is the only thing anyone exports. At every
moment the user pauses to inspect, they are looking at server pixels.

## Target architecture (single-variant fill page first)

1. **Backdrop renders with non-locked text transparent.** Locked inputs and
   design text stay baked in. The backdrop thereby becomes independent of
   every text/rich-text/hide override, so the existing slice cache
   (`overrideIndependentCacheKey`) can hold it: during a typing session it
   renders once per (canvas version, image picks/placements) and then serves
   from Redis. Same for the overlay slices, which today re-render per edit
   whenever they contain a bound input.
2. **A transparent Fabric canvas over the preview draws the fillable text**
   — the client echo. It reuses what the overlay already loads: the
   committed Fabric bundle, explicit project fonts, `fabric_break_word.js`,
   `rich_text_runs.js`, `rich_text_blocks.js`, `container_layout.js`. The
   overlay already BUILDS offscreen textboxes with the user's values to
   measure heights; the echo upgrades them to visible objects positioned by
   the same reflow pass, scaled to the displayed preview.
3. **Settle render.** A long debounce (~2-3s idle — affordable now that it
   is not the only feedback) POSTs the current values and swaps the full
   server render in under the echo; the echo layer clears. Export flow
   unchanged (strict server render).
4. **The per-keystroke Live round-trip disappears.** Text values stay in
   the mirror fields until the settle render / form POST — no more multi-MB
   base64 WebP in Live responses.

## Work items

- **Renderer**: a `transparentTextInputs` mode in `buildCanvasJson()`
  (non-locked bound textboxes → `opacity: 0`, layout influence kept — the
  `sliceCanvas` precedent); extend the cache-key proof to cover it.
- **Marker construction module**: the list/checkbox bullet drawing (block
  stacks, drawn checkbox Rect+Path, bullet images) currently lives in
  `template_variant_render.html.twig` inline JS. Extract into a fourth
  shared classic script so the echo draws identical markers.
- **Value-resolution mirror**: JS twin of `ResolveTextOverrides` semantics
  the echo needs — sample fallback, explicit `''` suppression,
  truncate-then-uppercase per run (grapheme-safe via `Intl.Segmenter`),
  hide. Locked inputs are backdrop, not echo. PHP↔JS contract tests over
  shared fixture values.
- **Z-order guard**: a bound text with design content stacked ABOVE it
  cannot be echoed on top without breaking occlusion. Detect per input
  server-side (any higher-index overlapping object) and ship a flag in
  `textLayoutData()`; such inputs keep today's behavior (server-only,
  settle render updates them). Expected rare.
- **Echo layer controller**: sibling of `variant_fill_overlay_controller`
  sharing its offscreen boxes; repaint on `input` events (no debounce —
  local canvas work); hide echo for an input while its popover WYSIWYG
  shows unstyled intermediate states? (No — runs apply live already.)
- **Settle scheduling**: reuse the single-flight discipline from
  `group_fill_controller.js`; on settle-response swap, drop the echo only
  if no edit happened since the request left (dirty flag, latest wins).
- **Group page (Phase 2)**: same layer per dimension; the group editor
  already proves multi-canvas client Fabric in this app.

## Verification

- **Golden tests**: for the round-trip fixture canvases (the
  browser-authored ones included), screenshot the echo layer in headless
  Chromium and pixel-diff against the Gotenberg render of the same values —
  near-zero diff expected (same engine), proving algorithm parity.
- **Safari spot-check** on the heavy prod variant (the 15-fatal one) for
  the wrap-boundary epsilon before trusting the echo broadly.
- The existing lenient/strict overflow gating stays client-computed as
  today; no contract change for the API (consumers never see the web
  preview — mfkfm's preview is the server render).

## Open questions (review before starting)

1. Do real designs stack decorative content above fillable text often
   enough that the z-order guard needs slicing instead of fallback?
2. Settle debounce: 2s? 3s? Render-on-blur additionally?
3. Should the echo also cover the TEXT-ONLY variants' `previewDataUri()`
   path first (simplest surface, no slices), as the pilot?
