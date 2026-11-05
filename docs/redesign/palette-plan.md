# Paleta barev — project colour palette (F7 + colours half of F8)

Owner decision 2026-11-05: **option C** — the project palette is the source of truth for
colours; manuals pick (and order) the project colours they use. Always in the project menu
(not a module): Přehled, Manuály, Šablony, Galerie, Fonty, **Paleta barev**, Jídelníčky,
Facebook a Instagram. Includes a prod data migration. Manual pages lose "Barevná paleta" (F8).

**Bar:** for existing data the customer manual (`manual_preview`) renders byte-for-byte the
same, logo recolouring is identical, and template swatches / print CMYK / API stay identical
until someone edits the palette.

Status: PLAN — decisions taken 2026-11-05 (§6). **D2 differs from the draft below: roles
are PER PROJECT** (`role` lives on `project_color`, not on the link). Sections 1–4 still
describe per-link roles; the P0 agent updates them. Consequence: manuals that disagree on a
colour's role today will change — proposed migration rule: role = the one most manuals use,
tie → primary > secondary > none; every manual whose primary/secondary pages would change is
listed in the dry-run report for the owner to review BEFORE P1a ships.

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
  name varchar null   (new "Název")     role varchar null      ← primary|secondary, PER MANUAL
  cmyk jsonb null  ([c,m,y,k] strings)  position int           ← order within the manual
  pantone, ral varchar null             created_at
  position int, created_at              UNIQUE (manual_id, logo_hex) WHERE logo_hex IS NOT NULL
```

- FK to `project_color` uses NO ACTION (checked at statement end) — RESTRICT would break the
  project-delete cascade. Deleting a manual deletes its links; palette colours survive.
- **Read model unchanged:** `Manual` keeps `detectedColors()`, `customColors()` (property →
  method; Twig `manual.customColors` still resolves), `color(n)`, `colorsCount()`,
  `primaryColors()`, `secondaryColors()`, all returning `ManualColor` VOs built from links:
  logo links (by position) use `new Color(logo_hex)`, custom links use `palette.hex`;
  cmyk/pantone/ral from the palette; type = link role. `color(n)` = logo segment ++ custom segment.
- **HEX of a palette colour is locked while any logo link references it** (today detected HEX
  is read-only too) — otherwise the FROM keys stop matching the SVG.
- `name` renders in the manual swatch only when set (RAL precedent) → migrated output unchanged.
- Templates-only projects finally get a palette (empty after backfill).
- No new path columns → `BuildStorageReferenceIndex` / storage-inventory unaffected.

## 2. Migration / backfill

Pure planner `Services/Palette/PaletteBackfillPlanner` (arrays in → palette rows + link rows +
report out; no entity dependency; marked FROZEN, used by the migration) + unit tests.

Per project, manuals ordered `created_at, id`:
1. Rebuild each manual's old lists exactly as today: detected = `detectedColors()` semantics
   from `logo` + `detected_colors`; custom = `custom_colors`.
2. **Palette order** = the old `orderedManualColors` walk (all primaries, then secondaries,
   then untyped) → `computeColors` and `BrandCmykPalette` produce identical output.
3. **Dedupe (D1 strict):** key = (`hex_key`, trimmed cmyk tuple, pantone, ral). Identical →
   one palette colour. Same `hex_key`, different print data (incl. empty vs filled) → separate
   entries, flagged duplicate. Display `hex` = first occurrence's literal.
4. **Links:** detected → logo links (`logo_hex` literal, role, position); custom → custom links.
   Roles stay per link, so role conflicts can't happen.

**Report** (CSV + summary, from `app:palette:plan --report`), never auto-fixed:
split duplicates; case/3-digit variants merged (another manual's printed HEX string may change);
persisted detected colours not in the logo (today in `primaryColors()`, would drop); non-hex
detected strings (`rgb(...)` from `DetectImageColors` — `new Color()` throws); duplicate
entries within one manual; CMYK conflicts `BrandCmykPalette` logs today.

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

## 3. Consumers

| Consumer | Change |
|---|---|
| Manual preview, `_manual_color_swatch` | none (Manual read model); optional `name` line only when set |
| Logo recolouring (`DefaultLogoColors`, `logoColorMapping/Background`, `LogoColorsMapping`) | none — `color(n)` keeps literal logo hex + order |
| `ResolveRichTextOptions::computeColors` | input = `GetProjectPalette::forProject()` (palette order, normalised, deduped). Editors, group editor, fill, API, MCP `get_context`/`describe_variant`, `LintContext::forProject` follow automatically |
| `BrandCmykPalette` | iterate the palette in order; same first-wins + warning |
| Allowlists (text `allowedColors`, shape `allowedColors`) | stay hex snapshots (D4); validation unchanged |
| API `richTextOptions.colors`, `colorOptions` | same shape + same order; `consumer-prompt.md`: "project palette, palette order" (was "primary first") |
| mfkfm | `lib/infrastructure/wboost/types.ts:139` comment only; no code |
| Avatar | unchanged code (`primaryColors()`); fetch-join links + colours to avoid N+1 |
| Overview chips / `has_palette` | palette colours (first 12, palette order), pencil/eye → palette page; `EXISTS project_color` |
| `GetManuals::allForProject` | add `ORDER BY created_at, id` (P0) |
| Docs | `manuals.md` colours section, `templates-text-inputs.md`, `templates-render.md` (print), `app-shell.md` (nav), `consumer-prompt.md`, `FEEDBACK.md` F7/F8 |

## 4. UX (Nocturne, Czech)

**Paleta barev** — `/project/{id}/paleta`, route `project_palette`, sidebar item after Fonty
(`icon('palette')`, `active = 'palette'`), always shown; VIEW to see, `project_edit` to change.
Layout from prototype js:1963-2056 / design-system §573:
- `.head`: eyebrow "Projekt · {name}", h1 "Paleta barev", lead "Barvy značky pro manuály, šablony a tisk."
- Toolbar: "Přidat barvu" (one lavender primary), `.pal-strip` preview.
- Groups "Z logotypu" (colour has ≥1 logo link) and "Přidané barvy". `.pal-card`: swatch
  (click = copy HEX), HEX (locked with a lock icon + title "HEX určuje logotyp" when logo-linked),
  Název, RGB caption, CMYK 4-up + "Dopočítat z HEX", Pantone, RAL, ⋯ menu (Kopírovat HEX,
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
- role select per row (Primární / Sekundární / Bez role);
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
| P0 | `app:palette:snapshot <json>` (per manual: detected/custom/primary/secondary all fields, `color(1..n)`, `colorsCount`, mapping + background for 5 types × 5 colour variants; per project: `computeColors`, `BrandCmykPalette`, avatar hex, `has_palette`) + `app:palette:plan --report` (planner dry-run, read-only) + allForProject ORDER BY. Run on the prod mirror: snapshot A + curl every `/nahled-manualu/…` → HTML A; owner reviews the report | planner unit tests (merge/split, case + 3-digit, stale detected, rgb strings, in-manual dups, order parity) | S–M, 1 d |
| P1a | entities, migration (CREATE + backfill via planner), `ProjectManualColors` re-projection on `EditManualColors` + logo upload, `app:palette:verify`, `app:palette:reconcile` | kernel test: fixtures → backfill → link read model == JSONB read model; mirror: migrate, verify = 0 diffs | M, 1–1.5 d |
| P1b | read switch (Manual read model from links), writes to links + JSONB mirror, `SyncManualLogoColors`, `GetProjectPalette` → computeColors / BrandCmykPalette / MCP / lint, fixture + test updates, docs + consumer-prompt + mfkfm comment | ResolveRichTextOptionsTest, BrandCmykPalette, ManualColorsControllerTest still green (old page writes through), parity tests for `color(n)`; mirror: snapshot C == A, HTML C == A except reported rows, API listing JSON diff | M–L, 1.5–2 d |
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
