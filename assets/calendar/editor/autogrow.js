// Měsíc and Číslo týdne never overflow their frame (architecture §E.5, handoff §5.11).

import { K, bleedOf } from '../model/defaults.js';
import { isElementVisible } from '../model/elements.js';
import { fontSpec } from '../render/svg.js';
import { monthTextSpec } from '../render/week-layout.js';
import { clampMove, clampResize, pageBounds, roundBox } from './geometry.js';

/** Frame widths needed by the widest Měsíc / Číslo týdne text of all week pages (padding + margin included). */
export function maxTextWidths(doc, pages, opts) {
    const layout = doc.design.layout;
    const out = { month: 0, wk: 0 };
    if (!layout) return out;
    const fonts = (opts.env && opts.env.fonts) || [];
    const wkFont = fontSpec(layout.styles.wk, fonts);
    let month = 0;
    let wk = 0;
    let pad = 0;
    for (const page of pages) {
        if (page.kind !== 'week') continue;
        const spec = monthTextSpec(layout.styles, fonts, page.week.monthText);
        pad = spec.pad;
        month = Math.max(month, opts.measurer.width(spec.text, spec.font));
        wk = Math.max(wk, opts.measurer.width(page.week.weekText, wkFont));
    }
    if (month > 0) out.month = month + 2 * pad + 2 * K.TEXT_GROW_MARGIN;
    if (wk > 0) out.wk = wk + 2 * K.TEXT_GROW_MARGIN;
    return out;
}

/** Grows the frames of `month` / `wk` to the needed widths; `null` when nothing has to grow. */
export function autoGrow(layout, widths, design) {
    const bounds = pageBounds(design.page, bleedOf(design.print));
    const probe = { ...design, layout };
    let boxes = null;
    for (const key of ['month', 'wk']) {
        const box = layout.boxes[key];
        const need = widths[key] || 0;
        if (!box || !isElementVisible(probe, key) || need <= box.w + 0.05) continue;
        const extra = need - box.w;
        const align = layout.styles[key].align;
        let grown = { ...box, w: need, x: align === 'end' ? box.x - extra : align === 'middle' ? box.x - extra / 2 : box.x };
        // keep inside the bounds: shift first, then shrink
        grown = clampResize(clampMove(grown, bounds), bounds);
        // round outwards so the rounded frame still holds the text
        const left = Math.max(bounds.x0, Math.floor(grown.x * 10 + 1e-6) / 10);
        const right = Math.min(bounds.x1, Math.ceil((grown.x + grown.w) * 10 - 1e-6) / 10);
        grown = roundBox({ ...grown, x: left, w: right - left });
        if (grown.w <= box.w && grown.x === box.x) continue;
        boxes = boxes || { ...layout.boxes };
        boxes[key] = grown;
    }
    return boxes ? { ...layout, boxes } : null;
}
