// Daylight-saving changes and season starts (map §5.5).

import { addDays, daysInMonth, iso, parts, weekday } from './dates.js';

const SEASON_DATES = { '3-20': 0, '6-21': 1, '9-22': 2, '12-21': 3 };
export const SEASON_TEXTS = Object.freeze(['Začátek jara', 'Začátek léta', 'Začátek podzimu', 'Začátek zimy']);
export const DST_TEXTS = Object.freeze({ summer: 'Začátek letního času', winter: 'Začátek zimního času' });

/** Last Sunday of March → 'summer', of October → 'winter'. */
export function dstChange(isoDate) {
    const p = parts(isoDate);
    if (p.m !== 3 && p.m !== 10) return null;
    const last = iso(p.y, p.m, daysInMonth(p.y, p.m));
    const lastSunday = addDays(last, -((weekday(last) + 1) % 7));
    if (isoDate !== lastSunday) return null;
    return p.m === 3 ? 'summer' : 'winter';
}

/** 0 spring (20 Mar), 1 summer (21 Jun), 2 autumn (22 Sep), 3 winter (21 Dec). */
export function seasonStart(isoDate) {
    const p = parts(isoDate);
    const k = SEASON_DATES[`${p.m}-${p.d}`];
    return k === undefined ? null : k;
}

export function timeTexts(isoDate, features) {
    const out = [];
    if (features && features.timeChange) {
        const dst = dstChange(isoDate);
        if (dst) out.push(DST_TEXTS[dst]);
    }
    if (features && features.seasons) {
        const season = seasonStart(isoDate);
        if (season !== null) out.push(SEASON_TEXTS[season]);
    }
    return out;
}
