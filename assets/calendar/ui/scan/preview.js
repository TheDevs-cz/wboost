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
 * Cuts `box` (`[x, y, w, h]` in source pixels) out of a same-origin picture → `{ url, width, height }`
 * (a blob URL the caller revokes), or null when the picture cannot be read.
 */
export async function cropPicture(src, box) {
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
        canvas.height = Math.max(1, Math.round(h * scale));
        canvas.getContext('2d').drawImage(img, x, y, w, h, 0, 0, canvas.width, canvas.height);
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
