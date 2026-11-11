// Waste collection rules (architecture §E.11).

import { daysInMonth, iso, isoWeek, parts, weekday } from './dates.js';
import { sorted } from '../model/content.js';

export function wasteRuleOn(kind, isoDate, year) {
    if (kind.mode === 'dates') return (kind.dates || []).includes(isoDate);
    const from = kind.from || iso(year, 1, 1);
    const to = kind.to || iso(year, 12, 31);
    if (isoDate < from || isoDate > to) return false;
    if (!(kind.weekdays || []).includes(weekday(isoDate))) return false;
    const w = isoWeek(isoDate).week;
    switch (kind.interval) {
        case 'w':
            return true;
        case '2e':
            return w % 2 === 0;
        case '2o':
            return w % 2 === 1;
        case '4w':
            return w % 4 === 1;
        case '4w2':
            return w % 4 === 3;
        case 'm':
            return parts(isoDate).d <= 7;
        default:
            return false;
    }
}

/**
 * Waste kinds collected on a date: kinds with `on`, in `pos` order, where the rule matches or an
 * extra collection exists. `ctx` (a DayContext) is optional and only saves re-sorting.
 */
export function wasteKindsOn(doc, isoDate, ctx) {
    if (!doc.design.features.waste) return [];
    const kinds = ctx && ctx.wasteKinds ? ctx.wasteKinds : sorted(doc.content.wasteKinds || {}, 'pos');
    const extras = ctx && ctx.extrasByDate ? ctx.extrasByDate.get(isoDate) || [] : Object.entries(doc.content.wasteExtras || {}).filter(([, e]) => e.date === isoDate).map(([id, e]) => ({ id, kindId: e.kind }));
    const out = [];
    for (const [id, kind] of kinds) {
        if (!kind.on) continue;
        if (wasteRuleOn(kind, isoDate, doc.design.year) || extras.some((e) => e.kindId === id)) out.push({ id, kind });
    }
    return out;
}

/** Collections per month of `year` (12 counts) by the kind's own rule. */
export function monthCounts(kind, year) {
    const counts = [];
    for (let m = 1; m <= 12; m++) {
        let n = 0;
        for (let d = 1, last = daysInMonth(year, m); d <= last; d++) {
            if (wasteRuleOn(kind, iso(year, m, d), year)) n++;
        }
        counts.push(n);
    }
    return counts;
}
