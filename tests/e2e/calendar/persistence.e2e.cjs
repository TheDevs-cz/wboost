/*
 * R4 in one go (handoff §9.5; phase P8a): something is changed in EACH of the five steps, saved
 * once, the page is reloaded — and everything is still there, including the selection-independent
 * settings (linking, bleed, features, the step itself). The per-step scripts prove the same piece
 * by piece (shell: steps 1–2, editor-*: step 3, content-*: step 4, preview: step 5).
 */
const assert = require('assert/strict');
const ed = require('./lib/editor.cjs');

module.exports = {
    name: 'persistence',
    checks: ['R4'],
    async run({ page, h, check }) {
        let id = null;
        await check('R4 a change in each step — features + bleed (1), Fotka vpravo (2), logo moved + linking off + hidden week number (3), page caption (4), comment (5) — one save, reload: all preserved', async () => {
            ed.leaveFreely(page);
            id = await h.createCalendar(page);
            // step 1
            await page.click('label:has([data-feature="timeChange"])');
            await page.click('label:has([data-feature="events"])');
            await page.$eval('[data-print="bleedMm"]', (el) => { el.focus(); el.select(); });
            await page.keyboard.type('3');
            await page.keyboard.press('Enter');
            await page.$eval('[data-cal-name]', (el) => { el.focus(); el.select(); });
            await page.keyboard.type('Kalendář R4');
            await page.keyboard.press('Enter');
            // step 2
            await h.gotoStep(page, 2);
            await page.click('[data-preset="photoRight"]');
            await page.waitForSelector('.cal-ws[data-cal-shown-step="3"]');
            // step 3
            await ed.selectLayer(page, h, 'logo');
            const logo0 = (await ed.allBoxes(page, h)).logo;
            await page.keyboard.down('Shift');
            await page.keyboard.press('ArrowLeft');
            await page.keyboard.press('ArrowDown');
            await page.keyboard.up('Shift');
            await page.keyboard.press('Escape');
            await page.click('[data-cal-action="link"]');
            await page.click('[data-layer-eye="wk"]');
            // step 4
            await h.gotoStep(page, 4);
            const week = await h.cal(page, (c) => c.pages().filter((p) => p.kind === 'week')[2]);
            await page.click(`[data-page-thumb="${week.index}"]`);
            await page.waitForSelector(`[data-page-panel="${week.key}"] [data-page-field="caption"]`);
            await page.$eval('[data-page-field="caption"]', (el) => el.focus());
            await page.keyboard.type('Rybník za humny');
            await page.keyboard.press('Enter');
            // step 5
            await h.gotoStep(page, 5);
            await page.$eval(`[data-page-thumb="${week.index}"]`, (el) => el.click());
            await page.waitForSelector('[data-day-plus]');
            await h.sleep(250);
            const day = await page.$eval('[data-day-plus]', (el) => el.dataset.dayPlus);
            await page.click(`[data-day-plus="${day}"]`);
            await page.waitForSelector('[data-cal-popover="plus"]');
            await page.click('[data-plus-option="comment"]');
            await page.waitForSelector('[data-cal-popover="comment"] [data-field="text"]');
            await page.type('[data-cal-popover="comment"] [data-field="text"]', 'Prosím jinou fotku');
            await page.click('[data-cal-popover="comment"] [data-popover-confirm]');
            await page.waitForFunction(() => Object.keys(window.__calendar.state().content.comments || {}).length === 1);

            assert.equal((await h.state(page)).status, 'dirty');
            await h.save(page);
            const before = await h.state(page);
            await h.reload(page);
            const s = await h.state(page);
            assert.equal(s.status, 'clean');
            assert.deepEqual(s.design, before.design, 'the whole design');
            assert.deepEqual(s.content, before.content, 'the whole content');
            // … and each change by name, so the comparison above cannot pass on two empty documents
            assert.equal(s.design.name, 'Kalendář R4');
            assert.deepEqual([s.design.features.timeChange, s.design.features.events], [true, true]);
            assert.deepEqual([s.design.print.bleed, s.design.print.bleedMm], [true, 3]);
            assert.equal(s.design.preset, 'photoRight');
            assert.equal(s.design.linkElements, false, 'linking stays off');
            assert.equal(s.design.layout.boxes.wk.hidden, true);
            h.expectBox(s.design.layout.boxes.logo, { x: logo0.x - 3, y: logo0.y + 3, w: logo0.w, h: logo0.h }, 0.051, 'logo');
            assert.equal(s.content.pageCaptions[week.key].text, 'Rybník za humny');
            const comment = Object.values(s.content.comments)[0];
            assert.deepEqual([comment.date, comment.text, comment.author], [day, 'Prosím jinou fotku', 'designer']);
            assert.equal(s.design.step, 5, 'the calendar reopens in the step it was left in');
            assert.equal(await page.$eval('.cal-ws', (el) => el.dataset.calShownStep), '5');
            // what the user sees after the reload
            await page.$eval(`[data-page-thumb="${week.index}"]`, (el) => el.click());
            await h.sleep(300);
            const svg = await page.$eval('.cal-5w .cal-page-svg svg', (el) => el.outerHTML);
            assert.match(svg, /Rybník za humny/);
            assert.doesNotMatch(svg, /data-el="wk"/, 'the hidden week number is not drawn');
            assert.equal(await page.$eval(`[data-day-comments="${day}"]`, (el) => el.textContent.trim()), '1');
            await h.gotoStep(page, 3);
            assert.equal(await page.$eval('[data-cal-action="link"]', (el) => el.getAttribute('aria-pressed')), 'false');
        });
        await check('cleanup', async () => {
            if (id) await h.deleteCalendar(page, id);
        });
    },
};
