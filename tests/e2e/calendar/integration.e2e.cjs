/*
 * Integration checks across the phases of the Kalendáře module — things no single phase owns:
 *
 *   - the silent step save under rapid step changes (store.save follow-up, state/payload.js)
 *   - a confirm dialog stacked on a tool dialog (ui/dom.js `showModal`)
 *   - step 5 on a phone: a 14-day page whose day rows are the buttons of the plus menu
 *     (ui/step5.js + ui/step5-overlay.js "compact")
 *
 * Everything is driven like a user: real clicks, key presses and touch taps; state is read through
 * `window.__calendar` and re-read after a reload.
 */
const assert = require('assert/strict');
const E = require('./lib/editor.cjs');

const POP = '.cal-pop';
const shownStep = (page) => page.$eval('.cal-ws', (el) => Number(el.dataset.calShownStep));
const idle = (page) => page.waitForNetworkIdle({ idleTime: 400, timeout: 15000 });

/** Slows every save down so that several step changes fit into one round trip. */
async function slowSaves(page, ms) {
    await page.setRequestInterception(true);
    const handler = (request) => {
        if (request.method() === 'POST' && /\/save$/.test(request.url())) setTimeout(() => request.continue().catch(() => null), ms);
        else request.continue().catch(() => null);
    };
    page.on('request', handler);
    return async () => {
        page.off('request', handler);
        await page.setRequestInterception(false);
    };
}

/** Records every value the save status takes from now on. */
const watchStatus = (page) =>
    page.evaluate(() => {
        const el = document.querySelector('[data-cal-status]');
        window.__statusSeen = [el.dataset.calStatus];
        if (window.__statusObserver) window.__statusObserver.disconnect();
        window.__statusObserver = new MutationObserver(() => window.__statusSeen.push(el.dataset.calStatus));
        window.__statusObserver.observe(el, { attributes: true, attributeFilter: ['data-cal-status'] });
    });
const statusSeen = (page) => page.evaluate(() => [...new Set(window.__statusSeen)]);

const modalState = (page) =>
    page.evaluate(() => {
        const shown = [...document.querySelectorAll('.modal.show')];
        const active = document.activeElement;
        const top = document.elementFromPoint(window.innerWidth / 2, window.innerHeight / 2);
        return {
            shown: shown.map((m) => (m.classList.contains('cal-dialog') ? 'confirm' : m.dataset.toolModal || m.dataset.calDialog || 'modal')),
            backdrops: document.querySelectorAll('.modal-backdrop').length,
            bodyOpen: document.body.classList.contains('modal-open'),
            bodyOverflow: getComputedStyle(document.body).overflow,
            topAtCentre: top && top.closest('.modal') ? (top.closest('.modal').classList.contains('cal-dialog') ? 'confirm' : 'tool') : null,
            focusIn: active && active.closest('.modal') ? (active.closest('.modal').classList.contains('cal-dialog') ? 'confirm' : 'tool') : null,
            focusOn: active ? Object.keys(active.dataset).join(',') || active.className || active.tagName : null,
            // focus on a real element that is not in a dialog (focus on <body> is "nowhere")
            focusOutside: Boolean(active && active !== document.body && !active.closest('.modal')),
        };
    });

module.exports = {
    name: 'integration',
    checks: ['R1', '§8 dialogs', 'C9'],
    async run({ browser, page, h, check }) {
        let weekId = null;
        let fortnightId = null;
        let year = null;

        await check('setup: a 7-day and a 14-day calendar with a layout', async () => {
            const week = await E.newCalendar(page, h, {});
            weekId = week.id;
            year = week.design.year;
            const fortnight = await E.newCalendar(page, h, { type: 'fortnight' });
            fortnightId = fortnight.id;
        });

        await check('R1 step save: rapid step changes end on the last step chosen, stay "Vše uloženo", and a reload opens that step', async () => {
            await h.openCalendar(page, weekId);
            await h.gotoStep(page, 5);
            await idle(page);
            const restore = await slowSaves(page, 350);
            try {
                const sequences = [
                    { clicks: [1, 5], gap: 0 },
                    { clicks: [3, 1, 3], gap: 0 },
                    { clicks: [1, 5], gap: 120 },
                    { clicks: [3, 1, 3], gap: 200 },
                    { clicks: [4, 5, 4, 1, 5], gap: 60 },
                    { clicks: [1, 2, 3, 4], gap: 400 }, // an answer lands between the clicks
                    { clicks: [5, 1, 5, 1, 4], gap: 30 },
                ];
                for (const { clicks, gap } of sequences) {
                    await watchStatus(page);
                    for (const step of clicks) {
                        await page.click(`[data-cal-step="${step}"]`);
                        if (gap) await h.sleep(gap);
                    }
                    const last = clicks[clicks.length - 1];
                    const label = `${clicks.join(' → ')} (gap ${gap} ms)`;
                    await page.waitForSelector(`.cal-ws[data-cal-shown-step="${last}"]`, { timeout: 5000 });
                    // while the answers of the older saves come in, the user is never moved
                    for (let i = 0; i < 12; i++) {
                        assert.equal(await shownStep(page), last, `${label}: bounced to another step`);
                        await h.sleep(100);
                    }
                    await idle(page);
                    assert.equal(await shownStep(page), last, label);
                    assert.equal((await h.state(page)).design.step, last, label);
                    assert.deepEqual(await statusSeen(page), ['clean'], `${label}: the status only ever read "Vše uloženo"`);
                    await h.reload(page);
                    assert.equal(await shownStep(page), last, `${label}: a reload opens the last step`);
                }
            } finally {
                await restore();
            }
        });

        await check('R1 step save: a real edit made while a step save is in flight is not lost and not falsely clean', async () => {
            await h.openCalendar(page, weekId);
            await h.gotoStep(page, 1);
            await idle(page);
            const restore = await slowSaves(page, 350);
            try {
                await page.click('[data-cal-step="3"]');
                await page.click('[data-cal-step="1"]');
                await page.waitForSelector('.cal-ws[data-cal-shown-step="1"]');
                await page.click('label:has([data-feature="qr"])');
                await h.waitStatus(page, 'dirty');
                await h.sleep(900);
                assert.equal((await h.state(page)).status, 'dirty', 'the unsaved switch keeps the status dirty after the step answers');
                assert.equal(await shownStep(page), 1);
            } finally {
                await restore();
            }
            await h.save(page);
            await h.reload(page);
            const s = await h.state(page);
            assert.equal(s.design.step, 1);
            assert.equal(s.design.features.qr, true);
        });

        await check('§8 stacked dialogs: a confirm over a tool dialog — Esc closes only the confirm, focus returns, the tool keeps Esc, Tab and the scroll lock', async () => {
            const s = await h.state(page);
            await h.seed(page, {
                design: { features: { ...s.design.features, waste: true, shifts: true }, step: 4 },
                content: [
                    { c: 'wasteKinds', id: 'bio', v: { ...s.content.wasteKinds.bio, on: true, mode: 'dates', dates: [`${year}-03-02`, `${year}-03-16`, `${year}-03-30`] } },
                    { c: 'shiftOps', id: 'op1', v: { name: 'Provoz 1', shifts: [{ letter: 'R', from: '06:00', to: '14:00' }], crews: ['A', 'B', 'C', 'D'], pos: 0 } },
                ],
            });
            await page.waitForSelector('.cal-b5 [data-page-thumb="0"]');
            const openTool = async (key) => {
                await page.click(`[data-tool="${key}"]`);
                await page.waitForSelector(`.cal-tool-modal.show[data-tool-modal="${key}"]`, { timeout: 5000 });
                await h.sleep(350);
            };
            const confirmShown = async () => {
                await page.waitForSelector('.cal-dialog.show [data-cal-choice="confirm"]', { timeout: 5000 });
                await h.sleep(350);
            };
            const confirmGone = async () => {
                await page.waitForFunction(() => !document.querySelector('.cal-dialog'), { timeout: 5000 });
                await h.sleep(50);
            };
            const toolGone = async () => {
                await page.waitForFunction(() => !document.querySelector('.cal-modal, .cal-dialog'), { timeout: 5000 });
            };

            // --- Odpady › Vymazat vše: Esc on the confirm
            await openTool('waste');
            await page.click('[data-waste-tab="bio"]');
            await page.waitForSelector('[data-waste-clear]:not([disabled])');
            await page.click('[data-waste-clear]');
            await confirmShown();
            let m = await modalState(page);
            assert.deepEqual(m.shown, ['waste', 'confirm']);
            assert.equal(m.topAtCentre, 'confirm', 'the confirm lies above the tool dialog');
            assert.equal(m.focusIn, 'confirm');
            assert.equal(m.backdrops, 2);
            const z = await page.evaluate(() => ({
                tool: Number(getComputedStyle(document.querySelector('.cal-tool-modal')).zIndex),
                backdrop: Number(getComputedStyle([...document.querySelectorAll('.modal-backdrop')].pop()).zIndex),
                confirm: Number(getComputedStyle(document.querySelector('.cal-dialog')).zIndex),
            }));
            assert.ok(z.tool < z.backdrop && z.backdrop < z.confirm, `the confirm's backdrop dims the tool dialog: ${JSON.stringify(z)}`);
            await h.shot(page, 'stacked-confirm');
            await page.keyboard.press('Escape');
            await confirmGone();
            m = await modalState(page);
            assert.deepEqual(m.shown, ['waste'], 'Esc closed only the confirm');
            assert.equal(m.bodyOpen, true, '<body> keeps modal-open');
            assert.equal(m.bodyOverflow, 'hidden', 'the page behind stays locked');
            assert.equal(m.backdrops, 1);
            assert.equal(m.focusIn, 'tool');
            assert.match(m.focusOn, /wasteClear/, 'focus is back on the button that asked');
            assert.equal((await h.state(page)).content.wasteKinds.bio.dates.length, 3, 'nothing was cleared');
            // Tab stays inside the tool dialog (its focus trap is alive again)
            let inside = 0;
            for (let i = 0; i < 40; i++) {
                await page.keyboard.press('Tab');
                const now = await modalState(page);
                assert.equal(now.focusOutside, false, `Tab ${i + 1} reached the page behind the dialog (${now.focusOn})`);
                if (now.focusIn === 'tool') inside++;
            }
            assert.ok(inside >= 30, `Tab walks the dialog (${inside} / 40 stops inside)`);
            // the decisive test of the trap: focus pushed onto the page behind comes straight back
            await page.evaluate(() => document.querySelector('[data-cal-action="save"]').focus());
            m = await modalState(page);
            assert.equal(m.focusOutside, false, 'the focus trap of the tool dialog is alive');
            assert.equal(m.focusIn, 'tool');
            // Esc now closes the tool dialog itself
            await page.keyboard.press('Escape');
            await toolGone();
            m = await modalState(page);
            assert.equal(m.bodyOpen, false);
            assert.equal(m.backdrops, 0);

            // --- Vymazat vše: Zrušit by click, then confirmed
            await openTool('waste');
            await page.click('[data-waste-tab="bio"]');
            await page.click('[data-waste-clear]');
            await confirmShown();
            await page.click('.cal-dialog.show [data-cal-choice="cancel"]');
            await confirmGone();
            m = await modalState(page);
            assert.equal(m.bodyOpen, true);
            assert.match(m.focusOn, /wasteClear/);
            await page.keyboard.press('Enter'); // the focused button asks again
            await confirmShown();
            await page.click('.cal-dialog.show [data-cal-choice="confirm"]');
            await confirmGone();
            assert.equal((await h.state(page)).content.wasteKinds.bio.dates.length, 0, 'cleared');
            m = await modalState(page);
            assert.deepEqual(m.shown, ['waste']);
            assert.equal(m.bodyOpen, true);
            assert.equal(m.focusIn, 'tool', 'focus stays in the tool dialog after the answer');

            // --- Odebrat druh: the button that asked disappears with its kind
            await page.click('[data-waste-remove]');
            await confirmShown();
            assert.equal((await modalState(page)).topAtCentre, 'confirm');
            await page.click('.cal-dialog.show [data-cal-choice="confirm"]');
            await confirmGone();
            await h.sleep(80);
            assert.equal((await h.state(page)).content.wasteKinds.bio, undefined, 'the kind is removed');
            m = await modalState(page);
            assert.deepEqual(m.shown, ['waste']);
            assert.equal(m.bodyOpen, true);
            assert.equal(m.focusIn, 'tool', 'the tool dialog took focus when the button went away');
            await page.keyboard.press('Escape');
            await toolGone();
            assert.equal((await modalState(page)).bodyOpen, false);

            // --- Šichtovnice › Odebrat provoz
            await openTool('shifts');
            await page.click('[data-shift-op-remove]');
            await confirmShown();
            await page.keyboard.press('Escape');
            await confirmGone();
            m = await modalState(page);
            assert.deepEqual(m.shown, ['shifts']);
            assert.equal(m.bodyOpen, true);
            assert.equal(m.focusIn, 'tool');
            assert.ok((await h.state(page)).content.shiftOps.op1, 'the operation is kept');
            await page.keyboard.press('Escape');
            await toolGone();
            await h.save(page);
        });

        await check('§8 dialogs: a confirm on its own (no dialog below) behaves as before', async () => {
            await h.openCalendar(page, weekId);
            await h.gotoStep(page, 1);
            await page.click('label:has([data-feature="moon"])');
            await h.waitStatus(page, 'dirty');
            await page.click('[data-cal-action="back-to-list"]');
            await page.waitForSelector('.cal-dialog.show [data-cal-choice="stay"]');
            await h.sleep(350);
            let m = await modalState(page);
            assert.deepEqual(m.shown, ['confirm']);
            assert.equal(m.bodyOpen, true);
            await page.keyboard.press('Escape');
            await page.waitForFunction(() => !document.querySelector('.cal-modal, .cal-dialog'), { timeout: 5000 });
            m = await modalState(page);
            assert.equal(m.bodyOpen, false);
            assert.equal(m.backdrops, 0);
            await h.save(page);
        });

        await check('C9 phone: step 5 of a 14-day page at 390 px — day rows are the buttons, nothing overlaps, a tap opens the plus menu', async () => {
            const phone = await h.newPage(browser, { width: 390, height: 844, hasTouch: true });
            try {
                await h.login(phone);
                await h.openCalendar(phone, fortnightId);
                const s = await h.state(phone);
                await h.seed(phone, { design: { features: { ...s.design.features, waste: true, events: true }, step: 5 }, content: [{ c: 'wasteKinds', id: 'bio', v: { ...s.content.wasteKinds.bio, on: true } }] });
                await phone.waitForSelector('.cal-5w svg');
                const pages = await h.cal(phone, (c) => c.pages());
                const week = pages.find((p) => p.kind === 'week' && p.index > 1);
                await h.cal(phone, () => null);
                // go to that page with the next button (taps)
                for (let i = 0; i < week.index; i++) {
                    const next = await (await phone.$('[data-cal-action="page-next"]')).boundingBox();
                    await phone.touchscreen.tap(next.x + next.width / 2, next.y + next.height / 2);
                }
                await phone.waitForSelector(`.cal-5w svg[data-page="${week.key}"]`, { timeout: 5000 });
                await phone.waitForSelector('.cal-5o.compact [data-day-row]');
                await h.sleep(200);

                const layout = await phone.evaluate(() => {
                    const rect = (el) => {
                        const r = el.getBoundingClientRect();
                        return { l: r.left, t: r.top, r: r.right, b: r.bottom, w: r.width, h: r.height };
                    };
                    return {
                        rows: [...document.querySelectorAll('[data-day-row]')].map((el) => ({ date: el.dataset.dayRow, ...rect(el) })),
                        plus: document.querySelectorAll('[data-day-plus]').length,
                        page: rect(document.querySelector('.cal-5w')),
                        hint: (() => {
                            const el = document.querySelector('.cal-5tap');
                            return el && !el.hidden ? el.textContent.trim() : null;
                        })(),
                        scrollWidth: document.scrollingElement.scrollWidth,
                        width: window.innerWidth,
                    };
                });
                assert.equal(layout.plus, 0, 'no per-row plus buttons on a phone');
                assert.equal(layout.rows.length, 14, 'one tappable row per day');
                assert.equal(layout.hint, 'Klepněte na den a přidejte odpad, akci nebo komentář.');
                assert.ok(layout.scrollWidth <= layout.width, `the page does not scroll sideways (${layout.scrollWidth} > ${layout.width})`);
                assert.ok(layout.page.w >= 300, `the freed gutter goes to the page: ${layout.page.w}px wide`);
                const byColumn = new Map();
                for (const row of layout.rows) {
                    assert.ok(row.l >= layout.page.l - 0.5 && row.r <= layout.page.r + 0.5, 'rows lie on the page');
                    assert.ok(row.h >= 8, `row ${row.date} is ${row.h}px high`);
                    const key = Math.round(row.l);
                    if (!byColumn.has(key)) byColumn.set(key, []);
                    byColumn.get(key).push(row);
                }
                for (const column of byColumn.values()) {
                    column.sort((a, b) => a.t - b.t);
                    for (let i = 1; i < column.length; i++) assert.ok(column[i].t >= column[i - 1].b - 0.6, `rows ${column[i - 1].date} / ${column[i].date} overlap`);
                }
                // every row is the top-most thing at its centre (a tap lands on it)
                const hits = await phone.evaluate(() => [...document.querySelectorAll('[data-day-row]')].map((el) => {
                    const r = el.getBoundingClientRect();
                    const top = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
                    return top === el;
                }));
                assert.ok(hits.every(Boolean), 'each row receives its own tap');
                await h.shot(phone, 'phone-step5-fortnight');

                // tap a day → the plus menu of THAT day → a comment
                const tapRow = async (date) => {
                    const box = await (await phone.$(`[data-day-row="${date}"]`)).boundingBox();
                    await phone.touchscreen.tap(box.x + box.width / 2, box.y + box.height / 2);
                    await phone.waitForSelector(`${POP}[data-cal-popover="plus"]`, { timeout: 3000 });
                };
                const first = layout.rows[3].date;
                const second = layout.rows[4].date;
                const label = (date) => `${Number(date.slice(8))}. ${Number(date.slice(5, 7))}.`;
                await tapRow(first);
                assert.ok((await phone.$eval(`${POP} .cal-pop-t`, (el) => el.textContent)).includes(label(first)), 'the menu is for the tapped day');
                assert.deepEqual(await phone.$$eval(`${POP} [data-plus-option]`, (nodes) => nodes.map((n) => n.dataset.plusOption)), ['waste', 'event', 'comment']);
                const popRect = await phone.$eval(POP, (el) => {
                    const r = el.getBoundingClientRect();
                    return { l: r.left, r: r.right, t: r.top, b: r.bottom };
                });
                assert.ok(popRect.l >= 0 && popRect.r <= 390 && popRect.t >= 0, 'the menu fits the screen');
                await h.shot(phone, 'phone-step5-plus');
                const option = await (await phone.$(`${POP} [data-plus-option="comment"]`)).boundingBox();
                await phone.touchscreen.tap(option.x + option.width / 2, option.y + option.height / 2);
                await phone.waitForSelector(`${POP} [data-field="text"]`);
                await phone.type(`${POP} [data-field="text"]`, 'Prosím posunout svoz');
                const ok = await (await phone.$(`${POP} [data-popover-confirm]`)).boundingBox();
                await phone.touchscreen.tap(ok.x + ok.width / 2, ok.y + ok.height / 2);
                await phone.waitForSelector(`[data-day-comments="${first}"]`);
                const comments = Object.values((await h.state(phone)).content.comments);
                assert.deepEqual(comments.map((c) => [c.date, c.text]), [[first, 'Prosím posunout svoz']]);
                // close whatever is open, then the mark of the comment fits its row
                await phone.keyboard.press('Escape');
                await h.sleep(150);
                if (await phone.$(POP)) await phone.keyboard.press('Escape');
                await phone.waitForFunction((selector) => !document.querySelector(selector), { timeout: 3000 }, POP);
                const mark = await phone.evaluate((date) => {
                    const r = document.querySelector(`[data-day-comments="${date}"]`).getBoundingClientRect();
                    const row = document.querySelector(`[data-day-row="${date}"]`).getBoundingClientRect();
                    return { t: r.top, b: r.bottom, h: r.height, rowT: row.top, rowB: row.bottom, l: r.left, r: r.right, vw: window.innerWidth };
                }, first);
                assert.ok(mark.t >= mark.rowT - 1 && mark.b <= mark.rowB + 1, `the comment mark stays inside its row: ${JSON.stringify(mark)}`);
                assert.ok(mark.l >= 0 && mark.r <= mark.vw, 'and on the screen');
                // the neighbouring day still opens its own menu
                await tapRow(second);
                assert.ok((await phone.$eval(`${POP} .cal-pop-t`, (el) => el.textContent)).includes(label(second)));
                await phone.keyboard.press('Escape');
                await h.shot(phone, 'phone-step5-comment');

                await h.save(phone);
                await h.reload(phone);
                assert.deepEqual(Object.values((await h.state(phone)).content.comments).map((c) => c.date), [first]);
                assert.equal(phone.problems.length, 0, phone.problems.join(' | '));
            } finally {
                await phone.close().catch(() => null);
            }
        });

        await check('C9 desktop: the same 14-day page at 1440 px keeps one plus button per day', async () => {
            await h.openCalendar(page, fortnightId);
            await page.waitForSelector('.cal-5w svg');
            await page.click('[data-page-thumb="3"]');
            await page.waitForSelector('[data-day-plus]');
            assert.equal(await page.$$eval('[data-day-plus]', (nodes) => nodes.length), 14);
            assert.equal(await page.$('[data-day-row]'), null);
            assert.equal(await page.$eval('.cal-5tap', (el) => el.hidden), true);
            assert.equal(await page.$eval('.cal-5o', (el) => el.classList.contains('compact')), false);
        });

        await check('cleanup', async () => {
            for (const id of [weekId, fortnightId]) {
                if (!id) continue;
                await h.gotoList(page);
                await h.deleteCalendar(page, id);
            }
        });
    },
};
