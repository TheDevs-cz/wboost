# K21 — the AI scan catches up with the feedback round (plan)

Jan, 2026-11-24: „With the new features, these should also be supported by the AI module:
scanning image / text / shape layers or shadows / glows to achieve the desired outputs.
Everything we did so far is worth checking whether it needs AI-module improvements that were
not implemented yet.“

This file is the audit of the calendar feedback round K1–K20 (`docs/calendar/FEEDBACK.md`) and of
the template editor's „Styl vrstvy“ (2026-11-24) against the two scans, and the plan for what the
scans should learn. Contracts: `docs/architecture/ai-scan.md` (§ "Calendar scans"),
`docs/architecture/calendars.md` (§ "Image effects", § "Own layers").

## 1. How a scan proposal is made — where a new feature has to be taught

A **calendar** candidate is a list of model commands (`preset`, `configure`, `layout {boxes, order,
styles}`) that the scanner sends to `POST {id}/render`; `CalendarCandidate` checks the command list,
`resources/calendar/model.mjs` validates the layout patch against the default style tree (unknown key
→ refused, enum → refused, number → clamped; effect lists via `checkEffects`), and the materialised
layout IS the proposal. So:

| What a feature needs | Where | Ships with |
|---|---|---|
| The model knows the key and when to set it | `resources/ai-scan/definition/system.calendar.md` (+ `tools.json` descriptions) | the app — the definition travels in every claim |
| The patch path accepts it | `model.mjs` patch validation (any key of `defaultStyles` already passes) | the app |
| A new op or top-level layout key (`layers`) | `CalendarCandidate` (closed op subset), `model.mjs`, the scanner adapter (`scanner/src/adapters/calendar.mjs` builds the commands; `tools.json` input schema is `additionalProperties: false`) | the app **and a scanner image** |
| A new report field / list | `finish.calendar.schema.json`, `scanner/src/finish/calendar-report.mjs`, `AiScanReportValidator`, `CalendarScanProposalView` | the app and a scanner image |
| The review says what was taken | `assets/calendar/ui/scan/{views,preview}.js`, `assets/ai-scan/copy.js` (`CAL.*`) | the app |
| Accept writes it | `CalendarScanProposal::plan()` (drops every asset), `AcceptCalendarScanHandler` | the app |

**Style keys are prompt-only work**: the scanner passes `styles` through untouched
(`#candidateOf` merges whatever object the model sends), so effects, the number band's width and the
like reach production with the app deploy — no image pin. **Own layers are not**: the adapter drops
any key but `boxes / order / styles`, and the tool schema does not declare `layers`.

A **template** candidate is a design DSL document (`src/Mcp/Design/Dsl/DslParser`) the scanner sends
whole; new DSL keys likewise need only the instructions (`system.template.md`).

## 2. The audit

| Item | Feature | Scan today | What to add | Phase |
|---|---|---|---|---|
| K1, K3, K6, K7, K7b, K8, K4 | scan UI / apply flow | done in those items | — | — |
| **K2** | picture effects (drop / inner shadow, outer / inner glow, gradient overlays, 16 blends) on photo, logo, header / background picture | **partly**: the instructions show `photo.effects` with ONE old-style drop shadow and a darkened-corner gradient; nothing on the logo, no glows, no `blend`; the review says nothing about effects | instructions: the effect vocabulary for what a flat page CAN show — a soft shadow or glow AROUND the photo or logo (`dropShadow`, `outerGlow`; colour, opacity, angle, distance, size measured outside the box); the inner kinds and overlays only for an unmistakable darkened band behind a label on the photo (they would lie over the customer's 53 photos and cannot be told from the photo's own pixels — the photo box is masked out of every measurement); header / background pictures get none (the scan never sets a picture there). Review: „Co jsme rozpoznali“ names the effect beside Fotografie / Logo („stín“, „záře“, „zaoblené rohy“). No schema / PHP change (style keys). Test: a candidate with every kind + blend is rendered, proposed and accepted unchanged | **2** |
| **K5 / K5b** | „Pozadí čísel“ `cal.numBg {color, opacity, grow}` | **partly**: K16 taught `color` / `opacity` and the frame-edge-on-page-edge rule; `grow` (K5b, 0–60 mm inward widening) is unknown, so a wide band is cut to the number column | instructions: measure the band's inner edge and set `grow` = band width − the default band (from 2 mm before the number column); review fact „pozadí čísel“ on Kalendárium | **2** |
| K10a | own waste icons (uploads per kind) | not scannable — an icon is a picture, and pictures are never taken out of the source; the scan switches `waste` on when pictograms show and the designer uploads the icons in step 3 / 4 › Odpady | nothing (say so in the plan; the review's pictures step does not list icons, on purpose — 10+ tiny places would bury the backdrop K7b just promoted) | — |
| K10b | Excel import of waste dates / events | content, not layout | — | — |
| K11, K12, K13, K14, K9 | photo names, warnings, page list, photo editor, URL | content / UI, nothing a picture of a page shows | — | — |
| **K15b** (+ K16) | languages; „Názvy dnů“ `cal.dayFormat` full / short / auto | **languages: done** (K16 report field `languages`, scanner image `sha-6a83f507` on prod — lily.srv 5df9b1d; K15b P3 offers them pre-ticked). **dayFormat**: the instructions offer `full | short` only, so a multilingual page is often built `full` — once the languages are switched on, full trilingual names rarely fit the row | instructions: `auto` (the default) unless the page clearly abbreviates every weekday name; never `full` when `languages` is non-empty | **2** |
| K17 | glyph coverage of fonts | not a scan feature (needs the font's cmap) | — | — |
| **K18** | photo caption (position, plate, above the photo) | **done** — K16 taught the plate box, padding, alignment; the default order already has `cap` above `photo` | nothing | — |
| **K19** | icon order `icons.order` | not taught: a page with moon glyphs AND waste pictograms in the rows is built with the default order (waste first) | one instruction line: `styles.icons.order` = the groups' order from the row end | **2** (one line) |
| **K20** | own layers — rect / rounded rect / ellipse / line, image layers | **not supported**: these are exactly what „Co kalendář nepřevezme“ lists as `ornament`, `more_logos`, `second_photo`; the review's advice „kalendář nevykreslí — udělejte ji součástí obrázku“ is untrue since K20 P1's step-3 UI is on main (8c4c04bd) | (a) **copy fix** now: the three rows say „přidejte si ji jako vlastní vrstvu (Úpravy prvků › Vrstvy › Přidat)“. (b) **scan builds layers**: model `layout.layers` in the candidate (rect / ellipse / line with measured fill, stroke, radius, opacity; an IMAGE layer with `asset: null` as a place for a second logo, partner strip or ornament picture), the pictures step lists those places beside logo / header / background, accept keeps the designer's own layers and adds the scanned ones (§ 4, Q1) | (a) **2**; (b) **3** (waits for K20 P1's MCP / `model.mjs` `layers` op on main) |
| K20 P4 | static text layer | not built yet | `header_text` (a slogan, a municipality name) becomes a text layer — only after K20 P4 | **4** |
| other | week label form `wk.form`, moon `styles.moon`, events `styles.ev`, `photo.radius / border`, fills with gradients | done (P5, K16) | — | — |
| other | font rows for `ev` (events) and `hol` | the report's `FONT_GROUPS` stop at `num day name month wk cap`: the event and holiday faces are set but never verified | scanner-side, small — fold into the Phase-3 image | 3 |
| other | cover / back / special pages | a scan reads ONE week page; nothing else is in scope | — | — |

### Templates — „Styl vrstvy“ (2026-11-24) against the template scan

The design DSL gained `blend` (texts, shapes, pictures, background), `shadow` = `dropShadow` |
`outerGlow` (texts, shapes, pictures), picture `cornerRadius` + `border`, `adjust` (picture
adjustments) and, earlier, `letterSpacing`. `system.template.md` still says „No rotation, shadows,
blur, blend modes, masks or clipping (no rounded or circular photos)…“ and lists the DSL keys
without them, so the scan reports a headline's shadow as `shadow_omitted` (review: „Stín pod nadpisem
jsme vynechali.“) and a round photo as a square place.

| Key | Worth proposing? |
|---|---|
| `shadow` on texts and shapes | **yes, gated by the eval** — a headline's drop shadow / glow is the most common „Styl“ on social posts and it is measured (outside the ink, not masked). Risk: shadows invented where there are none (the letter-spacing round, RESULTS §10.6, made easy inputs worse) |
| picture `cornerRadius` (+ `border`) | **yes** — a round or rounded photo becomes a round place instead of a square one; the picture area is masked, so it costs no score and cannot be measured either: low risk |
| `blend` | shapes only, for a tint that visibly multiplies / screens the photo under it — rare; include in the same gated round |
| `adjust` | **no** — it edits the user's picture, which the scan never has |
| `letterSpacing` | **no change** — RESULTS §10.6 decided against it with measurements |

## 3. Phases

| Phase | Contents | Depends on | Ships |
|---|---|---|---|
| **1 — this plan** | audit + plan | — | docs |
| **2 — calendar, no K20** | `system.calendar.md`: effect vocabulary (outside kinds on photo / logo, guarded inner / overlay), `numBg.grow`, `dayFormat` with languages, `icons.order`; review facts (photo / logo effects, rounded corners, number band); PHP test (a candidate with every effect kind + blend + `grow` renders, is proposed and accepted unchanged); node suite `scan-preview` for the facts; the `ornament` / `more_logos` / `second_photo` copy points at Vrstvy › Přidat; eval before / after; docs (`ai-scan.md`, `calendars.md`), FEEDBACK K21 row | — | app deploy only (no scanner image, no MCP surface change, no export fingerprint change — `ui/scan/*` is not in the fingerprint) |
| **2T — template layer styles** | `system.template.md`: `shadow` on texts / shapes, picture `cornerRadius` / `border`, shape `blend`; the „cannot express“ list shortened; eval before / after on easy + hard inputs; adopted only if the easy set does not get worse | — | app deploy only |
| **3 — calendar own layers** | `CalendarCandidate` accepts `layout.layers` + `u:` boxes / order (closed kinds, no asset — image layers only as empty places), adapter + `tools.json` `calendar_preview` param `layers`, picture layers masked in the feedback like the logo, report `needsUpload` slot `layer:<id>`, `plan()` drops layer assets, held pictures → layer assets at accept, accept merges (Q1), review lists the scanned layers and their picture places; `ev` / `hol` font rows; scanner image + pin in `~/www/lily.srv`; MCP `scan_design result` shows layers (skill release if its text changes) | K20 P1 complete on main (the `layers` op of `model.mjs`, `CalendarLayerRules`) | app, then scanner image, then pin |
| 4 — header text | `header_text` → K20 P4 text layer | K20 P4 | as 3 |

## 4. Product questions (recommendation first)

| # | Question | Recommendation | Alternatives |
|---|---|---|---|
| Q1 | Accept of a scan that proposes own layers, on a calendar that already has own layers | **Keep the designer's layers, add the scan's** with ids `scan-…`; a later scan replaces the `scan-…` layers of the earlier one; restore brings back the stored layout exactly | replace all own layers; or ask in the review |
| Q2 | Inner shadow / inner glow / gradient overlay on the PHOTO | **Only for an unmistakable darkened band behind a label that sits on the photo** — otherwise the effect would lie over every week's photo, and the scan cannot tell it from the photo itself | never; or whenever the model thinks it sees one |
| Q3 | Template layer styles | **Adopt shadows / corner radius only if the eval's easy set does not get worse** (the §10.6 rule) | adopt unconditionally |

## 5. Risks

- **Invented effects.** A model told about shadows starts seeing them. Mitigation: outside effects
  only where measured (a band of darker pixels just outside the box on the side away from the
  light), the eval checks that pages WITHOUT effects stay without.
- **Unmeasurable choices.** The photo / logo / picture areas are masked out of the feedback, so an
  inner effect or a corner radius is never corrected by a measurement. Mitigation: Q2.
- **The logo placeholder is a rectangle** in the candidate; a shadow under a transparent logo
  follows its outline in the real calendar. The measured shadow outside the box is still the right
  signal; the size can be off by the logo's own margin.
- **Prompt length.** Every rule costs input tokens on every scan (~1.5 k tokens for this round on a
  ~15 k first message; cache read on later turns).
- **Shared tree.** The eval swaps the instruction files the dev app serves; nobody else runs a real
  scanner here. The scan e2e uses the fake scanner, which ignores the instructions.

## 6. How it is evaluated

- **Engine on the plan**: `node bin/scanner.mjs serve` with `ANTHROPIC_API_KEY` unset, against the
  dev app (memory `feedback_subscription_for_dev_llm_calls`); `scanner/eval/calendar.mjs` through the
  real job core, scored with the calendar prototype's scorer (`CALENDAR_PROTO_DIR`), as in K16.
- **Inputs**: `chotebuz` (client page: number band, multilingual names, caption plate, sky header),
  `markvartovice` (white logo on the photo, plate-less caption), the synthetic `s01`, `s03`, `s05`
  (no effects — they must stay without), plus **targeted synthetic pages** built with the MCP tools
  like the s-set (`prototypes/calendar/synth`): a drop shadow under the photo + an outer glow on the
  logo, and a wide number band (`grow` 20 mm). One run before and one after per page (spread not
  measured — K16's figures give the noise: F1 ± 0.01, MAE ± 0.007).
- **What is compared**: masked edge F1 / MAE, box IoU (synthetic), and per targeted key: is the
  effect kind present, its size / distance within ±1 mm, `numBg.grow` within ±2 mm, no effect on
  pages that have none, `dayFormat` on chotebuz.
- **Templates (2T)**: `scanner/eval/run.mjs` on the easy set (7) and B1–B5 (headline shadows), one
  run each, against their stored earlier runs; adopted only when the easy set's text IoU and size
  accuracy stay within their earlier spread.
