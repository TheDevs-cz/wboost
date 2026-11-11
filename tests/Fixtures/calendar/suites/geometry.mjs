// Box geometry: clamps, minimum size, rounding, typed fields, hit testing, zoom (architecture §E.1, §E.13).
import assert from 'node:assert/strict';
import { boxNear, copy, layoutOpts, makeDoc, near } from '../helpers.mjs';

export default async function (t, load) {
    const G = await load('editor/geometry.js');
    const Dg = await load('editor/drag.js');
    const Z = await load('editor/zoom.js');
    const P = await load('editor/parts.js');
    const { K, DEFAULT_PAGE, bleedOf } = await load('model/defaults.js');
    const { visibleBoxKeys } = await load('model/elements.js');
    const PAGE = copy(DEFAULT_PAGE);
    const W = PAGE.width;
    const H = PAGE.height;
    const bounds = G.pageBounds(PAGE, 5);
    const ctxOf = (doc, link = true) => ({ page: doc.design.page, bleedMm: bleedOf(doc.design.print), visibleKeys: visibleBoxKeys(doc.design), link });

    t.test('page bounds include the bleed ring', () => {
        assert.deepEqual(bounds, { x0: -5, y0: -5, x1: W + 5, y1: H + 5 });
        assert.deepEqual(G.pageBounds(PAGE, 0), { x0: 0, y0: 0, x1: W, y1: H });
    });

    t.test('clampMove keeps the size and stops at the outer bleed edge', () => {
        boxNear(assert, G.clampMove({ x: 900, y: -80, w: 20, h: 10 }, bounds), { x: W + 5 - 20, y: -5, w: 20, h: 10 });
        boxNear(assert, G.clampMove({ x: -900, y: 800, w: 20, h: 10 }, bounds), { x: -5, y: H + 5 - 10, w: 20, h: 10 });
        boxNear(assert, G.clampMove({ x: 900, y: 0, w: 20, h: 10 }, G.pageBounds(PAGE, 0)), { x: W - 20, y: 0, w: 20, h: 10 });
        // larger than the page: shrunk to the bounds
        boxNear(assert, G.clampMove({ x: 3, y: 3, w: 9999, h: 9999 }, bounds), { x: -5, y: -5, w: W + 10, h: H + 10 });
        assert.equal(G.clampMove({ x: 1, y: 1, w: 2, h: 2, hidden: true }, bounds).hidden, true);
    });

    t.test('clampResize stops each edge individually', () => {
        boxNear(assert, G.clampResize({ x: -40, y: 10, w: 100, h: 500 }, bounds), { x: -5, y: 10, w: 65, h: H + 5 - 10 });
        boxNear(assert, G.clampResize({ x: 300, y: 140, w: 100, h: 100 }, bounds), { x: 300, y: 140, w: 15, h: 10 });
        // never under the minimum: the left edge stays when there is room, else the box hugs the bound
        boxNear(assert, G.clampResize({ x: 100, y: 100, w: 1, h: 1 }, bounds), { x: 100, y: 100, w: K.MIN_W, h: K.MIN_H });
        boxNear(assert, G.clampResize({ x: W + 3, y: H + 4, w: 50, h: 50 }, bounds), { x: W + 5 - K.MIN_W, y: H + 5 - K.MIN_H, w: K.MIN_W, h: K.MIN_H });
    });

    t.test('resizeFromHandle: every handle moves only its edges', () => {
        const s = { x: 100, y: 50, w: 40, h: 20 };
        boxNear(assert, G.resizeFromHandle(s, 'e', 7, 99), { x: 100, y: 50, w: 47, h: 20 });
        boxNear(assert, G.resizeFromHandle(s, 'w', 7, 99), { x: 107, y: 50, w: 33, h: 20 });
        boxNear(assert, G.resizeFromHandle(s, 's', 99, -5), { x: 100, y: 50, w: 40, h: 15 });
        boxNear(assert, G.resizeFromHandle(s, 'n', 99, -5), { x: 100, y: 45, w: 40, h: 25 });
        boxNear(assert, G.resizeFromHandle(s, 'se', 3, 4), { x: 100, y: 50, w: 43, h: 24 });
        boxNear(assert, G.resizeFromHandle(s, 'nw', 3, 4), { x: 103, y: 54, w: 37, h: 16 });
        boxNear(assert, G.resizeFromHandle(s, 'ne', 3, 4), { x: 100, y: 54, w: 43, h: 16 });
        boxNear(assert, G.resizeFromHandle(s, 'sw', 3, 4), { x: 103, y: 50, w: 37, h: 24 });
        assert.deepEqual(G.HANDLES.slice().sort(), ['e', 'n', 'ne', 'nw', 's', 'se', 'sw', 'w']);
    });

    t.test('resizing past the opposite edge stops at the minimum and never inverts', () => {
        const s = { x: 100, y: 50, w: 40, h: 20 };
        boxNear(assert, G.resizeFromHandle(s, 'e', -500, 0), { x: 100, y: 50, w: K.MIN_W, h: 20 });
        boxNear(assert, G.resizeFromHandle(s, 'w', 500, 0), { x: 140 - K.MIN_W, y: 50, w: K.MIN_W, h: 20 });
        boxNear(assert, G.resizeFromHandle(s, 's', 0, -500), { x: 100, y: 50, w: 40, h: K.MIN_H });
        boxNear(assert, G.resizeFromHandle(s, 'n', 0, 500), { x: 100, y: 70 - K.MIN_H, w: 40, h: K.MIN_H });
        boxNear(assert, G.resizeFromHandle(s, 'nw', 500, 500), { x: 140 - K.MIN_W, y: 70 - K.MIN_H, w: K.MIN_W, h: K.MIN_H });
    });

    t.test('roundBox rounds the edges, so a fixed edge stays put', () => {
        const r = G.roundBox({ x: 10.04, y: 5.05, w: 20.03, h: 3.91 });
        near(assert, r.x, 10);
        near(assert, r.x + r.w, 30.1);
        near(assert, r.y, 5.1, 'y', 1e-9);
        near(assert, r.y + r.h, 9);
        assert.equal(G.roundBox({ x: 1, y: 1, w: 1, h: 1, hidden: true }).hidden, true);
    });

    t.test('E8 dragging the caption’s right handle far left stops at the minimum width, left edge fixed', async () => {
        const doc = await makeDoc(load);
        const boxes = doc.design.layout.boxes;
        const session = Dg.beginDrag({ boxes, key: 'cap', mode: 'e', ...ctxOf(doc) });
        const out = Dg.dragTo(session, -400, 0, { snap: true, thresholdMm: 2 }).boxes;
        boxNear(assert, out.cap, { ...boxes.cap, w: K.MIN_W });
        for (const k of Object.keys(boxes)) if (k !== 'cap') assert.deepEqual(out[k], boxes[k], k);
    });

    t.test('E7 dragging the logo far past the right edge stops at the bleed edge; at the trim without bleed', async () => {
        const doc = await makeDoc(load);
        const boxes = doc.design.layout.boxes;
        const out = Dg.dragTo(Dg.beginDrag({ boxes, key: 'logo', mode: 'move', ...ctxOf(doc) }), 5000, 0, { snap: false }).boxes;
        boxNear(assert, out.logo, { ...boxes.logo, x: W + 5 - boxes.logo.w });
        const flat = await makeDoc(load, { print: { bleed: false, bleedMm: 5, marks: false } });
        const out2 = Dg.dragTo(Dg.beginDrag({ boxes: flat.design.layout.boxes, key: 'logo', mode: 'move', ...ctxOf(flat) }), 5000, 5000, { snap: false }).boxes;
        boxNear(assert, out2.logo, { ...boxes.logo, x: W - boxes.logo.w, y: H - boxes.logo.h });
    });

    t.test('E9 typed X = 20 and Š = 80 for the logo are real millimetres from the trim', async () => {
        const doc = await makeDoc(load, { features: { qr: true } });
        const base = doc.design.layout.boxes;
        let boxes = Dg.setField(base, 'logo', 'x', 20, ctxOf(doc));
        boxes = Dg.setField(boxes, 'logo', 'w', 80, ctxOf(doc));
        assert.deepEqual(boxes.logo, { x: 20, y: base.logo.y, w: 80, h: base.logo.h });
        for (const k of Object.keys(base)) if (k !== 'logo') assert.deepEqual(boxes[k], base[k], k);
        assert.equal(boxes.logo.x / doc.design.page.width, 20 / W);
    });

    t.test('typed values outside the limits land on the nearest allowed value', async () => {
        const doc = await makeDoc(load);
        const base = doc.design.layout.boxes;
        const c = ctxOf(doc, false);
        assert.equal(Dg.setField(base, 'logo', 'x', 9999, c).logo.x, W + 5 - base.logo.w);
        assert.equal(Dg.setField(base, 'logo', 'x', -9999, c).logo.x, -5);
        assert.equal(Dg.setField(base, 'logo', 'y', 9999, c).logo.y, H + 5 - base.logo.h);
        const wide = Dg.setField(base, 'logo', 'w', 9999, c).logo;
        assert.deepEqual([wide.x, Math.round((wide.x + wide.w) * 10) / 10], [base.logo.x, W + 5], 'the edge stops at the bleed, the element is not shifted');
        assert.equal(Dg.setField(base, 'logo', 'w', 0.5, c).logo.w, K.MIN_W);
        assert.equal(Dg.setField(base, 'logo', 'h', -3, c).logo.h, K.MIN_H);
        assert.equal(Dg.setField(base, 'logo', 'x', 20.26, c).logo.x, 20.3, 'rounded to 0.1 mm');
        assert.equal(base.logo.x, 168.5, 'the argument is not mutated');
    });

    t.test('E6 arrow keys: 0.3 mm, with Shift 3 mm, clamped, nothing else moves', async () => {
        const doc = await makeDoc(load);
        const base = doc.design.layout.boxes;
        const a = Dg.nudge(base, 'logo', K.NUDGE, 0, PAGE, 5);
        near(assert, a.logo.x, base.logo.x + 0.3, 'x', 1e-9);
        const b = Dg.nudge(a, 'logo', K.NUDGE_BIG, 0, PAGE, 5);
        near(assert, b.logo.x, base.logo.x + 3.3, 'x', 1e-9);
        assert.equal(K.NUDGE_BIG, K.NUDGE * 10);
        const c = Dg.nudge(base, 'head', -K.NUDGE_BIG, -K.NUDGE_BIG, PAGE, 5);
        assert.deepEqual(c.head, base.head, 'already at the bleed edge');
        for (const k of Object.keys(base)) if (k !== 'logo') assert.deepEqual(b[k], base[k], k);
    });

    t.test('hit testing follows the layer order and visibility', async () => {
        const doc = await makeDoc(load, { features: { qr: false } });
        const d = doc.design;
        assert.deepEqual(G.hitTest({ x: 180, y: 40 }, d, null), { el: 'logo' });
        assert.deepEqual(G.hitTest({ x: 100, y: 80 }, d, null), { el: 'photo' });
        assert.deepEqual(G.hitTest({ x: 250, y: 80 }, d, null), { el: 'cal', frame: 0 });
        assert.deepEqual(G.hitTest({ x: 250, y: 20 }, d, null), { el: 'month' });
        assert.deepEqual(G.hitTest({ x: 100, y: 5 }, d, null), { el: 'head' });
        assert.deepEqual(G.hitTest({ x: 180, y: 120 }, d, null), { el: 'photo' }, 'the QR element is off');
        assert.deepEqual(G.hitTest({ x: 198, y: 143 }, d, null), { el: 'bg' });
        assert.deepEqual(G.hitTest({ x: W + 4, y: 143 }, d, null), { el: 'bg' }, 'inside the bleed');
        assert.equal(G.hitTest({ x: W + 6, y: 143 }, d, null), null);
        const hidden = copy(d);
        hidden.layout.boxes.logo.hidden = true;
        assert.deepEqual(G.hitTest({ x: 180, y: 40 }, hidden, null), { el: 'photo' });
        const fort = await makeDoc(load, { type: 'fortnight' });
        assert.deepEqual(G.hitTest({ x: 40, y: 80 }, fort.design, null), { el: 'cal', frame: 0 });
        assert.deepEqual(G.hitTest({ x: 270, y: 80 }, fort.design, null), { el: 'cal', frame: 1 });
        assert.equal(G.containsPoint({ x: 0, y: 0, w: 2, h: 2 }, { x: 2, y: 2 }), true);
        assert.equal(G.containsPoint({ x: 0, y: 0, w: 2, h: 2 }, { x: 2.1, y: 2 }), false);
    });

    t.test('partAt finds the part under a point from the geometry', async () => {
        const doc = await makeDoc(load);
        const { buildPages, weekPages } = await load('generate/pages.js');
        const { layoutWeekPage } = await load('render/week-layout.js');
        const page = weekPages(buildPages(doc))[0];
        const geometry = layoutWeekPage(doc, page, await layoutOpts(load));
        const row = geometry.frames[0].rows[2];
        const centre = (key) => {
            const r = row.parts.find((p) => p.key === key).rect;
            return { x: r.x + r.w / 2, y: r.y + r.h / 2 };
        };
        assert.deepEqual(G.partAt(centre('num'), geometry), { frame: 0, date: '2026-12-30', part: 'num' });
        assert.deepEqual(G.partAt(centre('day'), geometry), { frame: 0, date: '2026-12-30', part: 'day' });
        assert.deepEqual(G.partAt(centre('name'), geometry), { frame: 0, date: '2026-12-30', part: 'name' });
        assert.deepEqual(G.partAt(centre('moon'), geometry), { frame: 0, date: '2026-12-30', part: 'moon' });
        const line = row.parts.find((p) => p.key === 'line').rect;
        assert.equal(G.partAt({ x: line.x + 30, y: line.y + 0.5 }, geometry).part, 'line');
        assert.equal(G.partAt({ x: line.x + 40, y: row.y + 1.2 }, geometry), null, 'empty part of the row');
        assert.equal(G.partAt({ x: 50, y: 80 }, geometry), null, 'outside the frame');
        assert.equal(G.partAt({ x: 50, y: 80 }, null), null);
        const sat = geometry.frames[0].rows[5];
        const r = sat.parts.find((p) => p.key === 'numSat').rect;
        assert.equal(G.partAt({ x: r.x + 1, y: r.y + 1 }, geometry).part, 'numSat');
    });

    t.test('zoom: fit scale is clamped, steps go up and down', () => {
        near(assert, Z.fitScale(1344, 640 + 120, PAGE, 5), Math.min((1344 - 64) / (W + 10), 640 / (H + 10)), 'fit', 1e-9);
        assert.equal(Z.fitScale(100, 100, PAGE, 5), K.FIT_MIN);
        assert.equal(Z.fitScale(99999, 99999, PAGE, 0), K.FIT_MAX);
        assert.ok(Z.fitScale(1344, 760, PAGE, 0) > Z.fitScale(1344, 760, PAGE, 5));
        assert.deepEqual([Z.zoomIn(1), Z.zoomIn(1.1), Z.zoomIn(4), Z.zoomIn(3)], [1.25, 1.25, 4, 4]);
        assert.deepEqual([Z.zoomOut(1), Z.zoomOut(0.5), Z.zoomOut(4), Z.zoomOut(1.6)], [0.75, 0.5, 3, 1.5]);
    });

    t.test('part gestures: offsets snap back, bars resize in the flow direction', () => {
        assert.deepEqual(P.dragPartOffset(null, 3, 0, true, false), { x: 3, y: 0 });
        assert.deepEqual(P.dragPartOffset({ x: 3, y: 0 }, -2.8, 0.2, true, false), null, 'snaps back to the original place');
        assert.deepEqual(P.dragPartOffset({ x: 3, y: 0 }, -2.8, 0.2, false, false), { x: 0.2, y: 0.2 }, 'Alt keeps small offsets');
        assert.deepEqual(P.dragPartOffset({ x: 1, y: 1 }, 2, 0, true, true), { x: -1, y: 1 }, 'a drag in the mirrored frame counts negated');
        assert.deepEqual(P.dragPartOffset(null, 0.04, 0.04, false, false), null);
        const box = { x: 2, y: 5, w: 30, h: 6 };
        assert.deepEqual(P.resizeEventBox(box, 'e', 4.04, 0), { x: 2, y: 5, w: 34, h: 6 });
        assert.deepEqual(P.resizeEventBox(box, 'w', 4, 0), { x: 6, y: 5, w: 26, h: 6 });
        assert.deepEqual(P.resizeEventBox(box, 'w', 400, 0), { x: 26, y: 5, w: K.EVBOX_MIN_W, h: 6 });
        assert.deepEqual(P.resizeEventBox(box, 's', 0, -100), { x: 2, y: 5, w: 30, h: K.EVBOX_MIN_H });
        assert.deepEqual(P.resizeEventBox(box, 'n', 0, 2), { x: 2, y: 7, w: 30, h: 4 });
        assert.equal(P.resizeIconCell(7.2, 4, 4), 8.2);
        assert.equal(P.resizeIconCell(7.2, 4, -400), K.ICON_CELL_MIN);
        assert.equal(P.resizeHolWrap(40, -5.04), 35);
        assert.equal(P.resizeHolWrap(40, -500), K.HOL_WRAP_MIN);
    });
}
