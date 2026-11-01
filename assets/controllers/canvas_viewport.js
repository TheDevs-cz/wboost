/**
 * Print bleed ("spadávka") + pasteboard in the editors.
 *
 * Canvas coordinates are TRIM-relative everywhere (saved JSON, fill pages,
 * renders, the MCP DSL); the bleed is the area past the trim edges. To let
 * the designer see and grab what lies there, the editor's Fabric canvas
 * element is the BLEED BOX — trim + `bleed` on every side — with the
 * viewport shifted by `bleed`, exactly like the print render template.
 *
 * Around that sits a PASTEBOARD (2026-11-01): a further margin of
 * `pasteboardFor()` px on every side, so transform handles of objects flush
 * with (or hanging over) the design edge are drawn and grabbable instead of
 * clipped by the canvas element — and an overhanging object stays visible.
 * It is dimmed ON THE BITMAP, in Fabric's overlay pass with
 * `controlsAboveOverlay` (see patchPasteboardPaint): Fabric draws the
 * selection border + handles on the LOWER canvas too, so a DOM mask can only
 * ever sit above or below both — it washed the handles out. Purely
 * editor-side: the save thumbnail crops to the trim, nothing outside the
 * design is saved, rendered or exported differently.
 *
 * Consequences every editor module must respect:
 *  - `canvas.getWidth()/getHeight()` are the ELEMENT size; the design size
 *    is `designWidth()/designHeight()`.
 *  - `obj.getBoundingRect()` is in scene (trim) coordinates, so DOM chrome
 *    positioned from the canvas element rect must start at the trim origin —
 *    `trimOriginRect()` shifts the element rect accordingly.
 *  - the trim corner sits `viewOffset()` element px in (bleed + pasteboard);
 *    `bleedOf()` is the BLEED alone (covers, the bleed guide, snap targets).
 */

import { Point, util } from 'fabric';

// Pasteboard per side as a share of the design's longer edge. Proportional so
// it is roughly the same on-screen band at every auto-fit zoom (≈ 70–90 px);
// kept modest because the element grows by (1 + 2·ratio)² in painted pixels.
export const PASTEBOARD_RATIO = 0.12;
const PASTEBOARD_FILL = 'rgba(236, 239, 243, 0.85)';
// The print bleed band (between trim and bleed edge) gets cut off — tinted.
const BLEED_BAND_FILL = 'rgba(255, 255, 255, 0.4)';

export function pasteboardFor(width, height) {
    return Math.round(Math.max(width, height, 0) * PASTEBOARD_RATIO);
}

export function bleedOf(canvas) {
    return (canvas && canvas.wboostBleed) || 0;
}

export function pasteboardOf(canvas) {
    return (canvas && canvas.wboostPasteboard) || 0;
}

/** Element px from the element's top-left to the trim corner. */
export function viewOffset(canvas) {
    return bleedOf(canvas) + pasteboardOf(canvas);
}

export function designWidth(canvas) {
    return canvas.getWidth() - 2 * viewOffset(canvas);
}

export function designHeight(canvas) {
    return canvas.getHeight() - 2 * viewOffset(canvas);
}

/**
 * Size the canvas element to the bleed box of a `width` × `height` design
 * plus the pasteboard, and shift the viewport so (0, 0) is the trim corner.
 */
export function applyBleed(canvas, width, height, bleed) {
    const b = bleed > 0 ? bleed : 0;
    const p = pasteboardFor(width, height);
    patchScenePointer(canvas);
    patchPasteboardPaint(canvas);
    canvas.wboostBleed = b;
    canvas.wboostPasteboard = p;
    canvas.setDimensions({ width: width + 2 * (b + p), height: height + 2 * (b + p) });
    canvas.setViewportTransform([1, 0, 0, 1, b + p, b + p]);
}

export function trimOriginRect(rect, canvas, scale) {
    const offset = viewOffset(canvas) * scale;
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

/**
 * Dim the pasteboard (everything outside the bleed box) and tint the bleed
 * band after the objects and BEFORE the controls: Fabric's render order is objects → overlay → controls
 * when `controlsAboveOverlay` is on, so overhanging content reads as outside
 * the design while borders and handles stay crisp on top of the dim. Drawn in
 * element px (the overlay pass runs outside the viewport transform).
 */
function patchPasteboardPaint(canvas) {
    if (canvas.wboostPasteboardPatched || typeof canvas._renderOverlay !== 'function') return;
    canvas.wboostPasteboardPatched = true;
    canvas.controlsAboveOverlay = true;

    const original = canvas._renderOverlay.bind(canvas);
    canvas._renderOverlay = function (ctx) {
        original(ctx);
        const p = pasteboardOf(this);
        const b = bleedOf(this);
        const w = this.getWidth();
        const h = this.getHeight();
        const frame = (outer, inner, fill) => {
            ctx.fillStyle = fill;
            ctx.beginPath();
            ctx.rect(outer, outer, w - 2 * outer, h - 2 * outer);
            ctx.rect(inner, inner, w - 2 * inner, h - 2 * inner);
            ctx.fill('evenodd');
        };
        ctx.save();
        if (p > 0) frame(0, p, PASTEBOARD_FILL);
        if (b > 0) frame(p, p + b, BLEED_BAND_FILL);
        ctx.restore();
    };
}
