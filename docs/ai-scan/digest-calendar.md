# AI scan — digest of the calendar module (read-only exploration, 2026-11-12)

Feature being prepared: a user uploads a picture (later a PDF) of an existing desk-calendar page; the
system produces a WBoost calendar whose layout and style match it. This file records what the
codebase offers and limits. Nothing here is a design decision. Statements marked **(inferred)** were
concluded from the code, not read as a stated rule.

Sources read: `CLAUDE.md`, `docs/architecture/calendars.md` (whole), `docs/calendar/handoff.md`,
`docs/calendar/prototype-map.md` §9 + the prototype lines it names, `docs/calendar/report.md`,
`docs/calendar/architecture.md` §F, `assets/calendar/model/**`, `resources/calendar/model.mjs`,
`src/Services/Calendar/*`, `src/Mcp/Tool/*Calendar*Tool.php`, `src/Mcp/Fill/Calendar*.php`,
`assets/calendar/ui/step2.js`, `assets/calendar/render/week*.js`, `git log --oneline -40`.

---

## 1. The layout a scan must produce

### 1.1 Where it lives

`calendar.layout` (JSONB, Doctrine type `json_object`) = `{ v: 1, boxes, order, styles }`
(`docs/architecture/calendars.md:66-68`). One layout serves **every week page** of the calendar; pages
carry content overrides only (`calendars.md:16-17`). Beside it, on the same row, sit the design keys a
scan may also want to set: `type`, `preset`, `print`, `features`, `page`, `name`, `year`
(`src/Services/Calendar/CalendarDesignRules.php:20`).

### 1.2 Units

- Geometry: **mm from the top-left trim corner**, x → right, y → down, 0.1 mm grid (`roundMm`,
  `assets/calendar/model/defaults.js:139-142`).
- Type sizes and line widths: **pt** (`MM_PER_PT = 25.4 / 72`, `defaults.js:6`).
- Colours: `#RRGGBB`; the model upper-cases them (`resources/calendar/model.mjs:203-209`).
- Opacities: 0–100 in steps of 5 (`LIMITS.percent`, `defaults.js:87`).
- Page: **310 × 145 mm**, binding band 12.5 mm at the top, a row of holes
  (`DEFAULT_PAGE`, `defaults.js:8-13`). Bleed default 5 mm, on (`DEFAULT_PRINT`, `defaults.js:15`).
- A box may reach into the bleed, never beyond `[-B, W+B] × [-B, H+B]` (`calendars.md:61-62`); the
  model clamps (`clampBoxesToBounds`, `model.mjs:362-365`).

### 1.3 Elements (the whole vocabulary)

`assets/calendar/model/elements.js:3-13`:

| key | Czech label | has box | hideable | what a week page draws |
|---|---|---|---|---|
| `photo` | Fotografie | yes | no | one photo per page (`pagePhotos[pageKey]`), cover-cropped; optional radius, border, shadow (`render/week.js:55-76`) |
| `cap` | Popisek fotografie | yes | via `features.captions` | wrapped caption text + fill (`week.js:123-146`) |
| `logo` | Logo | yes | `hidden` | one image, `contain` / `cover` (`week.js:148-162`) |
| `cal` | Kalendárium | yes | no | the day rows (7 per frame, always stacked vertically) |
| `month` | Měsíc | yes | `hidden` | one line of text + optional fill (`week.js:94-109`) |
| `wk` | Číslo týdne | yes | `hidden` + `features.weekNumbers` | one line of text (`week.js:111-121`) |
| `head` | Hlavička | yes | no | **a filled rectangle only** — colour / gradient / image; no text on week pages (`week.js:78-83`) |
| `qr` | QR kód | yes | `hidden` + `features.qr` | a square image |
| `bg` | Pozadí | **no box** | no | page-wide fill, always painted first |

Box keys: `photo cap logo head month month2 cal cal2 wk wk2 qr` (`elements.js:16`). The second frames
`cal2 month2 wk2` exist **only on a `fortnight` calendar** (`elements.js:22`, `normalize.js:164`);
they share every style with their first frame and are drawn horizontally mirrored
(`calendars.md:75-89`).

Kalendárium parts (style targets inside `cal`), `elements.js:34-49`: `num numSat numSun numHol day
name hol line moon time ev shift waste icon`.

### 1.4 `boxes`

`{ "<boxKey>": { x, y, w, h, hidden? } }`.

- Minimum 6 × 4 mm (`K.MIN_W`, `K.MIN_H`, `defaults.js:36-37`); smaller values are raised and reported
  as a note (`model.mjs:319-326`).
- `hidden: true` only on `logo month wk qr` (`HIDEABLE_BOXES`, `elements.js:31`). `cap` is hidden by
  `features.captions`; `wk` / `qr` also need `features.weekNumbers` / `features.qr`
  (`elements.js:92-114`).
- Month and week-number boxes **auto-grow** to their widest text over all week pages
  (`calendars.md:667-672`). On the server the width is estimated (no fonts in node,
  `calendars.md:726-728`).

### 1.5 `order`

Every key of `photo cap logo head month cal wk qr` exactly once, **index 0 = top layer**; default
`['qr','logo','cap','wk','month','head','cal','photo']` (`elements.js:32`). `bg` is never listed.
The model refuses anything else (`invalid_order`, `model.mjs:352-359`).

### 1.6 `styles` — every key, with allowed values

Defaults: `defaultStyles()`, `assets/calendar/model/layouts.js:43-105`. Enumerations: `ENUMS`,
`resources/calendar/model.mjs:47-64`. Ranges: `LIMITS`, `defaults.js:75-91`, applied by
`clampStyles`, `normalize.js:58-104`.

**Text style** = `{ family, weight, size, color }`:

- `family`: exactly one of the project's `fonts[].family` strings (e.g. `"Rubik (Rubik Bold)"`) or
  `null` = built-in fallback (`model.mjs:196-202`). With a project face the **face decides the
  weight** (drawn at weight 400 of its own `@font-face` family); `weight` only applies to the
  fallback and is capped at 700 there (`assets/calendar/model/fonts.js:51-58`).
- `size`: 3–60 pt, step 0.5 (`LIMITS.fontSize`).
- A day number is additionally capped at 74 % of the row height (78 % in compact rows) whatever its
  `size` (`render/week-layout.js:107-108`).

| Path | Shape / values |
|---|---|
| `num`, `numSat`, `numSun`, `numHol` | text style — day number: working days, Saturday, Sunday, public holiday |
| `day` | text style — weekday name |
| `name` | text style — name day |
| `hol` | text style + `wrapWidth` mm \| null |
| `ev` | text style + `align` start\|middle\|end\|null, `valign` top\|middle\|bottom, `box` `{x,y,w,h}` \| null (min 6 × 1.5 mm) |
| `time` | text style + `position` text\|sep, `align`, `valign` |
| `shift` | text style + `bgMode` alt\|all\|none, `bg` colour \| null |
| `placeText` | text style |
| `month` | text style + `align`, `upper` bool, `pad` 0–20 mm, `bg` fill |
| `wk` | text style + `align`, `form` a = „1. týden“ \| b = „Týden 1“ \| c = „T1“ (`generate/pages.js:41-42`) |
| `cap` | text style + `align`, `valign`, `pad` 0–20, `bg` fill |
| `head` | `{ bg: fill, text: text style }` — `text` styles the headings of the special pages, not the week page |
| `bg` | fill (page background) |
| `photo` | `source` client\|self, `radius` 0–30 mm, `border {on, width 0.1–10, color}`, `shadow {on, blur 0–30, opacity, dx, dy −10…10, color}` |
| `logo` | `{ asset: id\|null, fit: contain\|cover }` |
| `qr` | `{ perPage: bool, asset }` |
| `cal` | `bg {color\|null, opacity}`, `satColor`, `sunColor`, `numberSide`, `numAlign` \|null, `dayFormat` full\|short, `dayPosition` side\|above, `dayAlign` \|null, `iconSize` 1.5–12 mm, `iconGrid` "4"\|"2x2"\|"1+2"\|"2+1", `iconCellW` mm\|null, `iconCells` (open map), `placeLabels`, `emptyIconSlot` |
| `lines` | `{ on, width 0.1–5 pt, style solid\|dash\|dot, color }` — one rule **under each day row**, inset 5 mm from both frame edges (`week-layout.js:276-289`) |
| `moon` | `color`, `size` 1.5–9 mm, `valign`, `phases [4 × bool]`, `icons [4 × asset\|null]` |
| `icons` | `order` (subset of waste, moon, ev, time, season), `ev {name, asset, perEvent}`, `time {asset, color}`, `season {mode four\|one, asset, assets[4], color}` |
| `waste.kinds` | open map `{ "<wasteKindId>": { color?, icon? } }` |
| `offsets` | open map `{ "<part>": { x, y } }` — mm nudges of a Kalendárium part |

**Fill** (`fill()`, `layouts.js:19-41`; drawn by `fillLayers`, `render/svg.js:104-124`) — three
layers stacked colour → gradient → image, each with its own switch:

```
{ colorOn, color, colorOpacity,
  gradient: { on, type: linear|radial, angle, stops: [{ color, opacity, pos 0–100 }, … ≥ 2] },
  imageOn, asset: id|null, fit: cover|contain, imageOpacity }
```

Open maps (`cal.iconCells`, `waste.kinds`, `offsets`): keys are data, an entry merges one level,
`null` removes it (`model.mjs:66-67`, `259-272`).

### 1.7 Presets

`presetsForType`, `layouts.js:9-13`: `week` → `photoLeft`, `photoRight`; `fortnight` → `twoSided`
only. `photoRight` = `mirrorLayout(photoLeft)` (`layouts.js:198`). Preset-dependent style values
(`PRESET_STYLES`, `layouts.js:112-116`): `cal.numberSide`, `month.align`, `wk.align`, `cap.align`,
`cal.iconGrid`. Edge tables: `layouts.js:134-160`.

**The stored `preset` is a label, not a constraint on the boxes.** A layout with any geometry still
carries `photoLeft` / `photoRight` / `twoSided`; `replaceLayout` keeps the current preset or takes
the first of the type (`assets/calendar/state/store.js:489-494`). Clicking the *other* card in step 2
later mirrors whatever the layout is (`store.js:397-403`).

Default boxes, 5 mm bleed (dumped from the model with node on 2026-11-12):

| box | photoLeft | photoRight | twoSided |
|---|---|---|---|
| `head` | −5, −5, 320 × 32 | same | same |
| `photo` | −5, 27, 202 × 123 | 113, 27, 202 × 123 | 84, 27, 142 × 112 |
| `cal` | 197, 27, 118 × 112 | −5, 27, 118 × 112 | −5, 27, 89 × 112 |
| `cal2` | — | — | 226, 27, 89 × 112 |
| `month` | 203, 12.5, 101 × 14.5 | 6, 12.5, 101 × 14.5 | 6, 12.5, 120 × 14.5 |
| `month2` | — | — | 184, 12.5, 120 × 14.5 |
| `wk` | 203, 139, 101 × 4.5 | 6, 139, 101 × 4.5 | 6, 139, 72 × 4.5 |
| `wk2` | — | — | 232, 139, 72 × 4.5 |
| `cap` | 5, 135, 93 × 5.5 | 212, 135, 93 × 5.5 | 89, 139.5, 132 × 4.5 |
| `logo` | 168.5, 31, 23.5 × 23.5 | 118, 31, 23.5 × 23.5 | 143, 15, 24 × 24 |
| `qr` | 170.5, 116.5, 21 × 21 | 118.5, 116.5, 21 × 21 | 199.5, 113, 21 × 21 |

### 1.8 What validation rejects

Two layers, both on every design write.

**(a) The model's patch rules** — `resources/calendar/model.mjs`, refusal = `{ ok: false, code,
message }` → `CalendarModelRefused` (`src/Services/Calendar/CalendarModelBridge.php:124-133`):

| code | when | line |
|---|---|---|
| `no_layout` | a `layout` patch on a calendar without a layout | `model.mjs:347` |
| `unknown_preset` / `preset_type_mismatch` | preset not in the three / not of this type | `:174-179` |
| `unknown_box` | a box key the calendar's layout does not have (so `cal2` on a `week` calendar) | `:298-300` |
| `invalid_box` | non-object, a field other than `x y w h hidden`, non-finite number | `:295`, `:301`, `:315-316` |
| `not_hideable` | `hidden` on anything but `logo month wk qr` | `:306-310` |
| `invalid_order` | not every element exactly once | `:352-358` |
| `unknown_style_key` | a key absent from the calendar's current `styles` (message lists the siblings) | `:273-275` |
| `invalid_style_value` | wrong type, enum value outside `ENUMS`, `null` where the default is not `null`, a group given a scalar | `:211-225`, `:282-285` |
| `unknown_font` | `family` not one of the project's faces | `:196-202` |
| `invalid_color` | not `#RRGGBB` | `:203-209` |

Out-of-range **numbers are not refused**: they are clamped and reported in `notes`
(`noteDifferences`, `model.mjs:332-343`, `367-377`).

**(b) The server's structural rules** — `CalendarDesignRules::layout()`,
`src/Services/Calendar/CalendarDesignRules.php:151-214`. Error = `InvalidCalendarChange` whose message
is the user-facing Czech text, mostly `Neplatná data kalendáře (<path>).` (`:432-435`):

- top-level keys exactly `v boxes order styles`; `v === 1` (`:163-171`);
- box keys from `BOX_KEYS`; each box only `x y w h hidden`, numbers in −1000…5000, `w`, `h` > 0;
  `hidden` only `true` and only on the hideable four (`:306-331`);
- `month2` / `wk2` only beside `cal2` (`:187-191`);
- `order` a duplicate-free list of element keys (`:193-203`);
- `styles`: any JSON, strings ≤ 255 chars without control characters, depth ≤ 8 (`:339-392`);
  whole layout ≤ 200 000 bytes (`:157-159`);
- every asset id the layout mentions must be uploaded to **this** calendar —
  `Layout odkazuje na soubor, který v kalendáři není nahraný.`
  (`src/MessageHandler/Calendar/SaveCalendarHandler.php:83-87`);
- preset must fit the type after the patch — `Rozvržení neodpovídá typu kalendáře.` (`:138-140`).

The server does **not** understand a style value; only the JS does.

### 1.9 A complete real layout (photoLeft default, 5 mm bleed, project without fonts)

Output of `normalizeLayout(defaultLayout('photoLeft', DEFAULT_PAGE, 5, { fonts: [] }), 'week', …)`:

```json
{"v":1,
 "boxes":{"photo":{"x":-5,"y":27,"w":202,"h":123},"cap":{"x":5,"y":135,"w":93,"h":5.5},
  "logo":{"x":168.5,"y":31,"w":23.5,"h":23.5},"head":{"x":-5,"y":-5,"w":320,"h":32},
  "month":{"x":203,"y":12.5,"w":101,"h":14.5},"cal":{"x":197,"y":27,"w":118,"h":112},
  "wk":{"x":203,"y":139,"w":101,"h":4.5},"qr":{"x":170.5,"y":116.5,"w":21,"h":21}},
 "order":["qr","logo","cap","wk","month","head","cal","photo"],
 "styles":{
  "photo":{"source":"client","radius":0,"border":{"on":false,"width":0.7,"color":"#FFFFFF"},
   "shadow":{"on":false,"blur":3,"opacity":35,"dx":1.5,"dy":2,"color":"#000000"}},
  "cap":{"family":null,"weight":400,"size":9,"color":"#6E6977","align":"start","valign":"middle","pad":3,
   "bg":{"colorOn":true,"color":"#D2CED8","colorOpacity":100,"gradient":{"on":false,"type":"linear","angle":90,
    "stops":[{"color":"#FFFFFF","opacity":100,"pos":0},{"color":"#FFFFFF","opacity":100,"pos":100}]},
    "imageOn":false,"asset":null,"fit":"cover","imageOpacity":100}},
  "logo":{"asset":null,"fit":"contain"},
  "head":{"bg":{"colorOn":true,"color":"#CBC7D1","colorOpacity":100,"gradient":{"on":false,"type":"linear","angle":90,
    "stops":[{"color":"#CBC7D1","opacity":100,"pos":0},{"color":"#F6F5F8","opacity":100,"pos":100}]},
    "imageOn":false,"asset":null,"fit":"cover","imageOpacity":100},
   "text":{"family":null,"weight":800,"size":15,"color":"#2B2830"}},
  "month":{"family":null,"weight":800,"size":15,"color":"#2B2830","align":"end","upper":true,"pad":3,
   "bg":{"colorOn":false,"color":"#FFFFFF","colorOpacity":100,"gradient":{"on":false,"type":"linear","angle":90,
    "stops":[{"color":"#FFFFFF","opacity":100,"pos":0},{"color":"#CBC7D1","opacity":100,"pos":100}]},
    "imageOn":false,"asset":null,"fit":"cover","imageOpacity":100}},
  "wk":{"family":null,"weight":400,"size":9.5,"color":"#6E6977","align":"end","form":"a"},
  "qr":{"perPage":false,"asset":null},
  "bg":{"colorOn":true,"color":"#F6F5F8","colorOpacity":100,"gradient":{"on":false,"type":"linear","angle":90,
    "stops":[{"color":"#F6F5F8","opacity":100,"pos":0},{"color":"#CBC7D1","opacity":100,"pos":100}]},
    "imageOn":false,"asset":null,"fit":"cover","imageOpacity":100},
  "cal":{"bg":{"color":null,"opacity":100},"satColor":"#2F78B7","sunColor":"#BE3A2E","numberSide":"end",
   "numAlign":null,"dayFormat":"full","dayPosition":"side","dayAlign":null,"iconSize":6,"iconGrid":"4",
   "iconCellW":null,"iconCells":{},"placeLabels":false,"emptyIconSlot":true},
  "lines":{"on":true,"width":1,"style":"solid","color":"#D7D3DC"},
  "num":{"family":null,"weight":800,"size":28,"color":"#2B2830"},
  "numSat":{"family":null,"weight":800,"size":28,"color":"#2F78B7"},
  "numSun":{"family":null,"weight":800,"size":28,"color":"#BE3A2E"},
  "numHol":{"family":null,"weight":800,"size":28,"color":"#BE3A2E"},
  "day":{"family":null,"weight":600,"size":11,"color":"#6E6977"},
  "name":{"family":null,"weight":400,"size":9.5,"color":"#2B2830"},
  "hol":{"family":null,"weight":400,"size":8,"color":"#BE3A2E","wrapWidth":null},
  "ev":{"family":null,"weight":600,"size":8,"color":"#2E5A8C","align":null,"valign":"middle","box":null},
  "time":{"family":null,"weight":400,"size":8,"color":"#6E6977","position":"text","align":null,"valign":"bottom"},
  "shift":{"family":null,"weight":600,"size":8,"color":"#6E6977","bgMode":"alt","bg":null},
  "placeText":{"family":null,"weight":600,"size":7.5,"color":"#6E6977"},
  "moon":{"color":"#2B2830","size":5,"valign":"middle","phases":[true,true,true,true],"icons":[null,null,null,null]},
  "icons":{"order":["waste","moon"],"ev":{"name":"Akce","asset":null,"perEvent":false},
   "time":{"asset":null,"color":"#2B2830"},
   "season":{"mode":"four","asset":null,"assets":[null,null,null,null],"color":"#2B2830"}},
  "waste":{"kinds":{}},
  "offsets":{}}}
```

`ev.color` is the accent: the project's first palette colour that is not pure white / black, else
`#2E5A8C` (`model.mjs:95-96`, `layouts.js:7`).

---

## 2. What is NOT expressible

Concrete limits a scan of a real printed calendar will hit:

1. **No free shapes or decorations.** The vocabulary is eight boxes + the page background. A
   coloured stripe, a divider, a frame, a corner ornament, a second colour band has no element. The
   only filled rectangles are `head.bg`, `month.bg`, `cap.bg`, `cal.bg` (colour + opacity only) and
   `bg`. Workaround inside the model: an **uploaded image** as `bg.asset` / `head.bg.asset`.
2. **One photo per page.** No collage, no second photo, no photo masks other than a corner radius.
3. **One logo, one QR.** A sponsor strip or several logos is not expressible (except baked into a
   background image).
4. **The Kalendárium is always a vertical stack of day rows** — 7 per frame, equal height, one rule
   under each row (`week-layout.js:86-90`). Not expressible: days in a horizontal strip, a month
   grid, two columns of days on a 7-day page, rows of unequal height, vertical rules, boxed cells,
   alternating row backgrounds, a rule above the first row.
5. **Row internals are a fixed scheme**: a number column on one side (or centred), a text column
   (weekday + name day + holiday), a zone on the other side (icons, shifts). The weekday can sit
   beside or above the number (`dayPosition`); nothing else can be rearranged — only nudged by
   `offsets`.
6. **Free text does not exist on week pages.** No slogan, no municipality name, no year as a
   separate element; `head` draws no text. The month text is generated (`LEDEN 2027`,
   `PROSINEC 2026 / LEDEN 2027`); only its case (`upper`), style and alignment are settable. The
   week text has three fixed forms.
7. **Month and week number are single-line boxes**, vertically centred (`singleLine`,
   `week.js:85-88`); no vertical alignment key, no rotation (a vertical month name down the side is
   not expressible), no letter-spacing key (0 unless `upper`).
8. **No rotation, no skew, no opacity for any box**; stacking order is per element, not per part.
9. **Typography**: only fonts already uploaded to the project; without one, the built-in fallback
   (Outfit in the browser, Chromium's sans-serif in the headless render — `calendars.md:750-751`).
   No italic switch, no tracking, no line-height; weight is the face's.
10. **Page geometry**: 310 × 145 mm, band and holes have no UI and no model command
    (`calendars.md:1254`). The server would store another `page` (`CalendarDesignRules.php:243-260`:
    width / height 50–2000) but nothing offers it. A source of another aspect ratio (A4-wide,
    square, portrait) must be scaled into 310 × 145.
11. **`fortnight` has exactly one arrangement**: two week columns with the photo box anywhere, but
    `cal2` / `month2` / `wk2` are always mirror-styled copies of the first frame. Different styling
    per column is not expressible.
12. **Per-page variation is content, not layout**: a different header / background picture per page
    (`pageImages`), per-page photo crop, caption, QR. Nothing else may differ between weeks — a
    source whose months have different colours is not expressible as a style (only as 53 uploaded
    header images).
13. **Cover, back and special pages are fixed layouts**, not a canvas (`calendars.md:1390-1394`); a
    scanned cover cannot be reproduced beyond `pageSetup.cover.layout 0–3` or an uploaded full-page
    graphic (`specialPages` kind `image`, asset kind `page`).

---

## 3. The build path (in-process)

### 3.1 Create

`WBoost\Web\Message\Calendar\CreateCalendar(UuidInterface $calendarId, UuidInterface $projectId,
null|UuidInterface $userId)` (`src/Message/Calendar/CreateCalendar.php:13-20`) on the default
`command_bus`. The handler creates „Stolní kalendář {next year}“, type `week`, seeded content, a
client token, and the history line `Vytvořil kalendář`
(`src/MessageHandler/Calendar/CreateCalendarHandler.php:34-53`). Id from `ProvideIdentity::next()`.
No layout, no settings — those are a save afterwards.

### 3.2 Compute a design change — `CalendarModelBridge`

`src/Services/Calendar/CalendarModelBridge.php`:

```php
public function run(Calendar $calendar, array $commands = [], bool $pages = false,
    bool $warnings = false, null|array $view = null): CalendarModelResult            // :58
public function runDocument(array $calendarDocument, \stdClass $assets, Project $project,
    array $commands = [], bool $pages = false, bool $warnings = false,
    null|array $view = null): CalendarModelResult                                    // :82
```

Spawns `node resources/calendar/model.mjs`, 20 s timeout, ~60–100 ms per spawn (`:35-40`). **Never
writes.** Commands (`model.mjs:16-21`):

```
{ op: 'configure', name?, year?, type?, features?: {…}, print?: {…}, linkElements? }
{ op: 'preset', preset, reset? }
{ op: 'layout', boxes?: {…}, order?: [...], styles?: {…} }      // a patch, merged
{ op: 'replace_layout', layout, assets? }                         // a whole layout, normalised
{ op: 'step', step }
```

`CalendarModelResult` (`src/Value/Calendar/CalendarModelResult.php:22-29`): `payload` (JSON string
for `CalendarSavePayload::fromJson`, `null` = nothing changed), `notes` (clamps, mirrors), `design`
(fully materialised `stdClass`), `pages`, `warnings`, `view`.

Exceptions: `CalendarModelRefused` (`->reason` = code, message in English, written for an agent) and
`CalendarModelUnavailable` (node missing / crashed / `model_error`).

`replace_layout` runs `normalizeLayout` — it **silently drops unknown keys and falls back to
defaults** (`normalize.js:21-40`, `156-189`); `layout` (the patch) **refuses** unknown keys. A scanner
that wants to hear about its mistakes should use the patch on top of a preset.

### 3.3 Save — the one write path

```php
new SaveCalendar(UuidInterface $calendarId, CalendarActor $actor,
    null|\stdClass $designPatch = null, array $ops = [], bool $finish = false)
```

(`src/Message/Calendar/SaveCalendar.php:19-31`), `command_bus` (the handler needs the transaction for
its row lock, `calendars.md:1329-1330`). `designPatch` and `ops` come from
`CalendarSavePayload::fromJson($model->payload)` (`src/Value/Calendar/CalendarSavePayload.php:38`).

Errors, thrown inside `HandlerFailedException` (unwrap `getPrevious()`):

- `InvalidCalendarChange` — message is a Czech sentence for the user;
- `CalendarChangeForbidden` — the actor is a client;
- `CalendarNotFound`.

A refused save stores nothing (`calendars.md:1326-1328`). History: `Upravil vzhled a nastavení`,
coalesced per role for 10 minutes (`SaveCalendarHandler.php:135-139`, `172-180`); `revision++`.

`Mcp/Fill/CalendarWriter::apply(Calendar, CalendarActor, array $commands, bool $finish = false,
null|array $known = null)` (`src/Mcp/Fill/CalendarWriter.php:92-124`) does model → payload →
`SaveCalendar` in one call and takes the actor explicitly — usable in-process, but it converts every
failure into an MCP `ToolCallException` (`:166-180`), which is the wrong error type outside a tool.

### 3.4 Upload an asset

```php
CalendarAssetUploader::storeFile(Calendar $calendar, CalendarActor $actor,
    CalendarAssetKind $kind, UploadedFile $file): UuidInterface
```

(`src/Services/Calendar/CalendarAssetUploader.php:126-228`). Kinds: `photo logo icon qr image page`
(`src/Value/Calendar/CalendarAssetKind.php:12-19`). For bytes that are not a multipart upload:
`new UploadedFile($tmpPath, $name, null, null, true)` (`src/Mcp/Tool/UploadCalendarAssetTool.php:150`).

Rules in order (all `InvalidCalendarChange`, Czech text): > 10 MB `Soubor je větší než 10 MB.` →
client storage cap → a `photo` whose sniffed MIME is not JPEG / PNG / WebP / HEIC / HEIF / AVIF
`Fotka musí být JPG, PNG nebo WebP.` → not an image → > 60 MP by the header → unreadable. A PDF is
accepted for kind `page` only and its first page rasterised. Long edge > 1600 px → a WebP preview.
Dispatches `UploadCalendarAsset` (`src/Message/Calendar/UploadCalendarAsset.php`). Uploading never
changes the calendar; the layout references the id afterwards and the save checks ownership.
**Assets are never deleted** (`calendars.md:414-415`).

Entity: `src/Entity/CalendarAsset.php:34-97` — `kind`, `path = calendars/{calendarId}/{assetId}.{ext}`,
`previewPath`, `mimeType`, `originalName`, `width`, `height`, `description`, `sizeBytes`,
`uploadedByKind`, `uploadedBy`.

### 3.5 The actor — a trap outside a request

`CalendarActor::designer(null|UuidInterface $userId)` (`src/Value/Calendar/CalendarActor.php:22`).
`CalendarAccess::forUser()` and `CalendarLoader` resolve the role from `Security`
(`src/Services/Calendar/CalendarAccess.php:30-38`); **in a messenger consumer there is no token, so
`forUser()` answers `client`** (inferred from the code: `isGranted` without a token is false). An
in-process scanner must check `ProjectVoter::EDIT` in the web request, carry the user id in its
message and build `CalendarActor::designer($userId)` itself.

### 3.6 What the MCP tools add on top

| Tool | Adds over the services |
|---|---|
| `manage_calendar` (`src/Mcp/Tool/ManageCalendarTool.php`) | `create`: a **dry run** of the settings on a blank document before `CreateCalendar` (`:246-256`, `blank()` `:473-496`), so a refused request creates nothing; `ProjectVoter::EDIT` check; `finish`; `client_link`; `import_layout` (`ImportCalendarLayout::plan` + `CopyCalendarAsset` + history + `replace_layout`); `export_pdf` / `export_status` |
| `set_calendar_layout` (`SetCalendarLayoutTool.php:158-204`) | turns `preset / reset / boxes / order / styles` into `[preset command, layout command]`; nothing else |
| `upload_calendar_asset` | base64 decoding, temp file, the 4 MiB MCP transport note, the `next` hint |
| `update_calendar_content` | re-encodes `{collection, id, value, label}` as the web payload's `content` list, dispatches `SaveCalendar` without the model (`UpdateCalendarContentTool.php:238`) |
| `get_calendar` | the materialised document + `pages`, `fonts`, `palette`, history |
| `list_calendars` | reachable calendars |
| `render_calendar_page` | §4 |
| all | `CalendarLoader` (uuid + voter, "not found" = "not yours"), `CalendarWriter::designer()`, refusals phrased `message (reason)`, `CalendarPresenter` response shaping, scopes `calendars:read` / `calendars:write` |

Fonts and palette a scanner may use: `CalendarDocument::fonts(Project)` →
`[{family, fontName, faceName, weight, style, url}]`, `CalendarDocument::palette(Project)` →
`['#RRGGBB', …]` (`src/Services/Calendar/CalendarDocument.php:165-190`).

---

## 4. The look path

`CalendarPageRenderer::render(Calendar $calendar, string $pageKey, bool $bleed = false,
bool $marks = false, bool $sampleTexts = false, int $longEdge = 1600,
RenderImageFormat $format = Png): CalendarPageRender`
(`src/Services/Calendar/CalendarPageRenderer.php:65-159`).

- **Input**: a *stored* calendar and a page key (`cover`, `pre`, `w01`…`w53`, `post`, `sp:<id>`,
  `back`). It reads `CalendarDocument::calendar($calendar)` (`:97`) — **there is no entry point for
  an unsaved document**. A scan loop "propose → look → correct" must save each attempt (revision +1,
  possibly a history line) or the renderer needs a document-taking variant.
- **Output**: `CalendarPageRender { bytes, width, height, page, pages, warnings, view }`
  (`src/Value/Calendar/CalendarPageRender.php`). PNG or WebP; long edge 200–3000 px, default 1600
  (≈ 5.16 px/mm, ≈ 130 dpi for 310 mm) (`:42-44`, `:90`). `view` = the mm box the picture shows, so
  px ↔ mm is `width / view.w`. The MCP tool fixes WebP at 1600 (`RenderCalendarPageTool.php:42`).
- **`sampleTexts: true`** = `placeholders: 'all'`: sample caption, QR and LOGO placeholders — what
  step 3 shows. Without a photo the photo box is a grey placeholder labelled `FOTKU DOPLNÍ KLIENT`
  (`week.js:68-70`) — a comparison against a scanned page that has a photo must account for that.
- **Dependencies**: (1) **node** — one `CalendarModelBridge` run for the page list, view box and
  warnings (`:67`); (2) **Gotenberg 8** (headless Chromium, `compose.yaml:102-103`) — a screenshot of
  `templates/calendar/headless_render.html.twig`, which loads `assets/calendar/headless/render.js`
  through an import map of `data:` URLs (`CalendarModuleInliner`), fonts and pictures inlined as data
  URIs (`CalendarRenderAssets::inlined`, `::fontFaces`). No Ghostscript, no local Chrome.
- **Cost**: node spawn 60–100 ms (`CalendarModelBridge.php:35`). The Gotenberg client is capped at
  `timeout: 20` / `max_duration: 25` and its comment says a render is ~2 s
  (`config/packages/sensiolabs_gotenberg.yaml:23-24`). No measured figure for a single calendar page
  screenshot is documented; PDF chunks of ≤ 6 pages took 0.3–4.4 s (`calendars.md:1137-1140`).
  A busy renderer → `TemplateRenderUnavailable`; a JS error → Gotenberg 409 → `RuntimeException`.
- **Inside the messenger consumer: yes (inferred, with strong evidence).** The PDF export job
  already calls both the node bridge and Gotenberg from the consumer
  (`src/Services/Calendar/CalendarPdfRenderer.php:59-67`), and `render()` guards its
  `set_time_limit` with `PHP_SAPI !== 'cli'` (`:110-113`). Nothing in it needs a request or a session.
- The warnings in the result are computed with the **estimator** measurer in node, not the fonts
  (`calendars.md:726-728`); the picture itself uses real fonts in Chromium.

---

## 5. The prototype's scan screens (faked)

File `docs/calendar/prototype/wboost-prototyp.html`; index in `docs/calendar/prototype-map.md:1037`.

### 5.1 Step 2 — third card „Naskenovat design“ (L5465–L5475)

- Grid `.cal-lays.three` (L2332): three cards — `Fotka vlevo`, `Fotka vpravo`, `Naskenovat design`.
- The scan card: title **`Naskenovat design`**, pill **`Premium`** (`pill pch`), caption
  **`Rozvržení převezmeme z vašeho kalendáře`**. Instead of a live preview it shows a dashed
  placeholder (`.cal-scanart`, L2336) with a sparkle icon, **`Nahrajte fotku nebo PDF svého
  kalendáře`** and **`AI rozpozná fotografii, kalendárium, hlavičku i logo`**. Once a scan produced a
  layout the card shows the live preview of that layout like the other two.
- Clicking the card (L5917) selects it (`c.photo = 'scan'`, `c.L = null`) and opens a panel **below
  the cards** (`.cal-scan`, `aria-live="polite"`); it does not leave the step.
- Panel, idle: a drop zone (`label.zp-drop`, `accept="image/*,.pdf"`) — **`Přetáhněte sem fotku nebo
  PDF kalendáře`** / **`nebo klikněte a vyberte soubor · JPG, PNG, PDF`** — and a tertiary button
  **`Použít ukázkový kalendář`** (runs the scan on a built-in demo SVG, L5875).
- Panel, running (`.cal-scanrun`, L2341): the uploaded picture on the left (240 px column; omitted
  for a PDF — the prototype has no thumbnail for it), and a checklist of four lines, each
  pending → running → ticked, 650 ms apart (L5871), over an animated bar (`.cal-bar`, 1.25 s):
  1. `Hledám fotografii a popisek`
  2. `Rozpoznávám kalendárium — dny, čísla, jmeniny`
  3. `Hlavička a logo`
  4. `Písma a barvy`
- Result (L5872): **no result screen**. The layout is applied, undo history cleared, the calendar
  jumps to **step 3** (Úpravy prvků) with nothing selected, toast **`Design rozpoznán —
  zkontrolujte a dolaďte prvky`**. The list summary then names the layout `naskenovaný design`
  (L5312).
- The fixed fake result (`calScanLayout`, L5071–L5077): a navy header band (`#1F3A5F`) with white
  left-aligned month, navy day numbers, day number on the `start` side, white Kalendárium
  background, an inset photo with 6-unit radius, caption without background, logo top right.
- No error state, no cancel, no "try again", no comparison of original vs result, no confidence.

### 5.2 Step 3 — „Nahrát layout › Z PDF nebo obrázku“ (L5485, L6094)

Third item of the Vrstvy header menu: sparkle icon, **`Z PDF nebo obrázku`**, caption **`AI rozpozná
rozvržení a nastaví ho`**, `accept=".pdf,image/*"`. Choosing a file only closes the menu and toasts
`„{file}“ — AI rozpozná rozvržení a nastaví prvky (v aplikaci na serveru)` — nothing else is faked.
The sibling items' behaviour is the model to follow: the layout is replaced as **one undo step**,
toast `Layout načten ze souboru — Ctrl+Z vrátí původní`, a history line.

### 5.3 Status in the build

Both were left out on purpose: "Leave out … Keep the design ready for them … do not ship dead or fake
controls" (`docs/calendar/handoff.md:36-46`); listed as a known gap (`calendars.md:1378-1379`,
`docs/calendar/report.md:251`).

---

## 6. Step 2 as built

- **No Twig for the step.** `templates/calendar/workspace.html.twig:26-28` renders only
  `<div class="cal-ws" data-controller="calendar-workspace">` and a JSON bootstrap
  (`CalendarDocument::forCalendar`). All inner DOM is built by JS.
- **Stimulus**: `assets/controllers/calendar_workspace_controller.js` (lazy) mounts the step module
  (`:162` `mountStep2(body, ctx)`); a calendar without a layout cannot be shown past step 2 (`:139`).
  The top bar disables steps ≥ 3 and „Další“ while there is no layout
  (`assets/calendar/ui/topbar.js:95-99`).
- **The cards**: `assets/calendar/ui/step2.js`. `CARDS` (`:15-18`) = `Fotka vlevo` / `Fotografie
  vlevo, dny v týdnu vpravo`, `Fotka vpravo` / `Dny v týdnu vlevo, fotografie vpravo`; 14 days shows
  one static card `Dvoustranné rozvržení` with „Pokračovat“ (`:19`, `:62-68`). Each card is
  `<button class="cal-layc" role="radio" data-preset="…">` with a lazily rendered live thumbnail
  (`.cal-layp`) and a body (`.cal-type-b`) (`:58-73`). Container `.cal-s2 > .cal-s2c > .cal-lays`
  (`role="radiogroup"`, `aria-label="Rozvržení stránky"`) (`:75-78`).
- **How a choice is submitted**: it is not submitted. The click calls `store.choosePreset(preset)`
  (`:70`) — builds the default layout (or mirrors the existing one), sets `step: 3`, marks `preset`,
  `layout`, `step` dirty (`assets/calendar/state/store.js:387-418`). Saving is **explicit**
  („Uložit“); the payload carries the whole `layout`.
- **CSS**: `assets/styles/nocturne/calendar.css:124-129`, `149-156`. The grid is already
  `repeat(auto-fit, minmax(min(280px, 100%), 480px))` (`:150`), so a third card fits without a new
  modifier (the prototype's `.three` is not needed) **(inferred)**.
- **Where a third card plugs in**: a third entry beside `CARDS` in `step2.js` that is not a preset —
  its click must open a panel under `.cal-lays` inside `.cal-s2c` (the container is already a column
  flex with a 20 px gap, `calendar.css:149`) instead of calling `choosePreset`. The result of a
  finished scan can enter the document through `store.replaceLayout(layout, assets)`
  (`store.js:489-507`) — exactly what „Nahrát layout“ does (`assets/calendar/ui/editor/layers.js:236-250`),
  one undo step, the user saves. `sync()` (`step2.js:82-89`) marks the active card by `design.preset`,
  which knows only three values — a "scanned" state has no representation in the data today.
- **14-day calendars** have no choice in step 2; the layout is applied on entering the step
  (`step2.js:96`). Where a scan trigger goes for them is undecided.
- **Step 3 plug-in point**: the menu in `layers.js:219-230` (two items + „Uložit layout do
  souboru“); the server side of the existing imports is `ImportCalendarLayoutController` →
  `ImportCalendarLayout::plan` (`src/Services/Calendar/ImportCalendarLayout.php:35-60`), URL
  `env.urls.layoutImport`.
- Uploads from the workspace go to `env.urls.asset` (`calendar_asset_upload`) with `_token`, `kind`,
  `file` (`CalendarDocument.php:76-80`). There is **no asset kind for "a scan source"**; the six kinds
  all mean something that is drawn.

---

## 7. PDF export — final state, and what is reusable for PDF input

### 7.1 Final state (commits `6402e7e7` … `371410e6`)

Three variants (`CalendarExportVariant`): `print` (RGB, bleed + marks, original photos, no binding
holes), `approval` (trimmed, preview JPEGs), `print_cmyk` (PDF/X-4, FOGRA39, own colour pass in
`Services/Print/Cmyk/`, verified by `PdfRgbVerifier`) — `calendars.md:753-764`, `888-1069`.
Pipeline: plan (node) → chunks of ≤ 6 pages / 16 MB → Chromium **prints** each chunk (vector) →
`PdfChunkMerger` (constant memory, exact page boxes, dedup) → storage → prune to 6
(`calendars.md:789-832`).

The async job is the pattern to copy for a scan job: `CalendarExports::request()` dispatches
`RequestCalendarExport` on the command bus (row lock → one job) then `RunCalendarExport` on the
**`job_bus`** → transport `async` (`config/packages/messenger.php:22-31`, `:58`); a `calendar_export`
row holds `status`, `progress_done / total`, `error` (a Czech sentence), heartbeat; the browser polls
every 1.5 s; a row silent for 10 min reads as failed; the job never throws; dev and CI run **no
consumer**, tests route it `sync` (`calendars.md:834-868`). UI: one dialog `ui/export.js`.

Measured: production consumer runs with `--memory-limit 256M` in a 768 MB container; **one consumer,
one job at a time** — e-mails and domain events queue behind a running export
(`calendars.md:1111-1113`, `1159-1161`).

### 7.2 Reusable for PDF *input*

- **`RasterizePdfPage::firstPage(string $pdf): string` (PNG bytes)** —
  `src/Services/Calendar/RasterizePdfPage.php:41-97`. Imagick + Ghostscript; measures the page at
  4 dpi first, renders at ≤ 200 dpi so the long edge ≤ 4000 px, flattens onto white, strips metadata.
  `isPdf()` sniffs `%PDF` in the first 1024 bytes (`:31-34`). Throws `InvalidCalendarChange`
  („PDF se nepodařilo přečíst. Nahrajte stránku jako obrázek.“). **First page only** (`[0]`) — a
  scan that wants page 2 (the first week page behind a cover) needs a page parameter.
  Ghostscript behind Imagick is "verified in the dev image only" (`calendars.md:1359-1360`).
- **`ImageDecodeLimits`** (60 MP by the header, before any decode) and **`NormalizeImageFormat`**
  (HEIC → JPEG, EXIF rotation baked in) — the same guards a phone photo of a calendar needs
  (`CalendarAssetUploader.php:140`, `166-174`). Rule: never hand uploaded bytes to Imagick unchecked
  (`calendars.md:1353-1356`).
- **`DownscaleImage::toLongEdge()`** — to bring a scan to a model-friendly size.
- Ghostscript is also used read-side in tests (`gs -sDEVICE=tiffsep`, ink read-back) — test-only.
- **Not reusable**: `Services/Print/Pdf*` (`PdfReader`, `PdfChunkMerger`, `PdfPageBoxes`) read
  *Chromium's own* classic-xref output and refuse anything else (`PdfStructureNotSupported`,
  `calendars.md:821-823`) — they are not a general PDF parser. Nothing in the repo extracts vector
  text, fonts or colours from an arbitrary PDF **(inferred: none found)**; a PDF input is a raster
  today. TrimBox / BleedBox of an input PDF are not read by `RasterizePdfPage`.

---

## 8. Open questions and traps for a scanner

**Model and data**

1. **`cal.numberSide: "middle"` is refused over the patch path but valid in the web editor.** The
   editor offers Vlevo / Na střed / Vpravo (`assets/calendar/ui/editor/props-kal.js:61-65`),
   `normalize.js:65` accepts `middle`, the row layout implements it (`week-layout.js:98`, `116`), but
   `ENUMS.numberSide` is `['start','end']` (`model.mjs:52`). `replace_layout` would let it through.
2. **No LLM client in the project.** `composer.json` has `mcp/sdk` and `symfony/mcp-bundle` only; no
   Anthropic / OpenAI SDK, no API-key env var seen. Vendor, key handling, cost accounting and the
   "Premium" gate are all undecided.
3. **Fonts cannot be matched, only chosen.** `family` must be a face already in the project; with no
   project font the page is drawn in a fallback that differs between browser (Outfit) and headless
   Chromium (sans-serif). Weight comes from the face. Should a scan pick the nearest project face,
   or report "upload font X"?
4. **Colours**: any `#RRGGBB` is accepted; the project palette is not enforced. But the CMYK export
   maps only palette colours to brand CMYK (`calendars.md:905`) — scanned colours go through ICC.
   Sampling colours from a *photo of a print* (lighting, paper white) is unreliable; snap to the
   palette?
5. **Sizes in pt need the physical scale.** A photo of a calendar has no scale; the only anchor is
   the assumption that the page is 310 mm wide. Perspective, cropping and a non-310 × 145 source
   break it. A PDF has real units but may include bleed and crop marks.
6. **Month / week boxes auto-grow** and, on the server, with estimated widths; a tightly fitted
   scanned box may be widened, and widened again differently in the browser.
7. **`replace_layout` forgives everything** (unknown keys dropped, bad values defaulted) while
   `layout` refuses unknown keys but clamps numbers — neither tells the scanner "this value was
   meaningless" for every case. `notes` are the only feedback.
8. **Features belong to the look.** Whether the source shows week numbers, moon phases, captions, a
   QR is `features.*` (`configure`), not layout; a scan must set both, and `wk` / `qr` stay invisible
   without their feature.
9. **Week vs fortnight** is a calendar *type*; changing it resets all positions
   (`store.js:356-371`). The scan must decide the type before any geometry.
10. **The day rows of the source are that week's days.** Day numbers, names and holidays in the
    picture are sample data; reading them is only useful to locate parts and — maybe — the year.
11. **Sample page**: the rendered week holds whatever dates the calendar year gives (the first week
    page holds 1 January, a holiday → red number, long holiday name). Comparing against a scanned
    ordinary week needs a deliberately chosen page key.
12. **Header text**: a printed header often carries the municipality name or a slogan — not
    expressible (§2.6); it can only survive as a header *image* cut from the scan, which is then low
    resolution and wrong for print.
13. **Cutting assets out of the scan** (logo, header art, background) is possible technically
    (`image` / `logo` kinds) but yields print-unfit rasters, and assets can never be deleted — every
    retry leaves files behind (no sweeper, `calendars.md:1397-1398`).

**Process**

14. **The renderer reads stored calendars only** (§4). Iterating means saving each attempt
    (revision, history) or adding a document-based render.
15. **The actor in a consumer** (§3.5): `CalendarAccess::forUser()` would make the scanner a client.
16. **`CalendarWriter` throws `ToolCallException`** — reuse the bridge + `SaveCalendar` directly, or
    split the writer.
17. **One consumer.** A scan job on `async` blocks e-mails and PDF exports for its whole duration,
    and an LLM call is slow and can hang; the consumer's 256 MB soft limit and the 10-minute
    "stalled" convention apply. Dev and CI run no consumer.
18. **Long jobs go on the `job_bus`**, never the command bus (CLAUDE.md); progress the browser polls
    must be flushed outside a transaction.
19. **Where does the scan source live?** There is no asset kind for it and no table; storing it as a
    calendar asset makes it permanent and counted. A new path-storing column must be added to
    `BuildStorageReferenceIndex` (CLAUDE.md, `storage-inventory.md`).
20. **Upload limits**: 10 MB decimal, 60 MP; a phone photo passes, a print-shop PDF of a whole
    calendar often does not. A multi-page PDF needs a page choice.
21. **Step 2 has no "scanned" state.** `preset` ∈ three values (server-validated,
    `CalendarDesignRules.php:21`, `:97`); the prototype's `c.photo === 'scan'` and the list label
    `naskenovaný design` have no counterpart. Clicking Fotka vlevo / vpravo after a scan would
    mirror the scanned layout (`store.js:397-403`).
22. **Scan before or after a layout exists?** In step 2 the calendar may already carry a customised
    layout; the prototype discards it silently (`c.L = null`, L5917). The built app's rule for
    layout replacement is "one undo step + toast + history".
23. **Design is last-write-wins** (`calendars.md:393-395`): a background scan job saving the layout
    while the designer's tab holds unsaved changes will be overwritten by the tab's next save, or
    overwrite it. Handing the result to the browser (`store.replaceLayout`) avoids that.
24. **MCP mirror.** Project rule: everything must be MCP-controllable (memory note on multi-page
    documents); a scan started from the web probably wants an MCP action too, and a new tool or
    scope touches the list in `calendars.md:1244-1249`.
25. **Copy and docs**: Czech voice, no native dialogs, DOM hooks are an e2e contract
    (`calendars.md:615-625`); `calendars.md` "Known gaps" must be updated in the same commit.
26. **Module inliner fingerprint**: any change to `model/ generate/ render/ editor/ headless/`
    invalidates stored PDF exports (`calendars.md:841-846`) — relevant if the scan needs new
    renderer options.
