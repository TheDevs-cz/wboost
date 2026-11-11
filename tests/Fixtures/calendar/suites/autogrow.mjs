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
        }
    });

    t.test('maxTextWidths measures every week page with the style applied', async () => {
        const doc = await makeDoc(load);
        const font = { family: '"Outfit", Arial, sans-serif', weight: 700, sizeMm: ptToMm(15), letterSpacingMm: 0.09 };
        const longest = Math.max(...buildPages(doc).filter((p) => p.kind === 'week').map((p) => m.width(p.week.monthText, font)));
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
