// Drag pipeline (architecture §E.2): snapshot → resize/move → min size → snap → clamp → round → link.

import { K, roundMm } from '../model/defaults.js';
import { clampMove, clampResize, enforceMinimum, pageBounds, resizeFromHandle, roundBox } from './geometry.js';
import { snapMove, snapResize, snapTargets } from './snap.js';
import { applyLink } from './link.js';

function copyBoxes(boxes) {
    const out = {};
    for (const [k, b] of Object.entries(boxes)) out[k] = { ...b };
    return out;
}

export function beginDrag(input) {
    const base = copyBoxes(input.boxes);
    return {
        base,
        key: input.key,
        start: { ...base[input.key] },
        mode: input.mode,
        page: input.page,
        bleedMm: Number(input.bleedMm) || 0,
        visibleKeys: input.visibleKeys.slice(),
        link: Boolean(input.link),
        bounds: pageBounds(input.page, input.bleedMm),
        targets: snapTargets(base, input.key, input.visibleKeys, input.page, input.bleedMm),
    };
}

/** Boxes for a pointer offset from the drag start; always computed from the session's base. */
export function dragTo(session, dxMm, dyMm, o = {}) {
    const { base, key, start, mode, bounds } = session;
    const snap = o.snap !== false && Number.isFinite(o.thresholdMm) && o.thresholdMm > 0;
    let guides = [];
    if (mode === 'move') {
        let b = { ...start, x: start.x + dxMm, y: start.y + dyMm };
        if (snap) ({ box: b, guides } = snapMove(b, session.targets, o.thresholdMm));
        b = roundBox(clampMove(b, bounds));
        return { boxes: { ...copyBoxes(base), [key]: b }, guides: keptGuides(guides, b, 'move') };
    }
    let b = resizeFromHandle(start, mode, dxMm, dyMm);
    if (snap) {
        ({ box: b, guides } = snapResize(b, mode, session.targets, o.thresholdMm));
        b = enforceMinimum(b, mode, start);
    }
    b = roundBox(clampResize(b, bounds));
    const boxes = session.link ? applyLink(base, key, start, b, session.visibleKeys, session.page) : { ...copyBoxes(base), [key]: b };
    return { boxes, guides: keptGuides(guides, b, mode) };
}

// a guide survives only when the box really ended on it (the clamp or the minimum may have moved it away)
function keptGuides(guides, b, mode) {
    return guides.filter((g) => {
        const values = g.axis === 'x' ? [b.x, b.x + b.w / 2, b.x + b.w] : [b.y, b.y + b.h / 2, b.y + b.h];
        const candidates = mode === 'move' ? values : [values[0], values[2]];
        return candidates.some((v) => Math.abs(v - g.pos) <= K.GRID / 2 + 1e-9);
    });
}

/** Arrow keys: never links, never snaps. */
export function nudge(boxes, key, dxMm, dyMm, page, bleedMm) {
    const b = boxes[key];
    const moved = roundBox(clampMove({ ...b, x: b.x + dxMm, y: b.y + dyMm }, pageBounds(page, bleedMm)));
    return { ...copyBoxes(boxes), [key]: moved };
}

/** Typed / stepped X, Y, Š, V. Values outside the limits land on the nearest allowed value. */
export function setField(boxes, key, field, value, ctx) {
    const bounds = pageBounds(ctx.page, ctx.bleedMm);
    const before = boxes[key];
    const v = roundMm(Number(value) || 0);
    if (field === 'x' || field === 'y') {
        return { ...copyBoxes(boxes), [key]: roundBox(clampMove({ ...before, [field]: v }, bounds)) };
    }
    const sized = { ...before, [field]: Math.max(field === 'w' ? K.MIN_W : K.MIN_H, v) };
    const after = roundBox(clampResize(sized, bounds));
    // the left / top edge is fixed: an over-large value stops at the bleed edge
    if (ctx.link) return applyLink(boxes, key, before, after, ctx.visibleKeys, ctx.page);
    return { ...copyBoxes(boxes), [key]: after };
}
