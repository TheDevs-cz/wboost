# Kalendáře — import from a spreadsheet (K10b) — APPROVED 2026-11-24, built

Feedback K10 (b): „navíc chybí nahrání souboru, excel“. Jan confirmed
(2026-11-23) that the import belongs in **both** step-4 tools — Odpady and
Akce a spolky — as one feature: one parser, one upload, one preview, one
„Stáhnout vzor“, and a mapping per tool. Jan approved D2–D10 as recommended on 2026-11-24; D1 (real files) was not
available — built against the shapes below with crafted fixtures
(`tests/Fixtures/calendar/import/`). The current truth is
`docs/architecture/calendars.md` § "Import from a spreadsheet".

## What the original spec said

- `docs/calendar/handoff.md` §44, §227, §586, §683 (check C6), §718: the
  prototype's „Nahrát z Excelu“ in Odpady and Akce a spolky is left out of
  the first iteration; the import is **rule-based, not AI**, and moves to the
  second iteration. Check C6 ("no Excel control is shown") is pinned by e2e
  `content-tools` (`C6 no Excel import …`) — this feature retires C6.
- `docs/architecture/calendars.md` "Second iteration" list: „Excel import for
  Odpady and Akce a spolky (rule-based, postponed)“.
- The prototype (`docs/calendar/prototype/wboost-prototyp.html`,
  `calReadTable` / `calParseDate`): one file input per tool
  (`.xlsx,.xls,.csv,.txt`), xlsx unzipped in the browser
  (`DecompressionStream`), CSV/TSV split on `\t` / `;` / `,`; dates as ISO,
  `1. 3. 2027`, `01.03.2027`, `1/3/2027` or an Excel serial number; a kind
  column matched to a waste kind by name; events as date · name · club;
  everything written at once, then a toast „Načteno N termínů · M řádků
  přeskočeno“. No preview, no mapping, no undo — and `.xls` was in `accept`
  but could not actually be read. Its copy promised „AI z tabulky vyčte…“,
  which the decision above replaced.
- `docs/calendar/reference/edge-cases-kalendare-ai.pdf` asks for
  „Automatizace stránky s plánovacím kalendářem svozu odpadů“ and days with
  waste + events + moon at once — the import feeds exactly that data; the
  reference calendars (Brušperk-style waste plans, club codes like SDH / ZŠ)
  show what the result must look like, not the source files.
- **We have no real source file from a municipality.** The shapes below are
  what Czech municipalities typically publish (svozové kalendáře of the
  collection companies, zpravodaj tables, the clerk's own Excel). Asking Jan
  for 3–5 real files is decision D1.

## Data the import writes (unchanged contracts)

- Waste: `wasteKinds[id]` `{builtin, name ≤30, on, place, mode: rule|dates,
  weekdays, interval, from, to, dates[] ≤ 400, pos}` — an import sets
  `mode: 'dates'`, `on: true` and `dates` (the rule fields stay as they were,
  so switching back to „Pravidelně“ loses nothing). `wasteExtras` (single
  collections from step 5) are left alone.
- Events: `events[id]` `{date, name ≤80, club: clubId|null, icon: null}`;
  clubs `clubs[id]` `{code ≤6, name ≤60, pos}`. The per-event icon is never
  imported (an upload, set by hand as today).
- Dates must lie in `[year−1-12-01, year+1-01-31]` (`dateLimits`).
- All writes are ordinary content ops through `store.applyOps` → the normal
  save → `CalendarContentRules` validates them like hand-typed ones. No new
  collection, no server-side write path, no new content contract. History
  gets its usual sentences (≤ 30 + „… a dalších n změn“).
- Content ops are **not undoable** in the store — hence the preview + explicit
  confirmation before anything is written.

## Proposal

### 1. Formats and parser

| Format | Support | How |
|---|---|---|
| `.xlsx` | yes | PhpSpreadsheet |
| `.xls` (Excel 97–2003) | yes | PhpSpreadsheet (municipal PCs still save it) |
| `.ods` (LibreOffice) | yes | PhpSpreadsheet — free with the same library |
| `.csv` / `.txt` (TSV) | yes | PhpSpreadsheet's CSV reader, with encoding sniffing: UTF-8 (± BOM) else **Windows-1250** (Czech Excel's „CSV (oddělený středníkem)“), separator `;` / `\t` / `,` |

**Parse on the server, map in the browser.** A new stateless endpoint
`POST /calendar/{id}/import/table` (+ `/k/{token}/import/table` for the
client link) takes the file (`_token`, `file`, 10 MB decimal cap like every
upload) and answers `{ sheets: [{ name, rows: [[cell…]…] }] }`, each cell
`{ text, date? }` — `date` = ISO when the cell is a real date cell (Excel date
serial + date number format), so "4. 2." typed as a date never depends on
locale guessing. Limits: ≤ 10 sheets, ≤ 2000 rows × 60 columns per sheet,
read-data-only, no formulas evaluated beyond cached values. The file is read
from the PHP upload temp file and **never stored** (no `calendar_asset`, no
S3, nothing in history). Rate-limited like client uploads.

Why not in the browser like the prototype: `.xls` (binary BIFF) and `.ods`
would need a large JS library (SheetJS ~1 MB, licence changed) or are
impossible; date cells and Windows-1250 CSV are the two classic breakages,
and PhpSpreadsheet handles both. Why not import entirely on the server: the
mapping is interactive and the writes must go through the item-wise content
merge anyway — the browser already owns that.

**New dependency: `phpoffice/phpspreadsheet` (^5)** — MIT, the de-facto PHP
standard; needs `ext-zip`, `ext-xml`, `ext-xmlreader`, `ext-simplexml`,
`ext-mbstring`, `ext-iconv` — all already in the `web` image. Note
`roave/security-advisories` lists vulnerable ranges (`<=5.8`, etc.): we pin a
current release above those ranges. CSV-only would be pure PHP, but CSV alone
does not answer „excel“.

### 2. Table shapes we recognise

Detection is rule-based (pure JS, `state/import.js` (outside `model/` on purpose: the export fingerprint), node suite `import`),
offered as a guess the user can change in the mapping step.

Odpady:

| Shape | Example | v1 |
|---|---|---|
| **A — one row per collection** | `Datum · Druh odpadu (· Místo)` | yes |
| **B — one column per kind** | header `Komunál · Plast · Papír`, cells are dates | yes |
| **C — months × kinds, days listed** | row `Leden`, cell `7., 21.` under `Plast` | yes |
| **D — one column of dates** | dates only → into the kind chosen in the dialog (prototype's per-kind import) | yes |
| E — a 12 × 31 grid with marks | the printed plan as a sheet (`X`, `P`, colours) | no — colours are not data; ask for samples first (D1) |

Akce a spolky: one row per event — `Datum · Název akce (· Spolek / pořadatel)
(· zkratka)`. Extra columns (čas, místo, poznámka) are ignored in v1, or
appended to the name on request (D7). A date range `12.–14. 6.` creates one
event on the first day and is listed as a note in the preview (D6).

Date cells: real date cells; text `2027-03-01`, `1. 3. 2027`, `01.03.2027`,
`1.3.`, `1/3/2027`, `1. března 2027`, `1. 3.` (year = the calendar's year;
December dates in a January-first file are NOT guessed as next year — they
are reported if outside the range); a day list `7., 21.` in shape C. Header
row detected (a row with no date and ≥ 1 known header word) or chosen.

### 3. UI (both tools, same component `ui/tools/import.js`)

1. A secondary button **„Nahrát z tabulky“** (`upload` icon, `xlsx, xls, ods,
   csv`) in the tool's head + a link **„Stáhnout vzor“** beside it.
2. **Mapping** (in the tool's modal, replaces the body until done): sheet
   picker when > 1 sheet; detected shape (changeable); for Odpady each kind
   found in the file → a select „Druh v kalendáři“: an existing kind ·
   „Nový druh: <name>“ (default for unknown; a built-in name like „PLAST“ /
   „plasty“ matches its built-in kind by a normalised alias table) ·
   „Nenačítat“. For events: which column is date / name / club; each club
   found → an existing club (matched by code, then by name, case- and
   accent-insensitive) or „Nový spolek“ with its code (from a code column, or
   proposed from the initials, ≤ 6 chars, editable).
3. **Preview before any write**: per kind „Plast — 26 termínů (nahradí 24
   stávajících)“, per club „SDH — 5 akcí (2 už v kalendáři, přeskočí se)“;
   a collapsible list of skipped rows with the reason (neplatné datum, mimo
   rozsah 1. 12. 2026 – 31. 1. 2028, prázdný název, víc než 400 termínů u
   druhu, duplicita) — row numbers as in the file. The primary button
   „Načíst N termínů“ / „Načíst N akcí“ is disabled while nothing valid
   remains. Cancel = nothing happened.
4. Write: one `applyOps` (kinds, clubs, events), toast „Načteno …“, tool
   goes back to its normal view on the first imported kind / month.

Merge rules:

- **Waste: per kind, replace by default** („Nahradit termíny“ / „Přidat k
  termínům“ toggle in the preview). A schedule is the whole truth for a kind;
  adding is for a second file (e.g. the second half-year). Dates deduplicated
  and sorted; > 400 → that kind is refused in the preview.
- **Events: add, skip exact duplicates** (same date + same name + same club,
  normalised). No „replace all events“ in v1 (D5).
- Kinds / clubs that are created get the next `pos`; an imported kind is
  switched on (`on: true`), a built-in alias reuses the built-in item
  (`id = builtin`) when it does not exist yet — same as „+ Přidat druh“.

### 4. „Stáhnout vzor“

`GET /calendar/{id}/import/template/{waste|events}` (+ the client-link
twin) — an `.xlsx` generated with PhpSpreadsheet (buffered Response, never a
flushing StreamedResponse): sheet „Odpady“ in shape A (`Datum · Druh odpadu ·
Místo svozu`) with the calendar's year, a data-validation list of the
calendar's kinds and two example rows; sheet „Akce“ (`Datum · Název akce ·
Zkratka spolku · Název spolku`) with the existing clubs listed on a helper
sheet. Date column formatted `d. m. yyyy`. A second sheet „Návod“ with three
lines of Czech instructions.

### 5. Roles

Clients fill waste and events today (`CLIENT_WRITABLE` has `wasteKinds`,
`wasteExtras`, `events`, `clubs`), and the municipality is the one holding
the file — so **the client may import** (designer and client, same UI). The
parse endpoint stores nothing, so it does not touch the client upload rule
(`photo` only) — it is not an upload of an asset. The writes are ordinary
client ops, refused by the server exactly as typed ones would be.

### 6. MCP

No new tool. An agent can already read any file the user gives it and write
`wasteKinds` / `events` / `clubs` through `update_calendar_content` — the
mapping is the agent's own work. Import is web-only. (A note in the wboost
skill's calendars reference that an agent should do the import that way;
docs only → no skill version bump unless the release guard demands it.)

### 7. Tests

- PHPUnit (TDD): the parse endpoint — xlsx with real date cells, xls, ods,
  CSV in UTF-8 + BOM and in Windows-1250 with `;`, TSV, an empty file, a
  non-spreadsheet (422 Czech message), > limits, client link allowed +
  rate-limited, nothing written to storage / DB. Fixtures generated in the
  test with PhpSpreadsheet's writers (nothing binary committed). Template
  download for both kinds, both roles.
- Node suite `import`: shape detection A–D + events, date parsing (all
  formats, ranges, year handling, day lists), header detection, alias
  matching of built-in kinds, club matching/creation and code proposal,
  merge (replace / add / dedupe / 400 cap / range), the exact ops produced.
- e2e `content-tools` (designer) + `client-tools` (client link): upload a
  generated xlsx, map, preview counts + a skipped row, confirm, data in the
  day rows, saved + reloaded; C6 rewritten to "the import control is shown".
- Docs: `calendars.md` (new § Import), handoff C6 note, FEEDBACK K10.

Rough size: 2–3 days (server endpoint + template ½ day, pure module + suite
1 day, UI ½–1 day, e2e + docs ½ day).

## Open decisions for Jan (with my recommendation)

| # | Question | Recommendation |
|---|---|---|
| D1 | Real sample files from municipalities / collection companies (Odpady and Akce) — can you send 3–5? | **Yes, before the build** — the shape detection should be tested on reality; shape E stays out until a sample shows it. |
| D2 | New composer dependency `phpoffice/phpspreadsheet` for xlsx / xls / ods / Windows-1250 CSV? | **Yes.** Pure-JS xlsx (prototype) would drop `.xls`/`.ods` and is fragile with date cells. |
| D3 | May the client (link `/k/…` and read-only member) import? | **Yes** — same rights as typing the data; the file is never stored. |
| D4 | Waste merge default: replace a kind's dates, or add? | **Replace by default**, „Přidat k termínům“ as a toggle per import. |
| D5 | Events: add only (+ skip duplicates), or also „replace all events“? | **Add + skip duplicates** in v1; deleting events stays a manual act. |
| D6 | A multi-day event (`12.–14. 6.`)? | **One event on the first day**, flagged in the preview. (Multi-day events are not in the data model.) |
| D7 | Extra event columns (čas, místo)? | **Ignore in v1**; optional „Připojit k názvu“ (`19:00 · Hasičský bál`) only if you want it — the name cap is 80. |
| D8 | „Stáhnout vzor“ as `.xlsx` generated per calendar (kinds/clubs pre-filled) vs. a static CSV? | **Generated `.xlsx`** (needs D2 anyway); CSV would open with broken diacritics in Czech Excel. |
| D9 | Button copy: „Nahrát z tabulky“ (covers CSV/ODS) or „Nahrát z Excelu“ (the owner's word)? | **„Nahrát z Excelu“** with the caption „xlsx, xls, ods nebo csv“ — the word people search for. |
| D10 | Unknown waste kind in the file — create a new kind automatically, or require mapping? | **Preselect „Nový druh: <název>“** (a built-in alias preselects the built-in); the user can remap or skip before confirming. |
