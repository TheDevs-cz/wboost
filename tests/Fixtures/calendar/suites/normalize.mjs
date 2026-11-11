// normalizeDocument: defaults, idempotence, repair; layout files (architecture §B.8).
import assert from 'node:assert/strict';
import { ENV, copy, makeDoc } from '../helpers.mjs';

export default async function (t, load) {
    const N = await load('model/normalize.js');
    const D = await load('model/defaults.js');
    const L = await load('model/layouts.js');

    t.test('a stored calendar with empty settings gets every default', () => {
        const doc = N.normalizeDocument({ id: 'abc', revision: 7, name: 'Stolní kalendář 2027', year: 2027, type: 'week', page: {}, print: {}, features: {}, preset: null, layout: null, linkElements: true, step: 1, finished: false, content: { v: 1, wasteKinds: {} } }, ENV);
        assert.equal(doc.id, 'abc');
        assert.equal(doc.revision, 7);
        assert.deepEqual(doc.design.page, copy(D.DEFAULT_PAGE));
        assert.deepEqual(doc.design.print, copy(D.DEFAULT_PRINT));
        assert.deepEqual(doc.design.features, copy(D.DEFAULT_FEATURES));
        assert.equal(doc.design.layout, null);
        assert.equal(doc.design.preset, null);
        assert.equal(doc.design.linkElements, true);
        assert.equal(doc.content.v, 1);
        assert.deepEqual(doc.content.events, {});
        assert.deepEqual(Object.keys(doc.design).sort(), ['features', 'finished', 'layout', 'linkElements', 'name', 'page', 'preset', 'print', 'step', 'type', 'year']);
    });

    t.test('garbage in, a usable document out', () => {
        const doc = N.normalizeDocument({ year: 'x', type: 'month', step: 9, linkElements: 'yes', page: [], print: { bleedMm: 99, bleed: 'no' }, features: { moon: 0, bogus: true }, preset: 'photoLeft', content: [], junk: 1 }, ENV);
        assert.equal(doc.design.year, 2027); // current year + 1
        assert.equal(doc.design.type, 'week');
        assert.equal(doc.design.step, 1);
        assert.equal(doc.design.linkElements, true);
        assert.equal(doc.design.print.bleedMm, 10);
        assert.equal(doc.design.print.bleed, true);
        assert.equal(doc.design.features.moon, true);
        assert.equal(doc.design.features.bogus, undefined);
        assert.equal(doc.design.preset, null, 'a preset without a layout is dropped');
        assert.equal(doc.junk, undefined);
        assert.deepEqual(N.normalizeDocument(null, ENV).design.page, copy(D.DEFAULT_PAGE));
    });

    t.test('normalising is idempotent', async () => {
        const docs = [
            N.normalizeDocument({}, ENV),
            await makeDoc(load),
            await makeDoc(load, { preset: 'photoRight', features: { qr: true, events: true } }),
            await makeDoc(load, { type: 'fortnight', print: { bleed: false, bleedMm: 3, marks: false } }),
            await makeDoc(load, { styles: (l) => {
                l.styles.offsets = { name: { x: 1.26, y: 0 }, num: { x: 0, y: 0 } };
                l.styles.ev.box = { x: 1, y: 2, w: 1, h: 0.2 };
                l.styles.cal.iconCells = { '4': { 1: { size: 4, align: 'end' } } };
                l.styles.waste.kinds = { bio: { color: '#000000', icon: null } };
                l.styles.num.size = 999;
                l.boxes.logo.hidden = true;
            } }),
        ];
        for (const doc of docs) {
            const once = N.normalizeDocument(copy(doc), ENV);
            const twice = N.normalizeDocument(copy(once), ENV);
            assert.deepEqual(twice, once);
            assert.deepEqual(once, doc, 'makeDoc already returns a normalised document');
        }
    });

    t.test('layout repair: missing styles, numeric limits, open maps', () => {
        const layout = L.defaultLayout('photoLeft', D.DEFAULT_PAGE, 5, ENV);
        delete layout.styles.shift;
        delete layout.styles.moon.valign;
        layout.styles.num.size = 500;
        layout.styles.lines.width = 0;
        layout.styles.cal.iconSize = 0.2;
        layout.styles.moon.size = 50;
        layout.styles.moon.phases = [false, true];
        layout.styles.month.pad = -3;
        layout.styles.bg.gradient.stops = [{ color: '#000000', opacity: 100, pos: 0 }];
        layout.styles.offsets = { name: { x: 1.26, y: 0 }, num: { x: 0, y: 0 }, bad: 'x' };
        layout.styles.ev.box = { x: 1.04, y: 2, w: 1, h: 0.2 };
        layout.styles.cal.iconCells = { '2x2': { 3: { size: 5, align: 'end' } } };
        layout.styles.icons.order = ['moon', 'bogus', 'moon', 'ev'];
        layout.styles.unknownKey = { a: 1 };
        const out = N.normalizeLayout(layout, 'week', D.DEFAULT_PAGE, ENV);
        const defaults = L.defaultStyles(ENV);
        assert.deepEqual(out.styles.shift, defaults.shift);
        assert.equal(out.styles.moon.valign, 'middle');
        assert.equal(out.styles.num.size, 60);
        assert.equal(out.styles.lines.width, 0.1);
        assert.equal(out.styles.cal.iconSize, 1.5);
        assert.equal(out.styles.moon.size, 9);
        assert.deepEqual(out.styles.moon.phases, [false, true, true, true]);
        assert.equal(out.styles.month.pad, 0);
        assert.equal(out.styles.bg.gradient.stops.length, 2);
        assert.deepEqual(out.styles.offsets, { name: { x: 1.3, y: 0 } });
        assert.deepEqual(out.styles.ev.box, { x: 1, y: 2, w: D.K.EVBOX_MIN_W, h: D.K.EVBOX_MIN_H });
        assert.deepEqual(out.styles.cal.iconCells, { '2x2': { 3: { size: 5, align: 'end' } } });
        assert.deepEqual(out.styles.icons.order, ['moon', 'ev']);
        assert.equal(out.styles.unknownKey, undefined);
        assert.equal(layout.styles.num.size, 500, 'the argument is not mutated');
    });

    t.test('layout repair: boxes follow the type, order is completed, hidden only where allowed', () => {
        const layout = L.defaultLayout('photoLeft', D.DEFAULT_PAGE, 5, ENV);
        layout.order = ['cal', 'bogus', 'photo', 'cal', 'head'];
        layout.boxes.photo.hidden = true;
        layout.boxes.cap.hidden = true;
        layout.boxes.wk.hidden = true;
        delete layout.boxes.qr;
        layout.boxes.bogus = { x: 0, y: 0, w: 1, h: 1 };
        const week = N.normalizeLayout(layout, 'week', D.DEFAULT_PAGE, ENV);
        assert.deepEqual(week.order, ['qr', 'logo', 'cap', 'wk', 'month', 'cal', 'photo', 'head']);
        assert.equal(week.boxes.photo.hidden, undefined);
        assert.equal(week.boxes.cap.hidden, undefined);
        assert.equal(week.boxes.wk.hidden, true);
        assert.ok(week.boxes.qr);
        assert.equal(week.boxes.bogus, undefined);
        assert.equal(week.boxes.cal2, undefined);
        const fort = N.normalizeLayout(layout, 'fortnight', D.DEFAULT_PAGE, ENV);
        assert.ok(fort.boxes.cal2, 'a fortnight always has the second frame');
        const two = L.defaultLayout('twoSided', D.DEFAULT_PAGE, 5, ENV);
        assert.equal(N.normalizeLayout(two, 'week', D.DEFAULT_PAGE, ENV).boxes.cal2, undefined);
    });

    t.test('a layout file round-trips', async () => {
        const doc = await makeDoc(load, { preset: 'photoRight', styles: (l) => {
            l.boxes.logo = { x: 20, y: 40, w: 30, h: 12, hidden: true };
            l.styles.num.color = '#445566';
            l.styles.offsets = { name: { x: 1.5, y: 0 } };
        } });
        const file = N.exportLayoutFile(doc);
        assert.equal(file.wboostCalendarLayout, 1);
        assert.equal(file.type, 'week');
        assert.equal(file.preset, 'photoRight');
        assert.equal(file.bleedMm, 5);
        assert.equal(file.sourceCalendar, 'cal-1');
        assert.deepEqual(file.page, doc.design.page);
        const parsed = N.parseLayoutFile(JSON.stringify(file), doc.design);
        assert.equal(parsed.ok, true);
        assert.equal(parsed.sourceCalendar, 'cal-1');
        assert.deepEqual(parsed.layout, doc.design.layout);
        // the export is a copy
        file.layout.styles.num.color = '#000000';
        assert.equal(doc.design.layout.styles.num.color, '#445566');
    });

    t.test('a layout file made with another bleed or page size is adapted', async () => {
        const source = await makeDoc(load);
        const file = N.exportLayoutFile(source);
        const noBleed = await makeDoc(load, { print: { bleed: false, bleedMm: 5, marks: false } });
        const a = N.parseLayoutFile(JSON.stringify(file), noBleed.design);
        assert.deepEqual(a.layout.boxes, L.defaultBoxes('photoLeft', noBleed.design.page, 0));
        const bigPage = { ...copy(D.DEFAULT_PAGE), width: 420, height: 200 };
        const big = await makeDoc(load, { page: bigPage, print: { bleed: true, bleedMm: 3, marks: true } });
        const b = N.parseLayoutFile(JSON.stringify(file), big.design);
        assert.equal(b.ok, true);
        const e = b.layout.boxes;
        assert.deepEqual([e.head.x, e.head.y, e.head.x + e.head.w], [-3, -3, 423]);
        assert.ok(Math.abs(e.photo.y + e.photo.h - 203) < 1e-9);
        assert.ok(Math.abs(e.photo.x + e.photo.w - e.cal.x) < 1e-9, 'coinciding lines survive the scaling');
        assert.ok(Math.abs(e.cal.x - 197 * (420 / D.DEFAULT_PAGE.width)) <= 0.05);
    });

    t.test('foreign files are rejected', async () => {
        const doc = await makeDoc(load);
        const file = N.exportLayoutFile(doc);
        const bad = [
            'not json',
            '[]',
            '{}',
            JSON.stringify({ ...file, wboostCalendarLayout: 2 }),
            JSON.stringify({ ...file, type: 'fortnight' }),
            JSON.stringify({ ...file, layout: null }),
            JSON.stringify({ ...file, layout: { boxes: {}, styles: 'x' } }),
            JSON.stringify({ ...file, layout: { styles: {} } }),
        ];
        for (const text of bad) assert.deepEqual(N.parseLayoutFile(text, doc.design), { ok: false }, text.slice(0, 40));
        const fort = await makeDoc(load, { type: 'fortnight' });
        assert.deepEqual(N.parseLayoutFile(JSON.stringify(file), fort.design), { ok: false });
    });

    t.test('limits and unit helpers', () => {
        assert.equal(D.clampLimit('fontSize', 2), 3);
        assert.equal(D.clampLimit('fontSize', 12.26), 12.5);
        assert.equal(D.clampLimit('percent', 103), 100);
        assert.equal(D.clampLimit('percent', 52), 50);
        assert.equal(D.clampLimit('bleedMm', 4.74), 4.5);
        assert.equal(D.clampLimit('photoZoom', 1.234), 1.23);
        assert.equal(D.clampLimit('shadowOffset', -11), -10);
        assert.equal(D.clampLimit('lineWidth', 0.34), 0.3);
        assert.throws(() => D.clampLimit('nope', 1));
        assert.equal(D.roundMm(1.26), 1.3);
        assert.equal(D.roundMm(-0.04), 0);
        assert.ok(Object.is(D.roundMm(-0.04), 0));
        assert.ok(Math.abs(D.ptToMm(72) - 25.4) < 1e-12);
        assert.equal(D.bleedOf({ bleed: true, bleedMm: 5 }), 5);
        assert.equal(D.bleedOf({ bleed: false, bleedMm: 5 }), 0);
        assert.equal(D.K.LINK_TOL, 0.15);
        assert.deepEqual([D.K.MIN_W, D.K.MIN_H, D.K.NUDGE, D.K.NUDGE_BIG, D.K.GRID], [6, 4, 0.3, 3, 0.1]);
        assert.deepEqual(Object.keys(D.BUILTIN_WASTE), ['kom', 'bio', 'plast', 'papir', 'sklo']);
    });
}
