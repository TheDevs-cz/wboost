// Scope guard (handoff §9.6): nothing assumes the default page size. A 420 × 200 mm page with 3 mm
// bleed must drive default boxes, bounds, snapping, linking, mm values and the rendered viewBox —
// and no file but model/defaults.js may contain the default dimensions.
import assert from 'node:assert/strict';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';
import { copy, edges, layoutOpts, makeDoc, near } from '../helpers.mjs';

export default async function (t, load) {
    const { DEFAULT_PAGE, K } = await load('model/defaults.js');
    const L = await load('model/layouts.js');
    const G = await load('editor/geometry.js');
    const S = await load('editor/snap.js');
    const Dg = await load('editor/drag.js');
    const B = await load('editor/bleed.js');
    const A = await load('editor/autogrow.js');
    const Z = await load('editor/zoom.js');
    const { visibleBoxKeys } = await load('model/elements.js');
    const { buildPages, weekPages } = await load('generate/pages.js');
    const { renderPage, pageViewBox, holesMarkup } = await load('render/page.js');
    const { layoutWeekPage } = await load('render/week-layout.js');
    const { photoDpi } = await load('render/warnings.js');
    const opts = await layoutOpts(load);

    const PAGE = { width: 420, height: 200, bandHeight: 17, holes: { size: 5, radius: 0.8, pitch: 10, top: 5, margin: 6 } };
    const BLEED = 3;
    const fx = PAGE.width / DEFAULT_PAGE.width;
    const fy = PAGE.height / DEFAULT_PAGE.height;
    const big = (o = {}) => makeDoc(load, { page: PAGE, print: { bleed: true, bleedMm: BLEED, marks: true }, ...o });
    const ctx = (doc) => ({ page: doc.design.page, bleedMm: BLEED, visibleKeys: visibleBoxKeys(doc.design), link: true });

    t.test('only model/defaults.js contains the literals 310 and 145', () => {
        const root = join(t.root, 'assets/calendar');
        const files = [];
        const walk = (dir) => {
            for (const name of readdirSync(dir)) {
                const path = join(dir, name);
                if (statSync(path).isDirectory()) walk(path);
                else if (/\.(js|mjs|css|twig|json)$/.test(name)) files.push(path);
            }
        };
        walk(root);
        assert.ok(files.length >= 25, `scanned ${files.length} files`);
        const literal = /(?<![\w.#-])(310|145)(?![\w%])(?!\.\d*[1-9])/;
        const offenders = [];
        for (const file of files) {
            const rel = relative(root, file);
            const lines = readFileSync(file, 'utf8').split('\n');
            lines.forEach((line, i) => {
                if (literal.test(line)) offenders.push(`${rel}:${i + 1}: ${line.trim().slice(0, 90)}`);
            });
        }
        assert.deepEqual(offenders.filter((o) => !o.startsWith('model/defaults.js:')), []);
        assert.equal(offenders.filter((o) => o.startsWith('model/defaults.js:')).length, 2, 'the two literals live in DEFAULT_PAGE');
        assert.ok(literal.test('width: 310,') && literal.test('x = 145;') && literal.test('(310)') && !literal.test('#145310') && !literal.test('0.145') && !literal.test('3100') && !literal.test('2451450'));
    });

    for (const preset of ['photoLeft', 'photoRight', 'twoSided']) {
        t.test(`${preset}: default boxes scale with the page and keep their coinciding lines`, () => {
            const boxes = L.defaultBoxes(preset, PAGE, BLEED);
            const ref = L.defaultBoxes(preset, DEFAULT_PAGE, 0);
            const e = Object.fromEntries(Object.entries(boxes).map(([k, b]) => [k, edges(b)]));
            for (const [k, b] of Object.entries(boxes)) {
                assert.ok(b.x >= -BLEED && b.y >= -BLEED && b.x + b.w <= PAGE.width + BLEED + 1e-9 && b.y + b.h <= PAGE.height + BLEED + 1e-9, `${k} inside the bleed box`);
                for (const v of [b.x, b.y, b.w, b.h]) near(assert, v, Math.round(v * 10) / 10, `${k} on the 0.1 mm grid`, 1e-9);
                if (k === 'logo' || k === 'qr') {
                    assert.equal(b.w, b.h, `${k} stays square`);
                    near(assert, b.w, ref[k].w * fx, `${k} scales by the width factor`, 0.051);
                }
            }
            // head: full width into the bleed, bottom on the scaled header line
            assert.deepEqual([e.head.l, e.head.t, e.head.r], [-BLEED, -BLEED, PAGE.width + BLEED]);
            near(assert, e.head.b, 27 * fy, 'header line', 0.051);
            assert.equal(e.month.t, PAGE.bandHeight, 'the month starts at the calendar’s own band');
            for (const k of ['photo', 'cal', 'month']) assert.equal(k === 'month' ? e.month.b : e[k].t, e.head.b, `${k} on the header line`);
            assert.equal(e.wk.t, e.cal.b);
            if (preset === 'twoSided') {
                assert.equal(e.cal.r, e.photo.l);
                assert.equal(e.photo.r, e.cal2.l);
                assert.deepEqual([e.cal.l, e.cal2.r], [-BLEED, PAGE.width + BLEED]);
                near(assert, e.cal.r, 84 * fx, 'frame width scales', 0.051);
                near(assert, (e.logo.l + e.logo.r) / 2, PAGE.width / 2, 'logo centred', 0.051);
                // Měsíc and Číslo týdne once per column: the second frames mirror the first ones on THIS page
                for (const k of ['month', 'wk']) {
                    near(assert, e[`${k}2`].l, PAGE.width - e[k].r, `${k}2 mirrors ${k}`, 0.051);
                    near(assert, e[`${k}2`].r, PAGE.width - e[k].l, `${k}2 mirrors ${k}`, 0.051);
                    assert.deepEqual([e[`${k}2`].t, e[`${k}2`].b], [e[k].t, e[k].b]);
                }
                assert.equal(e.month2.b, e.head.b);
                assert.equal(e.wk2.t, e.cal2.b);
                assert.equal(e.month.l, e.wk.l);
                assert.equal(e.month2.r, e.wk2.r);
                assert.ok(e.month.r < e.logo.l && e.logo.r < e.month2.l, 'the logo sits between the two month texts');
            } else {
                const split = preset === 'photoLeft' ? [e.photo.r, e.cal.l] : [e.cal.r, e.photo.l];
                assert.equal(split[0], split[1]);
                near(assert, preset === 'photoLeft' ? e.photo.r : PAGE.width - e.photo.l, 197 * fx, 'split line scales', 0.051);
                assert.equal(e.photo.b, PAGE.height + BLEED);
                assert.deepEqual([e.month.l, e.month.r], [e.wk.l, e.wk.r]);
            }
        });
    }

    t.test('bounds, clamping and typed values follow the page', async () => {
        const doc = await big();
        const base = doc.design.layout.boxes;
        assert.deepEqual(G.pageBounds(PAGE, BLEED), { x0: -3, y0: -3, x1: 423, y1: 203 });
        const far = Dg.dragTo(Dg.beginDrag({ boxes: base, key: 'logo', mode: 'move', ...ctx(doc) }), 9999, 9999, { snap: false }).boxes.logo;
        near(assert, far.x + far.w, 423, 'right bleed edge', 1e-9);
        near(assert, far.y + far.h, 203, 'bottom bleed edge', 1e-9);
        // X = 380 mm is a valid position on this page and impossible on the default one
        assert.equal(Dg.setField(base, 'logo', 'x', 380, ctx(doc)).logo.x, 380);
        assert.equal(Dg.setField(base, 'logo', 'x', 20, ctx(doc)).logo.x, 20, 'X = 20 mm is 20 mm from the trim edge on any page');
        assert.equal(Dg.setField(base, 'logo', 'w', 9999, { ...ctx(doc), link: false }).logo.w, Math.round((423 - base.logo.x) * 10) / 10);
        assert.equal(Dg.nudge(base, 'logo', K.NUDGE, 0, PAGE, BLEED).logo.x, Math.round((base.logo.x + K.NUDGE) * 10) / 10, 'nudge stays 0.3 mm');
        assert.equal(K.MIN_W, 6, 'minimum size is absolute mm (deviation D8)');
    });

    t.test('snap lines: page centre, band, centre below the band, trim and bleed edges of THIS page', async () => {
        const doc = await big();
        const base = doc.design.layout.boxes;
        const targets = S.snapTargets(base, 'logo', [], PAGE, BLEED);
        assert.deepEqual(targets.xs, [0, 210, 420, -3, 423]);
        assert.deepEqual(targets.ys, [0, 17, 108.5, 200, -3, 203]);
        const logo = base.logo;
        const dx = 210 - (logo.x + logo.w / 2) + 0.9;
        const out = Dg.dragTo(Dg.beginDrag({ boxes: base, key: 'logo', mode: 'move', ...ctx(doc) }), dx, 60, { snap: true, thresholdMm: 1.5 });
        near(assert, out.boxes.logo.x + out.boxes.logo.w / 2, 210, 'snapped to the centre of the 420 mm page', 0.051);
        assert.deepEqual(out.guides, [{ axis: 'x', pos: 210 }]);
        // the default page's centre is no target here
        const old = DEFAULT_PAGE.width / 2;
        assert.ok(!targets.xs.includes(old));
    });

    t.test('linking: the page-edge rule uses the page size', async () => {
        const doc = await big();
        const base = doc.design.layout.boxes;
        const drag = (key, mode, dx, dy) => Dg.dragTo(Dg.beginDrag({ boxes: base, key, mode, ...ctx(doc) }), dx, dy, { snap: false }).boxes;
        const split = base.photo.x + base.photo.w;
        const a = drag('photo', 'e', -40, 0);
        near(assert, a.cal.x, split - 40, 'cal follows', 1e-9);
        near(assert, a.cal.x + a.cal.w, 423, 'cal right edge stays', 1e-9);
        const b = drag('cal', 'e', -20, 0);
        assert.deepEqual(b.head, base.head, 'the right bleed edge does not link');
        // an element ending at x = 310 (the DEFAULT page's width) is an ordinary inner edge here: it links
        const boxes = { a: { x: 250, y: 50, w: 60, h: 20 }, b: { x: DEFAULT_PAGE.width, y: 80, w: 40, h: 20 } };
        const linked = Dg.dragTo(Dg.beginDrag({ boxes, key: 'a', mode: 'e', page: PAGE, bleedMm: BLEED, visibleKeys: ['a', 'b'], link: true }), 10, 0, { snap: false }).boxes;
        assert.equal(linked.b.x, DEFAULT_PAGE.width + 10);
    });

    t.test('bleed adaptation and auto-grow limits follow the page', async () => {
        const doc = await big();
        const base = doc.design.layout.boxes;
        const none = B.adaptBoxesToBleed(base, PAGE, BLEED, 0);
        assert.deepEqual(none, L.defaultBoxes('photoLeft', PAGE, 0));
        assert.deepEqual(B.adaptBoxesToBleed(none, PAGE, 0, 7), L.defaultBoxes('photoLeft', PAGE, 7));
        const giant = A.autoGrow(doc.design.layout, { month: 9999, wk: 0 }, doc.design);
        assert.deepEqual([giant.boxes.month.x, giant.boxes.month.w], [-3, 426]);
        near(assert, Z.fitScale(1000, 800, PAGE, BLEED), Math.min((1000 - 64) / 426, (800 - 120) / 206), 'fit scale', 1e-9);
    });

    t.test('week geometry: rows divide the frame of this page; type keeps its real size', async () => {
        const doc = await big();
        const page = weekPages(buildPages(doc))[0];
        const g = layoutWeekPage(doc, page, opts);
        const f = g.frames[0];
        const cal = doc.design.layout.boxes.cal;
        assert.deepEqual(f.inner, { x: cal.x, y: cal.y, w: 420 - cal.x, h: cal.h });
        near(assert, f.rowHeight, (cal.h - K.ROW_SLACK) / 7, 'row height', 1e-9);
        const numItem = f.rows[0].items.find((i) => i.part === 'num');
        near(assert, numItem.x, 420 - K.ROW_PAD, 'number at the right trim edge minus the pad', 1e-9);
        near(assert, numItem.font.sizeMm, 28 * 25.4 / 72, '28 pt is 28 pt on any page', 1e-9);
        for (const row of f.rows) for (const item of row.items) assert.ok(item.rect.x >= cal.x - 0.01 && item.rect.x + item.rect.w <= 420.01, `${row.date} ${item.part} inside the trim`);
    });

    t.test('the rendered page: viewBox, holes and marks of this page', async () => {
        const doc = await big();
        const pages = buildPages(doc);
        const page = weekPages(pages)[0];
        const o = { ...opts, idPrefix: 'd' };
        assert.ok(renderPage(doc, page, o).includes('viewBox="0 0 420 200"'));
        assert.ok(renderPage(doc, page, { ...o, bleed: true }).includes('viewBox="-3 -3 426 206"'));
        assert.ok(renderPage(doc, page, { ...o, marks: true }).includes('viewBox="-11 -11 442 222"'));
        assert.deepEqual(pageViewBox(doc.design, { bleed: true }), { x: -3, y: -3, w: 426, h: 206 });
        for (const p of [pages[0], pages[pages.length - 1]]) assert.ok(renderPage(doc, p, { ...o, bleed: true }).includes('viewBox="-3 -3 426 206"'), p.key);
        // holes: count = floor((W − 2·margin − size) / pitch) + 1, centred
        const xs = [...holesMarkup(PAGE).matchAll(/<rect x="([-\d.]+)" y="5" width="5" height="5" rx="0.8"\/>/g)].map((m) => Number(m[1]));
        assert.equal(xs.length, Math.floor((420 - 12 - 5) / 10) + 1);
        near(assert, xs[0], 420 - (xs[xs.length - 1] + 5), 'centred', 1e-9);
        const marks = renderPage(doc, page, { ...o, marks: true });
        assert.ok(marks.includes('<line x1="425" y1="0" x2="430" y2="0"/>'), 'crop mark starts 2 mm outside the 3 mm bleed at the right trim corner');
        assert.ok(marks.includes('<line x1="0" y1="205" x2="0" y2="210"/>'));
        // the CMYK export strokes the marks itself (registration colour): same sheet, same clip, the band left empty
        const band = renderPage(doc, page, { ...o, marks: true, drawMarks: false });
        assert.ok(band.includes('viewBox="-11 -11 442 222"') && band.includes('<clipPath'));
        assert.ok(!band.includes('data-el="marks"') && !band.includes('<line x1="425"'));
        // nothing in the markup sits at the default page's edge by accident
        assert.ok(!marks.includes(`width="${DEFAULT_PAGE.width}"`));
    });

    t.test('dpi is measured on the real frame of this page', async () => {
        const doc = await big();
        const frame = doc.design.layout.boxes.photo;
        const small = (await makeDoc(load)).design.layout.boxes.photo;
        assert.ok(photoDpi({ width: 4000, height: 3000 }, frame, 1) < photoDpi({ width: 4000, height: 3000 }, small, 1));
        assert.equal(photoDpi({ width: 4000, height: 3000 }, frame, 1), Math.round(25.4 / Math.max(frame.w / 4000, frame.h / 3000)));
    });

    t.test('a portrait page works too', async () => {
        const page = { ...copy(PAGE), width: 150, height: 210, bandHeight: 10 };
        const doc = await makeDoc(load, { page, print: { bleed: false, bleedMm: 3, marks: false } });
        const boxes = doc.design.layout.boxes;
        for (const [k, b] of Object.entries(boxes)) assert.ok(b.x >= 0 && b.y >= 0 && b.x + b.w <= 150 + 1e-9 && b.y + b.h <= 210 + 1e-9 && b.w > 0 && b.h > 0, k);
        const svg = renderPage(doc, weekPages(buildPages(doc))[0], { ...opts, idPrefix: 'v' });
        assert.ok(svg.includes('viewBox="0 0 150 210"'));
        assert.ok(!/NaN|undefined/.test(svg));
        assert.deepEqual(S.snapTargets(boxes, 'logo', [], page, 0).xs, [0, 75, 150]);
    });
}
