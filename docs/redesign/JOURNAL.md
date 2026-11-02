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

---

## 2026-11-02 — P3 Projects

**What.** /projects, the project overview, "Objevte další moduly", add/edit
project and the sharing dialog are rebuilt in the prototype's markup
(`renderDashboard`/`cardHTML`/`renderDetail`).

**How / files.**
- CSS: `assets/styles/nocturne/projects.css` (imported in `assets/app.js`).
- `projects.html.twig` + `_project_card.html.twig`: greeting eyebrow (by hour,
  user's first name when `User::$name` is set), h1 + live count, search,
  chips Vše · Moje projekty · Grafik ▾ (owner menu with search) · S manuálem /
  Bez manuálu, `.pcard` grid, ⋯ menu (Změnit název a logo → edit page,
  Sdílet (admin), Odstranit projekt → existing ConfirmModal), user count
  (admins: opens the share modal), owner line kept (`.project-card-owner`),
  grey `.pwarn` triangle "Chybí: barevná paleta, písma". Empty + no-results
  states. `projects_filter_controller.js` does it all client-side.
- New `Query/GetProjectCardFacts` (+ `ProjectCardRow`): one batched SQL for
  manual count, has-palette (custom colours or any detected logo colour),
  font count. Fed to the template as `facts`.
- `_project_avatar.html.twig` is now the shared `.sym` partial (vars
  `logo_path`, `initials`, `class`, `alt`): logo on white, or `.sym.mono`;
  a failing `<img>` swaps itself for the monogram (inline `onerror`). Also used
  by the sidebar trigger and the quick switcher (`_shell/_sidebar`,
  `_shell/_quick_nav` — minimal include swap).
- `project_dashboard.html.twig`: back link, `.dhead` (88 px symbol, PROJEKT,
  h1, meta users · templates · owner · founded, "Upravit projekt"), palette
  chips from the first manual with colours + pencil → `manual_colors` (when
  `manual_edit`), module tiles with grip + personal order
  (`project_tiles_controller.js`, localStorage `wb-tiles`, HTML5 DnD),
  Uživatelé box (owner + shares, role/invite pills; admins: "Přidat kolegu"
  opens ManageProjectSharing, page reloads after the modal changed sharing),
  admin "Moduly projektu" switches, "Naposledy přidané šablony" restyled.
- Module switches on the overview post to the existing enable/disable
  endpoints with `back=dashboard`; **Enable/DisableProjectModuleController
  got that redirect branch** (3 lines each, presentation only — the
  alternative was landing admins on the edit page).
- `project_modules.html.twig`: `.pd-mod` rows (icon, name, description,
  Vypnuto + switch = the enable form's submit; read-only members see
  "Zapne správce projektu"), Kalendáře "Připravujeme" row.
- `_project_form.html.twig` (add + edit): name field, `.drop` dropzone over
  the existing `icon` FileType (`logo_drop_controller.js`: drag & drop +
  preview), current logo row, remove-icon checkbox, footer actions. Edit page
  keeps `#project-modules` (now `.pd-mod` rows + switches) and history.
- `components/ManageProjectSharing.html.twig`: Nocturne share dialog
  (candidate filter field, "Má přístup" list with owner pill, remove ×).

**Decisions.** Monogram is neutral `.sym.mono` (prototype + shell), no longer
brand-coloured. Card order unchanged (own → shared → others). Filter chips
only render with ≥2 projects; "Moje projekty" only when the user owns one;
"Grafik" only with ≥2 owners. Head "Nový projekt" hides ≤860 px (the top-bar +
is the primary there). No "Paleta barev" tile — colours are per manual.

**Tests changed.** `ProjectsControllerTest` monogram assertion →
`.pcard .sym.mono` (was the inline brand-colour style). New
`ProjectModulesTest::testAdminTogglesModuleFromTheProjectOverview` +
`testOwnerDoesNotSeeModuleSwitchesOnTheOverview`.

**Cheap features implemented.** #2 completeness triangle, #3 filter chips
(without Placené — no paid flag), #7 tile order (localStorage), #8 members box
(no role management; SharingLevel has only Read).

**Verified.** shoot.cjs on all five screens 390/820/1440 × dark/light: no
overflow, no JS errors. Puppeteer: search, chips, owner menu, no-results +
clear, card ⋯ menu, share modal from card + overview, tile drag + persisted
order after reload.

**Not verified.** Adding/removing a collaborator end-to-end in the browser
(reload-after-share path), the logo dropzone with a real drop, the
`onerror` fallback with a real broken prod URL (locally Minio files 404 →
fallback seen working on desktop shots).

**Follow-ups.** Placené chip + star need #1 (paid column). The owner line
on cards is extra vs the prototype (kept for tests / admins).

---

## 2026-11-02 — P4 Brand assets (manuals, fonts, gallery, calendars)

**What.** Manual editing pages, project fonts, the gallery Live component and
the Kalendáře teaser rebuilt in the prototype's markup (renderManuals /
renderFonts / renderGallery / renderPalette).

**How / files.**
- CSS: `assets/styles/nocturne/manual.css` (manual list `.mn-*`, tabs,
  logo cards `.man-var`, palette cards `.pal-*`, settings boxes `.man-sec`,
  mockup list `.man-pg`, mockup editor chrome scoped under `.man-editor`,
  calendars `.cal-*`), `gallery.css` (`.gal-*`, shared `.gal-drop`),
  `fonts.css` (`.fn-*`); imported in `assets/app.js` after `auth.css`.
- Manual: `manuals.html.twig` (card grid, white cover → monogram fallback),
  `base_manual.html.twig` (eyebrow "Projekt · X · Manuál", Zobrazit manuál +
  ⋯ menu with edit/delete), `_manual_nav.html.twig` → underline tabs with
  counts (Logotypy n/5, Fonty, Barevná paleta + peach warn < 3, Stránky s
  mockupy). Tab pages h1 = manual name. `manual_logos` = variant cards (white
  preview well as `<label for>` the hidden SVG input, drop to replace, broken
  image placeholder, detected colours, width slider mirrored into the
  submitted number field, own-page switch, missing-variants peach alert,
  sticky save bar). `manual_colors` = palette cards (copy-HEX swatch, role,
  auto RGB, CMYK + "Dopočítat z HEX", Pantone, RAL, palette strip, info
  notice "N barvám chybí CMYK · N barev nemá roli"). `manual_fonts` = family
  cards with per-cut switches. New partials `_manual_form`,
  `_manual_font_form`; add/edit manual + manual font + mockup page restyled.
  `_mockup_page_form_editor` = `.man-sec` sections + pill save bar.
- Fonts: `fonts.html.twig` (toolbar: Nahrát písma · sample text · size
  slider with px label; always-open drop strip for editors; empty state;
  family cards with weight axis + cuts), `_font_uploader` (`.gal-drop`),
  `add_font_face`, `rename_font`.
- Gallery: `components/Project/ImageGallery.html.twig` rewritten markup for
  BOTH modes (standalone + editor modal): toolbar (upload · new folder
  inline · Koš rose with count / "Zpět do galerie|složky"), path crumbs,
  folder cards, drop strip, `.gal-card` tiles with format badge + ⋯ menu
  (Přejmenovat · Přesunout do… · Přesunout do koše), Koš view. Every
  LiveAction, `data-live-*-param`, `data-model`, `.gallery-asset
  [data-storage-path]`, rename form + caption partial kept.
  `project_gallery.html.twig` drops the wrapping card.
- Calendars: `calendars.html.twig` (dashed hero + 3 tiles + beta alert).
- JS: new `file_drop_controller.js` (styled file inputs: name, preview,
  drop → DataTransfer), `range_sync_controller.js`,
  `gallery_filter_controller.js` (format chips, name search, size slider
  → `--galmin`, `wb-galsize`; re-applied after Live morphs via
  MutationObserver). Changed: `custom_color_controller.js` (RGB, CMYK calc,
  copy, contrast), `form_collection_controller.js` (opt-in `append` value;
  add button found via `closest()` so an icon click works),
  `font_specimen_controller.js` (size label, mask-icon flash),
  `mockup_page_editor_controller.js` (ri- icons → mask spans only).

**Decisions.** Explicit Uložit kept everywhere (no autosave — backend is a
single submit). Customer output untouched: `_mockup_page`,
`_manual_color_swatch`, `_manual_*` partials used by `manual_preview` were
only styled from outside (`.man-pg .mockup-slot…`). Listing cards render the
page with `show_downloads: false` (card is a link; page file moved to the ⋯
menu). Gallery size slider/search are client-side only; format chips render
only with ≥2 formats in the folder. No Mřížka/Náhled stránek switch (not
cheap). No separate project palette page exists — the palette is per manual.

**Tests changed.** None needed (selectors kept).

**Cheap features implemented.** #17 gallery size slider (+ format chips and
search), #18 copy-CSS button per cut, #19 auto RGB + "Dopočítat z HEX",
#23 missing logo variants alert (no actions).

**Verified.** Gate (see commit). shoot.cjs on manuals list, logos, colours,
fonts (manual + project), mockup list + editor, gallery, calendars, edit
manual at 390/1440 dark and 820/1440 light. Puppeteer: gallery chips /
search / size / folder open + crumb back (Live morph) / ⋯ menu / Koš open +
back; logo width slider ↔ number; own-page switch; colour add (append) →
HEX → RGB → CMYK calc → remove. No JS errors (only Minio 403s for images).

**Not verified.** A real logo/font/gallery upload end-to-end in the browser;
the drop-to-replace on logo cards; the editor's gallery modal (same
component, `gal-modal`) inside the canvas editors; dragging (Sortable) of
palette cards, font cuts and mockup pages after the markup change (handles
are `.dragula-handle` as before). #20 read-only palette for clients needs a
controller/voter change — not done.

**Follow-ups.** Gallery "used N×" badges need a batched usage query;
folder image counts need a repository count; Náhled stránek (PDF-reader)
view; manual pages for non-admin clients (`.man-cl`).

---

## 2026-11-02 — P8 Admin & account

**What.** Uživatelé (+ inline registration requests), Pozvat uživatele, user
profile (edit), Žádosti o registraci, Poptávky z webu, Statistiky (tabs, KPI
tiles, heatmaps, themed ApexCharts), Soubory v úložišti, Můj účet, Upravit
profil, Změna hesla, Propojené aplikace, AI (MCP server) — all in Nocturne.

**How / files.**
- CSS: `assets/styles/nocturne/admin.css` (imported in `assets/app.js`).
- Users (`admin/users.html.twig`, `users_filter_controller.js`): prototype
  `.u-card` list; pending requests pinned as mint rows with Zamítnout (existing
  dismiss endpoint, new `?back=users` redirect whitelist in
  `DismissRegistrationRequestController`) / Povolit (= invite page with the
  e-mail prefilled); dismissed requests only under "Zamítnuté". Chips Vše ·
  Žádosti · Zamítnuté (if any) · Administrátoři · Designeři · Uživatelé ·
  Čekají na přijetí (if any) · Projekt ▾ (searchable, owner gets a "Vlastník"
  pill). Row ⋯ = Bootstrap dropdown: Upravit profil, Znovu poslat pozvánku
  (pending), Odebrat uživatele / Stáhnout pozvánku (existing ConfirmModal,
  same text). Ordering me → pending invites → A–Z (Czech) in
  `AdminUsersController`. New presentation query `Query/GetProjectAccess`
  (+ `ProjectAccessRow`): owner + share pairs in one SQL.
- Invite / edit user: `admin/_role_picker.html.twig` renders the `role`
  ChoiceType as radio cards (`.rcard`, real `<input type=radio>`, look via
  `:has(:checked)`, no JS); MCP scopes as `.scopes` checkbox rows; invite's
  `projectIds` as a filterable pick list (`pick_filter_controller.js`). Field
  names unchanged. Edit user = prototype profile dialog as a page (`.up-card`:
  header, Profil / Projekty (read-only, from GetProjectAccess) / AI aplikace
  sections, sticky footer). `EditUserController` passes `projects`.
- Poptávky z webu: designed — a row per lead (monogram, name/company, source
  pill, "Nová" = < 7 days, contact links, interests as tags, message excerpt
  with `<details>` expand, relative date), source chips
  (`chip_filter_controller.js`). There is no read/handled state, so no status
  beyond "Nová".
- Statistiky (`admin/usage.html.twig`, `admin/_stats_macros.html.twig`,
  `usage_stats_controller.js`): tabs Klienti (exports) / Aktivita / Úložiště
  over the three existing sections (hash + sessionStorage, so the "Načíst
  znovu" round-trip lands back on Úložiště); KPI tiles with Twig-drawn
  sparklines derived from the existing view models (queries untouched);
  client × month and user × month tables as the prototype heatmap (levels
  relative to the table max, current month peach); storage table restyled.
  ApexCharts options read `--ch-1/--ch-2/--ch-ghost/--line/--subtle/--font`
  at runtime, render when their tab is first visible, and re-render on the
  theme switch (MutationObserver on `data-theme`). Inline chart scripts gone;
  the vendor script tag stays.
- Storage files: filter toolbar card, file rows (container query), Nocturne
  pager. Account: `user_profile` (profile sections, Facebook row, links to
  apps/AI), `edit_user_profile` + `change_password` as sheets,
  `connected_apps` (designed: app + token record lists, revoke forms
  unchanged), `mcp_guide` (designed: hero with server URL, numbered connect
  steps, capability list, prompt cards, FAQ `<details>`, sticky side column;
  all copy verbatim for McpGuideControllerTest).
- `templates/admin/_macros.html.twig`: ago(), person_initials(), role /
  role_pill / status.

**Decisions.** Kept invite/edit as pages (sheet look), not modals. Dismiss
inline has no confirmation (prototype); the old requests page keeps its
ConfirmModal. Statistics tab names follow the existing sections, not the
prototype's Klienti / Celý systém / Úspory (no data/queries for the latter).

**Tests changed.** None (all selectors/texts kept; `selectButton('Uložit')`
matches "Uložit změny").

**Cheap features implemented.** #10 requests inline + Žádosti/Zamítnuté
chips; project filter on users; #12 partially (read-only Projekty section on
the user page).

**Verified.** Gate on Admin/User/Mcp tests green (63), full gate before
commit. shoot.cjs on all 12 screens 390/820/1440 × dark/light. Puppeteer:
every chip, search, Projekt ▾ (search, Enter pick, owner pill, label), row
menu + delete confirm modal, stats tabs (click, arrow keys, hash, reload),
chart re-colour on the sidebar theme switch, role card radio, pick filter +
count, blank invite blocked, lead source chip. No JS errors.

**Found, not fixed (pre-existing).** Submitting the invite form with a blank
e-mail (browser validation bypassed) 500s: `InviteUserFormData::$email` is a
non-null string — same latent "blank → NULL" bug as the memory note on
FormData. The page keeps the `required` attribute (no `novalidate`).

**Not verified.** Actual invite/dismiss/resend round-trips in the browser
(endpoints unchanged, covered by tests); real OAuth apps/tokens on
Propojené aplikace (local DB has none); usage heatmap with 12+ months of data.

**Follow-ups.** Sidebar still has a separate "Žádosti o registraci" item
(could fold into Uživatelé' count like the prototype). Prototype Celý systém /
Úspory tabs and client insights need new queries (#13/#14/#16). Users page
has no "disabled" status (#9).
