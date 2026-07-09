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
// editorLocked is an EDITOR-ONLY flag (images): when true the object can't be
// moved/scaled/rotated in the admin canvas — a guard against accidental drags.
// It is deliberately NOT part of the imageInputs DTO and is ignored by the
// server renderer and the user-fill flow; it only ever shapes Fabric's
// interaction flags in the editor (see applyEditorLock).
export const CANVAS_CUSTOM_PROPERTIES = [
    'name', 'maxLength', 'locked', 'uppercase', 'description', 'hidable', 'richText', 'inputId',
    'imagePlaceholder', 'allowMove', 'allowResize', 'allowRotate', 'allowedDirectoryIds',
    'assetPath', 'assetId', 'editorLocked',
];

/**
 * Translate an object's `editorLocked` custom prop into Fabric's live
 * interaction flags. Single source of truth so the load path
 * (restoreCustomProperties) and the toolbar toggle stay in lockstep. Purely a
 * client-side editor convenience — none of these flags are serialized into the
 * canvas JSON by Fabric, so the export/render is untouched.
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
    obj.hoverCursor = locked ? 'not-allowed' : null;
    if (typeof obj.setCoords === 'function') obj.setCoords();
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
