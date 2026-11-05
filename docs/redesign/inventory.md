# Current wboost UI inventory (pre-redesign, 2026-11-02)

## How Hyper/Bootstrap is loaded
- Layouts: only `templates/base.html.twig` (Hyper shell: `.wrapper`, `.leftside-menu` + simplebar, `.leftbar-account` drop-up, `.mobile-topbar`, `.content-page > .content > .container-fluid`, `page-title-box`; blocks `title_actions`, `breadcrumb`, `content`, `full_content`) and `base_manual.html.twig` (extends base, adds "Zobrazit manuál"). 78 templates extend base, 9 base_manual. Auth, errors, both canvas editors, manual preview, oauth consent and FB deletion override `full_content` (no shell).
- `<head>`: `importmap('app')` → `assets/app.js` imports `bootstrap.min.css` (5.3.3), `grapes.min.css` (global!), `assets/styles/app.css`, Stimulus starter. Then `public/theme/js/hyper-config.js`, `public/theme/css/app-saas.min.css` (437 KB, embeds Bootstrap again), `public/theme/css/icons.min.css` (MDI + Remix + Unicons).
- End of body: `public/theme/js/vendor.min.js` (jQuery, Bootstrap bundle = global `window.bootstrap`, simplebar, lucide), `public/theme/js/app.min.js` (sidebar toggle, tooltip/popover/toast init).
- `public/theme/` ≈ 55 MB. `admin/usage.html.twig` loads `theme/vendor/apexcharts/apexcharts.min.js`. Default avatar `theme/images/users/avatar-1.jpg`. No jQuery usage in our code.
- Bootstrap JS via markup: `data-bs-toggle="modal"` (14 templates), `dropdown` (22), `collapse` (2), `tooltip` (1), `data-bs-dismiss="alert"` (flashes).
- Stimulus controllers calling global `bootstrap.Modal` (23 calls): `canvas_editor` (`#exportUnsavedModal`, `#addTextModal`, `#addChecklistModal`, `#imageGalleryModal`), `canvas_input_properties` (`#imageGalleryModal`, `#sampleTextModal`), `confirm_modal` (on `<body>`), `modal` (cleans `.modal-backdrop`), `auto_open_modal`, `background_picker`, `variant_count_modal`, `meal_selector`, `variant_fill_overlay` (guards global, queries `.modal.show`).
- Bootstrap markup/class reliance: `canvas_floating_toolbar` (`.canvas-mini-toolbar .btn[title]`), `canvas_layers`, `collapsible`, `popover_drag.js`; 18 controllers toggle `d-none`/`show`/`active`/`btn-*` (105 calls).
- Form theme: `config/packages/twig.php` → `bootstrap_5_layout.html.twig`; no custom form themes.

**Decision implication:** keep Bootstrap 5 JS (modal/dropdown/collapse) + Bootstrap grid/utilities as a behaviour layer during the reskin; drop Hyper CSS/JS (`app-saas`, `hyper-config`, `app.min.js`, vendor bundle → replace with bootstrap.bundle from importmap) and icon fonts later.

## User-facing pages (route → template)
Auth (full_content, Hyper `authentication-bg`/`account-pages`): login, registration, set_password, forgotten_password, oauth_consent, facebook_data_deletion_status.

Projects: `/dashboard` → redirect; projects (`projects.html.twig`, `_project_card`, `_project_avatar`); project_dashboard; project_modules; add_project/edit_project; project_gallery (Live `Project:ImageGallery`); calendars; fonts_list; add_font_face/rename_font.

Manuals (`base_manual`, `_manual_nav`): manuals_list, add/edit_manual, manual_logos, manual_colors (since F7 P3 only a redirect to `project_palette#manual-colors-{id}`), manual_fonts (+add/edit), manual_mockup_pages (+add/edit), manual_preview (standalone, customer-facing — do NOT restyle).

Templates: templates (`_template`, `_document`), template_categories (+add/edit), add/edit_template, template_variants, add_template_variant, **template_variant_editor** (+ `templates/editor/_editor_*` 6 partials), template_variant_export (single fill, Live `VariantFiller`), template_variant_print_settings, *_export_history (shared `template_export_history.html.twig`), add_template_group(+dimension), template_group_from_template, **template_group_editor**, template_group_fill, add/edit_template_document, template_document, template_document_fill, export_failed.

Email signatures: list, variants, add/edit template & variant, GrapesJS editor.

Weekly menus (Jídelníčky): weekly_menus, add/edit, planner (Live `WeeklyMenuPlanner`), meals, diets, dish_types (+add/edit). Standalone public/approval/PDF pages — do not restyle.

Admin (`src/Controller/Admin/`): admin_users, admin_invite_user, admin_edit_user, admin_registration_requests, admin_landing_leads, admin_usage (ApexCharts), admin_storage_files (only paginated page).

Account: user_profile, edit_profile, change_password, connected_apps, mcp_guide (`/ai`).

Errors: `templates/bundles/TwigBundle/Exception/error*.html.twig` (extend base, `authentication-bg`). Emails: 8 templates in `templates/emails/` (no layout) — out of scope.

## Shared partials / components
Live/Twig components: `VariantFiller`, `Project/ImageGallery`, `ManageProjectSharing`, `LogoColorsMapping`, `ManualPageText`, `WeeklyMenuPlanner`, anonymous `ConfirmModal`, `DownloadManualImage`. Flashes `_flashes.html.twig`. Toasts built in JS (gallery_uploader, group_sync, group_editor, canvas_missing_images, fill_draft) styled in app.css. Modals: `ConfirmModal`, `_background_gallery_modal`, `_email_signature_demo_modal`, `_social_publish_fake_door` (in base), `editor/_editor_modals`, `weekly_menu/_preview_modal` (19 templates contain `class="modal`). No shared empty-state/table/pagination partials.

## Stylesheet
Single `assets/styles/app.css` (117 KB, 4479 lines): 1–1100 editor shell; 1100–1730 fill overlay/popovers; 1734–2430 logo cards, weekly menu; 2431–2670 gallery/Koš/toasts; 2670–3260 modules nav, manual pages; 3268–3800 group editor/fill, navigator, rich lists; **3844–3962 app shell overrides (Hyper-coupled)**; 3963–4479 variant grid, fonts, colour allowlist, project/document cards. 20 rules target `leftside-menu`/`side-nav`, 34 use `--ct-*`.

## Must-stay-stable surfaces
- Headless render: `templates/api/template_variant_render.html.twig`, `templates/api/echo_golden_harness.html.twig` (no theme) — untouched.
- Classic scripts `assets/editor/*.js` (three-way contract) — untouched.
- Editor chrome: `template_variant_editor`, `template_group_editor`, `editor/_editor_stage` (771 lines), `_editor_left_panel`, `_editor_modals`, `_editor_toggles`, `_editor_fonts`, `canvas_*_controller.js`, `group_editor_controller.js`, `canvas_handles.js`. `canvas_zoom_controller.js` measures its offset; `.editor-left-panel-card` sticky top 56 px. Modal IDs must stay.
- Fill overlay: `variant_fill_overlay_controller.js`, `VariantFiller.html.twig`, `_fill_*`.
- Customer output: `manual_preview.html.twig` + `_manual_*`/`_mockup_page*`, `DownloadManualImage`, weekly-menu public/approval/PDF.

## Usage counts (157 twig files)
`btn-*` 114 files/655 hits · `card` 94/353 · `uil-*` 80/149 · `mdi-*` 76/449 (+65 in 17 JS controllers) · `badge` 38/115 · `alert` 26/38 · `dropdown-menu` 22/46 · `<table` 20/43 · `form-control/select` 19/118 · `ri-*` 19/39 (+2 JS) · `modal` 19/172 · `avatar-*` 12/42 · `authentication-bg` 9/19 · `--ct-*` 8/40.

## Tests that assert on theme classes (will break if classes change)
- `tests/Controller/Authentication/LoginControllerTest.php:81,109` `.alert-danger`
- `tests/Controller/TemplateDocument/TemplateDocumentFieldsControllerTest.php:102` `.alert-danger, .alert`
- `tests/Controller/TemplateDocument/TemplateDocumentFillControllerTest.php:74` `button[...].btn-primary`
- `tests/Controller/TemplateGroup/TemplateGroupFillControllerTest.php:116,123` `.modal[data-image-modal=…]`
- `tests/Controller/TemplateGroup/TemplateGroupExportVersionTest.php:109` `.text-white`
- `tests/Controller/Mcp/McpGuideControllerTest.php:225` `.leftbar-account__menu .dropdown-item`
- `tests/Controller/Template/TemplateExportVersionCurationTest.php:205` literal `<div class="text-truncate fw-semibold">`
- `.breadcrumb` (2), `.header-title` (1)
45 test files / ~387 selector calls mostly on app BEM classes + `data-*` — re-run the full suite per phase.
