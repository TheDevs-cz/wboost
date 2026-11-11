# Kalendáře — final report (handoff §9.7)

Phase P8a, 2026-11-11. Run against the dev stack in headless Chrome. A check is PASS only when a
named automated test drove it in the real browser (pure rules: node suite + a browser check).

Totals of the final run: node 17 suites / 227 tests, 0 failed · e2e 22 scripts / 297 checks, 0 failed
· phpstan no errors · PHPUnit 2668 tests, 21 857 assertions, OK.

After the change of 2026-11-11 (month and week number once per column on 14-day pages, assumption 9):
node 17 suites / 236 tests, 0 failed · e2e 23 scripts / 312 checks, 0 failed (new script
`editor-frames`, 15 checks; `client` and `editor-layout` passed on a second run — the dev container
restarted during the first) · phpstan no errors · PHPUnit 2693 tests, 22 887 assertions, OK.

## 1. Check by check

`script › title` = tests/e2e/calendar/<script>.e2e.cjs; `node <suite>` = tests/Fixtures/calendar/suites/<suite>.mjs.

### 1.1 Generation (2027)
| # | Expected | Result | Proved by |
| --- | --- | --- | --- |
| G1 | 7 dní: 55 pages | PASS | generation › G1 · node generation |
| G2 | 14 dní: 29 pages | PASS | generation › G2 + G6 · completion › 5 · node generation |
| G3 | first page 28 Dec – 3 Jan, PROSINEC 2026 / LEDEN 2027, week 53 | PASS | generation › G3 (drawn SVG) |
| G4 | second page 4–10 Jan, LEDEN 2027, week 1 | PASS | generation › G4 |
| G5 | last page 27 Dec – 2 Jan 2028, week 52 | PASS | generation › G5 |
| G6 | 14 dní first page, first week left, second right, weeks 53 and 1 — since 2026-11-11 printed per column: `PROSINEC 2026 / LEDEN 2027` + `53. týden` over / under the first week, `LEDEN 2027` + `1. týden` over / under the second | PASS | generation › G2 + G6 · editor-frames (14-day month / week number per column) · node generation, render |
| G7 | 13 public holidays styled and named, no other day | PASS | generation › G7 (all 371 rows + drawn 17 Nov) · node generation |
| G8 | Easter 2026 and 2028 | PASS | generation › G8 |
| G9 | Karina / Vasil on 2 Jan | PASS | content-tools › G9 · client-tools › Jména navíc |
| G10 | number style priority | PASS | generation › G10 |
| G11 | time change 28 Mar, 31 Oct | PASS | generation › G11 · kalendarium › Fáze měsíce, Změna času … |
| G12 | week number forms | PASS | panels › G12 |
| G13 | special pages add / move / renumber | PASS | content-pages › G13, Roční kalendárium, uploaded special pages · completion › 2 real .docx · node special |
| G14 | year change regenerates, renames, moves content | PASS | shell › year change renames … (G14), year change with page content … · node generation, payload |

### 1.2 Editor
| # | Result | Proved by |
| --- | --- | --- |
| E1, E2 | PASS | editor-select › E1, E2 |
| E3–E9 | PASS | editor-move › E3 … E9 · panels › E9 · node snap |
| E10, E11, E17, E19 | PASS | editor-layers › E10, E11, E17, E19 |
| E12 | PASS | editor-select › E12 (selection) · kalendarium › E12 |
| E13 | PASS | kalendarium › E13 · fonts › Kalendárium › day numbers and weekday names |
| E14 | PASS | editor-parts › E14 |
| E15, E16 | PASS | kalendarium › E15, E16 · node week-layout |
| E18 | PASS | panels › E18 · node autogrow |
| E20 | PASS | editor-move › E20 |
| E21 | PASS | content-pages › E21 · editor-layout › step 4 „Styl stránek“ … · kalendarium › step 4 › Styl stránek |

### 1.3 Propojit prvky (every row asserts all boxes before and after)
| # | Result | Proved by |
| --- | --- | --- |
| §7.4 rows 1–10 | PASS | link-table › row 1 … row 10 (12 checks) · node link |
| §7.4 rows 11, 12, 13 | PASS | link-table › row 11, row 12, row 13 |
| L1–L7 | PASS | link-rules › L1 … L7 |
| L8 | PASS | link-rules › L8 Fotka vpravo, L8 14 dní · scope-guards › dimensions: linking · editor-frames › Propojit prvky (the second frames of Měsíc / Číslo týdne) · node link L8b |
| L9 | PASS | link-rules › L9 (two checks) · panels › L9 |

### 1.4 Content, preview, warnings
| # | Result | Proved by |
| --- | --- | --- |
| C1 | PASS | content-pages › C1 · completion › 3 drag-and-drop on the bulk zone (real CDP drop) |
| C2 | PASS | preview › C2 (three checks) · completion › 6 |
| C3 | PASS | warnings › C3 (five checks) · content-pages › warnings · completion › 4 print-size photo (no dpi warning) |
| C4, C5, C6 | PASS | content-tools › C4, C5, C6 · scope-guards |
| C7 | PASS | content-tools › C7 (two checks) · completion › 7 C7 through the UI (Ikony › Přidat ikonu, nothing seeded) |
| C8 | PASS | content-tools › C8 · warnings › more icons than cells … |
| C9 | PASS | preview › C9 (four checks) · integration › C9 phone, C9 desktop |
| C10, C11 | PASS | preview › C10 designer, C10 client, C11 · client › C10 |
| C12 | PASS | content-pages › C12 |

### 1.5 Roles and persistence
| # | Result | Proved by |
| --- | --- | --- |
| R1 | PASS | client › R1 (two checks) · shell › client link |
| R2 | PASS | client › R2 (three checks) · PHPUnit CalendarClientTest |
| R3 | PASS | client › R3 (two checks) · client-tools (photo, caption, waste, event, name, comment through the UI, each in Historie as Klient) |
| R4 | PASS | persistence › R4 (a change in each step, one save, reload) · shell › save → reload … (R4) |
| R5 | PASS | shell › Dokončit …, a finished calendar opens in step 5 |
| R6 | PASS | editor-layout › R6 |

### 1.6 Scope guards
| Guard | Result | Proved by |
| --- | --- | --- |
| No AI / import control | PASS | scope-guards › walk through every screen and dialog (7 and 14 dní, all features on, 80+ stops incl. client view) + no AI / import control (text and title / aria-label / placeholder / alt scanned for Naskenovat, Použít ukázkový, Z PDF nebo obrázku, Nahrát z Excelu / Excel, Simulovat, Premium) |
| 14 dní never offers Fotka vlevo / vpravo | PASS | scope-guards › a 14-day calendar never shows … · shell › 14 days: step 2 … |
| No hard-coded page size | PASS | node dimensions (grep + 420 × 200 page) · scope-guards › §9.6 dimensions (four checks in the real editor; page stored through the save endpoint, no psql) |

### 1.7 Caveats
- Nunito digits are tabular: the cut change is proven on the weekday name and the month text, the family change on the number.
- „Výchozí písmo“ is not selectable in a project that has fonts; asserted as such.
- content-tools and completion 7 set native date fields by value + events; client-tools types them.
- fonts.e2e leaves Nunito and Inter in the e2e project; later scripts then run with project fonts (they ran font-less in the baseline before the P8a changes).
- e2e is not in CI. The dev container restarts under load; a script that hits a restart fails with ERR_CONNECTION_RESET and passes when rerun.

## 2. Prototype comparison (tests/e2e/calendar/compare-prototype.cjs)
| | Fotka vlevo | Fotka vpravo |
| --- | --- | --- |
| Default boxes as page fractions (tolerance 1 %) | all within 0.42 % | all within 0.54 % |
| Layer order | equal | equal |
| Mean pixel difference, trim area | 1.66 % | 1.65 % |

Pictures: report-assets/sample-photoLeft.png, sample-photoRight.png; numbers: comparison.json.
Deliberate differences: typeface (prototype demo font Montserrat 800, app fallback Outfit 700 in a
project without fonts); icon cells and event box drawn only while their part is selected;
FOTKU DOPLNÍ KLIENT label; astronomical moon phase (30 Dec, not 31); the prototype's bleed ring is
2.4 % of the width against 1.6 % (its units assume a 210 mm page). No visual regression found;
nothing changed in the default page.

## 3. Defects found and fixed in P8a
1. Default event box was 7–10 mm on a 14-day page: now page-wide beside the widest weekday / name
   line; a long-holiday row gets its own narrower box (week-layout.js `evDefault`, parts.js).
2. `nearestFace` could pick an italic (server sends the subfamily name as `style`).
3. Success toast swallowed clicks on the day popover: calendar toasts are click-through.
4. Stub leftovers removed (ui/stub.js, CSS, e2e skip branches, id pattern in architecture §B.6).
5. link-table row 6 depended on the project font.
Not done: swapping TestDataFixture::CALENDAR_LAYOUT_JSON for layout-photo-left.json (PHP tests were
owned by the parallel hardening work).

## 4. Assumptions
1. Saving is explicit for clients too; only the step number is stored silently.
2. Content merges item by item under a row lock; design is last-write-wins per top-level key.
3. 10 MB per upload; since hardening: 60 MP cap, EXIF orientation baked in, GIF and text-sniffing files refused for photos.
4. Client uploads are photos only, rate limited (500 uploads / h per link, 600 saves and history reads / h, 2 GB per calendar, history pruned to 2000).
5. Organiser select per event row.
6. Confirmations added: delete calendar, type switch, year change with page content, remove waste kind / shift operation, Vymazat vše, regenerate link.
7. Moon phases and season starts are astronomical on the Czech civil date (autumn 2027 = 23 September).
8. Default event box beside the day text.
9. ~~One Měsíc and one Číslo týdne on a 14-day page, although the client's real calendars print them per column.~~ Changed on launch day (2026-11-11, product owner's decision): a 14-day page follows the samples — month and week number once per column, each element with two frames (`month` / `month2`, `wk` / `wk2`) that share one style; calendars stored before the change are migrated when opened (`docs/architecture/calendars.md` → "Layout").
10. A page's own QR code wins in both modes.
11. Phone: day rows are the tap targets in step 5.
12. MCP: seven tools — list_calendars, get_calendar, render_calendar_page (calendars:read);
    manage_calendar, set_calendar_layout, upload_calendar_asset, update_calendar_content
    (calendars:write). A calendar can be created, configured, laid out, styled, filled, finished
    and LOOKED AT without a browser; deleting stays on the web. Defaults and generation stay in
    JS: the server runs the calendar's own store in node (resources/calendar/model.mjs) for the
    save payload and its own renderer in Gotenberg's Chromium for the picture
    (docs/architecture/calendars.md § "Server-side model and headless render"). Extended
    2026-11-11 from the three content-only tools the module launched with; calendars:write
    therefore now covers the design too (designer role only).
13. Kalendáře is an admin-only preview module until launch.
14. The public /ai page and the consent screen already describe the calendar tools.
Plus architecture §G.1 items 1–33 unchanged, except: seasons (item 17) are now computed; PDF special
pages are rendered with the long edge ≤ 4000 px.
Hardening commits of the same day (6c200d71, 36eb6c8c, 1697a0c5, 9fabb91d, 2acaf928, f08327f1) also:
strip control characters and invalid UTF-8 from content texts and refuse them in layout strings;
refuse content ids that are Object.prototype properties; count calendar layouts as font usage; a
font rename counts as a calendar save; the list page no longer embeds full documents; photos are
allow-listed by sniffed MIME type.

## 5. Recommended behaviours (§8) and defaults (§10)
| Item | Outcome |
| --- | --- |
| §8 layout loading: same type only | as recommended |
| §8 type switch: confirm, keep styles, reset positions | as recommended |
| §8 29 February: drop and say so | as recommended |
| §8 year / type change with overrides: warn, keep by week number | as recommended |
| §8 bleed change: nothing outside | as recommended + §10.7 |
| §8 waste end before start: reject | as recommended |
| §8 large images | print-size upload tested (3700 px, 7.5 MB); limit 10 MB / 60 MP |
| §10.1 14-day geometry | default; placement per architecture §B.4 |
| §10.2 type switch | default |
| §10.3 Excel import | not built, no control |
| §10.4 unsaved changes | default (dialog + beforeunload) |
| §10.5 Uložit layout do souboru | added |
| §10.6 delete confirmation | added |
| §10.7 bleed edge adaptation | default |
| §10.8 simultaneous editing | default for content; design is last-write-wins |
| §10.9 print output | not built; preview dimensionally exact |
| §10.10 uploaded special pages | default |
| §10.11 client link | default: unguessable, regenerable, no expiry |
| §10.12 binding band | prototype proportion, a calendar property |
| §10.13 cover / back / special pages | as far as the prototype goes |
| §10.14 removing step-5 additions | default; comments permanent |
| §10.15 QR source | uploads only |
| §10.16 photo / logo source | disk only |

## 6. Known gaps / second iteration
- AI scan, PDF / image layout import, Excel import (out of scope).
- Print PDF export.
- Long holiday + event on the same day of a 14-day page: ~10 mm box, warning only; a stacking rule needs a design decision.
- Cover / back / special page content editing; gallery as photo source; page size UI; asset sweeper; client autosave; design conflict detection; launch (preview gate).
- e2e not in CI; dev container restarts under load.
- TestDataFixture layout swap.
- Unverified: print-shop band / hole values, PDF special pages on the production image.

## 7. How to run
node tests/Fixtures/calendar/harness.mjs --all
node tests/e2e/calendar/run.cjs [filter]          # ~18 min for all; not in CI
node tests/e2e/calendar/compare-prototype.cjs     # writes docs/calendar/report-assets/
docker compose exec web composer phpstan
docker compose exec web vendor/bin/phpunit
BASE=http://localhost:8099 node docs/redesign/tools/shoot.cjs <outDir> /project/<id>/calendars /calendar/<id>
