# Templates API — export/preview endpoints, groups + export history

### Template variant export endpoint

`POST /api/template-variants/{id}/export` returns a PNG and IS AN EXPORT
(usage + a version in the variant's history); its sibling
`POST /api/template-variants/{id}/preview` takes the same body with the same
strict validation but returns WebP and records NOTHING — consumers must send
their live previews there (before 2026-09-29 the mfkfm backoffice previewed
through `/export`, so every typing pause became a history row). Both run
through `Services/Template/ApiExportRenderer` (strict: invalid values and
container overflow fail) and answer failures via
`Api/Templates/ExportErrorResponse` — EVERY 400 is structured
`{error, code, ...context}`: the resolvers throw
`Exceptions/InvalidExportValue` (a `BadRequestHttpException` subclass, so the
lenient web controllers keep catching it) with `value_too_long` /
`invalid_value` / `invalid_image_value` / `image_transform_not_allowed` /
`image_not_allowed` / `image_unreadable`, tagged with `inputId` /
`imageInputId`; rich-text violations carry `inputId` too
(`InvalidRichTextValue::forInput`). The former module alias paths
(`custom-template-variants`, `social-network-template-variants`,
`…/custom-templates`, `…/social-network-templates`) were REMOVED in the same
v2 cleanup — mfkfm is the only consumer and moved to the canonical paths.
The request body is `ExportRequest` and its `inputs` map is
**keyed by inputId UUID** (Stage 2): `{ "inputs": { "<uuid>": "Hello", "<uuid>": { "value": "World", "hide": false } } }`.
Discover the available input ids via
`GET /api/projects/{projectId}/templates` — each
`variants[].inputs[].id` is the same UUID accepted here. Unknown ids
are silently ignored; locked inputs cannot be overridden; `hide` only
applies to inputs with `hidable: true`.

### Template groups + export history over the API (2026-09-29)

- **Listing:** `TemplateResponse::$group` (`{id, name}` | null) and
  `TemplateVariantResponse::$groupMember` (variant carries the group FK —
  a hand-added variant on a grouped template is NOT a member).
- **Group render** (`src/Api/TemplateGroups/`): `POST
  /api/template-groups/{groupId}/preview/{variantId}` (WebP, unrecorded),
  `…/export` (ZIP) and `…/export/{variantId}` (PNG). Body =
  `GroupExportRequest {inputs, images, placements}` — the variant export's
  maps (members share input ids) plus `placements[variantId][imageInputId]`,
  which REPLACES the shared pick's transform in that dimension
  (`ApiExportRenderer::imagesForVariant`, the web `GroupFillRenderer`
  layering rule). Strict per dimension, error bodies add `variantId`.
  Exports record usage per member + ONE group version
  (`ExportFillValues::fromGroupApiRequest`) that dedupes with the web group
  page's exports. ZIP/PNG naming is `Services/TemplateGroup/GroupExportArchive`,
  shared with the web controllers. Members/ids/voters resolve through
  `Services/Template/ApiSubjectLoader` (group VIEW voter; non-member → 404).
- **History** (`src/Api/ExportVersions/`): `GET
  /api/template-groups/{id}/export-versions`, `GET
  /api/template-variants/{id}/export-versions` (pinned-first
  `ExportHistory` order + `SummarizeExportVersion` digest), `GET
  /api/export-versions/{id}` (+ `fill` in the API request shape, seeded
  leniently by `ExportVersionSeeder` and converted back by
  `Services/Template/ExportVersionApiView`), `PATCH
  /api/export-versions/{id}` `{name?, pinned?}` (absent key = untouched,
  dispatches the web's Rename/Pin messages; `TemplateExportVersionVoter::MANAGE`).

Each `variants[].inputs[]` also carries a nullable **`frame {x,y,width,height}`**
(canvas px, top-left origin, axis-aligned — same shape/space as
`imageInputs[].frame` and the variant's `width`/`height`), so a consumer can draw
a highlight box over the rendered preview and edit in place. `null` when the
textbox can't be located → fall back to the flat field list. Mapping to screen px
is a single scale factor (`renderedPreviewWidth / variant.width`) because the
export PNG is the variant's exact dimensions. The mfkfm backoffice consumer
(`~/www/mfkfm/backoffice`) implements this overlay; see
[`docs/api/consumer-prompt.md`](../api/consumer-prompt.md). v1 limitation:
rotated placeholders are reported as their axis-aligned bounding box.

### Recolourable shapes over the API (2026-10-20)

`variants[].shapeInputs[]` (`TemplateVariantShapeInputResponse`: `id`, `name`,
`target` fill|stroke|both, `defaultColor`, `colorOptions` — designed colour
first —, `freeColor`, `frame`, `layerIndex`) is built straight from the canvas
via `ShapeColorInput` — there is no column. Every render body (`ExportRequest`,
`GroupExportRequest`: variant preview/export, group preview/ZIP/PNG) takes
`shapeColors` (shape inputId → `#rrggbb`), resolved STRICTLY in
`ApiExportRenderer` (`ResolveShapeColorOverrides`, 400 `invalid_shape_color` /
`shape_color_not_allowed` + `allowedColors`, tagged `shapeInputId`); a group
passes the same map to every member. Versions record it
(`ExportFillValues::fromApiRequest(…, $shapeColors)`) and
`GET /api/export-versions/{id}` returns it as `fill.shapeColors` (`{}` when
empty). MCP mirrors all of it: `describe_variant.shapeInputs[]`,
`render_variant` / `export_variant` `shapeColors`, DSL shape `userColor`.

### Print PDF over the API + MCP (2026-10-30)

`ExportRequest` / `GroupExportRequest` carry an optional `format`
(`"png"` default | `"pdf"`); `ApiExportRenderer::renderExport()` routes PDF
through `Services/Print/PrintPdfRenderer` with STRICT container overflow, like
every API render. Print formats only (`TemplateDimension::isPrintFormat()`):
a px variant → 400 `pdf_not_available` (`ExportErrorResponse`), an unknown
value → 400 `invalid_format`; the group PDF ZIP filters to the print members.
The listing exposes `printFormat`, `bleedMm`, `cropMarks`; a background slot's
`frame` is `PlaceholderFrame::backgroundCover()` — negative origin by the
bleed. MCP mirrors it: `export_variant` `format: "pdf"` returns an
`EmbeddedResource` blob (`wboost://exports/{id}.pdf`), the variant/context
dimension responses carry `printFormat`/`bleedMm`/`bleedPx`/`cropMarks`
(kept field-for-field identical, `GetProjectTemplateStats` groups by them),
and the design pipeline knows the bleed (`CompilationContext::withBleed()` in
`DesignPreflight`, `DecompilationContextFactory::forVariant`): the compiler's
background covers the bleed box, the decompiler's cover check expects it, and
`DesignLinter::checkBounds` tolerates artwork inside the bleed.


### Multi-page documents over the API + MCP (2026-10-30)

`src/Api/TemplateDocuments/`:
- `TemplateDocumentResponse` carries every CRUD operation: `GET` collection /
  item via `TemplateDocumentsProvider`; POST / PATCH / PUT pages / POST page /
  PUT fields / DELETE via `TemplateDocumentWriteProcessor`, which dispatches
  the web's own messages and maps `InvalidTemplateDocumentChange` → 400
  `{error, code: reason, templateId?}`.
- `TemplateDocumentRenderResource` holds the page preview + whole-document
  export (`TemplateDocumentRenderProcessor`).
- Pages are addressed by `pageId` (the API also accepts the page's templateId
  leniently). Each page's fill is in the API shape with REAL inputIds;
  `DocumentApiValues::forPage` applies the shared fields. Every page is
  resolved strictly (`ApiExportRenderer::resolve`, now public) BEFORE any
  render; `TemplateDocumentExporter` writes the file.
- `ApiSubjectLoader::document()` / `writableDocument()` / `documentPage()`:
  - VIEW vs EDIT voter checks.
  - A document the caller cannot see answers 404.
- The page variant shape is `TemplatesProvider::buildVariant()`, now public.
- `TemplateResponse::$document` (`TemplateDocumentRefResponse`) is additive.
  Page templates are still listed.

MCP:
- `find_templates` reports `documents[]` (`DocumentPresenter`) plus a
  `documentId` on page templates.
- `manage_document` (`templates:design`; actions: create, rename, set_pages,
  add_page, set_fields; no delete).
- `export_document` (`templates:export`):
  - Strict, via `VariantFill` per page.
  - The print PDF is capped at 8 pages, for the chat-client time budget.
  - A file over 25 MB is refused and the reply points to the fill URL.
  - The file comes back as an `EmbeddedResource` blob.
- Body parsing shared with the API: `Services/TemplateDocument/DocumentWriteInput`.
