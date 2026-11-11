/*
 * Workspace shell, save flow, steps 1–2, list cards (phase P3; handoff R4 for steps 1–2, R5,
 * G14 in the UI, 9.6 "no AI controls, no 14-day choice").
 *
 * create → step 1 (type, year + rename, bleed limits, Označit vše) → step 2 (14 days: no choice;
 * 7 days: preset, mirror as one undo step) → save → reload keeps everything → steps 3–5 mount →
 * leave confirmation → Dokončit → list card → reopening lands in step 5 → client page → delete.
 */
const assert = require('assert/strict');

/** Visible text of an element, child elements separated by one space. */
const text = (page, selector) =>
    page.$eval(selector, (el) => {
        const parts = [];
        const walker = document.createTreeWalker(el, NodeFilter.SHOW_TEXT);
        while (walker.nextNode()) parts.push(walker.currentNode.textContent);
        return parts.join(' ').replace(/\s+/g, ' ').trim();
    });
const exists = async (page, selector) => (await page.$(selector)) !== null;

async function typeInto(page, selector, value) {
    await page.$eval(selector, (el) => {
        el.focus();
        el.select();
    });
    await page.keyboard.press('Backspace');
    await page.type(selector, value);
}

module.exports = {
    name: 'shell',
    checks: ['R4 (steps 1–2)', 'R5', 'G14 (UI)', '9.6'],
    async run({ page, h, check }) {
        const created = [];
        let id = null;
        let clientUrl = null;
        let nextYear = null;
        let saved = null;

        await check('create opens step 1 with the default name', async () => {
            id = await h.createCalendar(page);
            created.push(id);
            const s = await h.state(page);
            nextYear = new Date().getFullYear() + 1;
            assert.equal(s.design.name, `Stolní kalendář ${nextYear}`);
            assert.equal(s.design.year, nextYear);
            assert.equal(s.design.type, 'week');
            assert.equal(s.design.step, 1);
            assert.equal(s.design.layout, null);
            assert.equal(s.mode, 'designer');
            assert.equal(s.status, 'clean');
            assert.equal(await page.$eval('.cal-ws', (el) => el.dataset.calShownStep), '1');
            assert.equal(await page.$eval('[data-cal-step="1"]', (el) => el.getAttribute('aria-current')), 'step');
            clientUrl = await page.evaluate(() => JSON.parse(document.querySelector('[data-calendar-workspace-target="bootstrap"]').textContent).env.clientUrl);
            assert.match(clientUrl, /\/k\/[a-f0-9]{64}$/);
        });
        if (!id) return;

        await check('steps 3–5 are disabled without a layout', async () => {
            for (const n of [3, 4, 5]) assert.equal(await page.$eval(`[data-cal-step="${n}"]`, (el) => el.disabled), true, `step ${n}`);
            for (const n of [1, 2]) assert.equal(await page.$eval(`[data-cal-step="${n}"]`, (el) => el.disabled), false, `step ${n}`);
            assert.equal(await exists(page, '[data-cal-action="undo"]:not([hidden])') && !(await page.$eval('.cal-hist', (el) => el.hidden)), false, 'no undo / redo outside the editor');
        });

        await check('step 1: page counts on the type cards', async () => {
            const pages = (await h.cal(page, (c) => c.pages())).length;
            assert.match(await text(page, '[data-type="week"]'), new RegExp(`7 dní Týdenní Na každé straně jeden týden\\. ${pages} stran v roce ${nextYear}\\.`));
            assert.match(await text(page, '[data-type="fortnight"]'), /14 dní Dvojtýdenní Na každé straně dva týdny — poloviční počet stran\. \d+ stran v roce/);
            assert.equal(await page.$eval('[data-type="week"]', (el) => el.getAttribute('aria-checked')), 'true');
        });

        await check('14 days: step 2 shows no left / right choice and applies the single layout (9.6)', async () => {
            await page.click('[data-type="fortnight"]');
            assert.equal((await h.state(page)).design.type, 'fortnight');
            await h.gotoStep(page, 2);
            assert.equal(await exists(page, '[data-preset="photoLeft"]'), false);
            assert.equal(await exists(page, '[data-preset="photoRight"]'), false);
            assert.equal(await exists(page, 'button[data-preset]'), false, 'nothing to choose');
            assert.equal(await text(page, '[data-preset="twoSided"] b'), 'Dvoustranné rozvržení');
            await page.waitForSelector('[data-preset="twoSided"] .cal-layp svg');
            const s = await h.state(page);
            assert.equal(s.design.preset, 'twoSided');
            assert.equal(s.design.step, 2, 'the step is not skipped');
            assert.ok(s.design.layout.boxes.cal2, 'second frame exists');
            assert.doesNotMatch(await text(page, '.cal-ws'), /Naskenovat|AI|Premium/);
            for (const n of [3, 4, 5]) assert.equal(await page.$eval(`[data-cal-step="${n}"]`, (el) => el.disabled), false);
        });

        await check('type switch with a layout asks first; cancelling keeps the type', async () => {
            await h.gotoStep(page, 1);
            await page.click('[data-type="week"]');
            await page.waitForSelector('.cal-dialog.show');
            assert.equal(await text(page, '.cal-dialog .modal-title'), 'Změnit typ kalendáře?');
            assert.match(await text(page, '.cal-dialog .modal-body'), /^Rozmístění prvků se vrátí do výchozího rozvržení pro nový typ\. Písma, barvy a nastavení částí zůstanou\.$/);
            await h.answerDialog(page, 'cancel');
            assert.equal((await h.state(page)).design.type, 'fortnight');
            await page.click('[data-type="week"]');
            await h.answerDialog(page, 'confirm');
            const s = await h.state(page);
            assert.equal(s.design.type, 'week');
            assert.equal(s.design.preset, 'photoLeft');
            assert.equal(s.design.layout.boxes.cal2, undefined);
        });

        await check('year change renames a default name, keeps a custom one (G14)', async () => {
            await page.click(`[data-year="${nextYear + 1}"]`);
            let s = await h.state(page);
            assert.equal(s.design.year, nextYear + 1);
            assert.equal(s.design.name, `Stolní kalendář ${nextYear + 1}`);
            assert.equal(await page.$eval('[data-cal-name]', (el) => el.value), `Stolní kalendář ${nextYear + 1}`);
            assert.match(await text(page, '[data-type="week"]'), new RegExp(`v roce ${nextYear + 1}\\.`));
            await typeInto(page, '[data-cal-name]', 'Kalendář e2e');
            await page.keyboard.press('Enter');
            assert.equal(await page.evaluate(() => document.activeElement.matches('[data-cal-name]')), false, 'Enter leaves the field');
            await page.click(`[data-year="${nextYear}"]`);
            s = await h.state(page);
            assert.equal(s.design.year, nextYear);
            assert.equal(s.design.name, 'Kalendář e2e');
            assert.equal(await page.$eval(`[data-year="${nextYear}"]`, (el) => el.getAttribute('aria-pressed')), 'true');
        });

        await check('bleed is limited to 0–10 mm and resets to 5 mm when switched back on', async () => {
            const field = '[data-print="bleedMm"]';
            await typeInto(page, field, '99');
            await page.keyboard.press('Enter');
            assert.equal(await page.$eval(field, (el) => el.value), '10');
            assert.equal((await h.state(page)).design.print.bleedMm, 10);
            assert.equal((await h.boxes(page)).head.x, -10, 'bleed edges follow the bleed');
            await typeInto(page, field, '-3');
            await page.keyboard.press('Enter');
            assert.equal(await page.$eval(field, (el) => el.value), '0');
            await typeInto(page, field, '7,5');
            await page.keyboard.press('Enter');
            assert.equal((await h.state(page)).design.print.bleedMm, 7.5);
            await page.click('label:has([data-print="bleed"])');
            let s = await h.state(page);
            assert.equal(s.design.print.bleed, false);
            assert.equal(await page.$eval('.cal-pr .cal-num', (el) => el.hidden), true);
            assert.equal((await h.boxes(page)).head.x, 0, 'without bleed the edge is back on the trim');
            await page.click('label:has([data-print="bleed"])');
            s = await h.state(page);
            assert.deepEqual([s.design.print.bleed, s.design.print.bleedMm], [true, 5]);
            await page.click('label:has([data-print="marks"])');
            assert.equal((await h.state(page)).design.print.marks, false);
        });

        await check('Označit vše is tri-state', async () => {
            const all = '[data-feature-all]';
            const flags = () => page.$$eval('[data-feature]', (inputs) => inputs.map((i) => i.checked));
            assert.equal(await page.$eval(all, (el) => el.getAttribute('aria-checked')), 'mixed');
            assert.equal((await flags()).length, 10);
            await page.click(`label:has(${all})`);
            assert.ok((await flags()).every((v) => v === true), 'one click switches everything on');
            assert.equal(await page.$eval(all, (el) => el.checked && el.getAttribute('aria-checked') === null), true);
            await page.click(`label:has(${all})`);
            assert.ok((await flags()).every((v) => v === false), 'the next click switches everything off');
            assert.equal(await page.$eval(all, (el) => el.checked), false);
            for (const key of ['captions', 'weekNumbers', 'moon', 'events', 'qr']) await page.click(`label:has([data-feature="${key}"])`);
            assert.equal(await page.$eval(all, (el) => el.getAttribute('aria-checked')), 'mixed');
            const f = (await h.state(page)).design.features;
            assert.deepEqual(Object.keys(f).filter((k) => f[k]).sort(), ['captions', 'events', 'moon', 'qr', 'weekNumbers']);
        });

        await check('status text and Ctrl+S', async () => {
            assert.equal(await text(page, '[data-cal-status]'), 'Neuložené změny');
            assert.equal(await page.$eval('[data-cal-status]', (el) => el.dataset.calStatus), 'dirty');
            await page.keyboard.down('Control');
            await page.keyboard.press('s');
            await page.keyboard.up('Control');
            await h.waitStatus(page, 'clean');
            assert.equal(await text(page, '[data-cal-status]'), 'Vše uloženo');
            await h.waitToast(page, 'Kalendář uložen — najdete ho v seznamu kalendářů');
        });

        await check('step 2: the other card mirrors the layout as one undo step and opens step 3', async () => {
            await h.gotoStep(page, 2);
            await page.waitForSelector('[data-preset="photoLeft"] .cal-layp svg');
            await page.waitForSelector('[data-preset="photoRight"] .cal-layp svg');
            assert.equal(await text(page, '[data-preset="photoLeft"] .cal-type-b'), 'Fotka vlevo Fotografie vlevo, dny v týdnu vpravo');
            assert.equal(await text(page, '[data-preset="photoRight"] .cal-type-b'), 'Fotka vpravo Dny v týdnu vlevo, fotografie vpravo');
            assert.equal(await page.$eval('[data-preset="photoLeft"]', (el) => el.getAttribute('aria-checked')), 'true');
            const before = await h.boxes(page);
            assert.ok(before.photo.x < before.cal.x, 'photo on the left');
            await h.shot(page, 'step2');
            await page.click('[data-preset="photoRight"]');
            await page.waitForSelector('.cal-ws[data-cal-shown-step="3"]');
            const s = await h.state(page);
            const after = await h.boxes(page);
            const { width } = await h.cal(page, (c) => c.page());
            assert.equal(s.design.preset, 'photoRight');
            assert.equal(s.design.step, 3);
            assert.ok(after.photo.x > after.cal.x, 'photo on the right');
            h.expectBox(after.photo, { x: width - before.photo.x - before.photo.w, y: before.photo.y, w: before.photo.w, h: before.photo.h }, 0.11, 'mirrored photo');
            assert.equal(s.canUndo, true);
            await page.click('[data-cal-action="undo"]');
            h.expectBox((await h.boxes(page)).photo, before.photo, 0.11, 'undo restores the unmirrored photo');
            assert.equal((await h.state(page)).design.preset, 'photoLeft', 'one undo takes the whole mirroring back');
            await page.click('[data-cal-action="redo"]');
            assert.equal((await h.state(page)).design.preset, 'photoRight');
        });

        await check('save → reload keeps everything of steps 1–2 (R4)', async () => {
            await h.save(page);
            saved = (await h.state(page)).design;
            await h.reload(page);
            const s = await h.state(page);
            assert.deepEqual(s.design, saved);
            assert.equal(s.status, 'clean');
            assert.equal(await page.$eval('.cal-ws', (el) => el.dataset.calShownStep), '3', 'reopens where the user left');
            assert.equal(s.design.name, 'Kalendář e2e');
            assert.equal(s.design.preset, 'photoRight');
            assert.deepEqual(s.design.print, { bleed: true, bleedMm: 5, marks: false });
        });

        await check('steps 3–5 mount and the stage answers the hook', async () => {
            assert.ok((await h.cal(page, (c) => c.scale())) > 0, 'step 3 has a stage');
            const corner = await h.cal(page, (c) => c.toClient(0, 0));
            const far = await h.cal(page, (c) => c.toClient(100, 0));
            const scale = await h.cal(page, (c) => c.scale());
            assert.ok(Math.abs(far.x - corner.x - 100 * scale) < 0.5);
            assert.ok(await h.cal(page, (c) => c.geometry().frames.length === 1));
            await page.click('[data-layer="month"]');
            assert.deepEqual((await h.state(page)).selection, { el: 'month' });
            await h.shot(page, 'step3');
            await h.gotoStep(page, 4);
            await page.waitForSelector('[data-tool="photos"]');
            assert.equal(await page.$$eval('[data-tool]', (nodes) => nodes.length), 7);
            await page.waitForSelector('[data-page-thumb="1"] svg');
            await h.shot(page, 'step4');
            await h.gotoStep(page, 5);
            await page.waitForSelector('.cal-b6 .cal-page-svg svg');
            assert.equal(await text(page, '[data-cal-action="next-step"]'), 'Dokončit');
            await h.shot(page, 'step5');
            // a tool whose feature is off offers Zapnout; the step-1 switch follows
            await page.click('[data-tool="waste"]');
            await page.waitForSelector('.cal-tool-modal.show [data-cal-action="feature-on"]');
            assert.equal(await text(page, '.cal-tool-off p'), 'Odpady jsou vypnuté v kroku 1, v kalendáři se nezobrazí.');
            await h.sleep(350);
            await page.click('.cal-tool-modal [data-cal-action="feature-on"]');
            assert.equal((await h.state(page)).design.features.waste, true);
            await page.click('.cal-tool-modal [data-cal-action="modal-done"]');
            await page.waitForFunction(() => !document.querySelector('.cal-tool-modal'));
            await h.save(page);
        });

        await check('leaving with unsaved changes asks: stay, then leave without saving', async () => {
            await typeInto(page, '[data-cal-name]', 'Neuložený název');
            await page.keyboard.press('Enter');
            await h.waitStatus(page, 'dirty');
            await page.click('[data-cal-action="back-to-list"]');
            await page.waitForSelector('.cal-dialog.show');
            assert.equal(await text(page, '.cal-dialog .modal-title'), 'Neuložené změny');
            assert.equal(await text(page, '.cal-dialog .modal-body'), 'Kalendář má neuložené změny.');
            assert.deepEqual(await page.$$eval('.cal-dialog [data-cal-choice]', (nodes) => nodes.map((n) => n.textContent.trim()).sort()), ['Odejít bez uložení', 'Uložit a odejít', 'Zůstat']);
            await h.answerDialog(page, 'stay');
            assert.match(page.url(), new RegExp(`/calendar/${id}$`));
            await page.click('[data-cal-action="back-to-list"]');
            await h.sleep(100);
            await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2' }), h.answerDialog(page, 'discard').catch(() => null)]);
            assert.match(page.url(), /\/calendars$/);
            await page.waitForSelector('[data-controller="calendar-list"][data-cal-ready="1"]');
            assert.equal(await text(page, `.cal-card[data-calendar-id="${id}"] .cal-name`), 'Kalendář e2e');
        });

        await check('list card of an unfinished calendar: thumbnail, summary, pill, last change', async () => {
            const card = `.cal-card[data-calendar-id="${id}"]`;
            await page.waitForSelector(`${card} .cal-thumb svg`);
            assert.match(await text(page, `${card} .cal-meta`), new RegExp(`^Týdenní · ${nextYear} · fotka vpravo · \\d+ stran$`));
            assert.equal(await text(page, `${card} .cal-pill-draft`), 'Rozpracovaný vzhled');
            assert.match(await text(page, `${card} [data-cal-action="history"]`), /^Grafik · (právě teď|před \d+ min)$/);
            assert.equal(await page.$eval(`${card} .cal-thumb svg`, (svg) => svg.getAttribute('data-page')), 'pre', 'the first week page');
            await h.shot(page, 'list');
        });

        await check('the last-change line opens Historie', async () => {
            await page.click(`.cal-card[data-calendar-id="${id}"] [data-cal-action="history"]`);
            await page.waitForSelector('[data-cal-dialog="history"].show .cal-hrow');
            assert.equal(await text(page, '[data-cal-dialog="history"] .modal-title'), 'Historie — Kalendář e2e');
            const rows = await page.$$eval('[data-cal-dialog="history"] .cal-hrow', (nodes) => nodes.map((n) => n.textContent.replace(/\s+/g, ' ').trim()));
            assert.ok(rows.some((row) => /Grafik.*Vytvořil kalendář/.test(row)), rows.join(' / '));
            await h.sleep(350);
            await page.click('[data-cal-dialog="history"] [data-cal-action="modal-done"]');
            await page.waitForFunction(() => !document.querySelector('[data-cal-dialog="history"]'));
        });

        await check('Dokončit saves, returns to the list with a toast; the card loses the pill (R5)', async () => {
            await h.openCalendar(page, id);
            assert.equal(await page.$eval('.cal-ws', (el) => el.dataset.calShownStep), '5', 'the unfinished calendar reopens in the step where the user left');
            const pages = (await h.cal(page, (c) => c.pages())).length;
            await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2' }), page.click('[data-cal-action="next-step"]')]);
            assert.match(page.url(), /\/calendars$/);
            await h.waitToast(page, `Kalendář poskládán — ${pages} stran`);
            const card = `.cal-card[data-calendar-id="${id}"]`;
            assert.equal(await exists(page, `${card} .cal-pill-draft`), false);
            await page.waitForSelector(`${card} .cal-thumb svg`);
        });

        await check('a finished calendar opens in step 5 and offers Uložit vzhled (R5)', async () => {
            await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2' }), page.click(`.cal-card[data-calendar-id="${id}"] .cal-card-link`)]);
            await h.waitReady(page);
            const s = await h.state(page);
            assert.equal(s.design.finished, true);
            assert.equal(s.design.step, 5);
            assert.equal(await page.$eval('.cal-ws', (el) => el.dataset.calShownStep), '5');
            assert.equal(await text(page, '[data-cal-action="next-step"]'), 'Uložit vzhled');
            assert.deepEqual({ ...s.design, step: 0, finished: false, features: { ...s.design.features, waste: false } }, { ...saved, step: 0, finished: false, features: { ...saved.features, waste: false } }, 'the design survived every round trip');
            await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2' }), page.click('[data-cal-action="next-step"]')]);
            await h.waitToast(page, 'Vzhled uložen — všechny strany přeskládány');
        });

        await check('client link: step 5 only, no steps, no design controls', async () => {
            await page.goto(clientUrl, { waitUntil: 'networkidle2' });
            await h.waitReady(page);
            const s = await h.state(page);
            assert.equal(s.mode, 'client');
            assert.equal(await page.$eval('.cal-ws', (el) => el.dataset.calShownStep), '5');
            for (const selector of ['[data-cal-step]', '[data-cal-action="next-step"]', '[data-cal-action="prev-step"]', '[data-cal-action="undo"]', '[data-cal-action="client-link"]', 'input[data-cal-name]', '[data-cal-action="back-to-list"]']) {
                assert.equal(await exists(page, selector), false, selector);
            }
            assert.equal(await text(page, '[data-cal-name]'), 'Kalendář e2e');
            assert.deepEqual(await page.$$eval('[data-tool]', (nodes) => nodes.map((n) => n.dataset.tool)), ['photos', 'waste', 'events', 'names'], 'the four client tools (their features are on)');
            assert.equal(await exists(page, '[data-cal-action="save"]'), true);
            await h.shot(page, 'client');
        });

        let second = null;
        await check('year change with page content asks first; 29 February is dropped with a message', async () => {
            second = await h.createCalendar(page);
            created.push(second);
            const leap = [nextYear - 1, nextYear, nextYear + 1].find((y) => y % 4 === 0);
            if (!leap) return;
            const other = leap === nextYear ? nextYear + 1 : nextYear;
            if (leap !== nextYear) await page.click(`[data-year="${leap}"]`);
            await h.save(page).catch(() => null);
            await h.seed(page, {
                content: [
                    { c: 'pageCaptions', id: 'w05', v: { text: 'Rybník v zimě' }, label: '5. týden' },
                    { c: 'events', id: 'leapday', v: { date: `${leap}-02-29`, name: 'Přestupný den', club: null, icon: null }, label: '29. 2.' },
                    { c: 'events', id: 'kids', v: { date: `${leap}-06-01`, name: 'Den dětí', club: null, icon: null }, label: '1. 6.' },
                ],
            });
            await page.click(`[data-year="${other}"]`);
            await page.waitForSelector('.cal-dialog.show');
            assert.equal(await text(page, '.cal-dialog .modal-title'), 'Změnit rok?');
            assert.equal(await text(page, '.cal-dialog .modal-body'), 'Fotky a popisky zůstanou u týdnů se stejným číslem. Akce, jména a svozy se přesunou na stejný den nového roku.');
            await h.answerDialog(page, 'cancel');
            assert.equal((await h.state(page)).design.year, leap);
            await page.click(`[data-year="${other}"]`);
            await h.answerDialog(page, 'confirm');
            await h.waitToast(page, `29\\. 2\\. v roce ${other} není — odebráno položek: 1`);
            let s = await h.state(page);
            assert.equal(s.design.year, other);
            assert.deepEqual(Object.keys(s.content.events), ['kids']);
            assert.equal(s.content.events.kids.date, `${other}-06-01`);
            assert.equal(s.content.pageCaptions.w05.text, 'Rybník v zimě');
            await h.save(page);
            await h.reload(page);
            s = await h.state(page);
            assert.deepEqual([s.design.year, Object.keys(s.content.events), s.content.events.kids.date], [other, ['kids'], `${other}-06-01`]);
            if (other !== nextYear) {
                await page.click(`[data-year="${nextYear}"]`);
                await h.answerDialog(page, 'confirm');
                await h.save(page);
            }
        });

        await check('an unsaved page arms beforeunload; Uložit a odejít saves before leaving', async () => {
            await h.openCalendar(page, second);
            await typeInto(page, '[data-cal-name]', 'Druhý e2e');
            await page.keyboard.press('Enter');
            await h.waitStatus(page, 'dirty');
            const prompt = new Promise((resolve) => page.once('dialog', resolve));
            page.evaluate(() => window.location.reload()).catch(() => null);
            const dialog = await Promise.race([prompt, h.sleep(4000).then(() => null)]);
            assert.ok(dialog, 'the browser asks before unloading');
            assert.equal(dialog.type(), 'beforeunload');
            await dialog.dismiss();
            assert.equal((await h.state(page)).status, 'dirty');
            await page.click('[data-cal-action="back-to-list"]');
            await h.sleep(100);
            await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2' }), h.answerDialog(page, 'save').catch(() => null)]);
            assert.match(page.url(), /\/calendars$/);
            assert.equal(await text(page, `.cal-card[data-calendar-id="${second}"] .cal-name`), 'Druhý e2e');
        });

        await check('a new calendar is listed as unfinished, without a layout word', async () => {
            await h.gotoList(page);
            const card = `.cal-card[data-calendar-id="${second}"]`;
            assert.match(await text(page, `${card} .cal-meta`), new RegExp(`^Týdenní · ${nextYear} · \\d+ stran$`));
            assert.equal(await exists(page, `${card} .cal-pill-draft`), true);
            await page.waitForSelector(`${card} .cal-thumb svg`);
        });

        await check('delete asks for confirmation; cancelling keeps the calendar', async () => {
            const card = `.cal-card[data-calendar-id="${id}"]`;
            await page.click(`${card} .cal-del`);
            await page.waitForSelector(`#cal-delete-${id}.show`);
            assert.equal(await text(page, `#cal-delete-${id} .modal-title`), 'Smazat kalendář?');
            assert.equal(await text(page, `#cal-delete-${id} .modal-body`), 'Kalendář „Kalendář e2e“ se smaže včetně fotek a obsahu. Tuto akci nelze vrátit.');
            await h.sleep(350);
            await page.click(`#cal-delete-${id} [data-bs-dismiss="modal"].btn-tertiary`);
            await page.waitForFunction((sel) => !document.querySelector(sel), {}, `#cal-delete-${id}.show`);
            assert.equal(await exists(page, card), true);
            assert.equal(await h.deleteCalendar(page, id), true);
            assert.equal(await exists(page, card), false);
            assert.match(await text(page, '.alert'), /Kalendář „Kalendář e2e“ smazán/);
            assert.equal((await fetch(clientUrl)).status, 404, 'the client link dies with the calendar');
        });

        await check('empty state offers Vytvořit kalendář', async () => {
            for (const other of created.filter((c) => c !== id)) await h.deleteCalendar(page, other);
            await h.gotoList(page);
            assert.equal(await text(page, '.cal-empty h2'), 'Zatím tu není žádný kalendář');
            assert.equal(await exists(page, '.cal-empty [data-cal-action="create"]'), true);
            await h.shot(page, 'list-empty');
        });
    },
};
