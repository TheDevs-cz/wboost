// Page list of a calendar (architecture §E.9, §E.10).

import { addDays, compare, iso, isoWeek, mondayOf, parts } from './dates.js';
import { MONTHS } from './czech.js';
import { monthNames, printLanguages, weekWords } from './languages.js';
import { timeTexts } from './time.js';
import { GENERATED_SPECIAL_IDS, sorted, specialPageItem } from '../model/content.js';

export const SPECIAL_KIND_LABELS = Object.freeze({
    intro: 'Úvodní slovo',
    contacts: 'Důležité kontakty',
    year: 'Roční kalendárium',
    waste: 'Plánovací kalendář svozu odpadů',
    notes: 'Poznámky',
    // K20 P2 (D5): a page with no built-in content, for the designer's own layers
    blank: 'Prázdná strana',
});

/** Special-page kinds the designer makes (not generated from data): an upload, a Word text, a blank page. */
export const OWN_SPECIAL_KINDS = Object.freeze(['image', 'text', 'blank']);

const pad2 = (n) => String(n).padStart(2, '0');

export function pageKeyOfWeek(mondayIso, year) {
    const w = isoWeek(mondayIso);
    if (w.year < year) return 'pre';
    if (w.year > year) return 'post';
    return `w${pad2(w.week)}`;
}

/**
 * Upper-case month text of one week column: `LEDEN 2027`, `LEDEN / ÚNOR 2027`,
 * `PROSINEC 2026 / LEDEN 2027` (first and last day of that week).
 *
 * With extra languages (`langs` = `['cs', 'pl', 'en']`) every month is named in all of them:
 * `LEDEN / STYCZEŃ / JANUARY 2027`, and a week across two months is TWO lines (joined by `\n`):
 * `PROSINEC / GRUDZIEŃ / DECEMBER 2026` ⏎ `LEDEN / STYCZEŃ / JANUARY 2027` — the year on the first
 * line only when the years differ.
 */
export function monthText(firstIso, lastIso, langs = ['cs']) {
    const a = parts(firstIso);
    const b = parts(lastIso);
    let text;
    if (langs.length > 1) {
        const first = monthNames(a.m, langs);
        if (a.y === b.y && a.m === b.m) text = `${first} ${a.y}`;
        else text = `${first}${a.y === b.y ? '' : ` ${a.y}`}\n${monthNames(b.m, langs)} ${b.y}`;
    } else if (a.y === b.y && a.m === b.m) text = `${MONTHS[a.m - 1]} ${a.y}`;
    else text = `${MONTHS[a.m - 1]} ${a.y === b.y ? '' : `${a.y} `}/ ${MONTHS[b.m - 1]} ${b.y}`;
    return text.toUpperCase();
}

/**
 * Číslo týdne in its „Tvar“: a `1. týden`, b `Týden 1`, c `T1`. With extra languages the word is
 * named in all of them, the number once: `1. týden / tydzień / week`, `Týden / Tydzień / Week 1`;
 * `T1` has no word. Two weeks (the page title of a 14-day page in the app) stay Czech.
 */
export function weekText(form, weeks, langs = ['cs']) {
    const [w1, w2] = weeks;
    const two = weeks.length > 1;
    if (form === 'b') return two ? `Týdny ${w1}–${w2}` : `${weekWords(langs, true)} ${w1}`;
    if (form === 'c') return two ? `T${w1}–${w2}` : `T${w1}`;
    return two ? `${w1}. a ${w2}. týden` : `${w1}. ${weekWords(langs, false)}`;
}

function rangeText(firstIso, lastIso) {
    const a = parts(firstIso);
    const b = parts(lastIso);
    return `${a.d}. ${a.m}. – ${b.d}. ${b.m}.`;
}

/** Special pages in list order: the four generated kinds (default when not stored) plus uploaded ones. */
export function orderedSpecialPages(content) {
    const all = { ...(content.specialPages || {}) };
    for (const id of GENERATED_SPECIAL_IDS) {
        if (!all[id]) all[id] = specialPageItem(content, id);
    }
    return sorted(all, 'pos');
}

export function specialPageName(item) {
    return (item.name && item.name.trim()) || SPECIAL_KIND_LABELS[item.kind] || 'Vlastní strana';
}

export function buildPages(doc) {
    const { design, content } = doc;
    const year = design.year;
    const form = (design.layout && design.layout.styles && design.layout.styles.wk && design.layout.styles.wk.form) || 'a';
    const langs = printLanguages(design);
    const pages = [];
    const push = (page) => pages.push({ index: pages.length, ...page });

    push({ kind: 'cover', key: 'cover', title: 'Titulní strana', subtitle: '' });

    const special = design.features.specialPages ? orderedSpecialPages(content).filter(([, item]) => item.on) : [];
    const pushSpecial = ([id, item]) =>
        push({
            kind: 'special',
            key: `sp:${id}`,
            title: specialPageName(item),
            subtitle: OWN_SPECIAL_KINDS.includes(item.kind) ? 'Vlastní strana' : 'Speciální strana',
            special: { id, item },
        });
    special.filter(([, item]) => item.position !== 'back').forEach(pushSpecial);

    const n = design.type === 'fortnight' ? 14 : 7;
    const end = iso(year, 12, 31);
    for (let mon = mondayOf(iso(year, 1, 1)); compare(mon, end) <= 0; mon = addDays(mon, n)) {
        const days = [];
        for (let i = 0; i < n; i++) days.push(addDays(mon, i));
        const weeks = [isoWeek(mon).week];
        if (n === 14) weeks.push(isoWeek(days[7]).week);
        const frames = n === 14 ? [days.slice(0, 7), days.slice(7)] : [days];
        push({
            kind: 'week',
            key: pageKeyOfWeek(mon, year),
            title: weekText('a', weeks),
            subtitle: rangeText(days[0], days[n - 1]),
            week: {
                days,
                frames,
                weeks,
                // one text per week column (frame), as on the printed calendars: each column's
                // Měsíc describes its own seven days, each column carries its own week number
                monthTexts: frames.map((f) => monthText(f[0], f[f.length - 1], langs)),
                weekTexts: weeks.map((w) => weekText(form, [w], langs)),
            },
        });
    }

    special.filter(([, item]) => item.position === 'back').forEach(pushSpecial);

    const backVariant = special.some(([, item]) => item.kind === 'year') ? 'contacts' : 'year';
    push({ kind: 'back', key: 'back', title: 'Zadní strana', subtitle: backVariant === 'contacts' ? 'Kontakty klienta' : 'Přehled roku', backVariant });
    return pages;
}

export function weekPages(pages) {
    return pages.filter((p) => p.kind === 'week');
}

export function pageTitle(page) {
    return page.title;
}

/** Date range of a week page (`28. 12. – 3. 1.`), '' for other pages. */
export function pageRange(page) {
    return page.kind === 'week' ? page.subtitle : '';
}

/** The page the style editor shows (§E.10). */
export function samplePage(doc, pages, selection) {
    const weeks = weekPages(pages);
    if (selection && selection.part === 'time') {
        const found = weeks.find((p) => p.week.days.some((d) => timeTexts(d, doc.design.features).length > 0));
        if (found) return found;
    }
    return weeks[0] || null;
}

export function pageIndexOfDate(pages, isoDate) {
    const found = pages.find((p) => p.kind === 'week' && p.week.days.includes(isoDate));
    return found ? found.index : -1;
}
