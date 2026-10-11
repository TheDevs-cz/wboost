# Templates — data model + backgrounds (canvas vs layer mode)

### Template editor ("Šablony" — the unified templates module)

The largest feature in the codebase. A `Template` is a Fabric.js canvas that an
admin authors once and end-users / API consumers fill with their own copy.
Entities: `Template` / `TemplateVariant` / `TemplateCategory` (+ `TemplateGroup`;
tables `template`, `template_variant`, `template_category`, `template_group`) —
the 2026-08 merge of the former social-network and custom-template modules (see
the merge-history section below). This section is the post-migration shape
(Stages 1–7) — older patterns (text canvas column, positional input binding,
monolithic Stimulus controller, Fabric v5) have been retired.

**Data model — `template_variant`**

- `canvas`: **JSONB** (Stage 1). The serialized Fabric document. Empty rows
  are stored as `'{}'` (never `''`) and the renderer synthesizes a minimal
  Fabric document with just a background image when it sees an empty canvas
  (canvas-mode rows only — see Backgrounds below).
- `preview_image_path`: nullable string (Stage 1). Path to a PNG in Minio
  (rendered server-side after each admin save). Replaces the legacy
  `preview_image` BLOB column. The full URL is built via the upload helper.
- `inputs`: JSONB array of `EditorTextInput` value objects, persisted via
  `EditorTextInputsDoctrineType`. Each entry carries its `inputId` (UUID v4).
- **inputId UUID binding (Stage 2)**: every textbox / image on the canvas
  carries a custom property `inputId` minted at admin-time, and every
  `EditorTextInput` row mirrors that same id. Overrides (text content, hidden
  flag) are looked up by id, not by index — so two inputs may legitimately
  share a `name`, and reordering objects on the canvas no longer rebinds
  inputs. The `EditorTextInput::fromArray` factory keeps a defensive UUID-mint
  fallback for legacy rows; once the migration has run on prod, no live row
  hits it.

**Backgrounds — canvas mode vs LAYER mode (`background_mode` column)**

Two styles, discriminated by the `background_mode` column (`BackgroundMode`
enum on `template_variant`). **Canvas mode** ('canvas', every pre-rework
row): the background is Fabric's canvas-level `backgroundImage`, mirrored from
the NOT-NULL-in-practice `background_image` column, center-cover re-applied on
every load (editor connect + render template), set via the editor's "Pozadí"
gallery pick + `persistBackgroundPath` side-channel POST. Untouched forever.
**Layer mode** ('layer', every NEWLY created variant): the background is a
regular image object in `objects[]` marked `isBackground: true`, initially
placed **cover-fit anchored top-left** (`scale = max(cw/iw, ch/ih)`, overflow
crops bottom-right) at stack index 0 — reorderable, undoable, snapping-excluded,
styled distinctly in the layers panel ("Pozadí" row), and **an ordinary
lockable layer**: it is seeded `editorLocked: true` (`setBackgroundLayer` /
`BackgroundLayer::buildObject`, and `restoreCustomProperties` defaults
pre-existing flag-less backgrounds to locked) and therefore click-through —
a full-canvas evented object would swallow every mousedown, killing
rubber-band multi-select — but **unlocking it via the mini-toolbar padlock
makes it move and resize like any other picture**. `isBackground` itself no
longer gates interaction anywhere (`applyEditorLock` / `applyBackdropState` /
the backdrop-targeting sweeps key on `editorLocked` alone), so an unlocked
background gets the normal backdrop treatment: passthrough while unselected,
plain-click to select, fully manipulable while active. It used to be
force-locked, which meant the designer could never reposition it at all.
Key facts:

- **Optional**: add-variant/group forms no longer require a background; a
  layer-mode variant without one renders a TRANSPARENT PNG (renderer calls
  Gotenberg `omitBackground()`; editor/fill show a checkerboard). The
  `background_image` column is now NULLABLE and in layer mode is a
  denormalized pointer to the layer's `assetPath` (synced in
  `EditTemplateVariantCanvasHandler` — the single canvas-save chokepoint, both
  the single-variant editor and the group editor dispatch
  `EditTemplateVariantCanvasEditor`; feeds thumbnails + nullable API
  `backgroundImageUrl`).
- **Server-side authoring** via `Services/Editor/BackgroundLayer` (build /
  replace-in-place / extractAssetPath): Add handlers seed the layer, the
  canvas-save handler swaps it in place (empty input NEVER removes; a new
  upload replaces the picture but preserves stack index + slot metadata), Copy
  inherits the source's mode, `CanvasDesignProjector` re-covers (never
  rx-scales) the layer per dimension and stamps NO canvas-level block for
  layer-mode sources. Editor-side the "Pozadí" pick calls `setBackgroundLayer`
  (ordinary dirty canvas edit, NO side-channel POST).
- **Form-picked backgrounds come from the PROJECT GALLERY (2026-08-10).** The
  add-template / add-variant / add-group / add-group-dimension forms (and the
  edit-variant AJAX form) carry NO raw background file input any more — a
  classic upload bypassed the gallery (the user expected it there) and an
  existing gallery image was unpickable. Each background field is the
  `background-picker` Stimulus widget (`_background_gallery_picker.html.twig`
  hidden `backgroundImageId` + thumb + buttons) opening ONE shared
  `#backgroundGalleryModal` per page (`_background_gallery_modal.html.twig`
  hosting `Project:ImageGallery`; only the picker that OPENED the modal
  consumes `asset-selected` — armed flag). Uploads inside the modal go through
  the regular gallery uploader → they land IN the gallery. The wire value is
  the FileUpload id; handlers resolve it via
  `Services/Editor/ResolveGalleryBackground` (project + not-trashed guarded,
  invalid id degrades to "no background") and REFERENCE the gallery path —
  the editor-"Pozadí" shape, no byte copy. Messages carry
  `backgroundImageId: null|string` (`AddTemplateVariant`,
  `AddTemplateGroupDimension`, `EditTemplateVariant` — which also keeps
  `backgroundImagePath` for the editor side-channel — and
  `GroupVariantSelection`). The group add-dimension pick is now OPTIONAL
  (null = inherit the group background, the handler's documented fallback);
  the create-from-source copy branch still copies bytes per variant. Note the
  standing trash caveat now applies to these too: purging a gallery file used
  as a background loses its bytes.
- **Group editor**: background GEOMETRY is strictly per-dimension, the
  background PICTURE follows the "Úprava více variant" mode (2026-08-10 —
  shared while the mode is on, single-variant while off, so per-variant
  backgrounds are authorable). `isBackground` objects are excluded from the
  whole group_sync engine (baseline/diff, projectNewObject, removeObject,
  resync, z-order — `isSyncable()`), the `object:added/removed` handlers gate
  on the flag, and layer-mode variants get `backgroundUrl: null` in
  variantsData so every canvas-level re-apply site no-ops. Cover fit is
  absolute per (image, canvas size) — propagating it relatively would compound
  drift, and group-seeded siblings SHARE the background's inputId, so a resync
  would clobber per-dimension covers. But excluding the *picture* too left a
  dimension able to end up with NO background at all: it rendered over
  transparency and whatever full-canvas artwork sat lowest read as the
  background, so the stack looked scrambled even though its object order was
  identical to every other dimension (the 2026-08-06 "group export ignores
  layer order" report). Three paths therefore fan the picture out, each
  recomputing the cover fit from scratch for the target's own canvas:
  `GroupSync.projectBackgroundLayer` on a layer-mode "Pozadí" pick (replacing
  each included LAYER-mode target's background in place, at the slot it
  occupied — index 0 when it had none, metadata copied from the active layer
  so the shared inputId stays the join key; canvas-mode siblings of a mixed
  group are skipped — an isBackground object next to their canvas-level slot
  would render as a second background), the controller's
  `_projectCanvasBackground` on a CANVAS-mode pick (2026-08-12: legacy groups
  used to never propagate at all — each included canvas-mode target gets the
  canvas-level cover on its shadow + a per-variant edit-endpoint POST, because
  the background_image COLUMN is what renders), and
  `AddTemplateGroupDimensionHandler` when a new dimension is added with no
  pick of its own. **Dispatch-ordering contract (2026-08-12, browser-verified
  root cause of "background ignores the mode"): `setBackgroundLayer` is async
  (it awaits the image fetch BEFORE adding the layer), so
  `canvas-editor:background:changed` must only be dispatched AFTER it
  resolves** — `onAssetSelected` awaits it, and the group editor's handler
  resolves the source layer lazily inside the queued op as the belt. An
  un-awaited dispatch made the handler find the OLD layer (or none) and the
  pick silently never propagated. The rail's "Prvky mimo plátno" badge
  ignores `isBackground` objects — a cover fit always overflows one axis.
- **Fillable (Phase B)**: the designer can mark the layer `imagePlaceholder` →
  it flows into `imageInputs` with `isBackground: true` (new `EditorImageInput`
  field; `allowMove/Resize/Rotate` forced false — the fill is a deterministic
  cover, `ImagePlacement::computeCover` + JS mirrors). Unfilled → designed
  background renders (stand-in contract); background slots' `frame` = the full
  canvas rect (the designed object's bbox overflows it). API docs +
  consumer-prompt.md describe the contract; mfkfm is ported.
- `'isBackground'` is in `CANVAS_CUSTOM_PROPERTIES` (and stripped on
  clipboard paste — only ever ONE background layer per canvas).
