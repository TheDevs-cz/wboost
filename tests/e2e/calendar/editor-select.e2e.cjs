/*
 * Editor — selecting (handoff §5.1, §5.2, §6.2, §8 "Editor"): E1, E2, the selection half of E12,
 * Pozadí, the topmost-under-pointer rule, a covered element moved through Vrstvy, the hint line,
 * the tag, the panel handle and the keyboard rules. Real pointer and keys. Phase P4.
 */
const assert = require('assert/strict');
const e = require('./lib/editor.cjs');

const HINT_ELEMENT = 'Klikněte na prvek, táhněte ho a měňte velikost za úchyty · změna platí pro všechny strany';
const HINT_BLOCK = 'Klikněte znovu na část kalendária — číslo, den, jmeniny, svátek… — a nastavte její styl';
const HINT_PART = 'Táhněte pro posun ve všech řádcích · Esc = zpět na celé kalendárium';

const text = (page, selector) => page.$eval(selector, (el) => el.textContent.replace(/\s+/g, ' ').trim());
const pressed = (page, selector) => page.$eval(selector, (el) => el.getAttribute('aria-pressed'));

const { svgRectOf, assertRect, dragRow } = e;

module.exports = {
    name: 'editor-select',
    checks: ['E1', 'E2', 'E12 (selection)', '§8 Editor situations'],
    async run({ page, h, check }) {
        let cal = null;
        let boxes = null;

        await check('step 3 anatomy: Vrstvy, stage bar, sample page with bleed, hint, empty panel', async () => {
            cal = await e.newCalendar(page, h);
            boxes = await e.allBoxes(page, h);
            assert.equal(await text(page, '.cal-layh h3'), 'Vrstvy');
            assert.deepEqual(await page.$$eval('[data-layer]', (n) => n.map((x) => x.dataset.layer)), ['logo', 'cap', 'wk', 'month', 'head', 'cal', 'photo', 'bg'], 'rows in layer order, no QR row, Pozadí last');
            assert.equal(await text(page, '.cal-stage-note'), 'Vzorová strana · styl platí pro všechny strany');
            assert.equal(await text(page, '.cal-hint'), HINT_ELEMENT);
            assert.match(await text(page, '.cal-right'), /Vyberte prvek na stránce/);
            assert.equal(await page.$eval('[data-cal-action="zoom-fit"]', (el) => el.textContent.trim()), '100 %');
            // bleed: the canvas is the bleed box, the hatch and the dashed trim line are drawn
            const { width, height, bleedMm } = await h.cal(page, (c) => c.page());
            const scale = await h.cal(page, (c) => c.scale());
            const cv = await page.$eval('.cal-cv', (el) => ({ w: el.getBoundingClientRect().width, h: el.getBoundingClientRect().height }));
            assert.ok(Math.abs(cv.w - (width + 2 * bleedMm) * scale) < 1 && Math.abs(cv.h - (height + 2 * bleedMm) * scale) < 1, 'canvas = page + bleed');
            assert.ok(await page.$('.cal-chrome [data-chrome="bleed"]'), 'hatched bleed');
            assert.equal(await page.$eval('.cal-chrome [data-chrome="trim"]', (el) => el.getAttribute('stroke-dasharray') !== null), true, 'dashed trim line');
            assert.equal(await page.$eval('.cal-cv .cal-page-svg svg', (el) => el.querySelector('.cal-sel, [data-handle]') === null), true, 'no editor chrome inside the page SVG');
            // the sample page is the first week of the calendar
            const sample = await h.cal(page, (c) => c.pages().find((p) => p.kind === 'week').key);
            assert.equal(await page.$eval('.cal-cv .cal-page-svg svg', (el) => el.dataset.page), sample);
            await h.shot(page, 'editor-empty');
        });
        if (!cal) return;

        await check('E1 click the photo: frame with eight handles, tag Fotografie, row highlighted, panel shows the photo', async () => {
            await e.clickMm(page, h, { x: 60, y: 80 });
            assert.deepEqual(await e.selection(page, h), { el: 'photo' });
            assertRect(await e.frameRect(page), await svgRectOf(page, boxes.photo), 'frame = photo box');
            assert.equal(await e.visibleHandles(page), 8);
            assert.equal(await text(page, '[data-frame-tag]'), 'Fotografie');
            assert.equal(await pressed(page, '[data-layer="photo"]'), 'true');
            assert.equal(await page.$eval('[data-layer="photo"]', (el) => el.closest('.cal-elr').classList.contains('sel')), true);
            assert.equal(await page.$$eval('.cal-elr.sel', (n) => n.length), 1);
            assert.match(await text(page, '.cal-right'), /Fotografie/);
            assert.doesNotMatch(await text(page, '.cal-right'), /Vyberte prvek na stránce/);
            assert.equal(await text(page, '.cal-hint'), HINT_ELEMENT);
            await h.shot(page, 'editor-photo-selected');
        });

        await check('E2 Esc: nothing selected, the panel shows the empty state', async () => {
            await page.keyboard.press('Escape');
            assert.equal(await e.selection(page, h), null);
            assert.equal(await e.frameRect(page), null);
            assert.equal(await e.visibleHandles(page), 0);
            assert.match(await text(page, '.cal-right'), /Vyberte prvek na stránce/);
            assert.equal(await page.$$eval('.cal-elr.sel', (n) => n.length), 0);
        });

        await check('page and Vrstvy stay in sync; the selected row clicked again deselects', async () => {
            await page.click('[data-layer="month"]');
            assert.deepEqual(await e.selection(page, h), { el: 'month' });
            assertRect(await e.frameRect(page), await svgRectOf(page, boxes.month), 'frame = month box');
            assert.equal(await text(page, '[data-frame-tag]'), 'Měsíc');
            await page.click('[data-layer="month"]');
            assert.equal(await e.selection(page, h), null);
            await e.clickMm(page, h, e.centre(boxes.cap));
            assert.equal((await e.selection(page, h)).el, 'cap');
            assert.equal(await pressed(page, '[data-layer="cap"]'), 'true');
        });

        await check('overlap: a click selects the topmost element under the pointer (layer order)', async () => {
            // the logo lies over the photo; the caption lies over the photo
            await page.keyboard.press('Escape');
            await e.clickMm(page, h, e.centre(boxes.logo));
            assert.equal((await e.selection(page, h)).el, 'logo');
            await e.clickMm(page, h, { x: boxes.logo.x - 6, y: boxes.logo.y + 4 });
            assert.equal((await e.selection(page, h)).el, 'photo');
            // the month text lies over the header
            await e.clickMm(page, h, e.centre(boxes.month));
            assert.equal((await e.selection(page, h)).el, 'month');
            await e.clickMm(page, h, { x: 40, y: 20 });
            assert.equal((await e.selection(page, h)).el, 'head');
        });

        await check('Pozadí: bare page or its row selects it — no frame, no handles, no arrow-key move; the empty stage deselects', async () => {
            const bare = { x: 250, y: 147 }; // under the week number, right of the photo
            await e.clickMm(page, h, bare);
            assert.deepEqual(await e.selection(page, h), { el: 'bg' });
            assert.equal(await e.frameRect(page), null);
            assert.equal(await e.visibleHandles(page), 0);
            assert.equal(await pressed(page, '[data-layer="bg"]'), 'true');
            assert.match(await text(page, '.cal-right'), /Pozadí/);
            await page.keyboard.press('ArrowRight');
            e.assertBoxes(await e.allBoxes(page, h), boxes, 'arrow keys with Pozadí selected');
            const stage = await page.$eval('.cal-cvw', (el) => ({ x: el.getBoundingClientRect().left + 6, y: el.getBoundingClientRect().top + 6 }));
            await page.mouse.click(stage.x, stage.y);
            assert.equal(await e.selection(page, h), null, 'a click on the empty stage around the page');
            await page.click('[data-layer="bg"]');
            assert.deepEqual(await e.selection(page, h), { el: 'bg' });
            assert.equal(await page.$('[data-layer-eye="bg"]'), null, 'Pozadí cannot be hidden');
            assert.equal(await page.$('[data-layer-item="bg"]'), null, 'Pozadí is outside the sortable rows');
            await page.keyboard.press('Escape');
        });

        await check('E12 (selection) kalendárium: first click the block, second click the part — highlighted in every row', async () => {
            const geometry = await h.cal(page, (c) => c.geometry());
            const rows = geometry.frames[0].rows;
            const numRows = rows.filter((r) => r.parts.some((p) => p.key === 'num'));
            assert.ok(numRows.length >= 3, 'the sample week has workdays');
            const num = numRows[0].parts.find((p) => p.key === 'num').rect;
            const target = { x: num.x + num.w / 2, y: num.y + num.h / 2 };
            await e.clickMm(page, h, target);
            assert.deepEqual(await e.selection(page, h), { el: 'cal', frame: 0 }, 'the first click selects the whole block');
            assert.equal(await e.visibleHandles(page), 8);
            assert.equal(await text(page, '[data-frame-tag]'), 'Kalendárium');
            assert.equal(await text(page, '.cal-hint'), HINT_BLOCK);
            assert.equal(await page.$$eval('[data-part-box]', (n) => n.length), 0);
            assert.ok(await page.$('[data-part-row="num"]'), 'selecting the kalendárium expands its parts');
            e.assertBoxes(await e.allBoxes(page, h), boxes, 'clicking moves nothing');

            await e.clickMm(page, h, target);
            assert.deepEqual(await e.selection(page, h), { el: 'cal', frame: 0, part: 'num' });
            assert.equal(await page.$$eval('[data-part-box]', (n) => n.length), numRows.length, 'every workday number is highlighted');
            const boxesPx = await page.$$eval('[data-part-box]', (n) => n.map((x) => ({ x: x.getBoundingClientRect().left, y: x.getBoundingClientRect().top, w: x.getBoundingClientRect().width, h: x.getBoundingClientRect().height })));
            for (let i = 0; i < numRows.length; i++) {
                const r = numRows[i].parts.find((p) => p.key === 'num').rect;
                assertRect(boxesPx[i], await svgRectOf(page, { x: r.x - 0.5, y: r.y - 0.5, w: r.w + 1, h: r.h + 1 }), `highlight ${i}`);
            }
            assert.equal(await text(page, '[data-frame-tag]'), 'Kalendárium › Číslo — pracovní dny');
            assert.equal(await e.visibleHandles(page), 0, 'no handles while a part is selected');
            assert.equal(await page.$eval('.cal-ovl > .cal-sel:not(.other)', (el) => el.classList.contains('dim')), true, 'the block frame dims');
            assert.equal(await pressed(page, '[data-part-row="num"]'), 'true');
            assert.equal(await text(page, '[data-part-row="num"]'), 'pracovní dny');
            assert.equal(await text(page, '.cal-hint'), HINT_PART);
            e.assertBoxes(await e.allBoxes(page, h), boxes, 'a click on a selected kalendárium moves nothing');
            await h.shot(page, 'editor-part-selected');

            // another part by clicking it, and through the list
            const day = rows[0].parts.find((p) => p.key === 'day').rect;
            await e.clickMm(page, h, { x: day.x + day.w / 2, y: day.y + day.h / 2 });
            assert.equal((await e.selection(page, h)).part, 'day');
            await page.click('[data-part-row="hol"]');
            assert.deepEqual(await e.selection(page, h), { el: 'cal', frame: 0, part: 'hol' });
            assert.equal(await text(page, '[data-frame-tag]'), 'Kalendárium › Svátek');
            assert.deepEqual(
                await page.$$eval('[data-part-row]', (n) => n.map((x) => x.dataset.partRow)),
                ['num', 'numSat', 'numSun', 'numHol', 'day', 'name', 'hol', 'line', 'moon', 'icon'],
                'parts of the default features (moon on; events, shifts, waste, time off)',
            );
            assert.equal(await text(page, '.cal-pgrp'), 'Číslo dne');

            // arrow keys do nothing while a part is selected
            await page.keyboard.press('ArrowLeft');
            e.assertBoxes(await e.allBoxes(page, h), boxes, 'arrow keys with a part selected');

            // Esc: part → block → nothing
            await page.keyboard.press('Escape');
            assert.deepEqual(await e.selection(page, h), { el: 'cal', frame: 0 });
            assert.equal(await e.visibleHandles(page), 8);
            await page.keyboard.press('Escape');
            assert.equal(await e.selection(page, h), null);
        });

        await check('a drag that starts on another part moves the whole kalendárium and leaves the part level', async () => {
            await page.click('[data-layer="cal"]');
            await page.click('[data-part-row="name"]');
            const geometry = await h.cal(page, (c) => c.geometry());
            const num = geometry.frames[0].rows[2].parts.find((p) => p.key === 'num').rect;
            const from = { x: num.x + num.w / 2, y: num.y + num.h / 2 };
            await h.dragMm(page, from, { x: from.x - 20, y: from.y - 10 });
            assert.deepEqual(await e.selection(page, h), { el: 'cal', frame: 0 });
            e.assertBoxes(await e.allBoxes(page, h), e.withBoxes(boxes, { cal: { x: 177, y: 17 } }), 'the block moved, nothing else');
            assert.deepEqual((await h.state(page)).design.layout.styles.offsets, {}, 'no part offset was created');
            await e.pressMod(page, 'z');
            e.assertBoxes(await e.allBoxes(page, h), boxes, 'undo');
            await page.keyboard.press('Escape');
        });

        await check('a click never nudges: movement under 3 px is a click', async () => {
            const p = await h.cal(page, (c, b) => c.toClient(b.x + b.w / 2, b.y + b.h / 2), boxes.logo);
            await page.mouse.move(p.x, p.y);
            await page.mouse.down();
            await page.mouse.move(p.x + 2, p.y + 1);
            await page.mouse.up();
            assert.equal((await e.selection(page, h)).el, 'logo');
            e.assertBoxes(await e.allBoxes(page, h), boxes, 'after a 2 px wobble');
            assert.equal((await h.state(page)).canUndo, false);
            // the same on a handle
            const handle = await e.handleCentre(page, 'se');
            await page.mouse.move(handle.x, handle.y);
            await page.mouse.down();
            await page.mouse.move(handle.x + 2, handle.y + 2);
            await page.mouse.up();
            e.assertBoxes(await e.allBoxes(page, h), boxes, 'after a 2 px wobble on a handle');
            await page.keyboard.press('Escape');
        });

        await check('a covered element is selected through Vrstvy and then moved and resized by its frame', async () => {
            // put the logo under the photo: on the page it can no longer be clicked
            await dragRow(page, 'logo', 'photo', true);
            assert.deepEqual((await h.state(page)).design.layout.order.filter((k) => k !== 'qr'), ['cap', 'wk', 'month', 'head', 'cal', 'photo', 'logo']);
            const middle = e.centre(boxes.logo);
            await e.clickMm(page, h, middle);
            assert.equal((await e.selection(page, h)).el, 'photo', 'the click lands on the photo that covers the logo');
            await page.click('[data-layer="logo"]');
            assertRect(await e.frameRect(page), await svgRectOf(page, boxes.logo), 'the frame of the covered logo is drawn');
            await h.dragMm(page, middle, { x: middle.x - 30, y: middle.y + 20 });
            assert.equal((await e.selection(page, h)).el, 'logo');
            e.assertBoxes(await e.allBoxes(page, h), e.withBoxes(boxes, { logo: { x: 138.5, y: 51 } }), 'the logo moved, the photo did not');
            await h.dragHandle(page, 'se', 10, 10);
            e.assertBoxes(await e.allBoxes(page, h), e.withBoxes(boxes, { logo: { x: 138.5, y: 51, w: 33.5, h: 33.5 } }), 'resized by its handle');
            // a click (no drag) inside the selected frame goes to the topmost element again
            await e.clickMm(page, h, { x: 150, y: 60 });
            assert.equal((await e.selection(page, h)).el, 'photo');
            await e.restore(page, h, cal.design);
        });

        await check('keyboard belongs to a focused field: no nudge, no Esc deselect, no undo while typing', async () => {
            await e.selectLayer(page, h, 'logo');
            await page.keyboard.press('ArrowRight');
            const moved = await e.allBoxes(page, h);
            e.assertBoxes(moved, e.withBoxes(boxes, { logo: { x: 168.8 } }), 'arrow moved the logo');
            await page.click('[data-cal-name]');
            await page.keyboard.press('ArrowRight');
            await page.keyboard.press('ArrowLeft');
            await e.pressMod(page, 'z');
            e.assertBoxes(await e.allBoxes(page, h), moved, 'arrows and Ctrl+Z in the name field do not touch the page');
            assert.equal((await h.state(page)).canUndo, true);
            await page.keyboard.press('Escape');
            assert.equal((await e.selection(page, h)).el, 'logo', 'Esc in the field keeps the selection');
            await page.evaluate(() => document.activeElement && document.activeElement.blur());
            await e.pressMod(page, 'z');
            e.assertBoxes(await e.allBoxes(page, h), boxes, 'Ctrl+Z outside a field undoes');
        });

        await check('Esc during a drag puts everything back and keeps the selection', async () => {
            await e.selectLayer(page, h, 'photo');
            await h.dragHandle(page, 'e', -37, 0, { hold: true });
            e.assertBoxes(await e.allBoxes(page, h), e.withBoxes(boxes, { photo: { w: 165 }, cal: { x: 160, w: 155 } }), 'mid-drag');
            await page.keyboard.press('Escape');
            assert.deepEqual(await e.allBoxes(page, h), boxes, 'cancelled');
            assert.equal(await page.$$eval('[data-guide]', (n) => n.length), 0);
            await page.mouse.up();
            assert.deepEqual(await e.allBoxes(page, h), boxes);
            assert.equal((await e.selection(page, h)).el, 'photo');
            assert.equal((await h.state(page)).canUndo, false);
            await page.keyboard.press('Escape');
        });

        await check('the handle on the edge collapses and expands the properties panel; the page refits', async () => {
            const before = await h.cal(page, (c) => c.scale());
            assert.equal(await page.$eval('[data-cal-action="panel-toggle"]', (el) => el.getAttribute('aria-label')), 'Skrýt pravý panel');
            await page.click('[data-cal-action="panel-toggle"]');
            await page.waitForFunction(() => getComputedStyle(document.querySelector('.cal-right')).display === 'none');
            assert.equal(await page.$eval('[data-cal-action="panel-toggle"]', (el) => el.getAttribute('aria-label')), 'Zobrazit pravý panel');
            await page.waitForFunction((s) => window.__calendar.scale() > s + 0.2, {}, before);
            await e.selectLayer(page, h, 'logo');
            assertRect(await e.frameRect(page), await svgRectOf(page, boxes.logo), 'frame after the refit');
            await page.click('[data-cal-action="panel-toggle"]');
            await page.waitForFunction((s) => Math.abs(window.__calendar.scale() - s) < 0.01, {}, before);
            assert.equal(await page.$eval('.cal-right', (el) => getComputedStyle(el).display !== 'none'), true);
            await page.keyboard.press('Escape');
        });

        await h.deleteCalendar(page, cal.id);
    },
};
