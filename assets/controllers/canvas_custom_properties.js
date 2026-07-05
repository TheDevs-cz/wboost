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
export const CANVAS_CUSTOM_PROPERTIES = [
    'name', 'maxLength', 'locked', 'uppercase', 'description', 'hidable', 'richText', 'inputId',
    'imagePlaceholder', 'allowMove', 'allowResize', 'allowRotate', 'allowedDirectoryIds',
    'assetPath', 'assetId',
];
