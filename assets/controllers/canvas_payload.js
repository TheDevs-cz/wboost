import { CANVAS_CUSTOM_PROPERTIES, applyEditorLock, applyTextboxDefaults } from './canvas_custom_properties.js';

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

        // Custom props are restored above, but Fabric's interaction flags are
        // not — re-derive them so a saved textbox / image behaves like a
        // freshly created one the instant the canvas finishes loading. Images:
        // honour editorLocked (can't be dragged when set). Textboxes: width-only
        // resize (no glyph-stretching corner scale / rotation), matching
        // submitAddText — otherwise Fabric's permissive defaults let the user
        // stretch and shift a reloaded box.
        if (t === 'image') {
            applyEditorLock(obj);
        } else if (t === 'textbox') {
            applyTextboxDefaults(obj);
        }
    });
}

/**
 * Scale a background image so it COVERS a canvas of the given logical
 * dimensions (CSS `object-fit: cover`). Takes explicit dimensions instead of
 * reading them off a canvas so the group editor can cover-fit backgrounds on
 * thumbnail-scale shadow canvases whose ELEMENT size differs from the
 * variant's logical size.
 *
 * `anchor` — 'center' (legacy canvas-level backgrounds, overflow split evenly)
 * or 'top-left' (background LAYERS: pinned to 0,0 so overflow crops away
 * bottom-right; must match the server's `ImagePlacement::computeCover`).
 */
export function coverForDimensions(img, canvasWidth, canvasHeight, anchor = 'center') {
    const element = typeof img.getElement === 'function' ? img.getElement() : null;
    const imageWidth = (element && (element.naturalWidth || element.width)) || img.width || 1;
    const imageHeight = (element && (element.naturalHeight || element.height)) || img.height || 1;
    const scale = Math.max(canvasWidth / imageWidth, canvasHeight / imageHeight);
    img.set({
        ...(anchor === 'top-left'
            ? { originX: 'left', originY: 'top', left: 0, top: 0 }
            : { originX: 'center', originY: 'center', left: canvasWidth / 2, top: canvasHeight / 2 }),
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

    // Ruler guides ride the canvas document the same way (top-level `guides`
    // key). Fabric's loadFromJSON ignores unknown top-level keys, and guides
    // are not objects, so they can never render into the export.
    canvasJSON.guides = sanitizedGuides(canvas);

    // Textbox inputs. Type filter is case-insensitive: Fabric v7's
    // getObjects('textbox') does NOT match v7-saved objects ('Textbox').
    // Design-hidden layers (the layers panel's eye toggle → visible: false)
    // are NOT fillable: they are excluded from inputs[]/imageInputs[] so the
    // fill page, API listing and export never offer them. The positional
    // textbox↔input contract survives because the server-side counterpart
    // (TextInputObjectBinder) skips invisible textboxes identically.
    const textInputs = inMemoryObjects
        .filter((obj) => (obj.type || '').toLowerCase() === 'textbox' && obj.visible !== false)
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

    // Image placeholders: every image object the designer marked fillable
    // (design-hidden ones excluded — see the textbox filter above).
    const imageInputs = inMemoryObjects
        .filter((obj) => (obj.type || '').toLowerCase() === 'image' && obj.imagePlaceholder === true && obj.visible !== false)
        .map((img) => {
            if (!img.inputId) {
                img.inputId = crypto.randomUUID();
            }
            const isBackground = img.isBackground === true;
            return {
                inputId: img.inputId,
                name: img.name || null,
                description: img.description || null,
                // A background fill is a fixed top-left cover — no user transform.
                allowMove: !isBackground && (img.allowMove || false),
                allowResize: !isBackground && (img.allowResize || false),
                allowRotate: !isBackground && (img.allowRotate || false),
                hidable: img.hidable || false,
                allowedDirectoryIds: Array.isArray(img.allowedDirectoryIds) ? img.allowedDirectoryIds : [],
                isBackground,
            };
        });

    return {
        canvas: JSON.stringify(canvasJSON),
        textInputs: JSON.stringify(textInputs),
        imageInputs: JSON.stringify(imageInputs),
    };
}

/**
 * Ruler guides (canvas_rulers_controller): `[{axis: 'x'|'y', pos}]` in canvas
 * px — axis 'x' is a VERTICAL guide at x=pos, 'y' a horizontal one at y=pos.
 * Deliberately dimension-free (no clamping): group-editor shadow canvases
 * have thumbnail-scale ELEMENT sizes, so canvas.getWidth() is not the
 * variant's logical size there. In-canvas placement is enforced at drag time.
 *
 * @param {Object} canvas Fabric canvas carrying `wboostGuides`
 * @returns {Array} persistable guide definitions
 */
export function sanitizedGuides(canvas) {
    const guides = Array.isArray(canvas.wboostGuides) ? canvas.wboostGuides : [];
    const seen = new Set();
    return guides
        .filter((g) => g && (g.axis === 'x' || g.axis === 'y') && Number.isFinite(g.pos) && g.pos >= 0)
        .map((g) => ({ axis: g.axis, pos: Math.round(g.pos * 10) / 10 }))
        .filter((g) => {
            const key = `${g.axis}:${g.pos}`;
            if (seen.has(key)) return false;
            seen.add(key);
            return true;
        })
        .sort((a, b) => (a.axis === b.axis ? a.pos - b.pos : a.axis.localeCompare(b.axis)));
}

/**
 * Container sanitization: stale members pruned (texts + decorative images —
 * fillable placeholders and the background layer are never members), flow
 * order re-derived from tops, child references validated (existing ids only,
 * no self-refs, no cycles, one parent per child — first wins in list order),
 * `gap` normalized (finite ≥ 0 or absent), and inert containers dropped —
 * iterated to a fixpoint because dropping a degenerate child can strip its
 * parent below the 2-member minimum.
 *
 * @param {Object} canvas Fabric canvas carrying `wboostContainers`
 * @param {Array} objects live canvas objects (flat, canvas order)
 * @returns {Array} persistable container definitions
 */
export function sanitizedContainers(canvas, objects) {
    const containers = Array.isArray(canvas.wboostContainers) ? canvas.wboostContainers : [];
    const layout = window.WBoostContainerLayout;
    const memberIds = new Set(
        objects
            .filter((o) => o.inputId && (layout
                ? layout.isMemberCandidate(o)
                : (o.type || '').toLowerCase() === 'textbox'))
            .map((o) => o.inputId),
    );

    let result = containers
        .map((container) => {
            let memberInputIds = (container.memberInputIds || []).filter((id) => memberIds.has(id));
            if (layout) {
                memberInputIds = layout.sortMemberIdsByTop(objects, memberInputIds);
            }
            const entry = {
                id: container.id,
                maxHeight: container.maxHeight,
                memberInputIds,
                memberContainerIds: Array.isArray(container.memberContainerIds)
                    ? container.memberContainerIds.slice()
                    : [],
            };
            if (typeof container.gap === 'number' && Number.isFinite(container.gap) && container.gap >= 0) {
                entry.gap = Math.round(container.gap * 10) / 10;
            }
            if (typeof container.spaceAfter === 'number' && Number.isFinite(container.spaceAfter) && container.spaceAfter >= 0) {
                entry.spaceAfter = Math.round(container.spaceAfter * 10) / 10;
            }
            return entry;
        })
        .filter((container) => container.id && container.maxHeight > 0);

    // Child references: only surviving ids, no self-refs, one parent per child.
    const claimed = new Set();
    const knownIds = new Set(result.map((c) => c.id));
    result.forEach((container) => {
        container.memberContainerIds = container.memberContainerIds.filter((childId) => {
            if (childId === container.id || !knownIds.has(childId) || claimed.has(childId)) {
                return false;
            }
            claimed.add(childId);
            return true;
        });
    });

    // Cycles: drop child references that reach back to an ancestor.
    const byId = new Map(result.map((c) => [c.id, c]));
    const reaches = (fromId, targetId, seen) => {
        if (fromId === targetId) return true;
        if (seen.has(fromId)) return false;
        seen.add(fromId);
        const node = byId.get(fromId);
        return Boolean(node) && node.memberContainerIds.some((id) => reaches(id, targetId, seen));
    };
    result.forEach((container) => {
        container.memberContainerIds = container.memberContainerIds.filter(
            (childId) => !reaches(childId, container.id, new Set()),
        );
    });

    // Degenerate drop to fixpoint: a container needs 2+ members in total, and
    // dropping one can invalidate a parent that counted it.
    for (;;) {
        const valid = new Set(result
            .filter((c) => c.memberInputIds.length + c.memberContainerIds.length >= 2)
            .map((c) => c.id));
        if (valid.size === result.length) {
            break;
        }
        result = result
            .filter((c) => valid.has(c.id))
            .map((c) => ({ ...c, memberContainerIds: c.memberContainerIds.filter((id) => valid.has(id)) }));
    }

    return result;
}
