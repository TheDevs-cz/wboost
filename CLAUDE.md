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

### Topic docs — read the relevant one BEFORE touching that area

Detailed architecture notes live in `docs/architecture/` (split out of this file
on 2026-10-11 to keep it small). They are accurate and load-bearing: they record
contracts, invariants and past root causes that the code alone does not explain.
Read the matching file(s) before changing an area, and update them in the same
commit when you change a contract described there — not this file.

| File | Read when touching |
|---|---|
| `app-shell.md` | `base.html.twig`, left navigation, account drop-up, mobile drawer toggle, project modules (`project.modules`, "Objevte další moduly", module guard) |
| `templates-data-model.md` | `template_variant` columns, canvas JSONB, inputId binding, canvas vs LAYER background mode, `BackgroundLayer`, gallery-picked backgrounds, group background propagation |
| `templates-editor-ui.md` | admin editor Stimulus controllers (`canvas_*.js`), backdrop targeting, layers panel, vector shapes (+ MCP DSL shapes), floating toolbar/popover, editor shell/zoom, overlay chrome toggles |
| `templates-containers.md` | containers / smart text areas, `container_layout.js`, nesting, gap, collision-push, `spaceAfter`, overflow 400 contract |
| `templates-text-inputs.md` | font choice / allowlists, colour allowlist, manual-driven font defaults, font usage scan + rename, rich text runs, lists, checkbox lists, checklist component, sample text (`sampleValue`) |
| `templates-fill.md` | fill pages (single + group), `AbstractVariantFiller`, shared fill partials, click-into-preview overlay, client-side text echo, `render:finished` settle |
| `gallery-and-uploads.md` | `Project:ImageGallery`, folders, Koš (trash) + purge guard, tile metadata, 10 MB decimal upload limit and its mirrors, `NormalizeImageFormat` (HEIC) |
| `templates-render.md` | `TemplateVariantImageRenderer`, Gotenberg, render template, WebP vs PNG, slice cache, `TemplateRenderUnavailable`, print PDF export (`Services/Print/`, bleed + crop marks, CMYK / FOGRA39, PDF/X-1a) |
| `templates-export-versioning.md` | `TemplateExportVersion`, dedup hash, seeder, pins/names, history page, Turbo Stream curation, form anchors (no nested forms!) |
| `templates-module-and-groups.md` | the 2026-08 module merge, `TemplateDimension` / presets, publish gating, template groups, group editor propagation, structural reliability, network-flake hardening, missing-image stand-ins, routes, unmoved namespaces |
| `templates-image-placeholders.md` | image placeholders: `allowedDirectoryIds`, upload target, `ResolveImageOverrides`, `ImagePlacement`, hybrid backdrop + overlay slices |
| `api-templates.md` | `/api/template-variants/{id}/export|preview`, structured 400s, group render API, export-history API, input `frame` |
| `manuals.md` | mockup page grid + attachments, logo own-page variants, colours (RAL), logo width cascade, page texts |
| `storage-inventory.md` | `storage_object` scan, `BuildStorageReferenceIndex` (add every new path-storing column there!), project deletion storage cleanup |

Other docs: `docs/api/authentication.md` (OAuth2), `docs/api/consumer-prompt.md`
(public API contract for consumers — update on API changes), `docs/mcp/` (MCP
design DSL), `docs/plans/` (historical plans, not current truth).

### Cross-cutting rules (apply everywhere)

- **CSS ordering gotcha**: `importmap('app')` injects `assets/styles/app.css`
  **before** the theme's `app-saas.min.css`, so an equal-specificity rule of ours
  LOSES to the theme (and to Bootstrap). Rules that compete with theme styles
  must be scoped one level deeper (`.leftside-menu .leftbar-account`,
  `.wrapper .leftside-menu`, `.canvas-stage`, `.dropdown-menu.editor-display-menu`).
- **Upload limit = 10 MB DECIMAL** (`maxSize: '10m'` = 10 000 000 bytes); every
  JS/PHP mirror must use the same number — see `gallery-and-uploads.md`.
- **Never return a flushing `StreamedResponse`** for generated binaries — the
  resident FrankenPHP runtime corrupts the next request; return buffered Responses.
- **Classic scripts in `assets/editor/*.js` run on THREE surfaces** (editor page,
  fill page, headless render template) — keep them dependency-free classic
  scripts; any change is a three-way contract.
- **`<script>` inserted by a Live Component morph never executes** — window
  globals the fill component needs load from the page template.
- **Never nest `<form>` tags** (browsers drop the inner start tag and its
  `</form>` closes the outer form) — use `form="…"` attributes + anchors.
- **Twig empty hash encodes as `[]`**, which Stimulus `Object` values throw on —
  prefer scalar or Array values when the empty case comes from Twig.
- **Canvas object/property changes usually need an MCP DSL mirror**
  (`src/Mcp/Design/`, `DesignCompiler`, `src/Value/CanvasShape*`), and
  `DesignCompilerTest` parses the JS custom-property list — run the PHP suite
  even for JS-only editor changes.
- **API-shape changes** are documented in `docs/api/consumer-prompt.md` and
  ported to the mfkfm consumer (`~/www/mfkfm/backoffice`) in the same session.
- The shared fill engine lives under `Services/SocialNetwork/*` and storage
  keys keep their `social-networks/…` prefix **on purpose** — do not rename.

### Core Domain Entities

- **Manual**: Brand manuals with colors, fonts, logos, and mockup pages
- **Project**: Container for brand manuals with sharing capabilities
- **Template**: Fabric-canvas templates (the unified module — social preset
  and free-form print dimensions alike) with variants, categories and
  synchronization groups (`TemplateVariant`, `TemplateCategory`,
  `TemplateGroup`)
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

### Template endpoints

The template export/preview endpoints, group render API, export-history API
and image-placeholder API are documented in `docs/architecture/api-templates.md`
and `docs/architecture/templates-image-placeholders.md`.
