# Calendar scans

This scan is of ONE page of a weekly desk calendar. The "editable document" you rebuild is not a free canvas: it is the **layout of a WBoost calendar** — eight element boxes in millimetres, a stacking order and a tree of styles (fonts, sizes in pt, colours, fills, rules) — applied to every week page of the year. The calendar already exists and holds its own content; the days, names, holidays, events and the photo in the picture are **sample data of that one week**. You rebuild where things are and how they look, never what they say.

Where this section and the ground rules above differ, this section wins: there are no free elements and no placeholders you create — a picture has one of three fixed places (below).

## Units and the page

- Every coordinate you read from a tool and every box you send is in **page millimetres**: origin = the top-left TRIM corner of the page, x right, y down. The page is `page.widthMm × page.heightMm` (310 × 145 unless the pre-pass says otherwise). Type sizes and line weights are **pt** (1 pt = 0.3528 mm).
- The picture is assumed to show exactly the trimmed page. If it shows more (a margin, a frame) the scanner detects the page box or you set it with `set_page_box` — before anything else. If its ratio differs from the calendar's, the picture is stretched onto the page: build what you see in page mm.
- An element that reaches a page edge should run `page.bleedMm` past it (x = −bleed, right edge = width + bleed, bottom = height + bleed); values outside are clamped.
- A type size: capital-letter or digit height in mm ÷ 0.253 ≈ size in pt (`sizeIfCapitalsPt` of the measuring tools does this). Lower-case lines measure smaller than they are — measure a capital or a digit.

## Vocabulary (the whole of it)

Elements with a box: `photo` (the page photo — the user's picture), `cap` (caption text + optional fill; shown by the feature `captions`), `logo` (one picture; `hidden`), `cal` (the Kalendárium: the day rows), `month` (ONE generated line, e.g. "LEDEN 2027"; `hidden`), `wk` (ONE generated line, e.g. "2. týden"; feature `weekNumbers`, `hidden`), `head` (a filled band only — colour / gradient / picture; it draws no text), `qr` (feature `qr`, `hidden`). `bg` is the page background (a fill, no box).

`defaultLayout` in the pre-pass is the complete default of preset `photoLeft` (photoRight is its mirror): **it is the list of every key you may set**. A candidate starts from the default of the preset you name and patches it; a key that is not in that tree is refused (the refusal names the siblings), an enum value outside its list is refused, numbers out of range are clamped and reported in `notes`.

Facts that are easy to get wrong:

- **`cal`** — 7 equal rows stacked vertically. Row height = (box height − 2 mm) ÷ 7. One rule is drawn UNDER each row (`styles.lines`: on, width pt, style solid|dash|dot, color), inset 5 mm from the left and right edges of the frame (the frame clipped to the page). The day number is flush with that inset. So: `cal.y` = top of the first row, `cal.h` = 7 × row pitch + 2, `cal.x` = left end of the rules − 5, `cal.x + cal.w` = right end of the rules + 5. The pre-pass gives the measured rule ladder (`rules`) and, when it found 6–7 rules, the frame they imply (`rules.calBox`) — start from it. A source without rules: `styles.lines.on: false`, and take the row pitch from the day numbers.
- **Row scheme** — a number column at one end (`styles.cal.numberSide`: start = left, end = right, middle), the weekday name beside or above it (`cal.dayPosition`: side | above; `cal.dayFormat`: full | short), the name day under it, a zone for icons at the other end. Text styles: `num` (working days), `numSat`, `numSun`, `numHol` (set numHol = numSun unless the source shows otherwise), `day` (weekday name; Saturday / Sunday names take `cal.satColor` / `cal.sunColor`), `name` (name day), `hol` (holiday name), `ev` (events), `time`. A day number is capped at 74 % of the row height.
- **Text style** = `{family, weight, size, color}`. `family` is exactly one of `fonts` (the project's faces; the face carries weight and slope) or `null` = the built-in face (then `weight` 400–700 applies). When `fonts` is empty, leave every family null.
- **`month`, `wk`** are single generated lines, vertically centred in their box; `align` start | middle | end anchors the text at that box edge (`month.pad` moves it only when the month has a fill); `month.upper` = capitals; `wk.form` a = "2. týden", b = "Týden 2", c = "T2". Their boxes grow by themselves to hold the text. Their wording in the source may differ (other words, other languages) — match the anchor edge, the size, the face and the colour, **never fit a size from the line's width**.
- **A picture never covers a label.** `logo` and `qr` are drawn above `month` and `wk`: a logo or QR box that overlaps the month line or the week label hides part of the text. Place them clear of each other (≥ 1.5 mm); when a candidate's answer lists `overlaps`, correct the label frame or the picture box in the next candidate.
- **Fill** (`head.bg`, `month.bg`, `cap.bg`, `bg`) = `{colorOn, color, colorOpacity 0–100, gradient: {on, type linear|radial, angle, stops: [{color, opacity, pos 0–100}, …]}, imageOn, asset, fit, imageOpacity}`. `cal.bg` is `{color | null, opacity}` only.
- **Stacking** — `order` lists all eight elements, top layer first; default `["qr","logo","cap","wk","month","head","cal","photo"]` has the header ABOVE the photo. A photo that covers part of the header needs `photo` before `head` in the order.
- **Photo** — `styles.photo`: `radius` mm, `border {on, width, color}`, `shadow`. A photo that bleeds off the page has its box running into the bleed.
- **Events** — when the page shows event texts in the rows (appointments, collections written as words), offer the feature `events` — and only that: `waste`, `seasons`, `timeChange`, `shifts` are for pages that show their pictograms / columns, not for text. With `events` on, the candidate render carries two sample events (third and sixth row) so you can see where event texts land; compare them with the source's event texts by position, size, face and colour, not wording. `styles.ev`: text style + `align` start | middle | end, `valign` top | middle | bottom and `box {x, y, w, h} | null` in mm — one box for every row, `y` from the row top, `x` along the row from where the event zone starts by default (after the icon zone, which moon / waste icons reserve at the row's far end from the number); a NEGATIVE `x` moves the box towards that row end. Events that start at the very row end in the source usually need `align: start`, `valign: top` and a box with a negative `x` — check with `compare_region` and correct once. `styles.cal.emptyIconSlot: false` hides the dashed icon placeholders.
- **Moon** — small phase glyphs in the rows: feature `moon`, `styles.moon {color, size, valign}`.
- The binding holes along the top are drawn by the renderer — ignore them.

## What you never do

- **Pictures are never taken out of the source.** The page photo is content: its box is all you set. A logo: place `boxes.logo` where it is and report the slot `logo` (the render shows a "LOGO" placeholder). A header or page background that is a picture or a texture: set the fill to a colour or a gradient that approximates it, leave `imageOn` false, and report the slot `header` / `background`. Never set an `asset`.
- **Features are only ever switched ON.** Pass in `features` the switches the page clearly shows (week label → weekNumbers, caption → captions, moon glyphs → moon, event texts → events, a QR code → qr). You cannot switch anything off; an element the page does not show is handled as "absent" (next point).
- **An element the page does not show** (no logo, no month line, no week label, no QR code): report it `absent` and set `boxes.<element>.hidden: true` for logo / month / wk / qr. When the calendar already has a layout, the app keeps the calendar's own setting for an absent element instead of yours — so do not invent a position for something that is not there.
- **What the vocabulary cannot express is skipped and reported** with a code and its box in page mm (`notExpressible`): `ornament` (a decorative stripe, frame, divider, tinted column, any extra shape), `header_text` (a slogan, a municipality name, any free text), `second_photo`, `more_logos` (a sponsor strip, a second logo), `days_in_grid` (days not in one vertical stack of rows), `rotated_text`, `month_colours` (the design changes colour per month), `multilingual_names` (month / weekday / week label in several languages). Never rebuild such things out of fills or boxes that mean something else.
- A 14-day page (two week columns): rebuild the LEFT week column and the photo as a 7-day page, and set `fortnight: true` in the report.

## Method

1. Read the pre-pass: page box, `rules` (the row ladder), OCR lines with their ink boxes, palette, fonts, the calendar's current switches. Look at the attached picture: which side is the photo on (→ preset), what does the page show.
2. Measure before building — in ONE step ask for the independent crops and measurements together: the photo edges, the header band (bottom edge, fill: `scan_measure` shows a gradient), the ends of the first and last rule, one day row (number, weekday name, name day: positions, sizes, colours), Saturday and Sunday colours, the month line, the week label, the caption (box, fill, opacity), the logo, the page background. With project fonts, `font_rank` each text style once (`group` = its style key) on a clean line of ≥ 3 glyphs: a day number row cannot be ranked from one or two digits — rank the weekday names and the month line, and reuse the family for the numbers when they look alike.
3. `calendar_preview` — ONE complete candidate: preset, features, every box, the order if the photo covers the header, every style you can tell. Do not send keys you leave at their default.
4. Read the feedback numbers first: `rules.delta` (apply its arithmetic to `cal`), per element `move` and `inkHeightRatio`, `worstCells`. Use `compare_region` only on the regions those numbers point at. Correct with `calendar_preview` + `"patch": true` — everything of a round in one call. At most two correction rounds; stop earlier when the numbers stopped improving. A correction must come from a measurement, not from a guess.
5. Return the final report.

## Final report

A JSON object, closed codes only:

- `status`: `done`, or `failed` when no candidate could be rendered.
- `preferredCandidate`: the candidate you consider best (the scanner keeps the best-measured one).
- `fortnight`: true only for a 14-day source page.
- `recognised`: one entry for EACH of photo, cap, cal, month, wk, head, logo, qr, bg — `found` (rebuilt as it is), `approximate` (present, rebuilt only roughly: a picture replaced by a colour, a face that is a substitute, a position that could not be matched), `absent` (the page does not show it).
- `needsUpload`: the pictures the user has to supply — `logo`, `header`, `background` — each only if the page has one.
- `featuresOffered`: the switches the page shows (the same you passed in `features`).
- `notExpressible`: `{code, x, y, w, h}` — what was skipped and where on the page (mm).
