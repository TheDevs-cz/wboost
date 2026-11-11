// Page warnings (architecture §E.12). Derived from the layout and the asset map only.

import { parts } from '../generate/dates.js';
import { createDayContext } from '../generate/day.js';
import { isElementVisible } from '../model/elements.js';
import { layoutWeekPage } from './week-layout.js';

/** Effective dpi of a photo placed with cover scale × zoom into a frame (mm, bleed part included). */
export function photoDpi(assetInfo, frameBox, zoom) {
    const mmPerPx = Math.max(frameBox.w / assetInfo.width, frameBox.h / assetInfo.height) * (zoom || 1);
    return Math.round(25.4 / mmPerPx);
}

export function pageWarnings(doc, page, opts) {
    const out = [];
    const design = doc.design;
    if (page.kind !== 'week' || !design.layout) return out;
    const assets = (opts.env && opts.env.assets) || {};
    const photo = doc.content.pagePhotos[page.key];
    const asset = photo ? assets[photo.asset] : null;
    const box = design.layout.boxes.photo;
    if (asset && asset.width > 0 && asset.height > 0 && isElementVisible(design, 'photo')) {
        if (asset.height > asset.width && box.w > box.h) {
            out.push({ pageIndex: page.index, kind: 'portrait', target: { el: 'photo' }, message: 'Fotka je na výšku — do rámečku na šířku se ořízne' });
        }
        const dpi = photoDpi(asset, box, photo.zoom);
        if (dpi < 200) {
            out.push({ pageIndex: page.index, kind: 'dpi', target: { el: 'photo' }, message: `Nízká kvalita fotky — ${dpi} dpi (pro tisk aspoň 300 dpi)` });
        }
    }
    const geometry = layoutWeekPage(doc, page, opts);
    for (const frame of geometry.frames) {
        for (const row of frame.rows) {
            const p = parts(row.date);
            if (!frame.compact && row.iconsDrawn < row.iconsTotal) {
                out.push({ pageIndex: page.index, kind: 'icons', target: { date: row.date }, message: `${p.d}. ${p.m}. — ${row.iconsTotal} ikony, vejdou se ${row.iconsDrawn}` });
            }
            if (row.eventOverflow) {
                const k = row.info.events.length + row.info.eventsDropped;
                out.push({ pageIndex: page.index, kind: 'event', target: { date: row.date }, message: `${p.d}. ${p.m}. — ${k > 1 ? `${k} akce` : 'text akce'} se nevejde do pole pro akce` });
            }
        }
    }
    return out;
}

/** Warnings of every page that has any, keyed by page index. */
export function allWarnings(doc, pages, opts) {
    const out = new Map();
    if (!doc.design.layout) return out;
    const o = { ...opts, dayContext: opts.dayContext || createDayContext(doc) };
    for (const page of pages) {
        const list = pageWarnings(doc, page, o);
        if (list.length) out.set(page.index, list);
    }
    return out;
}
