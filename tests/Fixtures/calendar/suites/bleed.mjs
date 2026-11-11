// Bleed changes (architecture §E.5, handoff §10.7): on / off / resize, nothing outside.
import assert from 'node:assert/strict';
import { copy } from '../helpers.mjs';

export default async function (t, load) {
    const B = await load('editor/bleed.js');
    const { defaultBoxes } = await load('model/layouts.js');
    const { DEFAULT_PAGE } = await load('model/defaults.js');
    const { pageBounds } = await load('editor/geometry.js');
    const PAGE = copy(DEFAULT_PAGE);
    const W = PAGE.width;
    const H = PAGE.height;
    const inside = (boxes, bleed) => {
        const b = pageBounds(PAGE, bleed);
        for (const [k, box] of Object.entries(boxes)) {
            assert.ok(box.x >= b.x0 - 1e-9 && box.y >= b.y0 - 1e-9 && box.x + box.w <= b.x1 + 1e-9 && box.y + box.h <= b.y1 + 1e-9, `${k} is outside with bleed ${bleed}: ${JSON.stringify(box)}`);
        }
    };

    for (const preset of ['photoLeft', 'photoRight', 'twoSided']) {
        t.test(`${preset}: switching the bleed off returns bleed edges to the trim`, () => {
            const from = defaultBoxes(preset, PAGE, 5);
            const out = B.adaptBoxesToBleed(from, PAGE, 5, 0);
            assert.deepEqual(out, defaultBoxes(preset, PAGE, 0));
            inside(out, 0);
            assert.deepEqual(from, defaultBoxes(preset, PAGE, 5), 'not mutated');
        });

        t.test(`${preset}: switching the bleed on moves trim edges into the bleed`, () => {
            const out = B.adaptBoxesToBleed(defaultBoxes(preset, PAGE, 0), PAGE, 0, 5);
            assert.deepEqual(out, defaultBoxes(preset, PAGE, 5));
        });

        t.test(`${preset}: changing the bleed size moves bleed edges, off → on → off is the identity`, () => {
            const five = defaultBoxes(preset, PAGE, 5);
            assert.deepEqual(B.adaptBoxesToBleed(five, PAGE, 5, 3), defaultBoxes(preset, PAGE, 3));
            assert.deepEqual(B.adaptBoxesToBleed(five, PAGE, 5, 10), defaultBoxes(preset, PAGE, 10));
            assert.deepEqual(B.adaptBoxesToBleed(B.adaptBoxesToBleed(five, PAGE, 5, 0), PAGE, 0, 5), five);
            inside(B.adaptBoxesToBleed(five, PAGE, 5, 3), 3);
        });
    }

    t.test('only edges on the trim or on the old bleed edge move; hidden boxes too', () => {
        const boxes = {
            inner: { x: 20, y: 30, w: 50, h: 20 },
            trimLeft: { x: 0, y: 30, w: 50, h: 20 },
            bleedBottom: { x: 20, y: 100, w: 50, h: H + 5 - 100, hidden: true },
            halfway: { x: -2.5, y: 30, w: 50, h: 20 }, // in the bleed but on neither line
        };
        const out = B.adaptBoxesToBleed(boxes, PAGE, 5, 8);
        assert.deepEqual(out.inner, boxes.inner);
        assert.deepEqual(out.trimLeft, { x: -8, y: 30, w: 58, h: 20 });
        assert.deepEqual(out.bleedBottom, { x: 20, y: 100, w: 50, h: H + 8 - 100, hidden: true });
        assert.deepEqual(out.halfway, boxes.halfway);
    });

    t.test('nothing ends outside the allowed area when the bleed shrinks', () => {
        const boxes = {
            halfway: { x: -4, y: -4, w: 50, h: 20 }, // not on a line: its edges are cut at the new bounds
            wide: { x: 280, y: 100, w: 34, h: 49 },
            lost: { x: W + 2, y: H + 1, w: 3, h: 4 }, // entirely in the old bleed
            tiny: { x: W + 4, y: 10, w: 1, h: 10 },
        };
        const out = B.adaptBoxesToBleed(boxes, PAGE, 5, 0);
        inside(out, 0);
        assert.deepEqual(out.halfway, { x: 0, y: 0, w: 46, h: 16 });
        assert.deepEqual(out.wide, { x: 280, y: 100, w: 30, h: 45 });
        assert.deepEqual([out.lost.w, out.lost.h], [3, 4], 'a box entirely outside is moved back, size kept');
        assert.deepEqual([out.lost.x, out.lost.y], [W - 3, H - 4]);
        const three = B.adaptBoxesToBleed(boxes, PAGE, 5, 3);
        inside(three, 3);
    });

    t.test('clampBoxesToBounds leaves inside boxes untouched (used after undo)', () => {
        const five = defaultBoxes('photoLeft', PAGE, 5);
        assert.deepEqual(B.clampBoxesToBounds(five, PAGE, 5), five);
        const clamped = B.clampBoxesToBounds(five, PAGE, 0);
        inside(clamped, 0);
        assert.deepEqual(clamped, defaultBoxes('photoLeft', PAGE, 0));
        const hidden = B.clampBoxesToBounds({ a: { x: -9, y: 0, w: 20, h: 10, hidden: true } }, PAGE, 5);
        assert.deepEqual(hidden.a, { x: -5, y: 0, w: 16, h: 10, hidden: true });
    });
}
