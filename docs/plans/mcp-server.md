# Implementation Plan — wboost MCP Server (per-user authenticated agent gateway)

> **Audience:** the implementing Claude Code agent (orchestrator + subagents). Self-contained hand-off.
> **Repo:** `/Users/janmikes/www/brand-manuals` — Symfony 8, FrankenPHP, PostgreSQL 16, namespace `WBoost\Web`.
> **UI language:** Czech (user-facing strings). Code, docs and MCP tool descriptions: **English**.
> **Quality gates:** `docker compose exec web composer phpstan` (level max) + `docker compose exec web vendor/bin/phpunit` must stay green after **every** task.
> **Status:** approved. Scope = "spine + create from scratch", auth = "PAT now, OAuth-shaped".
> **This file is the single source of truth for progress.** See §2 for the protocol.

---

## 0. Locked product decisions — do NOT relitigate

1. **Goal.** A remote MCP server at `/_mcp` that lets a user drive wboost from Claude Code / claude.ai / ChatGPT: discover projects and templates, render and export filled variants, and **author new template variants from a described design**.
2. **The reference image never reaches the server.** The user drops it into their chat; the host model sees it natively. The server supplies *vocabulary* (fonts, brand colours, dimensions, DSL grammar), not vision.
3. **The public interface is a semantic DSL, never raw Fabric JSON.** Agents author a compact document; the server compiles it to a Fabric canvas and owns every invariant (§4). Rationale: ~50 keys/object of Fabric boilerplate, plus invariants no JSON schema can express.
4. **Full-document semantics.** `set_design` always replaces the whole design. No patch operations — patch sequences accumulate drift and are not reproducible. Element identity is carried by agent-chosen **slug ids** mapped to server-owned UUIDs.
5. **Accuracy > everything.** Every design call runs deterministic lint + server-side text measurement *before* spending a render. The Gotenberg render remains the arbiter.
6. **Drafts, never destruction.** v1 exposes **no** delete tool. Writes create or replace drafts. Every write returns a rendered preview **and** an `editorUrl` — never a bare "OK".
7. **Synchronous by default.** Single render ≈ 1.0–1.5 s, group of 3 ≈ 5 s — all well inside a chat client's ~25 s tool ceiling. Only user-scaled work (bulk export) gets a job handle. **Stage 7, optional.**
8. **Buffered responses only. No SSE.** Under FrankenPHP a flushing `StreamedResponse` corrupts the *next* request — already the reason `TemplateVariantImageRenderer::render()` buffers. See §6 R1.
9. **Auth: personal access tokens now, shaped so OAuth 2.1 drops in later.** Scopes, audience semantics, `WWW-Authenticate` challenges and `/.well-known/oauth-protected-resource` exist from day one. Full OAuth is Stage 8 (backlog).
10. **Authorisation reuses the existing voters.** The authenticator resolves a real `User`; `isGranted()` then behaves exactly as on the web. **No parallel permission model.** Scopes are a *second, narrowing* axis: effective permission = role ∩ scope.
11. **Tool budget: ≤ 15 tools.** Tool-count bloat degrades agent selection accuracy. Adding a 16th requires removing one.

---

## 1. House style (verified — match exactly)

The general conventions are documented in `docs/plans/admin-user-management.md` §1 and still hold. MCP-relevant subset + deltas:

- **Config files are PHP**, not YAML: `config/packages/<name>.php` returning `App::config([...])` (see `security.php`, `messenger.php`, `league_oauth2_server.php`). Route files: `config/routes/<name>.php`.
- **Commands** (`src/Message/<Domain>/`): `readonly final class`, constructor-promoted **public** props, no attributes. Nullable = `null|string`, never `?string`.
- **Handlers** (`src/MessageHandler/<Domain>/`): `#[AsMessageHandler] readonly final class`, single `__invoke(): void`. Create-handlers persist via repo `add()`; edit-handlers just mutate (the `command_bus` `doctrine_transaction` middleware flushes — **never call `flush()`**).
- **Read models:** `src/Query/Get<X>.php`, `readonly final`, injected directly (not via bus).
- **Identity/time:** `WBoost\Web\Services\ProvideIdentity::next()` (UUID v7), `Psr\Clock\ClockInterface::now()`.
- **Voters** (`src/Services/Security/`): `final class XVoter extends Voter`, inject `Security`, ADMIN god-mode short-circuit first.
- **DTOs are not services.** Only `*Provider.php` / `*Processor.php` are autowired under `src/Api/`. For `src/Mcp/` the autowire rule is set in Stage 0 — **tool classes are services, DTOs are not**.
- **Doctrine entities are NEVER the transport shape.** Same rule as `src/Api/` — MCP tools return dedicated response DTOs.
- **PHPStan level max:** narrow every `mixed`. `json_decode(...)` results need `assert(is_array(...))` + shape docblocks.
- **Tests:** `tests/Mcp/` mirroring `tests/Api/`. Reuse `tests/DataFixtures/TestDataFixture.php` and the `Fakes/FakeTemplateVariantImageRenderer` (it emits **format-matching** bytes — a PNG magic-byte assertion cannot pass when WebP was requested).

---

## 2. Idempotency protocol — READ THIS FIRST

Any agent, at any time, in a fresh session:

1. **Read this file top to bottom.** §0 and §4 are non-negotiable context.
2. **Determine actual state from the repo, not from the checkboxes.** Each task has a **`Done when`** line containing a command or a file assertion. Run it. The checkbox is a hint; the repo is the truth. If `Done when` passes but the box is unchecked, check it and move on.
3. **Pick the lowest-numbered task whose `Depends` are all satisfied and whose `Done when` fails.**
4. **Implement exactly that task.** Do not bundle tasks. Do not start the next one.
5. **Run both quality gates.** `docker compose exec web composer phpstan` and `docker compose exec web vendor/bin/phpunit`. Both green, no exceptions.
6. **Update this file**: tick the box, append one line to §9 Progress log (`YYYY-MM-DD — S<n>-T<n> — <one line what landed>`).
7. **Commit and push to `main`** (repo convention: commit + push per delivered task). Message: `feat(mcp): <task title>` / `test(mcp): …` / `chore(mcp): …`.
8. If a task turns out to be wrong or blocked: **do not silently redesign.** Append a note to §7 Open questions and stop, or pick the next independent task.

**Never mark a task done that you did not verify.** A false checkbox costs the next agent more than an unfinished task.

---

## 3. Architecture

### 3.1 What we reuse (do not rebuild)

| Concern | Existing code |
|---|---|
| Render to image | `src/Services/Editor/TemplateVariantImageRenderer.php` — `render()` / `renderToBytes()`, `RenderImageFormat::Png|Webp`, strict container overflow, Gotenberg + inlined Fabric v7 |
| Text override resolution | `src/Services/SocialNetwork/ResolveTextOverrides.php` (+ `ResolveRichTextOptions`, `RichText`) |
| Image override resolution | `src/Services/SocialNetwork/ResolveImageOverrides.php`, `ImagePlacement` |
| Text ↔ input binding | `src/Services/SocialNetwork/TextInputObjectBinder.php` (**positional contract**, §4.1) |
| Placeholder geometry | `src/Services/SocialNetwork/CanvasPlaceholderGeometry.php` |
| Server-side canvas construction (precedent) | `src/Services/Editor/BackgroundLayer.php`, `src/Services/TemplateGroup/CanvasDesignProjector.php` |
| Canvas persistence | `EditTemplateVariantCanvasEditor` → `EditTemplateVariantCanvasHandler` |
| Template/variant/group creation | `src/Message/Template/*`, `src/Message/TemplateGroup/*` |
| Fonts | `src/Query/GetFonts.php`, `Font::$faces`, `FontLib\Font` (already used in `AddFontHandler`) |
| Brand colours | `GetManuals::allForProject` via `ResolveRichTextOptions` |
| Gallery | `src/Entity/FileUpload.php`, `FileDirectory`, `PlaceholderAllowedDirectories`, `PlaceholderImageUploader` |
| Authorisation | `ProjectVoter`, `TemplateVoter`, `TemplateVariantVoter`, `TemplateGroupVoter`, `TemplateCategoryVoter` |
| Usage tracking | `src/Services/Usage/RecordExportUsage.php`, `ExportChannel` |
| Async infra | `config/packages/messenger.php` — `async` doctrine transport + prod `messenger-consumer` |
| PSR-7 | `nyholm/psr7` already installed (`config/packages/nyholm_psr7.yaml`) — required by the MCP HTTP transport |

### 3.2 What we build

```
src/Mcp/
  Tool/                       one invokable class per tool, #[McpTool]
  Response/                   response DTOs (readonly final, scalars/VOs only)
  Design/
    Dsl/DesignDocument.php    parsed DSL value object (+ Element VOs)
    DesignCompiler.php        DSL → Fabric canvas JSON + EditorTextInput[] + EditorImageInput[]
    DesignDecompiler.php      Fabric canvas JSON → DSL (round-trip)
    Geometry/GridResolver.php semantic anchors/grid → canvas px
    Lint/DesignLinter.php     deterministic warnings
    Measure/TextMeasurer.php  php-font-lib advance-width wrap estimate
    Archetype/                layout skeletons (JSON + loader)
  Security/
    McpTokenAuthenticator.php
    McpScope.php              enum
    McpScopeChecker.php       tool gating + tools/list filtering
  Exception/
src/Entity/McpAccessToken.php
src/Message/Mcp/ + src/MessageHandler/Mcp/
config/packages/mcp.php
config/routes/mcp.php
docs/mcp/                     connect guide + recommended prompts (public docs)
skills/wboost-design/         the Skill shipped alongside the connector
tests/Mcp/
```

### 3.3 The tool surface (the public interface — ≤ 15)

| # | Tool | Scope | Voter gate | Returns |
|---|---|---|---|---|
| 1 | `get_context` | `templates:read` | — (user's own) | user, role, projects[{id,name,fonts[],colors[],dimensions[],counts}] |
| 2 | `find_templates` | `templates:read` | `ProjectVoter::VIEW` | templates + variant summaries |
| 3 | `describe_variant` | `templates:read` | `TemplateVariantVoter::VIEW` | dimension, inputs[], imageInputs[], containers[], design (DSL) |
| 4 | `list_gallery` | `templates:read` | `ProjectVoter::VIEW` | directories + images |
| 5 | `render_variant` | `templates:read` | `TemplateVariantVoter::VIEW` | **image** (WebP, downscaled) |
| 6 | `export_variant` | `templates:export` | `TemplateVariantVoter::VIEW` | **image** (PNG, full size) |
| 7 | `list_archetypes` | `templates:design` | — | archetype catalogue |
| 8 | `preview_design` | `templates:design` | `TemplateVariantVoter::EDIT` | **image** + warnings[] — **does not persist** |
| 9 | `set_design` | `templates:design` | `TemplateVariantVoter::EDIT` | image + editorUrl + warnings[] |
| 10 | `get_design` | `templates:design` | `TemplateVariantVoter::EDIT` | DSL |
| 11 | `create_template` | `templates:design` | `TemplateVoter::ADD` + `ROLE_DESIGNER` | templateId + editorUrl |
| 12 | `add_variant` | `templates:design` | `TemplateVariantVoter::ADD` | variantId + editorUrl |
| 13 | `create_group` | `templates:design` | `ROLE_DESIGNER` + `ProjectVoter::EDIT` | groupId + variantIds |
| 14 | `upload_image` | `gallery:write` | `ProjectVoter::VIEW` | imageId + url |
| 15 | *(reserved)* | | | Stage 7 `bulk_export` / `get_job` replace this slot |

`tools/list` MUST be filtered by the token's scopes ∩ the user's roles — a read-only token must not learn the design tools exist.

### 3.4 The DSL (v1 grammar)

```jsonc
{
  "canvas": { "width": 1080, "height": 1080, "background": { "image": "<assetId>", "fill": "#111111" } },
  "elements": [
    { "kind": "text", "id": "headline",           // slug, stable across set_design calls
      "text": "SLEVA 50 %",                       // designed stand-in / sample
      "at": { "area": "top", "col": [1, 12], "marginX": 80, "offsetY": 40 },  // semantic (preferred)
      "x": 80, "y": 120, "width": 920,            // absolute px (escape hatch; wins if present)
      "font": "Hero New (Hero New ExtraBold)",    // MUST be a face from get_context
      "size": 96, "color": "#ffffff", "align": "left", "lineHeight": 1.16,
      "input": { "name": "Nadpis", "maxLength": 24, "uppercase": true,
                 "hidable": false, "locked": false, "richText": false,
                 "sampleValue": "SLEVA 50 %" } },

    { "kind": "image", "id": "photo",
      "at": { "area": "bottom", "col": [1, 12] }, "height": 480,
      "asset": "<assetId>",                       // stand-in picture (optional)
      "input": { "name": "Foto", "placeholder": true, "allowMove": true,
                 "allowResize": true, "allowRotate": false, "hidable": true,
                 "allowedDirectories": [] } },

    { "kind": "background", "id": "bg", "asset": "<assetId>", "fillable": false },

    { "kind": "container", "id": "body",
      "members": ["headline", "subhead"], "children": [],
      "maxHeight": 400, "gap": 24, "spaceAfter": 60 }
  ]
}
```

Rules:
- `id` is an agent-chosen slug, unique per document, stable across replacements. The compiler maps slug → the existing `inputId` UUID when the slug already exists on the variant, and mints a fresh UUID otherwise. **This is what makes editing safe.**
- Missing optional keys take documented defaults (see §4.2). The DSL is deliberately small; anything not expressible in it is a Stage-6+ extension, not an ad-hoc Fabric escape.
- `kind: "background"` is at most one per document and always compiles to stack index 0.
- Element order in `elements[]` is the **stack order** (bottom → top).

---

## 4. Compiler invariants — the correctness core

**Every one of these is a test in `tests/Mcp/Design/`.** A compiler that violates any of them produces a variant that renders wrong, exports wrong, or breaks the fill page.

### 4.1 Binding & identity

1. **Positional textbox↔input contract.** `TextInputObjectBinder` binds the *i-th VISIBLE Textbox object* to `inputs[i]`. Therefore: the emitted `inputs[]` array order MUST equal the order of visible Textbox objects in `canvas.objects[]`. Non-textbox objects never appear in `inputs[]`.
2. `inputId` (UUID v4 string) is stamped on the canvas object **and** mirrored on the `EditorTextInput` / `EditorImageInput` entry.
3. Objects with `visible: false` are excluded from `inputs[]` and `imageInputs[]` (design-hidden layers are not fillable).
4. `imageInputs[]` contains only image objects with `imagePlaceholder: true` and `visible !== false`; they bind by their own `inputId` (reliable), not positionally.

### 4.2 Fabric object shape

5. `originX: 'left'`, `originY: 'top'` on every object. Fabric v7 defaults to `center` — omitting these misplaces everything relative to legacy data and the renderer.
6. Textboxes carry `width` (the wrap width). Height is Fabric-computed; never author it.
7. Custom properties must be exactly the set in `assets/controllers/canvas_custom_properties.js` → `CANVAS_CUSTOM_PROPERTIES`. **That JS file is the source of truth**; the compiler mirrors it and a test asserts the two lists agree.
8. Editor-only interaction flags (`lockScalingX`, `hasControls`, `selectable`, `evented`, `editorLocked`) are NOT serialized by Fabric and MUST NOT be authored — the editor re-derives them on load (`applyTextboxDefaults` / `applyEditorLock`).
9. Image objects need `src` (the public URL via `UploaderHelper::getPublicPath`) **and** `assetPath` (+ `assetId` where known), or `AssetInliner` cannot inline them for headless Chromium.
10. `fontFamily` must be an exact face string from the project's fonts (e.g. `"Hero New (Hero New ExtraBold)"`). Unknown family → hard error naming the allowed list (mirror the `font_not_allowed` pattern).

### 4.3 Background

11. At most ONE object with `isBackground: true`, at stack index 0.
12. New variants are `BackgroundMode::Layer`. Build the object via `BackgroundLayer::buildObject()` — never hand-roll the cover transform (`scale = max(cw/iw, ch/ih)`, anchored top-left).
13. After save, `template_variant.background_image` is the denormalized pointer to the layer's `assetPath`. `EditTemplateVariantCanvasHandler` already syncs this — route all canvas writes through `EditTemplateVariantCanvasEditor` so it cannot drift.
14. A layer-mode variant with no background renders a transparent PNG. That is legal, not an error.

### 4.4 Containers

15. Containers live as a top-level `containers` key **inside** the canvas JSON, shape `[{id, maxHeight, memberInputIds, memberContainerIds, gap?, spaceAfter?}]`.
16. `memberInputIds` is in **flow order** = ascending designed `top`. The compiler re-derives it; it does not trust DSL order.
17. Sanitization must match `sanitizedContainers()` in `assets/controllers/canvas_payload.js` — to a fixpoint: ≥2 members counting children, no self-reference, no cycles, one parent per child, existing ids only, `gap`/`spaceAfter` finite ≥ 0 or absent. Degenerate containers are dropped, never left inert.
18. Fillable image placeholders and the background layer are **never** container members. Decorative images may be.
19. `CanvasContainer` (`src/Value/CanvasContainer.php`) parses defensively and must never throw on compiler output — a test round-trips compiler → `CanvasContainer` for every fixture.

### 4.5 Persistence

20. All canvas writes go through `EditTemplateVariantCanvasEditor`. **No direct `$variant->editCanvas()` from MCP code.**
21. `previewImageDataUri` is browser-produced and unavailable here. Pass `''` (the handler then keeps the existing thumbnail) and render + store the thumbnail server-side in the same task — see S5-T3.
22. Group-created variants (`variant->group !== null`) MUST be rejected by `set_design` with an error pointing at the group tools, mirroring `TemplateVariantEditorController`'s redirect.

---

## 5. Stages & tasks

Legend: `[ ]` todo · `[x]` done · **Done when** = the verification an agent runs to decide.

### Stage 0 — Foundations & transport

- [x] **S0-T1 — Install the MCP SDK + bundle.**
  **How:** `docker compose exec web composer require mcp/sdk symfony/mcp-bundle`. Pin exact versions in `composer.json` (the bundle is experimental — floating constraints will break a later build). Register the bundle in `config/bundles.php` if Flex does not.
  **Done when:** `docker compose exec web composer show -D | grep -E 'mcp/sdk|symfony/mcp-bundle'` lists both AND `grep -q McpBundle config/bundles.php`.
  **Depends:** —
  **Landed:** `mcp/sdk` **0.7.0** + `symfony/mcp-bundle` **0.12.0**, both pinned exact. Bundle FQCN is `Symfony\AI\McpBundle\McpBundle` (the package name says `symfony/mcp-bundle`, the namespace still says `Symfony\AI`). The transitive `php-http/discovery` recipe dropped a `config/packages/http_discovery.yaml` aliasing the six PSR-17 factory interfaces — deleted, because `config/packages/nyholm_psr7.yaml` already aliases exactly those six and two files fighting over them is a latent trap.
  **Config keys available to S0-T2:** `app`, `version`, `description`, `icons`, `website_url`, `pagination_limit`, `instructions`, `client_transports {stdio, http}` (**both default `false`** — the `/_mcp` route does not exist until one is enabled), `apps.enabled`, `http {path, allowed_hosts, session {store: file|memory|cache|framework, directory, cache_pool, prefix, ttl}}`.

- [x] **S0-T2 — Configure the server (buffered, Redis sessions, host allow-list).**
  **How:** create `config/packages/mcp.php` in the `App::config([...])` style. Path `/_mcp`. Session store = the `cache` driver over a PSR-16 wrapper of a Redis pool (**not** the default file store — blue/green deploy + worker mode). `allowed_hosts: ['wboost.cz', 'localhost']`. Add `config/routes/mcp.php`.
  Set the server `instructions` string (short: what wboost is, that the DSL is the design interface, that `get_context` comes first).
  **Done when:** `docker compose exec web bin/console debug:router | grep _mcp` shows the route AND `POST /_mcp` answers without a 404/500. ⚠️ **The original Done-when said 401 — that half moved to S1-T3**, where the firewall lands: with only `main` in play an unauthenticated POST redirects to `/login` (302), which is correct-for-now.
  **Depends:** S0-T1
  **Landed:** route `_mcp_endpoint  GET|POST|DELETE|OPTIONS  /_mcp`. Session store = new Redis pool `cache.mcp_session` (`config/packages/cache.php`, next to `cache.gotenberg_preview`) wrapped by an explicit `Psr16Cache` service `mcp.session.psr16` — the bundle's `cache` store is `Mcp\Server\Session\Psr16SessionStore`, whose first arg is a **PSR-16** `Psr\SimpleCache\CacheInterface`, not a Symfony PSR-6 pool; it only auto-wraps for its own default id `cache.mcp.sessions` over `cache.app`. Required adding `psr/simple-cache: ^3.0` to `require` — `mcp/sdk` declares it only in `require-dev`, so the interface was missing and the first POST 500'd.
  **`framework` store deliberately rejected:** it binds to `session.handler` = `PdoSessionHandler`, the Postgres row-locking handler behind the Sentry WEB-2B cascade.
  **This answers infra task I-T2: stateless is NOT available.** `McpBundle::configureSessionStore()` runs unconditionally for any enabled transport, and `StreamableHttpTransport::handleRequest()` always mints/returns an `Mcp-Session-Id`. There is no flag and no alternative transport class. A shared store is mandatory; Redis is the right one.

- [x] **S0-T3 — Force buffered responses (FrankenPHP guard).** ⚠️ See §6 R1.
  **How:** ensure the MCP controller never returns a flushing `StreamedResponse`. Prefer configuration/negotiation (do not advertise `text/event-stream`); if the bundle streams unconditionally, wrap its controller with a decorator that buffers the body into a plain `Response`.
  **Recon from S0-T2 — there is NO configuration switch, so a decorator it is:** `McpController::handle()` hard-codes `new StreamableHttpTransport(...)` and returns `$this->httpFoundationFactory->createResponse($psrResponse, $streamed)` with `$streamed = ('text/event-stream' === Content-Type)`. Only decorating `mcp.server.controller` can change that. The good news: `StreamableHttpTransport::handlePostRequest()` emits SSE **only** when `null !== $this->sessionFiber` — i.e. a handler suspended a Fiber for a server→client request (sampling / elicitation / progress). Plain tool handlers always answer `application/json`, verified live (`initialize` came back as a plain `Response`). There is also no GET SSE stream: the transport's `handleRequest()` `match` has no `GET` arm and answers 405. So the guard is narrow — buffer (or refuse) an `text/event-stream` PSR response — but it must exist, because S6-T2 wants per-dimension progress notifications, which is exactly the Fiber-suspending case.
  Also note `StreamableHttpTransport::DEFAULT_MAX_BODY_BYTES` = 4 MiB and the bundle does not expose it — relevant to `upload_image` (S5-T6).
  **Done when:** a test in `tests/Mcp/TransportTest.php` asserts the `/_mcp` response is **not** an instance of `StreamedResponse` for `initialize` and for a `tools/call`.
  **Depends:** S0-T2
  **Landed:** `src/Mcp/Transport/BufferedMcpController.php` (decorates `mcp.server.controller`, registered by hand in `config/packages/mcp.php` — `Transport/` is deliberately outside S0-T4's autowire glob) + `src/Mcp/Transport/BufferStreamedResponse.php` (the buffering, split out so it unit-tests without a container). Strategy is **buffer, not refuse**: refusing would turn S6-T2's progress-notification `create_group` into a hard error, whereas buffering keeps it working and only gives up incrementality — which §0.8 already gives up. Tests are real HTTP round-trips (`initialize` → `Mcp-Session-Id` → `notifications/initialized` → `tools/call`) against a test-env-only `#[McpTool]` probe in `tests/Mcp/Fixtures/`, logged in via `TestingLogin` because `/_mcp` still sits under `main` until S1-T3 (swap to token auth then — noted in the test docblock).
  ⚠️ **THE DOUBLE OUTPUT BUFFER IS LOAD-BEARING — do not "simplify" it to one `ob_start()`.** `StreamableHttpTransport` writes SSE as `echo` + `@ob_flush(); flush();`. Under a single buffer the `ob_flush()` pushes bytes **past** the guard to the SAPI — committing output early, i.e. the exact corruption being guarded against. Proven in-container: with one buffer, `echo "A"; ob_flush(); flush(); echo "B"` captures only `"B"` and `"A"` reaches the wire. Two nested buffers contain it (the inner `ob_flush()` lands in the outer level, `flush()` no-ops), and captures concatenate flushed-first. A third test asserts nothing leaks and buffer levels stay balanced — without it the Done-when would only be asserting a path that is trivially true today.

- [x] **S0-T4 — Service wiring for `src/Mcp/`.**
  **How:** in `config/services.php` (mirroring the `src/Api/` convention) autowire `src/Mcp/Tool/`, `src/Mcp/Design/`, `src/Mcp/Security/`; **exclude** `src/Mcp/Response/`, `src/Mcp/Design/Dsl/`, `src/Mcp/Exception/`.
  **Done when:** `docker compose exec web bin/console lint:container` passes AND `debug:container --tag=mcp.tool` (or the bundle's equivalent) lists nothing yet without erroring.
  **Depends:** S0-T1
  **Landed:** one `load('WBoost\Web\Mcp\', 'src/Mcp/{Tool,Design,Security}/**/*.php')` + `exclude([Response, Design/Dsl, Exception])` block in `config/services.php`, right after the `src/Api/` one. The six directories exist with tracked `.gitkeep` files — `src/Mcp/` itself **must** exist (`FileLoader::findClasses()` globs the resource pattern with `$ignoreErrors: false`, so a missing prefix throws; sub-dirs and excludes are tolerated), and git does not track empty dirs, so a bare `mkdir` would not survive a clone. `Transport/` is intentionally NOT in the glob — S0-T3 registers its decorator by hand.
  **Exclusion verified empirically, not assumed** (throwaway probes, then deleted): classes in `Tool/` and `Design/` (incl. depth-1 `Design/Geometry/`) register; `Response/`, `Design/Dsl/` and `Exception/` come back `container.excluded (source: in "config/services.php")` and are removed at compile. Symfony's glob matches **zero** directories for `**/`, so files sitting directly in `Tool/` are picked up.
  **Stage 1 note:** a backed enum inside a loaded dir is auto-excluded by Symfony itself (`container.excluded … because it's an enum`) — `src/Mcp/Security/McpScope.php` needs no special-casing.
  **Stage 2 inputs — the real attribute and tag:** attribute `Mcp\Capability\Attribute\McpTool` (from `mcp/sdk`); tag `mcp.tool` with a `method` attribute. `McpBundle::registerMcpAttributes()` also maps `McpPrompt`→`mcp.prompt`, `McpResource`→`mcp.resource`, `McpResourceTemplate`→`mcp.resource_template`. Two gotchas: a **class-level** `#[McpTool]` requires an `__invoke()` or the bundle throws `LogicException` at compile time; and the tool's input schema is generated at **compile time** from reflection + docblock, so a schema failure surfaces as a container build error, not a runtime one.

### Stage 1 — Auth & scope (PAT, OAuth-shaped)

- [ ] **S1-T1 — `McpAccessToken` entity + migration.**
  **How:** `src/Entity/McpAccessToken.php` — `id` (UUID v7), `user` (ManyToOne, `ON DELETE CASCADE`), `name`, `scopes` (JSON `list<string>`), `tokenHash` (sha256 of the secret; **never store the secret**), `createdAt`, `lastUsedAt` (nullable), `expiresAt` (nullable), `revokedAt` (nullable). Repository with `findActiveByHash()`.
  Token wire format: `wb_mcp_<32 bytes base64url>`; the lookup is by hash of the whole string.
  **Done when:** `docker compose exec web bin/console doctrine:migrations:migrate -n` applies AND `bin/console doctrine:schema:validate` reports the mapping in sync.
  **Depends:** S0-T4

- [ ] **S1-T2 — `McpScope` enum + scope checker.**
  **How:** `src/Mcp/Security/McpScope.php` — cases `TemplatesRead = 'templates:read'`, `TemplatesExport = 'templates:export'`, `TemplatesDesign = 'templates:design'`, `GalleryWrite = 'gallery:write'`. `templates:design` implies `templates:read`; `templates:export` implies `templates:read` (encode the implication in the enum, one method).
  `McpScopeChecker` answers `granted(McpScope): bool` from the current token.
  **Done when:** `tests/Mcp/Security/McpScopeTest.php` covers the implication matrix and passes.
  **Depends:** S1-T1

- [ ] **S1-T3 — Authenticator + firewall.**
  **How:** `src/Mcp/Security/McpTokenAuthenticator.php` (custom authenticator): read `Authorization: Bearer`, hash, look up an active token, resolve its `User`, stash the granted scopes in the token attributes, touch `lastUsedAt` (throttled — at most once per minute per token, to avoid a write per tool call).
  In `config/packages/security.php` add a firewall **above** `main`:
  ```
  'mcp' => ['pattern' => '^/_mcp', 'stateless' => true, 'provider' => 'api_user_provider',
            'custom_authenticators' => [McpTokenAuthenticator::class]],
  ```
  plus an `access_control` entry for `^/_mcp` (IS_AUTHENTICATED_FULLY) and a PUBLIC one for `^/\.well-known/oauth-protected-resource`.
  **On failure return 401 with** `WWW-Authenticate: Bearer resource_metadata="<abs url>", scope="templates:read"` — this is the OAuth-shaped part and costs nothing now.
  **Done when:** `tests/Mcp/AuthTest.php` proves: no header → 401 with the `resource_metadata` challenge; bad token → 401; revoked/expired → 401; valid → 200 and the resolved user is correct.
  **Depends:** S1-T2

- [ ] **S1-T4 — `/.well-known/oauth-protected-resource`.**
  **How:** a tiny invokable controller returning RFC 9728 JSON: `resource` (canonical `https://wboost.cz/_mcp`), `authorization_servers` (`["https://wboost.cz"]` — points at ourselves, correct once Stage 8 lands), `scopes_supported`, `bearer_methods_supported: ["header"]`. Public route.
  **Done when:** `curl -s localhost:8080/.well-known/oauth-protected-resource | jq -e '.resource and .scopes_supported'` succeeds.
  **Depends:** S1-T3

- [ ] **S1-T5 — CLI token management.**
  **How:** `app:mcp:token:create <email> --name= --scopes=` (prints the secret **once**), `app:mcp:token:list`, `app:mcp:token:revoke <id>`. Mirror `app:oauth-client:create`'s ergonomics.
  **Done when:** create → list shows it → revoke → a request with that token 401s (asserted in `tests/Mcp/AuthTest.php` or a command test).
  **Depends:** S1-T3

- [ ] **S1-T6 — Tool gating + `tools/list` filtering.**
  **How:** a `#[McpToolScope(McpScope::…)]` attribute (or a small registry) consumed by the bundle's tool collection so (a) a call without the scope returns **403** with `WWW-Authenticate: Bearer error="insufficient_scope", scope="…"`, and (b) `tools/list` omits tools the token cannot call.
  **Done when:** `tests/Mcp/ScopeFilteringTest.php` proves a `templates:read`-only token sees exactly the read tools in `tools/list` and gets 403 (with the header) on a design tool.
  **Depends:** S1-T2, S1-T3

### Stage 2 — Read tools

> Every tool: one invokable class in `src/Mcp/Tool/`, an English description written *for an agent* (say when to use it and what it returns), a dedicated response DTO, and a test in `tests/Mcp/Tool/`.

- [ ] **S2-T1 — `get_context`.**
  **How:** returns the authenticated user (name, email, role), granted scopes, and every project they can VIEW, each with: id, name, template count, distinct variant dimensions, **fonts** (exact face strings from `GetFonts::allForProject`) and **brand colours** (via `ResolveRichTextOptions`' manual-colour logic, extracted to a reusable query if needed). Cache per user for 60 s in `cache.app`.
  **Done when:** `tests/Mcp/Tool/GetContextTest.php` asserts a shared user sees the shared project, a foreign project is absent, and the font strings match `GetFonts`.
  **Depends:** S1-T6

- [ ] **S2-T2 — `find_templates(projectId, query?)`.**
  **How:** templates in the project with categories, group membership, and per-variant `{id, dimension label, preset, width, height, thumbnailUrl, inputCount}`. `ProjectVoter::VIEW`, 404 (not 403) for foreign projects — mirror `TemplatesProvider`.
  **Done when:** test covers visible/shared/foreign and that grouped templates are marked.
  **Depends:** S2-T1

- [ ] **S2-T3 — `describe_variant(variantId)`.**
  **How:** the fat orientation call. Dimension (+px), inputs[] (`id, name, description, maxLength, uppercase, locked, hidable, richText, lists, checklist, sampleValue, frame`), imageInputs[] (+ `frame`, `isBackground`, allowed directories, `includesRoot`), containers[], richTextOptions when relevant. Reuse `TextInputObjectBinder` + `CanvasPlaceholderGeometry` — **do not** reimplement geometry.
  **Done when:** test asserts input ids and frames equal what `GET /api/projects/{id}/templates` reports for the same variant (the two must never disagree).
  **Depends:** S2-T2

- [ ] **S2-T4 — `list_gallery(projectId, directoryId?)`.**
  **How:** directories (tree level) + images `{id, name, url, width, height}`. Trash is excluded (`deletedAt IS NULL` is already enforced in `FileUploadRepository`). No delete/move from MCP.
  **Done when:** test asserts trashed files are absent and a foreign project 404s.
  **Depends:** S2-T1

### Stage 3 — Render & export

- [ ] **S3-T1 — `ExportChannel::Mcp`.**
  **How:** add the case + label. Check `/admin/usage` renders the new column (the report derives columns from data, so this should be free — verify).
  **Done when:** `grep -q "Mcp" src/Value/ExportChannel.php` and the usage page still renders in a controller test.
  **Depends:** —

- [ ] **S3-T2 — `render_variant(variantId, inputs?, images?)` → image.**
  **How:** resolve overrides with `ResolveTextOverrides` / `ResolveImageOverrides`, render **WebP** at a downscaled size (target ≤ 1200 px on the long edge — pass through the existing render path; downscale with Imagick after render if the renderer cannot target a size). Return MCP image content (base64 + mime). **Lenient** container overflow (report it as a warning, don't fail).
  **Done when:** `tests/Mcp/Tool/RenderVariantTest.php` (with `FakeTemplateVariantImageRenderer`) asserts WebP was requested and the payload is image content, plus one real-render smoke test marked `@group gotenberg`.
  **Depends:** S2-T3

- [ ] **S3-T3 — `export_variant(variantId, inputs?, images?)` → PNG.**
  **How:** full-size **PNG**, `strictContainerOverflow: true`. Translate `ContainerOverflow` and `InvalidRichTextValue` into **actionable tool errors** (`"'Popis' overflows its container by 12 px — shorten it to ~90 characters or raise maxHeight"`), not raw 400 bodies. Record usage with `ExportChannel::Mcp`.
  **Done when:** test asserts PNG format, that an overflowing fill produces the actionable message containing the input name and the px, and that an `ExportEvent` row is written.
  **Depends:** S3-T1, S3-T2

> **Milestone A (the spine).** After S3-T3: `claude mcp add --transport http wboost http://localhost:8080/_mcp --header "Authorization: Bearer wb_mcp_…"`, then *"what projects do I have?"* and *"export the … post with headline X"* both work. **Demo this before starting Stage 4.**

### Stage 4 — Design DSL core (pure PHP, no agent in the loop)

> This is where accuracy is won. Everything here is unit-testable without Gotenberg and without an LLM. Over-invest.

- [ ] **S4-T1 — DSL value objects + strict parser.**
  **How:** `src/Mcp/Design/Dsl/` — `DesignDocument`, `CanvasSpec`, `TextElement`, `ImageElement`, `BackgroundElement`, `ContainerElement`, `Placement` (semantic `at` + absolute). Strict parse with precise errors (`"elements[2].font is required"`). Slug uniqueness enforced. Unknown keys rejected (agents hallucinate keys — silent acceptance produces silently wrong designs).
  **Done when:** `tests/Mcp/Design/DslParserTest.php` covers every element kind, every required-field error, unknown-key rejection, duplicate slugs.
  **Depends:** S0-T4

- [ ] **S4-T2 — `GridResolver`.**
  **How:** resolve `at: {area, col, row, marginX, marginY, offsetX, offsetY}` to px on a 12-column grid over the canvas. Areas: `top | upper | middle | lower | bottom | full` (thirds/halves — document the exact math in the class docblock, it is a public contract). Absolute `x/y/width` always wins when present.
  **Done when:** `tests/Mcp/Design/GridResolverTest.php` pins the px output for a 1080×1080 and a 2480×3508 (A4@300dpi) canvas.
  **Depends:** S4-T1

- [ ] **S4-T3 — `TextMeasurer` (php-font-lib).**
  **How:** load the project's face file from Minio (memoize per path, like `TemplateVariantImageRenderer::$inlinedFonts`), read `hmtx` advance widths + `head.unitsPerEm` via `FontLib\Font`, and estimate wrapped line count for a given text/width/fontSize. Apply a per-face calibration factor (default 1.0) stored in a small config map.
  Expose `estimateLines()` and `estimateHeight()` — clearly documented as **approximate**; Chromium remains the arbiter.
  **Done when:** `tests/Mcp/Design/TextMeasurerTest.php` asserts, for at least 3 fixture strings against a real fixture font, that the estimate is within ±1 line of a recorded Gotenberg render.
  **Depends:** S4-T1

- [ ] **S4-T4 — `DesignCompiler` (DSL → canvas + inputs).**
  **How:** the heart. Emit Fabric objects in stack order, enforcing **every invariant in §4**. Slug→UUID mapping takes the existing variant's inputs as input so ids are preserved across `set_design`. Reuse `BackgroundLayer::buildObject()` for backgrounds. Emit `containers` with flow-ordered members, sanitized to the same fixpoint as `sanitizedContainers()`.
  **Done when:** `tests/Mcp/Design/DesignCompilerTest.php` has one explicit test per numbered invariant in §4 (20+ tests), all passing.
  **Depends:** S4-T2

- [ ] **S4-T5 — `DesignDecompiler` (canvas → DSL) + round-trip.**
  **How:** the inverse, so `get_design` / `describe_variant` can show an existing design and the agent can edit it. Names slugs from input names (slugified, deduped) and remembers the mapping via `inputId`.
  **Done when:** a round-trip test over **every canvas in `tests/DataFixtures`** plus ≥3 real exported canvases: `decompile → compile` produces a canvas that renders identically (compare normalized JSON ignoring key order and float noise ≤ 0.01).
  **Depends:** S4-T4

- [ ] **S4-T6 — `DesignLinter`.**
  **How:** deterministic warnings, each with `code`, human message, and the offending slug: out of canvas bounds; unintended overlap of two text elements not in the same container; font not in project (**error**, not warning); colour not in the brand palette (warning); font size below a legibility floor (relative to canvas height); predicted container overflow (from `TextMeasurer`); container with <2 members; image element with no asset and no placeholder flag; text element with `maxLength` shorter than its own stand-in text.
  **Done when:** `tests/Mcp/Design/DesignLinterTest.php` has a fixture triggering each code exactly once.
  **Depends:** S4-T3, S4-T4

### Stage 5 — Design tools

- [ ] **S5-T1 — Renderer seam: render a candidate canvas without persisting.**
  **How:** add a narrowly-scoped capability to `TemplateVariantImageRenderer` (or a thin `CandidateRenderer` in `src/Mcp/`) that renders a supplied canvas JSON + inputs against a variant's dimension/project **without touching the DB, the thumbnail or the cache**. Do not disturb the existing signatures or the `RenderImageFormat::Png` default.
  **Done when:** a test renders a candidate canvas and asserts the variant row's `canvas`, `preview_image_path` and `updated` state are unchanged, and no `cache.gotenberg_preview` entry was written.
  **Depends:** S4-T4

- [ ] **S5-T2 — `preview_design(variantId, design)` → image + warnings.**
  **How:** parse → lint → compile → candidate-render (WebP, downscaled). Returns lint warnings **and** the picture. Never persists. This is the loop the agent iterates on; make its errors instructive.
  **Done when:** test asserts nothing persisted, warnings surface, and a font error blocks before any render is attempted (assert the renderer was never called).
  **Depends:** S5-T1, S4-T6

- [ ] **S5-T3 — `set_design(variantId, design)` → commit.**
  **How:** parse → lint → compile → dispatch `EditTemplateVariantCanvasEditor` (with `previewImageDataUri: ''`) → render the thumbnail server-side (PNG) and store it at `custom-templates/preview/<variantId>.png`, then persist the path. Reject `variant->group !== null` (§4.22). Return image + `editorUrl` + warnings.
  Implement thumbnail persistence as its own message/handler so it is reusable and testable.
  **Done when:** test asserts the canvas round-trips through `describe_variant`, the thumbnail path is set, a grouped variant is refused with the group-tool message, and inputs keep their UUIDs across two `set_design` calls with the same slugs.
  **Depends:** S5-T2

- [ ] **S5-T4 — `get_design(variantId)`.**
  **How:** decompiler output + the canvas dimension + the project's fonts/colours inline (so the agent can edit without a second call).
  **Done when:** test asserts `get_design` → `set_design` with no modification is a no-op on the stored canvas (normalized compare).
  **Depends:** S4-T5, S5-T3

- [ ] **S5-T5 — `create_template` + `add_variant`.**
  **How:** dispatch `AddTemplate` / `AddTemplateVariant`. `create_template(projectId, name, categoryId?)`; `add_variant(templateId, dimension)` where dimension is `{preset}` **or** `{unit, width, height}` → build `TemplateDimension` (presets via `TemplateDimension::fromPreset()`). No background upload in v1 (layer mode allows none). Both return ids + `editorUrl`.
  **Done when:** test creates a template + a 1:1 variant + an A4 variant and asserts the persisted `TemplateDimension` px values.
  **Depends:** S5-T3

- [ ] **S5-T6 — `upload_image(projectId, base64|url, filename, directoryId?)`.**
  **How:** route through `UploadFileHandler` so `NormalizeImageFormat` runs (HEIC etc.). Enforce the **10 MB decimal** cap (10 000 000 bytes) — the same number as `PlaceholderImageUploader::MAX_FILE_SIZE_BYTES`. Return `{imageId, url, width, height}`.
  **Done when:** test asserts an oversized payload is refused with the byte cap in the message and a normal upload is retrievable via `list_gallery`.
  **Depends:** S2-T4

> **Milestone B (create from scratch).** After S5-T6: *"here's a reference image — build an ACME 1:1 promo template"* produces a reviewable draft. **Demo before Stage 6.**

### Stage 6 — Accuracy scaffolding & packaging

- [ ] **S6-T1 — Archetypes + `list_archetypes`.**
  **How:** `src/Mcp/Design/Archetype/*.json` — start with 5: `hero-top`, `split-horizontal`, `centered-badge`, `lower-third`, `quote-card`. Each is a parameterized DSL skeleton (slots + semantic placement, no hard px). `list_archetypes` returns id, description, slot list, and a preview thumbnail if one is committed.
  **Done when:** every archetype compiles + renders for 1:1, 4:5 and 9:16 in a test; `list_archetypes` returns them all.
  **Depends:** S5-T5

- [ ] **S6-T2 — `create_group(projectId, name, dimensions[], fromVariantId?)`.**
  **How:** dispatch `CreateTemplateGroup` with `GroupVariantSelection[]`; when `fromVariantId` is set the existing projection seeds every dimension (`CanvasDesignProjector` already does the hard part). Emit progress notifications per dimension. Return groupId + variantIds + one preview per dimension.
  **Done when:** test creates a 3-dimension group from an existing variant and asserts every variant carries the source's `inputId`s (the group join key) and its own cover-fitted background.
  **Depends:** S6-T1

- [ ] **S6-T3 — The Skill.**
  **How:** `skills/wboost-design/SKILL.md` + `references/` — the design judgment: the DSL grammar, the archetype catalogue, the font/colour discipline, the "always `preview_design` before `set_design`" loop, three worked examples (brief → DSL → render), and the ten mistakes to avoid. Generate the DSL reference **from the parser** where possible so it cannot drift.
  **Done when:** the file exists, and a test asserts the DSL grammar section lists exactly the element kinds and required fields the parser accepts (drift guard).
  **Depends:** S6-T1

- [ ] **S6-T4 — Public docs: connect guide + recommended prompts.**
  **How:** `docs/mcp/connect.md` (Claude Code one-liner, claude.ai connector, ChatGPT, token creation, scope meanings, troubleshooting) and `docs/mcp/prompts.md` (10 copy-paste prompts covering the real jobs). Write these **from the working prototype**, not from this plan.
  **Done when:** a fresh `claude mcp add` following only `docs/mcp/connect.md` succeeds against local, verified by a human.
  **Depends:** S6-T3

- [ ] **S6-T5 — Plugin packaging.**
  **How:** the manifest bundling the MCP server entry + `skills/wboost-design/` + slash commands (`/wboost:new-template`, `/wboost:export`) so install is one command. Add MCP `prompts` mirroring the slash commands for clients without skills (claude.ai, ChatGPT).
  **Done when:** installing the plugin in a clean Claude Code session exposes the commands and the tools.
  **Depends:** S6-T4

### Stage 7 — Bulk async (optional; ship after Milestone B)

- [ ] **S7-T1 — `McpJob` entity + migration** (id, user, type, status, progress, request JSON, result JSON, error, timestamps).
- [ ] **S7-T2 — `BulkExportRequested` message routed to the existing `async` transport**; handler renders N variants, writes results to Minio, updates progress. Cap N; log what was dropped.
- [ ] **S7-T3 — `bulk_export` + `get_job` tools** (replace the reserved slot #15; keep the tool budget at 15).

### Stage 8 — OAuth 2.1 (backlog; needed only for claude.ai / ChatGPT connectors)

- [ ] **S8-T1 — Enable `enable_auth_code_grant` + `require_code_challenge_for_public_clients`** in `config/packages/league_oauth2_server.php`; wire the `/api/authorize` consent screen to the `main` firewall login.
- [ ] **S8-T2 — `/.well-known/oauth-authorization-server`** (RFC 8414) including `code_challenge_methods_supported: ["S256"]` and `client_id_metadata_document_supported`.
- [ ] **S8-T3 — Resource indicators** (RFC 8707): accept `resource`, bind the `aud` claim, and validate audience in the MCP authenticator.
- [ ] **S8-T4 — Client registration**: Client ID Metadata Documents (SHOULD) and/or RFC 7591 DCR (MAY), with SSRF protections on metadata fetches.
- [ ] **S8-T5 — Consent screen** listing scopes in Czech, plus a "connected apps" management page.
- [ ] **S8-T6 — Swap the authenticator**: OAuth bearer alongside PAT. The token model, scopes and tool gating must not change.

---

## 6. Risk register — read before touching the related task

- **R1 — FrankenPHP + `StreamedResponse` (S0-T3).** ✅ *Guarded as of 2026-08-05 — see S0-T3's note on the load-bearing double output buffer. Still verify it survives on the real worker in I-T4.* A flushing streamed response under resident PHP commits output early and the *next* request dies with "headers already sent (`Response.php:393`)". This is why `TemplateVariantImageRenderer::render()` buffers deliberately. The MCP bundle's controller streams for `text/event-stream`. **Buffer unconditionally and assert it in a test.**
- **R2 — Gotenberg capacity.** Synchronous dependency, capped at `timeout: 20` / `max_duration: 25` (`config/packages/sensiolabs_gotenberg.yaml`); overload raises `TemplateRenderUnavailable` (503). A design loop adds real load on top of the fill page. Mitigations: `preview_design` renders downscaled WebP, a per-session render cap surfaced to the agent, and no thumbnail write during iteration. Watch for the 2 GiB cgroup OOM signature (Sentry WEB-2B).
- **R3 — The slice cache does not help here.** `cache.gotenberg_preview` keys on a canvas hash, which changes every iteration by construction. Do not "optimize" by loosening the key — the override-independence proof in `sliceIsOverrideIndependent()` is what makes that cache safe.
- **R4 — MCP session store.** The bundle defaults to files in `kernel.cache_dir`; blue/green deploy + FrankenPHP worker make that wrong. Redis pool (S0-T2), or stateless only.
- **R5 — DNS-rebinding `allowed_hosts` defaults to localhost only.** Forgetting it means every production request 400s.
- **R6 — Positional textbox↔input contract (§4.1).** The most dangerous invariant: violating it silently mis-binds inputs, so exports substitute the wrong text and the fill overlay draws boxes in the wrong places. Test it explicitly, not incidentally.
- **R7 — `CANVAS_CUSTOM_PROPERTIES` drift.** The authoritative list is in JS (`assets/controllers/canvas_custom_properties.js`). A compiler that emits a stale set loses designer metadata on the next editor save. S4-T4 must include the drift-guard test.
- **R8 — Tool bloat.** Every added tool degrades selection accuracy. The 15-tool budget is a hard cap, not a target.
- **R9 — `sampleValue` / `maxLength` interaction.** A stand-in longer than its own `maxLength` renders fine in the editor but 400s an API consumer who omits the input. The linter must catch it (S4-T6).
- **R10 — Group-created variants.** `set_design` on one is clobbered by the next group save. Reject explicitly (§4.22).

---

## 7. Open questions (append; do not silently decide)

- Should `render_variant` accept an explicit `width` so the agent can trade latency for detail? *(Leaning yes, capped at the variant's native size.)*
- Per-face calibration factors for `TextMeasurer` — measured once and committed, or computed lazily and cached? *(Leaning: committed constants, with a `bin/console app:mcp:calibrate-fonts` to regenerate.)*
- Do we expose `template_variant_publish` (FB/IG) via MCP? *(Deferred — outward-facing side effect, wants explicit confirmation UX.)*

---

## 8. Production / infrastructure

**Infra repo: `~/www/lily.srv`** (IaC + ops record for `lily.srv.thedevs.cz`). Read its `CLAUDE.md`
first — deploy semantics are non-obvious. Relevant facts:

- The wboost stack lives at `apps/wboost/` (`compose.yaml`, `deploy.sh`, `cron.d/wboost`), rsynced to
  `/srv/wboost/`. Services: `web` (FrankenPHP, blue/green), `messenger-consumer`, `db` (pg16),
  `redis`, `minio`, `gotenberg`, `adminer`, `traefik`.
- **A change under `apps/wboost/` is DELIVERED but NOT ACTIVATED** by the D36 pull reconciler
  (class D, `apply=app-config`): a push to the infra repo's `main` rsyncs the tree and reports
  **NEEDS-WINDOW**; it takes effect on the app's **next D23 deploy**. Plan for that lag — never
  assume a compose change is live.
  **Exception:** `apps/wboost/cron.d/wboost` is class C and applies itself.
- Secrets come from **Infisical** (`docs/secrets.md`), not from files in the repo.
- Any manual action on the box gets an entry in `~/www/lily.srv/docs/journal.md` (append-only,
  newest first).

### Infra tasks

- [ ] **I-T1 — Gotenberg headroom (do before Milestone B).**
  The design loop adds renders on top of the fill page's 2–3 per keystroke. Gotenberg already
  OOM-killed Chromium inside its 2 GiB cgroup once (Sentry WEB-2B). Before shipping design tools,
  review `apps/wboost/compose.yaml`'s `gotenberg` limits and either raise the memory ceiling or
  bound Chromium concurrency, and record the reasoning in `docs/journal.md`.
  **Done when:** the limit change is pushed to the infra repo and confirmed active after the next
  wboost deploy (`docker stats` on the box shows the new ceiling).

- [x] **I-T2 — MCP session storage decision.** *(Resolved in S0-T2; no infra change needed.)*
  If S0-T2 ends up needing a session store, point it at the existing Redis (`REDIS_CACHE_DSN`,
  `redis://redis:6379/1`) via a **distinct cache pool namespace** — do not add a second Redis.
  Note that `maxmemory` (512 MB, `allkeys-lru`) is **server-wide**, so a separate logical DB buys
  isolation of keys, not of capacity. Prefer stateless if the bundle allows it.
  **Outcome:** stateless is **not available** — `McpBundle::configureSessionStore()` runs
  unconditionally and `StreamableHttpTransport` always issues an `Mcp-Session-Id`. So: the new
  `cache.mcp_session` pool sits on the **existing** Redis (`cache.adapter.redis`, its own
  namespace, `default_lifetime` 3600 = the session TTL). No second Redis, no compose change,
  nothing to deploy. Capacity caveat stands — these keys share the server-wide 512 MB
  `allkeys-lru` budget with the preview blobs; sessions are small, but if `/_mcp` ever sees heavy
  concurrent use, revisit alongside R3.

- [ ] **I-T3 — Edge rate limiting for `/_mcp`.**
  An agent loop can hammer the endpoint. Add a Traefik rate-limit middleware scoped to the `/_mcp`
  path (a token is a real user, so limits should be generous but finite). Coordinate with the
  per-session render cap in S5-T2 so the two do not fight.
  **Done when:** the middleware is in the infra repo and a burst test gets 429s at the expected rate.

- [ ] **I-T4 — Production smoke.**
  After the first deploy carrying `/_mcp`: create a token for one real user, connect from Claude
  Code against `https://wboost.cz/_mcp`, run `get_context` + `export_variant`, and log the result in
  `docs/journal.md`. Verify the `allowed_hosts` list (R5) and that responses are buffered (R1) in
  the real FrankenPHP worker, not just locally.
  **Done when:** the journal entry exists and a subsequent unrelated request on the same worker
  succeeds (proving R1 has not regressed).

- [ ] **I-T5 (Stage 8 only) — OAuth endpoints at the edge.**
  Full OAuth adds `/api/authorize`, `/.well-known/oauth-authorization-server` and possibly a
  registration endpoint. Confirm Traefik routes them and that none are accidentally VPN-gated
  (see `docs/admin-services.md`).

---

## 9. Progress log (append one line per completed task)

<!-- YYYY-MM-DD — S<n>-T<n> / I-T<n> — what landed -->
- 2026-08-05 — plan created.
- 2026-08-05 — S0-T1 — installed `mcp/sdk` 0.7.0 + `symfony/mcp-bundle` 0.12.0 (exact pins), registered `Symfony\AI\McpBundle\McpBundle`; dropped the stray `http_discovery.yaml` recipe file (nyholm already owns those aliases).
- 2026-08-05 — S0-T2 / I-T2 — `/_mcp` route live via `config/packages/mcp.php` + `config/routes/mcp.php`; sessions on a new Redis pool `cache.mcp_session` behind a `Psr16Cache` wrapper (stateless is not offered by the SDK); `allowed_hosts` covers wboost.cz + localhost; added `psr/simple-cache` (mcp/sdk ships it as require-dev only).
- 2026-08-05 — S0-T3 — `BufferedMcpController` decorates `mcp.server.controller` so `/_mcp` can never return a flushing `StreamedResponse` (R1 guarded); the nested double output buffer is required — a single one lets the SDK's `ob_flush()` escape to the SAPI.
- 2026-08-05 — S0-T4 — `src/Mcp/{Tool,Design,Security}` autowired in `config/services.php`, `Response/`+`Design/Dsl/`+`Exception/` excluded (verified with throwaway probes); tool attribute is `Mcp\Capability\Attribute\McpTool` → tag `mcp.tool`. **Stage 0 complete.**
