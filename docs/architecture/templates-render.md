# Templates — render path (Gotenberg, output formats, slice cache, capacity)

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
| API `/preview` (variant + group dimension) | **WebP** | the consumer's debounced live preview; never recorded, never a download |
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
