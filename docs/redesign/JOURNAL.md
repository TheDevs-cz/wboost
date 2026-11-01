# Redesign journal

Running log of the Nocturne redesign: what was done, how, and why. Newest
entry at the bottom. Every agent working on the redesign appends an entry
when it finishes a unit of work (what changed, files, decisions + reasons,
what is NOT verified, follow-ups). Keep entries short and factual.

---

## 2026-11-02 — Phase 0: research & reference capture (orchestrator)

- Read the prototype through the user's Chrome: the artifact is a
  public Claude Design page whose content lives in a cross-origin frame; the
  built single-file HTML was fetched from the frame host
  (`…frame.claudeusercontent.com/_f/<version>/`) and split into
  `prototype/app.css`, `app.js`, `body.html` (+ the original file).
- Clicked through every screen and saved 39 screenshots to
  `reference/screenshots/` (dashboard, card menu, project overview + modules,
  manual logos + mockup pages, templates list + new-template dialog, editor +
  inspector + display menu, fill page + export history + share-to-social,
  users admin + profile + invite dialogs, statistics ×3 tabs, placeholders,
  light theme, profile drop-up with role preview, phone layouts).
- Observations: dark-first "Nocturne" palette (bg `#0B0A0D`, lavender accent
  `#C7B6F2`, rose/mint/peach secondaries), font Outfit, collapsible sidebar
  272/84 px, drawer under 860 px with a top bar (hamburger, logo, + FAB).
  Template editor collapses the sidebar to an icon rail. Prototype sections
  not built yet: Jídelníčky, Facebook a Instagram, Poptávky z webu,
  Propojené aplikace, AI (MCP server) → we design those ourselves in the same
  language.
- Branch `redesign/nocturne` created. Design-system spec and current-UI
  inventory delegated to two research agents (outputs: `design-system.md`,
  `inventory.md`).
- User direction: replicate the design as-is, responsive everywhere, note new
  backend features (`new-features.md`), cheap ones may be implemented, deliver
  end-to-end on the branch + merge request, keep this journal.

---

## 2026-11-02 — P1 Foundation + P2 App shell

**What.** Hyper is out of the layout; Bootstrap 5.3 stays as the behaviour
layer; a Nocturne CSS layer restyles Bootstrap and carries the prototype kit;
`base.html.twig` is the prototype shell.

**How / files.**
- CSS `assets/styles/nocturne/`: `tokens.css` (prototype `:root` + light,
  the proposed `--rose-line/--mnt-glow/--mnt-line/--pch-glow/--pch-line`,
  chart tokens, `--bs-*` remapped for both themes, `--ct-*` shim for app.css),
  `base.css`, `icons.css` (all 89 `.i-*` mask icons, generated from the
  prototype), `buttons.css`, `forms.css`, `chips.css` (+ `.badge`), `cards.css`
  (+ list-group, accordion, `.empty`, `.sk`), `dialogs.css` (`<dialog>` +
  `.modal`), `menus.css` (+ dropdown, tooltip, popover), `tables.css`,
  `feedback.css` (alerts, `.notice`, toasts, progress, spinner), `page.css`
  (`.head`, crumbs, tabs, nav-tabs/pills, pagination, toolbar card, utility
  overrides, Hyper helper classes still in use), `shell.css`, `auth.css`.
- `assets/app.js` imports Bootstrap → Nocturne → `app.css` → `bootstrap_global.js`
  (sets `window.bootstrap`, tooltip/popover init, password eye) → Stimulus.
  New `assets/app_plain.js` entrypoint (`importmap.php`) for `manual_preview`.
- Shell: `templates/base.html.twig`, `templates/_shell/{_sidebar,_quick_nav,_logo,_logo_icon}.html.twig`,
  `assets/controllers/shell_controller.js`; `_flashes.html.twig` → kit alerts.
- PHP: `src/Twig/ShellExtension.php` (`icon()`, `initials()`, `shell_projects()`
  via `#[AsTwigFunction]`), `src/Query/GetShellProjects.php` + `ShellProjectRow.php`;
  `config/services.php` loads `src/Twig/*Extension.php`.
- Moved (git mv): `public/theme/css/icons.min.css(+map)` + icon fonts →
  `public/fonts/legacy-icons/{css,fonts}/`; ApexCharts →
  `public/vendor/apexcharts/apexcharts.min.js`; `avatar-2.jpg` →
  `public/images/legacy/`. Auth/error pages use the inline logo partial.
- Removed the Hyper shell block + `.side-nav-discover` rules from `app.css`.

**Decisions.**
- Bare `.btn` stays an unstyled Bootstrap wrapper (gallery tiles use `btn p-0`);
  the pill look applies once any `btn-*`/kit modifier is present. Primary =
  `btn btn-primary` (not bare `.btn` like the prototype). Legacy `.btn-sm` = 32 px.
- Document scrolls, sidebar is sticky (prototype scrolls `.main`) — editor and
  fill controllers measure `window.scrollY` and pin via `body` overflow.
- Bootstrap `.alert` = compact tinted notice that keeps inline flow; the kit
  icon-circle look only when the alert has a direct `> .i` child.
- `html[data-bs-theme]` is set alongside `data-theme` so Bootstrap's own
  dark-mode bits (form-select arrow etc.) follow; our `:root[data-theme]`
  overrides win on specificity.
- Sidebar: Facebook a Instagram fake door moved to Propojení (stays reachable
  outside projects); Propojené aplikace + AI (MCP) moved from the account menu
  to Propojení (prototype); account drop-up = Můj účet, Změnit heslo, Odhlásit
  se. Added "Přehled" (project dashboard). Žádosti o registraci keeps its own
  item with the pending count (prototype puts the count on Uživatelé).
- Editors open with the rail forced (not persisted).
- `manual_preview` (customer output) opts out of Nocturne entirely and keeps
  linking `theme/css/app-saas.min.css` — P10 must localise what it needs.

**Tests changed.** `tests/Controller/Mcp/McpGuideControllerTest.php` (AI entry
now asserted in `.sb nav[aria-label="Propojení"]`; the "sits in the account
menu" test became `testTheNavigationEntrySitsInTheConnectionsSection`),
`tests/Controller/Project/ProjectModulesTest.php` (`.leftside-menu` → `.sb`).

**Verified.** PHPStan clean; PHPUnit OK (1610 tests). shoot.cjs on /projects,
project dashboard, templates, /admin/users at 390/820/1440 × dark/light: no
overflow, no JS errors; plus manuals, fonts, gallery, usage, profile, /ai,
connected apps, edit project, registration requests (1440+390 dark), auth
pages logged-out. Puppeteer click test: quick switcher (recents, search,
Esc), profile drop-up (outside click), theme toggle + persistence, rail
collapse + persistence, Bootstrap modal (fake door, editor #addTextModal,
fill page), Bootstrap dropdown, drawer (open, Esc, scrim) — all pass.
Editors: rail forced, `editor-shell-page` pinning still works.

**Not verified / known issues.**
- Full-page screenshots show the sticky sidebar only one viewport tall
  (screenshot artefact of `100dvh`; fine in a real browser).
- Fill page Live component stayed on "Připravuji šablonu…" in headless runs
  (local Minio images 404 — environment, not the shell).
- Recent-projects ordering is per browser (localStorage), not a user pref.
- Flatpickr / GrapesJS / ApexCharts keep their own (light) colours.
- Error pages only checked by template lint (dev shows the Symfony page).

**Follow-ups for later phases.**
- Worst-looking after the class mapping: admin/usage (ApexCharts colours,
  dense tables), manual logo edit (`manual_logos`: native file inputs,
  broken-image circles), templates list cards (`_template`, `.card-widgets`),
  project cards on /projects (Hyper card layout, grey names), editor chrome
  (left panel buttons overflow, Hyper toolbar), weekly-menu planner.
- `public/theme/fonts/Nunito-*.ttf` is read by MCP text-measuring tests — move
  it before deleting `public/theme/` in P10.
- `--ct-*` shim + `page.css` "Hyper helper classes" block → delete in P10.
