/*
 * Editor — Vrstvy and the undo stack (handoff §5.7, §5.10, §4.4): E10 (drag reorder), E11 (eye),
 * the QR row, E17 (ten changes, ten undos, ten redos), E19 (mirroring in step 2 as one undo step).
 * Real pointer and keys. Phase P4.
 */
const assert = require('assert/strict');
const e = require('./lib/editor.cjs');

const layerRows = (page) => page.$$eval('[data-layer]', (n) => n.map((x) => x.dataset.layer));
const order = async (page, h) => (await h.state(page)).design.layout.order;
/** Paint order of the elements inside the page SVG (first = painted first = bottom). */
const painted = (page) => page.$$eval('.cal-cv .cal-page-svg svg > [data-el], .cal-cv .cal-page-svg svg > g > [data-el]', (n) => n.map((x) => x.dataset.el).filter((k, i, all) => !['paper', 'bg', 'holes'].includes(k) && all.indexOf(k) === i));
const snapshot = async (page, h) => {
    const s = await h.state(page);
    return JSON.stringify({ layout: s.design.layout, captions: s.design.features.captions });
};
const disabled = (page, action) => page.$eval(`[data-cal-action="${action}"]`, (el) => el.disabled);

module.exports = {
    name: 'editor-layers',
    checks: ['E10', 'E11', 'E17', 'E19', '§5.7'],
    async run({ page, h, check }) {
        const created = [];
        let cal = null;
        let before = null;

        await check('setup', async () => {
            cal = await e.newCalendar(page, h);
            created.push(cal.id);
            before = await e.allBoxes(page, h);
            assert.deepEqual(await order(page, h), ['qr', 'logo', 'cap', 'wk', 'month', 'head', 'cal', 'photo']);
            assert.deepEqual(await painted(page), ['photo', 'cal', 'head', 'month', 'wk', 'cap', 'logo'], 'the page paints bottom layer first');
        });
        if (!cal) return;

        await check('E10 dragging the Logo row below and above other rows restacks the page; Pozadí stays last', async () => {
            await e.dragRow(page, 'logo', 'cal', true);
            assert.deepEqual(await order(page, h), ['qr', 'cap', 'wk', 'month', 'head', 'cal', 'logo', 'photo']);
            assert.deepEqual(await layerRows(page), ['cap', 'wk', 'month', 'head', 'cal', 'logo', 'photo', 'bg']);
            assert.deepEqual(await painted(page), ['photo', 'logo', 'cal', 'head', 'month', 'wk', 'cap'], 'the logo is painted right above the photo');
            assert.equal((await h.state(page)).canUndo, true, 'a reorder is an undo step');
            // the stacking decides what a click hits: where logo and kalendárium overlap, the upper one wins
            await e.dragRow(page, 'logo', 'cap', false);
            assert.deepEqual(await order(page, h), ['qr', 'logo', 'cap', 'wk', 'month', 'head', 'cal', 'photo']);
            assert.deepEqual(await painted(page), ['photo', 'cal', 'head', 'month', 'wk', 'cap', 'logo']);
            await e.dragRow(page, 'photo', 'logo', false);
            assert.deepEqual(await order(page, h), ['qr', 'photo', 'logo', 'cap', 'wk', 'month', 'head', 'cal']);
            assert.deepEqual(await layerRows(page), ['photo', 'logo', 'cap', 'wk', 'month', 'head', 'cal', 'bg'], 'Pozadí is still the last row');
            await e.clickMm(page, h, e.centre(before.logo));
            assert.equal((await e.selection(page, h)).el, 'photo', 'the photo now lies over the logo: hit testing follows the layer order');
            await page.keyboard.press('Escape');
            e.assertBoxes(await e.allBoxes(page, h), before, 'reordering moves nothing');
            await h.save(page);
            await h.reload(page);
            assert.deepEqual(await order(page, h), ['qr', 'photo', 'logo', 'cap', 'wk', 'month', 'head', 'cal'], 'after reload');
            assert.deepEqual(await painted(page), ['cal', 'head', 'month', 'wk', 'cap', 'logo', 'photo']);
            // a row cannot be dropped under Pozadí and Pozadí has no grip to drag
            assert.equal(await page.$eval('.cal-els-fixed', (el) => el.querySelector('[data-layer-item]') === null && el.querySelector('.i-lock') !== null), true);
        });

        await check('E11 the eye of Číslo týdne: gone from the sample page and every week page (steps 4 and 5), back with the same position and style', async () => {
            await e.restore(page, h, cal.design);
            const wkStyle = JSON.stringify((await h.state(page)).design.layout.styles.wk);
            const weeks = await h.cal(page, (c) => c.pages().filter((p) => p.kind === 'week').map((p) => p.index));
            assert.ok(await page.$('.cal-page-svg [data-el="wk"]'));
            assert.equal(await page.$eval('[data-layer-eye="wk"]', (el) => el.getAttribute('aria-label')), 'Skrýt: Číslo týdne');
            // while it is visible its edges are snap targets: logo bottom 54.5 → 143.3 lands on its bottom edge (143.5)
            await e.resize(page, h, 'logo', 's', 0, 88.8, { hold: true });
            h.expectBox((await e.allBoxes(page, h)).logo, { h: 112.5 }, e.TOL, 'snap to the visible week number');
            await page.keyboard.press('Escape');
            await page.mouse.up();
            await page.click('[data-layer="wk"]');
            assert.equal((await e.selection(page, h)).el, 'wk');
            await page.click('[data-layer-eye="wk"]');
            assert.equal(await page.$('.cal-page-svg [data-el="wk"]'), null, 'gone from the sample page');
            assert.equal(await e.selection(page, h), null, 'a hidden element loses its selection');
            assert.equal(await page.$eval('[data-layer-eye="wk"]', (el) => el.getAttribute('aria-label')), 'Zobrazit: Číslo týdne');
            assert.equal(await page.$eval('[data-layer="wk"]', (el) => el.closest('.cal-elr').classList.contains('off')), true, 'dimmed in the list');
            for (const index of [weeks[0], weeks[20], weeks[weeks.length - 1]]) assert.equal((await h.cal(page, (c, i) => c.geometry(i), index)).boxes.wk, undefined, `page ${index}`);
            // a hidden element cannot be selected on the page or in the list, and is no snap target
            await e.clickMm(page, h, e.centre(before.wk));
            assert.notEqual(((await e.selection(page, h)) || {}).el, 'wk');
            await page.click('[data-layer="wk"]');
            assert.notEqual(((await e.selection(page, h)) || {}).el, 'wk');
            await page.keyboard.press('Escape');
            // logo bottom 54.5 → 143.3: the hidden week number's bottom edge (143.5) is no target, the trim edge (145) is
            await e.resize(page, h, 'logo', 's', 0, 88.8, { hold: true });
            h.expectBox((await e.allBoxes(page, h)).logo, { h: 114 }, e.TOL, 'no snap to the hidden element');
            assert.deepEqual(await page.$$eval('[data-guide]', (n) => n.map((x) => Number(x.dataset.pos))), [145]);
            await page.keyboard.press('Escape');
            await page.mouse.up();
            await page.keyboard.press('Escape');

            await h.save(page);
            for (const step of [4, 5]) {
                await h.gotoStep(page, step);
                await page.waitForSelector('svg[data-page]');
                assert.equal(await page.$$eval('svg[data-page] [data-el="wk"]', (n) => n.length), 0, `step ${step}: no week number on any drawn page`);
                assert.ok((await page.$$eval('svg[data-page] [data-el="cal"]', (n) => n.length)) > 0, `step ${step}: week pages are drawn`);
            }
            await h.gotoStep(page, 3);
            await h.reload(page);
            assert.equal((await e.allBoxes(page, h)).wk.hidden, true, 'hidden after reload');
            await page.click('[data-layer-eye="wk"]');
            assert.ok(await page.$('.cal-page-svg [data-el="wk"]'), 'back on the page');
            e.assertBoxes(await e.allBoxes(page, h), before, 'same position');
            assert.equal(JSON.stringify((await h.state(page)).design.layout.styles.wk), wkStyle, 'same style');
            await h.save(page);
        });

        await check('eyes: only the five hideable elements have one; Popisek fotografie is the step-1 switch; hide and show are undo steps', async () => {
            await e.restore(page, h, cal.design);
            assert.deepEqual((await page.$$eval('[data-layer-eye]', (n) => n.map((x) => x.dataset.layerEye))).sort(), ['cap', 'logo', 'month', 'wk']);
            await page.click('[data-layer-eye="cap"]');
            let s = await h.state(page);
            assert.equal(s.design.features.captions, false, 'the eye of the caption switches the step-1 item');
            assert.equal(s.design.layout.boxes.cap.hidden, undefined);
            assert.equal(await page.$('.cal-page-svg [data-el="cap"]'), null);
            await page.click('[data-layer-eye="logo"]');
            await page.click('[data-layer-eye="month"]');
            assert.equal(await page.$('.cal-page-svg [data-el="logo"]'), null);
            assert.equal(await page.$('.cal-page-svg [data-el="month"]'), null);
            for (let i = 0; i < 3; i++) await e.pressMod(page, 'z');
            s = await h.state(page);
            assert.equal(s.design.features.captions, true, 'undo brings the caption back');
            e.assertBoxes(s.design.layout.boxes, before, 'everything visible again');
            assert.equal(s.canUndo, false);
            await h.gotoStep(page, 1);
            await page.click('label:has([data-feature="captions"])');
            await h.gotoStep(page, 3);
            assert.equal(await page.$eval('[data-layer-eye="cap"]', (el) => el.getAttribute('aria-label')), 'Zobrazit: Popisek fotografie', 'step 1 and the eye are one switch');
            assert.equal(await page.$eval('[data-layer="cap"]', (el) => el.closest('.cal-elr').classList.contains('off')), true);
        });

        await check('the QR kód row exists only while QR is on in step 1; switching it off clears its selection and keeps the box', async () => {
            await e.restore(page, h, cal.design);
            assert.equal(await page.$('[data-layer="qr"]'), null);
            await h.gotoStep(page, 1);
            await page.click('label:has([data-feature="qr"])');
            await h.gotoStep(page, 3);
            assert.deepEqual(await layerRows(page), ['qr', 'logo', 'cap', 'wk', 'month', 'head', 'cal', 'photo', 'bg']);
            assert.ok(await page.$('[data-layer-eye="qr"]'));
            await page.click('[data-layer="qr"]');
            assert.equal(await page.$eval('[data-frame-tag]', (el) => el.textContent), 'QR kód');
            const qr = e.centre(before.qr);
            await h.dragMm(page, qr, { x: qr.x - 40, y: qr.y - 30 });
            const moved = (await e.allBoxes(page, h)).qr;
            h.expectBox(moved, { x: before.qr.x - 40, y: before.qr.y - 30, w: before.qr.w, h: before.qr.h }, e.TOL, 'qr');
            await h.gotoStep(page, 1);
            await page.click('label:has([data-feature="qr"])');
            await h.gotoStep(page, 3);
            assert.equal(await page.$('[data-layer="qr"]'), null, 'the row is gone');
            assert.equal(await e.selection(page, h), null, 'the selection is cleared');
            h.expectBox((await e.allBoxes(page, h)).qr, moved, e.TOL, 'position kept');
            // week number: the row stays, dimmed, while „Zobrazení týdnů“ is off
            await h.gotoStep(page, 1);
            await page.click('label:has([data-feature="weekNumbers"])');
            await h.gotoStep(page, 3);
            assert.ok(await page.$('[data-layer="wk"]'));
            assert.equal(await page.$eval('[data-layer="wk"]', (el) => el.closest('.cal-elr').classList.contains('off')), true);
            assert.equal(await page.$('.cal-page-svg [data-el="wk"]'), null);
        });

        await check('E17 ten different changes, Ctrl+Z ten times, Ctrl+Shift+Z ten times; the buttons are disabled at both ends', async () => {
            await e.restore(page, h, cal.design);
            assert.equal(await disabled(page, 'undo'), true);
            assert.equal(await disabled(page, 'redo'), true);
            const states = [await snapshot(page, h)];
            const logo = e.centre(before.logo);
            const changes = [
                () => h.dragMm(page, logo, { x: logo.x - 30, y: logo.y + 20 }), // move
                () => e.resize(page, h, 'cap', 'e', 30, 0), // resize
                async () => { await e.selectLayer(page, h, 'logo'); await page.keyboard.press('ArrowDown'); }, // nudge
                async () => { await page.keyboard.down('Shift'); await page.keyboard.press('ArrowLeft'); await page.keyboard.up('Shift'); }, // big nudge
                () => page.click('[data-layer-eye="month"]'), // hide
                () => page.click('[data-layer-eye="cap"]'), // caption switch
                () => e.dragRow(page, 'logo', 'head', true), // reorder
                () => e.resize(page, h, 'photo', 'e', -37, 0), // linked resize
                async () => {
                    // part offset
                    await e.selectLayer(page, h, 'cal');
                    await page.click('[data-part-row="name"]');
                    const g = await h.cal(page, (c) => c.geometry());
                    const r = g.frames[0].rows[0].parts.find((p) => p.key === 'name').rect;
                    await h.dragMm(page, e.centre({ x: r.x, y: r.y, w: r.w, h: r.h }), { x: r.x + r.w / 2 + 4, y: r.y + r.h / 2 + 1 });
                    await page.keyboard.press('Escape');
                    await page.keyboard.press('Escape');
                },
                () => page.click('[data-layer-eye="month"]'), // show
            ];
            for (const change of changes) {
                await change();
                const now = await snapshot(page, h);
                assert.notEqual(now, states[states.length - 1], `change ${states.length} changed the layout`);
                states.push(now);
            }
            assert.equal(new Set(states).size, 11, 'eleven distinct states');
            assert.equal(await disabled(page, 'undo'), false);
            assert.equal(await disabled(page, 'redo'), true);
            await page.evaluate(() => document.activeElement && document.activeElement.blur());
            for (let i = 9; i >= 0; i--) {
                await e.pressMod(page, 'z');
                assert.equal(await snapshot(page, h), states[i], `undo back to state ${i}`);
            }
            assert.equal(await disabled(page, 'undo'), true, 'undo is disabled at the start');
            assert.equal(await disabled(page, 'redo'), false);
            assert.equal((await h.state(page)).status, 'clean', 'back at the saved state nothing is unsaved');
            await e.pressMod(page, 'z');
            assert.equal(await snapshot(page, h), states[0], 'one more Ctrl+Z does nothing');
            for (let i = 1; i <= 10; i++) {
                // Ctrl+Shift+Z, and Ctrl+Y for every third step
                if (i % 3 === 0) await e.pressMod(page, 'y');
                else await e.pressMod(page, 'z', { shift: true });
                assert.equal(await snapshot(page, h), states[i], `redo to state ${i}`);
            }
            assert.equal(await disabled(page, 'redo'), true, 'redo is disabled at the end');
            assert.equal(await disabled(page, 'undo'), false);
            // the buttons do the same, and a new change clears the redo list
            await page.click('[data-cal-action="undo"]');
            await page.click('[data-cal-action="undo"]');
            assert.equal(await snapshot(page, h), states[8]);
            await page.click('[data-cal-action="redo"]');
            assert.equal(await snapshot(page, h), states[9]);
            await e.selectLayer(page, h, 'logo');
            await page.keyboard.press('ArrowUp');
            assert.equal(await disabled(page, 'redo'), true, 'a new change clears redo');
            // an undo that hides the selected element clears the selection
            await page.click('[data-layer-eye="logo"]');
            await page.click('[data-layer-eye="logo"]');
            await e.selectLayer(page, h, 'logo');
            await page.evaluate(() => document.activeElement && document.activeElement.blur());
            await e.pressMod(page, 'z');
            assert.equal(await e.selection(page, h), null, 'undo led to a state where the selected element is hidden');
        });

        await check('E19 Fotka vpravo on a customised Fotka vlevo: everything mirrors, sizes kept, Ctrl+Z in step 3 mirrors back', async () => {
            await e.restore(page, h, cal.design);
            const logo = e.centre(before.logo);
            await h.dragMm(page, logo, { x: logo.x - 30, y: logo.y + 20 });
            await e.resize(page, h, 'photo', 'e', -37, 0);
            await e.resize(page, h, 'cap', 'e', 30, 0);
            const custom = await e.allBoxes(page, h);
            const styles = (await h.state(page)).design.layout.styles;
            await h.gotoStep(page, 2);
            await page.click('[data-preset="photoRight"]');
            await page.waitForSelector('.cal-ws[data-cal-shown-step="3"]');
            const mirrored = await e.allBoxes(page, h);
            const expected = {};
            for (const [key, box] of Object.entries(custom)) expected[key] = { ...box, x: Math.round((310 - box.x - box.w) * 10) / 10 };
            e.assertBoxes(mirrored, expected, 'every box mirrored, sizes preserved');
            let s = await h.state(page);
            assert.equal(s.design.preset, 'photoRight');
            assert.equal(s.design.layout.styles.cal.numberSide, styles.cal.numberSide === 'end' ? 'start' : 'end', 'the day-number side flips');
            assert.equal(s.design.layout.styles.month.align, styles.month.align === 'end' ? 'start' : 'end', 'text alignment flips');
            assert.equal(await disabled(page, 'undo'), false);
            await e.pressMod(page, 'z');
            e.assertBoxes(await e.allBoxes(page, h), custom, 'Ctrl+Z mirrors back');
            s = await h.state(page);
            assert.equal(s.design.preset, 'photoLeft');
            assert.equal(s.design.layout.styles.cal.numberSide, styles.cal.numberSide);
            assert.equal(await page.$eval('.cal-ws', (el) => el.dataset.calShownStep), '3', 'still in the editor');
        });

        for (const id of created) await h.deleteCalendar(page, id);
    },
};
