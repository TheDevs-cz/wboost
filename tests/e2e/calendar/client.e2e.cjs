/*
 * Client view, Historie, Odkaz pro klienta (phase P7b; handoff R1, R2, R3, C10 client half,
 * §8 "Roles, saving and history", §10.8, §10.11).
 *
 * Three browser contexts: the designer (the logged-in e2e user), an anonymous holder of the client
 * link `/k/{token}`, and a second account the project is shared with for reading only. Roles are
 * asserted through the shell, `window.__calendar` and the network — forged requests are sent from
 * the client's own page with the client's own CSRF token, the way an attacker with the link would.
 * Parts that need the step-5 overlay (caption pencil, photo upload button) are skipped while step 5
 * is still the P7a stub; the same change then goes through the client's save endpoint.
 */
const assert = require('assert/strict');
const fs = require('fs');
const os = require('os');
const path = require('path');
const { execFileSync } = require('child_process');

const ROOT = path.resolve(__dirname, '../../..');
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const quote = (text) => `'${String(text).replace(/'/g, "''")}'`;
const compose = (args, input) => execFileSync('docker', ['compose', 'exec', '-T', ...args], { cwd: ROOT, input, encoding: 'utf8', stdio: ['pipe', 'pipe', 'pipe'] });
const psql = (sql) => compose(['postgres', 'psql', '-U', 'postgres', '-d', 'wboost', '-v', 'ON_ERROR_STOP=1', '-At'], sql).trim();

const FORBIDDEN = 'Tuto změnu může udělat jen grafik.';
const CLIENT_TOOLS = ['photos', 'waste', 'events', 'names'];
const DESIGN_CONTROLS = ['[data-cal-step]', '[data-cal-action="next-step"]', '[data-cal-action="prev-step"]', '[data-cal-action="undo"]', '[data-cal-action="redo"]', '[data-cal-action="client-link"]', 'input[data-cal-name]', '[data-layer]', '[data-handle]', '[data-preset]', '[data-feature]', '[data-type]'];

const boot = (page) => page.evaluate(() => JSON.parse(document.querySelector('[data-calendar-workspace-target="bootstrap"]').textContent));
const tools = (page) => page.$$eval('[data-tool]', (nodes) => nodes.map((n) => n.dataset.tool));
const isStub5 = (page) => page.$('.cal-ws[data-cal-shown-step="5"] [data-cal-stub]').then(Boolean);

/** POSTs multipart fields from inside the page (same origin, the page's cookies); never follows a redirect. */
function post(page, url, fields) {
    return page.evaluate(
        async (target, data) => {
            const form = new FormData();
            for (const [key, value] of Object.entries(data)) {
                if (value && typeof value === 'object' && value.file) form.append(key, new File([value.file], value.name, { type: value.type }));
                else form.append(key, value);
            }
            const response = await fetch(target, { method: 'POST', body: form, credentials: 'same-origin', redirect: 'manual', headers: { Accept: 'application/json' } });
            let json = null;
            try {
                json = await response.json();
            } catch (e) {
                json = null;
            }
            return { status: response.status, type: response.type, ok: response.ok, json };
        },
        url,
        fields,
    );
}

/** A forged save from the page: the page's own save URL and CSRF token, any payload. */
async function forgedSave(page, payload, url = null) {
    const b = await boot(page);
    return post(page, url || b.env.urls.save, { _token: b.env.csrf, payload: JSON.stringify(payload) });
}

/** The large page picture of step 5 (not a thumbnail): its viewBox in mm. */
const stageViewBox = (page) =>
    page.evaluate(() => {
        const all = [...document.querySelectorAll('.cal-ws-body svg[viewBox]')].filter((svg) => !svg.closest('[data-page-thumb]'));
        all.sort((a, b) => b.getBoundingClientRect().width - a.getBoundingClientRect().width);
        if (!all.length) return null;
        const [x, y, w, h] = all[0].getAttribute('viewBox').split(/[\s,]+/).map(Number);
        return { x, y, w, h };
    });

async function showPage(page, index) {
    await page.$eval(`[data-page-thumb="${index}"]`, (el) => el.click());
    await page.waitForFunction((i) => document.querySelector(`[data-page-thumb="${i}"]`).getAttribute('aria-current') === 'true', {}, index);
    await sleep(300);
}

async function openDialog(page, action) {
    await page.$eval(`[data-cal-action="${action}"]`, (el) => el.click());
    await page.waitForSelector(`[data-cal-dialog="${action}"].show`, { timeout: 5000 });
    await sleep(350);
}

async function closeDialog(page, action) {
    await page.keyboard.press('Escape');
    await page.waitForFunction((a) => !document.querySelector(`[data-cal-dialog="${a}"]`), { timeout: 5000 }, action);
}

/** Rows of an open Historie dialog, newest first. */
async function historyRows(page) {
    await page.waitForFunction(() => document.querySelector('[data-cal-dialog="history"] .cal-hist-list') && !document.querySelector('[data-cal-dialog="history"] [aria-busy]'), { timeout: 8000 });
    return page.$$eval('[data-cal-dialog="history"] .cal-hrow', (nodes) => nodes.map((n) => ({ who: n.querySelector('.pill').textContent.trim(), attr: n.dataset.who, what: n.querySelector('.cal-hwhat').textContent.trim(), at: n.querySelector('time').getAttribute('datetime') })));
}

/** Writes a JPEG drawn in the page to a temp file (for a real `<input type=file>` upload). */
async function jpegFile(page, width, height, name) {
    const data = await page.evaluate(
        (w, hgt) => {
            const canvas = document.createElement('canvas');
            canvas.width = w;
            canvas.height = hgt;
            const g = canvas.getContext('2d');
            g.fillStyle = '#5b8f6a';
            g.fillRect(0, 0, w, hgt);
            return canvas.toDataURL('image/jpeg', 0.8).split(',')[1];
        },
        width,
        height,
    );
    const file = path.join(fs.mkdtempSync(path.join(os.tmpdir(), 'cal-e2e-')), name);
    fs.writeFileSync(file, Buffer.from(data, 'base64'));
    return file;
}

module.exports = {
    name: 'client',
    checks: ['R1', 'R2', 'R3', 'C10 (client)', '§10.8', '§10.11'],
    async run({ browser, page, h, check }) {
        let id = null;
        let emptyId = null;
        let clientUrl = null;
        let weeks = [];
        let design = null;
        const origin = new URL(h.config.base).origin;
        const anonymous = await browser.createBrowserContext();
        const member = await browser.createBrowserContext();
        const client = await h.newPage(anonymous);
        if (process.env.E2E_DEBUG) client.on('response', (r) => r.status() >= 500 && console.log('    5xx', r.status(), r.request().method(), r.url()));
        const readOnly = await h.newPage(member);
        const roEmail = h.config.email.replace('@', '-ro@');
        const captionText = 'Masopust na návsi, foto obec';

        const openClient = async (target = client, url = clientUrl) => {
            const response = await target.goto(url, { waitUntil: 'networkidle2' });
            return response.status();
        };

        await check('setup: a calendar without a layout; a second account with read-only sharing', async () => {
            id = await h.createCalendar(page);
            clientUrl = (await boot(page)).env.clientUrl;
            assert.match(clientUrl, /\/k\/[a-f0-9]{64}$/);
            if (psql(`SELECT count(*) FROM "user" WHERE email = ${quote(roEmail)}`) === '0') compose(['web', 'bin/console', 'app:user:register', roEmail, h.config.password]);
            psql(`
                INSERT INTO project_share (id, level, shared_at, project_id, user_id)
                SELECT gen_random_uuid(), 'read', now(), ${quote(h.config.projectId)}, u.id FROM "user" u WHERE u.email = ${quote(roEmail)}
                ON CONFLICT (project_id, user_id) DO UPDATE SET level = 'read';
            `);
            await readOnly.goto(`${h.config.base}/login`, { waitUntil: 'networkidle2' });
            await readOnly.type('input[name="_username"]', roEmail);
            await readOnly.type('input[name="_password"]', h.config.password);
            await Promise.all([readOnly.waitForNavigation({ waitUntil: 'networkidle2' }), readOnly.click('button[type="submit"]')]);
            assert.doesNotMatch(readOnly.url(), /\/login/);
        });
        if (!id) return;

        await check('a calendar without a layout: the client sees a friendly notice, not blank pages', async () => {
            assert.equal(await openClient(), 200);
            assert.equal(await client.$('.cal-ws'), null, 'no workspace');
            const text = await client.$eval('[data-cal-not-ready]', (el) => el.textContent.replace(/\s+/g, ' ').trim());
            assert.match(text, /Kalendář se teprve připravuje/);
            assert.match(text, /Grafik ještě nedokončil vzhled kalendáře/);
            assert.equal(await client.$('main svg'), null, 'no page picture');
            await h.shot(client, 'client-not-ready');
            // the read-only member gets the same notice inside the app, with a way back
            await readOnly.goto(`${h.config.base}/calendar/${id}`, { waitUntil: 'networkidle2' });
            assert.equal(await readOnly.$('.cal-ws'), null);
            assert.match(await readOnly.$eval('[data-cal-not-ready]', (el) => el.textContent), /Kalendář se teprve připravuje/);
            assert.match(await readOnly.$eval('[data-cal-not-ready] a', (el) => el.getAttribute('href')), /\/calendars$/);
        });

        await check('designer: layout chosen, calendar saved (waste and events still off)', async () => {
            await h.gotoStep(page, 2);
            await page.click('[data-preset="photoLeft"]');
            await page.waitForSelector('.cal-ws[data-cal-shown-step="3"]', { timeout: 10000 });
            await h.gotoStep(page, 5);
            await h.save(page);
            const s = await h.state(page);
            design = s.design;
            weeks = await h.cal(page, (c) => c.pages().filter((p) => p.kind === 'week').map((p) => ({ index: p.index, key: p.key, title: p.title })));
            assert.ok(design.layout);
        });

        await check('R1: the client link opens step 5 only — no step bar, no Zpět, no forward button', async () => {
            assert.equal(await openClient(), 200);
            await h.waitReady(client);
            const s = await h.state(client);
            assert.equal(s.mode, 'client');
            assert.equal(await client.$eval('.cal-ws', (el) => el.dataset.calShownStep), '5');
            for (const selector of DESIGN_CONTROLS) assert.equal(await client.$(selector), null, `${selector} must not exist for a client`);
            assert.equal(await client.$('[data-cal-action="back-to-list"]'), null, 'the public page has no way into the app');
            assert.equal(await client.$('#sidebar'), null, 'standalone page: no app shell');
            assert.equal(await client.$eval('[data-cal-name]', (el) => el.tagName), 'SPAN', 'the name is text');
            assert.ok(await client.$('[data-cal-action="save"]'), 'Uložit stays');
            assert.ok(await client.$('[data-cal-action="history"]'), 'Historie stays');
            const b = await boot(client);
            assert.equal(b.mode, 'client');
            assert.deepEqual(Object.keys(b.env.urls).sort(), ['asset', 'history', 'save']);
            assert.equal(b.env.clientUrl, undefined);
            assert.equal(b.env.siblings, undefined);
            assert.equal(await client.$eval('meta[name="robots"]', (el) => el.content), 'noindex, nofollow');
        });

        await check('R1: tools of a switched-off feature are hidden; with waste and events on there are exactly four', async () => {
            assert.deepEqual(await tools(client), ['photos', 'names'], 'waste and events are off in step 1');
            await h.seed(page, { design: { features: { ...design.features, waste: true, events: true, shifts: true, qr: true, specialPages: true } } });
            design = (await h.state(page)).design;
            assert.deepEqual(await tools(page), ['photos', 'waste', 'events', 'names', 'moon', 'shifts', 'qr'], 'the designer has all seven');
            await h.reload(client);
            assert.deepEqual(await tools(client), CLIENT_TOOLS, 'Fotky a popisky, Odpady, Akce a spolky, Jména navíc');
            assert.deepEqual(await client.$$eval('[data-tool]', (nodes) => nodes.map((n) => n.textContent.trim())), ['Fotky a popisky', 'Odpady', 'Akce a spolky', 'Jména navíc']);
            await h.shot(client, 'client-step5');
        });

        await check('C10: the designer sees bleed and crop marks, the client the trimmed page', async () => {
            const week = weeks[2];
            await showPage(page, week.index);
            await showPage(client, week.index);
            const d = await stageViewBox(page);
            const c = await stageViewBox(client);
            const { width, height } = design.page;
            assert.ok(d && c, 'both show a page');
            assert.deepEqual(c, { x: 0, y: 0, w: width, h: height }, 'client: exactly the trim box');
            assert.ok(d.w >= width + 2 * design.print.bleedMm && d.x < 0, `designer: bleed and marks around the page (${JSON.stringify(d)})`);
        });

        await check('R2: a client tool cannot be opened for a designer-only tool, and no design control is reachable by keyboard', async () => {
            // every focusable control of the client page: none of them belongs to the design
            const focusable = await client.$$eval('.cal-ws button, .cal-ws a, .cal-ws input, .cal-ws select', (nodes) => nodes.map((n) => n.dataset.calAction || n.dataset.tool || n.dataset.calStep || '').filter(Boolean));
            for (const key of ['moon', 'shifts', 'qr', 'client-link', 'next-step', 'prev-step', 'undo', 'redo', 'link']) assert.ok(!focusable.includes(key), key);
            // Ctrl+Z / Ctrl+Shift+Z do nothing harmful
            await client.keyboard.down('Control');
            await client.keyboard.press('z');
            await client.keyboard.up('Control');
            assert.equal((await h.state(client)).status, 'clean');
        });

        await check('R2: forged design changes from the client page are refused and nothing changes', async () => {
            const before = await h.state(page);
            const layout = before.design.layout;
            const moved = JSON.parse(JSON.stringify(layout));
            moved.boxes.photo.x += 10;
            const forged = [
                ['layout', { design: { layout: moved } }],
                ['layout removed', { design: { layout: null } }],
                ['style only', { design: { layout: { ...moved, styles: { ...moved.styles, month: { ...(moved.styles.month || {}), size: 99 } } } } }],
                ['name', { design: { name: 'Přejmenováno klientem' } }],
                ['step', { design: { step: 3 } }],
                ['type', { design: { type: 'fortnight', preset: 'twoSided' } }],
                ['features', { design: { features: { ...before.design.features, moon: false } } }],
                ['print', { design: { print: { bleed: false, bleedMm: 0, marks: false } } }],
                ['finish', { finish: true }],
                ['design smuggled beside content', { design: { linkElements: false }, content: [{ c: 'names', id: 'forgedName001', v: { date: `${before.design.year}-03-01`, name: 'Bedřich' }, label: '1. 3.' }] }],
                ['special pages', { content: [{ c: 'specialPages', id: 'intro', v: null }] }],
                ['moon edits', { content: [{ c: 'moonEdits', id: 'forgedMoon001', v: { op: 'add', date: `${before.design.year}-03-01`, phase: 0 } }] }],
                ['shift operations', { content: [{ c: 'shiftOps', id: 'forgedShift01', v: null }] }],
                ['page QR', { content: [{ c: 'pageQr', id: weeks[2].key, v: null }] }],
                ['page images', { content: [{ c: 'pageImages', id: weeks[2].key, v: { head: null, bg: null } }] }],
            ];
            for (const [name, payload] of forged) {
                const answer = await forgedSave(client, payload);
                assert.equal(answer.status, 403, `${name}: ${JSON.stringify(answer)}`);
                assert.deepEqual(answer.json, { error: FORBIDDEN }, name);
            }
            await h.reload(page);
            const after = await h.state(page);
            assert.equal(after.revision, before.revision, 'no forged request bumped the revision');
            assert.deepEqual(after.design, before.design);
            assert.deepEqual(after.content, before.content);
        });

        await check('R2: the client link gives no access to the designer endpoints or other upload kinds', async () => {
            const b = await boot(client);
            for (const endpoint of ['save', 'client-link', 'layout-import', 'document-text', 'delete', 'asset']) {
                const answer = await post(client, `/calendar/${id}/${endpoint}`, { _token: b.env.csrf, action: 'regenerate', payload: JSON.stringify({ design: { name: 'x' } }), kind: 'photo' });
                assert.ok(!answer.ok, `${endpoint}: ${JSON.stringify(answer)}`);
            }
            const workspace = await client.evaluate(async (url) => (await fetch(url, { redirect: 'manual' })).type, `/calendar/${id}`);
            assert.equal(workspace, 'opaqueredirect', 'the workspace sends an anonymous visitor to the login');
            const jpeg = { file: 'x', name: 'logo.jpg', type: 'image/jpeg' };
            for (const kind of ['logo', 'icon', 'qr', 'image', 'page']) {
                const answer = await post(client, b.env.urls.asset, { _token: b.env.csrf, kind, file: jpeg });
                assert.equal(answer.status, 403, kind);
                assert.deepEqual(answer.json, { error: FORBIDDEN }, kind);
            }
            const svg = await post(client, b.env.urls.asset, { _token: b.env.csrf, kind: 'photo', file: { file: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="10" height="10"/></svg>', name: 'kresba.svg', type: 'image/svg+xml' } });
            assert.equal(svg.status, 422, JSON.stringify(svg));
            const wrongToken = await post(client, b.env.urls.save, { _token: 'forged', payload: '{}' });
            assert.equal(wrongToken.status, 403);
            assert.equal((await h.state(page)).design.name, design.name);
            assert.equal((await boot(page)).env.clientUrl, clientUrl, 'the link was not regenerated');
        });

        await check('R3: the client changes a caption and saves — status, toast, stored for the designer', async () => {
            const week = weeks[2];
            await showPage(client, week.index);
            let viaUi = false;
            if (!(await isStub5(client)) && (await client.$('[data-caption-edit]'))) {
                await client.$eval('[data-caption-edit]', (el) => el.click());
                await client.waitForSelector('[data-cal-popover="caption"] [data-field="caption"]', { timeout: 5000 });
                await client.type('[data-cal-popover="caption"] [data-field="caption"]', captionText);
                await client.keyboard.press('Enter');
                await client.waitForFunction(() => !document.querySelector('[data-cal-popover="caption"]'), { timeout: 5000 });
                await h.waitStatus(client, 'dirty');
                assert.equal(await client.$eval('[data-cal-status]', (el) => el.textContent.trim()), 'Neuložené změny');
                await client.click('[data-cal-action="save"]');
                await h.waitToast(client, /Změny uloženy/);
                await h.waitStatus(client, 'clean');
                viaUi = true;
            } else {
                console.log('    (step 5 overlay is not there yet: the caption goes through the client save endpoint)');
                const answer = await forgedSave(client, { content: [{ c: 'pageCaptions', id: week.key, v: { text: captionText }, label: week.title }] });
                assert.equal(answer.status, 200, JSON.stringify(answer));
                await h.reload(client);
            }
            assert.equal((await h.state(client)).content.pageCaptions[week.key].text, captionText);
            await h.reload(page);
            assert.equal((await h.state(page)).content.pageCaptions[week.key].text, captionText, 'the designer sees the client\'s caption');
            if (viaUi) await h.shot(client, 'client-after-caption');
        });

        await check('R3: Historie of the designer lists the change as Klient', async () => {
            await openDialog(page, 'history');
            const rows = await historyRows(page);
            assert.deepEqual({ who: rows[0].who, what: rows[0].what }, { who: 'Klient', what: `Upravil popisek — ${weeks[2].title}` });
            assert.equal(rows[0].attr, 'Klient');
            assert.ok(rows.some((r) => r.who === 'Grafik'), 'the designer\'s own entries are there too');
            assert.ok(Number.isFinite(new Date(rows[0].at).getTime()));
            assert.equal(await page.$eval('[data-cal-dialog="history"] .modal-title', (el) => el.textContent), `Historie — ${design.name}`);
            // filter: only the client's changes
            await page.click('[data-cal-dialog="history"] [data-history-filter] [data-value="Klient"]');
            const onlyClient = await historyRows(page);
            assert.ok(onlyClient.length >= 1 && onlyClient.every((r) => r.who === 'Klient'));
            await page.click('[data-cal-dialog="history"] [data-history-filter] [data-value="all"]');
            assert.equal((await historyRows(page)).length, rows.length);
            await h.shot(page, 'history-designer');
            await closeDialog(page, 'history');
        });

        await check('the client uploads a photo, comments a day and adds an event — all recorded as Klient', async () => {
            const week = weeks[3];
            await showPage(client, week.index);
            const day = await h.cal(client, (c, i) => c.geometry(i).frames[0].rows[1].date, week.index);
            const [, m, d] = day.split('-').map(Number);
            if (!(await isStub5(client)) && (await client.$('[data-photo-upload]'))) {
                const input = await client.$('[data-photo-upload]');
                await input.uploadFile(await jpegFile(client, 640, 420, 'obec.jpg'));
                await client.waitForFunction((key) => Boolean(window.__calendar.state().content.pagePhotos[key]), { timeout: 15000 }, week.key);
            } else {
                console.log('    (no photo button yet: the photo goes through the client upload + save endpoints)');
                const b = await boot(client);
                const uploaded = await client.evaluate(async (url, token) => {
                    const canvas = document.createElement('canvas');
                    canvas.width = 640;
                    canvas.height = 420;
                    canvas.getContext('2d').fillRect(0, 0, 640, 420);
                    const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.8));
                    const form = new FormData();
                    form.append('_token', token);
                    form.append('kind', 'photo');
                    form.append('file', new File([blob], 'obec.jpg', { type: 'image/jpeg' }));
                    return (await fetch(url, { method: 'POST', body: form })).json();
                }, b.env.urls.asset, b.env.csrf);
                const answer = await forgedSave(client, { content: [{ c: 'pagePhotos', id: week.key, v: { asset: uploaded.id, zoom: 1, px: 0.5, py: 0.5 }, label: week.title }] });
                assert.equal(answer.status, 200, JSON.stringify(answer));
            }
            if ((await h.state(client)).status === 'dirty') await h.save(client);
            // a comment and an event through the client's save endpoint (the popovers are P7a's)
            const answer = await forgedSave(client, {
                content: [
                    { c: 'comments', id: 'e2eComment0001', v: { date: day, text: 'Prosíme jinou fotku', author: 'designer', at: '1999-01-01T00:00:00+00:00' }, label: `${d}. ${m}.` },
                    { c: 'events', id: 'e2eEvent00001', v: { date: day, name: 'Pouť', club: null, icon: null }, label: `${d}. ${m}.` },
                ],
            });
            assert.equal(answer.status, 200, JSON.stringify(answer));
            await h.reload(client);
            await h.reload(page);
            const s = await h.state(page);
            const photo = s.content.pagePhotos[week.key];
            assert.ok(photo && photo.asset, 'the photo is on the page for the designer');
            assert.equal(s.content.comments.e2eComment0001.author, 'client', 'the server stamps the author');
            assert.notEqual(s.content.comments.e2eComment0001.at, '1999-01-01T00:00:00+00:00');
            assert.equal(s.content.events.e2eEvent00001.name, 'Pouť');
            // the small photo warns on the client's page too (C3 through a real upload)
            const warnings = await h.cal(client, (c, i) => c.warnings().filter((w) => w.pageIndex === i && w.kind === 'dpi'), week.index);
            assert.equal(warnings.length, 1, 'a 640 px photo is below 200 dpi');
            assert.ok(Number(await client.$eval(`[data-page-thumb="${week.index}"]`, (el) => el.dataset.warn)) >= 1);

            await openDialog(page, 'history');
            const rows = await historyRows(page);
            const top = rows.slice(0, 3).map((r) => `${r.who}: ${r.what}`).sort();
            assert.deepEqual(top, [`Klient: Komentář k ${d}. ${m}.: Prosíme jinou fotku`, `Klient: Nahrál fotku — ${week.title}`, `Klient: Přidal akci „Pouť“ — ${d}. ${m}.`].sort());
            await closeDialog(page, 'history');
        });

        await check('the client has Historie and Upozornění too', async () => {
            await openDialog(client, 'history');
            const rows = await historyRows(client);
            assert.ok(rows.length >= 4);
            assert.equal(rows[0].who, 'Klient');
            await h.shot(client, 'client-history');
            await closeDialog(client, 'history');
            await openDialog(client, 'warnings');
            assert.match(await client.$eval('[data-cal-dialog="warnings"] .modal-title', (el) => el.textContent), /^Upozornění \(\d+\)$/);
            assert.ok(await client.$('[data-cal-dialog="warnings"] [data-warning-kind="dpi"]'));
            await closeDialog(client, 'warnings');
        });

        await check('§10.8: designer and client change different things at the same time — both stay', async () => {
            const year = design.year;
            // both pages hold the same (now stale for each other) document
            const a = await forgedSave(client, { content: [{ c: 'names', id: 'e2eName000001', v: { date: `${year}-05-02`, name: 'Zikmund' }, label: '2. 5.' }] });
            const b = await forgedSave(page, { content: [{ c: 'names', id: 'e2eName000002', v: { date: `${year}-05-03`, name: 'Alexej' }, label: '3. 5.' }], design: { linkElements: false } });
            assert.equal(a.status, 200);
            assert.equal(b.status, 200);
            await h.reload(page);
            const s = await h.state(page);
            assert.equal(s.content.names.e2eName000001.name, 'Zikmund');
            assert.equal(s.content.names.e2eName000002.name, 'Alexej');
            assert.equal(s.design.linkElements, false);
            assert.equal(s.content.pageCaptions[weeks[2].key].text, captionText, 'earlier client content survived the designer\'s save');
        });

        await check('the list card shows who changed the calendar last and opens Historie', async () => {
            await h.gotoList(page);
            const chip = `.cal-card[data-calendar-id="${id}"] [data-cal-action="history"]`;
            assert.match(await page.$eval(chip, (el) => el.textContent.replace(/\s+/g, ' ').trim()), /^(Grafik|Klient) · /);
            await page.click(chip);
            await page.waitForSelector('[data-cal-dialog="history"].show', { timeout: 5000 });
            const rows = await historyRows(page);
            assert.ok(rows.some((r) => r.who === 'Klient' && /^Upravil popisek — /.test(r.what)));
            assert.ok(rows.some((r) => r.who === 'Grafik' && r.what === 'Vytvořil kalendář'), JSON.stringify(rows.slice(-2)));
            assert.equal(await page.$eval('[data-cal-dialog="history"] .modal-title', (el) => el.textContent), `Historie — ${design.name}`);
            await sleep(350);
            await h.shot(page, 'history-list');
            await closeDialog(page, 'history');
            await h.openCalendar(page, id);
        });

        await check('read-only member: client mode inside the app, four tools, changes recorded as Klient', async () => {
            await h.openCalendar(readOnly, id);
            const s = await h.state(readOnly);
            assert.equal(s.mode, 'client');
            assert.equal(await readOnly.$eval('.cal-ws', (el) => el.dataset.calShownStep), '5');
            for (const selector of DESIGN_CONTROLS) assert.equal(await readOnly.$(selector), null, `${selector} must not exist for a read-only member`);
            assert.deepEqual(await tools(readOnly), CLIENT_TOOLS);
            assert.ok(await readOnly.$('#sidebar'), 'inside the app shell');
            const b = await boot(readOnly);
            assert.equal(b.env.clientUrl, undefined, 'the token is not handed to an account');
            assert.ok(!JSON.stringify(b).includes(clientUrl.split('/k/')[1]));
            assert.deepEqual(await stageViewBox(readOnly), { x: 0, y: 0, w: design.page.width, h: design.page.height }, 'trimmed page');
            await h.shot(readOnly, 'readonly-member');

            const ok = await forgedSave(readOnly, { content: [{ c: 'pageCaptions', id: weeks[5].key, v: { text: 'Od člena jen pro čtení' }, label: weeks[5].title }] });
            assert.equal(ok.status, 200, JSON.stringify(ok));
            await openDialog(readOnly, 'history');
            const rows = await historyRows(readOnly);
            assert.deepEqual({ who: rows[0].who, what: rows[0].what }, { who: 'Klient', what: `Upravil popisek — ${weeks[5].title}` });
            await closeDialog(readOnly, 'history');
        });

        await check('read-only member: forged design changes and designer endpoints are refused', async () => {
            const before = await h.state(page);
            for (const payload of [{ design: { name: 'Nope' } }, { design: { layout: null } }, { design: { step: 2 } }, { finish: true }, { content: [{ c: 'specialPages', id: 'intro', v: null }] }]) {
                const answer = await forgedSave(readOnly, payload);
                assert.equal(answer.status, 403, JSON.stringify(payload));
                assert.deepEqual(answer.json, { error: FORBIDDEN });
            }
            const b = await boot(readOnly);
            for (const endpoint of ['client-link', 'layout-import', 'document-text', 'delete']) {
                const answer = await post(readOnly, `/calendar/${id}/${endpoint}`, { _token: b.env.csrf, action: 'regenerate' });
                assert.equal(answer.status, 403, endpoint);
            }
            const logo = await post(readOnly, b.env.urls.asset, { _token: b.env.csrf, kind: 'logo', file: { file: 'x', name: 'logo.jpg', type: 'image/jpeg' } });
            assert.equal(logo.status, 403);
            await h.reload(page);
            const after = await h.state(page);
            assert.deepEqual(after.design, before.design);
            assert.equal((await boot(page)).env.clientUrl, clientUrl);
            // the list of a read-only member offers neither create nor delete
            await readOnly.goto(h.listUrl(), { waitUntil: 'networkidle2' });
            assert.equal(await readOnly.$('[data-cal-action="create"]'), null);
            assert.equal(await readOnly.$('.cal-del'), null);
            assert.ok(await readOnly.$(`.cal-card[data-calendar-id="${id}"]`));
        });

        await check('Odkaz pro klienta: shows the link, Kopírovat copies it and is recorded', async () => {
            await browser.defaultBrowserContext().overridePermissions(origin, ['clipboard-read', 'clipboard-write']);
            await page.bringToFront();
            await openDialog(page, 'client-link');
            assert.equal(await page.$('[data-cal-dialog="client-link"] [data-cal-stub]'), null, 'no stub');
            assert.equal(await page.$eval('[data-cal-link-url]', (el) => el.value), clientUrl);
            assert.equal(await page.$eval('[data-cal-link-url]', (el) => el.readOnly), true);
            assert.equal(await page.$eval('[data-cal-action="link-open"]', (el) => el.href), clientUrl);
            assert.match(await page.$eval('[data-cal-dialog="client-link"] .cal-clink > p', (el) => el.textContent), /^Klient po otevření uvidí kalendář v náhledu/);
            await page.click('[data-cal-action="link-copy"]');
            await h.waitToast(page, /Odkaz zkopírován — pošlete ho klientovi/);
            assert.equal(await page.evaluate(() => navigator.clipboard.readText()), clientUrl);
            await h.shot(page, 'client-link');
            await page.waitForNetworkIdle({ idleTime: 200, timeout: 8000 });
            await closeDialog(page, 'client-link');
            await openDialog(page, 'history');
            const rows = await historyRows(page);
            assert.deepEqual({ who: rows[0].who, what: rows[0].what }, { who: 'Grafik', what: 'Zkopíroval odkaz pro klienta' });
            await closeDialog(page, 'history');
        });

        await check('§10.11: a new link is generated only after a confirmation; the old one stops working', async () => {
            await openDialog(page, 'client-link');
            assert.equal(await page.$eval('[data-cal-link-confirm]', (el) => el.hidden), true);
            await page.click('[data-cal-action="link-regenerate"]');
            assert.equal(await page.$eval('[data-cal-link-confirm]', (el) => el.hidden), false);
            assert.match(await page.$eval('[data-cal-link-confirm]', (el) => el.textContent), /Starý odkaz přestane fungovat/);
            await h.shot(page, 'client-link-confirm');
            await page.click('[data-cal-link-confirm] [data-cal-choice="cancel"]');
            assert.equal(await page.$eval('[data-cal-link-confirm]', (el) => el.hidden), true);
            assert.equal(await page.$eval('[data-cal-link-url]', (el) => el.value), clientUrl, 'Zrušit keeps the link');
            assert.equal(await openClient(), 200, 'and it still works');

            await page.bringToFront();
            await page.click('[data-cal-action="link-regenerate"]');
            await page.click('[data-cal-link-confirm] [data-cal-choice="confirm"]');
            await h.waitToast(page, /Nový odkaz je připravený/);
            const fresh = await page.$eval('[data-cal-link-url]', (el) => el.value);
            assert.match(fresh, /\/k\/[a-f0-9]{64}$/);
            assert.notEqual(fresh, clientUrl);
            assert.equal(await page.$eval('[data-cal-action="link-open"]', (el) => el.href), fresh);
            assert.equal(await page.$eval('[data-cal-link-confirm]', (el) => el.hidden), true);
            await closeDialog(page, 'client-link');

            // the client who still has the old page open: the old link is dead for reading and writing
            const stale = await forgedSave(client, { content: [{ c: 'names', id: 'e2eName000003', v: { date: `${design.year}-05-04`, name: 'Květoslav' }, label: '4. 5.' }] });
            assert.equal(stale.status, 404, 'saving through the old link');
            assert.equal(await openClient(client, clientUrl), 404, 'opening the old link');
            assert.equal(await openClient(client, fresh), 200, 'the new link works');
            await h.waitReady(client);
            assert.equal((await h.state(client)).mode, 'client');
            assert.equal((await h.state(client)).content.names.e2eName000003, undefined);

            // reopening the dialog (and the page) shows the new link
            await openDialog(page, 'client-link');
            assert.equal(await page.$eval('[data-cal-link-url]', (el) => el.value), fresh);
            await closeDialog(page, 'client-link');
            await h.reload(page);
            assert.equal((await boot(page)).env.clientUrl, fresh);
            await openDialog(page, 'history');
            const rows = await historyRows(page);
            assert.deepEqual({ who: rows[0].who, what: rows[0].what }, { who: 'Grafik', what: 'Vygeneroval nový odkaz pro klienta' });
            await closeDialog(page, 'history');
            clientUrl = fresh;
        });

        await check('client page on a phone: step 5, tools and Uložit reachable, no horizontal overflow', async () => {
            await client.setViewport({ width: 390, height: 844 });
            await openClient();
            await h.waitReady(client);
            assert.deepEqual(await tools(client), CLIENT_TOOLS);
            assert.ok(await client.$('[data-cal-action="save"]'));
            const overflow = await client.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
            assert.ok(overflow <= 1, `page overflows by ${overflow}px`);
            await h.shot(client, 'client-390');
            await client.setViewport({ width: 1440, height: 900 });
        });

        for (const extra of [client, readOnly]) {
            if (extra.problems.length) await check(`console of the ${extra === client ? 'client' : 'read-only'} page is free of errors`, async () => assert.deepEqual(extra.problems.filter((p) => !/404|403|422/.test(p)), []));
        }
        await anonymous.close().catch(() => null);
        await member.close().catch(() => null);
        if (emptyId) await h.deleteCalendar(page, emptyId);
        await h.deleteCalendar(page, id);
        psql(`DELETE FROM project_share WHERE project_id = ${quote(h.config.projectId)} AND user_id IN (SELECT id FROM "user" WHERE email = ${quote(roEmail)})`);
    },
};
