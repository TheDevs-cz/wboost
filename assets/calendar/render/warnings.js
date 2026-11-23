// Page warnings (architecture §E.12). Derived from the layout and the asset map only.
// `Warning = { pageIndex, kind, target, facts, message }` — the wording lives in `warning-text.js`.

import { createDayContext } from '../generate/day.js';
import { frameAlign, isElementVisible } from '../model/elements.js';
import { layoutWeekPage, placeMonthText } from './week-layout.js';
import { printLanguages } from '../generate/languages.js';
import { specialPageIssues } from './special.js';
import { warningMessage } from './warning-text.js';

/** A warning with its facts; `message` is the full text of `render/warning-text.js` (MCP, tooltips). */
const warn = (page, kind, target, facts) => {
    const warning = { pageIndex: page.index, kind, target, facts };
    warning.message = warningMessage(warning);
    return warning;
};

/** Effective dpi of a photo placed with cover scale × zoom into a frame (mm, bleed part included). */
export function photoDpi(assetInfo, frameBox, zoom) {
    const mmPerPx = Math.max(frameBox.w / assetInfo.width, frameBox.h / assetInfo.height) * (zoom || 1);
    return Math.round(25.4 / mmPerPx);
}

/** Below this effective resolution a week photo gets the „Nízká kvalita fotky“ warning. */
export const PHOTO_MIN_DPI = 200;

/**
 * The effective dpi of a week page's photo when it is under `PHOTO_MIN_DPI`, else `null` (also for
 * an unknown pixel size or a hidden photo element). Shared by the page warnings and „Fotky a popisky“.
 */
export function lowPhotoDpi(design, photo, assetInfo) {
    if (!design.layout || !photo || !assetInfo || !(assetInfo.width > 0 && assetInfo.height > 0) || !isElementVisible(design, 'photo')) return null;
    const dpi = photoDpi(assetInfo, design.layout.boxes.photo, photo.zoom);
    return dpi < PHOTO_MIN_DPI ? dpi : null;
}

export function pageWarnings(doc, page, opts) {
    const out = [];
    const design = doc.design;
    if (!design.layout) return out;
    if (page.kind !== 'week') {
        // cover, back and special pages: a text that does not fit even at its smallest size
        for (const issue of specialPageIssues(doc, page, opts)) out.push(warn(page, 'overflow', { el: 'page' }, { issue }));
        return out;
    }
    const assets = (opts.env && opts.env.assets) || {};
    const photo = doc.content.pagePhotos[page.key];
    const asset = photo ? assets[photo.asset] : null;
    const box = design.layout.boxes.photo;
    if (asset && asset.width > 0 && asset.height > 0 && isElementVisible(design, 'photo')) {
        if (asset.height > asset.width && box.w > box.h) {
            // the visible share of the photo's height: the frame's height over the drawn height (cover × zoom)
            const drawnH = Math.max(box.w * (asset.height / asset.width), box.h) * Math.max(1, photo.zoom || 1);
            out.push(warn(page, 'portrait', { el: 'photo' }, { width: asset.width, height: asset.height, keep: Math.min(1, box.h / drawnH) }));
        }
        const dpi = lowPhotoDpi(design, photo, asset);
        if (dpi !== null) {
            const zoom = photo.zoom || 1;
            out.push(warn(page, 'dpi', { el: 'photo' }, { dpi, width: asset.width, height: asset.height, zoom, frameW: box.w, frameH: box.h, dpiAt100: photoDpi(asset, box, 1) }));
        }
    }
    const geometry = layoutWeekPage(doc, page, opts);
    for (const frame of geometry.frames) {
        for (const row of frame.rows) {
            if (!frame.compact && row.iconsDrawn < row.iconsTotal) {
                out.push(warn(page, 'icons', { date: row.date }, { date: row.date, total: row.iconsTotal, drawn: row.iconsDrawn }));
            }
            if (row.eventOverflow) {
                out.push(warn(page, 'event', { date: row.date }, { date: row.date, count: row.info.events.length + row.info.eventsDropped }));
            }
        }
    }
    // „Názvy dnů“ wider than the row allows (K15b): drawn anyway, never cut — once per week column
    for (const frame of geometry.frames) {
        if (frame.dayOverflow) out.push(warn(page, 'dayNames', { el: 'cal', frame: frame.index }, { format: frame.dayFormat, multilingual: printLanguages(design).length > 1 }));
    }
    // a multilingual Měsíc that needs more room than its frame has (K15b)
    if (printLanguages(design).length > 1 && isElementVisible(design, 'month')) {
        page.week.monthTexts.forEach((text, f) => {
            const box = design.layout.boxes[f === 1 ? 'month2' : 'month'];
            if (!box) return;
            const placed = placeMonthText(design.layout.styles, (opts.env && opts.env.fonts) || [], text, box, frameAlign(design.layout.styles.month.align, f), opts.measurer, true);
            if (placed.overflow) out.push(warn(page, 'month', { el: 'month', frame: f }, { lines: placed.lines.length }));
        });
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
