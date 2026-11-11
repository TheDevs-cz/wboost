# Calendar module — codebase conventions to follow (exploration digest, 2026-11-11)

Read CLAUDE.md first. This is a digest of how existing code does the things the calendar module needs. Verify a file still exists before copying from it.

## Module wiring
- `src/Value/ProjectModule.php` — enum of modules (`label()`, `description()`, `icon()`, `landingRoute()` → `[route, paramName]`). Add `Calendars` (icon `calendar` exists in `icons.css`). Update its docblock ("Kalendáře isn't built yet").
- `Project::$modules` JSONB list; default literal must stay `'["manuals", "templates"]'` (schema drift otherwise).
- Sidebar: `templates/_shell/_sidebar.html.twig` — one `{% if nav_project.hasModule(project_module.X) %}` block per module; menu order is pinned by `tests/Controller/Project/ProjectModulesTest.php` (FB/IG always last). Dashboard tiles: `templates/project_dashboard.html.twig`.
- Discover page `templates/project_modules.html.twig`: has a hard-coded "Kalendáře · Připravujeme" teaser row and a `+ 1` in the count — remove both.
- Guard: `#[RequiresProjectModule(ProjectModule::X)]` on the landing controller only.
- Existing teaser to replace: `src/Controller/Project/CalendarController.php` (route `calendars`, `/project/{projectId}/calendars`), `templates/calendars.html.twig`, teaser CSS in `assets/styles/nocturne/manual.css` (~l.265).
- Tests asserting calendars are absent: `ProjectModulesTest.php` (~l.42, menu list ~l.57), `ProjectDashboardControllerTest.php` (~l.42). Fixture project 1 gets every `ProjectModule::cases()`.
- Backfill-migration example: `migrations/Version20261011112727.php`.

## Controllers / auth / CSRF / JSON
- One invokable `final class XController extends AbstractController` per action, attribute routes, snake_case route names, `#[MapEntity(id: 'projectId')] Project $project`, `#[IsGranted(ProjectVoter::VIEW, 'project')]` on the method. Writes via `MessageBusInterface::dispatch()` (doctrine_transaction middleware flushes). Ids from `Services/ProvideIdentity::next()` (uuid7).
- Voters in `src/Services/Security/`. `ProjectVoter`: admin/owner → EDIT; shared (Read) → VIEW only. `WeeklyMenuVoter` is the delegate-through-project example. "Grafik" = `project_edit`; read-only share = client-like.
- Fetch endpoints: multipart `FormData` with `_token` (named CSRF id rendered into a `data-…-csrf-value`), server `isCsrfTokenValid(ID, $request->request->getString('_token'))`, JSON 403 "Platnost formuláře vypršela…" on failure. No header convention, no raw JSON bodies (the Sort* controllers do that without CSRF — don't copy).
- Editor save precedent: `SaveManualIntroPageController` → `Services/Manual/ManualContentPageEditor::save()`: POST `payload` = JSON string + `_token`; payload VO `ManualContentPagePayload::fromJson` with `MAX_BYTES`; unwrap `HandlerFailedException` → 422 `{error}`; responds `{state}` (normalised). JS: `assets/controllers/manual_content_editor_controller.js` (~l.1345–1388). Per-field autosave + toast precedent: `project_palette_controller.js`.
- `config/packages/security.php` ~l.223: one regex of public paths (contains unanchored `.*/preview` — any URL containing `/preview` is public at access_control level; never use `/preview` in authenticated calendar URLs; every route carries its own `#[IsGranted]`). Add the client-link path there.
- Token-link precedent: `WeeklyMenuApprovalController` (`bin2hex(random_bytes(32))` stored on the entity, `hash_equals`, 404 on mismatch). Public pages are standalone HTML (don't extend `base.html.twig`) — see `templates/weekly_menu/approval.html.twig`.
- Don't copy weekly-menu shortcuts: delete without CSRF/methods, uuid-addressed public page, `->stream()` PDF response.

## Entities / migrations
- `#[Id] #[Column(type: UuidType::NAME, unique: true)] public UuidInterface $id`; constructor promotion, public props, `#[Immutable]` / `#[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]`; explicit `createdAt`/`updatedAt` (ClockInterface in handlers, no lifecycle callbacks); project FK `#[JoinColumn(nullable: false, onDelete: 'CASCADE')]`; user refs `onDelete: 'SET NULL'`.
- JSONB: plain `#[Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '[]'])]`, or typed VO + custom type in `src/Doctrine/*DoctrineType.php` (model: `ManualContentBackgroundDoctrineType`; register in `config/packages/doctrine.php`).
- Repository: `readonly final class XRepository` (`get()` throws `XNotFound`, `add()`, `remove()`); read models in `src/Query/Get*.php`. Messages in `src/Message/<Area>/`, handlers in `src/MessageHandler/<Area>/`.
- Migrations hand-written `migrations/VersionYYYYMMDDHHMMSS.php` with `getDescription()` + real `down()`, Doctrine `IDX_…/FK_…` names. CI job runs migrate + `schema:update --dump-sql` + `schema:validate` — the migration must match mapping exactly (generate with `doctrine:migrations:diff` then tidy). Tests build schema via `schema:create`; rebuild the test DB after adding migrations/fixtures.
- History precedent: `WeeklyMenuApprovalAuditLog`; `ProjectModuleChange` (enum + user SET NULL + index). `EntityWithEvents` is unused infrastructure — record history explicitly in handlers.

## Uploads / storage
- Inject `League\Flysystem\Filesystem`, `->write($path, $bytes)`. Public URL: `UploaderHelper::getPublicPath($path)`; Twig `uploaded_asset(path)`. No thumbnail/resize service — raw public URLs.
- Key namespaces: `file-upload/{projectId}/…` (gallery), `manuals/{manualId}/…`, `fonts/{projectId}/…`, … → use `calendars/{calendarId}/…`.
- Upload endpoint model: `UploadManualContentImageController` (CSRF → `Image(maxSize: '10m')` → `NormalizeImageFormat::normalize()` returns `{contents, extension, mimeType, width, height}`; HEIC converted; SVG returns null and is stored as is). `ReadStoredImagePixelSize`, `DownscaleImage::toLongEdge()` exist.
- **10 MB decimal** (10 000 000 bytes) everywhere, JS mirror `data-…-max-file-size-value="10000000"`; document new mirrors in `docs/architecture/gallery-and-uploads.md`.
- New path-storing column ⇒ update: `Services/Storage/BuildStorageReferenceIndex` (`queries()` entry keyed `table.column`, plus `PATH_PREFIXES`), `ResolveStorageOwnerByPath::buildIndex()` (UNION for the new entity id), `Value/StorageCategory::fromPath()` (+ `StorageCategoryTest`), `CollectProjectStoragePaths::collect()` (+ its test). `DeleteProjectHandler` collects paths before delete.

## Fonts / palette
- `Entity/Font` with JSONB `faces` (`Value/FontFace {name, weight, style, filePath}`); wire family string is exactly `Font::faceFamily($face)` = `"Rubik (Rubik Bold)"` — never re-implement. `Query/GetFonts::allForProject()`. Picker-ready list: `ResolveRichTextOptions::projectFaces()` / `->forProject($projectId)->toToolbarArray()['fonts']` → `{family, fontName, faceName, weight, style, url}`.
- `@font-face` emission precedent: `templates/editor/_editor_fonts.html.twig`, `manual_content_page_editor.html.twig` (l.12–19). Nearest-cut JS logic: `assets/controllers/canvas_font_choice.js`.
- Font rename rewrites stored references via `Services/Font/RewriteFontReferences` — calendar JSON storing family strings must be added there.
- Palette: `ProjectColorRepository::forProject()` = all project colours (use this); `GetProjectPalette::forProject()` = only manual-linked ones. Swatch popover precedent: `color_picker_controller.js` + `nocturne/color-picker.css`.

## Front end
- AssetMapper + `importmap.php` (stimulus 3.2.2, turbo 8, bootstrap 5.3.3, sortablejs 1.15.7, …). Only importmap entries or relative paths can be imported. No package.json at root, no bundler.
- Stimulus: `assets/controllers/<snake>_controller.js`; helper modules without the suffix, imported relatively; heavy controllers start with `/* stimulusFetch: 'lazy' */`. Pure logic goes in node-testable ES modules.
- `<html data-turbo="false">` site-wide. Toast: no shared helper — copy `project_palette_controller.js` l.335–357 (`.toasts` + `.toast-n` / `.toast-n.err`, CSS in `nocturne/feedback.css`). Dialogs: Bootstrap modals (`window.bootstrap.Modal`) restyled by Nocturne.
- Page blocks: `title`, `title_count`, `page_lead`, `title_actions` (one primary), `mobile_primary_action`, `breadcrumb`, `eyebrow`, `inner_class`, `custom_styles`, `body_class`; `active_menu_item` variable. Full-screen editors override `page_title_row` with an empty block (`template_variant_editor.html.twig` l.30–32); forced collapsed rail = hard-coded route list at `base.html.twig:13`.
- New area CSS: `assets/styles/nocturne/calendar.css`, imported in `assets/app.js` before `app.css`; list it in CLAUDE.md's CSS table and `app-shell.md`. Use tokens from `tokens.css` only.

## Tests / CI
- PHPUnit 13, `tests/` mirrors `src/`; DAMA transactions; fixtures `tests/DataFixtures/TestDataFixture.php` (`PROJECT_1_ID` owned by `USER_1_EMAIL`, `PROJECT_2_ID`, `SHARED_USER_EMAIL` has project 1 read-shared, `ADMIN_USER_EMAIL`). Controller tests: `WebTestCase` + `TestingLogin::logInAsUser($client, …)`. Handler tests: `KernelTestCase`.
- JS tests: no Jest. PHPUnit runs node harnesses through `Symfony\Process`, skipping when node is missing — example `tests/Value/PrintSettingsGeometryContractTest.php` (l.121–139), fixtures `tests/Fixtures/**/*-harness.{cjs,mjs}`.
- No Playwright/Panther. `docs/redesign/tools/shoot.cjs` = puppeteer-core screenshot tool (needs `PUPPETEER_DIR`); `.claude/skills/verify` explains launching/logging in.
- CI (`.github/workflows/test.yml`): phpunit (after `importmap:install` + `asset-map:compile`), phpstan level max (`composer phpstan`), migrations-up-to-date.
- Run everything in Docker: `docker compose exec web …`.

## Render / print (future PDF export)
- Gotenberg screenshot: `TemplateVariantImageRenderer` (~l.584–633), fonts/images inlined as data URIs (`AssetInliner`). Print pipeline `src/Services/Print/` (`PrintPageGeometry(trimWpt, trimHpt, bleedPt, cropMarks)`, `PdfX1aWriter`) is reusable. Never return a flushing `StreamedResponse`.

## MCP
- `src/Mcp/Tool/*Tool.php` (`#[McpTool]`, `#[McpToolScope(McpScope::X)]`), responses in `src/Mcp/Response/`, new scope needs an arm in `McpScope::grants()`; `ScopeFilteringTest` and `tests/Mcp/SkillDocumentationTest` (every tool named in `plugin/wboost/skills/wboost/SKILL.md`); server instructions in `config/packages/mcp.php`.

## Misc traps
- Never nest `<form>`. Twig `{}` encodes as `[]` (breaks Stimulus Object values — pass JSON via `<script type="application/json">` or String values).
- Messenger wraps handler exceptions in `HandlerFailedException`.
- No Czech holiday/name-day/Easter/moon code exists anywhere; `ext-calendar` is not required — compute Easter yourself. `endroid/qr-code` is installed (not needed: QR = uploaded images).
- Docs rule: add `docs/architecture/calendars.md` and link it from CLAUDE.md's topic table.
