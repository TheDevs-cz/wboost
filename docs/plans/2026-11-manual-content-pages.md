# Manual content pages (editable intro + custom pages) — plan 2026-11-09

**Status: DELIVERED 2026-11-09 (all phases live on prod).** Current truth is
`docs/architecture/manuals.md` (content pages P1–P4 sections) and
`gallery-and-uploads.md`; this file is history.

- P1 engine + intro — 37358497, 218f6cce, 9a3cee29
- P2 custom pages — c8094641
- P3 gallery migration — 63dc3f44
- P4 cleanup — c6d64df6 (form upload removed, `manual.intro_image` dropped,
  trashed-only pages skipped for readers, mockup slot uploads normalised);
  WEB-4W S3-429 hardening of `ReadStoredImagePixelSize` alongside

Feature request (Jan, 2026-11-09): the manual intro (first) page can today only
hold one image (`manual.intro_image`, uploaded in the add/edit manual form,
rendered as page heading + italic description + `<img max-width:100%>`).
Make it editable like mockup pages: pick a layout, every layout segment holds
several elements, WYSIWYG editing — elements = heading, rich text (ProseMirror),
image. Also let admins add **custom content pages** with the same engine.

Prod snapshot 2026-11-09: 34 manuals, 18 with an intro image, 154 mockup pages.

## Decisions (agreed with Jan)

| Topic | Decision |
|---|---|
| Geometry | Reuse the mockup 3×2 grid + the 11 `MockupPageLayout` layouts (slots, picker mini-previews), but **auto height**: rows grow with content, text never overflows. Images default to `fit: natural` (own aspect ratio, full slot width) — migrated intros look exactly like today; `cover` is an opt-in per image. |
| Scope | Generic engine: `ManualContentPage` with `role` = `intro` / `custom`. Intro always first, exactly one per manual (created lazily or by migration). Custom pages shipped now. |
| Placement | Custom content pages + mockup pages share **one sortable list** (the zone where mockup pages live today, after the built-in logo/colour/font pages). Intro is pinned first. Built-in pages keep their fixed order. |
| Page heading/description | The intro's page heading + description (`ManualPage::Intro` page texts, admin overrides) are **replaced by elements**: migration turns them into a heading element + rich-text element. A page never edited starts with the CURRENT defaults (default heading "Úvod"-equivalent + default description) as elements, so untouched manuals look the same. Each content page also carries a plain `name` (page numbering, listing, nav) separate from its content. |
| Elements | `heading {level 1–3, text}`, `richText {doc: ProseMirror JSON}`, `image {galleryImageId (+ path snapshot), fit: natural|cover, alt}`. Text restricted to the manual's fonts and the project palette colours. |
| Rich text storage | ProseMirror JSON doc (never HTML). Server renders it via a whitelist PHP renderer (nodes/marks allow-list → XSS-safe). Same Twig partial for editor preview and public manual. |
| ProseMirror delivery | ONE pre-built ESM bundle committed to the repo (NOT ~10 importmap pins re-fetched from jsDelivr each build — see memory on importmap vendor non-determinism). Document how to rebuild it. |
| Images source | Project gallery picker + upload (reuse the gallery modal used by templates). |
| Gallery migration | All existing intro images AND mockup page images become gallery images in folder **`Manuál / <manual name>`** per project. Gallery rows REFERENCE the existing storage keys (no copy). Mockup pages keep their slots/attachments; their editor gains the gallery picker. Attachments (PDF etc.) stay as they are. **Purge guard must cover manual references** (purging Koš must never delete a key a manual/mockup still uses). |
| Storage index | Every new path-storing column/JSON goes into `BuildStorageReferenceIndex`. |
| API / MCP | Out of scope. |

## Phases (each: tests + PHPStan green, commit+push main, CI green, deploy confirmed on lily)

- **P1 — engine + intro.** Entity + migration, renderer, editor page (layout picker,
  segments, "+ přidat prvek", SortableJS drag within/between segments, ProseMirror
  toolbar: bold/italic/underline, lists, link, manual font, palette colour), public
  render in `manual_preview.html.twig`. Data migration: each manual gets an intro page;
  `intro_image` → layout-7 single segment with heading + description elements (from
  page-text overrides or defaults) + image element (path-based until P3). pg_dump on
  prod before deploy. Intro upload in the manual form keeps working until P4 (or is
  redirected to the editor — agent's call, document it).
- **P2 — custom pages.** "Přidat stránku" next to mockup pages, delete, mixed sort
  with mockup pages (shared `position` space), numbering/nav in the manual render.
- **P3 — gallery migration.** One-off console command (dry-run first) creating
  `Manuál / <manual name>` folders + gallery rows over existing keys for intro +
  mockup images; image elements switch to `galleryImageId`; mockup editor gets the
  picker; purge guard extended. Backup, dry-run, run on prod, verify.
- **P4 — cleanup.** Remove intro upload from the manual form, drop
  `manual.intro_image` (backup first), update docs (`manuals.md`,
  `gallery-and-uploads.md`, `storage-inventory.md`).
