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
