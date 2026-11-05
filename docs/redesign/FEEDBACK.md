# Redesign feedback log (preview phase)

The user's UI/UX feedback on the Nocturne redesign after it shipped (PR #8,
merged to main 2026-11-03; the temporary wboost.thedevs.cz preview is gone).
Fixes land directly on `main`, which deploys to wboost.cz. One row per item; the orchestrator owns this
file, agents only flip the status of their own items and add the commit.

Status: `open` · `wip` (agent working) · `done` (on main) ·
`deferred` (needs backend / later — reason in Notes).

| ID | Area | Feedback (short) | Status | Commit | Notes |
|---|---|---|---|---|---|
| F1 | Shell / projects | Project logos: no white plate, no padding — the bare logo fills the symbol box everywhere (cards, detail, sidebar, quick nav, admin). Monogram keeps the dark plate. | done | (this commit) | dark-on-transparent logos are now low-contrast in the dark theme (user's call) |
| F2 | Projects | /projects: "Upravit" (card menu) and "Nový projekt" open the form in a modal like Sdílet, without leaving the page | wip | | subagent |
| F3 | Project detail | Header: "Upravit projekt" → ⋯ menu (Upravit = F2 modal, Sdílet projekt); remove the "Naposledy přidané šablony" card; meta line keeps only "Založeno …" (drop users/templates/owner) | wip | | same subagent as F2 |
