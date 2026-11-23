# Kalendáře — feedback log (post-launch)

Bugs, UI and UX feedback on the calendar module after its launch
(2026-11-11). Fixes land directly on `main`, which deploys to wboost.cz.
One row per item. The orchestrator owns this file; agents only flip the
status of their own items and add the commit.

Contracts live in `docs/architecture/calendars.md` — when an item changes a
contract described there, that doc is updated in the same commit.

Status: `open` · `wip` (agent working) · `done` (on main) ·
`deferred` (later / needs a decision — reason in Notes).

| ID | Area | Feedback (short) | Status | Agent | Commit | Notes |
|---|---|---|---|---|---|---|
| K1 | AI scan (step 2) | Before the scanner: a „Nejprve si připravte“ checklist — logo (SVG, PNG), the calendar's fonts uploaded, colour palette, page 1 of the calendar with all icons („pracovní verze“) — to set expectations | done | k1-scan-prep | 4f909067 | product owner's comment; upload screen of the scan panel, live font/palette counts from `entry` |
| K2 | Editor (step 3) — image effects | Photoshop-like layer effects for images: drop shadow, inner shadow, outer / inner glow with PS-like settings; gradient overlays (several, each with its own settings + blend mode from the full PS list), applied to one page's image or to all pages | wip | k2-image-effects | | big — research + plan first (`docs/calendar/effects-plan.md`), Jan approves before build |
| K3 | AI scan review — Písma | The „Písma“ card of the scan review is too quiet: red exclamation marks / clearer error state so the panel is noticed when fonts could not be determined or were substituted | done | k1-scan-prep | 67a3efc4 | rows with rose / peach / mint states, counts as pills, flagged border, „Nahrát písma“ step, a line in the summary; same files as K1 → same agent, after K1; prod calendar 01a1e35d-acae-7367-b7db-fbb8775a2f6f |
| K4 | Bug — AI scan apply, 14 days | 14-day calendar: „Nahradit rozvržení“ in the scan's pictures step ends with the toast „Návrh už není k dispozici.“ (`wrong_state` from accept); user had saved + clicked Pokračovat first | done | k4-scan-apply-bug | fix(calendar): a calendar scan is built for the calendar as saved (K4) | root cause (prod rows): 14 days chosen in step 1 NOT saved → scan 01a1e35f built for the stored 7-day calendar (photoRight) → user saved (fortnight) → accept refused (preset ≠ twoSided). Now: start saves first; type-changed proposal says so, no apply; refusal carries `reason: calendar_type` |
| K5 | Editor — Kalendárium day numbers | A background band behind the day-number column, reaching into the bleed at the outer page edge (right for numbers on the right, left for left, both columns on 14 days) | done | k5-number-band | (this commit) | PO: „přidat možnost, pozadí za čísly… roztáhnout do spadávky“ — „Pozadí čísel“ (colour + Krytí) in Kalendárium and Číslo dne; `cal.numBg`, off by default; verified step 3 / 5 + print, approval, CMYK PDFs (7 + 14 days) |
| K6 | AI scan — pictures step | Each place card („Logo“, „Obrázek v hlavičce“…) states the picture's dimensions (mm incl. bleed + recommended px) — the bleed must always be counted | done | k1-scan-prep | (this commit) | size in mm incl. bleed + px at 300/200 dpi on every place card, low-dpi hint for a held picture; after K3, same files; prod calendar 01a1e129-173c-7059-8cfa-7329a11fb327 |
| K11 | Step 4/5 — Fotky a popisky | Each week row shows the uploaded photo's file name (at least); + PO: start the caption from the file name | done | k11-photo-names | (this commit) | filled rows show `name · W × H px` (middle ellipsis, full name in `title`) + the warnings' „Nízká kvalita — N dpi“ (`lowPhotoDpi`, one threshold); an upload in this tool into a week with no caption and no embedded description starts the caption from the cleaned file name (`model/photo-caption.js`, camera names like `IMG_1234` skipped); designer + client; e2e content-pages / client-tools |
