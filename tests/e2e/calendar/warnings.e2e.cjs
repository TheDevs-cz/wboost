/*
 * Warnings (phase P7b; handoff §4.8, C3, C8 as a warning, "Dokončit on a calendar with warnings").
 *
 * Photos are generated in the page (canvas → JPEG) and uploaded to the real asset endpoint, so
 * the pixel sizes come back from the server exactly like for a user's upload. Content is seeded
 * through the save endpoint, the page is reloaded, and everything is asserted on what the user
 * sees: the chip, the thumbnail badges, the marks on the step-5 page, the Upozornění dialog.
 *
 *   portrait photo → `portrait` · small photo → `dpi` with the real cover-scale number ·
 *   zoom lowers the dpi · five waste kinds on one day → `icons` · long events → `event` ·
 *   a row of the list jumps to its page · warnings never block Dokončit.
 */
const assert = require('assert/strict');

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

/** Draws a `width × height` JPEG in the page and uploads it as a photo; answers the asset info. */
async function uploadPhoto(page, width, height, name) {
    const answer = await page.evaluate(
        async (w, hgt, fileName) => {
            const boot = JSON.parse(document.querySelector('[data-calendar-workspace-target="bootstrap"]').textContent);
            const canvas = document.createElement('canvas');
            canvas.width = w;
            canvas.height = hgt;
            const g = canvas.getContext('2d');
            g.fillStyle = '#7a93b8';
            g.fillRect(0, 0, w, hgt);
            g.fillStyle = '#f2d23c';
            g.fillRect(w * 0.1, hgt * 0.1, w * 0.3, hgt * 0.3);
            const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.8));
            const form = new FormData();
            form.append('_token', boot.env.csrf);
            form.append('kind', 'photo');
            form.append('file', new File([blob], fileName, { type: 'image/jpeg' }));
            const response = await fetch(boot.env.urls.asset, { method: 'POST', body: form, credentials: 'same-origin' });
            return { status: response.status, json: await response.json().catch(() => null) };
        },
        width,
        height,
        name,
    );
    assert.equal(answer.status, 200, `upload failed: ${JSON.stringify(answer.json)}`);
    assert.equal(answer.json.width, width);
    assert.equal(answer.json.height, height);
    return answer.json;
}

const warningsOf = (page, h, index) => h.cal(page, (c, i) => c.warnings().filter((w) => w.pageIndex === i), index);
const chipCount = (page) => page.$eval('[data-cal-action="warnings"]', (el) => (el.hidden ? 0 : Number(el.dataset.count)));
const thumbWarn = (page, index) => page.$eval(`[data-page-thumb="${index}"]`, (el) => Number(el.dataset.warn || 0));
const shownPage = (page) => page.$eval('[data-page-thumb][aria-current="true"]', (el) => Number(el.dataset.pageThumb));

async function showPage(page, index) {
    await page.$eval(`[data-page-thumb="${index}"]`, (el) => el.click());
    await page.waitForFunction((i) => document.querySelector(`[data-page-thumb="${i}"]`).getAttribute('aria-current') === 'true', {}, index);
    await sleep(250);
}

async function openList(page) {
    await page.$eval('[data-cal-action="warnings"]', (el) => el.click());
    await page.waitForSelector('[data-cal-dialog="warnings"].show', { timeout: 5000 });
    await sleep(350);
}

async function closeList(page) {
    await page.keyboard.press('Escape');
    await page.waitForFunction(() => !document.querySelector('[data-cal-dialog="warnings"]'), { timeout: 5000 });
}

const listRows = (page) =>
    page.$$eval('[data-cal-dialog="warnings"] [data-warning-page]', (nodes) =>
        nodes.map((node) => ({
            page: Number(node.dataset.warningPage),
            title: node.querySelector('b').textContent,
            rows: [...node.querySelectorAll('[data-warning-kind]')].map((row) => ({ kind: row.dataset.warningKind, text: row.textContent.replace(/^!/, '').trim() })),
        })),
    );

module.exports = {
    name: 'warnings',
    checks: ['C3', 'C8 (warning)', '§4.8'],
    async run({ page, h, check }) {
        let id = null;
        let year = null;
        let weeks = [];
        let photoBox = null;
        const assets = {};
        const stub5 = () => page.$('.cal-ws[data-cal-shown-step="5"] [data-cal-stub]').then(Boolean);

        await check('setup: a calendar with the Fotka vlevo layout, no warnings, chip hidden', async () => {
            id = await h.createCalendar(page);
            await h.gotoStep(page, 2);
            await page.click('[data-preset="photoLeft"]');
            await page.waitForSelector('.cal-ws[data-cal-shown-step="3"]', { timeout: 10000 });
            await h.gotoStep(page, 5);
            await h.save(page);
            year = (await h.state(page)).design.year;
            weeks = await h.cal(page, (c) => c.pages().filter((p) => p.kind === 'week').map((p) => ({ index: p.index, key: p.key, title: p.title })));
            photoBox = (await h.boxes(page)).photo;
            assert.ok(photoBox.w > photoBox.h, 'the default photo frame is landscape');
            assert.deepEqual(await h.cal(page, (c) => c.warnings()), []);
            assert.equal(await chipCount(page), 0, 'the chip is hidden while there are no warnings');
            assert.equal(await page.$('[data-page-thumb][data-warn]'), null);
        });
        if (!id) return;

        // week pages used below
        const P = () => ({ portrait: weeks[2], small: weeks[3], zoom: weeks[4] });

        await check('C3: a portrait photo in the landscape frame warns — page data, thumbnail, chip', async () => {
            // big enough for print (≥ 200 dpi), so the only problem is the orientation
            assets.portrait = await uploadPhoto(page, 2400, 3200, 'na-vysku.jpg');
            const target = P().portrait;
            await h.seed(page, { content: [{ c: 'pagePhotos', id: target.key, v: { asset: assets.portrait.id, zoom: 1, px: 0.5, py: 0.5 }, label: target.title }] });
            const list = await warningsOf(page, h, target.index);
            assert.deepEqual(list.map((w) => [w.kind, w.message]), [['portrait', 'Fotka je na výšku — do rámečku na šířku se ořízne']]);
            assert.deepEqual(list[0].target, { el: 'photo' });
            assert.equal(await thumbWarn(page, target.index), 1, 'badge on the thumbnail');
            assert.equal(await page.$eval(`[data-page-thumb="${target.index}"] .cal-wb`, (el) => el.hidden), false);
            assert.equal(await page.$eval(`[data-page-thumb="${target.index}"] .cal-wb`, (el) => el.getAttribute('aria-label')), '1 upozornění');
            assert.equal(await chipCount(page), 1);
            assert.equal(await page.$eval('[data-cal-action="warnings"]', (el) => el.textContent.trim()), '1 upozornění');
        });

        await check('C3: a small photo warns with the real print resolution (cover scale of the frame in mm)', async () => {
            assets.small = await uploadPhoto(page, 600, 400, 'mala.jpg');
            const target = P().small;
            await h.seed(page, { content: [{ c: 'pagePhotos', id: target.key, v: { asset: assets.small.id, zoom: 1, px: 0.5, py: 0.5 }, label: target.title }] });
            photoBox = (await h.boxes(page)).photo;
            const expected = Math.round(25.4 / Math.max(photoBox.w / 600, photoBox.h / 400));
            assert.ok(expected < 200, `fixture must be below 200 dpi, is ${expected}`);
            const list = await warningsOf(page, h, target.index);
            assert.deepEqual(list.map((w) => [w.kind, w.message]), [['dpi', `Nízká kvalita fotky — ${expected} dpi (pro tisk aspoň 300 dpi)`]]);
            assert.equal(await thumbWarn(page, target.index), 1);
            assert.equal(await chipCount(page), 2);
        });

        await check('zooming a photo lowers its resolution: fine at 100 %, warned at 200 %', async () => {
            const target = P().zoom;
            // ~250 dpi at 100 % in the default frame
            const width = Math.ceil((photoBox.w / 25.4) * 250);
            const height = Math.ceil((photoBox.h / 25.4) * 250);
            assets.zoom = await uploadPhoto(page, width, height, 'zoom.jpg');
            await h.seed(page, { content: [{ c: 'pagePhotos', id: target.key, v: { asset: assets.zoom.id, zoom: 1, px: 0.5, py: 0.5 }, label: target.title }] });
            assert.deepEqual(await warningsOf(page, h, target.index), [], 'no warning at 100 %');
            await h.seed(page, { content: [{ c: 'pagePhotos', id: target.key, v: { asset: assets.zoom.id, zoom: 2, px: 0.5, py: 0.5 }, label: target.title }] });
            const list = await warningsOf(page, h, target.index);
            assert.equal(list.length, 1);
            assert.equal(list[0].kind, 'dpi');
            const dpi = Number(/— (\d+) dpi/.exec(list[0].message)[1]);
            assert.ok(dpi >= 120 && dpi <= 130, `half of ~250 dpi, got ${dpi}`);
            await h.seed(page, { content: [{ c: 'pagePhotos', id: target.key, v: { asset: assets.zoom.id, zoom: 1, px: 0.5, py: 0.5 }, label: target.title }] });
            assert.deepEqual(await warningsOf(page, h, target.index), []);
        });

        await check('more icons than cells and an event text taller than its box warn per day (C8)', async () => {
            const s = await h.state(page);
            const kinds = s.content.wasteKinds;
            const ops = Object.keys(kinds).map((key) => ({ c: 'wasteKinds', id: key, v: { ...kinds[key], on: true, weekdays: [0], interval: 'w' } }));
            ops.push({ c: 'wasteKinds', id: 'e2eSklo0001', v: { builtin: 'sklo', name: 'Sklo', on: true, place: '', mode: 'rule', weekdays: [0], interval: 'w', from: null, to: null, dates: [], pos: 9 } });
            // a Wednesday in March: four long events
            const march = weeks.find((w) => w.key === 'w10');
            const day = await h.cal(page, (c, i) => c.geometry(i).frames[0].rows[2].date, march.index);
            for (let i = 0; i < 4; i += 1) {
                ops.push({ c: 'events', id: `e2eEvent000${i}`, v: { date: day, name: `Slavnostní otevření zrekonstruované budovy obecního úřadu a knihovny ${i + 1}`, club: null, icon: null }, label: 'e2e' });
            }
            await h.seed(page, { design: { features: { ...s.design.features, waste: true, events: true } }, content: ops });

            const all = await h.cal(page, (c) => c.warnings());
            const icons = all.filter((w) => w.kind === 'icons');
            assert.ok(icons.length >= 50, `every Monday of the year has too many icons, got ${icons.length}`);
            for (const w of icons.slice(0, 5)) {
                const m = /^(\d+)\. (\d+)\. — (\d+) ikony, vejdou se (\d+)$/.exec(w.message);
                assert.ok(m, w.message);
                assert.ok(Number(m[3]) > Number(m[4]), w.message);
                assert.match(w.target.date, /^\d{4}-\d\d-\d\d$/);
                assert.equal(new Date(`${w.target.date}T00:00:00Z`).getUTCDay(), 1, 'a Monday');
            }
            const events = all.filter((w) => w.kind === 'event');
            assert.equal(events.length, 1, JSON.stringify(events));
            assert.equal(events[0].pageIndex, march.index);
            assert.equal(events[0].target.date, day);
            const [, m, d] = day.split('-').map(Number);
            assert.equal(events[0].message, `${d}. ${m}. — 4 akce se nevejde do pole pro akce`);
            // the chip counts every warning of the calendar
            assert.equal(await chipCount(page), all.length);
            assert.equal(await thumbWarn(page, march.index), all.filter((w) => w.pageIndex === march.index).length);
        });

        await check('C3: the marks on the step-5 page name the problems of the photo and of the day', async () => {
            if (await stub5()) {
                console.log('    (skipped: step 5 is still the P7a stub — no marks on the page yet)');
                return;
            }
            await showPage(page, P().portrait.index);
            await page.waitForSelector('[data-photo-warning]', { timeout: 5000 });
            const tip = await page.$eval('[data-photo-warning]', (el) => el.getAttribute('title') || el.getAttribute('aria-label') || '');
            assert.match(tip, /Fotka je na výšku/);
            const march = weeks.find((w) => w.key === 'w10');
            await showPage(page, march.index);
            const dayMarks = await page.$$eval('[data-day-warning]', (nodes) => nodes.map((n) => ({ date: n.dataset.dayWarning, tip: n.getAttribute('title') || n.getAttribute('aria-label') || '' })));
            assert.ok(dayMarks.some((mark) => /se nevejde do pole pro akce/.test(mark.tip)), JSON.stringify(dayMarks));
            assert.ok(dayMarks.some((mark) => /ikony, vejdou se/.test(mark.tip)), JSON.stringify(dayMarks));
        });

        await check('C3: Upozornění lists every warning grouped by page, in page order', async () => {
            const all = await h.cal(page, (c) => c.warnings());
            await openList(page);
            assert.equal(await page.$eval('[data-cal-dialog="warnings"] .modal-title', (el) => el.textContent), `Upozornění (${all.length})`);
            const groups = await listRows(page);
            const indexes = [...new Set(all.map((w) => w.pageIndex))].sort((a, b) => a - b);
            assert.deepEqual(groups.map((g) => g.page), indexes);
            const portrait = groups.find((g) => g.page === P().portrait.index);
            assert.equal(portrait.title, P().portrait.title);
            assert.ok(portrait.rows.some((r) => r.kind === 'portrait' && r.text === 'Fotka je na výšku — do rámečku na šířku se ořízne'));
            const small = groups.find((g) => g.page === P().small.index);
            assert.ok(small.rows.some((r) => r.kind === 'dpi' && /^Nízká kvalita fotky — \d+ dpi \(pro tisk aspoň 300 dpi\)$/.test(r.text)));
            assert.equal(groups.reduce((sum, g) => sum + g.rows.length, 0), all.length);
            await h.shot(page, 'warnings-list');
            await closeList(page);
        });

        await check('a row of the list jumps to its page and closes the dialog', async () => {
            await showPage(page, 0);
            await openList(page);
            const target = P().small.index;
            await page.click(`[data-cal-dialog="warnings"] [data-warning-page="${target}"]`);
            await page.waitForFunction(() => !document.querySelector('[data-cal-dialog="warnings"]'), { timeout: 5000 });
            assert.equal(await shownPage(page), target);
        });

        await check('Esc closes the list without moving, and the dialog is keyboard reachable', async () => {
            const before = await shownPage(page);
            await openList(page);
            await page.focus('[data-cal-dialog="warnings"] [data-warning-page]');
            await page.keyboard.press('Escape');
            await page.waitForFunction(() => !document.querySelector('[data-cal-dialog="warnings"]'), { timeout: 5000 });
            assert.equal(await shownPage(page), before);
        });

        await check('step 4 shows the same chip and badges', async () => {
            await h.gotoStep(page, 4);
            const all = await h.cal(page, (c) => c.warnings());
            assert.equal(await chipCount(page), all.length);
            const target = P().portrait.index;
            if (await page.$(`[data-page-thumb="${target}"]`)) assert.equal(await thumbWarn(page, target), all.filter((w) => w.pageIndex === target).length);
            if (await page.$('.cal-ws[data-cal-shown-step="4"] [data-cal-stub]')) {
                console.log('    (step 4 is still the P6a stub: the jump of the chip and the page panel list are not asserted)');
            } else {
                // the chip walks through the pages that have warnings, wrapping
                await page.$eval('[data-cal-action="warnings"]', (el) => el.click());
                await sleep(300);
                const first = await page.$eval('[data-page-thumb][aria-current="true"]', (el) => Number(el.dataset.pageThumb)).catch(() => null);
                assert.ok(first !== null && all.some((w) => w.pageIndex === first), `the chip selected a page with a warning (${first})`);
            }
            await h.gotoStep(page, 5);
        });

        await check('removing the photo removes its warnings everywhere', async () => {
            const target = P().portrait;
            await h.seed(page, { content: [{ c: 'pagePhotos', id: target.key, v: null, label: target.title }] });
            assert.deepEqual((await warningsOf(page, h, target.index)).filter((w) => w.target.el === 'photo'), []);
            const all = await h.cal(page, (c) => c.warnings());
            assert.equal(await chipCount(page), all.length);
            assert.equal(await thumbWarn(page, target.index), all.filter((w) => w.pageIndex === target.index).length);
        });

        await check('warnings never block: saving and Dokončit work with warnings present', async () => {
            const all = await h.cal(page, (c) => c.warnings());
            assert.ok(all.length > 0);
            if ((await page.$eval('.cal-ws', (el) => el.dataset.calShownStep)) !== '5') await h.gotoStep(page, 5);
            assert.equal(await page.$eval('[data-cal-action="save"]', (el) => el.disabled), false);
            assert.equal(await page.$eval('[data-cal-action="next-step"]', (el) => el.disabled), false);
            await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2' }), page.click('[data-cal-action="next-step"]')]);
            await page.waitForSelector(`.cal-card[data-calendar-id="${id}"]`, { timeout: 10000 });
            await h.waitToast(page, /Kalendář poskládán/);
            assert.equal(await page.$(`.cal-card[data-calendar-id="${id}"] .pill`), null, 'finished: no Rozpracovaný vzhled pill');
            await h.openCalendar(page, id);
            assert.equal((await h.state(page)).design.finished, true);
            assert.equal(await chipCount(page), all.length, 'the warnings are still there after reopening');
        });

        await h.deleteCalendar(page, id);
    },
};
