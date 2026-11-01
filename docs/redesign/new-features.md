# Prototype features vs the current app

This page checks every row of `design-system.md` §9 against the code on `redesign/nocturne` (2026-11-02). It records what the redesign only has to restyle and what needs new work. Status was checked against `src/Entity`, `src/Controller`, `src/Value` and `templates`, not taken from memory notes.

**Legend.** Effort: **S** = UI-only, or less than ½ day using data we already have. **M** = 1–3 days. **L** = more than 3 days, or an external integration. **Cheap-now** = S, no schema change and no new external service, so it can ship with the redesign.

| # | Feature | Status | What exists (paths) | Gap | Effort | Schema? | Cheap-now? |
|---|---|---|---|---|---|---|---|
| 1 | Paid projects ★ | NEW | — (`Entity/Project.php` has no such flag) | `paid` column, admin toggle action, star on the card, "Placené" chip | S–M | **yes** (bool column) | no |
| 2 | Completeness warning | NEW | Manual colours and fonts exist (`Entity/Manual.php`, `ManualFont`) | A "missing palette/fonts" flag per project card. Computed; batch it to avoid N+1 | S | no | **yes** |
| 3 | Dashboard filters | NEW | `projects.html.twig` is a flat list. Owner and manual data exist | Chips: Moje projekty, Grafik (owner), S/Bez manuálu, done client-side over the rendered cards. "Placené" depends on #1 | S | no | **yes** (without Placené) |
| 4 | Quick project switcher | NEW | — | Sidebar combobox: the user's projects plus the last 3 opened, kept in `localStorage` | S | no | **yes** |
| 5 | Project modules | EXISTS | `Project::$modules`, `Value/ProjectModule.php`, `Project/ProjectModulesController`, `Enable/DisableProjectModuleController` | The prototype's `socialni` and `ai` modules are not in the enum. Leave them out | — | — | restyle |
| 6 | Self-service module discovery | EXISTS | `project_modules.html.twig`, `EnableProjectModuleController` (admins get an e-mail), `ProjectModuleChange` | — | — | — | restyle |
| 7 | Personal tile order | NEW | Tiles in `project_dashboard.html.twig` | Drag-sort the tiles and keep the order in `localStorage` (S). Syncing it server-side per viewer would need a column (M) | S | no (localStorage) | **yes** |
| 8 | Project members box | PARTIAL | `ProjectShare`, `Twig/Components/ManageProjectSharingComponent` (modal on the card), owner line on the dashboard | A members box on the project dashboard (owner and collaborators) that opens the existing share modal: S. Role pills and "Spravovat role": `SharingLevel` has only `Read`, so per-project roles need an enum and migration (M) | S / M | roles: yes | box: **yes** |
| 9 | Invite users + roles | EXISTS (mostly) | `Admin/InviteUserController`, `FormType/InviteUserFormType` (e-mail, name, role, projects), `ResendInvitationController`, 7-day token (`Services/InvitationMailer.php`), "pozván" status in `admin/users.html.twig` | Not there yet: "copy invite URL" (the plaintext token is not stored) and a **disabled** user status | M | disabled: yes | no |
| 10 | Registration requests | EXISTS | `RegistrationRequest` (pending/invited/dismissed), `Admin/AdminRegistrationRequestsController`, `DismissRegistrationRequestController`, sidebar badge in `base.html.twig` | Rows pinned inline on the users page and a "Žádosti" filter (UI only). "rejected" maps to `Dismissed` | S | no | **yes** (layout) |
| 11 | AI scopes per user | EXISTS | `User::$mcpScopes`, `FormType/EditUserFormType` (`mcpScopes`), `Value/McpCapability` | — | — | — | restyle |
| 12 | Full-page user profile | PARTIAL | `admin/edit_user.html.twig` (form), `User/UserProfileController` (self), `User/ConnectedAppsController` (self only) | An admin page with sections Profil / Projekty (shares) / AI aplikace (that user's OAuth clients and MCP tokens) / Další údaje. All read-only data that already exists | M | no | no |
| 13 | Client statistics (sales tool) | PARTIAL | `/admin/usage` (`Admin/AdminUsageController`, `Query/GetUsageOverview`): KPI tiles, per-client × month heatmap, user activity. `ExportEvent` stores channel (web/api/mcp/fb/ig), format and the triggering user | Alerts and insights (no downloads, streaks, ±40 %, dormant templates, unused output types), SS/AI columns, expandable per-user bars, client detail dialog | L | no | no |
| 14 | System statistics | PARTIAL | Same page: totals, active clients | 3/6/12-month trend, output-type rings (`ExportEvent.format`/`channel`), top templates. New queries only | M | no | no |
| 15 | Storage inventory UI | EXISTS | `/admin/usage` "Úložiště" (total, unused/orphaned, per-client bars, last scan), `Admin/RescanStorageController`, `Admin/AdminStorageFilesController` | — | — | — | restyle |
| 16 | Savings ("Úspory") | NEW | The raw data is in `ExportEvent` | Pricing rules (new design vs synced extra format; group/variant attribution), month select, KPIs, monthly bars, list | M | no | no |
| 17 | Gallery folders and trash | PARTIAL | `Twig/Components/Project/ImageGallery.php`: folders, Koš (restore/purge), rename file/folder, move (menu), upload dropzone. `Query/GetGalleryImageUsage` | Folders as **drag targets**, **bulk select** (move/delete), **"used N×" badge** (the query exists, it only needs batching), tile-size slider | M (badge + slider S) | no | slider: **yes** |
| 18 | Fonts page | EXISTS | `fonts.html.twig`, `Font/*Controller`, `font_specimen_controller.js` (shared sample text in localStorage, size slider, copy name), `Query/GetFontUsage`, weight axis | Copy **CSS** (`@font-face`/`font-family` snippet) instead of only the name | S | no | **yes** |
| 19 | Rich palette | PARTIAL | `manual_colors.html.twig`, `Manual/ManualColorsController`: detected (logo) and custom colours, role (`ManualColorType` primary/secondary), CMYK, Pantone, RAL | Show RGB automatically, "compute CMYK from HEX" button (client-side JS), autosave (M: the form is a single submit today) | S (RGB+CMYK) / M (autosave) | no | RGB + CMYK: **yes** |
| 20 | Read-only fonts/palette for clients | PARTIAL | Fonts page is `ProjectVoter::VIEW` and upload is gated (already read-only for clients). Palette is `ManualVoter::EDIT` only; clients see colours in `manual_preview` | A read-only palette view for VIEW users and "missing value" flags: S. The "ask the designer" action needs a notification e-mail (M) | S / M | no | view + flags: **yes** |
| 21 | Manual: detected logo colours | EXISTS | `Services/DetectImageColors.php`, `Components/LogoColorsMappingComponent`, `Value/DefaultLogoColors`, width cascade `EditManualLogoSlotWidthController` + `manual_logo_width_controller.js`, own-page `_manual_logo_variant_page.html.twig` | — | — | — | restyle |
| 22 | Manual: PDF-reader preview | PARTIAL | `Manual/ManualPreviewController`, `manual_preview.html.twig` (scrolling pages). Mockup pages can be reordered (`SortManualMockupPagesController`) | Thumbnail rail with scroll sync: S (UI). Reordering *all* manual pages needs a stored page order: M | S / M | reorder: yes | rail: **yes** |
| 23 | Client "missing logo variant" flow | NEW | Logo variants are known per manual | Alert when a variant is missing: S. "Ask the designer" / "report a problem" need an e-mail or notification backend: M | S / M | no | alert only: **yes** |
| 24 | Template categories as tags | PARTIAL | `SortTemplateCategoriesController`, `SortTemplatesController`, dragula sorting in `templates.html.twig` | Collapsible category groups remembered per viewer (`localStorage`) | S | no | **yes** |
| 25 | "Nejpoužívanější" | NEW | `ExportEvent` (projectId, templateId, exportedAt) | Top 4 templates by exports in the last 30 days for the project: one query plus a row | S | no | **yes** |
| 26 | Template types | EXISTS | `AddTemplateController`, `TemplateGroup/AddTemplateGroupController`, `TemplateDocument/AddTemplateDocumentController` (three buttons in `templates.html.twig`) | Merge them into one "new template" dialog (restyle). The "z obrázku" type is #27 | S | no | **yes** (dialog) |
| 27 | AI import of templates (Premium) | NEW | Canvas JSON and MCP design DSL (`src/Mcp/Design/`) could be the target format | Vision/LLM segmentation, a step-by-step review UI, a Premium gate | L | likely (jobs, premium flag) | no |
| 28 | Editor upgrades | EXISTS (mostly) | Group editor (edits all variants, "Prvky mimo plátno" badge in `group_editor_controller.js`), containers (`canvas_container_controller.js`), layers (`canvas_layers_controller.js`), clean-view toggles (`editor/_editor_toggles.html.twig`), minimap (`canvas_navigator_controller.js`), rulers, floating toolbar | Check the per-layer client-input marker and the off-canvas count on variant tabs in the single-template editor (small JS) | S | no | **yes** (marker / tab warning) |
| 29 | Fill page in-design editing | EXISTS (mostly) | Overlay boxes and popovers (`_fill_overlay_boxes.html.twig`, `variant_fill_overlay_controller.js`, `popover_drag.js`), colour allowlist, "Všechna pole", drafts (`SaveTemplateVariantFillDraftController`, `fill_draft_controller.js`), PNG/PDF ZIP split (`template_group_fill.html.twig`) | **Eyedropper** (native `EyeDropper` API, snapped to the allowlist) and hide/show container rows in the fill UI. Neither is verified to exist | S–M | no | eyedropper: **yes** |
| 30 | Export history curation | EXISTS | `TemplateExportVersion`, `Pin/RenameTemplateExportVersionController`, `_export_history_menu*.html.twig`, `template_export_history.html.twig` | — | — | — | restyle |
| 31 | Share to social + AI copywriter | PARTIAL | FB/IG backend: `SocialAccount`, `Services/Meta/PublishToFacebookPage.php`, `Template/TemplateVariantPublishController`, `SocialAccount/*`. The UI is still a fake door (`_social_publish_fake_door.html.twig`) | Real publish UI with post preview, 4:5 download, and the **AI copywriter** (no LLM integration in `src/`) | L | maybe (post drafts) | no |
| 32 | Poptávky / Propojené aplikace / AI (MCP) | EXISTS | `Admin/AdminLandingLeadsController` + `LandingLead`, `User/ConnectedAppsController`, `Mcp/McpGuideController` (+ MCP server) | In the prototype these are placeholder pages; the app has real ones. Only the nav needs reshaping | S | no | **yes** (nav) |
| 33 | Light theme | NEW | Tokens specified in `design-system.md` §1 (`:root[data-theme="light"]`) | Toggle in `localStorage['wb-theme']` plus the OS default: S. A visual QA pass of every page in light mode: M | S (+QA) | no | **yes**, if pages use tokens only |
| 34 | Role preview (view-as) | NEW | No `switch_user` in the security config | Admin "view as Grafik/Klient". The prototype marks it as prototype-only. Real impersonation (`switch_user`) needs a security review | M | no | no |

## Recommended to implement during the redesign

All of these are UI-only or use data we already have. None needs a migration or a new external service.

1. **#3 Dashboard filter chips** (Moje projekty / Grafik / S·Bez manuálu), client-side over the rendered cards. "Placené" waits for #1.
2. **#2 Completeness triangle** on project cards (palette or fonts missing). Compute it in one batched query.
3. **#4 Quick switcher**: search over the user's projects, with the last 3 opened from `localStorage`.
4. **#7 Personal tile order** on the project dashboard (SortableJS + `localStorage`).
5. **#8 Members box** on the project dashboard, opening the existing `ManageProjectSharing` modal. Leave out role pills.
6. **#10** Registration requests pinned inline on the users page, plus a "Žádosti" filter.
7. **#17** Gallery tile-size slider.
8. **#18** Copy-CSS button on font cuts.
9. **#19** Auto RGB plus "compute CMYK from HEX" on the palette.
10. **#20** Read-only palette view for VIEW users, with missing-value flags (no "ask designer" action yet).
11. **#22** Thumbnail rail with scroll sync in the manual preview (no reorder).
12. **#23** "Missing logo variant" alert for clients (no actions yet).
13. **#24** Collapsible template categories remembered in `localStorage`.
14. **#25** "Nejpoužívanější" row (top 4 by `ExportEvent`, last 30 days).
15. **#26** One "new template" type dialog over the three existing create routes.
16. **#28 / #29** Per-layer client-input marker, off-canvas count on variant tabs, eyedropper on the fill page.
17. **#32** Sidebar sections for the existing landing-leads, connected-apps and MCP pages.
18. **#33** Light/dark toggle, if the restyled pages use only design tokens. Budget a QA pass.

## Later (backend work)

- **Schema:** #1 paid flag (S–M). #8 per-project roles (`SharingLevel` has only `Read`). #9 disabled user status and "copy invite URL". #22 reordering all manual pages.
- **New queries and pages, no schema:** #12 admin user profile page (M). #14 system statistics trend, rings and top templates (M). #16 Úspory (M; the pricing rules need sign-off). #13 client sales insights and detail dialog (L). #17 gallery drag-to-folder, bulk select and "used N×" badges (M). #19 palette autosave (M).
- **Notifications:** #20 / #23 "ask the designer" and "report a problem" actions (an e-mail to the project owner or admins, M).
- **External or AI:** #27 AI template import (L, vision model plus review flow). #31 real FB/IG publish UI plus the AI copywriter (L; the publish backend already exists).
- **Security:** #34 admin view-as (`switch_user`) needs a review. Treat it as prototype-only for now.
