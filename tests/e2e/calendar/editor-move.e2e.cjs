/*
 * Editor — moving, resizing, snapping, nudging, limits, zoom, window resize (handoff §5.3–§5.6,
 * §5.9, §8): E3–E9, E20, alignment of frame and handles at every zoom and window size. Real
 * pointer; every data-changing scenario saves, reloads and asserts again. Phase P4.
 *
 * Default boxes (bleed 5 mm): logo 168.5,31 23.5×23.5 · cap 5,135 93×5.5 · photo −5,27 202×123.
 */
const assert = require('assert/strict');
const e = require('./lib/editor.cjs');

const guides = (page) => page.$$eval('[data-guide]', (nodes) => nodes.map((n) => ({ axis: n.dataset.guide, pos: Number(n.dataset.pos), x: n.getBoundingClientRect().left, y: n.getBoundingClientRect().top, w: n.getBoundingClientRect().width, h: n.getBoundingClientRect().height })));

/** Scrolls the stage so that a trim-relative point sits in the middle of the visible stage. */
async function scrollTo(page, h, pt) {
    const p = await h.cal(page, (c, q) => c.toClient(q.x, q.y), pt);
    await page.$eval('.cal-cvw', (el, target) => {
        const r = el.getBoundingClientRect();
        el.scrollLeft += target.x - (r.left + r.width / 2);
        el.scrollTop += target.y - (r.top + r.height / 2);
    }, p);
}

async function zoomTo(page, h, percent) {
    for (let i = 0; i < 12; i++) {
        const now = Math.round((await h.state(page)).zoom * 100);
        if (now === percent) return;
        await page.click(now < percent ? '[data-cal-action="zoom-in"]' : '[data-cal-action="zoom-out"]');
    }
    assert.fail(`zoom ${percent} % not reached`);
}

/** Frame and corner handles of the selected element against the page SVG itself. */
async function assertAligned(page, h, box, label) {
    const expected = await e.svgRectOf(page, box);
    e.assertRect(await e.frameRect(page), expected, `${label}: frame`);
    const nw = await e.handleCentre(page, 'nw');
    const se = await e.handleCentre(page, 'se');
    const east = await e.handleCentre(page, 'e');
    assert.ok(Math.abs(nw.x - expected.x) <= 1 && Math.abs(nw.y - expected.y) <= 1, `${label}: nw handle on the corner`);
    assert.ok(Math.abs(se.x - (expected.x + expected.w)) <= 1 && Math.abs(se.y - (expected.y + expected.h)) <= 1, `${label}: se handle on the corner`);
    assert.ok(Math.abs(east.x - (expected.x + expected.w)) <= 1 && Math.abs(east.y - (expected.y + expected.h / 2)) <= 1, `${label}: e handle on the edge centre`);
    // the test hook answers with the same geometry
    const hook = await h.cal(page, (c, b) => c.toClient(b.x, b.y), box);
    assert.ok(Math.abs(hook.x - expected.x) <= 0.6 && Math.abs(hook.y - expected.y) <= 0.6, `${label}: toClient`);
}

module.exports = {
    name: 'editor-move',
    checks: ['E3', 'E4', 'E5', 'E6', 'E7', 'E8', 'E9', 'E20', '§8 window resize'],
    async run({ page, h, check }) {
        let cal = null;
        let before = null;

        await check('setup', async () => {
            cal = await e.newCalendar(page, h);
            before = await e.allBoxes(page, h);
        });
        if (!cal) return;
        const logo = () => e.centre(before.logo);

        await check('E3 drag the logo 30 mm to the left: follows the pointer, size kept, on every week page, survives a reload', async () => {
            const from = logo();
            await h.dragMm(page, from, { x: from.x - 30, y: from.y }, { hold: true });
            // it follows the pointer while the button is down
            const mid = (await e.allBoxes(page, h)).logo;
            h.expectBox(mid, { x: 138.5, y: 31, w: 23.5, h: 23.5 }, e.TOL, 'logo mid-drag');
            e.assertRect(await e.frameRect(page), await e.svgRectOf(page, mid), 'the frame travels with the logo');
            const pointer = await h.cal(page, (c, p) => c.toClient(p.x, p.y), { x: from.x - 30, y: from.y });
            const frame = await e.frameRect(page);
            assert.ok(Math.abs(frame.x + frame.w / 2 - pointer.x) < 1.5, 'the grabbed point stays under the pointer');
            await e.release(page);
            const expected = e.withBoxes(before, { logo: { x: 138.5 } });
            e.assertBoxes(await e.allBoxes(page, h), expected, 'after the drag');
            assert.equal((await e.selection(page, h)).el, 'logo');
            assert.equal(await page.$eval('[data-field="x"]', (el) => el.value), '138,5', 'X in the panel dropped by 30 mm');
            await h.save(page);
            await h.reload(page);
            e.assertBoxes(await e.allBoxes(page, h), expected, 'after reload');
            const pages = await h.cal(page, (c) => c.pages().filter((p) => p.kind === 'week').map((p) => p.index));
            for (const index of [pages[0], pages[7], pages[pages.length - 1]]) {
                h.expectBox((await h.cal(page, (c, i) => c.geometry(i), index)).boxes.logo, expected.logo, e.TOL, `logo on page ${index}`);
            }
            const drawn = await page.$eval('.cal-page-svg [data-el="logo"]', (el) => el.getBoundingClientRect().left);
            assert.ok(Math.abs(drawn - (await e.svgRectOf(page, expected.logo)).x) < 2, 'the logo is drawn at the new place');
        });

        await check('E4 near the vertical page centre the logo snaps and a guide shows; released: gone; with Alt: no snap, no guide', async () => {
            await e.restore(page, h, cal.design);
            const from = logo();
            const { width, height } = await h.cal(page, (c) => c.page());
            // centre 180.25 → 155.75: within the snap distance of the page centre (155)
            await h.dragMm(page, from, { x: from.x - 24.5, y: from.y }, { steps: 40, hold: true });
            let box = (await e.allBoxes(page, h)).logo;
            assert.ok(Math.abs(box.x + box.w / 2 - width / 2) <= 0.051, `snapped: logo centre ${box.x + box.w / 2}`);
            let lines = await guides(page);
            assert.equal(lines.length, 1);
            assert.deepEqual([lines[0].axis, lines[0].pos], ['x', width / 2]);
            const centreLine = await e.svgRectOf(page, { x: width / 2, y: 0, w: 0, h: height });
            assert.ok(Math.abs(lines[0].x + lines[0].w / 2 - centreLine.x) <= 1, 'the guide is on the page centre');
            assert.ok(Math.abs(lines[0].y - centreLine.y) <= 1 && Math.abs(lines[0].h - centreLine.h) <= 1.5, 'the guide spans the trim height');
            assert.ok(lines[0].w <= 1.5, 'a thin line');
            await e.release(page);
            assert.equal((await guides(page)).length, 0, 'guides disappear on release');
            assert.deepEqual((await h.state(page)).selection, { el: 'logo' });
            e.assertBoxes(await e.allBoxes(page, h), e.withBoxes(before, { logo: { x: box.x } }), 'only the logo moved');

            await e.restore(page, h, cal.design);
            await h.dragMm(page, from, { x: from.x - 24.5, y: from.y }, { steps: 40, alt: true, hold: true });
            box = (await e.allBoxes(page, h)).logo;
            h.expectBox(box, { x: 144, y: 31, w: 23.5, h: 23.5 }, e.TOL, 'no snap with Alt');
            assert.equal((await guides(page)).length, 0, 'no guide with Alt');
            await e.release(page, true);
            e.assertBoxes(await e.allBoxes(page, h), e.withBoxes(before, { logo: { x: 144 } }), 'after the Alt drag');
        });

        await check('E5 the logo edge snaps to the photo edge, with a guide', async () => {
            await e.restore(page, h, cal.design);
            const from = logo();
            // right edge 192 → 196, one millimetre short of the photo's right edge (197)
            await h.dragMm(page, from, { x: from.x + 4, y: from.y }, { steps: 20, hold: true });
            const box = (await e.allBoxes(page, h)).logo;
            h.expectBox(box, { x: 173.5, y: 31, w: 23.5, h: 23.5 }, e.TOL, 'logo right edge on the photo edge');
            const lines = await guides(page);
            assert.deepEqual(lines.map((g) => [g.axis, g.pos]), [['x', 197]]);
            await e.release(page);
            assert.equal((await guides(page)).length, 0);
            // vertical: the logo top (31) approaches the header bottom / photo top (27)
            await e.restore(page, h, cal.design);
            await h.dragMm(page, from, { x: from.x, y: from.y - 3 }, { steps: 20, hold: true });
            h.expectBox((await e.allBoxes(page, h)).logo, { x: 168.5, y: 27 }, e.TOL, 'logo top on the photo top');
            assert.deepEqual((await guides(page)).map((g) => [g.axis, g.pos]), [['y', 27]]);
            await e.release(page);
        });

        await check('resizing snaps only the dragged edge, to the band line and to other elements', async () => {
            await e.restore(page, h, cal.design);
            await e.selectLayer(page, h, 'logo');
            // top edge 31 → 13.3: within reach of the lower edge of the binding band (12.5)
            await h.dragHandle(page, 'n', 0, -17.7, { hold: true });
            h.expectBox((await e.allBoxes(page, h)).logo, { x: 168.5, y: 12.5, w: 23.5, h: 42 }, e.TOL, 'top edge on the band line, bottom fixed');
            assert.deepEqual((await guides(page)).map((g) => [g.axis, g.pos]), [['y', 12.5]]);
            await e.release(page);
            // right edge 192 → 202: one millimetre short of the month's left edge (203)
            await h.dragHandle(page, 'e', 10, 0);
            e.assertBoxes(await e.allBoxes(page, h), e.withBoxes(before, { logo: { y: 12.5, h: 42, w: 34.5 } }), 'right edge on the month edge');
        });

        await check('E6 right arrow moves one step (0.3 mm), Shift + right arrow ten (3 mm); each is one undo step', async () => {
            await e.restore(page, h, cal.design);
            await e.selectLayer(page, h, 'logo');
            await page.keyboard.press('ArrowRight');
            e.assertBoxes(await e.allBoxes(page, h), e.withBoxes(before, { logo: { x: 168.8 } }), 'one step');
            await page.keyboard.down('Shift');
            await page.keyboard.press('ArrowRight');
            await page.keyboard.up('Shift');
            e.assertBoxes(await e.allBoxes(page, h), e.withBoxes(before, { logo: { x: 171.8 } }), 'ten steps');
            await page.keyboard.press('ArrowUp');
            await page.keyboard.down('Shift');
            await page.keyboard.press('ArrowDown');
            await page.keyboard.press('ArrowLeft');
            await page.keyboard.up('Shift');
            e.assertBoxes(await e.allBoxes(page, h), e.withBoxes(before, { logo: { x: 168.8, y: 33.7 } }), 'all four directions');
            for (let i = 0; i < 5; i++) await e.pressMod(page, 'z');
            e.assertBoxes(await e.allBoxes(page, h), before, 'five key presses = five undo steps');
            assert.equal((await h.state(page)).canUndo, false);
            // at the limit the arrow stops at the outer bleed edge
            await e.selectLayer(page, h, 'head');
            await page.keyboard.press('ArrowUp');
            e.assertBoxes(await e.allBoxes(page, h), before, 'the header already lies on the bleed edge');
        });

        await check('E7 dragged far past the page edge the logo stops at the outer bleed edge — with bleed off at the trim edge', async () => {
            await e.restore(page, h, cal.design);
            const from = logo();
            await h.dragMm(page, from, { x: from.x + 200, y: from.y - 120 });
            e.assertBoxes(await e.allBoxes(page, h), e.withBoxes(before, { logo: { x: 291.5, y: -5 } }), 'top right corner of the bleed box, size kept');
            // bleed off (step 1): the trim edge is the limit
            await e.restore(page, h, cal.design);
            await h.gotoStep(page, 1);
            await page.click('label:has([data-print="bleed"])');
            await h.gotoStep(page, 3);
            assert.equal((await h.cal(page, (c) => c.page())).bleedMm, 0);
            const flat = await e.allBoxes(page, h);
            h.expectBox(flat.logo, before.logo, e.TOL, 'the logo is not touched by the bleed switch');
            assert.equal(await page.$('.cal-chrome [data-chrome="bleed"]'), null, 'no hatch without bleed');
            await h.dragMm(page, e.centre(flat.logo), { x: from.x + 200, y: from.y - 120 });
            e.assertBoxes(await e.allBoxes(page, h), e.withBoxes(flat, { logo: { x: 286.5, y: 0 } }), 'top right corner of the trim');
            await h.dragMm(page, { x: 298, y: 12 }, { x: -140, y: 200 });
            e.assertBoxes(await e.allBoxes(page, h), e.withBoxes(flat, { logo: { x: 0, y: 121.5 } }), 'bottom left corner of the trim');
            await e.selectLayer(page, h, 'logo');
            await h.dragHandle(page, 'ne', 300, -150);
            e.assertBoxes(await e.allBoxes(page, h), e.withBoxes(flat, { logo: { x: 0, y: 0, w: 310, h: 145 } }), 'each edge stops at the trim on its own');
        });

        await check('E8 the caption right handle dragged far to the left stops at the minimum width; the left edge has not moved', async () => {
            await e.restore(page, h, cal.design);
            await e.resize(page, h, 'cap', 'e', -150, 0);
            e.assertBoxes(await e.allBoxes(page, h), e.withBoxes(before, { cap: { w: 6 } }), 'minimum width');
            await h.dragHandle(page, 's', 0, -40);
            e.assertBoxes(await e.allBoxes(page, h), e.withBoxes(before, { cap: { w: 6, h: 4 } }), 'minimum height, top edge fixed, no flip');
            // a corner dragged past the opposite corner: the minimum, the far corner fixed, nothing flips
            await e.resize(page, h, 'logo', 'nw', 80, 60);
            const small = e.withBoxes(before, { cap: { w: 6, h: 4 }, logo: { x: 186, y: 50.5, w: 6, h: 4 } });
            e.assertBoxes(await e.allBoxes(page, h), small, 'corner past the opposite corner');
            await h.save(page);
            await h.reload(page);
            e.assertBoxes(await e.allBoxes(page, h), small, 'after reload');
        });

        await check('E9 X = 20 and Š = 80 for the logo, measured on the rendered page against its width', async () => {
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
            await type('x', '20');
            await type('w', '80');
            h.expectBox((await e.allBoxes(page, h)).logo, { x: 20, y: 31, w: 80, h: 23.5 }, e.TOL, 'logo');
            const { width, bleedMm } = await h.cal(page, (c) => c.page());
            const measured = await page.evaluate(() => {
                const svg = document.querySelector('.cal-cv .cal-page-svg svg').getBoundingClientRect();
                const frame = document.querySelector('.cal-ovl > .cal-sel:not(.other)').getBoundingClientRect();
                return { svgLeft: svg.left, svgWidth: svg.width, left: frame.left, width: frame.width };
            });
            const mm = (width + 2 * bleedMm) / measured.svgWidth;
            assert.ok(Math.abs((measured.left - measured.svgLeft) * mm - bleedMm - 20) < 0.4, '20 mm from the left trim edge');
            assert.ok(Math.abs(measured.width * mm - 80) < 0.4, '80 mm wide');
        });

        await check('zoom: 50…400 % in the handoff steps, the percentage resets to fit, zoom is never saved', async () => {
            await e.restore(page, h, cal.design);
            const fit = await h.cal(page, (c) => c.scale());
            const seen = [];
            await zoomTo(page, h, 50);
            assert.equal(await page.$eval('[data-cal-action="zoom-out"]', (el) => el.disabled), true);
            await e.selectLayer(page, h, 'logo');
            for (let i = 0; i < 8; i++) {
                const zoom = (await h.state(page)).zoom;
                seen.push(Math.round(zoom * 100));
                assert.equal(await page.$eval('[data-cal-action="zoom-fit"]', (el) => el.textContent.trim()), `${Math.round(zoom * 100)} %`);
                assert.ok(Math.abs((await h.cal(page, (c) => c.scale())) - fit * zoom) < 0.01, `scale at ${zoom}`);
                await scrollTo(page, h, e.centre(before.logo));
                await assertAligned(page, h, before.logo, `zoom ${Math.round(zoom * 100)} %`);
                if (i < 7) await page.click('[data-cal-action="zoom-in"]');
            }
            assert.deepEqual(seen, [50, 75, 100, 125, 150, 200, 300, 400]);
            assert.equal(await page.$eval('[data-cal-action="zoom-in"]', (el) => el.disabled), true);
            const scroll = await page.$eval('.cal-cvw', (el) => ({ x: el.scrollWidth > el.clientWidth, y: el.scrollHeight > el.clientHeight }));
            assert.deepEqual(scroll, { x: true, y: true }, 'the stage scrolls when the page is larger');
            await page.click('[data-cal-action="zoom-fit"]');
            assert.equal((await h.state(page)).zoom, 1);
            assert.ok(Math.abs((await h.cal(page, (c) => c.scale())) - fit) < 0.01);
            const fits = await page.$eval('.cal-cvw', (el) => el.scrollWidth <= el.clientWidth && el.scrollHeight <= el.clientHeight);
            assert.equal(fits, true, 'at 100 % the page fits the stage without scrolling');
            const s = await h.state(page);
            assert.equal(s.status, 'clean', 'zooming is not a change');
            assert.equal(JSON.stringify(s.design).includes('zoom'), false);
        });

        await check('E20 at 200 %: the same results in mm; snapping needs to be closer in mm', async () => {
            await e.restore(page, h, cal.design);
            const from = logo();
            // at 100 %: a logo centre 2 mm from the page centre snaps (6 px > 2 mm)
            await h.dragMm(page, from, { x: from.x - 23.25, y: from.y }, { steps: 30 });
            const snapped = (await e.allBoxes(page, h)).logo;
            assert.ok(Math.abs(snapped.x + snapped.w / 2 - 155) <= 0.051, `100 %: snapped to the centre, got ${snapped.x}`);

            await e.restore(page, h, cal.design);
            await zoomTo(page, h, 200);
            const scale = await h.cal(page, (c) => c.scale());
            assert.ok(6 / scale < 2, 'the snap distance at 200 % is under 2 mm');
            await scrollTo(page, h, { x: from.x - 15, y: from.y });
            // E3 at 200 %
            await h.dragMm(page, from, { x: from.x - 30, y: from.y });
            const moved = e.withBoxes(before, { logo: { x: 138.5 } });
            e.assertBoxes(await e.allBoxes(page, h), moved, 'E3 at 200 %: 30 mm are 30 mm');
            await assertAligned(page, h, moved.logo, 'after the drag at 200 %');
            // E4 at 200 %: 2 mm from the centre no longer snaps …
            await e.pressMod(page, 'z');
            await h.dragMm(page, from, { x: from.x - 23.25, y: from.y }, { steps: 30, hold: true });
            const loose = (await e.allBoxes(page, h)).logo;
            assert.ok(Math.abs(loose.x - 145.25) <= 0.06, `200 %: no snap 2 mm from the centre, got ${loose.x}`);
            assert.equal((await guides(page)).length, 0);
            await e.release(page);
            // … 0.75 mm does, with the guide, and Alt still switches it off
            await e.pressMod(page, 'z');
            await h.dragMm(page, from, { x: from.x - 24.5, y: from.y }, { steps: 30, hold: true });
            const close = (await e.allBoxes(page, h)).logo;
            assert.ok(Math.abs(close.x + close.w / 2 - 155) <= 0.051, '200 %: snaps when closer');
            assert.deepEqual((await guides(page)).map((g) => [g.axis, g.pos]), [['x', 155]]);
            await e.release(page);
            assert.equal((await guides(page)).length, 0);
            await e.pressMod(page, 'z');
            await h.dragMm(page, from, { x: from.x - 24.5, y: from.y }, { steps: 30, alt: true });
            h.expectBox((await e.allBoxes(page, h)).logo, { x: 144 }, e.TOL, '200 % with Alt');
            await h.save(page);
            await h.reload(page);
            assert.equal((await h.state(page)).zoom, 1, 'zoom is a view setting only');
            e.assertBoxes(await e.allBoxes(page, h), e.withBoxes(before, { logo: { x: 144 } }), 'after reload');
        });

        await check('window resize: the page rescales to fit; frame, handles and guides stay on the page', async () => {
            await e.restore(page, h, cal.design);
            await e.selectLayer(page, h, 'photo');
            const big = await h.cal(page, (c) => c.scale());
            await assertAligned(page, h, before.photo, '1440 px');
            for (const [width, height] of [[1180, 720], [1024, 640], [800, 900]]) {
                await page.setViewport({ width, height });
                await e.sleep(400);
                assert.ok(Math.abs((await h.cal(page, (c) => c.scale())) - big) > 0.01, `${width} px: the page was rescaled`);
                await assertAligned(page, h, before.photo, `${width} px`);
                const fits = await page.$eval('.cal-cvw', (el) => el.scrollWidth <= el.clientWidth + 1);
                assert.equal(fits, true, `${width} px: the page fits the stage`);
            }
            // stacked (≤ 960 px): the side panels sit under the page instead of disappearing
            const order = await page.evaluate(() => ({ stage: document.querySelector('.cal-stage').getBoundingClientRect().bottom, layers: document.querySelector('.cal-left').getBoundingClientRect().top, props: document.querySelector('.cal-right').getBoundingClientRect().top, propsShown: getComputedStyle(document.querySelector('.cal-right')).display !== 'none' }));
            assert.ok(order.layers >= order.stage - 1 && order.props >= order.layers && order.propsShown, 'panels stack under the stage');
            // a drag in the small window, with its guide on the page
            await page.evaluate(() => window.scrollTo(0, 0));
            await h.dragHandle(page, 'e', -41, 0, { hold: true });
            const lines = await guides(page);
            assert.deepEqual(lines.map((g) => [g.axis, g.pos]), [['x', 155]]);
            const centreLine = await e.svgRectOf(page, { x: 155, y: 0, w: 0, h: 145 });
            assert.ok(Math.abs(lines[0].x + lines[0].w / 2 - centreLine.x) <= 1, 'the guide is on the page centre in the small window');
            await e.release(page);
            await page.setViewport({ width: 1440, height: 900 });
            await page.waitForFunction((s) => Math.abs(window.__calendar.scale() - s) < 0.01, {}, big);
            await assertAligned(page, h, (await e.allBoxes(page, h)).photo, 'back at 1440 px');
        });

        await h.deleteCalendar(page, cal.id);
    },
};
