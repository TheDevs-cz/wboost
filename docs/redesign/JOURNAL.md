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

---

## 2026-11-02 — P5 Templates (listing & management pages)

**What.** The Šablony listing, the "Nová šablona" type picker, categories,
all template / variant / group / document create + edit forms, the variants
page, the document page, the export-history page and export_failed rebuilt in
the prototype's language (renderTemplates / tpCard / TP_TYPES). Fill pages and
editors untouched.

**How / files.**
- CSS: `assets/styles/nocturne/templates.css` (import line in `assets/app.js`).
- Listing: `templates.html.twig` (lead, `.gal-nav.tp-nav` toolbar: Nová šablona
  → dialog, Kategorie, Sbalit/Rozbalit vše, size slider, search; category
  sections `.tp-group` with heading + count + round chevron; "Nezařazené" is a
  section too, named "Šablony" when there are no categories), cards
  `_template.html.twig` / `_document.html.twig` (`.tp-card`: grip
  `.dragula-handle`, title, ⋯ Bootstrap dropdown, preview `.tp-prev` with a
  placeholder when the image is missing/broken, "Naposledy exportováno …" /
  "Zatím neexportováno" / "N× exportováno za 30 dní", format pills deduped,
  lavender "Synchronizováno" pill, peach "Dokument" pill + page count).
  New `templates_list_controller.js`: collapse state per project
  (`wb-tpclosed-<projectId>`), toggle all, search (opens all sections, hides
  Nejpoužívanější + empty sections, "Nic jsme nenašli"), slider (`wb-sz2-tp`
  → `--tpmin/--tph/--tpfs`). Sorting stays the shared Sortable `dragula`
  controller per category grid (`data-entity-id` now on the `<li>`).
- Card links: grouped → group editor (designers) / group fill; plain
  template with ONE variant → variant editor (if `template_variant_edit`) or
  fill page; several variants → variants page (unchanged). Menus keep every
  former action (+ "Upravit design", "Vyplnit a exportovat", "Varianty a
  formáty" on plain cards). The toolbar "Galerie" shortcut was dropped (it is
  in the sidebar).
- `_template_new_dialog.html.twig` (Bootstrap modal `#templateNewModal`):
  four type cards with the prototype wireframes; each card = stretched link
  to `add_template` / `add_template_group` (+ "Vyjít z existující šablony" →
  `template_group_from_template`) / `add_template_document`; group + document
  cards only for ROLE_DESIGNER (as before); "Šablona z obrázku" Premium card
  is disabled ("Brzy").
- Nejpoužívanější: new read-only `Query/GetTemplateExportCounts` (export_event
  per template since a date) + `TemplatesController::mostUsed()` — top 4 cards
  by exports in the last 30 days, a document sums its pages' templates; the
  row renders only with ≥2 used cards (prototype rule). Top cards have no sort
  id, menu or modals (keeps ids / "exactly one copy link" assertions intact).
  Gotcha: the partial flag is `is_top` — a param named `top` collided with the
  controller's `top` list leaking through the include context.
- Forms: shared `_template_dimension_fields.html.twig` (preset chips +
  unit/width/height, same `template-dimension` params/targets) used by
  add_template / add_template_variant / add_template_group_dimension /
  add_template_document; `_print_settings_fields` + `_background_gallery_picker`
  restyled (same targets, `d-none` toggles kept); `_template_image_field`
  (file-drop zone over the template `image` FileType). `.tp-sec` boxes,
  `.tp-foot` actions. add_template_group: numbered sections, preset switch
  cards each with its background picker, custom-dimension rows from a
  `dimension_row` macro (the collection prototype is the same macro,
  `|e('html_attr')`; `.form-group` row hook kept).
- Categories page: tag chips (grip · name → edit · pencil · trash →
  ConfirmModal) + dashed "Přidat kategorii"; still sortable (`data-entity-id`).
- template_variants: `.tp-card` grid, ⋯ menu, transparent-background
  checkerboard, px size pill. template_group_from_template: source cards with
  thumbnail tiles. template_document: header pills + actions in the page
  header, sticky page rail (`.document-pages` / `__item` / `__thumb` /
  `is-active` / `data-entity-id` kept; horizontal strip < 860 px), page card,
  shared fields as rows (`_document_shared_fields`, all data hooks kept), all
  modals restyled. template_export_history: still a `<table>` with
  `tr[data-export-version]` (tests), restyled + stacked into cards < 700 px;
  pin/rename partials and the form anchors untouched (shared with P6 fill
  menus). export_failed: rose empty-state card.

**Tests.** New `tests/Controller/Template/TemplatesListingTest.php` (top row
ranking + 30-day window, no duplicate ids/copy links in the top row, dialog
links + disabled Premium card). No existing test changed.

**Cheap features implemented.** #24 collapsible categories (localStorage),
#25 Nejpoužívanější, #26 one "Nová šablona" type dialog.

**Verified.** Gate green. shoot.cjs on all 16 P5 routes at 1440 dark and
listing / document / group wizard / history / variants / categories at
390+820 × light+dark (history overflowed at 390 → fixed). Puppeteer: top row
(with temporary dev export events, deleted afterwards), collapse + persisted
after reload, toggle all, search + no-results, slider, card menu, dialog
links at 1440 and 390. No JS errors.

**Not verified.** Drag-reordering cards / categories / document pages after
the markup change (handles + `data-entity-id` as before); the gallery picker
modal and file drop on the new forms; adding/removing custom dimension rows
in the group wizard in a browser; real thumbnails (local Minio 404s → all
previews showed the placeholder).

**Follow-ups.** Category rename inline / drag a card into another category /
category-delete "move to" picker (prototype) need backend. Document cards have
no "naposledy exportováno" (document export versions are not in
`latestForProjectTemplates`). P6 should restyle the shared pin/rename partials.

---

## 2026-11-02 — P6 Fill pages (single / group / document)

**What.** The three fill pages in the prototype's "Vyplnit a exportovat" look
(renderTpFill / fiHist): back eyebrow "← PROJEKT · ŠABLONY", h1 = template /
group / document name, actions row, `.fi-bar` toggles row, format cards, the
export-history popover, restyled pop-ups, "Všechna pole" panel, image pickers,
placement toolbar, document page strip, fake-door share dialog. Overlay
contract (ids, targets, data-* hooks, Live/ignore subtrees, popovers fixed
outside the stage, fit zoom, echo, settle) untouched.

**How / files.**
- CSS `assets/styles/nocturne/fill.css`, imported in `assets/app.js` AFTER
  `app.css` (it re-skins app.css's fill-overlay rules; equal specificity wins).
- Single page (`template_variant_export`, `components/VariantFiller`): header
  rendered in the content block INSIDE the fill-draft wrapper (so the draft
  button can live in the header): Historie exportů ▾ · Upravit design ·
  draft status button. Toolbar: switches, Všechna pole, Náhled, zoom −/%/+,
  "Přizpůsobit obrazovce" (new `variant-fill-overlay#zoomFit` + `fitButton`
  target: back to the auto fit). The preview sits in a full-width `.fi-card`
  whose header holds "1:1 · 1080 × 1080 px" + Sdílet na sítě (FB/IG publish
  menu when connected, else the fake door — as before) + PDF pro tisk +
  primary "Stáhnout PNG" (all still exportButton targets inside the form).
  The export split dropdown is gone (its items moved into "Sdílet").
- Group page: header inside the form (history, Upravit design, Sdílet na
  sítě → fake door (new, trivially cheap), draft, primary "Stáhnout vše (ZIP) n"
  split with the print PDFs). Cards `.fi-grid`/`.fi-card` with `.fi-ch`
  header (label, px, overflow pill, PNG/PDF pills). New
  `fill_formats_controller.js` + `_fill_format_controls.html.twig`:
  "Přizpůsobit obrazovce" (prototype fiAuto formula; phones ≤700 px fit by
  width), format chips (on-screen only — ZIP still carries every format; the
  last chip cannot be switched off), size slider 240–820 (`wb-fisize`).
- Document page: same header/bar; page strip + pages restyled.
- Shared partials: `_fill_overlay_toggles` (`.fi-sw` switches around the
  same `#fill-*-toggle` checkboxes; label "Oblasti k vyplnění"),
  `_fill_overlay_boxes` (mask icons; lavender text / mint image / peach
  colour boxes, tags and dots via CSS; tags counter-scaled by `--fill-zoom`
  so they stay readable on the zoomed single page), `_fill_text_popover`,
  `_fill_shape_popover`, `_fill_panel_header` (Hotovo + ×, deliberately not
  `.btn` — openPanel focuses the first `button.btn`), `_fill_gallery_tile`
  (prototype `.fi-gt` tile; caption partial + selectors kept),
  `_fill_field_reorder_handle`, new `_fill_draft_button` (label doubles as
  status: fill_draft_controller sets `data-saved` after a save, drops it when
  the form differs again).
- History: `_export_history_menu*` = `.fi-hpop` (header, pinned rows on
  lavender, loaded row with lavender edge, rename/pin `.fi-hb` icons,
  "Zobrazit vše (N)", "Zpět na výchozí hodnoty"); Bootstrap dropdown,
  `#export-history-*` ids, Turbo Stream targets and the "Připnuté verze" /
  `text-truncate fw-semibold` strings kept. `_export_version_pin_button` /
  `_rename_form` (also on the history page) → mask icons, `.fi-hb.on`.
- `_social_publish_fake_door`: Nocturne dialog ("Sdílet na sítě", FB/IG/AI
  tiles). The full AI share UI (screen 24) stays a new feature (#31).
- JS changed minimally: `variant_fill_overlay_controller` (preview toggle no
  longer swaps Bootstrap btn classes, icon swaps on `.i` mask icons for the
  preview button and every eye; `zoomFit`), `fill_draft_controller`
  (`data-saved`).

**Tests changed.** None.

**Verified.** Gate green. Own puppeteer scripts (isMobile:false) on the single
page (2 variants incl. an image slot), group (3 formats) and document page at
390/820/1440 × dark/light: no overflow, no JS errors. Clicked: history open,
rename pencil + Esc (menu stays open), text pop-up + typing (echo), Uložit,
"Všechna pole" (focus lands in the first field) + Hotovo, Náhled (aria +
icon), image picker modal, eye toggle (icon swap), fake door, format chip
off/on, draft save (Turbo stream relabels the history toggle, button →
"Rozpracované uloženo"; this left one draft on variant 01a18d6e-e0a6 in the
dev DB).

**Not verified.** Real FB/IG publish modal (no connected account locally),
rich-text / checklist pop-ups (no such inputs in local data), group image
placement toolbar + group image picker pick, admin drag-reorder in the panel,
fonts/images (Minio 403 → Live previews render with fallback fonts).

**Follow-ups.** ZIP of only the chip-selected formats needs a backend param;
prototype multi-column "Všechna pole" dialog with live thumbnails, container
"+ Řádek" / hidden-rows pills, eyedropper (#29), AI share dialog (#31).

---

## 2026-11-02 — P9 Auth, errors, e-maily, jídelníčky

**What.** Signed-out / decision screens, error pages, the E-maily (email
signatures) module and the Jídelníčky (weekly menus) app pages in Nocturne.

**How / files.**
- New layout `templates/base_auth.html.twig` (extends base, overrides
  `full_content`): centred `.auth-card` on `--bg` with a radial `--lav-glow`
  behind it, wordmark on top — built on the prototype `.signed-out/.so-card`.
  Blocks `auth_card_class` (`wide`, `err`), `auth_body`, `auth_below`. Used by
  login, registration, set_password, forgotten_password, oauth_consent,
  facebook_data_deletion_status and the three error pages.
  CSS `assets/styles/nocturne/auth.css` rewritten (Hyper `account-pages` /
  `authentication-bg` / `.password-eye` rules dropped — nothing uses them now).
- Login: `.inp` fields with icons, "Zapomněli jste heslo?" beside the label,
  show/hide password button (`.auth-eye[data-password]`, still toggled by
  `bootstrap_global.js`), "nebo" divider, Facebook tertiary button.
  `.alert-danger` on errors, field names + CSRF unchanged.
- OAuth consent: plug icon circle, warning alert (copy verbatim), connected
  account box, scopes as read-only rows like the prototype AI scopes
  (`.auth-scopes`, mint `.chk` / rose for unknown scopes), Odmítnout tertiary
  + Povolit přístup primary (button names `deny`/`approve` kept).
- Error pages: shared `bundles/TwigBundle/Exception/_error_page.html.twig`
  (empty-state: icon circle, big code, title, text, Zpět + "Zpět na
  projekty"). `error.html.twig` maps 403/410/429/503 to their own copy,
  everything else generic; ≥500 uses the rose variant.
- E-maily (`assets/styles/nocturne/email.css`): template cards (white cover
  well with the background image → mail-icon fallback on error), ⋯ menu;
  variants as person rows (avatar, name link, "Odeslat zkušební e-mail",
  ⋯ menu); variant editor = "Texty podpisu" box (sticky) + Náhled (white well —
  e-mail HTML is customer output) + HTML kód box; add/edit forms reuse P8's
  `.adm-sheet`; GrapesJS page = Nocturne frame with a toolbar (Uložit + mint
  "Uloženo" status, `successMsg` target still toggles `d-none`), editor height
  from the viewport (`.es-gjs`), GrapesJS UI itself untouched. Demo modal:
  `.inp` rows, ghost × remove, lavender "Přidat další adresu"; all
  `email-demo-form` targets/`.invisible`/`.d-none` contracts kept.
- Jídelníčky (`assets/styles/nocturne/weekly-menu.css`): module tabs partial
  `weekly_menu/_nav.html.twig` (Jídelníčky · Seznam jídel · Diety · Druhy
  jídel) on the list pages; menu cards with approval status pill
  (`weekly_menu/_status_pill`), ⋯ menu (forms + confirm() unchanged),
  "Sestavit"; add/edit menu (`_menu_form_fields`, flatpickr-range inside an
  `.inp`), edit page "Schvalovací proces" box with an event list; planner
  page header (Spravovat jídla · Upravit údaje · Náhled) and the
  `WeeklyMenuPlanner` Live component rebuilt: weekly summary as KPI tiles,
  day / meal type / course `<details>` as nested cards, meal-type colour only
  as `--mt` dot + 3 px bar, `weekly_menu/_nutrition_pills`, mask icons, meal
  cards (`partials/_meal_card`) restyled; every Live action/param, id,
  sortable target/handle and modal trigger kept. Meal picker + variant-count
  + preview modals restyled (preview shows `_menu_display` on a white sheet).
  Meals = row list with type chips (`chip-filter`), diets / dish types =
  tiles, meal form = three `.pd-box` sections (`weekly_menu/_meal_form`, shared
  by add/edit), diet / dish-type forms = sheets.
- `app.css`: the whole weekly-menu block ("Weekly Menu Planner Styles" …
  ".collapsible-day .dropdown", ~480 lines) moved into weekly-menu.css
  (Dragula `.gu-*` rules stay — shared).
- JS: `collection_controller.js` — new variant items get `.wm-var` and a
  mask-icon ghost-danger remove button (only add/edit meal use it).
- Shared-file fix (genuine bug): `forms.css` tom-select multi chips — the
  lazily loaded `tom-select.bootstrap5.css` beat our `.ts-control > div` rule
  (white chips); selector now also matches `div.item` (+ `.active`, remove
  button radius).
- flatpickr calendar themed in weekly-menu.css (only these forms use it),
  `html`-prefixed because flatpickr.min.css loads after Nocturne.

**Decisions.** Head primary per page: list pages = "Nový …"; planner = Náhled
(planner autosaves). Variants are people → rows; templates, menus → cards.
`weekly_menu/public`, `approval`, `pdf` untouched. Error pages keep linking
to `projects` (was `dashboard`). Edit-menu / planner titles are the menu name.

**Tests changed.** `SetPasswordControllerTest` asserted `h4` → now `h1`
(the card headings are real h1s now).

**Cheap features implemented.** Meal-type filter chips on Seznam jídel;
specific 403/410/429/503 copy on the generic error page.

**Verified.** Gate (PHPStan + full PHPUnit) green. shoot.cjs on all P9 app
pages 390/820/1440 × dark/light (no overflow, no JS errors); auth pages
logged out; error pages via `/_error/{404,500,403}` (logged in — anonymous is
redirected to login by the firewall). Puppeteer: password eye, wrong-password
alert, real login, OAuth consent reached via a temporary dynamically
registered client (deleted afterwards), demo modal add/remove rows, variant
row menu, live signature preview, GrapesJS mounts (600 px frame), planner day
/ meal type / course open, meal picker + no-results, "+ Chod" menu,
variant-count modal, preview modal, meal form add variant + manual mode +
remove, flatpickr opens.

**Not verified.** Actually saving in GrapesJS / sending a demo e-mail /
requesting approval in the browser (endpoints unchanged); Live re-render of
the planner after add/remove actions in a browser (markup contract kept,
not clicked through); drag reorder in the planner.

**Found, not fixed (pre-existing).** `weekly_menu_dragula_controller.js` uses
`draggable: '.meal-badge'` for meals, but no element has that class — meal
reordering inside a variant never worked (course/variant reorder is fine).
Manual components used on `manual_preview` (`DownloadManualImage`,
`LogoColorsMapping`, `ManualPageText`) still use mdi icons — customer output
surface, left for P10.

---

## 2026-11-02 — P7 Editor chrome (variant + group editor)

**What.** Both canvas editors in the prototype's `.ed` look (renderTpEd,
screens 20–21): top bar, format tabs, tool row, left panel with Vrstvy /
Kontejnery tabs, the floating popovers restyled as the inspector, Zobrazení
check menu, navigator card, themed rulers. Chrome only — canvas bitmap,
pasteboard dim, SVG handles, guides, outlines, zones and every
`assets/editor/*.js` classic script untouched.

**How / files.**
- CSS `assets/styles/nocturne/editor.css` (after app.css + fill.css in
  `assets/app.js` — note: that import line already landed in P9's commit
  7d6b9c0 because it shared the working tree; this commit adds the file).
- `template_variant_editor` / `template_group_editor`: page header + breadcrumb
  blocks emptied, `inner_class` `inner-wide ed-inner` (edge to edge). New
  `.ed-head[data-editor-header]` wraps three bars:
  - `.ed-top`: back (leave-guarded), eyebrow `.breadcrumb.ed-crumb`
    (project · Šablony [· document · Strana N]), `h1.ed-name`, undo/redo,
    lavender clean-preview eye, "Vše uloženo" (CSS complement of the
    `unsavedChangesMessage` badge) / "Neuložené změny", Zobrazení, Vyplnit a
    exportovat (`confirmExport` kept), mint Uložit (`btn-success`).
  - `.ed-vars`: single editor = template's ungrouped variants as tabs
    (links) + "Přidat rozměr" (add_template_variant, `template_edit` only), or
    the document page strip (same `document-page-strip` controller). Group
    editor = the variant rail (all `group-editor` targets kept; chip = tab +
    badge + opt-in switch) + "Přidat rozměr" + the mode button restyled as the
    "Upravovat všechny formáty najednou" switch (aria-pressed driven; the
    controller's btn-primary toggling is neutralised). Scope indicator
    (`group-variant-rail-card--all`) = lavender underline of the row.
  - `editor/_editor_tools.html.twig` (new): text / image / shape picker
    (CSS-mask glyphs instead of mdi) / checklist / Pozadí / Nápověda + zoom
    group (− 52 % +). These were the left-panel buttons; same actions. No
    fonts → disabled text button + peach "Nejsou nahrány fonty" pill.
- `_editor_left_panel`: radio tabs + `:has()` (no JS), counts = CSS counters
  over the controller-built rows (tab strip after the lists in the DOM,
  `order: -1`; inactive pane hidden with `visibility` so it still counts).
- `_editor_toggles`: Zobrazení = check menu (inputs restyled into ✓/✗, ids +
  actions kept) + new "Mini navigátor" switch; "Popisky prvků" relabelled
  "Popisky vstupů pro klienta" (prototype wording).
- `_editor_stage`: mini toolbar + multi bar on mask icons (`.btn[title]`
  contract kept), popovers = `.ed-insp` with `.ed-ph` header (grip handle
  kept, icon tile, Uložit, ×), "Vyplňuje klient" switch card (image = the
  placeholder checkbox moved to the top; text = new `clientFills` proxy of
  `locked`), section headings, Duplikovat / Smazat footer; info/plus/x icons
  → mask icons; navigator handle shows "Navigátor".
- JS (minimal): new `editor_chrome_controller.js` (clean preview
  `.ed-is-clean`, navigator toggle `.ed-nav-off`, leave guard for chrome
  links); `canvas_input_properties` (`clientFills` target +
  `updateClientFills`, `_setLocked`/`_mirrorLocked`, sample icon classes);
  `canvas_floating_toolbar` (lock / placeholder icon swaps → mask classes);
  `canvas_layers` (mask icons, **client-input dot** = new-features #28 cheap
  part: unlocked text, image placeholder, recolourable shape);
  `canvas_container` (panel row icon); `canvas_rulers` (colours from
  `--ruler-*` on the bar, repaint on `data-theme` change).
- Docs: `docs/architecture/templates-editor-ui.md` (new "Editor chrome
  layout" section, toggles table + navigator row), `templates-module-and-groups.md`
  (mode switch label).

**Decisions.** Inspector stays the FLOATING popover (positioning, first-field
focus and drag untouched) — only its look follows screen 21; no new
position/size fields (would be canvas behaviour). The clean-preview eye is
chrome-only CSS (does not flip the Zobrazení switches). Stage = neutral dotted
surface-2 with the canvas centred (`margin: auto` in a flex viewport — safe
when zoomed in). Off-canvas count on single-editor tabs (#28 second part) not
done — needs per-variant canvas analysis; the group rail badge already does it.
Navigator hidden < 768 px; < 992 px the editor stacks (prototype's phone
notice shown), the sticky `.editor-left-panel-card` contract kept.

**Tests changed.** None (selectors, ids, `.breadcrumb` text kept).

**Verified.** Gate green. shoot.cjs on variant, group and document-page
editor at 390/820/1440 × dark/light: no overflow, no JS errors (only Minio
image 404s). Puppeteer (both themes): select object via a real click on the
canvas → mini toolbar; pencil → text inspector; "Vyplňuje klient" flips
`locked` both ways + layer dot + dirty badge; Vzorový text modal
(#sampleTextModal); Zobrazení menu (navigator + rulers off/on); clean eye;
zoom − / + (page stays pinned, scrollY 0); Kontejnery tab; layer row select;
#addTextModal, #imageGalleryModal (image + Pozadí), #addChecklistModal,
#editorHelpModal; shape picker → star added; #exportUnsavedModal on dirty
Vyplnit. Group editor: tab switching (canvas resizes per format), mode switch
off/on (opt-in switches hide/show, scope underline), include toggle; image
and shape inspectors on a document page.

**Not verified.** Real drag-restack of layer rows after the restyle (Sortable
handles unchanged), guide drag-out of the themed rulers, the Uložit/× in a
popover on a group editor resync, the leave-guard confirm dialogs (native
`confirm()`), mobile editing beyond "it renders without overflow".

**Follow-ups.** Off-canvas count on single-editor format tabs; container
membership icon on layer rows (needs a member lookup); inline template
rename in the top bar (prototype `ed-name` input — needs a rename endpoint
call); the rich-text sample-modal toolbar still uses mdi (shared fill-page
WYSIWYG markup) — P10 sweep.

---

## 2026-11-02 — P10a Sweep (icons, theme removal, CSS cleanup, backlog bugs)

**What.** No legacy icon font, no `public/theme/`, no `--ct-*` shim, no Hyper
helper classes in the app; three backlog bugs fixed.

**How / files.**
- Icons: every remaining `mdi`/`uil`/`ri` usage outside customer output →
  mask icons (JS builds `<span class="i i-…">`): upload queues (gallery,
  fonts, fill gallery), toasts (`canvas_missing_images`, `fill_draft`,
  gallery), container-zone duplicate/settings, group resync report rows,
  checklist / gradient-stop remove, text-toolbar hint, rich-text sample
  toolbar (`_editor_stage`), `_mockup_page` admin placeholder.
  `ProjectModule::icon()` now returns mask names and replaces the three
  duplicated `module_icons` maps. Unused `icon` fields dropped from
  `SHAPE_KINDS` / the shape-picker list (glyphs are CSS). New icons in
  `icons.css`: alert-circle, clock, equal, image-off, list-bullet,
  list-ordered, format-clear, settings. Dead `project_fonts.html.twig` deleted.
  Legacy-icon selectors in menus.css/buttons.css removed; `.mdi` rules in
  app.css → `.i`.
- Theme: `public/theme/` (45 MB) and `public/fonts/legacy-icons/` deleted;
  base layout loads no icon font. `manual_preview` links
  `public/css/manual-preview/hyper.css` (the Hyper rules its markup matches —
  uncss-style DOM matching over 12 manuals, admin + reader, dropdown / modals /
  width bar open, state pseudo-classes + JS-toggled classes stripped before
  matching; 437 → 53 KB; Nunito woff only) + `mdi.css` (5 glyphs: download,
  image, pencil, arrow-right, arrow-expand-horizontal; woff2 only).
  Nunito-Regular.ttf → `tests/Mcp/Design/Fixtures/fonts/` (TextMeasurerTest,
  DesignLinterTest). No Docker/deploy file referenced the theme.
- CSS: `--ct-*` shim gone from tokens.css (app.css reads Nocturne tokens; the
  two manual-preview-only outlines got literal colours because that page has
  no tokens). page.css Hyper helper block + font-NN + avatar-* + unused
  `bg-*-lighten`, chips.css `badge-*-lighten` removed. app.css: rules for
  classes no template/controller/editor/src references removed
  (variant-card, template-source-*, font-face-row, document-card-stack,
  module-card, gallery-trash-card, gallery-dropzone__icon/title,
  canvas-layer-row__flag, dragula-handle-absolute-left, text-lighter) + their
  orphan comments. Kept: dynamic classes (`chip-${level}`, `--h/--v`, resync
  tones), `gu-*`, Fabric `lower-canvas`, `manual-<slug>`.
- Bugs: (a) planner meal drag — `draggable: '.planner-meal-card'` (+ the ×
  is a filter so it still clicks); also `meals()/variants()/courses()` getters
  sort by position, because the Live re-render after a reorder saw the stale
  already-loaded collection and morphed the cards back (DB was right).
  (b) invite form blank e-mail 500 → `'empty_data' => ''` on the field (the
  memory's WEB-28 pattern). (c) sidebar "Žádosti o registraci" item dropped;
  the pending count sits on Uživatelé, which is also current on
  `/admin/registration-requests` (route kept). app-shell.md updated.

**Tests.** New `InviteUserControllerTest::testBlankEmailShowsFieldErrorInsteadOfCrashing`
(422 + field error), `AdminUsersControllerTest::testSidebarPutsThePendingRequestCountOnUsers`.
MCP tests read the moved Nunito fixture.

**Verified.** Gate green. manual_preview pixel-compared (ImageMagick AE = 0)
old vs new sheets at 1440: 10 manuals full page anon + admin, dropdown, hover,
page-text modal, logo-colour modal, width bar — the only non-zero diffs were
smooth-scroll timing / Symfony toolbar timings (re-checked with scroll-behavior
auto: identical). Re-run after the app.css token swap: identical. Puppeteer
meal drag on the planner ×3 (cycle back to the original order; DOM + DB
follow each drop). shoot.cjs on 10 pages at 1440 dark + 390 light: no
OVERFLOW, JSERR only Minio 404s. New icons rendered and eyeballed.

**Not verified.** Drag of courses / variants after the getter sort (same
code path as meals); the JS-built upload rows / toasts with real uploads
(markup-only change); the manual preview in Safari/Firefox (Chrome only —
fonts now woff/woff2 only, no eot/svg/ttf).

**Follow-ups.** GrapesJS blocks still name `fa fa-*` icons (GrapesJS own UI).

---

## 2026-11-02 — P10b QA pass + polish

**What.** Every GET HTML route shot at 390/820/1440 × dark/light, looked at
(1440 dark + 390 light for all, 1440 light for the custom-colour pages),
defects fixed; logged-out + ROLE_USER views; keyboard smoke; docs.

**Coverage.** 88 resolved routes (scratch `qa/routes.txt`): 81 logged-in app
routes (projects, manuals, mockups, fonts, gallery, calendars, modules,
templates/variants/groups/documents incl. editors, fills, export histories,
print settings, e-mails + GrapesJS, weekly menus/meals/diets/dish types, admin,
account, `/_error/{403,404,500}`), 4 auth pages, `manual_preview` + public
weekly menu (status only — untouchable). Skipped: redirects, JSON/PNG/binary
endpoints (incl. `/template-variant/{id}/edit`, a JSON endpoint for the
editor's Pozadí), approval page (no menu has a hash), mutations. 486 + ~100
re-shots. Non-200: only the error previews (expected codes). JSERR: only
Minio image loads. OVERFLOW: document export history at 820 (fixed) and
`manual_preview` < 1400 px (customer output, fixed-width by design, untouched).

**Defects → fix** (one commit, see below):

| # | Defect | Fix |
|---|---|---|
| 1 | E-mail template add/edit: native "Choose File / No file chosen" input (English, unstyled), separate "Současné pozadí" well | `_template_image_field` drop zone (new optional `caption` var), current background previewed in the zone; dead `.es-bg-*` CSS removed |
| 2 | History table overflowed the document at 820 px | `.tp-hist { position: relative }` — the `th .visually-hidden` (absolute) escaped the scroller |
| 3 | Phones: list pages showed the head primary AND the top-bar + (two primaries) | `shell.css`: `.main:has(.mbar-side > .btn-primary) .head-tools .btn-primary { display: none }` ≤ 860 px (generalises P3's per-page `.head-primary` rule) |
| 4 | Head actions fell to the left under a long lead (e-mail variants, document) | `.head-tools { margin-left: auto }` |
| 5 | Document header "Smazat…" read as truncated | label "Smazat dokument" |
| 6 | Mockup editor at 390: slot copy collided with number + file strip | container query hides the slot text < 520 px, tighter desk padding on phones |
| 7 | Print settings: crop-marks checkbox floated with a phantom gap when wrapped | row `align-items: flex-end`, checkbox row 44 px tall (0 on phones) |
| 8 | Profile "Heslo" row squeezed the text to 4 lines at 390 | `.acc-row:has(> .btn) .who` takes the line, button wraps |
| 9 | Light theme: white palette dots invisible on the project overview | `.pd-sw i` ring uses `--line-2` (prototype ring was white-alpha) |
| 10 | GrapesJS chrome kept its stock grey/pink look | colour classes (`gjs-one/two/three/four`, blocks, active panel button) mapped to tokens, scoped `.es-gjs` |
| 11 | **No visible keyboard focus on any `.btn`** (Bootstrap's focus shadow is overridden by our resting glows) | `.btn:focus-visible, .btn-close:focus-visible` = the base lavender ring |
| 12 | Phone drawer: focus stayed on the hamburger (selector hit the hidden rail button); closing didn't return focus | `shell_controller`: focus first *visible* `.drawer-close/.nav-i`; Esc/close returns focus to the hamburger |
| 13 | ROLE_USER: weekly-menu cards linked to planner/edit (403) | `is_granted('weekly_menu_edit', menu)` gate; viewers get "Zobrazit" → public menu (new tab) |
| 14 | ROLE_USER: fonts page "Písma mimo projekt" listed editor links (403) | alert only for `can_upload` (ProjectVoter::EDIT) |

**Keyboard/a11y smoke (puppeteer).** Tab order sidebar → page, lavender ring
on links/inputs/buttons (after #11), Bootstrap modal: focus inside, trapped
after 12 Tabs, Esc closes; dropdown Esc closes; drawer opens with focus inside,
Esc closes and returns focus. No `data-bs-keyboard=false` anywhere.

**Non-admin view.** Temporary `qa-user@test.cz` (ROLE_USER) shared on Mikeš +
My Speed Puzzling via `project_share` rows; shot projects, overview,
templates, manuals, fonts, group/variant/document fill, modules, weekly
menus, e-mails, profile; crawled 63 visible links for 4xx → #13, #14. Sidebar
correctly hides Galerie/Administrace; fill pages hide "Upravit design".
User + shares deleted afterwards.

**Docs.** CLAUDE.md: new "Design system (Nocturne UI)" section (spec pointer,
CSS file map, helpers, shoot.cjs). `app-shell.md`: full CSS load order incl.
area files and fill/editor after app.css, phone one-primary rule. No doc
describes Hyper as current any more.

**Not fixed / notes.** `manual_preview` horizontal overflow on phones
(customer output, P10a pixel-locked). Fill previews stay on the spinner in
headless runs (Minio/Gotenberg locally). Manual "Fonty" tab is hidden for
Logo-type manuals while `/manual/{id}/fonts` still renders (no active tab) —
pre-existing nav rule, left. Project overview "Fonty · 2 chybí" tile is
designer info that clients also see. Broken mockup thumbnails show the
browser's broken-image glyph (customer partial `_mockup_page`, untouched).
Sheet-style forms (add diet, invite, profile…) keep the P8 dialog-as-page
look without a `.head` — consistent, deliberate.

---

## 2026-11-02 — Delivery summary (orchestrator)

- Phases P1–P10 delivered on `redesign/nocturne` by 10 implementation agents
  (P3+P4, P8+P5, P6+P9 ran in pairs; P1/P2, P7, P10a, P10b alone), each
  reviewed by the orchestrator against the prototype screenshots.
- Final gate: PHPStan clean, PHPUnit 1617 tests green. 88 routes shot at
  3 widths × 2 themes in P10b.
- Hyper is gone (`public/theme/` deleted, 45 MB); Bootstrap 5.3 remains as the
  behaviour/grid layer under the Nocturne CSS (`assets/styles/nocturne/`).
- Customer-facing outputs (manual preview, weekly-menu public/PDF, render
  template, e-mails) are unchanged — manual preview verified pixel-identical.
- Process incident: one stray `git checkout <sha> --` by the orchestrator
  detached HEAD for a few seconds while no agent was running; tree was clean,
  switched back immediately, no loss.
- Open items for the merge-request description: see "Not verified" and
  "Follow-ups" in each phase entry above; backend features in `new-features.md`.

## Feedback round — F1 project logos bare (2026-11-05)

**What:** project logos/icons are no longer on a white plate with padding; the bare
image fills the whole `.sym` box (cards, project detail, sidebar switcher, quick nav,
admin users/edit user). The initials monogram keeps its dark `--surface-3` plate.
**How:** one rule in `nocturne/chips.css` — `.sym:not(.mono)` drops background,
inset ring and overflow clip; `.sym img` loses padding + `#fff`. A logo that fails
to load still swaps to `.mono` (plate comes back). `shoot.cjs` gained
`IMG_FROM`/`IMG_TO` so a prod-copy dev DB shows the real public uploads.
**Verified:** shots at 390/820/1440 × dark/light on /projects, project detail,
/admin/users with real prod logos; gate green.
**Note:** logos drawn dark on transparent are now low-contrast in the dark theme.
Gate tip: a stale `tests/.database.cache` copied with the checkout skips creating
`wboost_test` (672 connection errors) — delete the file.

## Feedback round — F2 project form in a dialog, F3 project overview header (2026-11-05)

**What (F2):** on /projects, "Nový projekt" (header, phone +, empty state) and the
card menu's "Změnit název a logo" open the add / edit form in a dialog styled like
Sdílet (same `.modal` shell + width, title + project-name subline) instead of
leaving the page; success reloads /projects with the flash ("Projekt upraven!" /
new "Projekt vytvořen!").
**What (F3):** project overview header: "Upravit projekt" button → ⋯ menu (same
look as the card menu) with Upravit (the F2 dialog, save returns to the overview)
and Sdílet projekt (admin-only, opens the existing share modal); meta line keeps
only "Založeno …" (users/templates/owner gone); "Naposledy přidané šablony" box
removed, with its now-dead controller sort + `.pd-recent/.pd-tpl` CSS.
**How:** one shared modal partial `_project_form_dialog.html.twig` +
`project_form_modal_controller.js` lazy-loads `<href>?modal=1`; add/edit
controllers only switch template/response in modal mode (fragment
`_project_form_modal.html.twig`, 422 on errors, JSON `{redirect}` on success,
`back=dashboard`). `_project_form.html.twig` gained a `modal` variant (no card,
Zrušit dismisses, explicit action). No business logic touched; routes and full
pages unchanged. Contract in `docs/architecture/app-shell.md`. One commit for
both items — they share the partial, JS, CSS and EditProjectController.
**Tests:** new `ProjectFormModalTest`; `ProjectDashboardControllerTest` updated
for the removed owner line / recent strip (asserts the new menu + gating instead).
**Verified:** gate green; puppeteer at 390/1440 × dark/light: both dialogs on
/projects, overview ⋯ menu + edit + share dialogs, validation error rendered
inside the dialog (URL unchanged, field focused), valid edit + revert on both
pages (prod-copy DB left as found), new project with a PNG logo via the phone
dialog (multipart works, logo shows on the card) then deleted; no JS errors,
no overflow. shoot.cjs 390/820/1440 × both themes on the four pages.
**Not verified:** real drag & drop onto the dialog's drop zone (file chooser
path only); keyboard-only flow beyond focus-on-open.
**Note:** the admin "Moduly projektu" change history is now only reachable via
the full `/edit-project/{id}` page (direct URL / module e-mail link) — the
overview no longer links there on a plain click.


## Feedback round — F4 templates list: categories collapsed + drag reorder (2026-11-05)

**What:** /project/{id}/templates now opens with every category section
collapsed (incl. "Nezařazené") and only "Nejpoužívanější" open; editors drag
whole category sections by a header grip to reorder them.
**How:** `templates_list_controller` starts with all non-top keys closed (a lone
section stays open), no longer persists the open state and drops the old
`wb-tpclosed-<projectId>` key; Twig renders the same collapsed state server-side
(no flash). Reorder = SortableJS on `.tp-groups` (handle `.tp-ggrip` = `man-grip`
+ `i-grip`, `forceFallback`, `.gu-transit`/`.gu-mirror` styles in templates.css),
only `[data-category-id]` sections move, `onMove` forces "Nezařazené" to stay
last; disabled while searching. It POSTs the FULL category order to the existing
`sort_template_categories` (new view var `category_order`; empty categories are
not rendered, so the visible order is re-slotted around them). Grips only for
`project_edit` (the endpoint's voter) and ≥ 2 non-empty categories.
"Nejpoužívanější" stays above the list, not draggable, still collapsible.
**Shared-file fix:** `dragula_controller.js` — moving a section makes Stimulus
disconnect/reconnect the card-grid controller inside it, and `Sortable#destroy()`
runs `_onDrop()` on the global drag state, killing the outer drag. disconnect()
now skips destroy while another Sortable is active; connect() reuses the instance.
**Tests:** `TemplatesListingTest::testCategorySectionsStartCollapsedAndEditorsGetCategoryGrips`.
**Verified:** shots 390/820/1440 × dark/light; puppeteer (mouse events, fallback
mode): initial state, expand/collapse, drag Obecní ↓ Fotbal → POST + psql +
reload persisted, restored; drag past "Nezařazené" keeps it last; an open section
dragged up, then card sorting inside it still works (POST intercepted).
**Not verified:** touch drag on a real phone (grip has `touch-action: none`).
**Follow-up (pre-existing, not changed):** `SortTemplateCategoriesHandler` does
not check that the posted category ids belong to the URL's project.

## Feedback round — F6, F8 (fonts), F11, F12 (2026-11-05)

**F6 (c1037bfc):** "Facebook a Instagram" left the global Propojení section and
is the project menu's last item (before only the "Objevte další moduly" CTA),
in every project (no module key), same behaviour: `user_profile` when
`facebook_connected()`, else the `#social-publish-fake-door` modal. Order:
Přehled, Manuály, Šablony, Galerie, Fonty, *(slot comment for Paleta barev,
F7)*, E-maily, Jídelníčky, Facebook a Instagram. Propojení keeps Propojené
aplikace + AI (MCP server) — no heading there anyway, only the divider.
Outside a project there is no FB entry. Test:
`ProjectModulesTest::testFacebookInstagramIsTheLastProjectMenuItem`.
**F8 fonts (31d5e6f5):** manual tab "Fonty" removed (Barevná paleta stays
until F7; tab bar never drops to one tab, so it stays). New box
"Písma v manuálech" (`#manual-fonts`, `.pd-box`) at the bottom of
/project/{id}/fonts: per BRAND manual (Manuály module on) its fonts as rows —
name in its face, role pill, colour dot + hex, cuts as toggle chips
(`manual-font-faces-toggle`, saves on change), grip (`dragula` →
`sort_manual_fonts`), ⋯ Upravit / Odebrat; read-only = disabled chips. The
`manual_fonts` route only redirects to `fonts_list#manual-fonts-{id}`
(`manual_fonts.html.twig` deleted); add/edit/delete redirect there; add/edit
pages extend base.html.twig as Fonty sub-pages (back trail Projekt → Fonty).
CSS in `fonts.css` (old `.fn-cut-sw` rules removed). Doc: `manuals.md`.
Test: `tests/Controller/Font/FontsPageManualFontsTest.php`.
**F11 + F12 (56b45dea):** `ManualFont::edit()` compares with the CURRENT
font's id; `SortTemplateCategories` carries the URL's project id and the
handler skips malformed / unknown / foreign ids. Tests:
`tests/Entity/ManualFontTest.php`, `SortTemplateCategoriesControllerTest`.
**Verified:** gate green (1631 tests); shots 390/820/1440 × dark/light of a
project overview, the Fonty page, a manual Logotypy page; puppeteer: FB item
last + opens the fake-door modal in the expanded sidebar, the icon rail and
the 390 drawer (URL unchanged); edit-manual-font save keeps the 5 cuts and
lands on `#manual-fonts-…`; cut chip toggle → 200, persists across reload,
toggled back (psql: row identical to before).
**Not verified:** manual-font drag reorder in a browser (same dragula
controller + endpoint as before); the connected-FB branch only in PHPUnit.
**Follow-up (pre-existing, same class as F12, not changed):**
`SortTemplatesHandler`, `SortManualFontsHandler` and
`SortManualMockupPagesHandler` don't verify the posted ids belong
to the project/manual in the URL.
## Feedback round — F9, F10 manual logos: dismissible warning + width reset (2026-11-05)

**What (F9):** the "V manuálu chybí N varianty loga" alert on /manual/{id}/logos
has an x button ("Skrýt upozornění"); the dismissal sticks per browser until
the manual's logo set changes (upload / replace / removal), then it re-appears.
**How:** new generic `dismiss_alert_controller.js` (values `key`, `signature`);
localStorage `wb-logo-warn-<manualId>` = Twig signature `Variant=filePath|…`
(`-` for missing; upload paths carry a timestamp, so a replace changes it too).
An inline script right after the alert hides it before first paint (no flash);
connect() repeats the check. No backend change. Only this page has the warning.
**What (F10):** the "Výchozí" / "N %" value label above the width box is gone;
in its place a small `btn-tertiary` pill "Výchozí" (`i-undo`), shown only while a
custom width is set, clears slider + box (`range-sync#reset`), saved by Uložit.
Box widened 76 → 116 px so the "výchozí" placeholder fits beside the spinners.
**Verified:** puppeteer — dismiss → reload stays hidden (already hidden at
DOMContentLoaded), edited stored signature → back; width 30 → Uložit → reload
→ reset → Uložit → reload empty (manual JSON restored byte-for-byte after);
shots 390/1440 × dark/light; full PHPUnit green.
**Not verified:** Safari/Firefox spinner widths; a real logo upload re-showing
the alert (simulated via the signature).
## F7 Paleta barev — P0: snapshot + backfill planner dry run (2026-11-05)

**What:** read-only tooling for the palette migration (`docs/redesign/palette-plan.md`),
no schema or behaviour change except a deterministic manual order.
- `GetManuals::allForProject` now `ORDER BY created_at, id` (was heap order). Callers
  checked: `computeColors` (editor / group editor / fill / API / MCP `get_context`,
  `LintContext`), `BrandCmykPalette` (CMYK first-wins), `ResolveEditorFontDefaults`
  (first primary font wins), the overview chips ("first manual with colours"), the
  Manuály listing + dashboard, the Fonty page box (sorts itself anyway). All only get
  a stable order; none relied on another one.
- `app:palette:snapshot <out.json>` — every colour-derived output per project
  (computeColors via `ResolveRichTextOptions::forProject`, `BrandCmykPalette`,
  avatar, `has_palette`, overview chips) and per manual (detected / custom / primary /
  secondary with all fields, `colorN`, `colorsCount`, mapping pairs + background for
  5 logo types × 5 colour variants). Public read model only; `customColors` read in
  ONE helper (becomes a method in P1b). Throwing fields → `{"error": …}`.
- `Services/Palette/PaletteBackfillPlanner` (pure, raw columns in via
  `manualFromStorage()`) + `Value/PaletteProjectPlan` (in Value/ because
  Services/** is autowired) + `app:palette:plan [--report=<csv>]` (DBAL, read-only).
- Plan §1–§5 rewritten for D2 (role per project, majority, tie primary > secondary >
  none) + §2a dry-run results.

**Dry run (local prod copy):** 115 palette colours, 155 links; 3 role changes
(Čeladná/Fotbal `#17375f` primary → secondary; Frýdlant TIC + Knihovna white →
primary on a 2:2 tie), 9 split-duplicate entries, 1 case merge, 3 CMYK conflicts,
0 stale / non-hex / in-manual dups / avatar changes / parity breaks. Detected colours
come from the logo JSONB, so missing Minio files don't matter.

**Tests:** `tests/Services/Palette/PaletteBackfillPlannerTest.php` (12, incl. parity
against real `Manual` entities: computeColors, BrandCmykPalette, `color(n)`, the
loose-`in_array` `#000`/`000000` quirk), `tests/ConsoleCommands/PaletteCommandsTest.php`
(snapshot byte-identical twice; plan leaves `manual`/`project` untouched).
**Not done:** HTML A of every `/nahled-manualu/…` on the prod mirror (plan P0 row).

## Feedback round — F13 mockup pages "Náhled stránek" (2026-11-05, 6bf953d4)

The mockup pages list gets the prototype's Mřížka / Náhled stránek switch. Default is
Náhled, stored in `localStorage['wb-manview']` and applied by an inline script before first
paint (no flash). One `.man-pages` list serves both views, restyled by `.man-mock[data-view]`,
so the dragula hooks and delete confirmations exist once. A new rail (`.man-pdf-side` /
`.man-thumbs`, `manual_mockup_pages_controller.js`) highlights the page in the viewport's
reading band, jumps on click, handles arrow/Home/End keys and reorders by drag (SortableJS,
`forceFallback`, 4 px threshold, same `{sorted}` payload to `sort_manual_mockup_pages`);
pages renumber after a drag in either view. CSS in `nocturne/manual-mockups.css`. Rail is
140 px under 1100 px and hidden under 760 px (phones reorder in Mřížka). Puppeteer-verified;
touch drag and screen readers not verified.

## Feedback round — F14 synced template pill (2026-11-05, 3a09af9a)

"Synchronizováno" on synced (multi-format) template cards read as a sync status. It is now an
icon-only `icon('layers')` pill right before the format pills, `title` "Šablona ve více
formátech — formáty se upravují společně (skupina)" + `aria-label`. Checked both themes.

## Feedback round — F15 sidebar remembers the last project (2026-11-05, 8e1fc6e0)

The sidebar remembers the last opened project in the session (`ShellExtension::shell_project`,
the one place the shell resolves its project). Pages without their own project (admin,
Propojené aplikace, AI, profile, /projects) keep its PROJEKT section and quick-nav selection,
with no project item highlighted. The `project_view` voter is re-checked on every render, so
deleted or unshared projects are forgotten; logout clears the session. /projects keeps it too,
so the nav doesn't jump. Tests: `ShellLastProjectTest` (7 cases). Not verified: 820 px.
Gate note: `gate.sh --clear-assets` removes the dev container PHPStan needs — load one page
before the gate.

## Palette P1a — project_color tables, backfill migration, write-path sync (F7, 2026-11-05)

Release R1 of the project palette (`palette-plan.md` §2b): ADDITIVE, the manual JSONB
colours stay authoritative, no read-path or UI change.
- Planner updated for the §7 owner decisions: "fill the gap" merge (same hex, print data
  differing only by empty vs filled → one colour carrying the union; first colour that
  already carries the data wins, else first compatible), new `print_gap_filled` finding,
  repeated logo literal → link without `logo_hex`. Tie rule / HEX spelling / literal
  logo links were already P0 behaviour. Per-manual helpers (`segments`, `manualWalk`,
  `manualRoles`, `matchPrint`, …) made public for the runtime projector.
- Entities `ProjectColor` (per-project `role`) + `ManualPaletteColor` (partial UNIQUE
  `(manual_id, logo_hex)`, FK to the palette NO ACTION), repositories.
- Migration `Version20261105170659`: CREATE + backfill via the FROZEN planner and
  `Services/Palette/PaletteBackfillSql` (one CTE statement per project, UUIDv5 ids,
  skip projects that already have a palette → replay-safe). `down()` drops the tables.
- Write paths: `EditManualColorsHandler` and `UpdateManualImagesHandler` flush, then
  `ProjectManualColors::reproject()` (DBAL, project row locked). Role "last write wins"
  only when the write changed the manual's role for that colour; logo uploads never set
  roles; palette colours never deleted. No other writer of the colour columns exists.
- `app:palette:verify` (JSONB read model vs table read model, per manual + per project
  computeColors / BrandCmykPalette; owner-accepted kinds in `PaletteParityDiff`, exit 1
  otherwise) and `app:palette:reconcile [--dry-run]`.

**Local prod copy:** 113 palette colours (P0: 115 — two §7 merges), 155 links (85 logo,
70 custom). Verify: 12 accepted diffs (the 3 role-change manuals, 2 gap fills, TIC's
`FFFFFF` spelling), 0 unexpected. Snapshot after migrating == snapshot A byte-for-byte;
`migrate prev` + re-migrate → identical rows (hash), reconcile dry run = 0 manuals.
**Tests:** `PaletteSyncTest` (fixtures + 3 manuals → backfill → verify 0 unexpected,
replay idempotency, edit colours / role last-write / untouched save / gap fill / logo
upload sync, tamper detection), `PaletteCommandsTest` (verify + reconcile repair),
planner tests for fill-the-gap + preference + repeated logo literal.
**Not verified:** a browser save on the colours / logo pages (covered by the controller
tests + handler tests only). **Follow-ups:** P1b must decide whether unused palette
colours (R1 leftovers) are skipped by `GetProjectPalette`; the "re-project every manual
linking the colour" step only matters once P1b mirrors links → JSONB.

## Palette P1b — reads from the project palette, JSONB mirrored (F7, 2026-11-05)

Release R2 (`palette-plan.md` §2c): the palette tables are the source of truth; every
write also mirrors the links back into `manual.detected_colors` / `custom_colors` so R1
code (blue-green overlap, a code-revert rollback) stays correct. No schema change.
- `Manual` read model from `$paletteLinks` (OneToMany EAGER + EAGER `projectColor` →
  one batch query per hydration; measured 1 link query on /projects, dashboard, manuals
  list): `detectedColors()` = logo links (literal `logo_hex`), `customColors()` (method
  now) = palette HEX, type = per-project role, print data from the palette. JSONB props
  renamed `jsonbDetectedColors` / `jsonbCustomColors` (same columns), `editColors()` →
  `mirrorJsonbColors()`, `editLogo()` sets the logo only. Old logic frozen in
  `Services/Palette/JsonbManualColors` (verify, reconcile, parity tests).
- `Query/GetProjectPalette` (palette order, **linked colours only** — decision: R1/R2
  never delete palette colours, leftovers must not become swatches / win print CMYK;
  P2 lists them as "Nepoužitá") → `ResolveRichTextOptions::computeColors(palette)`,
  `BrandCmykPalette::fromPalette()`, `LintContext::forProject(palette)`, editors,
  `get_context`. API shape unchanged; consumer-prompt + mfkfm `types.ts` comment now say
  "project palette, palette order".
- `Services/Palette/ManualPaletteWriter`: `editColors()` (old colours page) and
  `syncLogoColors()` (D3) write palette + links (unchanged → same colour; changed and
  unshared → edited IN PLACE; else §7 match; else append), then `mirrorProject()`, then
  `refreshManaged()` (detach palette entities, reset managed manuals' link collection +
  mirror — `EntityManager::refresh()` throws on readonly `createdAt`).
- verify: JSONB side = `JsonbManualColors`, project side = linked palette only, unused
  colours informational, new unexpected `jsonb_mirror_stale`. reconcile: stale manuals
  re-projected FROM JSONB (R1 projector) + project re-mirrored.

**Local prod copy:** snapshot R1 vs R2 = exactly the 12 accepted items (Fotbal role page
+ 2299 C, TIC white role/spelling + 7509C, Knihovna white role); project-level outputs
and every logo mapping identical. HTML of all 33 `/nahled-manualu/…`: only those 3
manuals differ. Reconcile: 3 manuals, 11 JSONB mirrors rewritten, 0 link / palette rows
changed; verify after = 1 accepted `palette_order`; snapshot + HTML unchanged by it.
Browser (puppeteer, Žabeň): colours page save (role + Pantone, in place) and a logo
upload adding `E30613` (appended, no role, role of #1 kept) — preview, palette, links,
mirror checked; data + the uploaded Minio file reverted (snapshot == before).
**Tests:** `PaletteSyncTest` (+ parity links vs JSONB path before/after mirror,
swatches / print CMYK / avatar from the palette, unlinked colour is no swatch, in-place
vs split edits, the writing request reads what it wrote, logo re-upload unlinks),
`ManualColorsControllerTest` (type change → palette role + mirror + manual pages),
`ResolveRichTextOptionsTest` (palette order), `DesignLinterTest`, planner parity vs
`JsonbManualColors`. Fixtures: Project 1 has a real palette now (2 colours, Manual 1).
**Bug found (P1a):** deleting a project with palette colours failed in the test DB
(FK violation) — the NO ACTION link → palette FK depends on the order Postgres fires
the project's RI cascade triggers (by trigger name); prod's order is unknown. Fixed by
`Version20261105193000` (ON DELETE CASCADE, mapping too); `DeleteProjectStorageTest`
covers it now that Project 1 has a palette.
**Not verified:** prod. **Follow-ups:** P2 palette page; overview chips / `has_palette`
still read the (mirrored) JSONB until P2.

## Feedback round — F19 container popover, F20 picker size slider (2026-11-05)

**F19 (a7ffe077):** the canvas editor's container settings popover (`.container-zone-popover`,
built by `canvas_container_controller.js`, styled in `app.css`) still had the pre-redesign
`#fff` background while its labels/inputs follow Nocturne → near-invisible titles in dark.
Now `--surface` / `--line-2` / `--shadow-3`, title + labels in text tokens. Checked both themes.

**F20 (61ee6f51):** the "Vyberte obrázek" picker (`Project:ImageGallery` modal mode: canvas
editor, group editor, add-template backgrounds) shows the tile-size slider; it shares the
`wb-galsize` value with the standalone gallery (modal default 150 px, page 168 px). Stable ids on
the `data-live-ignore` search + slider stop a duplicate search box after a folder change.
Fill-page pickers are separate and still lack the slider (F21).

CI note: `ProjectsTest::testTokenEndpoint…` flaked on `expires_in` 3599 vs 3600 — now accepts
both (2af71b3b).

## Feedback round — F17, F18 usage heatmap (2026-11-05, 112a9e9b + c2418c65)

Hover rings only the hovered cell (inset) and highlights the row name — no scaling; group
gaps are a `tr.hm-gap` spacer row. Client rows show the user's name (email fallback) with a
`.count` project badge as the expand button; projects start collapsed (whole-row click,
aria-expanded/aria-controls, Rozbalit/Sbalit vše). Search over client name, email and projects
(accent-insensitive, auto-expands project matches, "Nic nenalezeno"). Stažení / Šablony
`.seg.soft` switch reads per-metric values + levels rendered server-side (`data-ld`/`data-lt`),
choice in `localStorage['wb-usage-metric']`; owner name joined in `GetUsageOverview`.
**Data bug (since 3645b757, 2026-06-29):** a client row = project owner at download time, but
monthly values were stored per project only, so projects handed over between clients showed
mixed months (cells ≠ totals). Now every number is scoped to client+project; the top tiles count
a handed-over project once. Test `testProjectHandedOverBetweenClientsIsScopedPerClient`.
Not verified: screen readers.

## Feedback round — F16 "Vzorový text" (2026-11-05, 2422996b)

Rich inputs' "Vzorový text" dialog uses the fill page's `rich-text-editor` controller, which
needs `window.WBoostRichTextRuns` at connect. The editor pages never loaded
`rich_text_runs.js` / `rich_text_blocks.js` (missing since e57b9ab3, 2026-08-05), so the
controller crashed: the stored sample didn't show and typed text was lost on save — the client
only hit it after turning on rich text for "Kategorie". Both scripts now load in the shared
`editor/_editor_scripts.html.twig` (variant + group editor), with a script-tag test per editor.
P7 only checked that the dialog opens. The name chips over the text are unchanged pre-redesign
behaviour (hide via Zobrazení → Popisky vstupů pro klienta). Not verified: lists / checkbox
lists inside a rich sample.

## Feedback round — F21 picker on phones + fill-picker size slider (2026-11-05, 9ca367d5)

At 390 px the picker modal toolbar's folder-name field wouldn't shrink, so "Nová složka"
overflowed; it now wraps like the gallery page (upload / name + Nová složka / Koš rows,
gallery.css). The fill pickers (single, group, document) get the gallery's tile-size slider,
sharing `wb-galsize` and following each other live; hidden on phones (`fill_gallery_controller`,
fill.css). Puppeteer at 390/1440 both themes incl. reload. Not verified: the document fill
picker (no local document with image slots; markup identical to the group picker).

## Palette P2 — "Paleta barev" page (F7, 2026-11-05)

New project page `/project/{id}/paleta` (`project_palette`), sidebar item after Fonty
(always shown, `icon('palette')`, active `palette`; F6 slot comment removed). Every
palette colour in "Z logotypu" / "Přidané barvy" `.pal-card`s (prototype renderPalette):
swatch = copy HEX, HEX locked with a lock on logo colours, role select per PROJECT (D2),
RGB, CMYK + "Dopočítat z HEX" (the conversion moved to `palette_color_math.js`, shared
with the manual colours page), Název / Pantone / RAL, "Použito v:" manual chips or
"Nepoužitá", ⋯ Kopírovat HEX / Posunout výš / níž / Smazat barvu, drag reorder
(SortableJS forceFallback), "N barvám chybí CMYK" note. Autosave per field (JSON + CSRF,
kit `.toasts` toast "Uloženo", requests queued) through `Message/Palette/*` →
`ProjectPaletteWriter` (DBAL, project row locked, then `ManualPaletteWriter::mirrorProject`
— the R2 JSONB mirror stays exact). Delete guard: logo colour blocked, added-to-manuals
needs "Odebrat z manuálů a smazat", unused plain. Read-only members (§692): disabled
fields, empty print values dashed peach, HEX strip instead of the toolbar, c-warning
"Požádejte grafika o doplnění". Overview chips = palette (first 12) + pencil/eye link;
`has_palette` = `EXISTS project_color`; `app:palette:snapshot` v2 (+ `palette` list).
CSS `nocturne/palette.css` (kit stays in manual.css). Tests: `ProjectPaletteControllerTest`
(14), `ProjectPaletteHandlersTest` (6), nav order in `ProjectModulesTest`. Puppeteer:
Pantone / role / reorder (menu + drag) / add + delete / guard on the Čeladná prod copy,
snapshot diffs = only the edited colour's manuals; data reverted, snapshot + palette
tables byte-identical to before. Not verified: the client view in a browser (PHPUnit
only), the Frýdlant palette interactively (screenshots only).

## Palette P3 — "Barvy v manuálech" + manual colours tab removed (F7, F8 colours, 2026-11-06)

What: a "Barvy v manuálech" `.pd-box` at the bottom of Paleta barev (D6, like "Písma v
manuálech"): per manual (all types, 2-column grid on wide screens, anchor
`#manual-colors-{id}`) its colours in manual order — "Z logotypu" (reorder only) then
"Další barvy" — with number badges 1…n (= `color(n)`, 1–3 highlighted: the default logo
recolouring keys), swatch, HEX, Název, CMYK · Pantone · RAL summary and the palette
role as a read-only pill linking to the colour card (D2). Editors (`manual_edit`): drag
per segment (SortableJS forceFallback) or ⋯ Posunout výš / níž (JSON, badges renumbered),
⋯ Odebrat z manuálu (added colours only; logo rows show why not), "Přidat z palety"
dialog (palette colours not in the manual, multi-select, submit disabled until one is
ticked). "Manuál potřebuje alespoň 3 barvy" moved into the manual's section. The
manual "Barevná paleta" tab is gone; `/manual/{id}/colors` → 302 to the section (VIEW).
How: `Message/Palette/{Add,Remove,Sort}ManualPalette*` → new `ManualPaletteWriter`
`addColors` / `removeColor` / `sortColors` (project row FOR UPDATE, R2 JSONB mirror,
`refreshManaged`); logo + added links each permute among their own positions.
Deleted the old colours form stack + template + `colors_dragula_controller.js`, dead CSS
in manual.css (`.man-tab-warn`, copied overlay, form errors); `custom_color_controller`
trimmed to the live swatch of `LogoColorsMapping`. Phones: rows switch to a small grid
(HEX + print on line 1, role pill below, menu right).
Tests: `ManualPaletteColorsControllerTest` (11 — box EDIT vs VIEW, add incl. template
swatch path, remove + logo refusal, reorder + logo-first invariant, CSRF / rights,
redirect, tab gone, palette edits reach the customer manual); `ManualColorsControllerTest`
deleted. Verified: puppeteer on the Čeladná prod copy (add / remove / menu move / drag,
persisted after reload, no JS errors), shoot.cjs + box clips at 390/820/1440 both themes
for Čeladná + Frýdlant (editor and a temporary read-only member), manual pages without
the tab. Snapshot diff after the moves = only the moved manual; data restored, snapshot
byte-identical, verify OK. Not verified: the add dialog on a phone (desktop only), a
manual with no logo colours in the browser (PHPUnit only). Follow-ups: drop
`EditManualColors` / `editColors()` with P5a; maybe link "Barvy manuálu" from the
manual's ⋯ menu if users miss the tab.

## Palette P4 — "Sloučit" duplicates + template usage count (F7, 2026-11-06)

What: Paleta barev cards end "Použito v:" with an "N šablon" chip (1 šablona / 2–4
šablony / 5+ šablon; title lists them) and same-HEX colours get a peach "Stejný HEX má i
další barva" pill + "Sloučit" (editors only). The dialog (one per same-HEX group) lets the
designer pick which colour stays — default = used by the most manuals — and shows, per
choice, the resulting colour, what it gains, every manual that changes and whether template
print CMYK changes. The delete dialog now names the templates using the HEX (informational:
templates keep HEX snapshots, D4).
How: `Query/GetPaletteTemplateUsage` (one DBAL query over every variant's canvas + inputs,
scanned by HEX: paints, allowlists, rich sample runs; a document counts once) →
`Value/PaletteTemplateUsage`. Merge rules in the pure `Services/Palette/PaletteColorMerge`
(fill the gap, default survivor, preview) shared by the dialog and
`ProjectPaletteWriter::mergeColors()` (CQRS `MergeProjectColors`): fill the gap, earliest
palette position, links repointed (logo `logo_hex` kept, double links collapsed: logo wins,
else one added link at the earliest place), losers deleted, R2 JSONB mirror +
`refreshManaged`. Route `project_palette_merge` (EDIT, CSRF, survivor must be of the URL's
project).
Tests: `MergeProjectColorsHandlerTest` (6), `MergeProjectColorsControllerTest` (6),
`GetPaletteTemplateUsageTest` (2), `PaletteColorMergeTest` (3). Verified: shoot.cjs
390/820/1440 both themes + dialog shots (both choices) for Čeladná; puppeteer merges of the
two real Čeladná pairs through the dialog — snapshot diff = only the two loser manuals'
CMYK, verify 0 unexpected; data restored, snapshot byte-identical, palette tables + JSONB
md5-identical to the pre-work dump. Not verified: Frýdlant's `c6c6c6` pair in the browser
(PHPUnit covers the shape), a 3-way group in the browser, the read-only view in a browser
(PHPUnit only).

