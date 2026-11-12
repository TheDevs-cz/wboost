# Multi-page documents and print output

Contents: [Print setup](#print-setup-bleed--crop-marks) · [Multi-page documents](#multi-page-documents).
The field-level reference of `export_document`, `document_history`,
`manage_document` and `set_print_setup` is in `tools.md`.

## Print setup (bleed + crop marks)

Print formats (mm / cm) export a print PDF with a **bleed** ("spadávka" — how
far the artwork runs past the cut, 0–10 mm per side) and optional **crop marks**;
crop marks sit on the trim lines, each a fixed 3 mm from the trim and 5 mm
long (PDF page = trim + 2 × max(bleed, 8 mm) with marks, trim + 2 × bleed
without). The print PDF is PDF/X-1a in CMYK (Coated FOGRA39). Screen formats
(px, presets) have neither — a bleed > 0 or crop marks there are refused (`print_setup_not_available`), and an
out-of-range bleed is refused (`invalid_bleed`), never clamped.

- **Ask.** When the user wants something printed and has not said which bleed,
  ask them — suggest **3 mm with crop marks**, the common default.
- **Standalone variant:** `set_print_setup(variantId, bleedMm?, cropMarks?)`.
  A grouped variant changes only itself.
- **Document:** `manage_document` `create` with `dimension.bleedMm` /
  `cropMarks`, or `set_print` later. A document's pages follow it —
  `set_print_setup` on a page answers `document_page`.
- **Export:** `export_variant` `format: "pdf"` (one variant) or
  `export_document` `format: "pdf"` (a document, ≤ 8 pages from a chat).
- Edge-reaching artwork extends `dimension.bleedPx` past every canvas edge;
  the `background` element does it by itself.

## Multi-page documents

`find_templates` also returns `documents[]`: ordinary templates bound into ONE
ordered document (e.g. a 4-page price offer), one page size, exported as ONE
file. Each page carries its `variantId` — `describe_variant`,
`render_variant` and `set_design` work on it unchanged. Page templates carry a
`documentId`.

- **Fill + export:** `export_document(documentId, fields?, pages?, format?, versionId?, allowUnfilled?)`.
  - `pages` is keyed by `pageId`; each value is exactly `export_variant`'s body
    for that page's variant.
  - `fields` fills the document's **shared fields** (one value written into
    the bound input on every page).
  - **Pages never share values on their own**, not even duplicated pages with
    identical input ids. Only a shared field joins them.
- **Formats:**
  - `"pdf-screen"` (default): for sending.
  - `"pdf"`: print, CMYK, bleed + crop marks. mm / cm documents only, at most
    8 pages from a chat.
  - `"png"`: a ZIP.
- **Preview** single pages with `render_variant` first; `export_document`
  renders every page in full.
- **Arrange:** `manage_document(action, …)`, with these actions:
  - `create`: projectId, name, dimension, templateIds. **Print (mm / cm):
    if the user has not said which bleed, ASK** — suggest 3 mm with crop
    marks (`dimension.bleedMm: 3, cropMarks: true`). Without `bleedMm` a
    print document gets the default 5 mm and no crop marks. px has no
    bleed. `{"preset": "1:1"}` is retired — send px 1080 × 1080. Creating
    a document needs the designer role and the right to edit the project.
  - `rename`: name and / or category.
  - `set_pages`: the WHOLE ordered list; detaching keeps the template.
  - `add_page`: a blank page; its `variantId` is in the answer, design it with
    `set_design`.
  - `set_fields`: the WHOLE list of shared fields (`text` — any text input,
    value shaped by the field's `textMode` —, `image` or `shape`), at most one
    binding per page.
  - `duplicate_page`: a copy of a page (values stay per page).
  - `set_print`: bleed + crop marks for the document and every page.
  - There is no delete.
- **History:** `document_history(action, documentId, …)` — `list` the
  exports and drafts, `get` one with its fill, `rename`, `pin` / `unpin`,
  `save_draft` (stores a fill without rendering; give the user its `loadUrl`).
  Re-export a version with `export_document(…, versionId)` plus only what
  changes.
