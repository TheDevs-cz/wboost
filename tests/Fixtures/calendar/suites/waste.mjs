// Waste rules: every interval, range, dates mode, extras (architecture §E.11, handoff C4 / C5).
import assert from 'node:assert/strict';
import { makeDoc } from '../helpers.mjs';

export default async function (t, load) {
    const { wasteRuleOn, wasteKindsOn, monthCounts } = await load('generate/waste.js');
    const dates = await load('generate/dates.js');
    const { dayInfo, createDayContext } = await load('generate/day.js');

    const kind = (o = {}) => ({ builtin: 'bio', name: 'Bio odpad', on: true, place: '', mode: 'rule', weekdays: [3], interval: 'w', from: null, to: null, dates: [], pos: 0, ...o });
    const daysOn = (k, year = 2027) => {
        const out = [];
        for (let d = dates.iso(year - 1, 12, 1); d <= dates.iso(year + 1, 1, 31); d = dates.addDays(d, 1)) if (wasteRuleOn(k, d, year)) out.push(d);
        return out;
    };

    t.test('weekly: every selected weekday of the calendar year, nothing outside it', () => {
        const on = daysOn(kind());
        assert.equal(on.length, 52);
        assert.equal(on[0], '2027-01-07');
        assert.equal(on[on.length - 1], '2027-12-30');
        assert.ok(on.every((d) => dates.weekday(d) === 3));
    });

    t.test('C4 Bio odpad every Thursday from 1 April to 30 November', () => {
        const on = daysOn(kind({ from: '2027-04-01', to: '2027-11-30' }));
        const expected = [];
        for (let d = '2027-04-01'; d <= '2027-11-30'; d = dates.addDays(d, 1)) if (dates.weekday(d) === 3) expected.push(d);
        assert.deepEqual(on, expected);
        assert.equal(on[0], '2027-04-01');
        assert.equal(on[on.length - 1], '2027-11-25');
        assert.equal(wasteRuleOn(kind({ from: '2027-04-01', to: '2027-11-30' }), '2027-03-25', 2027), false);
        assert.equal(wasteRuleOn(kind({ from: '2027-04-01', to: '2027-11-30' }), '2027-12-02', 2027), false);
    });

    t.test('even / odd weeks follow the ISO week number', () => {
        const even = daysOn(kind({ interval: '2e' }));
        const odd = daysOn(kind({ interval: '2o' }));
        assert.ok(even.every((d) => dates.isoWeek(d).week % 2 === 0));
        assert.ok(odd.every((d) => dates.isoWeek(d).week % 2 === 1));
        assert.equal(even.length + odd.length, 52);
        assert.equal(odd[0], '2027-01-07');
        assert.equal(even[0], '2027-01-14');
    });

    t.test('every fourth week, and the variant two weeks later', () => {
        const a = daysOn(kind({ interval: '4w' }));
        const b = daysOn(kind({ interval: '4w2' }));
        assert.ok(a.every((d) => dates.isoWeek(d).week % 4 === 1));
        assert.ok(b.every((d) => dates.isoWeek(d).week % 4 === 3));
        assert.deepEqual(a.slice(0, 3), ['2027-01-07', '2027-02-04', '2027-03-04']);
        assert.deepEqual(b.slice(0, 2), ['2027-01-21', '2027-02-18']);
        assert.equal(dates.diffDays(a[0], b[0]), 14);
    });

    t.test('first in the month: the weekday within the first seven days', () => {
        const on = daysOn(kind({ interval: 'm', weekdays: [0] }));
        assert.equal(on.length, 12);
        assert.ok(on.every((d) => dates.parts(d).d <= 7 && dates.weekday(d) === 0));
        assert.equal(on[0], '2027-01-04');
    });

    t.test('several weekdays; a range may reach outside the calendar year', () => {
        const on = daysOn(kind({ weekdays: [0, 4], from: '2026-12-01', to: '2027-01-10' }));
        assert.deepEqual(on, ['2026-12-04', '2026-12-07', '2026-12-11', '2026-12-14', '2026-12-18', '2026-12-21', '2026-12-25', '2026-12-28', '2027-01-01', '2027-01-04', '2027-01-08']);
    });

    t.test('C5 dates mode: exactly the listed days, the rule is ignored', () => {
        const k = kind({ mode: 'dates', dates: ['2027-02-03', '2027-02-17', '2027-05-09'] });
        assert.deepEqual(daysOn(k), ['2027-02-03', '2027-02-17', '2027-05-09']);
        assert.deepEqual(monthCounts(k, 2027), [0, 2, 0, 0, 1, 0, 0, 0, 0, 0, 0, 0]);
        assert.deepEqual(monthCounts(kind({ from: '2027-04-01', to: '2027-04-30' }), 2027), [0, 0, 0, 5, 0, 0, 0, 0, 0, 0, 0, 0]);
    });

    t.test('a day shows a kind only when the feature and the kind are on; extras ignore range and mode', async () => {
        const content = {
            wasteKinds: {
                bio: kind({ from: '2027-04-01', to: '2027-11-30', pos: 1, place: 'Pod vlekem' }),
                kom: kind({ builtin: 'kom', name: 'Komunální odpad', weekdays: [3], pos: 0 }),
                plast: kind({ builtin: 'plast', name: 'Plast', on: false, pos: 2 }),
                papir: kind({ builtin: 'papir', name: 'Papír', mode: 'dates', dates: [], pos: 3 }),
            },
            wasteExtras: { x1: { kind: 'bio', date: '2027-01-05' }, x2: { kind: 'plast', date: '2027-01-05' }, x3: { kind: 'papir', date: '2027-01-05' } },
        };
        const doc = await makeDoc(load, { features: { waste: true }, content });
        const ctx = createDayContext(doc);
        assert.deepEqual(wasteKindsOn(doc, '2027-04-01', ctx).map((k) => k.id), ['kom', 'bio']);
        assert.deepEqual(wasteKindsOn(doc, '2027-03-25', ctx).map((k) => k.id), ['kom']);
        // Tuesday 5 January: extras only — bio (out of range), papír (dates mode); plast is switched off
        assert.deepEqual(wasteKindsOn(doc, '2027-01-05', ctx).map((k) => k.id), ['bio', 'papir']);
        assert.deepEqual(wasteKindsOn(doc, '2027-01-05').map((k) => k.id), ['bio', 'papir']);
        const info = dayInfo(doc, '2027-04-01', ctx);
        assert.deepEqual(info.icons.map((i) => [i.category, i.kindId, i.color, i.title]), [['waste', 'kom', '#3B3A3F', 'Komunální odpad'], ['waste', 'bio', '#7A5230', 'Bio odpad']]);
        assert.deepEqual(info.places, ['Pod vlekem']);
        assert.deepEqual(dayInfo(doc, '2027-01-05', ctx).extras.map((e) => e.kindId).sort(), ['bio', 'papir', 'plast']);
        const off = await makeDoc(load, { features: { waste: false }, content });
        assert.deepEqual(wasteKindsOn(off, '2027-04-01'), []);
        assert.deepEqual(dayInfo(off, '2027-04-01').icons, []);
    });

    t.test('own colour and icon of a kind come from the layout', async () => {
        const content = { wasteKinds: { bio: kind(), Custom123: kind({ builtin: null, name: 'Textil', pos: 1 }) } };
        const doc = await makeDoc(load, { features: { waste: true, moon: false }, content, styles: (l) => { l.styles.waste.kinds = { bio: { color: '#112233', icon: 'asset-1' } }; } });
        const icons = dayInfo(doc, '2027-01-07').icons;
        assert.deepEqual(icons.map((i) => [i.kindId, i.color, i.asset]), [['bio', '#112233', 'asset-1'], ['Custom123', '#8A8F98', null]]);
    });
}
