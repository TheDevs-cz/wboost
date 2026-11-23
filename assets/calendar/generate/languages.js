// Languages of the printed names (K15b, architecture §B.1): a calendar prints its month, weekday
// and week-number words in Czech plus the extra languages of `design.languages`, joined by ` / `
// in the fixed order of this table (cs → sk → pl → de → en). Name days, holidays, the time and
// season texts and the special pages stay Czech. Nominative forms everywhere — a header stands alone.

import { MONTHS, WEEKDAYS, WEEKDAYS_SHORT } from './czech.js';

// prettier-ignore
export const LANGUAGES = Object.freeze({
    cs: Object.freeze({
        label: 'Čeština',
        months: MONTHS,
        weekdays: WEEKDAYS,
        weekdaysShort: WEEKDAYS_SHORT,
        week: 'týden',
        weekCap: 'Týden',
    }),
    sk: Object.freeze({
        label: 'Slovenština',
        months: Object.freeze(['január', 'február', 'marec', 'apríl', 'máj', 'jún', 'júl', 'august', 'september', 'október', 'november', 'december']),
        weekdays: Object.freeze(['Pondelok', 'Utorok', 'Streda', 'Štvrtok', 'Piatok', 'Sobota', 'Nedeľa']),
        weekdaysShort: Object.freeze(['Po', 'Ut', 'St', 'Št', 'Pi', 'So', 'Ne']),
        week: 'týždeň',
        weekCap: 'Týždeň',
    }),
    pl: Object.freeze({
        label: 'Polština',
        months: Object.freeze(['styczeń', 'luty', 'marzec', 'kwiecień', 'maj', 'czerwiec', 'lipiec', 'sierpień', 'wrzesień', 'październik', 'listopad', 'grudzień']),
        weekdays: Object.freeze(['Poniedziałek', 'Wtorek', 'Środa', 'Czwartek', 'Piątek', 'Sobota', 'Niedziela']),
        weekdaysShort: Object.freeze(['Pn', 'Wt', 'Śr', 'Cz', 'Pt', 'So', 'Nd']),
        week: 'tydzień',
        weekCap: 'Tydzień',
    }),
    de: Object.freeze({
        label: 'Němčina',
        months: Object.freeze(['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember']),
        weekdays: Object.freeze(['Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag']),
        weekdaysShort: Object.freeze(['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So']),
        week: 'Woche',
        weekCap: 'Woche',
    }),
    en: Object.freeze({
        label: 'Angličtina',
        months: Object.freeze(['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December']),
        weekdays: Object.freeze(['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday']),
        weekdaysShort: Object.freeze(['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su']),
        week: 'week',
        weekCap: 'Week',
    }),
});

/** The extra languages a calendar can print, in print order (Czech is always first and implied). */
export const EXTRA_LANGUAGES = Object.freeze(['sk', 'pl', 'de', 'en']);

export const LANGUAGE_SEPARATOR = ' / ';

/** A stored `languages` value → known extra codes, unique, in the fixed print order. */
export function normalizeLanguages(value) {
    const list = Array.isArray(value) ? value : [];
    return EXTRA_LANGUAGES.filter((code) => list.includes(code));
}

/** Czech plus the extra languages of a design, in print order. */
export function printLanguages(design) {
    return ['cs', ...normalizeLanguages(design && design.languages)];
}

const joined = (langs, pick) => langs.map((code) => pick(LANGUAGES[code])).join(LANGUAGE_SEPARATOR);

/** `leden / styczeń / January` — month `m` (1–12) in every language (Czech alone: `leden`). */
export const monthNames = (m, langs) => joined(langs, (l) => l.months[m - 1]);

/** `Pondělí / Poniedziałek / Monday` — weekday `wd` (0 = Monday). */
export const weekdayNames = (wd, langs) => joined(langs, (l) => l.weekdays[wd]);

/** `Po / Pn / Mo`. */
export const weekdayShortNames = (wd, langs) => joined(langs, (l) => l.weekdaysShort[wd]);

/** `týden / tydzień / week` (form a, after the number) or `Týden / Tydzień / Week` (form b, before it). */
export const weekWords = (langs, capital) => joined(langs, (l) => (capital ? l.weekCap : l.week));
