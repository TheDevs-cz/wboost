import { CANVAS_CUSTOM_PROPERTIES } from './canvas_custom_properties.js';

/**
 * Canvas (de)serialization helpers shared by the single-variant editor
 * (canvas_editor_controller) and the group editor (group_editor_controller,
 * which serializes its offscreen per-variant shadow canvases through the
 * exact same code path so the save payload is byte-identical either way).
 *
 * Not a Stimulus controller — the missing `_controller` suffix keeps it out
 * of auto-registration.
 */

/**
 * Fabric v7's _fromObject does not copy arbitrary custom properties from the
 * source JSON onto deserialized objects — only properties registered as
 * customProperties (or in SerializedObjectProps) survive. Re-stamp every
 * custom annotation property (inputId, name, locked, …) from the source
 * document by positional index (Fabric preserves object order through
 * loadFromJSON), and mint an inputId for any textbox/image lacking one.
 */
export function restoreCustomProperties(canvas, sourceCanvas) {
    const sourceObjects = Array.isArray(sourceCanvas.objects) ? sourceCanvas.objects : [];

    canvas.getObjects().forEach((obj, idx) => {
        const source = sourceObjects[idx];
        if (source) {
            CANVAS_CUSTOM_PROPERTIES.forEach((prop) => {
                if (source[prop] !== undefined) {
                    obj[prop] = source[prop];
                }
            });
        }

        // Defensive: if a textbox/image still has no inputId (legacy data,
        // fresh-on-canvas object, etc.), mint one. Type match is
        // case-insensitive — v5 emitted 'textbox', v7 emits 'Textbox'.
        const t = (obj.type || '').toLowerCase();
        if ((t === 'textbox' || t === 'image') && !obj.inputId) {
            obj.inputId = crypto.randomUUID();
        }
    });
}

/**
 * Scale + center a background image so it COVERS a canvas of the given
 * logical dimensions (CSS `object-fit: cover`, centered). Takes explicit
 * dimensions instead of reading them off a canvas so the group editor can
 * cover-fit backgrounds on thumbnail-scale shadow canvases whose ELEMENT
 * size differs from the variant's logical size.
 */
export function coverForDimensions(img, canvasWidth, canvasHeight) {
    const element = typeof img.getElement === 'function' ? img.getElement() : null;
    const imageWidth = (element && (element.naturalWidth || element.width)) || img.width || 1;
    const imageHeight = (element && (element.naturalHeight || element.height)) || img.height || 1;
    const scale = Math.max(canvasWidth / imageWidth, canvasHeight / imageHeight);
    img.set({
        originX: 'center',
        originY: 'center',
        left: canvasWidth / 2,
        top: canvasHeight / 2,
        cropX: 0,
        cropY: 0,
        scaleX: scale,
        scaleY: scale,
    });
}

/**
 * Serialize a Fabric canvas into the exact editor-save payload shape:
 * `{ canvas, textInputs, imageInputs }`, all JSON strings matching the
 * single-variant editor form's hidden fields.
 */
export function buildVariantPayload(canvas) {
    // Fabric v7 silently drops some custom properties from
    // toJSON(propertiesToInclude) — merge each in-memory object's values back
    // onto the serialized entry by positional index to guarantee round-trip
    // integrity regardless of how Fabric internally classifies the property.
    const canvasJSON = canvas.toJSON(CANVAS_CUSTOM_PROPERTIES);
    const inMemoryObjects = canvas.getObjects();
    canvasJSON.objects.forEach((serialized, idx) => {
        const live = inMemoryObjects[idx];
        if (!live) return;
        CANVAS_CUSTOM_PROPERTIES.forEach((prop) => {
            const value = live[prop];
            if (value !== undefined) {
                serialized[prop] = value;
            }
        });
    });

    // Container definitions travel inside the canvas document (sanitized:
    // stale members pruned, flow order re-derived, inert containers dropped).
    canvasJSON.containers = sanitizedContainers(canvas, inMemoryObjects);

    // Textbox inputs. Type filter is case-insensitive: Fabric v7's
    // getObjects('textbox') does NOT match v7-saved objects ('Textbox').
    const textInputs = inMemoryObjects
        .filter((obj) => (obj.type || '').toLowerCase() === 'textbox')
        .map((textbox) => {
            if (!textbox.inputId) {
                textbox.inputId = crypto.randomUUID();
            }
            return {
                inputId: textbox.inputId,
                name: textbox.name,
                maxLength: textbox.maxLength || null,
                locked: textbox.locked || false,
                uppercase: textbox.uppercase || false,
                description: textbox.description || '',
                hidable: textbox.hidable || false,
                richText: textbox.richText || false,
            };
        });

    // Image placeholders: every image object the designer marked fillable.
    const imageInputs = inMemoryObjects
        .filter((obj) => (obj.type || '').toLowerCase() === 'image' && obj.imagePlaceholder === true)
        .map((img) => {
            if (!img.inputId) {
                img.inputId = crypto.randomUUID();
            }
            return {
                inputId: img.inputId,
                name: img.name || null,
                description: img.description || null,
                allowMove: img.allowMove || false,
                allowResize: img.allowResize || false,
                allowRotate: img.allowRotate || false,
                hidable: img.hidable || false,
                allowedDirectoryIds: Array.isArray(img.allowedDirectoryIds) ? img.allowedDirectoryIds : [],
            };
        });

    return {
        canvas: JSON.stringify(canvasJSON),
        textInputs: JSON.stringify(textInputs),
        imageInputs: JSON.stringify(imageInputs),
    };
}

/**
 * @param {Object} canvas Fabric canvas carrying `wboostContainers`
 * @param {Array} objects live canvas objects (flat, canvas order)
 * @returns {Array} persistable container definitions
 */
export function sanitizedContainers(canvas, objects) {
    const containers = Array.isArray(canvas.wboostContainers) ? canvas.wboostContainers : [];
    const layout = window.WBoostContainerLayout;
    const textboxIds = new Set(
        objects
            .filter((o) => (o.type || '').toLowerCase() === 'textbox' && o.inputId)
            .map((o) => o.inputId),
    );

    return containers
        .map((container) => {
            let memberInputIds = (container.memberInputIds || []).filter((id) => textboxIds.has(id));
            if (layout) {
                memberInputIds = layout.sortMemberIdsByTop(objects, memberInputIds);
            }
            return {
                id: container.id,
                maxHeight: container.maxHeight,
                memberInputIds,
            };
        })
        .filter((container) => container.memberInputIds.length >= 2 && container.maxHeight > 0);
}
