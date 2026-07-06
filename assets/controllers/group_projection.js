/**
 * Pure cross-dimension projection math for the template-group editor.
 * No Fabric imports — everything operates on plain numbers/objects.
 *
 * Conventions (the "1% left" contract):
 *  - horizontal positions scale by the WIDTH ratio, vertical by the HEIGHT
 *    ratio (each axis is percentage-preserving independently);
 *  - element SIZE (textbox wrap width, font size, image scale) scales by the
 *    WIDTH ratio only, so elements keep their aspect ratio;
 *  - rotation is absolute (an angle means the same thing at any size).
 */

export function ratios(sourceDims, targetDims) {
    return {
        rx: targetDims.width / sourceDims.width,
        ry: targetDims.height / sourceDims.height,
    };
}

/**
 * Relative-delta application: move/scale a target on top of its CURRENT
 * state (per-variant fine-tunes survive).
 *
 * @param {Object} base   baseline geometry of the ACTIVE object (before edit)
 * @param {Object} cur    current geometry of the ACTIVE object (after edit)
 * @param {Object} target current geometry of the matched target object
 * @param {number} rx     width ratio target/active
 * @param {number} ry     height ratio target/active
 * @returns {Object} new geometry values for the target (only changed keys)
 */
export function applyGeometryDelta(base, cur, target, rx, ry) {
    const out = {};

    if (cur.left !== base.left) {
        out.left = target.left + (cur.left - base.left) * rx;
    }
    if (cur.top !== base.top) {
        out.top = target.top + (cur.top - base.top) * ry;
    }
    if (cur.scaleX !== base.scaleX && base.scaleX) {
        out.scaleX = target.scaleX * (cur.scaleX / base.scaleX);
    }
    if (cur.scaleY !== base.scaleY && base.scaleY) {
        out.scaleY = target.scaleY * (cur.scaleY / base.scaleY);
    }
    if (cur.width !== base.width && base.width) {
        out.width = target.width * (cur.width / base.width);
    }
    if (cur.fontSize !== undefined && base.fontSize !== undefined
        && cur.fontSize !== base.fontSize && base.fontSize
    ) {
        out.fontSize = target.fontSize * (cur.fontSize / base.fontSize);
    }
    if (cur.angle !== base.angle) {
        out.angle = cur.angle; // absolute per spec
    }

    return out;
}

/**
 * Absolute projection of active geometry into a target's pixel space — used
 * for newly added elements and for the explicit "Srovnat podle skupiny"
 * re-sync (which deliberately overwrites target fine-tunes).
 *
 * @param {Object} geom     absolute geometry of the active object
 * @param {number} rx       width ratio target/active
 * @param {number} ry       height ratio target/active
 * @param {boolean} isTextbox textboxes project width+fontSize; other objects scaleX/scaleY
 */
export function projectGeometry(geom, rx, ry, isTextbox) {
    const out = {
        left: geom.left * rx,
        top: geom.top * ry,
        angle: geom.angle,
    };

    if (isTextbox) {
        out.width = geom.width * rx;
        if (geom.fontSize !== undefined) {
            out.fontSize = geom.fontSize * rx;
        }
        // Admin textboxes keep scale locked at 1 — copy verbatim.
        out.scaleX = geom.scaleX;
        out.scaleY = geom.scaleY;
    } else {
        out.scaleX = geom.scaleX * rx;
        out.scaleY = geom.scaleY * rx;
    }

    return out;
}
