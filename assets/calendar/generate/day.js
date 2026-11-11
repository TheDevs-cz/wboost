// Everything a day row prints (architecture §D.2 DayInfo, §E.11).

import { parts, weekday } from './dates.js';
import { MOON_PHASES, WEEKDAYS, WEEKDAYS_SHORT, holidayName, nameDay } from './czech.js';
import { effectivePhases } from './moon.js';
import { DST_TEXTS, SEASON_TEXTS, dstChange, seasonStart, timeTexts } from './time.js';
import { wasteKindsOn } from './waste.js';
import { shiftCrews } from './shifts.js';
import { sorted } from '../model/content.js';
import { BUILTIN_WASTE, FALLBACK_WASTE_COLOR } from '../model/defaults.js';
import { activeIconCategories } from '../model/elements.js';

export const MAX_EVENTS_PER_DAY = 4;

function groupByDate(collection, map) {
    const out = new Map();
    for (const [id, item] of sorted(collection || {}, 'date')) {
        if (!out.has(item.date)) out.set(item.date, []);
        out.get(item.date).push(map(id, item));
    }
    return out;
}

export function wasteKindColor(styles, id, kind) {
    const own = styles && styles.waste && styles.waste.kinds && styles.waste.kinds[id];
    if (own && own.color) return own.color;
    const builtin = kind.builtin ? BUILTIN_WASTE[kind.builtin] : null;
    return builtin ? builtin.color : FALLBACK_WASTE_COLOR;
}

/** Indexes over the content, built once per document state. */
export function createDayContext(doc) {
    const content = doc.content;
    const features = doc.design.features;
    const styles = (doc.design.layout && doc.design.layout.styles) || null;
    const wasteKinds = sorted(content.wasteKinds || {}, 'pos');
    const categories = styles ? activeIconCategories(doc.design) : [];
    const iconKeys = [];
    for (const category of categories) {
        if (category === 'waste') {
            for (const [id, kind] of wasteKinds) if (kind.on) iconKeys.push(`waste:${id}`);
        } else {
            iconKeys.push(category);
        }
    }
    return {
        styles,
        eventsByDate: groupByDate(content.events, (id, item) => ({ id, ...item })),
        namesByDate: groupByDate(content.names, (id, item) => ({ id, ...item })),
        extrasByDate: groupByDate(content.wasteExtras, (id, item) => ({ id, kindId: item.kind })),
        commentsByDate: groupByDate(content.comments, (id) => id),
        moon: features.moon ? effectivePhases(doc) : new Map(),
        wasteKinds,
        shiftOps: features.shifts ? sorted(content.shiftOps || {}, 'pos') : [],
        clubs: content.clubs || {},
        iconCategories: categories,
        /** Active icon keys of the calendar: categories in order, 'waste' expanded to `waste:<kindId>`. */
        iconKeys,
    };
}

export function dayInfo(doc, isoDate, ctx) {
    const context = ctx || createDayContext(doc);
    const features = doc.design.features;
    const styles = context.styles;
    const p = parts(isoDate);
    const wd = weekday(isoDate);
    const holiday = holidayName(isoDate);

    const extraNames = (context.namesByDate.get(isoDate) || []).map((n) => (n.name || '').trim());
    const names = [nameDay(p.m, p.d), ...extraNames].filter(Boolean).join(' / ');

    let events = [];
    let eventsDropped = 0;
    if (features.events) {
        const all = (context.eventsByDate.get(isoDate) || [])
            .filter((e) => (e.name || '').trim() !== '')
            .map((e) => {
                const club = e.club ? context.clubs[e.club] : null;
                const code = club && (club.code || '').trim();
                return { id: e.id, text: code ? `${code} · ${e.name.trim()}` : e.name.trim(), icon: e.icon || null };
            });
        events = all.slice(0, MAX_EVENTS_PER_DAY);
        eventsDropped = all.length - events.length;
    }

    const waste = wasteKindsOn(doc, isoDate, context);
    const icons = [];
    if (styles) {
        for (const category of context.iconCategories) {
            if (category === 'waste') {
                for (const { id, kind } of waste) {
                    const own = styles.waste.kinds[id];
                    icons.push({ category: 'waste', kindId: id, asset: (own && own.icon) || null, color: wasteKindColor(styles, id, kind), title: kind.name });
                }
            } else if (category === 'moon') {
                const phase = context.moon.get(isoDate);
                if (phase !== undefined && styles.moon.phases[phase]) {
                    icons.push({ category: 'moon', phase, asset: styles.moon.icons[phase] || null, color: styles.moon.color, title: MOON_PHASES[phase] });
                }
            } else if (category === 'ev') {
                if (events.length) {
                    const conf = styles.icons.ev;
                    icons.push({ category: 'ev', asset: (conf.perEvent && events[0].icon) || conf.asset || null, color: styles.ev.color, title: conf.name || 'Akce' });
                }
            } else if (category === 'time') {
                const dst = dstChange(isoDate);
                if (dst) icons.push({ category: 'time', asset: styles.icons.time.asset || null, color: styles.icons.time.color, title: DST_TEXTS[dst] });
            } else if (category === 'season') {
                const season = seasonStart(isoDate);
                if (season !== null) {
                    const conf = styles.icons.season;
                    icons.push({ category: 'season', season, asset: (conf.mode === 'one' ? conf.asset : conf.assets[season]) || null, color: conf.color, title: SEASON_TEXTS[season] });
                }
            }
        }
    }

    return {
        date: isoDate,
        y: p.y,
        m: p.m,
        d: p.d,
        wd,
        numberKind: holiday ? 'numHol' : wd === 6 ? 'numSun' : wd === 5 ? 'numSat' : 'num',
        weekday: WEEKDAYS[wd],
        weekdayShort: WEEKDAYS_SHORT[wd],
        names,
        holiday,
        events,
        eventsDropped,
        timeTexts: timeTexts(isoDate, features),
        icons,
        shifts: context.shiftOps.map(([, op], ci) => shiftCrews(op, isoDate, ci)),
        places: waste.map(({ kind }) => (kind.place || '').trim()).filter(Boolean),
        comments: (context.commentsByDate.get(isoDate) || []).length,
        extras: (context.extrasByDate.get(isoDate) || []).map((e) => ({ id: e.id, kindId: e.kindId })),
    };
}
