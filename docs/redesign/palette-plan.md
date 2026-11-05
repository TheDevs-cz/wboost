# Paleta barev — project colour palette (F7 + colours half of F8)

Owner decision 2026-11-05: **option C** — the project palette is the source of truth for
colours; manuals pick (and order) the project colours they use. Always in the project menu
(not a module): Přehled, Manuály, Šablony, Galerie, Fonty, **Paleta barev**, Jídelníčky,
Facebook a Instagram. Includes a prod data migration. Manual pages lose "Barevná paleta" (F8).

**Bar:** for existing data the customer manual (`manual_preview`) renders byte-for-byte the
same — **except the manuals the dry-run report lists under "role changes" (D2) and
"custom HEX literal changes"** — logo recolouring is identical, and template swatches /
print CMYK / API stay identical until someone edits the palette.

Status: decisions taken 2026-11-05 (§6); §1–§5 updated for **D2 = roles PER PROJECT**
(`role` lives on `project_color`, not on the link). Consequence: manuals that disagree on a
colour's role today change — migration rule: role = the one most manuals use, tie →
primary > secondary > none; every manual whose primary/secondary pages would change is
listed in the dry-run report (§2a) for the owner to review BEFORE P1a ships. **P0 done**
(snapshot + planner dry run, §2a). **P1a done** (2026-11-05: tables, backfill migration
`Version20261105170659`, write-path sync, `app:palette:verify` / `reconcile` — §2b).

## 0. What exists today (verified)

- `manual.detected_colors` / `manual.custom_colors` JSONB (`Entity/Manual.php:39-46`) of
  `Value/ManualColor` {Color hex (as written, `#` stripped, case kept), type primary|secondary|null,
  pantone, cmyk = 4 free strings, ral}.
- `Manual::detectedColors()` (234-267) = persisted detected **filtered to the logo's literal
  colours** + logo colours never persisted (appended, type null). `editLogo()` drops persisted
  detected colours no longer in the logo. Users reorder colours on the colours page (`order` field).
- **Recolouring is positional:** `color(n)` = `(detectedColors() ++ customColors)[n-1]`; `DefaultLogoColors`
  uses colours 1–3 as FROM keys, and branches on `count(detectedColors()) === 2`.
  `SvgColorsMapper` does `str_ireplace` on the raw SVG, so **the literal form matters**
  ("fff" ≠ "ffffff"; case doesn't).
- `primaryColors()` / `secondaryColors()` read the **raw persisted** detected list (not logo-filtered) ++ custom.
- Project-wide colour lists all come from `ResolveRichTextOptions::computeColors/orderedManualColors`
  (primary → secondary → untyped across manuals, deduped by `RichText::normalizeHexColor`):
  variant + group editors (`brand_colors`), fill, API `richTextOptions.colors`, MCP `get_context`,
  `describe_variant`, `LintContext` (DSL lint), `BrandCmykPalette` (print, first CMYK wins + warning).
- `GetManuals::allForProject` has **no ORDER BY** → today's swatch order / CMYK "first wins" is
  not deterministic across manuals.
- Allowlists (`EditorTextInput::$allowedColors`, shape `allowedColors` on canvas) store hex copies.
- Avatar: first non-white `primaryColors()` hex, manuals brand-first then createdAt (`GetProjectAvatars`).
- Overview chips: first manual with colours (`project_dashboard.html.twig:64-78`); card fact
  `has_palette` SQL on `custom_colors`/logo JSON (`GetProjectCardFacts`).
- Precedent (F8 fonts, 2026-11-05): the manual Fonty tab became a "Písma v manuálech" box
  on the project Fonty page; `/manual/{id}/fonts` redirects to `fonts_list#manual-fonts-<id>`.
- Prototype palette screen: `prototype/app.js:1963-2056`, design-system §573 (card), §692 (client view).

## 1. Data model

Tables (Font/ManualFont pattern), not JSONB on project: UUID identity, FK, cheap
"Použito v manuálech" joins, delete guard.

```
project_color                         manual_palette_color
  id uuid pk                            id uuid pk
  project_id → project CASCADE          manual_id → manual CASCADE
  hex varchar   (as written, no '#')    project_color_id → project_color  (NO ACTION; app guard)
  hex_key varchar ('#rrggbb', indexed)  logo_hex varchar null  ← literal logo colour; non-null = "Z logotypu"
  name varchar null   (new "Název")     position int           ← order within the manual
  role varchar null  ← primary|secondary, created_at
                       PER PROJECT (D2)  UNIQUE (manual_id, logo_hex) WHERE logo_hex IS NOT NULL
  cmyk jsonb null  ([c,m,y,k] strings)
  pantone, ral varchar null
  position int, created_at
```

- FK to `project_color` uses NO ACTION (checked at statement end) — RESTRICT would break the
  project-delete cascade. Deleting a manual deletes its links; palette colours survive.
- **Read model unchanged:** `Manual` keeps `detectedColors()`, `customColors()` (property →
  method; Twig `manual.customColors` still resolves), `color(n)`, `colorsCount()`,
  `primaryColors()`, `secondaryColors()`, all returning `ManualColor` VOs built from links:
  logo links (by position) use `new Color(logo_hex)`, custom links use `palette.hex`;
  cmyk/pantone/ral from the palette; **type = the palette colour's role** (one role per
  project colour — every manual linking it shows it on the same page). `color(n)` = logo
  segment ++ custom segment. `primaryColors()` / `secondaryColors()` = links in position
  order filtered by the palette role (today they read the raw persisted list, stale
  detected colours included — those drop, see the report).
- **HEX of a palette colour is locked while any logo link references it** (today detected HEX
  is read-only too) — otherwise the FROM keys stop matching the SVG.
- `name` renders in the manual swatch only when set (RAL precedent) → migrated output unchanged.
- Templates-only projects finally get a palette (empty after backfill).
- No new path columns → `BuildStorageReferenceIndex` / storage-inventory unaffected.

## 2. Migration / backfill

Pure planner `Services/Palette/PaletteBackfillPlanner` (arrays in → palette rows + link rows +
report out; no entity dependency; marked FROZEN, used by the migration) + unit tests.

Pure planner (implemented in P0: `src/Services/Palette/PaletteBackfillPlanner.php`, result
`Value/PaletteProjectPlan`, tests `tests/Services/Palette/PaletteBackfillPlannerTest.php`).
It reads the RAW columns (`manualFromStorage()` — DBAL, no hydration), so the migration can
call it unchanged. Per project, manuals ordered `created_at, id`:
1. Rebuild each manual's old lists exactly as today: detected = `detectedColors()` semantics
   from `logo` + `detected_colors` (incl. its LOOSE `in_array`: logo `#000` is not appended
   when `000000` is listed — numeric strings); custom = `custom_colors`.
2. **Palette order** = the old `orderedManualColors` walk (all primaries, then secondaries,
   then untyped) → `computeColors` and `BrandCmykPalette` produce identical output (the
   planner asserts both and reports `parity_broken` otherwise). Typed stale detected colours
   are in that walk (today's `primaryColors()` reads them), so they become palette colours
   without a link.
3. **Dedupe (D1 strict — refined by §7 "fill the gap", implemented as in §2b):** key = (`hex_key`, trimmed cmyk tuple, trimmed pantone, trimmed
   ral; an all-null cmyk = null, like `ManualColor`). Identical → one palette colour. Same
   `hex_key`, different print data (incl. empty vs filled) → separate entries, flagged
   duplicate. Display `hex` = first occurrence's literal.
4. **Role (D2 per project):** every manual using the palette colour votes with the
   strongest role it gives it (primary > secondary > none — a manual listing it twice votes
   once); the role most manuals vote for wins; tie → primary > secondary > none.
5. **Links:** rebuilt detected → logo links (`logo_hex` literal, position); custom → custom
   links; one position sequence per manual, logo segment first. In-manual duplicates keep
   one link each (positional parity of `color(n)`).

**Report** (CSV + summary, from `app:palette:plan --report=<csv>`), never auto-fixed:
split duplicates; case/3-digit variants merged + the custom links whose printed HEX /
`color(n)` literal changes (logo links keep their literal); print data merged up to
whitespace; persisted detected colours not in the logo (typed ones are on today's primary/
secondary page and drop); non-hex detected strings (`rgb(...)` from `DetectImageColors` —
`new Color()` throws, `detectedColors()` fails today); invalid persisted colours (hydration
throws); duplicate hexes within one manual (+ `UNIQUE (manual_id, logo_hex)` violations);
CMYK conflicts `BrandCmykPalette` logs today; **role votes where manuals disagree, and the
role-change list: every manual whose primary/secondary set (= its customer-manual colour
pages) changes**, old → new; monogram (avatar) colour changes.

### 2a. P0 dry run — local prod copy, 2026-11-05

`app:palette:plan` over 25 projects / 33 manuals (20 projects with manuals): **115 palette
colours, 155 links (85 logo, 70 custom)**. Findings: 9 split-duplicate entries (3 hexes in
2 projects), 1 case variant merged (`FFFFFF`/`ffffff`), 1 custom literal change, 3 CMYK
conflicts (= the 3 warnings `BrandCmykPalette` logs today), 2 role votes, **3 role changes**,
0 stale detected, 0 non-hex / invalid strings, 0 in-manual duplicates, 0 avatar changes,
0 parity breaks. Logo colours come from the logo JSONB (`detectedColors`), not the SVG
files, so the missing local Minio files affect nothing here.

Role changes (owner to confirm before P1a):
- **Čeladná → Fotbal - Čeladná:** `#17375f` primary → secondary (4 of 5 manuals say
  secondary) — leaves its primary page, appears on a new secondary page.
- **Frýdlant n. O. → Turistické informační centrum** and **→ Knihovna – logomanuál:** white
  `#ffffff` secondary → primary (tie 2:2 with "Frýdlant nad Ostravicí manuál" + "Erb města"
  → primary) — white moves to their primary pages. TIC's white is also printed `FFFFFF`
  instead of `ffffff` (case merge, `color(5)`, not a recolouring key).

Split duplicates the palette would show twice (P4 "Sloučit"): Čeladná `a2c62c` ×3 (pantone
empty vs `2299 C`, CMYK 95 vs 90 Y) and `17375f` ×2 (M 80 vs 85); Frýdlant `d19e5d` ×2
(pantone empty vs `7509C`) and `c6c6c6` ×2 (K 30 vs 20). Snapshot A:
`app:palette:snapshot <json>` (deterministic, two runs byte-identical).

**Logo re-upload later (D3):** `UpdateManualImagesHandler` → `SyncManualLogoColors`: delete logo
links whose literal left the logo (palette colour stays, shown "Nepoužitá"); append new literals
at the end of the logo segment, role null, reusing a palette colour by `hex_key` (first by
position) or creating one at the palette end. That's today's `detectedColors()`, just materialised.

**Releases (blue-green safe, migrations auto-run `--all-or-nothing`):**

| Rel | Schema | Authority | Notes |
|---|---|---|---|
| R1 (P1a) | additive: CREATE tables + backfill migration | JSONB | every write path re-projects that manual's links via the planner; reads unchanged; `app:palette:verify` compares both read models |
| R2 (P1b) | none | links/palette | reads switch; every write ALSO mirrors links → JSONB (so R1 code/rollback stay correct). Post-deploy `app:palette:reconcile`: JSONB ≠ mirror(links) ⇒ old code wrote during overlap ⇒ re-project that manual |
| R3–R4 | none | links | UI phases |
| R5a | none | links | stop mirroring, unmap the JSONB columns (they keep `'[]'` defaults, so overlapping code still works) |
| R5b | DROP `detected_colors`, `custom_colors` | — | ≥1 week after R2; **pg_dump to lily `/root/db-backups/` first**, ship only after the backup |

**Rollback:** R1 → revert (tables ignored). R2 → revert to R1 code: JSONB is current via the
mirror, and R1's projector never deletes palette colours, so palette-only data survives. UI
phases → plain revert. After R5b → restore the dump.

### 2b. P1a — shipped 2026-11-05 (release R1, additive)

Local prod copy after `doctrine:migrations:migrate`: **113 palette colours, 155 links
(85 logo, 70 custom)** — 2 fewer colours than §2a because of the §7 merge (Čeladná:
Fotbal's `A2C62C` without Pantone joins the `2299 C` entry; Frýdlant: `d19e5d` without
Pantone joins `7509C`). Plan report under §7 rules: 6 split-duplicate entries (Čeladná
`a2c62c` Y95/Y90, `17375f` M80/M85; Frýdlant `c6c6c6` K30/K20 — real conflicts, P4),
2 gap fills (Fotbal - Čeladná `color(1)` gains Pantone 2299 C; TIC `color(2)` gains
7509C), 2 case merges (`FFFFFF`/`ffffff`; `A2C62C`/`a2c62c` — the latter only on logo
links, nothing printed changes), 1 custom literal change (TIC white → `FFFFFF`), 3 role
changes (as §2a/§7), 0 parity breaks. `app:palette:verify` = exactly those 12 accepted
differences (3 role, 6 role pages, 2 gap fills, 1 spelling), 0 unexpected; computeColors
and BrandCmykPalette identical for every project. Snapshot after == snapshot A
byte-for-byte; `migrate prev` + re-migrate reproduces the same rows (UUIDv5 ids).

How it differs from / refines the text above:
- Backfill SQL lives in `Services/Palette/PaletteBackfillSql` (FROZEN like the planner;
  in src/ so tests replay exactly what the migration runs). ONE statement per project:
  palette INSERT guarded by "project has no palette" + `RETURNING`, links JOIN those
  rows (a skipped project never gets links to a deterministic id meaning another
  colour); a manual with links is skipped. Ids are UUIDv5 (project/manual id + position).
- §7 matching order: an occurrence joins the first same-`hex_key` colour that already
  carries its print data, else the first compatible one (fills the gap), else a new
  entry. Empty = null, blank or an all-blank CMYK tuple. Same rule at runtime.
- A logo literal repeated inside one manual links without `logo_hex` (partial UNIQUE);
  0 cases on the prod copy, reported as `in_manual_duplicate`.
- Write-path projector `ProjectManualColors` (DBAL — see `manuals.md`). D2 "last write
  wins" fires only when the write CHANGED the manual's role for that colour (the old
  colours page re-posts every type; an untouched save must not flip a project role);
  logo uploads never set roles. "Re-project every manual linking that colour" is a
  no-op in R1 (links carry no role) — it matters for P1b's JSONB mirror.
- R1 never deletes palette colours, so a print-data edit that conflicts leaves the old
  colour unused, and typed edits can move a swatch in today's walk while the palette
  keeps its order. `verify` accepts both (`unused_palette_colour`, `palette_order`).
  **P1b must decide** whether `GetProjectPalette` (computeColors, BrandCmykPalette)
  skips palette colours no manual links — otherwise leftovers become swatches.
- `app:palette:reconcile` never sets roles; it re-projects manuals whose links don't
  match (e.g. written by the old container during the blue-green overlap).

## 3. Consumers

| Consumer | Change |
|---|---|
| Manual preview, `_manual_color_swatch` | none (Manual read model); optional `name` line only when set. Output changes only for the §2a role-change / literal-change manuals |
| Logo recolouring (`DefaultLogoColors`, `logoColorMapping/Background`, `LogoColorsMapping`) | none — `color(n)` keeps literal logo hex + order |
| `ResolveRichTextOptions::computeColors` | input = `GetProjectPalette::forProject()` (palette order, normalised, deduped). Editors, group editor, fill, API, MCP `get_context`/`describe_variant`, `LintContext::forProject` follow automatically |
| `BrandCmykPalette` | iterate the palette in order; same first-wins + warning |
| Allowlists (text `allowedColors`, shape `allowedColors`) | stay hex snapshots (D4); validation unchanged |
| API `richTextOptions.colors`, `colorOptions` | same shape + same order; `consumer-prompt.md`: "project palette, palette order" (was "primary first") |
| mfkfm | `lib/infrastructure/wboost/types.ts:139` comment only; no code |
| Avatar | unchanged code (`primaryColors()` — now palette-role based); fetch-join links + colours to avoid N+1. Dry run: no monogram changes |
| Overview chips / `has_palette` | palette colours (first 12, palette order), pencil/eye → palette page; `EXISTS project_color` |
| `GetManuals::allForProject` | `ORDER BY created_at, id` — done in P0 (also fixes the overview chips' "first manual with colours", `ResolveEditorFontDefaults` first-primary and the Manuály / dashboard listing order, all heap order before) |
| Docs | `manuals.md` colours section, `templates-text-inputs.md`, `templates-render.md` (print), `app-shell.md` (nav), `consumer-prompt.md`, `FEEDBACK.md` F7/F8 |

## 4. UX (Nocturne, Czech)

**Paleta barev** — `/project/{id}/paleta`, route `project_palette`, sidebar item after Fonty
(`icon('palette')`, `active = 'palette'`), always shown; VIEW to see, `project_edit` to change.
Layout from prototype js:1963-2056 / design-system §573:
- `.head`: eyebrow "Projekt · {name}", h1 "Paleta barev", lead "Barvy značky pro manuály, šablony a tisk."
- Toolbar: "Přidat barvu" (one lavender primary), `.pal-strip` preview.
- Groups "Z logotypu" (colour has ≥1 logo link) and "Přidané barvy". `.pal-card`: swatch
  (click = copy HEX), HEX (locked with a lock icon + title "HEX určuje logotyp" when logo-linked),
  Název, **Role select (Primární / Sekundární / Bez role — D2: one role for every manual
  using the colour; the hint lists the manuals whose colour pages it changes)**, RGB
  caption, CMYK 4-up + "Dopočítat z HEX", Pantone, RAL, ⋯ menu (Kopírovat HEX,
  Posunout výš/níž, Smazat barvu), drag reorder (`colors-dragula`).
- Per card "Použito v:" chips per manual (logo mark when from the logo), or "Nepoužitá".
- Duplicate HEX chip "Stejný HEX jako …" + "Sloučit" (P4).
- Autosave per field: JSON POST + CSRF + toast "Uloženo" (pattern: `EditManualLogoSlotWidthController`).
- Alerts: "N barvám chybí CMYK pro tisk".
- **Delete guard:**
  - logo-linked → blocked: "Barva je v logotypu manuálu X — změní se až s novým logem";
  - custom-linked → dialog listing the manuals + "Odebrat z manuálů a smazat";
  - template note: "Šablony, které ji mají v omezení barev, ji zachovají" (count in P4).

**Barvy v manuálech** box on the same page (D6, mirrors "Písma v manuálech"; anchor
`#manual-colors-<id>`; only when Manuály is on; `manual_edit`). Per manual:
- an ordered list in two segments: "Z logotypu" (automatic, reorder only) and "Další barvy" (reorder, remove);
- number badges 1-2-3 with the hint "Pořadí určuje výchozí přebarvení variant loga";
- the palette colour's role shown read-only per row (pill; edited on the card — D2);
- "Přidat z palety" dialog (palette colours not yet in the manual, multi-select);
- the "Manuál potřebuje alespoň 3 barvy" warning moves here.

**Manual pages:** "Barevná paleta" tab removed; `/manual/{id}/colors` redirects to
`project_palette#manual-colors-{id}`; `ManualColorsController` form/FormData/FormTypes deleted.

**Clients (VIEW):**
- read-only per design-system §692: disabled fields, peach dashed empty fields, no add/menus;
- `.alert.c-warning`: "Některé hodnoty u barev nejsou specifikované… Požádejte grafika o doplnění";
- the manual box is read-only; the customer manual itself is unchanged.

## 5. Phases (separate commits/agents)

| P | Scope | Tests / verification | Effort |
|---|---|---|---|
| P0 ✔ | `app:palette:snapshot <json>` (per manual: detected/custom/primary/secondary all fields, `color(1..n)`, `colorsCount`, mapping + background for 5 types × 5 colour variants; per project: `computeColors`, `BrandCmykPalette`, avatar, `has_palette`, overview chips) + `app:palette:plan --report` (planner dry-run, read-only) + allForProject ORDER BY. Ran on the local prod copy (§2a); still TODO on the prod mirror: snapshot A + curl every `/nahled-manualu/…` → HTML A; owner reviews the report | planner unit tests (merge/split, trim, case + 3-digit, stale detected, rgb strings, in-manual dups, role majority + tie, order parity vs the entities) + command smoke test | S–M, 1 d |
| P1a ✔ | entities, migration (CREATE + backfill via planner), `ProjectManualColors` re-projection on `EditManualColors` + logo upload, `app:palette:verify`, `app:palette:reconcile` (§2b). D2: the old per-manual colours page still posts a type per manual — re-projection sets the palette colour's role (last write wins) and must re-project EVERY manual linking that colour; `verify` expects diffs exactly for the §2a role-change manuals | kernel test: fixtures → backfill → link read model == JSONB read model (minus reported role changes); mirror: migrate, verify = only reported diffs | M, 1–1.5 d |
| P1b | read switch (Manual read model from links), writes to links + JSONB mirror, `SyncManualLogoColors`, `GetProjectPalette` → computeColors / BrandCmykPalette / MCP / lint, fixture + test updates, docs + consumer-prompt + mfkfm comment | ResolveRichTextOptionsTest, BrandCmykPalette, ManualColorsControllerTest still green (old page writes through), parity tests for `color(n)`; mirror: snapshot C == A and HTML C == A except the §2a role-change / literal-change manuals, API listing JSON diff | M–L, 1.5–2 d |
| P2 | Paleta barev page + sidebar + autosave endpoints + delete guard + usage chips + client read-only + overview chips + `has_palette` | controller tests (VIEW vs EDIT, guard, locked HEX, CSRF), `shoot.cjs` 390/820/1440 both themes | M–L, 2 d |
| P3 | "Barvy v manuálech" box (order, role, add from palette, remove), drop the manual tab + redirect, delete the old colours page | controller tests, redirect test, snapshot diff after reorder = expected, screenshots | M, 1–1.5 d |
| P4 | "Sloučit" duplicates (repoint links, delete loser), template usage count (scan text inputs + shape `allowedColors`) | merge tests, manual output change only for touched manuals | S–M, 0.5–1 d |
| P5a / P5b | stop mirroring + unmap columns; then a separate backup-gated DROP migration; remove planner usage from runtime | phpstan, full suite; pg_dump before P5b deploy | S, 0.5 d |

Total ≈ 7–9 dev-days. Every phase: `composer phpstan`, PHPUnit, `/verify` before commit.

## 6. Decisions (owner, 2026-11-05)

D1 strict ✔ · **D2 per project** (changed) · D3 auto-add + link ✔ · D4 hex snapshots ✔ (default) · D5 palette order ✔ (default) · D6 box on Paleta barev ✔

Original options:

1. **D1 Dedupe rule.** Default **strict**: merge only when hex and CMYK/Pantone/RAL are identical;
   otherwise separate entries, flagged, with "Sloučit" (P4). Alternative: merge by hex,
   first non-empty wins (simpler, but some manuals visibly change).
2. **D2 Role scope.** Default **per manual** (Primární/Sekundární on the link) — keeps every manual
   exact. Alternative: per project (prototype; conflicting manuals change). Extra prototype roles
   (Doplňková/Text/Pozadí) deferred.
3. **D3 Detected logo colours.** Default **auto-add to the palette + auto-link to the manual on
   upload** (appended, no role); a colour that leaves the logo stays in the palette as "Nepoužitá"
   (not deleted).
4. **D4 Template allowlists.** Default **stay hex snapshots, don't follow palette edits**; the palette
   shows a usage-count warning; "Nahradit i v šablonách" later.
5. **D5 Swatch order in templates/API.** Default **palette order** (seeded = today's
   primary → secondary → untyped). Alternative: always role-sorted.
6. **D6 Where a manual picks colours.** Default **"Barvy v manuálech" box on Paleta barev**
   (mirrors F8 "Písma v manuálech"). Alternative: a panel on the manual's Logotypy tab.

## 7. Dry-run decisions (owner, 2026-11-05, after P0 report)

- **Role ties:** keep the tie rule (primary > secondary > none). Frýdlant nad Ostravicí white
  becomes primary for the project (TIC + Knihovna change). Accepted role changes: Čeladná →
  Fotbal - Čeladná `#17375f` primary → secondary; Frýdlant TIC + Knihovna white → primary.
- **D1 refined — "fill the gap" merge:** same `hex_key` where the print data differs ONLY by
  empty vs filled fields (no two non-empty values disagree) → ONE palette colour carrying the
  union of the filled values. A manual that lacked the value gains it on its colour page
  (listed in the report). Real conflicts (two different non-empty CMYK/Pantone/RAL) stay
  separate entries, flagged, merged later via "Sloučit" (P4).
- **HEX spelling:** custom colours print the palette's HEX spelling (`ffffff` → `FFFFFF` in one
  manual is accepted). Logo links keep their literal `logo_hex` (recolouring needs it).
