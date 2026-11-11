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
(= format). The caption fields are computed once in `Services/Image/GalleryImageCaption`
(`name`, `nameBase`, `nameExt`, `sizeLabel`, `format`, `tooltip`) and rendered
by the shared `_gallery_asset_caption.html.twig`.

**Pictures are renamable (2026-10-13).** Every live tile (standalone + both
editor modals — same component) has a pencil next to the move-to-folder
button; standalone, clicking the caption itself does the same. LiveActions
`startFileRename` / `renameFile` / `cancelRename` (Enter submits the inline
`<form>`, Escape cancels; the `image-gallery` controller focuses + selects the
morphed-in input via its `renameInput` target, which the folder rename uses
too) dispatch `RenameFileUpload` → `FileUpload::rename()`. Only the name base
is editable: the extension of the current name stays as a fixed suffix (a
"Bez názvu" upload gets the stored format's, `.png`), a typed duplicate
extension is not doubled, a blank name is ignored, and a trashed file is
refused (the bin is read-only). It is purely a label — `path`, the public URL
and every template reference stay put, so nothing else needs updating; every
surface that shows the name (fill pickers, `asset-selected`, MCP
`list_gallery`) reads it from the row.

**The fill pages' image pickers show the gallery tile 1:1 (2026-10-11).** Both
the single-variant and the group picker render every picture through
`_fill_gallery_tile.html.twig` (square thumb + the same caption; the grid CELL
is the `fill-gallery` thumb target, the inner button carries the page's pick
wiring). Their image lists (`AbstractVariantFiller::allowedImages`,
`GroupFillPlaceholders::allowedImages`) carry the caption fields and run the
lazy size backfill too. A fresh upload is cloned from the same partial's
`__KEY__` placeholder version, so the two WEB upload endpoints return
`PlaceholderImageUploader::uploadWithCaption()` (`caption` object added); the
OAuth API upload keeps `upload()`'s shape — the public contract did not move.

**Thumbnail size is one per-browser preference (`localStorage['wb-galsize']`,
2026-11-05).** The gallery page and the `Project:ImageGallery` picker modal
(`gallery-filter`) and the fill pickers (single / group / document,
`fill-gallery` `size` target, slider at the end of the `.fi-gnav` crumb row)
all read and write it and set it as `--galmin` on their grid; `fill-gallery`
also broadcasts `wb:galsize` so a page's other pickers follow live. The
slider (`.sz-ctl`) hides at ≤ 560 px, where the grids ignore `--galmin`.

Stage 8 added a **filesystem-like nested folder tree** on top:

- `FileDirectory` entity (`src/Entity/FileDirectory.php`) — nullable self-ref
  `parent` (null = root), scoped by `project` + `source`. `FileUpload` gained a
  nullable `directory` FK (`ON DELETE SET NULL`).
- Navigation/CRUD are **LiveActions** on the component (`openDirectory`,
  `openRoot`, `createDirectory`, `startRename`/`renameDirectory`,
  `startFileRename`/`renameFile`,
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

### Manual pictures live in the gallery (content pages P3, 2026-11-09)

The gallery is the single source of manual pictures. Per project there is a
root folder **`Manuál`** with a **sub-folder per manual** (the gallery has
real nested folders; a duplicate manual name gets " (2)", " (3)"…). Both are
app-maintained and found by `file_directory.system_key` (`manual-root`,
`manual:{manualId}`; unique per project + source), never by name — users may
rename them; an emptied + deleted folder is recreated on next use; an
existing user root folder literally named "Manuál" is adopted.
`Services/Manual/ManualGalleryFolders`.

- **Rows over existing keys, no copy.** `ManualGalleryImages::register()`
  creates a `FileUpload` whose `path` is the manual's own key
  (`manuals/{manualId}/…`) — idempotent by (project, path); a content key's
  UUID becomes the row id (resolvable from the key, like `file-upload/`
  names). So a gallery row's path is NOT always `file-upload/{projectId}/{id}.ext`
  any more: `GetGalleryImageUsage` matches template references by id OR
  key, and the purge handler deletes the object only when no other row
  names the same key.
- **Every manual upload registers**: content-editor upload
  (`UploadManualContentImageHandler`, answers `galleryImageId`), mockup
  slot uploads (`MockupPageSlotImages`, normalised since P4). The legacy
  intro upload of the add/edit manual form is gone (P4, together with the
  `manual.intro_image` column). Both manual editors also PICK from
  the gallery: `_manual_gallery_modal.html.twig` (`#manualGalleryModal`,
  rendered outside any form) mounts `Project:ImageGallery` with
  `currentDirectoryId` = the manual folder (created on editor GET via
  `ManualGalleryPicker` → `EnsureManualGalleryFolder`), so uploads made in
  the modal land there too.
- **One-off import** `app:manual:import-images-to-gallery [--dry-run]
  [--manual=<id>]` (`ManualGalleryImport`): content image elements (intro
  included) + mockup slots → rows in the manual folder, content elements get
  `galleryImageId`; keys missing in storage are skipped + listed; re-runs are
  no-ops (it re-plans after each manual to prove it). Attachments are not
  pictures and stay out. Run on prod 2026-11-09.
- **Purge guard — manuals.** `GetGalleryImageManualUsage` (exact match on
  key: mockup `images`, content element + page background `path` /
  `galleryImageId`; NOT
  project-scoped) → `PurgeFileUploadHandler`
  refuses with `FileUploadInUse::$manualSites` **even when forced**; the cron
  skips and names the manual; the Koš tile says "V manuálu: …", hides
  "Smazat ihned", and the LiveAction shows a notice if raced.
- **Trash = gone from the manual.** Unlike templates (where a trashed picture
  keeps rendering), a manual picture whose row is trashed (or, for a content
  element with `galleryImageId`, whose row is gone) is NOT shown: readers
  get nothing (empty slot / no element), editors a hatched stand-in
  (`_manual_image_standin.html.twig`, `manual_image_unavailable()` Twig fn,
  `ManualImageAvailability`, one query per manual + request, reset between
  requests). Restoring from the Koš brings it back — the object is never
  deleted while referenced. A content page left with nothing but such
  pictures is skipped for readers and takes no page number (P4); editors
  still see it with the stand-in.

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
`_mockup_page_form_editor.html.twig`), `manual_content_editor_controller.js`
(fed by `manual_intro_page.html.twig`; server side
`UploadManualContentImageController::MAX_SIZE`),
`PlaceholderImageUploader::MAX_FILE_SIZE_BYTES`, and the calendar module:
`UploadCalendarAssetController::MAX_SIZE` (`'10m'`) with its byte mirror
`CalendarDocument::MAX_FILE_SIZE` (`10_000_000`), which is both the server's
first check (`Soubor je větší než 10 MB.`, also for PDF special pages and
`.docx` / `.txt` documents) and the `env.maxFileSize` the calendar JavaScript
reads from its bootstrap JSON.

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
everywhere (logos, backgrounds).

Three rules of the normalizer every caller inherits (2026-11):

- **EXIF orientation.** A pass-through JPEG / PNG / WebP whose EXIF Orientation
  is not "upright" is the one exception to byte-for-byte: it is rotated and
  re-encoded in its own format (q95, ICC kept, metadata dropped) and the
  reported `width` / `height` are the rotated ones. Browsers honour the flag,
  `getimagesizefromstring()` does not — a portrait phone photo was recorded as
  4032 × 3024 and drawn sideways or squashed by whatever trusted that size.
  The check reads headers only and is skipped for bytes with no EXIF block.
  `DownscaleImage::toLongEdge` applies the orientation before it strips.
  Files stored before this keep their flag (no backfill); `AssetInliner` runs
  the normalizer at render time, so exports see them upright.
- **Text never reaches ImageMagick.** `NormalizeImageFormat::isText()` (leading
  `<`, or a sniffed `text/*` / XML / SVG / MVG / MSL MIME) answers null before any decode — ImageMagick *renders* an SVG (Inkscape
  on the server, following the file's references) and executes its script
  formats. The old sniff read 1 kB, so an SVG behind a long comment was
  rasterised. `looksLikeSvg()` (public, scans the whole file) is what callers
  use to tell "an SVG to keep" from "not a picture".
- **Pixel limit.** `ImageDecodeLimits::MAX_PIXELS` = 60 MP, read from the
  header (`ImageDecodeLimits::pixelSize`) before any decode: a transcode
  candidate above it is refused (null), an oriented one passes through
  unrotated. The calendar uploader refuses such a file outright (422) and also
  calls `ImageDecodeLimits::apply()` (ImageMagick memory 512 MiB / map 1 GiB /
  disk 2 GiB — **process-wide** once set, deliberately roomy). The gallery
  itself has no pixel refusal for PNG / JPEG / WebP: it never decodes them.

Still open on the shared path: PostScript / EPS / PDF bytes are not text, so
the normalizer hands them to ImageMagick (→ Ghostscript) like any other
"something Imagick can decode". The public calendar photo upload cannot reach
that (it allow-lists photo MIME types); the logged-in upload paths rely on
their form constraints. `AssetInliner::inlineImageWithDimensions` runs
the same normalizer at render time, so files uploaded before this (and any other
format Chromium can't paint) still export.

The manual uploads that do not go through `UploadFileHandler` call the same
normalizer themselves: the content-editor upload
(`UploadManualContentImageController`) and, since content pages P4, mockup
slot uploads (`MockupPageSlotImages`; undecodable bytes are stored as sent,
as before).
