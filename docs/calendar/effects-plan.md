# K2 — Photoshop-like layer effects on calendar images (plan)

Status: plan 2026-11-23; **decided by Jan 2026-11-24** (below); P1 + P2 built 2026-11-24 (current truth:
`docs/architecture/calendars.md` § "Image effects").
Spike files (HTML, Gotenberg PDFs, CMYK conversions, PNG renders) are in the session
scratchpad `k2spike/`. They are not in the repo.

## Decisions (Jan, 2026-11-24)

1. Blend modes: only the 16 that survive to the PDF, Photoshop order + Czech names; the other 11 are not listed.
2. Per page: the page REPLACES the style's effect list (copied on the first edit; „Vrátit na styl“ resets).
3. Scope: ALL pictures in the first release — the week photo, the logo (also on the cover / back), the
   header and background pictures (the style's and a page's own `pageImages` ones) and the photos of
   the cover / intro / contacts pages (`pageContent.*.photo`, style key `specialPhoto.effects`). QR
   codes and event icons: no.
4. Phasing, each shipped on its own: **P1** drop shadow + several gradient overlays on all those
   pictures, all pages; **P2** per-page overrides; **P3** inner shadow, outer glow, inner glow.
5. Recommendations D4–D10 below accepted as written: the old photo „Stín“ migrates into a drop shadow;
   effects mirror with Fotka vlevo / vpravo; Photoshop defaults (shadows Násobit, glows Závoj,
   gradient Normální, black 0 % → 70 %); linear + radial + reflected („Zrcadlený“); Photoshop's
   extras left out; per-page editing in the step-4 page panel with a hint in step 3; designer only.

## 0. What Jan asked for

The designer sets PS layer styles on **images**: Vržený stín, Vnitřní stín, Vnější záře, Vnitřní
záře, and **several** Překrytí přechodem per image. Each has PS settings (blend mode, colour,
opacity, angle, distance, spread / choke, size; gradient stops, style, angle, scale, position). They
apply to all pages, or to one image on one page. They **must survive into the PDF**, both the RGB
print file and the CMYK one. References: a white logo with a big soft shadow, and a week photo
whose top-right corner is darkened by two gradients so the logo reads.

## 1. Measured facts (spike, Gotenberg 8.34 / Chromium print → `PdfCmykConverter` → `PdfRgbVerifier`)

| Construct (SVG as `render/*` would write it) | RGB print PDF | CMYK pass |
|---|---|---|
| `mix-blend-mode` on a vector rect: normal, multiply, screen, overlay, darken, lighten, color-dodge, color-burn, hard-light, soft-light, difference, exclusion, hue, saturation, color, luminosity | Each becomes an ExtGState `/BM /<PdfName>`, still vector. The 3816×5400 JPEG under it stays the original JPEG (`pdfimages`: 485 ppi, DCT). | **OK + verifier clean.** `CmykChunkConversion::BLEND_MODES` already accepts all 16 PDF modes. The brief's premise that only `/BM /Normal` works is wrong; only the X-4 paragraph in `calendars.md` says "normal blend mode only". A Ghostscript render of the CMYK file matches the RGB one closely, including the non-separable modes (`out/p1-70.png` vs `p1-cmyk-70.png`). |
| `plus-lighter`, `plus-darker` (CSS for Lineárně zesvětlit / ztmavit) | **Written as `/BM /Normal`** (PDF has no Add / linear burn). It looks right on screen and is wrong in the PDF, with no error. | passes, but wrong |
| filter (`feGaussianBlur` / `feMorphology` / `feOffset` / `feFlood` / `feComposite`) on a **separate copy** of a PNG logo (drop shadow, inner glow) | The filtered copy is rasterised: a 300 ppi RGB image + soft mask, only as big as the filter region. The logo above it stays the original image object. | OK (Flate RGB + SMask → DeviceCMYK / DeviceGray) |
| filter **on the photo itself** (`feDropShadow`) | **The photo is rasterised to 300 ppi and stored losslessly.** A 160 mm frame became a 2035×1326 Flate image (2.7 MB), and the 3816×5400 JPEG was dropped. | OK, but the resolution is lost |
| blurred evenodd frame clipped to the photo box (inner shadow of a rectangle) + a multiply radial gradient over the photo | The frame is rasterised at 300 ppi. The gradient and the photo are untouched. | OK |
| SVG `<mask>` (alpha or luminance) to clip a gradient to the logo's alpha | Skia writes `/SMask /S /Alpha` with **`/TR` = a FunctionType 4 (PostScript) function**, and rasterised a page-sized 2363×1417 image for the luminance mask. | **FAIL**: "A soft mask has a transfer function." |
| `feImage href="#element"` (gradient as a filter input) | Misplaced in print: only a 20 mm strip was drawn. | — |
| `feImage href="data:image/svg+xml…"` with explicit userSpace `x/y/width/height`, `feComposite operator="in" in2="SourceAlpha"`, `mix-blend-mode:multiply`, in an isolated group | Correct: the gradient is clipped to the logo's alpha (rasterised overlay 947×948 at 300 ppi + smask). It looks the same as the mask version. | OK |

**Design rules that follow.**

1. **Nothing ever gets a `filter` attribute on the visible image.** Every effect is its own element,
   under or over the untouched `<image>`.
2. **No SVG `<mask>`.**
3. **Blend modes only via `mix-blend-mode` with a PDF-native mode.**
4. **Each image with effects is an `isolation:isolate` group**, so an overlay blends with the image
   only and not with the page behind it. Shadows and outer glows stay outside that group, so they
   blend with the page.
5. Use `color-interpolation-filters="sRGB"`. The default linearRGB changes how the blur looks.

## 2. Which images, and where the settings live

**P1: the week photo (`photo`) and the logo (`logo`).** These are the two in the screenshots. The
logo effects also apply where `styles.logo.asset` is drawn on the cover and back pages
(`special.js:228`), so the logo looks the same everywhere. Later and optional: the header and
background pictures (`head.bg` / `bg` fills), the cover / intro photos (`pageContent.*.photo`), and
event icons. QR codes never get effects, because a shadow on a QR code hurts scanning.

- **All pages** = `layout.styles.photo.effects` / `layout.styles.logo.effects`. This is designer
  only, undoable, part of the design patch, mirrored by MCP `set_calendar_layout`, and saved in
  layout files.
- **One page** = a new designer-only content collection, **`pageEffects`**:
  `pageKey → { photo: Effect[] | null, logo: Effect[] | null }` (`null` = the style's list).
  It has `pageKeyed: true`, so it goes into `PAGE_COLLECTIONS` / `PAGE_KEYED`, and „Vrátit na styl“
  and the „Upraveno“ dot cover it. This follows the `pageImages` precedent and keeps "pages carry
  content overrides only, never their own layout". An override **replaces** the style's list for
  that image (copy-on-edit, see decision D2). Page keys are year- and type-independent, so a year
  change keeps it.
- Client: never edits effects (a mirror of the server whitelist). The effects are always rendered,
  also in the client view.

## 3. Data shape

```jsonc
// styles.photo.effects / styles.logo.effects (default []), and pageEffects[key].photo|logo
[
  { "kind": "dropShadow", "on": true, "blend": "multiply", "opacity": 60, "color": "#000000",
    "angle": 120, "distance": 2, "spread": 0, "size": 6 },           // mm; angle = PS light angle (°)
  { "kind": "innerShadow", "on": true, "blend": "multiply", "opacity": 50, "color": "#000000",
    "angle": 120, "distance": 1, "spread": 0, "size": 3 },           // spread = Zúžení (choke)
  { "kind": "outerGlow", "on": true, "blend": "screen", "opacity": 75, "color": "#FFFFFF", "spread": 0, "size": 4 },
  { "kind": "innerGlow", "on": true, "blend": "screen", "opacity": 75, "color": "#FFFFFF", "spread": 0, "size": 3 },
  { "kind": "gradient",  "on": true, "blend": "multiply", "opacity": 80,
    "type": "radial", "angle": 90, "scale": 100, "x": 100, "y": 0,   // centre in % of the box, scale 10–150 %
    "stops": [ { "color": "#000000", "opacity": 70, "pos": 0 }, { "color": "#000000", "opacity": 0, "pos": 100 } ] }
]
```

- `blend` ∈ the 16 CSS names that are PDF-native (§4). The key is **`blend`, not `mode`**: `mode`
  is an MCP enum key already (`['four','one']`).
- Stacking is **by kind, as in Photoshop**, and within a kind by list order. From the bottom up:
  drop shadows, outer glows, IMAGE, gradient overlays, inner glows, inner shadows. Several items of
  the same kind are allowed, as in PS CC, which allows several drop shadows, inner shadows and
  gradient overlays. Cap: 10 effects per image, 2–8 stops (the Šablony `CanvasShapeGradient::MAX_STOPS`).
- New `LIMITS`: `fxSize` 0–30 mm / 0.1, `fxDistance` 0–30 mm / 0.1, `fxAngle` −180–180 / 1,
  `fxScale` 10–150 / 1, `fxCentre` −50–150 % / 1. `percent` already exists, for opacity and spread.
- Server: `CalendarDesignRules` needs no change. The deepest object is
  `styles.photo.effects[i].stops[j]` at depth 6 (the limit is 8). There are no asset references, so
  `CalendarLayoutAssets` / `CalendarRenderAssets` need nothing.
- **Migration**: `styles.photo.shadow` (today's „Stín“) becomes the first `dropShadow` when the
  stored styles have no `effects` key (the "missing key = old document" marker, as with
  `month2`): `size = blur`, `distance = hypot(dx, dy)`, `angle = atan2(dy, −dx)` in PS's sense,
  `blend: normal` (this keeps the old look exactly). `shadow` then leaves `defaultStyles`, and
  `mergeDefaults` drops it. Nothing is written back until the designer edits the layout.

## 4. Blend modes (Jan's PS list → what we offer)

| PS (cs) | CSS / PDF | Offer |
|---|---|---|
| Normální · Ztmavit · Násobit · Ztmavit barvy · Zesvětlit · Závoj · Zesvětlit barvy · Překrýt · Měkké světlo · Tvrdé světlo · Rozdíl · Vyloučit · Odstín · Sytost · Barva · Světlost | normal · darken · multiply · color-burn · lighten · screen · color-dodge · overlay · soft-light · hard-light · difference · exclusion · hue · saturation · color · luminosity | **yes (16)** — vector, CMYK OK (measured) |
| Lineárně zesvětlit (Přidat) · Lineárně ztmavit | plus-lighter · plus-darker | **no** — PDF gets Normal without any error (measured) |
| Rozpustit · Tmavší barva · Světlejší barva · Jasné / Lineární / Bodové světlo · Tvrdé míchání · Odečíst · Rozdělit | none in CSS or PDF | **no** |

The missing modes could only be emulated with `feComposite arithmetic` or `feComponentTransfer`
on the backdrop. Chromium has no `BackgroundImage`, so the photo itself would have to go inside the
filter, and §1 shows that rasterises the photo at 300 ppi. That is not worth it.

Recommended: show the 16 in PS order with PS's group separators, Czech PS names, and the default
per kind as in PS: shadows → Násobit, glows → Závoj, gradient → Normální. Caveat for print: the
CMYK file blends in DeviceCMYK (a transparency group `/CS` is rewritten to DeviceCMYK). Separable
modes match. The non-separable ones (Odstín…Světlost) and Rozdíl / Vyloučit follow the PDF
spec's CMYK rules, which a RIP may render slightly differently from the screen. A proof is advised
(the same as the existing note on Acrobat Preflight).

## 5. Rendering — a new pure `render/effects.js`, used by `week.js` (photo, logo) and `special.js` (logo)

`effectsUnder(shape, effects, opts, id)` + `effectsOver(shape, effects, opts, id)`. `shape` is
either `{ kind: 'rect', box, radius }` (the photo frame) or `{ kind: 'alpha', imageMarkup }` (a
copy of the logo `<image>`). Ids: `${idPrefix}-photo-fx${i}`, `-logo-fx${i}` (the `idPrefix`
uniqueness rule).

| Effect | Photo (rect + radius) | Logo (alpha) | PDF |
|---|---|---|---|
| Vržený stín | Rect grown by `spread·size`, offset by angle / distance, `feGaussianBlur σ = size·(1−spread)/2` (today's blur/2 convention), colour / opacity / blend | Copy of the logo: `SourceAlpha → feMorphology dilate (spread·size) → blur → feOffset → feFlood + feComposite in` | shadow 300 ppi raster, image untouched |
| Vnější záře | The same, no offset | The same, no offset | 300 ppi raster |
| Vnitřní stín | Evenodd frame (big rect minus the rounded box), blurred, offset, clipped by a **clipPath** (vector) to the box | Copy: inverted `SourceAlpha` (`feComponentTransfer`), erode (choke), blur, offset, `feComposite in SourceAlpha`, flood | 300 ppi raster overlay |
| Vnitřní záře | The same, no offset | The same, no offset | 300 ppi raster overlay |
| Překrytí přechodem | `<rect>` with a userSpace (mm) `linearGradient` / `radialGradient`, **clipPath** to the rounded box, `mix-blend-mode` | Copy with filter `feImage(data:image/svg+xml gradient, x/y/w/h in user space) → feComposite in SourceAlpha` | photo: **vector**; logo: 300 ppi raster overlay |

- Gradient geometry is in real mm (`gradientUnits="userSpaceOnUse"`), so 45° is a real 45° and a
  radial gradient is a circle, as in PS. The radius is `scale% × half the longer side`, the centre
  is `x / y %` of the box, and the linear length is the box's extent along the angle × scale.
  This is deliberately different from the fills' unit-square gradients (`svg.js gradientDef`). The
  angle convention stays the same (0° = left → right, 90° = top → bottom, as in fills and in
  Šablony `CanvasShapeGradient`). The shadow's „Úhel“ is PS's *light* angle (120° = light from the
  top left, shadow to the bottom right), because that is what the PS reference uses.
- The photo group: `[under effects] <g style="isolation:isolate"> photoIn(…) [over effects] </g>`.
  The border is drawn last, as today. The placeholder (no photo yet) gets the effects too, so step 3
  shows them.
- **Invariant, tested:** no `filter=` and no `mask=` inside the rendered photo `<image>` or logo
  `<image>`.
- Fortnight: photo and logo are single elements, so there is no second-frame mirroring.
  `mirrorLayout` (Fotka vlevo ↔ vpravo) mirrors the effects too (`angle → 180 − angle`,
  `x → 100 − x`), so the darkened corner follows the logo (D5).
- `render/warnings.js`: no new warning. Thumbnails render effects too. The cost of 55 thumbnails
  with filters has not been measured; check it in P1.

## 6. Editor UI (step 3 properties; step 4 page panel)

- The panels `photo` and `logo` in `ui/editor/props.js` get a section **„Efekty“**. It replaces
  „Stín“, and the old shadow becomes an item. The section has:
  - one row per effect, grouped by kind in the stacking order: eye toggle (`on`), PS name,
    `⋯` menu (Duplikovat · Odebrat), and a drag handle for reordering within its kind (SortableJS
    `forceFallback`, as elsewhere)
  - „Přidat efekt“, a menu of the five kinds
  - a click on a row opens its fields under it (accordion, one open)
- Fields, in PS terminology and in the units of the rest of the panel:
  - **Režim prolnutí** (select), **Barva**, **Krytí %**
  - **Úhel °**: number field plus a small dial, shadows only
  - **Vzdálenost mm**
  - **Rozšíření %** (outer) / **Zúžení %** (inner)
  - **Velikost mm**
  - Přechod: reuse `controls/gradient.js` (stops, swap, Lineární / Kruhový, angle), plus
    **Měřítko %** and **Střed X / Y %**. Optional later: a draggable centre handle on the stage.
- Binding: `styleBinding(store, 'photo.effects.<i>.<field>')` already works for list indices
  (`setPath` copies arrays). Sliders use `beginChange` / `updateLive` / `commitChange`. Add, remove,
  reorder and toggle are one `store.change` each. Hooks: `[data-prop="photo.effects.<i>.<field>"]`,
  `[data-fx-row="<i>"]`, `[data-fx-add="<kind>"]`.
- **„This page only“** lives in step 4's page panel (`ui/pagepanel.js`), where the real photo of
  the page is shown. The section is „Efekty fotky“ / „Efekty loga“.
  - It starts as „Podle stylu · 2 efekty“ with the button „Upravit jen pro tuto stranu“. That copies
    the style's list into `pageEffects[key].photo`, and the same effects editor then appears, bound
    through a content adapter (`putItem` on every change; content is never undoable, as with every
    page override).
  - „Zpět na styl“ sets the field back to null. The „Upraveno“ pill and „Vrátit na styl“ work
    automatically through `PAGE_COLLECTIONS`.
  - Step 3 shows a hint when pages override the style: „3 strany mají vlastní efekty“.

## 7. Mirrors and touch points

- `model/defaults.js` (LIMITS, `BLEND_MODES` + Czech labels, `EFFECT_KINDS`, `MAX_EFFECTS`)
- `model/layouts.js` (`effects: []` on photo + logo; `mirrorLayout` flip)
- `model/normalize.js` (`clampEffects`: kind / blend whitelist, LIMITS, stops via `clampFill`'s
  rules, cap; the `shadow` migration). `effects` is a free list in `mergeDefaults` (like stops),
  not an open map.
- `model/content.js` + **`CalendarContentRules`** (`pageEffects`, an exact item shape incl. effect
  objects → PHP needs the effect vocabulary: kinds, blend list, ranges; mirror test like
  `CalendarSeedContractTest` via `dump-defaults.mjs`), `PAGE_KEYED`, designer-only whitelist,
  `DescribeCalendarChange` („Upravil efekty strany — {strana}“ / „Vrátil efekty strany na styl“).
- `resources/calendar/model.mjs`: lists are stored whole and `checkNested` only checks colours,
  so add an `effects` validator with refusal codes (`invalid_effect`, naming the allowed kinds,
  blends and ranges).
- MCP `update_calendar_content` description (`pageEffects`) → **skill release**: `references/
  calendars.md` + `design.md` mention `photo.shadow`; `tools.json`, version bump
  (`docs/mcp/skill-maintenance.md`, `/skill-release`).
- AI scan: `resources/ai-scan/definition/system.calendar.md:29` names `styles.photo.shadow` →
  rename to `effects` (a scan proposal with `shadow` would still be migrated). Nothing else.
- Export fingerprint: automatic (a hash of the modules), so old exports re-render. `CalendarPdfPlan`
  is not affected.
- Docs: `calendars.md` gets a § "Image effects" plus a fix to the X-4 paragraph (all 16 PDF blend
  modes are live), and `docs/calendar/architecture.md` is not touched (history).
- e2e `panels.e2e.cjs:316–320` (photo.shadow) is rewritten for effects.

## 8. Phases (each one shippable)

- **P1 — All pages: Vržený stín + Překrytí přechodem (several) on photo + logo, all 16 blend
  modes.** Includes `render/effects.js`, the model and migration, the step-3 UI, the MCP layout
  validator, the skill release, docs, and the PDF tests. The 16 modes cost the same as 3, so they
  are all in.
- **P2 — This page only**: `pageEffects`, the PHP mirror, the page-panel UI, MCP content, history.
- **P3 — Vnitřní stín, Vnější záře, Vnitřní záře** (photo + logo).
- **P4 (optional)**: header / background pictures, cover / intro photos, a stage handle for the
  gradient centre and angle.

## 9. Test plan

- Node suite **`effects`** (new):
  - normalise / clamp / caps / unknown kind or blend dropped
  - `shadow` → `dropShadow` migration, idempotent
  - mirror
  - markup: unique ids per `idPrefix`; no `filter=` / `mask=` on the photo or logo `<image>`;
    blend → CSS; userSpace gradient maths at three page sizes (incl. 420 × 200)
  - stacking order
- Update the `render`, `normalize` and `layouts` suites.
- PHP:
  - `CalendarContentRulesTest` (P2 shapes, forged client op = 403)
  - `DescribeCalendarChangeTest`
  - `CalendarDesignToolsTest` (effects patch + refusals)
  - contract test of the PHP effect vocabulary vs JS
- Gotenberg group:
  - a new Chromium chunk fixture with every effect × blend → `ChromiumChunkCmykTest` converts it
    and the verifier passes
  - `CalendarPdfProducerGotenbergTest`: with effects on, the week photo is still the **original
    DCT image at its upload size**
- e2e `effects`:
  - add, eye, reorder
  - a slider drag = one undo step
  - per-page override, Upraveno, Vrátit na styl
  - the client sees it but has no controls
  - export `print_cmyk` succeeds
- Screens at 390 / 820 / 1440 px, both themes.

## 10. Risks

- Shadows, glows, inner effects and logo gradients are **300 ppi raster with a soft mask** in the
  PDF. That is fine for soft effects. A hard edge (size 0) shows 300 ppi stepping against a vector
  logo. SVG logos were not spiked (a PNG was); expect the same.
- Someone later "simplifying" to `filter` on the photo silently costs print resolution. This is
  guarded by the invariant test.
- Non-separable / difference modes in CMYK: the RIP can differ from the screen (§4).
- Thumbnail and stage performance with many filters has not been measured.
- `pageEffects` is the first content shape whose items are nested style-like objects, so PHP has
  to know the effect vocabulary (a new JS ↔ PHP mirror to keep in sync).
- Replace-semantics overrides do not follow later style edits; this is made visible by
  „Upraveno“ (D2).

## 11. Decisions for Jan (recommendation first)

- **D1 Blend modes**
  - Recommended: offer the **16 PDF-native modes** only, in PS order with PS's Czech names.
  - Hide Rozpustit, Lineárně ztmavit / zesvětlit, Tmavší / Světlejší barva, Jasné / Lineární /
    Bodové světlo, Tvrdé míchání, Odečíst, Rozdělit. They cannot reach the PDF without rasterising
    the photo. Lineárně zesvětlit would look right on screen and print as Normal.
  - Alternative: list them greyed out with the note „nelze zachovat v tisku“.
- **D2 Per-page semantics**
  - Recommended: a page override **replaces** the image's effect list (copy-on-edit, „Upraveno“,
    „Vrátit na styl“).
  - Alternative: the page *adds* effects on top of the style. The drawback is that you cannot
    remove one style effect on one page.
- **D3 Scope of P1**
  - Recommended: **photo + logo**, with the logo's effects also on the cover and back pages.
  - Later: header / background pictures and cover / intro photos.
  - Never: QR codes.
- **D4 The existing „Stín“ of the photo**
  - Recommended: migrate it into a Vržený stín effect and remove the old section. Old calendars
    look identical.
- **D5 Fotka vlevo ↔ vpravo**
  - Recommended: mirror the effects with the layout (gradient centre and angle, shadow angle), so
    the darkened corner follows the logo.
- **D6 Defaults**
  - Recommended: PS defaults translated to mm:
    - Vržený stín: Násobit, 75 %, 120°, 1.5 mm, 0 %, 3 mm, black
    - Vnější / Vnitřní záře: Závoj, 75 %
    - Překrytí přechodem: Normální, 100 %, black 0 % → black 70 %, Lineární 90°
  - Alternative: Normální for every effect.
- **D7 Gradient styles**
  - Recommended: **Lineární + Kruhový** (the same as fills and Šablony). Optional: Zrcadlený
    (cheap, done with mirrored stops).
  - PS's Úhlový and Kosočtverec have no SVG / PDF primitive, so they are omitted.
- **D8 PS extras left out**: Global light, Contour, Noise, Anti-alias, "Layer knocks out drop
  shadow", Inner glow source „Střed“.
  - Recommended: leave all of them out.
- **D9 Where „jen pro tuto stranu“ is edited**
  - Recommended: in the **step-4 page panel** (the real photo is visible there), with a hint in
    step 3.
  - Alternative: a page picker in step 3.
- **D10 The client**
  - Recommended: never sees the effect controls. Effects are designer-only, like `pageImages`.
