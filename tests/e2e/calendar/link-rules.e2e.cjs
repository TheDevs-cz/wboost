/*
 * Propojit prvky — L1–L9 of handoff §9.3 with the real pointer, plus the first row of §7.4 in
 * Fotka vpravo and in the 14-day layout (L8). Phase P4.
 */
const assert = require('assert/strict');
const e = require('./lib/editor.cjs');

const linkPressed = (page) => page.$eval('[data-cal-action="link"]', (el) => el.getAttribute('aria-pressed'));

module.exports = {
    name: 'link-rules',
    checks: ['L1', 'L2', 'L3', 'L4', 'L5', 'L6', 'L7', 'L8', 'L9'],
    async run({ page, h, check }) {
        const created = [];
        let cal = null;
        let before = null;

        await check('L1 new calendar: the toggle is on', async () => {
            cal = await e.newCalendar(page, h);
            created.push(cal.id);
            before = await e.allBoxes(page, h);
            assert.equal(await linkPressed(page), 'true');
            assert.equal((await h.state(page)).design.linkElements, true);
            assert.match(await page.$eval('[data-cal-action="link"]', (el) => el.title), /^Zapnuto: změna velikosti prvku posune i prvky, které s ním sousedí \(fotka, kalendárium, hlavička\)\. Vypnuto: každý prvek zvlášť\.$/);
            assert.equal(await page.$eval('[data-cal-action="link"]', (el) => el.textContent.trim()), 'Propojit prvky');
        });
        if (!cal) return;

        await check('L2 switching shows a toast, changes nothing on the page and survives a reload', async () => {
            await page.click('[data-cal-action="link"]');
            await h.waitToast(page, 'Prvky odpojené — každý zvlášť');
            assert.equal(await linkPressed(page), 'false');
            e.assertBoxes(await e.allBoxes(page, h), before, 'toggling moves nothing');
            assert.equal((await h.state(page)).canUndo, false, 'the toggle is not a layout step');
            await h.save(page);
            await h.reload(page);
            assert.equal((await h.state(page)).design.linkElements, false);
            assert.equal(await linkPressed(page), 'false');
            await page.click('[data-cal-action="link"]');
            await h.waitToast(page, 'Prvky propojené — sousedé se přizpůsobí');
            assert.equal(await linkPressed(page), 'true');
            await h.save(page);
        });

        await check('L3 photo right handle 40 mm left, Ctrl+Z once → photo and kalendárium both back', async () => {
            await e.restore(page, h, cal.design);
            await e.resize(page, h, 'photo', 'e', -40, 0);
            const after = await e.allBoxes(page, h);
            // 157 mm lies within the snap distance of the page centre at this zoom
            const edge = after.photo.x + after.photo.w;
            assert.ok(Math.abs(edge - 157) <= 2.7, `photo edge near 157, got ${edge}`);
            e.assertBoxes(after, e.withBoxes(before, { photo: { w: edge + 5 }, cal: { x: edge, w: 315 - edge } }), 'after the drag');
            await e.pressMod(page, 'z');
            e.assertBoxes(await e.allBoxes(page, h), before, 'one undo restores A and the follower');
            assert.equal((await h.state(page)).canUndo, false);
        });

        await check('L4 drag out and back without releasing → exactly as before, no drift, no undo step', async () => {
            await e.restore(page, h, cal.design);
            await e.selectLayer(page, h, 'photo');
            const start = await e.handleCentre(page, 'e');
            await h.dragHandle(page, 'e', -37, 0, { hold: true });
            e.assertBoxes(await e.allBoxes(page, h), e.withBoxes(before, { photo: { w: 165 }, cal: { x: 160, w: 155 } }), 'mid-drag');
            await page.mouse.move(start.x - 60, start.y + 9, { steps: 5 });
            await page.mouse.move(start.x, start.y, { steps: 8 });
            assert.deepEqual(await e.allBoxes(page, h), before, 'back at the start, still holding');
            await e.release(page);
            assert.deepEqual(await e.allBoxes(page, h), before, 'after release');
            const s = await h.state(page);
            assert.equal(s.canUndo, false, 'a drag that changed nothing is not an undo step');
            assert.equal(s.status, 'clean');
        });

        await check('L5 photo right handle as far right as it goes → kalendárium stops at its minimum, photo continues; back restores both', async () => {
            await e.restore(page, h, cal.design);
            await e.selectLayer(page, h, 'photo');
            const start = await e.handleCentre(page, 'e');
            await h.dragHandle(page, 'e', 200, 0, { hold: true });
            e.assertBoxes(await e.allBoxes(page, h), e.withBoxes(before, { photo: { w: 320 }, cal: { x: 309, w: 6 } }), 'at the limit');
            await page.mouse.move(start.x, start.y, { steps: 10 });
            assert.deepEqual(await e.allBoxes(page, h), before, 'dragging back restores the follower that hit its minimum');
            await e.release(page);
            assert.deepEqual(await e.allBoxes(page, h), before);
        });

        await check('L6 Alt held: no snapping, the kalendárium still follows', async () => {
            await e.restore(page, h, cal.design);
            await e.selectLayer(page, h, 'photo');
            await h.dragHandle(page, 'e', -40, 0, { alt: true, hold: true });
            assert.equal(await page.$$eval('[data-guide]', (n) => n.length), 0, 'no guide with Alt');
            await e.release(page, true);
            e.assertBoxes(await e.allBoxes(page, h), e.withBoxes(before, { photo: { w: 162 }, cal: { x: 157, w: 158 } }), 'exactly 40 mm, linked');
        });

        await check('L7 header bottom handle: followers redraw live during the drag', async () => {
            await e.restore(page, h, cal.design);
            await e.selectLayer(page, h, 'head');
            const top = (selector) => page.$eval(selector, (el) => el.getBoundingClientRect().top);
            const photoTop = await top('.cal-page-svg [data-el="photo"]');
            await h.dragHandle(page, 's', 0, 12, { hold: true });
            const expected = e.withBoxes(before, { head: { h: 44 }, photo: { y: 39, h: 111 }, cal: { y: 39, h: 100 }, month: { h: 26.5 } });
            e.assertBoxes(await e.allBoxes(page, h), expected, 'mid-drag state');
            const scale = await h.cal(page, (c) => c.scale());
            const moved = (await top('.cal-page-svg [data-el="photo"]')) - photoTop;
            assert.ok(Math.abs(moved - 12 * scale) < 1.5, `the photo on the page moved ${moved}px while the button is down (expected ${12 * scale})`);
            const line = await h.cal(page, (c) => c.toClient(0, 39));
            const headBottom = await page.$eval('.cal-page-svg [data-el="head"]', (el) => el.getBoundingClientRect().bottom);
            assert.ok(Math.abs(headBottom - line.y) < 1.5, 'the header on the page ends at the dragged line');
            assert.equal((await h.state(page)).canUndo, false, 'nothing is committed before release');
            await e.release(page);
            e.assertBoxes(await e.allBoxes(page, h), expected, 'after release');
            await e.pressMod(page, 'z');
            e.assertBoxes(await e.allBoxes(page, h), before, 'one undo step for A and every follower');
        });

        await check('L9 arrow keys never link; with linking off a resize changes only the photo', async () => {
            await e.restore(page, h, cal.design);
            await e.selectLayer(page, h, 'cal');
            await page.keyboard.press('ArrowLeft');
            await page.keyboard.down('Shift');
            await page.keyboard.press('ArrowDown');
            await page.keyboard.up('Shift');
            e.assertBoxes(await e.allBoxes(page, h), e.withBoxes(before, { cal: { x: 196.7, y: 30 } }), 'nudging the kalendárium moves only the kalendárium');
        });

        await check('L9 typed X / Y move one element; typed Š takes the neighbour along, not with linking off', async () => {
            await e.restore(page, h, cal.design);
            await e.selectLayer(page, h, 'logo');
            const type = async (field, value) => {
                await page.$eval(`[data-field="${field}"]`, (el) => {
                    el.focus();
                    el.select();
                });
                await page.keyboard.type(value);
                await page.keyboard.press('Enter');
            };
            await type('x', '100');
            await type('y', '60');
            e.assertBoxes(await e.allBoxes(page, h), e.withBoxes(before, { logo: { x: 100, y: 60 } }), 'typed X and Y');
            await e.selectLayer(page, h, 'photo');
            await type('w', '170');
            e.assertBoxes(await e.allBoxes(page, h), e.withBoxes(before, { logo: { x: 100, y: 60 }, photo: { w: 170 }, cal: { x: 165, w: 150 } }), 'typed Š links like a handle');
            await page.click('[data-cal-action="link"]');
            await type('w', '150');
            e.assertBoxes(await e.allBoxes(page, h), e.withBoxes(before, { logo: { x: 100, y: 60 }, photo: { w: 150 }, cal: { x: 165, w: 150 } }), 'linking off: only the photo');
        });

        await check('L8 Fotka vpravo: the photo edge facing the kalendárium takes it along', async () => {
            const right = await e.newCalendar(page, h, { preset: 'photoRight' });
            created.push(right.id);
            const b = await e.allBoxes(page, h);
            h.expectBox(b.photo, { x: 113, y: 27, w: 202, h: 123 }, e.TOL, 'photo');
            h.expectBox(b.cal, { x: -5, y: 27, w: 118, h: 112 }, e.TOL, 'cal');
            await e.resize(page, h, 'photo', 'w', 37, 0);
            e.assertBoxes(await e.allBoxes(page, h), e.withBoxes(b, { photo: { x: 150, w: 165 }, cal: { w: 155 } }), 'after');
        });

        await check('L8 14 dní: the photo edges take the neighbouring week along, each frame on its own', async () => {
            const two = await e.newCalendar(page, h, { type: 'fortnight' });
            created.push(two.id);
            const b = await e.allBoxes(page, h);
            h.expectBox(b.cal, { x: -5, y: 27, w: 89, h: 112 }, e.TOL, 'cal');
            h.expectBox(b.photo, { x: 84, y: 27, w: 142, h: 112 }, e.TOL, 'photo');
            h.expectBox(b.cal2, { x: 226, y: 27, w: 89, h: 112 }, e.TOL, 'cal2');
            await e.resize(page, h, 'photo', 'e', -37, 0);
            e.assertBoxes(await e.allBoxes(page, h), e.withBoxes(b, { photo: { w: 105 }, cal2: { x: 189, w: 126 } }), 'right edge → second week');
            await e.resize(page, h, 'photo', 'w', 20, 0);
            e.assertBoxes(await e.allBoxes(page, h), e.withBoxes(b, { photo: { x: 104, w: 85 }, cal2: { x: 189, w: 126 }, cal: { w: 109 } }), 'left edge → first week');
        });

        for (const id of created) await h.deleteCalendar(page, id);
    },
};
