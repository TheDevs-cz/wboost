# This calendar prints two weeks on a page

The calendar this scan is for is a **14-day** one (`calendar.type: "fortnight"`). Everything above holds; where this section and the one above differ, this section wins.

## The page

- There is ONE preset: `twoSided` — the first week column on the left, the photo between, the second week column on the right. `preset` need not be sent; `defaultLayout` is that preset's default.
- Three elements have TWO frames, one per week column: the Kalendárium (`cal` left, `cal2` right), the month line (`month`, `month2`) and the week label (`wk`, `wk2`). Each is still ONE element: one entry in `order`, one style group, one `hidden` flag (on the first frame — it hides both), one entry in `recognised`. So a candidate has eleven boxes: `photo cap logo head qr` + `cal cal2 month month2 wk wk2`.
- **The second frame is drawn mirrored.** `cal2` uses the same styles with left and right swapped: with `styles.cal.numberSide: "start"` the day numbers stand at the LEFT end of the rows of `cal` and at the RIGHT end of the rows of `cal2` — both at the outer page edges, as on most 14-day pages; `"end"` puts both beside the photo. `month2` / `wk2` anchor at the opposite edge of their box: `align` is the FIRST frame's (`start` = flush left in `month`, flush right in `month2`). Set every style from the LEFT column. The right column cannot be styled separately.
- A source whose columns are not mirror images of each other (the numbers on the same side in both) cannot be expressed: build the left column as it is, let the right one mirror, and report `cal` as `approximate`.
- **A second frame you do not send is placed for you** as the mirror image of its first frame across the vertical centre line of the page (x′ = page width − x − w; y, w, h the same). Send `cal2`, `month2` or `wk2` (any of x, y, w, h) only where the source's right column is NOT that mirror image — columns of different widths, a photo that is not centred. Never set `hidden` on a second frame.

## Measuring and correcting

- The pre-pass carries two row ladders: `rules` — the LEFT column, its `calBox` is `boxes.cal`; `rules2` — the RIGHT column, its `calBox` is `boxes.cal2`. When both are found, send both boxes from them. When one is missing, send the column that was found and let the other mirror.
- The photo stands between the columns: its left edge is usually where `cal` ends and its right edge where `cal2` begins — measure both edges.
- Measure one day row (number, weekday name, name day: positions, sizes, colours, faces) in the LEFT column; look at one row of the right column only to confirm that it mirrors.
- The feedback of `calendar_preview` has `rules` (→ `cal`) and `rules2` (→ `cal2`), each with its own `delta` and arithmetic, and `elements` lists `cal`, `cal2`, `month`, `month2`, `wk`, `wk2` separately. Correct a second frame from its own numbers only; a correction of a first frame moves the mirrored second frame with it unless you sent that second frame yourself.
- The sample page shows two consecutive weeks. With `events` on, sample events are in both columns.

## When the picture shows one week only

If the picture shows ONE week column (a 7-day page) although the calendar is a 14-day one: build that column as the first week — `cal` on the left, whichever side it is on in the picture — keep the photo's measured top and bottom and put it between the two columns, let every second frame mirror, and set `fortnight: false`. Report nothing as `notExpressible` for it.

`fortnight` in the final report says what the SOURCE shows: `true` for two week columns (the normal case here), `false` for one. The rule above about rebuilding only the left week column of a 14-day page is for 7-day calendars — here you rebuild both columns.
