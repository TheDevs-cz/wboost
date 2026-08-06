// Single source of truth for the custom properties Fabric serialises into
// canvas JSON (and that clone() must preserve). Imported by every Stimulus
// controller that round-trips canvas state — orchestrator, history,
// clipboard, etc. — so they stay in lockstep.
//
// Text-input props: name, maxLength, locked, uppercase, description, hidable,
// richText (user fills via the WYSIWYG), inputId.
// Image-placeholder props (mirrors EditorImageInput): imagePlaceholder marks a
// Fabric image as a fillable slot; allowMove/allowResize/allowRotate are the
// per-slot user limits; allowedDirectoryIds is the gallery folders offered;
// assetPath/assetId carry the gallery storage path + id so the server renderer
// can inline the image as base64 without reverse-mapping its public URL.
// editorLocked is an EDITOR-ONLY flag (images): when true the object is
// click-through in the admin canvas (not movable, not click-selectable —
// select it via the layers panel) — a guard against accidental drags that
// also keeps full-canvas images from blocking rubber-band multi-select.
// It is deliberately NOT part of the imageInputs DTO and is ignored by the
// server renderer and the user-fill flow; it only ever shapes Fabric's
// interaction flags in the editor (see applyEditorLock).
// isBackground marks THE background layer of a layer-mode variant (regular
// image object, initially cover-fitted top-left at the bottom of the stack):
// styled distinctly in the layers panel, replaced in place by the "Pozadí"
// picker, excluded from snapping and from ALL group-editor propagation
// (backgrounds stay per-dimension), stripped on copy/paste.
// lists* props (textboxes with richText): lists enables ul/ol in the fill
// WYSIWYG; listBullet ('disc'|'dash'|'check'|'image'), listBulletImage
// (gallery storage path), listIndent / listItemSpacing / listBlockSpacing
// (px, null = derived default — see ResolvedListStyle).
export const CANVAS_CUSTOM_PROPERTIES = [
    'name', 'maxLength', 'locked', 'uppercase', 'description', 'hidable', 'richText', 'inputId',
    'lists', 'listBullet', 'listBulletImage', 'listIndent', 'listItemSpacing', 'listBlockSpacing',
    'listCheckboxes', 'listCheckboxImage', 'listCheckboxCheckedImage',
    'checklist', 'checklistAdd', 'checklistRemove', 'checklistEditText', 'checklistToggle',
    'sampleValue',
    'imagePlaceholder', 'allowMove', 'allowResize', 'allowRotate', 'allowedDirectoryIds',
    'assetPath', 'assetId', 'editorLocked', 'isBackground',
];

/**
 * Translate an object's `editorLocked` custom prop into Fabric's live
 * interaction flags. Single source of truth so the load path
 * (restoreCustomProperties) and the toolbar toggle stay in lockstep. Purely a
 * client-side editor convenience — none of these flags are serialized into the
 * canvas JSON by Fabric, so the export/render is untouched.
 *
 * A locked image is CLICK-THROUGH (Figma-style): `evented`/`selectable` off,
 * so pointer events pass to whatever is underneath — including empty canvas,
 * where Fabric starts its rubber-band multi-select. This is load-bearing for
 * full-canvas images: an evented full-canvas object swallows every mousedown
 * (no rubber-band anywhere) and a locked one used to paint the not-allowed
 * cursor across the whole editor. Locked objects are selected via the LAYERS
 * PANEL instead (programmatic setActiveObject ignores these flags), where the
 * popover can unlock them again.
 *
 * Background layers are NOT special-cased here. They used to be force-locked
 * on the same "their pixels cover everything" reasoning, which cost the
 * designer the ability to move or resize the background at all. The backdrop
 * state below already solves the pointer problem for any canvas-covering
 * image without taking the affordances away, so a background is an ordinary
 * lockable layer: seeded LOCKED (see setBackgroundLayer /
 * BackgroundLayer::buildObject) and fully manipulable once unlocked.
 *
 * Reversible: unlocking restores the normal image affordances (movable, with
 * transform handles). Only ever called for image objects — textboxes carry
 * their own deliberate lock flags that this would clobber.
 */
export function applyEditorLock(obj) {
    if (!obj) return;
    const locked = obj.editorLocked === true;
    obj.lockMovementX = locked;
    obj.lockMovementY = locked;
    obj.lockScalingX = locked;
    obj.lockScalingY = locked;
    obj.lockRotation = locked;
    obj.hasControls = !locked;
    obj.selectable = !locked;
    obj.evented = !locked;
    obj.hoverCursor = null;
    if (typeof obj.setCoords === 'function') obj.setCoords();
}

// An UNLOCKED image whose bounding box covers at least this share of the
// canvas is treated as a "backdrop" for pointer targeting (see below).
export const BACKDROP_COVERAGE_RATIO = 0.9;

/**
 * Does this object's axis-aligned bounding box cover (nearly) the whole
 * canvas? Pure geometry — the intersection of the bbox with the canvas rect
 * must be ≥ BACKDROP_COVERAGE_RATIO of the canvas area. Full-bleed photos
 * overflow the canvas, so their clipped coverage is exactly 1.0; a hero image
 * over half the design stays well under the threshold.
 */
export function isBackdropCovering(obj, canvasWidth, canvasHeight) {
    if (!obj || !(canvasWidth > 0) || !(canvasHeight > 0)) return false;
    const rect = typeof obj.getBoundingRect === 'function' ? obj.getBoundingRect() : null;
    if (!rect) return false;
    const left = Math.max(0, rect.left);
    const top = Math.max(0, rect.top);
    const right = Math.min(canvasWidth, rect.left + rect.width);
    const bottom = Math.min(canvasHeight, rect.top + rect.height);
    const covered = Math.max(0, right - left) * Math.max(0, bottom - top);
    return covered >= BACKDROP_COVERAGE_RATIO * canvasWidth * canvasHeight;
}

/**
 * Third interaction state, between "normal" and applyEditorLock's full lock:
 * a canvas-covering UNLOCKED image ("backdrop") is skipped by pointer
 * targeting while it is not selected — dragging over it draws Fabric's
 * rubber-band multi-select instead of moving the picture, and a marquee never
 * pulls it into the ActiveSelection (any rectangle you draw intersects a
 * full-canvas image, so an evented backdrop would join EVERY marquee). Unlike
 * editorLocked, NO lock flags are set: the moment the object becomes active —
 * via a plain click (canvas_editor_controller's click-to-select on mouse:up),
 * the layers panel, or Fabric restoring a selection — it is fully movable /
 * scalable again, and it drops back to click-through on deselect.
 *
 * This is what lets an UNLOCKED background layer stay grabbable: it covers the
 * canvas by definition, so it is passthrough until you click it, then behaves
 * like any other picture. Locked (`editorLocked`) images are owned by
 * applyEditorLock and never touched here. Editor-only, like applyEditorLock:
 * none of these flags serialize, so the export render is untouched.
 */
export function applyBackdropState(obj, covering, isActive) {
    if (!obj) return;
    if (obj.editorLocked === true) return;
    const passthrough = covering === true && isActive !== true;
    obj.selectable = !passthrough;
    obj.evented = !passthrough;
}

/**
 * Re-apply the deliberate interaction flags every text placeholder is created
 * with (see submitAddText) so a RELOADED textbox behaves identically to a
 * freshly added one: it resizes by WIDTH ONLY (drag the side handles → the text
 * re-wraps), never by corner-scaling that stretches the glyphs and desyncs the
 * fill-page / container measurement (which is driven by fontSize, not scaleX)
 * and can shift the box on drag.
 *
 * Fabric does NOT serialize these flags (see the note above), so without this
 * pass a saved textbox comes back with Fabric's permissive defaults
 * (corner-scalable + rotatable) — the "text stretches / jumps when I resize it"
 * reports on already-saved templates. Runs from restoreCustomProperties, the
 * shared load path, so the single-variant editor and the group editor stay in
 * lockstep. Movement / selection are left untouched (textboxes stay draggable);
 * the `locked` custom prop is a fill-time flag and never gates editor
 * interaction.
 */
export function applyTextboxDefaults(obj) {
    if (!obj) return;
    obj.lockScalingX = true;
    obj.lockScalingY = true;
    obj.lockScalingFlip = true;
    obj.lockRotation = true;
    obj.hasControls = true;
    if (typeof obj.setCoords === 'function') obj.setCoords();
}
