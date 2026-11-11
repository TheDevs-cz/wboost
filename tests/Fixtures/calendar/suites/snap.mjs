// Snapping (architecture §E.3, handoff §5.5, checks E4, E5, E20).
import assert from 'node:assert/strict';
import { copy, makeDoc, near } from '../helpers.mjs';

export default async function (t, load) {
    const S = await load('editor/snap.js');
    const Dg = await load('editor/drag.js');
    const { DEFAULT_PAGE, K, bleedOf } = await load('model/defaults.js');
    const { visibleBoxKeys } = await load('model/elements.js');
    const PAGE = copy(DEFAULT_PAGE);
    const W = PAGE.width;
    const H = PAGE.height;
    const begin = (doc, key, mode, extra = {}) => Dg.beginDrag({ boxes: doc.design.layout.boxes, key, mode, page: doc.design.page, bleedMm: bleedOf(doc.design.print), visibleKeys: visibleBoxKeys(doc.design), link: false, ...extra });
    const th = (pxPerMm) => K.SNAP_PX / pxPerMm;

    t.test('targets: trim edges, bleed edges, centre line, band and the centre below it, other visible elements', async () => {
        const doc = await makeDoc(load);
        const boxes = doc.design.layout.boxes;
        const targets = S.snapTargets(boxes, 'logo', visibleBoxKeys(doc.design), PAGE, 5);
        for (const x of [0, W / 2, W, -5, W + 5, boxes.photo.x + boxes.photo.w, boxes.month.x, boxes.month.x + boxes.month.w, boxes.cap.x]) assert.ok(targets.xs.includes(x), `x ${x}`);
        for (const y of [0, PAGE.bandHeight, (PAGE.bandHeight + H) / 2, H, -5, H + 5, 27, 139, boxes.cap.y]) assert.ok(targets.ys.includes(y), `y ${y}`);
        assert.ok(!targets.xs.includes(boxes.logo.x), 'the moving element is not its own target');
        assert.ok(!targets.xs.includes(boxes.qr.x), 'QR is switched off in step 1');
        assert.ok(!targets.ys.includes(H / 2), 'there is no horizontal centre line of the whole page');
        const noBleed = S.snapTargets(boxes, 'logo', [], PAGE, 0);
        assert.deepEqual(noBleed.xs, [0, W / 2, W]);
        assert.deepEqual(noBleed.ys, [0, PAGE.bandHeight, (PAGE.bandHeight + H) / 2, H]);
    });

    t.test('hidden elements are not targets', async () => {
        const doc = await makeDoc(load, { styles: (l) => { l.boxes.wk.hidden = true; l.boxes.wk.x = 77.7; } });
        const targets = S.snapTargets(doc.design.layout.boxes, 'logo', visibleBoxKeys(doc.design), PAGE, 5);
        assert.ok(!targets.xs.includes(77.7));
        const shown = copy(doc.design);
        delete shown.layout.boxes.wk.hidden;
        assert.ok(S.snapTargets(shown.layout.boxes, 'logo', visibleBoxKeys(shown), PAGE, 5).xs.includes(77.7));
    });

    t.test('E4 moving the logo near the vertical page centre snaps its centre and shows one guide', async () => {
        const doc = await makeDoc(load);
        const logo = doc.design.layout.boxes.logo;
        const centre = logo.x + logo.w / 2;
        const session = begin(doc, 'logo', 'move');
        const { boxes, guides } = Dg.dragTo(session, W / 2 - centre + 1.2, 30, { snap: true, thresholdMm: th(3) });
        near(assert, boxes.logo.x + boxes.logo.w / 2, W / 2, 'centre', 0.051);
        assert.deepEqual(guides, [{ axis: 'x', pos: W / 2 }]);
        assert.equal(boxes.logo.w, logo.w);
        assert.equal(boxes.logo.h, logo.h);
    });

    t.test('E4 with Alt: no snap, no guide', async () => {
        const doc = await makeDoc(load);
        const logo = doc.design.layout.boxes.logo;
        const dx = W / 2 - (logo.x + logo.w / 2) + 1.2;
        const { boxes, guides } = Dg.dragTo(begin(doc, 'logo', 'move'), dx, 30, { snap: false, thresholdMm: th(3) });
        near(assert, boxes.logo.x, Math.round((logo.x + dx) * 10) / 10, 'x', 1e-9);
        assert.deepEqual(guides, []);
    });

    t.test('E5 an edge approaching the photo’s edge snaps to it', async () => {
        const doc = await makeDoc(load);
        const { logo, photo } = doc.design.layout.boxes;
        const photoRight = photo.x + photo.w;
        // logo's left edge 0.8 mm before the photo's right edge
        const { boxes, guides } = Dg.dragTo(begin(doc, 'logo', 'move'), photoRight - logo.x - 0.8, 0, { snap: true, thresholdMm: th(3) });
        near(assert, boxes.logo.x, photoRight, 'left edge', 1e-9);
        assert.ok(guides.some((g) => g.axis === 'x' && g.pos === photoRight));
    });

    t.test('E20 the threshold is in screen pixels: zooming in needs to be closer in mm', async () => {
        const doc = await makeDoc(load);
        const logo = doc.design.layout.boxes.logo;
        const dx = W / 2 - (logo.x + logo.w / 2) + 1.2; // 1.2 mm off the centre line; y far from any target
        const at = (pxPerMm) => Dg.dragTo(begin(doc, 'logo', 'move'), dx, 30, { snap: true, thresholdMm: th(pxPerMm) });
        assert.equal(at(3).guides.length, 1, '6 px = 2 mm at 3 px/mm');
        assert.equal(at(6).guides.length, 0, '6 px = 1 mm at 6 px/mm');
        near(assert, at(6).boxes.logo.x, Math.round((logo.x + dx) * 10) / 10, 'x', 1e-9);
    });

    t.test('moving: the nearest of left / centre / right wins per axis, both axes independently', () => {
        const targets = { xs: [100, 131], ys: [50] };
        const out = S.snapMove({ x: 99.2, y: 48.6, w: 30, h: 10 }, targets, 2);
        near(assert, out.box.x, 100, 'left edge is nearer than the right one (0.8 < 1.8)');
        near(assert, out.box.y, 50 - 0, 'top snaps', 1e-9);
        assert.deepEqual(out.guides, [{ axis: 'x', pos: 100 }, { axis: 'y', pos: 50 }]);
        const centre = S.snapMove({ x: 84.5, y: 0, w: 30, h: 10 }, { xs: [100], ys: [] }, 2);
        near(assert, centre.box.x, 85, 'centre candidate');
        const none = S.snapMove({ x: 60, y: 20, w: 30, h: 10 }, targets, 2);
        assert.deepEqual(none.guides, []);
        assert.deepEqual(none.box, { x: 60, y: 20, w: 30, h: 10 });
    });

    t.test('resizing: only the dragged edge snaps, the opposite edge stays', () => {
        const targets = { xs: [100, 131, 60.5], ys: [50, 61] };
        const e = S.snapResize({ x: 60, y: 20, w: 70, h: 10 }, 'e', targets, 2);
        assert.deepEqual(e.box, { x: 60, y: 20, w: 71, h: 10 });
        assert.deepEqual(e.guides, [{ axis: 'x', pos: 131 }]);
        const w = S.snapResize({ x: 99, y: 20, w: 31.5, h: 10 }, 'w', targets, 2);
        near(assert, w.box.x, 100);
        near(assert, w.box.x + w.box.w, 130.5, 'right edge stays although 131 is near');
        const se = S.snapResize({ x: 60, y: 20, w: 70, h: 40.4 }, 'se', targets, 2);
        assert.deepEqual(se.box, { x: 60, y: 20, w: 71, h: 41 });
        assert.equal(se.guides.length, 2);
        const n = S.snapResize({ x: 60, y: 49, w: 70, h: 12 }, 'n', targets, 2);
        assert.deepEqual(n.box, { x: 60, y: 50, w: 70, h: 11 });
    });

    t.test('a snapped resize is clamped, rounded and keeps the minimum', async () => {
        const doc = await makeDoc(load);
        const cap = doc.design.layout.boxes.cap;
        // the caption's right edge dragged to 0.5 mm left of its own left edge + minimum: snap must not shrink it below the minimum
        const out = Dg.dragTo(begin(doc, 'cap', 'e'), -(cap.w - 1), 0, { snap: true, thresholdMm: 3 }).boxes.cap;
        assert.ok(out.w >= K.MIN_W - 1e-9);
        assert.equal(out.x, cap.x);
        // photo's bottom edge snaps to the trim edge when dragged near it
        const { boxes, guides } = Dg.dragTo(begin(doc, 'photo', 's'), 0, -4.3, { snap: true, thresholdMm: 2 });
        near(assert, boxes.photo.y + boxes.photo.h, H, 'bottom on the trim', 1e-9);
        assert.deepEqual(guides, [{ axis: 'y', pos: H }]);
    });

    t.test('snapping to the band and to the centre of the area below it', async () => {
        const doc = await makeDoc(load);
        const logo = doc.design.layout.boxes.logo;
        const a = Dg.dragTo(begin(doc, 'logo', 'move'), -100, PAGE.bandHeight - logo.y + 0.7, { snap: true, thresholdMm: 2 });
        near(assert, a.boxes.logo.y, PAGE.bandHeight, 'top on the band', 1e-9);
        const mid = (PAGE.bandHeight + H) / 2;
        const b = Dg.dragTo(begin(doc, 'logo', 'move'), -100, mid - (logo.y + logo.h / 2) - 0.9, { snap: true, thresholdMm: 2 });
        near(assert, b.boxes.logo.y + b.boxes.logo.h / 2, mid, 'centre', 0.051);
        assert.ok(b.guides.some((g) => g.axis === 'y' && g.pos === mid));
    });

    t.test('on a fortnight the other Kalendárium frame is an ordinary target', async () => {
        const doc = await makeDoc(load, { type: 'fortnight' });
        const boxes = doc.design.layout.boxes;
        const targets = S.snapTargets(boxes, 'cal', visibleBoxKeys(doc.design), doc.design.page, 5);
        assert.ok(targets.xs.includes(boxes.cal2.x));
        assert.ok(!targets.xs.includes(boxes.cal.x + boxes.cal.w) || boxes.photo.x === boxes.cal.x + boxes.cal.w);
    });
}
