// Part chrome of the Kalendárium on the editor stage (architecture §D.2, §D.6, §E.6.8; handoff §6.2
// "Pole pro akce", "Ikony", "Svátek"). Owner: P5b.
//
// Contract: `mountPartsOverlay(overlayRoot, ctx): { update(geometry, scale, selection), destroy() }`
//   overlayRoot  `div.cal-ovl.cal-ovl-parts` of the stage: origin at the trim corner, positions are
//                `mm × scale` px; it lies above the selection chrome of `overlay.js`
//   update       called by the stage after EVERY render (also during live drags) with the
//                `WeekGeometry` of the page on the stage (or null), the px-per-mm scale and
//                `ui.selection`. Nodes are pooled and moved, never rebuilt, so the element under the
//                pointer stays the same for a whole drag.
//
// What is drawn — only while the matching part is selected, in every row of BOTH frames of a
// 14-day page (frame 2 is the mirrored one):
//   part `ev`    the red dashed event box of every row (`[data-evbox]`, with `data-frame`, `data-date`)
//                and its four side bars `[data-evbox-bar="n|e|s|w"]` (physical sides)
//   part `icon`  the dashed violet icon cells (`[data-iconcell]`, `data-cell`; the cell chosen in the
//                panel carries `.on`) and two bars per frame `[data-iconcell-bar="start|end"]`
//   part `hol`   two bars per frame `[data-holwrap-bar="start|end"]` at the wrap width of a long
//                holiday name (not on compact rows, which wrap by their own rule)
//   `start` = the left bar, `end` = the right bar.
//
// Drags run here and never reach the stage's pointer pipeline: `pointerdown` stops propagation,
// the element takes the pointer capture, the first movement past `K.DRAG_PX` calls
// `store.beginChange()`, every move `store.updateLive()` computed from the state at pointer down,
// release `store.commitChange()` — ONE undo step. Esc (or a lost pointer) cancels the drag.
//   - a side bar of the event box resizes `styles.ev.box` (flow-anchored, so every row and both
//     frames follow; a box that was still the default one is stored as drawn first)
//   - the event box itself moves by the part offset `styles.offsets.ev` like any other part
//     (snaps back to its place within `K.PART_SNAP_BACK` unless Alt is held)
//   - an icon cell bar changes `styles.cal.iconCellW`, a wrap bar `styles.hol.wrapWidth`; dragging a
//     bar away from the middle of its zone makes it wider

import { K, roundMm } from '../../model/defaults.js';
import { defaultEventBox, dragPartOffset, resizeEventBox, resizeHolWrap, resizeIconCell } from '../../editor/parts.js';
import { setStyle } from '../controls/bind.js';
import { h } from '../dom.js';

const SIDES = ['n', 'e', 's', 'w'];
const px = (v) => `${Math.round(v * 100) / 100}px`;

function place(el, box, scale) {
    el.style.left = px(box.x * scale);
    el.style.top = px(box.y * scale);
    el.style.width = px(box.w * scale);
    el.style.height = px(box.h * scale);
}

export function mountPartsOverlay(overlayRoot, ctx) {
    const { store } = ctx;
    const boxes = [];
    const cells = [];
    const bars = [];
    let last = { geometry: null, scale: 0, selection: null };
    let gesture = null;

    // ---- pools --------------------------------------------------------------------------------

    function pool(list, count, make) {
        while (list.length > count) list.pop().remove();
        while (list.length < count) {
            const node = make();
            list.push(node);
            overlayRoot.append(node);
        }
        return list;
    }

    function makeBox() {
        const node = h('div', { class: 'cal-evb', 'data-evbox': '' }, ...SIDES.map((side) => h('i', { class: `cal-evbar cal-evbar-${side}`, 'data-evbox-bar': side })));
        node.addEventListener('pointerdown', (event) => {
            const bar = event.target.closest('[data-evbox-bar]');
            begin(event, node, bar ? { kind: 'evbox', side: bar.dataset.evboxBar } : { kind: 'evmove' });
        });
        return node;
    }

    function makeBar() {
        const node = h('i', { class: 'cal-pbar' });
        node.addEventListener('pointerdown', (event) => begin(event, node, { kind: node.dataset.kind, side: node.dataset.side }));
        return node;
    }

    // ---- drawing ------------------------------------------------------------------------------

    function update(geometry, scale, selection) {
        last = { geometry, scale, selection };
        const part = geometry && scale > 0 && selection && selection.el === 'cal' ? selection.part || null : null;
        const frames = part ? geometry.frames : [];

        // event boxes
        const evRows = [];
        if (part === 'ev') {
            for (const frame of frames) for (const row of frame.rows) if (row.eventBox) evRows.push({ frame, row });
        }
        pool(boxes, evRows.length, makeBox).forEach((node, i) => {
            const { frame, row } = evRows[i];
            place(node, row.eventBox, scale);
            node.dataset.frame = String(frame.index);
            node.dataset.date = row.date;
            node.classList.toggle('over', row.eventOverflow === true);
        });

        // icon cells
        const cellRects = [];
        const barSpecs = [];
        const rowsSpan = (frame) => {
            const first = frame.rows[0];
            const lastRow = frame.rows[frame.rows.length - 1];
            return first ? { y: first.y, h: lastRow.y + lastRow.h - first.y } : null;
        };
        if (part === 'icon') {
            const chosen = Number(store.getState().ui.iconCell) || 0;
            for (const frame of frames) {
                const zone = frame.iconZone;
                const span = rowsSpan(frame);
                if (!zone || !span) continue;
                for (const row of frame.rows) {
                    if (row.iconCells.length) {
                        row.iconCells.forEach((rect, index) => cellRects.push({ rect, index, frame, row, on: index === chosen }));
                    } else {
                        // row mode (compact rows): one slot per reserved icon, the whole row height
                        const count = Math.max(1, Math.round(zone.w / zone.cellW));
                        for (let index = 0; index < count; index++) {
                            cellRects.push({ rect: { x: zone.x + index * (zone.w / count), y: row.y + 0.6, w: zone.w / count, h: Math.max(0.5, row.h - 1.2) }, index, frame, row, on: false });
                        }
                    }
                }
                barSpecs.push({ kind: 'iconcell', side: 'start', frame, x: zone.x, ...span });
                barSpecs.push({ kind: 'iconcell', side: 'end', frame, x: zone.x + zone.w, ...span });
            }
        }
        pool(cells, cellRects.length, () => h('span', { class: 'cal-icell', 'data-iconcell': '' })).forEach((node, i) => {
            const { rect, index, frame, row, on } = cellRects[i];
            place(node, rect, scale);
            node.dataset.cell = String(index);
            node.dataset.frame = String(frame.index);
            node.dataset.date = row.date;
            node.classList.toggle('on', on);
        });

        // holiday wrap
        if (part === 'hol') {
            for (const frame of frames) {
                const span = rowsSpan(frame);
                if (!span || frame.compact || !frame.holWrap) continue;
                barSpecs.push({ kind: 'holwrap', side: 'start', frame, x: frame.holWrap.x, ...span });
                barSpecs.push({ kind: 'holwrap', side: 'end', frame, x: frame.holWrap.x + frame.holWrap.w, ...span });
            }
        }
        pool(bars, barSpecs.length, makeBar).forEach((node, i) => {
            const spec = barSpecs[i];
            node.style.left = px(spec.x * scale);
            node.style.top = px(spec.y * scale);
            node.style.height = px(spec.h * scale);
            node.className = `cal-pbar cal-pbar-${spec.kind}`;
            node.dataset.kind = spec.kind;
            node.dataset.side = spec.side;
            node.dataset.frame = String(spec.frame.index);
            if (spec.kind === 'iconcell') {
                node.dataset.iconcellBar = spec.side;
                delete node.dataset.holwrapBar;
            } else {
                node.dataset.holwrapBar = spec.side;
                delete node.dataset.iconcellBar;
            }
        });
    }

    // ---- drags --------------------------------------------------------------------------------

    function begin(event, node, what) {
        if (event.button !== 0) return;
        // the stage must not read this press as "move the Kalendárium"
        event.stopPropagation();
        event.preventDefault();
        if (gesture) return;
        const { geometry, scale } = last;
        const frameIndex = Number(node.dataset.frame) || 0;
        const frame = geometry ? geometry.frames.find((f) => f.index === frameIndex) : null;
        const layout = store.getState().doc.design.layout;
        if (!frame || !layout || !(scale > 0)) return;

        const g = { ...what, node, pointerId: event.pointerId, x: event.clientX, y: event.clientY, scale, started: false, zoneLeft: frame.zoneLeft, mirrored: frame.mirrored };
        if (g.kind === 'evbox') {
            const row = frame.rows.find((r) => r.date === node.dataset.date);
            g.base = layout.styles.ev.box || (row ? defaultEventBox(frame, row) : null);
            if (!g.base) return;
        } else if (g.kind === 'evmove') {
            g.base = layout.styles.offsets.ev || null;
        } else if (g.kind === 'iconcell') {
            if (!frame.iconZone) return;
            g.base = frame.iconZone.cellW;
            g.cols = Math.max(1, Math.round(frame.iconZone.w / frame.iconZone.cellW));
        } else if (g.kind === 'holwrap') {
            g.base = frame.holWrap.w;
            g.max = roundMm(frame.textColumn.w);
        } else {
            return;
        }
        gesture = g;
        try {
            node.setPointerCapture(event.pointerId);
        } catch (e) {
            // a synthetic event without an active pointer: the window listeners still follow it
        }
        node.classList.add('drag');
        window.addEventListener('pointermove', onMove);
        window.addEventListener('pointerup', onUp);
        window.addEventListener('pointercancel', cancel);
        window.addEventListener('keydown', onKey, true);
        window.addEventListener('blur', cancel);
    }

    /** The layout after dragging by (dx, dy) mm, always computed from the value at pointer down. */
    function applyDrag(layout, g, dx, dy, alt) {
        if (g.kind === 'evbox') {
            // the bars are physical, the stored box runs in the flow direction of its frame
            const flowSide = g.side === 'n' || g.side === 's' ? g.side : (g.side === 'e') === g.zoneLeft ? 'e' : 'w';
            return setStyle(layout, 'ev.box', resizeEventBox(g.base, flowSide, g.zoneLeft ? dx : -dx, dy));
        }
        if (g.kind === 'evmove') {
            const offset = dragPartOffset(g.base, dx, dy, !alt, g.mirrored);
            const offsets = { ...layout.styles.offsets };
            if (offset) offsets.ev = offset;
            else delete offsets.ev;
            return setStyle(layout, 'offsets', offsets);
        }
        // a bar dragged outwards makes its zone wider
        const wider = g.side === 'end' ? dx : -dx;
        if (g.kind === 'iconcell') return setStyle(layout, 'cal.iconCellW', resizeIconCell(g.base, g.cols, wider));
        return setStyle(layout, 'hol.wrapWidth', Math.min(Math.max(g.max, K.HOL_WRAP_MIN), resizeHolWrap(g.base, wider)));
    }

    function onMove(event) {
        const g = gesture;
        if (!g || event.pointerId !== g.pointerId) return;
        if (!g.started) {
            if (Math.hypot(event.clientX - g.x, event.clientY - g.y) < K.DRAG_PX) return;
            g.started = true;
            store.beginChange();
        }
        const dx = (event.clientX - g.x) / g.scale;
        const dy = (event.clientY - g.y) / g.scale;
        store.updateLive((layout) => {
            const next = applyDrag(layout, g, dx, dy, event.altKey);
            return JSON.stringify(next.styles) === JSON.stringify(layout.styles) ? layout : next;
        });
    }

    function end() {
        const g = gesture;
        gesture = null;
        window.removeEventListener('pointermove', onMove);
        window.removeEventListener('pointerup', onUp);
        window.removeEventListener('pointercancel', cancel);
        window.removeEventListener('keydown', onKey, true);
        window.removeEventListener('blur', cancel);
        if (!g) return null;
        g.node.classList.remove('drag');
        try {
            if (g.node.hasPointerCapture(g.pointerId)) g.node.releasePointerCapture(g.pointerId);
        } catch (e) {
            // the node left the document together with its capture
        }
        return g;
    }

    function onUp(event) {
        if (!gesture || event.pointerId !== gesture.pointerId) return;
        const g = end();
        if (g.started) store.commitChange();
    }

    function cancel() {
        const g = end();
        if (g && g.started) store.cancelChange();
    }

    function onKey(event) {
        if (event.key !== 'Escape' || !gesture) return;
        // Esc puts the drag back; it must not also leave the part
        event.preventDefault();
        event.stopImmediatePropagation();
        cancel();
    }

    // the cell chosen in the Ikony panel is view state the stage does not render for
    const unsubscribe = store.subscribe((event) => {
        if (event.type === 'ui' && event.keys.includes('iconCell') && last.selection && last.selection.part === 'icon') update(last.geometry, last.scale, last.selection);
    });

    return {
        update,
        destroy() {
            cancel();
            unsubscribe();
            for (const list of [boxes, cells, bars]) for (const node of list.splice(0)) node.remove();
        },
    };
}
