// Content collections (architecture §B.6, §E.7).

import { daysInMonth, iso, parts } from '../generate/dates.js';

const def = (o) => Object.freeze({ clientWritable: false, ordered: false, dated: false, pageKeyed: false, ...o });

export const COLLECTIONS = Object.freeze({
    clubs: def({ clientWritable: true, ordered: true }),
    events: def({ clientWritable: true, dated: true }),
    names: def({ clientWritable: true, dated: true }),
    wasteKinds: def({ clientWritable: true, ordered: true }),
    wasteExtras: def({ clientWritable: true, dated: true }),
    moonEdits: def({ dated: true }),
    shiftOps: def({ ordered: true }),
    specialPages: def({ ordered: true }),
    pagePhotos: def({ clientWritable: true, pageKeyed: true }),
    pageCaptions: def({ clientWritable: true, pageKeyed: true }),
    pageImages: def({ pageKeyed: true }),
    pageQr: def({ pageKeyed: true }),
    comments: def({ clientWritable: true, dated: true }),
});

export const PAGE_COLLECTIONS = Object.freeze(Object.keys(COLLECTIONS).filter((c) => COLLECTIONS[c].pageKeyed));
export const GENERATED_SPECIAL_IDS = Object.freeze(['intro', 'contacts', 'year', 'waste']);

const ID_ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

/** 16 random base-62 characters. */
export function newId(random) {
    let out = '';
    if (!random && globalThis.crypto && typeof globalThis.crypto.getRandomValues === 'function') {
        const bytes = new Uint8Array(32);
        globalThis.crypto.getRandomValues(bytes);
        for (let i = 0; i < bytes.length && out.length < 16; i++) {
            if (bytes[i] < 248) out += ID_ALPHABET[bytes[i] % 62];
        }
    }
    const rnd = random || Math.random;
    while (out.length < 16) out += ID_ALPHABET[Math.min(61, Math.floor(rnd() * 62))];
    return out;
}

const byId = (a, b) => (a < b ? -1 : a > b ? 1 : 0);

export function sorted(collection, kind) {
    const entries = Object.entries(collection || {});
    if (kind === 'pos') {
        return entries.sort((a, b) => (Number(a[1].pos) || 0) - (Number(b[1].pos) || 0) || byId(a[0], b[0]));
    }
    return entries.sort((a, b) => byId(a[1].date || '', b[1].date || '') || byId(a[0], b[0]));
}

export function nextPos(collection) {
    let max = -1;
    for (const item of Object.values(collection || {})) max = Math.max(max, Number(item.pos) || 0);
    return max + 1;
}

/** New order → the items whose `pos` changed (ids missing from `ids` keep their order after them). */
export function reorder(collection, ids) {
    const known = ids.filter((id, i) => collection[id] && ids.indexOf(id) === i);
    const rest = sorted(collection, 'pos')
        .map(([id]) => id)
        .filter((id) => !known.includes(id));
    const out = {};
    [...known, ...rest].forEach((id, pos) => {
        if (collection[id].pos !== pos) out[id] = { ...collection[id], pos };
    });
    return out;
}

export function dateLimits(year) {
    return { min: iso(year - 1, 12, 1), max: iso(year + 1, 1, 31) };
}

function shiftDate(date, delta, clamp = false) {
    const p = parts(date);
    const y = p.y + delta;
    const last = daysInMonth(y, p.m);
    if (p.d > last) return clamp ? iso(y, p.m, last) : null;
    return iso(y, p.m, p.d);
}

/** Year change: ops moving every dated item by whole years (29 February handling per §E.7). */
export function shiftContentYear(content, fromYear, toYear) {
    const delta = toYear - fromYear;
    const ops = [];
    let dropped = 0;
    if (delta !== 0) {
        for (const c of ['events', 'names', 'wasteExtras']) {
            for (const [id, item] of sorted(content[c], 'date')) {
                const date = shiftDate(item.date, delta);
                if (date === null) {
                    dropped++;
                    ops.push({ c, id, v: null });
                } else {
                    ops.push({ c, id, v: { ...item, date } });
                }
            }
        }
        const clampDate = (date) => (date === null || date === undefined ? null : shiftDate(date, delta, true));
        for (const [id, kind] of sorted(content.wasteKinds, 'pos')) {
            const dates = [];
            for (const date of kind.dates || []) {
                const moved = shiftDate(date, delta);
                if (moved === null) dropped++;
                else dates.push(moved);
            }
            ops.push({ c: 'wasteKinds', id, v: { ...kind, dates, from: clampDate(kind.from), to: clampDate(kind.to) } });
        }
        for (const [id, item] of sorted(content.comments, 'date')) {
            ops.push({ c: 'comments', id, v: { ...item, date: clampDate(item.date) } });
        }
    }
    for (const [id] of sorted(content.moonEdits, 'date')) ops.push({ c: 'moonEdits', id, v: null });
    return { ops, dropped };
}

export function pageHasOverrides(content, pageKey) {
    return PAGE_COLLECTIONS.some((c) => Boolean(content[c] && content[c][pageKey]));
}

export function clearPageOverrides(content, pageKey) {
    return PAGE_COLLECTIONS.filter((c) => content[c] && content[c][pageKey]).map((c) => ({ c, id: pageKey, v: null }));
}

/** A special page item; the four generated pages have a default when nothing is stored. */
export function specialPageItem(content, id) {
    const stored = content.specialPages && content.specialPages[id];
    if (stored) return stored;
    const index = GENERATED_SPECIAL_IDS.indexOf(id);
    if (index < 0) return null;
    return { kind: id, on: false, layout: 0, position: 'front', nextYear: id === 'year', name: '', asset: null, text: null, fileName: null, pos: index };
}

/** Asset ids referenced anywhere in a layout (keys `asset`, `icon`, members of `icons` / `assets`). */
export function layoutAssetIds(layout) {
    const out = [];
    const add = (v) => {
        if (typeof v === 'string' && v !== '' && !out.includes(v)) out.push(v);
    };
    const walk = (node) => {
        if (Array.isArray(node)) {
            node.forEach(walk);
            return;
        }
        if (!node || typeof node !== 'object') return;
        for (const [key, value] of Object.entries(node)) {
            if (key === 'asset' || key === 'icon') add(value);
            else if ((key === 'icons' || key === 'assets') && Array.isArray(value)) value.forEach(add);
            else walk(value);
        }
    };
    walk(layout);
    return out;
}
