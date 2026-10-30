# Implementation Plan — Multi-page documents ("Dokumenty")

> **Audience:** the implementing Claude Code agent. Self-contained hand-off.
> **Repo:** `/Users/janmikes/www/brand-manuals` — Symfony, FrankenPHP, PostgreSQL 16, namespace `WBoost\Web`.
> **UI language:** Czech. Code, docs, API/MCP descriptions: English.
> **Quality gates after EVERY phase:** `docker compose exec web composer phpstan` + `docker compose exec web vendor/bin/phpunit` green (run the PHP suite even for JS-only phases — `DesignCompilerTest` parses JS).
> **Commit + push per phase** (house rule), staging files explicitly (never `git add -A`).
> **Status:** design approved 2026-10-30 (brainstorm in session). This file is the progress log — tick phases as they land.

---

## 0. Locked product decisions — do NOT relitigate

1. **A document is an ordered binder of existing templates.** A page IS an ordinary `Template` (+ the one variant of the document's size). Pages keep their own editor, fill engine, renderer, inputs. No "multi-page template" inside `Template`.
2. **A page belongs to exactly one document** and is **hidden from the Šablony listing** while attached. The document shows as ONE card ("4 strany" + stacked thumbnails). **Detach** = the template returns to the listing untouched. **Delete document** = two-mode modal like groups: "Zrušit pouze dokument" (pages return) / "Smazat včetně stránek".
3. **One page size per document** (`TemplateDocument::$dimension`). A page uses its template's variant of that size (matched on unit + unitWidth + unitHeight; preset and print setup ignored). "Nová stránka" creates a template with one blank variant of that dimension (incl. the document's print setup).
4. **Synchronized (group) templates cannot be pages** in v1, and a page cannot be turned into a group.
5. **Pages behave as independent templates when filled** — values are per page, never implicitly shared, **even when two pages carry the same inputId** (duplicates copy the canvas verbatim — `CopyTemplateHandler`, so collisions ARE the common case for the user's 4 templates).
6. **Shared fields ("Společná pole")** are the ONLY sharing mechanism: an explicit, designer-defined field bound to N `(page, inputId)` pairs. v1 kinds: **text** (plain text only — no rich runs, no font choice; not lists/checklists, not locked inputs) and **image** (shared pick, per-page placement). Value fans out BEFORE each page's own strict/lenient resolution, so per-page limits/allowlists still apply.
7. **Exports:** one **print PDF** (multi-page PDF/X-1a, FOGRA39, each page its own bleed/crop marks — print-format documents only), one **screen PDF** ("PDF k odeslání": sRGB, ~150 ppi JPEG, any document), **ZIP of PNGs** (any document; a 4:5 document = Instagram carousel).
8. **Web + API + MCP ship together** (this plan), plus the mfkfm consumer port in the same session.
9. **Additive API:** `GET /api/projects/{id}/templates` keeps listing page templates, with a new `document {id, name, position} | null` ref (like `group`) so existing consumers keep working until ported.

---

## 1. House style (verified)

Follow `docs/plans/admin-user-management.md` §1 + `docs/plans/mcp-server.md` §1. Key bits: messages `readonly final class` with public promoted props in `src/Message/TemplateDocument/`; handlers `#[AsMessageHandler] readonly final class`, never `flush()`; read models `src/Query/Get*.php`; ids via `ProvideIdentity::next()`; voters ADMIN-short-circuit first; entities never exposed by API/MCP; buffered `Response`s only (never a flushing `StreamedResponse`); never nest `<form>`s; Twig empty hash → prefer scalar/array Stimulus values; app.css loses to the theme at equal specificity — scope one level deeper.

---

## 2. Data model (Phase 1)

```
template_document
  id uuid PK, project_id FK CASCADE, category_id FK SET NULL (nullable),
  name varchar, position int default 0, created_at,
  dimension_* (embedded TemplateDimension: unit, unit_width, unit_height,
               preset, bleed_mm, crop_marks)

template_document_page
  id uuid PK,
  document_id FK → template_document CASCADE,
  template_id FK → template CASCADE   UNIQUE   (one document per template),
  variant_id  FK → template_variant CASCADE,  (must belong to template_id)
  position int

template_document_field
  id uuid PK, document_id FK CASCADE, name varchar, kind varchar(8) ('text'|'image'),
  position int,
  bindings jsonb  -- list<{templateId, inputId}>
```

- Entities `TemplateDocument`, `TemplateDocumentPage`, `TemplateDocumentField` (+ repositories). `TemplateDocument::MAX_PAGES = 30` (export-time budget, §11 R2).
- Why a page row instead of FK columns on `template` (the group pattern): the page needs an explicit **variant** (a template may hold two variants of the same size) and a stable id; CASCADE on template OR variant removes the page row, which simply makes the template visible again.
- Bindings are JSON on purpose (no FK to inputs — inputs live inside JSONB). Stale bindings (detached page / input deleted in the editor) are **ignored at fill/render time** and flagged on the document page (§4). Detach removes that page's bindings eagerly.
- **Positions:** documents share the `position` space with templates of the same category (one sortable list per category on the listing).
- **Storage inventory:** no new path-storing column → `BuildStorageReferenceIndex` unchanged (state it in the doc).
- `export_event.format` is `varchar(8)` → widen to 16 in the same migration (new `ExportFormat::PdfScreen = 'pdf-screen'`).
- Migration is purely additive (+ the column widen) → safe for auto-run on deploy.

**Invariants enforced in handlers (with tests):** template not grouped (`template.group === null`); variant belongs to template and matches the document size; template not already a page elsewhere (UNIQUE as backstop); **same project as the document** (API/MCP pass raw ids — never trust them); ≤ MAX_PAGES; a shared field never binds two inputs of the SAME page (§3).

**Page-list replace MUST diff, never delete-all + re-insert** (review B2): Doctrine's UnitOfWork runs INSERTs before DELETEs within one flush and handlers never flush, so re-inserting a row for a template whose old row is pending deletion violates `UNIQUE(template_id)`. `SetTemplateDocumentPagesHandler` updates `position` on kept rows, removes dropped rows, adds only new ones. Test: "reorder via PUT".

**Cascades / neighbours:**
- `DeleteTemplateVariantHandler` on a variant that is a page → refuse with a message ("Varianta je stránkou dokumentu X — nejdřív ji odpojte") instead of silently detaching via CASCADE; same guard in the variants-page UI.
- `DeleteTemplate` of a page template (from the document page's "Smazat stránku") → CASCADE removes the page row; bindings of that page are dropped by the handler.
- Attaching a print-format page **applies the document's bleed / crop marks to the page variant** (`EditTemplateVariantPrintSettings`) so one PDF never mixes print setups; the attach dialog says so.
- "Duplikovat stránku" must know the NEW variant id: extend `CopyTemplate` to return/accept an old→new variant id map (today it returns nothing and a template can hold two same-size variants).
- New-template positions (`TemplateRepository::count()` in Add/Copy/CreateGroup handlers) ignore documents → new cards can share a position with a document; make the listing's position source cover both (e.g. `max(position)+1` over templates ∪ documents of the project).

---

## 3. Fill keys — the one tricky contract (Phase 3, spike FIRST)

The group fill machinery (`template_group_fill.html.twig`, `group_fill_controller.js`, `variant_fill_overlay_controller`, `GroupFillPlaceholders`, echo) is exactly "N preview surfaces on one form" — the document fill page reuses it. But everything there is keyed by **inputId**, globally (`[data-text-mirror]` lookups, `textValues[<id>]`, echo `inputId`), and pages may share inputIds (decision 5).

**Solution: re-key at the boundary.** Every `(page, inputId)` gets a **fill key**:

- bound to a shared field → `fieldId` (so all bound boxes edit one mirror — sharing for free);
- otherwise → `Uuid::uuid5(page.templateId, inputId)` (deterministic, UUID-shaped, stable across reloads / detach+reattach, so drafts and versions stay valid).

`Services/TemplateDocument/DocumentFillKeys` (pure, unit-tested) owns `keyFor(page, inputId)` and `valuesForPage(page, rawMaps): rawMaps` (fill-key maps → that page's real-inputId maps; shared values fanned out). Applies to text, hidden, font, image, placement and shape maps.

- **Rendering** always uses the REAL variant + `valuesForPage()` — the renderer, cache and print pipeline never see fill keys.
- **Overlay/echo/placeholder data** come out re-keyed via **option (a), confirmed by review**: the existing builders run on an in-memory re-keyed copy — `DocumentPageView::rekeyed(TemplateVariant): TemplateVariant`. Hard rules:
  - **`clone $variant` first, then `->editCanvas()` on the CLONE — never re-key the managed original.** The fill page flushes mid-request (`FileUploadPixelSizeBackfill.php:61`, reached from `GroupFillPlaceholders::imageInputs`), which would persist fill keys into the stored canvas. The clone stays out of the UnitOfWork as long as it is never added to `Template::$variants` (EAGER, no cascade — verified). Never hand the clone to the renderer. Test: after building the fill page, the stored canvas is byte-identical.
  - Re-key EVERY inputId location: `inputs[]` (order preserved — text binding is POSITIONAL, `TextInputObjectBinder.php:55-75`), `imageInputs[]`, canvas object `inputId` (images, shapes, texts), and canvas `containers[].memberInputIds` (`FillTextPlaceholders.php:381`, `EchoCapableTextInputs.php:256`) — a stale member id makes `CanvasContainer` drop the container and the overlay reflow diverges from the render.
  - `EditorTextInput` / `EditorImageInput` are readonly without withers → re-key via `fromArray(['id' => …] + toArray())` so future fields survive.
  - `Template::$fillFieldOrder` holds real inputIds (`TwigExtension.php:84`) → map to fill keys per page.
- `GroupFillPlaceholders` unifies "first-wins by inputId" → with fill keys that unification IS the shared-field behaviour, but two post-processing steps are needed (not "untouched"): shared text field → **MIN `maxLength`** of its bindings; shared image field → **intersection of `allowedDirectoryIds`**. Each page still enforces its own rules at render.
- Binding two inputs of the same page to one field would give the clone duplicate inputIds (first-wins in `framesByInputId` / `echoData`, `GroupFillPlaceholders.php:95-104`, would drop a box) → forbidden by invariant (§2).
- **Form size:** `max_input_vars` is 1000 and PHP drops the excess SILENTLY. The group page emits `imagePlacements` for every slot × every variant (`template_group_fill.html.twig:214-224`) — on a document emit only each page's own slots (placements keyed `[variantId][fillKey]`; page variant ids are unique). Also count the worst case (30 pages × fields): either raise `max_input_vars` (php.ini in the Dockerfile — every env) or serialise the fill as one JSON field. Decide in the spike; add a test with a 30-page fixture.
- JS unchanged except: per-surface preview endpoints/URLs and the page sidebar. The JS state hash (`fill_state_hash.js` ↔ PHP `fillStateHash`) works on fill keys unchanged.

**Spike exit criterion:** two pages created by "Duplikovat" (colliding inputIds) on one document fill page; typing on page 1 changes ONLY page 1; a shared field changes both.

---

## 4. Web UX

### 4.1 Listing (Phase 2)
- New query `GetTemplateListing` → per category (+ uncategorized) an ordered list of items `template | document`, page templates excluded (`LEFT JOIN template_document_page … IS NULL`). `templates.html.twig` iterates these items instead of `category.templates`.
- Audit every other template list and decide: `TemplateGroupFromTemplateController` (exclude pages), `ProjectDashboardController` recent/count (exclude pages, count documents separately), `FindTemplatesTool` (§8), API `TemplatesProvider` (keep, add `document` ref — decision 9), `GetProjectTemplateStats`, `GetExportVersions::latestForProjectTemplates`, **`templates.html.twig:38/:40`** (`categories_with_templates` filter + empty state count only `category.templates` → a category holding only documents vanishes), **`GetGalleryImageUsage`** (links to a hidden page template → link to the document page instead), **`GetUsageOverview` / `RecordExportUsage`** (default: keep one `export_event` per page — per-template stats stay true — and add nullable `export_event.document_id` so `/admin/usage` can show "1 export dokumentu (4 strany)" instead of 4 unrelated exports).
- `_document.html.twig` card: name, "N stran", 2–3 stacked page thumbnails (`previewImagePath`), size label, last export. Click → designers: document page; others: fill page (group card pattern). Menu: Upravit název/kategorii, Vyplnit, Smazat (two-mode modal).
- Template cards' delete modal warns nothing new (pages aren't on the listing).
- `SortTemplates` accepts mixed ids; handler resolves template OR document.
- "Nový dokument" button → form: name, category, size (A5/A4/A3 + mm/cm/px + IG presets — reuse `template_dimension_controller.js`), print setup, optional "Připojit existující šablony" multi-pick (only compatible, ungrouped templates; show thumbnail).
- **Rollout guard:** a single feature switch (`TemplateDocuments::enabledFor(user)` = ROLE_ADMIN until Phase 5) gates the button, the cards **and the page-exclusion itself** — otherwise an admin trying it on a client project makes those templates vanish for that project's users (and from mfkfm until Phase 7). With the switch off, pages show as ordinary templates.

### 4.2 Document page `/template-document/{id}` (Phase 2) — designers (EDIT)
Apple-Preview layout: left sidebar of page thumbnails (number under each), main area = selected page large + its actions.
- Reorder: SortableJS with `forceFallback: true` (house fix) → POST `SortTemplateDocumentPages`.
- "+" in the sidebar / between pages: **Připojit existující** (modal of compatible templates, the `TemplateGroupFromTemplate` picker pattern) / **Nová stránka** (name → `AddTemplateDocumentPage` creates template+variant, then opens the editor).
- Page "…": Upravit (editor), Odpojit, Duplikovat stránku (copy template → attach after; inputIds will collide — fine by §3), Smazat stránku.
- "Společná pole" panel (Phase 3): list fields; "Přidat pole" → name + kind + checkbox list of bindable inputs grouped by page (thumbnail + input name); **suggestions**: inputs with the same inputId (duplicated pages) or same name on ≥ 2 pages, one click to create. Stale binding badge.
- "Tiskové nastavení dokumentu" (print formats): bleed/crop marks applied to the document AND every page variant (dispatches `EditTemplateVariantPrintSettings` per page) — keeps pages consistent.

### 4.3 Editor with a page strip (Phase 2)
- `TemplateVariantEditorController` accepts `?document=<id>` (validated: the variant is a page of it). Renders breadcrumb "Dokument › Strana n" + a slim page strip (thumbnails, prev/next) + "← zpět do dokumentu".
- **The canvas editor tracks dirtiness but has NO leave guard** (only `fill_draft_controller` / mockup editor register `beforeunload`). Dirty state already exists: `canvas_editor_controller.js` `markUnsaved()` / `markSaved()` (the "neuložené změny" badge, ~l.356) dispatches `canvas-editor:dirty`, and ~l.375 reads the badge state. Hook the strip/back links + `beforeunload` onto that (confirm, or "Uložit a přejít"). Read `templates-editor-ui.md` first.
- Saving a page refreshes its `previewImagePath` → the document sidebar thumbnails update for free.

### 4.4 Fill page `/template-document/{id}/fill` (Phase 3) — everyone (VIEW)
Built from `template_group_fill.html.twig` (copy-then-adapt into `template_document_fill.html.twig`; extract shared partials where the diff would otherwise be a fork).
- Left sidebar: page thumbnails (live: the surface previews scaled; overflow badge per page), click scrolls to the page.
- Main: pages stacked vertically (continuous scroll like Preview), one `.fill-surface` per page. **Lazy previews:** a page's settle render starts when it scrolls into view (IntersectionObserver) — not N renders on load.
- Fields panel ("Všechna pole"): "Společná pole" first, then sections per page (heading = page n + name), each in that template's `fillFieldOrder`.
- Linked boxes on pages edit the shared field's popover (same fill key → same mirror). Caption shows "Společné: <name>".
- Image placeholders: picker per fill key; uploads go through the page variant's existing upload route (`template_variant_placeholder_upload`, VIEW = project view). Shared image field: picker shows the intersection of the bound slots' `allowedDirectoryIds`.
- Export split button: "Stáhnout PDF pro tisk" (print documents only), "Stáhnout PDF k odeslání", "Stáhnout PNG (ZIP)". Worst overflow of any page disables export (group rule).
- Preview endpoint per page: `POST /template-document/{id}/fill-preview/{pageId}` (mirrors `TemplateGroupFillPreviewController`, incl. `?base=1` echo base). **404 unless `pageId` belongs to `{id}`** (same rule for every `{pageId}` route and the API).

---

## 5. Rendering & export (Phase 4)

- `Services/TemplateDocument/DocumentPageRenderer` — per page: `DocumentFillKeys::valuesForPage()` then the same resolution as `GroupFillRenderer` (lenient on web, strict via `ApiExportRenderer` on API/MCP). **Both `GroupFillRenderer::resolve()` and `ApiExportRenderer::resolve()` (`:61`) are private** — expose them (or extract shared resolver collaborators) because `renderPage()` needs resolved overrides.
- **Strict mode resolves ALL pages before rendering ANY** — a 400 on page 28 must not arrive after minutes of renders.
- `BrandCmykPalette::forProject()` once per document, not per page.
- **Print PDF:** split `PrintPdfRenderer::render()` into `renderPage(): PrintPage {raster, geometry}` + the existing single-page `render()` (unchanged behaviour for every current caller). `PdfX1aWriter::write()` takes `list<PrintPage>` — today object numbers are hard-coded (ICC = 4, Info = 8, `PdfX1aWriter.php:39-92`) and the xref assumes contiguous numbering: allocate numbers dynamically (catalog, pages, ICC, Info, registration colour space once; page + image + content per page), keep the xref contiguous, `/Kids` + `/Count`, each page its own MediaBox/TrimBox/BleedBox + crop marks, ONE OutputIntent/ICC stream, ONE Info; trailer `/ID` hashes ALL page rasters. Existing writer tests keep passing with a 1-element list; add a 3-page structure test + extend `PrintPdfRendererGotenbergTest` (Ghostscript ink check on page 2).
- **Screen PDF:** `Services/Print/ScreenPdfRenderer` + `ScreenPdfWriter` (PDF 1.4, DeviceRGB DCT image per page, page = trim size in pt — mm→pt, px→px·0.75, no bleed/marks). Raster = the ordinary PNG render, downscaled to 150 ppi for mm/cm (px kept), JPEG q85 via Imagick. Share the low-level PDF helpers (`text()`, `box()`, xref) with `PdfX1aWriter` via a small `PdfObjects` helper rather than duplicating.
- **ZIP:** `DocumentExportArchive` → `{doc}.zip` with `{nn}-{page}.png` (order-prefixed, slugged like `GroupExportArchive`).
- `ExportFormat::PdfScreen` (`'pdf-screen'`); `fromRequestValue` stays lenient. Print PDF on a px document → 400 (`pdf_not_available`).
- Web: `TemplateDocumentExportController` POST `/template-document/{id}/export` (`format`), `ReleaseSessionLock`, buffered response, `export_failed.html.twig` on 400/503 (group controller pattern). Usage recorded per page (`RecordExportUsage::record(variant, Web, format)`).
- File name `{document}.pdf`; PDF `/Title` = document name.

---

## 6. Export history + drafts (Phase 6)

Filling a 4-page offer and losing it on reload is not acceptable for long — documents get "Uložit rozpracované" + "Historie exportů" like variants/groups. **Read `templates-export-versioning.md` first.**
- **`template` becomes NULLABLE + new nullable `document` FK + CHECK `num_nonnulls(variant_id, group_id, document_id) = 1`.** Pointing `template` at the first page is wrong: that page's CASCADE would wipe the document's history on detach/delete.
- The repository branches "variant, else group" everywhere (`TemplateExportVersionRepository.php:56-61` findDuplicate, `:88-90` findDraftOf, `:121-127` prune) — a document version would query `group IS NULL`: dedup never matches, prune never runs (unbounded history). Replace the `(variantId, groupId)` parameter pairs with an `ExportSubject` value object (variant | group | document) across repository, `RecordExportVersion`, `TemplateExportVersionVoter` (`:37-45` returns false otherwise → curation 403), `ExportVersionRedirect` (`:46` dereferences `template`), Turbo streams. `GetExportVersions.php:53` inner-joins template → document rows are naturally excluded from the template cards' "naposledy exportováno".
- **Store fill values in the API shape `{fields: {fieldId}, pages: {templateId: {inputId}}}`, NOT by fill keys** — a key flips between uuid5 and fieldId when an input is (un)bound, which would orphan drafts; the API shape also lets `GET /api/export-versions/{id}` return it as-is. `ExportVersionSeeder::forDocument` converts to fill keys at seed time. Dedup hash canonicalisation is load-bearing — reuse it.
- API: `GET /api/template-documents/{id}/export-versions`.

---

## 7. API (Phase 5) — `src/Api/TemplateDocuments/`

| Operation | Notes |
|---|---|
| `GET /api/projects/{projectId}/template-documents` | `TemplateDocumentResponse {id, name, category, dimension…, printFormat, bleedMm, cropMarks, pages[], fields[]}`; `pages[] {id, position, templateId, templateName, variant: TemplateVariantResponse}` (reuse the variant builder so consumers get inputs/frames/imageInputs/shapeInputs); `fields[] {id, name, kind, bindings[{templateId, inputId}], maxLength}` |
| `POST /api/projects/{projectId}/template-documents` | `{name, categoryId?, dimension {unit, unitWidth, unitHeight, preset?, bleedMm?, cropMarks?}, templateIds[]}` |
| `PATCH /api/template-documents/{id}` | `{name?, categoryId?}` |
| `PUT /api/template-documents/{id}/pages` | `{templateIds[]}` — attach/detach/reorder atomically; detached pages return to the listing |
| `POST /api/template-documents/{id}/pages` | `{name}` → blank page (template + variant) appended; returns the page (variantId for `set_design`) |
| `PUT /api/template-documents/{id}/fields` | full replace of shared fields |
| `DELETE /api/template-documents/{id}` | dissolves ONLY (pages return). Deleting page templates stays a web action. |
| `POST /api/template-documents/{id}/preview/{pageId}` | WebP, unrecorded |
| `POST /api/template-documents/{id}/export` | `{format: "pdf"\|"pdf-screen"\|"png", fields: {fieldId: value \| {imageId, hide}}, pages: {pageId: {inputs, images, shapeColors}}}` → PDF or ZIP. A page's `images[inputId]` may carry ONLY a transform for a slot bound to a shared image field — the fan-out supplies the `imageId` before `ResolveImageOverrides` (which 400s a transform without one). |

- Writes = Request DTO + Processor dispatching the same CQRS messages as the web (CLAUDE.md rule). Security `IS_AUTHENTICATED_FULLY` + `TemplateDocumentVoter`: reads/exports via `ApiSubjectLoader::document()` (VIEW); **writes need a new `ApiSubjectLoader::writableDocument()` (EDIT = ROLE_DESIGNER + project access)** — the existing loader only ever checks VIEW. Every referenced templateId/categoryId is re-checked for same-project.
- Strict validation; every 400 is `ExportErrorResponse` + `pageId` (and `fieldId` when a shared value failed). **`pageId` is the single page identifier on every API/MCP surface** (preview URL, export body, errors). Unknown pageId in `pages` → ignored (inputs rule); binding errors on PUT fields → 400 `invalid_binding`.
- **API fill shape uses REAL inputIds per page** (not fill keys — those are a web form detail); the processor converts to fill keys via `DocumentFillKeys`. Shared field values override the bound inputs of `pages[*].inputs`.
- `TemplateResponse` gains `document {id, name, position} | null` (additive).
- Docs: `docs/api/consumer-prompt.md`, `api-templates.md`.

---

## 8. MCP (Phase 5) — two new tools (budget raised to a soft ~25 on 2026-10-30, `mcp-server.md` §0.11)

Extend-vs-new per §0.11 of `mcp-server.md`: listing → extend `find_templates`; per-page describe/render/design → existing variant tools unchanged (a page IS a variant); structure writes → new `manage_document`; whole-document export → new `export_document` (different subject + body; merging into `export_variant` would need either-or params).

- **`find_templates`**: + `documents[]` (same summary as the API) and a `document` ref on page templates. No new tool for reading.
- **`manage_document`** (`templates:design`): `action` = `create` | `rename` | `set_pages` | `add_page` | `set_fields`. `add_page` returns the new `variantId` so the agent continues with the existing `set_design`. No delete (MCP rule "drafts, never destruction"; detaching is non-destructive). Returns the document summary + `editorUrl`/`fillUrl`.
- **`export_document`** (`templates:export`): same body as the API export.
  - **Chat-client ceiling (~25 s):** print PDF ≈ 5–7 s/page (A4 measured ~5 s in the Imagick step alone), screen ≈ 1–2 s/page. Refuse above a page budget with a structured error that names the fill URL / API.
  - **Payload size:** a print PDF is several MB per page; base64 in a tool result floods the host's context. Return the PDF/ZIP as an `EmbeddedResource` only below a size cap (e.g. 8 MB); above it, error with the fill URL. (A signed short-lived download URL is the proper follow-up — out of scope.)
- 11 built tools after this work (17 counting the still-unbuilt planned ones). `describe_variant` / `render_variant` work per page unchanged.
- Docs: `docs/mcp/` + the in-app MCP guide (`templates/mcp_guide.html.twig`).

---

## 9. mfkfm consumer (Phase 7) — `~/www/mfkfm/backoffice`

Hide page templates (`document !== null`) from its template list; add a documents list + document fill (per-page reuse of its variant fill components, shared fields block) + the three export buttons via the new endpoints. Read its current fill implementation before estimating; if it grows large, ship "hide pages + export with defaults" first and log the rest as an mfkfm issue.

---

## 10. Phases (each: phpstan + phpunit green, docs updated, commit + push)

| # | Scope | Tests |
|---|---|---|
| 1 | Entities, migration (+ format column widen), repositories, voter, messages/handlers: Create/Rename/Delete(two-mode)/Attach/Detach/SortPages/AddPage, invariants | handler tests per invariant |
| 2 | Listing integration (`GetTemplateListing`, mixed sort, card, other list audits), "Nový dokument", document page (sidebar, attach/detach/reorder/new/duplicate), editor page strip + unsaved guard; ROLE_ADMIN gate | controller tests (listing excludes pages, access, sort) |
| 3 | **Spike §3**, `DocumentFillKeys`, shared fields (entity UI + suggestions), fill page + per-page preview endpoint | `DocumentFillKeys` unit (collisions, shared fan-out, stale binding), fill page + preview controller tests |
| 4 | Multi-page `PdfX1aWriter`, `renderPage()`, screen PDF, ZIP, export controller, `ExportFormat::PdfScreen`, usage | writer structure tests, Gotenberg group test, export controller tests (all 3 formats, px→print 400) |
| 5 | API + MCP (+ `document` ref on templates/find_templates), docs; **remove the ROLE_ADMIN gate** | ApiTestCase per operation incl. 400 shapes; MCP tool tests |
| 6 | Drafts + export history for documents | versioning tests (dedup, seed round-trip) |
| 7 | mfkfm port | mfkfm build/lint |
| 8 | Docs: new `docs/architecture/templates-documents.md` + CLAUDE.md table row, `api-templates.md`, `consumer-prompt.md`, render doc (multi-page writer), memory note | — |

Browser verification (house reality: interactive JS is often left unverified — don't): Phase 2 (reorder, attach/detach, editor strip guard) and Phase 3 (colliding-inputId pages, shared field, lazy previews) get a real-browser pass via the headless-Chrome recipe.

---

## 11. Risks

- **R1 — fill-key re-keying** (§3): the only architectural unknown; spiked first.
- **R2 — export duration:** each print page = TWO Gotenberg renders (composite + black-ink mask, `PrintPdfRenderer.php:81-99`) + ~5 s Imagick at A4 → budget ~8–10 s/page, i.e. 30 pages ≈ 4–5 min synchronous. `max_execution_time` reads 0 only in the CLI — **measure under FrankenPHP on prod**, plus any proxy timeout in front of lily. Mitigations in order: `set_time_limit()` in the export controller, lower `MAX_PAGES` (e.g. 20), async export via the existing messenger consumer (follow-up).
- **R3 — memory:** `memory_limit` is 512M. Pages render sequentially but all encoded CMYK JPEGs (a few MB each) + the concatenated PDF string are held → measure a 30-page A4 export; if close, write pages to a temp file / `php://temp` and build the PDF incrementally.
- **R4 — editor navigation data loss** (§4.3): no leave guard today; the dirty signal exists, so this is small but must not be skipped.
- **R5 — listing regressions:** every template list must be audited (§4.1) — a missed one shows page templates twice or lets a page be grouped.
- **R6 — group fill fork drift:** copying `template_group_fill.html.twig` creates a third fill surface; extract partials where reasonable and note the three-way contract in the docs.

## 12. Review log
- 2026-10-30 — independent code-grounded review. Folded in: MCP budget conflict (B1 — resolved: budget raised, two separate tools), diffing page replace vs UNIQUE (B2), clone-not-original + full re-key surface (containers, fillFieldOrder, readonly inputs), MIN maxLength / directory intersection post-processing, same-page double binding ban, `max_input_vars` silent drop, export-history subject redesign + API-shaped storage, private resolvers, multi-page writer numbering + /ID, memory/timing re-estimate, feature switch covering the exclusion, extra listing audits (category filter, gallery usage, usage counts), API EDIT loader + same-project checks, pageId ownership 404, variant-delete guard, print-setup sync on attach, CopyTemplate variant map, shared position space, strict pre-resolve, per-document palette.
- Verified as correct by the review: `Template::$variants` EAGER without cascade; positional text binding; placements keyed `[variantId][inputId]`; `group === null` sufficient at attach; `export_event.format` varchar(8); no editor `beforeunload`; storage index unaffected; API Platform write conventions match.

## 13. Progress log
- 2026-10-30 deviation: document export history is its own entity (`TemplateDocumentExportVersion` + `DocumentFillValues`) instead of a third subject on `TemplateExportVersion` — same semantics, zero changes to the single-subject readers.
- [x] Phase 1 — entities + migration (`Version20261030145218`), `TemplateDocumentRules` / `TemplateDocumentPageList` (diffing), voter, 7 handlers, shared `TemplateRepository::nextPosition()`, page-variant delete guard; `tests/MessageHandler/TemplateDocument/` (12).
- [x] Phase 2 — listing (`GetTemplateListing`, `_document.html.twig`, mixed sort), "Nový dokument", document page + 7 page actions, editor page strip + leave confirm, `TemplateDocumentsFeature` (admin-only), dashboard exclusion; `tests/Controller/TemplateDocument/` (8). Doc: `docs/architecture/templates-documents.md`.
- [x] Phase 3 — `DocumentPageView::rekeyed` (clone-only re-key), shared-fields designer UI (`DocumentFieldsView`, `TemplateDocumentFieldsController`, suggestions, stale badge), fill page `template_document_fill` (`DocumentFillPage`, lazy previews in `group_fill_controller`, sidebar `document_fill_controller`, shared-field MIN maxLength / folder intersection, own-slot placements), per-page preview `template_document_fill_preview`; `max_input_vars` raised to 10000 (`.docker/php/wboost.ini`, every env); tests `TemplateDocumentFillControllerTest` (8), `TemplateDocumentFieldsControllerTest` (4), `DocumentPageViewTest`. Browser-verified (puppeteer, dev): colliding pages independent, shared field one mirror / 4 boxes, lazy renders, suggestion + modal kind toggle.
- [x] Phase 4 — multi-page PDF/X-1a writer + screen PDF (c1c13bf), `TemplateDocumentExporter`, web export.
- [x] Phase 5 — API (`src/Api/TemplateDocuments/`) + MCP (`manage_document`, `export_document`, `find_templates` documents); rollout switch REMOVED.
- [x] Phase 6 — document history + drafts (own entity), `?version=` seeding, history dropdown.
- [x] Phase 7 — mfkfm port (fotbal-fm.cz fd65d45; follow-ups in JanMikes/fotbal-fm.cz#3). Browser-verified end to end on dev 2026-10-30 (listing, document page, editor strip, fill, 3 export formats, drafts/history).
- [ ] Follow-ups: `GET /api/template-documents/{id}/export-versions` (history over the API — not built), inline rename/pin of document versions, image placement on the document fill page not browser-clicked.
