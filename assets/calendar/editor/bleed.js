// Bleed adaptation (architecture §E.5, handoff §10.7).

import { K, roundMm } from '../model/defaults.js';
import { clampMove, clampResize, pageBounds, roundBox } from './geometry.js';

/** Edges on the trim edge or on the old bleed edge follow to the new bleed edge. */
export function adaptBoxesToBleed(boxes, page, oldBleedMm, newBleedMm) {
    const TOL = K.LINK_TOL;
    const old = Number(oldBleedMm) || 0;
    const next = Number(newBleedMm) || 0;
    const moved = {};
    for (const [key, b] of Object.entries(boxes)) {
        let left = b.x;
        let right = b.x + b.w;
        let top = b.y;
        let bottom = b.y + b.h;
        if (Math.abs(left) <= TOL || (old > 0 && Math.abs(left + old) <= TOL)) left = -next;
        if (Math.abs(right - page.width) <= TOL || (old > 0 && Math.abs(right - page.width - old) <= TOL)) right = page.width + next;
        if (Math.abs(top) <= TOL || (old > 0 && Math.abs(top + old) <= TOL)) top = -next;
        if (Math.abs(bottom - page.height) <= TOL || (old > 0 && Math.abs(bottom - page.height - old) <= TOL)) bottom = page.height + next;
        // a box lying entirely in the old bleed would collapse: it keeps its size and is moved back instead
        if (right - left <= 0) [left, right] = [b.x, b.x + b.w];
        if (bottom - top <= 0) [top, bottom] = [b.y, b.y + b.h];
        moved[key] = { ...b, x: roundMm(left), y: roundMm(top), w: roundMm(right - left), h: roundMm(bottom - top) };
    }
    return clampBoxesToBounds(moved, page, next);
}

/** No box outside `[-B, W+B] × [-B, H+B]`. */
export function clampBoxesToBounds(boxes, page, bleedMm) {
    const bounds = pageBounds(page, bleedMm);
    const out = {};
    for (const [key, b] of Object.entries(boxes)) {
        const inside = b.x >= bounds.x0 - 1e-9 && b.y >= bounds.y0 - 1e-9 && b.x + b.w <= bounds.x1 + 1e-9 && b.y + b.h <= bounds.y1 + 1e-9;
        if (inside) {
            out[key] = { ...b };
            continue;
        }
        const outside = b.x >= bounds.x1 || b.y >= bounds.y1 || b.x + b.w <= bounds.x0 || b.y + b.h <= bounds.y0;
        out[key] = roundBox(outside ? clampMove(b, bounds) : clampResize(b, bounds));
    }
    return out;
}
