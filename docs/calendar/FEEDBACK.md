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
| K1 | AI scan (step 2) | Before the scanner: a „Nejprve si připravte“ checklist — logo (SVG, PNG), the calendar's fonts uploaded, colour palette, page 1 of the calendar with all icons („pracovní verze“) — to set expectations | done | k1-scan-prep | (this commit) | product owner's comment; upload screen of the scan panel, live font/palette counts from `entry` |
| K2 | Editor (step 3) — image effects | Photoshop-like layer effects for images: drop shadow, inner shadow, outer / inner glow with PS-like settings; gradient overlays (several, each with its own settings + blend mode from the full PS list), applied to one page's image or to all pages | wip | k2-image-effects | | big — research + plan first (`docs/calendar/effects-plan.md`), Jan approves before build |
| K3 | AI scan review — Písma | The „Písma“ card of the scan review is too quiet: red exclamation marks / clearer error state so the panel is noticed when fonts could not be determined or were substituted | wip | k1-scan-prep | | same files as K1 → same agent, after K1; prod calendar 01a1e35d-acae-7367-b7db-fbb8775a2f6f |
| K4 | Bug — AI scan apply, 14 days | 14-day calendar: „Nahradit rozvržení“ in the scan's pictures step ends with the toast „Návrh už není k dispozici.“ (`wrong_state` from accept); user had saved + clicked Pokračovat first | wip | k4-scan-apply-bug | | prod calendar 01a1e35d-acae-7367-b7db-fbb8775a2f6f, reported ~2026-11-16 |
| K5 | Editor — Kalendárium day numbers | A background band behind the day-number column, reaching into the bleed at the outer page edge (right for numbers on the right, left for left, both columns on 14 days) | wip | k5-number-band | | PO: „přidat možnost, pozadí za čísly… roztáhnout do spadávky“ |
