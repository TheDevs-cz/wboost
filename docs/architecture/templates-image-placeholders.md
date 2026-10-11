# Templates — image placeholders

### Image placeholders

The image counterpart of text inputs (mirrors the same architecture). A designer
marks a canvas image as a fillable **placeholder** (custom prop
`imagePlaceholder`), sets per-slot limits (`allowMove`/`allowResize`/`allowRotate`,
`hidable`) and toggles which gallery folders feed it (`allowedDirectoryIds`); these
persist as an `EditorImageInput[]` JSONB column `image_inputs` on
`template_variant` (via `EditorImageInputsDoctrineType`), alongside
the textbox `inputs`.

**`allowedDirectoryIds` semantics — empty = UNRESTRICTED (the WHOLE gallery:
every project folder PLUS the gallery root), never "none".** An explicit
allow-list can only name folders, so picking any folder implicitly excludes the
root. The single source of truth is `PlaceholderAllowedDirectories`
(`resolve()` / `resolveIds()` / `includesRoot()` / pure static `effectiveIds()`),
used by **all four** interpretation sites — `PlaceholderImageUploader` (upload
target), `ResolveImageOverrides` (render-time validation), `VariantFiller` (web
pick list + `canUpload` flag), `PlaceholderGalleryProvider` (API pick list) — so
they can never disagree. The admin editor warns the designer when they leave a
placeholder's folders empty ("uživateli bude otevřená celá galerie"). The only
remaining dead end is a restricted slot whose every picked folder was deleted.

**Upload target is the UPLOADER'S choice (web + API), never an arbitrary
fallback.** `PlaceholderImageUploader::resolveTargetDirectory` resolves an
omitted `directoryId` only when unambiguous: a restricted slot with exactly one
folder → that folder; an unrestricted slot → the gallery root (null directory);
a restricted slot with several folders → 400 (the caller must choose). The web
fill page renders a per-slot folder `<select>` (inside `data-live-ignore` so the
choice survives Live re-renders) whenever more than one target exists, and the
API exposes resolved `directories` ({id, name}) + `includesRoot` on each
`imageInputs[]` entry so consumers can render the same choice. The upload
response's `directoryId` is null for root uploads.

- **Render core**: `ResolveImageOverrides` validates the fill (the chosen `imageId`
  must be a `FileUpload` in one of the slot's allowed folders; move/resize/rotate
  are 400'd when the slot forbids them), inlines the picture as base64 and reads its
  natural size; `ImagePlacement` (a pure, unit-tested helper) computes the
  object-contain fit + `scale`/`offset`/`rotation` into an absolute Fabric transform
  plus an `absolutePositioned` `clipPath` rect = the designer's frame. The renderer
  bakes this into the canvas JSON in `buildCanvasJson` — and now inlines **all**
  canvas image srcs (decorative + stand-ins), not just the background, so headless
  Chromium never reaches Minio. The headless Twig template needs no image-specific JS.
- **API**: `POST /api/template-variants/{id}/export` accepts an `images` map keyed
  by `imageInputId` — either `"<fileId>"` (centered object-contain) or
  `{ imageId, scale, offsetX, offsetY, rotation }` / `{ hide: true }`. The
  templates listing exposes `variants[].imageInputs[]` (`id`, limits,
  `allowedDirectoryIds`, `frame`, `defaultImageUrl`).
  `GET /api/template-variants/{variantId}/placeholders/{inputId}/images` lists a
  slot's pickable images; `POST` (multipart) to the same path uploads one into an
  allowed folder (both with the deprecated custom/social path aliases). Both
  upload paths (OAuth API + web session) share `PlaceholderImageUploader`.
- **Web fill (hybrid, z-order preserving)**: `Template:VariantFiller` renders
  the design BELOW the lowest image placeholder as the server **backdrop**
  (placeholders hidden, background included) and floats the chosen pictures as
  live Fabric objects (`variant_image_fill_controller.js`) the user moves/resizes/
  rotates within the limits, clipped to the frame. **Arrow keys nudge the selected
  picture by 1 canvas px** on BOTH fill pages (2026-09-03, the editor's
  `moveSelectedObject` semantics: per-axis `lockMovement*` respected, inert while
  a fill field / WYSIWYG has focus or a picker modal is open). The single page
  listens on `document` (Fabric's upper canvas is not focusable) and fires
  `object:modified` so the hidden fields + overlay box follow; the group page's
  ghost box converts the px step to a ratio of THAT dimension's frame and takes
  focus on pointerdown — cancelling pointerdown cancels focus-on-press, so
  before that the box was Tab-only and arrows scrolled the page after a click.
  Design content the admin stacked
  ABOVE a placeholder (locked image, title over a photo) renders as transparent
  **overlay slices** — one per placeholder gap that actually holds content
  (`AbstractVariantFiller::overlaySlices()` → `CanvasSlice` → the renderer's
  `sliceCanvas`) — that the controller paints directly over that placeholder's live
  object; `_restack()` keeps placeholders + overlays in designed stack order
  (`layerIndex` in the placeholder payload). Slicing suppresses out-of-range objects
  via `opacity: 0`, NEVER `visible: false` (invisible objects fall out of the
  positional textbox binding and container membership → per-slice reflow drift), and
  stubs sliced-out image srcs (headless Chromium can't reach Minio). Placements
  mirror into hidden `images[<uuid>][...]` form fields so the plain download POST
  drives the same server render. The canvas + placement fields live in a
  `data-live-ignore` subtree; the backdrop + overlay sources are re-read from
  Live-updated elements via `MutationObserver`. The export is **always** the full
  (un-sliced) server render, so the PNG is authoritative regardless of the live
  preview.
