# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Development Commands

**Local Development:**
```bash
docker compose up  # Runs application at http://localhost:8080
```

**User Management:**
```bash
docker compose exec web web bin/console app:user:register <email> <password>  # Create user
```

**Code Quality:**
```bash
docker compose exec web composer phpstan          # Run PHPStan static analysis (level max)
docker compose exec web vendor/bin/phpunit       # Run PHPUnit tests
```

**Asset Management:**
```bash
docker compose exec web bin/console importmap:install      # Install frontend assets
docker compose exec web bin/console asset-map:compile     # Compile assets for production
```

## Architecture Overview

This is a **Symfony 7** application for brand manual management, using:

- **CQRS Pattern**: Commands/Queries with dedicated handlers in `Message/` and `MessageHandler/`
- **Domain-Driven Design**: Entities represent core business concepts (Manual, Project, User, etc.)
- **Event-Driven Architecture**: Domain events via `EntityWithEvents` trait
- **Dockerized Environment**: Full stack with PostgreSQL, Redis, Minio S3, and MailCatcher

### Social Network Template Editor

The largest feature in the codebase. A `SocialNetworkTemplate` is a Fabric.js
canvas that an admin authors once and end-users / API consumers fill with their
own copy. This section is the post-migration shape (Stages 1–7) — older patterns
(text canvas column, positional input binding, monolithic Stimulus controller,
Fabric v5) have been retired.

**Data model — `social_network_template_variant`**

- `canvas`: **JSONB** (Stage 1). The serialized Fabric document. Empty rows
  are stored as `'{}'` (never `''`) and the renderer synthesizes a minimal
  Fabric document with just a background image when it sees an empty canvas.
- `preview_image_path`: nullable string (Stage 1). Path to a PNG in Minio
  (rendered server-side after each admin save). Replaces the legacy
  `preview_image` BLOB column. The full URL is built via the upload helper.
- `inputs`: JSONB array of `EditorTextInput` value objects, persisted via
  `EditorTextInputsDoctrineType`. Each entry carries its `inputId` (UUID v4).
- **inputId UUID binding (Stage 2)**: every textbox / image on the canvas
  carries a custom property `inputId` minted at admin-time, and every
  `EditorTextInput` row mirrors that same id. Overrides (text content, hidden
  flag) are looked up by id, not by index — so two inputs may legitimately
  share a `name`, and reordering objects on the canvas no longer rebinds
  inputs. The `EditorTextInput::fromArray` factory keeps a defensive UUID-mint
  fallback for legacy rows; once the migration has run on prod, no live row
  hits it.

**Admin editor — 7 Stimulus controllers (Stage 4)**

The legacy monolithic `social_network_canvas_controller` was split along
responsibility boundaries. All siblings reach the orchestrator via Stimulus 3
**outlets** (`static outlets = ["canvas-editor"]`) to read its `this.canvas`
Fabric instance, and listen to a `canvas-editor:selection:changed` window
event the orchestrator dispatches on Fabric's selection lifecycle:

| Controller | Responsibility |
|---|---|
| `canvas_editor_controller` | Orchestrator. Owns the Fabric `Canvas`, loads/saves canvas JSON, marks the form dirty, broadcasts selection changes. |
| `canvas_history_controller` | Undo/redo stack — full-canvas-JSON snapshots, restored via the orchestrator's loader. |
| `canvas_clipboard_controller` | Copy / paste / duplicate (keyboard + buttons). |
| `canvas_zoom_controller` | CSS-transform-only visual zoom of the wrapper element. |
| `canvas_text_toolbar_controller` | Font / size / colour / alignment / decoration / max-length controls for the active textbox. |
| `canvas_input_properties_controller` | Editor-side input metadata (name, description, locked, hidable, uppercase). Persists onto the canvas object as custom properties via `CANVAS_CUSTOM_PROPERTIES`. |
| `canvas_alignment_controller` | Multi-object align ops, z-order, delete. |

**User-fill flow — Live Component (Stage 5)**

`SocialNetwork:VariantFiller` (`src/Twig/Components/SocialNetwork/VariantFiller.php`)
replaces the old client-side Fabric runtime on the user-fill page. There is no
canvas in the browser — text inputs are bound via
`data-model="on(input)|debounce(600)|..."` so re-renders fire ~600ms after the
user stops typing (live-preview feel; checkboxes stay on `on(change)`), the
server resolves overrides via
`ResolveTextOverrides`, and the preview image is rendered by the same Gotenberg
path the API uses. Download is a regular controller action.

**Project image gallery — Live Component (Stage 7 → 8)**

`Project:ImageGallery` (`src/Twig/Components/Project/ImageGallery.php`) is the
per-project, per-`FileSource` asset library shown in the admin editor's "Add
image" / "Set background" modal. Image **selection** stays a DOM
`CustomEvent("asset-selected")` (with `{ url, path, id }`) so the host Stimulus
controller routes the chosen URL to `addImageToCanvas` or `setBackgroundImage`
without a server round-trip.

Stage 8 added a **filesystem-like nested folder tree** on top:

- `FileDirectory` entity (`src/Entity/FileDirectory.php`) — nullable self-ref
  `parent` (null = root), scoped by `project` + `source`. `FileUpload` gained a
  nullable `directory` FK (`ON DELETE SET NULL`).
- Navigation/CRUD are **LiveActions** on the component (`openDirectory`,
  `openRoot`, `createDirectory`, `startRename`/`renameDirectory`,
  `deleteDirectory`, `moveFile`), each dispatching a CQRS message under
  `Message/Image/` (`CreateFileDirectory`, `RenameFileDirectory`,
  `DeleteFileDirectory`, `MoveFileUpload`). `$currentDirectoryId` is a (server-set)
  LiveProp; deleting a folder lifts its child folders **and** files to the parent
  (never discarded). **`#[LiveArg]` names must be lowercase** (e.g.
  `#[LiveArg('directoryid')]`) to match the HTML-lowercased `data-live-*-param`.
- Uploads still POST to `project_upload_file`; the modal's upload form carries a
  hidden `directoryId` (= `$currentDirectoryId`) so new files land in the open
  folder. **The upload form's field prefix is `upload_project_file_form[...]`**
  (the form's block prefix from `UploadProjectFileFormType`), and it must include
  a `_token` (`csrf_token('submit')`) — the form is submitted via
  `new FormData(form)` by the `gallery-uploader` controller.
- Authorisation: **no class-level `#[IsGranted]`** (its subject can't resolve from
  a LiveProp during a LiveAction — that 500s). Access is enforced in
  `#[PostMount]` + a `guard()` helper called by every render method and action;
  client-supplied folder/file ids are re-checked via `ownedDirectory()`.

The component root merges its controller via
`{{ attributes.defaults({'data-controller': 'image-gallery'}) }}` — writing a
second literal `data-controller` next to `{{ attributes }}` silently loses it
(duplicate attribute; browser keeps `live`).

The same component is also rendered **standalone** on a management page
(`SocialNetworkGalleryController` → `/project/{projectId}/social-network-gallery`,
linked from the social-networks page next to "Kategorie"). A `bool $modal`
LiveProp (default `true`) toggles the modal header/close chrome and the
click-to-select image buttons; pass `:modal="false"` to render plain thumbnails
where folders + upload + move are the management surface.

**Render path — Gotenberg + identical Fabric runtime**

PNG export (admin preview, user download, API export) all flow through
`SocialNetworkTemplateVariantImageRenderer`. It builds the canvas JSON
(inlining the background image as a base64 data URI so headless Chromium
needs no Minio access), renders `templates/api/social_network_template_variant_render.html.twig`
through Gotenberg, and waits for `window.canvasRendered === true`. The Twig
template runs the **same Fabric v7 build** the editor uses, so admin and
export pixels match. Post-Stage 6 the Fabric UMD bundle is committed at
`assets/fabric/fabric-7.3.1.min.js` and inlined as a `<script>` tag — the
renderer no longer fetches Fabric from jsDelivr at render time.

### Core Domain Entities

- **Manual**: Brand manuals with colors, fonts, logos, and mockup pages
- **Project**: Container for brand manuals with sharing capabilities
- **SocialNetworkTemplate**: Templates for social media content with variants
- **EmailSignatureTemplate**: Email signature templates with variants
- **User**: User management with authentication and profiles

### Key Architectural Patterns

- **Message Bus**: All write operations go through Symfony Messenger with dedicated handlers
- **Repository Pattern**: Custom repositories for complex queries (e.g., `ManualRepository`)
- **Value Objects**: Rich domain types in `Value/` directory (Color, Logo, etc.)
- **Security Voters**: Authorization logic in `Services/Security/` 
- **Form Data Objects**: Separate DTOs for form handling in `FormData/`

### External Services

- **S3/Minio**: File storage for uploads and generated assets
- **ImageMagick**: Image processing via PHP Imagick extension
- **Doctrine ORM**: Database layer with migrations and custom types
- **Twig Components**: Live components for interactive UI elements

### Testing Strategy

- PHPUnit with database transactions (`DAMA\DoctrineTestBundle`)
- Controller tests for HTTP endpoints
- Separate test database configuration
- Test fixtures in `tests/DataFixtures/`

### Development Services

- **Adminer**: http://localhost:8000 (postgres/postgres/wboost)
- **MailCatcher**: http://localhost:8025
- **Minio**: http://localhost:19001 (wboost/wboostminio)

Always run any commands in corresponding Docker container - like PHPStan: `docker compose exec web composer run phpstan`

## API (`src/Api/`)

The application exposes a REST API at `/api` powered by API Platform 4. The API is intended for service-to-service communication and is protected by OAuth2 with the `client_credentials` grant.

### Strict DTO rule

**Doctrine entities (`src/Entity/*`) are NEVER exposed as API resources** — neither as request bodies nor as responses. Entities are domain objects; transport shape is decoupled.

Each API feature lives in its own folder under `src/Api/<Feature>/`:

```
src/Api/
└── Projects/
    ├── ProjectResponse.php       ← read DTO carrying #[ApiResource]
    └── ProjectsProvider.php      ← API Platform State Provider (ProviderInterface)
```

A read DTO is a `final readonly class` with public scalar / value-object properties. It carries `#[ApiResource]` plus operation attributes (e.g. `#[GetCollection]`).

A State Provider implements `ApiPlatform\State\ProviderInterface` and is the **only** place that touches the database for that resource — usually via DBAL or a Doctrine repository. It returns one or more DTO instances. It MAY read the authenticated user from `Symfony\Bundle\SecurityBundle\Security` to scope results.

For write operations (none today): use a Request DTO + State Processor (`ApiPlatform\State\ProcessorInterface`) that dispatches a CQRS Message — never mutate entities directly from the processor.

### Adding a new API resource

1. Create `src/Api/<Feature>/<Feature>Response.php` — DTO with `#[ApiResource]`.
2. Create `src/Api/<Feature>/<Feature>Provider.php` — implements `ProviderInterface`.
3. Reference the provider in the operation: `provider: <Feature>Provider::class`.
4. Apply security: `security: "is_granted('IS_AUTHENTICATED_FULLY')"` for service-to-service.
5. Verify the route: `docker compose exec web bin/console debug:router | grep '/api'`.
6. Run `docker compose exec web composer phpstan` and `vendor/bin/phpunit`.

Service-loader convention: only `*Provider.php` and `*Processor.php` files under `src/Api/` are autowired. DTOs are not services.

### OAuth2 (client_credentials)

> **Full guide:** [`docs/api/authentication.md`](docs/api/authentication.md) — the
> end-to-end flow (create credentials → obtain token → call the API), the JWT
> claim layout, why there are no refresh tokens, an error reference, and the
> configuration table. The summary below is the quick version.

The API is protected by JWT (RS256) issued via the `client_credentials` grant. Service consumers POST to `/api/token`:

```bash
curl -sX POST https://example.com/api/token \
    -d 'grant_type=client_credentials' \
    -d 'client_id=...' \
    -d 'client_secret=...'
```

The returned bearer token's `sub` claim contains the linked **App User's UUID**. The `api` firewall reads that claim, loads the matching `User` via `api_user_provider`, and the State Provider scopes data to that user.

The link between an OAuth2 client and an App User is a row in `oauth2_client_user` (`client_identifier` → `user_id`), populated by `app:oauth-client:create`.

RSA keys are stored **directly in env vars** as base64-encoded PEM (decoded by Symfony's `%env(base64:...)%` processor); no key files on disk. See `.env` and `.env.local` for the four `OAUTH2_*` variables.

### Managing OAuth2 clients

```bash
# Create a client linked to a user (prints plaintext secret ONCE)
docker compose exec web bin/console app:oauth-client:create user@example.com --name=service-name

# List all clients with their linked users
docker compose exec web bin/console app:oauth-client:list

# Deactivate a client and revoke its outstanding tokens
docker compose exec web bin/console app:oauth-client:revoke <client-id>
```

### API testing

API tests extend `WBoost\Web\Tests\Api\ApiTestCase` (default `Accept: application/json` header; JSON-LD/Hydra is disabled, so collections come back as flat JSON arrays). To obtain a real access token in a test, use `WBoost\Web\Tests\TestingApiAuthentication::getAccessToken($client, $clientId, $clientSecret)` — it goes through the live `/api/token` endpoint, which is the contract being exercised. Fixture credentials live as constants on `tests/DataFixtures/TestDataFixture.php` (`OAUTH2_CLIENT_ID`, `OAUTH2_CLIENT_SECRET`).

### Social-network template variant export endpoint

`POST /api/social-network-template-variants/{id}/export` returns a PNG. The
request body is `ExportRequest` and its `inputs` map is **keyed by inputId
UUID** (Stage 2): `{ "inputs": { "<uuid>": "Hello", "<uuid>": { "value": "World", "hide": false } } }`.
Discover the available input ids via
`GET /api/projects/{projectId}/social-network-templates` — each
`variants[].inputs[].id` is the same UUID accepted here. Unknown ids
are silently ignored; locked inputs cannot be overridden; `hide` only
applies to inputs with `hidable: true`.
