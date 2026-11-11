// Element / part vocabulary and visibility rules (architecture §A.1, §B.5, §D.2).

export const ELEMENTS = Object.freeze([
    { key: 'photo', label: 'Fotografie', icon: 'image', hideable: false, hasBox: true },
    { key: 'cap', label: 'Popisek fotografie', icon: 'type', hideable: true, hasBox: true },
    { key: 'logo', label: 'Logo', icon: 'star', hideable: true, hasBox: true },
    { key: 'cal', label: 'Kalendárium', icon: 'calendar', hideable: false, hasBox: true },
    { key: 'month', label: 'Měsíc', icon: 'type', hideable: true, hasBox: true },
    { key: 'wk', label: 'Číslo týdne', icon: 'type', hideable: true, hasBox: true },
    { key: 'head', label: 'Hlavička', icon: 'layers', hideable: false, hasBox: true },
    { key: 'qr', label: 'QR kód', icon: 'grid', hideable: true, hasBox: true },
    { key: 'bg', label: 'Pozadí', icon: 'image', hideable: false, hasBox: false },
]);

export const ELEMENT_KEYS = Object.freeze(ELEMENTS.map((e) => e.key));
export const BOX_KEYS = Object.freeze(['photo', 'cap', 'logo', 'head', 'month', 'month2', 'cal', 'cal2', 'wk', 'wk2', 'qr']);
/**
 * Second frames of a 14-day layout: box key → the box key of the first frame. The element of both
 * frames is the first key (`cal`, `month`, `wk`); the two frames share every style, the second one
 * is drawn horizontally mirrored (`flipRowStyles`, `frameAlign`).
 */
export const SECOND_FRAMES = Object.freeze({ cal2: 'cal', month2: 'month', wk2: 'wk' });
/** Elements that have one frame per week column on a 14-day page. */
export const FRAMED_ELEMENTS = Object.freeze(Object.values(SECOND_FRAMES));
/** Names of the two frames in the UI (selection tag, „Pozice a velikost“). */
export const FRAME_LABELS = Object.freeze(['1. týden', '2. týden']);
/**
 * Box keys that may carry `hidden` (cap's visibility is `features.captions`). A second frame never
 * carries it: the eye of Měsíc / Číslo týdne is the flag of the first frame and hides both.
 */
export const HIDEABLE_BOXES = Object.freeze(['logo', 'month', 'wk', 'qr']);
export const DEFAULT_ORDER = Object.freeze(['qr', 'logo', 'cap', 'wk', 'month', 'head', 'cal', 'photo']);

export const PARTS = Object.freeze([
    { key: 'num', label: 'Číslo — pracovní dny', group: 'Číslo dne' },
    { key: 'numSat', label: 'Číslo — sobota', group: 'Číslo dne' },
    { key: 'numSun', label: 'Číslo — neděle', group: 'Číslo dne' },
    { key: 'numHol', label: 'Číslo — svátky', group: 'Číslo dne' },
    { key: 'day', label: 'Den v týdnu' },
    { key: 'name', label: 'Jména' },
    { key: 'hol', label: 'Svátek' },
    { key: 'line', label: 'Linky' },
    { key: 'moon', label: 'Fáze měsíce' },
    { key: 'time', label: 'Změna času' },
    { key: 'ev', label: 'Akce' },
    { key: 'shift', label: 'Šichtovnice' },
    { key: 'waste', label: 'Odpady' },
    { key: 'icon', label: 'Ikony' },
]);

export const PART_KEYS = Object.freeze(PARTS.map((p) => p.key));

export const ICON_CATEGORIES = Object.freeze([
    { key: 'waste', label: 'Odpady', feature: 'waste' },
    { key: 'moon', label: 'Fáze měsíce', feature: 'moon' },
    { key: 'ev', label: 'Akce', feature: 'events' },
    { key: 'time', label: 'Změna času', feature: 'timeChange' },
    { key: 'season', label: 'Změna ročního období', feature: 'seasons' },
]);

export function elementLabel(key) {
    const found = ELEMENTS.find((e) => e.key === key);
    return found ? found.label : key;
}

export function partLabel(key) {
    const found = PARTS.find((p) => p.key === key);
    return found ? found.label : key;
}

export function boxKeysOf(elementKey, design) {
    if (elementKey === 'bg') return [];
    if (FRAMED_ELEMENTS.includes(elementKey) && design && design.type === 'fortnight') return [elementKey, `${elementKey}2`];
    return [elementKey];
}

export function elementOfBox(boxKey) {
    return SECOND_FRAMES[boxKey] || boxKey;
}

/** 0 for a first (or only) frame, 1 for a second frame. */
export function frameOfBox(boxKey) {
    return SECOND_FRAMES[boxKey] ? 1 : 0;
}

/** Horizontal alignment of a frame: the second frame mirrors the stored one (`middle` stays). */
export function frameAlign(align, frame) {
    if (frame !== 1) return align;
    return align === 'start' ? 'end' : align === 'end' ? 'start' : align;
}

export function isElementVisible(design, elementKey) {
    const features = design.features || {};
    const boxes = (design.layout && design.layout.boxes) || {};
    const hidden = (k) => Boolean(boxes[k] && boxes[k].hidden);
    switch (elementKey) {
        case 'photo':
        case 'cal':
        case 'head':
        case 'bg':
            return true;
        case 'cap':
            return Boolean(features.captions);
        case 'logo':
        case 'month':
            return !hidden(elementKey);
        case 'wk':
            return Boolean(features.weekNumbers) && !hidden('wk');
        case 'qr':
            return Boolean(features.qr) && !hidden('qr');
        default:
            return false;
    }
}

/** Box keys of the drawn elements, in BOX_KEYS order (second frames with their first one on a fortnight). */
export function visibleBoxKeys(design) {
    const boxes = (design.layout && design.layout.boxes) || {};
    return BOX_KEYS.filter((k) => {
        if (!boxes[k]) return false;
        if (SECOND_FRAMES[k] && design.type !== 'fortnight') return false;
        return isElementVisible(design, elementOfBox(k));
    });
}

export function availableParts(design) {
    const f = design.features || {};
    const out = ['num', 'numSat', 'numSun', 'numHol', 'day', 'name', 'hol', 'line'];
    if (f.moon) out.push('moon');
    if (f.timeChange || f.seasons) out.push('time');
    if (f.events) out.push('ev');
    if (f.shifts) out.push('shift');
    if (f.waste) out.push('waste');
    out.push('icon');
    return out;
}

function iconOrder(design) {
    const styles = design.layout && design.layout.styles;
    return styles && styles.icons && Array.isArray(styles.icons.order) ? styles.icons.order : [];
}

function categoryOn(design, key) {
    const cat = ICON_CATEGORIES.find((c) => c.key === key);
    return Boolean(cat && design.features && design.features[cat.feature]);
}

/** `styles.icons.order` filtered by the step-1 features. */
export function activeIconCategories(design) {
    return iconOrder(design).filter((k) => categoryOn(design, k));
}

/** Categories that are enabled in step 1 and not yet in the icon list. */
export function addableIconCategories(design) {
    const order = iconOrder(design);
    return ICON_CATEGORIES.map((c) => c.key).filter((k) => categoryOn(design, k) && !order.includes(k));
}
