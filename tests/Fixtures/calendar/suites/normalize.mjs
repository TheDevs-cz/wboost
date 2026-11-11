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
        const asWeek = N.normalizeLayout(two, 'week', D.DEFAULT_PAGE, ENV).boxes;
        assert.deepEqual([asWeek.cal2, asWeek.month2, asWeek.wk2], [undefined, undefined, undefined]);
        // a second frame never carries `hidden`
        const flagged = copy(two);
        flagged.boxes.month2.hidden = true;
        flagged.boxes.wk2.hidden = true;
        const cleaned = N.normalizeLayout(flagged, 'fortnight', D.DEFAULT_PAGE, ENV).boxes;
        assert.deepEqual([cleaned.month2.hidden, cleaned.wk2.hidden], [undefined, undefined]);
    });

    // ---- 14-day layouts stored before Měsíc / Číslo týdne got one frame per week column ----------

    /** The 14-day layout as it was created and stored until 2026-11-11: one `month`, one `wk` (right, end-aligned). */
    const oldTwoSided = (change) => {
        const layout = L.defaultLayout('twoSided', D.DEFAULT_PAGE, 5, ENV);
        delete layout.boxes.month2;
        delete layout.boxes.wk2;
        layout.boxes.month = { x: 6, y: 12.5, w: 120, h: 14.5 };
        layout.boxes.wk = { x: 232, y: 139, w: 72, h: 4.5 };
        layout.styles.month.align = 'start';
        layout.styles.wk.align = 'end';
        if (change) change(layout);
        return layout;
    };
    const migrate = (layout, page = D.DEFAULT_PAGE) => N.normalizeLayout(copy(layout), 'fortnight', page, ENV);

    t.test('migration: an old 14-day layout gets month2 / wk2 mirrored about the page centre', () => {
        const old = oldTwoSided();
        const before = copy(old);
        const now = migrate(old);
        assert.deepEqual(old, before, 'the argument is not mutated');
        assert.equal(now.v, 1, 'no schema bump: the missing keys are the marker');
        // Měsíc was over the first week: it stays, the second frame is its mirror
        assert.deepEqual(now.boxes.month, { x: 6, y: 12.5, w: 120, h: 14.5 });
        assert.deepEqual(now.boxes.month2, { x: 184, y: 12.5, w: 120, h: 14.5 });
        assert.equal(now.styles.month.align, 'start');
        // Číslo týdne sat under the SECOND week, end-aligned: that box becomes the second frame and the
        // stored alignment is flipped, so it is still drawn end-aligned at the right
        assert.deepEqual(now.boxes.wk2, { x: 232, y: 139, w: 72, h: 4.5 });
        assert.deepEqual(now.boxes.wk, { x: 6, y: 139, w: 72, h: 4.5 });
        assert.equal(now.styles.wk.align, 'start');
        // which is exactly today's default
        const fresh = L.defaultLayout('twoSided', D.DEFAULT_PAGE, 5, ENV);
        assert.deepEqual(now.boxes, fresh.boxes);
        assert.deepEqual(now.styles, fresh.styles);
        // everything else is untouched
        for (const k of Object.keys(before.boxes)) if (k !== 'wk') assert.deepEqual(now.boxes[k], before.boxes[k], k);
        assert.deepEqual({ ...now.styles, wk: null }, { ...N.normalizeLayout(copy(before), 'fortnight', D.DEFAULT_PAGE, ENV).styles, wk: null });
    });

    t.test('migration is idempotent and leaves a layout that already has the second frames alone', () => {
        const variants = [
            oldTwoSided(),
            oldTwoSided((l) => { l.boxes.wk.hidden = true; l.boxes.month = { x: 40.3, y: 3, w: 250.4, h: 9 }; }),
            oldTwoSided((l) => { l.boxes.month = { x: 200, y: 14, w: 90, h: 12 }; l.styles.month.align = 'middle'; }),
        ];
        for (const old of variants) {
            const once = migrate(old);
            const twice = migrate(once);
            assert.deepEqual(twice, once);
            assert.deepEqual(migrate(twice), once);
        }
        // a current layout the user arranged asymmetrically is NOT re-mirrored
        const current = L.defaultLayout('twoSided', D.DEFAULT_PAGE, 5, ENV);
        current.boxes.month2 = { x: 150, y: 2, w: 60, h: 8 };
        current.boxes.wk2 = { x: 100, y: 100, w: 30, h: 5 };
        current.styles.wk.align = 'end';
        const kept = migrate(current);
        assert.deepEqual([kept.boxes.month2, kept.boxes.wk2, kept.styles.wk.align], [current.boxes.month2, current.boxes.wk2, 'end']);
        assert.deepEqual(kept.boxes.month, current.boxes.month);
        // only one of the two missing → only that one is derived
        const half = L.defaultLayout('twoSided', D.DEFAULT_PAGE, 5, ENV);
        half.boxes.month2 = { x: 150, y: 2, w: 60, h: 8 };
        delete half.boxes.wk2;
        half.boxes.wk = { x: 10, y: 130, w: 50, h: 5 };
        const h2 = migrate(half);
        assert.deepEqual(h2.boxes.month2, { x: 150, y: 2, w: 60, h: 8 });
        assert.deepEqual([h2.boxes.wk, h2.boxes.wk2], [{ x: 10, y: 130, w: 50, h: 5 }, { x: 250, y: 130, w: 50, h: 5 }]);
        // both missing altogether (a broken layout) → today's defaults, no derivation
        const none = L.defaultLayout('twoSided', D.DEFAULT_PAGE, 5, ENV);
        for (const k of ['month', 'month2', 'wk', 'wk2']) delete none.boxes[k];
        const d = L.defaultBoxes('twoSided', D.DEFAULT_PAGE, 0);
        const n2 = migrate(none);
        assert.deepEqual([n2.boxes.month, n2.boxes.month2, n2.boxes.wk, n2.boxes.wk2], [d.month, d.month2, d.wk, d.wk2]);
    });

    t.test('migration rules: a box across the centre line is cut to its own half; the stored box keeps its place and look', () => {
        const half = D.DEFAULT_PAGE.width / 2;
        const gap = D.K.SECOND_FRAME_GAP;
        // 1. a month frame across most of the page width (centre left of the line) → cut to the left half
        const wide = migrate(oldTwoSided((l) => { l.boxes.month = { x: 6, y: 12.5, w: 298, h: 14.5 }; }));
        assert.deepEqual(wide.boxes.month, { x: 6, y: 12.5, w: half - gap - 6, h: 14.5 });
        assert.deepEqual(wide.boxes.month2, { x: half + gap, y: 12.5, w: half - gap - 6, h: 14.5 });
        assert.equal(wide.styles.month.align, 'start');
        // the two never overlap and are mirror images
        assert.ok(wide.boxes.month.x + wide.boxes.month.w < wide.boxes.month2.x);
        // 2. across the line with its centre on the right → the right part stays and becomes the second frame
        const right = migrate(oldTwoSided((l) => { l.boxes.month = { x: 140, y: 12.5, w: 160, h: 14.5 }; l.styles.month.align = 'end'; }));
        assert.deepEqual(right.boxes.month2, { x: half + gap, y: 12.5, w: 300 - half - gap, h: 14.5 });
        assert.deepEqual(right.boxes.month, { x: 10, y: 12.5, w: 300 - half - gap, h: 14.5 });
        assert.equal(right.styles.month.align, 'start', 'flipped: the kept box is drawn end-aligned as the second frame');
        // 3. a box that does not reach the line is mirrored as it is; `middle` is never flipped
        const mid = migrate(oldTwoSided((l) => { l.boxes.wk = { x: 240.4, y: 120, w: 40.2, h: 6 }; l.styles.wk.align = 'middle'; }));
        assert.deepEqual(mid.boxes.wk2, { x: 240.4, y: 120, w: 40.2, h: 6 });
        assert.deepEqual(mid.boxes.wk, { x: 29.4, y: 120, w: 40.2, h: 6 });
        assert.equal(mid.styles.wk.align, 'middle');
        // 4. the eye stays on the first key, whichever frame the stored box became
        const hidden = migrate(oldTwoSided((l) => { l.boxes.wk.hidden = true; l.boxes.month.hidden = true; }));
        assert.deepEqual([hidden.boxes.wk.hidden, hidden.boxes.wk2.hidden, hidden.boxes.month.hidden, hidden.boxes.month2.hidden], [true, undefined, true, undefined]);
        assert.deepEqual(hidden.boxes.wk2, { x: 232, y: 139, w: 72, h: 4.5 });
        // 5. frames swapped by the user (first week on the right): the first frame is the one on cal's side
        const swapped = migrate(oldTwoSided((l) => { const c = l.boxes.cal; l.boxes.cal = l.boxes.cal2; l.boxes.cal2 = c; }));
        assert.deepEqual(swapped.boxes.wk, { x: 232, y: 139, w: 72, h: 4.5 });
        assert.deepEqual(swapped.boxes.wk2, { x: 6, y: 139, w: 72, h: 4.5 });
        assert.equal(swapped.styles.wk.align, 'end', 'the stored box stayed the first frame: nothing is flipped');
        assert.deepEqual(swapped.boxes.month2, { x: 6, y: 12.5, w: 120, h: 14.5 });
        assert.equal(swapped.styles.month.align, 'end');
        // 6. a narrow box across the line never gets narrower than the minimum
        const tiny = migrate(oldTwoSided((l) => { l.boxes.wk = { x: half - 3, y: 139, w: 8, h: 4.5 }; }));
        assert.ok(tiny.boxes.wk.w >= D.K.MIN_W && tiny.boxes.wk2.w >= D.K.MIN_W);
        // 7. another page size: the centre line is that page's
        const page = { ...copy(D.DEFAULT_PAGE), width: 420, height: 200 };
        const big = migrate(oldTwoSided((l) => { l.boxes.month = { x: 10, y: 15, w: 150, h: 20 }; }), page);
        assert.deepEqual(big.boxes.month2, { x: 260, y: 15, w: 150, h: 20 });
    });

    t.test('migration through normalizeDocument: a stored old calendar opens with both frames and renders per column', async () => {
        const { buildPages, weekPages } = await load('generate/pages.js');
        const { renderPage } = await load('render/page.js');
        const { createEstimatorMeasurer } = await load('render/measure.js');
        const stored = { id: 'old-1', revision: 7, name: 'Starý', year: 2027, type: 'fortnight', preset: 'twoSided', page: {}, print: {}, features: {}, layout: oldTwoSided(), content: {} };
        const doc = N.normalizeDocument(copy(stored), ENV);
        assert.deepEqual(Object.keys(doc.design.layout.boxes).sort(), ['cal', 'cal2', 'cap', 'head', 'logo', 'month', 'month2', 'photo', 'qr', 'wk', 'wk2']);
        assert.deepEqual(N.normalizeDocument(copy(doc), ENV), doc);
        const svg = renderPage(doc, weekPages(buildPages(doc))[0], { env: { assets: {}, fonts: [] }, measurer: createEstimatorMeasurer(), idPrefix: 'm' });
        assert.ok(svg.includes('>53. týden<') && svg.includes('>1. týden<') && svg.includes('>LEDEN 2027<'));
        assert.ok(/data-el="wk" data-frame="1"><text x="304"[^>]*text-anchor="end"/.test(svg), 'the old week number is where it was');
    });

    t.test('a 14-day layout file round-trips; an old file gets its second frames, also for another page size', async () => {
        const doc = await makeDoc(load, { type: 'fortnight', styles: (l) => {
            l.boxes.month2 = { x: 190, y: 10, w: 100, h: 12 };
            l.boxes.wk2 = { x: 250, y: 130, w: 40, h: 6 };
            l.boxes.wk.hidden = true;
            l.styles.wk.form = 'c';
        } });
        const file = N.exportLayoutFile(doc);
        assert.deepEqual(Object.keys(file.layout.boxes).sort(), ['cal', 'cal2', 'cap', 'head', 'logo', 'month', 'month2', 'photo', 'qr', 'wk', 'wk2']);
        const parsed = N.parseLayoutFile(JSON.stringify(file), doc.design);
        assert.equal(parsed.ok, true);
        assert.deepEqual(parsed.layout, doc.design.layout);
        // a file written before the change
        const plain = await makeDoc(load, { type: 'fortnight' });
        const oldFile = { ...N.exportLayoutFile(plain), layout: oldTwoSided() };
        const fromOld = N.parseLayoutFile(JSON.stringify(oldFile), plain.design);
        assert.equal(fromOld.ok, true);
        assert.deepEqual(fromOld.layout.boxes, plain.design.layout.boxes);
        assert.equal(fromOld.layout.styles.wk.align, 'start');
        // … loaded into a calendar with another page size: mirrored on the SOURCE page, then scaled
        const bigPage = { ...copy(D.DEFAULT_PAGE), width: 420, height: 200 };
        const big = await makeDoc(load, { type: 'fortnight', page: bigPage, print: { bleed: true, bleedMm: 3, marks: true } });
        const scaled = N.parseLayoutFile(JSON.stringify(oldFile), big.design);
        assert.equal(scaled.ok, true);
        const e = scaled.layout.boxes;
        const fx = 420 / D.DEFAULT_PAGE.width;
        assert.ok(Math.abs(e.month2.x + e.month2.w - 304 * fx) <= 0.11 && Math.abs(e.month2.x - 184 * fx) <= 0.11, JSON.stringify(e.month2));
        assert.ok(Math.abs(e.wk.x - 6 * fx) <= 0.11 && Math.abs(e.wk2.x + e.wk2.w - 304 * fx) <= 0.11);
        assert.ok(Math.abs((e.month.x + e.month.w) - (420 - e.month2.x)) <= 0.11, 'still mirror images');
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
