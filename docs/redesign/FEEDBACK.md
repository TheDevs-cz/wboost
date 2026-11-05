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
| F2 | Projects | /projects: "Upravit" (card menu) and "Nový projekt" open the form in a modal like Sdílet, without leaving the page | done | b6722ddd | one shared dialog (`_project_form_dialog`, `?modal=1` fragments); committed together with F3 (shared partial/JS/CSS) |
| F3 | Project detail | Header: "Upravit projekt" → ⋯ menu (Upravit = F2 modal, Sdílet projekt); remove the "Naposledy přidané šablony" card; meta line keeps only "Založeno …" (drop users/templates/owner) | done | b6722ddd | with F2; admin module-change history now only on the full /edit-project page |
| F4 | Templates list | All categories collapsed on load, only "Nejpoužívanější" open; drag & drop to reorder categories (Nejpoužívanější pinned first) — reuses existing SortTemplateCategories | done | a8e9d627 | touch drag on a real phone not verified; dragula_controller got a mid-drag destroy guard |
| F5 | Manuals list | Remove the logo colour circles from the manual cards | done | (this commit) | |
| F6 | Shell nav | "Facebook a Instagram" moves from the global Propojení section into the project menu (last item, shown per enabled modules) | done | c1037bfc | decided: move the link/fake-door as is to the end of the project menu, no module |
| F7 | New module | "Paleta barev" project module (nav item after Fonty): colours from all the project's manuals in one global palette — brainstorm + proposal first | wip | 914e07e4 (P0) | decided 2026-11-05: option C (project palette = source of truth, manuals pick from it, prod migration); always shown, not a module. Plan first: docs/redesign/palette-plan.md |
| F8 | Manual pages | Manual top tabs: drop Fonty + Barevná paleta (project-level now), keep only Logotypy + Mockupy | partial (fonts done; Barevná paleta tab waits for F7) | 31d5e6f5 | fonts: move manual font editing into the project Fonty page; colours: goes with F7 |
| F9 | Manual logos | "V manuálu chybí N varianty loga" warning can be dismissed permanently; re-appears when a logo is uploaded/removed (any change of the manual's logo set) | done | b2922c43 | subagent; localStorage, no backend |
| F10 | Manual logos | "Šířka loga v manuálu": drop the value label above the number box ("Výchozí" / "21 %") — the box placeholder "výchozí" says it; fix the truncated placeholder | done | b2922c43 | same subagent as F9 |
| F11 | Bug (found) | Saving a manual font always clears its enabled cuts (`ManualFont.php:75` compares the font id with the ManualFont's own id) | done | 56b45dea | user: fix now |
| F12 | Bug (found) | `SortTemplateCategoriesHandler` does not check the posted category ids belong to the project in the URL | done | 56b45dea | pre-existing, found by the F4 agent |
| F13 | Manual mockup pages | Prototype layout: toolbar (Přidat stránku · "Pořadí změníte přetažením" · Mřížka / Náhled stránek toggle); Náhled = big page previews in the middle + sticky "Stránky N" thumbnail strip on the right | done | 6bf953d4 | subagent |
| F14 | Templates list | "Synchronizováno" pill on synced (multi-format) template cards is misleading → icon-only "layers" pill with tooltip "Šablona ve více formátech…" | done | 3a09af9a | |
| F15 | Shell nav | Remember the last visited project on admin / non-project pages: quick nav keeps it selected and the PROJEKT section stays | done | 8e1fc6e0 | subagent |
| F16 | Template editor | Bug report: "Přestal fungovat a zobrazovat se vzorový text" (sample text / sampleValue) on /template-variant/01a19bda-5cce-7317-badb-98889a8016c4/editor | wip | | debug subagent |
| F17 | Admin statistics | /admin/usage heatmap: hovering a row breaks the cells (borders between cells vanish, row grows) | done | 112a9e9b | with F18 |
| F18 | Admin statistics | Heatmap rework (client feedback): rows show user NAME + round badge with project count, projects collapsed → expand on click; search; numbers visible without hover — cells show downloads (Stažení / Šablony toggle, tooltip both) | done | 112a9e9b, c2418c65 | same subagent as F17 |
| F19 | Template editor | Container popover ("Kontejner": Mezera mezi prvky / Mezera za kontejnerem / Max. výška) — titles unreadable: light popover background with dark-theme label colours | done | a7ffe077 | |
| F20 | Editor gallery modal | "Vyberte obrázek" modal (editor) lacks the tile-size slider the standalone gallery has — add it | done | 61ee6f51 | subagent |
| F21 | Gallery picker (phone) | At 390 px the picker toolbar's "Nová složka" button overflows; fill-page pickers (single/group/document) still have no size slider | open | | found by the F20 agent |
