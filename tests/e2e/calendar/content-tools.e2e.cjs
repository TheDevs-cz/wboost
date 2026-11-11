/*
 * Content tools of step 4 (phase P6b; handoff C4, C5, C6, C7, C8, G9 and the §8 "Content" rows):
 * Odpady, Akce a spolky, Jména navíc, Fáze měsíce, Šichtovnice.
 *
 * Everything is entered through the dialogs with real clicks and key presses; the result is read
 * from the generated day data (`window.__calendar.geometry(page).frames[].rows[].info` of every
 * week page), saved, reloaded and read again.
 *
 * Not reachable through the UI yet: putting the event icon among the row icons (Kalendárium ›
 * Ikony panel, phase P5b) — the C7 icon half switches it on by saving the style directly.
 */
const assert = require('assert/strict');
const fs = require('fs');
const os = require('os');
const path = require('path');

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const M = '.cal-tool-modal.show';
const pad = (n) => String(n).padStart(2, '0');
const isoOf = (y, m, d) => `${y}-${pad(m)}-${pad(d)}`;

/** Every day of the week pages → what the row prints. */
const dayData = (h, page) =>
    h.cal(page, (c) => {
        const out = {};
        c.pages().forEach((p, index) => {
            if (p.kind !== 'week') return;
            const g = c.geometry(index);
            if (!g) return;
            for (const frame of g.frames) {
                for (const row of frame.rows) {
                    out[row.date] = {
                        waste: row.info.icons.filter((i) => i.category === 'waste').map((i) => i.kindId),
                        moon: row.info.icons.filter((i) => i.category === 'moon').map((i) => i.phase),
                        ev: row.info.icons.filter((i) => i.category === 'ev').length,
                        events: row.info.events.map((e) => e.text),
                        names: row.info.names,
                        shifts: row.info.shifts,
                        overflow: Boolean(row.eventOverflow),
                    };
                }
            }
        });
        return out;
    });
const datesWith = (data, pick) => Object.keys(data).filter((date) => pick(data[date])).sort();

async function openTool(page, key) {
    await page.click(`[data-tool="${key}"]`);
    await page.waitForSelector(`.cal-tool-modal.show[data-tool-modal="${key}"]`, { timeout: 8000 });
    await sleep(350);
}
async function closeTool(page) {
    await page.click(`${M} [data-cal-action="modal-done"]`);
    await page.waitForFunction(() => !document.querySelector('.cal-tool-modal'), { timeout: 8000 });
    await sleep(150);
}
async function typeInto(page, selector, value) {
    await page.$eval(selector, (el) => {
        el.focus();
        el.select();
    });
    await page.keyboard.press('Backspace');
    if (value) await page.type(selector, value);
}
/** Puts a date into a native date field the way the browser's own picker does (value + change). */
async function pickDate(page, selector, value) {
    await page.$eval(
        selector,
        (el, v) => {
            el.value = v;
            el.dispatchEvent(new Event('input', { bubbles: true }));
            el.dispatchEvent(new Event('change', { bubbles: true }));
        },
        value,
    );
}
const value = (page, selector) => page.$eval(selector, (el) => el.value);
const exists = async (page, selector) => (await page.$(selector)) !== null;
const modalText = (page) => page.$eval(M, (el) => el.innerText);

module.exports = {
    name: 'content-tools',
    checks: ['C4', 'C5', 'C6', 'C7', 'C8', 'G9'],
    async run({ browser, page, h, check }) {
        let id = null;
        let Y = 0;
        let ready = false;
        const kept = {};
        // a failed check may leave unsaved changes behind: never let `beforeunload` block the next navigation
        page.on('dialog', (dialog) => dialog.accept().catch(() => null));

        await check('setup: calendar with a layout, events and shifts on, waste off, step 4', async () => {
            id = await h.createCalendar(page);
            Y = (await h.state(page)).design.year;
            const f = (await h.state(page)).design.features;
            for (const [key, want] of Object.entries({ events: true, shifts: true, moon: true, waste: false })) {
                if (f[key] !== want) await page.click(`label:has([data-feature="${key}"])`);
            }
            await h.gotoStep(page, 2);
            await page.click('button[data-preset="photoLeft"]');
            await page.waitForSelector('.cal-ws[data-cal-shown-step="3"]', { timeout: 10000 });
            await h.gotoStep(page, 4);
            await page.waitForSelector('[data-tool="waste"]', { timeout: 8000 });
            const s = await h.state(page);
            assert.deepEqual([s.design.features.events, s.design.features.shifts, s.design.features.moon, s.design.features.waste], [true, true, true, false]);
            ready = true;
        });
        if (!ready) return;

        await check('a tool whose feature is off shows the notice; Zapnout switches it on', async () => {
            await openTool(page, 'waste');
            assert.match(await modalText(page), /Odpady jsou vypnuté v kroku 1/);
            assert.equal(await exists(page, `${M} [data-waste-tab]`), false);
            await page.click(`${M} [data-cal-action="feature-on"]`);
            await h.waitToast(page, 'Zapnuto');
            await page.waitForSelector(`${M} [data-waste-tab="bio"]`);
            assert.equal((await h.state(page)).design.features.waste, true);
            assert.equal(await exists(page, `${M} [data-cal-stub]`), false, 'the tool is not a stub');
        });

        await check('C6 no Excel import in Odpady; the four built-in kinds are tabs', async () => {
            assert.doesNotMatch(await modalText(page), /Excel|AI /);
            assert.equal(await exists(page, `${M} input[type="file"]`), false);
            assert.deepEqual(await page.$$eval(`${M} [data-waste-tab]`, (els) => els.map((el) => el.textContent.trim())), ['Komunální odpad', 'Bio odpad', 'Plast', 'Papír']);
            assert.deepEqual(await page.$$eval(`${M} [data-waste-add] option`, (els) => els.map((el) => el.textContent)), ['+ Přidat druh', 'Sklo', 'Vlastní druh…']);
        });

        await check('C4 Bio odpad every Thursday, weekly, 1 April – 30 November', async () => {
            await page.click(`${M} [data-waste-tab="bio"]`);
            assert.equal(await exists(page, `${M} [data-weekday]`), false, 'a hidden kind shows only the switch');
            await page.click(`${M} label:has([data-waste-on])`);
            await page.waitForSelector(`${M} [data-weekday="3"]`);
            await page.click(`${M} [data-weekday="3"]`);
            await page.click(`${M} [data-weekday="0"]`);
            await page.select(`${M} [data-waste-interval]`, 'w');
            await pickDate(page, `${M} [data-waste-from]`, isoOf(Y, 4, 1));
            await pickDate(page, `${M} [data-waste-to]`, isoOf(Y, 11, 30));
            const bio = (await h.state(page)).content.wasteKinds.bio;
            assert.deepEqual([bio.on, bio.mode, bio.weekdays, bio.interval, bio.from, bio.to], [true, 'rule', [3], 'w', isoOf(Y, 4, 1), isoOf(Y, 11, 30)]);
            const expected = [];
            for (let t = Date.UTC(Y, 3, 1); t <= Date.UTC(Y, 10, 30); t += 86400000) {
                const d = new Date(t);
                if (d.getUTCDay() === 4) expected.push(isoOf(Y, d.getUTCMonth() + 1, d.getUTCDate()));
            }
            assert.ok(expected.length >= 34);
            assert.deepEqual(datesWith(await dayData(h, page), (d) => d.waste.includes('bio')), expected, 'exactly the Thursdays in the range, none outside');
            assert.equal(await page.$eval(`${M} [data-waste-total]`, (el) => el.textContent), `Vychází ${expected.length} svozů`);
            kept.bio = expected;
        });

        await check('waste: the last weekday cannot be unselected (Vyberte aspoň jeden den)', async () => {
            await page.click(`${M} [data-weekday="3"]`);
            await h.waitToast(page, 'Vyberte aspoň jeden den');
            assert.deepEqual((await h.state(page)).content.wasteKinds.bio.weekdays, [3]);
            assert.equal(await page.$eval(`${M} [data-weekday="3"]`, (el) => el.getAttribute('aria-pressed')), 'true');
        });

        await check('waste: an end before the start is rejected and the previous date kept', async () => {
            await pickDate(page, `${M} [data-waste-to]`, isoOf(Y, 3, 1));
            await h.waitToast(page, 'Konec svozu je před začátkem');
            assert.equal(await value(page, `${M} [data-waste-to]`), isoOf(Y, 11, 30));
            await pickDate(page, `${M} [data-waste-from]`, isoOf(Y, 12, 5));
            assert.equal(await value(page, `${M} [data-waste-from]`), isoOf(Y, 4, 1));
            const bio = (await h.state(page)).content.wasteKinds.bio;
            assert.deepEqual([bio.from, bio.to], [isoOf(Y, 4, 1), isoOf(Y, 11, 30)]);
        });

        await check('dates are limited to 1 December of the previous year … 31 January of the next', async () => {
            const limits = await page.$eval(`${M} [data-waste-from]`, (el) => [el.min, el.max]);
            assert.deepEqual(limits, [isoOf(Y - 1, 12, 1), isoOf(Y + 1, 1, 31)]);
            await pickDate(page, `${M} [data-waste-from]`, isoOf(Y - 1, 11, 30));
            assert.equal(await value(page, `${M} [data-waste-from]`), isoOf(Y, 4, 1), 'a date outside the range is dropped');
            assert.equal((await h.state(page)).content.wasteKinds.bio.from, isoOf(Y, 4, 1));
        });

        await check('C5 Plast as Konkrétní data: three clicked days, month strip shows the count', async () => {
            await page.click(`${M} [data-waste-tab="plast"]`);
            await page.click(`${M} label:has([data-waste-on])`);
            await page.waitForSelector(`${M} [data-waste-mode]`);
            await page.click(`${M} [data-waste-mode] button[data-value="dates"]`);
            await page.waitForSelector(`${M} .cal-dpg`);
            assert.equal(await page.$eval(`${M} [data-waste-count]`, (el) => el.textContent), 'Klikněte na dny svozu');
            await page.click(`${M} [data-month="3"]`);
            const days = [isoOf(Y, 3, 5), isoOf(Y, 3, 12), isoOf(Y, 3, 26)];
            for (const day of days) await page.click(`${M} [data-date="${day}"]`);
            assert.deepEqual((await h.state(page)).content.wasteKinds.plast.dates, days);
            assert.deepEqual(datesWith(await dayData(h, page), (d) => d.waste.includes('plast')), days, 'icons on exactly those days');
            assert.equal(await page.$eval(`${M} [data-month="3"]`, (el) => `${el.dataset.count}|${el.querySelector('i').hidden}|${el.querySelector('i').textContent}`), '3|false|3');
            assert.match(await page.$eval(`${M} [data-month="3"]`, (el) => el.getAttribute('aria-label')), /^březen \d{4}, 3 svozy$/);
            assert.equal(await page.$eval(`${M} [data-waste-count]`, (el) => el.textContent), 'Vybráno 3 termíny');
            assert.equal(await page.$eval(`${M} [data-date="${days[0]}"]`, (el) => el.getAttribute('aria-pressed')), 'true');
            // a second click removes the day again
            await page.click(`${M} [data-date="${isoOf(Y, 3, 19)}"]`);
            await page.click(`${M} [data-date="${isoOf(Y, 3, 19)}"]`);
            assert.deepEqual((await h.state(page)).content.wasteKinds.plast.dates, days);
            // the grid reaches December of the previous year and stops there
            await page.click(`${M} [data-month="1"]`);
            await page.click(`${M} [data-month-step="-1"]`);
            assert.equal(await page.$eval(`${M} .cal-dph b`, (el) => el.textContent), `Prosinec ${Y - 1}`);
            assert.equal(await page.$eval(`${M} [data-month-step="-1"]`, (el) => el.disabled), true);
            kept.plast = days;
        });

        await check('waste: Sklo and a custom kind are added, renamed; Vymazat vše; a kind is removed after a confirmation', async () => {
            await page.select(`${M} [data-waste-add]`, 'sklo');
            await page.waitForSelector(`${M} [data-waste-tab="sklo"].on`);
            await page.select(`${M} [data-waste-add]`, 'own');
            await page.waitForSelector(`${M} [data-waste-name]`);
            assert.equal(await page.evaluate(() => document.activeElement.matches('[data-waste-name]')), true, 'the name of the new kind has focus');
            assert.equal(await value(page, `${M} [data-waste-name]`), 'Nový druh odpadu');
            await typeInto(page, `${M} [data-waste-name]`, 'Textil');
            await typeInto(page, `${M} [data-waste-place]`, 'U hřiště');
            let kinds = (await h.state(page)).content.wasteKinds;
            const own = Object.keys(kinds).find((key) => kinds[key].builtin === null);
            assert.deepEqual([kinds[own].name, kinds[own].place, kinds[own].on, kinds.sklo.builtin], ['Textil', 'U hřiště', true, 'sklo']);
            assert.equal(await page.$eval(`${M} [data-waste-tab="${own}"]`, (el) => el.textContent.trim()), 'Textil');
            assert.equal(await page.evaluate(() => document.activeElement.matches('[data-waste-place]')), true, 'typing keeps the focus in the field');
            // dates + Vymazat vše on the custom kind
            await page.click(`${M} [data-waste-mode] button[data-value="dates"]`);
            await page.waitForSelector(`${M} .cal-dpg`);
            await page.click(`${M} [data-month="5"]`);
            await page.click(`${M} [data-date="${isoOf(Y, 5, 4)}"]`);
            await page.click(`${M} [data-date="${isoOf(Y, 5, 18)}"]`);
            assert.equal((await h.state(page)).content.wasteKinds[own].dates.length, 2);
            await page.click(`${M} [data-waste-clear]`);
            await h.answerDialog(page, 'confirm');
            await sleep(400);
            assert.deepEqual((await h.state(page)).content.wasteKinds[own].dates, []);
            // remove Sklo: cancel keeps it, confirm removes it
            await page.click(`${M} [data-waste-tab="sklo"]`);
            await page.click(`${M} [data-waste-remove]`);
            await page.waitForSelector('.cal-dialog.show');
            assert.equal(await page.$eval('.cal-dialog .modal-title', (el) => el.textContent), 'Odebrat druh odpadu „Sklo“?');
            await h.answerDialog(page, 'cancel');
            await sleep(400);
            assert.ok((await h.state(page)).content.wasteKinds.sklo);
            await page.click(`${M} [data-waste-remove]`);
            await h.answerDialog(page, 'confirm');
            await sleep(400);
            kinds = (await h.state(page)).content.wasteKinds;
            assert.equal(kinds.sklo, undefined);
            assert.equal(await exists(page, `${M} [data-waste-tab="sklo"]`), false);
            kept.own = own;
            await h.shot(page, 'waste-1440-dark');
            await closeTool(page);
        });

        await check('C6 no Excel import in Akce a spolky; C7 an event of club ZŠ prints „ZŠ · název“', async () => {
            await openTool(page, 'events');
            assert.doesNotMatch(await modalText(page), /Excel|AI /);
            assert.equal(await exists(page, `${M} input[type="file"]`), false);
            assert.equal(await page.$eval(`${M} [data-ev-empty]`, (el) => el.textContent), 'V tomto měsíci žádné akce.');
            await page.click(`${M} [data-club-add]`);
            await page.waitForSelector(`${M} [data-club-code]`);
            assert.equal(await value(page, `${M} [data-club-code]`), 'S1');
            await typeInto(page, `${M} [data-club-code]`, 'ZŠ-dlouhá');
            assert.equal(await value(page, `${M} [data-club-code]`), 'ZŠ-dlo', 'a code has at most 6 characters');
            await typeInto(page, `${M} [data-club-code]`, 'ZŠ');
            await typeInto(page, `${M} [data-club-name]`, 'Základní škola');
            await page.click(`${M} [data-event-add]`);
            await page.waitForSelector(`${M} [data-event-row]`);
            assert.equal(await page.evaluate(() => document.activeElement.matches('[data-event-name]')), true, 'the new event name has focus');
            await page.keyboard.type('Den dětí');
            const date = isoOf(Y, 6, 1);
            await pickDate(page, `${M} [data-event-date]`, date);
            const s = await h.state(page);
            const clubId = Object.keys(s.content.clubs)[0];
            const eventId = Object.keys(s.content.events)[0];
            assert.deepEqual(s.content.clubs[clubId], { code: 'ZŠ', name: 'Základní škola', pos: 0 });
            assert.deepEqual(s.content.events[eventId], { date, name: 'Den dětí', club: clubId, icon: null });
            assert.deepEqual((await dayData(h, page))[date].events, ['ZŠ · Den dětí']);
            // the month view followed the event into June; filter and heading name the club
            assert.equal(await page.$eval(`${M} [data-month="6"]`, (el) => `${el.classList.contains('on')}|${el.dataset.count}`), 'true|1');
            assert.equal(await page.$eval(`${M} .cal-s4h h3`, (el) => el.textContent), 'Základní škola');
            assert.deepEqual(await page.$$eval(`${M} [data-ev-filter] option`, (els) => els.map((el) => el.textContent)), ['Všechny akce (1)', 'ZŠ — Základní škola (1)', 'Bez pořadatele (0)']);
            assert.equal(await page.$eval(`${M} [data-event-name]`, (el) => el.maxLength), 80);
            kept.clubId = clubId;
            kept.eventDate = date;
        });

        await check('events: whole-year view, organiser filter, an empty name is not printed', async () => {
            await page.select(`${M} [data-ev-filter]`, '*');
            await page.click(`${M} [data-ev-view] button[data-value="y"]`);
            await page.click(`${M} [data-event-add]`);
            await page.waitForFunction((m) => document.querySelectorAll(`${m} [data-event-row]`).length === 2, {}, M);
            const s = await h.state(page);
            const blank = Object.keys(s.content.events).find((key) => s.content.events[key].name === '');
            assert.deepEqual(s.content.events[blank], { date: isoOf(Y, 1, 1), name: '', club: null, icon: null });
            assert.deepEqual((await dayData(h, page))[isoOf(Y, 1, 1)].events, [], 'an event without a name is not printed');
            await page.select(`${M} [data-ev-filter]`, '');
            assert.equal(await page.$eval(`${M} .cal-s4h h3`, (el) => el.textContent), 'Bez pořadatele');
            assert.deepEqual(await page.$$eval(`${M} [data-event-row]`, (els) => els.map((el) => el.dataset.eventRow)), [blank]);
            // give it the club through the row's own organiser select, then remove it
            await page.select(`${M} [data-event-row="${blank}"] [data-event-club]`, kept.clubId);
            assert.equal((await h.state(page)).content.events[blank].club, kept.clubId);
            await page.select(`${M} [data-ev-filter]`, '*');
            await page.click(`${M} [data-event-row="${blank}"] [data-event-remove]`);
            assert.equal((await h.state(page)).content.events[blank], undefined);
            assert.equal(await page.$$eval(`${M} [data-event-row]`, (els) => els.length), 1);
        });

        await check('C8 several events on one day: up to four print, a warning appears when they do not fit', async () => {
            const date = kept.eventDate;
            const before = (await h.cal(page, (c) => c.warnings())).filter((w) => w.kind === 'event').length;
            assert.equal(before, 0);
            let warned = false;
            let n = 1;
            while (!warned && n < 6) {
                await page.click(`${M} [data-event-add]`);
                await page.waitForFunction((m, count) => document.querySelectorAll(`${m} [data-event-row]`).length === count, {}, M, n + 1);
                await page.keyboard.type(`Akce číslo ${n + 1} s dlouhým názvem, aby se do pole určitě nevešla celá`);
                const s = await h.state(page);
                const fresh = Object.keys(s.content.events).find((key) => s.content.events[key].date === isoOf(Y, 1, 1));
                await pickDate(page, `${M} [data-event-row="${fresh}"] [data-event-date]`, date);
                n++;
                warned = (await h.cal(page, (c) => c.warnings())).some((w) => w.kind === 'event' && w.target.date === date);
            }
            assert.ok(warned, `a warning for ${date} after ${n} events`);
            const day = (await dayData(h, page))[date];
            assert.equal(day.overflow, true);
            assert.ok(day.events.length >= 2 && day.events.length <= 4, 'at most four events are printed');
            assert.equal(day.events[0].startsWith('ZŠ · ') || day.events.some((e) => e === 'ZŠ · Den dětí'), true);
            kept.eventCount = n;
            await h.shot(page, 'events-1440-dark');
            await closeTool(page);
            assert.equal(await page.$eval('[data-cal-action="warnings"]', (el) => el.hidden), false, 'the warning chip of step 4 shows');
        });

        await check('G9 2 January prints Karina; with the extra name Vasil it prints „Karina / Vasil“', async () => {
            const date = isoOf(Y, 1, 2);
            assert.equal((await dayData(h, page))[date].names, 'Karina');
            await openTool(page, 'names');
            assert.match(await modalText(page), /Jméno se připíše ke jménům dne, např\. „Karina \/ Vasil“\./);
            await page.click(`${M} [data-name-add]`);
            await page.waitForSelector(`${M} [data-name-row]`);
            await page.keyboard.type('Vasil');
            await pickDate(page, `${M} [data-name-date]`, date);
            const s = await h.state(page);
            assert.deepEqual(Object.values(s.content.names), [{ date, name: 'Vasil' }]);
            assert.equal((await dayData(h, page))[date].names, 'Karina / Vasil');
            assert.equal(await page.$eval(`${M} [data-name-preview]`, (el) => el.textContent), 'Karina / Vasil');
            // an empty extra name is not printed
            await page.click(`${M} [data-name-add]`);
            await page.waitForFunction((m) => document.querySelectorAll(`${m} [data-name-row]`).length === 2, {}, M);
            const blank = Object.entries((await h.state(page)).content.names).find(([, item]) => item.name === '')[0];
            await pickDate(page, `${M} [data-name-row="${blank}"] [data-name-date]`, isoOf(Y, 1, 3));
            assert.equal((await dayData(h, page))[isoOf(Y, 1, 3)].names, 'Radmila');
            await page.click(`${M} [data-name-row="${blank}"] [data-name-remove]`);
            assert.equal(Object.keys((await h.state(page)).content.names).length, 1);
            await h.shot(page, 'names-1440-dark');
            await closeTool(page);
        });

        await check('a date typed on the keyboard is accepted once complete (Enter leaves the field)', async () => {
            await openTool(page, 'names');
            await page.click(`${M} [data-name-add]`);
            await page.waitForFunction((m) => document.querySelectorAll(`${m} [data-name-row]`).length === 2, {}, M);
            const blank = Object.entries((await h.state(page)).content.names).find(([, item]) => item.name === '')[0];
            const field = `${M} [data-name-row="${blank}"] [data-name-date]`;
            await page.focus(field);
            // 3 March reads the same in day-first and month-first locales
            await page.keyboard.type(`0303${Y}`);
            assert.equal((await h.state(page)).content.names[blank].date, isoOf(Y, 3, 3), 'the complete date is applied while the field still has focus');
            await page.keyboard.press('Enter');
            assert.equal(await page.evaluate(() => document.activeElement.matches('[data-name-date]')), false);
            assert.equal(await value(page, field), isoOf(Y, 3, 3));
            await page.click(`${M} [data-name-row="${blank}"] [data-name-remove]`);
            assert.deepEqual(Object.values((await h.state(page)).content.names), [{ date: isoOf(Y, 1, 2), name: 'Vasil' }]);
            await closeTool(page);
        });

        await check('moon: change a phase, move a date, add, remove — and they print', async () => {
            const before = await dayData(h, page);
            const computed = datesWith(before, (d) => d.moon.length > 0);
            assert.ok(computed.length >= 48, 'computed phases of the year are printed');
            await openTool(page, 'moon');
            assert.equal(await page.$eval(`${M} [data-moon-reset]`, (el) => `${el.textContent}|${el.disabled}`), `Přepočítat podle roku ${Y}|true`);
            const january = await page.$$eval(`${M} [data-moon-row]`, (els) => els.map((el) => [el.dataset.moonRow, el.querySelector('[aria-checked="true"]').dataset.moonPhase]));
            assert.ok(january.length >= 3 && january.every(([date]) => date.startsWith(`${Y}-01`)), 'the month view lists January');
            assert.deepEqual(january.map(([date, phase]) => [date, Number(phase)]), computed.filter((d) => d.startsWith(`${Y}-01`)).map((d) => [d, before[d].moon[0]]));
            // 1) phase of the first row
            const [d0, p0] = january[0];
            const p1 = (Number(p0) + 2) % 4;
            await page.click(`${M} [data-moon-row="${d0}"] [data-moon-phase="${p1}"]`);
            assert.deepEqual(Object.values((await h.state(page)).content.moonEdits), [{ op: 'add', date: d0, phase: p1 }]);
            assert.deepEqual((await dayData(h, page))[d0].moon, [p1]);
            // back to the computed phase leaves no edit behind
            await page.click(`${M} [data-moon-row="${d0}"] [data-moon-phase="${p0}"]`);
            assert.deepEqual((await h.state(page)).content.moonEdits, {});
            await page.click(`${M} [data-moon-row="${d0}"] [data-moon-phase="${p1}"]`);
            // 2) move the second row to a free day
            const [d1, q1] = january[1];
            let free = '';
            for (let d = 1; d <= 28 && !free; d++) if (!january.some(([date]) => date === isoOf(Y, 1, d))) free = isoOf(Y, 1, d);
            await pickDate(page, `${M} [data-moon-row="${d1}"] [data-moon-date]`, free);
            let data = await dayData(h, page);
            assert.deepEqual([data[d1].moon, data[free].moon], [[], [Number(q1)]]);
            // a day that already has a phase is refused
            await pickDate(page, `${M} [data-moon-row="${free}"] [data-moon-date]`, d0);
            await h.waitToast(page, 'Na tento den už jedna fáze připadá');
            assert.equal(await value(page, `${M} [data-moon-row="${free}"] [data-moon-date]`), free);
            // 3) remove the third row
            const [d2] = january[2];
            await page.click(`${M} [data-moon-row="${d2}"] [data-moon-remove]`);
            // 4) add one (full moon on the first free day of the month shown)
            const rowsBefore = await page.$$eval(`${M} [data-moon-row]`, (els) => els.map((el) => el.dataset.moonRow));
            await page.click(`${M} [data-moon-add]`);
            await page.waitForFunction((m, count) => document.querySelectorAll(`${m} [data-moon-row]`).length === count, {}, M, rowsBefore.length + 1);
            const added = (await page.$$eval(`${M} [data-moon-row]`, (els) => els.map((el) => el.dataset.moonRow))).find((date) => !rowsBefore.includes(date));
            data = await dayData(h, page);
            assert.deepEqual([data[d2].moon, data[added].moon], [[], [2]]);
            const edits = Object.values((await h.state(page)).content.moonEdits).map((e) => `${e.op} ${e.date} ${e.phase}`).sort();
            assert.deepEqual(edits, [`add ${d0} ${p1}`, `add ${free} ${q1}`, `add ${added} 2`, `remove ${d1} ${q1}`, `remove ${d2} ${january[2][1]}`].sort());
            // whole year lists everything
            await page.click(`${M} [data-moon-view] button[data-value="y"]`);
            assert.ok((await page.$$eval(`${M} [data-moon-row]`, (els) => els.length)) >= computed.length - 1);
            await page.click(`${M} [data-moon-view] button[data-value="m"]`);
            kept.moon = { d0, p1, free, q1: Number(q1), d1, d2, added, computed: computed.length };
            await h.shot(page, 'moon-1440-dark');
            await closeTool(page);
        });

        await check('shifts: an operation with the 8 h preset, 12 h preset, add / remove a shift, crews', async () => {
            assert.deepEqual((await dayData(h, page))[isoOf(Y, 3, 3)].shifts, []);
            await openTool(page, 'shifts');
            assert.equal(await page.$eval(`${M} [data-shift-empty]`, (el) => el.hidden), false);
            await page.click(`${M} [data-shift-op-add]`);
            await page.waitForSelector(`${M} [data-shift-op]`);
            const opId = await page.$eval(`${M} [data-shift-op]`, (el) => el.dataset.shiftOp);
            const op = async () => (await h.state(page)).content.shiftOps[opId];
            assert.deepEqual(await op(), { name: 'Provoz 1', shifts: [{ letter: 'R', from: '06:00', to: '14:00' }, { letter: 'O', from: '14:00', to: '22:00' }, { letter: 'N', from: '22:00', to: '06:00' }], crews: ['A', 'B', 'C', 'D'], pos: 0 });
            await page.select(`${M} [data-shift-preset]`, '12');
            assert.deepEqual((await op()).shifts, [{ letter: 'D', from: '06:00', to: '18:00' }, { letter: 'N', from: '18:00', to: '06:00' }]);
            assert.equal(await page.$$eval(`${M} [data-shift-row]`, (els) => els.length), 2);
            assert.equal(await value(page, `${M} [data-shift-preset]`), '', 'the preset select returns to its placeholder');
            await page.select(`${M} [data-shift-preset]`, '8');
            assert.equal((await op()).shifts.length, 3);
            await page.click(`${M} [data-shift-add]`);
            assert.equal((await op()).shifts.length, 4);
            assert.equal(await page.$eval(`${M} [data-shift-add]`, (el) => el.hidden), true, 'at most four shifts');
            await page.keyboard.type('X');
            assert.deepEqual((await op()).shifts[3], { letter: 'X', from: '06:00', to: '14:00' });
            await page.$eval(`${M} [data-shift-row="3"] [data-shift-to]`, (el) => {
                el.value = '15:30';
                el.dispatchEvent(new Event('change', { bubbles: true }));
            });
            assert.equal((await op()).shifts[3].to, '15:30');
            await page.click(`${M} [data-shift-row="1"] [data-shift-remove]`);
            assert.deepEqual((await op()).shifts.map((s) => s.letter), ['R', 'N', 'X']);
            assert.deepEqual(await page.$$eval(`${M} [data-shift-letter]`, (els) => els.map((el) => el.value)), ['R', 'N', 'X']);
            await typeInto(page, `${M} [data-shift-crew="3"]`, 'Z');
            await typeInto(page, `${M} [data-shift-name]`, 'Válcovna');
            const now = await op();
            assert.deepEqual([now.name, now.crews], ['Válcovna', ['A', 'B', 'C', 'Z']]);
            const day = (await dayData(h, page))[isoOf(Y, 3, 3)].shifts;
            assert.equal(day.length, 1);
            assert.equal(day[0].length, 3, 'one crew letter per shift');
            const letters = new Set(Object.values(await dayData(h, page)).flatMap((d) => d.shifts[0]));
            assert.ok(letters.has('Z') && letters.has('A'), 'crew letters print');
            kept.opId = opId;
            kept.op = now;
            await h.shot(page, 'shifts-1440-dark');
            await closeTool(page);
        });

        await check('everything is kept after save + reload (C4, C5, C7, C8, G9, moon, shifts)', async () => {
            const before = await h.state(page);
            const dataBefore = await dayData(h, page);
            await h.save(page);
            await h.reload(page);
            const after = await h.state(page);
            for (const c of ['wasteKinds', 'clubs', 'events', 'names', 'moonEdits', 'shiftOps']) assert.deepEqual(after.content[c], before.content[c], c);
            assert.equal(after.design.features.waste, true);
            const data = await dayData(h, page);
            assert.deepEqual(data, dataBefore);
            assert.deepEqual(datesWith(data, (d) => d.waste.includes('bio')), kept.bio);
            assert.deepEqual(datesWith(data, (d) => d.waste.includes('plast')), kept.plast);
            assert.equal(data[isoOf(Y, 1, 2)].names, 'Karina / Vasil');
            assert.ok(data[kept.eventDate].events.includes('ZŠ · Den dětí'));
            assert.deepEqual(data[kept.moon.d0].moon, [kept.moon.p1]);
            assert.deepEqual(data[kept.moon.free].moon, [kept.moon.q1]);
            assert.ok((await h.cal(page, (c) => c.warnings())).some((w) => w.kind === 'event' && w.target.date === kept.eventDate));
            // the dialogs show the saved state again
            if ((await h.state(page)).design.step !== 4) await h.gotoStep(page, 4);
            await page.waitForSelector('[data-tool="waste"]');
            await openTool(page, 'waste');
            await page.click(`${M} [data-waste-tab="bio"]`);
            assert.deepEqual([await value(page, `${M} [data-waste-from]`), await value(page, `${M} [data-waste-to]`)], [isoOf(Y, 4, 1), isoOf(Y, 11, 30)]);
            assert.deepEqual(await page.$$eval(`${M} [data-weekday][aria-pressed="true"]`, (els) => els.map((el) => el.dataset.weekday)), ['3']);
            assert.equal(await page.$eval(`${M} [data-waste-tab="${kept.own}"]`, (el) => el.textContent.trim()), 'Textil');
            await closeTool(page);
        });

        await check('C7 with the event icon among the row icons, the icon shows in that row', async () => {
            // Kalendárium › Ikony › Přidat ikonu is phase P5b — the category is switched on in the style directly
            const layout = (await h.state(page)).design.layout;
            assert.equal(layout.styles.icons.order.includes('ev'), false);
            assert.equal((await dayData(h, page))[kept.eventDate].ev, 0);
            layout.styles.icons.order.push('ev');
            layout.styles.icons.ev.perEvent = true;
            await h.seed(page, { design: { layout } });
            const data = await dayData(h, page);
            assert.equal(data[kept.eventDate].ev, 1);
            assert.deepEqual(datesWith(data, (d) => d.ev > 0), [kept.eventDate], 'the icon shows only on days with an event');
        });

        await check('events: an own icon per event can be uploaded and removed (when the style prints one)', async () => {
            if ((await h.state(page)).design.step !== 4) await h.gotoStep(page, 4);
            await page.waitForSelector('[data-tool="events"]');
            await openTool(page, 'events');
            await page.click(`${M} [data-ev-view] button[data-value="y"]`);
            const s = await h.state(page);
            const eventId = Object.keys(s.content.events).find((key) => s.content.events[key].name === 'Den dětí');
            const row = `${M} [data-event-row="${eventId}"]`;
            const file = path.join(os.tmpdir(), `wboost-e2e-icon-${process.pid}.png`);
            fs.writeFileSync(file, Buffer.from('iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAIAAACQkWg2AAAAFklEQVR4nGM4YRNAEmIY1TCqYfhqAAA5l1QQO0NBsAAAAABJRU5ErkJggg==', 'base64'));
            try {
                const input = await page.$(`${row} [data-event-icon]`);
                assert.ok(input, 'the upload tile shows');
                await input.uploadFile(file);
                await page.waitForFunction((sel) => document.querySelector(`${sel} .cal-evico img`), { timeout: 10000 }, row);
                const icon = (await h.state(page)).content.events[eventId].icon;
                assert.match(String(icon), /^[A-Za-z0-9_-]{1,40}$/);
                await page.click(`${row} .cal-evico-x`);
                assert.equal((await h.state(page)).content.events[eventId].icon, null);
                assert.equal(await exists(page, `${row} .cal-evico img`), false);
            } finally {
                fs.rmSync(file, { force: true });
            }
            await closeTool(page);
        });

        await check('a deleted club leaves its events, printed without the code', async () => {
            if ((await h.state(page)).design.step !== 4) await h.gotoStep(page, 4);
            await page.waitForSelector('[data-tool="events"]');
            await openTool(page, 'events');
            await page.select(`${M} [data-ev-filter]`, kept.clubId);
            await page.waitForSelector(`${M} [data-club-remove]`);
            assert.equal(await page.$eval(`${M} [data-club-remove]`, (el) => el.getAttribute('aria-label')), 'Odebrat Základní škola');
            await page.click(`${M} [data-club-remove]`);
            const s = await h.state(page);
            assert.deepEqual(s.content.clubs, {});
            assert.equal(Object.keys(s.content.events).length, kept.eventCount);
            assert.ok(Object.values(s.content.events).every((e) => e.club === null));
            const day = (await dayData(h, page))[kept.eventDate];
            assert.ok(day.events.includes('Den dětí') && day.events.every((e) => !e.includes(' · ')), JSON.stringify(day.events));
            assert.equal(await value(page, `${M} [data-ev-filter]`), '*');
            await closeTool(page);
        });

        await check('moon: Přepočítat podle roku drops every edit', async () => {
            await openTool(page, 'moon');
            assert.equal(await page.$eval(`${M} [data-moon-reset]`, (el) => el.disabled), false);
            await page.click(`${M} [data-moon-reset]`);
            await h.waitToast(page, 'Fáze přepočítány automaticky');
            assert.deepEqual((await h.state(page)).content.moonEdits, {});
            const data = await dayData(h, page);
            assert.equal(datesWith(data, (d) => d.moon.length > 0).length, kept.moon.computed);
            assert.deepEqual([data[kept.moon.d1].moon.length, data[kept.moon.d2].moon.length, data[kept.moon.free].moon.length], [1, 1, 0]);
            await closeTool(page);
        });

        await check('shifts: removing an operation asks first; Esc closes a tool; saved again', async () => {
            await openTool(page, 'shifts');
            assert.equal(await value(page, `${M} [data-shift-name]`), 'Válcovna');
            await page.click(`${M} [data-shift-op-remove]`);
            await page.waitForSelector('.cal-dialog.show');
            assert.equal(await page.$eval('.cal-dialog .modal-title', (el) => el.textContent), 'Odebrat provoz „Válcovna“?');
            await h.answerDialog(page, 'cancel');
            await sleep(400);
            assert.ok((await h.state(page)).content.shiftOps[kept.opId]);
            await page.focus(`${M} [data-shift-name]`);
            await page.keyboard.press('Escape');
            await page.waitForFunction(() => !document.querySelector('.cal-tool-modal'), { timeout: 8000 });
            await h.save(page);
            await h.reload(page);
            const s = await h.state(page);
            assert.deepEqual([s.content.clubs, s.content.moonEdits], [{}, {}]);
            assert.deepEqual(s.content.shiftOps[kept.opId], kept.op);
        });

        await check('390 px: every dialog fits the phone width (no sideways scroll)', async () => {
            const phone = await h.newPage(browser, { width: 390, height: 780 });
            try {
                await h.openCalendar(phone, id);
                if ((await h.state(phone)).design.step !== 4) await h.gotoStep(phone, 4);
                for (const theme of ['dark', 'light']) {
                    await phone.evaluate((t) => document.documentElement.setAttribute('data-theme', t), theme);
                    for (const key of ['waste', 'events', 'names', 'moon', 'shifts']) {
                        await phone.$eval(`[data-tool="${key}"]`, (el) => el.click());
                        await phone.waitForSelector(`.cal-tool-modal.show[data-tool-modal="${key}"]`, { timeout: 8000 });
                        await sleep(350);
                        if (key === 'waste') await phone.$eval(`${M} [data-waste-tab="bio"]`, (el) => el.click());
                        const fit = await phone.$eval(`${M} .modal-body`, (el) => {
                            const wide = [...el.querySelectorAll('*')].filter((n) => n.getBoundingClientRect().right > window.innerWidth + 0.5 || n.getBoundingClientRect().left < -0.5);
                            return { scroll: el.scrollWidth - el.clientWidth, wide: wide.slice(0, 3).map((n) => n.className || n.tagName) };
                        });
                        assert.ok(fit.scroll <= 1, `${key}: body scrolls sideways by ${fit.scroll}px`);
                        assert.deepEqual(fit.wide, [], `${key}: elements leave the screen`);
                        await h.shot(phone, `${key}-390-${theme}`);
                        if (key === 'waste') {
                            await phone.$eval(`${M} [data-waste-tab="plast"]`, (el) => el.click());
                            await sleep(100);
                            await h.shot(phone, `waste-dates-390-${theme}`);
                        }
                        await phone.$eval(`${M} [data-cal-action="modal-done"]`, (el) => el.click());
                        await phone.waitForFunction(() => !document.querySelector('.cal-tool-modal'), { timeout: 8000 });
                    }
                }
                assert.deepEqual(phone.problems, [], 'console of the phone page');
            } finally {
                await phone.close().catch(() => null);
            }
        });

        await check('1440 px screenshots in both themes', async () => {
            if (!h.config.shots) return;
            if ((await h.state(page)).design.step !== 4) await h.gotoStep(page, 4);
            for (const theme of ['dark', 'light']) {
                await page.evaluate((t) => document.documentElement.setAttribute('data-theme', t), theme);
                for (const key of ['waste', 'events', 'names', 'moon', 'shifts']) {
                    await openTool(page, key);
                    if (key === 'waste') {
                        await page.click(`${M} [data-waste-tab="bio"]`);
                        await h.shot(page, `waste-rule-1440-${theme}`);
                        await page.click(`${M} [data-waste-tab="plast"]`);
                        await page.click(`${M} [data-month="3"]`);
                    }
                    if (key === 'events') await page.click(`${M} [data-ev-view] button[data-value="y"]`);
                    await h.shot(page, `${key}-1440-${theme}`);
                    await closeTool(page);
                }
            }
            await page.evaluate(() => document.documentElement.setAttribute('data-theme', 'dark'));
        });

        await check('cleanup', async () => {
            await h.deleteCalendar(page, id);
        });
    },
};
