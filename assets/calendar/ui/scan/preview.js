// „Naskenovat design“ — the review's pictures and facts (ux.md C4).
//
// Pure except `cropPicture()` (a canvas). Nothing here is saved: the proposal is drawn with the
// calendar's own renderer on a document that exists only for the preview — the calendar's
// document with the proposed layout, the ticked switches, the pictures held for the proposal
// (temporary asset entries) and the photo region of the uploaded picture inside the photo box.

import { normalizeLayout } from '../../model/normalize.js';
import { buildPages, weekPages } from '../../generate/pages.js';
import { renderThumb } from '../thumb.js';

const PHOTO = 'scan-preview-photo';
const slotAsset = (slot) => `scan-preview-${slot}`;

/** Where a slot's picture goes in `styles` (the server's `CalendarScanProposal::SLOTS`). */
function placeSlot(styles, slot, id) {
    if (slot === 'logo' && styles.logo) styles.logo = { ...styles.logo, asset: id };
    if (slot === 'header' && styles.head && styles.head.bg) styles.head = { ...styles.head, bg: { ...styles.head.bg, asset: id, imageOn: true } };
    if (slot === 'background' && styles.bg) styles.bg = { ...styles.bg, asset: id, imageOn: true };
}

/**
 * The document and the asset map the preview draws.
 *
 * @param doc       the store's Doc
 * @param proposal  the proposal document of `GET proposal`
 * @param env       the workspace `env` (fonts, assets)
 * @param o.ticked  feature keys the user ticked
 * @param o.photo   `{ url, width, height }` — the photo region of the original, or null
 * @returns `{ doc, assets, page }` — `page` is the sample week page (null when there is none)
 */
export function previewDocument(doc, proposal, env, { ticked = [], photo = null } = {}) {
    const d = doc.design;
    const assets = { ...(env.assets || {}) };
    const layout = normalizeLayout(JSON.parse(JSON.stringify(proposal.layout)), d.type, d.page, env);
    const styles = { ...layout.styles };
    for (const item of proposal.pictures || []) {
        if (!item.picture) continue;
        const id = slotAsset(item.slot);
        assets[id] = { url: item.picture.url, previewUrl: null, width: item.picture.width, height: item.picture.height, mime: 'image/png', name: item.picture.name, description: null, kind: item.slot === 'logo' ? 'logo' : 'image' };
        placeSlot(styles, item.slot, id);
    }
    const features = { ...d.features };
    for (const key of ticked) if (key in features) features[key] = true;
    const content = { ...doc.content };
    const next = { ...doc, design: { ...d, preset: proposal.preset, layout: { ...layout, styles }, features }, content };
    const pages = weekPages(buildPages(next));
    const page = pages.find((p) => p.key === proposal.page) || pages[0] || null;
    if (photo && page) {
        assets[PHOTO] = { url: photo.url, previewUrl: null, width: photo.width, height: photo.height, mime: 'image/jpeg', name: '', description: null, kind: 'photo' };
        content.pagePhotos = { ...(content.pagePhotos || {}), [page.key]: { asset: PHOTO, zoom: 1, px: 0.5, py: 0.5 } };
    }
    return { doc: next, assets, page };
}

/** SVG markup of the proposal on its sample week (`''` when it cannot be drawn). */
export function previewMarkup(doc, proposal, ctx, opts) {
    const built = previewDocument(doc, proposal, ctx.env, opts);
    if (!built.page) return '';
    return renderThumb(built.doc, built.page, { env: { assets: built.assets, fonts: ctx.env.fonts || [] }, measurer: ctx.measurer }, { placeholders: 'all' });
}

/** `11.–17. 1. 2027` of the sample week the preview shows. */
export function sampleWeekLabel(doc, proposal) {
    const pages = weekPages(buildPages(doc));
    const page = pages.find((p) => p.key === proposal.page) || pages[0];
    if (!page || !page.week || !page.week.days || !page.week.days.length) return '';
    const [fy, fm, fd] = page.week.days[0].split('-').map(Number);
    const [ty, tm, td] = page.week.days[page.week.days.length - 1].split('-').map(Number);
    if (fy === ty && fm === tm) return `${fd}.–${td}. ${tm}. ${ty}`;
    return fy === ty ? `${fd}. ${fm}. – ${td}. ${tm}. ${ty}` : `${fd}. ${fm}. ${fy} – ${td}. ${tm}. ${ty}`;
}

/**
 * The part of the uploaded picture the preview puts into the photo box: `[x, y, w, h]` in source
 * pixels — the WHOLE proposed photo box, bleed included, so its ratio is the box's and the
 * renderer's cover fit places it 1:1. The scanner's `photoBox` is clipped to the picture: a box
 * running 5 mm into the bleed got the trim-only cut stretched over it (~4.5 % larger, shifted), and
 * what is printed in the photo — the source's caption plate — showed beside the proposed one (K16).
 * Falls back to `photoBox` when the layout or the page box is missing.
 *
 * @param proposal  `{ pageBox, photoBox, layout }` of `GET proposal`
 * @param page      `design.page` (`{ width, height }` mm)
 */
export function photoCutBox(proposal, page) {
    const box = proposal && proposal.layout && proposal.layout.boxes ? proposal.layout.boxes.photo : null;
    const pb = proposal ? proposal.pageBox : null;
    const fallback = (proposal && proposal.photoBox) || null;
    if (!box || !Array.isArray(pb) || pb.length !== 4 || !page || !(page.width > 0) || !(page.height > 0)) return fallback;
    if (![box.x, box.y, box.w, box.h].every(Number.isFinite) || box.w <= 0 || box.h <= 0) return fallback;
    const sx = pb[2] / page.width;
    const sy = pb[3] / page.height;
    return [pb[0] + box.x * sx, pb[1] + box.y * sy, box.w * sx, box.h * sy];
}

/**
 * Cuts `box` (`[x, y, w, h]` in source pixels) out of a same-origin picture → `{ url, width, height }`
 * (a blob URL the caller revokes), or null when the picture cannot be read. A box reaching past the
 * picture (the bleed of `photoCutBox`) is filled by stretching the picture's edge pixels — only the
 * trimmed page is shown, the filler just keeps the cut's ratio. `ratio` (w / h, optional) is the
 * ratio of the box in page mm: a picture of another ratio than the page is stretched onto the page,
 * as the scanner reads it, so the cut is too.
 */
export async function cropPicture(src, box, ratio = null) {
    if (!box || box[2] < 2 || box[3] < 2) return null;
    try {
        const img = new Image();
        img.decoding = 'async';
        img.src = src;
        await img.decode();
        const [x, y, w, h] = box.map((v) => Math.round(v));
        const scale = Math.min(1, 1600 / Math.max(w, h));
        const canvas = document.createElement('canvas');
        canvas.width = Math.max(1, Math.round(w * scale));
        canvas.height = Math.max(1, Math.round(ratio > 0 ? canvas.width / ratio : h * scale));
        const sy = canvas.height / h;
        const g = canvas.getContext('2d');
        const W = img.naturalWidth;
        const H = img.naturalHeight;
        // the part of the box inside the picture, in source px, and where it goes on the canvas
        const ix0 = Math.max(0, x), iy0 = Math.max(0, y);
        const ix1 = Math.min(W, x + w), iy1 = Math.min(H, y + h);
        if (ix1 - ix0 < 1 || iy1 - iy0 < 1) return null;
        const cx = (v) => (v - x) * scale, cy = (v) => (v - y) * sy;
        // edges first (stretched 1 px strips), the picture over them
        const cols = [[x, ix0, ix0, 1], [ix0, ix1, ix0, ix1 - ix0], [ix1, x + w, ix1 - 1, 1]];
        const rows = [[y, iy0, iy0, 1], [iy0, iy1, iy0, iy1 - iy0], [iy1, y + h, iy1 - 1, 1]];
        for (const [dx0, dx1, sxp, sw] of cols) {
            if (dx1 - dx0 <= 0) continue;
            for (const [dy0, dy1, syp, sh] of rows) {
                if (dy1 - dy0 <= 0) continue;
                g.drawImage(img, sxp, syp, sw, sh, cx(dx0), cy(dy0), cx(dx1) - cx(dx0), cy(dy1) - cy(dy0));
            }
        }
        const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.86));
        return blob ? { url: URL.createObjectURL(blob), width: canvas.width, height: canvas.height } : null;
    } catch (e) {
        return null;
    }
}

// ---- the fact beside each recognised element (derived from the layout, never sent) ----------

const HEX = /^#[0-9A-F]{6}$/i;

function side(box, page) {
    const cx = box.x + box.w / 2;
    if (box.w >= page.width * 0.8) return 'přes celou šířku';
    if (cx < page.width * 0.4) return 'vlevo';
    if (cx > page.width * 0.6) return 'vpravo';
    return 'uprostřed';
}

function corner(box, page) {
    const cy = box.y + box.h / 2;
    const vertical = cy < page.height * 0.4 ? 'nahoře' : cy > page.height * 0.6 ? 'dole' : '';
    return [side(box, page), vertical].filter(Boolean).join(' ');
}

function fillFact(fill) {
    if (!fill) return '';
    if (fill.imageOn && fill.asset) return 'obrázek';
    if (fill.gradient && fill.gradient.on) return 'barevný přechod';
    if (fill.colorOn !== false && HEX.test(fill.color || '')) return `barva ${String(fill.color).toUpperCase()}`;
    return '';
}

/**
 * A short Czech phrase about where / how an element sits in the proposed layout.
 *
 * @param element  `photo` | `cap` | `cal` | `month` | `wk` | `head` | `logo` | `qr` | `bg`
 * @param layout   the proposal's layout
 * @param page     `design.page` (`{ width, height }`)
 */
export function elementFact(element, layout, page) {
    const boxes = layout.boxes || {};
    const styles = layout.styles || {};
    const box = boxes[element];
    switch (element) {
        case 'photo': {
            if (!box) return '';
            const bleed = box.x <= 0 || box.x + box.w >= page.width || box.y + box.h >= page.height;
            return `${side(box, page)}${bleed ? ', do okraje' : ''}`;
        }
        case 'cal': {
            // 14 days: two week columns, the second drawn mirrored — numbers at the outer page edges or beside the photo
            if (boxes.cal2) {
                const number = { start: 'čísla u okrajů stránky', middle: 'čísla uprostřed', end: 'čísla u fotografie' }[styles.cal && styles.cal.numberSide] || '';
                return ['dva sloupce', number].filter(Boolean).join(', ');
            }
            const number = { start: 'čísla vlevo', middle: 'čísla uprostřed', end: 'čísla vpravo' }[styles.cal && styles.cal.numberSide] || '';
            return [box ? side(box, page) : '', number].filter(Boolean).join(', ');
        }
        case 'head': {
            const fill = fillFact(styles.head && styles.head.bg);
            return fill ? `pruh — ${fill}` : 'pruh';
        }
        case 'month':
            return [box ? (boxes.month2 ? 'nad oběma sloupci' : corner(box, page)) : '', styles.month && styles.month.upper ? 'velká písmena' : ''].filter(Boolean).join(', ');
        case 'bg':
            return fillFact(styles.bg);
        case 'wk':
            return box ? (boxes.wk2 ? 'u obou sloupců' : corner(box, page)) : '';
        case 'cap':
        case 'logo':
        case 'qr':
            return box ? corner(box, page) : '';
        default:
            return '';
    }
}
