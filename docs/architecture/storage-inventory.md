# Storage inventory + project storage deletion

### Storage inventory — admin usage/orphan report

`/admin/usage` carries a **Úložiště** section (bytes per project, rolled up per
client) plus a file drill-down at `/admin/storage/files`. Both are
`#[IsGranted(User::ROLE_ADMIN)]`.

File types are **columns on each client/project row**, not a separate global
by-type table — the per-project split is what says *why* a client is big, which
one merged "all clients by type" table cannot. The column set is derived from
the data (only categories actually present get a column, ordered by total size
desc), the same way the usage table above derives its month columns; cells link
through to the file list pre-filtered by project + category. Objects whose
project is gone render as one "Bez projektu" row carrying the identical
breakdown (`StorageOverview::$unattributed`), never folded into a client.

It is backed by `storage_object` — one row per object that actually exists in
the Minio bucket, **derived data rebuilt by a scan, never written at upload
time**. That is the whole point: an inventory built from what the app believes
it wrote could not surface the objects it forgot about, and those dominate
(deleting a project/manual/template has never removed its files, and every
`Edit*` handler that re-uploads writes a new timestamped key and abandons the
old one — on the dev mirror that is ~70 % of all bytes).

**Storage keys survived the templates merge UNMOVED.** Former-social files stay
under `social-networks/…` forever (merged canvases still embed those URLs);
the unified template handlers write `custom-templates/…`. Consequences:
`BuildStorageReferenceIndex` keeps BOTH prefixes in its `PATH_PREFIXES` regex,
`CollectProjectStoragePaths` emits both key families per template/variant, and
`StorageCategory` maps `custom-templates/` → `Template` (`'template'`, the
folded discriminator — same fold as `export_event.template_type` /
`ExportedTemplateType`) while the `SocialNetwork` (`'social_network'`) case
survives purely for the untouched `social-networks/…` keys and historical rows.

- `ScanStorage` (`src/Services/Storage/`) lists the bucket and cross-references
  `BuildStorageReferenceIndex`, which enumerates **every** column that stores a
  path: plain strings (the legacy `manual.intro_image` is gone since content
  pages P4 — the intro picture is a content page image element), the JSON
  documents (`manual.logo`,
  `manual_mockup_page.images`, the image elements of
  `manual_content_page.segments`, `manual_content_page.background`, `font.faces`),
  the calendar uploads and PDF exports (`calendar_asset.path`, `calendar_asset.preview_path`, `calendar_export.path` —
  see "Calendars" below), the canvas JSONB **and the e-mail
  signature `code`** — the last two EMBED paths inside full public URLs rather
  than storing the key, so they are matched by storage-prefix regex (the base
  URL is environment-specific; the prefix is the stable anchor). **A writer
  missing from that list makes a live file look like an orphan** — add new ones
  there. The signature `code` case was found the hard way, by diffing a prod
  scan against a full `pg_dump`; if you ever act on the orphan list in bulk, do
  that diff first rather than trusting the flag.
- **Gallery rows may point at manual keys** (content pages P3): the manual
  picture import made `file_upload` rows whose `path` is a
  `manuals/{manualId}/…` key (and new manual uploads do the same), so such
  objects are referenced twice (manual column + `file_upload.path`) — the
  `×N` count is expected, and `StorageCategory` still files them under the
  manual. Purging the gallery row never deletes a key a manual or another
  row still uses (see gallery-and-uploads.md).
- **Calendars** (module Kalendáře, 2026-11): every upload is a `calendar_asset`
  row with `path` = `calendars/{calendarId}/{assetId}.{ext}` and, for a raster
  whose long edge exceeds 1600 px, `preview_path` =
  `calendars/{calendarId}/{assetId}-preview.webp`. The calendar's JSON (`layout`,
  `content`) refers to assets **by id only**, so those two columns are the whole
  reference set — no JSON walk. `calendars` is in `PATH_PREFIXES`,
  `StorageCategory::Calendar` (`'calendar'`, "Kalendář") files the keys,
  `ResolveStorageOwnerByPath` resolves the calendar id to its project, and
  `CollectProjectStoragePaths` emits one `calendars/{calendarId}` directory per
  calendar. Deleting a calendar removes that directory
  (`DeleteCalendarHandler`, same order as a project: remove + flush, then
  storage). An asset is never deleted on its own — a replaced photo stays
  until its calendar goes, **referenced** (so it is not an orphan in the
  report, just unused). A layout import copies the pictures it needs into the
  target calendar under new ids.
  **PDF exports** (`calendar_export.path` =
  `calendars/{calendarId}/exports/{40 hex}.pdf`, registered in
  `BuildStorageReferenceIndex`) lie inside the same directory, so the owner
  lookup, the project collection and the calendar deletion cover them
  unchanged. Only the newest six exports of a calendar are kept
  (`CalendarPdfExporter::prune`: rows first, then files — a failed file
  delete leaves an orphan the report shows, never a row without its file). A
  print export of a full year is 150–500 MB: these are the largest objects
  the app writes.
- Unreferenced objects are still attributed to a project by
  `ResolveStorageOwnerByPath`, which pulls the first UUID out of the key and
  looks it up across every namespacing entity. Objects whose entity is gone stay
  unattributed and are reported as their own bucket, never folded into a client.
- Transient by-products (`social-publish/`, `thumbnails/`) are inventoried but
  never flagged as orphans — they are unreferenced by design.
- Writes are UPSERTs on `path` followed by `DELETE WHERE scan_id <> :scanId`.
  The delete keys on a **per-run `scan_id`, not `scanned_at`**: that column is
  `TIMESTAMP(0)`, so two scans in the same second could not tell each other
  apart and stale rows would survive.
- Refresh: `app:storage:scan` (idempotent; the one-time backfill and the
  recurring job) or the "Načíst znovu" button. The scan **only reads** from
  storage — there is no delete action, because a copied template variant shares
  its source's background key (`reference_count > 1`, shown as a `×N` badge), so
  "delete what looks unused" is not safe to automate.
- The scan also reports **dangling references** — DB rows pointing at keys that
  are not in the bucket (broken images), the mirror image of an orphan.

**AI scan files are NOT in this inventory** (docs/architecture/ai-scan.md
§ "Storage"). They live in a second, PRIVATE bucket (`S3_PRIVATE_BUCKET_NAME`,
`oneup_flysystem.private_filesystem`) under
`ai-scan/{projectId}/{scanId}/{secret}/…`; `ScanStorage` lists the public
bucket only, and no column stores one of those keys (the key is derived from
the scan row: project id, scan id, `ai_scan.storage_secret`) — so
`BuildStorageReferenceIndex` has nothing to add for them. That bucket has its
own rules instead, in `AiScanPurge` (`app:ai-scan:purge`, nightly):

- a scan's files go when it is discarded (at once), or 30 days after it ended
  (`ai_scan.expires_at`; `accepted` scans keep their row and state, only the
  files go);
- a scan directory whose newest object is **older than 24 hours** and whose
  row is missing or `expired` is deleted — uploads whose transaction failed;
- deleting a project deletes `ai-scan/{projectId}/` there
  (`DeleteProjectHandler` → `AiScanStorage::deleteProject()`), after the
  public-bucket cleanup below.

One AI scan object does sit in the PUBLIC bucket: `ai-scan/stand-in-v1.png`,
the shared stand-in picture candidate renders use (`AiScanStandIn`; the
renderer inlines pictures by storage key). It is referenced by no row on
purpose — `StorageCategory::AiScan` files it and it is never flagged as an
orphan, like the other by-products.

**Deleting a project deletes its storage.** `DeleteProjectHandler` collects the
project's key namespaces via `CollectProjectStoragePaths` **before** removing
the row, flushes so the DELETE + cascades actually execute, and only then calls
`DeleteProjectStorage`. Order is load-bearing: most namespaces are keyed by a
CHILD entity id (`manuals/{manualId}`, `custom-templates/{variantId}` +
`social-networks/{variantId}`, …), not by the project id, so the cascade
destroys the only record of what belonged where — and an image can never be
referenced from another project, so there is no later chance to reattach it.
Two details that must not be "simplified": per-variant previews are deleted as
individual FILES because `custom-templates/preview/` and
`social-networks/preview/` are shared by every project, and an email-signature
template clears BOTH `emails/{id}/` and `manuals/{id}/` (the ADD and EDIT
handlers disagree on the prefix). Storage failures are logged and swallowed —
the row is already gone, so throwing would only turn a leaked file into a failed
request.
