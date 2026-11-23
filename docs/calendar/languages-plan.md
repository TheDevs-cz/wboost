# K15b — Vícejazyčný kalendář (plan)

Status: **approved by Jan 2026-11-24 with changes (§7), being built in three phases.** Source: the PO's scan of the Chotěbuz calendar
(`docs/calendar/reference/chotebuz-7d-*.png`), reported as `multilingual_names` „Vícejazyčné názvy“.
PO: „zvážit, jestli tohle nedáme do prvního kroku … zaškrtávátko jazyků“.

## 1. What the reference actually does

Only the **week page** is multilingual, and only three texts:

| Text | Chotěbuz | Today (Czech only) |
|---|---|---|
| Měsíc | `LEDEN / STYCZEŃ / JANUARY 2026`; a week across months is **two lines**: `PROSINEC / GRUDZIEŃ / DECEMBER 2025` ⏎ `/ LEDEN / STYCZEŃ / JANUARY 2026` | `PROSINEC 2025 / LEDEN 2026` (one line) |
| Den v týdnu | `Pondělí / Poniedziałek / Monday` (one line, Czech capitalisation) | `Pondělí` |
| Číslo týdne | `3. týden / tydzień / week` (number once) | `3. týden` |

Czech only on the same calendar: name days, holidays (`Den památky Jana Palacha`), events, the
intro page, contacts, notes. Identical words repeat (`Sobota / Sobota / Saturday`,
`LISTOPAD / LISTOPAD / NOVEMBER`) — the reference keeps the duplicates.

## 2. Where the texts come from

| Text | Code | Multilingual? |
|---|---|---|
| month names | `generate/czech.js` `MONTHS` → `generate/pages.js` `monthText()` → `page.week.monthTexts[f]` | **yes** |
| weekday full / short | `czech.js` `WEEKDAYS`, `WEEKDAYS_SHORT` → `generate/day.js` `dayInfo().weekday/weekdayShort` → only consumer `render/week-layout.js` `dayLabel()` | **yes** |
| week number | `pages.js` `weekText(form, [w])` → `page.week.weekTexts[f]` (frames always get the single-week form; the two-week form is only the Czech page title in the UI) | **yes** (forms a, b; `c` = `T3` is language-neutral) |
| name days, holidays | `czech.js` `NAME_DAYS`, `FIXED_HOLIDAYS`, `holidayName()` | no — Czech facts (extra names stay possible via „Jmeniny“) |
| DST / season row texts | `generate/time.js` `DST_TEXTS`, `SEASON_TEXTS` (printed with `time.position = 'text'`) | no in v1 (open decision D6) |
| moon phase names | `MOON_PHASES` — tooltips / UI only, never printed | no |
| year overview + waste-plan grids | `render/special.js` (`MONTHS[...]`, `WEEKDAY_INITIALS`, default heading `PŘEHLED ROKU`) | no in v1 — the reference special pages are Czech; every heading is already editable text |
| editor/UI copy (datepicker, waste tool, step names) | `ui/**` | no — the app speaks Czech |

## 3. Evidence: does it fit? (mock render, default layouts, default type sizes)

Method: a patched **scratch copy** of `assets/calendar` (CZ + PL + EN tables, repo untouched), the
real `normalizeDocument` → `defaultLayout` → `autoGrow` → `layoutWeekPage` → `renderPage`, estimator
measurer, rasterised in headless Chrome with Outfit. Script + PNGs:
`/private/tmp/claude-501/-Users-janmikes-www-wboost/d2382ada-6ea8-41bd-82a5-80d39c1309e0/scratchpad/k15/`
(`scripts/mock.mjs`, `out/ml-*.png`, `out2/ml-compact.png`; the scratchpad is temporary).

| Case (310 × 145 mm) | Result |
|---|---|
| Měsíc, one month (CZ/PL/EN, 15 pt 800 upper) | widest `LISTOPAD / LISTOPAD / NOVEMBER 2026` = **116 mm**; frame 101 mm (7 d) grows to ~118, 120 mm (14 d) holds it — **fits** |
| Měsíc, week across months, one line | `… DECEMBER 2025 – LEDEN / … 2026` = **222–226 mm**; autogrow takes the frame to 226 mm → on 14 days the two frames **collide over the photo/logo** (`out/ml-fortnight-w01.png`); on 7 days it spans the whole head |
| Měsíc, 4 languages (CZ/PL/EN/DE), one month | 144–150 mm — does not fit a 14-day frame |
| Den v týdnu full, 11 pt 600, side position | widest `Středa / Środa / Wednesday` **63.6 mm**; 7-day: looks fine (`out/ml-week-w01.png`), but with Akce on the text column is 56 mm → 106 of 371 rows over it; 14-day: column 41.8 mm (moon on) → **every** row over (visually it runs into the icon zone, `out/ml-fortnight-w01.png`) |
| Den v týdnu „Nad číslem“ (`dayPosition: above`) | number column grows to the label → text column 5.6 mm, holidays wrap one word per line (`out/ml-week-above-w01.png`) — **broken** |
| Compact rows (297 × 105, 14 d) | names pushed into the photo (`out2/ml-compact.png`) — **broken** |
| Den v týdnu short (`Po / Pn / Mo`) | 25 mm — fits everywhere |
| Číslo týdne `1. týden / tydzień / week`, 9.5 pt | 42 mm in 72–101 mm frames — fits |

Conclusions: (a) a cross-month Měsíc must break into **two lines** like the reference; (b) the full
weekday needs the existing row machinery to know about it (no overflow check exists for the day
label today); (c) at most **two extra languages** — a third one breaks Měsíc and the 14-day rows.

## 4. Proposal

### Data
- New design key **`design.languages: string[]`** — the extra languages, Czech implied and always
  first, e.g. `['pl', 'en']`. Unique, from the table, any number (§7). `normalize` default `[]` → every stored
  calendar unchanged. Designer-only, saved as a design patch.
- Not inside `features`: features are strictly booleans (`CalendarDesignRules::features()` exact
  keys + `is_bool`, PHPStan `array<string,bool>`, step 1 „Označit vše“, `setAllFeatures`, MCP
  `features` = switches, scan `featuresOffered`). Cost: one new JSON column `calendar.languages`
  (additive migration, default `'[]'`, no backup needed), `Calendar::applyDesign`,
  `CalendarDesignRules::KEYS` + `languages()` check, `CalendarDocument` bootstrap,
  `state/payload.js`, `DescribeCalendarChange` (history: „Jazyky: polština, angličtina“).
  The `linkElements` key is the template (same set of files).

### Language table — new `generate/languages.js`
```
LANGUAGES = { cs, sk, pl, de, en }   // print order = table order
  { label: 'Polština', months: [...nominative...], weekdays: [...], weekdaysShort: [...],
    week: 'tydzień', weekCap: 'Tydzień' }
```
- Offer **Polish, Slovak, German, English** (+ Czech always). Why: Czech border regions
  (PL — Těšínsko / Chotěbuz, SK — Moravia, DE — Šluknovsko / Chebsko), English for tourism. Adding
  one = one table entry (+ a glyph check, see Risks). Hungarian/Ukrainian: not now.
- **Forms: nominative everywhere** (headers stand alone): `grudzień` not `grudnia`, `Dezember`,
  `december`, `January`. Czech `MONTHS` is nominative already — consistent.
- Short weekdays: cs `Po Út St Čt Pá So Ne`, sk `Po Ut St Št Pi So Ne`, pl `Pn Wt Śr Cz Pt So Nd`,
  de `Mo Di Mi Do Fr Sa So`, en `Mo Tu We Th Fr Sa Su`.
- Week word: sk `týždeň`, pl `tydzień`, de `Woche`, en `week`.

### Text rules (one place: `generate/`)
- Separator ` / ` between languages (as the reference and as today's two-month text).
- **Měsíc**: one month → `LEDEN / STYCZEŃ / JANUARY 2026`. Across months → two lines:
  `PROSINEC / GRUDZIEŃ / DECEMBER 2025` and `LEDEN / STYCZEŃ / JANUARY 2026`, year on each line
  only when the years differ, else at the end of line 2. `monthTexts[f]` becomes a string with
  `\n` (or `string[]`); `monthLabel()` case logic unchanged per line (upper / „Leden / Styczeń“).
  Without languages: exactly today's one-line text (fingerprint aside, output identical).
- **Den v týdnu**: `Pondělí / Poniedziałek / Monday`, short `Po / Pn / Mo`; capitalisation as the
  table (all start upper case). `dayInfo().weekday/weekdayShort` carry the joined strings — the
  only consumer is `week-layout.js`.
- **Číslo týdne**: form a `3. týden / tydzień / week`, form b `Týden / Tydzień / Week 3`, form c
  `T3` unchanged. The „Tvar“ switch labels show the multilingual sample.
- Duplicates kept (`Sobota / Sobota / Saturday`) like the reference.

### Layout
- `render/week.js renderMonth`: draw 1–2 lines (line height 1.15 × size, block centred in the frame).
  `week-layout.js textWidths` / `autogrow.js maxTextWidths`: width = the wider line. Autogrow
  also grows the Měsíc frame **height** to two lines when any page needs them (away from the
  calendar; clamped like width). Default month frame 14.5 mm vs two lines of 15 pt ≈ 12.2 mm + pad —
  fits without a fill, grows with one.
- Weekday: when `languages` is non-empty
  - **side** position: the day label wraps nothing but counts in `namesWidest` (already does) so the
    default event box moves; add a page **warning** `dayLabel` when a label is wider than the text
    column (new kind in `render/warnings.js` + `warning-text.js`: „Názvy dnů se nevejdou — zvolte
    zkratky, nebo zmenšete písmo“). The warning is useful beyond languages.
  - **above** position and **compact** rows: use the short forms automatically (D3).
- No change to name days / holidays / events placement.

### UI — step 1 (`ui/step1.js`)
New section after „Tisk“: **„Jazyky názvů“** — „Čeština“ (checked, disabled), then checkboxes
Slovenština, Polština, Němčina, Angličtina (no cap). Lead line: „Názvy měsíců, dnů a týdnů se vytisknou ve všech
zaškrtnutých jazycích. Jmeniny a svátky zůstávají české.“ Not part of „Označit vše“.
`store.setLanguages(list)` (design patch, undo-able, autogrow re-run).

### MCP
`model.mjs` `configure { languages: ['pl','en'] }` (refuse unknown codes / > 2 with a teaching
message), `manage_calendar` docs in `ManageCalendarTool`, `get_calendar` returns `languages` →
skill update (`references/calendars.md`), `tools.json` regenerated, plugin bump (`/skill-release`).

### AI scan (phase 3, just what it takes)
- `finish.calendar.schema.json`: new `languages: string[]` (enum of the table codes, ≤ 2);
  `system.calendar.md`: „month / weekday / week labels in several languages → list the extra
  languages in `languages` (Czech implied); report `multilingual_names` only for a language not in
  the table or more than two“.
- `CalendarScanProposal` reads it, the review offers it like a feature („Jazyky: polština,
  angličtina“, pre-ticked), `AcceptCalendarScanHandler` writes `design.languages`; proposal hash
  includes it. Keep `multilingual_names` in `NOT_EXPRESSIBLE` for the leftover cases.
- Scanner capability: reading the words is trivial for the model; one eval on the Chotěbuz page.

## 5. Phases

1. **Model + texts** — `languages.js`, `normalize`, `pages.js` / `day.js`, two-line Měsíc render +
   autogrow height, short-form fallback, `dayLabel` warning. PHP: column + migration, rules,
   entity, bootstrap, history. Node suites: `generation` (texts per language/form, cross-month,
   cross-year, empty list = today's strings byte-for-byte), `week-layout` (two-line widths, short
   fallback in above/compact), `autogrow` (height growth), `render` (two `<text>` lines),
   `warnings`, `normalize` (default / bad codes / >2 / order). PHPUnit `CalendarDesignRulesTest`,
   `SaveCalendarControllerTest`. Fingerprint update in the same commit.
2. **Step-1 UI + MCP + skill release** — e2e `tests/e2e/calendar/` (tick PL + EN → page 1 shows
   `LEDEN / STYCZEŃ / JANUARY`, reload persists), screenshots 390/820/1440 both themes; MCP test.
3. **AI scan** — schema, prompt, proposal, accept, review copy; fault suite + one Chotěbuz run.

## 6. Risks
- **Glyphs**: an uploaded project font with a Czech-only subset lacks `ł ś ź ż ą ę ń` (PL),
  `ľ ĺ ŕ ô ä` (SK), `ß ä ö ü` (DE) → silent fallback glyphs in the print PDF. Nothing checks glyph
  coverage today. Minimum: a warning when a font in use misses a needed character (needs font
  cmap data server-side) — or at least a line in step 1. Recommend a follow-up, not a blocker.
- Estimator vs real metrics: the widths above are the estimator's (the same one the server uses);
  real fonts differ by a few %. The two-line rule has margin; the weekday is the tight one.
- Export fingerprint moves (expected; all stored PDF exports invalidate once).
- Concurrent work: K2 effects and k1-scan-prep touch render / scan files — build after they land.

## 7. Decisions (Jan, 2026-11-24)

- **D1** step 1 „Jazyky názvů“, Czech fixed — accepted.
- **D2** Slovak, Polish, German, English with **no cap** — any number. 3–4 extra languages must not
  break silently: the Měsíc wraps to more lines and its frame grows in height (never over the
  Kalendárium / photo; on 14 days each frame stays on its own half of the page), whatever still does
  not fit gets a page warning — never clipped. Fixed order cs → sk → pl → de → en (**D4** accepted).
- **D3** the user chooses: step 3 › Kalendárium „Názvy dnů: Celé · Zkrácené · Automaticky“ (`cal.dayFormat`
  full | short | auto, new layouts default to auto). Automaticky = full where they fit, else short;
  Celé = always full + the warning „Názvy dnů se nevejdou“ when they do not fit; Zkrácené = always
  short (Czech-only calendars too: Po, Út …). A Czech-only calendar under Automaticky prints exactly
  what it printed before (full names; stored layouts keep their `full`).
- **D5** two lines for a week across months, **D6** DST / season texts and the special pages Czech,
  **D7** own design key + column `languages` — accepted.
- **D8** font glyph coverage check — separate follow-up (FEEDBACK K17).

Fit rule (week-layout E.6.3a): beside the number / on compact rows the widest label must fit the
text column the full icon zone leaves; above the number it may widen the number column to half the
row at most. Judged once per frame, so every page of a calendar uses the same form.
