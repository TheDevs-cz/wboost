---
description: Start a session on the templates canvas editor / group editor / fill+export pipeline — code map, working mode, current state.
---

We're working on the WBoost canvas template editor: the admin single-variant editor, the group
editor, the user fill/export pages, and the Gotenberg render pipeline. Focus: UX polish and bug
fixing.

$ARGUMENTS

## Context

CLAUDE.md is already in your context and is accurate + load-bearing. The sections that matter
here: "Template editor (Šablony — the unified templates module)" (data model, canvas-vs-layer
backgrounds, containers, rich text / lists / checklists, vector shapes, floating toolbar,
client-side text echo, fill flow, gallery, render path, slice cache, export versioning), "One
Šablony module — merge history + dimension model" (TemplateDimension + presets, groups, routes,
deliberately-unmoved namespaces) and the API sections. Do not re-derive what it states; verify
against code only when a task touches it.

Code map (paths verified 2026-09-02):

- Admin editor JS — `assets/controllers/canvas_*.js`; orchestrator `canvas_editor_controller.js`,
  siblings reach it via the `canvas-editor` Stimulus outlet. Serialization contracts:
  `canvas_payload.js`, `canvas_custom_properties.js`, `canvas_shapes.js`. Group editor:
  `group_editor_controller.js` + `group_sync.js` + `group_projection.js`. Dimension forms:
  `template_dimension_controller.js`, `template_group_dimension_controller.js`.
- Shared classic scripts — `assets/editor/*.js` (`container_layout.js`, `rich_text_runs.js`,
  `rich_text_blocks.js`, `fill_text_echo.js`, `fabric_break_word.js`, `text_measure.js`). They run
  verbatim on THREE surfaces (editor page, fill page, headless render template), so any change is
  a three-way contract and they must stay dependency-free classic scripts.
- Fill pages — `src/Twig/Components/AbstractVariantFiller.php` + its only subclass
  `src/Twig/Components/Template/VariantFiller.php`, component template
  `templates/components/VariantFiller.html.twig`, page `templates/template_variant_export.html.twig`
  (where the classic scripts + @font-face load — a `<script>` inserted by a Live morph NEVER
  executes). JS: `variant_fill_overlay_controller.js`, `variant_image_fill_controller.js`,
  `variant_text_echo_controller.js`, `fill_gallery_controller.js`, `rich_text_editor_controller.js`,
  `checklist_editor_controller.js`, `variant_publish_controller.js` (FB/IG, preset-gated). Group:
  `templates/template_group_fill.html.twig` + `group_fill_controller.js`.
- Gallery — `src/Twig/Components/Project/ImageGallery.php` + `gallery_uploader_controller.js` +
  `image_gallery_controller.js`; rendered both as the editor modal and standalone at
  `/project/{id}/gallery` (route `project_gallery`). Trash bin "Koš", 7-day purge.
- Backend — `src/Services/Editor/` (`TemplateVariantImageRenderer`, `BackgroundLayer`,
  `EchoCapableTextInputs`, `ResolveGalleryBackground`), `src/Services/SocialNetwork/` (the shared
  fill engine — module-agnostic, namespace kept on purpose, do NOT rename),
  `src/Services/TemplateGroup/` (`CanvasDesignProjector`, `GroupFillRenderer`,
  `GroupFillPlaceholders`), `src/Services/Template/` (`RecordExportVersion`, `ExportVersionSeeder`).
  Single canvas-save chokepoint: `EditTemplateVariantCanvasHandler` — both editors dispatch
  `EditTemplateVariantCanvasEditor`. Render contract: `templates/api/template_variant_render.html.twig`.
- API — `src/Api/Templates/`: canonical `/api/projects/{id}/templates` + `/api/template-variants/…`.
  The `…custom-template…` / `…social-network-template…` families are deprecated aliases on the same
  operations (`deprecationReason`, `*_legacy_custom` / `*_legacy_social` route names) and must stay
  behaviorally identical.
- MCP design DSL — `src/Mcp/` (`Design/` compiler + decompiler + lint, `Tool/` set_design / preview /
  render / export / gallery), docs in `docs/mcp/`. It compiles to the SAME canvas JSON the editor
  writes, so canvas object/property changes usually need a mirror there (`DesignCompiler`, `Dsl/`,
  `src/Value/CanvasShape*`).

## Working mode (strict)

- One task = one commit, pushed to `main` immediately after verification. Conventional style from
  `git log` (`fix(fill): …`, `feat(editor): …`, `fix(gallery): …`); the body explains the WHY. Stage
  files explicitly — parallel sessions share this working tree.
- Avoid assumptions — ask. When a requirement is ambiguous (semantics, scope, UX behaviour), ask
  concise multiple-choice questions with a recommendation BEFORE implementing. Don't ask about what
  the code or conventions already answer.
- Verification before every commit, everything inside the container:
  `docker compose exec web composer phpstan`, `docker compose exec web vendor/bin/phpunit`,
  `docker compose exec web bin/console lint:twig templates/<touched>`, and `node --check` for touched
  JS (copy to a scratch `.mjs` first). The `gotenberg` group is EXCLUDED from the default suite — run
  it explicitly (`vendor/bin/phpunit --group gotenberg`) when you touch the render or echo pipeline.
  Update tests that pin changed behaviour; add tests for new server-side logic.
- You cannot browser-verify interactive JS by default: say so explicitly in the summary and list what
  to click through. When it matters, the working recipes are the static `public/` harness, curl-login
  + base-href, and local headless Chrome.
- API-shape changes get ported to the mfkfm consumer (`~/www/mfkfm/backoffice`) in the same session
  and documented in `docs/api/consumer-prompt.md`.
- Keep CLAUDE.md updated when you change an architectural contract.

## Current state (2026-09-02)

- Prod deploys automatically: push to `main` → `Tests` → `Release` (HMAC webhook to lily.srv).
  Destructive migrations need a manual backup first. Nightly on lily: gallery trash purge 02:00,
  storage scan 02:30 Prague.
- Newest work, the most likely source of follow-up reports (see `git log` for detail):
  - **Export versioning** (2026-09-01) — every successful export snapshots a re-loadable fill;
    `?version=<id>` seeding on both fill pages. The image-restore JS is not visually verified.
  - **Client-side text echo** (2026-09-01) — typing paints locally over a transparent-text base
    render; the debounced server settle stays the truth at rest and the only exported pixels.
    Golden MSE parity test + headless-Chrome verified.
  - **Fill-preview fatals + perf** (2026-08-31) — LOCK_NONE sessions, deferred first render,
    single-flight group previews, render timeouts typed as 503.
  - **Group-sync flake hardening** (2026-08-31) — `loadFromJSON` silently DROPS images whose src
    fails to fetch; drop-aware restore, retries, "Nenačteno" rail badge, structural-gap alert on save.
  - **Vector shapes + gradient fills** (2026-08-11) and their MCP DSL vocabulary (2026-08-12).
  - **Gallery-picked backgrounds** on every add/edit form (2026-08-10); a background pick now follows
    the group editor's multi-edit mode.
- Older but still not interactively verified: background-as-layer rework, floating toolbar,
  containers v2 (nesting/gap/image members).
- The 2026-08-04 templates merge is settled. Residual risk there is leftover labels/routes and
  preset-vs-free-form behaviour splits, not the data model.

<!--
Maintenance: the "Current state (date)" block is the only part that ages. Refresh it from
`git log --oneline -25` when it drifts more than a few weeks behind; the code map and working
mode are stable.
-->
