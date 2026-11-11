// Měsíc / Číslo týdne grow to fit their text (architecture §E.5, handoff §5.11, check E18).
import assert from 'node:assert/strict';
import { copy, layoutOpts, makeDoc, near } from '../helpers.mjs';

export default async function (t, load) {
    const A = await load('editor/autogrow.js');
    const { buildPages } = await load('generate/pages.js');
    const { K, ptToMm } = await load('model/defaults.js');
    const { createEstimatorMeasurer } = await load('render/measure.js');
    const opts = await layoutOpts(load);
    const m = createEstimatorMeasurer();
    const widthsOf = (doc) => A.maxTextWidths(doc, buildPages(doc), opts);

    t.test('the default layouts need no growth', async () => {
        for (const o of [{}, { preset: 'photoRight' }, { type: 'fortnight' }]) {
            const doc = await makeDoc(load, o);
            const widths = widthsOf(doc);
            assert.ok(widths.month > 20 && widths.month <= doc.design.layout.boxes.month.w, `month ${widths.month}`);
            assert.ok(widths.wk > 5 && widths.wk <= doc.design.layout.boxes.wk.w, `wk ${widths.wk}`);
            assert.equal(A.autoGrow(doc.design.layout, widths, doc.design), null);
            if (o.type === 'fortnight') {
                assert.deepEqual(Object.keys(widths).sort(), ['month', 'month2', 'wk', 'wk2']);
                assert.ok(widths.month2 > 20 && widths.month2 <= doc.design.layout.boxes.month2.w, `month2 ${widths.month2}`);
                assert.ok(widths.wk2 > 5 && widths.wk2 <= doc.design.layout.boxes.wk2.w, `wk2 ${widths.wk2}`);
            } else {
                assert.deepEqual(Object.keys(widths).sort(), ['month', 'wk']);
            }
        }
    });

    t.test('14 dní: each frame is measured and grown on its own, away from its own anchor', async () => {
        const doc = await makeDoc(load, { type: 'fortnight', styles: (l) => { l.styles.month.size = 30; l.styles.wk.size = 60; } });
        const before = copy(doc.design.layout);
        const pages = buildPages(doc).filter((p) => p.kind === 'week');
        const widths = widthsOf(doc);
        // per frame: the widest text of THAT column over all pages
        const font = { family: '"Outfit", Arial, sans-serif', weight: 700, sizeMm: ptToMm(30), letterSpacingMm: 0.09 * 2 };
        for (const f of [0, 1]) {
            const longest = Math.max(...pages.map((p) => m.width(p.week.monthTexts[f], font)));
            const key = f ? 'month2' : 'month';
            assert.ok(Math.abs(widths[key] - (longest + 2 * K.TEXT_GROW_MARGIN)) < longest * 0.02, `${key} ${widths[key]} vs ${longest}`);
        }
        for (const key of ['month', 'month2', 'wk', 'wk2']) assert.ok(widths[key] > before.boxes[key].w, `${key} must grow`);
        const grown = A.autoGrow(doc.design.layout, widths, doc.design);
        assert.ok(grown);
        // first frames are start-aligned: the left edge stays; second frames mirror: the right edge stays
        for (const key of ['month', 'wk']) {
            assert.equal(grown.boxes[key].x, before.boxes[key].x, `${key} left edge`);
            assert.ok(grown.boxes[key].w >= widths[key] - 1e-9 && grown.boxes[key].w < widths[key] + 0.11, key);
        }
        for (const key of ['month2', 'wk2']) {
            near(assert, grown.boxes[key].x + grown.boxes[key].w, before.boxes[key].x + before.boxes[key].w, `${key} right edge`, 1e-9);
            assert.ok(grown.boxes[key].w >= widths[key] - 1e-9 && grown.boxes[key].w < widths[key] + 0.11, key);
        }
        for (const k of Object.keys(before.boxes)) if (!['month', 'month2', 'wk', 'wk2'].includes(k)) assert.deepEqual(grown.boxes[k], before.boxes[k], k);
        assert.deepEqual(doc.design.layout, before, 'the argument is not mutated');
        assert.equal(A.autoGrow(grown, widths, doc.design), null, 'one pass is enough');
        // only one frame too narrow → only that one grows
        const one = copy(grown);
        one.boxes.wk2 = { ...one.boxes.wk2, x: one.boxes.wk2.x + 10, w: one.boxes.wk2.w - 10 };
        const again = A.autoGrow(one, widths, doc.design);
        assert.deepEqual(again.boxes.wk2, grown.boxes.wk2);
        for (const k of Object.keys(one.boxes)) if (k !== 'wk2') assert.equal(again.boxes[k], one.boxes[k], k);
        // the eye of the element (the flag of the first frame) stops both
        const hidden = copy(doc.design.layout);
        hidden.boxes.month.hidden = true;
        const rest = A.autoGrow(hidden, widths, { ...doc.design, layout: hidden });
        assert.deepEqual([rest.boxes.month, rest.boxes.month2], [hidden.boxes.month, hidden.boxes.month2]);
        assert.ok(rest.boxes.wk.w > hidden.boxes.wk.w && rest.boxes.wk2.w > hidden.boxes.wk2.w);
    });

    t.test('maxTextWidths measures every week page with the style applied', async () => {
        const doc = await makeDoc(load);
        const font = { family: '"Outfit", Arial, sans-serif', weight: 700, sizeMm: ptToMm(15), letterSpacingMm: 0.09 };
        const longest = Math.max(...buildPages(doc).filter((p) => p.kind === 'week').map((p) => m.width(p.week.monthTexts[0], font)));
        near(assert, widthsOf(doc).month, longest + 2 * K.TEXT_GROW_MARGIN, 'month', 1e-9);
        assert.ok(longest > m.width('LEDEN 2027', font), 'two-month pages are the widest');
        // background on → the padding counts on both sides
        const padded = await makeDoc(load, { styles: (l) => { l.styles.month.bg.colorOn = true; } });
        near(assert, widthsOf(padded).month, longest + 2 * 3 + 2 * K.TEXT_GROW_MARGIN, 'with pad', 1e-9);
        // lower case is narrower
        const lower = await makeDoc(load, { styles: (l) => { l.styles.month.upper = false; } });
        assert.ok(widthsOf(lower).month < widthsOf(doc).month);
        // week number forms
        const wkB = await makeDoc(load, { styles: (l) => { l.styles.wk.form = 'c'; } });
        assert.ok(widthsOf(wkB).wk < widthsOf(doc).wk);
        assert.deepEqual(A.maxTextWidths(await makeDoc(load, { preset: null }), [], opts), { month: 0, wk: 0 });
    });

    t.test('E18 a larger month size grows the frame towards the side opposite the alignment anchor', async () => {
        const doc = await makeDoc(load, { styles: (l) => { l.styles.month.size = 36; } });
        const before = copy(doc.design.layout);
        const widths = widthsOf(doc);
        assert.ok(widths.month > before.boxes.month.w);
        const grown = A.autoGrow(doc.design.layout, widths, doc.design);
        assert.ok(grown);
        const b = grown.boxes.month;
        near(assert, b.x + b.w, 304, 'align end: the right edge stays', 1e-9);
        assert.ok(b.w >= widths.month - 1e-9 && b.w < widths.month + 0.11, `w ${b.w} for ${widths.month}`);
        assert.deepEqual([b.y, b.h], [before.boxes.month.y, before.boxes.month.h]);
        for (const k of Object.keys(before.boxes)) if (k !== 'month') assert.deepEqual(grown.boxes[k], before.boxes[k], k);
        assert.deepEqual(grown.styles, before.styles);
        assert.deepEqual(doc.design.layout, before, 'the argument is not mutated');
        // a second pass has nothing to do
        assert.equal(A.autoGrow(grown, widths, doc.design), null);
    });

    t.test('start grows to the right, middle to both sides', async () => {
        const right = await makeDoc(load, { preset: 'photoRight', styles: (l) => { l.styles.month.size = 30; } });
        const g1 = A.autoGrow(right.design.layout, widthsOf(right), right.design);
        assert.equal(g1.boxes.month.x, 6);
        assert.ok(g1.boxes.month.w > 101);
        const mid = await makeDoc(load, { styles: (l) => { l.styles.month.size = 19; l.styles.month.align = 'middle'; } });
        const g2 = A.autoGrow(mid.design.layout, widthsOf(mid), mid.design);
        assert.ok(g2.boxes.month.w > 101 && g2.boxes.month.x + g2.boxes.month.w < 315);
        near(assert, g2.boxes.month.x + g2.boxes.month.w / 2, 203 + 101 / 2, 'centre stays', 0.11);
    });

    t.test('the frame never shrinks and never leaves the page', async () => {
        const wide = await makeDoc(load, { styles: (l) => { l.boxes.month.x = 20; l.boxes.month.w = 284; } });
        assert.equal(A.autoGrow(wide.design.layout, widthsOf(wide), wide.design), null);
        const huge = await makeDoc(load, { styles: (l) => { l.styles.month.size = 60; } });
        const g = A.autoGrow(huge.design.layout, widthsOf(huge), huge.design);
        assert.ok(g.boxes.month.x >= -5 - 1e-9);
        assert.ok(g.boxes.month.x + g.boxes.month.w <= huge.design.page.width + 5 + 1e-9);
        const giant = A.autoGrow(huge.design.layout, { month: 5000, wk: 0 }, huge.design);
        assert.deepEqual([giant.boxes.month.x, giant.boxes.month.w], [-5, huge.design.page.width + 10]);
    });

    t.test('Číslo týdne grows too; hidden or switched-off elements do not', async () => {
        const doc = await makeDoc(load, { styles: (l) => { l.styles.wk.size = 40; l.boxes.wk.w = 20; l.boxes.wk.x = 284; } });
        const g = A.autoGrow(doc.design.layout, widthsOf(doc), doc.design);
        near(assert, g.boxes.wk.x + g.boxes.wk.w, 304, 'right edge stays', 1e-9);
        assert.ok(g.boxes.wk.w > 20);
        const hidden = await makeDoc(load, { styles: (l) => { l.styles.wk.size = 40; l.boxes.wk.w = 20; l.boxes.wk.hidden = true; l.styles.month.size = 36; l.boxes.month.hidden = true; } });
        assert.equal(A.autoGrow(hidden.design.layout, widthsOf(hidden), hidden.design), null);
        const off = await makeDoc(load, { features: { weekNumbers: false }, styles: (l) => { l.styles.wk.size = 40; l.boxes.wk.w = 20; } });
        assert.equal(A.autoGrow(off.design.layout, widthsOf(off), off.design), null);
    });
}
