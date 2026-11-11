// Pure box geometry of the editor (architecture §E.1, §E.4).

import { K, roundMm } from '../model/defaults.js';
import { boxKeysOf, isElementVisible } from '../model/elements.js';

export const HANDLES = Object.freeze(['n', 'ne', 'e', 'se', 's', 'sw', 'w', 'nw']);

export function pageBounds(page, bleedMm) {
    const B = Number(bleedMm) || 0;
    return { x0: 0 - B, y0: 0 - B, x1: page.width + B, y1: page.height + B };
}

const keepFlags = (box, out) => (box.hidden ? { ...out, hidden: true } : out);

/** Rounds the four edges to the grid, so a fixed edge stays exactly where it was. */
export function roundBox(box) {
    const x = roundMm(box.x);
    const y = roundMm(box.y);
    return keepFlags(box, { x, y, w: roundMm(roundMm(box.x + box.w) - x), h: roundMm(roundMm(box.y + box.h) - y) });
}

export function clampMove(box, bounds) {
    const w = Math.min(box.w, bounds.x1 - bounds.x0);
    const h = Math.min(box.h, bounds.y1 - bounds.y0);
    const x = Math.min(Math.max(box.x, bounds.x0), bounds.x1 - w);
    const y = Math.min(Math.max(box.y, bounds.y0), bounds.y1 - h);
    return keepFlags(box, { x, y, w, h });
}

function clampSpan(a, b, lo, hi, min) {
    let left = Math.max(a, lo);
    let right = Math.min(b, hi);
    if (right - left < min) {
        if (left + min <= hi) {
            right = left + min;
        } else {
            right = hi;
            left = hi - min;
        }
    }
    return [left, right];
}

/** Each edge individually into the bounds; never smaller than the minimum box. */
export function clampResize(box, bounds) {
    const [l, r] = clampSpan(box.x, box.x + box.w, bounds.x0, bounds.x1, K.MIN_W);
    const [t, b] = clampSpan(box.y, box.y + box.h, bounds.y0, bounds.y1, K.MIN_H);
    return keepFlags(box, { x: l, y: t, w: r - l, h: b - t });
}

/** Minimum size relative to the start box: the edge opposite to the dragged one never moves. */
export function enforceMinimum(box, handle, start) {
    const out = { ...box };
    if (out.w < K.MIN_W) {
        if (handle.includes('w')) out.x = start.x + start.w - K.MIN_W;
        out.w = K.MIN_W;
    }
    if (out.h < K.MIN_H) {
        if (handle.includes('n')) out.y = start.y + start.h - K.MIN_H;
        out.h = K.MIN_H;
    }
    return out;
}

export function resizeFromHandle(start, handle, dx, dy) {
    const out = { ...start };
    if (handle.includes('e')) out.w = start.w + dx;
    if (handle.includes('w')) {
        out.x = start.x + dx;
        out.w = start.w - dx;
    }
    if (handle.includes('s')) out.h = start.h + dy;
    if (handle.includes('n')) {
        out.y = start.y + dy;
        out.h = start.h - dy;
    }
    return enforceMinimum(out, handle, start);
}

export function containsPoint(box, pt) {
    return pt.x >= box.x && pt.x <= box.x + box.w && pt.y >= box.y && pt.y <= box.y + box.h;
}

/**
 * Topmost visible element under a trim-relative point: `{ el, frame? }`, `{ el: 'bg' }` on the bare
 * page (bleed included), `null` outside.
 */
export function hitTest(pt, design, selection) {
    const layout = design.layout;
    if (!layout) return null;
    for (const el of layout.order) {
        if (!isElementVisible(design, el)) continue;
        const keys = boxKeysOf(el, design);
        for (let i = 0; i < keys.length; i++) {
            const box = layout.boxes[keys[i]];
            if (box && containsPoint(box, pt)) return el === 'cal' ? { el, frame: i } : { el };
        }
    }
    const B = design.print && design.print.bleed ? design.print.bleedMm : 0;
    const b = pageBounds(design.page, B);
    return pt.x >= b.x0 && pt.x <= b.x1 && pt.y >= b.y0 && pt.y <= b.y1 ? { el: 'bg' } : null;
}

/** Kalendárium part under a point: the last-painted part of the row whose rect (grown 0.5 mm) contains it. */
export function partAt(pt, geometry) {
    if (!geometry) return null;
    for (const frame of geometry.frames) {
        if (!containsPoint(frame.inner, pt)) continue;
        for (const row of frame.rows) {
            if (pt.y < row.y || pt.y > row.y + row.h + 0.8) continue;
            let found = null;
            for (const part of row.parts) {
                const r = part.rect;
                const grow = part.key === 'line' ? 0.8 : 0.5;
                if (pt.x >= r.x - grow && pt.x <= r.x + r.w + grow && pt.y >= r.y - grow && pt.y <= r.y + r.h + grow) found = part.key;
            }
            if (found) return { frame: frame.index, date: row.date, part: found };
            if (pt.y <= row.y + row.h) return null;
        }
    }
    return null;
}
