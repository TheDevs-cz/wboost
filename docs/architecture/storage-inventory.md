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
  path: plain strings, the JSON documents (`manual.logo`,
  `manual_mockup_page.images`, `font.faces`), the canvas JSONB **and the e-mail
  signature `code`** — the last two EMBED paths inside full public URLs rather
  than storing the key, so they are matched by storage-prefix regex (the base
  URL is environment-specific; the prefix is the stable anchor). **A writer
  missing from that list makes a live file look like an orphan** — add new ones
  there. The signature `code` case was found the hard way, by diffing a prod
  scan against a full `pg_dump`; if you ever act on the orphan list in bulk, do
  that diff first rather than trusting the flag.
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
