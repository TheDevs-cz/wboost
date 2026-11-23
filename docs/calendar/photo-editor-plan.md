# K14 — photo editing in the calendar (plan)

Status: approved by Jan 2026-11-24 — **build P1 + P2, no colour adjustments** (§ 11 records the
decisions). P1 (a6b0dc2e) and P2 built 2026-11-24; the current truth is `docs/architecture/calendars.md` § "Photo crop
and „Upravit fotku“". Spike files (HTML, Gotenberg PDFs, extracted JPEGs, PNG renders) were in the
session scratchpad `k14spike/`; they are not in the repo.

Deviations of the build from this plan: the maths module is `model/photo-crop.js` (not `render/`
— `model/content.js` normalises with it); the History line is „Upravil fotku“ for any change of
the turn or the mirror (no separate „Otočil“ / „Zrcadlil“ — one „Hotovo“ often does both); in
„Fotky a popisky“ and the content photo field the editor opens INLINE (a floating panel would sit
under the tool's Bootstrap modal and outside its focus trap).

## 0. What Jan asked for

Step 5 „Náhled“, week page „53. týden“: the photo toolbar above the page is only „Vyměnit fotku“,
a zoom slider (100–300 %), „Fotku posunete tažením“ and a bin. Jan: *improve photo editing — the
editor like we have in template variants editing etc. — should be able to edit photos here too.*

## 1. What the Šablony side offers today (the benchmark)

### 1a. Fill pages (content editing — the right comparison)

`templates-image-placeholders.md` § "Web fill", `assets/controllers/variant_image_fill_controller.js`,
`group_fill_controller.js`, the shared panel `templates/_fill_image_adjust.html.twig` driven by
`assets/controllers/image_adjust_toolbar.js`, maths in `image_placement.js` (JS) and
`src/Services/SocialNetwork/ImagePlacement.php` (server).

| Capability | Fill page | Notes |
|---|---|---|
| Pick from gallery („Vybrat z galerie“, `Project:ImageGallery`, allowed folders) | yes | + upload into an allowed folder |
| Drag to pan | yes | the picture is a live Fabric object clipped to the frame |
| Zoom | yes | slider `.sz-ctl`, **100 % = contain („Přizpůsobit“)**, range 25 % … max(400 %, 2 × cover) |
| Fill the frame | „Vyplnit“ button | cover + centre |
| Width / height / X / Y typed in the variant's unit | yes | aspect locked |
| Rotate | slider −180…180° | free angle; slot flag `allowRotate` |
| Corner / rotate handles | yes | `image_adjust_handles.js`, a DOM layer, clamped to the viewport |
| Arrow-key nudge | 1 canvas px | inert while a field has focus |
| Faint picture outside the frame while selected | yes | Fabric `after:render`, never exported |
| Hide the slot | `hidable` slots | |
| Flip / mirror | **no** on placeholders | `flipX` exists only for *decorative* images in the admin editor („Zrcadlit vodorovně“, `canvas-image-properties#toggleMirror`); a fill resets it |
| Brightness / contrast / saturation / B&W / any filter | **no** | nothing anywhere in templates (`grep` for Fabric `filters`, brightness, grayscale: none) |
| Reset | no dedicated button | „Vyplnit“ re-centres |

Export: the placement (`scale / offsetX|Y / rotation`) is baked by `ImagePlacement` into an absolute
Fabric transform + an `absolutePositioned` clip rect; the server renders it in Gotenberg.

### 1b. Admin canvas editor (designer)

Free move / resize / rotate handles (`canvas_handles.js`), `flipX` mirror for decorative images,
opacity, layer order. No crop tool and no colour adjustments either.

**So "the templates' editor" = pan + zoom (contain…cover…) + rotation + Vyplnit + typed values +
handles + arrow nudge + gallery source.** Mirror exists only for decorative images; colour
adjustments do not exist at all.

## 2. What the calendar has today

Data (`model/content.js` ↔ `Services/Calendar/CalendarContentRules`, exact key sets):

- `pagePhotos[pageKey] = { asset, zoom 1–3, px 0–1, py 0–1 }` — client-writable, page-keyed.
- `pageContent.{cover|sp:intro|sp:contacts}.photo = null | { asset, zoom, px, py }` — the same crop
  model (`CalendarContentRules::photo()`).
- Maths (one formula, copied in three places): cover scale × `zoom`; the picture's top-left is
  `box.x + (box.w − dw) · px` (px = share of the free travel). `render/svg.js` `photoParts()`
  (used by `photoIn` / `photoWithEffects`, i.e. week pages via `render/week.js renderPhoto` and the
  cover / intro / contacts via `render/special.js pagePhoto`), `ui/step5-overlay.js drawnSize()`,
  `ui/pagecontent.js drawn()`.
- `render/warnings.js photoDpi(asset, frameBox, zoom)` → „Nízká kvalita fotky“ (< 200 dpi), and
  the `portrait` warning (portrait picture in a landscape frame). `render/warning-text.js` (K12)
  explains them.

UI surfaces (four, all different):

| Surface | Who | What it can do today |
|---|---|---|
| Step 5 page (`ui/step5-overlay.js`): toolbar `.cal-5ph` + drag surface `.cal-5ps` | designer **and client** (the client view is step 5) | upload / replace, zoom 100–300 %, drag-pan on the page (live by moving the SVG `<image>`, written on release), remove |
| Step 4 page panel (`ui/pagepanel.js`, field `photo`) | designer (client: „Fotky doplní klient.“ when the style says so) | upload tile + remove only — **no crop at all** |
| „Fotky a popisky“ (`ui/tools/photos.js`) | both | per-week upload / remove / caption, bulk upload — **no crop** |
| Page content (`ui/pagecontent.js photoField`) — cover, intro, contacts; step 4 panel and the step-5 „Upravit obsah stránky“ popover | both | upload tile, a small crop preview to drag, zoom slider, remove |

Missing compared to templates: rotation, flip, fit/fill choice, reset, keyboard, typed values, the
whole-picture view while cropping, gallery as a source, a consistent editor across surfaces. Every
write is a `putItem` (not undoable); History names it `Nahrál / Vyměnil / Posunul / Odebral fotku`
(`DescribeCalendarChange`).

## 3. The print constraint — measured

Spike (`k14spike/`, Gotenberg of the dev stack, `pdfimages -list` + `pdfimages -j` + `md5`): a
3816 × 5400 JPEG (2 004 874 B) clipped into a 160 × 100 mm frame, as `render/svg.js` would write it.

| Construct around the `<image>` | PDF image object | Bytes of the JPEG |
|---|---|---|
| plain (today) | 3816 × 5400 **jpeg**, icc, 606 ppi | **identical** (md5 = source) |
| `rotate(7) scale(1.25)` | 3816 × 5400 jpeg, 485 ppi | **identical** |
| `scale(-1 1)` (mirror) | 3816 × 5400 jpeg | **identical** |
| `rotate(90)` | 3816 × 5400 jpeg | **identical** |
| translate + `rotate(-12)` + `scale(-1.3 1.3)` | 3816 × 5400 jpeg | **identical** |
| `opacity="0.9"` on the group | 3816 × 5400 jpeg | identical (soft alpha in the ExtGState) |
| CSS `filter: brightness(1.1) contrast(1.1)` | **1892 × 1184 RGB Flate + smask, 300 ppi** | **the JPEG is gone** — rasterised to the visible clip at 300 ppi |

Conclusions:

1. **Geometry is free.** Pan, zoom, any rotation and mirroring are a `transform` on the `<image>`
   inside the existing clip; Chromium writes them into the content stream (`cm`) and copies the
   JPEG byte for byte. The CMYK pass re-encodes the image object as it does today and never looks
   at the matrix — nothing changes there. The approval PDF uses the ≤ 1600 px preview, same transform.
2. **Colour adjustments can never be a live filter in the print file** (confirms K2's finding):
   the photo is rasterised at 300 ppi, so a 600 ppi photo loses half its resolution and becomes a
   lossless 2.7 MB blob per page. They are only possible as **pixels baked on the server**
   (Imagick → a new JPEG asset, which the CMYK pass then sees as an ordinary picture).
3. **A rotated picture must still cover the frame.** The render of the combined transform shows
   white wedges at the corners when the scale is not raised (`k14spike/r-e-all-1.png`); the cover
   maths must account for the angle (§ 5).

## 4. How K14 relates to K2 (image effects)

- **K2 = the designer's style** of an image *frame*: shadows, glows, gradient overlays —
  `styles.photo.effects` (all pages) + designer-only `pageEffects` (one page). Shapes follow the
  **frame rect** (`pictureShape(asset, box, …)`), not the picture inside it.
- **K14 = per-page content**: which part of which picture shows in that frame (crop, rotation,
  flip) — `pagePhotos` / `pageContent.*.photo`, client-writable.
- They meet at exactly one function: `render/svg.js photoParts()` (K14 changes the `<image>`
  markup inside the clip; K2 wraps the result). Because K2's effects follow the frame, a rotated
  or flipped picture needs nothing from K2. The only case to check: K2's alpha-shape `copy` of a
  possibly-transparent picture (`pictureShape` type `alpha`, `copy = parts.body`) — it copies the
  body, so it carries the K14 transform automatically. Rule from K2 kept: **no `filter=` / `mask=`
  on the visible `<image>`** — K14 adds only `transform`.
- „Vrátit na styl“ / the „Upraveno“ dot: unaffected (`pagePhotos` is page content, as today).

**Sequencing:** K14's render change lands **after** K2's render commit (both touch
`render/svg.js` photo functions and `render/week.js renderPhoto`), after K12 (`ui/step5-overlay.js`,
`render/warnings.js`, `render/warning-text.js`) and K11 (`ui/tools/photos.js`). K9 (URL state) does
not collide.

## 5. Data shape

Two **optional** keys on the crop model, everywhere it occurs (`pagePhotos` items and
`pageContent.*.photo`):

```
{ asset, zoom, px, py, rotate?, flip? }
  zoom    1 … 5        (was 1 … 3; × cover scale — see decision D2)
  px, py  0 … 1        share of the free travel, as today
  rotate  −180 … 180   degrees, 0.1 steps; absent = 0
  flip    true         mirrored horizontally (about the picture's own centre); absent = false
```

- **Absent = default, and the writer omits defaults** (a pure `cropItem()` helper in
  `model/content.js` drops `rotate: 0` / `flip: false`). So every stored item, every open old tab,
  every MCP caller and the AI-scan accept path keep working unchanged and produce identical items;
  an unedited photo renders byte-identical markup (render snapshots and the fingerprint inputs of
  existing calendars do not move).
- Server (`CalendarContentRules`): `item()` today demands an exact key set → add an optional-keys
  variant (`['asset','zoom','px','py']` required, `['rotate','flip']` optional); `rotate` a finite
  number in range, `flip` must be `true` when present (a stored `false` is refused, so there is one
  spelling); `zoom` range 1…5. Used by `pagePhotos` and `photo()`.
- `model/content.js` normalises (`pageContentOf` line 108 and the `pagePhotos` reader) with the
  defaults; JS ↔ PHP mirror in one commit, `CalendarContentRulesTest` + `content` suite.
- `LIMITS.photoZoom.max` 3 → 5 (and a new `LIMITS.photoRotate`).
- History (`DescribeCalendarChange`): `Posunul fotku` stays for a pan / zoom; `Otočil fotku` /
  `Zrcadlil fotku` when only `rotate` / `flip` changed (both: `Upravil fotku`) — same for the
  page-content branch.
- MCP: `update_calendar_content` docstring (`src/Mcp/Tool/UpdateCalendarContentTool.php` lines
  134–140), `plugin/wboost/skills/wboost/references/calendars.md` (lines 116, 416, 427, 465),
  `tools.json` regenerated, plugin version bump + CHANGELOG (`/skill-release`; `SkillManifestTest`).
- No migration, no DB change in P1.

### Placement maths — one pure module `render/photo-crop.js`

Frame `B` (w_f × h_f, centre c), picture w_a × h_a (px), angle θ, flip f.

```
E_x = w_f·|cos θ| + h_f·|sin θ|        extents of the frame measured along the picture's axes
E_y = w_f·|sin θ| + h_f·|cos θ|
k0  = max(E_x / w_a, E_y / h_a)        cover scale (mm per pixel) — the rotated picture covers the frame
k   = k0 · zoom;   dw = w_a·k;  dh = h_a·k
o   = ((0.5 − px)·(dw − E_x), (0.5 − py)·(dh − E_y))     pan, in the picture's axes
<image width=dw height=dh transform="translate(c + R(θ)·o) rotate(θ) scale(f ? −1 : 1, 1) translate(−dw/2, −dh/2)">
dpi = 25.4 / k
```

For θ = 0 this is exactly today's formula (E = frame, k0 = today's cover scale, same x/y), and for
θ = 0 without flip the module emits today's `x / y / width / height` markup. The coverage test is
conservative (the rotated frame's bounding box in picture axes), so the frame is **always** covered
— no white corners in print, at the cost of a slightly smaller pan range at odd angles (what Photos
/ Lightroom "straighten" do too).

Exports: `photoPlacement(box, asset, crop)` → `{ x, y, w, h, transform | null, dpi }`,
`coverScale()`, `panBy(crop, dxMm, dyMm, box, asset)` (screen delta → px/py through R(−θ), flip-aware),
`mirrorCrop(crop)` (toggling flip also maps px → 1 − px and θ → −θ so the *visible* crop mirrors in
place), `rotateBy90(crop)`. Users: `render/svg.js photoParts`, `render/warnings.js photoDpi /
lowPhotoDpi` (dpi now includes the angle; the `portrait` warning uses the rotated orientation —
a portrait picture turned by 90° is landscape), `ui/step5-overlay.js previewCrop` (sets the same
attributes / transform on the live `<image>`), the new editor, `ui/tools/photos.js` dpi hint,
`ui/picture-size.js` if it shows pixels for a photo place.

## 6. Feature list in phases

### P1 — one photo editor, everywhere (geometry only) — the valuable core

A shared UI module **`ui/photo-editor.js`** — „Upravit fotku“ — opened from every photo surface,
for designer and client alike:

- step 5: a new button „Upravit fotku“ in `.cal-5ph` (and double-click / the pencil-free photo
  itself); the drag-on-page pan and the zoom slider stay as the quick path;
- step 4 page panel, week `photo` field: „Upravit“ next to the tile (today it has no crop at all);
- „Fotky a popisky“: a pencil per filled row;
- page content (`ui/pagecontent.js photoField`): replaces its private crop preview + slider by the
  same editor (button „Upravit výřez“ under the tile; the small preview stays as a read-only thumb).

The editor (`openPopover({ wide: true })`: draggable on desktop, bottom sheet < 700 px — the
existing calendar popover, so the page stays visible behind it):

- **Crop stage**: the whole picture, the frame as a window, the outside dimmed (the templates' "faint
  outside part"), drag to pan, wheel / pinch to zoom about the pointer, rule-of-thirds lines while
  dragging. For a week photo the frame includes the bleed for the designer and the trim for the
  client (the same `view.area` step 5 uses) — the bleed band is drawn as a hatched strip.
- **Zoom** slider `.sz-ctl` 100–500 % with − / + and the value (100 % = fills the frame; the
  templates' word „Vyplnit“ becomes the reset of the zoom).
- **Otočit o 90° doleva / doprava** buttons, **Narovnat** slider −45…45° (0.1°; a grid shows while
  dragging), **Zrcadlit** toggle (templates' term „Zrcadlit vodorovně“).
- **Na střed** (px = py = 0.5) and **Obnovit** (zoom 1, centre, no rotation, no flip).
- Live readout: `{šířka} × {výška} px · {dpi} dpi` with the rose / peach „Nízká kvalita“ state from
  the same threshold (`PHOTO_MIN_DPI`, K12's wording) — so a user sees the price of zooming.
- Keyboard: arrows pan 0.3 mm / Shift 3 mm (the calendar's `K` nudges), `+` / `−` zoom, `[` / `]`
  rotate 90°, Enter = Hotovo, Esc = Zavřít bez uložení. Inert while a text field has focus.
- **Draft + one write**: the editor edits a local copy; the page behind is previewed live with
  `photoPlacement()` (moving the `<image>` like `previewCrop` does now — no re-render, no re-decode
  of a print-size photo); „Hotovo“ writes ONE `putItem` (one History line, one save) and Esc /
  ✕ discards (content writes are not undoable, so a cancel must not need an undo).
- „Vyměnit fotku“ / „Odebrat“ inside the editor too, so it is the one place for a photo.

### P2 — the project gallery as a photo source (designer)

- „Vybrat z galerie“ beside „Nahrát fotku“ on every photo surface (and in „Fotky a popisky“ per
  row). Opens the `Project:ImageGallery` modal (as the calendar scan does, `ui/scan.js
  pickFromGallery` + `asset-selected`).
- Server: `POST …/assets` with `fileId` instead of `file` → a new `CalendarAssetUploader` door that
  reads the gallery file (same checks as `CalendarScanPictures::holdGalleryFile`: same project, not
  in Koš, `isGalleryPicture()`, ≤ 10 MB) and runs the **same** rule set as an upload (format sniffing,
  pixel cap, normalisation, preview, EXIF caption) → a `calendar_asset` of kind `photo`. The bytes
  are copied, so a later Koš / purge of the gallery file changes nothing in the calendar.
- **Designer only**: the client on `/k/{token}` is not a project member and must not browse the
  project's gallery. The gallery modal is rendered in `workspace.html.twig` for `mode == 'designer'`
  (today only with `ai_scan_start`) — lazy-loaded so the page does not pay for it up front.
- Optional later: multi-select from the gallery for the bulk „Nahrát všechny fotky najednou“.

### P3 — colour adjustments, baked on the server (only if Jan wants them)

Jas, Kontrast, Sytost, Černobílá (+ maybe Teplota). Only as baked pixels (§ 3):

- The editor previews with CSS filters **on screen only** while the sliders move; „Použít“ asks the
  server to bake: Imagick on the **original** (never on an earlier derived file), JPEG q 92, a new
  `calendar_asset` (kind `photo`, `derived_from` = the original, `adjust` = the parameters) + its
  ≤ 1600 px preview. The item then stores `{ asset: <derived>, source: <original>, adjust: {…}, … }`
  so a re-edit starts from the original; „Obnovit barvy“ points `asset` back to `source`.
- Screen = print, because both show the baked file; the CSS approximation exists only during the
  slider drag. The PDF / CMYK pipeline sees an ordinary JPEG — no export change at all.
- Costs: a DB migration (`calendar_asset.derived_from`, `adjust` JSONB), a new endpoint + message
  handler, storage (every „Použít“ is a new file of up to 10 MB+; counts against the client's 2 GB
  cap; old derivatives become orphans for `storage_object` to see), one JPEG generation loss on
  adjusted photos (none on unadjusted ones), and a spike to match the CSS preview to Imagick's
  maths (brightness / contrast formulas differ).

## 7. Render changes (pure)

- `render/photo-crop.js` new (§ 5); `render/svg.js photoParts()` uses it — the only render change;
  week (`render/week.js`) and special pages (`render/special.js pagePhoto`) follow automatically.
- `render/warnings.js photoDpi` / `lowPhotoDpi` take the crop (angle-aware); `portrait` uses the
  rotated orientation.
- Moves the export fingerprint (render module changed) — expected; check
  `CalendarExportFingerprintTest`.
- Invariant (test): the rendered photo `<image>` carries no `filter` / `mask`; for a default crop the
  markup equals today's.

## 8. Tests

- Node suite `photo-crop` (new): θ = 0 equals the old formula for random inputs; coverage invariant
  (frame corners inside the rotated picture rect) for random θ / zoom / px / py / flip; `mirrorCrop`
  keeps the visible crop mirrored; `rotateBy90` four times = identity; `panBy` round trip; dpi.
- `render` suite: default crop → today's markup; rotate / flip → a `transform`, no `filter`.
- `content` / `payload` suites: normalised defaults, `cropItem()` drops defaults.
- `warnings` suite: dpi with an angle; portrait + 90° = no portrait warning.
- PHPUnit: `CalendarContentRulesTest` (optional keys, ranges, `flip: false` refused, legacy 4-key
  item accepted, zoom 5 ok / 5.1 refused), `DescribeCalendarChangeTest` (new labels),
  `CalendarClientTest` (the client may write rotate / flip), `CalendarPdfProducerGotenbergTest` (a
  rotated + flipped photo → `pdfimages` still lists the original JPEG with the original size —
  the § 3 evidence as a regression test). P2: controller test for the gallery door (other project's
  file 4xx, trashed 4xx, client 403, copy works).
- e2e: `preview.e2e.cjs` (open editor, rotate, flip, straighten, Hotovo, reload — stays),
  `client.e2e.cjs` (client edits a photo), `content-pages.e2e.cjs` (cover photo through the editor),
  phone width bottom sheet. Screens at 390 / 820 / 1440, both themes.

## 9. Risks

- **Pan feel at an angle**: drag must follow the pointer on screen while px / py live in the
  picture's axes (`panBy` rotates the delta) — covered by the suite + e2e.
- **Conservative coverage** shrinks the pan range at odd angles; acceptable, documented.
- **dpi drops with straightening** (k0 grows) — the live readout makes it visible.
- **Bigger zoom range** makes low-dpi prints easier; the warning stays the guard.
- **Concurrent files** (K2 / K11 / K12) — build after their commits (§ 4).
- **Old open tabs** keep working (optional keys); an old tab re-saving a photo it shows would write
  the item without `rotate` / `flip` only if the user edits that photo there — acceptable.
- P3 only: storage growth, generation loss, CSS ↔ Imagick mismatch.

## 10. Files the build will touch

P1: `assets/calendar/render/photo-crop.js` (new), `render/svg.js`, `render/warnings.js`
(+ `render/warning-text.js` facts if it shows the angle), `model/content.js`, `model/defaults.js`
(`LIMITS`), `ui/photo-editor.js` (new), `ui/step5-overlay.js`, `ui/pagepanel.js`,
`ui/tools/photos.js`, `ui/pagecontent.js`, `assets/styles/nocturne/calendar-preview.css` +
`calendar-content.css` (or a new `calendar-photo.css`), `src/Services/Calendar/CalendarContentRules.php`,
`src/Services/Calendar/DescribeCalendarChange.php`, `src/Mcp/Tool/UpdateCalendarContentTool.php`,
skill files + `tools.json` + plugin version, `docs/architecture/calendars.md` (content table, § crop,
§ PDF "transforms keep the JPEG"), tests listed in § 8.
P2: `src/Services/Calendar/CalendarAssetUploader.php` (+ a gallery-copy door), the asset controller
of both route families (designer only), `templates/calendar/workspace.html.twig`, `state/api.js`,
the four surfaces again (button), `docs/architecture/calendars.md` § Uploads.
P3: migration, `CalendarAsset`, a bake message + handler (Imagick), endpoint, editor tab „Barvy“.

## 11. Decisions (approved by Jan 2026-11-24)

Jan approved the recommendations below, with one change to D1: **P1 + P2, no colour adjustments**;
the client gets the full editor (rotate / flip / straighten), the gallery is designer-only.

| # | Question | Options | Recommended → decided |
|---|---|---|---|
| D1 | Scope of the first build | (a) P1 only · (b) P1 + P2 · (c) all three | **(b)**: P1 is the core; P2 is small (the scan already copies gallery bytes) and is the templates' main source of pictures |
| D2 | Maximum zoom | 3 × (today) · 4 × · 5 × | **5 ×**, with the live dpi readout — a large print photo can take it, a small one gets the warning |
| D3 | "Celá fotka" (contain, zoom < 100 %) like templates' 100 % | (a) no — the frame is always covered · (b) yes, with empty bands | **(a)**: an uncovered frame prints paper-white strips, often into the bleed; the calendar has no band colour. Revisit if a client asks |
| D4 | Rotation | (a) 90° steps only · (b) 90° steps + straighten ±45° · (c) free −180…180 slider like templates | **(b)**: covers phone photos lying on their side and crooked horizons; one `rotate` number stores all of it |
| D5 | Flip | horizontal only · also vertical | **horizontal only** („Zrcadlit“) — vertical = horizontal + 180° |
| D6 | Who may rotate / flip | designer + client · designer only | **both** — it is page content (`pagePhotos` is client-writable); the client's photos are the ones that arrive sideways |
| D7 | Editor commit model | (a) draft + „Hotovo“ (one write, Esc cancels) · (b) live writes like today | **(a)**: content writes are not undoable; one History line per edit instead of one per drag |
| D8 | Gallery for the client | no · yes | **no** — `/k/{token}` is not a project member; the project gallery is the designer's |
| D9 | Colour adjustments (P3) | (a) not now · (b) baked server-side derived assets · (c) live filters | **(a) not now**; if wanted later, **(b)** only. (c) is ruled out by measurement: a filter rasterises the photo to 300 ppi in the print PDF. Templates have no adjustments either, so "like templates" does not need them |
| D10 | Apply the editor to the cover / intro / contacts photos too | yes · week photos only | **yes** — same crop model, same render function; one editor instead of four crop UIs |
