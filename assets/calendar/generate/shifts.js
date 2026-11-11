// Shift schedules (map §2.3, §4.4).

import { diffDays } from './dates.js';

export const SHIFT_PRESETS = Object.freeze({
    8: [['R', '06:00', '14:00'], ['O', '14:00', '22:00'], ['N', '22:00', '06:00']],
    12: [['D', '06:00', '18:00'], ['N', '18:00', '06:00']],
});

/** The n-th (1-based) default operation: `Provoz {n}`, 8-hour preset, crews A–D. */
export function defaultOperation(n) {
    return {
        name: `Provoz ${n}`,
        shifts: SHIFT_PRESETS[8].map(([letter, from, to]) => ({ letter, from, to })),
        crews: ['A', 'B', 'C', 'D'],
        pos: Math.max(0, n - 1),
    };
}

/**
 * One crew letter (or '–') per shift of the operation on a date. Each crew works every shift for two
 * days, then is off; crews are offset by two days, column `columnIndex` by three days.
 */
export function shiftCrews(op, isoDate, columnIndex) {
    const n = diffDays('1970-01-01', isoDate) + 3 * (columnIndex || 0);
    const shifts = op.shifts || [];
    const k = shifts.length;
    const cycle = Math.max(8, (k + 1) * 2);
    const out = [];
    (op.crews || []).forEach((crew, i) => {
        const q = Math.floor(((((n + i * 2) % cycle) + cycle) % cycle) / 2);
        if (q < k && !out[q]) out[q] = crew;
    });
    return shifts.map((_, q) => out[q] || '–');
}
