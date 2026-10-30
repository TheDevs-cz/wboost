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

**Rollout switch — `TemplateDocumentsFeature`** (Twig `template_documents_enabled()`):
admin-only until the feature is complete. It gates the entry points AND the
hiding of pages: with it off, a page is an ordinary template on the listing
and the dashboard, and the document routes 404.

**Listing** (`GetTemplateListing` → `TemplateListingItem`): per category one
ordered list of template | document cards; pages excluded via `NOT EXISTS`
on `template_document_page`. `_document.html.twig` = stacked page thumbnails
+ "N stran" + two-mode delete (`deletePages` 0 = dissolve, 1 = delete page
templates). `GetTemplates::allForProject(…, excludeDocumentPages:)` for the
dashboard and the attach dialog.

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
select listing that page's bindable texts and image slots as two `<optgroup
data-kind>` (`document_field_form_controller` enables the group of the chosen
kind; without JS the server refuses a mismatch). **Suggestions**: unbound
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
  Post-processing in `DocumentFillPage`: a shared TEXT field is plain text
  (no WYSIWYG / font choice), shows the MIN `maxLength` of its bindings and is
  hidable only if every binding is; a shared IMAGE field's picker offers the
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
