// Daylight-saving changes and season starts (map §5.5).
//
// A season starts at its equinox / solstice — an instant, computed astronomically (Meeus,
// Astronomical Algorithms, ch. 27; good to under a minute for 1951–2050) and converted to the Czech
// civil date the same way `moon.js` converts its phases. A fixed table (20 Mar, 21 Jun, 22 Sep,
// 21 Dec) is wrong about one year in two: autumn starts on 23 September in 2026 and 2027, summer on
// 20 June in 2028.

import { addDays, daysInMonth, iso, parts, weekday } from './dates.js';

const RAD = Math.PI / 180;

// Mean equinox / solstice for years 1000–3000 (Meeus table 27.B): JDE0 = Σ c[i] · Y^i, Y = (year − 2000) / 1000.
// prettier-ignore
const SEASON_MEAN = [
    [2451623.80984, 365242.37404, 0.05169, -0.00411, -0.00057], // March equinox
    [2451716.56767, 365241.62603, 0.00325, 0.00888, -0.00030], // June solstice
    [2451810.21715, 365242.01767, -0.11575, 0.00337, 0.00078], // September equinox
    [2451900.05952, 365242.74049, -0.06223, -0.00823, 0.00032], // December solstice
];
// Periodic terms (Meeus table 27.C): S = Σ A · cos(B + C·T)
// prettier-ignore
const SEASON_TERMS = [
    [485, 324.96, 1934.136], [203, 337.23, 32964.467], [199, 342.08, 20.186], [182, 27.85, 445267.112],
    [156, 73.14, 45036.886], [136, 171.52, 22518.443], [77, 222.54, 65928.934], [74, 296.72, 3034.906],
    [70, 243.58, 9037.513], [58, 119.81, 33718.147], [52, 297.17, 150.678], [50, 21.02, 2281.226],
    [45, 247.54, 29929.562], [44, 325.15, 31555.956], [29, 60.93, 4443.417], [18, 155.12, 67555.328],
    [17, 288.79, 4550.194], [16, 198.04, 62894.029], [14, 199.76, 31436.921], [12, 95.39, 14577.848],
    [12, 287.11, 31931.756], [12, 320.81, 34777.259], [9, 227.73, 1222.114], [8, 15.45, 16859.074],
];
export const SEASON_TEXTS = Object.freeze(['Začátek jara', 'Začátek léta', 'Začátek podzimu', 'Začátek zimy']);
export const DST_TEXTS = Object.freeze({ summer: 'Začátek letního času', winter: 'Začátek zimního času' });

/** Last Sunday of March → 'summer', of October → 'winter'. */
export function dstChange(isoDate) {
    const p = parts(isoDate);
    if (p.m !== 3 && p.m !== 10) return null;
    if (isoDate !== lastSundayOf(p.y, p.m)) return null;
    return p.m === 3 ? 'summer' : 'winter';
}

function lastSundayOf(year, month) {
    const last = iso(year, month, daysInMonth(year, month));
    return addDays(last, -((weekday(last) + 1) % 7));
}

/**
 * Julian Ephemeris Day (TT) of a season start.
 * @param season 0 March equinox, 1 June solstice, 2 September equinox, 3 December solstice
 */
export function seasonJde(year, season) {
    const Y = (year - 2000) / 1000;
    const jde0 = SEASON_MEAN[season].reduce((sum, c, power) => sum + c * Y ** power, 0);
    const T = (jde0 - 2451545.0) / 36525;
    const W = (35999.373 * T - 2.47) * RAD;
    const lambda = 1 + 0.0334 * Math.cos(W) + 0.0007 * Math.cos(2 * W);
    let S = 0;
    for (const [a, b, c] of SEASON_TERMS) S += a * Math.cos((b + c * T) * RAD);
    return jde0 + (0.00001 * S) / lambda;
}

// The two conversions below mirror `deltaT` and `pragueOffsetHours` of moon.js (private there).

/** ΔT = TT − UT in seconds (Espenak & Meeus polynomial for 2005–2050, good to a few seconds). */
function deltaT(year) {
    const t = year - 2000;
    return 62.92 + 0.32217 * t + 0.005589 * t * t;
}

/** Offset of Czech civil time from UTC in hours at a UTC instant (ms): summer time between the last Sundays of March and October, 01:00 UTC. */
function pragueOffsetHours(utcMs) {
    const year = new Date(utcMs).getUTCFullYear();
    const at = (date) => {
        const p = parts(date);
        return Date.UTC(p.y, p.m - 1, p.d, 1);
    };
    return utcMs >= at(lastSundayOf(year, 3)) && utcMs < at(lastSundayOf(year, 10)) ? 2 : 1;
}

const seasonCache = new Map();

/**
 * The four season starts of a year, in order (spring, summer, autumn, winter).
 * @returns {{ season: number, utcMs: number, date: string }[]} `date` = Czech civil date
 */
export function seasonInstants(year) {
    let list = seasonCache.get(year);
    if (!list) {
        list = [0, 1, 2, 3].map((season) => {
            const jde = seasonJde(year, season);
            const utcMs = (jde - 2440587.5) * 86400000 - deltaT(2000 + (jde - 2451545) / 365.25) * 1000;
            const local = new Date(utcMs + pragueOffsetHours(utcMs) * 3600000);
            return Object.freeze({ season, utcMs, date: iso(local.getUTCFullYear(), local.getUTCMonth() + 1, local.getUTCDate()) });
        });
        seasonCache.set(year, Object.freeze(list));
    }
    return list;
}

/** 0 spring, 1 summer, 2 autumn, 3 winter when the season starts on this Czech date; otherwise null. */
export function seasonStart(isoDate) {
    const p = parts(isoDate);
    if (p.m % 3 !== 0) return null;
    const hit = seasonInstants(p.y).find((s) => s.date === isoDate);
    return hit ? hit.season : null;
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
