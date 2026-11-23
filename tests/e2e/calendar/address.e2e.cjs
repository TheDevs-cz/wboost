/*
 * The workspace address (feedback K9; docs/architecture/calendars.md § "Address"): `?krok=N` and,
 * in steps 4 and 5, `&strana=<pageKey>` follow what is shown (replaceState), and a link with them
 * opens that step and page — as navigation only: nothing becomes unsaved, nothing is saved.
 * Invalid values are ignored; steps 3–5 need a layout; a client link takes `strana` only;
 * `?sken=` (the AI scan panel) wins over `krok`.
 */
const assert = require('assert/strict');
const ed = require('./lib/editor.cjs');

const search = (page) => page.evaluate(() => Object.fromEntries(new URLSearchParams(window.location.search)));
const shown = (page) => page.$eval('.cal-ws', (el) => el.dataset.calShownStep);

module.exports = {
    name: 'address',
    checks: ['K9'],
    async run({ browser, page, h, check }) {
        let id = null;
        let week = null;
        const saves = [];
        page.on('request', (request) => {
            if (request.method() === 'POST' && /\/calendar\/[^/]+\/save/.test(request.url())) saves.push(request.url());
        });

        await check('a calendar without a layout: krok=4 shows step 2, an invalid krok is ignored', async () => {
            ed.leaveFreely(page);
            id = await h.createCalendar(page);
            assert.deepEqual(await search(page), { krok: '1' }, 'step 1 is in the address right away');
            assert.match(await page.title(), / · Typ \| WBoost$/);
            await page.goto(`${h.config.base}/calendar/${id}?krok=4&strana=w05`, { waitUntil: 'networkidle2' });
            await h.waitReady(page);
            assert.equal(await shown(page), '1', 'no layout: the stored step 1 (steps 3–5 need a layout)');
            assert.deepEqual(await search(page), { krok: '1' });
            await page.goto(`${h.config.base}/calendar/${id}?krok=9&strana=%3Cb%3E`, { waitUntil: 'networkidle2' });
            await h.waitReady(page);
            assert.equal(await shown(page), '1');
            assert.deepEqual(await search(page), { krok: '1' });
        });

        await check('moving through the steps, thumbnails and arrows keeps the address current', async () => {
            await h.openCalendar(page, id);
            await h.gotoStep(page, 2);
            assert.deepEqual(await search(page), { krok: '2' });
            await page.click('[data-preset="photoLeft"]');
            await page.waitForSelector('.cal-ws[data-cal-shown-step="3"]');
            await h.save(page);
            assert.deepEqual(await search(page), { krok: '3' });
            assert.match(await page.title(), / · Úpravy prvků \| WBoost$/);
            await h.gotoStep(page, 4);
            assert.deepEqual(await search(page), { krok: '4' }, 'Styl stránek has no page');
            week = await h.cal(page, (c) => c.pages().filter((p) => p.kind === 'week')[4]);
            await page.click(`[data-page-thumb="${week.index}"]`);
            await page.waitForFunction((key) => new URLSearchParams(window.location.search).get('strana') === key, { timeout: 5000 }, week.key);
            assert.deepEqual(await search(page), { krok: '4', strana: week.key });
            await page.click('[data-cal-action="page-next"]');
            const after = await h.cal(page, (c, i) => c.pages()[i + 1].key, week.index);
            await page.waitForFunction((key) => new URLSearchParams(window.location.search).get('strana') === key, { timeout: 5000 }, after);
            assert.match(await page.title(), / · Obsah \| WBoost$/);
            await h.gotoStep(page, 5);
            const s5 = await search(page);
            assert.equal(s5.krok, '5');
            assert.ok(s5.strana, 'step 5 always shows a page');
            await page.$eval('[data-page-thumb="0"]', (el) => el.click());
            await page.waitForFunction(() => new URLSearchParams(window.location.search).get('strana') === 'cover', { timeout: 5000 });
            assert.match(await page.title(), / · Náhled \| WBoost$/);
            // back to step 3 by Zpět — the page goes from the address
            await page.click('[data-cal-action="prev-step"]');
            await page.waitForSelector('.cal-ws[data-cal-shown-step="4"]');
            await page.click('[data-cal-action="prev-step"]');
            await page.waitForSelector('.cal-ws[data-cal-shown-step="3"]');
            assert.deepEqual(await search(page), { krok: '3' });
            await page.waitForNetworkIdle({ idleTime: 200, timeout: 8000 }).catch(() => null);
            assert.equal((await h.state(page)).status, 'clean');
        });

        await check('a link ?krok=4&strana=<week> opens step 4 on that page — clean, nothing saved', async () => {
            const stored = (await h.state(page)).design.step;
            assert.equal(stored, 3);
            saves.length = 0;
            await page.goto(`${h.config.base}/calendar/${id}?krok=4&strana=${encodeURIComponent(week.key)}`, { waitUntil: 'networkidle2' });
            await h.waitReady(page);
            assert.equal(await shown(page), '4');
            assert.equal(await page.$eval(`[data-page-thumb="${week.index}"]`, (el) => el.getAttribute('aria-current')), 'true');
            await page.waitForSelector(`[data-page-panel="${week.key}"]`, { timeout: 5000 });
            const s = await h.state(page);
            assert.equal(s.status, 'clean');
            assert.deepEqual(await search(page), { krok: '4', strana: week.key });
            await h.sleep(400);
            assert.equal(saves.length, 0, 'opening by the address writes nothing');
            // a reload of the stored calendar (no address) still opens the stored step
            await page.goto(`${h.config.base}/calendar/${id}`, { waitUntil: 'networkidle2' });
            await h.waitReady(page);
            assert.equal(await shown(page), '3');
            // a link to step 5 with a special key that does not exist: step 5, the page ignored
            await page.goto(`${h.config.base}/calendar/${id}?krok=5&strana=sp%3Anope`, { waitUntil: 'networkidle2' });
            await h.waitReady(page);
            assert.equal(await shown(page), '5');
            assert.equal((await search(page)).krok, '5');
            assert.equal((await h.state(page)).status, 'clean');
        });

        await check('an edit after opening by the address saves without moving the view; the next step move is stored', async () => {
            await page.goto(`${h.config.base}/calendar/${id}?krok=4&strana=${encodeURIComponent(week.key)}`, { waitUntil: 'networkidle2' });
            await h.waitReady(page);
            await page.$eval('[data-page-field="caption"]', (el) => el.focus());
            await page.keyboard.type('Adresa K9');
            await page.keyboard.press('Enter');
            await h.save(page);
            assert.equal(await shown(page), '4', 'the answer of the save does not jump back to the stored step 3');
            assert.equal((await h.state(page)).design.step, 4);
            await h.gotoStep(page, 5);
            await page.waitForNetworkIdle({ idleTime: 200, timeout: 8000 }).catch(() => null);
            await page.goto(`${h.config.base}/calendar/${id}`, { waitUntil: 'networkidle2' });
            await h.waitReady(page);
            assert.equal(await shown(page), '5', 'moving on stored the step as usual');
        });

        await check('client link with strana opens that page in step 5', async () => {
            const clientUrl = await page.evaluate(() => JSON.parse(document.querySelector('[data-calendar-workspace-target="bootstrap"]').textContent).env.clientUrl);
            assert.ok(clientUrl);
            const anonymous = await browser.createBrowserContext();
            const client = await h.newPage(anonymous);
            try {
                await client.goto(`${clientUrl}?krok=2&strana=${encodeURIComponent(week.key)}`, { waitUntil: 'networkidle2' });
                await h.waitReady(client);
                assert.equal(await shown(client), '5');
                assert.equal(await client.$eval(`[data-page-thumb="${week.index}"]`, (el) => el.getAttribute('aria-current')), 'true');
                assert.deepEqual(await search(client), { strana: week.key }, 'a client has no krok');
                assert.equal((await h.state(client)).status, 'clean');
                await client.$eval('[data-page-thumb="0"]', (el) => el.click());
                await client.waitForFunction(() => new URLSearchParams(window.location.search).get('strana') === 'cover', { timeout: 5000 });
            } finally {
                await anonymous.close();
            }
        });

        await check('?sken= wins over krok (when the AI scan is on for this project)', async () => {
            const hasScan = await page.evaluate(() => Boolean(JSON.parse(document.querySelector('[data-calendar-workspace-target="bootstrap"]').textContent).env.scan));
            if (!hasScan) {
                console.log('    (skipped: no env.scan for the e2e project — run with the AI scan switched on, see scan.e2e.cjs)');
                return;
            }
            await page.goto(`${h.config.base}/calendar/${id}?sken=1&krok=4&strana=${encodeURIComponent(week.key)}`, { waitUntil: 'networkidle2' });
            await h.waitReady(page);
            await page.waitForSelector('.cal-ws[data-cal-shown-step="2"]', { timeout: 10000 });
            await page.waitForFunction(() => !new URLSearchParams(window.location.search).has('sken'), { timeout: 10000 });
            assert.deepEqual(await search(page), { krok: '2' });
        });

        await check('clean up', async () => {
            await h.deleteCalendar(page, id);
        });
    },
};
