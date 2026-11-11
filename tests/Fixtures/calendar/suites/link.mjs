// Propojit prvky: one test per row of handoff §7.4, each asserting ALL boxes before and after,
// plus the rules of §9.3 (L4–L6, L8, L9) and §7.2.
import assert from 'node:assert/strict';
import { copy, makeDoc } from '../helpers.mjs';

export default async function (t, load) {
    const Dg = await load('editor/drag.js');
    const { applyLink } = await load('editor/link.js');
    const { DEFAULT_PAGE, K, bleedOf } = await load('model/defaults.js');
    const { visibleBoxKeys } = await load('model/elements.js');
    const W = DEFAULT_PAGE.width;
    const H = DEFAULT_PAGE.height;

    // Fotka vlevo, bleed 5 mm (architecture §B.4)
    const LEFT = {
        head: { x: -5, y: -5, w: 320, h: 32 },
        photo: { x: -5, y: 27, w: 202, h: 123 },
        cal: { x: 197, y: 27, w: 118, h: 112 },
        month: { x: 203, y: 12.5, w: 101, h: 14.5 },
        wk: { x: 203, y: 139, w: 101, h: 4.5 },
        cap: { x: 5, y: 135, w: 93, h: 5.5 },
        logo: { x: 168.5, y: 31, w: 23.5, h: 23.5 },
        qr: { x: 170.5, y: 116.5, w: 21, h: 21 },
    };

    /** Every box must equal `base` except the listed changes — elements that should not move are checked too. */
    function expectBoxes(actual, base, changes, message = '') {
        assert.deepEqual(Object.keys(actual).sort(), Object.keys(base).sort(), `${message} keys`);
        for (const key of Object.keys(base)) {
            const want = { ...base[key], ...(changes[key] || {}) };
            for (const f of ['x', 'y', 'w', 'h']) {
                assert.ok(Math.abs(actual[key][f] - want[f]) <= 1e-9, `${message} ${key}.${f}: expected ${want[f]}, got ${actual[key][f]}`);
            }
            assert.equal(Boolean(actual[key].hidden), Boolean(want.hidden), `${message} ${key}.hidden`);
        }
    }

    const ctx = (doc, link = true) => ({ page: doc.design.page, bleedMm: bleedOf(doc.design.print), visibleKeys: visibleBoxKeys(doc.design), link });
    const drag = (doc, boxes, key, mode, dx, dy, o = {}) => {
        const session = Dg.beginDrag({ boxes, key, mode, ...ctx(doc, o.link !== false) });
        return Dg.dragTo(session, dx, dy, { snap: Boolean(o.snap), thresholdMm: o.thresholdMm || 2 });
    };
    const fresh = async (o) => {
        const doc = await makeDoc(load, o);
        const base = copy(doc.design.layout.boxes);
        return { doc, base };
    };

    t.test('before: the default layout Fotka vlevo is the one the examples assume', async () => {
        const { doc, base } = await fresh();
        expectBoxes(base, LEFT, {});
        assert.equal(doc.design.linkElements, true, 'L1 linking is on by default');
        assert.deepEqual(visibleBoxKeys(doc.design), ['photo', 'cap', 'logo', 'head', 'month', 'cal', 'wk']);
    });

    t.test('7.4 row 1: photo right handle to the left → kalendárium’s left edge follows, its right edge stays', async () => {
        const { doc, base } = await fresh();
        expectBoxes(base, LEFT, {}, 'before');
        const { boxes } = drag(doc, base, 'photo', 'e', -40, 0);
        expectBoxes(boxes, LEFT, { photo: { w: 162 }, cal: { x: 157, w: 158 } }, 'after');
        expectBoxes(base, LEFT, {}, 'the start boxes are not mutated');
    });

    t.test('7.4 row 2: kalendárium left handle to the right → kalendárium narrower, photo wider', async () => {
        const { doc, base } = await fresh();
        expectBoxes(base, LEFT, {}, 'before');
        const { boxes } = drag(doc, base, 'cal', 'w', 30, 0);
        expectBoxes(boxes, LEFT, { cal: { x: 227, w: 88 }, photo: { w: 232 } }, 'after');
    });

    t.test('7.4 row 3: header bottom handle down → photo and kalendárium start lower, month frame gets taller', async () => {
        const { doc, base } = await fresh();
        expectBoxes(base, LEFT, {}, 'before');
        const { boxes } = drag(doc, base, 'head', 's', 0, 10);
        expectBoxes(boxes, LEFT, { head: { h: 42 }, photo: { y: 37, h: 113 }, cal: { y: 37, h: 102 }, month: { h: 24.5 } }, 'after');
    });

    t.test('7.4 row 4: kalendárium top handle down → photo top, header bottom and month bottom follow', async () => {
        const { doc, base } = await fresh();
        expectBoxes(base, LEFT, {}, 'before');
        const { boxes } = drag(doc, base, 'cal', 'n', 0, 10);
        expectBoxes(boxes, LEFT, { cal: { y: 37, h: 102 }, photo: { y: 37, h: 113 }, head: { h: 42 }, month: { h: 24.5 } }, 'after');
    });

    t.test('7.4 row 5: kalendárium bottom handle up → week number’s top follows, its bottom stays', async () => {
        const { doc, base } = await fresh();
        expectBoxes(base, LEFT, {}, 'before');
        const { boxes } = drag(doc, base, 'cal', 's', 0, -10);
        expectBoxes(boxes, LEFT, { cal: { h: 102 }, wk: { y: 129, h: 14.5 } }, 'after');
    });

    t.test('7.4 row 6: month text left handle → week number’s left edge follows (aligned columns)', async () => {
        const { doc, base } = await fresh();
        expectBoxes(base, LEFT, {}, 'before');
        expectBoxes(drag(doc, base, 'month', 'w', 10, 0).boxes, LEFT, { month: { x: 213, w: 91 }, wk: { x: 213, w: 91 } }, 'to the right');
        expectBoxes(drag(doc, base, 'month', 'w', -4, 0).boxes, LEFT, { month: { x: 199, w: 105 }, wk: { x: 199, w: 105 } }, 'to the left');
        // and the shared right edge
        expectBoxes(drag(doc, base, 'month', 'e', -20, 0).boxes, LEFT, { month: { w: 81 }, wk: { w: 81 } }, 'right edge');
    });

    t.test('7.4 row 7: photo left or bottom handle inwards → only the photo (edges started on the page edge)', async () => {
        const { doc, base } = await fresh();
        expectBoxes(base, LEFT, {}, 'before');
        expectBoxes(drag(doc, base, 'photo', 'w', 20, 0).boxes, LEFT, { photo: { x: 15, w: 182 } }, 'left');
        expectBoxes(drag(doc, base, 'photo', 's', 0, -20).boxes, LEFT, { photo: { h: 103 } }, 'bottom');
        expectBoxes(drag(doc, base, 'photo', 'sw', 20, -20).boxes, LEFT, { photo: { x: 15, w: 182, h: 103 } }, 'corner');
    });

    t.test('7.4 row 8: logo or caption handles → only that element (no shared edges)', async () => {
        const { doc, base } = await fresh();
        expectBoxes(base, LEFT, {}, 'before');
        for (const handle of ['n', 'ne', 'e', 'se', 's', 'sw', 'w', 'nw']) {
            const dx = handle.includes('e') ? 6 : handle.includes('w') ? -6 : 0;
            const dy = handle.includes('s') ? 5 : handle.includes('n') ? -5 : 0;
            for (const key of ['logo', 'cap']) {
                const { boxes } = drag(doc, base, key, handle, dx, dy);
                const b = LEFT[key];
                expectBoxes(boxes, LEFT, { [key]: { x: b.x + Math.min(dx, 0), y: b.y + Math.min(dy, 0), w: b.w + Math.abs(dx), h: b.h + Math.abs(dy) } }, `${key} ${handle}`);
            }
        }
    });

    t.test('7.4 row 9: kalendárium top-left corner → photo follows horizontally; header, photo and month vertically', async () => {
        const { doc, base } = await fresh();
        expectBoxes(base, LEFT, {}, 'before');
        const { boxes } = drag(doc, base, 'cal', 'nw', 20, 10);
        expectBoxes(boxes, LEFT, { cal: { x: 217, y: 37, w: 98, h: 102 }, photo: { y: 37, w: 222, h: 113 }, head: { h: 42 }, month: { h: 24.5 } }, 'after');
    });

    t.test('7.4 row 10: moving the kalendárium by its body → only the kalendárium moves', async () => {
        const { doc, base } = await fresh();
        expectBoxes(base, LEFT, {}, 'before');
        const { boxes } = drag(doc, base, 'cal', 'move', -30, 5);
        expectBoxes(boxes, LEFT, { cal: { x: 167, y: 32 } }, 'after');
    });

    t.test('7.4 row 11: hidden week number is ignored and is where it was when shown again', async () => {
        const { doc, base } = await fresh({ styles: (l) => { l.boxes.wk.hidden = true; } });
        const hiddenLeft = { ...LEFT, wk: { ...LEFT.wk, hidden: true } };
        expectBoxes(base, hiddenLeft, {}, 'before');
        assert.ok(!visibleBoxKeys(doc.design).includes('wk'));
        const { boxes } = drag(doc, base, 'cal', 's', 0, -10);
        expectBoxes(boxes, hiddenLeft, { cal: { h: 102 } }, 'after');
        const shown = { ...boxes, wk: { x: boxes.wk.x, y: boxes.wk.y, w: boxes.wk.w, h: boxes.wk.h } };
        expectBoxes(shown, LEFT, { cal: { h: 102 } }, 'shown again');
        // a hidden element is no trigger either
        const { doc: d2, base: b2 } = await fresh({ styles: (l) => { l.boxes.month.hidden = true; } });
        const out = drag(d2, b2, 'month', 'w', 10, 0).boxes;
        expectBoxes(out, { ...LEFT, month: { ...LEFT.month, hidden: true } }, { month: { x: 213, w: 91 } }, 'hidden trigger');
    });

    t.test('7.4 row 12: linking off → only the photo changes, a gap opens', async () => {
        const { doc, base } = await fresh();
        expectBoxes(base, LEFT, {}, 'before');
        const { boxes } = drag(doc, base, 'photo', 'e', -40, 0, { link: false });
        expectBoxes(boxes, LEFT, { photo: { w: 162 } }, 'after');
        assert.equal(boxes.cal.x - (boxes.photo.x + boxes.photo.w), 40);
    });

    t.test('7.4 row 13: with the gap open nothing follows; after snapping back together they move together again', async () => {
        const { doc, base } = await fresh();
        const gap = drag(doc, base, 'photo', 'e', -40, 0, { link: false }).boxes;
        expectBoxes(gap, LEFT, { photo: { w: 162 } }, 'gap');
        // linking on, the edges no longer coincide → the kalendárium does not follow
        const again = drag(doc, gap, 'photo', 'e', -10, 0).boxes;
        expectBoxes(again, LEFT, { photo: { w: 152 } }, 'no follower');
        // drag the photo's edge until it snaps to the kalendárium's left edge
        const snapped = drag(doc, again, 'photo', 'e', 49.3, 0, { snap: true, thresholdMm: 2 });
        expectBoxes(snapped.boxes, LEFT, {}, 'snapped back');
        assert.deepEqual(snapped.guides, [{ axis: 'x', pos: 197 }]);
        // from the next drag on they move together
        const together = drag(doc, snapped.boxes, 'photo', 'e', -20, 0).boxes;
        expectBoxes(together, LEFT, { photo: { w: 182 }, cal: { x: 177, w: 138 } }, 'together');
    });

    t.test('L4 no drift: dragging away and back without releasing restores everything exactly', async () => {
        const { doc, base } = await fresh();
        const session = Dg.beginDrag({ boxes: base, key: 'photo', mode: 'e', ...ctx(doc) });
        const o = { snap: false };
        for (const dx of [-3.33, -17.2, -40.07, -80, -12.5, 0.04, -0.01, 0]) Dg.dragTo(session, dx, 0, o);
        expectBoxes(Dg.dragTo(session, 0, 0, o).boxes, LEFT, {}, 'back at the start');
        assert.deepEqual(Dg.dragTo(session, 0, 0, o).boxes, base);
        expectBoxes(Dg.dragTo(session, -40, 0, o).boxes, LEFT, { photo: { w: 162 }, cal: { x: 157, w: 158 } }, 'same result every time');
    });

    t.test('L5 follower minimum: the kalendárium stops at its minimum width, the photo continues; back restores both', async () => {
        const { doc, base } = await fresh();
        const session = Dg.beginDrag({ boxes: base, key: 'photo', mode: 'e', ...ctx(doc) });
        const o = { snap: false };
        const atMin = Dg.dragTo(session, 112, 0, o).boxes; // exactly the minimum
        expectBoxes(atMin, LEFT, { photo: { w: 314 }, cal: { x: 309, w: K.MIN_W } }, 'at the minimum');
        const beyond = Dg.dragTo(session, 400, 0, o).boxes; // as far as it goes: the outer bleed edge
        expectBoxes(beyond, LEFT, { photo: { w: W + 10 }, cal: { x: W + 5 - K.MIN_W, w: K.MIN_W } }, 'beyond');
        assert.ok(beyond.photo.x + beyond.photo.w > beyond.cal.x, 'they overlap');
        expectBoxes(Dg.dragTo(session, 0, 0, o).boxes, LEFT, {}, 'restored');
        // vertical follower minimum: header pushed far down
        const s2 = Dg.beginDrag({ boxes: base, key: 'head', mode: 's', ...ctx(doc) });
        const down = Dg.dragTo(s2, 0, 120, o).boxes;
        expectBoxes(down, LEFT, { head: { h: 152 }, photo: { y: 146, h: K.MIN_H }, cal: { y: 135, h: K.MIN_H }, month: { h: 134.5 } }, 'vertical minimum');
    });

    t.test('L6 Alt disables snapping but not linking; without Alt the snapped position is what followers get', async () => {
        const { doc, base } = await fresh();
        const alt = drag(doc, base, 'photo', 'e', -41.4, 0, { snap: false });
        expectBoxes(alt.boxes, LEFT, { photo: { w: 160.6 }, cal: { x: 155.6, w: 159.4 } }, 'Alt');
        assert.deepEqual(alt.guides, []);
        const snapped = drag(doc, base, 'photo', 'e', -41.4, 0, { snap: true, thresholdMm: 2 });
        expectBoxes(snapped.boxes, LEFT, { photo: { w: 160 }, cal: { x: W / 2, w: 160 } }, 'snapped to the page centre');
        assert.deepEqual(snapped.guides, [{ axis: 'x', pos: W / 2 }]);
    });

    t.test('L8 Fotka vpravo: the mirrored neighbours follow', async () => {
        const { doc, base } = await fresh({ preset: 'photoRight' });
        const RIGHT = Object.fromEntries(Object.entries(LEFT).map(([k, b]) => [k, { ...b, x: Math.round((W - b.x - b.w) * 10) / 10 }]));
        expectBoxes(base, RIGHT, {}, 'before');
        const { boxes } = drag(doc, base, 'photo', 'w', 40, 0);
        expectBoxes(boxes, RIGHT, { photo: { x: 153, w: 162 }, cal: { w: 158 } }, 'after');
        expectBoxes(drag(doc, base, 'cal', 'e', -30, 0).boxes, RIGHT, { cal: { w: 88 }, photo: { x: 83, w: 232 } }, 'mirror of row 2');
        expectBoxes(drag(doc, base, 'head', 's', 0, 10).boxes, RIGHT, { head: { h: 42 }, photo: { y: 37, h: 113 }, cal: { y: 37, h: 102 }, month: { h: 24.5 } }, 'row 3');
    });

    t.test('L8 14-day layout: both frames are independent boxes that follow the photo', async () => {
        const { doc, base } = await fresh({ type: 'fortnight' });
        const TWO = {
            head: { x: -5, y: -5, w: 320, h: 32 },
            cal: { x: -5, y: 27, w: 89, h: 112 },
            cal2: { x: 226, y: 27, w: 89, h: 112 },
            photo: { x: 84, y: 27, w: 142, h: 112 },
            month: { x: 6, y: 12.5, w: 120, h: 14.5 },
            wk: { x: 232, y: 139, w: 72, h: 4.5 },
            cap: { x: 89, y: 139.5, w: 132, h: 4.5 },
            logo: { x: 143, y: 15, w: 24, h: 24 },
            qr: { x: 199.5, y: 113, w: 21, h: 21 },
        };
        expectBoxes(base, TWO, {}, 'before');
        expectBoxes(drag(doc, base, 'photo', 'e', -40, 0).boxes, TWO, { photo: { w: 102 }, cal2: { x: 186, w: 129 } }, 'photo right edge');
        expectBoxes(drag(doc, base, 'photo', 'w', 10, 0).boxes, TWO, { photo: { x: 94, w: 132 }, cal: { w: 99 } }, 'photo left edge');
        expectBoxes(drag(doc, base, 'cal2', 'w', -16, 0).boxes, TWO, { cal2: { x: 210, w: 105 }, photo: { w: 126 } }, 'second frame triggers');
        expectBoxes(drag(doc, base, 'head', 's', 0, 10).boxes, TWO, { head: { h: 42 }, cal: { y: 37, h: 102 }, cal2: { y: 37, h: 102 }, photo: { y: 37, h: 102 }, month: { h: 24.5 } }, 'header');
        expectBoxes(drag(doc, base, 'cal', 's', 0, -10).boxes, TWO, { cal: { h: 102 }, cal2: { h: 102 }, photo: { h: 102 }, wk: { y: 129, h: 14.5 } }, 'bottom line');
    });

    t.test('L9 arrow keys and typed X / Y change nothing else; typed Š moves the edge and its neighbours', async () => {
        const { doc, base } = await fresh();
        expectBoxes(Dg.nudge(base, 'logo', K.NUDGE, 0, doc.design.page, 5), LEFT, { logo: { x: 168.8 } }, 'nudge');
        expectBoxes(Dg.nudge(base, 'photo', 0, -K.NUDGE_BIG, doc.design.page, 5), LEFT, { photo: { y: 24 } }, 'nudging never links');
        expectBoxes(Dg.setField(base, 'logo', 'x', 20, ctx(doc)), LEFT, { logo: { x: 20 } }, 'typed X');
        expectBoxes(Dg.setField(base, 'logo', 'y', 60, ctx(doc)), LEFT, { logo: { y: 60 } }, 'typed Y');
        expectBoxes(Dg.setField(base, 'photo', 'x', 0, ctx(doc)), LEFT, { photo: { x: 0 } }, 'typed X of a linked element');
        expectBoxes(Dg.setField(base, 'photo', 'w', 162, ctx(doc)), LEFT, { photo: { w: 162 }, cal: { x: 157, w: 158 } }, 'typed Š, linking on');
        expectBoxes(Dg.setField(base, 'photo', 'w', 162, ctx(doc, false)), LEFT, { photo: { w: 162 } }, 'typed Š, linking off');
        expectBoxes(Dg.setField(base, 'head', 'h', 42, ctx(doc)), LEFT, { head: { h: 42 }, photo: { y: 37, h: 113 }, cal: { y: 37, h: 102 }, month: { h: 24.5 } }, 'typed V');
        expectBoxes(Dg.setField(base, 'photo', 'h', 100, ctx(doc)), LEFT, { photo: { h: 100 } }, 'typed V of an edge that started in the bleed');
    });

    t.test('page edges never link: trim edge and bleed edge on every side', async () => {
        const { doc, base } = await fresh();
        expectBoxes(drag(doc, base, 'head', 'w', 20, 0).boxes, LEFT, { head: { x: 15, w: 300 } }, 'head left');
        expectBoxes(drag(doc, base, 'head', 'n', 0, 3).boxes, LEFT, { head: { y: -2, h: 29 } }, 'head top');
        expectBoxes(drag(doc, base, 'head', 'e', -20, 0).boxes, LEFT, { head: { w: 300 } }, 'head right (the kalendárium has the same edge)');
        expectBoxes(drag(doc, base, 'cal', 'e', -20, 0).boxes, LEFT, { cal: { w: 98 } }, 'cal right');
        // an edge exactly on the trim edge (no bleed) does not link either
        const flat = await fresh({ print: { bleed: false, bleedMm: 5, marks: false } });
        assert.equal(flat.base.photo.x, 0);
        assert.equal(flat.base.head.x, 0);
        const out = drag(flat.doc, flat.base, 'photo', 'w', 20, 0).boxes;
        assert.deepEqual(out.head, flat.base.head);
        assert.deepEqual(out.photo, { ...flat.base.photo, x: 20, w: flat.base.photo.w - 20 });
        const bottom = drag(flat.doc, flat.base, 'photo', 's', 0, -20).boxes;
        for (const k of Object.keys(flat.base)) if (k !== 'photo') assert.deepEqual(bottom[k], flat.base[k], k);
        assert.equal(flat.base.photo.y + flat.base.photo.h, H);
    });

    t.test('collinear is enough, tolerance 0.15 mm, no chain reaction, both edges of a follower', async () => {
        const page = (await fresh()).doc.design.page;
        const boxes = {
            a: { x: 50, y: 20, w: 50, h: 20 },
            touching: { x: 100, y: 20, w: 40, h: 20 },
            far: { x: 100.1, y: 110, w: 20, h: 10 }, // same line, far away vertically, 0.1 mm off
            sameSide: { x: 60, y: 60, w: 40, h: 10 }, // its RIGHT edge is on the line
            off: { x: 100.2, y: 70, w: 20, h: 10 }, // 0.2 mm off: not linked
            chained: { x: 140, y: 20, w: 30, h: 20 }, // touches `touching`, not `a`
        };
        const keys = Object.keys(boxes);
        const to = { x: 50, y: 20, w: 60, h: 20 };
        const out = applyLink(boxes, 'a', boxes.a, to, keys, page);
        assert.deepEqual(out.a, to);
        assert.deepEqual(out.touching, { x: 110, y: 20, w: 30, h: 20 });
        assert.deepEqual([out.far.x, out.far.y, out.far.h], [110, 110, 10]);
        assert.ok(Math.abs(out.far.x + out.far.w - 120.1) < 1e-9, 'the far edge of the follower stays');
        assert.deepEqual(out.sameSide, { x: 60, y: 60, w: 50, h: 10 });
        assert.deepEqual(out.off, boxes.off);
        assert.deepEqual(out.chained, boxes.chained, 'no chain reaction');
        // followers are resized, never shifted: the far edge stays
        assert.equal(out.touching.x + out.touching.w, 140);
        // non-visible boxes come back untouched
        const partial = applyLink(boxes, 'a', boxes.a, to, ['a', 'far'], page);
        assert.deepEqual(partial.touching, boxes.touching);
        assert.deepEqual(partial.far, out.far);
        assert.deepEqual(boxes.touching, { x: 100, y: 20, w: 40, h: 20 }, 'the base is not mutated');
        // an edge that did not move takes nothing along
        const still = applyLink(boxes, 'a', boxes.a, { ...boxes.a, h: 30 }, keys, page);
        assert.deepEqual(still.touching, { ...boxes.touching, h: 30 }, 'only the bottom line moved');
        assert.deepEqual(still.far, boxes.far);
        assert.deepEqual(still.sameSide, boxes.sameSide);
    });

    t.test('the dragged element itself is snapped, clamped and rounded before followers land on its edge', async () => {
        const { doc, base } = await fresh();
        const { boxes } = drag(doc, base, 'photo', 'e', -40.04, 0);
        expectBoxes(boxes, LEFT, { photo: { w: 162 }, cal: { x: 157, w: 158 } }, 'rounded to 0.1 mm');
        const far = drag(doc, base, 'cal', 'w', -900, 0).boxes;
        expectBoxes(far, LEFT, { cal: { x: -5, w: 320 }, photo: { w: K.MIN_W } }, 'clamped at the bleed, follower at its minimum');
    });
}
