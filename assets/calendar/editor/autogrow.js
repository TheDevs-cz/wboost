// Měsíc and Číslo týdne never overflow their frame (architecture §E.5, handoff §5.11). On a 14-day
// page each of them has one frame per week column (`month` / `month2`, `wk` / `wk2`); every frame
// grows on its own, to the widest text IT shows on any page.

import { K, bleedOf } from '../model/defaults.js';
import { boxKeysOf, frameAlign, isElementVisible } from '../model/elements.js';
import { fontSpec } from '../render/svg.js';
import { MONTH_LINE_HEIGHT, monthLines, monthTextSpec } from '../render/week-layout.js';
import { printLanguages } from '../generate/languages.js';
import { clampMove, clampResize, pageBounds, roundBox } from './geometry.js';

const GROWING = ['month', 'wk'];

/**
 * Frame widths needed by the widest Měsíc / Číslo týdne text of all week pages (padding + margin
 * included), per box key: `{ month, wk }` and on a 14-day calendar also `{ month2, wk2 }`.
 *
 * A calendar with extra languages also gets `monthLines(key, room)` — the most lines a Měsíc text
 * of that frame needs in a text area `room` mm wide (one per month, more when a line wraps) — and
 * `monthLineHeight` (mm); `autoGrow` grows the frame's height to them.
 */
export function maxTextWidths(doc, pages, opts) {
    const layout = doc.design.layout;
    const out = { month: 0, wk: 0 };
    if (!layout) return out;
    const fonts = (opts.env && opts.env.fonts) || [];
    const wkFont = fontSpec(layout.styles.wk, fonts);
    const month = [0, 0];
    const wk = [0, 0];
    let pad = 0;
    const multilingual = printLanguages(doc.design).length > 1;
    const monthSpecs = [[], []];
    for (const page of pages) {
        if (page.kind !== 'week') continue;
        page.week.frames.forEach((days, f) => {
            const spec = monthTextSpec(layout.styles, fonts, page.week.monthTexts[f]);
            pad = spec.pad;
            for (const line of spec.text.split('\n')) month[f] = Math.max(month[f], opts.measurer.width(line, spec.font));
            if (multilingual) monthSpecs[f].push(spec);
            wk[f] = Math.max(wk[f], opts.measurer.width(page.week.weekTexts[f], wkFont));
        });
    }
    boxKeysOf('month', doc.design).forEach((key, f) => {
        out[key] = month[f] > 0 ? month[f] + 2 * pad + 2 * K.TEXT_GROW_MARGIN : 0;
    });
    boxKeysOf('wk', doc.design).forEach((key, f) => {
        out[key] = wk[f] > 0 ? wk[f] + 2 * K.TEXT_GROW_MARGIN : 0;
    });
    if (multilingual) {
        const keys = boxKeysOf('month', doc.design);
        out.monthPad = pad;
        out.monthLineHeight = MONTH_LINE_HEIGHT * monthTextSpec(layout.styles, fonts, '').font.sizeMm;
        out.monthLines = (key, room) => {
            const f = keys.indexOf(key);
            let most = 0;
            for (const spec of monthSpecs[f] || []) most = Math.max(most, monthLines(spec.text, spec.font, room, opts.measurer, true).length);
            return most;
        };
    }
    return out;
}

/**
 * The bounds a Měsíc frame of a multilingual 14-day calendar may grow in: its own half of the page
 * (K.SECOND_FRAME_GAP short of the centre line), so the two week columns' headers never collide —
 * a text wider than that wraps instead (`monthLines`). A frame already over the line keeps its extent.
 */
function halfBounds(bounds, box, W) {
    const half = W / 2;
    if (box.x + box.w / 2 <= half) return { ...bounds, x1: Math.max(box.x + box.w, Math.min(bounds.x1, half - K.SECOND_FRAME_GAP)) };
    return { ...bounds, x0: Math.min(box.x, Math.max(bounds.x0, half + K.SECOND_FRAME_GAP)) };
}

/** The nearest edge of the Kalendárium / photo frames below (`down`) or above a Měsíc frame that they share columns with. */
function verticalStop(boxes, frame, down) {
    let stop = down ? Infinity : -Infinity;
    for (const key of ['cal', 'cal2', 'photo']) {
        const b = boxes[key];
        if (!b || b.x >= frame.x + frame.w || b.x + b.w <= frame.x) continue;
        if (down && b.y >= frame.y + frame.h - 0.05) stop = Math.min(stop, b.y);
        if (!down && b.y + b.h <= frame.y + 0.05) stop = Math.max(stop, b.y + b.h);
    }
    return stop;
}

/**
 * Grows the frames of Měsíc / Číslo týdne to the needed widths — and, for a multilingual Měsíc of
 * several lines, the height (away from the nearer page edge, the frame's centre stays on its half
 * of the page); `null` when nothing has to grow.
 */
export function autoGrow(layout, widths, design) {
    const pageBox = pageBounds(design.page, bleedOf(design.print));
    const probe = { ...design, layout };
    const halves = design.type === 'fortnight' && typeof widths.monthLines === 'function';
    let boxes = null;
    for (const el of GROWING) {
        if (!isElementVisible(probe, el)) continue;
        boxKeysOf(el, design).forEach((key, f) => {
            const box = layout.boxes[key];
            if (!box) return;
            const lined = el === 'month' && typeof widths.monthLines === 'function';
            const bounds = halves && el === 'month' ? halfBounds(pageBox, box, design.page.width) : pageBox;
            // keep inside the bounds: shift first, then shrink — a multilingual Měsíc is never shifted
            // (its text wraps instead), it only grows away from its anchor as far as the bounds allow
            const keepIn = (b) => (lined ? clampResize(b, bounds) : clampResize(clampMove(b, bounds), bounds));
            const need = widths[key] || 0;
            let grown = { ...box };
            if (need > box.w + 0.05) {
                const extra = need - box.w;
                // away from the alignment anchor; the second frame is aligned as the mirror of the first
                const align = frameAlign(layout.styles[el].align, f);
                grown = keepIn({ ...box, w: need, x: align === 'end' ? box.x - extra : align === 'middle' ? box.x - extra / 2 : box.x });
            }
            if (lined) {
                const pad = widths.monthPad || 0;
                const lines = widths.monthLines(key, Math.max(0, grown.w - 2 * pad));
                const needH = lines > 1 ? lines * widths.monthLineHeight + 2 * pad + 2 * K.TEXT_GROW_MARGIN : 0;
                if (needH > box.h + 0.05) {
                    // towards the middle of the page (the frame's edge at the nearer page edge stays),
                    // but never over the Kalendárium or the photo: what still does not fit is warned about
                    const down = box.y + box.h / 2 <= design.page.height / 2;
                    const stop = verticalStop(layout.boxes, grown, down);
                    const room = down ? Math.min(needH, stop - box.y) : Math.min(needH, box.y + box.h - stop);
                    if (room > box.h + 0.05) grown = keepIn({ ...grown, h: room, y: down ? box.y : box.y + box.h - room });
                }
            }
            if (grown.w === box.w && grown.h === box.h && grown.x === box.x && grown.y === box.y) return;
            // round outwards so the rounded frame still holds the text
            const left = Math.max(bounds.x0, Math.floor(grown.x * 10 + 1e-6) / 10);
            const right = Math.min(bounds.x1, Math.ceil((grown.x + grown.w) * 10 - 1e-6) / 10);
            const topEdge = Math.max(bounds.y0, Math.floor(grown.y * 10 + 1e-6) / 10);
            const bottom = Math.min(bounds.y1, Math.ceil((grown.y + grown.h) * 10 - 1e-6) / 10);
            grown = grown.h === box.h ? roundBox({ ...grown, x: left, w: right - left }) : roundBox({ ...grown, x: left, w: right - left, y: topEdge, h: bottom - topEdge });
            if (grown.w <= box.w && grown.x === box.x && grown.h <= box.h && grown.y === box.y) return;
            boxes = boxes || { ...layout.boxes };
            boxes[key] = grown;
        });
    }
    return boxes ? { ...layout, boxes } : null;
}
