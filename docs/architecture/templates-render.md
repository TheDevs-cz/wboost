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

**Embedding rule — data in the render page stays data (security, 2026-11-13)**

The render page puts server data inside a `<script>`: the canvas, the typed
texts, font and container names. The HTML parser ends a script block at the
first `</script` it sees — whatever JS string that sits in — so a text
`a</script><script>…</script>` in a design (typed by any editor, or read off a
picture by an AI scan) **ran as script in the renderer's Chromium**: it listed
`file:///tmp/`, reached `http://minio:9000` and fetched an outside host. The
canvas JSON was encoded with `JSON_UNESCAPED_SLASHES` and embedded `|raw`.

The rule, for this template and every other page:

- **Nothing is embedded raw in a `<script>` (of any type) unless it is JSON
  encoded with `JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT`**
  — `<`, `>`, `&`, `'`, `"` become `\uXXXX`, the parsed value is identical.
  `buildCanvasJson()` and every `json_encode(…)|raw` of
  `template_variant_render.html.twig` do; so do `font_faces_render.html.twig`,
  `echo_golden_harness.html.twig` and the group editor's `variantsData` block
  (Twig's bare `|json_encode` escapes `/`, which stops `</script>` but not
  `<!--<script`, which makes the parser swallow the block's own end tag).
  `CalendarDocument::encode()` and the calendar renderers' `font_faces` use
  `JSON_HEX_TAG | JSON_HEX_AMP` in `type="application/json"` blocks, which is
  enough there (no `<` survives, and the block is never executed).
  The only other raw things are the inline script FILES of the repository.
- **A value inside an inline handler is a JS string first**: `|e('js')`
  (`onclick="return confirm('… {{ menu.approvalEmail|e('js') }}?')"`) — the
  browser decodes the attribute's entities before the script runs, so HTML
  escaping alone lets a `'` end the string.
- **A value inside CSS is a CSS string**: `font-family: '{{ family|e('css') }}'`
  in every `@font-face` block and `style` attribute. A font's names come from
  the uploaded file's name table; a line break ends a CSS string and what
  follows is read as CSS. Names are also cleaned at upload
  (`AddFontHandler::nonEmpty()`: control, zero-width and direction characters
  out, `/` and `\` → `-`, 120 characters; a slash in a face name made the
  fonts page fail — the name is a route parameter).
- `data-*` attributes carrying JSON (`{{ value|json_encode }}`, autoescaped)
  are safe as they are.

Tests: `TemplateRenderHtmlEscapingTest` (the page the REAL renderer builds for
hostile texts in every string of a design and a fill; every variable of the
template; a source check that no `|raw` lacks the flags),
`ScannerRenderEscapingTest` (the same through scanner API `render` →
`CandidateRenderer`), `EmbeddedDataEscapingTest` (group editor block, inline
handler, CSS). `tests/RenderHtmlTesting.php` reads the page out of the request
the renderer builds — no Chromium needed, so they run in the default suite.
`RenderInjectionGotenbergTest` (`gotenberg` group, run by CI's
`calendar-render` job, which has a Gotenberg service) is the proof of concept
itself against a real Chromium: the injected script would paint the picture
magenta.
In the browser the same data is restored by Fabric from the stored canvas —
**every custom property of a canvas object is whatever was saved**: a
`maxLength` holding markup reached `innerHTML` in the editor's text toolbar
(`canvas_text_toolbar_controller.js`, fixed 2026-11-14 by taking digits
only). An editor controller that writes a canvas property into markup must
coerce it to its type first, or use `textContent`.

Defence in depth on the renderer itself (deny lists, network): the infra
backlog (`lily.srv/docs/backlog.md`, "Gotenberg egress").

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
so a stale hit is not reachable even before tag invalidation. **User-moved
elements (M2)** are baked into the canvas JSON (`ResolvedInputOverrides::$offsets`,
after the row expansion, before slicing — templates-data-model.md), so they are
in the key too, only when present (offset-less keys unchanged). The proof above
already covers them: every movable element is bound (the bake addresses
inputIds), so a range with a moved VISIBLE element is never cacheable; a moved
transparent echo text paints nothing (`TemplateVariantImageRendererCacheTest`). The
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

**Download file names (2026-11-09)** — the "everything downloads as
`<uuid>.png` / `úvodní-strana`" report. ONE builder, `Value/DownloadFileName`
(generic: also used by the manual logo download), and ONE template-module
naming service, `Services/Template/TemplateExportFileNames`, used by every
web controller, API processor and MCP tool that hands out a file:

| Download | Name |
|---|---|
| single template | `<template>[ - <size>]` — size only when the template has several variants |
| page of a document (single-variant route) | `<document> - <page>` |
| group dimension / ZIP entry | `<group> - <size>` (`1:1` → `1x1`) |
| group ZIP | `<group>.zip`, print `<group> - PDF.zip` |
| document | `<document>.pdf` / `.zip`, entries `<document> - 01 - <page>.png` |
| any of them | ` - <version name>` appended when the export landed on a NAMED export version |

The version is looked up AFTER the (synchronous) recording as the exported row
with this exact fill hash (`findDuplicate`), so a changed fill — a new,
unnamed version — never carries a stale name. Headers go through
`DownloadFileName::disposition()` = `HeaderUtils::makeDisposition`: UTF-8
name with diacritics in `filename*`, transliterated ASCII in `filename`
(reserved `/\:*?"<>|`, control/zero-width chars, `%` in the fallback and
Windows device names handled; ≤ 120 chars cut at a word boundary). ZIP
entries are UTF-8 with the UTF-8 flag set (`ZipArchive::FL_ENC_UTF_8`). The
API variant export keeps its `inline` disposition type. The admin editor has
no download of its own ("Vyplnit a exportovat" links to the fill page).

**Print PDF export (2026-10-30) — `Services/Print/`**

Every PRINT-format variant (mm / cm — `TemplateDimension::isPrintFormat()`;
px is a screen format and gets no PDF, no bleed, no crop marks) exports a
print PDF next to its PNG ("Export do PDF" on the fill page, a "PDF" button
per print dimension + "Tiskové formáty jako PDF (ZIP)" on the group fill page,
whose ZIP holds the print members only; the pressed submit button posts
`format=pdf` to the SAME download routes, `Value/ExportFormat`, a px variant
answers 400, and the format is recorded on `export_event.format`). Target: what the studio's printers take —
**PDF/X-1a:2001** (PDF 1.3), output intent **Coated FOGRA39 (ISO 12647-2:2004)**,
one flattened **300 ppi CMYK raster** + vector crop marks, exactly the
structure of the Illustrator-exported reference file.

- **Print setup lives on `TemplateDimension`**: `bleedMm` + `cropMarks`. Chosen
  on the **add forms inline** ("Tisk" row: Spadávka toggle + − n mm + stepper
  that writes the `bleed` widget itself, 0 = off, + Ořezové značky + "PDF w × h"
  summary; `_print_settings_inline.html.twig` + `print_inline_controller.js`,
  2026-11-10) and for an **existing variant / document** in the
  **"Nastavení tisku" modal** (`_print_settings.html.twig` +
  `print_settings_controller.js`, read-only size, live preview; pure maths
  in `print_settings_geometry.js`), `PATCH /api/template-variants/{id}/print-setup` and MCP
  `set_print_setup` / `manage_document`, see `api-templates.md`.
  **Geometry (`PrintPageGeometry`, in the owner's terms)**: *spadávka* =
  `bleedMm`, 0 = off, otherwise per side, 0–10 mm
  (`TemplateDimension::MAX_PRINT_BLEED_MM`; new print formats start at
  `DEFAULT_PRINT_BLEED_MM` = 5 + marks; stored rows keep their value, prod's
  3 mm stays until someone edits it; `withPrintSettings` keeps px at 0 / no
  marks). *Ořezové značky* = on/off; each lies on a trim line, starts *posun*
  = `MARK_OFFSET_MM` (3) from the trim and is `MARK_LENGTH_MM` (5) long, both
  constants, ending 8 mm from the trim, drawn OVER the artwork when the bleed
  is above 3 mm (the white halo). Per side, page margin = marks ?
  max(bleed, 3 + 5) : bleed; BleedBox inset = margin − bleed; TrimBox inset =
  margin; outside the BleedBox is paper. 100 × 100 mm: 5 + marks = 116 × 116,
  10 + marks = 120 × 120, 5 = 110 × 110, 10 = 120 × 120, none = 100 × 100,
  marks only = 116 × 116. The `print_settings_geometry.js` twin is pinned by
  `PrintSettingsGeometryContractTest`. **The bleed is not part of the canvas**:
  coordinates stay trim-relative and the bleed is the area past the edges.
  The modal's preview draws bleed and marks at true scale but shrinks a trim
  longer than 100 mm to 100 mm (same proportions), or an A3's bleed would be
  a hairline; its caption always states the real size.
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
  there are none). Every black object prints K100 (the studio's call —
  large black areas are not part of their designs); `BlackInkObjects`
  decides HOW by role: texts whose every colour is black (base fill /
  designed styles / the user's rich runs, as the render applies them) and
  shapes that are lines or ≤ 2 mm thick OVERPRINT (suppressed in pass 1);
  larger black shapes KNOCK OUT (painted white in pass 1 — no ink under
  them, so the picture beneath cannot ghost through the black). Admin-set
  OPACITY (2026-11-08): a translucent black shape (opacity < 1) OVERPRINTS
  instead — pass 2 paints its alpha as a K tint over the picture, which is
  the see-through the designer asked for (a knockout would blank the photo
  and print flat grey); opacity 0 objects take no ink. Everything else that is
  translucent is simply flattened in pass 1's browser composite, so the
  PDF/X-1a file never carries live transparency (X-1a forbids it). "Black" =
  `#000000` + every brand colour whose palette CMYK is 0/0/0/100.
- **`PrintColorConverter`** (Imagick + lcms): flatten on white → sRGB
  (`resources/icc/sRGB-v4.icc`, CC0 compact profile) → `CoatedFOGRA39.icc`
  relative colorimetric + BPC → **brand colours keep the palette's numbers**
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
  `BrandCmykPalette` = the project palette (`GetProjectPalette` — F7 R2:
  palette order, linked colours only), the first palette colour wins on a
  conflicting hex (split duplicates, logged).
- **`PdfX1aWriter::write(list<PrintPage>, $profile, $title, $createdAt)`**
  writes the file by hand, ONE page or MANY (documents): per page MediaBox /
  TrimBox / BleedBox of its own, crop marks as Illustrator draws them
  (1.25 pt white halo + 0.25 pt registration `Separation /All`); ONCE per file the OutputIntent with the embedded
  profile, the registration colour space and Info with the GTS keys +
  `Trapped`; trailer `/ID` = digest of title + date + EVERY page's JPEG.
  Object numbers are allocated by **`PdfObjects`** (catalog, pages tree,
  ICC, registration CS, Info, then page + image + content per page — a
  single page is still the historical 8 objects), whose `toPdf()` writes a
  contiguous xref and assembles the file with one `implode()` (stream data
  is held by reference, never copied per append). `PdfObjects` also owns the
  shared serialisers (`text()` = ASCII literal or UTF-16BE hex, `number()`,
  `box()`, `date()`, `deflate()`) — both writers use it, do not re-add
  private copies. ImageMagick writes Adobe-style INVERTED CMYK JPEGs
  (APP14) → the image gets `/Decode [1 0 1 0 1 0 1 0]` (detected, not
  assumed).
- **`PrintPdfRenderer`** API: `renderPage(variant, overrides, ?imageOverrides,
  strict = false, ?palette = null): PrintPage` (raster + geometry; the two
  passes + conversion), `render(…, $title, strict)` = the single-variant
  file (unchanged for every existing caller), and
  `renderDocument(list<array{variant, overrides, ?imageOverrides}>, $title,
  strict = false)` = one multi-page PDF, pages rendered sequentially, the
  brand palette read ONCE (all pages share a project). Between pages only
  the encoded CMYK JPEG survives (R3 in `docs/plans/multipage-documents.md`:
  measure a 30-page A4 under the 512 MB limit before raising page caps).
  `renderDocument` does not check the unit — refusing a px document
  (`pdf_not_available`) is the caller's job, like for single variants.
- **Screen PDF ("PDF k odeslání", `ExportFormat::PdfScreen` = `pdf-screen`,
  documents only)**: `ScreenPdfRenderer::renderDocument(pages, $title,
  strict = false)` + `ScreenPdfWriter` (PDF 1.4, via `PdfObjects`). Any
  dimension, px included. Raster = the ordinary PNG render (NO print layer:
  no bleed, no black-ink passes), downscaled with Imagick to 150 ppi for
  mm / cm (px keeps its pixels), flattened on white, sRGB JPEG q85; one
  DeviceRGB DCT image per page, page = trim size (mm/cm → pt, px × 0.75),
  no TrimBox / BleedBox / marks / OutputIntent. The JPEG is forced to 3
  components via the WAND-level `setType(TRUECOLOR)` — IM7's JPEG coder
  auto-greys a grey-only design and `setImageType()` alone does not stop it.
  Single-variant / group / API / MCP exports do NOT offer it:
  `ExportFormat::fromVariantRequestValue()` maps it to PNG on the web forms
  (like any unknown value), the API + MCP answer `invalid_format`
  (`isVariantFormat()`), and the renderers' `renderExport()` throw on it.
- Tests: `tests/Services/Print/*` (classifier, CMYK parsing, converter ink
  values, writer structure incl. 3-page numbering / contiguous xref / one
  OutputIntent, screen writer structure + raster encoding) +
  `PrintPdfRendererGotenbergTest` (group `gotenberg`, run with
  `--group gotenberg --filter Print`), which rasterizes the real PDF back
  through Ghostscript `tiff32nc` and asserts INK: bleed filled, paper
  outside it, marks on all four plates, black text K100 over the untouched
  cyan; a 2-page `renderDocument` (page 2's own ink + marks, separated with
  `-dFirstPage`), and a 2-page screen PDF.
- **`PdfChunkMerger`** (2026-11-11, calendar PDF export): several
  Chromium-printed PDFs → one file on the local disk, in constant memory —
  objects renumbered, streams copied raw, identical streams written once,
  page boxes set on the way. It exists because a 500 MB print file cannot go
  to Gotenberg's merge route and back (its `/tmp` tmpfs, the 30 s timeout).
- **`PdfPageBoxes`** (2026-11-11, used by the calendar PDF export —
  `calendars.md` § "PDF export") is the one class here that works on a PDF it
  did not write: it appends an incremental update that sets MediaBox / CropBox
  / TrimBox / BleedBox + Info on every page of a Chromium-printed, qpdf-merged
  file. That export is the opposite design of the one above — a **vector RGB**
  file printed by Chromium (live text, embedded fonts, photos at native
  pixels), not a flattened CMYK raster; the CMYK conversion of such a vector
  file with Ghostscript was spiked and is NOT clean (rich black text, no
  brand palette).
- **`Services/Print/Cmyk/` (2026-11-12, the calendar's „Pro tisk (CMYK)“ —
  `calendars.md` § "CMYK variant")** is that conversion done by ourselves:
  `PdfCmykConverter` rewrites the colours of a Chromium-printed vector PDF
  (content-stream operators, shading functions, colour spaces, transparency
  groups, pictures) to DeviceCMYK and `PdfRgbVerifier` proves that no RGB is
  left; `PdfChunkMerger` adds the PDF/X-4 frame. It shares with the raster
  pipeline above the profile, the conversion intent (`IccColorTransform` =
  the lcms call of `PrintColorConverter`, for swatches and pictures instead
  of one page raster), `BrandCmykPalette` and the output-intent names
  (`PdfX1aWriter::OUTPUT_CONDITION*`). It differs in two decisions: black is
  decided by COLOUR (neutral → K only; nearly neutral text → K only), not by
  object role (`BlackInkObjects`: overprint / knock-out), and nothing is
  flattened — live transparency, hence X-4, not X-1a. **Could the template
  print export adopt it?** Technically yes: the pass reads any PDF Skia
  prints, and Fabric's canvas would have to be printed as SVG/DOM instead of
  screenshotted. Not done, and not free: (1) the render template draws on a
  `<canvas>` — Chromium prints a canvas as ONE bitmap, so the vector route
  needs an SVG export of the Fabric scene (`toSVG()` is not pixel-identical
  to the canvas for rich text, lists, containers); (2) the studio's printers
  were given PDF/X-1a (no transparency) and K 100 overprint for black
  objects, which this pass does not produce; (3) the black-ink rules differ
  as said. Keep the two apart until a printer asks for vector templates.
- The ICC profile is Adobe's "Coated FOGRA39" (extracted from the studio's
  reference PDF — the profile the designers' own exports embed). If its
  licence ever becomes a concern, ECI's ISOcoated_v2 is the FOGRA39-based
  free alternative.
