// Kalendárium part gestures (architecture §E.6.8, "Part bars in the editor").
// All horizontal deltas are in the FLOW direction of the dragged frame: dFlow = zoneLeft ? dx : −dx.

import { K, roundMm } from '../model/defaults.js';

/**
 * New offset of a part after dragging by (dx, dy) mm on the page. Offsets are stored for the first
 * frame; a drag in the mirrored frame counts its dx negated.
 * @returns {{x: number, y: number} | null} null = no offset (delete `offsets[part]`)
 */
export function dragPartOffset(start, dx, dy, snapBack, mirrored) {
    const s = start || { x: 0, y: 0 };
    let x = roundMm(s.x + (mirrored ? -dx : dx));
    let y = roundMm(s.y + dy);
    if (snapBack) {
        if (Math.abs(x) < K.PART_SNAP_BACK) x = 0;
        if (Math.abs(y) < K.PART_SNAP_BACK) y = 0;
    }
    return x === 0 && y === 0 ? null : { x, y };
}

/**
 * Resizes the flow-anchored event box. `side` is in flow terms: 'w' = the bar on the zone side (flow
 * start), 'e' = the bar on the number side; 'n' / 's' are physical.
 */
export function resizeEventBox(box, side, dFlow, dy) {
    const out = { ...box };
    if (side === 'e') out.w = Math.max(K.EVBOX_MIN_W, box.w + dFlow);
    if (side === 'w') {
        out.w = Math.max(K.EVBOX_MIN_W, box.w - dFlow);
        out.x = box.x + box.w - out.w;
    }
    if (side === 's') out.h = Math.max(K.EVBOX_MIN_H, box.h + dy);
    if (side === 'n') {
        out.h = Math.max(K.EVBOX_MIN_H, box.h - dy);
        out.y = box.y + box.h - out.h;
    }
    return { x: roundMm(out.x), y: roundMm(out.y), w: roundMm(out.w), h: roundMm(out.h) };
}

/**
 * The drawn event box of a row as a stored (flow-anchored, offset-free) `ev.box`. While the default
 * box is in use the page-wide box is taken (`frame.evDefault`), not the narrower one of a row whose
 * holiday text needs the room — a stored box is one box for every row.
 */
export function defaultEventBox(geometryFrame, row) {
    const r = row.eventBox;
    if (!r) return null;
    const off = geometryFrame.evOffset || { x: 0, y: 0 };
    const page = geometryFrame.evDefault;
    if (page) return { x: roundMm(page.x), y: roundMm(r.y - off.y - row.y), w: roundMm(page.w), h: roundMm(r.h) };
    const col = geometryFrame.textColumn;
    const x = r.x - off.x;
    const flowX = geometryFrame.zoneLeft ? x - col.x : col.x + col.w - (x + r.w);
    return { x: roundMm(flowX), y: roundMm(r.y - off.y - row.y), w: roundMm(r.w), h: roundMm(r.h) };
}

/** New `cal.iconCellW` after dragging a side bar of the icon zone by `dFlow` (positive = wider). */
export function resizeIconCell(cellW, cols, dFlow) {
    return Math.max(K.ICON_CELL_MIN, roundMm((cols * cellW + dFlow) / cols));
}

/** New `hol.wrapWidth` after dragging a wrap bar by `dFlow` (positive = wider). */
export function resizeHolWrap(width, dFlow) {
    return Math.max(K.HOL_WRAP_MIN, roundMm(width + dFlow));
}
