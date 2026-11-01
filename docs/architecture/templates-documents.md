# Templates — multi-page documents ("Dokumenty")

Plan + review log: `docs/plans/multipage-documents.md` (locked decisions §0).

**Model.** A `TemplateDocument` (project, category, name, `position`, ONE
`TemplateDimension` — the page size + print setup) is an ordered binder of
ordinary templates. A page is a `TemplateDocumentPage` row: template +
the variant of the document's size it renders (`TemplateDimension::
hasSameSizeAs` — unit + both sides; preset/print setup ignored). `template_id`
is UNIQUE: a template is a page of at most ONE document. Shared fields
(`TemplateDocumentField`: name, kind text|image, JSON `bindings`
`[{templateId, inputId}]`) are the ONLY way pages share a value — never
implicitly, not even when two pages carry the same inputId (`CopyTemplate`
copies canvases verbatim, so duplicated pages collide by design).

**Invariants live in `Services/TemplateDocument/TemplateDocumentRules`** —
every writer (web, API, MCP) dispatches the same messages
(`Message/TemplateDocument/*`): same project, template not grouped, not a page
elsewhere, a variant of the document size, ≤ `TemplateDocument::MAX_PAGES`
(30), no duplicates; bindings only to pages of THIS document, one per page,
text = not locked / not lists / not checklist, image = any slot. Refusals are
`InvalidTemplateDocumentChange` (Czech message + machine `reason`); web
controllers surface them as flash via `DispatchDocumentChange`.

- **Page list replace DIFFS** (`TemplateDocumentPageList::set`): Doctrine runs
  INSERTs before DELETEs within one flush, so delete-all + re-insert would trip
  UNIQUE(template_id) on a reorder. Kept pages move, dropped pages go (their
  bindings with them, `TemplateDocument::removePage`), new ones attach.
- **Print setup is the document's**: attaching (or "Tiskové nastavení
  dokumentu") writes the document's bleed + crop marks into the page variant —
  one PDF never mixes setups.
- **Deleting a page's variant is refused** (`DeleteTemplateVariantHandler`) —
  the page row would otherwise CASCADE away silently. Deleting the template
  (or "Smazat stránku") is fine: CASCADE removes the page row.
- **Freshly created entities**: a `Template` built in the current request has an
  EMPTY in-memory `variants` collection, so size matching (`matchingVariant`)
  only works on templates loaded from the DB. Same-request flows pass the
  variant explicitly (`InsertTemplateDocumentPage`, used by "Duplikovat
  stránku" with `CopyTemplate`'s pre-assigned `variantIds`).
- **Positions**: templates and documents share ONE sort space per category
  (`TemplateRepository::nextPosition()` counts both; `SortTemplatesHandler`
  resolves either kind of id).

**Rollout** — built behind an admin-only switch (`TemplateDocumentsFeature`)
while the phases landed on prod; the switch was REMOVED on 2026-10-30 when web,
API and MCP were complete. Documents are visible to every project viewer;
arranging them is a designer + owner tool (`TemplateDocumentVoter::EDIT`).

**Listing** (`GetTemplateListing` → `TemplateListingItem`): per category one
ordered list of template | document cards; pages excluded via `NOT EXISTS`
on `template_document_page`. `_document.html.twig` = stacked page thumbnails
+ "N stran" + two-mode delete (`deletePages` 0 = dissolve, 1 = delete page
templates). `GetTemplates::allForProject(…, excludeDocumentPages:)` for the
dashboard and the attach dialog. `GetGalleryImageUsage` still links a picture
used on a page to that template's variants page (reachable, just not on the
listing).

**Document page** (`/template-document/{id}`, `template_document.html.twig`):
sidebar of page thumbnails (the shared Sortable `dragula` controller posts
template ids; the sort endpoint accepts a REORDER only — a different set =
409, so a stale tab can't detach), selected page large (`?page=<templateId>`),
attach dialog (`?attach=1` auto-opens it via `auto_open_modal_controller`),
new page (blank variant → editor), duplicate, detach, delete page, print
settings, rename. Every POST carries the `template_document` CSRF token.

**Editor page strip**: `template_variant_editor?document=<id>` (honoured only
when the variant IS a page of that document) adds breadcrumb "Dokument ›
Strana n", a strip of sibling pages, and redirects a non-JSON save back to the
document. `document_page_strip_controller` asks before leaving with the
editor's "Neuložené změny" badge visible — the editor itself has no leave
guard.

**Shared fields — designer UI** (document page, EDIT only,
`_document_shared_fields.html.twig` + `DocumentFieldsView`): the "Společná
pole" card lists each field (name, kind, bound pages) with a stale badge when
a binding no longer resolves (`isBindable` fails — input deleted / changed in
the editor). Add / edit / delete in a modal: name, kind, and per page ONE
select listing that page's bindable texts (with their kind: formátovaný / se
seznamy / zaškrtávací seznam), image slots and recolourable shapes as three
`<optgroup data-kind>` (`document_field_form_controller` enables the group of
the chosen kind; without JS the server refuses a mismatch). A text field's
badge names its effective `DocumentTextMode`. **Suggestions**: unbound
inputs sharing an inputId (duplicated pages) or a real, non-fallback name on
≥ 2 pages, one hidden-field form each. Everything POSTs to ONE controller,
`TemplateDocumentFieldsController` (`template_document_fields`, CSRF
`template_document`; form `fieldId` ("" = new), `name`, `kind`,
`bindings[<templateId>]` = inputId | "", `delete`=1): it rebuilds the FULL
list from `DocumentFieldsView::currentDefinitions()` — which drops stale
bindings, because the full-replace handler re-validates every field and an
unrelated stale binding would otherwise refuse the edit — applies the one
change and dispatches `SetTemplateDocumentFields` (ids kept) through
`DispatchDocumentChange` (refusal → flash).

**Fill keys** (`Value/DocumentFillKeys`, plan §3): the fill machinery keys
everything by inputId globally, but pages may share inputIds and must not
share values. Every `(page, inputId)` gets a fill key: the field id when
bound to a shared field, else `uuid5(templateId, inputId)` (deterministic,
UUID-shaped, stable across reloads and detach + re-attach). The web form is
in fill-key space; `valuesForPage()` turns it back into ONE page's real-inputId
maps (shared values fanned out; other pages' keys dropped;
`imagePlacements` stays `[variantId][key]` → `[variantId][inputId]`).
Renderer, cache, print pipeline, API and stored versions never see fill keys.

- **`Services/TemplateDocument/DocumentPageView::rekeyed(variant, keys,
  templateId)`** gives the EXISTING placeholder builders (`GroupFillPlaceholders`,
  `FillTextPlaceholders`, `FillShapePlaceholders`) a re-keyed in-memory copy.
  Hard rules: `clone` FIRST and `editCanvas()` the clone — never the managed
  original (the fill page flushes mid-request via `FileUploadPixelSizeBackfill`
  and would persist the keys; the clone is outside the identity map and
  nothing cascade-persists it); re-key EVERY location — `inputs[]` (order
  kept: text binding is positional), `imageInputs[]`, canvas objects'
  `inputId` (nested group objects too), `containers[].memberInputIds`
  (a stale member makes `CanvasContainer` drop the container and the overlay
  reflow diverge); readonly inputs via `fromArray(['inputId' => key] +
  toArray())`; NEVER hand the clone to a renderer/resolver/message.
  Pinned by `DocumentPageViewTest` (incl. a flush) and the fill controller
  test (stored canvas byte-identical after the page renders).
- Binding two inputs of ONE page to one field is forbidden (§2) — the clone
  would carry a duplicate key and first-wins would drop a box.

**Fill page** (`GET /template-document/{documentId}/fill`,
`template_document_fill`, VIEW, 404 with the feature switch off;
`TemplateDocumentFillController` → `DocumentFillPage::build()` →
`template_document_fill.html.twig`): the THIRD fill surface (single variant,
group, document — see `templates-fill.md`), copied from the group page.
- One `.fill-surface` per PAGE, stacked (continuous scroll), a sticky
  thumbnail sidebar (`document_fill_controller`: instant jump on click —
  Bootstrap's `:root{scroll-behavior:smooth}` would sweep every page in
  between into view and start its render — thumbs follow each page's latest
  render, overflow badge mirrored, current page highlighted).
- All builder data comes from the re-keyed clones, so every mirror / popover /
  box / echo object / image frame carries the fill key. A shared field = ONE
  mirror and ONE popover, a box on every bound page ("Společné: <name>").
  Post-processing in `DocumentFillPage`: a shared TEXT field may bind ANY
  editable text input and offers the richest editor EVERY binding accepts —
  `DocumentTextMode::common()`: checklist (all checklists; a checklist binds
  ONLY to checklists — `TemplateDocumentRules::assertBindings`), list (all
  rich + lists), rich (all rich), else plain (a seeded rich value degrades to
  its text). MIN `maxLength`; hidable / listCheckboxes / checklist
  capabilities AND-ed; font menus (rich faces or the plain whole-text select —
  only when every binding offers it) and colour allowlists INTERSECTED
  (`commonColors`: null narrows nothing). The value then goes to every page
  through the fill key unchanged — valid there because the mode is the common
  one. A shared SHAPE field (kind `shape`) is ONE colour row: free only when
  every shape is, otherwise the colours every shape allows; `""` = each
  page's own designed colour. A shared IMAGE field's picker offers the
  INTERSECTION of the bound slots' `allowedDirectoryIds` (an empty
  intersection of restricted lists becomes a never-existing folder id — `[]`
  would mean "any folder"), placement flags AND-ed over the bound slots.
  Each page still enforces its own rules at render.
- "Všechna pole": "Společná pole" first, then one section per page in that
  template's `fillFieldOrder` (real ids mapped to keys). No admin drag-reorder
  here (order is per template — arrange it on the template's own fill page).
- Placements: `imagePlacements[<page variantId>][<key>][…]` only for each
  page's OWN adjustable slots (the group page emits slot × every variant).
- Uploads: per slot through `template_variant_placeholder_upload` of the
  page's variant with the REAL inputId (a shared field: its first binding —
  the offered folders are the intersection, so any pick is allowed there too).
- Export split button → `template_document_export` (same form): `format` =
  `pdf` (print documents only — primary there), `pdf-screen` (primary
  otherwise), `png`. The worst overflow of ANY page disables all of them.
- **Form field contract** (what preview + export read, all in fill-key space):
  `textValues[key]`, `fontValues[key]`, `hiddenValues[key]`,
  `shapeColorValues[key]`, `images[key][imageId|hide]`,
  `imagePlacements[variantId][key][scale|rotation|offsetXRatio|offsetYRatio]`,
  `format`.
- **Lazy previews**: `group_fill_controller` with `lazy: true` renders a page
  when it comes within 200 px of the viewport (IntersectionObserver), not N
  renders on load. `variants[].keys` (each page's fill keys) narrows an edit's
  re-render to the pages carrying the edited key; off-screen ones just go
  STALE (veil kept) and render when scrolled to; the echo lights up on
  visible pages only. The group page passes neither, so it behaves as before
  (scope coalescing is now a union instead of "widen to all").
- **Preview** `POST /template-document/{documentId}/fill-preview/{pageId}`
  (`template_document_fill_preview`, pageId = `TemplateDocumentPage` id; 404
  unless it belongs to the document): the group preview's twin — session lock
  released, WebP, `?base=1` echo base (real echo-capable ids) — rendering the
  REAL variant with `valuesForPage()` through `GroupFillRenderer::render()`.
  The client's single-flight-per-page + 2-concurrent queue is unchanged.
- **`max_input_vars` decision: raised to 10000** for every environment
  (`.docker/php/wboost.ini` → `conf.d/zz-wboost.ini`: Dockerfile COPY for
  prod, read-only mounts in `compose.yaml` / `compose.prod-mirror.yaml`).
  PHP drops fields past the limit SILENTLY (the export would render
  defaults). Worst case counted: a page of 12 hidable texts + 2 adjustable,
  hidable image slots posts 36 fields → 30 pages ≈ 1080, past the default
  1000; `TemplateDocumentFillControllerTest` builds exactly that and pins the
  form against the shipped ini. Chosen over a single JSON field because the
  export / preview / drafts share the group page's field names and the native
  form submit (download) keeps working without JS serialisation. A running
  dev container picks the mount up on `docker compose up -d web`.

**Export** (`TemplateDocumentExporter` + `DocumentExportArchive`): ONE file for
the whole document from pages ALREADY resolved by the caller.
- `ExportFormat::Pdf` → `PrintPdfRenderer::renderDocument` (multi-page
  PDF/X-1a; print formats only).
- `PdfScreen` → `ScreenPdfRenderer::renderDocument`.
- `Png` → ZIP of `{nn}-{page}.png`.
- Files are named `{document}.pdf` / `{document}.zip`.

The three callers:
- **Web** — `TemplateDocumentExportController`, POST `format`. The form is
  in fill-key space, translated by `DocumentFillKeys::valuesForPage`, and
  resolved LENIENTLY via `GroupFillRenderer::resolve` (now public).
- **API** — `TemplateDocumentRenderProcessor`. It resolves STRICTLY via
  `ApiExportRenderer::resolve` (now public), **every page before any
  render**.
- **MCP** — `export_document`. Strict per page through `VariantFill`, so
  page errors are prefixed with the page.

Usage is recorded per page variant. `set_time_limit(600)` covers a 30-page
print PDF. A print PDF is refused for px documents (`pdf_not_available`).

**Export history + drafts** (`TemplateDocumentExportVersion`, own table): the
`TemplateExportVersion` semantics over a document fill.
- **Same semantics:** dedup by hash, drafts promoted / merged on export,
  names, pins, 100 unpinned cap.
- **Why its own entity:** the single-subject one (variant XOR group,
  `template` NOT NULL, the `ExportFillValues` column type, the seeder, the
  API view and the history page) is read everywhere as ONE variant's input
  space.
- **Fill shape:** `DocumentFillValues` = per page (keyed by **templateId**,
  stable across detach + re-attach) the ordinary `ExportFillValues` with
  REAL inputIds, shared values fanned out into every bound input. NEVER
  fill keys: a key flips between uuid5 and a field id when an input is
  (un)bound.
- **Recording:** `RecordDocumentExportVersion` (swallow + log, after the
  render), called from all three exports.
- **Drafts:** `SaveTemplateDocumentFillDraft`.
- **Seeding:** `DocumentVersionSeeder`. Each page goes through the lenient
  `ExportVersionSeeder::forGroupValues()` (split out of `forGroup`), then is
  re-keyed into fill-key space. A shared field takes its first bound page's
  value.

**History UI** — the SAME chrome as the variant / group fill pages, made
subject-agnostic: `CuratableExportVersion` (interface on both version
entities) + generic `ExportHistory<T>` (`GetExportVersions::forDocument`).
- `DocumentExportHistoryView::context()` feeds `_export_history_menu.html.twig`
  (pinned + 5 recent, inline rename / pin, "Zobrazit vše"), the curation and
  draft streams, and the form anchors with the document's own routes
  (`pin_route` / `rename_route` template vars, default the variant ones).
  The anchors render AFTER `</form>` — the dropdown sits inside the fill
  form (never nest forms).
- Curation: `template_document_export_version_pin|rename` (POST, the variant
  CSRF ids, `TemplateDocumentExportVersionVoter::MANAGE` = document VIEW),
  Turbo Stream from the fill page, redirect from the history page.
- History page `template_document_export_history` reuses
  `template_export_history.html.twig`; summaries via
  `SummarizeDocumentExportVersion` (page digests merged, shared values
  listed once).
- "Uložit rozpracované" → `template_document_fill_draft` (CSRF
  `template_fill_draft`), answered by `DocumentExportHistoryView::draftSaved()`
  + `X-Fill-Draft-Version`. Seeding: texts / hides / fonts / shape colours
  through the builders (`DocumentFillPage::build($document, $seed)`),
  pictures + placements through `data-group-fill-seed-value` (`_applySeed`).

**History over the API / MCP** — `DocumentExportVersionApiView` presents a
version in the document EXPORT body's shape (`{fields, pages}`; per page
`ExportVersionApiView::variantFillValues()`, lenient against the current
design; `pages` complete, `fields` re-collected from the first bound page).
API: `GET /api/template-documents/{id}/export-versions`,
`GET|PATCH /api/template-document-export-versions/{id}`. MCP:
`document_history` (list / get / rename / pin / unpin / save_draft, channel
`mcp` on its drafts) and `export_document` `versionId` (the stored fill as
the base, `fields` / `pages` override key by key — `Mcp/Fill/DocumentFillInput`).
`manage_document` also has `duplicate_page` (the web's CopyTemplate +
InsertTemplateDocumentPage) and `set_print`. Still no MCP delete (the
"drafts, never destruction" rule): detach via `set_pages`, delete on the web.

**Shared text vs page-level switches** (`DocumentApiValues::sharedText`): a
shared value replaces only the TEXT of the bound input; the page's own
`hide` / `fontFamily` survive unless the shared value sets them.
