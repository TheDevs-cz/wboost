/*
 * Editor — Kalendárium parts on the stage (handoff §6.2 "Moving a part", §8): E14 (part offset drag,
 * snap-back, Vrátit na původní místo) and the two frames of the 14-day layout (selection of each
 * frame, the part highlighted in both, the mirrored offset). Real pointer. Phase P4.
 * The part panels and the event-box / icon-cell bars belong to P5b (kalendarium.e2e.cjs).
 */
const assert = require('assert/strict');
const e = require('./lib/editor.cjs');

const RESET = '[data-cal-action="offset-reset"]';
const offsets = async (page, h) => (await h.state(page)).design.layout.styles.offsets;
/** `{ date: rect }` of one part in one frame of a page (default: the page on the stage). */
const partRects = (page, h, part, frame = 0, pageIndex) =>
    h.cal(
        page,
        (c, key, f, index) => {
            const out = {};
            for (const row of c.geometry(index === null ? undefined : index).frames[f].rows) {
                const found = row.parts.find((p) => p.key === key);
                if (found) out[row.date] = found.rect;
            }
            return out;
        },
        part,
        frame,
        pageIndex === undefined ? null : pageIndex,
    );

function assertShift(before, after, dx, dy, label) {
    const dates = Object.keys(before);
    assert.ok(dates.length > 0, `${label}: the part is drawn`);
    assert.deepEqual(Object.keys(after), dates, `${label}: same rows`);
    for (const date of dates) {
        assert.ok(Math.abs(after[date].x - before[date].x - dx) <= 0.051, `${label} ${date}: x moved ${after[date].x - before[date].x}, expected ${dx}`);
        assert.ok(Math.abs(after[date].y - before[date].y - dy) <= 0.051, `${label} ${date}: y moved ${after[date].y - before[date].y}, expected ${dy}`);
    }
}

const firstRect = (rects) => rects[Object.keys(rects)[0]];

module.exports = {
    name: 'editor-parts',
    checks: ['E14', '14-day frames'],
    async run({ page, h, check }) {
        const created = [];
        let cal = null;

        await check('E14 the part Jména dragged 3 mm to the right shifts in every row and on every week page; the hint offers Vrátit na původní místo', async () => {
            cal = await e.newCalendar(page, h);
            created.push(cal.id);
            const boxes = await e.allBoxes(page, h);
            await page.click('[data-layer="cal"]');
            await page.click('[data-part-row="name"]');
            assert.equal(await page.$(RESET), null, 'nothing to reset yet');
            const names = await partRects(page, h, 'name');
            const days = await partRects(page, h, 'day');
            const other = await h.cal(page, (c) => c.pages().filter((p) => p.kind === 'week')[9].index);
            const namesOther = await partRects(page, h, 'name', 0, other);
            const count = Object.keys(names).length;
            assert.ok(count >= 5, 'most rows of the sample week carry a name day');
            assert.equal(await page.$$eval('[data-part-box]', (n) => n.length), count, 'one highlight per drawn name');

            const from = e.centre(firstRect(names));
            await h.dragMm(page, from, { x: from.x + 3, y: from.y }, { hold: true });
            assert.deepEqual(await offsets(page, h), { name: { x: 3, y: 0 } }, 'live while the button is down');
            assertShift(names, await partRects(page, h, 'name'), 3, 0, 'mid-drag');
            const marked = await page.$$eval('[data-part-box]', (n) => n.map((x) => x.getBoundingClientRect().left));
            const first = await e.svgRectOf(page, { ...firstRect(await partRects(page, h, 'name')) });
            assert.ok(Math.abs(marked[0] - (first.x - 0.5 * (await h.cal(page, (c) => c.scale())))) <= 1, 'the highlight boxes travel with the part');
            assert.equal((await h.state(page)).canUndo, false);
            await e.release(page);

            assert.deepEqual(await offsets(page, h), { name: { x: 3, y: 0 } });
            assert.deepEqual(await e.selection(page, h), { el: 'cal', frame: 0, part: 'name' }, 'the part stays selected');
            assertShift(names, await partRects(page, h, 'name'), 3, 0, 'names');
            assertShift(days, await partRects(page, h, 'day'), 0, 0, 'weekday names stay');
            assertShift(namesOther, await partRects(page, h, 'name', 0, other), 3, 0, 'names on another week page');
            e.assertBoxes(await e.allBoxes(page, h), boxes, 'no element moved');
            assert.equal(await page.$eval('.cal-hint', (el) => el.textContent.replace(/\s+/g, ' ').trim()), 'Táhněte pro posun ve všech řádcích · Esc = zpět na celé kalendárium · Vrátit na původní místo');
            assert.equal((await h.state(page)).canUndo, true);

            await h.save(page);
            await h.reload(page);
            assert.deepEqual(await offsets(page, h), { name: { x: 3, y: 0 } }, 'after reload');
            assertShift(names, await partRects(page, h, 'name'), 3, 0, 'names after reload');

            await page.click('[data-layer="cal"]');
            await page.click('[data-part-row="name"]');
            await page.click(RESET);
            assert.deepEqual(await offsets(page, h), {}, 'Vrátit na původní místo clears the offset');
            assertShift(names, await partRects(page, h, 'name'), 0, 0, 'names restored');
            assert.equal(await page.$(RESET), null, 'the button is gone again');
            await e.pressMod(page, 'z');
            assert.deepEqual(await offsets(page, h), { name: { x: 3, y: 0 } }, 'the reset is one undo step');
            await e.pressMod(page, 'z', { shift: true });
            assert.deepEqual(await offsets(page, h), {});
        });
        if (!cal) return;

        await check('a part snaps back to its original place when dragged close to it — unless Alt is held; both axes; one undo step per drag', async () => {
            await e.restore(page, h, cal.design);
            await page.click('[data-layer="cal"]');
            await page.click('[data-part-row="day"]');
            const start = e.centre(firstRect(await partRects(page, h, 'day')));
            await h.dragMm(page, start, { x: start.x - 4, y: start.y + 1 });
            assert.deepEqual(await offsets(page, h), { day: { x: -4, y: 1 } });
            // back to 0.2 mm from the origin on both axes: snaps home, the offset disappears
            await h.dragMm(page, { x: start.x - 4, y: start.y + 1 }, { x: start.x - 0.2, y: start.y + 0.2 });
            assert.deepEqual(await offsets(page, h), {}, 'snapped back');
            assert.equal(await page.$(RESET), null);
            await e.pressMod(page, 'z');
            assert.deepEqual(await offsets(page, h), { day: { x: -4, y: 1 } }, 'undo of the second drag');
            // the same drag with Alt keeps the small offset
            await h.dragMm(page, { x: start.x - 4, y: start.y + 1 }, { x: start.x - 0.2, y: start.y + 0.2 }, { alt: true });
            assert.deepEqual(await offsets(page, h), { day: { x: -0.2, y: 0.2 } }, 'no snap-back with Alt');
            // one axis home, the other not
            await page.click(RESET);
            await h.dragMm(page, start, { x: start.x + 0.1, y: start.y - 3 });
            assert.deepEqual(await offsets(page, h), { day: { x: 0, y: -3 } });
            // Esc during the drag puts the part back
            await h.dragMm(page, { x: start.x, y: start.y - 3 }, { x: start.x + 6, y: start.y - 1 }, { hold: true });
            assert.deepEqual(await offsets(page, h), { day: { x: 6, y: -1 } });
            await page.keyboard.press('Escape');
            assert.deepEqual(await offsets(page, h), { day: { x: 0, y: -3 } });
            await page.mouse.up();
            assert.deepEqual(await e.selection(page, h), { el: 'cal', frame: 0, part: 'day' });
        });

        await check('the four number kinds move separately; arrow keys do not move a part', async () => {
            await e.restore(page, h, cal.design);
            await page.click('[data-layer="cal"]');
            await page.click('[data-part-row="numSat"]');
            const sat = await partRects(page, h, 'numSat');
            const work = await partRects(page, h, 'num');
            assert.equal(Object.keys(sat).length, 1, 'one Saturday in the sample week');
            const from = e.centre(firstRect(sat));
            await h.dragMm(page, from, { x: from.x - 5, y: from.y });
            assert.deepEqual(await offsets(page, h), { numSat: { x: -5, y: 0 } });
            assertShift(work, await partRects(page, h, 'num'), 0, 0, 'workday numbers stay');
            await page.keyboard.press('ArrowRight');
            assert.deepEqual(await offsets(page, h), { numSat: { x: -5, y: 0 } });
        });

        await check('14 dní: each frame is selected on its own, the part is highlighted in both, an offset is mirrored in the second frame', async () => {
            const two = await e.newCalendar(page, h, { type: 'fortnight' });
            created.push(two.id);
            const boxes = await e.allBoxes(page, h);
            await e.clickMm(page, h, e.centre(boxes.cal2));
            assert.deepEqual(await e.selection(page, h), { el: 'cal', frame: 1 });
            assert.equal(await page.$eval('[data-frame-tag]', (el) => el.textContent), 'Kalendárium · 2. týden');
            e.assertRect(await e.frameRect(page), await e.svgRectOf(page, boxes.cal2), 'frame = second week');
            const dim = await page.$eval('.cal-ovl > .cal-sel.other', (el) => (el.hidden ? null : { x: el.getBoundingClientRect().left, y: el.getBoundingClientRect().top, w: el.getBoundingClientRect().width, h: el.getBoundingClientRect().height }));
            e.assertRect(dim, await e.svgRectOf(page, boxes.cal), 'the other frame gets a thin outline');
            assert.equal(await e.visibleHandles(page), 8);
            await e.clickMm(page, h, e.centre(boxes.cal));
            assert.deepEqual(await e.selection(page, h), { el: 'cal', frame: 0 });
            assert.equal(await page.$eval('[data-frame-tag]', (el) => el.textContent), 'Kalendárium · 1. týden');

            // moving and nudging one frame leaves the other alone
            await e.clickMm(page, h, e.centre(boxes.cal2));
            await page.keyboard.down('Shift');
            await page.keyboard.press('ArrowUp');
            await page.keyboard.up('Shift');
            e.assertBoxes(await e.allBoxes(page, h), e.withBoxes(boxes, { cal2: { y: 24 } }), 'only the second week moved');
            await e.pressMod(page, 'z');

            // the part: 14 highlights, in both frames
            const names1 = await partRects(page, h, 'name', 0);
            const names2 = await partRects(page, h, 'name', 1);
            const from = e.centre(firstRect(names2));
            await e.clickMm(page, h, from);
            assert.deepEqual(await e.selection(page, h), { el: 'cal', frame: 1, part: 'name' });
            assert.equal(await page.$$eval('[data-part-box]', (n) => n.length), Object.keys(names1).length + Object.keys(names2).length, 'one highlight per drawn name, in both weeks');
            assert.ok(Object.keys(names1).length >= 5 && Object.keys(names2).length >= 5);
            assert.deepEqual(await page.$$eval('[data-part-box]', (n) => [...new Set(n.map((x) => x.dataset.frame))]), ['0', '1']);
            assert.equal(await page.$eval('[data-frame-tag]', (el) => el.textContent), 'Kalendárium › Jména');
            // dragged 3 mm to the right in the mirrored second frame: stored as −3 for frame 1
            await h.dragMm(page, from, { x: from.x + 3, y: from.y + 1 });
            assert.deepEqual(await offsets(page, h), { name: { x: -3, y: 1 } });
            assertShift(names2, await partRects(page, h, 'name', 1), 3, 1, 'second week follows the pointer');
            assertShift(names1, await partRects(page, h, 'name', 0), -3, 1, 'first week mirrors it');
            // the same part dragged in the first frame
            const left = e.centre(firstRect(await partRects(page, h, 'name', 0)));
            await h.dragMm(page, left, { x: left.x + 5, y: left.y });
            assert.deepEqual(await offsets(page, h), { name: { x: 2, y: 1 } });
            assert.equal((await e.selection(page, h)).frame, 0, 'the frame that was dragged is the selected one');
            await h.save(page);
            await h.reload(page);
            assert.deepEqual(await offsets(page, h), { name: { x: 2, y: 1 } });
        });

        for (const id of created) await h.deleteCalendar(page, id);
    },
};
