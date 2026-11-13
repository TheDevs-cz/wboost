# AI sken — UX design

Status: design, **revision 5**, 2026-11-12. Implementation is under way: the job core (P1) and
the scanner (P2) are being built; the wizard (P3) is built next, from this document and the mock.
Rewritten after
three reviews (`review.md`, `review-ux.md`, `review-reliability.md`) so that this document and
`architecture.md` use the same states, names, numbers and limits. Where they could differ, §2 here
and `architecture.md` §4.9 / §5.2 are the single tables both refer to.

Scope: "AI scan" in **Šablony** ("Šablona z obrázku") and **Kalendáře** ("Naskenovat design"),
access UI, admin views, MCP outline. Stage names, time figures and report contents describe the
scanner as designed; `architecture.md` revision 5 is the source of every backend fact here.

The static mock `ux-mock/index.html` (v2) is drawn from this revision and checked in a real
browser at 390 / 820 / 1440 px in both themes; `ux-mock/NOTES.md` lists its screens, the class of
every new component and the paragraph here that specifies it. This document draws the main screens
and gives copy for all of them; §12 lists the components.

**Changes in revision 5** (targeted edits, nothing renumbered; §2.4 is new):

1. Fonts in the report (S4, C4, principle 5): three fixed copy patterns — *measured match*,
   *nearest available*, *could not be determined*. The original's typeface is **never named**;
   only faces of the project are. The earlier `Vypadá jako {rodina} (odhad)` is gone.
2. §2.4: every counter of the flow with its 1 / 2–4 / 5+ forms and its zero case, the `z` / `ze`
   rule, and the rule for the background in the summary line. `Rozhodnout zbývá…` is replaced by
   `Zbývá rozhodnout 1 věc · Zbývají rozhodnout {n} věci · Zbývá rozhodnout {n} věcí`.
3. S5: a pre-check stop shows a neutral row `Kontrola obrázku — zastaveno`, not the rose ✖; F9
   says what the retry button looks like when a limit or the queue is in the way.
4. S4: the left-out item lists each thing with its own crop; simplification lines gained
   gradient / pattern entries; the compare control is stated as two modes plus `Ukázat vrstvy`.
5. Layout rules that were open: where the step label sits (§5), order of the columns between 641
   and 1000 px (§3, S4, S4b), the collapsed `Průběh` inside the status card on phones (S3), the
   phone's peach dot (§7), the heading of the list strip (T5), the top bar's button whenever the
   calendar panel shows a primary (§6), the calendar apply label with an empty place (§6).
6. Copy that was missing: consent as info lines on later scans (S2), "one chance" for `Zkusit
   přesto` (F3a), `Odebrat obrázek` on a pre-filled logo (S4b), `Tohle kalendář neumí` rows carry
   a crop instead of free text (C4).
7. §12: `--on-pch` has a value and a home; one moving element per view; tap area of inline links.
8. Mirrors `architecture.md` revision 5: at most 8 scans in kontrola + fronta; no PDF in the first
   build (later "first page only"); `checking` may end in F4 / F5; what counts as "failed twice";
   a provider-cut run is F5, not `nedokončeno`; `Vypnuto` also fails waiting scans; `layout_origin`
   column; `Vrátit původní` runs to the proposal's expiry; MCP `restore` `confirm` /
   `layout_changed`, `wrong_state`.

| Term | Meaning |
|---|---|
| **sken** | one scan: one uploaded picture → one proposal |
| **AI sken** | the capability (admin switches, usage, request dialog) |
| **původní obrázek** | the uploaded picture |
| **návrh** | what a scan produces: `návrh šablony` or `návrh rozvržení`. It is not a template and changes no calendar until the user creates / applies it |
| **místo pro obrázek** | a picture placeholder: a named, placed layer without content |
| **zpráva ze skenu** | what the scanner substituted, simplified, left out or doubts |

Every count in a Czech string uses the app's plural helper (1 / 2–4 / 5+) and `z` / `ze` by the
number. Strings below show the three forms once where they differ: `1 sken · 2 skeny · 5 skenů`.
§2.4 is the full list of counters, with the zero case of each.

---

## 1. Principles

1. **A scan makes a proposal, and the screen says so.** Nothing exists among templates and no
   calendar changes until one button is pressed. The proposal is called `návrh` everywhere and
   shows the date it expires.
2. **Honest waiting.** Only stages the scanner reports, with facts it really sends. No percentage,
   no countdown. Queue position and estimated times are ranges, and they disappear when the
   service is not working rather than pretend.
3. **Waiting is optional.** "Můžete odejít — dáme vědět" from the first second; the scan is
   findable in the module list, the sidebar and one e-mail.
4. **Say what did not match, next to where it happened.** Each report item and each picture card
   carries its own small crop of the original and of the proposal, so item and picture are visible
   together on any screen.
5. **Say only what was measured.** A typeface is named only when it is a face of the project;
   the typeface of the original is never named, because nothing the scanner has supports a name.
   "Nenašli jsme" instead of "není"; a mismatch is stated as a mismatch.
6. **Ask before the scan only what the picture cannot answer.** One question in Šablony (format),
   none in Kalendáře.
7. **The review decides, the editor edits.** No geometry editing in the wizard.
8. **One flow, two skins; one lavender primary per view.** Peach marks AI and "now"; mint is done.
9. **Never charge for what a free fix does, never ask for a click that changes nothing.**

---

## 2. States, numbers, credits — shared with `architecture.md`

### 2.1 States

| State | Pill (history, cards) | Meaning | Exits |
|---|---|---|---|
| `checking` | `Kontrola obrázku` (peach, spinner) | upload stored; the scanner normalises it and runs the pre-check (seconds; does not wait behind running scans) | → `queued`, `stopped`, `cancelled`, `failed` (F5), `refused` (F4); "Zkusit znovu" after a failed check returns to `checking` |
| `queued` | `Ve frontě` (peach) | passed the pre-check, waits for a free scanner | → `running`, `cancelled`, `failed` (queue timeout) |
| `running` | `Sken běží` (peach, spinner) | the builder works | → `ready`, `failed`, `refused`, `cancelled`, back to `queued` (restart) |
| `ready` | `Připraveno ke kontrole` (peach) | a proposal waits; flag `nedokončeno` when stopped at a limit | → `accepted`, `discarded`, `expired` |
| `accepted` | `Šablona vytvořena` / `Rozvržení použito` (mint) | the template exists / the layout is saved | — |
| `discarded` | `Zahozeno` (neutral) | thrown away by a user | — |
| `cancelled` | `Zrušeno` (neutral) | stopped by the user before a proposal | → `discarded`, `expired` |
| `stopped` | `Potřebuje jiný obrázek` (neutral) | the pre-check said no | → `queued` ("Zkusit přesto", photo of a print only), `discarded`, `expired` |
| `failed` | `Nepodařilo se` (rose) | system-side failure | → `queued` ("Zkusit znovu", bounded), `discarded`, `expired` |
| `refused` | `Odmítnuto` (rose) | the provider refused the content | → `discarded`, `expired` |
| `expired` | `Vypršelo` (neutral) | 30 days passed; picture and proposal deleted | — |

Conditions shown on top of `checking` / `queued` (not states): `waiting` = `scanner_absent` |
`scanner_too_old` | `provider_blocked` | `paused`; `restarted` = the run was interrupted and started again.

Wording: one name per thing — `Sken běží`, `Sken se nepodařil`, `návrh`, `místo pro obrázek`.
User-facing "pokus" means a user's retry (`2. pokus`); the scanner's automatic restart is never
called a pokus.

### 2.2 Numbers the user can meet

| Quantity | Value |
|---|---|
| Upload | JPG, PNG, WebP, HEIC; ≤ 10 MB (10 000 000 bytes); each side ≤ 8000 px; ≤ 36 megapixels; shorter side ≥ 600 px (a calendar page: ≥ 300 px — real pages are often 977 × 457 px exports; measured in `scanner/eval/RESULTS.md` §9.5). Pictures only in the first build; PDF comes later, as "first page only" |
| Template size made from a scan | longer side ≤ 5000 px (A3 at 300 dpi) |
| Usual duration | 1–3 min; pre-check 5–15 s |
| Limit of one run | 6 min; the scanner may restart a run once by itself → up to ≈ 12 min of work plus waiting |
| "Trvá to déle" line | after 3 min of `running` |
| Queue | a project may have at most 4 scans in progress at once (kontrola, fronta, sken together); one scan runs at a time for everyone, the rest wait; at most 8 are in kontrola and fronta together; a scan waiting 60 min fails and is not counted |
| Correction rounds | up to 2 (`kolo {n} ze 2`) |
| Retention | návrh and původní obrázek: 30 days from the end of the scan, date shown; then `Vypršelo` |
| Failed, stopped or refused card for other project editors | 24 h, then only the requester sees it |
| E-mail | one when the scan ends (not sooner than 2 min after, only if still unseen) + one reminder 3 days before a waiting proposal expires |
| Monthly limit | per project, default 10 |
| Daily starts | 10 per user, 20 per project |
| "Zkusit znovu" | 2 per upload. A picture that failed twice is refused, also when uploaded again. Only these count as a failure of the picture: run limit, build failure, interruption by a crash, a check that crashed twice — **not** provider, renderer or queue failures, a cancel or a pre-check stop |
| "Zkusit přesto" | once per upload; not one of the two retries |
| Page polling | 2 s on the scan surface, 10 s elsewhere |

### 2.3 What counts

| Outcome | Counted against the monthly limit |
|---|---|
| Proposal delivered (also `nedokončeno`), whatever the user does with it | **yes** |
| Run cut short mainly by the provider, even if a candidate existed | no — it is F5, not a `nedokončeno` proposal |
| Stopped by the pre-check, failed, refused, queue timeout | no |
| Cancelled while `checking` or `queued` | no |
| Cancelled while `running` | **yes** — the work was already paid for |
| "Zkusit znovu" / "Zkusit přesto" | reserves a scan again; the same rules apply |

One sentence for users: `Počítá se sken, který dostanete, a sken, který zrušíte za běhu.`

### 2.4 Counters — forms and the zero case

One helper, three forms (1 · 2–4 · 5 and more; 0 takes the third form unless the table says
otherwise). **`z` / `ze`:** `ze` before 2, 3, 4, 6, 7, 12, 13, 14, 16, 17, 100 and 200; `z`
otherwise (`2 ze 4`, `7 z 10`, `3 ze 12`, `0 z 3`).

| Counter | 1 | 2–4 | 5+ | Zero |
|---|---|---|---|---|
| Summary: texts | `1 text` | `{n} texty` | `{n} textů` | part left out |
| Summary: shapes | `1 tvar` | `{n} tvary` | `{n} tvarů` | part left out |
| Summary: places for pictures | `1 místo pro obrázek` | `{n} místa pro obrázky` | `{n} míst pro obrázky` | part left out |
| Summary: background | — | — | — | the word `pozadí` closes the list when the proposal has a background layer (colour or gradient); a full-page background **photo** is a place for a picture and is counted there |
| To decide (summary, e-mail) | `Zbývá rozhodnout 1 věc.` | `Zbývají rozhodnout {n} věci.` | `Zbývá rozhodnout {n} věcí.` | `Nic k rozhodnutí.` (e-mail: the sentence is left out) |
| Tab counters `K rozhodnutí {n}`, `Vrstvy {n}` | number only | | | `0` is shown; the tab body says `Nic k rozhodnutí` + the "nothing replaced" text |
| Scans ahead | `Před vámi je 1 sken` | `Před vámi jsou {n} skeny` | `Před vámi je {n} skenů` | `Jste na řadě — začneme během chvíle.` |
| Card line, queued | `Ve frontě · 1 před vámi` | `Ve frontě · {n} před vámi` | same | `Ve frontě · jste na řadě` |
| Texts found (stage note) | `Nalezen 1 text` | `Nalezeny {n} texty` | `Nalezeno {n} textů` | `Texty jsme nenašli` |
| Day rows (stage note) | `1 řádek dní` | `{n} řádky dní` | `{n} řádků dní` | no note |
| Texts affected (font item) | `Týká se 1 textu.` | `Týká se {n} textů.` | same | item not shown |
| Texts shrunk | `u 1 textu` | `u {n} textů` | same | line not shown |
| Colours unified | `1 barvu jsme sjednotili` | `{n} barvy jsme sjednotili` | `{n} barev jsme sjednotili` | line not shown |
| Things simplified | `Zjednodušili jsme 1 věc:` | `…{n} věci:` | `…{n} věcí:` | line not shown |
| Line split into texts | — | `na {n} texty` | `na {n} textů` | — |
| Things left out | `1 prvek jsme vynechali` · `Nepodařilo se ho převést na text ani tvar.` | `{n} prvky jsme vynechali` · `Nepodařilo se je převést…` | `{n} prvků jsme vynechali` · `…je převést…` | item not shown |
| Pictures done (S4b head) | `{hotovo} z / ze {n}` | | | `0 z {n}`; with no place at all the step is skipped |
| Pictures to add later (primary, export dialog) | `1 obrázek doplníte později` · `Šablona má 1 nedoplněný obrázek` · `bude … šedý zástupný obrázek` | `{n} obrázky doplníte později` · `{n} nedoplněné obrázky` · `budou … šedé zástupné obrázky` | `{n} obrázků doplníte později` · `{n} nedoplněných obrázků` · `bude … {n} šedých zástupných obrázků` | label without the tail: `Vytvořit šablonu a otevřít editor` |
| Pill on the template | `K doplnění: {n}` (number only) | | | no pill |
| Merge bar | — (button disabled, `Vyberte aspoň dvě místa`) | `Sloučit {n} místa` | `Sloučit {n} míst` | as 1 |
| Files assigned by the multi-drop | `1 obrázek přiřazen` | `{n} obrázky přiřazeny` | `{n} obrázků přiřazeno` | `Žádné prázdné místo — obrázky jsme nepřiřadili` |
| Elements created (toast) | `1 prvek` | `{n} prvky` | `{n} prvků` | cannot happen (F8) |
| Project fonts | `1 řez` | `{n} řezy` | `{n} řezů` | the "no font" row of T2 |
| Credits left | `zbývá {n} z / ze {limit}` (number only) | | | the limit texts of S2 / T1; the last one: `Spotřebuje poslední sken projektu v tomto měsíci` |
| Correction round | `kolo {n} ze 2` | | | no pill |
| History link on the card | `Historie skenů ({n})` | | | link not shown |
| User's retry | `{n}. pokus` from the second | | | first run: nothing |
| Minutes in estimates | always a range `{a}–{b} min` | | | under one minute `během chvíle` |

---

## 3. Flow

```
 Šablony: card in „Nová šablona“ → own page        Kalendáře: action card in step 2 → panel
                         │                                         │
   S1 upload → S2 checks, options, consent → S3 waiting (kontrola → fronta → sken)
                                                   │            │
                                 S5 stopped / failed            S4 review → S4b pictures → create / apply
                                                   │                                  │
                        leave any time: list card · sidebar · toast · e-mail      history
```

Up to 1000 px there is one column, and the order is fixed per screen: **S3 and S5** — the status
/ result card first, then the pictures; **S2** — the picture and its checks, then "Než začneme";
**S4** — summary line, viewer, report panel, action block (the same order as on a phone);
**S4b** — the numbered picture (not sticky, at most 360 px wide; hidden under 641 px), the
multi-drop zone, the cards, the action block.

---

## 4. Shared screens

### S1 — Upload

Component: `.gal-drop` with a peach sparkle. One file; the whole page accepts a drop.
`accept="image/jpeg,image/png,image/webp,image/heic,image/heif"` Pictures only.

```
┌─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ┐
                                 ✦
│                Přetáhněte sem obrázek grafiky                      │
                 nebo vyberte soubor z počítače
│     JPG, PNG, WebP nebo HEIC · nejvýše 10 MB · jeden obrázek       │
└─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ┘
S ČÍM TO JDE NEJLÉPE
✓ Export z grafického programu nebo snímek obrazovky   ✗ Fotka vytištěného plakátu z mobilu
✓ Celá grafika bez ořezu, kratší strana aspoň 1000 px  ✗ Koláž z více grafik v jednom obrázku
✓ Rovné texty, ploché barvy, jasné tvary               ✗ Stíny, obrysy a otočené texty — ty zjednodušíme
```

Phone: the zone says `Klepněte a vyberte obrázek`; tips in one column.

| Slot | Šablony | Kalendáře |
|---|---|---|
| Bold line | `Přetáhněte sem obrázek grafiky` | `Přetáhněte sem obrázek stránky kalendáře` |
| Caption | `JPG, PNG, WebP nebo HEIC · nejvýše 10 MB · jeden obrázek = jedna šablona` | `… · jedna týdenní stránka` |
| ✓ | as drawn | `Export stránky z grafického programu` · `Jedna celá týdenní stránka, rovně a bez ořezu` · `Běžný týden — ne titulní strana ani měsíční přehled` |
| ✗ | as drawn | `Fotka kalendáře na stole — zkreslí barvy i rozměry` · `Více stránek v jednom obrázku` · `Ozdobné pruhy a rámečky — rozvržení má pevnou sadu prvků` |

**PDF** is not in the first build: a PDF gets the message below. It comes later as "first page
only" — no page row, no thumbnail.

Refusals (toast `.err` + `.hint.err` under the zone). The browser checks type and byte size; pixel
size is read from the file header by the browser where it can and by the server (HEIC: by the
scanner in the checking step, which then stops the scan with F3e if it is out of bounds).

| Case | Message |
|---|---|
| Not accepted | `Tento soubor neumíme. Nahrajte obrázek JPG, PNG, WebP nebo HEIC.` |
| PDF / PSD / AI / IDML | `PDF a zdrojové soubory zatím neumíme. Uložte stránku jako obrázek (PNG nebo JPG) a nahrajte ji.` |
| More than one | `Nahrajte jen jeden obrázek.` |
| > 10 MB | `Obrázek je větší než 10 MB. Zmenšete ho nebo ho uložte jako JPG.` |
| Short side < 600 px (Kalendáře: < 300 px) | `Obrázek je příliš malý ({w} × {h} px). Kratší strana musí mít aspoň 600 px, lépe 1000 px a víc.` (Kalendáře: `…aspoň 300 px, lépe 600 px a víc.`) |
| Side > 8000 px or > 36 Mpx | `Obrázek je příliš velký ({w} × {h} px). Zmenšete ho — nejvýše 8000 px na stranu a 36 megapixelů.` |
| Network | `Nahrání se nepodařilo. Zkontrolujte připojení a zkuste to znovu.` + `Zkusit znovu` (the same request id, so a repeat never makes a second scan) |

### S2 — Picture chosen: facts, options, consent, start

```
┌──────────────────────────────────────────────┐  ┌──────────────────────────────┐
│ ┌────────┐  vysledek-zapasu-u19.png          │  │ Než začneme                  │
│ │ náhled │  1080 × 1350 px · 4:5 · 1,8 MB    │  │ (module options — T2 / none) │
│ └────────┘  ✓ Formát a velikost jsou v pořádku│ │ ☐ Souhlasím, že obrázek …    │
│             ✓ Obrázek je ostrý               │  │ ☐ Potvrzuji, že tuto grafiku…│
│             [↻ Vybrat jiný obrázek]          │  │ (i) Před vámi jsou 2 skeny…  │
│ S ČÍM TO JDE NEJLÉPE …                       │  │ [ ✦ Spustit rozpoznávání   ] │
└──────────────────────────────────────────────┘  │ ✦ Spotřebuje 1 sken · v pro- │
                                                  │   jektu zbývá 7 z 10         │
                                                  └──────────────────────────────┘
```

Browser-side facts (nothing has left the browser):

| Check | OK | Warning (peach, never blocks) |
|---|---|---|
| Type / size | `Formát a velikost jsou v pořádku` | — |
| Resolution | `Dostatečné rozlišení` | `Malý obrázek — drobné texty nemusíme přečíst` (600–999 px; Kalendáře, 300–599 px: `Malý obrázek — rozvržení může vyjít méně přesně`, and the review's `Pro informaci` then says `Obrázek má nízké rozlišení — polohy a velikosti písma mohou být méně přesné. Zkontrolujte je v kroku Úpravy prvků.`) |
| Sharpness | `Obrázek je ostrý` | `Obrázek je rozmazaný — výsledek bude jen přibližný` |
| Ratio (Kalendáře) | `Poměr stran odpovídá vašemu kalendáři` | `Stránka má jiný poměr stran než váš kalendář ({š} × {v} mm) — rozvržení přizpůsobíme` |
| HEIC | no preview, no pixel checks in browsers that cannot decode it: file name, size, `Náhled uvidíte po nahrání` | `Fotka z telefonu (HEIC). Pokud jde o fotku tisku, výsledek bude jen přibližný.` |

**Consent** — always visible above the button; two separate statements. Both are required on the
user's first scan and after every change of wording; later they are shown as plain info lines.
Wording marked *[…]* needs confirmation against the provider contract and by a lawyer before any
picture that is not WBoost's own is scanned in production (`architecture.md` gate G1).

> ☐ **Souhlasím, že obrázek a údaje z něj (texty, barvy, názvy písem) odešlete ke zpracování
> službě Claude společnosti Anthropic (USA).** *[Anthropic je maže do 30 dnů.]* My uchováme původní
> obrázek 30 dní. [Jak s obrázky nakládáme]
>
> ☐ **Potvrzuji, že tuto grafiku smím použít.**

Dialog `Jak s obrázky nakládáme` (S, 460 px), button `Rozumím`:

> **Co odesíláme a komu.** Obrázek, texty a barvy z něj, názvy písem a barvy palety projektu
> posíláme službě Claude společnosti Anthropic (USA), která v obrázku rozpozná texty, tvary
> a rozvržení. Nikomu dalšímu je nepředáváme.
>
> **Kdo obrázek uvidí.** Lidé, kteří mohou projekt upravovat. Tým WBoost jen při řešení potíží, nebo když
> sken ohodnotíte jako nepovedený či nám napíšete.
>
> **K čemu slouží.** Jen k vytvoření vašeho návrhu. *[Anthropic data zaslaná přes API nepoužívá
> k trénování modelů.]* My vaše obrázky nepoužíváme k trénování ani k testování služby.
>
> **Jak dlouho je držíme.** My 30 dní od skenu — i když sken zrušíte nebo se nepodaří; potom
> obrázek i nepřijatý návrh smažeme. *[Anthropic do 30 dnů; déle, nejvýše 2 roky, jen když obsah
> vyhodnotí jako porušení svých pravidel.]*
>
> **Co nenahrávat.** Grafiku, ke které nemáte práva, a obrázky s citlivými osobními údaji.

Later scans (both statements already given for the current wording) — two plain lines with an
info icon in place of the checkboxes, nothing to tick: `Obrázek a údaje z něj odešleme ke
zpracování službě Claude společnosti Anthropic (USA).` *[`Anthropic je maže do 30 dnů.`]* `My
uchováme původní obrázek 30 dní.` [Jak s obrázky nakládáme] · `Spuštěním potvrzujete, že tuto
grafiku smíte použít.`

**Queue line** — shown only when the service works and somebody is ahead:

| Situation | Text |
|---|---|
| Others ahead | `Před vámi je 1 sken · jsou {n} skeny · je {n} skenů. Začneme asi za {a}–{b} min, hotovo asi za {c}–{d} min.` |
| Service waiting (scanner absent, provider blocked, paused) | button disabled; `AI sken je dočasně nedostupný. Víme o tom. Zkuste to později.` |

**Credit line** under the button: `Spotřebuje 1 sken · v projektu tento měsíc zbývá {n} z {limit}` ·
without a limit `Spotřebuje 1 sken` · last one `Spotřebuje poslední sken projektu v tomto měsíci`.

Primary `Spustit rozpoznávání` (lavender, sparkle). Disabled reasons:

| Why | Hint |
|---|---|
| Consent missing | `Nejdřív potvrďte oba souhlasy.` |
| The project already has 4 scans in progress | `V projektu už běží nebo čekají 4 skeny. Počkejte, až některý doběhne.` + `Zobrazit` |
| Monthly limit | `Projekt má skeny pro tento měsíc vyčerpané. Další budou od 1. {měsíce}.` |
| Daily starts | `Dnes už jste spustili 10 skenů. Zítra můžete pokračovat.` |
| Global queue full | `Fronta je teď plná. Zkuste to za chvíli.` |
| Three pictures of the project were refused by the AI today | `AI dnes odmítla tři obrázky z tohoto projektu. Další skeny půjdou spustit zítra.` |
| Service waiting | as above |

### S3 — Waiting: kontrola → fronta → sken

One screen, three phases. Left: the pictures. Right: the status card (first under 1000 px).
A visually hidden live region announces only phase changes, stage changes and the end; the
ticking time and the list are outside it. Tab title: `Kontrola… — {název}` → `Ve frontě — {název}`
→ `Sken běží — {název}` → `✓ Návrh je připravený — {název}`.

```
┌──────────────────────────────────────────────┐  ┌──────────────────────────────┐
│ VÁŠ OBRÁZEK            PRŮBĚŽNÝ NÁHLED       │  │ Rozebírám grafiku…           │
│ ┌──────────────────┐   ┌──────────────────┐  │  │ 0:47 · hotovo asi za 1–2 min │
│ │  původní obrázek │   │ nalezené texty   │  │  │ ▓▓▓▓▓▓▓▓░░░░░░░░░░░░░░░░░░░  │
│ │                  │   │ vyznačené na     │  │  │ ✔ Kontrola obrázku       6 s │
│ │                  │   │ původním obrázku │  │  │ ✔ Čtení textů            3 s │
│ │                  │   │ → později náhled │  │  │   Nalezeno 12 textů          │
│ └──────────────────┘   └──────────────────┘  │  │ ◔ Rozbor grafiky             │
└──────────────────────────────────────────────┘  │   Porovnávám písma           │
                                                  │ ○ Sestavení šablony          │
                                                  │ ○ Porovnání a doladění       │
                                                  │ ○ Dokončení                  │
                                                  │ ┌──────────────────────────┐ │
                                                  │ │✓ Můžete odejít — dáme    │ │
                                                  │ │  vědět.                  │ │
                                                  │ └──────────────────────────┘ │
                                                  │ [← Zpět na šablony] Zrušit sken│
                                                  └──────────────────────────────┘
```

Phone: status card first — title, time, bar, the current stage with its note, the full stage list
**inside the card** collapsed under `Průběh` (a disclosure, closed by default under 641 px, open
above), leave box, buttons — then the two pictures side by side.

**Stages** — exactly what the scanner reports; `note` is a code with integer / enum parameters,
turned into Czech here. A stage without a note shows none.

| `stage` | List label (Š / K) | Title | Notes the event can carry |
|---|---|---|---|
| `check` | `Kontrola obrázku` | `Kontroluji obrázek…` | — |
| `read` | `Čtení textů` / `Čtení kalendária` | `Čtu texty…` / `Čtu dny a čísla…` | `texts_found {n}` → `Nalezen 1 text · Nalezeny {n} texty · Nalezeno {n} textů`; `rows_found {n}` → `{n} řádků dní` |
| `analyse` | `Rozbor grafiky` / `Rozbor stránky` | `Rozebírám grafiku…` / `Rozebírám stránku…` | `zooming` → `Prohlížím detail`; `measuring` → `Měřím barvy a tvary`; `fonts` → `Porovnávám písma`; `provider_retry {n, of}` → `Služba AI je vytížená, zkoušíme to znovu ({n}/{of})` |
| `build` | `Sestavení šablony` / `Sestavení stránky` | `Sestavuji šablonu…` / `Sestavuji stránku…` | — (the first rendered preview appears) |
| `refine` | `Porovnání a doladění` | `Porovnávám s originálem…` | none; the pill `kolo {n} ze 2` comes from the status field `round` |
| `finish` | `Dokončení` | `Dokončuji…` | — |

- **Preview pane.** After `read` it shows the original with the found text lines outlined
  (`Tyto texty jsme našli`) — the first real evidence, a few seconds in. From `build` it shows the
  latest rendered preview (`previewSeq` increments; cross-fade 240 ms); places for pictures show
  the original's own pixels, dimmed, tagged `místo pro obrázek` (as in S4).
- **Bar**: finished stages only; peach; no percentage.
- **Time**: elapsed, ticking in the browser; beside it `hotovo asi za {c}–{d} min` from the server,
  or `obvykle 1–3 min` when the server has no estimate.
- **Leave box**: `**Můžete odejít — dáme vědět.** Sken běží na serveru. Návrh najdete v Šablonách;
  když tu nebudete, pošleme e‑mail.` (Kalendáře: `…u tohoto kalendáře…`)
- **No lavender button** on this screen.

**Phase texts in the status card** (they replace the stage list while not `running`):

| Condition | Card |
|---|---|
| `checking` | `Kontrolujeme obrázek…` · `Trvá to pár vteřin. Ověřujeme, že z něj půjde udělat návrh.` |
| `queued`, others ahead | `Čeká ve frontě` · `Před vámi je 1 sken · jsou {n} skeny · je {n} skenů (jeden právě běží).` · `Začneme asi za {a}–{b} min, hotovo asi za {c}–{d} min.` |
| `queued`, nobody ahead, scanner healthy | `Čeká ve frontě` · `Jste na řadě — začneme během chvíle.` |
| `checking` / `queued` + `waiting` (scanner absent, provider blocked, paused) | `Sken čeká` · `AI sken teď nepracuje. Víme o tom. Váš sken počká ve frontě nejdéle hodinu — dáme vědět, jak dopadne. Nezapočítá se, pokud ho nestihneme.` **No position, no estimate, no "Jste na řadě".** |
| `queued` after `restarted` | `Sken se přerušil, spouštíme ho znovu.` · the stage list resets, the last preview stays, the elapsed time keeps running |
| estimate exceeded while `queued` | `Sken před vámi trvá déle než obvykle. Jste stále {n}. v pořadí.` |
| `running` > 3 min | under the bar: `Trvá to déle než obvykle — nejdéle asi 12 minut. Klidně odejděte, dáme vědět.` |

**Estimates** (server-side, `architecture.md` §5.1): `queuePosition` = scans ahead counting the
running one; start = what the running scan still needs + the usual duration of each scan ahead, by
kind; finish = start + own kind's duration. "Usual" = the middle half of recent finished runs,
failed ones included; a run on its automatic restart adds one full run limit. Always a range in
whole minutes, upper bound rounded up; under one minute `během chvíle`. Computed by the server, at most
15 s old; the browser never counts down. Null — and not shown — while `waiting`.

**Cancel** (`Zrušit sken`, ghost) — confirm dialog `Zrušit sken?`, buttons `Pokračovat ve skenu` ·
`Zrušit sken` (danger):

| When | Text |
|---|---|
| `checking` / `queued` | `Sken se zastaví a nezapočítá se. Obrázek u nás zůstane 30 dní, můžete ho použít znovu.` |
| `running` | `Sken už běží. Zastavíme ho, ale započítá se. Obrázek u nás zůstane 30 dní.` |

**Finish**: the page moves to S4 by itself, focus on the review heading, toast `Návrh je připravený`.

### S4 — Review ("Kontrola")

```
┌──────────────────────────────────────────────┐  ┌──────────────────────────────┐
│ (Posuvník | Vedle sebe)        ○ Ukázat vrstvy│ │ Hotovo: 12 textů, 3 tvary,   │
│        ┌──────────────┬───────────────┐      │  │ 4 místa pro obrázky.         │
│        │ Původní      │   Návrh       │      │  │ Zbývají rozhodnout 2 věci.   │
│        │ obrázek     ‹│›  šablony     │      │  │ K rozhodnutí 2 · Vrstvy 19   │
│        └──────────────┴───────────────┘      │  │ ┌──────────────────────────┐ │
│ T Texty 12 · O Obrázky 4 · Tv Tvary 3 · P 1  │  │ │[výřez pův.][výřez návrh] │ │
│ V místech pro obrázky je vidět původní       │  │ │A Písmo nadpisů jsme      │ │
│ obrázek — do šablony se nepřenese.           │  │ │  nahradili               │ │
└──────────────────────────────────────────────┘  │ │  V projektu jsme stejné  │ │
                                                  │ │  písmo nenašli. Použili  │ │
                                                  │ │  jsme nejbližší: Outfit. │ │
                                                  │ │ [Vybrat jiné písmo ▾]    │ │
                                                  │ │ [↑ Nahrát správné písmo] │ │
                                                  │ └──────────────────────────┘ │
                                                  │ ▸ Pro informaci (3)          │
                                                  │ Jak se sken povedl? 👍 👎    │
                                                  └──────────────────────────────┘
┌──────────────────────────────────────────────────────────────────────────────────┐
│ Návrh se stane šablonou, až ho vytvoříte. Uchováme ho do 12. 12. 2026.           │
│ ✎ [Výsledek zápasu U19   ]   Zahodit  [↻ Naskenovat znovu]  [Pokračovat na obrázky →]│
└──────────────────────────────────────────────────────────────────────────────────┘
```

Phone: summary line → viewer → the panel (each item with its own crops, so the viewer need not be
in view) → action block (not sticky). Desktop: the panel scrolls inside itself (`max-height`); the
action bar is sticky and never covers it.

**Viewer.** Two modes and one toggle — there is no third "overlay" mode (`Překrytí` stays cut;
the outlines of `Ukázat vrstvy` are the only thing drawn over the pictures). Modes (`.seg.soft`, remembered per browser): `Posuvník` (one frame, handle with a
≥ 36 px focus target, `role="slider"`, ←/→ 5 %, Home / End, `aria-valuetext="52 % původní"`) and
`Vedle sebe`. Touch: `touch-action: pan-y`; the slider moves only when dragged by its handle, so
the page scrolls. Labels `Původní obrázek` / `Návrh šablony` (Kalendáře: `Vaše stránka` /
`Návrh rozvržení`).

- **Places for pictures show the original's own pixels** (dimmed 60 %, dashed outline, tag
  `místo pro obrázek`), drawn by the browser from the original — on screen only. Caption under the
  viewer: `V místech pro obrázky je vidět původní obrázek — do šablony se nepřenese.` A filled
  place shows the chosen picture.
- `Ukázat vrstvy`: neutral outlines with a letter tag (`T` texty, `O` obrázky, `Tv` tvary, `P`
  pozadí) and counts in the legend (a type with no layer is left out of the legend); colour only on
  the highlighted one. Hover or focus of an item,
  a layer row or a picture card highlights its box.
- **Summary line** (top of the panel, 15 px): `Hotovo: {texty}, {tvary}, {místa pro obrázky},
  pozadí. {Zbývá rozhodnout …}` — forms, zero cases and the background rule in §2.4, e.g.
  `Hotovo: 2 texty, 2 místa pro obrázky, pozadí. Zbývají rozhodnout 4 věci.` Under it, muted:
  `Tohle je návrh — šablona zatím neexistuje.` (Kalendáře: `…kalendář se zatím nezměnil.`) No
  similarity number.

**Tab `K rozhodnutí {n}`** — only things the user can change; the counter counts only these.
Nothing blocks creating. Every item: the two crops, a bold statement, one sentence, buttons; a
decided item collapses to one line with `Vrátit`.

| Item | Statement and sentence | Actions |
|---|---|---|
| Font: nearest available | `Písmo {skupiny} jsme nahradili` · `V projektu jsme stejné písmo nenašli. Použili jsme nejbližší: {řez}. Tvary písmen se od obrázku liší; velikost jsme upravili o {x} %, aby řádky vyšly stejně. Týká se 1 textu · {n} textů.` (`{x}` = 0: `…liší; velikost zůstala stejná.`) | `Vybrat jiné písmo ▾` (project faces in their own typeface; re-fits and re-renders, ≈ 2 s; on a slow render the choice is kept and the preview says `Náhled se nepodařilo obnovit` · `Zkusit znovu`) · `Nahrát správné písmo` (TTF / OTF / WOFF → affected texts switch and are re-fitted) · `Nechat` |
| Font: could not be determined | `Písmo {skupiny} se nepodařilo určit` · `Textu je málo nebo je písmo příliš ozdobné, takže ho s písmy projektu nejde porovnat. Použili jsme {řez}. Týká se 1 textu · {n} textů.` | the same three |
| Doubtful text | `Přečetli jsme to správně?` · `Tímto textem si nejsme jistí.` + one crop of the original + input with the transcription | `Potvrdit` (Enter) |
| Left out | `1 prvek jsme vynechali · {n} prvky · {n} prvků` · `Nepodařilo se ho · je převést na text ani tvar.` Then **one row per thing**: a crop of the original at that spot (the crop says which thing it is — there is no free-text description) | per row `Přidat místo pro obrázek` · `Nechat být` |

**The three font patterns** (the only ones; S4 and C4 use them identically):

| Pattern | When | Where | Copy |
|---|---|---|---|
| **Measured match** | a face of the project, rendered, is measurably close to the text in the picture | not a decision: the `Vrstvy` row of the text and one line in `Pro informaci` | `Písmo {skupiny}: {řez} z projektu — změřená shoda s obrázkem.` |
| **Nearest available** | no face of the project is close; the closest one was used and the mismatch measured | decision item above | as above — it states that the shapes differ and by how much the size was changed |
| **Could not be determined** | too little text, or lettering no face can be compared with | decision item above | as above |

Never shown: a name for the original's typeface (`Vypadá jako …`), a similarity percentage, or
"v projektu není". The scanner's guess of a family name is not part of the proposal the UI reads.

**`Pro informaci ({n})`** — collapsed, never counted, no buttons except `Vrátit` where an automatic
change can be undone:

| Line | Undo |
|---|---|
| `Zmenšili jsme písmo u 1 textu · {n} textů, aby se vešly jako v originále.` | `Vrátit` |
| `1 barvu · {n} barvy · {n} barev jsme sjednotili s paletou projektu.` + swatch pairs | `Vrátit přesné barvy` |
| `Písmo {skupiny}: {řez} z projektu — změřená shoda s obrázkem.` (one line per group; the measured-match pattern) | — |
| `Zjednodušili jsme 1 věc · {n} věci · {n} věcí:` (closed list) `Stín pod nadpisem jsme vynechali.` · `Otočený štítek je narovnaný.` · `Obrys písma neumíme — písmo je plné.` · `Rozestupy písmen jsou jen přibližné.` · `Dvoubarevný řádek je rozdělený na {n} texty · {n} textů.` · `Přechod barev v pozadí je jen přibližný.` · `Vzor nebo textura má své místo pro obrázek — na tvary ji nepřevádíme.` | — |
| `Obrázky z nahrané grafiky nepřebíráme. Každý má v šabloně své místo — doplníte je v dalším kroku.` | — |

Nothing replaced: `Nic jsme nenahrazovali` / `Žádné písmo, text ani prvek jsme nemuseli nahradit
ani vynechat. Přesto si návrh porovnejte s originálem.`

Unfinished (`nedokončeno`, only when a run stopped at its own time limit and kept its best
attempt; a run cut short mainly by the provider is F5 instead and is not counted):
`.alert.c-warning` — `Sken jsme nestihli doladit` / `Zastavili jsme ho po dosažení časového limitu
a nechali nejlepší verzi. Sken se započítal.` Information only.

**Tab `Vrstvy {n}`** — every element in stack order: letter tag, name (inline rename; for a text it
is the field label on the fill page), and

| Row | Editable here |
|---|---|
| Text | **the text itself** (inline input; doubtful ones are marked with a peach dot and listed in "K rozhodnutí" too) and a switch `vyplňuje uživatel` / `pevný text` (default: user fills) |
| Picture place | name only — kind, fit and content are decided in S4b |
| Shape / background | `tvar · #13294F` / `barva · #0E1E3A` (read-only) |

Hint: `Zapnutý přepínač = pole, které uživatel vyplní před exportem. Polohy a velikosti doladíte
v editoru.`

**Feedback**: `Jak se sken povedl?` + `Dobře` / `Špatně` (icons `thumb-up`, `thumb-down`). After 👎
one optional field `Co se nepovedlo? (nepovinné)` + `Odeslat`. Toast `Díky za hodnocení`.

**Action bar** (`.as-foot`):

| Control | Šablony | Kalendáře |
|---|---|---|
| Line above | `Návrh se stane šablonou, až ho vytvoříte. Uchováme ho do {datum}.` | `Kalendář se nezmění, dokud rozvržení nepoužijete. Návrh uchováme do {datum}.` |
| Name | `Název šablony`, prefilled from the picture's headline text, else the file name | — |
| Ghost danger | `Zahodit` | `Zahodit` |
| Tertiary | `Naskenovat znovu` | `Naskenovat znovu` |
| **Primary** | `Pokračovat na obrázky →` when there are places for pictures, else `Vytvořit šablonu a otevřít editor →` | same rule; `Použít rozvržení →` |

- `Zahodit` → `alertdialog` `Zahodit návrh?` · `Návrh se smaže a sken zůstane započítaný. Obrázky,
  které jste k němu nahráli, přesuneme do Koše v galerii.` (Kalendáře: `…Kalendář se nezmění.`) ·
  `Zrušit` · `Zahodit`. Toast `Návrh zahozen`.
- `Naskenovat znovu` → dialog: `Nový sken může dopadnout jinak, ne nutně lépe.` Options
  `Stejný obrázek, jiný formát` (Šablony) · `Stejný obrázek znovu` · `Nahrát lepší obrázek`.
  `✦ Spotřebuje další sken · v projektu zbývá {n} z {limit}`. A missing font is **not** a reason to
  rescan — the font item fixes it for free.
- Leaving loses nothing: fixes are saved as they are made (`Uloženo`). Two tabs: last change wins
  per item; creating twice makes one template.

### S4b — Places for pictures ("Fotky a loga")

Skipped when the scan found no picture. The pictures are the sticky column; cards scroll.

```
┌──────────────────────────────────────────────┐  ┌──────────────────────────────┐
│ NÁVRH ŠABLONY (číslovaná místa)              │  │ Fotky a loga       2 ze 4    │
│ ┌──────────────────────────────────────────┐ │  │ ┌ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─┐ │
│ │  [1]                                     │ │  │  ↑ Přetáhněte sem víc       │
│ │            [2]                           │ │  │ │ obrázků najednou         │ │
│ │   [3]                         [4]        │ │  │ └ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─┘ │
│ └──────────────────────────────────────────┘ │  │ ┌──────────────────────────┐ │
│ Čísla ukazují, kam který obrázek patří.      │  │ │[výřez] 1 Logo  140×60 px │ │
│ V prázdných místech je vidět původní obrázek.│  │ │ (Součást šablony|Mění se)│ │
└──────────────────────────────────────────────┘  │ │ Použili jsme logo projektu│ │
                                                  │ │ (Vejít celý|Vyplnit) Změnit ⋯│
                                                  │ └──────────────────────────┘ │
                                                  │ ┌[výřez] 2 Fotka na pozadí ┐ │
                                                  │ │ Mění se při každém exportu│ │
                                                  │ │ [Přidat ukázku] Bez ukázky ⋯│
                                                  │ └──────────────────────────┘ │
                                                  └──────────────────────────────┘
┌──────────────────────────────────────────────────────────────────────────────────┐
│ ← Zpět na kontrolu        [Vytvořit šablonu · 2 obrázky doplníte později →]      │
└──────────────────────────────────────────────────────────────────────────────────┘
```

Phone (under 641 px): the multi-drop zone, then cards (each with its crop of the original, so the
big picture is not shown), then the action block. 641–1000 px: the numbered picture first, not
sticky (§3).

| Element | Copy / behaviour |
|---|---|
| Heading | `Fotky a loga` + `{hotovo} z / ze {n}` |
| Multi-drop | `Přetáhněte sem víc obrázků najednou` — files go to the empty cards in order; each card then offers `Patří jinam ▾` to move its picture to another place. `Vybrat víc z galerie` does the same from the gallery picker (opened in the folder used last) |
| Card title | number, name from the scan (`Logo`, `Fotka na pozadí`, `Ikona`, `Vzor 1` — inline rename), size `{w} × {h} px`, crop of the original at that spot |
| Kind (`.seg.soft`, only here) | `Součást šablony` (logo, icon, pattern — one picture for good) · `Mění se při každém exportu` (photo — a field on the fill page). Preset from the scan. A full-page background photo is always the second kind, with the note `Pozadí přes celou šablonu se vyplňuje při exportu.` |
| Pre-filled logo | a place the scan named a logo is filled with the project's logo when the project has one as a reusable file: `Použili jsme logo projektu` · `Změnit`. (Today the project stores only an icon; if no reusable logo file is found when this is built, the pre-fill is left out.) |
| Empty, `Součást šablony` | `Vybrat z galerie` · `Nahrát` (gallery folder `AI sken / {název}`) |
| Empty, `Mění se…` | `Přidat ukázku` (optional sample) · `Bez ukázky`. **Full-page background:** `Přidat ukázku` · `Nechat šedé pozadí` — a background always carries a picture, so without a sample it shows the grey stand-in until a user fills it |
| Fit (filled cards) | `Vejít celý` / `Vyplnit` — default `Vejít celý` for logos and icons, `Vyplnit` for photos, patterns, illustrations. The preview updates at once |
| Filled | thumbnail of the chosen picture next to the crop of the original, mint check, `Změnit` · `Odebrat obrázek` — also on a pre-filled logo, because the scan may have called something a logo that is not one (a pictogram); seeing both thumbnails side by side is how the user notices |
| Too small | `Obrázek je menší než místo v šabloně ({w} × {h} px) — v exportu bude neostrý.` (peach) |
| Menu `⋯` | `Smazat místo pro obrázek` · `Sloučit s dalšími…` (the cards get checkboxes and a bar `Sloučit {n} míst` · `Zrušit`; the result is one place over their common box) · `Změnit na tvar ▾` (`Obdélník` / `Kruh` / `Hvězda`, in the colour measured there). Each undoable until the template is created |

**One primary**, whose label states the consequence: all filled → `Vytvořit šablonu a otevřít
editor →`; some `Součást šablony` places empty → `Vytvořit šablonu · 1 obrázek doplníte později ·
{n} obrázky · {n} obrázků`. Below it, while something is empty: `Prázdná místa zůstanou v šabloně
šedá. Doplníte je v editoru — při exportu vás na ně upozorníme.` Tertiary `Zpět na kontrolu`.

Kalendáře lists only `Logo`, `Obrázek v hlavičce`, `Obrázek na pozadí` (always `Součást`, fit
choice applies; no kind segment, no `⋯` menu, no merge). A place left empty stays an element
without a picture — step 3 shows it empty and it is filled there. A picture chosen here is kept with the scan and becomes a calendar picture only
when the layout is applied. The page photograph is content of each week and is not asked for.

### S5 — Stopped or failed

Same frame as S3; the status card becomes the result card, the picture stays, finished stages stay
ticked, the failing one gets a rose ✖. Phone: a 96 px thumbnail above the card.

```
│ (!) Sken se nepodařil                │
│ Služba AI teď neodpovídá. Váš obrázek│
│ máme uložený — stačí to zkusit znovu.│
│ Sken se nezapočítal.                 │
│ ✔ Kontrola obrázku · ✔ Čtení textů   │
│ ✖ Rozbor grafiky   (rose: failed)    │
│ [↻ Zkusit znovu]  Vybrat jiný obrázek│
│ Zahodit                              │
```

Every such card has `Zahodit` (ghost) — the scan leaves the lists; without it the card disappears
by itself after 30 days.

**Stage rows on the result card.** `failed` / `refused`: finished stages ticked, the failing one a
rose ✖ (`x-circle`). **`stopped` (pre-check, F3a–F3e) is not a failure:** one neutral row
`Kontrola obrázku — zastaveno` with the `info` icon in `--text-2`, no rose anywhere on the card,
icon circle and pill neutral; the stages that never ran are not listed. F7q (never started) shows
no stage rows.

| # | Case | Title | Text | Actions | Counted |
|---|---|---|---|---|---|
| F3a | Photo of a print (pre-check) | `Tohle vypadá jako fotka tisku` | `Fotka z ruky zkreslí rozměry i barvy, výsledek bude jen přibližný. Nejlépe funguje export nebo snímek obrazovky.` | `Nahrát jiný obrázek` (primary) · `Zkusit přesto` (tertiary; under it `Spotřebuje 1 sken · v projektu zbývá {n} z {limit}. Jde to jen jednou.`; disabled with the S2 hints when a limit or the queue stands in the way; offered once per upload — the scan then goes straight to the queue) | no |
| F3b | Blurry / too small | `Obrázek je příliš rozmazaný` | `Texty v něm nepřečteme. Nahrajte ostřejší nebo větší verzi.` | `Nahrát jiný obrázek` | no |
| F3c | Not the expected thing | K: `Tohle nevypadá jako stránka kalendáře` · Š: `V obrázku jsme nenašli grafiku` | K: `Potřebujeme jednu týdenní stránku s fotografií a dny v řádcích.` Š: `Obrázek je prázdný nebo je to jen fotografie bez textů a tvarů.` | `Nahrát jiný obrázek` | no |
| F3d | Several designs | `V obrázku je víc grafik` | `Z jednoho obrázku umíme udělat jednu šablonu. Ořízněte ho na jednu grafiku.` | `Nahrát jiný obrázek` | no |
| F3e | File cannot be processed | `Tento obrázek neumíme zpracovat` | `Soubor je poškozený nebo v neobvyklém formátu. Uložte ho znovu jako PNG nebo JPG.` | `Nahrát jiný obrázek` | no |
| F4 | Provider refused the content | `Tento obrázek AI odmítla zpracovat` | `Služba ho vyhodnotila jako obsah, se kterým nepracuje. Pokud si myslíte, že jde o omyl, napište nám.` | `Nahrát jiný obrázek` · `Napsat nám` | no |
| F5 | Provider not answering | `Sken se nepodařil` | `Služba AI teď neodpovídá. Váš obrázek máme uložený — stačí to zkusit znovu.` | `Zkusit znovu` · `Vybrat jiný obrázek` | no |
| F5r | Renderer not answering | `Sken se nepodařil` | `Náhled se teď nepodařilo vykreslit. Váš obrázek máme uložený — zkuste to znovu.` | same | no |
| F6 | Run limit, no usable attempt | `Sken trval příliš dlouho` | `Zastavili jsme ho, protože trval mnohem déle než obvykle. Pomůže jednodušší nebo menší obrázek.` | same | no |
| F7 | Interrupted twice | `Sken se přerušil` | `Na naší straně se něco pokazilo. Váš obrázek máme uložený.` | `Zkusit znovu` | no |
| F7q | Waited 60 min in the queue | `Sken jsme nestihli spustit` | `Čekal ve frontě déle než hodinu, proto jsme ho zastavili. Váš obrázek máme uložený — zkuste to znovu.` | `Zkusit znovu` | no |
| F8 | Could not be built | `Návrh se nepodařilo sestavit` | `Obrázek jsme přečetli, ale návrh se nepodařilo poskládat. Dali jsme o tom vědět vývojářům.` | `Zkusit znovu` · `Vybrat jiný obrázek` | no |
| F9 | Retry not possible | the card keeps its F5–F8 title and text | twice failed (also shown when the same file is uploaded again): `Zkusit znovu` is **removed** and the line `Tento obrázek se nám nedaří zpracovat. Zkuste jiný.` stands in its place · limit / queue / service: `Zkusit znovu` stays, **disabled**, with the matching S2 hint under it as a plain `.hint` with the `info` icon (a limit is not a failure — no rose) (`Projekt má skeny pro tento měsíc vyčerpané. Další budou od 1. {měsíce}.` etc.); it becomes active again when the reason passes | `Vybrat jiný obrázek` | — |
| F15 | Creating fails | toast `.err` | `Šablonu se teď nepodařilo vytvořit. Zkuste to za chvíli.` / the review reopens with `Písmo {název} už v projektu není` · `Obrázek {název} už v galerii není` as items | `Zkusit znovu` / fix | — |
| F12 | AI sken switched off for the project meanwhile | info line on the scan | `AI sken je pro tento projekt vypnutý. Rozpracované návrhy můžete dokončit, nové skeny spustit nejdou.` | review and create still work | — |

`Zkusit znovu` reuses the stored picture and options; shown in history as `2. pokus`, `3. pokus`.

---

## 5. Šablony — "Šablona z obrázku"

Own page: `…/project/{id}/sablony/z-obrazku` (new) and `…/z-obrazku/{scanId}` (one URL per scan).
Head: back row `← Šablony` with `Historie skenů` at its right; h1 `Šablona z obrázku` + `✦ Premium`;
lead **on step 1 only**: `Nahrajte hotovou grafiku a AI z ní poskládá upravitelnou šablonu — texty,
tvary, barvy a místa pro obrázky. Trvá to obvykle 1–3 minuty a nemusíte u toho čekat.` Position
label instead of a second stepper design, as the last line of `.head-main` under the h1 (13 px,
600, `--muted`, the numbers in `--text`; the eyebrow stays `Projekt · {projekt}`): `Krok 3 ze 4 · Kontrola` (steps `Nahrání` ·
`Rozpoznávání` · `Kontrola` · `Fotky a loga`; the fourth only when there are pictures).

### T1 — Card in "Nová šablona"

The fourth card (`.tp-type.prem`). Copy: `Nahrajte hotovou grafiku (JPG nebo PNG) a AI z ní
poskládá upravitelnou šablonu — texty, tvary, barvy a místa pro obrázky.` (departs from the
brief's "obrázky i barvy" on purpose: pictures are not taken over).

| State | Button | Extra line | Behaviour |
|---|---|---|---|
| **Enabled** | `Nahrát obrázek` — always present while enabled | none · `Sken běží — {stage}` · `Ve frontě · {n} před vámi` · `Návrh „{název}“ čeká na kontrolu` (name truncated to one line) as links to that scan; `Historie skenů ({n})` | stretched link to the scan page |
| **Admin on a project without AI sken** | `Nahrát obrázek` | `Projekt AI sken zapnutý nemá — vidíte ho jako administrátor.` | as enabled |
| **Locked** | `Požádat o přístup` (lock) | — | closes the create dialog and opens the request dialog (§9.1) — never a modal over a modal |
| **Request sent** | `Žádost odeslána` (disabled) | `Odesláno {datum}. Dáme vám vědět.` | inert |
| **Limit reached** | `Nahrát obrázek` (disabled) | `Projekt má tento měsíc vyčerpáno {limit} z {limit} skenů. Další budou od 1. {měsíce}.` | inert |
| **Service waiting** | `Nahrát obrázek` (disabled) | `AI sken je dočasně nedostupný. Víme o tom.` | inert |
| Before the wizard ships | `Brzy` (disabled) — today's card | — | inert |

Hover / focus of the enabled card: peach border and button (`--pch` fill, text `--on-pch`).

### T2 — "Než začneme" (S2 options)

```
Formát šablony                              podle obrázku
( Na obrazovku | Pro tisk )
[ 4:5 · 1080 × 1350 px (podle obrázku)               ▾ ]
Další rozměry přidáte později v editoru.

Písma                                           nepovinné
[A  Písma projektu · 15 řezů                  [↑ Přidat]]
Znáte písmo použité v grafice? Přidejte ho do projektu —
výsledek bude přesnější. Jinak vybereme nejbližší z projektu.
```

- `Na obrazovku`: `{poměr} · {w} × {h} px (podle obrázku)` · `{2w} × {2h} px` for small pictures ·
  `Vlastní šířka…`. `Pro tisk` (preselected within 1 % of an A-format, up to A3): `A4 na výšku ·
  210 × 297 mm` … · `Vlastní šířka…` (mm). Hint `Spadávku z obrázku nepoznáme — šablona vznikne bez
  ní a doplníte ji v editoru.` The ratio is never changed.
- Fonts row: `Písma projektu · 1 řez · {n} řezy · {n} řezů`; `Přidat` opens the fonts uploader
  inline.
- Project without a font (texts cannot be created): the row turns peach — `Projekt zatím nemá
  žádné písmo. Bez písma neumíme vytvořit texty.` · `Nahrát písma` · `Přidat písmo Outfit`; the
  start button is enabled after either.

### T4 — Creating, and after

`Vytvořit šablonu…`: the template is created under the name (uncategorised); pictures uploaded in
S4b live in the gallery folder `AI sken / {název}`; the editor opens with the toast `Šablona
„{název}“ vytvořena ze skenu — 1 prvek · {n} prvky · {n} prvků`.

After creating, the scan page is **read-only**: the comparison, the report as a checklist, and per
open item a link `Otevřít v editoru`. No fix can be applied from it any more. The template's list
card carries a neutral pill `Ze skenu` for 7 days, linking there.

**Unfilled pictures** (`Součást šablony` places left empty): peach pill `K doplnění: {n}` on the
template card and in the editor's layers panel. Web export asks first — `Šablona má 1 nedoplněný
obrázek · {n} nedoplněné obrázky · {n} nedoplněných obrázků` / `V exportu bude · budou na jejich
místě šedé zástupné obrázky. Doplňte je v editoru, nebo exportujte přesto.` · `Otevřít editor`
(primary) · `Exportovat přesto`. Exports over the API and MCP: owner decision O11 — recommended:
refused with the code `unfilled_pictures` unless the caller passes `allowUnfilled`. An empty
`Mění se…` place without a sample needs no guard: it is a field the user fills at export. The
one exception is a full-page background left grey: an export that supplies no picture for it is
treated like an unfilled picture (same dialog, same rule on the API and MCP).

### T5 — List strip

Above the categories: cards of this project's scans that are not finished with, under a small
heading `✦ Skeny {n}` with `Historie skenů` at its right (the strip is not shown when there is no
card).

```
┌────────────────────────────┐ ┌────────────────────────────┐ ┌────────────────────────────┐
│ ┌──────┐ Výsledek zápasu…  │ │ ┌──────┐ letak-podzim.png  │ │ ┌──────┐ plakat.jpg        │
│ │ ░✦░░ │ ◔ Rozbor grafiky  │ │ │ náhl │ ● Připraveno      │ │ │ náhl │ ✖ Nepodařilo se   │
│ └──────┘ 0:47              │ │ └──────┘   vyprší 12. 12.  │ │ └──────┘ Zkusit znovu      │
│          Zobrazit průběh   │ │          [Zkontrolovat]    │ │          Zahodit           │
└────────────────────────────┘ └────────────────────────────┘ └────────────────────────────┘
```

| State | Card |
|---|---|
| `checking` | thumbnail, `Kontrola obrázku…` |
| `queued` | `Ve frontě · {n} před vámi · asi za {a}–{b} min` — or `Sken čeká — AI sken teď nepracuje` when `waiting` |
| `running` | sweep, stage label, elapsed time, `Zobrazit průběh` |
| `ready` | proposal thumbnail, `Připraveno ke kontrole`, `vyprší {datum}`, `Zkontrolovat`. Others see `Sken spustil(a) {jméno}` and may open it |
| `stopped` | dimmed, `Potřebuje jiný obrázek`, `Zobrazit` · `Zahodit` — for other editors only 24 h |
| `failed` | dimmed, `Nepodařilo se`, `Zkusit znovu` · `Zahodit` — for other editors only 24 h |
| `refused` | dimmed, `Odmítnuto`, `Zobrazit` · `Zahodit` — for other editors only 24 h |
| `accepted`, `discarded`, `cancelled`, `expired` | no card |

Empty list: second CTA `Vytvořit z obrázku ✦` (when enabled).

---

## 6. Kalendáře — "Naskenovat design"

Host: wizard step 2 "Rozvržení", weekly calendars only. Designers only (never on the client link).

### C1 — Action card under the layout choice

The two layout cards stay a radiogroup. The scan entry is **a separate action card outside it** —
not a radio, one button.

```
( Fotka vlevo ) ( Fotka vpravo ) [ ( Naskenovaný design ) — only when the layout came from a scan ]

┌──────────────────────────────────────────────────────────────────────────────────┐
│ ✦  Naskenovat design  ✦ Premium                               [ Nahrát obrázek ] │
│    Nahrajte obrázek stránky svého kalendáře. AI rozpozná, kde je fotografie,     │
│    kalendárium, hlavička a logo, a navrhne stejné rozvržení.                     │
└──────────────────────────────────────────────────────────────────────────────────┘
```

| State | Button | Line under the text |
|---|---|---|
| Enabled | `Nahrát obrázek` → opens the panel | — |
| Scan in progress | `Zobrazit průběh` | `Kontrola obrázku…` · `Ve frontě · {n} před vámi` · `Sken běží — {stage} · 0:47` |
| Proposal waiting | `Zkontrolovat návrh` | `Návrh rozvržení čeká na kontrolu · vyprší {datum}` |
| Locked / request sent / limit / service waiting | as T1 | as T1 |

When the calendar's layout came from a scan (the calendar's `layout_origin` column says `scan`), a third **real**
radio `Naskenovaný design` / `Rozvržení převzaté z vašeho kalendáře` is shown selected, with a live
preview like its siblings. Choosing `Fotka vlevo` / `Fotka vpravo` then replaces the layout with
the default one (never mirrors the scanned one) and toasts `Rozvržení nahrazeno výchozím` with the
action `Vrátit`.


### C2–C3 — Panel: upload and waiting

A panel under the cards: header `✦ Naskenovat design` · `Historie skenů` · ✕. S1 and S2 inside it
(drop zone left, tips right; then preview + checks left, `Co převezmeme`, consent, queue line and
button right). No pre-scan question.

`Co převezmeme`: `Převezmeme rozmístění fotografie, kalendária, hlavičky, měsíce a loga a také
barvy. Písma vybereme z projektu. Fotky, texty a dny doplní kalendář sám.` If the calendar has a
layout: `Kalendář už rozvržení má. Sken ho nezmění, dokud návrh nepoužijete.`

Waiting = S3; the two pictures are stacked (landscape), the status card is to the right (first on
phones). The wizard stays usable. While a scan is unfinished the step-2 item in the wizard's step
row carries a peach dot (no extra chip — the top bar has no room at 390 px); a toast `Návrh
rozvržení je připravený` · `Zkontrolovat` appears in any step.

### C4 — Panel: review

```
┌──────────────────────────────────────────────────────────────────────────────────┐
│ ✦ Naskenovat design — kontrola                                                   │
│ (Vedle sebe | Posuvník)                            Co jsme rozpoznali            │
│ VAŠE STRÁNKA                                       ✔ Fotografie     vlevo, do okraje│
│ ┌──────────────────────────────────────────┐       ✔ Kalendárium    čísla vlevo  │
│ │              původní obrázek             │       ✔ Hlavička       pruh #1F3A5F │
│ └──────────────────────────────────────────┘       ✔ Měsíc          velká písmena│
│ NÁVRH ROZVRŽENÍ                                    ✔ Logo           vpravo nahoře│
│ ┌──────────────────────────────────────────┐       ~ Popisek fotky  přibližně    │
│ │        živý náhled navrženého rozvržení  │       – Číslo týdne    na stránce   │
│ └──────────────────────────────────────────┘                        jsme nenašli │
│ Náhled ukazuje týden 11.–17. 1. 2027. Fotka        K rozhodnutí 1                │
│ je z vašeho obrázku — do kalendáře se nevloží.     ┌ A Písmo čísel dnů jsme … ┐  │
│                                                    └──────────────────────────┘  │
│                                                    (i) Tohle kalendář neumí      │
│                                                    • Ozdobný pruh pod hlavičkou  │
│                                                    ☑ Zapnout čísla týdnů         │
│                                                    ☑ Zapnout popisek fotky       │
│ Kalendář se nezmění, dokud rozvržení nepoužijete. Návrh uchováme do 12. 12. 2026.│
│ Zahodit      [↻ Naskenovat znovu]                      [Pokračovat na obrázky →] │
└──────────────────────────────────────────────────────────────────────────────────┘
```

Phone: viewer in `Posuvník`, caption, checklist, decisions, switches, action block.

- **Co jsme rozpoznali** — one row per element: `Fotografie`, `Popisek fotky`, `Kalendárium`,
  `Měsíc`, `Číslo týdne`, `Hlavička`, `Logo`, `QR kód` (+ `Pozadí`). ✔ `rozpoznáno` + a fact ·
  ~ `přibližně` · – `na stránce jsme nenašli`. An element not found **keeps the calendar's current
  setting**; the scan never switches anything off.
- **Switches on only.** Features the page shows and the calendar has off are offered as ticked
  checkboxes: `Zapnout čísla týdnů`, `Zapnout popisek fotky`, `Zapnout fáze měsíce`, `Zapnout QR
  kód`. Unticked = only positions, fonts and colours are applied.
- **K rozhodnutí**: font items as in S4; `Projekt nemá žádné písmo` · `Kalendář se vykreslí
  náhradním písmem a v tisku může vypadat jinak.` · `Nahrát písma` · `Nechat`.
- **Pro informaci**: `Obrázek vypadá jako čtrnáctidenní kalendář — převzali jsme levou polovinu.` ·
  `Stránka má jiný poměr stran než váš kalendář ({š} × {v} mm) — rozvržení jsme přizpůsobili.` ·
  palette line · `Barvy jsou jen odhad` (photo of a print).
- **Tohle kalendář neumí** (closed list): `Ozdobný pruh / rámeček / ornament` · `Text v hlavičce`
  · `Druhá fotografie` · `Další loga` · `Dny v mřížce` · `Svislý nebo otočený text` · `Jiné barvy
  pro každý měsíc` · `Vícejazyčné názvy`. Each row is the list item plus a small crop of the
  original at that spot — no free text after it, so the scanner returns only the item's code and
  a box.
- The photo region of the original is shown in the browser's preview only.

### Applying — what is saved and how to get back

One lavender button in view: whenever the panel shows a primary — `Spustit rozpoznávání` with a
picture chosen, the review, the pictures — the top bar's `Pokračovat` is drawn tertiary; the
panel's button is the primary; the same holds for a result card in the panel (S5: `Nahrát jiný
obrázek`, `Zkusit znovu`). While the panel only waits (upload zone, queue, running) the top bar
keeps its lavender button. The S2 disabled-reason hints are plain `.hint` lines, never rose.

`Použít rozvržení` (label `Nahradit rozvržení` when the calendar has one; with an empty picture
place the label ends `· 1 obrázek doplníte později · {n} obrázky · {n} obrázků`, §2.4):

1. If the calendar has unsaved changes, the button is disabled: `Nejdřív uložte rozpracované
   změny.` + `Uložit`.
2. One request. The server saves the proposed layout and the ticked switches into the calendar
   as it is stored at that moment (texts or photos a client added meanwhile through the client
   link are kept and appear after the reload),
   turns the chosen pictures into calendar pictures, writes the history line `Převzal rozvržení ze
   skenu`, and keeps the **previous layout and settings with the scan until the date shown**.
3. The wizard reloads the saved calendar and opens step 3. Toast `Rozvržení převzato ze skenu` with
   the action `Vrátit původní`.

Honest limits, said in the UI: under the button `Uloží se hned. Původní rozvržení vrátíte tlačítkem
Vrátit původní — do {datum}.` (`{datum}` is the proposal's expiry date shown in the review, not 30
days from applying.) The wizard's `Zpět o krok` does **not** undo it (the step history
starts again after applying). `Vrátit původní rozvržení` is also in the scan's history row; it
saves the kept layout, layout choice and switched features back as a normal change (`Vrátil
původní rozvržení`) — nothing else: texts, photos and page content stay as they are. If the
layout or the features were changed after the scan was applied, it asks first — dialog `Vrátit
původní rozvržení?` / `Od použití skenu jste rozvržení upravili. Vrácením o tyto úpravy přijdete.
Texty, fotky a obsah stránek zůstanou.` · `Zrušit` · `Vrátit původní` (danger). Pictures
that became calendar pictures stay in the calendar (they cannot be deleted) but are no longer used.
The calendar's own change history holds sentences only and cannot restore a layout.

### C5 — Calendar list

One pill on the calendar's card: `◔ Sken běží` / `Ve frontě` (peach) · `● Návrh připraven` (peach)
· `Sken se nepodařil` (rose). Click opens step 2.

---

## 7. Leaving and coming back

| Where the user is | What they see |
|---|---|
| On the scan surface | S3, then S4 opens by itself |
| Elsewhere in the project | sidebar: a 16 px peach ring on the module item while a scan of theirs is unfinished, a peach `.count` when a proposal waits unseen or a scan failed; rail mode: an 8 px peach dot on the item's icon; phone (the sidebar is a closed drawer): the same dot on the top bar's menu button, whose accessible name gains `— sken čeká na vaši pozornost`, and the ring / count on the item inside the drawer |
| Anywhere in the app | a toast on the state change (10 s, close ✕). Pages rendered with an unfinished scan of the viewer poll every 10 s. In an editor with unsaved changes the toast's action opens the scan **in a new tab** |
| Tab in the background | tab title |
| Gone | one e-mail |
| Next visit | list card / pill, entry card line, history |

| Event | Toast | Action |
|---|---|---|
| Ready (Š) | `Návrh šablony „{název}“ je připravený ke kontrole` | `Zkontrolovat` |
| Ready (K) | `Návrh rozvržení pro „{kalendář}“ je připravený` | `Zkontrolovat` |
| Stopped | `Sken „{název}“ potřebuje jiný obrázek` | `Zobrazit` |
| Failed / refused | `Sken „{název}“ se nepodařil` (`.err`) | `Zobrazit` |
| Other project | prefix `{projekt}: ` | |

"Seen" = the requester's browser got the final state on the scan surface, or they clicked a toast
or card. It clears the badge and cancels the e-mail.

E-mails (plain transactional layout, to the requester; none when the account no longer exists;
buttons only open pages — nothing happens on a GET):

> **Návrh šablony z obrázku je připravený** — Z obrázku **{soubor}** jsme v projektu **{projekt}**
> připravili návrh šablony **„{název}“**. **[Zkontrolovat návrh]** · Zbývá rozhodnout 1 věc · Zbývají
> rozhodnout {n} věci · Zbývá rozhodnout {n} věcí. *(only when any, §2.4)* · Šablona vznikne, až návrh potvrdíte. **Návrh i původní
> obrázek uchováme do {datum}; potom je smažeme.**
>
> **Návrh rozvržení kalendáře „{kalendář}“ je připravený** — … **[Zkontrolovat návrh]** · Kalendář
> se nezmění, dokud rozvržení nepoužijete. Návrh uchováme do {datum}.
>
> **Sken potřebuje jiný obrázek** — Obrázek **{soubor}** se k rozpoznání nehodí: {věta F3}.
> Nezapočítal se. **[Nahrát jiný obrázek]**
>
> **Sken se nepodařil** — Obrázek **{soubor}** se nám nepodařilo zpracovat: {věta F4–F8}.
> Nezapočítal se. **[Otevřít sken]**

Reminder, 3 days before a waiting proposal expires (once, to the requester):

> **Návrh „{název}“ za 3 dny vyprší** — Návrh z obrázku **{soubor}** v projektu **{projekt}**
> čeká na kontrolu. {datum} ho i původní obrázek smažeme. **[Zkontrolovat návrh]**

When a proposal expires, pictures uploaded for it move to the gallery's Koš, as on discard.

---

## 8. History

Page `Historie skenů` per project. Head: `Projekt · {projekt}`, h1 + count, lead `Všechny skeny
v tomto projektu. Obrázky a nepřijaté návrhy uchováváme 30 dní.`, primary `Nový sken` (menu
`Šablona z obrázku` · `Naskenovat design kalendáře…`). Chips: `Vše` | `Šablony` · `Kalendáře` |
`Čekají na kontrolu` · `Hotové` · `Nepodařené`.

```
│ [▣] Výsledek zápasu U19      Šablony    ● Připraveno ke kontrole · vyprší 12. 12.  Jana N. · dnes 11:42  ⋯ │
│ [▣] Stolní kalendář 2027     Kalendáře  ✔ Rozvržení použito                        Petr K. · včera       ⋯ │
│ [▣] letak-podzim.png         Šablony    ✖ Nepodařilo se · 2. pokus                 Jana N. · 10. 11.     ⋯ │
```

Phone: thumbnail + name + pill, then module · who · when. Pills: §2.1.

| Row menu | When |
|---|---|
| `Otevřít návrh` | `ready`; also `accepted` while the proposal still exists (read-only) |
| `Otevřít šablonu` / `Otevřít kalendář` | `accepted` |
| `Vrátit původní rozvržení` | calendar, `accepted`, until the date |
| `Zkusit znovu` | `failed`, while retries and the picture remain |
| `Zahodit` (`.mi.danger`) | `ready`, `failed`, `stopped`, `refused`, `cancelled` |

A row whose picture and proposal are gone shows `Vypršelo` and has no menu. Empty: `Zatím tu není
žádný sken` / `Nahrajte obrázek hotové grafiky nebo stránky kalendáře a AI z něj vytvoří
upravitelný návrh.` / `Nový sken`.

---

## 9. Access and admin

Who may **start** a scan: administrators always; project editors of a project with AI sken on.
Who may **use** an existing scan (see it, fix, create, discard): the requester and every editor of
the project — also after AI sken was switched off. Read-only members see nothing of it.

**"Premium"** in v1 means: a paid extra switched on per project by WBoost on request. There is no
price list in the app; the request dialog says so. (Price and packaging: owner decision.)

### 9.1 "Požádat o přístup"

Ships with the wizard — the first moment a locked card is visible. Dialog (M, 520 px, fake-door
look, but it posts):

| Element | Copy |
|---|---|
| Title | `AI sken` |
| Lead | `Hotová grafika → upravitelná šablona za pár minut.` (Kalendáře: `Stránka loňského kalendáře → hotové rozvržení za pár minut.`) |
| Text | `AI sken je placená funkce, kterou zapínáme pro každý projekt zvlášť. Napište si o ni — ozveme se vám e‑mailem s podmínkami.` |
| Illustration | a before / after pair, caption `ukázka` |
| Field | `K čemu ho chcete použít?` · `nepovinné` (≤ 500 chars) |
| Sent with it | `Odešleme: vaše jméno a e‑mail, projekt „{projekt}“ a tuto zprávu. Dostane ji tým WBoost.` |
| Buttons | `Zrušit` · `Požádat o přístup` (primary) |
| Sent | `Žádost odeslána` · `Ozveme se vám na {e-mail}.` · `Zavřít` |

Stored (user, project, origin, message, time, status) and e-mailed to the administrators'
notification list with a deep link to the switch. One open request per user and project.

### 9.2 Admin switch — per project

In "Moduly projektu" (admin only), group `Funkce Premium`:

```
│ ✦  AI sken  ✦Premium                                   Zapnuto            (●━━)   │
│    Šablona z obrázku a naskenování designu kalendáře. Obrázky zpracovává Anthropic.│
│    Měsíční limit [ 10 ] skenů  ·  Tento měsíc 3 z 10  ·  Celkem 14  ·  Využití →   │
│ ┌ (mint) Jana Nováková žádá o AI sken · 12. 11. 2026 · „Chceme převést…“          │
│ │                                                   [Zamítnout] [Zapnout]         │
```

Switch toast `AI sken zapnut pro projekt {projekt}` / `AI sken vypnut`. Under the switch:
`Vypnutím se nic nesmaže. Čekající skeny zrušíme a nezapočítáme, běžící doběhnou a hotové návrhy
půjde dokončit.` Limit field: empty = `bez limitu`. `Zapnout` on a request switches the project on
and e-mails the requester (`AI sken je zapnutý` + `Vyzkoušet`).

### 9.3 Admin switch — from user administration

On `admin/edit_user`, section "Projekty": each owned project row gets the same switch (`✦ AI sken`)
before the `Vlastník` pill — its own request (`form=` anchor), never a nested form. Note:
`AI sken se zapíná pro projekt — smí ho pak použít každý, kdo může projekt upravovat.`

### 9.4 Admin — "AI skeny" tab on `/admin/usage`

```
( Klienti | Celý systém | Úspory | ✦ AI skeny 2 )

Provoz:  (Zapnuto | Pozastaveno | Vypnuto)    ☐ Jen administrátoři
Ve frontě 2 · nejstarší čeká 4 min · skener naposledy před 8 s (verze 1.4) · poskytovatel v pořádku
Dnes utraceno 3,10 $ z 20 $ (z toho 1,50 $ rezervováno běžícím skenem)
Denní strop [ 20 ] $ · strop projektu [ 5 ] $ · souběžně [ 1 ]

┌ (mint) Žádosti o přístup 2 … [Zamítnout] [Zapnout] ┐
┌ Projekty: Projekt · Vlastník · AI sken (switch) · Tento měsíc · Limit · Celkem · Náklady ┐
┌ Skeny: Kdy · Projekt · Uživatel · Modul · Stav · Pokus · Doba · Tokeny · Náklady · Kredit · Hodn. · Kanál · Paměť / CPU ⋯ ┐
```

- **Provoz** takes effect on the next request, without a deploy: `Pozastaveno` = no new uploads and
  no new runs, reviews and creating work; `Vypnuto` = also stops running **and waiting** scans (they fail as
  interrupted, not counted). Rose notices in the strip: `Skener neodpovídá {t}` · `Skener je příliš
  starý` · `Poskytovatel odmítá náš účet — ověřujeme každých 5 min` · `Denní strop dosažen`.
- Scans row menu: `Otevřít sken` (read-only review) · `Otevřít projekt` · `Zobrazit hodnocení`.
  The review page shows admins a link `Využití tohoto skenu`.
- Phone: tables become cards.

---

## 10. MCP

**`scan_design`** — scope `ai:scan` (new, off by default). Same rules, limits and counting as the
web; channel `mcp` in usage.

| Action | Input | Returns | Notes |
|---|---|---|---|
| `start` | `projectId`, `kind`, picture (`imageId` or small `imageBase64`), `requestId` (idempotency), template `format` / `name` or `calendarId` (pictures only) | `scanId`, `status`, `creditsLeft`, queue fields, `pollAfterSeconds` | refusals (nothing charged): `not_enabled`, `consent_required` (the user must have agreed to the current wording in the web), `limit_reached`, `daily_starts_reached`, `project_queue_full`, `queue_full`, `temporarily_unavailable`, `project_paused` (three pictures refused by the AI today), `no_fonts`, `unsupported_image`, `source_unscannable` |
| `status` | `scanId` | `status` (§2.1), `waiting` reason or null, `restarted`, `stage`, `note` + `noteParams`, `round`, `elapsedSeconds`, `queuePosition`, `estimatedStartSeconds: [from, to]`, `estimatedFinishSeconds: [from, to]` (null while waiting), `expiresAt`, `unfinished`, `errorCode` + sentence, `retriesLeft`, `pollAfterSeconds` | 1–3 minutes is normal; up to ≈ 12 |
| `result` | `scanId`, `image?` | counts, `elements[]` (type, name, text, input / fixed), `placeholders[]` (id, name, role, box, kind, fit), `decisions[]` with `fixes[]`, `information[]`, calendar `recognised[]` / `featuresOffered[]` / `notExpressible[]`, a render | `ready` or later, while the proposal exists |
| `apply` | `scanId`, `fixes[]` (`choose_font`, `confirm_text`, `set_text`, `set_input`, `add_placeholder`, `remove_placeholder`, `merge_placeholders`, `placeholder_to_shape`), `placeholders[]` (`{id, assetId \| null, kind, fit}`), `name`; calendar `features[]` to switch on | template ids + `editorUrl`, `unfilledPictures`; calendar revision + `restoreUntil` | = create / apply; idempotent |
| `restore` | `scanId` (calendar), `confirm?` | calendar revision | = "Vrátit původní rozvržení"; refused with `layout_changed` when the layout was edited since applying, unless `confirm: true` (the web's confirm dialog) |
| `list` | `projectId`, `status?` | scans | |
| `cancel` | `scanId` | `status`, `counted` | counted when it was running |
| `retry` | `scanId` (failed) | `status`, `retriesLeft` | reserves a scan; refusals as `start` + `source_unscannable`, `no_retries_left` |
| `continue` | `scanId` (stopped as a photo of a print) | `status` | once per upload; reserves a scan; goes straight to the queue (the check is not repeated); refusals as `start` |

**`create_template`** — scope `templates:design`: `projectId`, `name`, `dimension`
(`{unit, width, height, bleedMm?, cropMarks?}` or a preset), `categoryId?` → `templateId`,
`variantId`, `editorUrl`.

Any action called in a state that does not allow it answers `wrong_state`.

Web-only (owner decision O12): the admin switch, the access request, discard, the operating mode.

---

## 11. State × surface

✔ specified above; — not applicable.

| State / condition | Scan surface | List (T5 / C5) | Entry card | Shell | Toast | E-mail | MCP `status` |
|---|---|---|---|---|---|---|---|
| `checking` | S3 phase text | ✔ | line | ring | — | — | ✔ |
| `queued` | S3 queue text | ✔ | line | ring | — | — | ✔ + queue fields |
| + `waiting` | S3 "Sken čeká" | ✔ | service-waiting state for new scans | ring | — | — (failure mail if it times out) | `waiting` |
| + `restarted` | S3 note | as queued | line | ring | — | — | `restarted` |
| `running` | S3 stages | ✔ | line | ring | — | — | ✔ |
| `ready` (+ `nedokončeno`) | S4 (+ banner) | ✔ + date | line | count | ✔ | ✔ | ✔ `unfinished` |
| `accepted` | editor / step 3; scan page read-only | pill `Ze skenu` / third radio | enabled | — | ✔ | — | ✔ |
| `discarded` | back to S1 | — | enabled | — | ✔ | — | ✔ |
| `cancelled` | back to S2 with the picture | — | enabled | — | ✔ `Sken zrušen` | — | ✔ `counted` |
| `stopped` | S5 F3 | ✔ | enabled | count | ✔ | ✔ own mail | ✔ |
| `failed` | S5 F5–F8 | ✔ (24 h for others) | enabled | count | ✔ | ✔ | ✔ `retriesLeft` |
| `refused` | S5 F4 | ✔ | enabled | count | ✔ | ✔ | ✔ |
| `expired` | history row only | — | enabled | — | — | — | ✔ |
| Queue full / limits / service waiting at start | S2 hints | — | card state | — | — | — | refusal codes |
| AI sken switched off meanwhile | F12 line | ✔ | locked for new | — | — | — | ✔ |
| Creating fails | F15 | — | — | — | ✔ | — | error code |

---

## 12. Components for the mock

Existing kit used as is: `.head`, `.tp-type`, `.tp-prem`, `.gal-drop`, `.seg.soft`, `.tabs-u`,
`.inp`, `.sw`, `.cb`, `.pill`, `.alert`, `.notice`, `.hint`, `.u-card`, `.fbar`, `.empty`, buttons,
the gallery picker, the fake-door dialog shell, the calendar wizard's step row.

New, all in `ai-scan.css` (classes `as-*`), each needed at 390 / 820 / 1440 px in both themes:

| Component | Used in | Notes |
|---|---|---|
| Status card (`as-status`) | S3, S5 | title, time line, stage bar, stage list with notes, leave box; variants: checking, queued, waiting, restarted, failed. First under 1000 px |
| Preview pane with OCR outlines | S3 | original + outlined lines; later the render |
| Compare viewer (`as-compare`) | S4, C4 | two modes; handle ≥ 36 px; `touch-action: pan-y`; layer outlines with letter tags; **original pixels inside empty places**, dimmed, tagged |
| Crop pair (`as-crops`) | every decision item, picture card | two small crops cut by the browser from the two pictures |
| Decision item (`as-it`) | S4, C4 | open / decided states; `Vrátit` |
| Information list | S4, C4 | collapsed, no counter |
| Layer row with inline text edit | S4 "Vrstvy" | peach dot for doubtful texts |
| Action bar (`as-foot`) | S4, S4b, C4 | expiry line above; one primary. While it is sticky, toasts and the merge bar sit above it, and a card's `⋯` menu opens over it |
| Picture card (`as-ph`) | S4b | number, crop, kind segment, fit segment, states empty / pre-filled / filled / too small, menu |
| Multi-drop zone + reassign menu | S4b | |
| Merge mode | S4b | checkboxes on cards + sticky bar |
| Numbered overlay boxes | S4b picture | |
| Scan card | T5 | seven states |
| Action card (non-radio) | C1 | five states |
| Recognised-elements checklist | C4 | ✔ / ~ / – |
| Operating strip | admin tab | mode segment, notices, three number fields |
| Icons | — | `thumb-up`, `thumb-down` |

Tokens: one new token `--on-pch: #3A2A20` (text on a peach fill, the same in both themes), added
to `tokens.css`; `.tp-prem` switches to it; no other hard-coded colour. The e-mails keep the
colours of the existing transactional e-mail layout. Motion: one moving element per view — the
sweep over the preview pane (and over a running scan card's thumbnail); rings and spinners of the
flow are drawn static. Inline links inside the consent text get a 32 px tap area on touch devices. Focus: hidden
inputs show the ring on their label (`:has(:focus-visible)`). Segments are plain buttons with
`aria-pressed`. Report actions are 40 px high on touch devices. Reduced motion: only the ticking
time shows activity.

---

## 13. Sources

Product patterns borrowed (checked 2026-11-12): expectations before upload (Canva Magic Layers,
Kittl help pages); font substitution as a named, fixable item (Figma "Missing fonts"); credits at
the point of spending (Figma AI credits); stage messages instead of a percentage (Gamma feature
request); notification only when the user left (Leadpages, WriteText.ai). Deliberately different:
a proposal and one create button instead of dropping the result into the editor. In-house: the
calendar prototype's scan panel, the redesign prototype's "AI import", the calendar PDF export
dialog, the fake door, registration-request rows.

Owner decisions are one list: `architecture.md` §11.
