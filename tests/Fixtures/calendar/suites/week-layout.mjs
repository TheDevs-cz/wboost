// Kalendárium geometry (architecture §E.6): rows, compact mode, mirrored second frame, holiday wrap,
// event box, icon grid.
import assert from 'node:assert/strict';
import { copy, layoutOpts, makeDoc, near } from '../helpers.mjs';

export default async function (t, load) {
    const { layoutWeekPage, monthLabel } = await load('render/week-layout.js');
    const { buildPages, weekPages } = await load('generate/pages.js');
    const { K, ptToMm } = await load('model/defaults.js');
    const { wrapText, createEstimatorMeasurer, usedFamilies } = await load('render/measure.js');
    const P = await load('editor/parts.js');
    const opts = await layoutOpts(load);
    const measurer = createEstimatorMeasurer();
    const NUMBERS = ['num', 'numSat', 'numSun', 'numHol'];

    const pageWith = (doc, date) => weekPages(buildPages(doc)).find((p) => p.week.days.includes(date));
    const geo = (doc, date = '2027-01-01') => layoutWeekPage(doc, pageWith(doc, date), opts);
    const rowOf = (g, date) => g.frames.flatMap((f) => f.rows).find((r) => r.date === date);
    const frameOf = (g, date) => g.frames.find((f) => f.rows.some((r) => r.date === date));
    const itemsOf = (row, part) => row.items.filter((i) => i.part === part);
    const partRect = (row, key) => (row.parts.find((p) => p.key === key) || {}).rect;
    const insideX = (rect, col, message) => assert.ok(rect.x >= col.x - 0.01 && rect.x + rect.w <= col.x + col.w + 0.01, `${message}: ${JSON.stringify(rect)} not in ${JSON.stringify(col)}`);
    const kind = (o = {}) => ({ builtin: 'bio', name: 'Bio odpad', on: true, place: '', mode: 'rule', weekdays: [0, 1, 2, 3, 4, 5, 6], interval: 'w', from: '2026-12-01', to: '2028-01-31', dates: [], pos: 0, ...o });
    const wasteContent = (n) => ({ wasteKinds: Object.fromEntries(['kom', 'bio', 'plast', 'papir', 'sklo'].slice(0, n).map((id, pos) => [id, kind({ builtin: id, name: id, pos })])) });

    t.test('default 7-day rows: seven equal rows inside the trim, not compact', async () => {
        const doc = await makeDoc(load);
        const g = geo(doc);
        assert.equal(g.frames.length, 1);
        const f = g.frames[0];
        assert.deepEqual([f.index, f.boxKey, f.mirrored, f.zoneLeft, f.compact], [0, 'cal', false, true, false]);
        // the box reaches 5 mm into the bleed; rows are laid out only inside the trim
        assert.deepEqual(f.inner, { x: 197, y: 27, w: 113, h: 112 });
        near(assert, f.rowHeight, (112 - K.ROW_SLACK) / 7, 'row height', 1e-9);
        assert.ok(f.rowHeight > K.COMPACT_BELOW);
        assert.equal(f.rows.length, 7);
        f.rows.forEach((row, i) => {
            near(assert, row.y, 27 + i * f.rowHeight, `row ${i}`, 1e-9);
            near(assert, row.h, f.rowHeight, 'h', 1e-9);
        });
        assert.deepEqual(f.rows.map((r) => r.date), pageWith(doc, '2027-01-01').week.days);
        assert.deepEqual(Object.keys(g.boxes).sort(), ['cal', 'cap', 'head', 'logo', 'month', 'photo', 'wk']);
        assert.deepEqual(g.boxes.cal, { x: 197, y: 27, w: 118, h: 112 });
        assert.ok(g.textWidths.month > 50 && g.textWidths.month < 101);
        assert.ok(g.textWidths.wk > 8 && g.textWidths.wk < 30);
    });

    t.test('Fotka vlevo: numbers on the right, text right-aligned towards them, icon zone on the left', async () => {
        const doc = await makeDoc(load);
        const g = geo(doc, '2027-01-22'); // full moon on Friday 22 January
        const f = g.frames[0];
        const row = rowOf(g, '2027-01-22');
        const numItem = itemsOf(row, 'num')[0];
        assert.equal(numItem.text, '22');
        assert.equal(numItem.anchor, 'end');
        near(assert, numItem.x, f.inner.x + f.inner.w - K.ROW_PAD, 'number at the right pad', 1e-9);
        near(assert, numItem.font.sizeMm, ptToMm(28), 'number size', 1e-9);
        const day = itemsOf(row, 'day')[0];
        const name = itemsOf(row, 'name')[0];
        assert.deepEqual([day.text, name.text, day.anchor, name.anchor], ['Pátek', 'Slavomír', 'end', 'end']);
        near(assert, day.x, f.textColumn.x + f.textColumn.w, 'text column right edge', 1e-9);
        assert.ok(f.textColumn.x + f.textColumn.w < partRect(row, 'num').x, 'text column ends before the number');
        assert.ok(day.y < name.y);
        const moon = itemsOf(row, 'moon')[0];
        assert.equal(moon.ref.phase, 2);
        assert.ok(moon.box.x >= f.inner.x + K.ROW_PAD - 0.01 && moon.box.x + moon.box.w < f.textColumn.x, 'moon in the zone');
        near(assert, moon.box.w, 5, 'moon size', 1e-9);
        assert.deepEqual(f.iconZone, { x: f.inner.x + K.ROW_PAD, w: 4 * 7.2, cols: 4, cellW: 7.2 });
        assert.equal(row.iconCells.length, 4);
        near(assert, f.textColumn.x, f.inner.x + K.ROW_PAD + 4 * 7.2 + K.ZONE_END_GAP, 'text column after the zone', 1e-9);
        // every row keeps the same text column, with or without icons
        for (const r of f.rows) for (const key of ['day', 'name']) insideX(partRect(r, key), f.textColumn, `${r.date} ${key}`);
        // parts in paint order, numbers last
        assert.deepEqual(row.parts.map((p) => p.key), ['line', 'moon', 'day', 'name', 'num']);
    });

    t.test('Fotka vpravo mirrors the row: numbers left, text left-aligned, zone on the right', async () => {
        const left = geo(await makeDoc(load), '2027-01-22');
        const right = geo(await makeDoc(load, { preset: 'photoRight' }), '2027-01-22');
        const fl = left.frames[0];
        const fr = right.frames[0];
        assert.equal(fr.zoneLeft, false);
        assert.equal(fr.mirrored, false);
        const W = 310;
        near(assert, fr.textColumn.x, W - (fl.textColumn.x + fl.textColumn.w), 'text column mirrored', 1e-6);
        near(assert, fr.iconZone.x, W - (fl.iconZone.x + fl.iconZone.w), 'zone mirrored', 1e-6);
        const a = rowOf(left, '2027-01-22');
        const b = rowOf(right, '2027-01-22');
        for (const part of ['num', 'day', 'name', 'moon', 'line']) {
            const ra = partRect(a, part);
            const rb = partRect(b, part);
            near(assert, rb.x, W - (ra.x + ra.w), `${part} x`, 1e-6);
            near(assert, rb.y, ra.y, `${part} y`, 1e-6);
        }
        assert.equal(itemsOf(b, 'num')[0].anchor, 'start');
        assert.equal(itemsOf(b, 'day')[0].anchor, 'start');
    });

    t.test('14 dní: two frames of seven rows, not compact; frame 2 mirrors frame 1', async () => {
        const doc = await makeDoc(load, { type: 'fortnight' });
        const page = pageWith(doc, '2027-01-01');
        const g = layoutWeekPage(doc, page, opts);
        assert.equal(g.frames.length, 2);
        const [f1, f2] = g.frames;
        assert.deepEqual([f1.boxKey, f2.boxKey, f1.mirrored, f2.mirrored], ['cal', 'cal2', false, true]);
        assert.deepEqual(f1.rows.map((r) => r.date), page.week.frames[0]);
        assert.deepEqual(f2.rows.map((r) => r.date), page.week.frames[1]);
        assert.deepEqual(f1.inner, { x: 0, y: 27, w: 84, h: 112 });
        assert.deepEqual(f2.inner, { x: 226, y: 27, w: 84, h: 112 });
        assert.equal(f1.compact, false);
        near(assert, f1.rowHeight, f2.rowHeight, 'same rows', 1e-9);
        near(assert, f1.rowHeight, 110 / 7, 'row height as in the 7-day layouts', 1e-9);
        // day numbers towards the outer page edges
        assert.equal(f1.zoneLeft, false);
        assert.equal(f2.zoneLeft, true);
        const W = doc.design.page.width;
        near(assert, f2.textColumn.x, W - (f1.textColumn.x + f1.textColumn.w), 'text columns mirror', 1e-6);
        near(assert, f2.numberColumn.x, W - (f1.numberColumn.x + f1.numberColumn.w), 'number columns mirror', 1e-6);
        near(assert, f2.holWrap.x, W - (f1.holWrap.x + f1.holWrap.w), 'wrap spans mirror', 1e-6);
        const n1 = itemsOf(f1.rows[0], 'num')[0];
        const n2 = itemsOf(f2.rows[0], 'num')[0];
        assert.deepEqual([n1.anchor, n2.anchor], ['start', 'end']);
        near(assert, n1.x, K.ROW_PAD, 'frame 1 number at the left edge', 1e-9);
        near(assert, n2.x, W - K.ROW_PAD, 'frame 2 number at the right edge', 1e-9);
        assert.deepEqual([itemsOf(f1.rows[0], 'day')[0].anchor, itemsOf(f2.rows[0], 'day')[0].anchor], ['start', 'end']);
        // lines mirror too
        const l1 = partRect(f1.rows[3], 'line');
        const l2 = partRect(f2.rows[3], 'line');
        near(assert, l2.x, W - (l1.x + l1.w), 'line', 1e-6);
        // stored styles are untouched by rendering the mirrored frame
        assert.equal(doc.design.layout.styles.cal.numberSide, 'start');
        assert.equal(f2.styles.cal.numberSide, 'end');
    });

    t.test('part offsets shift a part in every row; mirrored in the second frame; icons share the Ikony offset', async () => {
        const base = await makeDoc(load, { type: 'fortnight' });
        const moved = await makeDoc(load, { type: 'fortnight', styles: (l) => { l.styles.offsets = { name: { x: 3, y: 1 }, icon: { x: -1, y: 0.5 }, numSat: { x: 0, y: 2 } }; } });
        const a = layoutWeekPage(base, pageWith(base, '2027-01-07'), opts);
        const b = layoutWeekPage(moved, pageWith(moved, '2027-01-07'), opts);
        for (const f of [0, 1]) {
            const sign = f === 0 ? 1 : -1;
            a.frames[f].rows.forEach((row, i) => {
                const other = b.frames[f].rows[i];
                near(assert, other.iconCells[0].x - row.iconCells[0].x, -1 * sign, 'cells follow the icon offset', 1e-6);
                if (!partRect(row, 'name')) return; // 1 January has no name day
                near(assert, partRect(other, 'name').x - partRect(row, 'name').x, 3 * sign, `frame ${f} name x`, 1e-6);
                near(assert, partRect(other, 'name').y - partRect(row, 'name').y, 1, 'name y', 1e-6);
                near(assert, partRect(other, 'day').x, partRect(row, 'day').x, 'day stays', 1e-9);
            });
        }
        const sat = (g) => partRect(rowOf(g, '2027-01-02'), 'numSat');
        near(assert, sat(b).y - sat(a).y, 2, 'Saturday number has its own offset', 1e-6);
        near(assert, partRect(rowOf(b, '2027-01-04'), 'num').y, partRect(rowOf(a, '2027-01-04'), 'num').y, 'workday number stays', 1e-9);
        // moon (new moon 7 January 2027) falls back to the Ikony offset
        const moonA = partRect(rowOf(a, '2027-01-07'), 'moon');
        const moonB = partRect(rowOf(b, '2027-01-07'), 'moon');
        near(assert, moonB.x - moonA.x, 1, 'moon in the mirrored frame', 1e-6);
        near(assert, moonB.y - moonA.y, 0.5, 'moon y', 1e-6);
    });

    t.test('compact rows under the threshold: one baseline, smaller type, no event box', async () => {
        const doc = await makeDoc(load, { features: { events: true }, styles: (l) => { l.boxes.cal.h = 70; }, content: { events: { e1: { date: '2026-12-29', name: 'Koncert', club: null, icon: null } } } });
        const g = geo(doc);
        const f = g.frames[0];
        near(assert, f.rowHeight, 68 / 7, 'row height', 1e-9);
        assert.equal(f.compact, true);
        const row = rowOf(g, '2026-12-29');
        const day = itemsOf(row, 'day')[0];
        const name = itemsOf(row, 'name')[0];
        const ev = itemsOf(row, 'ev')[0];
        near(assert, day.y, name.y, 'weekday and name share the baseline', 1e-9);
        assert.ok(name.x < day.x && ev.x < name.x, 'flowing from the number outwards');
        assert.equal(ev.text, 'Koncert');
        assert.equal(row.eventBox, null);
        assert.equal(row.eventOverflow, false);
        near(assert, day.font.sizeMm, ptToMm(11), 'no scaling above the base height', 1e-9);
        const num = itemsOf(row, 'num')[0];
        near(assert, num.font.sizeMm, f.rowHeight * 0.78, 'numbers are capped to the row', 1e-9);
        assert.deepEqual(row.iconCells, []);
        // even shorter rows scale the type
        const tiny = await makeDoc(load, { styles: (l) => { l.boxes.cal.h = 44; } });
        const ft = geo(tiny).frames[0];
        near(assert, itemsOf(ft.rows[0], 'day')[0].font.sizeMm, ptToMm(11) * (ft.rowHeight / K.COMPACT_BASE), 'scaled', 1e-9);
        // exactly at the threshold the row is normal
        const edge = await makeDoc(load, { styles: (l) => { l.boxes.cal.h = Math.ceil((K.COMPACT_BELOW * 7 + K.ROW_SLACK) * 10) / 10; } });
        assert.equal(geo(edge).frames[0].compact, false);
    });

    t.test('a long holiday name wraps and is never cut — 17 November in an 84 mm frame', async () => {
        const HOLIDAY = 'Den boje za svobodu a demokracii';
        const doc = await makeDoc(load, { type: 'fortnight' });
        const g = geo(doc, '2027-11-17');
        const f = frameOf(g, '2027-11-17');
        assert.equal(f.inner.w, 84);
        const row = rowOf(g, '2027-11-17');
        const lines = itemsOf(row, 'hol');
        assert.ok(lines.length >= 2, `wrapped into ${lines.length} lines`);
        assert.equal(lines.map((l) => l.text).join(' '), HOLIDAY);
        for (const line of lines) {
            assert.ok(!line.text.includes('…'));
            insideX(line.rect, f.textColumn, 'holiday line');
            assert.ok(line.rect.y >= row.y && line.rect.y + line.rect.h <= row.y + row.h + 0.01, 'inside its row');
        }
        assert.equal(itemsOf(row, 'numHol')[0].text, '17');
        assert.equal(itemsOf(row, 'name').length, 0, 'no name day on 17 November');
        // the same in every layout, and on 1 January (the longest name)
        for (const o of [{}, { preset: 'photoRight' }, { type: 'fortnight' }]) {
            const d = await makeDoc(load, o);
            for (const [date, name] of [['2027-11-17', HOLIDAY], ['2027-01-01', 'Nový rok / Den obnovy samostatného českého státu'], ['2027-07-05', 'Den slovanských věrozvěstů Cyrila a Metoděje'], ['2027-10-28', 'Den vzniku samostatného československého státu']]) {
                const gg = geo(d, date);
                const r = rowOf(gg, date);
                const fr = frameOf(gg, date);
                assert.equal(itemsOf(r, 'hol').map((l) => l.text).join(' '), name, `${date}`);
                for (const item of r.items.filter((i) => i.type === 'text' && i.part !== 'numHol')) {
                    insideX(item.rect, fr.textColumn, `${date} ${item.part}`);
                    assert.ok(item.rect.y >= r.y - 0.01 && item.rect.y + item.rect.h <= r.y + r.h + 0.01, `${date} ${item.part} leaves its row`);
                }
            }
        }
    });

    t.test('compact rows wrap the holiday too instead of cutting it', async () => {
        const doc = await makeDoc(load, { type: 'fortnight', styles: (l) => { l.boxes.cal.h = 70; l.boxes.cal2.h = 70; } });
        const g = geo(doc, '2027-11-17');
        const f = frameOf(g, '2027-11-17');
        assert.equal(f.compact, true);
        const lines = itemsOf(rowOf(g, '2027-11-17'), 'hol');
        assert.equal(lines.map((l) => l.text).join(' '), 'Den boje za svobodu a demokracii');
        assert.ok(lines.length >= 2);
    });

    t.test('a compact row too narrow for everything keeps the text beside the number and inside the row height', async () => {
        const { defaultOperation } = await load('generate/shifts.js');
        const kinds = Object.fromEntries(['kom', 'bio', 'plast', 'papir'].map((id, pos) => [id, kind({ builtin: id, name: id, pos, weekdays: pos < 2 ? [0] : [], mode: pos < 2 ? 'rule' : 'dates' })]));
        const content = { wasteKinds: kinds, shiftOps: { a: defaultOperation(1), b: defaultOperation(2) }, events: { e1: { date: '2027-11-18', name: 'Akce', club: null, icon: null } } };
        const doc = await makeDoc(load, {
            type: 'fortnight',
            features: { waste: true, moon: true, events: true, shifts: true },
            content,
            styles: (l) => { l.boxes.cal.h = 72; l.boxes.cal2.h = 72; l.styles.icons.order = ['waste', 'moon', 'ev']; },
        });
        const g = geo(doc, '2027-11-17');
        for (const f of g.frames) {
            assert.equal(f.compact, true);
            // two icons on the fullest day: the zone shrinks from six slots to those two
            assert.equal(f.iconZone.w, 2 * f.iconZone.cellW);
            assert.ok(f.textColumn.w > 20, `the text column keeps its room: ${f.textColumn.w}`);
            assert.equal(f.iconZone.cols, 6, 'four waste kinds, moon and events are active');
            assert.ok(f.iconZone.w < 6 * f.iconZone.cellW, 'the reserved slots give way');
            for (const row of f.rows) {
                const num = row.parts.find((p) => NUMBERS.includes(p.key)).rect;
                const day = partRect(row, 'day');
                assert.ok(day.x + day.w <= num.x + 0.01 || day.x >= num.x + num.w - 0.01, `${row.date}: weekday and number do not overlap`);
                assert.equal(row.iconsDrawn, row.iconsTotal, 'compact rows drop no icon');
                for (const item of row.items.filter((i) => i.type === 'icon')) assert.ok(item.box.x >= f.inner.x && item.box.x + item.box.w <= f.inner.x + f.inner.w, 'icons stay in the frame');
            }
        }
        const row = rowOf(g, '2027-11-17');
        const lines = itemsOf(row, 'hol');
        assert.equal(lines.map((l) => l.text).join(' '), 'Den boje za svobodu a demokracii');
        assert.ok(lines.length <= 2, 'as many lines as the row height holds');
        for (const line of lines) assert.ok(line.rect.y >= row.y - 0.01 && line.rect.y + line.rect.h <= row.y + row.h + 0.01, 'the holiday stays inside its row');
    });

    t.test('the holiday wrap width is a style and wraps earlier', async () => {
        const narrow = await makeDoc(load, { styles: (l) => { l.styles.hol.wrapWidth = 22; } });
        const wide = await makeDoc(load);
        const a = geo(narrow, '2027-11-17');
        const b = geo(wide, '2027-11-17');
        assert.ok(itemsOf(rowOf(a, '2027-11-17'), 'hol').length > itemsOf(rowOf(b, '2027-11-17'), 'hol').length);
        assert.equal(a.frames[0].holWrap.w, 22);
        near(assert, a.frames[0].holWrap.x + 22, a.frames[0].textColumn.x + a.frames[0].textColumn.w, 'anchored like the text', 1e-9);
        near(assert, b.frames[0].holWrap.w, b.frames[0].textColumn.w, 'default = text column', 1e-9);
        // wider than the column is capped
        const over = await makeDoc(load, { styles: (l) => { l.styles.hol.wrapWidth = 500; } });
        near(assert, geo(over).frames[0].holWrap.w, b.frames[0].textColumn.w, 'capped', 1e-9);
    });

    t.test('default event box: the free row area beside the day text, whole row height, no overlap', async () => {
        const LONG = 'Velmi dlouhý název obecní akce, který se do pole pro akce rozhodně nevejde, protože pokračuje dál a dál a ještě dál, až za okraj řádku a ještě o kus dál';
        const content = { events: {
            e1: { date: '2026-12-29', name: 'Koncert', club: null, icon: null },
            e2: { date: '2026-12-30', name: LONG, club: null, icon: null },
            e3: { date: '2027-01-02', name: 'Novoroční turnaj ve stolním tenise', club: null, icon: null },
        } };
        const overlap = (a, b) => a.x < b.x + b.w - 0.01 && b.x < a.x + a.w - 0.01 && a.y < b.y + b.h - 0.01 && b.y < a.y + a.h - 0.01;
        for (const o of [{}, { preset: 'photoRight' }, { type: 'fortnight' }, { styles: (l) => { l.styles.cal.numberSide = 'middle'; } }, { styles: (l) => { l.styles.cal.numberSide = 'start'; } }, { styles: (l) => { l.styles.cal.dayPosition = 'above'; } }, { features: { events: true, moon: false }, styles: (l) => { l.styles.cal.emptyIconSlot = false; } }]) {
            const doc = await makeDoc(load, { features: { events: true }, content, ...o });
            const g = geo(doc);
            const label = JSON.stringify(o.preset || o.type || (o.styles ? String(o.styles) : 'photoLeft'));
            const first = g.frames[0].rows[0].eventBox;
            for (const f of g.frames) {
                for (const row of f.rows) {
                    const box = row.eventBox;
                    assert.ok(box, `${label} ${row.date} has a box`);
                    near(assert, box.y, row.y + K.EVBOX_PAD_Y, 'whole row minus the padding', 1e-9);
                    near(assert, box.h, row.h - 2 * K.EVBOX_PAD_Y, 'height', 1e-9);
                    // one page-wide box; only a row whose holiday / time text is wider than every name line gets a narrower one
                    if (row.info.holiday) assert.ok(box.w <= f.evDefault.w + 1e-6, `${label} ${row.date}: a holiday row is never wider`);
                    else near(assert, box.w, f.evDefault.w, 'the same width in every ordinary row and frame', 1e-6);
                    near(assert, f.evDefault.w, g.frames[0].evDefault.w, 'the same page-wide box in both frames', 1e-6);
                    assert.ok(box.x >= f.textColumn.x - 0.01 && box.x + box.w <= f.textColumn.x + f.textColumn.w + 0.01, `${label}: inside the text column`);
                    // a page whose day text leaves less than the minimum box width cannot avoid touching it
                    if (box.w <= K.EVBOX_MIN_W + 0.01) continue;
                    for (const item of row.items) {
                        if (item.part === 'ev' || item.part === 'line' || (item.part === 'shift' && item.type === 'rect')) continue;
                        assert.ok(!overlap(box, item.rect), `${label} ${row.date}: the box overlaps ${item.part} "${item.text || item.type}"`);
                    }
                    // the page-wide box is clear of the weekday and name lines of every row
                    const wide = { ...box, w: f.evDefault.w, x: f.zoneLeft === (doc.design.layout.styles.cal.numberSide !== 'middle') ? box.x : box.x + box.w - f.evDefault.w };
                    for (const r of f.rows) {
                        for (const item of r.items) {
                            if (item.part !== 'day' && item.part !== 'name') continue;
                            assert.ok(!overlap({ ...wide, y: r.y + K.EVBOX_PAD_Y }, item.rect), `${label} ${r.date}: the page box overlaps ${item.part} "${item.text}"`);
                        }
                    }
                }
                if (f.iconZone) assert.ok(first.x >= 0 && (f.rows[0].eventBox.x >= f.iconZone.x + f.iconZone.w - 0.01 || f.rows[0].eventBox.x + f.rows[0].eventBox.w <= f.iconZone.x + 0.01), `${label}: clear of the icon zone`);
            }
            // aligned away from the number side
            const f0 = frameOf(g, '2026-12-29');
            const ev = itemsOf(rowOf(g, '2026-12-29'), 'ev')[0];
            assert.equal(ev.anchor, f0.textAnchor === 'end' ? 'start' : 'end', `${label}: away from the number`);
        }

        const doc = await makeDoc(load, { features: { events: true }, content });
        const g = geo(doc);
        const f = g.frames[0];
        const short = rowOf(g, '2026-12-29');
        near(assert, short.eventBox.x, f.textColumn.x, 'starts at the zone side of the text column', 1e-9);
        near(assert, short.eventBox.w, f.textColumn.w - f.namesWidest - K.EVBOX_GAP, 'ends a gap before the widest weekday / name line of the page', 1e-9);
        // the long holiday of 1 January narrows only its own row
        const nyRow = rowOf(g, '2027-01-01');
        assert.ok(f.textWidest > f.namesWidest + 5, 'the holiday is the widest text of the page');
        assert.ok(nyRow.eventBox.w < short.eventBox.w - 5, `holiday row ${nyRow.eventBox.w} < ${short.eventBox.w}`);
        const nyHol = itemsOf(nyRow, 'hol');
        for (const item of nyHol) assert.ok(item.rect.x >= nyRow.eventBox.x + nyRow.eventBox.w + K.EVBOX_GAP - 0.01 || nyRow.eventBox.w <= K.EVBOX_MIN_W + 0.01, 'the holiday row box ends a gap before the holiday text');
        assert.ok(short.eventBox.w > 20, `room for events: ${short.eventBox.w}`);
        assert.equal(short.eventOverflow, false);
        assert.deepEqual(itemsOf(short, 'ev').map((i) => i.text), ['Koncert']);
        // a two-line event fits the default 7-day row without a warning
        const two = rowOf(g, '2027-01-02');
        const twoLines = itemsOf(two, 'ev');
        assert.equal(twoLines.length, 2, twoLines.map((l) => l.text).join(' | '));
        assert.equal(two.eventOverflow, false);
        for (const line of twoLines) assert.ok(line.rect.y >= two.y && line.rect.y + line.rect.h <= two.y + two.h, 'inside its row');
        // a very long one warns, and starts at the top of the box instead of printing over the row above
        const long = rowOf(g, '2026-12-30');
        assert.equal(long.eventOverflow, true);
        const lines = itemsOf(long, 'ev');
        assert.equal(lines.map((l) => l.text).join(' '), LONG);
        for (const line of lines) insideX(line.rect, { x: long.eventBox.x, w: long.eventBox.w }, 'event line');
        assert.ok(lines[0].rect.y >= long.y, 'never above its own row');
        assert.equal(rowOf(g, '2026-12-28').eventOverflow, false, 'a day without events never overflows');
        // the long holiday of 1 January keeps to its side and stays in its row
        const ny = rowOf(g, '2027-01-01');
        for (const item of itemsOf(ny, 'hol')) assert.ok(item.rect.y >= ny.y && item.rect.y + item.rect.h <= ny.y + ny.h + 0.01, 'holiday inside its row');
        // event text never moves the weekday or the number
        const plain = geo(await makeDoc(load));
        for (const part of ['day', 'num']) assert.deepEqual(partRect(long, part), partRect(rowOf(plain, '2026-12-30'), part));
        assert.equal(rowOf(plain, '2026-12-30').eventBox, null, 'no box while events are off');
    });

    t.test('default event box: four events stack as lines; 14-day frames mirror it', async () => {
        const events = {};
        for (let i = 0; i < 4; i++) events[`e${i}`] = { date: '2027-01-05', name: `Akce ${i + 1}`, club: null, icon: null };
        events.left = { date: '2026-12-29', name: 'Koncert', club: null, icon: null };
        const week = await makeDoc(load, { features: { events: true }, content: { events } });
        const row = rowOf(geo(week, '2027-01-05'), '2027-01-05');
        assert.deepEqual(itemsOf(row, 'ev').map((i) => i.text), ['Akce 1', 'Akce 2', 'Akce 3', 'Akce 4']);
        assert.equal(row.eventOverflow, false);
        const fort = await makeDoc(load, { type: 'fortnight', features: { events: true }, content: { events } });
        const g = geo(fort, '2027-01-05');
        const [f1, f2] = g.frames;
        const W = fort.design.page.width;
        const b1 = f1.rows[1].eventBox;
        const b2 = f2.rows[1].eventBox;
        near(assert, b2.x, W - (b1.x + b1.w), 'mirrored box', 1e-6);
        near(assert, b2.w, b1.w, 'same width', 1e-6);
        assert.deepEqual([itemsOf(f1.rows[1], 'ev')[0].anchor, itemsOf(f2.rows[1], 'ev')[0].anchor], ['end', 'start']);
    });

    t.test('default event box on a 14-day page: legible beside the names although the page holds a long holiday', async () => {
        const events = { a: { date: '2026-12-29', name: 'Zasedání zastupitelstva', club: null, icon: null }, b: { date: '2027-01-06', name: 'Tříkrálová sbírka', club: null, icon: null } };
        for (const features of [{ events: true }, { events: true, waste: true }]) {
            const doc = await makeDoc(load, { type: 'fortnight', features, content: { events } });
            const g = geo(doc);
            const [f1, f2] = g.frames;
            assert.ok(f1.rows.some((r) => r.info.holiday), 'the sample page holds 1 January');
            for (const f of g.frames) {
                for (const row of f.rows) {
                    if (row.info.holiday) continue;
                    assert.ok(row.eventBox.w >= 18, `${row.date}: an ordinary row keeps a legible box, got ${row.eventBox.w.toFixed(1)} mm of a ${f.textColumn.w.toFixed(1)} mm column`);
                }
            }
            assert.equal(rowOf(g, '2026-12-29').eventOverflow, false);
            assert.equal(rowOf(g, '2027-01-06').eventOverflow, false);
            near(assert, f2.rows[2].eventBox.x, doc.design.page.width - (f1.rows[1].eventBox.x + f1.rows[1].eventBox.w), 'mirrored', 1e-6);
        }
    });

    t.test('the event box follows styles.ev.box (flow-anchored) and its offset', async () => {
        const content = { events: { e2: { date: '2026-12-30', name: 'Velmi dlouhý název obecní akce, který se do jednoho řádku pole pro akce rozhodně nevejde', club: null, icon: null } } };
        const box = { x: 4, y: 1, w: 30, h: 13 };
        const doc = await makeDoc(load, { features: { events: true }, content, styles: (l) => { l.styles.ev.box = box; l.styles.offsets = { ev: { x: -2, y: 0.5 } }; } });
        const g = geo(doc);
        const f = g.frames[0];
        const row = rowOf(g, '2026-12-30');
        near(assert, row.eventBox.x, f.textColumn.x + 4 - 2, 'x from the zone-side edge + offset', 1e-9);
        near(assert, row.eventBox.y, row.y + 1 + 0.5, 'y', 1e-9);
        assert.deepEqual([row.eventBox.w, row.eventBox.h], [30, 13]);
        const lines = itemsOf(row, 'ev');
        for (const line of lines) assert.ok(measurer.width(line.text, line.font) <= 30 + 0.01 || !line.text.includes(' '), `line fits: ${line.text}`);
        assert.equal(row.eventOverflow, lines.length * 1.2 * lines[0].font.sizeMm > 13.15);
        // a narrower box wraps into more lines
        const narrow = await makeDoc(load, { features: { events: true }, content, styles: (l) => { l.styles.ev.box = { ...box, w: 18 }; } });
        assert.ok(itemsOf(rowOf(geo(narrow), '2026-12-30'), 'ev').length > lines.length);
        // mirrored layout: the same stored box is measured from the other side
        const right = await makeDoc(load, { preset: 'photoRight', features: { events: true }, content, styles: (l) => { l.styles.ev.box = box; } });
        const gr = geo(right);
        const fr = gr.frames[0];
        const rr = rowOf(gr, '2026-12-30');
        near(assert, rr.eventBox.x + rr.eventBox.w, fr.textColumn.x + fr.textColumn.w - 4, 'flow-anchored', 1e-9);
        // defaultEventBox materialises the drawn box as a stored one
        assert.deepEqual(P.defaultEventBox(f, row), box);
        assert.deepEqual(P.defaultEventBox(fr, rr), box);
        const def = await makeDoc(load, { features: { events: true }, content });
        const gd = geo(def);
        const stored = P.defaultEventBox(gd.frames[0], gd.frames[0].rows[0]);
        assert.equal(stored.x, 0);
        near(assert, stored.w, gd.frames[0].textColumn.w - gd.frames[0].namesWidest - K.EVBOX_GAP, 'w', 0.051);
        // … also when the designer grabs the box of the holiday row: the stored box is the page-wide one
        assert.deepEqual(P.defaultEventBox(gd.frames[0], rowOf(gd, '2027-01-01')), { ...stored });
        assert.deepEqual([stored.y, Math.round((stored.h + 2) * 10) / 10], [K.EVBOX_PAD_Y, Math.round(gd.frames[0].rowHeight * 10) / 10]);
    });

    t.test('more than four events on a day: four are laid out, the overflow is flagged', async () => {
        const events = {};
        for (let i = 0; i < 6; i++) events[`e${i}`] = { date: '2026-12-29', name: `A${i}`, club: null, icon: null };
        const doc = await makeDoc(load, { features: { events: true }, content: { events }, styles: (l) => { l.styles.ev.box = { x: 0, y: 0, w: 60, h: 15 }; } });
        const row = rowOf(geo(doc), '2026-12-29');
        assert.equal(itemsOf(row, 'ev').length, 4);
        assert.equal(row.eventOverflow, true);
    });

    t.test('icon grid: icons fill the cells in order; icons beyond the cell count are not drawn', async () => {
        const doc = await makeDoc(load, { features: { waste: true, moon: true }, content: wasteContent(5) });
        const g = geo(doc, '2027-01-22');
        const row = rowOf(g, '2027-01-22'); // five waste kinds + the full moon
        assert.equal(row.iconsTotal, 6);
        assert.equal(row.iconsDrawn, 4);
        const icons = row.items.filter((i) => i.type === 'icon');
        assert.deepEqual(icons.map((i) => i.ref.kindId), ['kom', 'bio', 'plast', 'papir']);
        icons.forEach((icon, i) => {
            const cell = row.iconCells[i];
            assert.ok(icon.box.x >= cell.x && icon.box.x + icon.box.w <= cell.x + cell.w + 1e-9 && icon.box.y >= cell.y && icon.box.y + icon.box.h <= cell.y + cell.h + 1e-9, `icon ${i} in its cell`);
            near(assert, icon.box.w, 6, 'default icon size', 1e-9);
            near(assert, icon.box.h, 6 * 1.15, 'waste icons are taller', 1e-9);
        });
        assert.ok(row.iconCells[0].x < row.iconCells[1].x, 'zone edge first');
        // 2 + 2 grid, three kinds: no overflow; grids of three cells
        for (const [grid, cells, drawn] of [['2x2', 4, 4], ['2+1', 3, 3], ['1+2', 3, 3]]) {
            const d = await makeDoc(load, { features: { waste: true, moon: true }, content: wasteContent(5), styles: (l) => { l.styles.cal.iconGrid = grid; } });
            const r = rowOf(geo(d, '2027-01-22'), '2027-01-22');
            assert.equal(r.iconCells.length, cells, grid);
            assert.equal(r.iconsDrawn, drawn, grid);
            assert.equal(geo(d, '2027-01-22').frames[0].iconZone.cols, 2);
        }
        const fits = await makeDoc(load, { features: { waste: true, moon: true }, content: wasteContent(3) });
        const ok = rowOf(geo(fits, '2027-01-22'), '2027-01-22');
        assert.deepEqual([ok.iconsDrawn, ok.iconsTotal], [4, 4]);
    });

    t.test('icon order, per-cell size and alignment, cell width', async () => {
        const styles = (l) => {
            l.styles.icons.order = ['moon', 'waste'];
            l.styles.cal.iconCellW = 10;
            l.styles.cal.iconCells = { '4': { 1: { size: 3, align: 'start' } } };
        };
        const doc = await makeDoc(load, { features: { waste: true, moon: true }, content: wasteContent(2), styles });
        const g = geo(doc, '2027-01-22');
        const row = rowOf(g, '2027-01-22');
        const icons = row.items.filter((i) => i.type === 'icon');
        assert.deepEqual(icons.map((i) => i.ref.category), ['moon', 'waste', 'waste']);
        assert.equal(g.frames[0].iconZone.cellW, 10);
        near(assert, icons[1].box.w, 3, 'cell size', 1e-9);
        near(assert, icons[1].box.x, row.iconCells[1].x + 0.15, 'aligned to the start of its cell', 1e-9);
        near(assert, icons[2].box.x + icons[2].box.w / 2, row.iconCells[2].x + 5, 'centred by default', 1e-9);
        assert.equal(P.resizeIconCell(g.frames[0].iconZone.cellW, g.frames[0].iconZone.cols, 4), 11);
    });

    t.test('no active icon category: an empty slot when wanted, nothing reserved otherwise', async () => {
        const slot = await makeDoc(load, { features: { moon: false } });
        const g = geo(slot);
        assert.equal(g.frames[0].iconZone, null);
        assert.equal(g.frames[0].rows.every((r) => r.items.filter((i) => i.type === 'slot').length === 1), true);
        near(assert, g.frames[0].textColumn.x, 197 + K.ROW_PAD + K.EMPTY_SLOT_W + K.ZONE_END_GAP, 'slot reserves its width', 1e-9);
        const none = await makeDoc(load, { features: { moon: false }, styles: (l) => { l.styles.cal.emptyIconSlot = false; } });
        const gn = geo(none);
        assert.equal(gn.frames[0].rows.some((r) => r.items.some((i) => i.type === 'slot')), false);
        near(assert, gn.frames[0].textColumn.x, 197 + K.ROW_PAD, 'text starts at the pad', 1e-9);
    });

    t.test('shift columns sit at the zone edge and push the icons; short rows print one line', async () => {
        const { defaultOperation } = await load('generate/shifts.js');
        const content = { shiftOps: { a: defaultOperation(1), b: defaultOperation(2) } };
        const doc = await makeDoc(load, { features: { shifts: true }, content });
        const g = geo(doc, '2027-01-22');
        const f = g.frames[0];
        const row = rowOf(g, '2027-01-22');
        const letters = itemsOf(row, 'shift').filter((i) => i.type === 'text');
        assert.equal(letters.length, 6, 'three shifts in each of two columns');
        assert.ok(letters.every((l) => /^[ABCD–]$/.test(l.text)));
        const step = K.SHIFT_COL_W + K.SHIFT_COL_GAP;
        near(assert, letters[0].x, f.inner.x + K.SHIFT_COL_W / 2, 'first column centre', 1e-9);
        near(assert, letters[3].x, f.inner.x + step + K.SHIFT_COL_W / 2, 'second column centre', 1e-9);
        assert.equal(itemsOf(row, 'shift').filter((i) => i.type === 'rect').length, 1, 'alternating background on the second column');
        near(assert, f.iconZone.x, f.inner.x + 2 * step + K.SHIFT_END_GAP, 'icons after the columns', 1e-9);
        const compact = await makeDoc(load, { features: { shifts: true }, content, styles: (l) => { l.boxes.cal.h = 70; } });
        const rc = rowOf(geo(compact, '2027-01-22'), '2027-01-22');
        const one = itemsOf(rc, 'shift');
        assert.equal(one.length, 1);
        assert.match(one[0].text, /^[ABCD–]( [ABCD–]){2} {2}\| {2}[ABCD–]( [ABCD–]){2}$/);
    });

    t.test('shift rotation: each crew works a shift two days, crews two days apart, columns three days apart', async () => {
        const { shiftCrews, defaultOperation, SHIFT_PRESETS } = await load('generate/shifts.js');
        const op = defaultOperation(1);
        assert.deepEqual(op, { name: 'Provoz 1', shifts: [{ letter: 'R', from: '06:00', to: '14:00' }, { letter: 'O', from: '14:00', to: '22:00' }, { letter: 'N', from: '22:00', to: '06:00' }], crews: ['A', 'B', 'C', 'D'], pos: 0 });
        assert.equal(SHIFT_PRESETS[12].length, 2);
        const { addDays } = await load('generate/dates.js');
        const days = Array.from({ length: 16 }, (_, i) => addDays('2027-01-04', i));
        const morning = days.map((d) => shiftCrews(op, d, 0)[0]);
        for (let i = 0; i + 8 < days.length; i++) assert.equal(morning[i], morning[i + 8], '8-day cycle');
        const runs = morning.join('').match(/(.)\1*/g).slice(1, -1);
        assert.ok(runs.every((r) => r.length === 2), `two days per crew: ${morning.join('')}`);
        for (const d of days) {
            const crews = shiftCrews(op, d, 0);
            assert.equal(new Set(crews).size, 3, 'three different crews work, one is off');
            assert.deepEqual(shiftCrews(op, d, 1), shiftCrews(op, addDays(d, 3), 0));
        }
        assert.deepEqual(shiftCrews({ shifts: [{ letter: 'D' }], crews: ['A', '', '', ''] }, '2027-01-04', 0).length, 1);
    });

    t.test('weekday above the number, number in the middle', async () => {
        const above = await makeDoc(load, { styles: (l) => { l.styles.cal.dayPosition = 'above'; } });
        const g = geo(above, '2027-01-22');
        const row = rowOf(g, '2027-01-22');
        const day = itemsOf(row, 'day')[0];
        const num = itemsOf(row, 'num')[0];
        assert.ok(day.y < num.y - 3, 'weekday above the number');
        assert.ok(day.rect.x >= g.frames[0].numberColumn.x - 0.01, 'inside the number column');
        assert.ok(g.frames[0].numberColumn.w >= measurer.width('Čtvrtek', day.font) - 1e-9, 'the column fits the longest weekday');
        assert.deepEqual(itemsOf(row, 'name').map((i) => i.text), ['Slavomír']);
        const middle = await makeDoc(load, { styles: (l) => { l.styles.cal.numberSide = 'middle'; } });
        const gm = geo(middle, '2027-01-22');
        const fm = gm.frames[0];
        near(assert, fm.numberColumn.x + fm.numberColumn.w / 2, fm.inner.x + fm.inner.w / 2, 'number in the middle', 1e-9);
        assert.ok(fm.textColumn.x > fm.numberColumn.x + fm.numberColumn.w, 'text after the number');
        assert.equal(itemsOf(rowOf(gm, '2027-01-22'), 'day')[0].anchor, 'start');
        assert.equal(itemsOf(rowOf(gm, '2027-01-22'), 'num')[0].anchor, 'middle');
        const short = await makeDoc(load, { styles: (l) => { l.styles.cal.dayFormat = 'short'; } });
        assert.equal(itemsOf(rowOf(geo(short, '2027-01-22'), '2027-01-22'), 'day')[0].text, 'Pá');
    });

    t.test('weekday colours, time texts with the text or on their own', async () => {
        const doc = await makeDoc(load, { features: { timeChange: true, seasons: true } });
        const g = geo(doc, '2027-03-28');
        const sunday = rowOf(g, '2027-03-28');
        assert.equal(itemsOf(sunday, 'day')[0].fill, doc.design.layout.styles.cal.sunColor);
        assert.equal(itemsOf(rowOf(g, '2027-03-27'), 'day')[0].fill, doc.design.layout.styles.cal.satColor);
        assert.equal(itemsOf(rowOf(g, '2027-03-26'), 'day')[0].fill, doc.design.layout.styles.cal.sunColor, 'holiday on a Friday');
        assert.equal(itemsOf(rowOf(g, '2027-03-25'), 'day')[0].fill, doc.design.layout.styles.day.color);
        const time = itemsOf(sunday, 'time')[0];
        assert.equal(time.text, 'Začátek letního času');
        assert.ok(time.y > itemsOf(sunday, 'name')[0].y, 'under the name');
        const sep = await makeDoc(load, { features: { timeChange: true }, styles: (l) => { l.styles.time.position = 'sep'; l.styles.time.valign = 'top'; } });
        const gs = geo(sep, '2027-03-28');
        const own = itemsOf(rowOf(gs, '2027-03-28'), 'time')[0];
        assert.equal(own.anchor, 'start', 'away from the number by default');
        near(assert, own.x, gs.frames[0].textColumn.x, 'at the far edge of the text column', 1e-9);
        assert.ok(own.y < itemsOf(rowOf(gs, '2027-03-28'), 'day')[0].y + 1);
    });

    t.test('text helpers: wrapping, month label, used families', () => {
        const font = { family: 'x', weight: 400, sizeMm: 3 };
        assert.deepEqual(wrapText('', 10, font, measurer), []);
        assert.deepEqual(wrapText('Jan', 0.1, font, measurer), ['Jan'], 'a word wider than the limit stays alone');
        const lines = wrapText('jedna dva tři čtyři pět šest', 14, font, measurer);
        assert.ok(lines.length > 2);
        assert.equal(lines.join(' '), 'jedna dva tři čtyři pět šest');
        for (const line of lines) assert.ok(measurer.width(line, font) <= 14 || !line.includes(' '));
        const two = wrapText('jedna dva tři čtyři pět šest', 14, font, measurer, { maxLines: 2 });
        assert.equal(two.length, 2);
        assert.equal(two.join(' '), 'jedna dva tři čtyři pět šest');
        assert.equal(monthLabel('PROSINEC 2026 / LEDEN 2027', true), 'PROSINEC 2026 / LEDEN 2027');
        assert.equal(monthLabel('PROSINEC 2026 / LEDEN 2027', false), 'Prosinec 2026 / Leden 2027');
        assert.equal(monthLabel('ÚNOR / BŘEZEN 2027', false), 'Únor / Březen 2027');
        // estimator: deterministic, heavier is wider, letter spacing adds per character
        assert.equal(measurer.width('Mm i', font), (0.9 + 0.82 + 0.28 + 0.3) * 3);
        assert.ok(measurer.width('abc', { ...font, weight: 700 }) > measurer.width('abc', font));
        near(assert, measurer.width('abc', { ...font, letterSpacingMm: 0.1 }) - measurer.width('abc', font), 0.3, 'spacing', 1e-9);
        assert.deepEqual(usedFamilies({ styles: { a: { family: 'F (A)' }, b: { text: { family: 'F (B)' } }, c: { family: null }, d: { family: 'F (A)' } } }), ['F (A)', 'F (B)']);
        assert.deepEqual(usedFamilies(null), []);
    });

    t.test('place labels widen the zone; layout does not mutate the document', async () => {
        const content = { wasteKinds: { bio: kind({ place: 'Pod lyžařským vlekem' }) } };
        const plain = await makeDoc(load, { features: { waste: true }, content });
        const labelled = await makeDoc(load, { features: { waste: true }, content, styles: (l) => { l.styles.cal.placeLabels = true; } });
        const before = copy(labelled);
        const a = geo(plain);
        const b = geo(labelled);
        assert.ok(b.frames[0].textColumn.x > a.frames[0].textColumn.x + 10);
        const label = itemsOf(b.frames[0].rows[0], 'icon').find((i) => i.type === 'text');
        assert.equal(label.text, 'Pod lyžařským vlekem');
        assert.ok(label.rect.x + label.rect.w <= b.frames[0].textColumn.x + 0.01);
        assert.deepEqual(labelled, before);
    });
}
