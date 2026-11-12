# AI sken — independent UX/UI review

Reviewed 2026-11-12: `docs/ai-scan/ux.md` (Rev 2), `docs/ai-scan/ux-mock/index.html`, against
`docs/ai-scan/architecture.md` (Rev 2), `CLAUDE.md`, `docs/redesign/design-system.md`,
`templates/_template_new_dialog.html.twig`, `assets/calendar/ui/{step2,topbar,export,dom}.js`,
`assets/calendar/state/store.js`.

The mock was opened in headless Chrome (puppeteer-core) at 390 / 820 / 1440 px, dark and light,
in eight states per size. 51 screenshots are in `docs/ai-scan/ux-mock/review-shots/`
(`<state>--<width>--<theme>.png`). `docs/redesign/tools/shoot.cjs` could not be used: it logs
into the app and takes app paths, not a file URL.

References: `ux:NNN` = line in `ux.md`, `arch:NNN` = line in `architecture.md`, `mock:NNN` = line
in `ux-mock/index.html`.

Counts: **4 blockers, 21 majors, 22 minors.**

---

## 1. Findings

### Blockers

**B1 — Failed, stopped and refused scans have no way out.**
- Where: T5 list card (`ux:924`), §6 (`ux:1682`, `ux:1688–1689`), history row menu (`ux:1382`),
  state diagram (`arch:520–537`).
- Evidence: the failed card offers `Zkusit znovu · Zahodit`, but the state diagram has
  `discarded` only from `ready`. `failed`, `stopped` and `refused` have no transition except a
  retry, and no expiry (only `ready → expired`). T5 removes a card only for
  accepted / discarded / cancelled (`ux:925`). T5 has no row at all for `stopped` or `refused`.
- Why it matters: a failed scan card sits above the template list for ever, and for every project
  member. After 30 days the source is deleted (`arch:551`), so `Zkusit znovu` cannot work either.
- Change: add `failed | stopped | refused → discarded` (user) and an automatic close after the
  source retention. Add the `stopped` and `refused` rows to T5. Remove the card for everyone but
  the requester after 24 hours.

**B2 — The queue text lies when nothing is being processed.**
- Where: S3 "Queue" (`ux:432–455`), F5b (`ux:1299`), `arch:510–516`, `arch:539–542`, `arch:629`,
  `arch:634`.
- Evidence: the estimate is "remaining time of the running scans, floored at 30 s, plus the scans
  ahead". With the scanner absent or the provider blocked, nothing runs, so a queued user at
  position 0 reads `Jste na řadě` and a 1–3 min estimate. Queued scans wait up to 12 hours before
  the purge fails them as `scanner_unavailable` (`arch:540`). The "estimate exceeded" copy
  (`ux:449`) assumes a scan ahead that is slow, not a dead scanner. F5b's text exists, but §6 has
  no row for it, and no list card, pill, toast or e-mail says the scan is parked.
- Also: `running → queued` after a lost lease (`arch:530`) has no UI rule. The stage list, the
  elapsed time and the intermediate preview go backwards with no explanation.
- Also: `scanner_unavailable` and `renderer_unavailable` (`arch:636`) have no sentence in §2.5.
  F5's "Služba AI teď neodpovídá" is false when Gotenberg is the cause.
- Why it matters: queue position and estimated time are an explicit owner requirement; this is the
  case where they are most wrong.
- Change: the status endpoint returns a `paused` reason (`scanner_absent | provider_blocked`). The
  queue block then shows `Skener teď nepracuje. Váš sken čeká ve frontě — dáme vědět, až doběhne.`
  with no time. Give a parked scan a deadline the user is told (for example 1 hour, then fail with
  a refund and the e-mail) instead of a silent 12 hours. On requeue show
  `Sken se přerušil, spouštíme ho znovu.` and keep the last preview. Add the two missing failure
  sentences.

**B3 — Calendar: "one undo step" does not hold, and "accepted" has no defined moment.**
- Where: C4 accept (`ux:1177–1186`), settings checkbox (`ux:1160–1169`), `arch:367–372`.
- Evidence 1: the undo snapshot is `{ layout, captions, preset }` only
  (`assets/calendar/state/store.js:178–181`). `Převzít i nastavení z obrázku` changes week
  numbers, moon phases and QR; none of them is in the snapshot. The new `layoutOrigin` key is not
  either. So `Ctrl+Z vrátí původní` restores the layout and leaves the features and the "scanned"
  marker changed.
- Evidence 2: `replaceLayout` pushes an undo step only when a layout existed and does not change
  the step (`store.js:489–507`); "wizard goes to step 3" is a second action.
- Evidence 3: accept = browser `replaceLayout`, and the save "carries `scan: <id>`"
  (`arch:369–370`). Between apply and `Uložit` the scan is still `ready`: the C1 card, the C5 pill
  and the top-bar chip keep saying `Sken připraven` although the layout is on screen. Apply → undo
  → save, and apply → leave without saving, are unspecified. Held pictures become calendar assets
  "at accept" and assets cannot be deleted (`arch:367–368`); an undo leaves them.
- Evidence 4: the wizard top bar already has a lavender `Pokračovat` (`topbar.js:74`). With the
  review panel open there are two lavender forward buttons in view, and the top one moves to step
  3 **without** applying the proposal.
- Change: extend the snapshot with `features` and `layoutOrigin` (or apply settings as a separate,
  reported change). Define accept = the save that carries the scan id; until then the card says
  `Použito — neuloženo`. Create assets at that save. While a proposal is open in step 2, the top
  bar's `Pokračovat` becomes tertiary or asks `Pokračovat bez použití skenu?`.

**B4 — There is no visual design for most of the flow, and the one that exists is declared obsolete.**
- Where: `ux:1838–1844`.
- Evidence: the mock covers three Šablony screens. It predates S4b, the queue block, the 4-step
  bar, the stage table and the report rows; it still shows "1–5 min", `kolo 1 ze 3`, "Písma a
  barvy" and "Logo sponzora je vyříznuté z obrázku" (`mock:217`, `mock:310`, `mock:327`,
  `mock:400`). S4b, queue, failure, entry cards, request dialog, history, all Kalendáře screens,
  e-mails and admin views exist only as ASCII.
- Why it matters: the owner's requirement "works at 390 / 820 / 1440 in dark and light" cannot be
  checked for the placeholder step, the calendar panel or the queue — the three parts he asked for
  by name. The calendar panel is the riskiest layout (a full review inside a wizard step).
- Change: before P3 is approved, redraw the mock for S2 with queue line, S3 queued, S4, S4b with
  12 placeholders, S5, T1 states, C1–C4 at the three widths. Do not defer it to P3/P4 as
  `ux:1840` and `ux:1844` say (they also name different phases).

### Majors

**M1 — Nothing on the review says the template does not exist yet.**
- Where: S4 / T4 (`ux:625–633`, `ux:880–892`), toasts and e-mail (`ux:1220`, `ux:1234–1247`).
- Evidence: h1 `Šablona z obrázku`, viewer label `Šablona`, field `Název šablony`, primary
  `Otevřít v editoru`, toast `Šablona z obrázku je připravená`. The glossary verb "přijmout"
  (`ux:52`) is on no button; only the e-mail explains "dokud výsledek nepřijmete".
- Why: the user assumes the template is saved and leaves; it then expires after 30 days
  (`arch:536`).
- Change: label the result `Návrh šablony`; primary `Vytvořit šablonu a otevřít editor`; a line
  in the action bar `Návrh se stane šablonou, až ho vytvoříte. Uchováme ho 30 dní.`

**M2 — Expiry of an unaccepted proposal is silent.**
- Where: `arch:536`, `arch:553`; `ux:1364` (a history pill only); §6 has no row.
- Evidence: no warning e-mail, no date on the list card, nothing in the "ready" e-mail. Pictures
  the user uploaded in S4b to `AI sken / {název}` are sent to Koš on discard (`ux:636`), but
  expiry says nothing about them. The credit stays spent.
- Also: F13 says the result stays visible after the source expires (`ux:1308`), but the proposal
  render is deleted 30 days after accept and `proposal` is nulled (`arch:553`, `arch:571`).
  `Otevřít výsledek` on an old accepted scan has nothing to show.
- Change: show `Vyprší {datum}` on the ready card and in the e-mail; one reminder 3 days before;
  define the fate of S4b uploads; drop `Otevřít výsledek` when the proposal is gone.

**M3 — A template with a photo is compared against grey boxes.**
- Where: S3 preview (`ux:429`), S4 viewer (`ux:536–556`); `arch:340–345`.
- Evidence: placeholders render as a neutral stand-in. On photo-backed inputs the picture area is
  ≥ 0.9 of the canvas in 18 of 36 prototype runs (`arch:123`, E13). The slider then compares a
  photo with a grey rectangle. Kalendáře solved this by showing the source region on screen only
  (`ux:1171–1175`); Šablony did not.
- Why: the result looks broken at the moment of payoff; the user cannot judge the layout.
- Change: in the review and S4b previews, draw the source crop inside each empty placeholder at
  reduced opacity with a `místo pro obrázek` tag — on screen only, as the calendar does.

**M4 — The font item states a guess as a fact.**
- Where: `ux:566`, `ux:575–581`; `arch:118` (E8), `arch:394–395`, X4 (`arch:152`).
- Evidence: chips `{vypadá jako} → {použité}` and the button `Nahrát {rodina}` use
  `sourceLooksLike`, the model's guess. Face recognition was 0 of 128 with fonts absent, and
  "matched" was the model's assertion. Whether a project font is recognised from another renderer
  was never measured, so `V projektu není` may be false for the brand's own font.
- Why: a user buys or hunts for "Montserrat" because a button named it.
- Change: `Vypadá jako Montserrat (odhad)`; button `Nahrát správné písmo`; consequence line
  `V projektu jsme ho nenašli.`

**M5 — Wrong texts can only be corrected when the scanner doubts them.**
- Where: `ux:568`, Vrstvy tab (`ux:596–608`).
- Evidence: only low-confidence texts get an input. Text accuracy on the hard set was 0.86
  (`arch:68`). The mock's own sample lost its diacritics (`KONEC UTKANI`, `mock:396`). The Vrstvy
  tab renames layers but cannot edit the text.
- Change: make the text value editable in every Vrstvy text row; show a crop of the source next to
  each `Zkontrolujte přepis` item so the user can compare without the viewer.

**M6 — On a phone the report and the picture are never on screen together.**
- Where: S4 phone layout (`ux:508–534`), S4b phone (`ux:692–693`), principle 4 (`ux:76–81`).
- Evidence: `review-shots/review--390--dark.png` — viewer 425 px tall, then a 1 300 px report.
  Tapping an item "scrolls the viewer into view" (`ux:550`), which scrolls the item away. With 12
  placeholders the S4b list is about 2 200 px under one picture.
- Change: put a small source / result crop in every report item and every placeholder card. This
  also tells the user *which* picture to upload ("Logo 140 × 60 px" does not).

**M7 — The compare slider blocks page scrolling on touch.**
- Where: `mock:144` (`touch-action: none`), spec "Drag anywhere" (`ux:541`).
- Evidence: at 390 px the slider is the full content width
  (`review-shots/review--390--light.png`); a vertical swipe that starts on it does nothing.
  `Překrytí` adds press-and-hold on the picture (`ux:543`), which opens the iOS image callout and
  uses Space, the page-scroll key.
- Change: `touch-action: pan-y`, drag only from the handle on touch. Ship `Posuvník` and
  `Vedle sebe` in v1; drop `Překrytí` and the second, full-size viewer dialog.

**M8 — The placeholder step is one-by-one work with no help from what the project already has.**
- Where: S4b (`ux:695–709`).
- Evidence: each `Pevný` card needs `Vybrat z galerie` → find → pick, or an upload; 12
  placeholders ≈ 40–50 clicks. There is no multi-file drop, no "same picture for these", and no
  suggestion, although the project has manual logos, a project avatar and a gallery.
- Change: pre-fill `Logo` placeholders with the project's logo as a suggestion (`Použili jsme logo
  projektu · Změnit`); accept a multi-file drop on the step and assign files in order to the empty
  cards; show the gallery picker already filtered to the folder used last.

**M9 — "Doplním později" and the primary do the same thing, and unfilled pictures leak.**
- Where: `ux:715–716`, `ux:707`, export guard (`ux:894–900`), `arch:343–345`, O11 (`arch:895`).
- Evidence: both buttons accept. Unfilled fixed pictures reference a real gallery file in the
  folder `AI sken`; the user can trash, rename or reuse it, which is how the guard finds them. The
  guard is web only: API and MCP exports produce the grey picture silently, and the mfkfm
  consumer exports through the API. An empty `Vyměnitelný` slot without a sample is not covered
  at all.
- Change: one primary whose label states the consequence (`Vytvořit šablonu · 2 obrázky doplníte
  později`). Hide or lock the stand-in file in the gallery. Return a warning field in the API
  export response, or block publish in the consumer; decide before P3, not as a default.

**M10 — Fit mode cannot be chosen, and two controls decide the same thing.**
- Where: `ux:702`, `ux:708`, Vrstvy tab switch (`ux:603`).
- Evidence: `Pevný` is always contain, a sample is always centre-cropped; a decorative photo that
  should fill its box is letterboxed until the editor. Fixed vs fillable is a switch in Vrstvy and
  a segmented control in S4b. `Odebrat` (remove the picture) and `Odebrat z šablony` (remove the
  layer) sit on the same card. "The background photo is always `Vyměnitelný`" is unexplained.
- Change: add `Vejít celý | Vyplnit` on filled `Pevný` cards; keep the fixed / fillable choice in
  S4b only; rename the menu item `Smazat místo pro obrázek`.

**M11 — Stage notes with numbers cannot be produced by the specified protocol.**
- Where: `ux:412–413`, `ux:459`; `arch:326`, `arch:376–378`.
- Evidence: events carry `stage, note code, round?` and "no free text reaches the UI".
  `Nalezeno 12 textů`, `Porovnávám písma: 2 z projektu, 1 nahrazeno`, `7 řádků dní, čísla vlevo`
  and `zkoušíme to znovu (2/3)` all need parameters.
- Change: add `noteParams` (integers and closed enums only) to the event, or drop the numbers
  from the UX. Field names also differ: `previewVersion` (`ux:398`) vs `preview_seq` (`arch:500`).

**M12 — PDF: the two documents disagree.**
- Where: `ux:141`, `ux:203`, `ux:963–974`; `arch:80`, `arch:271`, O4 (`arch:888`), P2
  (`arch:801`).
- Evidence: architecture proposes a PDF page as a picture in v1 "with page choice" and recommends
  yes. The UX refuses PDF with an error message, has no page picker, and rewrote the card copy to
  avoid the word.
- Change: decide O4 first. If yes: add the page-picker screen to S2, PDF to `accept`, and restore
  the calendar card's line.

**M13 — The pre-check runs after the queue, and its e-mail has the wrong button.**
- Where: `arch:247–249`, F3 (`ux:1293–1296`), failure e-mail (`ux:1266–1277`).
- Evidence: the user is told to leave, waits through the queue, and then learns the picture is a
  photo of a print or not a calendar page. The failure e-mail's only button is `Zkusit znovu`; for
  a stopped scan there is no retry transition (`arch:532`: only `Zkusit přesto`, photos only), so
  the button leads to the same stop or nowhere. A HEIC upload is warned in S2 (`ux:263`), then
  stopped and asked again. `Zkusit přesto` spends a scan without a credit line, and has no copy
  for "limit reached meanwhile".
- Change: run the pre-check before queueing (one cheap call, ahead of the builder queue), so the
  verdict arrives while the user is still on the page. Give stopped scans their own e-mail with
  `Nahrát jiný obrázek`. Put `Spotřebuje 1 sken` next to `Zkusit přesto`.

**M14 — One scan per project in flight blocks serial work, and the entry card hides the upload.**
- Where: `arch:435`, T1 states (`ux:802–803`), `ux:336`.
- Evidence: a designer converting twelve posters uploads one, waits 1–3 min, decides, uploads the
  next. While a result waits, the T1 card's only button is `Zkontrolovat výsledek`; a new scan is
  reachable only through `Historie skenů → Nový sken`.
- Change: let a project queue several scans (the global queue cap and the monthly limit already
  bound cost), or at least accept the next upload and hold it. Keep `Nahrát obrázek` on the card
  in every enabled state, with the status as the extra line.

**M15 — After accept, "the fixes still work" is not buildable as written.**
- Where: `ux:890–892`, `ux:1375`; `arch:356–358`.
- Evidence: fixes edit the proposal document. After accept the template lives in the editor and
  may have changed. A fix applied from the scan page either does nothing or overwrites editor
  work.
- Change: after accept the review is read-only. Open items become a checklist with
  `Otevřít v editoru` per item.

**M16 — "K dořešení" makes the user click to clear information.**
- Where: `ux:558–573`, toast (`ux:890`).
- Evidence: `Rozumím` items (simplified effects, palette snap, ratio, 14-day) count as open until
  clicked; the tab counter and the post-accept toast `{n} věci ze skenu zbývá dořešit` then nag
  about them. "Nothing here blocks accepting" contradicts the tab's name. `Text se nevešel` asks
  whether to shrink the font, which the app-side fit can do itself.
- Change: two groups — `K rozhodnutí` (counts: fonts, transcriptions, left-out things) and
  `Pro informaci` (never counts, no button). Apply the text shrink automatically and report it
  with `Vrátit`.

**M17 — "Naskenovat znovu" sells a second credit for something that is free.**
- Where: `ux:640–657`.
- Evidence: the option `Nejdřív nahraju chybějící písma` → rescan, while uploading the font inside
  the review re-fits the texts with no scan (`ux:566`, `arch:346–354`). The lead `Stejný obrázek
  dá podobný výsledek` contradicts the prototypes: the same object became a slot or shapes between
  runs (`arch:122`, E12). `Zahodit` does not say that the scan stays counted (`ux:635–639`,
  `ux:1686`).
- Change: remove the font option and point to the font item. Lead: `Nový sken může dopadnout
  jinak, ne nutně lépe.` Add `Sken zůstane započítaný.` to the discard dialog.

**M18 — The calendar scan switches off features because one page does not show them.**
- Where: `ux:1120–1125`, `ux:1160–1169`.
- Evidence: `Vypneme: fáze měsíce`, `– QR kód v obrázku není (the element is switched off)`. One
  weekly page cannot prove that a calendar has no moon phases, QR code or week numbers.
- Change: the scan may only switch a feature **on**. Absent elements keep the calendar's current
  setting and are listed as `na stránce jsme nenašli`.

**M19 — The scan card is a radio button that is not a choice and contains other controls.**
- Where: C1 (`ux:978–982`); `assets/calendar/ui/step2.js:70–77`.
- Evidence: the siblings are `<button role="radio">` in a `radiogroup`, and clicking one calls
  `choosePreset`, which also advances to step 3. The scan card is specified as `role="radio"`
  that selects in peach and does not choose. Its locked state puts a `.btn.xs` inside it, its
  accepted state a link `Naskenovat znovu` — interactive content inside a button.
- Change: keep the scan card outside the radiogroup as its own card with one button; show the
  "accepted" state as a third real radio only once a scanned layout exists.

**M20 — Consent and disclosure do not match what is sent, stored and seen.**
- Where: `ux:268–308`; `arch:607–610`.
- Evidence:
  - Architecture requires the consent text to name the provider's retention and WBoost's 30 days
    (`arch:610`); the checkbox text has neither — they are behind the link, which the UX says the
    disclosure must never be (`ux:268–269`).
  - Sent to the provider besides the picture: OCR text, design drafts, font face names and the
    brand palette (`arch:607`). The dialog names only the picture.
  - `Nikomu dalšímu ho nepředáváme` — but every project member sees the scan card (`ux:923`), and
    WBoost admins can open any scan and the 👎 note from the usage table (`ux:1616`).
  - `Vaše obrázky nepoužíváme k ladění ani testování služby` sits next to the feedback toast
    `Díky, pomůže nám to sken zlepšit` (`ux:622`).
  - One checkbox carries two unrelated statements: consent to the transfer and a warranty of
    rights.
  - A cancelled scan's picture is kept 30 days and may already be at the provider; the cancel
    dialog says `Obrázek můžete nahrát znovu` (`ux:467–468`), §6 says the picture is kept
    (`ux:1687`).
- Change: see the string list (§3). Split the rights statement into its own line. State who can
  see the picture.

**M21 — The owner's "real request" requirement and "Premium" are not met as specified.**
- Where: T1 (`ux:804`), `ux:1474–1476`, `arch:596`, `ux:1443–1445`.
- Evidence: every non-entitled user sees a disabled `Brzy` until P6; the request action exists
  only after the launch gate. This reverses an owner requirement and is recorded only as a review
  disposition. Separately, `AI sken je součást Premium` names a plan that has no definition, price
  or page anywhere (billing is cut, `arch:84`); a user cannot decide whether to ask.
- Also: an admin sees the enabled card on a non-entitled project with no hint; other editors of
  that project get a 403 on the admin's scan card (voter, `arch:577–578`) although `ux:923` says
  any member can open it.
- Change: get the owner's explicit yes on `Brzy` until P6. Replace "součást Premium" with what is
  true (`placená funkce, kterou zapínáme na požádání`), or define Premium. Show admins
  `Projekt AI sken zapnutý nemá — vidíte ho jako administrátor.`

### Minors

| # | Location | Evidence | Change |
|---|---|---|---|
| m1 | S3 live region (`ux:344`, `mock:320–321`) | `role="status" aria-live` wraps the whole column, including a timer that ticks every second and a list re-rendered every 2 s | a separate visually hidden live region that receives only stage changes and the final state |
| m2 | Consent checkbox, `Ukázat vrstvy` (`mock:291`, `mock:350`) | focus lands on a hidden `.sr` input; the visible box and the label get no ring (measured: outline `none`) — `review-shots/focus-consent--1440--dark.png` | `:has(:focus-visible)` ring on the label |
| m3 | Segments (`mock:272`, `mock:345`) | `role="radio"` buttons are three tab stops each, no arrow keys | roving tabindex, or plain buttons with `aria-pressed` |
| m4 | Slider (`mock:357`, `mock:547`) | the focusable knob is 2 px wide; no Home / End, no `aria-valuetext`; hover highlight has no focus equivalent in the mock | 36 px focus target; `aria-valuetext="52 % původní"` |
| m5 | Touch targets at 390 | fix buttons, segments, `Potvrdit`, thumbs are 32 px high; `Ukázat vrstvy` 22 px; the consent link 16 px (measured) — house rule is 44 / 40 / 32 | 40 px for report actions on `hover: none` |
| m6 | Page head on phones | head, lead, `Historie skenů` and step bar take about 385 px before content on every step (`review-shots/upload--390--dark.png`); the lead still says "trvá to obvykle…" on the review | show the lead on step 1 only; move `Historie skenů` into the back row |
| m7 | Review at 1440 × 900 | the side panel is sticky and about 850 px tall, the sticky action bar covers its bottom; the third item is hidden behind the bar (`review-shots/review--1440--dark.png`) | panel scrolls inside itself (`max-height`), or is not sticky |
| m8 | S4b desktop (`ux:672–689`) | the list is in the side column; with 12 cards the pictures scroll away | make the pictures the sticky column |
| m9 | Colour meaning (`ux:545–548`, rule 3) | on one screen peach = Premium, "now", needs attention **and** the Pozadí layer type; rose = Tvary; lavender = Texty and the primary | neutral outlines with a letter tag (T / O / Tv / P), colour only on highlight |
| m10 | New tokens (`mock:95`, `mock:147–149`, `ux:801`) | hard-coded `#3A2A20`, `#fff`, `#141218`, `#0b0a0d` — tokens.css says never invent values | use `--on-accent` and add an `--on-pch` token |
| m11 | Step bar (`mock:50–59`) | a second stepper design next to the calendar wizard's; steps 1–2 are not clickable but look like the calendar's clickable steps | reuse the wizard's step component, or a plain `Krok 3 ze 4` label |
| m12 | Request dialog from T1 (`ux:805`) | the create dialog is a Bootstrap modal; "stays in the dialog" means a modal over a modal | replace the card body in place, or close the first dialog |
| m13 | Wireframe labels | `Rozbor rozvržení` in S5 and T5 (`ux:744`, `ux:913`) and `Rozebírám rozvržení…` (`ux:351`) vs the table's `Rozbor grafiky`; `kolo 1 ze 2` drawn before the refine stage (`ux:351`) | align with the stage table |
| m14 | Retry naming (`ux:1311–1313`, `arch:432`, `arch:500`) | UX: retry "creates a new job", shown as `2. pokus`; architecture: the same row is requeued and `attempt` counts lease attempts; admin column `Pokus` is ambiguous | one word for user retries, another for automatic attempts |
| m15 | History (`ux:1366–1383`) | Rev 2 cuts `Použít znovu`, `Stáhnout původní obrázek`, `Smazat z historie`, the `Kdo` chip; the table, the filter line (`ux:1332–1333`) and §3 (`ux:1417`) still list them | delete the cut rows |
| m16 | Feedback (`ux:610–623`, `arch:489`, `arch:729`) | reason chips are specified in the UX and cut in the architecture | remove the chips |
| m17 | Limit copy (`ux:326–328`, `ux:807`) | the limit is per project (`arch:505`), the copy says `máte`, `váš poslední sken`; a colleague can use it up | `V projektu zbývá…` |
| m18 | Calendar page size (`ux:264`, `ux:1137`) | copy hard-codes 310 × 145 mm; the page is a per-calendar setting | print the calendar's own page size |
| m19 | S2 checks (`ux:262–263`) | `Ostrý obrázek bez zkreslení` — a sharpness measure cannot see distortion; "HEIC — jde nejspíš o fotku tisku" is a guess | `Obrázek je ostrý`; `Fotka z telefonu (HEIC)` + advice |
| m20 | Undo hint (`ux:986`, `ux:1182–1184`) | `Ctrl+Z` on a phone or a Mac; the calendar toast supports an action button (`ui/dom.js:116`) | toast action `Vrátit` |
| m21 | Tab title and estimates | `Rozpoznávám…` while queued (`ux:1680`); two animations plus a spinner run for minutes in one pane (`mock:107–108`, `mock:133`) | `Ve frontě — {název}`; keep the sweep only |
| m22 | MCP (`ux:1639`) | `apply` cannot remove / merge / convert a placeholder, edit a text or choose fit; `retry` and `continue` name no credit effect or refusal codes | add the three placeholder fixes to `fixes[]`; list refusals |

---

## 2. Walk-throughs

### Clicks and screens to an accepted object (no fixes made)

| Flow | Path | Screens | Clicks (minimum) |
|---|---|---|---|
| Šablony, no pictures in the design | list → `Nová šablona` → card → pick file → consent → `Spustit` → (wait) → `Otevřít v editoru` | 6 (list, dialog, S1/S2, S3, S4, editor) | 6 + the OS file picker |
| Šablony, pictures, all "later" | … → `Pokračovat na obrázky` → `Doplním později` | 7 | 7 |
| Šablony, 12 placeholders filled, 3 fonts swapped | … 3 × (menu, face) + 12 × (gallery, folder, picture, confirm) + accept | 7 + the gallery picker 12 × | about 60 |
| Kalendáře | step 2 → scan card → pick file → consent → `Spustit` → (wait) → `Použít rozvržení a pokračovat` → `Uložit` | 1 wizard, 4 panel states, step 3 | 6; 9 with a logo |

The template is exportable only after one more visit to the fill page; an accepted template with
unfilled pictures is not yet a usable object.

### First-time client on a phone

1. The create dialog's AI card is `Brzy` unless the project is entitled (M21). A calendar client
   (link `/k/{token}`) never sees step 2; the calendar entry is designer-only
   (`store.js` `assertDesigner`).
2. The natural input is a camera photo of a print — the worst input. It passes S2 with a warning,
   waits in the queue, then stops (M13).
3. S2 asks `Na obrazovku / Pro tisk` plus a size select with `Vlastní šířka…`. Prefilled, but the
   words "spadávka" and "řezů" appear on the same card.
4. S3: phone locks, polling stops; the e-mail arrives two minutes after the end. Works.
5. S4: slider blocks scrolling (M7); report and picture are never together (M6); the tab
   `Vrstvy 19` with switches `vyplňuje uživatel / pevný text` is a designer's decision the client
   cannot make. `Pevný / Vyměnitelný` likewise.
6. `Otevřít v editoru` puts a phone user into the desktop canvas editor. For this person the
   useful end is the fill page. No alternative is offered.

### Designer on a desktop, twelve placeholders, three substituted fonts

1. Fonts: three items, each `Vybrat jiné písmo ▾`; no "use this face for the other groups".
   The named family is a guess (M4).
2. Placeholders: one by one (M8); pictures scroll out of view (m8); the same object may be five
   slots — `Sloučit s dalšími…` has no specified UI (new component).
3. Layers: 19 switches, all texts default to "input" (`ux:826`); the finish contract carries no
   per-text hint (`arch:381–386`), so the system cannot preset them.
4. Second poster: blocked until the first is decided (M14).

### Admin testing

1. Unlimited and always entitled; nothing marks a project as not entitled (M21).
2. Gate G1 forbids non-WBoost pictures in production before the legal check (`arch:615–618`);
   S2 shows admins the same consent with no reminder.
3. No link from the review to the scan's usage row (cost, tokens, stages); the admin goes to
   `/admin/usage` and finds it by time.
4. Cannot see the locked, limit or request states without a second account.

---

## 3. State coverage matrix

Columns: scan surface · list (T5 / C5) · entry card (T1 / C1) · shell · toast · e-mail · MCP.
✔ = specified, — = not applicable, ✘ = missing, ≠ = the documents disagree.

| State / transition (`arch` §5.2, §7) | Surface | List | Entry | Shell | Toast | E-mail | MCP | Note |
|---|---|---|---|---|---|---|---|---|
| queued | ✔ | ✔ | ✔ | ✔ | — | — | ✔ | tab title says `Rozpoznávám…` (m21) |
| queued, scanner absent | ✘ | ✘ | ✔ new scans only | ✘ | ✘ | ✘ until 12 h | `temporarily_unavailable` on start only | B2 |
| queued, provider blocked | ✔ text | ✘ | ✔ | ✘ | ✘ | ✘ | ✘ in `status` | B2 |
| queued → cancelled | ✔ | ✔ | ✔ | — | ✔ | — | ✔ | |
| running | ✔ | ✔ | ✔ | ✔ | — | — | ✔ | note parameters missing (M11) |
| running → queued (lease lost) | ✘ | ✘ | ✘ | — | — | — | ✘ | B2 |
| running → cancelled | ✔ | ✔ | ✔ | — | ✔ | — | ✔ | dialog vs kept picture (M20) |
| stopped (pre-check) | ✔ | ≠ (§6 yes, T5 table no) | ✔ | ✘ badge | ✔ | ≠ wrong button | ✔ | M13, B1 |
| stopped → queued (`Zkusit přesto`) | ✔ | ✘ | ✘ | — | — | — | ✔ `continue` | no credit line, no limit case |
| ready, unseen | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | |
| ready, seen | ✔ | ✔ | ✔ | — | — | — | ✔ | |
| ready, flagged unfinished | ✔ | ✘ | ✘ | — | ✘ | ✘ | ✘ flag in `result` | |
| accepted (template) | ✔ | ✔ | ✔ | — | ✔ | — | ✔ | fixes after accept (M15) |
| accepted (calendar) | ≠ | ≠ | ≠ | ≠ | ✔ | — | ✔ | moment undefined (B3) |
| discarded | ✔ | ✔ | ✔ | — | ✔ | — | web only | credit not stated (M17) |
| expired | ✘ | ✘ | ✘ | ✘ | ✘ | ✘ | ✔ value only | M2 |
| failed (provider, timeout, interrupted, build) | ✔ | ≠ `Zahodit` has no transition | ✔ | ✘ badge | ✔ | ✔ | ✔ | B1 |
| failed `renderer_unavailable`, `scanner_unavailable` | ✘ sentence | — | — | — | — | ✔ generic | code only | B2 |
| failed → queued (`Zkusit znovu`) | ✔ | ✔ | ✘ | — | — | — | ✔ `retry` | no copy for limit reached, queue full, scanner down, another scan in flight; new reserve not mentioned |
| refused | ✔ | ✘ T5 row | ✔ | ✘ | ✔ | ✔ | ✔ | B1 |
| accept fails (F15) | ✔ | — | — | — | ✔ | — | ✘ | |
| access removed mid-scan (F12) | ✔ | ✔ | ✔ | — | — | — | ✘ | |
| monthly limit reached mid-flow | ✔ S2 | — | ✔ | — | — | — | ✔ | rescan dialog and `Zkusit přesto` lack it |
| queue full | ✔ | — | ✘ | — | — | — | ✔ | |
| source expired | ≠ | — | — | — | — | — | ✘ | F13 vs `arch:553` (M2) |
| two tabs | ✘ | — | — | — | — | — | — | not mentioned anywhere; autosaved fixes are last-write-wins; accept is idempotent (`arch:641`) |
| finish while the user is in an editor | — | — | — | ✔ in principle | ✔ | ✔ | — | the shell poll starts only on pages rendered with a pending scan (`ux:1211`); the toast's `Zkontrolovat` leaves an editor with unsaved changes — unspecified |

UI with no backing state or data:
- `Zahodit` on failed scans (B1); `Použít znovu` (cut); post-accept fixes (M15).
- Stage notes with numbers (M11); the per-text "input / fixed" preset (no contract field).
- `Sloučit`, `Změnit na tvar` exist in `arch:357` but not in MCP (m22).
- "Admins are e-mailed once", "Dali jsme o tom vědět vývojářům" — backed (`arch:646`, Sentry).

Numbers and names that differ:

| Topic | `ux.md` | `architecture.md` |
|---|---|---|
| PDF in v1 | refused (`ux:203`) | proposed, with page choice (`arch:888`) |
| Preview counter | `previewVersion` (`ux:398`) | `preview_seq` (`arch:500`) |
| Retry | new job, `2. pokus` (`ux:1311`) | same row; `attempt` = lease attempts (`arch:432`) |
| Reason chips | specified (`ux:616`) | cut (`arch:729`) |
| History actions | still listed (`ux:1377–1383`) | cut (`arch:489`) |
| Result after 30 days | "Výsledek zůstává" (`ux:1308`) | proposal deleted and nulled (`arch:553`, `arch:571`) |
| Mock redraw | P3 (`ux:1839`) and P4 (`ux:1844`) | P3 (`arch:817`) |
| Longest wait | "1–3 min", slow line after 3 min | 360 s per attempt × 2 + queue ≈ 12 min + queue (`arch:430–432`) |
| Who opens a scan | any project member (`ux:923`) | admin, or editor of an entitled project (`arch:577`) |
| Default estimate | 1–3 / 1–2 min (`ux:446`) | 60–150 s / 60–120 s (`arch:512`) — agree after rounding |

---

## 4. String changes

Grammar: every count needs the 1 / 2–4 / 5+ helper the design system requires (§6). The strings
below are written with one form only.

| Where | Now | Replace with |
|---|---|---|
| `ux:315` | `Právě běží {n} sken / skeny. Váš začne…` | `Před vámi je 1 sken / jsou {n} skeny / je {n} skenů. Váš začne…` (the count is scans ahead, not running) |
| `ux:570` | `{n} obrázků čeká na doplnění` | `1 obrázek čeká` / `{n} obrázky čekají` / `{n} obrázků čeká na doplnění` |
| `ux:1133` | `{n} obrázky čekají na doplnění` | same three forms |
| `ux:571` | `{n} barev jsme sjednotili…` | `1 barvu` / `{n} barvy` / `{n} barev jsme sjednotili…` |
| `ux:572` | `Zjednodušili jsme {n} věcí` | `1 věc` / `{n} věci` / `{n} věcí` |
| `ux:573` | `{n} prvky jsme vynechali` | `1 prvek` / `{n} prvky` / `{n} prvků jsme vynechali` |
| `ux:566` | `Týká se {n} textů.` | `Týká se 1 textu.` / `{n} textů.` |
| `ux:572` | `…rozdělený na {n} texty.` | `na {n} texty` / `na {n} textů` |
| `ux:890` | `{n} věci ze skenu zbývá dořešit` | `Ze skenu zbývá dořešit 1 věc` / `zbývají … {n} věci` / `zbývá … {n} věcí` |
| `ux:1243` | `Našli jsme {n} věci, které stojí za pohled` | `1 věc, která stojí` / `{n} věci, které stojí` / `{n} věcí, které stojí za pohled` |
| `ux:897` | `Šablona má {n} nedoplněné obrázky` + `budou … šedé zástupné obrázky` | `1 nedoplněný obrázek … bude šedý zástupný obrázek` / `{n} nedoplněné obrázky` / `{n} nedoplněných obrázků` |
| `ux:889` | `— {n} prvků` | `1 prvek` / `{n} prvky` / `{n} prvků` |
| `ux:412` | `Nalezeno 12 textů` | `Nalezen 1 text` / `Nalezeny {n} texty` / `Nalezeno {n} textů` |
| `ux:856` | `{n} řezů` | `1 řez` / `{n} řezy` / `{n} řezů` |
| `ux:552` | `{n} kola doladění` | `bez doladění` / `1 kolo doladění` / `2 kola doladění` |
| `ux:700` | `{hotovo} ze {n}` | `z` / `ze` by the number (`2 z 12`, `2 ze 4`); same for `zbývá {n} z {limit}`, `{limit} z {limit}`, `Tento měsíc {n} z {limit}` |
| `ux:154`, `ux:1293` | `S čím to jde nejlíp`, `Nejlíp funguje…` | `S čím to jde nejlépe`, `Nejlépe funguje…` (the app's copy is standard Czech) |
| `ux:202`, `ux:568`, `ux:206`, `ux:1294` | `Tenhle soubor…`, `Tímhle textem…`, `moc malý`, `moc rozmazaný` | `Tento soubor…`, `Tímto textem…`, `příliš malý`, `příliš rozmazaný` |
| `ux:207` | `Obrázek má příliš mnoho pixelů. Zmenšete ho pod 60 Mpx.` | `Obrázek je příliš velký. Zmenšete ho — nejvýše 60 megapixelů.` |
| `ux:262` | `Ostrý obrázek bez zkreslení` | `Obrázek je ostrý` |
| `ux:263` | `Fotka z telefonu (HEIC) — jde nejspíš o fotku tisku, ne o export` | `Fotka z telefonu (HEIC). Pokud jde o fotku tisku, výsledek bude jen přibližný.` |
| `ux:264`, `ux:1137` | `…než 310 × 145 mm…` | `…než váš kalendář ({š} × {v} mm)…` |
| `ux:274–276` | `Souhlasím, že obrázek odešlete ke zpracování službě Claude společnosti Anthropic (USA). Slouží jen k rozpoznání grafiky. Mám právo tuto grafiku použít.` | `Souhlasím, že obrázek a údaje z něj (texty, barvy, názvy písem) odešlete ke zpracování službě Claude společnosti Anthropic (USA). Anthropic je maže do 30 dnů, my původní obrázek po 30 dnech.` + a separate line `Potvrzuji, že tuto grafiku smím použít.` |
| `ux:285–287` | `…Nikomu dalšímu ho nepředáváme.` | `…Nikomu dalšímu ho nepředáváme. Uvidí ho lidé s přístupem do projektu a při řešení potíží tým WBoost.` |
| `ux:289–290` | `Vaše obrázky nepoužíváme k ladění ani testování služby.` | keep only if admins do not open customers' scans; otherwise `Vaše obrázky nepoužíváme k trénování. Podíváme se na ně jen tehdy, když sken ohodnotíte jako nepovedený nebo nám napíšete.` |
| `ux:293–296` | `…déle je může držet jen tehdy, když obsah vyhodnotí jako porušení svých pravidel.` | `…déle, nejvýše 2 roky, jen když obsah vyhodnotí jako porušení svých pravidel.` (E33, `arch:143`) |
| `ux:326–328` | `tento měsíc zbývá {n} z {limit}` · `Spotřebuje váš poslední sken v tomto měsíci` | `v projektu tento měsíc zbývá {n} z {limit}` · `Spotřebuje poslední sken projektu v tomto měsíci` |
| `ux:807` | `Tento měsíc máte vyčerpáno {limit} z {limit} skenů.` | `Projekt má tento měsíc vyčerpáno {limit} z {limit} skenů.` |
| `ux:338` | `…Na nápravě pracujeme — zkuste to později.` | `…Víme o tom. Zkuste to později.` |
| `ux:436–439` | `Na řadě přibližně za 3–5 min · hotovo asi za 5–8 min` | `Začneme asi za 3–5 min, hotovo asi za 5–8 min` (one word for "about", no jargon "na řadě" twice) |
| `ux:449` | `Jste stále {n}. v pořadí — začneme hned, jak skončí.` | add the paused variant: `Skener teď nepracuje. Váš sken čeká ve frontě — dáme vědět, až doběhne.` |
| `ux:457` | `Trvá to déle než obvykle. Pořád na tom pracujeme — klidně odejděte, dáme vědět.` | `Trvá to déle než obvykle, nejdéle asi 6 minut. Klidně odejděte — dáme vědět.` |
| `ux:461` | `…uvidíte ho v Šablonách a pošleme vám e‑mail.` | `…najdete ho v Šablonách. Když tu nebudete, pošleme e‑mail.` |
| `ux:467–468` | `Rozpoznávání se zastaví a sken se nezapočítá. Obrázek můžete nahrát znovu.` | `Rozpoznávání se zastaví a sken se nezapočítá. Obrázek u nás zůstane 30 dní, můžete ho použít znovu.` |
| `ux:472`, `ux:1220` | `Šablona z obrázku je připravená — „{název}“` | `Návrh šablony „{název}“ je připravený ke kontrole` |
| `ux:1234` | `Šablona z obrázku je připravená ke kontrole` | `Návrh šablony z obrázku je připravený` |
| `ux:1246–1247` | `Dokud výsledek nepřijmete, mezi šablonami se neobjeví. Původní obrázek u skenu uchováme {30} dní.` | `Šablona vznikne, až návrh potvrdíte. Návrh i původní obrázek uchováme do {datum}.` |
| `ux:566` | `Písmo {skupiny} jsme nahradili` · `V projektu není.` · `Nahrát {rodina}` | `Písmo {skupiny} jsme nahradili` · `V projektu jsme ho nenašli. Vypadá jako {rodina} (odhad).` · `Nahrát správné písmo` |
| `ux:568` | `Zkontrolujte přepis` · `Tímhle textem si nejsme jistí.` | `Přečetli jsme to správně?` · `Tímto textem si nejsme jistí.` |
| `ux:570` | `Obrázky z grafiky nevyřezáváme. Každý má v šabloně své místo — doplníte je v dalším kroku.` | `Obrázky z nahrané grafiky nepřebíráme. Každý má v šabloně své místo — doplníte je v dalším kroku.` |
| `ux:583–585` | `Všechno sedí` / `Nenašli jsme nic, co by stálo za kontrolu.…` | `Nic jsme nenahrazovali` / `Žádné písmo, text ani prvek jsme nemuseli nahradit ani vynechat. Přesto si výsledek porovnejte s originálem.` |
| `ux:589–591` | `Zastavili jsme ho na časovém limitu a nechali nejlepší verzi.` | `Zastavili jsme ho po dosažení časového limitu a nechali nejlepší verzi. Sken se započítal.` |
| `ux:632–633` | `Otevřít v editoru →` | `Vytvořit šablonu a otevřít editor →` |
| `ux:635–639` | `Výsledek se smaže.…` | `Návrh se smaže a sken zůstane započítaný.…` |
| `ux:653` | `Stejný obrázek dá podobný výsledek. Pomůže:` | `Nový sken může dopadnout jinak, ne nutně lépe. Pomůže:` |
| `ux:654–655` | `Nejdřív nahraju chybějící písma` · `Nahraju lepší obrázek` | drop the first; `Nahrát lepší obrázek` |
| `ux:702` | `Pevný` / `Vyměnitelný` | `Součást šablony` / `Mění se při každém exportu` (or keep the short words and add this as the caption) |
| `ux:707` | `Prázdná místa zůstanou v šabloně jako vrstvy se zástupným obrázkem.…` | `Prázdná místa zůstanou v šabloně šedá. Doplníte je v editoru — při exportu vás na ně upozorníme.` |
| `ux:709` | `Odebrat z šablony` | `Smazat místo pro obrázek` (and `ze šablony`, not `z šablony`) |
| `ux:715` | `Doplním později` + `Otevřít v editoru` | one button: `Vytvořit šablonu · {n} obrázky doplníte později` |
| `ux:771–772` | steps `1 Obrázek` … `4 Obrázky` | `1 Nahrání` · `2 Rozpoznávání` · `3 Kontrola` · `4 Fotky a loga` |
| `ux:767–769` / `ux:810–812` | head: `AI z ní poskládá…`; card: `systém z ní sám vytvoří…` | one actor in both: `AI z ní poskládá…` |
| `ux:949–951`, `ux:969` | `AI rozpozná fotografii, kalendárium, hlavičku i logo` | `AI rozpozná, kde je fotografie, kalendárium, hlavička a logo` (the logo and photo are not taken over) |
| `ux:1039–1040` | `Rozmístění fotografie, kalendária, hlavičky, měsíce a loga, písma z projektu a barvy. Fotky, texty a dny doplní kalendář sám.` | `Převezmeme rozmístění fotografie, kalendária, hlavičky, měsíce a loga a také barvy. Písma vybereme z projektu. Fotky, texty a dny doplní kalendář sám.` |
| `ux:986`, `ux:1182–1184` | `… — Ctrl+Z vrátí naskenovaný design` · `Vrátíte ho krokem zpět (Ctrl+Z).` | toast with the action `Vrátit`; text `Vrátíte ho tlačítkem Zpět o krok.` |
| `ux:1164–1165` | `Zapneme: čísla týdnů, popisek fotky. Vypneme: fáze měsíce.` | `Zapneme: čísla týdnů, popisek fotky.` (never switch off, M18) |
| `ux:1298` | `Služba AI teď neodpovídá.…` (also used for the renderer) | renderer: `Náhled se teď nepodařilo vykreslit. Váš obrázek máme uložený — zkuste to znovu.` |
| `ux:1356–1361`, `ux:1194–1196`, `ux:1222` | `Rozpoznává se` / `Sken běží` / `Rozpoznávání běží`; `Nepovedlo se` / `Sken se nepovedl` / `Sken se nepodařil` | one each: `Sken běží`; `Sken se nepodařil` (pill `Nepodařilo se`) |
| `ux:1329` | `Kalendář z obrázku…` | `Naskenovat design kalendáře…` (a fourth name for one feature) |
| `ux:1443–1445`, `ux:1465` | `AI sken je součást Premium a zapínáme ho pro každý projekt zvlášť.` | `AI sken je placená funkce, kterou zapínáme pro každý projekt zvlášť.` until Premium is defined |
| `ux:1519` | `Prémiové funkce` | `Funkce Premium` (one spelling with the pill) |

Length at 390 px (not measurable without a mock; estimated at 13 px Outfit):
- `Sken „{název}“ čeká na kontrolu` with a 60-character name in the T1 row card (text column about
  210 px) is four lines — truncate the name.
- `Tento měsíc máte vyčerpáno … Další budou od 1. {měsíce}.` is four lines in the same card.
- `{projekt}: Šablona z obrázku je připravená` + action button wraps to three lines in a toast.
- `Nahradit rozvržení a pokračovat →` fits (about 250 px).

---

## 5. UI check of the mock

What the screenshots show (all in `docs/ai-scan/ux-mock/review-shots/`):

| Check | Result |
|---|---|
| Horizontal overflow | none in any of the 48 state shots |
| Truncated text | none detected |
| Text contrast | every measured text pair ≥ 4.76 : 1 in both themes (lowest: font family caption 4.76 dark, 4.85 light; eyebrow 5.09 light) |
| One lavender primary | holds on all three screens; the review also has lavender text boxes when `Ukázat vrstvy` is on (m9) |
| Peach as the AI accent | holds for the pill, the "now" step, the bar; overloaded on the review (m9) |
| `.head` | present; no primary in the head — the primary lives in a new sticky footer `.as-foot` (a new pattern next to rule 10's `.gal-nav`) |
| Pills | buttons and segments are pills; fields 14 px — consistent |
| Focus | 2 px lavender outline on buttons, links, tabs; invisible on both hidden-input controls (m2); inputs rely on the wrapper |
| Touch targets | m5 |
| Reduced motion | sweep, shimmer and spinner stop (verified); the only sign of activity left is the ticking time — acceptable |
| Phone layout | single column works; head too tall (m6); slider scroll trap (M7) |
| 820 px | side column drops under the main one; the running screen puts the stage list below a 430 px picture pair, so the current stage is off screen on load (`review-shots/running--820--light.png`) — put the stage card first below 1000 px |
| Emoji | thumbs are emoji in the mock; the doc already says two icons are needed |

Screens that exist only as ASCII — feasibility with the existing kit:

| Screen | Feasible with | New components needed |
|---|---|---|
| S2 queue line, credit line, disabled hints | `.hint`, `.notice` | none |
| S3 queued block | `.as-card` | queue block; the paused variant (B2) |
| S4b | `.seg.soft`, gallery picker, `.inp` | numbered overlay boxes; placeholder card with thumbnail; **merge mode** (multi-select across cards + confirm); shape-convert menu; fit toggle |
| S5 | stage list + `.alert` | failure card |
| T1 states | `.tp-type` | peach hover variant; status line; modal-in-modal problem (m12) |
| T5 strip | `.tp-card` | scan card |
| C1 card | `.cal-layc` | non-radio scan card (M19) |
| C2–C4 panel | — | the whole panel; "Co jsme rozpoznali" checklist; top-bar chip. The top bar already holds back, name, five steps, undo / redo, status, `Uložit`, `Zpět`, `Pokračovat`; a chip at 390 px has no room |
| History | `.u-card`, `.fbar`, `.pill` | none |
| Request dialog | fake-door dialog | none |
| Zoom dialog | design system names `.gal-pv`, which is not in `assets/styles/nocturne/` | a viewer dialog — or drop it (M7) |
| Compare `Překrytí` | range input | hold-to-compare — drop |

---

## 6. Delight and automation

Where the user does work the system could do:
- Clicking `Rozumím` to clear a counter (M16).
- Deciding `Zmenšit písmo, aby se vešel` (M16).
- Finding and uploading the project's own logo into a `Logo` placeholder (M8).
- Filling twelve placeholders one at a time (M8).
- Waiting through the queue to learn the picture is unusable (M13).
- Naming: the name comes from the file name (`ux:629`); `IMG_4521.HEIC` gives `IMG_4521`. The
  scanner already reads the headline.
- Toggling 19 layers because nothing presets "input vs fixed".

Missing payoff:
- For 60–100 s the preview pane is an empty skeleton (`ux:426–431`). The pre-pass has the OCR
  lines with boxes after about 5 s (`arch:304`); drawing them over the original is real, early
  evidence that "it sees my texts".
- The finish is a silent page swap plus `Sken je hotový`. The summary line is 12 px, muted, and
  reports duration and rounds (`ux:552`) — the machine's facts, not the user's.
- On a photo design the result looks empty (M3).

Three changes with the best return for the least cost:
1. **Source crops everywhere a decision is asked** — inside empty placeholders in the previews,
   and as a thumbnail in each report item and placeholder card (M3, M6). One image-crop endpoint;
   it fixes the phone review, the placeholder step and the first impression together.
2. **Pre-fill what the project already knows** — the project logo into `Logo` placeholders,
   automatic text shrink, informational items that never count (M8, M16). No new UI.
3. **OCR boxes on the original during the long stage, and a result headline at the end** —
   `Hotovo: 12 textů, 3 tvary, 4 místa pro obrázky. Rozhodnout zbývá 2 věci.` in place of the
   duration line. Needs the pre-pass boxes in one event and M11's parameters.

---

## 7. What could not be judged

- Any visual quality of S4b, the queue block, the failure card, entry cards, the request dialog,
  history, admin views, e-mails and all Kalendáře screens — they are not drawn (B4).
- Whether the calendar panel fits the workspace at 390 and 820 px with the real top bar; the
  calendar workspace was read in code, not run.
- Real timing: font swap "in ~2 s", the 2-minute e-mail, queue estimates — none is measured
  (`arch:924`), so the copy's promises rest on expectations.
- Screen-reader behaviour: only the markup was inspected; no VoiceOver or NVDA run.
- Colour contrast of non-text parts (layer boxes over arbitrary artwork, the white slider knob on
  light artwork) — depends on the uploaded picture.
- Legal adequacy of the consent wording; only its agreement with `architecture.md` §6 was checked.
- Whether the shell toast can appear in the calendar workspace (it has its own toast helper) and
  what happens to unsaved editor changes when the toast's action navigates away.
- Two-tab behaviour of the calendar save with a scanned layout (existing last-write-wins was not
  traced).
- The prototypes' `results.md` files were read only through the evidence table in
  `architecture.md` §2.1 (E1–E16), not line by line.
