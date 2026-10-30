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

**Print PDF export (2026-10-30) — `Services/Print/`**

Every variant exports a print PDF next to its PNG ("Export do PDF" on the
fill page, a "PDF" button per dimension + "Vše jako PDF pro tisk (ZIP)" on
the group fill page; the pressed submit button posts `format=pdf` to the
SAME download routes, `Value/ExportFormat`, and the format is recorded on
`export_event.format`). Target: what the studio's printers take —
**PDF/X-1a:2001** (PDF 1.3), output intent **Coated FOGRA39 (ISO 12647-2:2004)**,
one flattened **300 ppi CMYK raster** + vector crop marks, exactly the
structure of the Illustrator-exported reference file.

- **Print setup lives on `TemplateDimension`** (`bleedMm`, `cropMarks`;
  0 / false for every pre-existing variant; add forms default to 3 mm +
  marks, an Instagram preset clears both; "Tiskové nastavení" page edits an
  existing variant). It applies to px variants too — px map to paper at
  300 DPI like every physical unit. **The bleed is not part of the canvas**:
  coordinates stay trim-relative and the bleed is the area past the edges.
- **Raster passes go through the ordinary renderer** with a `PrintLayer`
  (`renderToBytes(..., printLayer:)`, never cached): the render template
  widens the canvas by `bleed_px` per side and shifts the viewport by it, so
  whatever the design draws past the trim lands in the bleed; the
  canvas-mode background covers the bleed box. Suppression is the slice
  convention (`opacity: 0`, stub image src) so every pass lays out
  identically — and the rich-text **list block stack now inherits the
  replaced object's opacity** (it used to leak through any suppression).
- **`PrintPdfRenderer`**: pass 1 = composite WITHOUT the black-ink objects,
  pass 2 = ONLY them, background-less (its alpha is the K mask; skipped when
  there are none). `BlackInkObjects` decides by role: texts whose every
  colour is black (base fill / designed styles / the user's rich runs, as
  the render applies them) and shapes that are lines or ≤ 2 mm thick — NOT
  large black areas (K100 alone prints as flat grey there; they go through
  ICC as rich black). "Black" = `#000000` + every brand colour whose manual
  CMYK is 0/0/0/100.
- **`PrintColorConverter`** (Imagick + lcms): flatten on white → sRGB
  (`resources/icc/sRGB-v4.icc`, CC0 compact profile) → `CoatedFOGRA39.icc`
  relative colorimetric + BPC → **brand colours keep the manual's numbers**
  (pixels whose RGB is EXACTLY a brand hex get its CMYK; only colours the
  design references are masked, each costs a full-size pass) → **K
  overprint** `K + a·(1−K)` with C/M/Y untouched, done on separated grey
  channels because IM's channel-limited composite on a CMYK image is a
  silent no-op → CMYK JPEG q95, no chroma subsampling. ImageMagick is Q16,
  so the pixel cache is capped at 256 MB for the conversion (A2 measured
  1.3 GB resident uncapped → ~640 MB, ~19 s; A4 ~5 s); limits are restored
  after (process-wide in the FrankenPHP worker).
- **Manual CMYK is free text** — `CmykColor::fromManualColor` reads it
  leniently ("100 %", "35,5", blank channel next to filled = 0) and drops
  entries implausible for their hex (a naive CMYK→RGB distance check — prod
  had `000000` stored as 0/0/0/0); dropped colours simply take the ICC path.
  `BrandCmykPalette` = union of the project manuals, first manual wins on a
  conflicting hex (logged).
- **`PdfX1aWriter`** writes the file by hand (8 objects): MediaBox /
  TrimBox / BleedBox, crop marks as Illustrator draws them (1.25 pt white
  halo + 0.25 pt registration `Separation /All`, 27 pt long, offset
  max(3 mm, bleed)), OutputIntent with the embedded profile, Info with the
  GTS keys + `Trapped`, trailer `/ID`. ImageMagick writes Adobe-style
  INVERTED CMYK JPEGs (APP14) → the image gets `/Decode [1 0 1 0 1 0 1 0]`
  (detected, not assumed).
- Tests: `tests/Services/Print/*` (classifier, CMYK parsing, converter ink
  values, writer structure) + `PrintPdfRendererGotenbergTest` (group
  `gotenberg`), which rasterizes the real PDF back through Ghostscript
  `tiff32nc` and asserts INK: bleed filled, paper outside it, marks on all
  four plates, black text K100 over the untouched cyan.
- The ICC profile is Adobe's "Coated FOGRA39" (extracted from the studio's
  reference PDF — the profile the designers' own exports embed). If its
  licence ever becomes a concern, ECI's ISOcoated_v2 is the FOGRA39-based
  free alternative.
