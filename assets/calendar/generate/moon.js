// Moon phases: true phase instants (Meeus, Astronomical Algorithms, ch. 49) converted to the Czech
// civil date. Phase 0 = nov, 1 = první čtvrť, 2 = úplněk, 3 = poslední čtvrť.

import { addDays, compare, iso, parts, weekday } from './dates.js';
import { sorted } from '../model/content.js';

const RAD = Math.PI / 180;
const sin = (deg) => Math.sin(deg * RAD);
const cos = (deg) => Math.cos(deg * RAD);

// [coefficient, power of E, multiple of M, of M', of F]
// prettier-ignore
const NEW_MOON = [
    [-0.40720, 0, 0, 1, 0], [0.17241, 1, 1, 0, 0], [0.01608, 0, 0, 2, 0], [0.01039, 0, 0, 0, 2], [0.00739, 1, -1, 1, 0],
    [-0.00514, 1, 1, 1, 0], [0.00208, 2, 2, 0, 0], [-0.00111, 0, 0, 1, -2], [-0.00057, 0, 0, 1, 2], [0.00056, 1, 1, 2, 0],
    [-0.00042, 0, 0, 3, 0], [0.00042, 1, 1, 0, 2], [0.00038, 1, 1, 0, -2], [-0.00024, 1, -1, 2, 0], [-0.00007, 0, 2, 1, 0],
    [0.00004, 0, 0, 2, -2], [0.00004, 0, 3, 0, 0], [0.00003, 0, 1, 1, -2], [0.00003, 0, 0, 2, 2], [-0.00003, 0, 1, 1, 2],
    [0.00003, 0, -1, 1, 2], [-0.00002, 0, -1, 1, -2], [-0.00002, 0, 1, 3, 0], [0.00002, 0, 0, 4, 0],
];
// prettier-ignore
const FULL_MOON = [
    [-0.40614, 0, 0, 1, 0], [0.17302, 1, 1, 0, 0], [0.01614, 0, 0, 2, 0], [0.01043, 0, 0, 0, 2], [0.00734, 1, -1, 1, 0],
    [-0.00515, 1, 1, 1, 0], [0.00209, 2, 2, 0, 0], [-0.00111, 0, 0, 1, -2], [-0.00057, 0, 0, 1, 2], [0.00056, 1, 1, 2, 0],
    [-0.00042, 0, 0, 3, 0], [0.00042, 1, 1, 0, 2], [0.00038, 1, 1, 0, -2], [-0.00024, 1, -1, 2, 0], [-0.00007, 0, 2, 1, 0],
    [0.00004, 0, 0, 2, -2], [0.00004, 0, 3, 0, 0], [0.00003, 0, 1, 1, -2], [0.00003, 0, 0, 2, 2], [-0.00003, 0, 1, 1, 2],
    [0.00003, 0, -1, 1, 2], [-0.00002, 0, -1, 1, -2], [-0.00002, 0, 1, 3, 0], [0.00002, 0, 0, 4, 0],
];
// prettier-ignore
const QUARTER = [
    [-0.62801, 0, 0, 1, 0], [0.17172, 1, 1, 0, 0], [-0.01183, 1, 1, 1, 0], [0.00862, 0, 0, 2, 0], [0.00804, 0, 0, 0, 2],
    [0.00454, 1, -1, 1, 0], [0.00204, 2, 2, 0, 0], [-0.00180, 0, 0, 1, -2], [-0.00070, 0, 0, 1, 2], [-0.00040, 0, 0, 3, 0],
    [-0.00034, 1, -1, 2, 0], [0.00032, 1, 1, 0, 2], [0.00032, 1, 1, 0, -2], [-0.00028, 2, 2, 1, 0], [0.00027, 1, 1, 2, 0],
    [-0.00005, 0, -1, 1, -2], [0.00004, 0, 0, 2, 2], [-0.00004, 0, 1, 1, 2], [0.00004, 0, -2, 1, 0], [0.00003, 0, 1, 1, -2],
    [0.00003, 0, 3, 0, 0], [0.00002, 0, 0, 2, -2], [0.00002, 0, -1, 1, 2], [-0.00002, 0, 1, 3, 0],
];
// prettier-ignore
const PLANETARY = [
    [0.000325, 299.77, 0.107408], [0.000165, 251.88, 0.016321], [0.000164, 251.83, 26.651886], [0.000126, 349.42, 36.412478],
    [0.000110, 84.66, 18.206239], [0.000062, 141.74, 53.303771], [0.000060, 207.14, 2.453732], [0.000056, 154.84, 7.306860],
    [0.000047, 34.52, 27.261239], [0.000042, 207.19, 0.121824], [0.000040, 291.34, 1.844379], [0.000037, 161.72, 24.198154],
    [0.000035, 239.56, 25.513099], [0.000023, 331.55, 3.592518],
];

/** Julian Ephemeris Day (TT) of the phase with lunation number `k` (integer = new moon, +0.25 … +0.75). */
function phaseJde(k) {
    const T = k / 1236.85;
    const T2 = T * T;
    const T3 = T2 * T;
    const T4 = T3 * T;
    let jde = 2451550.09766 + 29.530588861 * k + 0.00015437 * T2 - 0.00000015 * T3 + 0.00000000073 * T4;
    const E = 1 - 0.002516 * T - 0.0000074 * T2;
    const M = 2.5534 + 29.1053567 * k - 0.0000014 * T2 - 0.00000011 * T3;
    const Mp = 201.5643 + 385.81693528 * k + 0.0107582 * T2 + 0.00001238 * T3 - 0.000000058 * T4;
    const F = 160.7108 + 390.67050284 * k - 0.0016118 * T2 - 0.00000227 * T3 + 0.000000011 * T4;
    const omega = 124.7746 - 1.56375588 * k + 0.0020672 * T2 + 0.00000215 * T3;
    const phase = Math.round((((k % 1) + 1) % 1) * 4) % 4;
    const terms = phase === 0 ? NEW_MOON : phase === 2 ? FULL_MOON : QUARTER;
    let sum = -0.00017 * sin(omega);
    for (const [c, e, m, mp, f] of terms) {
        sum += c * (e === 0 ? 1 : e === 1 ? E : E * E) * sin(m * M + mp * Mp + f * F);
    }
    if (phase === 1 || phase === 3) {
        const w = 0.00306 - 0.00038 * E * cos(M) + 0.00026 * cos(Mp) - 0.00002 * cos(Mp - M) + 0.00002 * cos(Mp + M) + 0.00002 * cos(2 * F);
        sum += phase === 1 ? w : -w;
    }
    for (const [c, a0, a1] of PLANETARY) {
        sum += c * sin(a0 + a1 * k - (a0 === 299.77 ? 0.009173 * T2 : 0));
    }
    return jde + sum;
}

/** ΔT = TT − UT in seconds (Espenak & Meeus polynomial for 2005–2050, good to a few seconds). */
function deltaT(year) {
    const t = year - 2000;
    return 62.92 + 0.32217 * t + 0.005589 * t * t;
}

function lastSunday(year, month) {
    const last = iso(year, month, month === 3 || month === 10 ? 31 : 30);
    return addDays(last, -((weekday(last) + 1) % 7));
}

/** Offset of Czech civil time from UTC in hours at a UTC instant (ms). */
function pragueOffsetHours(utcMs) {
    const year = new Date(utcMs).getUTCFullYear();
    const at = (date) => {
        const p = parts(date);
        return Date.UTC(p.y, p.m - 1, p.d, 1);
    };
    return utcMs >= at(lastSunday(year, 3)) && utcMs < at(lastSunday(year, 10)) ? 2 : 1;
}

/**
 * Phase instants between two approximate years (inclusive), for tests and tooling.
 * @returns {{ phase: number, utcMs: number, date: string }[]} `date` = Czech civil date
 */
export function moonPhaseInstants(fromYear, toYear) {
    const out = [];
    const k0 = Math.floor((fromYear - 2000) * 12.3685) - 2;
    const k1 = Math.ceil((toYear + 1 - 2000) * 12.3685) + 2;
    for (let q = k0 * 4; q <= k1 * 4; q++) {
        const k = q / 4;
        const jde = phaseJde(k);
        const approxYear = 2000 + (jde - 2451545) / 365.25;
        const utcMs = (jde - 2440587.5) * 86400000 - deltaT(approxYear) * 1000;
        const local = new Date(utcMs + pragueOffsetHours(utcMs) * 3600000);
        out.push({
            phase: ((q % 4) + 4) % 4,
            utcMs,
            date: iso(local.getUTCFullYear(), local.getUTCMonth() + 1, local.getUTCDate()),
        });
    }
    return out;
}

/** Phases whose Czech date lies in `year−1-12-20 … year+1-01-12`. */
export function computeMoonPhases(year) {
    const min = iso(year - 1, 12, 20);
    const max = iso(year + 1, 1, 12);
    return moonPhaseInstants(year - 1, year + 1)
        .filter((p) => p.date >= min && p.date <= max)
        .map((p) => ({ date: p.date, phase: p.phase }))
        .sort((a, b) => compare(a.date, b.date));
}

/** Computed phases with the calendar's `moonEdits` applied. */
export function effectivePhases(doc) {
    const map = new Map();
    for (const p of computeMoonPhases(doc.design.year)) map.set(p.date, p.phase);
    const edits = sorted((doc.content && doc.content.moonEdits) || {}, 'date');
    for (const [, edit] of edits) {
        if (edit.op === 'remove' && map.get(edit.date) === edit.phase) map.delete(edit.date);
    }
    for (const [, edit] of edits) {
        if (edit.op === 'add') map.set(edit.date, edit.phase);
    }
    return map;
}
