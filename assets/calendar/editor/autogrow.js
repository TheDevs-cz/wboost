// Měsíc and Číslo týdne never overflow their frame (architecture §E.5, handoff §5.11). On a 14-day
// page each of them has one frame per week column (`month` / `month2`, `wk` / `wk2`); every frame
// grows on its own, to the widest text IT shows on any page.

import { K, bleedOf } from '../model/defaults.js';
import { boxKeysOf, frameAlign, isElementVisible } from '../model/elements.js';
import { fontSpec } from '../render/svg.js';
import { monthTextSpec } from '../render/week-layout.js';
import { clampMove, clampResize, pageBounds, roundBox } from './geometry.js';

const GROWING = ['month', 'wk'];

/**
 * Frame widths needed by the widest Měsíc / Číslo týdne text of all week pages (padding + margin
 * included), per box key: `{ month, wk }` and on a 14-day calendar also `{ month2, wk2 }`.
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
    for (const page of pages) {
        if (page.kind !== 'week') continue;
        page.week.frames.forEach((days, f) => {
            const spec = monthTextSpec(layout.styles, fonts, page.week.monthTexts[f]);
            pad = spec.pad;
            month[f] = Math.max(month[f], opts.measurer.width(spec.text, spec.font));
            wk[f] = Math.max(wk[f], opts.measurer.width(page.week.weekTexts[f], wkFont));
        });
    }
    boxKeysOf('month', doc.design).forEach((key, f) => {
        out[key] = month[f] > 0 ? month[f] + 2 * pad + 2 * K.TEXT_GROW_MARGIN : 0;
    });
    boxKeysOf('wk', doc.design).forEach((key, f) => {
        out[key] = wk[f] > 0 ? wk[f] + 2 * K.TEXT_GROW_MARGIN : 0;
    });
    return out;
}

/** Grows the frames of Měsíc / Číslo týdne to the needed widths; `null` when nothing has to grow. */
export function autoGrow(layout, widths, design) {
    const bounds = pageBounds(design.page, bleedOf(design.print));
    const probe = { ...design, layout };
    let boxes = null;
    for (const el of GROWING) {
        if (!isElementVisible(probe, el)) continue;
        boxKeysOf(el, design).forEach((key, f) => {
            const box = layout.boxes[key];
            const need = widths[key] || 0;
            if (!box || need <= box.w + 0.05) return;
            const extra = need - box.w;
            // away from the alignment anchor; the second frame is aligned as the mirror of the first
            const align = frameAlign(layout.styles[el].align, f);
            let grown = { ...box, w: need, x: align === 'end' ? box.x - extra : align === 'middle' ? box.x - extra / 2 : box.x };
            // keep inside the bounds: shift first, then shrink
            grown = clampResize(clampMove(grown, bounds), bounds);
            // round outwards so the rounded frame still holds the text
            const left = Math.max(bounds.x0, Math.floor(grown.x * 10 + 1e-6) / 10);
            const right = Math.min(bounds.x1, Math.ceil((grown.x + grown.w) * 10 - 1e-6) / 10);
            grown = roundBox({ ...grown, x: left, w: right - left });
            if (grown.w <= box.w && grown.x === box.x) return;
            boxes = boxes || { ...layout.boxes };
            boxes[key] = grown;
        });
    }
    return boxes ? { ...layout, boxes } : null;
}
