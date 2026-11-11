/*
 * Kalendárium panels and part chrome (handoff §6.1 "Kalendárium", §6.2, §8; checks E12, E13, E15, E16
 * of §9.2). Phase P5b. Real pointer for everything that is dragged on the page or in a list; every
 * control is asserted through the stored style AND through what is drawn (`__calendar.geometry()` /
 * the page SVG). Data-changing scenarios save, reload and re-assert.
 */
const assert = require('assert/strict');
const e = require('./lib/editor.cjs');

const near = (a, b, tol = 0.11) => Math.abs(a - b) <= tol;
const prop = (path) => `[data-prop="${path}"]`;

module.exports = {
    name: 'kalendarium',
    checks: ['E12', 'E13', 'E15', 'E16'],
    async run({ page, h, check }) {
        const created = [];
        let week = null; // { id, design } of the 7-day calendar with content
        let days = []; // dates of the sample week

        const styles = async () => (await h.state(page)).design.layout.styles;
        const content = async () => (await h.state(page)).content;
        const geometry = (index) => h.cal(page, (c, i) => c.geometry(i === null ? undefined : i), index === undefined ? null : index);
        const panel = () => page.$eval('[data-kal-panel]', (el) => el.dataset.kalPanel).catch(() => null);
        const title = () => page.$eval('.cal-props-kal [data-props-title]', (el) => el.textContent);
        const exists = async (selector) => (await page.$(selector)) !== null;
        const shown = (selector) => page.evaluate((s) => [...document.querySelectorAll(s)].some((el) => el.offsetParent !== null), selector);
        const undo = () => page.click('[data-cal-action="undo"]');
        const redo = () => page.click('[data-cal-action="redo"]');
        const canUndo = async () => (await h.state(page)).canUndo;

        async function selectBlock() {
            await e.selectLayer(page, h, 'cal');
            await page.waitForSelector('[data-kal-panel="block"]', { timeout: 5000 });
        }

        async function selectPart(part) {
            const now = await e.selection(page, h);
            if (!now || now.el !== 'cal') await e.selectLayer(page, h, 'cal');
            // a click on the row of the selected part would deselect it
            if (!now || now.el !== 'cal' || now.part !== part) await page.click(`[data-part-row="${part}"]`);
            await page.waitForSelector(`[data-kal-panel="${part}"]`, { timeout: 5000 });
        }

        async function type(selector, text) {
            await page.$eval(selector, (el) => {
                el.scrollIntoView({ block: 'center' });
                el.focus();
                el.select();
            });
            await page.keyboard.press('Backspace');
            if (text !== '') await page.keyboard.type(text);
            await page.keyboard.press('Enter');
            await h.sleep(40);
        }

        async function click(selector) {
            await page.$eval(selector, (el) => el.scrollIntoView({ block: 'center' }));
            await page.click(selector);
            await h.sleep(40);
        }

        /** Clicks the label of a hidden native checkbox / switch. */
        async function toggle(selector) {
            await page.$eval(selector, (el) => el.closest('label').scrollIntoView({ block: 'center' }));
            await page.evaluate((s) => document.querySelector(s).closest('label').click(), selector);
            await h.sleep(40);
        }

        const centreOf = (selector) =>
            page.$eval(selector, (el) => {
                const r = el.getBoundingClientRect();
                return { x: r.left + r.width / 2, y: r.top + r.height / 2 };
            });

        /** Drags the element behind `selector` by mm with the real mouse. */
        async function dragBy(selector, dxMm, dyMm, { hold = false, alt = false } = {}) {
            const scale = await h.cal(page, (c) => c.scale());
            const from = await centreOf(selector);
            if (alt) await page.keyboard.down('Alt');
            await page.mouse.move(from.x, from.y);
            await page.mouse.down();
            await page.mouse.move(from.x + dxMm * scale, from.y + dyMm * scale, { steps: 12 });
            if (!hold) {
                await page.mouse.up();
                if (alt) await page.keyboard.up('Alt');
            }
            await h.sleep(60);
        }

        /** `{ date: rect }` of a part in a frame of a page. */
        async function partRects(part, frame = 0, index) {
            const out = {};
            for (const row of (await geometry(index)).frames[frame].rows) {
                const found = row.parts.find((p) => p.key === part);
                if (found) out[row.date] = found.rect;
            }
            return out;
        }

        /** Drawn texts of one part on the stage: [{ date, text, size, fill }]. */
        const drawn = (part) =>
            page.$$eval(`.cal-cv svg[data-page] [data-el="cal"] text[data-part="${part}"]`, (nodes) =>
                nodes.map((n) => ({ date: n.closest('[data-date]').dataset.date, frame: n.closest('[data-frame]').dataset.frame, text: n.textContent, size: Number(n.getAttribute('font-size')), fill: n.getAttribute('fill') })),
            );

        const evBoxes = async (frame = 0, index) => (await geometry(index)).frames[frame].rows.map((row) => row.eventBox);
        const evLines = async (date) => (await drawn('ev')).filter((t) => t.date === date).length;

        async function seedContent(design, features, items) {
            await page.waitForNetworkIdle({ idleTime: 200, timeout: 8000 }).catch(() => null);
            await h.seed(page, { design: { features: { ...design.features, ...features }, step: 3 }, content: items });
            await page.waitForSelector('.cal-ws[data-cal-shown-step="3"]', { timeout: 10000 });
            return (await h.state(page)).design;
        }

        // a failed check may have left the button down: every check starts with a free pointer
        // KAL_ONLY=<regex> runs the setup checks and the matching ones only (a development aid)
        const only = process.env.KAL_ONLY ? new RegExp(process.env.KAL_ONLY) : null;
        const run = check;
        check = async (id, fn) => {
            if (only && !only.test(id) && !/^setup/.test(id)) return null;
            return run(id, async () => {
                await page.mouse.up().catch(() => null);
                await page.keyboard.up('Alt').catch(() => null);
                await fn();
            });
        };

        /** Waits until `test()` holds (an upload answered, a list was rebuilt). */
        async function until(test, label, timeout = 8000) {
            const end = Date.now() + timeout;
            while (Date.now() < end) {
                if (await test()) return;
                await h.sleep(60);
            }
            assert.fail(`timed out: ${label}`);
        }

        // ------------------------------------------------------------------------------------------

        await check('setup: a 7-day calendar with events, waste, shifts, time and season changes', async () => {
            const cal = await e.newCalendar(page, h);
            created.push(cal.id);
            days = await h.cal(page, (c) => c.pages().find((p) => p.kind === 'week').week.days);
            // the sample week starts in December of the year before: the rule must reach back to it
            const from = `${cal.design.year - 1}-12-01`;
            const kind = (name, builtin, pos) => ({ builtin, name, on: true, place: pos === 0 ? 'U hřbitova' : '', mode: 'rule', weekdays: [0, 1, 2, 3, 4, 5, 6], interval: 'w', from, to: null, dates: [], pos });
            const design = await seedContent(cal.design, { events: true, waste: true, shifts: true, timeChange: true, seasons: true }, [
                { c: 'wasteKinds', id: 'bio', v: kind('Bio odpad', 'bio', 0) },
                { c: 'wasteKinds', id: 'plast', v: kind('Plast', 'plast', 1) },
                { c: 'events', id: 'evLongEvent0001', v: { date: days[1], name: 'Slavnostní rozsvícení vánočního stromu na náměstí s koncertem žáků ZUŠ', club: null, icon: null } },
                { c: 'events', id: 'evShortEvent001', v: { date: days[3], name: 'Ples', club: null, icon: null } },
                { c: 'shiftOps', id: 'opProvoz0000001', v: { name: 'Provoz 1', shifts: [{ letter: 'R', from: '06:00', to: '14:00' }, { letter: 'O', from: '14:00', to: '22:00' }, { letter: 'N', from: '22:00', to: '06:00' }], crews: ['A', 'B', 'C', 'D'], pos: 0 } },
            ]);
            week = { id: cal.id, design };
            assert.equal(design.features.events, true);
            assert.equal((await content()).wasteKinds.bio.on, true);
        });
        if (!week) return;

        await check('E12 a click on the Kalendárium selects the whole block, a second click on a day number selects every workday number; the back arrow returns', async () => {
            const numbers = await partRects('num');
            const dates = Object.keys(numbers);
            assert.ok(dates.length >= 3, 'the sample week has workdays');
            const at = e.centre(numbers[dates[0]]);
            await e.clickMm(page, h, at);
            assert.deepEqual(await e.selection(page, h), { el: 'cal', frame: 0 }, 'first click: the whole block');
            assert.equal(await panel(), 'block');
            assert.equal(await title(), 'Kalendárium');
            assert.equal(await page.$eval('.cal-props-kal .cal-pph .cap', (el) => el.textContent), 'Platí pro všechny strany kalendáře');
            assert.equal(await page.$$eval('.cal-props-kal [data-field]', (n) => n.length), 4, 'Pozice a velikost');
            assert.equal(await exists('[data-cal-stub]'), false, 'no stub left in the editor');
            await h.shot(page, 'kal-01-block-week');

            await e.clickMm(page, h, at);
            assert.deepEqual(await e.selection(page, h), { el: 'cal', frame: 0, part: 'num' });
            assert.equal(await panel(), 'num');
            assert.equal(await title(), 'Číslo — pracovní dny');
            assert.equal(await page.$eval('.cal-props-kal .cal-pph .cap', (el) => el.textContent), 'Kalendárium · platí pro všechny dny');
            assert.equal(await page.$$eval('[data-part-box="num"]', (n) => n.length), dates.length, 'every workday number is highlighted');
            assert.equal(await page.$eval('[data-frame-tag]', (el) => el.textContent), 'Kalendárium › Číslo — pracovní dny');
            for (const hook of ['num.family', 'num.weight', 'num.size', 'num.color', 'cal.numAlign']) assert.ok(await exists(prop(hook)), `${hook} control`);
            assert.equal(await page.$eval('[data-cal-action="part-back"]', (el) => el.getAttribute('aria-label')), 'Zpět na kalendárium');
            await h.shot(page, 'kal-02-number-part');

            await page.click('[data-cal-action="part-back"]');
            assert.deepEqual(await e.selection(page, h), { el: 'cal', frame: 0 });
            assert.equal(await panel(), 'block');
            // every always-present part has its panel with the prototype's name
            for (const [key, name] of [['numSat', 'Číslo — sobota'], ['numSun', 'Číslo — neděle'], ['numHol', 'Číslo — svátky'], ['day', 'Den v týdnu'], ['name', 'Jména'], ['hol', 'Svátek'], ['line', 'Linky'], ['icon', 'Ikony']]) {
                await selectPart(key);
                assert.equal(await title(), name);
            }
            await page.keyboard.press('Escape');
            assert.equal(await panel(), 'block', 'Esc returns to the whole block');
        });

        await check('E13 colour and size of the workday number change every workday number on the sample and on other week pages; Saturday, Sunday and holiday numbers keep theirs', async () => {
            await e.restore(page, h, week.design);
            await selectPart('num');
            const before = await styles();
            const other = await h.cal(page, (c) => c.pages().filter((p) => p.kind === 'week')[9].index);
            const satBefore = await drawn('numSat');
            const sunBefore = [...(await drawn('numSun')), ...(await drawn('numHol'))];
            const otherBefore = { num: await partRects('num', 0, other), numSat: await partRects('numSat', 0, other) };
            assert.equal(before.num.size, 28);

            await type(prop('num.size'), '18');
            await click(`${prop('num.color')} [data-color="#2F78B7"]`);
            const after = await styles();
            assert.equal(after.num.size, 18);
            assert.equal(after.num.color, '#2F78B7');
            for (const key of ['numSat', 'numSun', 'numHol']) assert.deepEqual(after[key], before[key], `${key} keeps its style`);

            const numbers = await drawn('num');
            assert.ok(numbers.length >= 3);
            const mm = (18 * 25.4) / 72;
            for (const n of numbers) {
                assert.ok(near(n.size, mm, 0.02), `${n.date}: drawn at ${n.size} mm, expected ${mm}`);
                assert.equal(n.fill, '#2F78B7');
            }
            assert.deepEqual(await drawn('numSat'), satBefore, 'the Saturday number is untouched');
            assert.deepEqual([...(await drawn('numSun')), ...(await drawn('numHol'))], sunBefore, 'Sunday / holiday numbers are untouched');
            const otherNum = await partRects('num', 0, other);
            for (const date of Object.keys(otherNum)) assert.ok(otherNum[date].h < otherBefore.num[date].h - 1, `another week page (${date}) follows`);
            assert.deepEqual(await partRects('numSat', 0, other), otherBefore.numSat, 'Saturday on another page stays');
            await h.shot(page, 'kal-03-number-styled');

            // undo: two steps
            await undo();
            assert.equal((await styles()).num.color, before.num.color);
            assert.equal((await styles()).num.size, 18);
            assert.equal(await panel(), 'num', 'the part panel survives an undo');
            await undo();
            assert.equal((await styles()).num.size, 28);
            assert.equal(await canUndo(), false);
            await redo();
            await redo();

            // one shared alignment for all numbers
            await click(`${prop('cal.numAlign')} [data-value="start"]`);
            assert.equal((await styles()).cal.numAlign, 'start');
            const col = (await geometry()).frames[0].numberColumn;
            for (const rect of Object.values(await partRects('numSat'))) assert.ok(near(rect.x, col.x, 0.3), 'Saturday number is left-aligned in the number column too');

            // the other three number styles are separate
            await selectPart('numSun');
            await type(prop('numSun.size'), '34');
            assert.equal((await styles()).numSun.size, 34);
            assert.equal((await styles()).num.size, 18);
            assert.equal((await styles()).numHol.size, 28);

            await h.save(page);
            await h.reload(page);
            const saved = await styles();
            assert.equal(saved.num.size, 18);
            assert.equal(saved.num.color, '#2F78B7');
            assert.equal(saved.numSun.size, 34);
            assert.equal(saved.cal.numAlign, 'start');
            assert.ok((await drawn('num')).every((n) => n.fill === '#2F78B7'), 'after reload');
        });

        await check('whole block: Názvy dnů, Den v týdnu (+ its alignment), Číslo dne, background with Bez barvy + opacity', async () => {
            await e.restore(page, h, week.design);
            await selectBlock();
            assert.deepEqual(await page.$$eval('.cal-props-kal .cal-psec > .lbl', (n) => n.map((x) => x.textContent)), ['Pozice a velikost', 'Názvy dnů', 'Den v týdnu', 'Číslo dne', 'Pozadí kalendária', 'Velikosti']);

            await click(`${prop('cal.dayFormat')} [data-value="short"]`);
            assert.equal((await styles()).cal.dayFormat, 'short');
            assert.deepEqual((await drawn('day')).map((t) => t.text), ['Po', 'Út', 'St', 'Čt', 'Pá', 'So', 'Ne']);

            assert.equal(await shown(prop('cal.dayAlign')), false, 'Zarovnání dne only with „Nad číslem“');
            const dayBefore = await partRects('day');
            await click(`${prop('cal.dayPosition')} [data-value="above"]`);
            assert.equal((await styles()).cal.dayPosition, 'above');
            assert.equal(await shown(prop('cal.dayAlign')), true);
            let g = await geometry();
            const numCol = g.frames[0].numberColumn;
            for (const [date, rect] of Object.entries(await partRects('day'))) {
                assert.ok(rect.x >= numCol.x - 0.3 && rect.x + rect.w <= numCol.x + numCol.w + 0.3, `${date}: the weekday sits over the number`);
                assert.ok(Math.abs(rect.x - dayBefore[date].x) > 5, 'it left the text column');
            }
            assert.equal(await page.$eval(`${prop('cal.dayAlign')} [aria-pressed="true"]`, (el) => el.dataset.value), 'end', 'shows what the number side gives');
            await click(`${prop('cal.dayAlign')} [data-value="start"]`);
            assert.equal((await styles()).cal.dayAlign, 'start');
            for (const rect of Object.values(await partRects('day'))) assert.ok(near(rect.x, numCol.x, 0.3), 'weekday left-aligned over the number');
            await h.shot(page, 'kal-04-block-day-above');

            assert.equal(g.frames[0].zoneLeft, true);
            await click(`${prop('cal.numberSide')} [data-value="start"]`);
            assert.equal((await styles()).cal.numberSide, 'start');
            g = await geometry();
            assert.equal(g.frames[0].zoneLeft, false, 'the icon zone moved to the other side');
            assert.ok(g.frames[0].numberColumn.x < g.frames[0].textColumn.x, 'numbers on the left of the text');
            await click(`${prop('cal.numberSide')} [data-value="middle"]`);
            g = await geometry();
            assert.ok(near(g.frames[0].numberColumn.x + g.frames[0].numberColumn.w / 2, g.frames[0].inner.x + g.frames[0].inner.w / 2, 0.2), 'numbers in the middle');

            assert.equal(await shown(prop('cal.bg.opacity')), false, 'no opacity without a colour');
            assert.equal(await page.$eval(`${prop('cal.bg.color')} [data-color=""]`, (el) => el.getAttribute('aria-checked')), 'true', 'Bez barvy is the default');
            await click(`${prop('cal.bg.color')} [data-color="#CBC7D1"]`);
            assert.equal((await styles()).cal.bg.color, '#CBC7D1');
            assert.equal(await shown(prop('cal.bg.opacity')), true);
            await click(`${prop('cal.bg.color')} [data-color=""]`);
            assert.equal((await styles()).cal.bg.color, null);
            assert.equal(await shown(prop('cal.bg.opacity')), false);
        });

        await check('Velikosti: one slider per enabled text kind; the Čísla slider sets all four number styles and is ONE undo step', async () => {
            await e.restore(page, h, week.design);
            await selectBlock();
            assert.deepEqual(
                await page.$$eval('.cal-szl .cal-szi', (rows) => rows.filter((r) => !r.hidden).map((r) => r.querySelector('.cal-szt').textContent)),
                ['Čísla', 'Den v týdnu', 'Jména', 'Svátek', 'Změna času', 'Akce', 'Šichtovnice', 'Ikony', 'Fáze měsíce'],
            );
            // a discrete change first, so one undo can be told apart from "everything undone"
            await click(`${prop('cal.dayFormat')} [data-value="short"]`);
            const slider = await page.$('[data-size="num"]');
            await slider.evaluate((el) => el.scrollIntoView({ block: 'center' }));
            const box = await slider.boundingBox();
            const y = box.y + box.height / 2;
            const x0 = box.x + ((28 - 8) / 32) * box.width;
            await page.mouse.move(x0, y);
            await page.mouse.down();
            const seen = new Set();
            for (let i = 1; i <= 8; i++) {
                await page.mouse.move(x0 - (i * box.width) / 22, y);
                await h.sleep(25);
                seen.add((await styles()).num.size);
            }
            await page.mouse.up();
            await h.sleep(60);
            assert.ok(seen.size >= 3, `live while dragging: ${[...seen].join(', ')}`);
            const s = await styles();
            assert.ok(s.num.size < 24 && s.num.size >= 8, `Čísla ${s.num.size}`);
            for (const key of ['numSat', 'numSun', 'numHol']) assert.equal(s[key].size, s.num.size, `${key} follows the Čísla slider`);
            assert.equal(await page.$eval('label:has([data-size="num"]) .cal-szv', (el) => el.textContent), `${String(s.num.size).replace('.', ',')} pt`);
            await undo();
            const back = await styles();
            for (const key of ['num', 'numSat', 'numSun', 'numHol']) assert.equal(back[key].size, 28, `${key} restored by ONE undo`);
            assert.equal(back.cal.dayFormat, 'short', '… and nothing more');
            assert.equal(Number(await page.$eval('[data-size="num"]', (el) => el.value)), 28, 'the slider follows the undo');
        });

        await check('Den v týdnu, Jména, Svátek, Linky panels change what is drawn', async () => {
            await e.restore(page, h, week.design);
            await selectPart('day');
            assert.deepEqual(await page.$$eval('.cal-props-kal .cal-psec > .lbl', (n) => n.map((x) => x.textContent)), ['Písmo', 'Barva — všední dny', 'Sobota', 'Neděle a státní svátky']);
            await click(`${prop('cal.satColor')} [data-color="#2B2830"]`);
            await click(`${prop('cal.sunColor')} [data-color="#6E6977"]`);
            await click(`${prop('day.color')} [data-color="#BE3A2E"]`);
            const dayTexts = await drawn('day');
            const info = Object.fromEntries((await geometry()).frames[0].rows.map((row) => [row.date, row.info]));
            for (const t of dayTexts) {
                const i = info[t.date];
                assert.equal(t.fill, i.holiday || i.wd === 6 ? '#6E6977' : i.wd === 5 ? '#2B2830' : '#BE3A2E', `${t.date} weekday colour`);
            }

            await selectPart('name');
            await type(prop('name.size'), '12');
            assert.ok((await drawn('name')).every((t) => near(t.size, (12 * 25.4) / 72, 0.02)));
            await type(prop('name.size'), '99');
            assert.equal((await styles()).name.size, 60, 'limited to 60 pt');

            await selectPart('line');
            assert.equal(await page.$$eval('.cal-cv svg[data-page] [data-el="cal"] line[data-part="line"]', (n) => n.length), 7);
            await type(prop('lines.width'), '2,5');
            assert.equal((await styles()).lines.width, 2.5);
            await click(`${prop('lines.style')} [data-value="dash"]`);
            assert.equal((await styles()).lines.style, 'dash');
            assert.ok(await page.$eval('.cal-cv svg[data-page] [data-el="cal"] line[data-part="line"]', (el) => el.hasAttribute('stroke-dasharray')), 'dashed on the page');
            await click(`${prop('lines.color')} [data-color="#BE3A2E"]`);
            assert.equal(await page.$eval('.cal-cv svg[data-page] [data-el="cal"] line[data-part="line"]', (el) => el.getAttribute('stroke')), '#BE3A2E');
            await toggle(prop('lines.on'));
            assert.equal((await styles()).lines.on, false);
            assert.equal(await page.$$eval('.cal-cv svg[data-page] [data-el="cal"] line[data-part="line"]', (n) => n.length), 0, 'no lines drawn');
            assert.equal(await shown(prop('lines.width')), false, 'the line settings hide with the switch');
            assert.equal(await panel(), 'line', 'the part stays selected although nothing of it is drawn');
        });

        await check('Svátek: two side bars set the wrap width (one undo step)', async () => {
            await e.restore(page, h, week.design);
            await selectPart('hol');
            assert.equal(await page.$$eval('[data-holwrap-bar]', (n) => n.length), 2);
            assert.equal(await exists('[data-evbox]'), false, 'no event box while Svátek is selected');
            const frame = (await geometry()).frames[0];
            assert.equal((await styles()).hol.wrapWidth, null);
            const left = await centreOf('[data-holwrap-bar="start"]');
            const expectLeft = await h.cal(page, (c, x) => c.toClient(x, 0).x, frame.holWrap.x);
            assert.ok(Math.abs(left.x - expectLeft) <= 1, 'the bar stands at the wrap edge');
            await dragBy('[data-holwrap-bar="start"]', 12, 0);
            const width = (await styles()).hol.wrapWidth;
            assert.ok(near(width, frame.holWrap.w - 12, 0.25), `wrap width ${width}, expected ${frame.holWrap.w - 12}`);
            assert.ok(near((await geometry()).frames[0].holWrap.w, width, 0.11), 'the layout uses it');
            assert.deepEqual(await e.selection(page, h), { el: 'cal', frame: 0, part: 'hol' });
            await e.assertBoxes(await e.allBoxes(page, h), week.design.layout.boxes, 'no element moved');
            await undo();
            assert.equal((await styles()).hol.wrapWidth, null, 'one undo');
            assert.equal(await canUndo(), false);
            // very narrow: stops at the minimum
            await dragBy('[data-holwrap-bar="start"]', 200, 0);
            assert.equal((await styles()).hol.wrapWidth, 6);
        });

        await check('E15 Akce: the red box shows in every row; dragging its right handle changes the box in every row and the long event re-wraps; one undo; the box moves as a whole; Výchozí velikost pole', async () => {
            await e.restore(page, h, week.design);
            await selectPart('ev');
            assert.equal(await title(), 'Akce');
            assert.equal(await page.$$eval('[data-evbox]', (n) => n.length), 7, 'a box in every row');
            assert.equal(await page.$$eval('[data-evbox-bar]', (n) => n.length), 28, 'four side bars each');
            assert.equal(await shown('[data-cal-action="evbox-reset"]'), false, 'nothing to reset yet');
            const before = await evBoxes();
            const evTexts = async (date) => (await drawn('ev')).filter((t) => t.date === date).map((t) => t.text);
            const linesBefore = await evLines(days[1]);
            const textsBefore = await evTexts(days[1]);
            assert.ok(linesBefore >= 2, 'the long event is drawn on several lines');
            assert.equal((await styles()).ev.box, null);
            // the overlay box lies exactly on the drawn geometry
            const first = await page.$eval(`[data-evbox][data-date="${days[0]}"]`, (el) => {
                const r = el.getBoundingClientRect();
                return { x: r.left, y: r.top, w: r.width, h: r.height };
            });
            e.assertRect(first, await e.svgRectOf(page, before[0]), 'event box');
            await h.shot(page, 'kal-05-akce-box');

            const bar = `[data-evbox][data-date="${days[2]}"] [data-evbox-bar="e"]`;
            await dragBy(bar, -11, 0, { hold: true });
            let mid = await evBoxes();
            assert.ok(near(mid[0].w, before[0].w - 11, 0.25), `live: ${mid[0].w} vs ${before[0].w - 11}`);
            assert.equal(await canUndo(), false, 'no undo entry while the button is down');
            await e.release(page);
            const after = await evBoxes();
            const stored = (await styles()).ev.box;
            assert.ok(stored, 'the box is stored');
            assert.ok(near(stored.w, before[0].w - 11, 0.25), `stored width ${stored.w}`);
            after.forEach((box, i) => {
                assert.ok(near(box.w, stored.w), `row ${i}: width ${box.w}`);
                assert.ok(near(box.x, before[i].x), `row ${i}: the left edge stayed`);
                assert.ok(near(box.h, before[i].h) && near(box.y, before[i].y), `row ${i}: height untouched`);
            });
            const linesAfter = await evLines(days[1]);
            assert.ok(linesAfter > linesBefore, `the long event wraps to the new width: ${linesBefore} → ${linesAfter} lines`);
            assert.notDeepEqual(await evTexts(days[1]), textsBefore);
            assert.equal((await evTexts(days[1])).join(' '), textsBefore.join(' '), 'nothing of the text is lost');
            const texts = (await geometry()).frames[0].rows[1].items.filter((item) => item.part === 'ev');
            for (const item of texts) assert.ok(item.rect.w <= stored.w + 0.2 || item.text.indexOf(' ') < 0, `line „${item.text}“ fits the box`);
            assert.deepEqual(await e.selection(page, h), { el: 'cal', frame: 0, part: 'ev' });
            await e.assertBoxes(await e.allBoxes(page, h), week.design.layout.boxes, 'no element moved');
            assert.equal(await shown('[data-cal-action="evbox-reset"]'), true);
            await h.shot(page, 'kal-06-akce-box-narrow');

            await undo();
            assert.equal((await styles()).ev.box, null, 'ONE undo restores the default box');
            assert.equal(await evLines(days[1]), linesBefore);
            assert.equal(await canUndo(), false);
            await redo();
            assert.ok(near((await styles()).ev.box.w, stored.w));

            // the other three sides
            await dragBy(`[data-evbox][data-date="${days[0]}"] [data-evbox-bar="s"]`, 0, -4);
            let box = (await styles()).ev.box;
            assert.ok(near(box.h, stored.h - 4, 0.25) && near(box.y, stored.y), `bottom bar: h ${box.h}`);
            await dragBy(`[data-evbox][data-date="${days[0]}"] [data-evbox-bar="n"]`, 0, 2);
            box = (await styles()).ev.box;
            assert.ok(near(box.h, stored.h - 6, 0.3) && near(box.y, stored.y + 2, 0.25), `top bar: y ${box.y}, h ${box.h}`);
            await dragBy(`[data-evbox][data-date="${days[0]}"] [data-evbox-bar="w"]`, 5, 0);
            box = (await styles()).ev.box;
            assert.ok(near(box.x, stored.x + 5, 0.25) && near(box.w, stored.w - 5, 0.25), `left bar: x ${box.x}, w ${box.w}`);
            // far past the opposite side: stops at the minimum, never inverts
            await dragBy(`[data-evbox][data-date="${days[0]}"] [data-evbox-bar="e"]`, -150, 0);
            assert.equal((await styles()).ev.box.w, 6);
            await dragBy(`[data-evbox][data-date="${days[0]}"] [data-evbox-bar="e"]`, 9, 0);
            assert.equal((await styles()).ev.box.w, 15);

            // the box as a whole (part offset), Esc during the drag, snap back
            const placed = await evBoxes();
            await dragBy(`[data-evbox][data-date="${days[4]}"]`, 6, 3, { hold: true });
            assert.deepEqual((await styles()).offsets, { ev: { x: 6, y: 3 } });
            await page.keyboard.press('Escape');
            assert.deepEqual((await styles()).offsets, {}, 'Esc cancels the drag');
            await page.mouse.up();
            assert.deepEqual(await e.selection(page, h), { el: 'cal', frame: 0, part: 'ev' }, 'Esc did not leave the part');
            await dragBy(`[data-evbox][data-date="${days[4]}"]`, 6, 3);
            assert.deepEqual((await styles()).offsets, { ev: { x: 6, y: 3 } });
            (await evBoxes()).forEach((b, i) => assert.ok(near(b.x, placed[i].x + 6) && near(b.y, placed[i].y + 3), `row ${i} moved with the box`));
            assert.ok(await exists('[data-cal-action="offset-reset"]'), 'the hint offers Vrátit na původní místo');

            await h.save(page);
            await h.reload(page);
            const saved = await styles();
            assert.equal(saved.ev.box.w, 15);
            assert.deepEqual(saved.offsets, { ev: { x: 6, y: 3 } });

            await selectPart('ev');
            await click('[data-cal-action="evbox-reset"]');
            const reset = await styles();
            assert.equal(reset.ev.box, null, 'Výchozí velikost pole clears the size');
            assert.deepEqual(reset.offsets, {}, '… and the position');
            (await evBoxes()).forEach((b, i) => assert.ok(near(b.x, before[i].x) && near(b.w, before[i].w) && near(b.y, before[i].y), `row ${i} is back`));
            assert.equal(await shown('[data-cal-action="evbox-reset"]'), false);
            await undo();
            assert.equal((await styles()).ev.box.w, 15, 'the reset is one undo step');
        });

        await check('Akce panel: font, colour, alignments', async () => {
            await e.restore(page, h, week.design);
            await selectPart('ev');
            assert.deepEqual(await page.$$eval('.cal-props-kal .cal-psec > .lbl', (n) => n.map((x) => x.textContent)), ['Písmo', 'Barva', 'Zarovnání', 'Svislé zarovnání', 'Pole pro akce']);
            assert.equal(await page.$eval(`${prop('ev.align')} [aria-pressed="true"]`, (el) => el.dataset.value), 'start', 'default: away from the numbers');
            const box = (await evBoxes())[3];
            let text = (await partRects('ev'))[days[3]];
            assert.ok(near(text.x, box.x, 0.3), 'short event starts at the left edge of the box');
            await click(`${prop('ev.align')} [data-value="end"]`);
            text = (await partRects('ev'))[days[3]];
            assert.ok(near(text.x + text.w, box.x + box.w, 0.3), 'right-aligned in the box');
            const midY = text.y;
            await click(`${prop('ev.valign')} [data-value="top"]`);
            assert.equal((await styles()).ev.valign, 'top');
            assert.ok((await partRects('ev'))[days[3]].y < midY - 1, 'moved to the top of the box');
            await type(prop('ev.size'), '11');
            await click(`${prop('ev.color')} [data-color="#BE3A2E"]`);
            assert.ok((await drawn('ev')).every((t) => t.fill === '#BE3A2E' && near(t.size, (11 * 25.4) / 72, 0.02)));
        });

        await check('E16 Ikony: layout 2 + 2 and reordering two categories rearrange the icons; Přidat ikonu / Odebrat z ikon; Alt+↑↓', async () => {
            await e.restore(page, h, week.design);
            await selectPart('icon');
            assert.equal(await title(), 'Ikony');
            assert.deepEqual(await page.$$eval('.cal-props-kal .cal-psec > .lbl', (n) => n.map((x) => x.textContent)), ['Rozvržení ikon v řádku', 'Buňka', 'Velikost ikon (výchozí)', 'Ikony v řádku']);
            const order = () => page.$$eval('[data-icon-order]', (n) => n.map((x) => x.dataset.iconOrder));
            assert.deepEqual(await order(), ['waste', 'moon']);
            assert.equal(await shown(prop('cal.emptyIconSlot')), false, 'the empty-slot switch only when no icon category is active');

            // Přidat ikonu offers exactly what is on in step 1 and not listed yet
            await click('[data-cal-action="icon-add"]');
            assert.deepEqual(await page.$$eval('[data-icon-add]', (n) => n.map((x) => [x.dataset.iconAdd, x.textContent])), [['ev', 'Akce'], ['time', 'Změna času'], ['season', 'Změna ročního období']]);
            await page.click('[data-icon-add="ev"]');
            assert.deepEqual((await styles()).icons.order, ['waste', 'moon', 'ev']);
            assert.deepEqual(await order(), ['waste', 'moon', 'ev']);

            // 4 in a line: the day with an event shows bio, plast, the event icon in cells 0, 1, 2
            const iconsOf = async (date) => (await geometry()).frames[0].rows.find((r) => r.date === date).items.filter((i) => i.type === 'icon').map((i) => ({ category: i.ref.category, kind: i.ref.kindId || null, x: i.box.x + i.box.w / 2, y: i.box.y + i.box.h / 2 }));
            const cellsOf = async (date) => (await geometry()).frames[0].rows.find((r) => r.date === date).iconCells;
            assert.equal((await styles()).cal.iconGrid, '4');
            let cells = await cellsOf(days[3]);
            assert.equal(cells.length, 4);
            assert.ok(cells.every((c) => near(c.y, cells[0].y)), 'four cells in one line');
            let icons = (await iconsOf(days[3])).filter((i) => i.category !== 'moon');
            assert.deepEqual(icons.map((i) => i.kind || i.category), ['bio', 'plast', 'ev']);
            assert.equal(await page.$$eval('[data-iconcell]', (n) => n.length), 28, 'dashed cells in every row');
            assert.equal(await page.$$eval('[data-iconcell-bar]', (n) => n.length), 2);
            await h.shot(page, 'kal-07-ikony-4');

            await click(`${prop('cal.iconGrid')} [data-value="2x2"]`);
            assert.equal((await styles()).cal.iconGrid, '2x2');
            cells = await cellsOf(days[3]);
            assert.equal(cells.length, 4);
            assert.ok(near(cells[0].y, cells[1].y) && near(cells[2].y, cells[3].y) && cells[2].y > cells[0].y + 2, 'two rows of two cells');
            assert.ok(near(cells[0].x, cells[2].x) && near(cells[1].x, cells[3].x) && cells[1].x > cells[0].x + 2);
            const inCell = (i, c) => i.x >= c.x && i.x <= c.x + c.w && i.y >= c.y && i.y <= c.y + c.h;
            const cellOf = (i) => cells.findIndex((c) => inCell(i, c));
            icons = await iconsOf(days[3]);
            const hasMoon = icons.some((i) => i.category === 'moon');
            assert.deepEqual(icons.map((i) => i.kind || i.category), hasMoon ? ['bio', 'plast', 'moon', 'ev'] : ['bio', 'plast', 'ev']);
            assert.deepEqual(icons.map(cellOf), icons.map((_, index) => index), 'the icons fill the cells in the set order');
            assert.equal(await page.$$eval(`[data-iconcell][data-date="${days[3]}"]`, (n) => n.length), 4);
            const chrome = await page.$eval(`[data-iconcell][data-date="${days[3]}"][data-cell="3"]`, (el) => {
                const r = el.getBoundingClientRect();
                return { x: r.left, y: r.top, w: r.width, h: r.height };
            });
            e.assertRect(chrome, await e.svgRectOf(page, cells[3]), 'dashed cell 4');
            await h.shot(page, 'kal-08-ikony-2x2');

            // reorder with the real mouse: Akce above Odpady
            const from = await centreOf('[data-icon-order="ev"] .cal-grip');
            const to = await page.$eval('[data-icon-order="waste"]', (el) => {
                const r = el.getBoundingClientRect();
                return { x: r.left + 20, y: r.top + 3 };
            });
            await page.mouse.move(from.x, from.y);
            await page.mouse.down();
            await page.mouse.move(from.x, from.y - 8, { steps: 4 });
            await page.mouse.move(to.x, to.y, { steps: 14 });
            await h.sleep(200);
            await page.mouse.up();
            await h.sleep(250);
            assert.deepEqual((await styles()).icons.order, ['ev', 'waste', 'moon'], 'dragged to the first place');
            assert.deepEqual(await order(), ['ev', 'waste', 'moon']);
            assert.deepEqual(await page.$$eval('[data-icon-order] .cal-ordn', (n) => n.map((x) => x.textContent)), ['1', '2', '3']);
            icons = await iconsOf(days[3]);
            assert.deepEqual(icons.slice(0, 3).map((i) => i.kind || i.category), ['ev', 'bio', 'plast'], 'the event icon is now nearest the edge');
            assert.deepEqual(icons.map(cellOf), icons.map((_, index) => index));
            // a day without an event keeps bio, plast in the first cells
            assert.deepEqual((await iconsOf(days[0])).filter((i) => i.category !== 'moon').map((i) => i.kind), ['bio', 'plast']);
            await h.shot(page, 'kal-09-ikony-reordered');
            await undo();
            assert.deepEqual((await styles()).icons.order, ['waste', 'moon', 'ev'], 'the reorder is one undo step');
            await redo();

            // Alt + arrows on a focused row
            await selectPart('icon');
            await page.focus('[data-icon-order="ev"]');
            await page.keyboard.down('Alt');
            await page.keyboard.press('ArrowDown');
            await page.keyboard.up('Alt');
            assert.deepEqual((await styles()).icons.order, ['waste', 'ev', 'moon']);
            assert.equal(await page.evaluate(() => document.activeElement.dataset.iconOrder), 'ev', 'focus stays on the moved row');
            assert.deepEqual(await e.selection(page, h), { el: 'cal', frame: 0, part: 'icon' });
            e.assertBoxes(await e.allBoxes(page, h), week.design.layout.boxes, 'Alt+arrow never nudges the Kalendárium');
            await page.keyboard.down('Alt');
            await page.keyboard.press('ArrowUp');
            await page.keyboard.press('ArrowUp');
            await page.keyboard.up('Alt');
            assert.deepEqual((await styles()).icons.order, ['ev', 'waste', 'moon'], 'stops at the first place');

            await h.save(page);
            await h.reload(page);
            assert.deepEqual((await styles()).icons.order, ['ev', 'waste', 'moon']);
            assert.equal((await styles()).cal.iconGrid, '2x2');
            assert.deepEqual((await iconsOf(days[3])).slice(0, 3).map((i) => i.kind || i.category), ['ev', 'bio', 'plast'], 'after reload');

            // Odebrat z ikon
            await selectPart('icon');
            await click('[data-icon-open="moon"]');
            await click('[data-icon-remove="moon"]');
            assert.deepEqual((await styles()).icons.order, ['ev', 'waste']);
            assert.ok((await iconsOf(days[0])).every((i) => i.category !== 'moon'));
            await click('[data-cal-action="icon-add"]');
            assert.deepEqual(await page.$$eval('[data-icon-add]', (n) => n.map((x) => x.dataset.iconAdd)), ['moon', 'time', 'season']);
            await page.keyboard.press('Escape');
            assert.equal(await shown('[data-icon-add]'), false, 'Esc closes the menu');
            assert.equal(await panel(), 'icon', '… and not the part');

            // nothing left: the empty state and the switch of the empty slot
            for (const key of ['ev', 'waste']) {
                await click(`[data-icon-open="${key}"]`);
                await click(`[data-icon-remove="${key}"]`);
            }
            assert.deepEqual((await styles()).icons.order, []);
            assert.equal(await page.$$eval('[data-icon-order]', (n) => n.length), 0);
            assert.equal(await page.$eval('.cal-props-kal .cal-psec:has(.cal-ord) .cap', (el) => el.textContent), 'Zatím žádné ikony.');
            assert.equal(await exists('[data-iconcell]'), false, 'no cells without an icon category');
            assert.equal(await shown(prop('cal.emptyIconSlot')), true);
            const slots = () => page.$$eval('.cal-cv svg[data-page] [data-el="cal"] rect[data-part="icon"]', (n) => n.length);
            assert.equal(await slots(), 7, 'an empty dashed slot in every row');
            await toggle(prop('cal.emptyIconSlot'));
            assert.equal((await styles()).cal.emptyIconSlot, false);
            assert.equal(await slots(), 0);
        });

        await check('Ikony: cell diagram with per-cell size and alignment, default icon size, cell width bars, 1 + 2 / 2 + 1', async () => {
            await e.restore(page, h, week.design);
            await selectPart('icon');
            const row = async (date) => (await geometry()).frames[0].rows.find((r) => r.date === date);
            const iconBoxes = async (date) => (await row(date)).items.filter((i) => i.type === 'icon' && i.ref.category === 'waste').map((i) => i.box);
            assert.equal(await page.$$eval('[data-icon-cell]', (n) => n.length), 4);
            let boxes = await iconBoxes(days[0]);
            assert.ok(near(boxes[0].w, 6) && near(boxes[1].w, 6), 'default icon size 6 mm');

            // default size
            await type(prop('cal.iconSize'), '4');
            assert.equal((await styles()).cal.iconSize, 4);
            boxes = await iconBoxes(days[0]);
            assert.ok(near(boxes[0].w, 4) && near(boxes[1].w, 4));
            assert.equal(await page.$eval('[data-cell-size]', (el) => el.value), '4', 'the cell shows the default it uses');

            // cell 2 gets its own size and alignment
            await click('[data-icon-cell="1"]');
            assert.equal(await page.$eval('[data-icon-cell="1"]', (el) => el.getAttribute('aria-pressed')), 'true');
            assert.equal(await page.$$eval('[data-iconcell].on', (n) => n.length), 7, 'the chosen cell is marked in every row');
            await type('[data-cell-size]', '2,5');
            assert.deepEqual((await styles()).cal.iconCells, { 4: { 1: { size: 2.5, align: 'middle' } } });
            boxes = await iconBoxes(days[0]);
            assert.ok(near(boxes[0].w, 4) && near(boxes[1].w, 2.5), `cell sizes ${boxes[0].w} / ${boxes[1].w}`);
            const cell = (await row(days[0])).iconCells[1];
            await click('[data-cell-align] [data-value="start"]');
            assert.equal((await styles()).cal.iconCells['4']['1'].align, 'start');
            boxes = await iconBoxes(days[0]);
            assert.ok(near(boxes[1].x, cell.x + 0.15, 0.06), 'left in its cell');
            // the stepper
            await page.click('.cal-icset .cal-num button[aria-label="Větší"]');
            assert.equal((await styles()).cal.iconCells['4']['1'].size, 3);
            // an empty field = back to the default size
            await type('[data-cell-size]', '');
            assert.equal((await styles()).cal.iconCells['4']['1'].size, null);
            assert.equal(await page.$eval('[data-cell-size]', (el) => el.value), '4');
            // out of range is corrected
            await type('[data-cell-size]', '99');
            assert.equal((await styles()).cal.iconCells['4']['1'].size, 30);
            await click('[data-icon-cell="0"]');
            assert.equal(await page.$eval('[data-cell-size]', (el) => el.value), '4', 'another cell, its own value');
            assert.equal(await page.$eval('[data-cell-align] [aria-pressed="true"]', (el) => el.dataset.value), 'middle');

            // 1 + 2: three cells, the first double and "max"
            await click(`${prop('cal.iconGrid')} [data-value="1+2"]`);
            assert.equal(await page.$$eval('[data-icon-cell]', (n) => n.length), 3);
            let cells = (await row(days[0])).iconCells;
            assert.equal(cells.length, 3);
            assert.ok(near(cells[0].w, 2 * cells[1].w), 'the first cell is double');
            assert.equal(await page.$eval('[data-cell-size]', (el) => `${el.value}|${el.placeholder}`), '|max');
            await click(`${prop('cal.iconGrid')} [data-value="2+1"]`);
            cells = (await row(days[0])).iconCells;
            assert.ok(near(cells[2].w, 2 * cells[0].w) && cells[2].y > cells[0].y + 1, '2 + 1: the double cell below');
            await click(`${prop('cal.iconGrid')} [data-value="4"]`);

            // cell width bars
            const zone = (await geometry()).frames[0].iconZone;
            assert.equal(zone.cols, 4);
            await dragBy('[data-iconcell-bar="end"]', 8, 0);
            const widened = (await styles()).cal.iconCellW;
            assert.ok(near(widened, zone.cellW + 2, 0.11), `cell width ${widened}, expected ${zone.cellW + 2}`);
            assert.ok(near((await geometry()).frames[0].iconZone.w, 4 * widened, 0.11));
            const text = (await geometry()).frames[0].textColumn;
            assert.ok(text.x > week.design.layout.boxes.cal.x + 5 + 4 * widened, 'the text column gave way');
            await undo();
            assert.equal((await styles()).cal.iconCellW, null, 'one undo');
            await dragBy('[data-iconcell-bar="start"]', 300, 0);
            assert.equal((await styles()).cal.iconCellW, 2, 'stops at the minimum cell width');
        });

        await check('Ikony: custom icons with return-to-default (moon phase, waste kind, time), seasons four or one', async () => {
            await e.restore(page, h, week.design);
            await selectPart('icon');
            const png = require('path').join(require('os').tmpdir(), `kal-icon-${Date.now()}.svg`);
            require('fs').writeFileSync(png, '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10" width="10" height="10"><circle cx="5" cy="5" r="4" fill="#C84B55"/></svg>');
            const upload = async (slot, stored) => {
                const input = await page.$(`input[data-icon-slot="${slot}"]`);
                assert.ok(input, `tile ${slot}`);
                await input.uploadFile(png);
                await until(async () => Boolean(stored(await styles())), `upload into ${slot}`);
                await h.waitToast(page, 'Vlastní ikona nahrána');
                // the toast lies over the lower end of the panel: real clicks wait until it is gone
                await until(() => page.evaluate(() => document.querySelector('.toast-n') === null), 'the toast goes away', 12000);
            };

            await click('[data-icon-open="moon"]');
            assert.equal(await page.$$eval('.cal-orx .cal-mphc', (n) => n.length), 4, 'four phases');
            assert.equal(await shown('[data-icon-reset="moon:2"]'), false);
            await upload('moon:2', (st) => st.moon.icons[2]);
            const icons = (await styles()).moon.icons;
            assert.ok(icons[2] && !icons[0] && !icons[1] && !icons[3], 'only the full moon got an own icon');
            assert.equal(await shown('[data-icon-reset="moon:2"]'), true);
            assert.ok(await exists('label.cal-icp[data-asset] img'), 'the tile shows the picture');
            await click('[data-icon-reset="moon:2"]');
            assert.equal((await styles()).moon.icons[2], null, 'Vrátit společnou');
            await toggle(prop('moon.phases.0'));
            assert.deepEqual((await styles()).moon.phases, [false, true, true, true], 'a phase can be switched off');
            await toggle(prop('moon.phases.0'));

            await click('[data-icon-open="waste"]');
            await upload('waste:bio', (st) => (st.waste.kinds.bio || {}).icon);
            const own = (await styles()).waste.kinds.bio;
            assert.ok(own && own.icon, 'the waste kind has its icon');
            const bioIcon = (await geometry()).frames[0].rows[0].items.find((i) => i.type === 'icon' && i.ref.kindId === 'bio');
            assert.equal(bioIcon.ref.asset, own.icon, 'the page draws it');
            assert.ok(await exists(`.cal-cv svg[data-page] [data-el="cal"] image`), 'as a picture');
            await click('[data-icon-reset="waste:bio"]');
            assert.equal((await styles()).waste.kinds.bio.icon, null, 'Vrátit původní');
            await click(`${prop('waste.kinds.plast.color')} [data-color="#BE3A2E"]`);
            assert.equal((await styles()).waste.kinds.plast.color, '#BE3A2E', 'the colour of a kind');

            await click('[data-cal-action="icon-add"]');
            await page.click('[data-icon-add="season"]');
            await click('[data-icon-open="season"]');
            assert.equal(await page.$$eval('.cal-orx .cal-mphc.nock', (n) => n.filter((x) => x.offsetParent).length), 4, 'four season icons');
            await upload('season:1', (st) => st.icons.season.assets[1]);
            assert.ok((await styles()).icons.season.assets[1]);
            await click(`${prop('icons.season.mode')} [data-value="one"]`);
            assert.equal((await styles()).icons.season.mode, 'one');
            assert.equal(await shown('.cal-orx .cal-mphc.nock'), false);
            assert.equal(await shown('input[data-icon-slot="season"]'), true, 'one icon for all seasons');
            await click(`${prop('icons.season.mode')} [data-value="four"]`);
            await click('[data-icon-reset="season:1"]');
            assert.equal((await styles()).icons.season.assets[1], null, 'Vrátit výchozí');

            await click('[data-cal-action="icon-add"]');
            await page.click('[data-icon-add="time"]');
            await click('[data-icon-open="time"]');
            assert.equal(await shown(prop('icons.time.color')), true);
            await upload('time', (st) => st.icons.time.asset);
            assert.ok((await styles()).icons.time.asset);
            assert.equal(await shown(prop('icons.time.color')), false, 'no colour for an uploaded picture');
            await click('[data-icon-reset="time"]');
            assert.equal((await styles()).icons.time.asset, null);
            require('fs').unlinkSync(png);
        });

        await check('Fáze měsíce, Změna času (the sample switches to the week of the time change), Šichtovnice, Odpady panels', async () => {
            await e.restore(page, h, week.design);
            // Fáze měsíce
            await selectPart('moon');
            assert.deepEqual(await page.$$eval('.cal-props-kal .cal-psec', (n) => n.filter((x) => !x.hidden).map((x) => x.querySelector(':scope > .lbl').textContent)), ['Pozice', 'Velikost ikonky', 'Svislé zarovnání', 'Barva', 'Fáze']);
            assert.equal(await page.$eval('[data-icon-order="moon"]', (el) => el.classList.contains('hl')), true, 'the moon row is marked in Pozice');
            await page.click(`.cal-szr .cal-num button[aria-label="Více"]`);
            assert.equal((await styles()).moon.size, 5.2, 'the stepper moves by 0.2 mm');
            assert.equal(Number(await page.$eval('[data-size-slider="moon"]', (el) => el.value)), 5.2, 'the slider follows');
            await type(prop('moon.size'), '12');
            assert.equal((await styles()).moon.size, 9, 'limited to 9 mm');
            await click(`${prop('moon.valign')} [data-value="top"]`);
            assert.equal((await styles()).moon.valign, 'top');
            await click(`${prop('moon.color')} [data-color="#2F78B7"]`);
            assert.equal((await styles()).moon.color, '#2F78B7');

            // Změna času: the sample page becomes the first week with a time or season change
            const first = (await geometry()).frames[0].rows[0].date;
            assert.equal(first, days[0]);
            await selectPart('time');
            assert.equal(await title(), 'Změna času');
            const rows = (await geometry()).frames[0].rows;
            assert.notEqual(rows[0].date, days[0], 'another week is on the stage');
            assert.ok(rows.some((r) => r.info.timeTexts.length > 0), 'it is a week with a time / season change');
            assert.ok(Object.keys(await partRects('time')).length >= 1, 'the text is drawn');
            assert.ok((await page.$$eval('[data-part-box="time"]', (n) => n.length)) >= 1, '… and highlighted');
            const expected = await h.cal(page, (c) => c.pages().find((p) => p.kind === 'week' && p.week.days.some((d) => /-03-(20|2[5-9]|3[01])$/.test(d))).week.days[0]);
            assert.equal(rows[0].date, expected, 'the first such week of the year (March)');
            assert.equal(await shown(prop('time.align')), false);
            assert.equal(await page.$eval('.cal-props-kal .cal-note', (el) => el.textContent), 'Pod jménem a svátkem, ve stejném sloupci.');
            const inText = Object.values(await partRects('time'))[0];
            await click(`${prop('time.position')} [data-value="sep"]`);
            assert.equal((await styles()).time.position, 'sep');
            assert.equal(await page.$eval('.cal-props-kal .cal-note', (el) => el.textContent), 'Vlastní místo v řádku dne — zarovnání níže.');
            assert.equal(await shown(prop('time.align')), true);
            assert.equal(await page.$eval(`${prop('time.align')} [aria-pressed="true"]`, (el) => el.dataset.value), 'start');
            const column = (await geometry()).frames[0].textColumn;
            let sep = Object.values(await partRects('time'))[0];
            assert.ok(near(sep.x, column.x, 0.3), 'at the far side of the text column');
            await click(`${prop('time.align')} [data-value="end"]`);
            sep = Object.values(await partRects('time'))[0];
            assert.ok(near(sep.x + sep.w, column.x + column.w, 0.3), 'right-aligned');
            await click(`${prop('time.valign')} [data-value="top"]`);
            assert.ok(Object.values(await partRects('time'))[0].y < sep.y - 2, 'at the top of the row');
            assert.ok(inText.x > column.x + 5, 'with the day text it was beside the number');
            await h.shot(page, 'kal-10-time-part');
            await page.keyboard.press('Escape');
            assert.equal((await geometry()).frames[0].rows[0].date, days[0], 'back on the first week');

            // Šichtovnice (content shortcut)
            await selectPart('shift');
            assert.equal(await page.$$eval('[data-shift-col]', (n) => n.length), 1);
            assert.equal(await exists('[data-shift-col-remove]'), false, 'the last column cannot be removed');
            await click('[data-cal-action="shift-col-add"]');
            let ops = Object.values((await content()).shiftOps).sort((a, b) => a.pos - b.pos);
            assert.deepEqual(ops.map((o) => o.name), ['Provoz 1', 'Provoz 2']);
            assert.equal(await page.$$eval('[data-shift-col]', (n) => n.length), 2);
            const second = await page.$$eval('[data-shift-col]', (n) => n[1].dataset.shiftCol);
            await page.$eval(`[data-shift-col="${second}"] [data-shift-col-name]`, (el) => {
                el.focus();
                el.select();
            });
            await page.keyboard.type('Noční');
            assert.equal((await content()).shiftOps[second].name, 'Noční');
            assert.equal(await page.evaluate(() => document.activeElement.hasAttribute('data-shift-col-name')), true, 'typing keeps the focus');
            const zoneBefore = (await geometry()).frames[0].textColumn.x;
            assert.ok(zoneBefore > week.design.layout.boxes.cal.x + 10, 'two shift columns take room');
            await click(`${prop('shift.bgMode')} [data-value="none"]`);
            assert.equal(await shown(prop('shift.bg')), false);
            await click(`${prop('shift.bgMode')} [data-value="all"]`);
            await click(`${prop('shift.bg')} [data-color="#CBC7D1"]`);
            assert.equal((await styles()).shift.bg, '#CBC7D1');
            await h.shot(page, 'kal-11-shift-part');
            await page.click(`[data-shift-col="${second}"] [data-shift-col-remove]`);
            await h.answerDialog(page, 'confirm');
            assert.deepEqual(Object.keys((await content()).shiftOps), ['opProvoz0000001']);
            assert.equal(await page.$$eval('[data-shift-col]', (n) => n.length), 1);

            // Odpady
            await selectPart('waste');
            assert.equal(await title(), 'Odpady');
            assert.deepEqual(await page.$$eval('[data-waste-kind]', (n) => n.map((x) => x.dataset.wasteKind)), ['bio', 'plast']);
            await page.$eval('[data-waste-kind="plast"] [data-waste-kind-name]', (el) => {
                el.focus();
                el.select();
            });
            await page.keyboard.type('Plasty a kovy');
            assert.equal((await content()).wasteKinds.plast.name, 'Plasty a kovy');
            await click('[data-cal-action="waste-kind-add"]');
            const offered = await page.$$eval('[data-waste-add]', (n) => n.map((x) => x.textContent));
            assert.deepEqual(offered, ['Komunální odpad', 'Papír', 'Sklo', 'Vlastní druh…'], 'kinds that are off, the missing built-in, an own kind');
            await page.click('[data-waste-add="kom"]');
            assert.equal((await content()).wasteKinds.kom.on, true);
            assert.deepEqual(await page.$$eval('[data-waste-kind]', (n) => n.map((x) => x.dataset.wasteKind)), ['bio', 'kom', 'plast'], 'in the order of the Odpady tool');
            await click('[data-cal-action="waste-kind-add"]');
            await page.click('[data-waste-add="new:sklo"]');
            assert.equal((await content()).wasteKinds.sklo.builtin, 'sklo');
            await page.click('[data-waste-kind="sklo"] [data-waste-kind-remove]');
            await h.answerDialog(page, 'confirm');
            assert.equal((await content()).wasteKinds.sklo, undefined, 'removed after the confirmation');

            // collection place beside the icons, with its own text style
            assert.equal(await shown(prop('placeText.size')), false);
            await toggle(prop('cal.placeLabels'));
            assert.equal((await styles()).cal.placeLabels, true);
            assert.equal(await shown(prop('placeText.size')), true);
            const place = (await geometry()).frames[0].rows[0].items.find((i) => i.type === 'text' && i.text === 'U hřbitova');
            assert.ok(place, 'the collection place prints beside the icons');
            await click(`${prop('placeText.color')} [data-color="#BE3A2E"]`);
            assert.equal((await styles()).placeText.color, '#BE3A2E');
            await h.shot(page, 'kal-12-waste-part');

            // Zobrazovat odpady = Odpady among the icons of the row
            await toggle(prop('icons.order.waste'));
            assert.deepEqual((await styles()).icons.order, ['moon']);
            assert.ok((await geometry()).frames[0].rows[0].items.every((i) => !(i.type === 'icon' && i.ref.category === 'waste')));
            assert.equal(await shown('[data-waste-kind]'), false);
            assert.equal(await panel(), 'waste');
            await toggle(prop('icons.order.waste'));
            assert.deepEqual((await styles()).icons.order, ['waste', 'moon']);

            await h.save(page);
            await h.reload(page);
            assert.equal((await content()).wasteKinds.plast.name, 'Plasty a kovy', 'content shortcut saved');
            assert.equal((await content()).wasteKinds.kom.on, true);
            assert.equal((await styles()).time.position, 'sep');
            assert.equal((await styles()).moon.size, 9);
        });

        await check('a feature switched off while its part is selected: the part goes, the selection falls back to the whole block, the style is kept', async () => {
            await e.restore(page, h, week.design);
            await selectPart('ev');
            await dragBy(`[data-evbox][data-date="${days[2]}"] [data-evbox-bar="e"]`, -10, 0);
            const box = (await styles()).ev.box;
            assert.ok(box);
            await h.gotoStep(page, 1);
            await page.click('label:has([data-feature="events"])');
            assert.equal((await h.state(page)).design.features.events, false);
            assert.deepEqual(await e.selection(page, h), { el: 'cal', frame: 0 }, 'the selection fell back to the block');
            await h.gotoStep(page, 3);
            assert.equal(await panel(), 'block');
            assert.equal(await exists('[data-part-row="ev"]'), false, 'Akce left the parts list');
            assert.equal(await exists('[data-evbox]'), false);
            assert.equal((await drawn('ev')).length, 0, 'no event text on the page');
            assert.equal(await page.$$eval('.cal-szl .cal-szi', (rows) => rows.some((r) => r.querySelector('.cal-szt').textContent === 'Akce')), false, 'no Akce slider');
            assert.deepEqual((await styles()).ev.box, box, 'the style is kept');
            // the same while the editor is open: undo of the caption switch aside, features come from step 1
            await h.gotoStep(page, 1);
            await page.click('label:has([data-feature="events"])');
            await h.gotoStep(page, 3);
            await selectPart('ev');
            assert.ok(near((await evBoxes())[0].w, box.w), 'the box is back as it was');
        });

        // ---- 14 days ---------------------------------------------------------------------------

        let fort = null;
        await check('14 days: the block panel names the two frames; the event box shows in both frames mirrored; a bar or the box dragged in frame 2 mirrors into frame 1', async () => {
            const cal = await e.newCalendar(page, h, { type: 'fortnight' });
            created.push(cal.id);
            const all = await h.cal(page, (c) => c.pages().find((p) => p.kind === 'week').week.days);
            const design = await seedContent(cal.design, { events: true, waste: true }, [
                { c: 'events', id: 'evFortnight0001', v: { date: all[1], name: 'Výroční členská schůze sboru dobrovolných hasičů', club: null, icon: null } },
                { c: 'events', id: 'evFortnight0002', v: { date: all[9], name: 'Výroční členská schůze sboru dobrovolných hasičů', club: null, icon: null } },
                { c: 'wasteKinds', id: 'bio', v: { builtin: 'bio', name: 'Bio odpad', on: true, place: '', mode: 'rule', weekdays: [0, 1, 2, 3, 4, 5, 6], interval: 'w', from: `${cal.design.year - 1}-12-01`, to: null, dates: [], pos: 0 } },
            ]);
            fort = { id: cal.id, design, days: all };
            const W = design.page.width;

            await selectBlock();
            assert.ok(await exists(prop('cal.frame')), '1. týden | 2. týden');
            await h.shot(page, 'kal-13-block-fortnight');
            await click(`${prop('cal.frame')} [data-value="1"]`);
            assert.deepEqual(await e.selection(page, h), { el: 'cal', frame: 1 });
            assert.equal(await panel(), 'block');
            assert.ok(near(Number((await page.$eval('[data-field="x"]', (el) => el.value)).replace(',', '.')), design.layout.boxes.cal2.x), 'the fields show the second frame');

            await selectPart('ev');
            const g = await geometry();
            assert.equal(g.frames.length, 2);
            assert.equal(g.frames[0].compact, false);
            assert.equal(await page.$$eval('[data-evbox][data-frame="0"]', (n) => n.length), 7);
            assert.equal(await page.$$eval('[data-evbox][data-frame="1"]', (n) => n.length), 7);
            const mirrored = (a, b, label) => {
                assert.ok(near(a.x + a.w, W - b.x, 0.15), `${label}: mirrored x (${a.x + a.w} vs ${W - b.x})`);
                assert.ok(near(a.w, b.w) && near(a.h, b.h), `${label}: same size`);
            };
            let left = await evBoxes(0);
            let right = await evBoxes(1);
            left.forEach((box, i) => mirrored(box, right[i], `default row ${i}`));
            const rowsOf = (boxes) => boxes.map((b) => b.y);
            assert.deepEqual(rowsOf(left).map((y) => Math.round(y * 10)), rowsOf(right).map((y) => Math.round(y * 10)), 'same rows');
            await h.shot(page, 'kal-14-akce-fortnight');

            // frame 2 is mirrored: its zone is on the right, so the bar towards the numbers is the LEFT one
            assert.equal(g.frames[0].zoneLeft, false);
            assert.equal(g.frames[1].zoneLeft, true);
            const base = left[0];
            await dragBy(`[data-evbox][data-frame="1"][data-date="${all[8]}"] [data-evbox-bar="e"]`, -3, 0);
            const stored = (await styles()).ev.box;
            assert.ok(near(stored.w, base.w - 3, 0.25), `stored width ${stored.w}, expected ${base.w - 3}`);
            left = await evBoxes(0);
            right = await evBoxes(1);
            left.forEach((box, i) => {
                assert.ok(near(box.w, stored.w), `frame 1 row ${i} follows`);
                mirrored(box, right[i], `after the resize, row ${i}`);
            });
            await undo();
            assert.equal((await styles()).ev.box, null, 'one undo');
            await redo();

            // the box as a whole, dragged in frame 2 by +5 mm → frame 1 moves by −5 mm
            const before0 = await evBoxes(0);
            const before1 = await evBoxes(1);
            const textBefore = [(await partRects('ev', 0))[all[1]], (await partRects('ev', 1))[all[9]]];
            await dragBy(`[data-evbox][data-frame="1"][data-date="${all[10]}"]`, 5, 2);
            assert.deepEqual((await styles()).offsets, { ev: { x: -5, y: 2 } }, 'offsets are stored for the first frame');
            left = await evBoxes(0);
            right = await evBoxes(1);
            left.forEach((box, i) => {
                assert.ok(near(box.x, before0[i].x - 5) && near(box.y, before0[i].y + 2), `frame 1 row ${i}: mirrored move`);
                assert.ok(near(right[i].x, before1[i].x + 5) && near(right[i].y, before1[i].y + 2), `frame 2 row ${i}: follows the pointer`);
                mirrored(box, right[i], `after the move, row ${i}`);
            });
            // the event text travels with its box in both frames
            const text0 = (await partRects('ev', 0))[all[1]];
            const text1 = (await partRects('ev', 1))[all[9]];
            assert.ok(near(text0.x, textBefore[0].x - 5) && near(text0.y, textBefore[0].y + 2), 'frame 1: the text moved with its box');
            assert.ok(near(text1.x, textBefore[1].x + 5) && near(text1.y, textBefore[1].y + 2), 'frame 2: the text moved with its box');
            await undo();
            assert.deepEqual((await styles()).offsets, {}, 'one undo');
            await redo();

            await h.save(page);
            await h.reload(page);
            const saved = await styles();
            assert.ok(near(saved.ev.box.w, stored.w));
            assert.deepEqual(saved.offsets, { ev: { x: -5, y: 2 } });
            await selectPart('ev');
            left = await evBoxes(0);
            right = await evBoxes(1);
            left.forEach((box, i) => mirrored(box, right[i], `after reload, row ${i}`));
        });

        await check('14 days: icon cells and the holiday wrap bars show in both frames; a part moved in frame 2 is mirrored in frame 1', async () => {
            if (!fort) throw new Error('no 14-day calendar');
            await e.restore(page, h, fort.design);
            const W = fort.design.page.width;
            await selectPart('icon');
            assert.equal((await styles()).cal.iconGrid, '2x2');
            assert.equal(await page.$$eval('[data-iconcell][data-frame="0"]', (n) => n.length), 28);
            assert.equal(await page.$$eval('[data-iconcell][data-frame="1"]', (n) => n.length), 28);
            assert.equal(await page.$$eval('[data-iconcell-bar]', (n) => n.length), 4);
            const zones = (await geometry()).frames.map((f) => f.iconZone);
            assert.ok(near(zones[0].x + zones[0].w, W - zones[1].x, 0.15), 'the zones mirror each other');
            await h.shot(page, 'kal-15-ikony-fortnight');
            // the inner bar of frame 2 (its left one) dragged inwards widens the cells of both frames
            await dragBy('[data-iconcell-bar="start"][data-frame="1"]', -6, 0);
            const cellW = (await styles()).cal.iconCellW;
            assert.ok(near(cellW, zones[1].cellW + 3, 0.11), `cell width ${cellW}`);
            const after = (await geometry()).frames.map((f) => f.iconZone);
            assert.ok(near(after[0].w, 2 * cellW) && near(after[1].w, 2 * cellW));
            assert.ok(near(after[0].x + after[0].w, W - after[1].x, 0.15), 'still mirrored');
            await undo();

            await selectPart('hol');
            assert.equal(await page.$$eval('[data-holwrap-bar]', (n) => n.length), 4);

            // a moved part: Jména dragged in frame 2
            await selectPart('name');
            const n0 = await partRects('name', 0);
            const n1 = await partRects('name', 1);
            const date = Object.keys(n1)[0];
            const from = e.centre(n1[date]);
            await h.dragMm(page, from, { x: from.x + 4, y: from.y });
            assert.deepEqual((await styles()).offsets, { name: { x: -4, y: 0 } });
            const m0 = await partRects('name', 0);
            const m1 = await partRects('name', 1);
            for (const d of Object.keys(n0)) assert.ok(near(m0[d].x, n0[d].x - 4), `frame 1 ${d} mirrored`);
            for (const d of Object.keys(n1)) assert.ok(near(m1[d].x, n1[d].x + 4), `frame 2 ${d}`);
            await undo();
            assert.deepEqual((await styles()).offsets, {});
        });

        await check('step 4 › Styl stránek: the same panels and part chrome (same module, same undo stack)', async () => {
            if (!week) throw new Error('no calendar');
            await h.openCalendar(page, week.id);
            await e.restore(page, h, week.design);
            await h.gotoStep(page, 4);
            await page.click('[data-content-view="style"]');
            await page.waitForSelector('.cal-cv', { timeout: 5000 });
            const text = (await partRects('ev'))[days[3]];
            await e.clickMm(page, h, e.centre(text));
            assert.deepEqual(await e.selection(page, h), { el: 'cal', frame: 0 });
            await page.waitForSelector('[data-kal-panel="block"]', { timeout: 5000 });
            await e.clickMm(page, h, e.centre(text));
            await page.waitForSelector('[data-kal-panel="ev"]', { timeout: 5000 });
            assert.equal(await page.$$eval('[data-evbox]', (n) => n.length), 7);
            const before = (await evBoxes())[0];
            await dragBy(`[data-evbox][data-date="${days[5]}"] [data-evbox-bar="e"]`, -6, 0);
            assert.ok(near((await styles()).ev.box.w, before.w - 6, 0.25));
            await h.shot(page, 'kal-17-step4-style-akce');
            await undo();
            assert.equal((await styles()).ev.box, null);
            await page.click('[data-cal-action="part-back"]');
            await page.waitForSelector('[data-kal-panel="block"]', { timeout: 5000 });
            await h.gotoStep(page, 3);
        });

        await check('screenshots: 1440 and 390 px, dark and light; at 390 px the panel sits under the page without a horizontal scroll', async () => {
            if (!week) throw new Error('no calendar');
            await h.openCalendar(page, week.id);
            await e.restore(page, h, week.design);
            const theme = (name) => page.evaluate((t) => ['data-theme', 'data-bs-theme'].forEach((a) => document.documentElement.setAttribute(a, t)), name);
            await theme('light');
            await selectBlock();
            await h.shot(page, 'kal-20-block-1440-light');
            await selectPart('ev');
            await h.shot(page, 'kal-21-akce-1440-light');
            await selectPart('icon');
            await click('[data-icon-open="moon"]');
            await page.$eval('[data-icon-open="moon"]', (el) => el.scrollIntoView({ block: 'start' }));
            await h.shot(page, 'kal-22-ikony-moon-1440-light');
            await theme('dark');
            await h.shot(page, 'kal-23-ikony-moon-1440-dark');

            await page.setViewport({ width: 390, height: 844 });
            await h.sleep(300);
            const widths = await page.evaluate(() => ({ doc: document.documentElement.scrollWidth, win: window.innerWidth, panel: document.querySelector('[data-kal-panel]').getBoundingClientRect().width }));
            assert.ok(widths.doc <= widths.win + 1, `no horizontal page scroll (${widths.doc} > ${widths.win})`);
            assert.ok(widths.panel <= widths.win, 'the panel fits');
            for (const name of ['dark', 'light']) {
                await theme(name);
                await page.$eval('[data-kal-panel]', (el) => el.scrollIntoView({ block: 'start' }));
                await h.shot(page, `kal-24-ikony-390-${name}`);
                await page.$eval('.cal-cv', (el) => el.scrollIntoView({ block: 'center' }));
                await h.shot(page, `kal-25-stage-390-${name}`);
            }
            await selectPart('num');
            await page.$eval('[data-kal-panel]', (el) => el.scrollIntoView({ block: 'start' }));
            await h.shot(page, 'kal-26-number-390-light');
            await theme('dark');
            await page.setViewport({ width: 1440, height: 900 });
            await h.sleep(200);
        });

        for (const id of created) {
            await h.deleteCalendar(page, id).catch(() => null);
        }
    },
};
