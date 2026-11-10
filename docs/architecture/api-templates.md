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

**Whole-container hide (2026-11-09).** The same `inputs` map hides a
`hidable` container when keyed by the CONTAINER id: `{"<containerId>":
{"hide": true}}` (listing: `variants[].containers[].hidable` + `name`).
`Services/SocialNetwork/ValidateContainerHides::strict()` runs first in
`ApiExportRenderer::resolve()` (+ MCP `VariantFill::texts()`): a container
entry must be exactly `{hide: bool}` (else `invalid_value`) and `hide: true`
needs `hidable` (else `container_not_hidable`, both with `containerId`).
Ids naming neither an input nor a container stay silently ignored — a group
passes ONE map to every dimension. Everything after that is the lenient
shared path (see templates-containers.md "Whole-container hide"): the
resolver collects the request, the renderer expands it. Versions record it
in `hidden` like any input hide, so `fill.inputs[<containerId>]` comes back
`{hide: true}` with no extra plumbing.

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
  page's exports. ZIP packing is `Services/TemplateGroup/GroupExportArchive`, names
  `Services/Template/TemplateExportFileNames` (see "Download file names" in
  `templates-render.md`), both shared with the web controllers. Members/ids/voters resolve through
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

### User-moved elements over the API (axis-move M5, 2026-11-09)

`variants[].movableElements[]` (`TemplateVariantMovableElementResponse`: `id`,
`kind` text|shape|image|container, `name` (designer's, nullable), designed
`frame`, `vertical` / `horizontal`, `limits` up/down/left/right, `range`
minX/maxX/minY/maxY, `memberIds`) is the fill page's
`FillMovableElements` rows over the EXPANDED variant — the same frames and
bounds the strict validation measures against, so a client clamping to
`range` is never refused. Bodies: `ExportRequest.offsets` (element id →
`{x, y}`), `GroupExportRequest.offsets` (variantId → element id → `{x, y}`,
per dimension like `placements`), document `pages[pageId].offsets`
(`DocumentApiValues` passes it through, page-only). `ApiExportRenderer`
resolves them with `ResolveElementOffsets::forVariant(…, lenient: false)`
(`resolveOffsets()`): 400 `unknown_element` / `move_not_allowed` (`reason`,
`axis`) / `offset_out_of_range` (`axis`, `min`, `max`, `value`) /
`invalid_value`, all with `elementId`. The group export resolves EVERY
member's map up front (`resolveGroupOffsets()`, 400s tagged `variantId`;
non-member keys ignored) because the version stores all dimensions.
Versions store the canonical resolved maps (`withOffsets`, per variant /
per page variant, absent when nothing moved → old hashes unchanged);
`GET /api/export-versions/{id}` answers `fill.offsets` (variant: id → xy;
group: variantId → id → xy; `{}` when empty) and document versions
`fill.pages[pageId].offsets`, re-clamped by the seeders. MCP mirrors it
(see "Repeatable rows + moves over MCP" below).

### Repeatable rows over the API (container-rows P5, 2026-11-09)

REST first; MCP mirrors it (below), mfkfm follows. One service,
`Services/Template/ApiRepeatableRows`:

- **`describe(variant)`** — every repeatable row of the EXPANDED variant:
  parent, fillable members of the TEMPLATE row (unlocked texts,
  non-background image slots, recolourable shapes, hidable containers of the
  row tree — the row itself included; `kind` text|image|shape|container) and
  per slot (0 = template row) the id each member carries. Slot ids are READ
  off the expanded design by pairing the template row's tree with slot k's
  tree positionally (slot definitions keep the template's member order) —
  never re-derived — so a P6-merged row's legacy ids (`repeat.slots`) show
  through. Listing: `variants[].repeatableRows[]`
  (`TemplateVariantRepeatableRow{,Member,Slot}Response`), `containers[].repeat`
  + `fitsRows` (same values + key order as MCP `describe_variant` —
  `DescribeVariantTest` compares them, a repeatable fixture included) and
  `TemplateResponse.group.repeatableRows[]` (`TemplateGroupRepeatableRowResponse`:
  the smallest member's `fitsRows`). Document pages get it via `buildVariant`.
- **`apply(variant, rows, inputs, images, shapeColors)`** →
  `Value\AppliedApiRows` — the body's `rows` (row id → LIST of row objects
  keyed by template member ids, length = count) moved onto the slot ids in
  the ordinary maps + `counts` + `origins` (slot id → row / index / template
  id). Strict 400s: `not_repeatable`, `too_many_rows` / `too_few_rows`
  (`count`, `min`, `max`), `unknown_row_input`, `ambiguous_row_value` (any
  slot id of a SENT row also present in the matching map — Jan's decision
  11), `invalid_value`; all `containerId`. `ApiExportRenderer::resolve()`
  applies it unless handed an `AppliedApiRows` (callers that record the
  version apply first), feeds `withRowCounts($counts)` and re-tags resolver
  refusals through `AppliedApiRows::tag()` (id → the template id sent, +
  `rowContainerId`, `rowIndex`). No `rows` → maps untouched, every row at
  its default (backward compatible) — unless the maps address a row's slot
  k ≥ 1 by id (a P6-merged row's legacy ids): `apply` then adds
  `RepeatableRows::inferredCounts` (≥ k+1) to `counts`, for rows the body's
  `rows` does not name too, so the render and the recorded count agree.
- **Groups:** `assertKnownInGroup()` (a key no member carries →
  `not_repeatable`), each member applies with `groupMember: true` (skips row
  ids it does not carry at all), `ApiExportRenderer::groupMemberValues()`
  layers `placements` AFTER the rows so a placement may address a row
  picture's slot id; `applyToGroup()` unions every member for the version.
- **Documents:** `pages[pageId].rows` (page-only; `DocumentApiValues` passes
  it raw), applied per page before the strict resolve.
- **Versions:** the processors record the APPLIED maps (slot-id space, like
  the web fill) + `withRows(RepeatableRows::normalizeCounts(...))` (every row,
  defaults included — the web forms' shape; empty for a design without rows,
  so old hashes hold). `ExportVersionApiView` folds them back
  (`ApiRepeatableRows::fold()`, the inverse of `apply`): `fill.rows` (row id →
  one object per shown row; slot values removed from the flat maps, unused
  slots dropped) so a loaded fill posts back as-is. Document versions fold
  (`DocumentExportVersionApiView::fill(foldRows: true)`) on the REST path
  and in the MCP document tools alike.

### Repeatable rows + moves over MCP (2026-11-10)

The MCP half of P5 + M5 — same contract, same codes, one implementation:

- **`VariantFill`** (shared by `render_variant`, `export_variant`,
  `export_document`): `rows()` = `ApiRepeatableRows::apply()` on the
  expanded variant (counts = explicit `rows` + the legacy slot-id inference
  on top), `texts()` / `withShapeColors()` / `images()` take the
  `AppliedApiRows` and re-tag refusals through `tag()`, `withOffsets()` =
  `ResolveElementOffsets::forVariant(…, lenient: false)`. Every
  `InvalidExportValue` / `InvalidRichTextValue` becomes a tool error
  `"<REST message> (<code>) Details — key: value; …"` (`VariantFill::refusal()`).
  Order: rows → texts → shape colours → offsets → images (REST's).
- **Tools:** `render_variant` / `export_variant` take `rows` + `offsets`;
  `export_document` takes them per page (`pages[pageId].rows|offsets`,
  page-only, through `DocumentApiValues`). MCP has no group tool, so no
  per-dimension offsets / shared group count here.
- **Versions:** `export_variant` records like `ExportProcessor` (applied
  maps + `normalizeCounts` + offsets under the variant id);
  `export_document` and `document_history` "save_draft" like the REST
  document export (`DocumentFillValues::fromApiPages($values, $offsets,
  $rowCounts)`; a draft applies `rows` strictly — they must land on slot
  ids — but stores `offsets` unvalidated). `document_history` "get" and the
  `versionId` base read `fill(foldRows: true)`; `DocumentFillInput::resolve`
  merges `offsets` per element id and `rows` per row container id (the whole
  list) over the stored fill.
- **`describe_variant`:** `repeatableRows[]` + `movableElements[]`, field
  for field the REST listing's (`VariantRepeatableRowResponse`,
  `VariantMovableElementResponse`; `DescribeVariantTest` compares them).
  `VariantFill::warnings()` reads the EXPANDED variant (a merged row's
  legacy slot id is not reported as unknown).
- Tests: `tests/Mcp/Tool/FillRowsAndOffsetsTest.php`; the MCP tools left
  `ElementOffsetsEntryPointsTest::DELEGATES`.

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
- **Print setup validation (2026-11-10)**: `DocumentWriteInput::printSettings()`
  is the ONE rule for API create / PATCH and MCP create / `set_print`: `bleedMm`
  a number from 0 to `TemplateDimension::MAX_PRINT_BLEED_MM` (10; refused,
  never clamped: `invalid_bleed`), `cropMarks` a bool (`invalid_crop_marks`), a
  real setup on px / preset → `print_setup_not_available`
  (`Exceptions\InvalidPrintSetup`; the API maps it to
  `InvalidTemplateDocumentChange` → structured 400, MCP to a tool error
  naming the code). On create an omitted `bleedMm` is 5 for a print format and
  0 for px; an omitted `cropMarks` is false. On update, absent keeps the value.
- **Standalone variant print setup (2026-11-09)**:
  `PATCH /api/template-variants/{variantId}/print-setup`
  (`Api/Templates/TemplateVariantPrintSetup{Request,Response,Processor}`) and
  MCP `set_print_setup` (`Mcp/Tool/SetPrintSetupTool`, `templates:design`)
  both go through `Services/Template/VariantPrintSetup`, which dispatches the
  web's `EditTemplateVariantPrintSettings` (whose handler re-fits the background
  through `ApplyPrintSettings`). An absent key keeps its value, both absent →
  `missing_print_setup`; validation = `DocumentWriteInput::printSettings()` +
  `print_setup_not_available` (px — the web modal does not exist there either)
  and `document_page` (+ `documentId`: pages follow their document,
  `TemplateDocumentPageList::applyPrintSetup`). Access: API
  `ApiSubjectLoader::variant()` + `TemplateVariantVoter::EDIT` (403); MCP
  `DesignVariants::editable()` — NOT `writable()`, so grouped variants are
  accepted and change only themselves, exactly like the web form (a group
  has no print setup of its own; every member dimension carries its own).
  The request DTO's field is `mixed` so a wrong type reaches the structured
  `invalid_crop_marks` instead of the serializer's 400.
