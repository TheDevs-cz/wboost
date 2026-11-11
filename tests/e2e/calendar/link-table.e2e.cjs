/*
 * Propojit prvky — every row of handoff §7.4 on the default layout Fotka vlevo, each as its own
 * check, with the position and size of ALL boxes asserted before and after (handoff §9.3), driven
 * by the real pointer on the handles. Phase P4.
 *
 * Default boxes (bleed 5 mm): head −5,−5 320×32 · photo −5,27 202×123 · cal 197,27 118×112 ·
 * month 203,12.5 101×14.5 · wk 203,139 101×4.5 · cap 5,135 93×5.5 · logo 168.5,31 23.5×23.5.
 * The drag distances are chosen so that the dragged edge ends more than the snap distance away
 * from every snap line; the expected numbers are therefore exact.
 */
const assert = require('assert/strict');
const e = require('./lib/editor.cjs');

const DEFAULT = {
    head: { x: -5, y: -5, w: 320, h: 32 },
    photo: { x: -5, y: 27, w: 202, h: 123 },
    cal: { x: 197, y: 27, w: 118, h: 112 },
    month: { x: 203, y: 12.5, w: 101, h: 14.5 },
    wk: { x: 203, y: 139, w: 101, h: 4.5 },
    cap: { x: 5, y: 135, w: 93, h: 5.5 },
    logo: { x: 168.5, y: 31, w: 23.5, h: 23.5 },
};

module.exports = {
    name: 'link-table',
    checks: ['9.3 table (handoff §7.4, every row)'],
    async run({ page, h, check }) {
        let cal = null;
        let before = null;

        await check('setup: default Fotka vlevo, linking on, edges coincide as §7.4 says', async () => {
            cal = await e.newCalendar(page, h);
            before = await e.allBoxes(page, h);
            for (const [key, box] of Object.entries(DEFAULT)) h.expectBox(before[key], box, e.TOL, key);
            assert.equal((await h.state(page)).design.linkElements, true);
        });
        if (!cal) return;

        /** One row: a fresh default layout, the action, then every box compared with the expectation. */
        const row = (name, action, patch) =>
            check(name, async () => {
                await e.restore(page, h, cal.design);
                e.assertBoxes(await e.allBoxes(page, h), before, 'before');
                await action();
                e.assertBoxes(await e.allBoxes(page, h), e.withBoxes(before, patch), 'after');
            });

        await row('row 1: photo right handle to the left → kalendárium widens, its right edge stays', () => e.resize(page, h, 'photo', 'e', -37, 0), {
            photo: { w: 165 },
            cal: { x: 160, w: 155 },
        });

        await row('row 2: kalendárium left handle to the right → photo widens', () => e.resize(page, h, 'cal', 'w', 30, 0), {
            cal: { x: 227, w: 88 },
            photo: { w: 232 },
        });

        await row('row 3: header bottom handle down → photo and kalendárium start lower, month frame gets taller', () => e.resize(page, h, 'head', 's', 0, 12), {
            head: { h: 44 },
            photo: { y: 39, h: 111 },
            cal: { y: 39, h: 100 },
            month: { h: 26.5 },
        });

        await row('row 4: kalendárium top handle down → photo top, header bottom and month bottom follow', () => e.resize(page, h, 'cal', 'n', 0, 12), {
            cal: { y: 39, h: 100 },
            photo: { y: 39, h: 111 },
            head: { h: 44 },
            month: { h: 26.5 },
        });

        await row('row 5: kalendárium bottom handle up → week number top follows, its bottom stays', () => e.resize(page, h, 'cal', 's', 0, -20), {
            cal: { h: 92 },
            wk: { y: 119, h: 24.5 },
        });

        // 10 mm only: the month frame must stay wider than „PROSINEC 2026 / LEDEN 2027“ in whatever font the
        // project has (fonts.e2e.cjs leaves real fonts in the e2e project), or auto-grow widens it again
        await row('row 6: month left handle → week number left edge follows', () => e.resize(page, h, 'month', 'w', 10, 0), {
            month: { x: 213, w: 91 },
            wk: { x: 213, w: 91 },
        });

        await row('row 7a: photo left handle inwards → only the photo (edge started in the bleed)', () => e.resize(page, h, 'photo', 'w', 30, 0), {
            photo: { x: 25, w: 172 },
        });

        await row('row 7b: photo bottom handle inwards → only the photo', () => e.resize(page, h, 'photo', 's', 0, -30), {
            photo: { h: 93 },
        });

        await row('row 8a: logo handles → only the logo', async () => {
            await e.resize(page, h, 'logo', 'e', 20, 0);
            await e.resize(page, h, 'logo', 'n', 0, 12);
        }, {
            logo: { y: 43, w: 43.5, h: 11.5 },
        });

        await row('row 8b: caption handles → only the caption', async () => {
            await e.resize(page, h, 'cap', 'n', 0, -10);
            await e.resize(page, h, 'cap', 'e', 30, 0);
        }, {
            cap: { y: 125, h: 15.5, w: 123 },
        });

        await row('row 9: kalendárium top-left corner → photo follows horizontally; header, photo and month vertically', () => e.resize(page, h, 'cal', 'nw', 30, 12), {
            cal: { x: 227, y: 39, w: 88, h: 100 },
            photo: { y: 39, w: 232, h: 111 },
            head: { h: 44 },
            month: { h: 26.5 },
        });

        await row('row 10: moving the kalendárium by its body → only the kalendárium moves', async () => {
            const from = e.centre(before.cal);
            await h.dragMm(page, from, { x: from.x - 20, y: from.y - 10 });
        }, {
            cal: { x: 177, y: 17 },
        });

        await check('row 11: week number hidden → kalendárium bottom handle changes only the kalendárium; shown again it is where it was', async () => {
            await e.restore(page, h, cal.design);
            await page.click('[data-layer-eye="wk"]');
            const hidden = await e.allBoxes(page, h);
            assert.equal(hidden.wk.hidden, true);
            assert.equal((await h.boxes(page)).wk, undefined, 'a hidden element is not a visible box');
            await e.resize(page, h, 'cal', 's', 0, -20);
            e.assertBoxes(await e.allBoxes(page, h), e.withBoxes(hidden, { cal: { h: 92 } }), 'after the drag');
            await page.click('[data-layer-eye="wk"]');
            e.assertBoxes(await e.allBoxes(page, h), e.withBoxes(before, { cal: { h: 92 } }), 'after showing the week number again');
        });

        await check('row 12: linking off → only the photo changes, a gap opens', async () => {
            await e.restore(page, h, cal.design);
            await page.click('[data-cal-action="link"]');
            assert.equal((await h.state(page)).design.linkElements, false);
            await e.resize(page, h, 'photo', 'e', -37, 0);
            e.assertBoxes(await e.allBoxes(page, h), e.withBoxes(before, { photo: { w: 165 } }), 'after');
        });

        await check('row 13: gap open, linking on again → no follow until the edges meet; after snapping together they move as one', async () => {
            // continues row 12: photo right edge 160, kalendárium left edge 197
            await page.click('[data-cal-action="link"]');
            assert.equal((await h.state(page)).design.linkElements, true);
            await e.resize(page, h, 'photo', 'e', -10, 0);
            e.assertBoxes(await e.allBoxes(page, h), e.withBoxes(before, { photo: { w: 155 } }), 'the kalendárium does not follow across the gap');
            // 150 → 196: one millimetre short of the kalendárium's edge, so the snap closes the gap
            await e.resize(page, h, 'photo', 'e', 46, 0);
            e.assertBoxes(await e.allBoxes(page, h), before, 'snapped back onto the shared line');
            await e.resize(page, h, 'photo', 'e', -37, 0);
            e.assertBoxes(await e.allBoxes(page, h), e.withBoxes(before, { photo: { w: 165 }, cal: { x: 160, w: 155 } }), 'linked again');
        });

        await h.deleteCalendar(page, cal.id);
    },
};
