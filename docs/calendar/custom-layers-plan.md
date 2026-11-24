# K20 — own layers on calendar pages (plan)

Status: **P1 + P2 built** (2026-11-24) — own pictures and shapes on every
week page (P1); page sets, the cover / back / special pages, the stage page
switcher, the step-4 page panel, „Prázdná strana“, per-page inlining and the
import rule (P2). P3 (effects, gradient, crop, dpi warning, lock) is open.
P2 deviations from §4: the stage switcher lists the sample, every non-week
page and the week pages a layer names (not only pages with a layer — the
cover and a blank page must be reachable before they have one); a layer
added while a page is on the stage is „jen tato strana“ of that page, on the
sample page all week pages (D2); „Na stránkách“ is a select, not radios. Jan approved every decision of §8
as recommended (D1–D5, 2026-11-24). The current truth is
`docs/architecture/calendars.md` § "Own layers".

## 0. What Jan asked for

> I should be able to add a custom layer to one / some / all calendar pages (or
> add a page with it etc.) → image, shape. It seems not possible now to add a
> custom layer; it could be in step 3.

Step 3 „Úpravy prvků“ › Vrstvy lists a fixed set: QR kód, Logo, Popisek
fotografie, Číslo týdne, Měsíc, Fotografie, Hlavička, Kalendárium (+ parts),
Pozadí. The designer can restack, hide and style them, but not add one.

The AI scan already tells users the same thing in „Co kalendář nepřevezme“
(`assets/ai-scan/copy.js` `notExpressible`): `ornament` (a stripe across the
page, a frame round the photo, a dividing line), `more_logos` (partner logos),
`second_photo`, `header_text`. Today the advice is "bake it into the header or
background picture". Own layers answer the first three directly (and the fourth
with the optional text layer, §2.3).

## 1. What exists and what limits it

**Layout = a closed vocabulary.** `calendar.layout` is `{ v: 1, boxes, order,
styles }` (`model/layouts.js`, `model/normalize.js`):

- `boxes` — exactly `BOX_KEYS` (`photo cap logo head month month2 cal cal2 wk
  wk2 qr`); `normalizeLayout()` iterates that list and drops anything else.
- `order` — element keys of `DEFAULT_ORDER`, top first; Pozadí paints first
  and has no box. `normalizeLayout()` drops unknown keys.
- `styles` — deep-merged over `defaultStyles()`; `mergeDefaults` keeps only
  keys the defaults have, so an unknown style key is dropped as well.
- Server: `CalendarDesignRules` checks the exact top-level keys, the known box
  keys, `order` against the element list; `styles` is structural only (any
  JSON, strings ≤ 255, depth ≤ 8, ≤ 200 kB). Every `asset` / `icon` key in the
  layout must be an asset of this calendar (`CalendarLayoutAssets::ids`, a
  generic walk — new nested `asset` keys are covered for free).

**One style, many pages.** The layout is drawn on every week page; pages only
carry content overrides (`PAGE_COLLECTIONS`: `pagePhotos pageCaptions
pageImages pageQr pageEffects`). K2 P2 (efb8bf17) added the last of them: a
page's own effect list per picture, designer-only content, page-keyed, so
„Upraveno“ and „Vrátit na styl“ (`pageHasOverrides` / `clearPageOverrides`)
cover it; never undoable.

**Rendering.** `renderPage()` (`render/page.js`): paper → Pozadí (`fillLayers`
+ effects) → the page body → binding holes. Week pages: `renderWeekPage()`
walks `layout.order` bottom-up and dispatches on the element key. Cover, back
and special pages: `render/special.js` draws them as fixed layouts (1000 × 468
design units mapped onto the real page) — "layouts, not a canvas"; there is no
z-order there and nothing can be positioned freely.

**Editor.** The step-3 stage always shows the sample page (`samplePage()` =
the first week page, or the first with a time change while that part is
selected) — the stage bar says „Vzorová strana · styl platí pro všechny
strany“. Interaction is pure and generic over a `boxes` map: `hitTest` walks
`order` and `boxKeysOf`; `beginDrag` / `dragTo` / snap / clamp / round take any
box map; `adaptBoxesToBleed`, `mirrorBoxes` and the layout-file scaling in
`parseLayoutFile` iterate `Object.entries(boxes)`. What is element-specific:
`isElementVisible` (a `switch` over the nine keys, unknown → false),
`visibleBoxKeys` (iterates `BOX_KEYS`), the Vrstvy rows (`ELEMENTS` specs,
`EYES`), the props panels (one builder per element), link (`applyLink` over
the visible keys).

**Step 4 / 5.** The step-4 page view is a rendered page + the page panel
(`ui/pagepanel.js`), not an editing stage. Step 5 is the client's surface;
the client never changes the layout (store throws in client mode; server 403
on any `design` key).

**Print.** Everything is SVG printed by Chromium (vector text, shapes,
gradients, opacity, dashes; `<image>` with its file's own bytes). The CMYK
pass rewrites every vector colour (neutral → K only, project palette hex → the
palette's CMYK, else ICC) and every picture. Rules that bind new markup: never
a `filter` or `mask` on an `<image>` (K2), the 16 PDF blend modes only, no
streamed responses, `CalendarRenderAssets` must know every asset a page shows.
Any change under `model/ generate/ render/ editor/ headless/` moves the export
fingerprint (`CalendarModuleInliner::fingerprint()`).

**Templates editor** (`templates-editor-ui.md` § "Vector shapes"): Fabric
objects on a free canvas — obdélník, čtverec, kruh, elipsa, trojúhelník, čára
(a thin Rect on purpose), hvězda; fill solid / gradient, stroke, radius,
opacity, a dotted / dashed čára that dots the LINE, not its outline. Nothing
of that code is reusable here (Fabric, a canvas JSON, the raster render path).
Worth copying are the **decisions**: a line is a box (so it snaps and resizes
like everything else), sizes relative to the page, new shapes take the first
project palette colour, the line dash style is read from the style name, not
the numbers. No coupling of the two modules is proposed.

## 2. Data model

### 2.1 Where layers live — in the layout, geometry in `boxes`

A layer is **design**: designer-only, undoable (the undo snapshot is the
layout), saved with the `layout` design key (last write wins, like every
style), carried by layout files, „Nahrát layout“ from a sibling and MCP
`set_calendar_layout`. Its page set is a property of the layer (§3) — a layer
"only on page 5" is still design, not a per-page override (decision D1).

```
layout = {
  v: 1,
  boxes:  { …built-ins…, "u:Ab3dE…": { x, y, w, h, hidden? } },
  order:  [ "qr", "u:Ab3dE…", "logo", …, "photo" ],        // index 0 = top
  styles: { …unchanged… },
  layers: { "Ab3dE…": Layer }                                // NEW top-level key
}
```

- The geometry goes into `boxes` under **`u:<id>`** (id = `newId()`, server
  pattern `[A-Za-z0-9_-]{1,40}` minus `RESERVED_IDS`). This is the cheap
  part: drag, resize, snap, clamp, nudge, the `hidden` eye, bleed adaptation,
  mirroring (`mirrorBoxes`), layout-file scaling and MCP's `boxes` patch all
  iterate the box map already. `hidden` is allowed on every `u:` box.
- The z-position is the `u:<id>` entry in `order` — one list with the
  built-ins, so Vrstvy's SortableJS stays one list.
- Everything else is the `layers[id]` item.
- Invariant (normalize repairs, server refuses): every `layers` id has exactly
  one `u:` box and one `u:` order entry and vice versa. Cap **50 layers**.

Considered and rejected: geometry inside `layers[id].box` (every box-generic
function would need a second loop — bleed, mirror, file scaling, drag, MCP);
layers as a content collection (not undoable, not in layout files, a client
could not be kept out of the geometry without a per-field rule, and the
"one style" designer would edit design in two stores).

### 2.2 The layer item

```
Layer = {
  kind: 'image' | 'rect' | 'ellipse' | 'line',
  name: string ≤ 60,                 // '' = „Obrázek 2“, „Obdélník 1“… numbered bottom = 1 (display only)
  pages: 'weeks' | 'all' | [pageKey…],   // §3; default 'weeks'
  except: [pageKey…],                // „Skrýt na této straně“; only with 'weeks' / 'all'
  opacity: 0–100,                    // the whole layer (one <g opacity>)
  blend: BLEND_MODE,                 // 'normal' default; the 16 PDF modes of model/effects.js
  effects: Effect[],                 // the K2 list (normalizeEffects), P3
  // kind 'image'
  asset: assetId | null, fit: 'contain' | 'cover', radius: mm,
  crop: null | { zoom, px, py, rotate?, flip? }     // the photo-crop model, only with fit 'cover', P3
  // kinds 'rect' / 'ellipse'
  fill: { on, color, opacity, gradient: { on, type, angle, stops } },   // the fill shape of styles.*.bg minus the picture
  stroke: { on, color, width: pt, style: 'solid' | 'dash' | 'dot' },
  radius: mm,                        // rect only; > 0 = „Zaoblený obdélník“ (one kind, not two)
  // kind 'line'
  stroke: { color, width: pt, style, cap: 'butt' | 'round' },
}
```

- **Rounded rectangle is `rect` + `radius`**, not a kind of its own — the
  „Přidat vrstvu“ menu offers it as an entry that creates a rect with
  radius 3 mm.
- **A line is a box** (the templates' lesson): horizontal when `w ≥ h`, drawn
  through the box's vertical centre from `x` to `x + w`; vertical otherwise.
  The box keeps a minimum thickness for hit testing (`K.MIN_H`), the drawn
  stroke is `width` pt. Dash patterns follow the Kalendárium lines
  (`renderItem` `line` in `render/week.js`): dash `4w 3w`, dot `0.01 2.6w`
  round-capped. No diagonals (calendars have none; the box model stays
  simple).
- Colours `#RRGGBB` upper case; the colour picker is `colorPicker` with the
  project palette first — **a palette colour prints with the palette's CMYK
  to the digit** (CMYK rule 2), which is the main reason a designer would
  rather draw a stripe than bake it into a JPEG.
- Ranges go to `LIMITS` in `model/defaults.js` (`layerRadius`, `layerStroke`
  0.1–20 pt, `percent`); `normalizeLayer()` clamps, fills defaults, drops
  unknown keys — the same pure-and-idempotent rule as `normalizeLayout`.
- Upload kind for an image layer: the existing **`image`** (designer; SVG,
  PNG, JPEG, WebP), and „Z galerie“ (`storeGalleryFile`) — no new kind.

### 2.3 Text — recommended later, static only (D4)

A static text layer (`kind: 'text'`, `text ≤ 500`, a text style of the
existing shape — family / size / weight / colour / align / valign / line
height, `wrapText`) is cheap to render and covers `header_text` („Obec
Horní Lhota“ in the header). Recommended as **P4**, not in the first slice:
it brings fonts into play (`GetFontUsage` reads only `layout.styles` — it
must learn `layout.layers`; `RewriteFontReferences` is a text replace and
already works), overflow warnings, and the temptation to ask for per-page
text, which is content and a different feature. No variables (`{měsíc}`) —
the built-in Měsíc / Číslo týdne already do that.

## 3. Scope — which pages, who edits

**Page set of a layer** (`pages` + `except`):

| Value | Shown on | Typical use |
|---|---|---|
| `'weeks'` (default) | every week page | a stripe, a partner logo strip, a frame round the photo |
| `'all'` | cover, front special pages, week pages, back special pages, back | a brand mark in a corner of every sheet |
| `[keys]` | exactly those pages — one key = „Jen tato strana“ | a sticker on the week of the town festival, a decoration on the intro page |
| `except: [keys]` | minus those (with `'weeks'` / `'all'`) | „Skrýt na této straně“ from step 4 |

- Keys are the year/type-independent page keys (`cover`, `pre`, `w01`…`w53`,
  `post`, `back`, `sp:<id>`). A key without a page (no `w53` this year, a
  special page removed, a week that no longer starts a 14-day page) is simply
  not shown; the panel says „2 vybrané strany v kalendáři nejsou“. Nothing is
  rewritten on a year / type change.
- A layout file / „Nahrát layout“ from another calendar keeps `'weeks'` /
  `'all'` layers as they are; `sp:<id>` keys of the other calendar mean
  nothing here and are dropped on import (`parseLayoutFile`,
  `ImportCalendarLayout`) — `wNN` / `cover` / `back` keys stay.
- **Z-order on pages that are not week pages.** Those pages have no element
  stack. Rule: a layer that sits **below every built-in element** in `order`
  (directly above Pozadí) is drawn under the page's content; every other
  layer is drawn above it, in its relative order. The binding holes stay on
  top of everything, as now. Vrstvy shows a thin divider „— pod obsahem
  ostatních stran —“ under the lowest built-in row once any layer is shown on
  such a page, so the rule is visible.
- **„Add a page with it“** (Jan's aside): a new special-page kind **„Prázdná
  strana“** (`specialPages.kind = 'blank'`: background only, no generated
  content) placed with the K13 „+“ insertion points; the designer then puts
  layers on it (`pages: ['sp:<id>']`). Together with the existing „Nahrát
  grafiku“ page this is the free-design page (D5). `CalendarContentRules`
  `SPECIAL_PAGE_KINDS` + `render/special.js` + the chooser get one kind.

**Design vs content.** Layers are design (§2.1): designer-only, saved as the
`layout` key, last write wins, on the undo stack — also when changed from step
4's „Styl stránek“ or from the step-4 page panel (the store's `change()` works
in any step in designer mode). No content collection in P1–P4.

**Client.** Sees every layer drawn (step 5, `/k/{token}`, thumbnails, PDFs),
can neither select nor change one — the server's existing rule (any `design`
key from a client = 403) already guarantees it. A client-fillable image layer
(„Obrázek doplní klient“, e.g. a sponsor per month) would be a page-keyed,
client-writable content collection `pageLayerImages[pageKey][layerId] =
{asset, crop}` with `photo` uploads — P5, only if a real case appears (D3).

## 4. UI

### 4.1 Step 3 — Vrstvy

```
┌ Vrstvy ───────────────────── [+ Přidat] [⇪] ┐   [⇪] = Nahrát layout (existing)
│ ⠿ ▭ Pruh přes stránku          ⋯  👁        │   ← own layer, top of the stack
│ ⠿ ▦ QR kód                         👁        │
│ ⠿ ★ Logo                           👁        │
│ ⠿ ▣ Loga partnerů   · 3 strany  ⋯  👁        │   ← page set shown when not „všechny týdenní“
│ ⠿ T Popisek fotografie             👁        │
│ ⠿ …                                          │
│ ⠿ ▣ Fotografie                               │
│ ─ ─ pod obsahem ostatních stran ─ ─          │   (only while a layer is on a non-week page)
│ ⠿ ◯ Kruh za fotkou              ⋯  👁        │
│ 🔒 Pozadí                                    │
└──────────────────────────────────────────────┘

[+ Přidat] ▾                       ⋯ (row menu)
 ┌──────────────────────────┐      ┌───────────────────────────┐
 │ ▣ Obrázek — nahrát       │      │ Přejmenovat               │
 │ ▣ Obrázek z galerie      │      │ Duplikovat                │
 │ ─────────────            │      │ Na stránkách…             │
 │ ▭ Obdélník               │      │ ─────────                 │
 │ ▢ Zaoblený obdélník      │      │ Smazat vrstvu        Del  │
 │ ◯ Elipsa                 │      └───────────────────────────┘
 │ ─ Čára                   │
 └──────────────────────────┘
```

- „Přidat“ creates the layer **centred on the shown page, on top of the
  stack, selected**, in one `store.change()` (one undo step). Sizes are page
  relative (the templates' lesson): a shape ¼ of the short trim edge, a line
  ½ of the width; an image takes its pixel aspect ratio, contain-fitted into
  ⅓ of the page height. The first colour is the first project palette colour
  (else the accent), stroke off.
- An image added before its upload finishes is not created — the layer
  appears when the upload answers (no empty image layers; an image layer
  whose asset is gone shows the grey slot in the editor and nothing in print).
- Delete: row menu, the Delete / Backspace key on the stage (`ui/editor/keys.js`)
  — undoable, so no confirm dialog. Built-in rows get no menu and no delete.
- Rows of own layers: kind icon, name (or the numbered fallback), the page-set
  pill when it is not the default, the eye (`hidden` on the `u:` box), the
  grip. DOM hooks `[data-layer="u:<id>"]`, `[data-layer-eye="u:<id>"]`,
  `[data-layer-menu-of="u:<id>"]`, `[data-cal-action="layer-add"]`,
  `[data-layer-add="image|gallery|rect|rounded|ellipse|line"]`.

### 4.2 Step 3 — stage and properties

```
┌ stage bar ───────────────────────────────────────────────────────────────┐
│ [Propojit prvky]  Strana: [Vzorová · 1. týden ▾]   ·   −  100 %  +       │
└──────────────────────────────────────────────────────────────────────────┘
   ▾ Vzorová · 1. týden / Titulní strana / Úvodní slovo / … / Zadní strana
     (only pages that show at least one own layer, + the cover and the back)

┌ Vlastnosti — Pruh přes stránku (Obdélník) ─────┐
│ Na stránkách                                    │
│  (•) Všechny týdenní  ( ) Všechny  ( ) Vybrané  │
│      [Vybrat stránky…]  → checklist of pages    │
│ Pozice a velikost   x [ ] y [ ] š [ ] v [ ]     │   positionSection (existing)
│ Výplň        [■ #E5DDF7 ▾]  Krytí [====|  ] %   │   colorPicker / gradient (P3)
│ Obrys        [ ] zapnout  barva · tloušťka pt · plná/čárkovaná/tečkovaná │
│ Zaoblení     [ 3 ] mm                           │   rect only
│ Krytí vrstvy [=======|] %   Prolnutí [Normální▾]│
│ Efekty       (P3: the K2 effects editor)        │
└─────────────────────────────────────────────────┘
```

- **The stage can show another page** — `ui.stagePage` (page key or null =
  the sample page), a select on the stage bar. On a week page everything
  works as today (the built-ins are still the one style). On the cover, back
  and special pages the built-in content is a non-interactive picture: only
  own layers are hit-tested and selectable, a selected built-in is dropped,
  and the note reads „Na této straně upravujete jen vlastní vrstvy“. A layer
  not shown on the stage page is listed dimmed in Vrstvy and selectable from
  there; selecting it switches the stage to the first page it is on.
- `hitTest(pt, design, selection, page)` gains the page; own layers are hit
  like boxes, in `order`. `isElementVisible` learns `u:` keys (`layerOnPage()`
  in a new `model/layers.js`, plus the box's `hidden`).
- **Snap**: own layers snap to everything and everything snaps to visible own
  layers. **Link** („Propojit prvky“) never involves own layers — neither as
  the resized box nor as a follower (they are decoration; a frame round the
  photo must not drag the photo). `beginDrag` gets two key lists instead of
  one.
- Image properties: upload / Z galerie / Odebrat (`uploadTile`), Vyplnit /
  Přizpůsobit (`fit`), Zaoblení, pixel size + dpi line (`pictureSize`), Krytí,
  Prolnutí, Efekty (P3), „Upravit výřez“ (P3, `ui/photo-editor.js` on the
  layer's box with `fit: cover`).
- Arrow keys nudge 0.3 / 3 mm, Alt disables snap — all existing behaviour,
  because the layer is a box.

### 4.3 Step 4 / 5 — per page

```
┌ Page panel — 23. týden ───────────────────────────┐
│ … Fotografie · Popisek · Obrázek v hlavičce …     │   (existing)
│ Vlastní vrstvy na této straně                     │
│  ▭ Pruh přes stránku   všechny týdenní  [Skrýt zde] │
│  ▣ Slavnosti 2027      jen tato strana  [Upravit]   │
│  [+ Přidat vrstvu jen na tuto stranu]              │
└────────────────────────────────────────────────────┘
```

- Step 4's page panel (designer) lists the layers on the shown page: „Skrýt
  zde“ / „Zobrazit zde“ toggles the page in `except` (or removes it from a
  list); „Upravit“ and „Přidat vrstvu jen na tuto stranu“ go to step 3 with
  `ui.stagePage` = this page (and the new layer created with `pages: [key]`).
  These are design changes: undoable, one save like any layout change, no
  „Upraveno“ pill and untouched by „Vrátit na styl“ (they are not overrides).
- Step 5: drawn only. No layer UI for anybody in step 5 (the designer's step
  5 is the client's view plus a few designer tools; adding layers there would
  need the editor stage in step 5 — not worth it).
- URL: `?krok=3&strana=<key>` addresses the stage page too (`state/url.js`
  currently writes `strana` in steps 4–5 only — extend to step 3 when
  `ui.stagePage` is set).

## 5. Render, PDF, CMYK, fingerprint, MCP, scan, tests

**Render** — a new pure `render/layers.js`:

- `renderLayer(doc, page, id, opts)` → `<g data-el="layer" data-layer="<id>"
  opacity=… style="mix-blend-mode:…">`. rect → `rect` (`rx`), ellipse →
  `<ellipse>`, line → `<line>`, fills / gradients via the existing
  `gradientDef`; image → `imageIn` (fit) or `photoParts` (crop, P3) inside a
  clip with the radius; effects through `withEffects` with `{ type: 'rect' }`
  for rect / image-JPEG and `{ type: 'alpha', copy }` for ellipse / line /
  transparent images (as K2 does for logos).
- `renderWeekPage` dispatches `u:` keys in its `order` loop; `renderPage`
  draws the „under“ / „over“ bands around `renderCover` / `renderBack` /
  `renderSpecial` (in page mm, outside the 1000 × 468 mapping). Holes last.
- **Byte-identical without layers**: a layout without `layers` renders
  exactly today's markup (node suite asserts it on the fixture pages).

**PDF / CMYK** — nothing new for the pipeline if the markup keeps the K2
rules: shapes are vector (`re`, Béziers for the ellipse, dashes, `ca`/`CA`,
`/BM`), colours go through the CMYK colour rules (palette match, K-only
neutrals), gradients as shading patterns, an image as the uploaded bytes (no
`filter`/`mask` on the `<image>`), the layer blend = one of the 16 modes. An
SVG image layer prints as vector (as an SVG logo does). Verify once with
`CalendarCmykExportGotenbergTest` extended by a page with every kind (+ a
palette-colour stripe read back as the palette's CMYK).

**Bleed** — boxes may reach `[-B, W+B]`; a layer edge on the trim / old bleed
edge follows a bleed change (`adaptBoxesToBleed`, free with `boxes`). A stripe
"to the page edge" is therefore drawn into the bleed — the designer's
intention for print.

**Assets in the headless render** — `CalendarRenderAssets::shared()` takes
every layout asset for every page, so a page-limited image layer is inlined
into every chunk's HTML. Fine for P1 (`'weeks'` / `'all'` layers are on every
chunk anyway); in P2 make `perPage()` place a layer's asset under its page
keys when `pages` is a list, so a 10 MB sticker on one week is not carried
through 10 chunks. `PdfChunkMerger` deduplicates the streams either way.

**Fingerprint** — `render/layers.js`, `model/layers.js` and the edits in
`render/page.js`, `render/week.js`, `editor/*`, `model/normalize.js` move
`CalendarModuleInliner::fingerprint()` once per phase that touches them (old
exports are re-rendered on the next request; expected).

**Server** (`CalendarDesignRules`): top-level `layers` allowed; box keys
`u:<id>` (id pattern, `hidden` allowed) and order entries `u:<id>`; the
three-way id consistency; ≤ 50; each item structural like `styles` (exact
key sets per kind, `kind` enum, finite numbers, `#RRGGBB`, page keys by
pattern, `blend` in the 16 modes, effects through `CalendarEffectRules`);
assets via the existing walk. History stays „Upravil vzhled a nastavení“
(coalesced) — the design sentence is generic today and a layer does not need
its own.

**Rules around existing layout operations**:

| Operation | Own layers |
|---|---|
| Fotka vlevo ↔ vpravo (`mirrorLayout`) | mirrored with the boxes (the decoration follows the composition, as the effects do) |
| Choose another preset / reset to default | **kept** (they are not part of a preset), clamped to the bounds |
| Type change 7 ↔ 14 days (`rebaseLayoutForType`) | kept, clamped |
| „Nahrát layout“ (file / sibling) | replaced by the file's layers (the file IS the design), `sp:` keys dropped, assets copied as today |
| AI scan accept / restore | current layers kept on top of the scanned layout (the scan does not know them); restore brings back the stored layout's own |
| Old browser tab (pre-deploy JS) saving the layout | drops `layers` (its `normalizeLayout` does not know the key) — the same, accepted risk as every new style key; deploys are rare and tabs short-lived |

**MCP** (skill release required — `docs/mcp/skill-maintenance.md`):

- `set_calendar_layout` gains `layers` — a map id → item or `null` (remove),
  merged into the stored map; new ids may be chosen by the agent (pattern) or
  omitted in an `add` list that the tool answers with the minted ids. `boxes`
  and `order` take `u:<id>` keys through the existing paths.
  `resources/calendar/model.mjs`: `checkLayer()` (kinds, exact keys, colours,
  enums, page keys, effects via `checkEffects`, clamps with notes), new codes
  `unknown_layer`, `invalid_layer`, `too_many_layers`.
- `get_calendar` returns them inside `layout`; `render_calendar_page` draws
  them; `upload_calendar_asset` kind `image` already fits.
- Skill: `references/calendars.md` + `tools.md` (a stripe, a partner logo
  strip, a one-page sticker as examples), `tools.json` regenerated, plugin
  version bump, `CHANGELOG.md`.

**AI scan** — out of scope. Later the scanner could emit `layers` for the
`ornament` / `more_logos` / `second_photo` codes it now only reports (a stripe
as a rect with the sampled colour, a partner strip as an image place in the
pictures step). The calendar-side contract would be ready; the scanner prompt,
the proposal schema and the review UI are an AI-scan project of their own.

**Tests**:

| Layer | What |
|---|---|
| node suite `layers` | `normalizeLayer` (defaults, clamps, unknown keys, idempotent), the id / box / order invariant repair, `layerOnPage` for every page-set form incl. missing keys, render markup per kind (no `filter`/`mask` on `<image>`, dash patterns, blend), byte-identical output without layers, mirror / bleed / file scaling / `sp:` drop, under / over bands on cover and special pages, hit test with layers |
| existing suites | `normalize`, `layouts`, `link` (own layers never link), `effects`, `dimensions` (no size literals) |
| PHP | `CalendarDesignRulesTest` (accepted / refused shapes, invariant, cap, foreign asset), `CalendarClientTest` (a client's `layers` = 403), `CalendarDesignToolsTest` (MCP add / patch / remove, refusals), `CalendarLayoutAssets` walk, `SkillManifestTest` |
| Gotenberg | `CalendarCmykExportGotenbergTest` + a layer page (palette stripe CMYK exact, image bytes unchanged) |
| e2e `user-layers.e2e.cjs` | add each kind, drag / resize / snap in mm, undo, reorder against built-ins, eye, delete key, page set „Jen tato strana“ via step 4, stage page switch to the cover, client sees and cannot select, print PDF read back |

## 6. Phases

| Phase | Contents | Effort |
|---|---|---|
| **P1 — own shapes and images on all week pages** (the useful core) | `model/layers.js` + normalize + `CalendarDesignRules`; kinds image / rect (+ rounded) / ellipse / line with fill colour, stroke, radius, opacity, blend; Vrstvy „Přidat“ menu, rows, eye, menu (rename, duplicate, delete), reorder with built-ins; stage drag / resize / snap / nudge / Delete; properties panel; page set fixed to `'weeks'`; render + PDF + CMYK checked; MCP `layers` + skill release; node suite, PHP tests, e2e | **3–4 days** |
| **P2 — page sets and the other pages** | `pages` / `except` + „Na stránkách“ control and page checklist; cover / back / special pages (under / over bands); stage page switch + `?strana` in step 3; step-4 page panel „Vlastní vrstvy na této straně“ (Skrýt zde, Upravit, Přidat jen sem); „Prázdná strana“ special-page kind; per-page asset inlining; import drops foreign `sp:` keys | **2–3 days** |
| **P3 — finish** | K2 effects on layers; gradient fill; image crop („Upravit výřez“ via the photo editor); dpi warning for image layers (`render/warnings.js`, target `u:<id>`); lock (`locked`: not hit on the stage, still listed) | **1.5–2 days** |
| P4 — static text layer (optional, D4) | `kind: 'text'`, text style controls (`controls/text.js`), wrap, overflow warning, font usage scan learns `layers` | 1.5–2 days |
| P5 — client-fillable image layer (only on a real request, D3) | `pageLayerImages` content collection (client-writable, page-keyed), slot in step 5 like the photo, `photo` uploads | 2 days |

P1 alone answers "a stripe / a partner logo strip / a frame on every week";
P2 answers "some / one page / a page with it".

## 7. Risks

- **Old tabs drop `layers`** on their next layout save (§5 table). Accepted
  as for every style key; the commit message of P1 should say so, and the
  orchestrator can deploy P1 at a quiet hour.
- **Layer covers what the client fills** (a shape over the photo or the day
  rows). It is the designer's choice; no warning in P1. If it bites, a
  warning "vrstva zakrývá fotku" is cheap later (geometry is known).
- **Page-limited layers are not "overrides"**, so „Vrátit na styl“ leaves
  them — by design (§3), stated in the panel copy.
- **Effects on ellipse / line** go through the alpha filter path → a 300 ppi
  raster in the PDF (as a logo shadow already is). Fine for print, noted.
- **Step-3 stage on a non-week page** is new ground for the editor (built-ins
  locked there). Kept narrow: the props panel only ever shows own layers on
  such a page.

## 8. Decisions for Jan (recommendation first)

**Approved 2026-11-24: D1–D5 exactly as recommended** — page-limited layers
live in the design, new layers go on all week pages, the client sees layers
read-only, text comes later (P4), „Prázdná strana“ comes in P2.

| # | Question | Recommended | Alternative |
|---|---|---|---|
| D1 | Where does a layer that is only on some pages live? | **In the design** — one layer object with its page set („Na stránkách: všechny týdenní / všechny / vybrané / jen tato“); designer-only, undoable, travels with layout files. „Vrátit na styl“ does not remove it. | As a per-page content override (like K2's `pageEffects`): not undoable, „Vrátit na styl“ removes it, not in layout files |
| D2 | Which pages by default, and which may carry layers at all? | **New layers go on all week pages**; cover, back and special pages only when chosen („Všechny“ or picked) — a stripe for the weeks rarely belongs on the cover | Every page by default |
| D3 | May the client do anything with own layers? | **No** — the client sees them, nothing more. Client-fillable image slots (sponsor per month) only later on a real request (P5) | A „Doplní klient“ image layer from the start |
| D4 | Text as a layer kind? | **Later (P4), static text only** — images and shapes first; header text is the one real case (scan `header_text`) | In P1; or never (bake text into images) |
| D5 | „Add a page with it“ | **A „Prázdná strana“ special-page kind in P2** (placed with the K13 „+“), then layers on it | Only the existing „Nahrát grafiku“ page |
