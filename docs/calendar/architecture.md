# Kalendáře — architecture and implementation plan

Status: binding design, 2026-11-11. Audience: implementation agents. Each agent sees this file plus
`docs/calendar/handoff.md` (product rules; **wins over everything**), `docs/calendar/prototype-map.md`
(prototype facts, Czech copy in §8), `docs/calendar/codebase-notes.md`, `CLAUDE.md`, the prototype
HTML and `docs/calendar/reference/*`.

How to read: §A is the overview. §B (persisted shapes), §D.2 (JS module contracts) and §C.5 (save
contract) are **contracts between agents** — do not change a name, parameter or shape without updating
this file in the same commit. §E holds the algorithms. §F is the phase plan with file ownership.
"Handoff §x" / "map §x" refer to the two input documents. All Czech UI copy comes verbatim from map §8;
new copy introduced by this design is listed in §G.3 and nowhere else.

Contents: A Summary · A.2 Deviations and risks · B Persisted data · C Server · D Front end ·
E Algorithms · F Phases · G Assumptions.

---

## A. Summary

**What is built.** A project module "Kalendáře": list page → five-step workspace (Typ, Rozvržení, Úpravy
prvků, Obsah, Náhled) → client view. Scope is exactly handoff §2 with the §10 defaults.

**One document, two halves.** A calendar is one row holding a *design* (name, year, type, page, print,
features, preset, `layout` JSONB) and a *content* JSONB made of collections of individually addressable
items. The design is designer-only and saved as a top-level patch; content is merged item by item under
a row lock, so a designer and a client changing different things never overwrite each other. Files live
in `calendar_asset` (JSON refers to assets by id); changes are listed in `calendar_history`.

**Real units everywhere.** Geometry is stored in mm from the top-left trim corner, type in pt. Page size
(default 310 × 145 mm), binding band and hole pattern are properties of the calendar, defaulted in one
JS file (`assets/calendar/model/defaults.js`). No canvas units exist anywhere.

**The browser generates and renders; the server stores and guards.** All generation rules (pages of the
year, name days, holidays, Easter, moon, time/season changes, waste rules, texts) and the renderer are
dependency-free ES modules under `assets/calendar/`. The renderer is a pure function
`(document, page, options) → SVG string` with a viewBox in mm; text is measured through an injectable
measurer (canvas in the browser, deterministic estimator in node). The server never regenerates pages:
it validates shapes, enforces roles, merges, records history, stores files.

**Editor.** The page SVG contains only the page. Selection frame, handles, guides, part highlights, the
event box, icon cells and the step-5 buttons are an HTML overlay positioned from a pure geometry object
(`layoutWeekPage`) — never from `getBoundingClientRect` of SVG nodes. Hit-testing, clamping, snapping,
linking, mirroring, bleed adaptation and auto-grow are pure functions tested in node; the pointer
pipeline is `snapshot → resize/move → min size → snap → clamp → round → link → live render`.
Everything the step-3 editor changes lives in `layout` (plus the caption switch), so one undo stack
covers it.

**14-day type.** One preset `twoSided`: the Kalendárium element has two frames (`cal` = first week on
the left, `cal2` = second week on the right), seven rows each, photo between them. All styles are stated
for frame 1; frame 2 is rendered with the same row algorithm under `flipRowStyles()` — the very function
that mirrors Fotka vlevo ↔ Fotka vpravo.

**Roles.** Designer = `ProjectVoter::EDIT`. Client = holder of the unguessable link `/k/{token}` or a
logged-in user with only `VIEW`. The client gets step 5 with four tools; the server rejects any design
key and any content collection outside the client whitelist.

**Front-end shape.** Two lazy Stimulus controllers (`calendar-list`, `calendar-workspace`) own a state
store and mount plain DOM modules from `assets/calendar/ui/`. No bundler, no new dependency (SortableJS
from the importmap).

**Tests.** Node unit tests for every pure rule, run by PHPUnit wrappers; PHPUnit controller/handler
tests for persistence, roles and merging; puppeteer-core end-to-end scripts under `tests/e2e/calendar/`
that drag with the real pointer and assert in mm through the read-only hook `window.__calendar`.

### A.1 Glossary of identifiers

| Term | Meaning |
| --- | --- |
| `Doc` | Client-side calendar document `{ id, revision, design, content }` (§B.1). |
| box key | Key of a rectangle in `layout.boxes`: `photo cap logo head month cal cal2 wk qr`. |
| element key | `photo cap logo head month cal wk qr bg`. `cal2` is the second *frame* of element `cal`; `bg` has no box. |
| part key | Kalendárium part: `num numSat numSun numHol day name hol line moon time ev shift waste icon`. |
| page key | Stable key of a page for overrides: `pre`, `w01`…`w53`, `post`, `cover`, `back`, `sp:<id>` (§E.9). |
| `B` | Effective bleed in mm: `print.bleed ? print.bleedMm : 0`. |
| `W`, `H` | `page.width`, `page.height` in mm. |
| visible box | A box whose element is drawn: feature on **and** not `hidden` (§B.5). |

### A.2 Deviations from the lead's decisions, and risks

Deviations (each is deliberate; say so if you disagree rather than working around it):

| # | Decision | Deviation | Why |
| --- | --- | --- | --- |
| D1 | 4: one CSS file `calendar.css` | Seven files `assets/styles/nocturne/calendar*.css` (§D.9) | Parallel phases must never edit the same file. `calendar.css` stays the entry for list + shell. |
| D2 | 5: everything the step-3 editor changes lives in `layout` | Two editor shortcuts write **content** and are not undoable: Šichtovnice › Sloupce (add / rename / remove an operation) and Odpady › add / rename / remove a waste kind | The step-4 tools own these lists and clients may edit waste kinds; duplicating them into `layout` would create two sources of truth. Removal asks for confirmation instead. Everything else of map §10 item 7 (icon order, week-number form, custom icons, waste colours, moon phase flags, event icon options) **is** in `layout`. |
| D3 | 5: undo covers `layout` | An undo entry is `{ layout, captions }` | Handoff §4.3: the eye of Popisek fotografie and the step-1 item are one switch (`features.captions`), and hide/show must be undoable (§5.10). |
| D4 | 5: `calendar_asset` columns | Extra nullable `preview_path` (long edge ≤ 1600 px derivative, WebP) | 55 thumbnails of 10 MB print photos would stall the browser. The full file is used only on the large step-5 page and for dpi maths. |
| D5 | 3 / 5: defaults in JS only; new calendars start empty | The server seeds four built-in waste kinds (switched off, no places, no rules beyond a placeholder weekday) from one PHP constant | Seeding in JS would need "virtual" items that exist before they are saved, which breaks item-wise merging. A contract test pins the PHP names to the JS table. |
| D6 | 5: moon list is a collection | Stored as **edits over the computed list** (`moonEdits`: add / remove) | The list is generated by JS rules; materialising it server-side is impossible and client-side seeding has the D5 problem. Year change simply clears the edits. |
| D7 | 6: server generates history descriptions | The client sends an optional plain-text `label` per content operation (page title, date); the server chooses the verb, the author and composes the sentence | Page titles ("5. týden") are generation output the server does not compute. The label is length-capped and never trusted for anything but display. |
| D8 | 1: min box, nudge, tolerances "in mm or page fractions" | All are absolute mm constants (§B.7) | They relate to physical type and hand precision, not to the page ratio; the scope-guard test (other page sizes) still passes because bounds, snap lines and defaults derive from the page. |

Risks to watch:

| # | Risk | Mitigation |
| --- | --- | --- |
| R1 | **Design save is last-write-wins** (two designers, or two tabs). Handoff §10.8 only defines content merging. | Design is patched per top-level key (only changed keys are sent), which narrows the window; `revision` is returned on every save. Listed in §G. |
| R2 | The app-wide **10 MB decimal upload cap** applies to calendar photos too (CLAUDE.md cross-cutting rule). A 310 mm photo at 300 dpi is 3661 px wide and normally 3–8 MB as JPEG, but TIFF/PNG exports will be refused. | Clear error message; the cap is one constant (`UploadCalendarAssetController::MAX_SIZE`) to raise later. |
| R3 | **Explicit saving for the client** (decision 6) means a client who closes the tab loses work despite the `beforeunload` prompt on some mobile browsers. | Status text + prompt; revisit autosave for client mode in iteration 2. |
| R4 | Reference calendars (Bruzovice, Bukovec) print **month and week number once per column**; this design keeps the handoff's single Měsíc and single Číslo týdne (`1. a 2. týden`). | The box model already supports second frames (`cal2`); `month2` / `wk2` can be added the same way later without migration. |
| R5 | Text is measured with the project's web fonts; **before fonts load**, widths are wrong. | `ensureFontsLoaded()` gates the first render of the stage; thumbnails re-render once on `document.fonts.ready`. Node tests use the estimator only. |
| R6 | Node harnesses import `.js` ES modules with relative imports from a tree without `"type": "module"`. This needs **node ≥ 22.7** (syntax detection). Local node is 24/25. | The harness prints `{"skip": …}` on older node and the PHPUnit wrapper marks the test skipped; phase P2 checks the CI runner and adds `actions/setup-node` if needed. |
| R7 | Unreferenced assets (replaced photos, undone uploads) are never deleted individually. | They are removed with the calendar / project; the storage report shows them as referenced by `calendar_asset.path`. A sweeper is iteration-2 work. |
| R8 | PDF special pages are rasterised with Imagick + Ghostscript (present in the dev image: `/usr/bin/gs`, Imagick format `PDF`). | Verified in the dev image by P1's test (a 310 × 145 mm PDF → 2441 × 1142 px PNG); the production image is built from the same Dockerfile but was **not** checked on the server. If PDF decoding is unavailable the endpoint answers 422 with a Czech message and images still work. |
| R9 | The step-4/5 page lists render up to 57 inline SVGs. | Lazy rendering through `IntersectionObserver`, preview-size images, debounced re-render (§D.7). |

---

## B. Persisted data

Conventions: all lengths in mm, rounded to 0.1 mm when written by the editor (`roundMm`); type sizes
and line weights in pt; colours `#RRGGBB` upper case, or `null` for "Bez barvy"; opacities 0–100;
dates `YYYY-MM-DD`; asset references are `calendar_asset.id` strings (uuid) or `null`.
Every object below is **fully materialised** after `normalizeDocument()` — there are no optional style
fields with render-time fallbacks (the prototype's lazy fields are replaced by explicit defaults).

### B.1 Client document

```ts
type Doc = { id: string; revision: number; design: Design; content: Content };

type Design = {
  name: string;                 // ≤ 60
  year: number;                 // 2000–2100
  type: 'week' | 'fortnight';   // 7 dní | 14 dní
  page: PageProps;
  print: PrintSettings;
  features: Features;
  preset: null | 'photoLeft' | 'photoRight' | 'twoSided';   // null until step 2
  layout: null | Layout;                                    // null until step 2
  linkElements: boolean;        // Propojit prvky, default true
  step: 1 | 2 | 3 | 4 | 5;
  finished: boolean;
};

type PageProps = {
  width: number;  height: number;          // trim size, mm
  bandHeight: number;                      // binding band, mm from the top trim edge
  holes: { size: number; radius: number; pitch: number; top: number; margin: number };
};
type PrintSettings = { bleed: boolean; bleedMm: number; marks: boolean };
type Features = {
  captions: boolean; weekNumbers: boolean; moon: boolean; timeChange: boolean; seasons: boolean;
  waste: boolean; events: boolean; shifts: boolean; specialPages: boolean; qr: boolean;
};
```

Defaults (the **only** place page dimensions may appear in code is `model/defaults.js`):

| Constant | Value |
| --- | --- |
| `DEFAULT_PAGE` | `{ width: 310, height: 145, bandHeight: 12.5, holes: { size: 4.3, radius: 0.6, pitch: 8, top: 4, margin: 4.5 } }` |
| `DEFAULT_PRINT` | `{ bleed: true, bleedMm: 5, marks: true }` |
| `DEFAULT_FEATURES` | `captions: true, weekNumbers: true, moon: true`, all others `false` |
| `MARKS` | `{ gapMm: 2, lengthMm: 5, weightPt: 0.25 }` — crop marks start `B + 2` mm from the trim corner |

Holes: `count = floor((W − 2·margin − size) / pitch) + 1`, the row is centred horizontally
(`x0 = (W − ((count − 1)·pitch + size)) / 2`), squares `size × size`, corner `radius`, top edge at
`top`. At 310 mm this gives 38 holes. The band has no fill of its own (the grey strip of the prototype
is the default Hlavička); `bandHeight` is a snap line and the top of full-page content on cover/special
pages.

Step-1 mapping of features to labels (map §8.5), in order: `captions` Popisky fotografií ·
`weekNumbers` Zobrazení týdnů · `moon` Fáze měsíce · `timeChange` Změna času · `seasons` Změna ročního
období · `waste` Odpady · `events` Upravitelné akce · `shifts` Šichtovnice · `specialPages` Speciální
stránky · `qr` QR kód.

### B.2 Layout

```ts
type Box = { x: number; y: number; w: number; h: number; hidden?: true };   // mm, trim-relative
type Layout = {
  v: 1;
  boxes: { photo: Box; cap: Box; logo: Box; head: Box; month: Box; cal: Box; cal2?: Box; wk: Box; qr: Box };
  order: ElementKey[];      // every element key except 'bg', index 0 = top layer
  styles: Styles;
};
```

Rules: `cal2` exists iff `design.type === 'fortnight'`. `hidden` is allowed only on `cap`*, `logo`,
`month`, `wk`, `qr` (*`cap` never stores `hidden`: its visibility is `features.captions`, deviation D3).
Boxes may extend into the bleed (negative, or beyond `W`/`H`) but never beyond `[-B, W+B] × [-B, H+B]`.
`order` default: `['qr', 'logo', 'cap', 'wk', 'month', 'head', 'cal', 'photo']`. Both Kalendárium
frames paint at the position of `cal`. Pozadí is always painted first.

```ts
type TextStyle = { family: string | null; weight: number; size: number; color: string };
// family = exactly Font::faceFamily() e.g. "Rubik (Rubik Bold)", or null = built-in fallback
// ("Outfit", Arial, sans-serif) drawn with `weight`. `weight` is always kept: it is the wanted
// weight used to pick the nearest cut when the family changes.
type Align = 'start' | 'middle' | 'end';          // physical: left | centre | right
type VAlign = 'top' | 'middle' | 'bottom';
type Stop = { color: string; opacity: number; pos: number };
type Fill = {
  colorOn: boolean; color: string; colorOpacity: number;
  gradient: { on: boolean; type: 'linear' | 'radial'; angle: number; stops: Stop[] };   // ≥ 2 stops; 0° = left→right, 90° = top→bottom
  imageOn: boolean; asset: string | null; fit: 'cover' | 'contain'; imageOpacity: number;
};

type Styles = {
  photo: { source: 'client' | 'self'; radius: number;
           border: { on: boolean; width: number; color: string };
           shadow: { on: boolean; blur: number; opacity: number; dx: number; dy: number; color: string } };
  cap:   TextStyle & { align: Align; valign: VAlign; pad: number; bg: Fill };
  logo:  { asset: string | null; fit: 'contain' | 'cover' };
  head:  { bg: Fill; text: TextStyle };            // text: titles of cover / special pages
  month: TextStyle & { align: Align; upper: boolean; pad: number; bg: Fill };
  wk:    TextStyle & { align: Align; form: 'a' | 'b' | 'c' };
  qr:    { perPage: boolean; asset: string | null };
  bg:    Fill;
  cal: {
    bg: { color: string | null; opacity: number };
    satColor: string; sunColor: string;            // weekday-name colours (Sobota; Neděle a státní svátky)
    numberSide: Align;                             // Číslo dne: Vlevo | Na střed | Vpravo
    numAlign: Align | null;                        // shared alignment of all numbers; null = follow numberSide
    dayFormat: 'full' | 'short';
    dayPosition: 'side' | 'above';
    dayAlign: Align | null;                        // only for 'above'; null = follow numAlign/numberSide
    iconSize: number;                              // mm, default icon size
    iconGrid: '4' | '2x2' | '1+2' | '2+1';
    iconCellW: number | null;                      // mm; null = iconSize + ICON_GAP
    iconCells: { [grid: string]: { [cell: string]: { size: number | null; align: Align } } };
    placeLabels: boolean;                          // Místo svozu vedle ikony
    emptyIconSlot: boolean;                        // Prázdné místo pro ikonu u každého dne
  };
  lines: { on: boolean; width: number; style: 'solid' | 'dash' | 'dot'; color: string };
  num: TextStyle; numSat: TextStyle; numSun: TextStyle; numHol: TextStyle;
  day: TextStyle; name: TextStyle;
  hol:  TextStyle & { wrapWidth: number | null };  // mm; null = text column width
  ev:   TextStyle & { align: Align | null; valign: VAlign; box: null | { x: number; y: number; w: number; h: number } };
  time: TextStyle & { position: 'text' | 'sep'; align: Align | null; valign: VAlign };
  shift: TextStyle & { bgMode: 'alt' | 'all' | 'none'; bg: string | null };
  placeText: TextStyle;                            // Text u ikon (collection place)
  moon: { color: string; size: number; valign: VAlign; phases: [boolean, boolean, boolean, boolean];
          icons: [string | null, string | null, string | null, string | null] };
  icons: {
    order: IconCategory[];                         // subset of 'waste'|'moon'|'ev'|'time'|'season'; position 1 = nearest the zone edge
    ev: { name: string; asset: string | null; perEvent: boolean };
    time: { asset: string | null; color: string };
    season: { mode: 'four' | 'one'; asset: string | null;
              assets: [string | null, string | null, string | null, string | null]; color: string };
  };
  waste: { kinds: { [kindId: string]: { color: string | null; icon: string | null } } };
  offsets: { [part in PartKey]?: { x: number; y: number } };   // mm, absent = not moved
};
```

`ev.box` is **flow-anchored** (§E.6): `x` = distance from the text column's zone-side edge, `y` from the
row top. It therefore needs no change when the layout is mirrored and is right for both 14-day frames.

### B.3 Default styles (`defaultStyles(env)`)

`env.fontFamily` = family string of the project's first font face with weight nearest 400, or `null`;
each text style then gets the face of the same font nearest its wanted weight (`nearestFace`, §D.2).
`env.accent` = first project colour that is not pure white/black, fallback `#2E5A8C`.

Wireframe colours `COLORS` (map §2.2 `CK`): `band #CBC7D1`, `paper #F6F5F8`, `ph #E2DFE6`,
`ph2 #D2CED8`, `line #D7D3DC`, `ink #2B2830`, `mut #6E6977`, `sat #2F78B7`, `red #BE3A2E`.

Type sizes: the prototype drew `pt × 1.68` units on a 1000-unit page, i.e. 1.476× too large for 310 mm.
The defaults are rescaled and rounded so the default pages look like the prototype:

| Style | Prototype pt | × 1.476 | **Default pt** | Weight | Colour |
| --- | --- | --- | --- | --- | --- |
| `num`, `numSat`, `numSun`, `numHol` | 19 | 28.0 | **28** | 800 | `ink`, `sat`, `red`, `red` |
| `day` | 7.5 | 11.1 | **11** | 600 | `mut` |
| `name` | 6.5 | 9.6 | **9.5** | 400 | `ink` |
| `hol` | 5.5 | 8.1 | **8** | 400 | `red` |
| `month`, `head.text` | 10 | 14.8 | **15** | 800 | `ink` |
| `wk` | 6.5 | 9.6 | **9.5** | 400 | `mut` |
| `cap` | 6 | 8.9 | **9** | 400 | `mut` |
| `ev` | 5.5 | 8.1 | **8** | 600 | `env.accent` |
| `time` | 5.5 | 8.1 | **8** | 400 | `mut` |
| `shift` | 5.5 | 8.1 | **8** | 600 | `mut` |
| `placeText` | 5 | 7.4 | **7.5** | 600 | `mut` |
| `lines.width` | 0.7 | 1.03 | **1** | — | `line` |

mm-valued style defaults (the prototype's mm were scaled by the same wrong factor):

| Field | Prototype | **Default** |
| --- | --- | --- |
| `cal.iconSize` | 4 mm | **6** mm |
| `moon.size` | 3.4 mm | **5** mm |
| `month.pad`, `cap.pad` | 2 mm | **3** mm |
| `photo.border.width` | 0.5 mm | **0.7** mm |
| `photo.shadow` blur / dx / dy | 2 / 1 / 1.5 mm | **3 / 1.5 / 2** mm, opacity 35, colour `#000000` |

Remaining defaults:

```js
photo: { source: 'client', radius: 0, border: { on: false, width: 0.7, color: '#FFFFFF' }, shadow: { on: false, blur: 3, opacity: 35, dx: 1.5, dy: 2, color: '#000000' } }
cap:   { ...text(400, 9, mut), align: 'start', valign: 'middle', pad: 3, bg: fill({ colorOn: true, color: '#D2CED8' }) }
logo:  { asset: null, fit: 'contain' }
head:  { bg: fill({ colorOn: true, color: '#CBC7D1', stops: ['#CBC7D1', '#F6F5F8'] }), text: text(800, 15, ink) }
month: { ...text(800, 15, ink), align: 'end', upper: true, pad: 3, bg: fill({ colorOn: false, color: '#FFFFFF', stops: ['#FFFFFF', '#CBC7D1'] }) }
wk:    { ...text(400, 9.5, mut), align: 'end', form: 'a' }
qr:    { perPage: false, asset: null }
bg:    fill({ colorOn: true, color: '#F6F5F8', stops: ['#F6F5F8', '#CBC7D1'] })
cal:   { bg: { color: null, opacity: 100 }, satColor: sat, sunColor: red, numberSide: 'end', numAlign: null, dayFormat: 'full', dayPosition: 'side', dayAlign: null,
         iconSize: 6, iconGrid: '4', iconCellW: null, iconCells: {}, placeLabels: false, emptyIconSlot: true }
lines: { on: true, width: 1, style: 'solid', color: line }
hol.wrapWidth: null;  ev: { align: null, valign: 'top', box: null };  time: { position: 'text', align: null, valign: 'bottom' }
shift: { bgMode: 'alt', bg: null }
moon:  { color: ink, size: 5, valign: 'middle', phases: [true, true, true, true], icons: [null, null, null, null] }
icons: { order: ['waste', 'moon'], ev: { name: 'Akce', asset: null, perEvent: false }, time: { asset: null, color: ink }, season: { mode: 'four', asset: null, assets: [null, null, null, null], color: ink } }
waste: { kinds: {} };  offsets: {}
```

`fill(o)` = `{ colorOn: false, color: '#FFFFFF', colorOpacity: 100, gradient: { on: false, type: 'linear', angle: 90, stops: [{ color: a, opacity: 100, pos: 0 }, { color: b, opacity: 100, pos: 100 }] }, imageOn: false, asset: null, fit: 'cover', imageOpacity: 100, ...o }`.

The page itself is painted white (`#FFFFFF`, paper) under Pozadí; the prototype's grey paper is now the
default Pozadí colour, so what is drawn is what prints.

Preset-dependent style values (set by `defaultLayout`, re-set by `rebaseLayoutForType`):

| Field | `photoLeft` | `photoRight` | `twoSided` |
| --- | --- | --- | --- |
| `cal.numberSide` | `end` | `start` | `start` |
| `month.align` | `end` | `start` | `start` |
| `wk.align` | `end` | `start` | `end` |
| `cap.align` | `start` | `end` | `middle` |
| `cal.iconGrid` | `4` | `4` | `2x2` |

### B.4 Default boxes (`defaultBoxes(preset, page, B)`)

The tables give mm at the default page with `B` symbolic. For another page size every x and w is
multiplied by `page.width / DEFAULT_PAGE.width`, every y and h by `page.height / DEFAULT_PAGE.height`
(logo and QR: w **and** h by the width factor, so they stay square), `12.5` is replaced by
`page.bandHeight`, and the result is rounded to 0.1 mm. `B` is the bleed at the moment the layout is
created; later bleed changes go through `adaptBoxesToBleed` (§E.5).

**Fotka vlevo (`photoLeft`)** — lines: header bottom `y = 27`, split `x = 197`, kalendárium bottom
`y = 139`, text column `x = 203 … 304`.

| Box | x | y | w | h | Edges in the bleed |
| --- | --- | --- | --- | --- | --- |
| `head` | −B | −B | 310 + 2B | 27 + B | left, top, right |
| `photo` | −B | 27 | 197 + B | 118 + B | left, bottom |
| `cal` | 197 | 27 | 113 + B | 112 | right |
| `month` | 203 | 12.5 | 101 | 14.5 | — |
| `wk` | 203 | 139 | 101 | 4.5 | — |
| `cap` | 5 | 135 | 93 | 5.5 | — |
| `logo` | 168.5 | 31 | 23.5 | 23.5 | — |
| `qr` | 170.5 | 116.5 | 21 | 21 | — |

Coinciding lines (handoff §7.4 depends on them, tests assert them): `y = 27` head bottom / photo top /
cal top / month bottom; `x = 197` photo right / cal left; `y = 139` cal bottom / wk top; `x = 203` and
`x = 304` month and wk left / right. `logo`, `cap`, `qr` share no edge with anything (the prototype's
accidental logo/QR right-edge coincidence is removed on purpose).

**Fotka vpravo (`photoRight`)** = `mirrorLayout(photoLeft)`: `x' = 310 − x − w` for **every** box
(including `qr` — the prototype's unmirrored fresh QR box is a bug):

| Box | x | w | | Box | x | w |
| --- | --- | --- | --- | --- | --- | --- |
| `head` | −B | 310 + 2B | | `wk` | 6 | 101 |
| `photo` | 113 | 197 + B | | `cap` | 212 | 93 |
| `cal` | −B | 113 + B | | `logo` | 118 | 23.5 |
| `month` | 6 | 101 | | `qr` | 118.5 | 21 |

**14 dní (`twoSided`)** — designed after the Bruzovice / Bukovec references: header across, first week
left with numbers on the outer left edge, second week right mirrored, photo between, crest centred over
the header/photo line, caption centred under the photo.

| Box | x | y | w | h | Notes |
| --- | --- | --- | --- | --- | --- |
| `head` | −B | −B | 310 + 2B | 27 + B | as 7-day |
| `cal` | −B | 27 | 84 + B | 112 | first week; right edge `x = 84` |
| `cal2` | 226 | 27 | 84 + B | 112 | second week; left edge `x = 226` |
| `photo` | 84 | 27 | 142 | 112 | bottom `y = 139` |
| `month` | 6 | 12.5 | 120 | 14.5 | `align: start` |
| `wk` | 232 | 139 | 72 | 4.5 | `align: end`, right `x = 304` |
| `cap` | 89 | 139.5 | 132 | 4.5 | `align: middle`, under the photo |
| `logo` | 143 | 15 | 24 | 24 | centred on `x = 155`, straddles `y = 27` |
| `qr` | 199.5 | 113 | 21 | 21 | inside the photo's lower right corner |

Coinciding lines: `y = 27` head bottom / cal top / cal2 top / photo top / month bottom; `x = 84` cal
right / photo left; `x = 226` photo right / cal2 left; `y = 139` cal bottom / cal2 bottom / photo bottom
/ wk top. Each frame has seven rows, so the row height equals the 7-day layouts (no compact mode by
default).

### B.5 Visibility, and what mirroring flips

| Element | Drawn when |
| --- | --- |
| `photo`, `cal` (+`cal2`), `head`, `bg` | always |
| `cap` | `features.captions` |
| `logo`, `month` | `!boxes[k].hidden` |
| `wk` | `features.weekNumbers && !boxes.wk.hidden` |
| `qr` | `features.qr && !boxes.qr.hidden` |

`visibleBoxKeys(design)` returns the box keys of drawn elements (`cal2` with `cal`). Only visible boxes
are selectable, snap targets, link followers and link triggers (fixes map §10 item 10). The Vrstvy eye
of Popisek fotografie toggles `features.captions`; the eye of `wk` / `qr` toggles `hidden` and the row
of `qr` exists only while `features.qr`; when `features.weekNumbers` is off the `wk` row stays, dimmed,
and its panel shows `Číslo týdne je vypnuté v kroku 1.`

`mirrorLayout(layout, page)` (step-2 switch between `photoLeft` and `photoRight`; one undo step):

| Flips | Does not flip |
| --- | --- |
| every box (hidden ones too): `x' = W − x − w` | `order`, sizes, y, h |
| `month.align`, `wk.align`, `cap.align` (`start ↔ end`, `middle` stays) | gradient angles and stops, picture fit |
| everything in `flipRowStyles`: `cal.numberSide`, `cal.numAlign`, `cal.dayAlign`, `ev.align`, `time.align` (non-null values), every `cal.iconCells[*][*].align`, `offsets[*].x → −x` | `photo.shadow.dx`, `hol.wrapWidth`, `cal.iconCellW`, `ev.box` (flow-anchored), `icons.order` (position 1 stays nearest the zone edge) |

`flipRowStyles(styles)` returns a copy with exactly the row-internal flips of the left column. The
renderer applies it to frame `cal2` of a 14-day page; stored styles are never changed by that.

### B.6 Content

```ts
type Content = {
  v: 1;
  clubs:        { [id]: { code: string /*≤6*/; name: string /*≤60*/; pos: number } };
  events:       { [id]: { date: string; name: string /*≤80*/; club: string | null /*club id*/; icon: string | null } };
  names:        { [id]: { date: string; name: string /*≤40*/ } };
  wasteKinds:   { [id]: { builtin: 'kom' | 'bio' | 'plast' | 'papir' | 'sklo' | null; name: string /*≤30*/; on: boolean;
                          place: string /*≤40*/; mode: 'rule' | 'dates'; weekdays: number[] /*0=Po…6=Ne, ≥1*/;
                          interval: 'w' | '2e' | '2o' | '4w' | '4w2' | 'm'; from: string | null; to: string | null /*null = whole calendar year*/;
                          dates: string[]; pos: number } };
  wasteExtras:  { [id]: { kind: string /*waste kind id*/; date: string } };          // single collections added in step 5
  moonEdits:    { [id]: { op: 'add' | 'remove'; date: string; phase: 0 | 1 | 2 | 3 } };
  shiftOps:     { [id]: { name: string /*≤30*/; shifts: { letter: string /*≤2*/; from: string; to: string /*HH:MM*/ }[] /*1–4*/;
                          crews: [string, string, string, string] /*≤2 each*/; pos: number } };
  specialPages: { [id]: { kind: 'intro' | 'contacts' | 'year' | 'waste' | 'image' | 'text'; on: boolean; layout: number;
                          position: 'front' | 'back'; nextYear: boolean; name: string /*≤60*/;
                          asset: string | null; text: string | null /*≤6000*/; fileName: string | null /*≤120*/; pos: number } };
  pagePhotos:   { [pageKey]: { asset: string; zoom: number /*1–3*/; px: number; py: number /*0–1*/ } };
  pageCaptions: { [pageKey]: { text: string /*≤80*/ } };
  pageImages:   { [pageKey]: { head: string | null; bg: string | null } };
  pageQr:       { [pageKey]: { asset: string } };
  comments:     { [id]: { date: string; text: string /*≤500*/; author: 'designer' | 'client'; at: string /*ISO 8601, server*/ } };
};
```

Rules:

- Item ids are client-generated `[A-Za-z0-9_-]{8,40}` (`newId()` = 16 random base-62 characters). The
  generated special pages use the fixed ids `intro`, `contacts`, `year`, `waste`; a missing item means
  the default `{ on: false, layout: 0, position: 'front', nextYear: kind === 'year', name: '', asset: null, text: null, fileName: null, pos: index }`
  and it is written on first change. Uploaded pages (`image`, `text`) have random ids and are really
  deleted. Unlike the prototype nothing is switched on when the feature is enabled (map §10 item 2).
- Ordered collections (`clubs`, `wasteKinds`, `shiftOps`, `specialPages`) sort by `pos`, ties by id;
  a reorder rewrites `pos` of the moved items (0, 1, 2 …). Dated collections sort by date, then id.
- `events.club` holds the club **id**; renaming a code needs no propagation; a deleted club's events
  print without a code.
- `moonEdits`: effective phases = `computeMoonPhases(year)` minus every `remove` (same date and phase)
  plus every `add`. Changing a date in the tool = one `remove` + one `add`. **Přepočítat podle roku**
  deletes all edits.
- Page-keyed collections: photos, captions and QR apply to week pages; `pageImages.head` to week pages,
  `pageImages.bg` to any page. "Has overrides" (dot, pill **Upraveno**) = any of the four collections
  has that page key. **Vrátit na styl** deletes all four items of the page.
- `comments` are append-only: the server accepts only inserts and overwrites `author` and `at`.
- A new calendar's content is `{ v: 1, wasteKinds: { kom, bio, plast, papir } }` (deviation D5), each
  `{ builtin: <id>, name: <built-in name>, on: false, place: '', mode: 'rule', weekdays: [0], interval: 'w', from: null, to: null, dates: [], pos: 0…3 }`.
  Built-in table `BUILTIN_WASTE` (id, name, colour): `kom` Komunální odpad `#3B3A3F` · `bio` Bio odpad
  `#7A5230` · `plast` Plast `#F2D23C` · `papir` Papír `#2EA3D6` · `sklo` Sklo `#3F8F5A`. A kind's colour
  is `styles.waste.kinds[id].color ?? BUILTIN_WASTE[builtin].color ?? '#8A8F98'`.

Client whitelist (collections a client may write): `pagePhotos`, `pageCaptions`, `wasteKinds`,
`wasteExtras`, `events`, `clubs`, `names`, `comments`. Everything else, and every design key, is
designer-only.

### B.7 Constants (`K` in `model/defaults.js`) and limits

All mm unless noted; converted from the prototype's raw units at 0.31 mm per unit.

| Name | Value | Prototype | Use |
| --- | --- | --- | --- |
| `MM_PER_PT` | 25.4 / 72 | — | pt → mm |
| `GRID` | 0.1 | 0.5 u | `roundMm` of every stored box value |
| `MIN_W`, `MIN_H` | 6, 4 | 20 × 12 u | minimum box |
| `NUDGE`, `NUDGE_BIG` | 0.3, 3 | 1, 10 u | arrow keys |
| `FIELD_STEP` | 0.5 | 0.5 mm | X / Y / Š / V steppers |
| `LINK_TOL` | 0.15 | 0.6 u | edges coincide when `abs(a − b) ≤ LINK_TOL` |
| `SNAP_PX` | 6 (screen px) | 6 px | snap threshold; `DRAG_PX` = 3 |
| `ROW_PAD` | 5 | 16 u | row side padding |
| `NUM_GAP` | 3 | 10 u | number ↔ text |
| `ROW_SLACK` | 2 | 6 u | unused strip at the frame bottom |
| `COMPACT_BELOW` | 11.8 | 38 u | row height under which rows are compact |
| `COMPACT_BASE` | 9.3 | 30 u | `sc = min(1, rh / COMPACT_BASE)` in compact mode |
| `COMPACT_TEXT_MIN` | 24 | — | text column width a compact row tries to keep when the icon slots would squeeze it (§E.6.7) |
| `SHIFT_MIN_ROW` | 10.5 | 34 u | shift columns need at least this row height |
| `SHIFT_COL_W`, `SHIFT_COL_GAP`, `SHIFT_END_GAP` | 5, 0.3, 2 | 16, 1, 6 u | shift columns |
| `ICON_GAP` | 1.2 | 4 u | cell width = icon size + gap |
| `ZONE_END_GAP` | 2 | 6 u | gap between the icon zone and the text column |
| `EMPTY_SLOT`, `EMPTY_SLOT_W` | 6, 7.5 | 20, 24 u | empty dashed icon slot |
| `EVBOX_MIN_W`, `EVBOX_MIN_H` | 6, 1.5 | 20 × 4 u | event box minimum |
| `ICON_CELL_MIN` | 2 | 6 u | minimum icon cell width |
| `HOL_WRAP_MIN` | 6 | 20 u | minimum holiday wrap width |
| `PART_SNAP_BACK` | 0.3 | 0.75 u | part offset snaps to 0 inside this |
| `CAP_INSET_X`, `CAP_INSET_Y` | 2.5, 1 | 8, 3 u | caption inset when its background is off |
| `TEXT_GROW_MARGIN` | 0.6 | 2 u | auto-grow margin each side |
| `UNDO_LIMIT` | 100 | 60 | undo entries kept |
| `ZOOM_STEPS` | `[0.5, 0.75, 1, 1.25, 1.5, 2, 3, 4]` | same | |
| `FIT_MIN`, `FIT_MAX` | 1, 5.2 (px per mm) | 0.3, 1.6 px/u | clamp of the fit scale |

`LIMITS` (handoff §6.1, real units; `clampLimit(key, v)` clamps and rounds to the step):

| Key | Min | Max | Step |
| --- | --- | --- | --- |
| `fontSize` | 3 | 60 | 0.5 |
| `sliderNum` / `sliderDay` / `sliderText` | 8 / 3 / 3 | 40 / 16 / 14 | 0.5 |
| `lineWidth` (pt) | 0.1 | 5 | 0.1 |
| `iconSize` | 1.5 | 12 | 0.5 |
| `iconCellSize` | 1 | 30 | 0.5 |
| `moonSize` | 1.5 | 9 | 0.1 (the stepper buttons move by 0.2) |
| `photoBorder` | 0.1 | 10 | 0.1 |
| `shadowOffset` | −10 | 10 | 0.5 |
| `pad` | 0 | 20 | 0.5 |
| `percent` | 0 | 100 | 5 |
| `generic` (radius, shadow blur) | 0 | 30 | 0.5 |
| `bleedMm` | 0 | 10 | 0.5 |
| `photoZoom` | 1 | 3 | 0.01 |

### B.8 Versioning, normalising, layout files

- `layout.v` and `content.v` are both `1`. `normalizeDocument(raw, env) → Doc` is the single entry
  point run on every bootstrap and every save response: it fills `page`, `print`, `features` from the
  defaults when the stored JSON is `{}`, deep-merges `defaultStyles(env)` under the stored styles (so a
  later version can add a style key without a migration), drops unknown top-level keys, ensures `cal2`
  matches the type, removes element keys from `order` that do not exist and appends missing ones on
  top, clamps every numeric style to `LIMITS`, and ensures every content collection key exists (`{}`).
  `migrate(raw)` runs first and is a `switch` on `v` (identity for 1). The function is pure and
  idempotent: `normalize(normalize(x))` deep-equals `normalize(x)` (tested).
- Layout file (**Uložit layout do souboru** / **Ze souboru layoutu**):
  `{ "wboostCalendarLayout": 1, "type": "week", "preset": "photoLeft", "page": PageProps, "bleedMm": 5, "sourceCalendar": "<uuid>", "layout": Layout }`,
  file name `<slug of the calendar name>-layout.json`. `parseLayoutFile(text, design)` accepts it only
  when `wboostCalendarLayout === 1`, `layout.boxes` and `layout.styles` are objects and `type` equals
  the current type; otherwise `{ ok: false }` → toast `Soubor neobsahuje layout kalendáře`. A file
  made for another page size is scaled box-wise by the width / height factors; bleed edges follow the
  current bleed (`adaptBoxesToBleed` from the file's `bleedMm`); styles missing in the file get the
  defaults of an empty environment (no project font, fallback accent), so the result is always a
  fully materialised layout. Asset ids are remapped
  by the server (§C.7).

---

## C. Server

Namespace `WBoost\Web`. Follow `codebase-notes.md` for every convention not restated here (one
invokable controller per action, CQRS messages, uuid7 ids from `ProvideIdentity`, explicit timestamps
from `ClockInterface`, hand-written migrations, buffered responses only).

### C.1 Entities and tables

**`calendar`** — `src/Entity/Calendar.php`

| Column | Type | Default | Notes |
| --- | --- | --- | --- |
| `id` | uuid PK | | |
| `project_id` | uuid FK → `project` `ON DELETE CASCADE`, indexed | | |
| `name` | varchar(60) | | |
| `year` | integer | | |
| `type` | varchar(16), enum `Value/Calendar/CalendarType` (`Week = 'week'`, `Fortnight = 'fortnight'`) | `'week'` | |
| `page` | jsonb | `'{}'` | `PageProps`; `{}` until the first design save (JS fills the default) |
| `print` | jsonb | `'{}'` | `PrintSettings` |
| `features` | jsonb | `'{}'` | `Features` |
| `layout_preset` | varchar(16) null | null | `photoLeft` / `photoRight` / `twoSided` |
| `layout` | jsonb null | null | `Layout`. Hydrated as an **object tree** (`stdClass`, Doctrine type `json_object` = `Doctrine/JsonObjectDoctrineType`), never as PHP arrays: the layout holds maps that are empty or keyed by digits (`styles.offsets`, `styles.cal.iconCells["4"]["0"]`), and a PHP array would return them as JSON lists. The server therefore stores and returns the layout exactly as sent (jsonb only reorders keys). |
| `content` | jsonb | `'{}'` | `Content` |
| `step` | smallint | 1 | |
| `finished` | boolean | false | |
| `link_elements` | boolean | true | |
| `client_token` | varchar(64), unique index | | `bin2hex(random_bytes(32))`, set at creation |
| `revision` | integer | 0 | +1 on every successful save |
| `created_at`, `updated_at` | timestamp immutable | | |

The server never needs page dimensions or feature defaults, which is why the three settings columns may
be `{}`: `defaults.js` stays the only place that knows 310 × 145.

**`calendar_asset`** — `src/Entity/CalendarAsset.php`

| Column | Type | Notes |
| --- | --- | --- |
| `id` | uuid PK | also the file's base name |
| `calendar_id` | uuid FK `ON DELETE CASCADE`, indexed | |
| `kind` | varchar(16), enum `CalendarAssetKind`: `photo`, `logo`, `icon`, `qr`, `image`, `page` | `image` = header / background picture, `page` = special-page graphic |
| `path` | varchar(255) | `calendars/{calendarId}/{assetId}.{ext}` |
| `preview_path` | varchar(255) null | `calendars/{calendarId}/{assetId}-preview.webp`; only when a raster's long edge > 1600 px (`DownscaleImage::toLongEdge(1600, Webp)` — WebP, not JPEG, so a large logo or icon keeps its transparency) |
| `mime_type` | varchar(64) | of the stored file |
| `original_name` | varchar(255) | |
| `width`, `height` | integer null | pixels of the stored file; null only for SVG |
| `description` | varchar(255) null | embedded caption (EXIF `ImageDescription` / XMP `dc:description`), trimmed |
| `size_bytes` | integer | |
| `uploaded_by_kind` | varchar(16), enum `CalendarAuthorKind` (`designer`, `client`) | |
| `uploaded_by_id` | uuid FK → `user` `ON DELETE SET NULL`, null | |
| `created_at` | timestamp immutable | |

**`calendar_history`** — `src/Entity/CalendarHistoryEntry.php`, index `(calendar_id, created_at)`

| Column | Type |
| --- | --- |
| `id` uuid PK · `calendar_id` FK cascade · `author_kind` varchar(16) (`CalendarAuthorKind`) · `user_id` FK → `user` SET NULL, null · `description` varchar(255) · `created_at` timestamp immutable | |

One migration `migrations/Version<timestamp>.php` creates the three tables (generate with
`doctrine:migrations:diff`, tidy names, real `down()`). No backfill: the module is opt-in and not part
of `ProjectModule::defaults()`; `Project::$modules`' default literal stays untouched.

Repositories: `CalendarRepository` (`get`, `getForUpdate` = `find(…, LockMode::PESSIMISTIC_WRITE)`,
`getByClientToken`, `forProject`, `add`, `remove`), `CalendarAssetRepository` (`get`, `add`, `forCalendar`,
`existingIds(calendarId, ids)`), `CalendarHistoryRepository` (`add`, `latest(calendarId, limit)`).
Exceptions `CalendarNotFound`, `CalendarAssetNotFound`, `InvalidCalendarChange` (message is the Czech
text shown to the user), `CalendarChangeForbidden`.
Read model `Query/GetCalendars::forProject(projectId)` → `list<Value/Calendar/CalendarListRow>` (the
`Calendar` + `lastChangeBy` / `lastChangeAt` of its newest history entry, read via one lateral join).

### C.2 Values and services (`src/Value/Calendar/`, `src/Services/Calendar/`)

| Class | Responsibility |
| --- | --- |
| `Value/Calendar/CalendarActor` | `{ CalendarAuthorKind $kind, null\|UuidInterface $userId }`; `isDesigner()`. |
| `Value/Calendar/CalendarSavePayload` | `fromJson(string)`: `MAX_BYTES = 2_000_000`; parses `{ design?, content?, finish? }` (the payload must be a JSON object); throws `InvalidCalendarChange`. `design` is kept as an object tree (`stdClass`) so the layout survives untouched; op values are plain arrays. |
| `Value/Calendar/CalendarContentOp` | `{ string $collection, string $id, null\|array $value, string $label }`. |
| `Services/Calendar/CalendarContentRules` | Collection registry: allowed keys and types, string caps, id patterns, which collections a client may write, insert-only collections, per-item byte caps. Pure, unit-tested. `assertWritable(op, actor)` (client whitelist → `CalendarChangeForbidden`), `assetIds(op)`, `validate(op, actor, year, existingAssets, stored, now)` → the item rebuilt from the known keys (null = delete). |
| `Services/Calendar/CalendarDesignRules` | Validates a design patch structurally (§C.5). Pure. `validate(patch: stdClass, currentType, currentPreset)` → normalised array; `layout(mixed)` validates one Layout document. |
| `Services/Calendar/CalendarLayoutAssets` | `ids(layout, strict = true)` and `rewrite(layout, map)` — the asset-reference walk of a layout (§C.5 step 3), shared by save and layout import. |
| `Services/Calendar/CalendarContentSeed` | `initial(): array` — the four waste kinds of §B.6. |
| `Services/Calendar/DescribeCalendarChange` | Turns an applied op (collection, kind of change, old/new value, label) into the Czech history sentence (§C.6). Pure. |
| `Services/Calendar/CalendarDocument` | `forCalendar(Calendar, CalendarActor): array` — the bootstrap / save-response document (§C.4), including the asset map with public URLs. |
| `Services/Calendar/CalendarSaver`, `CalendarAssetUploader`, `CalendarHistoryList` | The save / upload / history endpoints behind **both** route families (`/calendar/{id}/…` and `/k/{token}/…`); the controllers only resolve the calendar and the actor. `CalendarResponses` holds the shared JSON error answers, `CalendarHistoryWriter` adds history lines for the handlers. |
| `Services/Calendar/CalendarAccess` | Resolves the actor for a request: `forUser(Calendar)` (EDIT → designer, VIEW → client) and `forToken(string $token): array{Calendar, CalendarActor}` (404 on unknown token). |
| `Services/Calendar/ReadImageDescription` | Embedded caption from the **original** upload bytes (before `NormalizeImageFormat`): Imagick property `exif:ImageDescription`, else the first `<rdf:li>` of XMP `dc:description`; trimmed, ≤ 255. |
| `Services/Calendar/RasterizePdfPage` | First PDF page → PNG via Imagick (`setResolution(200, 200)`, long edge capped at 4000 px). Throws `InvalidCalendarChange('PDF se nepodařilo přečíst. Nahrajte stránku jako obrázek.')`. |
| `Services/Calendar/ExtractDocumentText` | `.docx` (ZipArchive → `word/document.xml`, one line per `w:p`, text of `w:t`) or `.txt`; returns paragraphs joined by `\n`, ≤ 6000 characters. |
| `Services/Calendar/ImportCalendarLayout` | `plan(target, layout, source, actor)` → the layout with remapped asset ids + one `CopyCalendarAsset` message per picture (§C.7). |
| `Services/Security/CalendarVoter` | `calendar_view` / `calendar_edit`, delegating to the project exactly like `WeeklyMenuVoter`. |

### C.3 Messages and handlers (`src/Message/Calendar/`, `src/MessageHandler/Calendar/`)

| Message | Handler effect |
| --- | --- |
| `CreateCalendar(calendarId, projectId, userId)` | Row with `name = "Stolní kalendář {Y}"`, `year = Y` (`Y` = clock year + 1), `type = week`, `content = CalendarContentSeed::initial()`, fresh `client_token`; history `Vytvořil kalendář`. |
| `SaveCalendar(calendarId, CalendarActor, ?stdClass designPatch, list<CalendarContentOp> ops, bool finish)` | §C.5. `designPatch` is the raw object tree of the request (see `layout` in §C.1); a caller with content only (MCP) passes `null`. |
| `DeleteCalendar(calendarId)` | Collect `calendars/{id}` first, remove the row (assets + history cascade), then `Filesystem::deleteDirectory`. |
| `UploadCalendarAsset(assetId, calendarId, kind, path, contents, ?previewPath, ?previewContents, mime, originalName, ?width, ?height, ?description, CalendarActor)` | Writes the file(s) through Flysystem and adds the row. |
| `CopyCalendarAsset(newAssetId, sourceAssetId, targetCalendarId, CalendarActor)` | Layout import: copies the file (+ preview) of an asset of another calendar **of the same project** under the new id and adds its row. |
| `RegenerateCalendarClientLink(calendarId, CalendarActor)` | New `client_token`; history `Vygeneroval nový odkaz pro klienta`. |
| `RecordCalendarHistory(calendarId, CalendarActor, description)` | Plain history insert (used for `Zkopíroval odkaz pro klienta`, layout imports). |

`DeleteProjectHandler` needs no change beyond §C.9 (`CollectProjectStoragePaths`).

### C.4 Controllers, routes, bootstrap

All under `src/Controller/Calendar/`. Never put `/preview` into a URL (the public regex in
`config/packages/security.php` contains an unanchored `.*/preview`). Every authenticated route carries
its own `#[IsGranted]`; every POST checks the CSRF token id `calendar`
(`CalendarDocument::CSRF_TOKEN_ID`) from the multipart field `_token` and answers JSON 403
`{ "error": "Platnost formuláře vypršela. Obnovte stránku." }` on failure.

| Route name | Method, path | Guard | Answers |
| --- | --- | --- | --- |
| `calendars` | GET `/project/{projectId}/calendars` | `ProjectVoter::VIEW`, `#[RequiresProjectModule(ProjectModule::Calendars)]` | list page (replaces the teaser `Controller/Project/CalendarController.php`, which is deleted) |
| `calendar_create` | POST `/project/{projectId}/calendars/create` | `ProjectVoter::EDIT` + CSRF | 302 → `calendar_workspace` |
| `calendar_workspace` | GET `/calendar/{calendarId}` | `CalendarVoter::VIEW` | workspace page; mode from `CalendarAccess::forUser` |
| `calendar_save` | POST `/calendar/{calendarId}/save` | `CalendarVoter::VIEW` | §C.5 |
| `calendar_asset_upload` | POST `/calendar/{calendarId}/asset` | `CalendarVoter::VIEW` | §C.7 |
| `calendar_document_text` | POST `/calendar/{calendarId}/document-text` | `CalendarVoter::EDIT` | `{ text, name }` |
| `calendar_layout_import` | POST `/calendar/{calendarId}/layout-import` | `CalendarVoter::EDIT` | §C.7 |
| `calendar_history` | GET `/calendar/{calendarId}/history` | `CalendarVoter::VIEW` | `{ entries: [{ who: 'Grafik'\|'Klient', what, at }] }`, newest first, max 200 |
| `calendar_client_link` | POST `/calendar/{calendarId}/client-link` | `CalendarVoter::EDIT` | field `action` = `copied` (history only) or `regenerate`; answers `{ url }` |
| `calendar_delete` | POST `/calendar/{calendarId}/delete` | `CalendarVoter::EDIT` + CSRF | 302 → `calendars` with flash `Kalendář „{name}“ smazán` |
| `calendar_client` | GET `/k/{token}` | public | standalone client page |
| `calendar_client_save` | POST `/k/{token}/save` | public (token) | §C.5, actor = client |
| `calendar_client_asset` | POST `/k/{token}/asset` | public (token) | §C.7, kinds `photo`, `icon` only |
| `calendar_client_history` | GET `/k/{token}/history` | public (token) | as `calendar_history` |

`{token}` has the requirement `[a-f0-9]{64}`; an unknown token is a 404. `{calendarId}` has the
requirement `Calendar::ID_REQUIREMENT` (any UUID-shaped id). The four `/k/{token}…` routes have their own
thin controllers (`ClientCalendarController`, `SaveClientCalendarController`,
`UploadClientCalendarAssetController`, `ClientCalendarHistoryController` — one invokable controller per
action, and a public route cannot share the `#[IsGranted]` of its twin); both families call the same
services (`CalendarSaver`, `CalendarAssetUploader`, `CalendarHistoryList`). `security.php`: append
`|k/[a-f0-9]{64}` to the alternation of the public-paths regex (the entry that lists
`weekly-menu/.*/approval/.*`). The token addresses the calendar by itself — never combine it with the
calendar uuid in a URL.

Templates (`templates/calendar/`):

| File | Content |
| --- | --- |
| `list.html.twig` | Extends `base.html.twig`; blocks `eyebrow` (`Projekt · {name}`), `title` (`Kalendáře`), `page_lead`, `title_actions` (one primary: form-button **Vytvořit kalendář**, only with `project_edit`), `mobile_primary_action`. Body: `.cal-list` of cards or the empty state (map §8.3). Root `data-controller="calendar-list"`. Each card: link to the workspace, an empty `.cal-thumb` and `.cal-meta` to be filled by JS, name, pill `Rozpracovaný vzhled` when `!finished`, history chip `{who} · {relative time}` (button, opens the history modal), delete button (designer only) opening a Bootstrap confirm modal that posts `calendar_delete`. JSON for the controller: once per page `<script type="application/json" data-calendar-list-target="env">` = the shared part of `env` (`projectName`, `fonts`, `palette`, `currentYear`, `maxFileSize`, `csrf`); per card (`li.cal-card[data-calendar-list-target="card"][data-calendar-id]`) one `<script type="application/json" data-calendar-list-target="doc">` = `{ calendar, assets, urls: { workspace, history } }`. The history chip is `button[data-cal-action="history"][data-calendar-id][data-history-url][data-calendar-name]` holding `<time datetime="ISO" data-calendar-list-target="time">` (server text: absolute date). The page works without JS: create and delete are plain forms, delete confirms in a Bootstrap modal `#cal-delete-{id}`. |
| `workspace.html.twig` | Extends `base.html.twig`, overrides `page_title_row` with an empty block, `inner_class` = `inner-wide`, sets `active_menu_item = 'calendars'`; add `calendar_workspace` to the `shell_force_rail` route list in `base.html.twig`. Emits one `@font-face` per project font face (copy `templates/editor/_editor_fonts.html.twig`). Body: `<div class="cal-ws" data-controller="calendar-workspace" data-calendar-workspace-mode-value="designer\|client">` + `<script type="application/json" data-calendar-workspace-target="bootstrap">`. All inner DOM is built by JS. |
| `client.html.twig` | Extends `base.html.twig` through `full_content` (no sidebar, no top bar), same fonts + root element with mode `client`, `<meta name="robots" content="noindex">`. |

Bootstrap JSON (also the shape of `CalendarDocument::forCalendar`):

```json
{
  "mode": "designer",
  "calendar": { "id": "…", "revision": 7, "name": "…", "year": 2027, "type": "week", "page": {}, "print": {},
                "features": {}, "preset": null, "layout": null, "linkElements": true, "step": 1, "finished": false,
                "content": { "v": 1, "wasteKinds": { } } },
  "assets": { "<assetId>": { "url": "…", "previewUrl": "… or null", "width": 4000, "height": 3000, "mime": "image/jpeg", "name": "IMG_1.jpg", "description": null, "kind": "photo" } },
  "env": {
    "projectName": "…", "fonts": [ { "family": "Rubik (Rubik Bold)", "fontName": "Rubik", "faceName": "Rubik Bold", "weight": 700, "style": "normal", "url": "…" } ],
    "palette": ["#C84B55"], "currentYear": 2026, "maxFileSize": 10000000, "csrf": "…",
    "siblings": [ { "id": "…", "name": "…", "type": "week", "hasLayout": true } ],
    "urls": { "list": "…", "save": "…", "asset": "…", "history": "…", "clientLink": "…", "layoutImport": "…", "documentText": "…" },
    "clientUrl": "https://…/k/<token>"
  }
}
```

Fonts come from `ResolveRichTextOptions::projectFaces(GetFonts::allForProject(…), UploaderHelper)`,
the palette from `ProjectColorRepository::forProject()` (`#RRGGBB` upper case, palette order). In client
mode `siblings`, `clientUrl`, and the urls `clientLink`, `layoutImport`, `documentText` are omitted. On the
public link (`/k/{token}`) `save` / `asset` / `history` point at the `/k/{token}/…` routes and there is no
`list` url; a logged-in read-only member (client mode inside the app shell) keeps the `/calendar/{id}/…`
endpoints and `list`, so the token is never handed to a user account. `mode` is `designer` only for
`calendar_edit`.
Because Twig encodes an empty hash as `[]`, `CalendarDocument` casts every map (`page`, `print`,
`features`, `content`, each collection, `assets`) to `stdClass`/`ArrayObject` before encoding so empty
maps arrive as `{}`. `layout` needs no cast: it is stored and hydrated as an object tree (§C.1).

### C.5 Save endpoint contract

Request: multipart `FormData` with `_token` and `payload` (JSON string, ≤ 2 000 000 bytes):

```json
{
  "design": { "name": "…", "layout": { }, "step": 3 },
  "content": [
    { "c": "events", "id": "Ab3…", "v": { "date": "2027-06-01", "name": "Den dětí", "club": null, "icon": null }, "label": "1. 6." },
    { "c": "pagePhotos", "id": "w05", "v": null, "label": "5. týden" }
  ],
  "finish": false
}
```

- The payload itself must be a JSON object (`{}` is a valid save that only bumps `revision`; `[]` is 422).
- `design` (optional): a **patch of top-level design keys** — any subset of `name`, `year`, `type`,
  `page`, `print`, `features`, `preset`, `layout`, `linkElements`, `step`, `finished`. Each present key
  replaces the stored value whole. The client sends only keys that changed since the last server
  state.
- `content` (optional): list of ops, ≤ 2000 per request; `v: null` deletes; `label` ≤ 120 characters.
- `finish: true` (designer only): sets `finished = true`, `step = 5`; history `Dokončil kalendář`
  the first time, `Uložil vzhled kalendáře` afterwards.

Handler algorithm (`SaveCalendarHandler`, inside the Messenger transaction):

1. `getForUpdate(calendarId)` (row lock).
2. Client actor: a present `design` key or `finish` → `CalendarChangeForbidden`; any op whose
   collection is not on the client whitelist → `CalendarChangeForbidden`. The whole request is
   rejected, nothing is applied.
3. Design patch → `CalendarDesignRules::validate`: `name` 1–60 characters after trim; `year` integer
   2000–2100; `type` enum; `preset` null or enum and compatible with `type` (checked on the values the calendar has **after** the patch: a `type` change must come with a
   fitting `preset` when the stored one no longer fits); `step` 1–5; booleans;
   `page`, `print`, `features` objects with the exact keys of §B.1 and finite numbers (`width`,
   `height` 50–2000; `bleedMm` 0–10); `layout` null or an object ≤ 200 000 bytes encoded with exactly the
   keys `v` (= 1), `boxes`, `order`, `styles` — any other top-level key is refused; `boxes` (object; known
   keys, not necessarily all; each `{ x, y, w, h }` finite numbers in `[-1000, 5000]`, `w`, `h` > 0, plus
   optional `hidden: true` on `logo`, `month`, `wk`, `qr` only), `order` (list of known element keys
   without `bg`, no duplicates), `styles` (object; any JSON; every string ≤ 255 characters; nesting depth
   ≤ 8, `styles` itself being level 1).
   Asset ids found anywhere in the layout (keys `asset`, `icon`, members of `icons`/`assets` arrays)
   must exist in `calendar_asset` for this calendar. Violations → `InvalidCalendarChange`.
4. Each op → `CalendarContentRules::validate`: known collection; id matches the collection's pattern
   (`[A-Za-z0-9_-]{1,40}`; page-keyed collections `pre|post|w\d\d|cover|back|sp:[A-Za-z0-9_-]{1,40}`);
   value keys exactly those of §B.6 with the right types and caps; dates are real calendar dates inside
   `[year−1-12-01, year+1-01-31]` using the year **after** the design patch; asset ids exist for this
   calendar; item ≤ 16 000 bytes (`specialPages` ≤ 32 000). `comments`: insert only — the value is `{ date, text }` (`author` / `at`
   may be sent and are ignored), `author` and `at` are set from the actor and the clock, a delete is
   rejected, and so is an op on an existing id — with two exceptions that keep the rest working:
   re-sending a comment with the stored `date` and `text` is an *unchanged op* (a retried save must not
   fail), and a **designer** may change the `date` of an existing comment (the year change of §E.7 shifts
   comment dates; text, author and time stay as stored).
5. Apply: for each op in order, compare with the stored item — unchanged ops are skipped silently; set
   or unset `content[c][id]` (comparison ignores key order and `5` vs `5.0`; deleting an item that does
   not exist is unchanged too). The collection key is always kept (`{}` when empty). Total encoded
   content must stay ≤ 2 000 000 bytes.
6. History: one `CalendarHistoryEntry` per applied op (§C.6), at most 30 per request (then one extra
   entry `… a dalších {n} změn`); for a design patch that really changed a value (keys sent with their stored value do not count) one
   entry `Upravil vzhled a nastavení` unless the newest entry of this calendar is that same sentence by the same author kind and less
   than 10 minutes old; a patch containing only `step` writes no entry.
7. `revision += 1`, `updatedAt = now`.

Response 200: `CalendarDocument::forCalendar()` reduced to `{ calendar, assets }` plus
`"savedAt": "<ISO 8601>"`. Errors: 403 `{ error }` for CSRF and `CalendarChangeForbidden`
(`Tuto změnu může udělat jen grafik.`), 422 `{ error }` for `InvalidCalendarChange` (unwrap
`HandlerFailedException` as `ManualContentPageEditor::save` does), 404 for an unknown calendar/token.

Merge semantics: ops are item-level and independent, so two people changing different items both win;
for the same item the later request wins and both history entries exist (handoff §10.8). Design keys
are last-write-wins per key (risk R1).

### C.6 History sentences (`DescribeCalendarChange`)

Style guide: map §8.13 (past tense, masculine, no full stop). `{l}` = the op's `label` (omitted with
its dash when empty). "new" = id did not exist; "changed" = existed; "removed" = `v: null`.

| Collection | New | Changed | Removed |
| --- | --- | --- | --- |
| `pagePhotos` | `Nahrál fotku — {l}` | asset differs: `Vyměnil fotku — {l}`; only zoom/px/py differ: `Posunul fotku — {l}` | `Odebral fotku — {l}` |
| `pageCaptions` | `Upravil popisek — {l}` | same | `Odebral popisek — {l}` |
| `pageImages`, `pageQr` | `Upravil obrázky strany — {l}` / `Nahrál QR kód — {l}` | same | `Odebral obrázky strany — {l}` / `Odebral QR kód — {l}` |
| `events` | `Přidal akci „{name}“ — {l}` | `Upravil akci „{name}“ — {l}` | `Odebral akci „{old name}“` |
| `clubs` | `Přidal spolek „{name}“` | `Upravil spolek „{name}“` | `Odebral spolek „{old name}“` |
| `names` | `Přidal jméno „{name}“ — {l}` | `Upravil jméno „{name}“ — {l}` | `Odebral jméno „{old name}“` |
| `wasteKinds` | `Přidal druh odpadu „{name}“` | `Upravil svoz — {name}` | `Odebral druh odpadu „{old name}“` |
| `wasteExtras` | `Přidal svoz {kind name} — {l}` | — | `Odebral svoz {kind name} — {l}` |
| `comments` | `Komentář k {l}: {text}` (text cut to 160 characters) | — | — |
| `moonEdits` | `Upravil fáze měsíce` (coalesced: once per request) | | |
| `shiftOps` | `Přidal provoz „{name}“` | `Upravil šichtovnici — {name}` | `Odebral provoz „{old name}“` |
| `specialPages` | uploaded: `Přidal vlastní stránku „{name}“`; generated: `Upravil speciální stránky` | uploaded with a new asset/text: `Vyměnil vlastní stránku „{name}“`; else `Upravil speciální stránky` (coalesced) | `Smazal stránku „{old name}“` |

Labels the client sends: page-keyed collections → the page title (`5. týden`, `1. a 2. týden`,
`Titulní strana` …); dated collections → `{d}. {m}.`. Author = the actor's kind; the list shows
`Grafik` / `Klient`.

### C.7 Assets, layout import, document text

**Upload** (`UploadCalendarAssetController` / `UploadClientCalendarAssetController`, both calling
`CalendarAssetUploader`): multipart `_token`, `kind`, `file`. A file over 10 MB is refused first with
`Soubor je větší než 10 MB.`; a refused kind for a client is 403.

1. CSRF → actor → kind allowed for the actor (client: `photo`, `icon`).
2. `kind = page` and the upload is a PDF (`%PDF` magic, ≤ 10 MB) → `RasterizePdfPage` → continue with
   the PNG bytes. Otherwise validate with `Image(maxSize: self::MAX_SIZE)` (`MAX_SIZE = '10m'`, the
   decimal 10 MB; JS mirror `env.maxFileSize = 10000000` — record this mirror in
   `docs/architecture/gallery-and-uploads.md`).
3. `ReadImageDescription` on the original bytes; `NormalizeImageFormat::normalize()`; `null` + `<svg`
   in the first 1024 bytes → store as SVG (width/height null; SVG is refused for `kind = photo` with
   `Fotka musí být JPG, PNG nebo WebP.`); `null` otherwise → 422
   `Tento formát obrázku neumíme přečíst. Nahrajte PNG, JPG, WebP nebo SVG.`
4. Long edge > 1600 px → preview through `DownscaleImage::toLongEdge` (WebP, keeps transparency). A
   rasterised PDF page gets no `description`.
5. Dispatch `UploadCalendarAsset`; answer
   `{ "id", "url", "previewUrl", "width", "height", "mime", "name", "description", "kind" }`.

Pixel size is therefore recorded on every upload path (handoff §8). Uploading never changes the
calendar document: the client then references the id in layout or content and saves.

**Layout import** (`ImportCalendarLayoutController`): fields `_token` and either `from` (uuid of
another calendar) or `layout` — a JSON object `{ "layout": Layout, "sourceCalendar": "<uuid>" | null,
"type"?: "week" | "fortnight" }`, ≤ 200 000 bytes. The whole layout file of §B.8 has this shape (its other
keys are ignored), and so has the result of `parseLayoutFile`. With `from`: the source calendar must
belong to the **same project** (else 404 `{ error }`), have the same `type` (else 422 `Layout je pro jiný
typ kalendáře.`) and have a layout (else 422). With `layout`: a `type` that differs from the target's is
the same 422, anything that is not a valid Layout is 422 `Soubor neobsahuje layout kalendáře`; when
`sourceCalendar` is missing, foreign or deleted, every asset reference becomes `null`.
`ImportCalendarLayout::plan` walks the layout and, for each referenced asset of the source calendar,
rewrites the id and emits a `CopyCalendarAsset` message whose handler copies the file(s)
(`Filesystem::copy`) to `calendars/{targetId}/{newAssetId}.{ext}` and adds the `calendar_asset` row.
Answers `{ layout, assets, name }` (`assets` = map of the new ids as in the bootstrap; `name` = the source
calendar's name, `null` for a file); writes history `Načetl layout z „{name}“` or `Načetl layout ze souboru`. The
calendar row is **not** modified: the client applies the layout as one undo step and saves it like any
other design change.

**Document text** (`ExtractCalendarDocumentTextController`): field `file` (`.docx` or `.txt`, ≤ 10 MB)
→ `{ text, name }` (`name` = file name without extension). Anything else → 422
`Nahrajte dokument ve formátu DOCX nebo TXT.`

### C.8 List page and module wiring (phase P1)

- `src/Value/ProjectModule.php`: add `case Calendars = 'calendars'` — label `Kalendáře`, description
  `Stolní kalendáře v brandu projektu — data, jmeniny a státní svátky doplní systém.`, icon `calendar`,
  landing route `['calendars', 'projectId']`; fix the class docblock. Not in `defaults()`.
- `templates/_shell/_sidebar.html.twig`: a `hasModule(project_module.Calendars)` block after
  Jídelníčky, `project_active == 'calendars'` (set by `active_menu_item` in the three calendar
  templates). `ProjectModulesTest`'s pinned menu becomes
  `[…, 'E-maily', 'Jídelníčky', 'Kalendáře', 'Facebook a Instagram']` (fixture project 1 has every
  module).
- `templates/project_modules.html.twig`: remove the hard-coded "Kalendáře · Připravujeme" row and the
  `+ 1` in the count.
- Delete `templates/calendars.html.twig`, `src/Controller/Project/CalendarController.php` and the
  teaser CSS block in `assets/styles/nocturne/manual.css`.
- Update the tests that assert the calendar link is absent (`ProjectModulesTest` ~l.42 stays valid for
  project 2 — the module is off there; `ProjectDashboardControllerTest` ~l.42: adjust the comment, keep
  the assertion for a project without the module, add one for fixture project 1).
- `tests/DataFixtures/TestDataFixture.php`: constants `CALENDAR_1_ID` (project 1, unfinished, no
  layout, one history entry), `CALENDAR_2_ID` (project 1, finished, step 5, `photoLeft` layout),
  `CALENDAR_3_ID` (project 2, `photoLeft` layout — the "other project" case), their
  `CALENDAR_{1,2,3}_TOKEN`, `CALENDAR_YEAR` (2027) and `CALENDAR_LAYOUT_JSON` — a minimal hand-written
  layout valid per §C.5 (with an empty map, a digit-keyed map and an empty list on purpose). P8a swaps
  it for `tests/Fixtures/calendar/layout-photo-left.json` from P2's harness.
- Not done in P1 (outside its file list, noted for the lead): the project dashboard
  (`templates/project_dashboard.html.twig`) has one tile per left-nav module but no Kalendáře tile yet
  (it needs a count from `ProjectDashboardController`); the now unused rule `.pd-mod-soon` stays in
  `assets/styles/nocturne/projects.css`.

### C.9 Storage and font registrations

| File | Change |
| --- | --- |
| `Services/Storage/BuildStorageReferenceIndex` | Two `queries()` entries: `calendar_asset.path` and `calendar_asset.preview_path` (`… FROM calendar_asset a JOIN calendar c ON c.id = a.calendar_id` + owner join on `c.project_id`; the second `WHERE a.preview_path IS NOT NULL`). Add `calendars` to `PATH_PREFIXES`. |
| `Services/Storage/ResolveStorageOwnerByPath::buildIndex()` | UNION arm `SELECT c.id AS entity_id … FROM calendar c` + owner join. |
| `Value/StorageCategory` | `case Calendar = 'calendar'`, label `Kalendář`, `str_starts_with($path, 'calendars/')`; extend `StorageCategoryTest`. |
| `Services/Storage/CollectProjectStoragePaths::collect()` | `calendars/{calendarId}` directory for every calendar of the project; extend its test. |
| `Services/Font/RewriteFontReferences` | Add target `['calendar', 'layout']` and make `replaceIn` scope per table (`calendar`: `project_id = :projectId AND layout IS NOT NULL`). Layout text styles store `"family": "<Font::faceFamily()>"`, so the existing quoted face-string pairs rewrite them; the bare-family `"fontFamily"` pair never matches calendars, which is correct. Extend the rewriter's test with a calendar row. |
| `docs/architecture/storage-inventory.md`, `gallery-and-uploads.md` | Record the new columns / the new 10 MB mirror (done in P1, not deferred). |

### C.10 PHP tests (phase P1)

| Test | Asserts |
| --- | --- |
| `tests/Controller/Calendar/CalendarsControllerTest` | list renders cards / empty state; module guard; create needs EDIT; create makes `Stolní kalendář {next year}` and redirects; delete needs EDIT + CSRF and removes row and storage directory. |
| `tests/Controller/Calendar/SaveCalendarControllerTest` | designer patch of each design key round-trips (R4); invalid shapes → 422 and nothing stored; payload cap; response document shape; `finish`. |
| `tests/Controller/Calendar/CalendarClientTest` | `/k/{token}` 200 without login, 404 for a wrong token; read-shared user gets `mode: client` on `calendar_workspace`; client save of a whitelisted op stores it and writes history as `Klient` (R3); client design patch → 403 and nothing stored (R2); client op on `specialPages` / `pageQr` / `shiftOps` → 403; comment insert sets author server-side, comment update/delete → 422; regenerate invalidates the old token. |
| `tests/MessageHandler/Calendar/SaveCalendarHandlerTest` | item-wise merge: two sequential saves touching different items both survive; same item → last wins with two history entries; unchanged op writes no history; 30-entry cap; date-range validation follows a year in the same patch; unknown asset id rejected. |
| `tests/Services/Calendar/CalendarContentRulesTest`, `DescribeCalendarChangeTest`, `CalendarDesignRulesTest` | rule tables and every sentence of §C.6. |
| `tests/Controller/Calendar/UploadCalendarAssetControllerTest` | width/height recorded; preview written for a large image; SVG logo accepted, SVG photo refused; client may upload `photo` but not `logo`; description read from a fixture JPEG. |
| `tests/Controller/Calendar/ImportCalendarLayoutControllerTest` | assets are copied to new ids under the target calendar; other project → 404; type mismatch → 422. |
| storage / font tests named in §C.9 | |

---

## D. Front end

### D.1 Rules for all calendar JS

- Plain ES modules under `assets/calendar/`, relative imports with the `.js` extension, no default
  exports except Stimulus controllers. Nothing outside `assets/calendar/ui/` and
  `assets/calendar/state/api.js` may touch `window`, `document`, `fetch` or timers — with one marked
  exception, `createCanvasMeasurer()` and `ensureFontsLoaded()` in `render/measure.js`. Everything else
  must import cleanly in node.
- Pure functions never mutate their arguments; they return new objects (structural sharing allowed).
- Dates are ISO strings `YYYY-MM-DD`; arithmetic goes through `generate/dates.js` (UTC based — never
  `new Date(y, m, d)` local time). Months are **1-based** in every API. Weekday `0 = Monday … 6 = Sunday`.
- All user-visible text is escaped through `esc()` (`ui/dom.js` for HTML, `render/svg.js` for SVG).
- No native `alert` / `confirm` / `prompt` (they freeze browser automation): use `confirmDialog()`.
- No number in any file may encode the page size; use `design.page`. Only `model/defaults.js` contains
  `310` / `145`. Test files and fixtures are exempt.

### D.2 Module map and contracts

`Doc`, `Design`, `Layout`, `Styles`, `Content`, `Box` are the types of §B. `Env` is the bootstrap `env`
plus `assets` and `mode`:
`Env = { mode, projectName, fonts, palette, accent, currentYear, maxFileSize, csrf, siblings, urls, clientUrl, assets }`
(`accent` is computed once by the workspace controller from `palette`).

#### model/ (owner P2)

| File | Exports |
| --- | --- |
| `model/defaults.js` | `SCHEMA_VERSION`, `DEFAULT_PAGE`, `DEFAULT_PRINT`, `DEFAULT_FEATURES`, `MARKS`, `K`, `LIMITS`, `COLORS`, `BUILTIN_WASTE`, `MM_PER_PT`; `bleedOf(print): number`; `clampLimit(key: keyof LIMITS, value: number): number`; `roundMm(v: number): number`; `ptToMm(pt: number): number`. |
| `model/elements.js` | `ELEMENTS: { key, label, icon, hideable: boolean, hasBox: boolean }[]` (order and labels of map §8.1); `PARTS: { key, label, group?: 'Číslo dne' }[]`; `ICON_CATEGORIES: { key, label, feature }[]`; `elementLabel(key)`; `partLabel(key)`; `boxKeysOf(elementKey, design): string[]` (`['cal', 'cal2']` for a fortnight); `elementOfBox(boxKey): ElementKey`; `isElementVisible(design, elementKey): boolean`; `visibleBoxKeys(design): string[]`; `availableParts(design): PartKey[]` (map §6.7 order; `moon` if `features.moon`, `time` if `timeChange \|\| seasons`, `ev` if `events`, `shift` if `shifts`, `waste` if `waste`); `activeIconCategories(design): IconCategory[]` (`styles.icons.order` filtered by feature); `addableIconCategories(design): IconCategory[]`. |
| `model/fonts.js` | `fontNames(fonts): string[]`; `facesOf(fonts, fontName): Face[]`; `nearestFace(fonts, fontName, weight): Face \| null` (upright preferred, nearest weight, ties → heavier); `faceByFamily(fonts, family): Face \| null`; `cssFont(style: TextStyle, fonts): { family: string, weight: number }` (project face → `'"<family>"'`, weight 400 because every face is its own family; `null` or unknown family → `'"Outfit", Arial, sans-serif'` with `style.weight` capped to 700); `weightLabel(weight): string`. |
| `model/layouts.js` | `presetsForType(type): Preset[]`; `defaultStyles(env: { fonts, accent }): Styles`; `defaultBoxes(preset, page, bleedMm): Boxes`; `defaultLayout(preset, page, bleedMm, env): Layout`; `flipRowStyles(styles): Styles`; `mirrorLayout(layout, page): Layout`; `rebaseLayoutForType(layout, newType, page, bleedMm): { layout: Layout, preset: Preset }` (§E.8). |
| `model/normalize.js` | `migrate(raw): raw`; `normalizeDocument(rawCalendar, env): Doc` (accepts the flat bootstrap `calendar` object **or** an already normalised `Doc`); `normalizeLayout(layout, type, page, env): Layout`; `normalizeContent(content): Content`; `exportLayoutFile(doc): object`; `parseLayoutFile(text: string, design): { ok: true, layout: Layout, sourceCalendar: string \| null } \| { ok: false }`. |
| `model/content.js` | `COLLECTIONS: { [name]: { clientWritable: boolean, ordered: boolean, dated: boolean, pageKeyed: boolean } }`; `newId(random?: () => number): string`; `sorted(collection: object, kind: 'pos' \| 'date'): [id, item][]`; `nextPos(collection): number`; `reorder(collection, ids: string[]): { [id]: item }` (items whose `pos` changed); `dateLimits(year): { min: string, max: string }` (`year−1-12-01` … `year+1-01-31`); `shiftContentYear(content, fromYear, toYear): { ops: { c, id, v }[], dropped: number }` (§E.7); `pageHasOverrides(content, pageKey): boolean`; `clearPageOverrides(content, pageKey): { c, id, v: null }[]`; `specialPageItem(content, id): SpecialPage` (default when missing); `layoutAssetIds(layout): string[]`. |

#### generate/ (owner P2)

| File | Exports |
| --- | --- |
| `generate/dates.js` | `iso(y, m, d): string`; `parts(isoDate): { y, m, d }`; `isValid(isoDate): boolean`; `addDays(isoDate, n): string`; `diffDays(a, b): number`; `weekday(isoDate): 0…6`; `mondayOf(isoDate): string`; `isoWeek(isoDate): { year, week }`; `daysInMonth(y, m): number`; `easterSunday(year): string` (map §5.3 algorithm); `compare(a, b): number`. |
| `generate/czech.js` | `MONTHS` (lower case, 12), `WEEKDAYS`, `WEEKDAYS_SHORT`, `WEEKDAY_INITIALS` (`P Ú S Č P S N`), `MOON_PHASES`, `SEASONS`, `WEEK_RULES: { key, label }[]`, `NAME_DAYS: string[][]` (copied verbatim from the prototype L4880–L4892), `FIXED_HOLIDAYS: { 'M-D': string }` (map §5.3); `nameDay(m, d): string`; `holidayName(isoDate): string` (`''` when none; Good Friday = Easter − 2 `Velký pátek`, Easter Monday = Easter + 1 `Velikonoční pondělí`); `pluralPages(n): string` (`strana` / `strany` / `stran`); `pluralTerms(n): string` (`termín` / `termíny` / `termínů`). |
| `generate/pages.js` | `buildPages(doc): Page[]` (§E.9); `weekPages(pages): Page[]`; `pageKeyOfWeek(mondayIso, year): string`; `monthText(firstIso, lastIso): string` (upper case, map §5.2); `weekText(form, weeks: number[]): string`; `pageTitle(page): string`; `pageRange(page): string`; `samplePage(doc, pages, selection): Page` (§E.10); `pageIndexOfDate(pages, isoDate): number` (−1 when none). |
| `generate/moon.js` | `computeMoonPhases(year): { date, phase }[]` (phases whose **Czech civil date** lies in `year−1-12-20 … year+1-01-12`; true phase instants by Meeus ch. 49 + ΔT + CET/CEST — **not** the prototype's mean-cycle approximation of map §5.4, which is off by a day too often; verified to the minute against published 2026–2028 instants and against an independent elongation formula in the `moon` suite); `moonPhaseInstants(fromYear, toYear): { phase, utcMs, date }[]` (tests / tooling); `effectivePhases(doc): Map<isoDate, 0…3>` (applies `moonEdits`; a later `add` on a date wins). |
| `generate/time.js` | `dstChange(isoDate): 'summer' \| 'winter' \| null` (last Sunday of March / October); `seasonStart(isoDate): 0 \| 1 \| 2 \| 3 \| null` (20 Mar, 21 Jun, 22 Sep, 21 Dec); `timeTexts(isoDate, features): string[]` (DST text when `features.timeChange`, season text when `features.seasons`). |
| `generate/waste.js` | `wasteRuleOn(kind, isoDate, year): boolean` (§E.11); `wasteKindsOn(doc, isoDate, ctx): { id, kind }[]` (kinds with `on`, in `pos` order, where the rule matches or an extra exists); `monthCounts(kind, year): number[]` (12 counts for the month strip). |
| `generate/shifts.js` | `SHIFT_PRESETS: { '8': [letter, from, to][], '12': … }` (map §2.3); `defaultOperation(n): ShiftOp`; `shiftCrews(op, isoDate, columnIndex): string[]` (one crew letter or `'–'` per shift; map §4.4 formula with `n = diffDays('1970-01-01', date) + 3·columnIndex`). |
| `generate/day.js` | `createDayContext(doc): DayContext` (indexes: events / names / extras / comments by date, effective moon map, ordered waste kinds, ordered shift ops, club map); `dayInfo(doc, isoDate, ctx): DayInfo`. |

```ts
type Page = {
  index: number; kind: 'cover' | 'week' | 'special' | 'back'; key: string;
  title: string;            // 'Titulní strana', '1. týden', '53. a 1. týden', special page name, 'Zadní strana'
  subtitle: string;         // range '28. 12. – 3. 1.', 'Speciální strana', 'Vlastní strana', 'Přehled roku', 'Kontakty klienta', ''
  week?: { days: string[]; frames: string[][]; weeks: number[]; monthText: string; weekText: string };
  special?: { id: string; item: SpecialPage };
  backVariant?: 'year' | 'contacts';
};
type DayInfo = {
  date: string; y: number; m: number; d: number; wd: number;
  numberKind: 'numHol' | 'numSun' | 'numSat' | 'num';   // holiday > Sunday > Saturday > workday
  weekday: string; weekdayShort: string;
  names: string;              // name day + extra names joined ' / ', '' when none
  holiday: string;
  events: { id: string; text: string; icon: string | null }[];   // non-empty names, 'CODE · name' when the club exists; [] when features.events is off; at most 4
  eventsDropped: number;      // events beyond the fourth
  timeTexts: string[];
  icons: IconRef[];           // in styles.icons.order, waste expanded into its kinds; only active categories; moon only when styles.moon.phases[phase]
  shifts: string[][];         // per operation column; [] when features.shifts is off
  places: string[];           // collection places of the day's waste kinds (non-empty)
  comments: number;
  extras: { id: string; kindId: string }[];   // step-5 single collections on this date
};
type IconRef = { category: 'waste' | 'moon' | 'ev' | 'time' | 'season'; kindId?: string; phase?: number; season?: number; asset: string | null; color: string; title: string };
```

`page.week.frames` is `[days]` for 7 days and `[first seven, second seven]` for 14 days.

#### render/ (owner P2; `special.js` filled by P6a)

| File | Exports |
| --- | --- |
| `render/measure.js` | `createEstimatorMeasurer(): Measurer`; `createCanvasMeasurer(): Measurer` (browser only); `ensureFontsLoaded(fonts, families: string[]): Promise<void>` (browser only; `document.fonts.load('16px "<family>"')` for each, resolves after `document.fonts.ready`); `wrapText(text, maxWidthMm, font: FontSpec, measurer, opts?: { maxLines?: number }): string[]` (§E.6.5); `usedFamilies(layout): string[]`. |
| `render/svg.js` | `esc(text)`; `num(v)` (≤ 3 decimals, no trailing zeros); `rect(box, attrs)`; `textEl(x, y, text, font: FontSpec, attrs)`; `fillLayers(box, fill: Fill, imageAsset, opts, idPrefix): string` (colour → gradient → picture, §E.6.9); `gradientDef(id, gradient, box)`; `imageIn(box, asset, fit, opacity, opts)`; `photoIn(box, asset, crop: { zoom, px, py }, radius, opts, idPrefix)`; `assetUrl(asset, quality)`. |
| `render/icons.js` | `binIcon(box, color)`, `moonIcon(box, phase, color)`, `clockIcon(box, color)`, `seasonIcon(box, season, color)`, `starIcon(box, color)` — SVG fragments drawn into a mm box (ported from prototype L4970–L4990, L5036–L5040); `iconFragment(ref: IconRef, box, opts)` (uploaded asset or built-in). |
| `render/week-layout.js` | `layoutWeekPage(doc, page, opts: LayoutOpts): WeekGeometry` (§E.6). |
| `render/week.js` | `renderWeekPage(doc, page, geometry, opts): string` (inner SVG markup, elements in reverse `layout.order`). |
| `render/special.js` | `renderCover(doc, page, opts): string`; `renderBack(doc, page, opts): string`; `renderSpecial(doc, page, opts): string` (P2 ships titled placeholders; P6a implements map §4.5). |
| `render/page.js` | `renderPage(doc, page, opts: RenderOpts): string` (complete `<svg>`); `pageViewBox(design, opts): { x, y, w, h }`; `holesMarkup(page)`; `cropMarks(design)`. |
| `render/warnings.js` | `pageWarnings(doc, page, opts: LayoutOpts): Warning[]`; `allWarnings(doc, pages, opts): Map<number, Warning[]>`; `photoDpi(assetInfo, frameBox, zoom): number`. |

```ts
type FontSpec = { family: string; weight: number; sizeMm: number; letterSpacingMm?: number };
type Measurer = { width(text: string, font: FontSpec): number /* mm */ };
type LayoutOpts = { env: { assets, fonts }; measurer: Measurer; dayContext?: DayContext };
type RenderOpts = LayoutOpts & {
  idPrefix: string;                      // unique per rendered instance (gradient / clip / filter ids)
  bleed?: boolean;                       // viewBox includes the bleed ring; default false
  marks?: boolean;                       // adds a white margin with crop marks (implies bleed)
  holes?: boolean;                       // default true
  placeholders?: 'all' | 'frames' | 'none';   // default 'frames'
  quality?: 'preview' | 'full';          // which asset URL to use; default 'preview'
};
type Rect = { x: number; y: number; w: number; h: number };
type WeekGeometry = {
  boxes: { [boxKey]: Rect };             // visible boxes as stored
  frames: {
    index: 0 | 1; boxKey: 'cal' | 'cal2'; inner: Rect;       // box clipped to the trim
    mirrored: boolean; zoneLeft: boolean; rowHeight: number; compact: boolean;
    textColumn: { x: number; w: number };                    // physical, same for every row
    holWrap: { x: number; w: number };                       // effective holiday wrap span
    iconZone: { x: number; w: number; cols: number; cellW: number } | null;
    rows: {
      date: string; y: number; h: number;
      parts: { key: PartKey; rect: Rect }[];                 // drawn parts, offsets applied, paint order
      eventBox: Rect | null;                                 // offset applied; null in compact mode or when events are off
      eventOverflow: boolean;
      iconCells: Rect[];                                     // grid cells (empty in row mode)
      iconsDrawn: number; iconsTotal: number;
    }[];
  }[];
  textWidths: { month: number; wk: number };                 // measured single-line widths incl. padding, mm
};
type Warning = { pageIndex: number; kind: 'portrait' | 'dpi' | 'icons' | 'event'; target: { el: 'photo' } | { date: string }; message: string };
```

`layoutWeekPage` returns more than `WeekGeometry` promises. Stable extras the UI may use:
`frames[].box` (the unclipped stored box), `frames[].textAnchor` (`'start' | 'end'`, the text column's
anchor), `frames[].numberColumn: { x, w }`, `frames[].evOffset: { x, y }` (the `ev` offset applied in
that frame). Renderer-internal and free to change: `frames[].styles` (the frame's possibly flipped
styles), `rows[].items` (draw list: `text` / `rect` / `line` / `icon` / `slot` with final coordinates
and a `rect` bounding box), `rows[].info` (the `DayInfo`). `iconZone.cols` in row (compact) mode is
the number of active icon keys even when fewer slots are reserved (§E.6.7).

Additive exports of P2 beyond the tables (all pure): `model/elements.js` `ELEMENT_KEYS`, `BOX_KEYS`,
`HIDEABLE_BOXES`, `DEFAULT_ORDER`, `PART_KEYS`; `model/content.js` `PAGE_COLLECTIONS`,
`GENERATED_SPECIAL_IDS`; `model/layouts.js` `fill(o)`, `FALLBACK_ACCENT`; `model/fonts.js`
`FALLBACK_FAMILY`; `model/defaults.js` `FALLBACK_WASTE_COLOR`; `generate/pages.js`
`SPECIAL_KIND_LABELS`, `orderedSpecialPages(content): [id, item][]` (the four generated kinds with
defaults + uploaded ones, by `pos`), `specialPageName(item)`; `generate/time.js` `DST_TEXTS`,
`SEASON_TEXTS`; `generate/day.js` `MAX_EVENTS_PER_DAY`, `wasteKindColor(styles, id, kind)`;
`render/svg.js` `attrs(map)`, `fontSpec(style, fonts, sizeMm?, letterSpacingMm?): FontSpec`,
`slotPlaceholder(box, label, opts?)`; `render/week-layout.js` `monthLabel(text, upper)`,
`monthTextSpec(styles, fonts, text): { text, font, pad }`, `fillIsOn(fill)`, `MONTH_LETTER_SPACING`;
`editor/geometry.js` `HANDLES`, `enforceMinimum(box, handle, start)`. `DayContext.iconKeys` lists the
active icon keys as `'moon' | 'ev' | 'time' | 'season' | 'waste:<kindId>'`.

Estimator widths (em per character, × `sizeMm`, + `letterSpacingMm` per character): space 0.28;
`iIl.,:;!|'` and `j t f r í ì` 0.30; other lower case and digits 0.54; `m w` 0.82; upper case 0.66;
`M W` 0.90; `– — ·` 0.56; anything else 0.60; multiply by 1.06 when `weight ≥ 600`. Canvas measurer:
`ctx.font = "<weight> 100px <family>"`, `width = measureText(text).width / 100 × sizeMm` + spacing.

#### editor/ — pure geometry (owner P2)

| File | Exports |
| --- | --- |
| `editor/geometry.js` | `pageBounds(page, bleedMm): { x0, y0, x1, y1 }`; `roundBox(box): Box`; `clampMove(box, bounds): Box`; `clampResize(box, bounds): Box`; `resizeFromHandle(start: Box, handle: Handle, dx, dy): Box` (min size applied, §E.1); `containsPoint(box, pt)`; `hitTest(pt, design, selection): Target \| null` (§E.4); `partAt(pt, geometry): { frame, date, part } \| null`. `Handle = 'n' \| 'ne' \| 'e' \| 'se' \| 's' \| 'sw' \| 'w' \| 'nw'`. `Target = { el: ElementKey, frame?: 0 \| 1 }`. |
| `editor/snap.js` | `snapTargets(baseBoxes, movingKey, visibleKeys, page, bleedMm): { xs: number[], ys: number[] }`; `snapMove(box, targets, thresholdMm): { box, guides: Guide[] }`; `snapResize(box, handle, targets, thresholdMm): { box, guides }`. `Guide = { axis: 'x' \| 'y', pos: number }`. |
| `editor/link.js` | `applyLink(baseBoxes, key, from: Box, to: Box, visibleKeys, page): Boxes` (§E.4; returns all boxes, `key` set to `to`). |
| `editor/drag.js` | `beginDrag(input: { boxes, key, mode: 'move' \| Handle, page, bleedMm, visibleKeys, link: boolean }): DragSession`; `dragTo(session, dxMm, dyMm, o: { snap: boolean, thresholdMm: number }): { boxes: Boxes, guides: Guide[] }` (§E.2, always computed from the session's base); `nudge(boxes, key, dxMm, dyMm, page, bleedMm): Boxes`; `setField(boxes, key, field: 'x' \| 'y' \| 'w' \| 'h', value, ctx: { page, bleedMm, visibleKeys, link }): Boxes`. |
| `editor/parts.js` | `dragPartOffset(start: { x, y } \| null, dx, dy, snapBack: boolean, mirrored: boolean): { x, y } \| null` (`dx`, `dy` = page mm of the drag; offsets are stored for frame 1, so `mirrored` negates `dx`; null = no offset → delete `offsets[part]`); `resizeEventBox(box, side: 'n' \| 'e' \| 's' \| 'w', dFlow, dy): EvBox` (**`side` is in flow terms**: `'w'` = the bar on the zone side (flow start), `'e'` = the bar on the number side — the caller maps the physical bar through `frame.zoneLeft`; `'n'` / `'s'` are physical); `defaultEventBox(geometryFrame, row): EvBox \| null` (the drawn `row.eventBox` as a stored, flow-anchored, offset-free `ev.box`; null when the row has no box); `resizeIconCell(cellW, cols, dFlow): number`; `resizeHolWrap(width, dFlow): number` (both: positive `dFlow` = wider; the caller signs it for the dragged bar). |
| `editor/bleed.js` | `adaptBoxesToBleed(boxes, page, oldBleedMm, newBleedMm): Boxes`; `clampBoxesToBounds(boxes, page, bleedMm): Boxes`. |
| `editor/autogrow.js` | `maxTextWidths(doc, pages, opts: LayoutOpts): { month: number, wk: number }`; `autoGrow(layout, widths, design): Layout \| null` (null = nothing to grow; §E.5). |
| `editor/zoom.js` | `fitScale(stageW, stageH, page, bleedMm): number` (px per mm); `zoomIn(z)`, `zoomOut(z)` (next step of `K.ZOOM_STEPS`, clamped). |

#### state/ (owner P3)

| File | Exports |
| --- | --- |
| `state/api.js` | `createApi(env): { save(payload): Promise<SaveResponse>, upload(file: File, kind): Promise<AssetInfo & { id }>, history(): Promise<Entry[]>, clientLink(action): Promise<{ url }>, importLayout(body: { from } \| { layout }): Promise<{ layout, assets, name }>, documentText(file): Promise<{ text, name }> }`. Every call is multipart `FormData` with `_token`; a non-2xx answer rejects with `Error(json.error \|\| 'Uložení se nepovedlo')`. |
| `state/store.js` | `createStore({ doc, env, api, measurer }): Store` (below). |
| `state/undo.js` | `createUndo(limit): { push(snapshot: string), undo(current): string \| null, redo(current): string \| null, canUndo(), canRedo(), clear() }`. |
| `state/payload.js` | `buildPayload(state, { finish }): { payload, sent: SentMarker }`; `applyResponse(state, response, sent): state` (§D.4). Pure, node-tested. |

#### ui/ — DOM modules (owners per §F)

Every UI module exports `mountX(root: HTMLElement, ctx: Ctx, options?): { destroy(): void }` and builds
its DOM inside `root`. `Ctx = { store, env, api, measurer, toast(message, error?), confirm(opts): Promise<boolean>, openTool(key) }`.

| File | Mounts | Owner |
| --- | --- | --- |
| `ui/dom.js` | `h(tag, attrs, ...children)`, `esc`, `icon(name, size?)` (`<span class="i i-…">`), `toast`, `confirmDialog({ title, text, confirmLabel, cancelLabel?, danger? })`, `openModal({ title, body: Node, size?: 'lg' \| 'xl', onClose? }): { el, close() }` (Bootstrap modal, footer button `Hotovo`), `numberField({ value, min, max, step, unit, label, onChange, onStart?, onCommit? })`, `switchRow`, `segmented`, `selectField`, `formatNumber(v)` (decimal comma), `parseNumber(text)`, `relativeTime(iso)` (map §8.1), `onOutside`, `debounce`. | P3 |
| `ui/topbar.js` | `mountTopBar` — back arrow, name field, steps, undo/redo, status, Uložit, Zpět, Pokračovat / Dokončit / Uložit vzhled (handoff §4.2). | P3 |
| `ui/step1.js`, `ui/step2.js` | Steps 1 and 2. | P3 |
| `ui/thumb.js` | `renderThumb(doc, page, ctx, opts?): string`; `mountLazyThumb(el, getMarkup: () => string): { refresh(), destroy() }` (IntersectionObserver). | P3 |
| `ui/editor/index.js` | `mountEditor(slots: { layers, stage, props }, ctx, opts: { embedded: boolean }): { destroy() }`. | P3 stub → P4 |
| `ui/editor/stage.js`, `ui/editor/overlay.js`, `ui/editor/keys.js`, `ui/editor/layers.js` | Stage toolbar + page + overlay + pointer pipeline; keyboard; Vrstvy list incl. Nahrát layout menu. | P4 |
| `ui/editor/props.js` | `mountProps(root, ctx)` — dispatches on `ui.selection` to the element panels; `Pozice a velikost`. | P3 stub → P5a |
| `ui/controls/color.js`, `fill.js`, `gradient.js`, `text.js`, `align.js`, `upload.js` | `colorPicker`, `fillPanel`, `gradientEditor`, `textProps`, `alignControl` / `valignControl`, `uploadTile` — each `({ value, onChange, … }) → HTMLElement`. | P5a |
| `ui/editor/props-kal.js` | `mountKalProps(root, ctx)` — Kalendárium whole-block panel and every part panel (called by `props.js` when `selection.el === 'cal'`). | P3 stub → P5b |
| `ui/editor/parts-overlay.js` | `mountPartsOverlay(overlayRoot, ctx): { update(geometry: WeekGeometry \| null, scale: number, selection): void, destroy() }` — draws the event box / icon cell / holiday wrap bars for the selected part and runs their drags itself (`pointerdown` on a bar stops propagation, then `beginChange` / `updateLive` / `commitChange`). The stage calls `update` after every render. Part highlight boxes, part selection and the part-offset drag belong to the stage (P4). | P3 stub → P5b |
| `ui/step4.js`, `ui/pagelist.js`, `ui/pagepanel.js` | Step 4 frame, thumbnails list, per-page right panel. | P3 stub → P6a |
| `ui/tools/index.js` | `TOOLS: { key, label, feature \| null, clientAllowed }[]`; `openTool(key, ctx)`; `mountToolbar(root, ctx, { step })`. | P3 |
| `ui/tools/photos.js`, `qr.js`, `special.js` | Fotky a popisky, QR kódy, Speciální stránky panel. | P6a |
| `ui/tools/waste.js`, `events.js`, `names.js`, `moon.js`, `shifts.js`, `datepicker.js` | The other five tools + the shared month grid. | P6b |
| `ui/step5.js`, `ui/step5-overlay.js`, `ui/popover.js` | Step 5 frame, page buttons, floating editors. | P3 stub → P7a |
| `ui/warnings.js`, `ui/history.js`, `ui/client-link.js` | `warningChip`, `openWarnings`; `openHistory`; `openClientLink`. | P3 stub → P7b |

Stubs written by P3 export the final signatures and render a neutral placeholder, so later phases only
replace the bodies of files they own.

### D.3 Stimulus controllers (owner P3)

| Controller | File | Role |
| --- | --- | --- |
| `calendar-list` | `assets/controllers/calendar_list_controller.js` (`/* stimulusFetch: 'lazy' */`) | Targets `env` (shared JSON, once), `card`, `doc` (JSON per card: `{ calendar, assets, urls }`), `thumb`, `meta`, `time` (§C.4 `list.html.twig`). For each card: `normalizeDocument` → `buildPages` → thumbnail of the first week page (cover when there is no layout: a neutral placeholder) + summary `{Týdenní\|Dvojtýdenní} · {year} · {fotka vlevo\|fotka vpravo\|dvoustranné} · {N stran}`. History chip → `openHistory`. Shows the toast stored in `sessionStorage['wb-cal-toast']` after Dokončit. |
| `calendar-workspace` | `assets/controllers/calendar_workspace_controller.js` (lazy) | Parses the bootstrap, awaits `ensureFontsLoaded`, creates measurer, api, store and `Ctx`; mounts the top bar and the current step module into `.cal-ws`; remounts on `design.step` change; handles Ctrl+S, `beforeunload`, the leave confirmation and installs `window.__calendar`. In `client` mode: no step bar, step fixed to 5. |

### D.4 State store and undo

```ts
type State = {
  doc: Doc;
  server: { design: Design; content: Content };       // last state acknowledged by the server
  dirtyDesign: Set<keyof Design>;
  dirtyContent: Map<string /* 'collection/id' */, { seq: number; label: string }>;
  ui: Ui; status: 'clean' | 'dirty' | 'saving' | 'error';
};
type Ui = {
  selection: null | { el: ElementKey; frame?: 0 | 1; part?: PartKey };
  zoom: number; guides: Guide[]; panelCollapsed: boolean; partsOpen: boolean; layoutMenuOpen: boolean;
  contentView: 'style' | 'special' | number;            // step 4: Styl stránek | Speciální stránky | page index
  previewPage: number;                                  // step 5 page index
  tool: null | string; toolState: object;               // open content tool + its view state (tab, month, filter)
  iconCell: number | null;
};
```

Store API (`Store`):

| Method | Behaviour |
| --- | --- |
| `getState()`, `subscribe(fn: (e: StoreEvent) => void): () => void` | `StoreEvent.type`: `layout` (`{ live }`), `design` (`{ keys }`), `content` (`{ collection, id }`), `ui` (`{ keys }`), `status`, `undo`, `replace`. |
| `pages()`, `dayContext()`, `geometry(page)`, `warnings()` | Memoised derivations; invalidated by `layout`, `design`, `content`, `replace`. |
| `setDesign(patch)` | For `name`, `linkElements`, `finished`. Marks keys dirty. |
| `setStep(n)` | Sets `design.step`; `step` is dirty but does not change `status`; when `status === 'clean'` and mode is designer, triggers a silent `save()`. |
| `setFeature(key, on)`, `setAllFeatures(on)` | `features` patch. Changing `captions` while a layout exists pushes an undo entry (D3). Clears a selection that became invisible / unavailable (handoff §8). |
| `setPrint(patch)` | Applies limits (bleed off→on resets `bleedMm` to 5), then `adaptBoxesToBleed` on the layout (not an undo step). |
| `changeYear(year): { dropped: number }` | §E.7. |
| `changeType(type)` | §E.8; clears undo and redo. |
| `choosePreset(preset)` | §E.8. |
| `change(fn: (layout: Layout) => Layout, opts?: { captions?: boolean })` | One undoable step: push snapshot → apply → auto-grow → emit `layout`. |
| `beginChange()`, `updateLive(fn)`, `commitChange()`, `cancelChange()` | A multi-event step (drag, slider): `beginChange` remembers the snapshot, `updateLive` applies without pushing and emits `layout { live: true }`, `commitChange` pushes the remembered snapshot if the layout differs, runs auto-grow and emits `layout { live: false }`; `cancelChange` restores. |
| `replaceLayout(layout, assets)` | Layout import: merges `assets` into `env.assets`, one undo step, clears the selection. |
| `undo()`, `redo()`, `canUndo()`, `canRedo()` | Snapshot = `JSON.stringify({ layout, captions: features.captions })`. After restoring: `clampBoxesToBounds` with the current bleed, clear the selection if its element is no longer visible. A new change clears redo. Limit `K.UNDO_LIMIT`. |
| `putItem(collection, id, value, label?)`, `removeItem(collection, id, label?)`, `applyOps(ops)` | Content edits; never on the undo stack. |
| `addAsset(info)` | Registers an uploaded asset in `env.assets`. |
| `setUi(patch)` | View state; never dirty, never saved. |
| `isDirty()`, `save(opts?: { finish?: boolean }): Promise<{ ok: boolean, error?: string }>` | §below. |

Every layout-changing method marks `dirtyDesign.layout`. Layout changes are possible only in designer
mode (the store throws otherwise — a defensive mirror of the server rule).

**Saving.** `buildPayload`: `design` = the dirty design keys with their current values (omitted when
none), `content` = one op per dirty item (`v` = current item or `null`), remembering each item's `seq`.
One request at a time; a `save()` during a request resolves after a follow-up request. On success
`applyResponse`: `server` = response; for every sent item whose `seq` is unchanged the dirty mark is
cleared; `doc.content` = response content overlaid with the still-dirty local items; `doc.design` =
response design overlaid with still-dirty keys; `doc.revision` = response; assets merged; then
`normalizeDocument` and a `replace` event. Status: `dirty` → **Neuložené změny**, `clean` → **Vše
uloženo**, failure → **Uložení se nepovedlo** + error toast with the server's message; the dirty marks
stay. Undo history survives a save.

**Leaving.** `beforeunload` is armed while `isDirty()`. The back arrow and step-5 finish go through the
workspace controller: back with unsaved changes opens `confirmDialog` (copy in §G.3) with *Uložit a
odejít* / *Odejít bez uložení* / *Zůstat*.

**Test hook** (read-only, installed by the workspace controller, documented in
`docs/architecture/calendars.md`):

```ts
window.__calendar = {
  ready: Promise<void>,                       // resolves after the first render with fonts loaded
  state(): { design, content, revision, selection, zoom, status, mode, canUndo, canRedo },   // deep copy
  boxes(): { [boxKey]: Box },                 // visible boxes, mm
  page(): { width, height, bleedMm, bandHeight },
  scale(): number,                            // current px per mm of the visible stage (0 when no stage)
  toClient(xMm, yMm): { x, y },               // viewport coordinates of a trim-relative point on the visible stage
  pages(): Page[],
  geometry(pageIndex?): WeekGeometry | null,  // default: the page currently on the stage
  warnings(): Warning[],
};
```

### D.5 Renderer structure

`renderPage` → `<svg xmlns viewBox="…" data-page="<key>" role="img" aria-label="<title>">`:

1. viewBox from `pageViewBox`: `0 0 W H`; with `bleed`: `−B −B W+2B H+2B`; with `marks`:
   `−M −M W+2M H+2M`, `M = B + MARKS.gapMm + MARKS.lengthMm + 1`, white background rect, the page
   clipped to the bleed box.
2. Paper rect (white, bleed box) → Pozadí layers (`styles.bg`, picture = `pageImages[key].bg` or the
   style's asset) over the bleed box.
3. Page body by kind: `renderWeekPage` / `renderCover` / `renderBack` / `renderSpecial`.
4. `holesMarkup(page)` (fill `#F6F5F8`), on top of everything, on every page kind, when `holes`.
5. `cropMarks(design)` when `marks`: eight black lines, `MARKS.weightPt`, each starting `B + gapMm`
   from its trim corner along the trim line and `lengthMm` long.

The page SVG never contains editor chrome. Elements carry `data-el="<element>"` (and `data-frame`,
`data-part`, `data-date` inside the Kalendárium) for debugging and e2e only — interaction never
depends on SVG hit-testing. All sizes inside the SVG are mm; fonts are `font-size="<mm>"`.

Week page elements (map §4.3 behaviour unless stated): Fotografie (`photoIn`; **the photo is clipped to
`styles.photo.radius`**, border drawn inside the edge, shadow = offset rect with `feGaussianBlur
stdDeviation = blur / 2`; placeholder rect `ph` + diagonals `ph2` + pill `FOTKU DOPLNÍ KLIENT` /
`FOTOGRAFIE` when the frame is larger than 50 × 19 mm); Hlavička (`fillLayers`, picture =
`pageImages.head` or style asset; `OBRÁZEK HLAVIČKY` slot only with `placeholders: 'all' | 'frames'`);
Měsíc; Kalendárium; Popisek (text = `pageCaptions[key].text`; the sample text
`Popisek fotografie, autor` only with `placeholders: 'all'`); Číslo týdne; Logo (`LOGO` placeholder
unless `placeholders: 'none'`); QR (square, side `min(w, h)`, top-left; source `pageQr[key]` when
`styles.qr.perPage`, else `styles.qr.asset`; `QR` placeholder only with `placeholders: 'all'`).

Single-line texts (Měsíc, Číslo týdne): anchor by `align` inside the box minus `pad` (Měsíc: `pad`
only when its background colour, gradient or picture is on); baseline `y + h/2 + 0.36·size`;
`letter-spacing` 0.09 mm when `upper`. Měsíc with `upper: false`: lower case with the first letter and
each letter after `"/ "` capitalised. Caption: wrapped to the box width minus insets (`pad` on all
sides when its background is on, else `CAP_INSET_X` / `CAP_INSET_Y`), line height 1.2, block aligned
by `valign`.

### D.6 Editor architecture (steps 3 and 4 "Styl stránek")

DOM of the stage (`ui/editor/stage.js`):

```
.cal-stage                     scroll container (the zoomed page may be larger)
  .cal-stage-bar               Propojit prvky · "Vzorová strana · styl platí pro všechny strany" · zoom
  .cal-cvw > .cal-cv           sized (W + 2B)·s × (H + 2B)·s px, s = fitScale × zoom
     svg.cal-page              renderPage(sample, { bleed: true, placeholders: 'all' })
     svg.cal-chrome            same viewBox: bleed hatch (45°, #E0708A at .35) + dashed trim line (#D9486A), pointer-events none
     div.cal-ovl               HTML overlay, origin at the trim corner: frames, handles, tag, part boxes, guides
  .cal-hint                    hint line (map §8.7)
```

- `s` is recomputed on window resize (`ResizeObserver` on the stage) and on zoom; overlay positions
  are `mm × s` and are redrawn from state on every `layout` / `ui` event, so frame, handles and guides
  always stay aligned.
- Live updates during a drag replace only the page SVG markup and overlay positions; panels are not
  rebuilt. Panels rebuild on `ui.selection` change and on `replace` / `undo`; a value change coming
  from the panel itself never rebuilds the panel (inputs keep focus).
- Selection chrome: frame 2 px lavender with eight 10 px handles and the name tag under the left
  corner; with a part selected the frame is dashed/dim without handles and the tag reads
  `Kalendárium › {part}`. On a fortnight the frame not selected gets a thin dim outline, and the tag of
  the selected frame reads `Kalendárium · 1. týden` / `· 2. týden`. `bg` has no frame.
- Part highlight: one box per `geometry.frames[].rows[].parts` entry of the selected part (1.5 px
  dashed peach on `rgba(242,161,95,.12)`, padding 0.5 mm) in every row of every frame.
- Event box (red dashed, four side bars) is shown **only while the part `ev` is selected**; icon
  cells (violet dashed, two side bars) only while `icon` is selected; holiday wrap bars only while
  `hol` is selected (fixes map §10 item 6). All come from `WeekGeometry`.
- Guides: 1 px `--rose` lines across the trim width / height at `ui.guides`.

Pointer pipeline (`pointerdown` on `.cal-cv`, pointer capture, `pt` = pointer in mm through `s`):

1. On an element handle → resize session for the selected box. On a part bar → part session (§E.6.8).
2. Otherwise `target = hitTest(pt, design, selection)`:
   - `null` (outside the bleed box) → deselect. `{ el: 'bg' }` → select Pozadí.
   - If something is selected and `pt` is inside its selected box → arm a **move of the selected box**
     (this is how a covered element is moved by its frame).
   - Else select `target` (clearing any part) and arm a move of it.
   - If the selection is `cal` with part `P` and `partAt(pt) === P` → arm a **part-offset drag**
     instead of a move.
3. Movement under `DRAG_PX` (3 px) → it is a click: on `pointerup` select the topmost target under the
   pointer; if that is the already selected Kalendárium, select `partAt(pt)` when there is one.
4. First movement past the threshold: `store.beginChange()`; `session = beginDrag(…)` with the boxes at
   pointer down; a move of the Kalendárium clears the part selection.
5. Every `pointermove`: `dragTo(session, dx / s, dy / s, { snap: !event.altKey, thresholdMm: SNAP_PX / s })`
   → `store.updateLive` with the returned boxes, `ui.guides` = returned guides.
6. `pointerup`: clear guides, `store.commitChange()` (one undo step for A and all followers).

Keyboard (`ui/editor/keys.js`; ignored while focus is in `input, select, textarea, [contenteditable]`
or a modal is open): arrows `NUDGE`, Shift + arrows `NUDGE_BIG` (one undo step per key press; nothing
when a part or Pozadí is selected); Esc part → element → nothing; Ctrl/⌘+Z undo; Ctrl/⌘+Shift+Z and
Ctrl/⌘+Y redo. The undo / redo buttons of the top bar are shown whenever the editor is mounted (step 3
**and** step 4 Styl stránek).

Vrstvy (`ui/editor/layers.js`): rows from `layout.order` (QR row only with `features.qr`) + fixed
Pozadí row with the lock; SortableJS for drag-reorder with above/below indicator (one `change`); click
toggles selection; eye per §B.5; Kalendárium row arrow expands `availableParts` (group label
**Číslo dne**); header menu **Nahrát layout**: one item per sibling calendar of the same type with a
layout (`Layout z kalendáře v projektu`), `Ze souboru layoutu`, and `Uložit layout do souboru`
(downloads `exportLayoutFile` as a Blob). Import: `api.importLayout` → `store.replaceLayout` → toast
`Layout načten z „{name}“ — Ctrl+Z vrátí původní` / `Layout načten ze souboru — Ctrl+Z vrátí původní`.

Properties (`ui/editor/props.js`, `props-kal.js`): panels per handoff §6 and map §8.8–8.9, built from
`ui/controls/*`. Rules: a discrete control calls `store.change`; a slider / colour drag calls
`beginChange` on first input, `updateLive` while moving, `commitChange` on `change` (one undo step,
fixes map §10 item 8). `Pozice a velikost` shows the selected box (on a fortnight a segmented
`1. týden | 2. týden` picks the frame) and calls `setField` — `x` / `y` use `clampMove`, `w` / `h` move
the right / bottom edge with `clampResize` and link when `linkElements` (§E.2). Uploads go through
`api.upload(file, kind)` → `store.addAsset` → `store.change` referencing the id. **Tvar** writes
`styles.wk.form`; the Ikony panel edits `styles.icons.*`, `styles.cal.icon*`, `styles.moon.*`,
`styles.waste.kinds[*]`; the two content shortcuts of deviation D2 call `putItem` / `removeItem` after
`confirmDialog` for removals.

Mounting: step 3 passes three columns as slots. Step 4 (`contentView === 'style'`) passes the centre
column as `stage` and two stacked regions of the right aside as `layers` and `props` with
`embedded: true`: the aside shows the header `Styl stránek` / `Změny se projeví na všech stranách
kalendáře.`, the Vrstvy list while nothing is selected, and the properties with a back button
`Vrstvy` once something is selected. Same module, same store, same undo stack.

### D.7 Steps, tools, preview

- **Step 1** (`ui/step1.js`): type cards with page counts from `buildPages` on a copy of the design;
  switching type with an existing layout → `confirmDialog` (§G.3) → `store.changeType`. Year:
  `currentYear`, `+1`, `+2` (plus the calendar's own year when outside); with page overrides present →
  `confirmDialog` first; a toast reports dropped 29 February items. Tisk block; checklist with the
  tri-state **Označit vše**. Enter in a field blurs it.
- **Step 2** (`ui/step2.js`): week → two cards with `renderThumb` of the first week under
  `defaultLayout` (or the current layout for the active card); click rules of handoff §4.4 via
  `store.choosePreset`. Fortnight → `choosePreset('twoSided')` is applied automatically on entering
  step 2 when no layout exists, and one non-interactive card shows it with **Pokračovat**.
- **Step 4** (`ui/step4.js`): grid `168px minmax(0, 1fr) 400px`; toolbar from `ui/tools/index.js`;
  left list = Styl stránek, Speciální stránky, add menu, thumbnails (warning badge, override dot);
  centre = editor stage or the selected page (`placeholders: 'frames'`, trimmed, zoom shared with the
  editor) with previous / next; right = editor panels, special-page settings, or `ui/pagepanel.js`
  (warnings, then only applicable fields, **Vrátit na styl**).
- **Tools** (`ui/tools/*`): each `openTool` builds a large modal closed by **Hotovo** or Esc; when the
  tool's feature is off the body is the notice + **Zapnout** (designer only; clients never see a tool
  whose feature is off). All edits are `putItem` / `removeItem` with labels. Dates are restricted to
  `dateLimits(year)`. Validation messages: `Vyberte aspoň jeden den`, `Konec svozu je před začátkem`
  (the change is rejected and the previous date kept). Bulk photo upload: images only, sorted with
  `localeCompare(…, 'cs', { numeric: true })`, the first `weekPages.length` files, uploaded with at
  most three requests in parallel; each sets `pagePhotos[key] = { asset, zoom: 1, px: 0.5, py: 0.5 }`
  and, when the asset has a `description`, `pageCaptions[key] = { text: description.slice(0, 80) }`.
  No Excel import control exists.
- **Step 5** (`ui/step5.js`): toolbar (designer: all seven tools, disabled ones dimmed; client: the
  four allowed tools whose feature is on), **Odkaz pro klienta** (designer), warning chip, **Historie**.
  Large page: designer → `renderPage(…, { marks: print.marks, bleed: B > 0, quality: 'full', placeholders: 'frames' })`,
  client → trimmed. Thumbnails on the right. `ui/step5-overlay.js` positions, from `WeekGeometry`:
  photo controls (upload / replace, zoom slider 100–300 %, remove; drag inside the frame pans:
  `px`, `py` change by `−Δ / (drawn size − frame size)` clamped to 0–1), the caption pencil, a plus
  button per row on the outer side of its frame (left of a frame whose centre is left of `W / 2`,
  else right), comment bubbles with counts, warning marks. Popovers (`ui/popover.js`, draggable by the
  grip through `assets/controllers/popover_drag.js`, Enter confirms from inputs, Esc / close discards,
  empty event name or comment keeps focus and saves nothing): plus menu → Odpad (`wasteExtras`
  insert), Akce (`events` insert), Komentář (`comments` insert); the plus menu also lists that day's
  extra collections and events with a remove button (handoff §10.14); caption editor with the
  `n / 80 znaků` counter. A replaced photo resets `zoom` / `px` / `py`.
- **Dokončit / Uložit vzhled**: `store.save({ finish: true })` → store the toast
  (`Kalendář poskládán — {N stran}` first time, `Vzhled uložen — všechny strany přeskládány` later) in
  `sessionStorage` → navigate to `env.urls.list`.
- **Client view**: the workspace controller in `client` mode mounts a reduced top bar (name as text,
  status, **Uložit**) and step 5 only.
- **Thumbnails** everywhere are inline SVG from `renderThumb` (`placeholders: 'frames'`, preview
  quality, trimmed), rendered lazily when scrolled into view and refreshed with a 150 ms debounce on
  `layout` (non-live), `design`, `content`, `replace`.

### D.8 Warnings in the UI

`store.warnings()` = `allWarnings(doc, pages, opts)`. Badge `!` on thumbnails (`aria-label="{n}
upozornění"`), chip `{n} upozornění` (step 4: jump to the next page with a warning, wrapping; step 5:
open the list `Upozornění ({n})` grouped by page title, empty `Bez kolizí.`), marks on the step-5 page,
list in the step-4 page panel. Warnings never block anything.

### D.9 CSS

Tokens from `tokens.css` only; colours inside the page SVG are document colours. Files (all imported
in `assets/app.js` after `weekly-menu.css` and before `app.css`, by P3 in one edit):

| File | Holds | Owner |
| --- | --- | --- |
| `calendar.css` | list, cards, workspace shell, top bar, steps 1–2, thumbnails, shared widgets of `ui/dom.js` | P3 |
| `calendar-editor.css` | step-3 grid, layers, stage, overlay, hint, zoom | P4 |
| `calendar-controls.css` | property panels and `ui/controls/*` | P5a |
| `calendar-content.css` | step-4 grid, page list, page panel, photos / QR / special tools | P6a |
| `calendar-tools.css` | waste, events, names, moon, shifts dialogs, date picker | P6b |
| `calendar-preview.css` | step 5 frame, page overlay buttons, popovers | P7a |
| `calendar-dialogs.css` | warnings list and marks, history, client link dialog, client page | P7b |

Class names keep the prototype's
`cal-*` vocabulary (map §1.2) where a counterpart exists. Breakpoints 1180 / 960 / 900 px: side panels
stack under the page (handoff §8). Check 390 / 820 / 1440 px in both themes with
`docs/redesign/tools/shoot.cjs`.

### D.10 DOM hooks that tests rely on (contract)

| Selector | Element |
| --- | --- |
| `[data-cal-step="1…5"]` | step buttons of the top bar (`aria-current="step"` on the active one, `disabled` when unavailable) |
| `[data-cal-action="…"]` | `back-to-list`, `save`, `prev-step`, `next-step`, `undo`, `redo`, `link`, `zoom-in`, `zoom-out`, `zoom-fit`, `panel-toggle`, `offset-reset`, `client-link`, `history`, `warnings`, `create` |
| `[data-cal-status]` | save status text; attribute value = `clean` / `dirty` / `saving` / `error` |
| `[data-cal-name]` | calendar name input |
| `[data-layer="<element>"]`, `[data-layer-eye="<element>"]`, `[data-part-row="<part>"]`, `[data-layer-menu]` | Vrstvy rows, eye buttons, part rows, Nahrát layout menu button |
| `.cal-cv` | the page canvas; `[data-handle="n…nw"]` handles; `[data-frame-tag]` name tag; `[data-guide="x\|y"]` guides; `[data-part-box]` part highlights; `[data-evbox-bar="n\|e\|s\|w"]`, `[data-iconcell-bar="start\|end"]`, `[data-holwrap-bar="start\|end"]` |
| `[data-field="x\|y\|w\|h"]` | inputs of Pozice a velikost; every other property control carries `data-prop="<path in styles>"` (e.g. `month.size`, `cal.iconGrid`) |
| `[data-feature="<key>"]`, `[data-feature-all]`, `[data-type="week\|fortnight"]`, `[data-year="<y>"]`, `[data-preset="<preset>"]` | steps 1–2 |
| `[data-tool="<key>"]` | content tool buttons (`photos`, `waste`, `events`, `names`, `moon`, `shifts`, `qr`) |
| `[data-page-thumb="<index>"]` (+ `data-warn="<n>"`, `data-overridden`) | page thumbnails in steps 4 and 5 |
| `[data-day-plus="<date>"]`, `[data-day-comments="<date>"]`, `[data-photo-zoom]`, `[data-caption-edit]` | step-5 overlay |
| `.toast-n` | toasts |

---

## E. Algorithms

Notation: `B`, `W`, `H` as in §A.1; `bounds = { x0: −B, y0: −B, x1: W + B, y1: H + B }`;
`TOL = K.LINK_TOL`; `r(v) = roundMm(v)`.

### E.1 Limits, minimum size, rounding

- `resizeFromHandle(start, handle, dx, dy)`: `e`: `w = start.w + dx`; `w`: `x = start.x + dx`,
  `w = start.w − dx`; `s`: `h = start.h + dy`; `n`: `y = start.y + dy`, `h = start.h − dy`; corners
  combine two. Then minimum: `w < MIN_W` → `w = MIN_W` and, for handles containing `w`,
  `x = start.x + start.w − MIN_W`; same for `h` / `MIN_H` / `n`. The opposite edge never moves and the
  box never inverts.
- `clampResize(box, bounds)`: each edge individually into the bounds (`left = max(x, x0)`,
  `right = min(x + w, x1)`, same vertically); if the result is narrower than `MIN_W`, keep `left` when
  `left + MIN_W ≤ x1`, else `right = x1`, `left = x1 − MIN_W`; same for the height.
- `clampMove(box, bounds)`: `w = min(w, x1 − x0)`, `h = min(h, y1 − y0)`, `x ∈ [x0, x1 − w]`,
  `y ∈ [y0, y1 − h]`. Size is kept.
- `roundBox`: rounds the four **edges** to `GRID` and derives `w`, `h`, so a fixed edge stays exactly
  where it was.
- Nudge: `clampMove(box + (±NUDGE | ±NUDGE_BIG))`, rounded. Never links, never snaps.

### E.2 Drag pipeline (`dragTo`)

Always from the session base (`base` = all boxes at pointer down, `start = base[key]`), never
accumulated:

```
move:    b = start + (dx, dy)            → if snap: snapMove → clampMove → roundBox ; other boxes = base
resize:  b = resizeFromHandle(start, …)  → if snap: snapResize (dragged edges only; then re-apply the minimum)
                                         → clampResize → roundBox
                                         → link ? applyLink(base, key, start, b, visibleKeys, page) : { ...base, [key]: b }
```

`dragTo` returns only the guides the final box really sits on (a guide whose snap was undone by the
clamp or the minimum size is dropped).

`setField` (typed / stepped value, rounded to `GRID`): `x`, `y` → `clampMove`, no link. `w`, `h` →
left / top edge fixed, minimum, `clampResize`, then `applyLink(boxes, key, before, after, …)` when
`link`. A value outside the limits therefore lands on the nearest allowed value and the field shows
it.

### E.3 Snapping

Threshold `th = SNAP_PX / s` mm (`s` = px per mm, so zooming in allows finer placement).

```
xs = [0, W / 2, W] + (B > 0 ? [−B, W + B] : [])
ys = [0, page.bandHeight, (page.bandHeight + H) / 2, H] + (B > 0 ? [−B, H + B] : [])
for every visible box k ≠ moving key (positions from the session base):  xs += [k.x, k.x + k.w];  ys += [k.y, k.y + k.h]
```

- Move: x candidates `[x, x + w/2, x + w]`, y candidates `[y, y + h/2, y + h]`. Per axis choose the
  (candidate, target) pair with the smallest distance `≤ th`; ties → earlier candidate, then earlier
  target. Shift the box by the difference. One guide per snapped axis at the target.
- Resize: only the dragged edges are candidates (`w` → left, `e` → right, `n` → top, `s` → bottom);
  the edge moves to the target, the opposite edge stays.
- `snap: false` (Alt held) → no snapping and no guides. Hidden / absent elements are never targets.
  On a fortnight the other Kalendárium frame is an ordinary target.

### E.4 Linking (`applyLink`) — handoff §7 is authoritative

```
pageX(v) = v ≤ TOL || v ≥ W − TOL          pageY(v) = v ≤ TOL || v ≥ H − TOL        // on the trim edge or in the bleed
edges of A: [from.x → to.x (x)], [from.x + from.w → to.x + to.w (x)], [from.y → to.y (y)], [from.y + from.h → to.y + to.h (y)]
result = copy of base; result[key] = to
for every visible box n ≠ key:      o = copy of base[n]
  for each edge (a → z, axis) of A:
    skip if |a − z| < 0.005 (edge did not move) or page<axis>(a)
    x axis:  if |o.x − a| ≤ TOL:            right = o.x + o.w;  o.x = min(z, right − MIN_W);  o.w = right − o.x
             else if |o.x + o.w − a| ≤ TOL:  o.w = max(MIN_W, z − o.x)
    y axis:  the same with y, h, MIN_H
  result[n] = o
```

Consequences to keep: only resizes call it (handles, typed Š / V); followers are resized, never
shifted; collinear is enough; no chain reaction; follower minimum with A continuing (overlap);
everything derives from `base`, so dragging back restores exactly; followers are not snapped, clamped
or rounded themselves (they land on A's rounded edge); non-visible boxes are returned untouched; Pozadí
has no box. `cal` and `cal2` are independent boxes (each may trigger or follow).

`hitTest(pt, design, selection)`: walk `layout.order` from the top over visible elements; the first
whose box (any frame) contains `pt` wins → `{ el, frame }`. None: inside `bounds` → `{ el: 'bg' }`,
else `null`. `partAt(pt, geometry)`: in the row containing `pt.y` of the frame containing `pt`, the
last-painted part whose rect (grown by 0.5 mm) contains `pt`; lines hit within 0.8 mm.

### E.5 Auto-grow and bleed adaptation

**Auto-grow** (handoff §5.11; runs after a committed change, after year / type / feature changes and
after `replace`; never during a live drag): `maxTextWidths` measures the Měsíc text of **every** week
page and the Číslo týdne text of every week page with their styles (upper-casing applied) and returns
`max + 2·effectivePad + 2·TEXT_GROW_MARGIN` (wk has no pad). For each of `month`, `wk` that is
visible and whose need exceeds `box.w + 0.05`: `extra = need − w`; align `end` → `x −= extra`;
`middle` → `x −= extra / 2`; `start` → x stays; `w = need`; then keep inside `bounds` (shift first,
then shrink) and round **outwards** to `GRID` (left edge down, right edge up), so one pass is enough. The frame never shrinks. Inside `store.change` / `commitChange` the growth is
part of that undo step; elsewhere it marks the layout dirty without an undo entry.

**Bleed change** (`adaptBoxesToBleed(boxes, page, old, new)`, handoff §10.7), for every box (hidden
ones too) and each of its four edges against its own page side:

| Edge | Moves to | When |
| --- | --- | --- |
| left `x` | `−new` | `abs(x) ≤ TOL` or (`old > 0` and `abs(x + old) ≤ TOL`) |
| right `x + w` | `W + new` | `abs(x + w − W) ≤ TOL` or (`old > 0` and `abs(x + w − W − old) ≤ TOL`) |
| top, bottom | likewise with `y`, `h`, `H` | |

Other edges do not move. A box that lay entirely inside the old bleed ring (its edge moves would
collapse it) keeps its size instead. Then `clampBoxesToBounds` with the new bleed (`clampResize`, falling back to
`clampMove` for a box entirely outside). With `new = 0` the rule returns bleed edges to the trim.

### E.6 Kalendárium layout (`layoutWeekPage`)

For each frame `f` (0 → box `cal`, days `page.week.frames[0]`; 1 → `cal2`, `frames[1]`):
`S = f === 1 ? flipRowStyles(styles) : styles`, `mirrored = f === 1`.

**E.6.1 Frame and rows.** `inner` = box clipped to the trim (`x ∈ [0, W]`, `y ∈ [0, H]`), at least
`MIN_W × MIN_H`. The background rect uses the unclipped box. `n` = number of days,
`rh = (inner.h − ROW_SLACK) / n`, row `i` at `t = inner.y + i·rh`. `compact = rh < COMPACT_BELOW`,
`sc = compact ? min(1, rh / COMPACT_BASE) : 1`. Type sizes in mm: `sz(k) = ptToMm(S[k].size) · sc`
(numbers are not scaled by `sc`; they are capped below).

**E.6.2 Flow axis.** `side = S.cal.numberSide`. `zoneLeft = side === 'end' || (side === 'middle' && !mirrored)`.
A flow coordinate `u ∈ [0, inner.w]` starts at the zone edge: `X(u) = zoneLeft ? inner.x + u : inner.x + inner.w − u`.
"Towards the number" for text is decided physically: anchor `end` when the text column lies left of the
number column, `start` when it lies right of it (so a mirrored `middle` frame is right-aligned too).
The result is `frame.textAnchor`.

**E.6.3 Number column.** `nCap = rh · (compact ? 0.78 : 0.74)`; `nSz(k) = min(ptToMm(S[k].size), nCap)`;
`nMax = min(max of the four sizes, nCap)`. `above = S.cal.dayPosition === 'above' && !compact`.
`numW = nMax · 1.3`, and with `above`: `max(numW, width of the longest weekday label at sz('day'))`.
Flow span of the column: `side !== 'middle'` → `[w − ROW_PAD − numW, w − ROW_PAD]`; `middle` →
`[w/2 − numW/2, w/2 + numW/2]`. Number anchor inside the column = `S.cal.numAlign ?? side`
(physical). Baseline `t + rh/2 + 0.36·nSz(k)`; with `above`: block height
`Hb = 1.2·sz('day') + 0.74·nSz(k)`, top `t + (rh − Hb)/2`, weekday baseline `top + 0.85·sz('day')`
aligned by `S.cal.dayAlign ?? numAlign ?? side`, number baseline `top + Hb`.

**E.6.4 Zone (from `u = 0`).**

```
ops      = features.shifts ? ordered shift operations : []
shW      = ops.length && !compact && rh ≥ SHIFT_MIN_ROW ? ops.length·(SHIFT_COL_W + SHIFT_COL_GAP) + SHIFT_END_GAP : 0
zone0    = shW > 0 ? shW : ROW_PAD
keys     = active icon keys of the calendar (categories of styles.icons.order whose feature is on; 'waste' expanded to the kinds with on = true)
cellW    = max(S.cal.iconCellW ?? 0, S.cal.iconSize + ICON_GAP)
grid     = compact ? null : S.cal.iconGrid          // null = row mode
cols     = grid === '4' ? 4 : grid ? 2 : keys.length
iconsW   = keys.length ? cols·cellW : (S.cal.emptyIconSlot ? EMPTY_SLOT_W : 0)
labelsW  = S.cal.placeLabels && features.waste ? (widest place of any kind at sz('placeText')) + 2.5 : 0
zoneEnd  = zone0 + iconsW + labelsW + (iconsW > 0 ? ZONE_END_GAP : 0)
```

In grid mode the zone reserves all `cols` cells on every row, even on days without icons. When the
shifts print as one text (compact rows, or rows under `SHIFT_MIN_ROW`), `zone0` grows by the widest
shift text of the frame + `SHIFT_END_GAP`, so icons never sit on it. A frame too narrow for its zone
keeps the text column's number-side end and gives it 1 mm. Text column
flow span: `side !== 'middle'` →
`[zoneEnd, w − ROW_PAD − numW − NUM_GAP]`; `middle` → `[w/2 + numW/2 + NUM_GAP, w − ROW_PAD]`. Its
physical span is `frame.textColumn`; the text anchor is towards the number.

**E.6.5 Text block (normal mode).** Lines, top to bottom: weekday (unless `above`; `dayFormat`
chooses full or short; colour `sunColor` on a holiday or Sunday, `satColor` on Saturday, else
`day.color`) · name line(s) = `wrapText(info.names, colW, name, m, { maxLines: 2 })` · holiday lines =
`wrapText(info.holiday, min(S.hol.wrapWidth ?? colW, colW), hol, m)` — **never truncated** · time
texts joined ` · ` when `S.time.position === 'text'`. Each line advances `size`, draws, then
`0.22·size`. Vertical start: `base = 1.22·((above ? 0 : sz('day')) + sz('name')·max(1, nameLines))` (a day without a
name day is centred like its neighbours),
`y0 = t + (rh − base)/2` (centred on weekday + names, as in the prototype); if the whole block would
pass `t + rh − 0.5`, centre the whole block instead, but never start above `t + 0.5`.
`wrapText`: greedy by words (split on spaces; a word wider than the limit stays alone on its line);
`maxLines` joins the remainder onto the last line. `holWrap` = the wrap span anchored like the text.

**E.6.6 Event box and separate time text (normal mode).** Events `info.events` (max 4), size
`eS = sz('ev')`. Default box (when `S.ev.box` is null): flow `x = 0` from the text column's zone-side
edge, `w = colW`, `y = min(rh − 1.5·eS, (rh + base)/2 + 0.35·sz('name'))`, `h = rh − 0.6 − y`.
Stored box: `{ x, y, w ≥ EVBOX_MIN_W, h ≥ EVBOX_MIN_H }`, `x` in flow direction from the same edge.
Then the part offset `offsets.ev` is added. Lines = every event text wrapped to `w`; line height
`1.2·eS`; horizontal anchor `S.ev.align ?? (towards the number)`; block placed by `S.ev.valign`.
`eventOverflow = lines·1.2·eS > h + 0.15 || info.eventsDropped > 0`. Text is clipped by nothing and
never moves other parts. With the **default** box only (`S.ev.box === null`), on a day whose text block
has lines beyond weekday + one name line (holiday, time text, a wrapped name) the event lines start
under that block instead of printing over it, and the lost height counts towards `eventOverflow` —
so such a day warns instead of overlapping. A stored box is always used exactly as placed. Time text with `position: 'sep'`: size `min(sz('time'), 0.4·rh)`, anchored
at the text column's left / centre / right by `S.time.align ?? (away from the number)`, vertically
`top`: `t + 1 + size`, `middle`: `t + rh/2 + 0.36·size`, `bottom`: `t + rh − 1 − 0.2·size`.

**E.6.7 Compact mode.** One baseline `t + rh/2 + 0.36·sz('day')`, flowing from the number outwards:
weekday, then names after `measured width + 2.5`, then one extra after `measured width + 3`: the
holiday (full text, wrapped to the remaining width with line height `1.15·sz('hol')` and centred on
the row — no ellipsis; when that needs more lines than the row height holds, first the name day gives
way, then the wrap width grows towards the zone until the lines fit — the text is never cut), else
the events joined ` · `, else the time texts. No event box
(`eventBox: null`); `eventOverflow` = joined events wider than the remaining width or
`eventsDropped > 0`. `dayPosition: 'above'` is ignored. Shifts: one text at `u = ROW_PAD`, columns
joined `'  |  '` (crews of a column joined by a space). Icons use row mode. Row mode reserves one slot
per active icon key; when that would leave the text column narrower than `COMPACT_TEXT_MIN` (number
side `start` / `end` only), the reserved width shrinks down to the slots the fullest day of that frame
needs — icons are packed from the zone edge, so nothing overlaps and nothing is dropped.

**E.6.8 Lines, shifts, icons, offsets.**

- Lines (`S.lines.on`): under every row including the last, from `inner.x + ROW_PAD` to
  `inner.x + inner.w − ROW_PAD` at `t + rh`; width `ptToMm(S.lines.width)`; `dash` = `4w 3w`; `dot` =
  `0.01 2.6w` with round caps.
- Shift columns (`shW > 0`): column `ci` spans flow `[ci·(SHIFT_COL_W + SHIFT_COL_GAP), … + SHIFT_COL_W]`,
  full row height; background per `bgMode` (`all`, or `alt` on odd `ci`) = `S.shift.bg` opaque, or
  `S.shift.color` at 14 % when null; letters `shiftCrews(op, date, ci)` stacked and centred,
  `lh = min(1.15·sz('shift'), (rh − 2) / count)`, font `min(sz('shift'), 0.95·lh)`.
- Icon grid (normal mode). Cells in flow order from `zone0`, row area `t + 0.6 … t + rh − 0.6`
  (height `Hc`): `'4'` four cells `cellW × Hc`; `'2x2'` two columns × two rows of `cellW × Hc/2`
  (order: top row first, zone edge first); `'2+1'` two cells `cellW × 0.45·Hc` on top and one
  `2·cellW × 0.55·Hc` below; `'1+2'` one `2·cellW × 0.55·Hc` on top and two below. The day's icons
  (`info.icons`) fill the cells in order; icons beyond the cell count are not drawn
  (`iconsDrawn < iconsTotal`). Icon size in cell `c`:
  `min(cell.w − 0.3, (cell.h − 0.3) / 1.15, S.cal.iconCells[grid]?.[c]?.size ?? (double-width cell ? ∞ : S.cal.iconSize))`,
  at least 0.6; moon icons use `min(S.moon.size, 0.8·rh, cell.w − 0.3)` and `S.moon.valign`;
  horizontal alignment in the cell = `iconCells[grid][c].align ?? 'middle'`; waste and event icons are
  `size × 1.15·size`.
- Row mode (compact): the day's icons are packed from `zone0` in order, slot width `cellW` (moon:
  `max(cellW, moonSize + ICON_GAP)`), icon height `min(1.15·iconSize, 0.72·rh)`.
- Empty slot (no active key, `emptyIconSlot`): dashed rect `EMPTY_SLOT × min(EMPTY_SLOT, 0.62·rh)`,
  only with `placeholders !== 'none'`.
- Place labels: `info.places` joined ` · ` at flow `zone0 + iconsW + 1`, baseline centred, style
  `placeText`.
- Part offsets: every part's geometry and markup is translated by `offsets[part]`; `waste` and `moon`
  fall back to `offsets.icon`; the four number kinds have separate offsets. In the mirrored frame the
  x of an offset is already negated by `flipRowStyles`.

Part bars in the editor (`editor/parts.js`; all deltas are converted to the **flow** direction of the
dragged frame, `dFlow = zoneLeft ? dx : −dx`): event box `e`/`w` bars change `w` (and `x` for the
zone-side bar), `n`/`s` change `y`/`h`, minimum `EVBOX_MIN_*`, rounded to `GRID`, stored in
`styles.ev.box`; **Výchozí velikost pole** sets `ev.box = null` and deletes `offsets.ev`. Icon cell
bars: `iconCellW = max(ICON_CELL_MIN, r((cols·cellW ± dFlow) / cols))`. Holiday wrap bars:
`hol.wrapWidth = max(HOL_WRAP_MIN, r(width ± dFlow))`. Part-offset drag: `r(start + delta)`; without
Alt a component with `abs < PART_SNAP_BACK` becomes 0; `{0, 0}` deletes the offset; **Vrátit na
původní místo** deletes `offsets[part]`.

**E.6.9 Background layers** (`fillLayers`): colour (when `colorOn`; `colorOpacity`) → gradient (when
`gradient.on`; linear: `x1,y1 → x2,y2` on the unit square from `angle` (0° left→right, 90°
top→bottom); radial: centred, radius 50 %) → picture (when `imageOn`: the page override or the style
asset; `cover` = `preserveAspectRatio="xMidYMid slice"`, `contain` = `meet`; `imageOpacity`; with
`imageOn` and no picture a slot placeholder per §D.5).

### E.7 Year change (`store.changeYear`)

1. If the name equals `Stolní kalendář {oldYear}` → rename to `Stolní kalendář {newYear}`.
2. `shiftContentYear(content, old, new)`: every date `YYYY-MM-DD` becomes `(YYYY + new − old)-MM-DD`
   (so December of the previous year stays "previous year"). Affected: `events.date`, `names.date`,
   `wasteExtras.date`, `wasteKinds.dates[]`, `wasteKinds.from` / `to`, `comments.date`. A resulting
   29 February that does not exist: events, names, extras and entries of `dates[]` are **dropped**
   (counted in `dropped`); `from` / `to` and comment dates move to 28 February. All `moonEdits` are
   deleted (phases are recomputed). Page-keyed overrides are untouched: keys are year-independent
   (§E.9), so a week keeps its photo by week number and a key without a page in the new year is simply
   not shown.
3. The resulting ops are applied as dirty content items; the page list, auto-grow and warnings
   recompute. Returns `{ dropped }`; the UI toasts the sentence of §G.3 when `dropped > 0`.

### E.8 Type change, presets

`changeType(type)` (after the confirmation of handoff §10.2 when a layout exists): set `type`; with a
layout: `rebaseLayoutForType` — `boxes = defaultBoxes(newPreset, page, B)` (`newPreset` = `twoSided`
for a fortnight, `photoLeft` for a week), `hidden` flags carried over per key, the preset-dependent
style values of §B.3 reset, `ev.box = null`, `hol.wrapWidth = null`, `cal.iconCellW = null`,
`offsets = {}`; every other style and `order` kept. Undo and redo are cleared; selection cleared.
Without a layout only `type` changes (and `preset` stays null).

`choosePreset(preset)` (step 2): no layout → `defaultLayout`; same preset → nothing; `photoLeft ↔
photoRight` with a layout → `store.change(l => mirrorLayout(l, page))` (one undo step) and `preset`
updated; then `step = 3`, selection cleared.

### E.9 Page list (`buildPages`) and page keys

```
pages = [cover]
special = features.specialPages ? ordered specialPages with on = true : []
front specials (position 'front')
mon = mondayOf(year-01-01);  n = type === 'fortnight' ? 14 : 7
while mon ≤ year-12-31:  week page with days mon … mon + n − 1;  mon += n
back specials (position 'back')
back page: backVariant = special contains the kind 'year' ? 'contacts' : 'year'
```

Week page: `weeks = [isoWeek(mon).week]` (+ `isoWeek(mon + 7).week` for a fortnight);
`monthText(first, last)`: same month → `LEDEN 2027`; different months → `{M1} {Y1 if Y1 ≠ Y2} / {M2} {Y2}`
(`PROSINEC 2026 / LEDEN 2027`, `LEDEN / ÚNOR 2027`); `weekText(form, weeks)`:

| form | one week | two weeks |
| --- | --- | --- |
| `a` | `{w}. týden` | `{w1}. a {w2}. týden` |
| `b` | `Týden {w}` | `Týdny {w1}–{w2}` |
| `c` | `T{w}` | `T{w1}–{w2}` |

`title` = form `a`; `subtitle` = `{d}. {m}. – {d}. {m}.`. Page key
`pageKeyOfWeek(mon, year)`: `isoWeek(mon).year < year` → `pre`; `> year` → `post`; else
`'w' + two-digit week`. Other keys: `cover`, `back`, `sp:<id>`. Keys do not contain the year, the
range or the type, so overrides survive year and type changes for weeks that still start a page.
2027: 55 pages (53 week pages, keys `pre`, `w01` … `w52`) / 29 pages for a fortnight (`pre`, `w02`,
`w04` … `w52`).

### E.10 Sample page

`samplePage(doc, pages, selection)`: the first week page; while the selected part is `time`, the first
week page having any `timeTexts` on any day (fallback: the first week page).

### E.11 Waste, events, shifts, moon, time

- `wasteRuleOn(kind, date, year)`: `mode: 'dates'` → `kind.dates` contains the date. `mode: 'rule'` →
  date within `[kind.from ?? year-01-01, kind.to ?? year-12-31]`, weekday in `kind.weekdays`, and with
  `w = isoWeek(date).week`: `w` always · `2e` `w % 2 === 0` · `2o` `w % 2 === 1` · `4w` `w % 4 === 1`
  · `4w2` `w % 4 === 3` · `m` day of month ≤ 7. A day shows kind K when `features.waste`, `K.on`, and
  (`wasteRuleOn` or a `wasteExtras` item `{ kind: K, date }` exists) — extras ignore range and mode.
- Events of a day: items with that date and a non-empty trimmed name, sorted by id; text
  `"{code} · {name}"` when the club exists and has a non-empty code. The event **icon** category is
  present on a day with at least one event; its asset is the first event's `icon` when
  `styles.icons.ev.perEvent`, else `styles.icons.ev.asset`, else the built-in star.
- Names: `[nameDay, ...extra names of the date in id order].filter(non-empty).join(' / ')`.
- Moon: `effectivePhases` (§B.6); icon shown when `features.moon`, `moon` is in `icons.order` and
  `styles.moon.phases[phase]`.
- Time: DST icon/text on the last Sunday of March (`Začátek letního času`) and October
  (`Začátek zimního času`) when `features.timeChange`; season on the fixed dates when
  `features.seasons`.
- Number kind priority: holiday > Sunday > Saturday > workday.

### E.12 Warnings

| Kind | Condition | Message |
| --- | --- | --- |
| `portrait` | page has `pagePhotos[key]`, asset has `width`, `height`, `height > width`, and the photo box has `w > h` | `Fotka je na výšku — do rámečku na šířku se ořízne` |
| `dpi` | `dpi = round(25.4 / (max(box.w / asset.width, box.h / asset.height) · zoom))` (cover scale of the real frame in mm, including its bleed part) `< 200` | `Nízká kvalita fotky — {dpi} dpi (pro tisk aspoň 300 dpi)` |
| `icons` | a row with `iconsDrawn < iconsTotal` (grid mode only) | `{d}. {m}. — {n} ikony, vejdou se {cells}` |
| `event` | a row with `eventOverflow` | `{d}. {m}. — {k > 1 ? k + ' akce' : 'text akce'} se nevejde do pole pro akce` |

All come from `layoutWeekPage` + the asset map — there is no render side effect and nothing is stored.

### E.13 Zoom

`fitScale = clamp(min((stageW − 64) / (W + 2B), (stageH − 120) / (H + 2B)), FIT_MIN, FIT_MAX)` px per
mm; `s = fitScale · zoom`; 100 % = fit. Zoom lives in `ui` (memory only, never saved); the percentage
button resets to 1; the stage scrolls when the canvas is larger.

---

## F. Phases

Rules for every phase: touch only the files you own; a needed change to another phase's contract means
editing this document first and telling the lead. Run `docker compose exec web composer phpstan` and
`vendor/bin/phpunit` before finishing; rebuild the test DB after adding migrations or fixtures; after
JS/CSS changes `rm -rf var/cache/dev public/assets` in the container. Commit with an explicit
pathspec.

Waves: **1** P1 ‖ P2 → **2** P3 → **3** P4 ‖ P5a ‖ P6a ‖ P6b ‖ P7a ‖ P7b → **4** P5b → **5** P8a ‖ P8b.

Shared test infrastructure:

- Node: `tests/Fixtures/calendar/harness.mjs` (P2) reads `{ root, suite }` from stdin, refuses node
  < 22.7 with `{ "skip": "…" }`, imports `tests/Fixtures/calendar/suites/<suite>.mjs` and calls its
  `export default async function (t, load)`; `load(path)` imports `assets/calendar/<path>` by file
  URL; `t.test(name, fn)` collects failures from `node:assert/strict`. Output
  `{ passed, failures: [{ name, message }] }`. `tests/Calendar/CalendarJsTest.php` (P2) has a data
  provider over `suites/*.mjs` (glob) — adding a suite file needs no PHP edit; a suite that registers
  no test fails. `t.root` is the repository root. Shared fixtures live **beside** the harness in
  `tests/Fixtures/calendar/helpers.mjs` (`ENV`, `makeDoc(load, { type, preset, year, page, print, features, content, styles })`,
  `layoutOpts(load)`, `edges`, `near`, `boxNear`, `copy`) — never put a non-suite file into `suites/`.
  By hand: `node tests/Fixtures/calendar/harness.mjs --all` or `… <suite> [<suite> …]`.
  `tests/Fixtures/calendar/dump-defaults.mjs` prints JS defaults for PHP contract tests
  (`CalendarSeedContractTest`). The dev and CI image `ghcr.io/thedevs-cz/php:8.5-wboost` ships node
  24, so the suites run in CI without `actions/setup-node`.
- e2e: `tests/e2e/calendar/lib/harness.cjs` (P3) — env `BASE`, `PUPPETEER_DIR`, `CHROME`,
  `USER_EMAIL`, `USER_PASS` as `docs/redesign/tools/shoot.cjs`; helpers `launch()`, `login(page)`,
  `createCalendar(page): calendarId` (through the UI), `seed(page, payload)` (POSTs to the save URL
  with the page's CSRF token), `gotoStep(page, n)`, `cal(page, fn)` (evaluates against
  `window.__calendar`), `dragMm(page, fromMm, toMm, { steps = 12, alt = false, hold = false })` (real
  `page.mouse` moves through `toClient`), `dragHandle(page, handle, dxMm, dyMm, opts)`, `boxes(page)`,
  `expectBox(actual, expected, tolMm = 0.11)`, `reload(page)`, `deleteCalendar(page, id)`.
  `tests/e2e/calendar/run.cjs [filter]` runs every `*.e2e.cjs` and prints a pass/fail table; scripts
  export `{ name, checks: ['E1', …], run(ctx) }`. `tests/e2e/calendar/README.md` documents setup
  (`.claude/skills/verify`). e2e is not part of CI.

### P1 — backend foundation

| | |
| --- | --- |
| Goal | Everything in §C: module wiring, entities, migration, messages, controllers, templates, save / asset / client endpoints, history, storage and font registrations, fixtures, PHP tests. The pages render and the endpoints work with hand-made JSON even before any calendar JS exists. |
| Owns | `src/Entity/Calendar*.php`, `src/Repository/Calendar*.php`, `src/Value/Calendar/**`, `src/Services/Calendar/**`, `src/Services/Security/CalendarVoter.php`, `src/Message/Calendar/**`, `src/MessageHandler/Calendar/**`, `src/Controller/Calendar/**`, `src/Query/GetCalendars.php`, `src/Exceptions/Calendar*.php` + `InvalidCalendarChange.php`, `templates/calendar/**`, `migrations/Version*.php` (one new), edits listed in §C.8–C.9 (`ProjectModule.php`, sidebar, `project_modules.html.twig`, `base.html.twig` rail list, `security.php`, storage services, `StorageCategory`, `RewriteFontReferences`, `manual.css` teaser removal, deleted teaser files), `tests/Controller/Calendar/**`, `tests/MessageHandler/Calendar/**`, `tests/Services/Calendar/**`, touched existing tests, `tests/DataFixtures/TestDataFixture.php`, `docs/architecture/storage-inventory.md`, `gallery-and-uploads.md`. |
| Depends on | nothing. Uses `tests/Fixtures/calendar/layout-photo-left.json` from P2 for `CALENDAR_2` — until it exists, a minimal hand-written layout (valid per §C.5) is fine; P8a swaps it. |
| Tests | §C.10. |
| Handoff checks | Server side of R2, R3, R4, R6; scope guard "client cannot change layout by any route". |

### P2 — JS core

| | |
| --- | --- |
| Goal | `assets/calendar/model/**`, `generate/**`, `render/**` (week pages complete; cover / back / special as titled placeholders), `editor/**` — all pure, with node suites. |
| Owns | those four directories, `tests/Fixtures/calendar/harness.mjs`, the suites listed below under `tests/Fixtures/calendar/suites/`, `tests/Fixtures/calendar/layout-photo-left.json` (other phases add their own suite files there), `tests/Calendar/CalendarJsTest.php`, `tests/Calendar/CalendarSeedContractTest.php` (PHP `CalendarContentSeed` names vs `BUILTIN_WASTE` — written once P1's class exists; skip-if-missing until then), `.github/workflows/test.yml` only if node < 22.7 there. |
| Depends on | nothing. |
| Suites | `moon` (phase instants vs published 2026–2028 times and an independent elongation formula, Czech civil dates, edits), `generation` (G1–G12, G14 pure parts: page counts 2027, first/second/last page dates, month and week texts in three forms, 14-day page, holidays 2027 exactly, Easter 2026 / 2028, Karina and Karina / Vasil, number-kind priority, DST 28 Mar / 31 Oct 2027, page keys incl. `pre` / `post` for 2024, special pages order / position, year shift incl. 29 Feb), `waste` (every interval, range, dates mode, extras), `layouts` (coinciding lines of §B.4 for the three presets, mirror twice = identity, mirror flips exactly §B.5, rebase keeps styles), `normalize` (idempotent, fills defaults, layout file round trip, rejects foreign files), `geometry` (clamps, min size, no inversion, typed X = 20 / Š = 80), `snap` (targets incl. band and centre lines, Alt, hidden not a target, threshold scales with zoom), `link` (**one test per row of handoff §7.4** asserting all boxes before / after, plus L3–L6: no drift, follower minimum with A continuing, Alt keeps linking, corner handle, hidden follower, page-edge rule; the first row repeated for `photoRight` and `twoSided`), `bleed` (on / off / resize, nothing outside), `autogrow`, `week-layout` (default row height not compact; compact under the threshold; frame 2 mirrors frame 1; holiday wraps and is never cut — `Den boje za svobodu a demokracii` in an 84 mm frame; event overflow; icon overflow; event box follows `ev.box`), `render` (valid XML via a tiny well-formedness check; viewBox for trim / bleed / marks; `<image>` uses preview vs full URL; idPrefix uniqueness; no editor chrome in the markup), `warnings` (dpi formula with zoom and height-driven cover, portrait), `dimensions` (**scope guard**: page 420 × 200 with bleed 3 — default boxes scale, bounds, snap lines `W/2` and band, mm values, viewBox; plus a grep-style test that only `model/defaults.js` contains the literals `310` and `145` under `assets/calendar/`), `content` (ids, reorder, page overrides). Also emits `tests/Fixtures/calendar/layout-photo-left.json`. |
| Handoff checks | G1–G12, G14 (rules), 9.3 table + L3–L6 (rules), E7–E9 (rules), 9.6 dimensions. |

### P3 — workspace shell, save flow, steps 1–2, list cards, skeleton

| | |
| --- | --- |
| Goal | A calendar can be created, opened, named, configured (steps 1–2), saved and reloaded; the list shows thumbnails and summaries; every later UI file exists as a stub with its final signature; the e2e harness exists. |
| Owns | `assets/calendar/state/**`, `assets/calendar/ui/dom.js`, `topbar.js`, `step1.js`, `step2.js`, `thumb.js`, `tools/index.js`, **stub** versions of every other `ui/**` file of §D.2, `assets/controllers/calendar_list_controller.js`, `calendar_workspace_controller.js`, `assets/styles/nocturne/calendar.css` + empty `calendar-editor.css`, `calendar-controls.css`, `calendar-content.css`, `calendar-tools.css`, `calendar-preview.css`, `calendar-dialogs.css`, the import lines in `assets/app.js`, `tests/Fixtures/calendar/suites/payload.mjs`, `tests/e2e/calendar/lib/**`, `run.cjs`, `README.md`, `shell.e2e.cjs`. May adjust `templates/calendar/*.twig` (hand-over from P1) for data attributes only. |
| Stub editor | `ui/editor/index.js` stub: renders the sample page (bleed, `placeholders: 'all'`) in the stage slot, a plain list of element rows with `data-layer` in the layers slot (click selects), and mounts `mountProps` in the props slot — enough for P5a to work before P4 lands. |
| Depends on | P1, P2. |
| Tests | `payload` suite (dirty tracking, overlay of in-flight edits, response application); e2e `shell`: create → step 1 changes (type, year rename G14, bleed limits, Označit vše tri-state) → step 2 preset → save → reload keeps everything (R4 for steps 1–2) → Dokončit → list card without the pill and reopening in step 5 (R5) → leave confirmation → delete confirmation; a 14-day calendar shows no left / right choice (9.6); steps 3–5 disabled without a layout. |
| Handoff checks | R4 (steps 1–2), R5, G14 (UI), 9.6 (no AI controls, no 14-day choice). |

### P4 — editor interaction (step 3)

| | |
| --- | --- |
| Goal | Select, move, resize, snap with guides, link, nudge, layers (reorder, eye, parts list rows), zoom, undo / redo, part selection + part-offset drag + part highlights, hint line, Nahrát layout / Uložit layout do souboru. |
| Owns | `ui/editor/index.js`, `stage.js`, `overlay.js`, `keys.js`, `layers.js`, `calendar-editor.css`, `tests/e2e/calendar/editor-*.e2e.cjs`, `link-*.e2e.cjs`. |
| Depends on | P3. Runs parallel to P5a, P6a, P6b, P7a, P7b. Calls `mountProps` (P5a) and `mountPartsOverlay` (P5b stub) through their contracts only. |
| Tests (e2e, real pointer) | E1–E11, E17, E19, E20 (repeat E3 / E4 at 200 %), E14 (part offset + reset), the part-selection half of E12, L1–L9, **every row of handoff §7.4** in `photoLeft` (all boxes asserted before / after), the first row in `photoRight` and `twoSided`, L7 by sampling `boxes()` mid-drag (`hold: true`), R6 with a second calendar, window-resize alignment of frame vs box, covered-element move via Vrstvy. Each data-changing scenario saves, reloads and re-asserts. |
| Handoff checks | E1–E11, E14, E17, E19, E20, L1–L9, 9.3 table, R6. |

### P5a — shared controls and element panels

| | |
| --- | --- |
| Goal | `ui/controls/*` and the panels of every element except the Kalendárium (handoff §6.1, map §8.8) incl. Pozice a velikost, uploads, limits, HEX rules, font / cut selection, background panel, gradient editor; sliders as one undo step. |
| Owns | `ui/controls/**`, `ui/editor/props.js`, `calendar-controls.css`, `tests/e2e/calendar/panels.e2e.cjs`. |
| Depends on | P3 (works against the stub editor). |
| Tests | e2e: E9 and L9 through the fields; E18 (month font size → frame grows); limits table (out-of-range values corrected); HEX accept / reject toast; logo upload shows on the page and persists; Tvar changes every week page (G12); QR per-page switch; undo of a slider is one step. |
| Handoff checks | E9, E18, G12, L9 (typed). |

### P5b — Kalendárium panels and part chrome (wave 4)

| | |
| --- | --- |
| Goal | Whole-block panel (Názvy dnů, Den v týdnu, Číslo dne, background, Velikosti sliders), all part panels (map §8.9), event box bars, icon cell bars, holiday wrap bars, icon order (SortableJS + Alt+↑↓), icon grid picker and per-cell settings, custom icons, the two content shortcuts of D2. |
| Owns | `ui/editor/props-kal.js`, `ui/editor/parts-overlay.js`, additions to `calendar-controls.css` (after P5a merged), `tests/e2e/calendar/kalendarium.e2e.cjs`. |
| Depends on | P4, P5a. |
| Tests | e2e: E12, E13, E15, E16; feature switched off while its part is selected → falls back to the whole block; time part switches the sample page; frame 2 of a fortnight mirrors a moved part. |
| Handoff checks | E12, E13, E15, E16. |

### P6a — step 4 frame, pages, photos, QR, special pages

| | |
| --- | --- |
| Goal | Step-4 layout and navigation, page list with badges and dots, per-page panel and overrides, Styl stránek mounting the editor, Fotky a popisky (rows + bulk upload), QR kódy, Speciální stránky (settings, add menu, reorder, uploaded pages via `api.upload(kind 'page')` and `api.documentText`), and the real `render/special.js` (cover, back, generated kinds and layouts per map §4.5, uploaded image / text pages per handoff §10.10, all in mm and proportional to the page). |
| Owns | `ui/step4.js`, `ui/pagelist.js`, `ui/pagepanel.js`, `ui/tools/photos.js`, `qr.js`, `special.js`, `render/special.js`, `calendar-content.css`, `tests/Fixtures/calendar/suites/special.mjs`, `tests/e2e/calendar/content-pages.e2e.cjs`. |
| Depends on | P3. |
| Tests | suite `special` (each kind and layout renders well-formed SVG at two page sizes; waste grid marks exactly the rule's days; year grid marks holidays; back variant switch). e2e: C1, C12, G13, E21 (once P4 is merged — the script is delivered here and skips while the stub editor is detected), per-page QR, override dot, Vrátit na styl. |
| Handoff checks | C1, C12, G13, E21. |

### P6b — content tools

| | |
| --- | --- |
| Goal | Odpady, Akce a spolky, Jména navíc, Fáze měsíce, Šichtovnice dialogs with the shared date picker (map §8.11), feature-off notice + Zapnout. |
| Owns | `ui/tools/waste.js`, `events.js`, `names.js`, `moon.js`, `shifts.js`, `datepicker.js`, `calendar-tools.css`, `tests/e2e/calendar/content-tools.e2e.cjs`. |
| Depends on | P3. |
| Tests | e2e: C4, C5, C6 (no Excel control), C7, C8, G9 through the UI, end-before-start rejected, last weekday rejected, moon edit + Přepočítat, shift presets; all persisted after reload. |
| Handoff checks | C4–C8, G9. |

### P7a — step 5 preview and floating editors

| | |
| --- | --- |
| Goal | Step-5 frame, large page with print marks for the designer and trimmed page for the client, thumbnails, photo upload / zoom / pan / remove, caption editor, plus menu (Odpad, Akce, Komentář, removal list), comment bubbles and thread, Dokončit flow. |
| Owns | `ui/step5.js`, `ui/step5-overlay.js`, `ui/popover.js`, `calendar-preview.css`, `tests/e2e/calendar/preview.e2e.cjs`. |
| Depends on | P3. |
| Tests | e2e: C2, C9 (with `seed` for a waste kind), C10 designer half, C11, empty event / comment not saved, Enter / Esc behaviour, popover drag. |
| Handoff checks | C2, C9, C10, C11. |

### P7b — warnings, history, client link, client view

| | |
| --- | --- |
| Goal | Warning chip / list / marks data, Historie dialog (workspace and list), Odkaz pro klienta (copy + regenerate), the client mode end to end (public link and read-only shared user). |
| Owns | `ui/warnings.js`, `ui/history.js`, `ui/client-link.js`, `calendar-dialogs.css`, `tests/e2e/calendar/client.e2e.cjs`, `warnings.e2e.cjs`. |
| Depends on | P3 (and P1's endpoints). |
| Tests | e2e: C3 (small and portrait fixtures from `tests/e2e/calendar/lib/fixtures/`), warnings on page / thumbnail / list; R1; R2 (UI has no route to steps 1–4, and a forged design POST from the client page is refused); R3; C10 client half; link regeneration kills the old URL. |
| Handoff checks | C3, C10, R1–R3. |

### P8a — end-to-end completion, comparison, report

| | |
| --- | --- |
| Goal | Run the whole suite, fill gaps so every check of handoff §9 has a named automated test, compare with the prototype, write the final report. |
| Owns | `tests/e2e/calendar/compare-prototype.cjs`, `docs/calendar/report.md`, fixes requested from owners are made here only after the wave-3/4 phases are closed. |
| Comparison | Open `docs/calendar/prototype/wboost-prototyp.html` in the same browser, create a calendar there, read its layout from `localStorage['wb-cal-<pid>']` and compare each default box as page fractions with ours for `photoLeft` and `photoRight` (tolerance 1 % of the page width / height); screenshot both sample pages side by side into `docs/calendar/report-assets/` and record a mean pixel difference computed on a canvas in the page. Screenshots of the default pages at 390 / 820 / 1440 px in both themes via `shoot.cjs`. |
| Report | Handoff §9.7: check-by-check table with the test that proves it, failures with reasons, the assumptions of §G, every "recommended" behaviour of handoff §8 implemented or changed. |

### P8b — documentation and MCP

| | |
| --- | --- |
| Goal | `docs/architecture/calendars.md` (contracts, invariants, traps found on the way, the test hook), CLAUDE.md rows (topic table, CSS table, entity list), `docs/architecture/app-shell.md` module mention, and three MCP tools. |
| MCP | New scopes in `McpScope`: `calendars:read`, `calendars:write` (`write` grants `read`). Tools in `src/Mcp/Tool/`: `ListCalendarsTool` (`list_calendars(project)` → id, name, year, type, finished, updatedAt, last change), `GetCalendarTool` (`get_calendar(calendar)` → design summary without `layout.styles`, content collections, asset map), `UpdateCalendarContentTool` (`update_calendar_content(calendar, operations[])` → dispatches `SaveCalendar` as a designer actor with the same validation; no design changes, no deletes of calendars). Responses in `src/Mcp/Response/`, names added to `plugin/wboost/skills/wboost/SKILL.md`, server instructions in `config/packages/mcp.php`, `ScopeFilteringTest` / `SkillDocumentationTest` updated, tool tests under `tests/Mcp/`. |
| Owns | the files named above. |
| Depends on | P1 (MCP), everything (docs). |

---

## G. Assumptions

### G.1 Where handoff and prototype were silent or the prototype was only a shortcut

1. Binding band 12.5 mm (8.6 % of 145 mm) and 38 holes of 4.3 mm on an 8 mm pitch — proportions of the prototype, pending print-shop values (handoff §10.12).
2. Crop marks: 2 mm outside the bleed, 5 mm long, 0.25 pt (the prototype drew "6 mm" under its wrong scale; its comment says 5).
3. Default type sizes and mm style values are the prototype's × 1.476, rounded (§B.3).
4. Default boxes are rounded to clean mm values (§B.4); every one is within 0.5 % of the page of the prototype's fraction. The QR default no longer shares its right edge with the logo.
5. Geometry grid 0.1 mm; nudge 0.3 / 3 mm; link tolerance 0.15 mm (edges equal or 0.1 mm apart coincide); minimum box 6 × 4 mm.
6. 14-day layout as designed in §B.4: month text top left, week number bottom right, caption centred under the photo, crest centred over the header line, icon grid `2 + 2`; one Měsíc and one Číslo týdne element (risk R4). In the editor the two frames are selected separately; the tag and the position panel name them `1. týden` / `2. týden`.
7. Mirroring flips exactly the list of §B.5 (handoff says "text alignment and the day-number side"); gradients, pictures and the photo shadow are not mirrored.
8. Compact rows never truncate a holiday name (client edge case); it wraps instead. Name-day lines wrap to two lines when the column is narrow. The text block is centred as a whole when it would leave the row.
9. An uploaded photo is clipped to the corner radius (the prototype clipped only the placeholder).
10. The sample texts (`Popisek fotografie, autor`, `QR`) are drawn only on designer style surfaces (editor, step 2), never in steps 4–5, the client view or thumbnails.
11. The event box, icon cells and holiday wrap bars appear only while their part is selected (handoff wording; the prototype always drew them).
12. Elements whose feature is off are not snap targets or link followers.
13. Typed Š / V stop at the bleed edge instead of shifting the element; typed values are rounded to 0.1 mm.
14. Auto-grow considers every week page, is part of the causing undo step, and grows towards the side opposite the alignment anchor.
15. Undo keeps 100 steps and covers the caption switch; the undo / redo buttons also show in step 4 Styl stránek. Type change clears the undo history; bleed changes are not undo steps (restored snapshots are clamped to the current bleed).
16. Icon categories: an ordered list in the style, default `Odpady, Fáze měsíce`; others are added with Přidat ikonu (the prototype's order was unstable).
17. Waste rhythm rules follow ISO week numbers (as municipalities publish them), so they shift across a 53-week year; "První v měsíci" = day ≤ 7; season starts are the fixed dates 20 Mar, 21 Jun, 22 Sep, 21 Dec; moon phases are the true astronomical phases on their Czech civil date (e.g. the full moon of 20 Feb 2027 23:23 UTC prints on 21 Feb) and stay editable.
18. A waste range is stored as real dates (default: the whole calendar year), which removes the prototype's month-only / exact-date split and survives a year change.
19. New calendars contain no demo data; only four built-in waste kinds exist, switched off. Sklo and custom kinds are added from `+ Přidat druh`. Special pages are all off until chosen.
20. Special page text documents: `.docx` and `.txt`, first 6000 characters, plain paragraphs. PDF special pages: first page rasterised at 200 dpi.
21. Year change: 29 February items are dropped with a message; comment dates and range bounds move to 28 February; moon edits are discarded; overrides stay by week number.
22. History: content changes of **every** step are recorded on save (the prototype logged only some step-5 actions), design saves as one coalesced entry; the list shows the latest 200. Comments are permanent.
23. Per-day removal in step 5 lists the day's extra collections and **all** events of that day (handoff §10.14), for designer and client alike.
24. Client mode: the name is read-only, tools whose feature is off are hidden, **Zapnout** never shows, Historie and the warnings list are available. A logged-in user with read-only sharing gets the same client mode inside the app shell.
25. The client link is created with the calendar and works until regenerated; it does not expire.
26. Saving stays explicit for both roles; the step number is stored silently when nothing else is unsaved, so reopening returns to the last step.
27. Design changes are last-write-wins per top-level key (risk R1).
28. Upload limit 10 MB per file (app-wide rule); uploads are never deleted individually (risk R7).
29. Loading a layout offers only calendars of the same type (handoff §8 recommended) and copies its pictures; a layout file from another project loads without pictures.
30. Zoom is not persisted; fit is clamped to 1–5.2 px per mm.
31. Bulk photo upload resets crop and zoom and records pixel sizes (handoff §8), and overwrites a caption only when the photo carries a description.

### G.2 Recommended behaviours of handoff §8 / §10 — all implemented as recommended

Same-type filter for layout loading · confirmation on type switch, styles kept, positions reset ·
29 February dropped with a message · warning before year / type change when page overrides exist,
overrides kept by week key · bleed adaptation of §10.7 · waste end-before-start rejected · explicit
save + leave confirmation · Uložit layout do souboru · delete confirmation · item-wise merging ·
no print export (renderer dimensionally exact) · uploaded special pages per §10.10 · regenerable
client link · band as a calendar property · cover / back / special pages as far as the prototype
goes · removal of step-5 additions · uploaded QR only · disk uploads only.

### G.3 New Czech copy introduced by this design (everything else is map §8)

| Where | Text |
| --- | --- |
| Type switch confirmation | Title `Změnit typ kalendáře?` · `Rozmístění prvků se vrátí do výchozího rozvržení pro nový typ. Písma, barvy a nastavení částí zůstanou.` + (with overrides) ` Fotky a popisky u stran, které v novém typu nezačínají stranu, se nezobrazí.` · buttons `Změnit typ`, `Zrušit` |
| Year change confirmation (overrides exist) | Title `Změnit rok?` · `Fotky a popisky zůstanou u týdnů se stejným číslem. Akce, jména a svozy se přesunou na stejný den nového roku.` · `Změnit rok`, `Zrušit` |
| 29 February toast | `29. 2. v roce {year} není — odebráno položek: {n}` |
| Delete calendar | Title `Smazat kalendář?` · `Kalendář „{name}“ se smaže včetně fotek a obsahu. Tuto akci nelze vrátit.` · `Smazat`, `Zrušit` |
| Leave with unsaved changes | Title `Neuložené změny` · `Kalendář má neuložené změny.` · `Uložit a odejít`, `Odejít bez uložení`, `Zůstat` |
| Layout menu | `Uložit layout do souboru` · `.json pro použití v jiném kalendáři` |
| Fortnight in step 2 | Card `Dvoustranné rozvržení` · `První týden vlevo, fotografie uprostřed, druhý týden vpravo`; summary word `dvoustranné` |
| Kalendárium frames | `1. týden`, `2. týden` (segmented control and frame tag) |
| Removal list in the day plus menu | Heading `V tento den` · remove aria `Odebrat {název}` |
| Removal confirmations (D2) | `Odebrat druh odpadu „{name}“?` / `Odebrat provoz „{name}“?` · `Termíny a nastavení se smažou.` · `Odebrat`, `Zrušit` |
| Client link dialog | Button `Vygenerovat nový odkaz` · note `Starý odkaz přestane fungovat.` |
| Errors | `Tuto změnu může udělat jen grafik.` · `Fotka musí být JPG, PNG nebo WebP.` · `PDF se nepodařilo přečíst. Nahrajte stránku jako obrázek.` · `Nahrajte dokument ve formátu DOCX nebo TXT.` · `Soubor je větší než 10 MB.` |
| History | sentences of §C.6 not present in map §8.13 |
