// „Nahrát z Excelu“ (K10b, docs/calendar/excel-import-plan.md, architecture § "Import from a
// spreadsheet"). Pure: the server reads the file into rows of cell strings (`CalendarTableReader`:
// a date cell is already ISO), this module recognises the table, proposes a mapping, and turns the
// user's choices into ordinary content ops — the preview and the write use the very same plan.
//
// Waste shapes: `rows` (A — one row per collection: date · kind · place), `columns` (B — one
// column per kind, dates below), `months` (C — months × kinds, days listed „7., 21.“), `dates`
// (D — one column of dates; the kind is chosen). Events: one row per event (date · name · club
// · code; other columns are ignored). Row numbers in issues are the file's (1-based).
//
// Merge rules (Jan, 2026-11-24): waste — per target kind REPLACE its dates (or ADD, a toggle),
// `mode: 'dates'`, `on: true`, ≤ MAX_WASTE_DATES; events — ADD, an exact duplicate (same date,
// name and club, normalised) is skipped; a multi-day event goes on its first day.

import { iso, parts } from '../generate/dates.js';
import { dateLimits, newId, nextPos } from './content.js';
import { BUILTIN_WASTE } from './defaults.js';

export const MAX_WASTE_DATES = 400;
export const MAX_KIND_NAME = 30;
export const MAX_PLACE = 40;
export const MAX_EVENT_NAME = 80;
export const MAX_CLUB_CODE = 6;
export const MAX_CLUB_NAME = 60;

export const WASTE_SHAPES = Object.freeze({
    rows: 'Jeden řádek = jeden svoz (datum a druh)',
    columns: 'Jeden sloupec = jeden druh odpadu',
    months: 'Měsíce v řádcích, dny svozu v buňkách',
    dates: 'Jen sloupec dat (jeden druh)',
});

export const ISSUE_TEXTS = Object.freeze({
    date: 'neplatné datum',
    range: 'datum mimo rozsah kalendáře',
    name: 'chybí název akce',
    kind: 'chybí druh odpadu',
    cap: `víc než ${MAX_WASTE_DATES} termínů u jednoho druhu`,
    duplicate: 'akce už v kalendáři je',
    twice: 'stejná akce je v tabulce víckrát',
    multiday: 'vícedenní akce — vložena na první den',
});

const MONTHS = [
    ['leden', 'ledna', 'led', 'i'],
    ['unor', 'unora', 'uno', 'ii'],
    ['brezen', 'brezna', 'bre', 'iii'],
    ['duben', 'dubna', 'dub', 'iv'],
    ['kveten', 'kvetna', 'kve', 'v'],
    ['cerven', 'cervna', 'cer', 'vi'],
    ['cervenec', 'cervence', 'cvc', 'vii'],
    ['srpen', 'srpna', 'srp', 'viii'],
    ['zari', 'zar', 'ix'],
    ['rijen', 'rijna', 'rij', 'x'],
    ['listopad', 'listopadu', 'lis', 'xi'],
    ['prosinec', 'prosince', 'pro', 'xii'],
];

/** Normalised words of the built-in kinds (a cell or a header that CONTAINS one matches). */
const WASTE_ALIASES = {
    kom: ['komunalni', 'komunal', 'smesny', 'tko', 'popelnice', 'domovni odpad', 'zbytkovy'],
    bio: ['bio', 'bioodpad', 'biologick', 'zeleny odpad', 'kompost', 'hnede'],
    plast: ['plast', 'zlute', 'pet', 'nápojov'],
    papir: ['papir', 'modre'],
    sklo: ['sklo', 'zelene kontejnery'],
};

/** Lower case, no diacritics, single spaces. */
export function norm(text) {
    return String(text || '')
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase()
        .replace(/\s+/g, ' ')
        .trim();
}

const clean = (text, max) => String(text || '').replace(/\s+/g, ' ').trim().slice(0, max);

const valid = (y, m, d) => {
    if (!(m >= 1 && m <= 12 && d >= 1 && d <= 31 && y >= 1900 && y <= 2200)) return null;
    const check = new Date(Date.UTC(y, m - 1, d));
    return check.getUTCMonth() === m - 1 && check.getUTCDate() === d ? iso(y, m, d) : null;
};

const yearOf = (text, fallback) => {
    if (!text) return fallback;
    const n = Number(text);
    return text.length <= 2 ? 2000 + n : n;
};

/** A month name / Roman numeral / number → 1–12, or 0. */
export function monthOf(text) {
    const t = norm(text).replace(/[.:]/g, '').replace(/\s*\d{4}$/, '').trim();
    if (!t) return 0;
    if (/^\d{1,2}$/.test(t) && Number(t) >= 1 && Number(t) <= 12) return Number(t);
    for (let m = 0; m < 12; m++) if (MONTHS[m].includes(t) || (t.length > 3 && MONTHS[m][0] === t)) return m + 1;
    return 0;
}

/**
 * A cell → ISO date or null. Accepts ISO, `1. 3. 2027`, `01.03.2027`, `1.3.`, `1/3/2027`,
 * `1. března 2027`, `1. 3. 27`, and an Excel serial number (a date cell the reader could not
 * see as one, e.g. a formula). A missing year is `year`.
 */
export function parseDate(text, year) {
    const t = String(text || '').trim();
    if (!t) return null;
    let m = /^(\d{4})-(\d{1,2})-(\d{1,2})(?:[T ].*)?$/.exec(t);
    if (m) return valid(Number(m[1]), Number(m[2]), Number(m[3]));
    m = /^(\d{1,2})\s*[./]\s*(\d{1,2})\s*[./]?\s*(\d{2}|\d{4})?\s*$/.exec(t);
    if (m) return valid(yearOf(m[3], year), Number(m[2]), Number(m[1]));
    m = /^(\d{1,2})\.?\s+([A-Za-zÀ-ž]+)\s*(\d{4})?\s*$/.exec(t);
    if (m && monthOf(m[2])) return valid(yearOf(m[3], year), monthOf(m[2]), Number(m[1]));
    if (/^\d{5}(?:\.\d+)?$/.test(t)) {
        const serial = Math.floor(Number(t));
        if (serial < 30000 || serial > 80000) return null;
        const date = new Date(Date.UTC(1899, 11, 30) + serial * 86400000);
        return valid(date.getUTCFullYear(), date.getUTCMonth() + 1, date.getUTCDate());
    }
    return null;
}

/** `12.–14. 6. 2027` / `12. – 14. června` → `{ date: first day, multi: true }`; else parseDate. */
export function parseEventDate(text, year) {
    const t = String(text || '').trim();
    const range = /^(\d{1,2})\.?\s*[–—-]\s*(\d{1,2}\s*[./]\s*.+)$/.exec(t);
    if (range) {
        const last = parseDate(range[2], year);
        if (last) {
            const p = parts(last);
            const first = valid(p.y, p.m, Number(range[1]));
            if (first && first <= last) return { date: first, multi: true };
        }
    }
    const date = parseDate(t, year);
    return date ? { date, multi: false } : null;
}

/** Shape C cell: `7., 21.` / `3. a 17.` / `7, 21` → the ISO dates of that month; null when a part is no day. */
export function parseDayList(text, y, m) {
    const t = String(text || '').trim();
    if (!t) return [];
    const full = parseDate(t, y);
    if (full) return [full];
    const pieces = t.split(/\s*(?:,|;|\ba\b|\+|&)\s*/).filter(Boolean);
    const out = [];
    for (const piece of pieces) {
        const day = /^(\d{1,2})\.?$/.exec(piece.trim());
        const date = day ? valid(y, m, Number(day[1])) : parseDate(piece, y);
        if (!date) return null;
        out.push(date);
    }
    return out;
}

/** The built-in kind a text names (`PLASTY`, `Termíny svozu – sklo`), or null. */
export function builtinOf(text) {
    const t = norm(text);
    if (!t) return null;
    for (const [key, words] of Object.entries(WASTE_ALIASES)) {
        if (norm(BUILTIN_WASTE[key].name) === t) return key;
    }
    for (const [key, words] of Object.entries(WASTE_ALIASES)) {
        if (words.some((w) => t.includes(norm(w)))) return key;
    }
    return null;
}

function columnStats(rows, year) {
    const width = rows.reduce((max, row) => Math.max(max, row.length), 0);
    const stats = [];
    for (let c = 0; c < width; c++) {
        const s = { dates: 0, months: 0, texts: 0, distinct: new Set(), firstDate: -1 };
        rows.forEach((row, r) => {
            const cell = String(row[c] || '').trim();
            if (!cell) return;
            if (parseDate(cell, year) || parseEventDate(cell, year)) {
                s.dates++;
                if (s.firstDate < 0) s.firstDate = r;
            } else if (monthOf(cell)) s.months++;
            else {
                s.texts++;
                s.distinct.add(norm(cell));
            }
        });
        stats.push(s);
    }
    return stats;
}

/** Guess of the waste shape: `{ shape, … }` or `{ shape: null }` when no date is found. */
export function detectWasteShape(rows, year) {
    const stats = columnStats(rows, year);
    const monthCol = stats.findIndex((s) => s.months >= 3);
    if (monthCol >= 0) return { shape: 'months', monthCol };
    const dateCols = stats.map((s, c) => (s.dates >= 2 ? c : -1)).filter((c) => c >= 0);
    if (!dateCols.length) {
        const one = stats.findIndex((s) => s.dates >= 1);
        return one >= 0 ? { shape: 'dates', dateCol: one } : { shape: null };
    }
    if (dateCols.length >= 2) return { shape: 'columns', dateCols };
    const dateCol = dateCols[0];
    // a text column filled on the date rows = the kind; the next one = the place
    const aligned = stats
        .map((s, c) => {
            if (c === dateCol || !s.texts) return null;
            let n = 0;
            for (const row of rows) if (parseDate(row[dateCol], year) && String(row[c] || '').trim()) n++;
            return n ? { c, n, distinct: s.distinct.size } : null;
        })
        .filter(Boolean);
    const kinds = aligned.filter((x) => x.n >= Math.max(1, stats[dateCol].dates * 0.5)).sort((a, b) => b.n - a.n || a.distinct - b.distinct || a.c - b.c);
    if (!kinds.length) return { shape: 'dates', dateCol };
    const kindCol = kinds[0].c;
    // the place: the next text column on the date rows (filled or not on every row)
    const place = aligned.filter((x) => x.c !== kindCol).sort((a, b) => a.c - b.c)[0];
    return { shape: 'rows', dateCol, kindCol, placeCol: place ? place.c : -1 };
}

/**
 * Reads the waste table in `shape` (detected when omitted) → `{ shape, groups, issues }`:
 * `groups` = `[{ key, label, dates[], places[] }]` (one per kind found in the file, dates sorted and
 * unique, in the calendar's range), `issues` = `[{ row, text, reason }]`.
 */
export function readWaste(rows, year, shapeName) {
    const detected = detectWasteShape(rows, year);
    const guess = shapeName && shapeName !== detected.shape ? forceShape(rows, year, shapeName) : detected;
    const { min, max } = dateLimits(year);
    const groups = new Map();
    const issues = [];
    const groupOf = (label) => {
        const key = norm(label) || '—';
        if (!groups.has(key)) groups.set(key, { key, label: clean(label, 80) || 'Bez názvu', dates: new Set(), places: [] });
        return groups.get(key);
    };
    const add = (group, date, row, text) => {
        if (date < min || date > max) issues.push({ row: row + 1, text, reason: 'range' });
        else group.dates.add(date);
    };
    const rowText = (row) => row.filter(Boolean).join(' · ');

    if (guess.shape === 'rows') {
        let started = false;
        rows.forEach((row, r) => {
            const cell = String(row[guess.dateCol] || '').trim();
            const kind = String(row[guess.kindCol] || '').trim();
            const date = parseDate(cell, year);
            if (date) started = true;
            if (!started || (!cell && !kind)) return;
            if (!date) {
                issues.push({ row: r + 1, text: rowText(row), reason: 'date' });
                return;
            }
            if (!kind) {
                issues.push({ row: r + 1, text: rowText(row), reason: 'kind' });
                return;
            }
            const group = groupOf(kind);
            if (guess.placeCol >= 0 && row[guess.placeCol]) group.places.push(clean(row[guess.placeCol], MAX_PLACE));
            add(group, date, r, rowText(row));
        });
    } else if (guess.shape === 'columns') {
        const first = Math.min(...guess.dateCols.map((c) => rows.findIndex((row) => parseDate(row[c], year))));
        const header = first > 0 ? rows[first - 1] : [];
        for (const c of guess.dateCols) {
            const group = groupOf(String(header[c] || '').trim() || `Sloupec ${columnName(c)}`);
            for (let r = first; r < rows.length; r++) {
                const cell = String(rows[r][c] || '').trim();
                if (!cell) continue;
                const date = parseDate(cell, year);
                if (date) add(group, date, r, cell);
                else issues.push({ row: r + 1, text: cell, reason: 'date' });
            }
        }
    } else if (guess.shape === 'months') {
        const first = rows.findIndex((row) => monthOf(row[guess.monthCol]));
        const header = first > 0 ? rows[first - 1] : [];
        for (let r = first; r < rows.length; r++) {
            const row = rows[r];
            const month = monthOf(row[guess.monthCol]);
            if (!month) continue;
            const y = Number((/(\d{4})/.exec(String(row[guess.monthCol])) || [])[1]) || year;
            row.forEach((cell, c) => {
                if (c === guess.monthCol || !String(cell || '').trim()) return;
                const group = groupOf(String(header[c] || '').trim() || `Sloupec ${columnName(c)}`);
                const dates = parseDayList(cell, y, month);
                if (!dates) issues.push({ row: r + 1, text: `${row[guess.monthCol]}: ${cell}`, reason: 'date' });
                else for (const date of dates) add(group, date, r, `${row[guess.monthCol]}: ${cell}`);
            });
        }
    } else if (guess.shape === 'dates') {
        const first = rows.findIndex((row) => parseDate(row[guess.dateCol], year));
        const title = first > 0 ? rows.slice(0, first).map((row) => row.filter(Boolean).join(' ')).filter(Boolean).pop() || '' : '';
        const group = groupOf(title || 'Termíny ze souboru');
        group.fromTitle = true;
        for (let r = Math.max(0, first); r < rows.length; r++) {
            const cell = String(rows[r][guess.dateCol] || '').trim();
            if (!cell) continue;
            const date = parseDate(cell, year);
            if (date) add(group, date, r, cell);
            else issues.push({ row: r + 1, text: cell, reason: 'date' });
        }
    }

    return {
        shape: guess.shape,
        groups: [...groups.values()].map((g) => ({ key: g.key, label: g.label, dates: [...g.dates].sort(), place: commonPlace(g.places), fromTitle: Boolean(g.fromTitle) })).filter((g) => g.dates.length),
        issues,
    };
}

/** A shape the user picked although the table looks different. */
function forceShape(rows, year, shape) {
    const stats = columnStats(rows, year);
    const byDates = stats.map((s, c) => [c, s.dates]).sort((a, b) => b[1] - a[1]);
    const dateCol = byDates.length ? byDates[0][0] : 0;
    if (shape === 'dates') return { shape, dateCol };
    if (shape === 'columns') return { shape, dateCols: stats.map((s, c) => (s.dates >= 1 ? c : -1)).filter((c) => c >= 0) };
    if (shape === 'months') {
        const monthCol = stats.map((s, c) => [c, s.months]).sort((a, b) => b[1] - a[1])[0];
        return { shape, monthCol: monthCol ? monthCol[0] : 0 };
    }
    const kind = stats.map((s, c) => [c, s.texts]).filter(([c]) => c !== dateCol).sort((a, b) => b[1] - a[1])[0];
    return { shape: 'rows', dateCol, kindCol: kind ? kind[0] : dateCol + 1, placeCol: -1 };
}

const columnName = (c) => String.fromCharCode(65 + (c % 26)) + (c >= 26 ? String(Math.floor(c / 26)) : '');

function commonPlace(places) {
    if (!places.length) return '';
    return places.every((p) => p === places[0]) ? places[0] : '';
}

/**
 * The proposed target of every group: an existing kind (same name, or the same built-in), a
 * built-in kind not in the calendar yet (`new:<builtin>`), or a new own kind (`new`). Shape D
 * without a recognisable title goes to `selected` (the kind open in the tool).
 */
export function suggestWasteMapping(content, groups, selected) {
    const kinds = Object.entries(content.wasteKinds || {});
    const out = {};
    for (const group of groups) {
        const byName = kinds.find(([, kind]) => norm(kind.name) === group.key);
        const builtin = builtinOf(group.label);
        const byBuiltin = builtin ? kinds.find(([id, kind]) => kind.builtin === builtin || id === builtin) : null;
        if (byName) out[group.key] = byName[0];
        else if (byBuiltin) out[group.key] = byBuiltin[0];
        else if (builtin) out[group.key] = `new:${builtin}`;
        else if (group.fromTitle && selected && (content.wasteKinds || {})[selected]) out[group.key] = selected;
        else out[group.key] = 'new';
    }
    return out;
}

/**
 * The write: `mapping` group key → kind id | `new` | `new:<builtin>` | `skip`; `mode` `replace` |
 * `add`. → `{ ops, targets: [{ id, name, isNew, before, after, imported, refused }], total }`.
 * Refused kinds (over the cap) are not written.
 */
export function planWaste(content, read, mapping, mode = 'replace', makeId = newId) {
    const kinds = content.wasteKinds || {};
    const targets = new Map();
    let pos = nextPos(kinds);
    const used = new Set(Object.keys(kinds));
    for (const group of read.groups) {
        const to = mapping[group.key] || 'skip';
        if (to === 'skip') continue;
        let id = to;
        let fresh = null;
        if (to === 'new' || to.startsWith('new:')) {
            const builtin = to.startsWith('new:') ? to.slice(4) : null;
            const name = builtin ? BUILTIN_WASTE[builtin].name : clean(group.label, MAX_KIND_NAME) || 'Nový druh odpadu';
            // two groups mapped to the same NEW kind (`new:plast` twice, or the same label) share it
            const shareKey = builtin ? `b:${builtin}` : `n:${norm(name)}`;
            const shared = [...targets.values()].find((t) => t.shareKey === shareKey);
            if (shared) {
                id = shared.id;
            } else {
                id = builtin && !used.has(builtin) ? builtin : makeId();
                used.add(id);
                fresh = { builtin, name, on: true, place: '', mode: 'dates', weekdays: [1], interval: '2e', from: null, to: null, dates: [], pos: pos++ };
            }
            if (!targets.has(id)) targets.set(id, { id, shareKey, item: fresh, isNew: true, dates: new Set(), places: [] });
        } else if (!kinds[id]) {
            continue;
        } else if (!targets.has(id)) {
            targets.set(id, { id, item: kinds[id], isNew: false, dates: new Set(), places: [] });
        }
        const target = targets.get(id);
        group.dates.forEach((d) => target.dates.add(d));
        if (group.place) target.places.push(group.place);
    }

    const ops = [];
    const summary = [];
    let total = 0;
    for (const target of targets.values()) {
        const before = target.isNew ? [] : (target.item.dates || []).slice();
        const imported = [...target.dates].sort();
        const next = mode === 'add' ? [...new Set([...before, ...imported])].sort() : imported;
        const refused = next.length > MAX_WASTE_DATES;
        summary.push({ id: target.id, name: target.item.name, isNew: target.isNew, before: before.length, after: next.length, imported: imported.length, refused, wasRule: !target.isNew && target.item.mode === 'rule' && target.item.on === true });
        if (refused) continue;
        total += imported.length;
        const place = target.item.place || commonPlace(target.places);
        ops.push({ c: 'wasteKinds', id: target.id, v: { ...target.item, on: true, mode: 'dates', dates: next, place } });
    }
    return { ops, targets: summary, total };
}

// ---- events ------------------------------------------------------------------------------------

const HEADS = {
    date: /datum|termin|den\b|kdy/,
    name: /nazev|akce|udalost|program|co\b/,
    club: /poradatel|spolek|organiz|klub|kdo/,
    code: /zkratka|kod\b/,
};

/** Columns of the events table: `{ header, date, name, club, code }` (−1 = none). */
export function detectEventColumns(rows, year) {
    const stats = columnStats(rows, year);
    const dateCol = stats.map((s, c) => [c, s.dates]).sort((a, b) => b[1] - a[1] || a[0] - b[0])[0];
    const out = { header: -1, date: dateCol && dateCol[1] > 0 ? dateCol[0] : -1, name: -1, club: -1, code: -1 };
    if (out.date < 0) return out;
    const firstData = rows.findIndex((row) => parseEventDate(row[out.date], year));
    for (let r = firstData - 1; r >= 0; r--) {
        if (rows[r].some((cell) => String(cell || '').trim())) {
            out.header = r;
            break;
        }
    }
    if (out.header >= 0) {
        rows[out.header].forEach((cell, c) => {
            const t = norm(cell);
            if (c === out.date || !t) return;
            for (const key of ['code', 'club', 'name']) {
                if (out[key] < 0 && HEADS[key].test(t) && !(key === 'name' && HEADS.club.test(t))) {
                    out[key] = c;
                    break;
                }
            }
        });
    }
    // no header word: the text column with the most different values is the name, the next the club
    const free = stats
        .map((s, c) => ({ c, texts: s.texts, distinct: s.distinct.size }))
        .filter((s) => s.texts > 0 && ![out.date, out.name, out.club, out.code].includes(s.c))
        .sort((a, b) => b.distinct - a.distinct || a.c - b.c);
    if (out.name < 0 && free.length) out.name = free.shift().c;
    if (out.club < 0 && out.header < 0 && free.length) out.club = free.sort((a, b) => a.c - b.c).shift().c;
    return out;
}

/** → `{ columns, items: [{ row, date, name, club, code, multi }], issues }`. */
export function readEvents(rows, year, columns) {
    const cols = columns || detectEventColumns(rows, year);
    const { min, max } = dateLimits(year);
    const items = [];
    const issues = [];
    if (cols.date < 0) return { columns: cols, items, issues };
    const firstData = rows.findIndex((row) => parseEventDate(row[cols.date], year));
    rows.forEach((row, r) => {
        if (r <= cols.header || r < firstData || !row.some((cell) => String(cell || '').trim())) return;
        const text = row.filter(Boolean).join(' · ');
        const when = parseEventDate(row[cols.date], year);
        const name = cols.name >= 0 ? clean(row[cols.name], 300) : '';
        if (!when) {
            issues.push({ row: r + 1, text, reason: 'date' });
            return;
        }
        if (when.date < min || when.date > max) {
            issues.push({ row: r + 1, text, reason: 'range' });
            return;
        }
        if (!name) {
            issues.push({ row: r + 1, text, reason: 'name' });
            return;
        }
        if (when.multi) issues.push({ row: r + 1, text, reason: 'multiday', note: true });
        items.push({ row: r + 1, date: when.date, name: name.slice(0, MAX_EVENT_NAME), club: cols.club >= 0 ? clean(row[cols.club], MAX_CLUB_NAME) : '', code: cols.code >= 0 ? clean(row[cols.code], 20) : '', multi: when.multi });
    });
    return { columns: cols, items, issues };
}

/** An all-caps first word of ≤ 6 letters (`SDH Testov`, `ZŠ Testov`) — what people write as the code. */
function capsWord(text) {
    const first = clean(text, MAX_CLUB_NAME).split(' ')[0] || '';
    return first.length >= 2 && first.length <= MAX_CLUB_CODE && first === first.toUpperCase() && /\p{Lu}/u.test(first) ? first : '';
}

/** One organiser however it is written: the code column, else the all-caps first word, else the whole name. */
const clubKey = (item) => norm(item.code) || norm(capsWord(item.club)) || norm(item.club);

/** A short code for a new club: its own code, a short all-caps first word, else the initials. */
export function proposeCode(name, code, taken) {
    const words = clean(name, MAX_CLUB_NAME).split(' ').filter(Boolean);
    let base = clean(code, MAX_CLUB_CODE);
    if (!base && words.length && words[0].length <= MAX_CLUB_CODE && words[0] === words[0].toUpperCase() && /\p{Lu}/u.test(words[0])) base = words[0];
    if (!base) base = words.filter((w) => w.length > 2 || /\p{Lu}/u.test(w[0])).map((w) => w[0].toUpperCase()).join('').slice(0, MAX_CLUB_CODE);
    if (!base) base = 'S';
    let out = base;
    for (let n = 2; taken.has(norm(out)); n++) out = `${base.slice(0, MAX_CLUB_CODE - String(n).length)}${n}`;
    return out;
}

/**
 * The organisers found → `[{ key, name, code, count, suggested }]`; `suggested` = an existing club id
 * (matched by code, then by name — case and accents ignored) or `new`.
 */
export function eventClubs(content, items) {
    const clubs = Object.entries(content.clubs || {});
    const taken = new Set(clubs.map(([, c]) => norm(c.code)));
    const groups = new Map();
    for (const item of items) {
        const key = clubKey(item);
        if (!key) continue;
        if (!groups.has(key)) groups.set(key, { key, name: '', code: item.code || capsWord(item.club), count: 0 });
        const group = groups.get(key);
        // the longest way the file writes it is the name („SDH Testov“ over „SDH“)
        if ((item.club || '').length > group.name.length) group.name = item.club;
        if (!group.name) group.name = item.code;
        group.count++;
    }
    const out = [];
    for (const group of groups.values()) {
        const n = norm(group.name);
        const c = norm(group.code);
        const match = clubs.find(([, club]) => (c && norm(club.code) === c) || (n && (norm(club.code) === n || norm(club.name) === n)));
        const proposed = match ? '' : proposeCode(group.name, group.code, taken);
        if (proposed) taken.add(norm(proposed));
        out.push({ ...group, suggested: match ? match[0] : 'new', proposed });
    }
    return out;
}

/**
 * The write of an events import: `choice` club key → `{ to: clubId | 'new' | 'none', code? }`. →
 * `{ ops, added, duplicates, issues }`. Events are only ADDED; an exact duplicate of a stored event
 * or of an earlier row is skipped (and listed).
 */
export function planEvents(content, read, clubs, choice, makeId = newId) {
    const ops = [];
    const issues = [];
    const known = content.clubs || {};
    let pos = nextPos(known);
    const clubIds = new Map();
    for (const club of clubs) {
        const pick = choice[club.key] || { to: club.suggested };
        if (pick.to === 'none') {
            clubIds.set(club.key, null);
        } else if (pick.to === 'new') {
            const id = makeId();
            const code = clean(pick.code !== undefined ? pick.code : club.proposed, MAX_CLUB_CODE);
            const name = clean(club.name, MAX_CLUB_NAME);
            ops.push({ c: 'clubs', id, v: { code, name: name === code ? '' : name, pos: pos++ } });
            clubIds.set(club.key, id);
        } else {
            clubIds.set(club.key, known[pick.to] ? pick.to : null);
        }
    }
    const seen = new Set(Object.values(content.events || {}).map((e) => `${e.date}|${norm(e.name)}|${e.club || ''}`));
    const inFile = new Set();
    let added = 0;
    let duplicates = 0;
    for (const item of read.items) {
        const club = clubKey(item) ? clubIds.get(clubKey(item)) || null : null;
        const key = `${item.date}|${norm(item.name)}|${club || ''}`;
        if (seen.has(key)) {
            duplicates++;
            issues.push({ row: item.row, text: `${item.date} · ${item.name}`, reason: inFile.has(key) ? 'twice' : 'duplicate', note: true });
            continue;
        }
        seen.add(key);
        inFile.add(key);
        ops.push({ c: 'events', id: makeId(), v: { date: item.date, name: item.name, club, icon: null } });
        added++;
    }
    // clubs nobody uses after the duplicates are dropped are not created
    const usedClubs = new Set(ops.filter((op) => op.c === 'events').map((op) => op.v.club));
    return { ops: ops.filter((op) => op.c !== 'clubs' || usedClubs.has(op.id)), added, duplicates, issues };
}
