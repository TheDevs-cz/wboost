# App shell — Nocturne sidebar + phone drawer (2026-11, redesign P2)

The Hyper theme is gone (no `app-saas.min.css`, `hyper-config.js`,
`app.min.js`, `vendor.min.js`). `templates/base.html.twig` renders the
Nocturne shell from `docs/redesign/prototype/body.html`; partials live in
`templates/_shell/` and the behaviour is the `shell` Stimulus controller
(`assets/controllers/shell_controller.js`). CSS: `assets/styles/nocturne/`
(`shell.css` for the shell, the rest is the component layer).

### Loading order / globals

- `assets/app.js` imports, in this order: Bootstrap 5.3 CSS → GrapesJS CSS →
  `styles/nocturne/*.css` (tokens, base, icons, buttons, forms, chips, cards,
  dialogs, menus, tables, feedback, page, shell, auth, then the area files
  projects, manual, gallery, fonts, templates, admin, email, weekly-menu) →
  `styles/app.css` (feature rules) → `nocturne/fill.css` + `editor.css`
  (re-skin app.css's fill/editor rules) → `bootstrap_global.js` → Stimulus.
  Later wins on equal specificity, so Nocturne beats Bootstrap and `app.css`
  beats both. File map: CLAUDE.md "Design system".
- Phone header rule (P10b): when a page fills `mobile_primary_action` (the
  round + in the top bar), `.head-tools .btn-primary` is hidden ≤ 860 px —
  one primary per view; don't hide it per page.
- `assets/bootstrap_global.js` imports the Bootstrap ESM build (registers the
  `data-bs-*` data-API) and sets **`window.bootstrap`** — the Stimulus
  controllers call the global `bootstrap.Modal`. It also inits
  `[data-bs-toggle=tooltip|popover]` and the auth pages' password eye
  (`[data-password]`), the bits of Hyper's `app.min.js` we still needed.
- No icon fonts in the app (P10a): every icon is a mask icon —
  `{{ icon('name', 's16') }}` (`src/Twig/ShellExtension.php`) →
  `<span class="i i-name s16" aria-hidden="true">`, from
  `nocturne/icons.css`; JS builds the same `<span class="i i-name">`.
  Missing glyph → add one to `icons.css` (24-unit viewBox, stroke 1.8,
  round caps/joins; Lucide-compatible paths). ApexCharts lives in
  `public/vendor/apexcharts/`.
- `manual_preview.html.twig` (customer output) opts OUT: it overrides the
  `importmap` block with the `app_plain` entrypoint (no Nocturne), forces
  light, and links its own legacy look from `public/css/manual-preview/`:
  `hyper.css` (the subset of the retired Hyper sheet its markup matches —
  extracted by DOM matching, verified pixel-identical; Nunito woff fonts
  beside it) and `mdi.css` (only the 5 MDI glyphs its partials use:
  download, image, pencil, arrow-right, arrow-expand-horizontal). Adding an
  MDI icon or a Hyper class to the preview partials means adding its rule
  there.

### Theme

`<html data-theme="dark|light" data-bs-theme="…">`, set before paint by the
inline `theme_script` block from `localStorage['wb-theme']` (absent → OS
preference). Bootstrap's own `--bs-*` variables are remapped onto the
Nocturne tokens for both themes (`nocturne/tokens.css`), so un-restyled
Bootstrap components follow the theme (no Hyper `--ct-*` variables exist any
more — `app.css` reads the Nocturne tokens directly). The sidebar's
Tmavý/Světlý control (`shell#pickTheme`, rail: `shell#toggleTheme`) switches
and persists; it dispatches `shell:theme` on `<html>`.

### Layout + scrolling

`.app` = grid `var(--sbw) | 1fr`; `aside.sb` (272 px) + `main.main >
.mbar + .wrap > .inner#view`. **The document scrolls, not `.main`** (a
deliberate deviation from the prototype): the sidebar is `position: sticky`.
The canvas editors pin themselves via `body.editor-shell-page` and measure
against `window.scrollY` (`canvas_zoom_controller`, `canvas_navigator`,
`variant_fill_overlay`) — keep it that way. `.inner` is max 1320 px, padding
`32px clamp(0,3vw,24px) 72px`, flex column gap 28 between header / flashes /
`.page-body` (the `content` block is wrapped in `.page-body`, so legacy
content keeps its own spacing; redesigned pages can use `.stack`).

- **Collapsed rail** (≥ 861 px): `html.sb-rail`, 84 px, labels hidden via
  `.hide-c`, section labels become `.sb-div` hairlines, the toggle sits on the
  sidebar border. Persisted in `localStorage['wb-collapsed']`, restored by
  the head script. Toggling dispatches a window `resize` after the 250 ms
  transition (zoom auto-fit / charts re-measure).
- **Editors** (`template_variant_editor`, `template_group_editor`) render with
  `html.sb-rail data-sb-forced` server-side and `persist=false`, so the rail
  toggle there is not remembered. Their `.inner` is still the standard one
  (editor chrome redesign = P7).
- **≤ 860 px**: the sidebar is an off-canvas drawer (`html.sb-drawer`, 300 px,
  scrim, close button, Esc, closes on resize to desktop) and a sticky blurred
  `.mbar` (60 px: hamburger, centred logo, optional round `+` from the
  `mobile_primary_action` block). The editors' below-lg sticky panel clears it
  (`top: 64px` in app.css).

### Sidebar (`templates/_shell/_sidebar.html.twig`)

Top → bottom: logo (inline themable SVG `_shell/_logo.html.twig`, app icon
`_logo_icon` in the rail) + collapse / drawer-close buttons · **Všechny
projekty** with the project count (`shell_projects()|length`) · **Rychlá
navigace** trigger (current — or remembered, see below — project symbol +
name, or "Vybrat projekt…") ·
scroll area with **Projekt** (Přehled, then the module items — see below),
**Administrace** (ROLE_ADMIN: Uživatelé with the pending registration
request count `pending_registration_requests_count()` — requests are the
users page's "Žádosti" chip, so there is no separate item; Uživatelé is also
current on the still-routable `/admin/registration-requests` — Poptávky z
webu, Statistiky),
**Propojení** (Propojené aplikace, AI (MCP server)) · Vzhled toggle · profile button `.sb-me` with the drop-up
`#meMenu` (Můj účet, Změnit heslo, Odhlásit se).

- Current item = `aria-current="page"` from the page's `active_menu_item`
  (`active_submenu_item` for the Jídelníčky sub-items) or, where pages set
  none, the route (`project_dashboard` → Přehled, `connected_apps`,
  `mcp_guide`). "Všechny projekty" is current only outside a project.
- `shell_projects()` (`GetShellProjects`, one DBAL query: admins = every
  project, others = owned + shared; Czech-collated) feeds the count and the
  switcher popover `_shell/_quick_nav.html.twig` (fixed, z 1070, search,
  ↑/↓/Enter/Esc, current project ticked mint). The controller records opened
  projects in `localStorage['wb-recent']` and lifts the last 3 into
  "Naposledy otevřené". Rows show the project icon or a monogram (no manual
  logo lookup — cheap on every page).
- **Remembered project** (F15, 2026-11-05): the sidebar's project is
  `shell_project` = `shell_project(project ?? null)` (`ShellExtension`,
  called once in `base.html.twig` — the single place the shell resolves it).
  A page that passes its own `project` stores the id in the session
  (`ShellExtension::LAST_PROJECT_SESSION_KEY`); a page without one (admin,
  Propojené aplikace, AI, profile, **/projects** too — one rule, and the nav
  doesn't jump when you step back to the list) gets the remembered project:
  quick-nav trigger + ticked row + the whole PROJEKT section, but **no
  project item is `aria-current`** (`project_active` is '' — the page's own
  item, e.g. Uživatelé / Všechny projekty, is current) and the eyebrow stays
  the page's own ("Administrace"). Every render re-loads the project by PK
  and re-runs `project_view`; deleted or no longer viewable → key removed,
  nothing shown. Logout invalidates the session (Symfony default). The
  `data-shell-project-id-value` (localStorage recents) stays the page's own
  project only; the controller keeps the ticked row out of "Naposledy
  otevřené". Pinned by `ShellLastProjectTest`. The editors are project
  pages, so the rail is unaffected.
- `.side-nav-discover` is kept on the "Objevte další moduly" item (tests).
- **Project menu order** (F6, 2026-11-05; Paleta barev F7 P2): Přehled,
  Manuály, Šablony, Galerie, Fonty, **Paleta barev** (`project_palette`,
  `icon('palette')`, `active_menu_item = 'palette'`; like Fonty always shown,
  not a module — read-only for members without `project_edit`), E-maily,
  Jídelníčky (+ sub items), **Facebook a Instagram** — always last (before only the "Objevte
  další moduly" CTA), shown in EVERY project (no module key): links to
  `user_profile` when `facebook_connected()`, otherwise opens the fake-door
  modal `#social-publish-fake-door` (`_social_publish_fake_door.html.twig`,
  included once by `base.html.twig`). With no current or remembered
  project there is no PROJEKT section, so no FB item.
  Pinned by `ProjectModulesTest::testFacebookInstagramIsTheLastProjectMenuItem`.

### Page header blocks

`page_title_row` (default) renders, inside `.inner`: the `breadcrumb` block
as a quiet back-trail (`.page-crumbs`; first item gets a ← arrow drawn as
`a::before` INSIDE the link — arrow + text are one padded hit area — the
`.active` last item — which duplicates the h1 — is hidden; a breadcrumb that
is only the current page is hidden entirely), then `header.head` with the
`eyebrow` block (default: "Projekt · {name}" inside a project,
"Administrace" on `admin_*` pages), `h1.page-title` = `title` + optional
`title_count` (`<small>`), optional `page_lead`, and `title_actions` in
`.head-tools` on the right. A page that overrides `page_title_row` empty (the
editors) gets its plain breadcrumb instead. `inner_class` adds classes to
`.inner` (`inner-wide` = no max width). Flashes (`_flashes.html.twig`) render
under the header as kit alerts (`.alert.alert-{label}` + accent circle icon).

### Project add / edit dialog (2026-11-05, F2/F3)

`/projects` ("Nový projekt" header + phone primary action + empty state, card
⋯ "Změnit název a logo") and the project overview (header ⋯ "Upravit") open
ONE shared Bootstrap modal, `_project_form_dialog.html.twig`
(`#project-form-modal`, `project_form_modal_controller`). The controller
intercepts plain clicks on any `a[data-project-form-modal]` (document-level,
so the shell's phone action works too), GETs `<href>?modal=1`
(`add_project` / `edit_project` render `_project_form_modal.html.twig`
instead of the page) and POSTs it via fetch as multipart `FormData`
(logo upload). Contract: invalid → the fragment again with **422**; success →
**JSON `{redirect}`** (flash already queued): `/projects`, or the overview
when the trigger is `data-project-form-modal="dashboard"` (→ `back=dashboard`,
kept in the form action). Links keep their real href: modified clicks, fetch
failures and expired sessions fall back to the full pages, which are
unchanged (the admin "Moduly projektu" history lives only there).

### Project modules — nav shows only what the project uses (2026-10-11)

A project lists its switched-on modules in `project.modules` (JSONB list of
`ProjectModule` values: `manuals`, `templates`, `email_signatures`,
`weekly_menus`, kept in enum order). The left navigation and the project
dashboard render a module only when `project.hasModule(...)` — Twig reaches the
enum via `enum('WBoost\\Web\\Value\\ProjectModule')`. **Galerie, Fonty and
Paleta barev are not modules** (other modules draw on them — always shown); **Kalendáře** isn't
built yet, so it is gone from the nav/dashboard and only linked from the
discover page (`calendars.html.twig` highlights the discover nav item).

- **New projects** start with `ProjectModule::defaults()` = Manuály + Šablony
  (also the column default). The 2026-10-11 migration backfilled existing
  projects: core two + E-maily / Jídelníčky only where content existed.
- **Discover / upsell**: `project_modules` (`/project/{id}/modules`, "Objevte
  další moduly") lists the switched-off modules; the left-nav item
  (`.nav-i.discover.side-nav-discover`) links to it while any module is off — the only entry
  point (the dashboard tile was dropped 2026-10-12: the nav entry is enough). Anyone with `project_edit` switches a module on there
  (`project_module_enable`, POST + CSRF) and lands on it.
- **Admin mail**: `EnableProjectModuleHandler` e-mails the
  `SIGNUP_NOTIFICATION_EMAILS` admins (bound as `$adminNotificationRecipients`)
  when a **non-admin** switches a module on; admins switching don't trigger it.
- **Switching off is admin-only** (`project_module_disable`), from the
  "Moduly projektu" card on `edit_project` (`#project-modules`, ROLE_ADMIN), which
  also shows the `project_module_change` history, and from the same box on the
  project overview (`#pd-modules`; its forms post `back=dashboard`, the enable/
  disable controllers then redirect back to the overview). Off = hidden, never deleted.
- **Guard** = `#[RequiresProjectModule]` on the module LANDING controllers only
  (Manuals, Templates, EmailSignatureTemplates, WeeklyMenus, Meals, Diets,
  DishTypes); `ProjectModuleGuardListener` (CONTROLLER_ARGUMENTS, after
  `#[IsGranted]`) redirects to the discover page with an info flash. Item pages,
  the render pipeline, the API and MCP are deliberately NOT gated — this is
  decluttering, not access control. A new module = enum case + nav/dashboard
  `if` + attribute on its landing controller + backfill decision.
