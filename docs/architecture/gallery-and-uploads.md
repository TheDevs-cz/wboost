# Project image gallery, upload limits, format normalization

**Project image gallery — Live Component (Stage 7 → 8)**

`Project:ImageGallery` (`src/Twig/Components/Project/ImageGallery.php`) is the
per-project, per-`FileSource` asset library shown in the admin editor's "Add
image" / "Set background" modal. Image **selection** stays a DOM
`CustomEvent("asset-selected")` (with `{ url, path, id, name, width, height }`
— the last three nullable, added 2026-09-03) so the host Stimulus controller
routes the chosen URL to `addImageToCanvas` or `setBackgroundImage` without a
server round-trip.

**Tile caption — file name + pixel size (2026-09-03).** Every tile (modal,
standalone, Koš) carries an always-visible caption under the thumbnail: the
uploaded file name (base + extension in separate spans, so a long name
truncates in the middle and keeps its tail) and `1080 × 1350 px` with a format
badge (`PNG`/`JPG`/…; an SVG reads "Vektor"); the tooltip repeats it with the
upload date. Two look-alike pictures of different sizes were otherwise
indistinguishable. Backed by three nullable `file_upload` columns:
`original_name` (the client name, sanitised in `UploadFileHandler`; NULL for
uploads before the column — nothing to backfill it from, the tile says "Bez
názvu") and `width`/`height` (recorded at upload from the normalised bytes, so
a transcoded HEIC reports its JPEG size). Old rows get their size **backfilled
lazily on first sight** — `Services/Image/FileUploadPixelSizeBackfill` (a
bounded header read via `ReadStoredImagePixelSize`, persisted, never repeated;
an unreadable object stays NULL and is retried, no marker column) is called by
the gallery listings AND the MCP `list_gallery` tool (which no longer reads
headers itself) — plus `app:gallery:backfill-image-size` for a one-shot sweep
(run once after deploy; non-zero exit lists unreadable rows). The MCP
`GalleryImageResponse` gained `originalName`; `name` stays the stored basename
(= format). The caption fields are computed once in `ImageGallery::describe()`
(`name`, `nameBase`, `nameExt`, `sizeLabel`, `format`, `tooltip`).

Stage 8 added a **filesystem-like nested folder tree** on top:

- `FileDirectory` entity (`src/Entity/FileDirectory.php`) — nullable self-ref
  `parent` (null = root), scoped by `project` + `source`. `FileUpload` gained a
  nullable `directory` FK (`ON DELETE SET NULL`).
- Navigation/CRUD are **LiveActions** on the component (`openDirectory`,
  `openRoot`, `createDirectory`, `startRename`/`renameDirectory`,
  `deleteDirectory`, `deleteFile`, `moveFile`), each dispatching a CQRS message under
  `Message/Image/` (`CreateFileDirectory`, `RenameFileDirectory`,
  `DeleteFileDirectory`, `DeleteFileUpload`, `MoveFileUpload`). `$currentDirectoryId`
  is a (server-set) LiveProp. **Deleting a folder only removes an EMPTY folder** —
  a folder that still holds images or sub-folders is refused (the handler throws
  `FileDirectoryNotEmpty`; the component pre-checks and shows the transient
  non-LiveProp `$folderActionError` notice), so contents are never silently
  relocated or discarded; the user empties it first. **Deleting an image moves
  it to the Koš (trash bin)** — `deleteFile` → `DeleteFileUpload` sets
  `deleted_at`, DETACHES the file from its folder (remembered in
  `restore_directory_id`, SET NULL) and needs no confirm; the bin is a
  read-only special directory listed in EVERY folder, not just the root
  (2026-09-03 — a root-only card read as "this folder has no bin"; opening
  it keeps `currentDirectoryId` so the breadcrumb leads back), showing a
  per-file purge countdown with only Obnovit (`RestoreFileUpload` — back to
  the original folder, root when it's gone) and Smazat ihned
  (`PurgeFileUpload`, confirmed). Trashed images are invisible/unusable
  everywhere: every live listing in `FileUploadRepository` filters
  `deletedAt IS NULL` (load-bearing at the ROOT — detached bin entries would
  otherwise surface as root files), `ResolveImageOverrides` rejects trashed
  ids explicitly (the unrestricted-slot root branch would accept them), and
  `MoveFileUploadHandler` refuses to move a trashed file (a move would
  silently un-trash it). `PurgeFileUploadHandler` is the only place in the
  app that hard-deletes gallery storage (row + object). The purge cron is
  `app:gallery:purge-trash` (daily; retention =
  `FileUpload::TRASH_RETENTION_DAYS` = 7). **A picture a template still
  references is never purged automatically (2026-09-08).** Purging is the
  one gallery action that damages a design for good — the prod group whose
  every variant referenced a purged decorative picture could not even be
  opened for editing (see the editor's stand-in handling) — so
  `Query/GetGalleryImageUsage` scans ONE project's variants (canvas JSONB
  text + `background_image` + `inputs` text, substring-matched on the file
  UUID, which every spelling of a reference contains: public URL, storage
  key, `assetId`; export-version picks are deliberately NOT a reference —
  the seeder degrades leniently) and the handler throws `FileUploadInUse`
  unless `PurgeFileUpload::$force` is set. The cron never forces: such a
  file stays in the Koš, its tile says "Používá se v: <templates>" +
  "Automaticky se nesmaže" and the run reports it as skipped; the bin's
  "Smazat ihned" forces after a confirm that names the templates. Trashing
  itself stays harmless — a trashed picture still renders wherever a design
  references it; only fills reject trashed ids. **`#[LiveArg]`
  names must be lowercase** (e.g. `#[LiveArg('directoryid')]` / `#[LiveArg('fileid')]`)
  to match the HTML-lowercased `data-live-*-param`.
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
(`ProjectGalleryController` → `/project/{projectId}/gallery`, linked from the
left navigation as "Galerie" and from the templates module pages). A
`bool $modal` LiveProp (default `true`) toggles the modal header/close chrome
and the click-to-select image buttons; pass `:modal="false"` to render plain
thumbnails where folders + upload + move are the management surface.

The gallery is **project-wide** (one library shared by the variant editor, the
group editor and every fill surface): the `FileSource` enum case is
`ProjectImage = 'project_image'` (renamed from the original
`social_network_image`; the rename migration updated `file_upload.source` +
`file_directory.source` in place).

### Upload size limit — 10 MB, decimal

Every image upload is capped at **10 MB**, expressed server-side as
`maxSize: '10m'` on the `Image`/`File` constraint in the form types (14
occurrences across 9 `src/FormType/*` classes — manual images/logos, mockup
pages, template + variant backgrounds, template groups, email signatures,
the project gallery) plus the `Image` attribute on
`FormData/ProjectFormData::$icon` (the custom project icon).

**Symfony reads the `m` suffix as DECIMAL megabytes — 10 000 000 bytes, not
10 MiB.** Every mirror of the limit must use that same number or a file in the
gap is accepted by one layer and rejected by the next:
`gallery_uploader_controller.js` (`maxSize` Stimulus value),
`mockup_page_editor_controller.js` (fed by
`_mockup_page_form_editor.html.twig`), and
`PlaceholderImageUploader::MAX_FILE_SIZE_BYTES`.

A mockup page's downloadable ATTACHMENT is deliberately outside this limit —
it is not an image and a print PDF routinely exceeds 10 MB. It has its own
20 MB decimal cap (`ManualMockupPageFormType::DOWNLOAD_MAX_SIZE`), mirrored by
`_mockup_page_form_editor.html.twig` into `mockup_page_editor_controller.js`.

The placeholder upload endpoints (web + API + group fill, 3 controllers —
`TemplateVariantPlaceholderUploadController` (API),
`TemplateVariantPlaceholderUploadWebController`,
`TemplateGroupPlaceholderUploadController`) take the raw `UploadedFile` off
the request with no form behind them, so the cap lives in
`PlaceholderImageUploader::upload()` — the single chokepoint they all share —
and surfaces as a `400`. Documented in `docs/api/consumer-prompt.md`.

PHP/Caddy allow 50 MB (`upload_max_filesize`/`post_max_size` come from the
`ghcr.io/thedevs-cz/php` base image, not this repo), so the app-level limit is
the only thing in play.

### Gallery uploads are format-normalized (`NormalizeImageFormat`)

A gallery picture has to survive THREE readers: `getimagesizefromstring()` (the
natural-size read behind every placeholder fill), a browser `<img>` (thumbnails,
the fill page's live Fabric preview) and Gotenberg's headless Chromium (the
export). PNG / JPEG / GIF / WebP pass all three; **HEIC/HEIF — the default
iPhone camera format — passes none of them** (PHP sizes a real iPhone capture to
`false`, Chrome paints nothing), which shipped as a broken thumbnail plus a 400
"could not be read or is not a supported raster image" at export time.

So `UploadFileHandler` — the single chokepoint behind BOTH the gallery form and
the placeholder upload endpoints — runs every upload through
`Services/Image/NormalizeImageFormat`: web-safe rasters pass byte-for-byte,
anything else Imagick can decode is transcoded (PNG when it has alpha, else
JPEG q90, EXIF orientation baked in and the ICC profile preserved), and the
stored extension always describes the BYTES, never the client's file name. SVG
is deliberately refused by the normalizer and stored untouched — it stays vector
everywhere (logos, backgrounds). `AssetInliner::inlineImageWithDimensions` runs
the same normalizer at render time, so files uploaded before this (and any other
format Chromium can't paint) still export.
