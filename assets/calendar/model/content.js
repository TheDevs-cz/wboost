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
    // content of the cover, the back and the generated special pages (2026-11-12). Keyed by the
    // fixed page keys of `CONTENT_PAGE_KEYS`, but NOT `pageKeyed`: they are the page's content,
    // not an override of the style — „Vrátit na styl“ must never remove them.
    pageSetup: def({}),
    pageContent: def({ clientWritable: true }),
    pageGroups: def({ clientWritable: true, ordered: true }),
});

export const PAGE_COLLECTIONS = Object.freeze(Object.keys(COLLECTIONS).filter((c) => COLLECTIONS[c].pageKeyed));
export const GENERATED_SPECIAL_IDS = Object.freeze(['intro', 'contacts', 'year', 'waste', 'notes']);

/** Page keys that carry editable content (`pageContent`) — a fixed set, so the item shape follows from the key. */
export const CONTENT_PAGE_KEYS = Object.freeze(['cover', 'back', 'sp:intro', 'sp:contacts', 'sp:year', 'sp:waste', 'sp:notes']);
/** Page keys with designer options (`pageSetup`). */
export const SETUP_PAGE_KEYS = Object.freeze(['cover', 'back', 'sp:intro', 'sp:contacts']);
/** Pages that carry groups of lines (`pageGroups[*].page`). */
export const GROUP_PAGE_KEYS = Object.freeze(['sp:contacts', 'sp:waste']);

/** Caps of the page content — the mirror of `CalendarContentRules` (change both). */
export const CONTENT_LIMITS = Object.freeze({
    heading: 80,
    title: 80,
    subtitle: 80,
    coverNote: 120,
    salutation: 80,
    body: 4000,
    signName: 80,
    signRole: 80,
    notesHeading: 40,
    note: 200,
    name: 80,
    address: 300,
    addressLines: 6,
    phone: 60,
    email: 80,
    web: 80,
    imprint: 200,
    groupHeading: 80,
    lineLabel: 40,
    lineValue: 200,
    lines: 30,
    groups: 60,
});

export const COVER_LOGO_POSITIONS = Object.freeze(['none', 'tl', 'tc', 'tr', 'title']);
export const COVER_LOGO_SIZES = Object.freeze(['s', 'm', 'l']);

const SETUP_DEFAULTS = Object.freeze({
    cover: Object.freeze({ layout: 0, logo: 'tr', logoSize: 'm' }),
    back: Object.freeze({ logo: true }),
    'sp:intro': Object.freeze({ align: 'left' }),
    'sp:contacts': Object.freeze({ clubLegend: true, wasteLegend: false, notes: false }),
});

const CONTENT_DEFAULTS = Object.freeze({
    cover: Object.freeze({ title: '', subtitle: '', note: '', photo: null }),
    back: Object.freeze({ heading: '', footer: '', name: '', address: '', phone: '', email: '', web: '', imprint: '' }),
    'sp:intro': Object.freeze({ heading: '', salutation: '', body: '', signName: '', signRole: '', photo: null }),
    'sp:contacts': Object.freeze({ heading: '', notesHeading: '', photo: null }),
    'sp:year': Object.freeze({ heading: '', note: '' }),
    'sp:waste': Object.freeze({ heading: '', note: '' }),
    'sp:notes': Object.freeze({ heading: '' }),
});

/**
 * The designer options of a page: the stored item over the defaults (a missing item = defaults;
 * unknown keys never get through, so the result always has the exact shape the server accepts).
 * `null` for a page without options.
 */
export function pageSetupOf(content, pageKey) {
    const defaults = SETUP_DEFAULTS[pageKey];
    if (!defaults) return null;
    const stored = (content.pageSetup && content.pageSetup[pageKey]) || {};
    const out = {};
    for (const key of Object.keys(defaults)) out[key] = typeof stored[key] === typeof defaults[key] ? stored[key] : defaults[key];
    return out;
}

/** The editable content of a page: the stored item over empty defaults; `null` for a page without any. */
export function pageContentOf(content, pageKey) {
    const defaults = CONTENT_DEFAULTS[pageKey];
    if (!defaults) return null;
    const stored = (content.pageContent && content.pageContent[pageKey]) || {};
    const out = {};
    for (const key of Object.keys(defaults)) {
        if (key === 'photo') {
            const p = stored.photo;
            out.photo = p && typeof p === 'object' && typeof p.asset === 'string' ? { asset: p.asset, zoom: Number(p.zoom) || 1, px: Number.isFinite(p.px) ? p.px : 0.5, py: Number.isFinite(p.py) ? p.py : 0.5 } : null;
        } else {
            out[key] = typeof stored[key] === 'string' ? stored[key] : '';
        }
    }
    return out;
}

/** The groups of a page in order: `[[id, { page, heading, lines: [{ label, value }], highlight, pos }]]`. */
export function pageGroupsOf(content, pageKey) {
    return sorted(content.pageGroups || {}, 'pos').filter(([, group]) => group && group.page === pageKey);
}

/**
 * Light formatting of a body text (the intro): paragraphs are separated by an empty line, a single
 * line break stays a line break, `**bold**` and `*italic*` mark runs (they nest), `\*` is a
 * literal asterisk. A mark without its closing partner in the same paragraph is plain text — a
 * malformed input never fails and never loses a character.
 *
 * @returns `[{ lines: [[{ text, bold, italic }]] }]` — paragraphs → lines → runs
 */
export function parseRichText(source) {
    const paragraphs = String(source === null || source === undefined ? '' : source)
        .replace(/\r\n?/g, '\n')
        .split(/\n[ \t]*\n+/)
        .map((p) => p.replace(/^\n+|\n+$/g, ''))
        .filter((p) => p.trim() !== '');
    return paragraphs.map((paragraph) => {
        // tokens: escaped asterisk, `**`, `*`, line break, text
        const tokens = paragraph.match(/\\\*|\*\*|\*|\n|[^*\n\\]+|\\/g) || [];
        // a mark opens only when the same mark follows later in the paragraph
        const closes = (from, mark) => tokens.indexOf(mark, from + 1) >= 0;
        const lines = [[]];
        let bold = false;
        let italic = false;
        const push = (text) => {
            const line = lines[lines.length - 1];
            const last = line[line.length - 1];
            if (last && last.bold === bold && last.italic === italic) last.text += text;
            else line.push({ text, bold, italic });
        };
        tokens.forEach((token, index) => {
            if (token === '\n') lines.push([]);
            else if (token === '\\*') push('*');
            else if (token === '**') {
                if (bold) bold = false;
                else if (closes(index, '**')) bold = true;
                else push('**');
            } else if (token === '*') {
                if (italic) italic = false;
                else if (closes(index, '*')) italic = true;
                else push('*');
            } else push(token);
        });
        return { lines: lines.map((line) => line.filter((run) => run.text !== '')).filter((line) => line.some((run) => run.text.trim() !== '')) };
    }).filter((paragraph) => paragraph.lines.length > 0);
}

/** A body text without its marks (history, search, tests). */
export function richPlainText(source) {
    return parseRichText(source)
        .map((p) => p.lines.map((line) => line.map((run) => run.text).join('')).join('\n'))
        .join('\n\n');
}

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
    // notes sit before the back page by default, where the client's calendars have them
    return { kind: id, on: false, layout: 0, position: id === 'notes' ? 'back' : 'front', nextYear: id === 'year', name: '', asset: null, text: null, fileName: null, pos: index };
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
