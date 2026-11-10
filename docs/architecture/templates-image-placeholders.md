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

**Allowing a folder allows its WHOLE sub-tree, recursively (2026-11-09).**
`effectiveIds()` expands every named folder with all its descendants
(`GalleryFolderTree::expand`, pure + unit-tested), so ticking "Soupiska" also
opens "Soupiska/A tým/…" — including sub-folders created later. Ticking only
children still works and never opens the parent. Before this, the match was
exact-id (a parent did NOT include its children). Consequences, all through
the same rule:
- resolved lists (`resolve()` → web/API `directories`, MCP describe) come back
  in **gallery tree order** (folder, then its sub-tree; siblings
  alphabetical, as `listAll` returns them) and include the descendants;
  `allowedDirectoryIds` in the API stays the RAW designer list;
- **upload default** (`resolveTargetDirectory`, no `directoryId`): a
  restricted slot whose folders form ONE sub-tree (exactly one folder with no
  allowed ancestor) defaults to that top folder — a ticked parent stays
  unambiguous although its children are open too; several sub-trees → 400;
- **document shared image fields** intersect the bound slots' EFFECTIVE sets
  (`intersectIds()` / pure `intersectEffective()`), so a parent on one page and
  its child on another meet at the child's branch (raw id intersection gave
  `[]`). `DocumentFillPage::sharedImageInput` and the documents API
  (`fields[].allowedDirectoryIds`, now an expanded list) both use it.

**Admin editor tree** (`editor/_image_folder_branch.html.twig`, recursive;
controllers pass `gallery_directories` = `GalleryFolderTree`;
`canvas_image_properties_controller.js` "Složky galerie"). The stored
allow-list keeps only the TOP-MOST ticked folders: ticking a parent removes
its ticked descendants from `allowedDirectoryIds` and paints the whole
sub-tree ticked + disabled (`.is-inherited`, "zahrnuto" pill, tooltip
"Zahrnuto v nadřazené složce „X“"). The removed child ticks are parked in a
per-image `WeakMap` (editor session only, never saved — lost on reload or an
undo that rebuilds the object), so unticking the parent restores them. A
parent with only some ticked descendants shows the checkbox
`indeterminate` dash (display only). Branches are collapsed by default; on
each NEW image selection only the ancestors of ticked folders open (a ticked
parent itself stays collapsed); ticking a parent opens it. Legacy rows that
store a parent AND its child are shown correctly and normalised to the parent
on the next tick change. Ids of deleted folders drop out on the next change
(as with the old flat list).

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
  **Image adjust (2026-11-08)**: selecting a picked picture (click, or the
  box's "Upravit velikost a polohu" tool for keyboard/touch) opens a panel —
  the shared `_fill_image_adjust.html.twig` body driven by
  `image_adjust_toolbar.js` on BOTH fill pages (single: fixed `.fill-adjust`
  next to the picture's visible box, a bottom sheet under 768 px — anchored
  ONCE when it opens for a picture, then a palette that never re-anchors:
  slider steps, typed values, picture / handle drags, view pans, scroll and
  window resize only clamp it back inside the window (2026-11-09 fix: it
  re-anchored on every `object:modified`, so a growing picture pushed the
  panel — and the slider thumb — away from under the pointer); group /
  document: inside `.group-fill-placement-toolbar`, now window-aware so it may
  hang outside the small card instead of covering the picture). Controls:
  gallery-style zoom slider (`.sz-ctl`, 100 % = contain = "Přizpůsobit",
  range 25 % … max(400 %, 2× cover)), width/height (aspect locked) and the
  unrotated picture's top-left X/Y from the CANVAS origin in the variant's
  unit (mm/cm 300 DPI, px for screen formats; `image_placement.js`
  `placementToBox` and inverses), rotation, Vyplnit (cover + centre). Nothing new is stored: every
  control maps onto the same `scale`/`offsetX|Y`(`Ratio`)/`rotation` the drag
  writes, so render, export, history and drafts are untouched (API contract
  unchanged). Every resize — handles, slider, typed size — scales about the
  picture CENTRE, so the pan never drifts. On the single page Fabric's own
  controls are OFF (`hasControls:false`): they are painted inside the canvas
  bitmap, so a corner pushed past the canvas edge (the reported "can't grab
  the corner any more") was unreachable. `image_adjust_handles.js` draws the
  outline + corner/rotate handles in a fixed layer on `<body>`, clipped only
  to the viewport box + 10 px, and CLAMPS an off-screen corner onto that edge
  (`.is-clamped`) — the centre-anchored distance-ratio drag works the same
  from a clamped handle. While selected, the part of the picture outside its
  frame is painted faintly (Fabric `after:render`, never exported).
  Design content the admin stacked
  ABOVE a placeholder (locked image, title over a photo) renders as transparent
  **overlay slices** — one per placeholder gap that actually holds content
  (`AbstractVariantFiller::overlaySlices()` → `CanvasSlice` → the renderer's
  `sliceCanvas`) — that the controller paints directly over that placeholder's live
  object; `_restack()` keeps placeholders + overlays in designed stack order
  (`layerIndex` in the placeholder payload). Slicing suppresses out-of-range objects
  via `opacity: 0`, NEVER `visible: false` (invisible objects fall out of the
  positional textbox binding and container membership → per-slice reflow drift), and
  stubs sliced-out image srcs (headless Chromium can't reach Minio). The
  admin-set design **opacity** rides the payload too (`opacity`, 0…1) and
  `_replaceObject` applies it to whatever picture the slot shows — stand-in or
  the user's pick; the server keeps the object's own `opacity` through
  `ImagePlacement`'s merge, so a filled slot exports at the designed opacity
  (2026-11-08, `ObjectOpacityRenderTest`). Placements
  mirror into hidden `images[<uuid>][...]` form fields so the plain download POST
  drives the same server render. The canvas + placement fields live in a
  `data-live-ignore` subtree; the backdrop + overlay sources are re-read from
  Live-updated elements via `MutationObserver`. The export is **always** the full
  (un-sliced) server render, so the PNG is authoritative regardless of the live
  preview.

### Image slots inside containers (2026-11-09, container-rows plan P2a)

A fillable slot can be a container member (`canvas.containers[].memberInputIds`
names its `inputId`; the background slot never can). It is a FIXED box in the
vertical flow — moved by a wrapping text above it, pushing what is below,
collapsing when hidden — exactly like a decorative image member. Full contract
in templates-containers.md "Image slots as members"; what it means for the
slot machinery here:

- **The FRAME moves, the placement does not change.** `scale` /
  `offsetX|Y(Ratio)` / `rotation` are relative to the frame (offset from its
  centre, scale of its contain fit), so a moved frame carries the picture
  along and nothing stored is rewritten. Render: the template's frame proxy
  translates the baked picture AND its `absolutePositioned` clip by the same
  delta. Single fill page: `variant_image_fill_controller._frame()` = designed
  frame + the overlay's flow shift (`slotsLaidOut`), used for every placement
  computation (pick, stand-in, seed, adjust panel, handles, crop ghost,
  history) — objects remember the shift they carry (`_flowDy`) and translate
  with their clip when it changes. Group / document page: the ghost and the
  placement toolbar use `group_fill_controller._frame(variantId, inputId)`.
- **Hide.** A user-hidden member slot collapses at fill time (renderer:
  `hiddenMemberSlots` → template `hidden_overrides`, after phase A). A slot
  the FLOW collapses (hidden container, hidden host text it is attached to)
  is hidden on the fill page without touching its own hide field
  (`collapsed` in `slotsLaidOut`). On the single page a hidable flowing slot
  also has an unnamed Live mirror `hiddenValues[<id>]` so the server backdrop
  re-renders the texts around it.
- **API.** `imageInputs[].containerId`, `containers[].memberImageInputIds`;
  `frame` stays the DESIGNED box (the preview render is where it lands).
- **In repeatable rows (P4).** Every materialised row has its own slot id
  (derived), so the picker, the upload target (`PlaceholderImageUploader`
  expands), the placement fields / group placement map and the adjust panel
  are simply per row. Unused rows collapse (no picture on the single page, no
  ghost on the group / document page). A row action moves the picture state
  with the row (`fill_rows.js`: the single page's per-slot fields + URL, the
  group's pictures + per-dimension placements); a removed row's freed slot
  goes back to the stand-in with a neutral placement.
