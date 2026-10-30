/**
 * Print bleed ("spadávka") in the editors.
 *
 * Canvas coordinates are TRIM-relative everywhere (saved JSON, fill pages,
 * renders, the MCP DSL); the bleed is the area past the trim edges. To let
 * the designer see and grab what lies there, the editor's Fabric canvas
 * element is the BLEED BOX — trim + `bleed` on every side — with the
 * viewport shifted by `bleed`, exactly like the print render template.
 *
 * Two consequences every editor module must respect:
 *  - `canvas.getWidth()/getHeight()` are the ELEMENT size; the design size
 *    is `designWidth()/designHeight()`.
 *  - `obj.getBoundingRect()` is in scene (trim) coordinates, so DOM chrome
 *    positioned from the canvas element rect must start at the trim origin —
 *    `trimOriginRect()` shifts the element rect accordingly.
 *
 * With no bleed every helper is the identity, so non-print variants behave
 * exactly as before.
 */

import { Point, util } from 'fabric';

export function bleedOf(canvas) {
    return (canvas && canvas.wboostBleed) || 0;
}

export function designWidth(canvas) {
    return canvas.getWidth() - 2 * bleedOf(canvas);
}

export function designHeight(canvas) {
    return canvas.getHeight() - 2 * bleedOf(canvas);
}

/**
 * Size the canvas element to the bleed box of a `width` × `height` design and
 * shift the viewport so (0, 0) is the trim corner.
 */
export function applyBleed(canvas, width, height, bleed) {
    const b = bleed > 0 ? bleed : 0;
    patchScenePointer(canvas);
    canvas.wboostBleed = b;
    canvas.setDimensions({ width: width + 2 * b, height: height + 2 * b });
    canvas.setViewportTransform([1, 0, 0, 1, b, b]);
}

/**
 * The canvas element's client rect re-based on the TRIM: left/top at the trim
 * corner, width/height of the trim, for chrome that positions scene
 * coordinates as `rect.left + x * scale`.
 */
export function trimOriginRect(rect, canvas, scale) {
    const offset = bleedOf(canvas) * scale;
    if (!offset) return rect;

    const width = rect.width - 2 * offset;
    const height = rect.height - 2 * offset;

    return {
        left: rect.left + offset,
        top: rect.top + offset,
        right: rect.left + offset + width,
        bottom: rect.top + offset + height,
        width,
        height,
        x: rect.left + offset,
        y: rect.top + offset,
    };
}

/**
 * Fabric's `_getPointerImpl` inverts the viewport transform BEFORE it corrects
 * for CSS scaling, i.e. it treats the viewport translation as CSS pixels. The
 * editors zoom with a CSS transform, so a 35 px bleed shift became 35 SCREEN
 * px (≈ 170 canvas px at 20 % zoom) and every click on a print variant landed
 * off target. The viewport point itself is computed right (offset → retina →
 * CSS scale), so the scene point is derived from it instead. Identical to
 * Fabric's result whenever the viewport is the identity.
 */
function patchScenePointer(canvas) {
    if (canvas.wboostScenePointerPatched || typeof canvas._getPointerImpl !== 'function') return;
    canvas.wboostScenePointerPatched = true;

    const original = canvas._getPointerImpl.bind(canvas);
    canvas._getPointerImpl = function (event, fromViewport = false) {
        const viewportPoint = original(event, true);
        if (fromViewport) return viewportPoint;

        return new Point(viewportPoint.x, viewportPoint.y)
            .transform(util.invertTransform(this.viewportTransform));
    };
}
