// What size a picture of a layout place should have (feedback K6 / K7) — said on the scan's place
// cards (ui/scan/views.js) and in step 3 where the picture is actually chosen (ui/editor/props.js).
//
//   pictureSize({ boxes, page, print, place }) → { box, text } | null
//     place   'logo' | 'head' | 'bg' (the scan's slots `header` / `background` map to `head` / `bg`)
//     box     the place's box in mm, cut at the bleed edge — what is printed; `bg` is the whole page
//             plus bleed
//     text    „Rozměr 320 × 32 mm včetně spadávky 5 mm · ideálně 3780 × 378 px, nejméně 2520 × 252 px“;
//             with the bleed off, a box at the page edge says „(spadávka je vypnutá)“; the logo is
//             fitted inside its box: „Místo pro logo 34 × 11 mm · SVG, nebo PNG s průhledností
//             (ideálně 402 × 130 px)“
//   pictureDpi(picture {width, height, name?}, box, fit) → effective dpi in the place, null for an SVG
//
// MIN_PRINT_DPI / PRINT_DPI mirror the editor's dpi warning (render/warnings.js: under 200 dpi →
// „… (pro tisk aspoň 300 dpi)“). Mirrored, not imported from there: a constant moved into render/
// would change the export fingerprint and invalidate every stored PDF export.

import { bleedOf } from '../model/defaults.js';
import { photoDpi } from '../render/warnings.js';

export const MIN_PRINT_DPI = 200;
export const PRINT_DPI = 300;

const SLOT_PLACE = { header: 'head', background: 'bg' };
/** The scan's slot name → the layout's element key. */
export const placeOfSlot = (slot) => SLOT_PLACE[slot] || slot;

const fmt = (n) => String(Math.round(n * 10) / 10).replace('.', ',');

export function pictureSize({ boxes, page, print, place }) {
    const bleed = bleedOf(print);
    const raw = place === 'bg' ? { x: -bleed, y: -bleed, w: page.width + 2 * bleed, h: page.height + 2 * bleed } : boxes && boxes[place];
    if (!raw || !(raw.w > 0 && raw.h > 0)) return null;
    // what is printed: the box cut at the bleed edge (a layout made with another bleed may reach further)
    const x0 = Math.max(raw.x, -bleed);
    const y0 = Math.max(raw.y, -bleed);
    const box = { x: x0, y: y0, w: Math.min(raw.x + raw.w, page.width + bleed) - x0, h: Math.min(raw.y + raw.h, page.height + bleed) - y0 };
    if (!(box.w > 0 && box.h > 0)) return null;
    const eps = 0.01;
    const over = box.x < -eps || box.y < -eps || box.x + box.w > page.width + eps || box.y + box.h > page.height + eps;
    const atEdge = box.x <= eps || box.y <= eps || box.x + box.w >= page.width - eps || box.y + box.h >= page.height - eps;
    const px = (dpi) => `${Math.ceil((box.w / 25.4) * dpi)} × ${Math.ceil((box.h / 25.4) * dpi)} px`;
    const bleedText = bleed > 0 && over ? ` včetně spadávky ${fmt(bleed)} mm` : bleed === 0 && atEdge ? ' (spadávka je vypnutá)' : '';
    const size = `${fmt(box.w)} × ${fmt(box.h)} mm${bleedText}`;
    const text = place === 'logo'
        ? `Místo pro logo ${size} · SVG, nebo PNG s průhledností (ideálně ${px(PRINT_DPI)})`
        : `Rozměr ${size} · ideálně ${px(PRINT_DPI)}, nejméně ${px(MIN_PRINT_DPI)}`;
    return { box, text };
}

export function pictureDpi(picture, box, fit) {
    if (!picture || !picture.width || !picture.height || /\.svg$/i.test(picture.name || '')) return null;
    if (fit !== 'contain') return photoDpi(picture, box, 1);
    return Math.round(Math.max(picture.width / (box.w / 25.4), picture.height / (box.h / 25.4)));
}
