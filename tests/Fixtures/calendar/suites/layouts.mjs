// Default layouts: coinciding lines (§B.4), mirroring (§B.5), type rebase (§E.8), the layout fixture.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { ENV, copy, edges, near } from '../helpers.mjs';

export default async function (t, load) {
    const { DEFAULT_PAGE, DEFAULT_FEATURES, K } = await load('model/defaults.js');
    const L = await load('model/layouts.js');
    const E = await load('model/elements.js');
    const PAGE = copy(DEFAULT_PAGE);
    const same = (...values) => values.every((v) => Math.abs(v - values[0]) <= 1e-9);
    const allEdges = (boxes) => Object.fromEntries(Object.entries(boxes).map(([k, b]) => [k, edges(b)]));

    t.test('Fotka vlevo: the default boxes of §B.4 with 5 mm bleed', () => {
        assert.deepEqual(L.defaultBoxes('photoLeft', PAGE, 5), {
            head: { x: -5, y: -5, w: 320, h: 32 },
            photo: { x: -5, y: 27, w: 202, h: 123 },
            cal: { x: 197, y: 27, w: 118, h: 112 },
            month: { x: 203, y: 12.5, w: 101, h: 14.5 },
            wk: { x: 203, y: 139, w: 101, h: 4.5 },
            cap: { x: 5, y: 135, w: 93, h: 5.5 },
            logo: { x: 168.5, y: 31, w: 23.5, h: 23.5 },
            qr: { x: 170.5, y: 116.5, w: 21, h: 21 },
        });
    });

    t.test('Fotka vlevo: coinciding lines and free elements', () => {
        const e = allEdges(L.defaultBoxes('photoLeft', PAGE, 5));
        assert.ok(same(e.head.b, e.photo.t, e.cal.t, e.month.b, 27));
        assert.ok(same(e.photo.r, e.cal.l, 197));
        assert.ok(same(e.cal.b, e.wk.t, 139));
        assert.ok(same(e.month.l, e.wk.l, 203));
        assert.ok(same(e.month.r, e.wk.r, 304));
        // bleed edges
        assert.ok(same(e.head.l, e.head.t, e.photo.l, -5));
        assert.ok(same(e.head.r, e.cal.r, 315));
        assert.equal(e.photo.b, 150);
        // logo, caption and QR share no edge with anything
        for (const free of ['logo', 'cap', 'qr']) {
            for (const [other, o] of Object.entries(e)) {
                if (other === free) continue;
                for (const a of [e[free].l, e[free].r]) for (const b of [o.l, o.r]) assert.ok(Math.abs(a - b) > K.LINK_TOL, `${free} x ${a} vs ${other} ${b}`);
                for (const a of [e[free].t, e[free].b]) for (const b of [o.t, o.b]) assert.ok(Math.abs(a - b) > K.LINK_TOL, `${free} y ${a} vs ${other} ${b}`);
            }
        }
    });

    t.test('Fotka vpravo is the mirror of Fotka vlevo — every box, QR included', () => {
        const b = L.defaultBoxes('photoRight', PAGE, 5);
        assert.deepEqual({ x: b.head.x, w: b.head.w }, { x: -5, w: 320 });
        assert.deepEqual({ x: b.photo.x, w: b.photo.w }, { x: 113, w: 202 });
        assert.deepEqual({ x: b.cal.x, w: b.cal.w }, { x: -5, w: 118 });
        assert.deepEqual({ x: b.month.x, w: b.month.w }, { x: 6, w: 101 });
        assert.deepEqual({ x: b.wk.x, w: b.wk.w }, { x: 6, w: 101 });
        assert.deepEqual({ x: b.cap.x, w: b.cap.w }, { x: 212, w: 93 });
        assert.deepEqual({ x: b.logo.x, w: b.logo.w }, { x: 118, w: 23.5 });
        assert.deepEqual({ x: b.qr.x, w: b.qr.w }, { x: 118.5, w: 21 });
        const left = L.defaultBoxes('photoLeft', PAGE, 5);
        for (const k of Object.keys(left)) {
            assert.equal(b[k].y, left[k].y);
            assert.equal(b[k].h, left[k].h);
        }
        const e = allEdges(b);
        assert.ok(same(e.cal.r, e.photo.l, 113));
        assert.ok(same(e.month.l, e.wk.l, 6));
        assert.ok(same(e.month.r, e.wk.r, 107));
    });

    t.test('14 dní: boxes and coinciding lines', () => {
        const b = L.defaultBoxes('twoSided', PAGE, 5);
        assert.deepEqual(b.cal, { x: -5, y: 27, w: 89, h: 112 });
        assert.deepEqual(b.cal2, { x: 226, y: 27, w: 89, h: 112 });
        assert.deepEqual(b.photo, { x: 84, y: 27, w: 142, h: 112 });
        assert.deepEqual(b.month, { x: 6, y: 12.5, w: 120, h: 14.5 });
        assert.deepEqual(b.wk, { x: 232, y: 139, w: 72, h: 4.5 });
        assert.deepEqual(b.cap, { x: 89, y: 139.5, w: 132, h: 4.5 });
        assert.deepEqual(b.logo, { x: 143, y: 15, w: 24, h: 24 });
        assert.deepEqual(b.qr, { x: 199.5, y: 113, w: 21, h: 21 });
        const e = allEdges(b);
        assert.ok(same(e.head.b, e.cal.t, e.cal2.t, e.photo.t, e.month.b, 27));
        assert.ok(same(e.cal.r, e.photo.l, 84));
        assert.ok(same(e.photo.r, e.cal2.l, 226));
        assert.ok(same(e.cal.b, e.cal2.b, e.photo.b, e.wk.t, 139));
        assert.equal(e.wk.r, 304);
        near(assert, (e.logo.l + e.logo.r) / 2, PAGE.width / 2, 'logo centre');
        // the two frames are mirror images of each other
        near(assert, e.cal.l, PAGE.width - e.cal2.r, 'frames');
        near(assert, e.cal.r, PAGE.width - e.cal2.l, 'frames');
    });

    t.test('without bleed the edge elements end on the trim', () => {
        const e = allEdges(L.defaultBoxes('photoLeft', PAGE, 0));
        assert.deepEqual([e.head.l, e.head.t, e.head.r, e.photo.l, e.photo.b, e.cal.r], [0, 0, PAGE.width, 0, PAGE.height, PAGE.width]);
        assert.equal(e.head.b, 27);
    });

    t.test('presets per type, preset-dependent styles, default order', () => {
        assert.deepEqual(L.presetsForType('week'), ['photoLeft', 'photoRight']);
        assert.deepEqual(L.presetsForType('fortnight'), ['twoSided']);
        const pick = (l) => [l.styles.cal.numberSide, l.styles.month.align, l.styles.wk.align, l.styles.cap.align, l.styles.cal.iconGrid];
        assert.deepEqual(pick(L.defaultLayout('photoLeft', PAGE, 5, ENV)), ['end', 'end', 'end', 'start', '4']);
        assert.deepEqual(pick(L.defaultLayout('photoRight', PAGE, 5, ENV)), ['start', 'start', 'start', 'end', '4']);
        assert.deepEqual(pick(L.defaultLayout('twoSided', PAGE, 5, ENV)), ['start', 'start', 'end', 'middle', '2x2']);
        const layout = L.defaultLayout('photoLeft', PAGE, 5, ENV);
        assert.deepEqual(layout.order, ['qr', 'logo', 'cap', 'wk', 'month', 'head', 'cal', 'photo']);
        assert.equal(layout.v, 1);
        assert.equal(layout.boxes.cal2, undefined);
        assert.ok(L.defaultLayout('twoSided', PAGE, 5, ENV).boxes.cal2);
        assert.equal(layout.styles.ev.color, ENV.accent);
        assert.equal(L.defaultStyles({}).ev.color, '#2E5A8C');
    });

    t.test('default type sizes and families', () => {
        const fonts = [
            { family: 'Rubik (Rubik Regular)', fontName: 'Rubik', faceName: 'Rubik Regular', weight: 400, style: 'normal', url: 'r' },
            { family: 'Rubik (Rubik Bold)', fontName: 'Rubik', faceName: 'Rubik Bold', weight: 700, style: 'normal', url: 'b' },
            { family: 'Rubik (Rubik Italic)', fontName: 'Rubik', faceName: 'Rubik Italic', weight: 800, style: 'italic', url: 'i' },
        ];
        const s = L.defaultStyles({ fonts, accent: '#123456' });
        assert.deepEqual([s.num.size, s.day.size, s.name.size, s.hol.size, s.month.size, s.wk.size, s.cap.size, s.ev.size, s.time.size, s.shift.size, s.placeText.size, s.lines.width], [28, 11, 9.5, 8, 15, 9.5, 9, 8, 8, 8, 7.5, 1]);
        assert.equal(s.num.family, 'Rubik (Rubik Bold)');
        assert.equal(s.name.family, 'Rubik (Rubik Regular)');
        assert.equal(s.day.family, 'Rubik (Rubik Bold)'); // 600 is between 400 and 700: ties and nearer go heavier
        assert.equal(L.defaultStyles({}).num.family, null);
        assert.deepEqual(s.icons.order, ['waste', 'moon']);
    });

    t.test('mirroring twice is the identity', () => {
        for (const preset of ['photoLeft', 'photoRight']) {
            const layout = L.defaultLayout(preset, PAGE, 5, ENV);
            layout.boxes.logo = { x: 12.3, y: 40, w: 30, h: 10, hidden: true };
            layout.styles.offsets = { name: { x: 1.5, y: -0.5 }, num: { x: 0, y: 2 } };
            layout.styles.cal.iconCells = { '4': { 0: { size: 4, align: 'start' }, 2: { size: null, align: 'middle' } } };
            layout.styles.ev.align = 'end';
            layout.styles.ev.box = { x: 2, y: 3, w: 40, h: 5 };
            const before = copy(layout);
            const twice = L.mirrorLayout(L.mirrorLayout(layout, PAGE), PAGE);
            assert.deepEqual(twice, before);
            assert.deepEqual(layout, before, 'the argument is not mutated');
        }
    });

    t.test('mirroring flips exactly the list of §B.5', () => {
        const layout = L.defaultLayout('photoLeft', PAGE, 5, ENV);
        layout.boxes.logo.hidden = true;
        Object.assign(layout.styles.cal, { numAlign: 'start', dayAlign: 'end', iconCellW: 9, iconCells: { '2x2': { 1: { size: 5, align: 'end' }, 3: { size: null, align: 'middle' } } } });
        layout.styles.ev = { ...layout.styles.ev, align: 'start', box: { x: 1, y: 2, w: 30, h: 6 } };
        layout.styles.time = { ...layout.styles.time, align: 'end' };
        layout.styles.offsets = { name: { x: 2, y: 1 }, ev: { x: -3, y: 0 } };
        layout.styles.hol.wrapWidth = 40;
        layout.styles.photo.shadow.dx = 2;
        layout.styles.head.bg.gradient.angle = 30;
        const m = L.mirrorLayout(layout, PAGE);

        for (const [k, b] of Object.entries(layout.boxes)) {
            near(assert, m.boxes[k].x, PAGE.width - b.x - b.w, `${k}.x`);
            assert.deepEqual([m.boxes[k].y, m.boxes[k].w, m.boxes[k].h], [b.y, b.w, b.h], k);
        }
        assert.equal(m.boxes.logo.hidden, true);
        assert.deepEqual([m.styles.month.align, m.styles.wk.align, m.styles.cap.align], ['start', 'start', 'end']);
        assert.deepEqual([m.styles.cal.numberSide, m.styles.cal.numAlign, m.styles.cal.dayAlign, m.styles.ev.align, m.styles.time.align], ['start', 'end', 'start', 'end', 'start']);
        assert.deepEqual(m.styles.cal.iconCells, { '2x2': { 1: { size: 5, align: 'start' }, 3: { size: null, align: 'middle' } } });
        assert.deepEqual(m.styles.offsets, { name: { x: -2, y: 1 }, ev: { x: 3, y: 0 } });

        // everything else is untouched
        const strip = (l) => {
            const c = copy(l);
            for (const b of Object.values(c.boxes)) delete b.x;
            for (const k of ['month', 'wk', 'cap']) delete c.styles[k].align;
            for (const k of ['numberSide', 'numAlign', 'dayAlign', 'iconCells']) delete c.styles.cal[k];
            delete c.styles.ev.align;
            delete c.styles.time.align;
            delete c.styles.offsets;
            return c;
        };
        assert.deepEqual(strip(m), strip(layout));
        assert.deepEqual(m.order, layout.order);
        assert.equal(m.styles.photo.shadow.dx, 2);
        assert.equal(m.styles.hol.wrapWidth, 40);
        assert.equal(m.styles.cal.iconCellW, 9);
        assert.deepEqual(m.styles.ev.box, { x: 1, y: 2, w: 30, h: 6 });
        assert.deepEqual(m.styles.icons.order, layout.styles.icons.order);
        assert.equal(m.styles.head.bg.gradient.angle, 30);
    });

    t.test('flipRowStyles: null alignments stay null, middle stays middle', () => {
        const s = L.defaultStyles(ENV);
        const f = L.flipRowStyles({ ...s, cal: { ...s.cal, numberSide: 'middle' } });
        assert.equal(f.cal.numberSide, 'middle');
        assert.equal(f.cal.numAlign, null);
        assert.equal(f.ev.align, null);
        assert.equal(f.time.align, null);
        assert.deepEqual(L.flipRowStyles(L.flipRowStyles(s)), s);
    });

    t.test('type rebase keeps styles and order, resets positions and position-like styles', () => {
        const layout = L.defaultLayout('photoRight', PAGE, 5, ENV);
        layout.boxes.logo = { x: 10, y: 60, w: 40, h: 40, hidden: true };
        layout.boxes.wk.hidden = true;
        layout.order = ['logo', 'qr', 'cap', 'wk', 'month', 'head', 'photo', 'cal'];
        layout.styles.num.color = '#102030';
        layout.styles.name.size = 12;
        layout.styles.icons.order = ['moon', 'waste', 'ev'];
        layout.styles.ev.box = { x: 1, y: 1, w: 20, h: 5 };
        layout.styles.hol.wrapWidth = 30;
        layout.styles.cal.iconCellW = 9;
        layout.styles.offsets = { name: { x: 2, y: 0 } };
        const before = copy(layout);

        const { layout: fort, preset } = L.rebaseLayoutForType(layout, 'fortnight', PAGE, 5);
        assert.deepEqual(layout, before, 'the argument is not mutated');
        assert.equal(preset, 'twoSided');
        const def = L.defaultBoxes('twoSided', PAGE, 5);
        assert.deepEqual(fort.boxes, { ...def, logo: { ...def.logo, hidden: true }, wk: { ...def.wk, hidden: true } });
        assert.deepEqual(fort.order, layout.order);
        assert.deepEqual([fort.styles.num.color, fort.styles.name.size, fort.styles.icons.order], ['#102030', 12, ['moon', 'waste', 'ev']]);
        assert.deepEqual([fort.styles.ev.box, fort.styles.hol.wrapWidth, fort.styles.cal.iconCellW, fort.styles.offsets], [null, null, null, {}]);
        assert.deepEqual([fort.styles.cal.numberSide, fort.styles.month.align, fort.styles.wk.align, fort.styles.cap.align, fort.styles.cal.iconGrid], ['start', 'start', 'end', 'middle', '2x2']);

        const back = L.rebaseLayoutForType(fort, 'week', PAGE, 5);
        assert.equal(back.preset, 'photoLeft');
        assert.equal(back.layout.boxes.cal2, undefined);
        assert.deepEqual([back.layout.styles.cal.numberSide, back.layout.styles.cal.iconGrid, back.layout.styles.cap.align], ['end', '4', 'start']);
        assert.equal(back.layout.styles.num.color, '#102030');
    });

    t.test('visibility: features and hidden flags decide which boxes take part', () => {
        const design = { type: 'week', features: { ...DEFAULT_FEATURES }, layout: L.defaultLayout('photoLeft', PAGE, 5, ENV) };
        assert.deepEqual(E.visibleBoxKeys(design), ['photo', 'cap', 'logo', 'head', 'month', 'cal', 'wk']);
        design.features.qr = true;
        design.features.captions = false;
        design.layout.boxes.month.hidden = true;
        assert.deepEqual(E.visibleBoxKeys(design), ['photo', 'logo', 'head', 'cal', 'wk', 'qr']);
        design.features.weekNumbers = false;
        assert.equal(E.isElementVisible(design, 'wk'), false);
        assert.equal(E.isElementVisible(design, 'bg'), true);
        const fort = { type: 'fortnight', features: { ...DEFAULT_FEATURES }, layout: L.defaultLayout('twoSided', PAGE, 5, ENV) };
        assert.ok(E.visibleBoxKeys(fort).includes('cal2'));
        assert.deepEqual(E.boxKeysOf('cal', fort), ['cal', 'cal2']);
        assert.deepEqual(E.boxKeysOf('cal', design), ['cal']);
        assert.deepEqual(E.boxKeysOf('bg', design), []);
        assert.equal(E.elementOfBox('cal2'), 'cal');
    });

    t.test('element, part and icon vocabulary', () => {
        assert.deepEqual(E.ELEMENTS.map((e) => e.label), ['Fotografie', 'Popisek fotografie', 'Logo', 'Kalendárium', 'Měsíc', 'Číslo týdne', 'Hlavička', 'QR kód', 'Pozadí']);
        assert.deepEqual(E.ELEMENTS.filter((e) => e.hideable).map((e) => e.key), ['cap', 'logo', 'month', 'wk', 'qr']);
        assert.deepEqual(E.ELEMENTS.filter((e) => !e.hasBox).map((e) => e.key), ['bg']);
        assert.equal(E.elementLabel('cal'), 'Kalendárium');
        assert.equal(E.partLabel('hol'), 'Svátek');
        assert.equal(E.PARTS.filter((p) => p.group === 'Číslo dne').length, 4);
        const design = { type: 'week', features: { ...DEFAULT_FEATURES }, layout: L.defaultLayout('photoLeft', PAGE, 5, ENV) };
        assert.deepEqual(E.availableParts(design), ['num', 'numSat', 'numSun', 'numHol', 'day', 'name', 'hol', 'line', 'moon', 'icon']);
        design.features = { ...design.features, moon: false, seasons: true, events: true, shifts: true, waste: true };
        assert.deepEqual(E.availableParts(design), ['num', 'numSat', 'numSun', 'numHol', 'day', 'name', 'hol', 'line', 'time', 'ev', 'shift', 'waste', 'icon']);
        assert.deepEqual(E.activeIconCategories(design), ['waste']);
        assert.deepEqual(E.addableIconCategories(design), ['ev', 'season']);
    });

    t.test('font helpers', async () => {
        const F = await load('model/fonts.js');
        const fonts = [
            { family: 'Rubik (Rubik Light)', fontName: 'Rubik', faceName: 'Rubik Light', weight: 300, style: 'normal', url: 'l' },
            { family: 'Rubik (Rubik Bold)', fontName: 'Rubik', faceName: 'Rubik Bold', weight: 700, style: 'normal', url: 'b' },
            { family: 'Rubik (Rubik Medium Italic)', fontName: 'Rubik', faceName: 'Rubik Medium Italic', weight: 500, style: 'italic', url: 'i' },
            { family: 'Lora (Lora Regular)', fontName: 'Lora', faceName: 'Lora Regular', weight: 400, style: 'normal', url: 'o' },
        ];
        assert.deepEqual(F.fontNames(fonts), ['Rubik', 'Lora']);
        assert.equal(F.facesOf(fonts, 'Rubik').length, 3);
        assert.equal(F.nearestFace(fonts, 'Rubik', 500).weight, 700); // upright preferred, tie → heavier
        assert.equal(F.nearestFace(fonts, 'Rubik', 400).weight, 300);
        assert.equal(F.nearestFace(fonts, 'Nope', 400), null);
        // the server sends the file's subfamily name as `style` — an italic listed first must not win
        const real = [
            { family: 'Inter (Inter Italic)', fontName: 'Inter', faceName: 'Inter Italic', weight: 400, style: 'Italic', url: 'i' },
            { family: 'Inter (Inter Bold Italic)', fontName: 'Inter', faceName: 'Inter Bold Italic', weight: 700, style: 'Bold Italic', url: 'bi' },
            { family: 'Inter (Inter Regular)', fontName: 'Inter', faceName: 'Inter Regular', weight: 400, style: 'Regular', url: 'r' },
            { family: 'Inter (Inter Bold)', fontName: 'Inter', faceName: 'Inter Bold', weight: 700, style: 'Bold', url: 'b' },
        ];
        assert.equal(F.nearestFace(real, 'Inter', 400).url, 'r');
        assert.equal(F.nearestFace(real, 'Inter', 800).url, 'b');
        assert.deepEqual(real.map(F.isUpright), [false, false, true, true]);
        assert.equal(F.nearestFace(real.slice(0, 2), 'Inter', 400).url, 'i', 'a family with italics only still answers');
        assert.equal(F.faceByFamily(fonts, 'Lora (Lora Regular)').url, 'o');
        assert.deepEqual(F.cssFont({ family: 'Rubik (Rubik Bold)', weight: 800 }, fonts), { family: '"Rubik (Rubik Bold)"', weight: 400 });
        assert.deepEqual(F.cssFont({ family: null, weight: 800 }, fonts), { family: '"Outfit", Arial, sans-serif', weight: 700 });
        assert.deepEqual(F.cssFont({ family: 'Gone (Gone Bold)', weight: 600 }, fonts), { family: '"Outfit", Arial, sans-serif', weight: 600 });
        assert.equal(F.weightLabel(700), 'Bold');
    });

    t.test('the committed layout fixture is the default Fotka vlevo layout', () => {
        const file = JSON.parse(readFileSync(join(t.root, 'tests/Fixtures/calendar/layout-photo-left.json'), 'utf8'));
        assert.deepEqual(file, L.defaultLayout('photoLeft', PAGE, 5, { fonts: [], accent: null }));
    });
}
