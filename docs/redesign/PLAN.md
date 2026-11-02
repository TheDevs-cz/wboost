# Nocturne redesign — plan & status

Orchestration state for a long-running, multi-agent delivery. Agents: read
`README.md`, this file, `design-system.md`, `inventory.md`, and the relevant
`docs/architecture/*.md` before you start; append to `JOURNAL.md` when you
finish; tick your phase's boxes here.

## Architecture decisions (binding)

1. **Hyper goes, Bootstrap stays as a behaviour layer.** Remove
   `public/theme/css/app-saas.min.css`, `hyper-config.js`, `app.min.js`,
   `vendor.min.js` (jQuery/simplebar/lucide). Bootstrap 5.3 JS
   (`bootstrap.bundle` — Modal/Dropdown/Collapse/Tooltip) stays and must
   remain available as `window.bootstrap` (23 controller calls depend on it).
   Bootstrap CSS stays for grid/utilities/component *mechanics*; its look is
   overridden by the Nocturne layer, which loads **after** Bootstrap (this also
   retires the "app.css loses to the theme" ordering gotcha — update
   CLAUDE.md + `docs/architecture/app-shell.md` accordingly).
2. **Nocturne CSS lives in `assets/styles/nocturne/`**, one file per concern
   (`tokens.css`, `base.css`, `icons.css`, `buttons.css`, `forms.css`,
   `chips.css`, `cards.css`, `dialogs.css` (Bootstrap `.modal` + native
   `<dialog>`), `menus.css`, `tables.css`, `feedback.css` (alerts, toasts,
   badges, empty states), `shell.css`, `page.css` (page header, tabs,
   toolbars) + one file per screen area (`dashboard.css`, `project.css`,
   `manual.css`, `templates.css`, `fill.css`, `editor.css`, `admin.css`,
   `auth.css` …)). Imported from an `assets/styles/nocturne/index.css`
   (or from `assets/app.js`) after Bootstrap. Values are copied from
   `prototype/app.css` — do not invent new colours/radii.
   The existing `assets/styles/app.css` stays for feature styles; Hyper-coupled
   rules in it (`--ct-*`, `.leftside-menu`, shell block ~3844–3962) are migrated
   or deleted.
3. **Bootstrap classes are restyled, not mass-renamed.** `.btn-primary` →
   lavender pill, `.btn-light/.btn-secondary/.btn-outline-*` → tertiary,
   `.btn-danger` → rose, `.btn-success` → mint, `.card` → Nocturne card,
   `.form-control/.form-select` → Nocturne input, `.dropdown-menu` → Nocturne
   menu, `.modal-content` → Nocturne dialog, `.badge` → pill, `.alert-*` →
   notice, `.table` → Nocturne rows, `.nav-tabs` → underline tabs. This keeps
   the 655 `btn-*` usages and all tests that select on Bootstrap classes working
   while every page instantly inherits the look. Key screens are then rebuilt
   with the prototype's own markup/classes for fidelity.
4. **Theme:** dark default, `<html data-theme="light|dark">`, stored in
   `localStorage['wb-theme']`, applied by a tiny inline script in `<head>`
   before CSS paints (no flash); Tmavý/Světlý toggle in the sidebar. Headless
   render / manual preview / public weekly-menu pages are NOT themed.
5. **Icons:** copy the prototype's mask icon set (`.i.i-name`, 87 names) to
   `assets/styles/nocturne/icons.css`. Provide a Twig helper/macro
   (`{{ icon('share') }}` → `<span class="i i-share" aria-hidden="true">`).
   MDI/Unicons/Remix stay loaded (move `icons.min.css` + fonts out of
   `public/theme/` to e.g. `public/fonts/legacy-icons/`) until the final sweep
   converts remaining usages; redesigned screens use `.i` icons only.
6. **Font:** Outfit 400/500/600/700 (Google Fonts link in base, preconnect).
7. **Untouchable surfaces:** `templates/api/template_variant_render.html.twig`,
   `echo_golden_harness`, `assets/editor/*.js` classic scripts,
   `manual_preview.html.twig` + `_manual_*`/`_mockup_page*` customer output,
   weekly-menu public/approval/PDF pages, emails. Editor/fill pages: restyle
   chrome only; keep every id, `data-*` hook, modal id and controller contract.
8. **No backend business-logic changes.** Template/controller variable
   additions for presentation are fine. New features → `new-features.md`;
   only implement if trivially cheap and self-contained (note it in the journal).
9. **Responsive is a requirement**: every page checked at 390 / 820 / 1440 px
   in both themes with `docs/redesign/tools/shoot.cjs` (flags horizontal
   overflow + JS errors). Sidebar → drawer under 860 px exactly like the
   prototype (`reference/screenshots/41-43`).

## Working conventions for agents

- Work directly in `/Users/janmikes/www/brand-manuals` on branch
  `redesign/nocturne` (Docker mounts this dir; app at http://localhost:18080,
  login `redesign@test.cz` / `Redesign123`, ROLE_ADMIN).
- Never `git add -A`; commit with an explicit pathspec
  (`git commit -m … -- <paths>`); another agent may be working in parallel.
  Do not push; the orchestrator pushes and opens the MR.
- After CSS/JS/asset changes: `docker compose exec -T web rm -rf var/cache/dev public/assets`.
- Gates before each commit: `docker compose exec -T web composer phpstan`
  and `docker compose exec -T web vendor/bin/phpunit` (full suite — some tests
  parse JS/HTML). If a test asserted on a removed theme class, update the test
  to the new stable selector and say so in the journal.
- Visual check: `node docs/redesign/tools/shoot.cjs <scratch dir> /path …`, then
  look at the PNGs and compare with `reference/screenshots/`. Never commit
  screenshots of real client data.
- Copy is Czech; match the prototype's wording where the screen exists.

## Phases

- [x] **P1 Foundation** — Nocturne tokens + component layer over Bootstrap,
      Outfit, icons, theme toggle, remove Hyper CSS/JS, keep `window.bootstrap`.
      Every page must still render (restyled via class mapping).
- [x] **P2 App shell** — new `base.html.twig` sidebar (logo, Všechny projekty +
      count, Rychlá navigace switcher, Projekt nav from `project.modules`,
      Administrace, Propojení, theme toggle, profile drop-up), collapse 272/84
      (persisted), drawer + top bar < 860 px, page header pattern
      (eyebrow / h1 + count / actions), flashes → toasts/notices. Editor pages
      get the icon rail variant. Update `docs/architecture/app-shell.md`.
- [x] **P3 Projects** — dashboard (`/projects`: greeting eyebrow, search,
      filter chips that map to existing data, card grid + card menu), project
      overview (`project_dashboard`: header, palette chips, module tiles, users
      box, modules box), add/edit project, project modules page.
- [ ] **P4 Brand assets** — manuals list/edit + logos + colours + fonts +
      mockup pages, project fonts, gallery (Live component), calendars.
- [ ] **P5 Templates** — templates list (categories, Nejpoužívanější if data
      exists, card style), categories, variants, add template (type picker
      look), documents pages, export history page, print settings, export_failed.
- [ ] **P6 Fill pages** — single / group / document fill chrome (header
      actions row, toggles row, format cards), export-history popover, keep the
      overlay contract.
- [ ] **P7 Editors** — variant + group editor chrome (top bar, format tabs,
      tool row, layers panel, inspector, navigator, display menu), icon-rail
      sidebar. Chrome only.
- [ ] **P8 Admin & account** — users (table rows + filter chips), invite /
      edit user, registration requests, landing leads (Poptávky z webu),
      statistiky (`admin_usage`, KPI tiles + charts restyled), storage files,
      profile / change password / connected apps / MCP guide.
- [ ] **P9 Auth, errors, rest** — login, registration, set/forgotten password,
      oauth consent, error pages, email signatures, weekly menus (app pages).
- [ ] **P10 Sweep & delivery** — convert remaining mdi/uil/ri icons, delete
      `public/theme/`, purge dead Hyper rules, full responsive QA pass of every
      route (both themes), docs (CLAUDE.md, architecture docs), `new-features.md`
      reconciled against what wboost already has, push + merge request.

## Status log

(orchestrator updates per phase: started/finished, commit range)

- 2026-11-02 — P1 + P2 done (one agent; see JOURNAL). Editors got the
  forced icon rail only; their chrome is still P7.
- 2026-11-02 P1+P2 done (2970a3b). Shared agent brief + locked gate added. P3 (Projects) and P4 (Brand assets) started in parallel.
