/*
 * AI scan in the calendar wizard — „Naskenovat design“ (docs/ai-scan/ux.md §6,
 * docs/architecture/calendars.md § "Calendar scan — UI"):
 *
 *   card → panel → upload → consent → start → waiting (the wizard stays usable, the dot, the toast)
 *   → review (live preview, recognised rows, switches) → pictures → apply → step 3 → „Vrátit původní“,
 *   the preset card over a scanned layout, restore with the confirmation after later edits,
 *   the list pill, discard — and around it: the card's other states (locked → „Požádat o přístup“ →
 *   request sent, monthly limit, service waiting), `Ukázat vrstvy`, the rating, numbered places, a
 *   picture dropped on a place, several dropped at once, the gallery as a source, „Naskenovat znovu“
 *   with the same picture, an applied scan opened from a link, „Vrátit původní rozvržení“ from the
 *   history row, the history of a project that has only the Kalendáře module.
 *
 * No model is called: tests/e2e/ai-scan/lib/fake-scanner.cjs plays the scanner over the real
 * scanner API and takes ONLY the scans of the e2e calendar project. It needs the AI scan switched
 * on (`bin/console app:ai-scan:status --mode=on`).
 *
 * WHO: the plain e2e user — an editor (the owner) of a project that has AI sken switched on, i.e.
 * the customer of decision 16 (docs/ai-scan/decisions.md), not an administrator. The fixture
 * switches the entitlement of the e2e project on and, when the local runtime still says "admins
 * only", switches that off (`app:ai-scan:status --admin-only=0` — what production runs). The
 * service-waiting card is shown from a rewritten `entry` answer: the script does not pause the
 * service other work on this machine uses.
 *
 * NOTHING THE PAGES ASK FOR IS MISSING: every answer 404 of the run is collected and fails the last
 * check — the panel shows the copy of the upload the status document names (`picture`) and the
 * render it names (`previewCandidate`), so a 404 on a picture is a bug (ai-scan.md).
 *
 * Beside a real scanner on the shared stack (it would take this script's scans): an app container
 * of its own — `BASE`, `E2E_WEB_CONTAINER`, `E2E_DB`, `SCANNER_TOKEN`, as in ../ai-scan/README.md.
 */
const fs = require('fs');
const os = require('os');
const path = require('path');
const fake = require('../ai-scan/lib/fake-scanner.cjs');

const GALLERY_FILE = 'e2e-scan-header.png';
const quote = (text) => `'${String(text).replace(/'/g, "''")}'`;

// the stack's services — or an app container and a database of this run's own (lib/harness.cjs)
const { compose, psql } = require('./lib/harness.cjs');

/** The entitlement, and nothing left of earlier runs: the project's scans, their usage, requests, the consent. */
function fixture(h) {
    const project = quote(h.config.projectId);
    psql(`
        UPDATE "user" SET ai_scan_consent_version = NULL, ai_scan_consent_at = NULL WHERE email = ${quote(h.config.email)};
        UPDATE project SET ai_scan_enabled = true, ai_scan_monthly_limit = NULL, modules = '["manuals", "templates", "calendars"]' WHERE id = ${project};
        DELETE FROM ai_access_request WHERE project_id = ${project};
        DELETE FROM ai_scan WHERE project_id = ${project};
        DELETE FROM ai_usage_event WHERE project_id = ${project};
        DELETE FROM ai_credit_ledger WHERE project_id = ${project};
        DELETE FROM file_upload WHERE project_id = ${project} AND original_name = ${quote(GALLERY_FILE)};
    `);
    if (psql('SELECT admin_only FROM ai_scan_runtime LIMIT 1') === 't') compose(['web', 'bin/console', 'app:ai-scan:status', '--admin-only=0']);
}
const project = (h, set) => psql(`UPDATE project SET ${set} WHERE id = ${quote(h.config.projectId)}`);

/** A flat picture that reads as a weekly page: photo right, seven rows left, a dark header. */
async function calendarPicture(browser, file, tint) {
    const page = await browser.newPage();
    await page.setViewport({ width: 1600, height: 748 });
    const rows = Array.from({ length: 7 }, (_, i) => `<div style="position:absolute;left:40px;top:${150 + i * 80}px;width:520px;height:70px;border-bottom:2px solid #c9c9d2;font:700 44px sans-serif;color:#1f3a5f">${12 + i}<span style="font:400 20px sans-serif;margin-left:18px;color:#555">${['Pondělí', 'Úterý', 'Středa', 'Čtvrtek', 'Pátek', 'Sobota', 'Neděle'][i]}</span></div>`).join('');
    await page.setContent(`<body style="margin:0;width:1600px;height:748px;background:#f3f1ea;position:relative;overflow:hidden">
<div style="position:absolute;left:0;top:0;width:1600px;height:130px;background:#1f3a5f"></div>
<div style="position:absolute;left:40px;top:70px;font:800 40px sans-serif;color:#fff">LEDEN 2026</div>
<div style="position:absolute;left:1350px;top:60px;width:180px;height:60px;background:${tint};border-radius:8px"></div>
<div style="position:absolute;left:600px;top:130px;width:1000px;height:618px;background:linear-gradient(135deg,#6b8f71,#27445c)"></div>
<div style="position:absolute;left:0;top:660px;width:480px;height:40px;background:${tint};opacity:.5"></div>${rows}</body>`);
    fs.writeFileSync(file, Buffer.from(await page.screenshot({ type: 'png' })));
    await page.close();
    return file;
}

async function logoPicture(browser, file, { width = 360, height = 120, colour = '#c8102e', label = 'LOGO' } = {}) {
    const page = await browser.newPage();
    await page.setViewport({ width, height });
    await page.setContent(`<body style="margin:0;height:${height}px;background:${colour};color:#fff;font:800 ${Math.round(height / 2)}px sans-serif;display:grid;place-items:center">${label}</body>`);
    fs.writeFileSync(file, Buffer.from(await page.screenshot({ type: 'png' })));
    await page.close();
    return file;
}

module.exports = {
    name: 'AI scan — Naskenovat design (step 2)',

    async run({ browser, page, h, check }) {
        const { assert } = h;
        fixture(h);
        const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'wboost-calscan-e2e-'));
        const picture = (name) => calendarPicture(browser, path.join(tmp, name), `#${Math.floor(Math.random() * 0xffffff).toString(16).padStart(6, '0')}`);
        const logo = await logoPicture(browser, path.join(tmp, 'logo.png'));
        const strip = await logoPicture(browser, path.join(tmp, GALLERY_FILE), { width: 1200, height: 160, colour: '#27445c', label: 'HLAVIČKA' });
        const backdrop = await logoPicture(browser, path.join(tmp, 'pozadi.png'), { width: 800, height: 380, colour: '#e9e4d4', label: '' });

        /** Drops files from "the desktop" on an element: the same events a real drag ends with. */
        const dropFiles = (selector, files) => page.evaluate(async (sel, list) => {
            const transfer = new DataTransfer();
            for (const item of list) transfer.items.add(new File([Uint8Array.from(atob(item.base64), (c) => c.charCodeAt(0))], item.name, { type: 'image/png' }));
            const target = document.querySelector(sel);
            target.dispatchEvent(new DragEvent('dragover', { bubbles: true, cancelable: true, dataTransfer: transfer }));
            const over = target.closest('[data-dropzone]').classList.contains('over');
            target.dispatchEvent(new DragEvent('drop', { bubbles: true, cancelable: true, dataTransfer: transfer }));
            return over;
        }, selector, files.map((file) => ({ name: path.basename(file), base64: fs.readFileSync(file).toString('base64') })));
        /**
         * SHOTS_MATRIX=1: besides the normal screenshot, the scan surface (the open dialog, else the
         * panel or the card) at 390 / 820 / 1440 px in both themes → `<name>--<width>--<theme>.png`,
         * for looking at by eye (in a viewport tall enough to hold the whole page — the wizard's sticky
         * bar would cover the top of a panel that has to scroll). The state is not touched: only the
         * viewport and the theme attribute.
         */
        const shot = async (name) => {
            await h.shot(page, name);
            if (process.env.SHOTS_MATRIX !== '1' || !h.config.shots) return;
            const theme = await page.evaluate(() => document.documentElement.getAttribute('data-theme'));
            for (const width of [390, 820, 1440]) {
                // a viewport taller than the page: nothing scrolls, so the sticky top bar covers nothing
                await page.setViewport({ width, height: 3200 });
                for (const t of ['dark', 'light']) {
                    await page.evaluate((value) => document.documentElement.setAttribute('data-theme', value), t);
                    await new Promise((resolve) => setTimeout(resolve, 350));
                    const target = (await page.$('dialog[open]')) || (await page.$('.modal.show .modal-content')) || (await page.$('[data-cal-scan-panel]')) || (await page.$('[data-cal-scan-card]')) || (await page.$('.as-history')) || null;
                    const file = path.join(h.config.shots, `${name}--${width}--${t}.png`);
                    if (target) await target.screenshot({ path: file }).catch(() => page.screenshot({ path: file }));
                    else await page.screenshot({ path: file, fullPage: true });
                    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
                    if (overflow > 1) console.error(`  [matrix] ${name} ${width}px ${t}: the page is ${overflow}px wider than the screen`);
                }
            }
            await page.evaluate((value) => { if (value) document.documentElement.setAttribute('data-theme', value); else document.documentElement.removeAttribute('data-theme'); }, theme);
            await page.setViewport({ width: 1440, height: 900 });
            await new Promise((resolve) => setTimeout(resolve, 250));
        };
        const cardState = () => page.$eval('[data-cal-scan-card]', (el) => el.dataset.state);
        const waitCard = (wanted) => page.waitForFunction((value) => { const el = document.querySelector('[data-cal-scan-card]'); return el && el.dataset.state === value; }, { timeout: 20000 }, wanted);

        // the fake scanner: only this project's scans, quick claims (a real scanner may run beside us)
        const options = { scenario: 'happy', beatMs: 350, beats: 5, once: false, intakeOnly: false, paused: true, stopped: false, idleMs: 150, accepts: fake.projectFilter([h.config.projectId]) };
        const lines = [];
        const served = fake.serve(options, (line) => lines.push(line)).catch((error) => { lines.push(`ERROR ${error.message}`); });

        const panelView = () => page.$eval('[data-cal-scan-panel]', (el) => el.dataset.view).catch(() => null);
        const waitPanel = (view, timeout = 30000) => page.waitForFunction((wanted) => { const el = document.querySelector('[data-cal-scan-panel]'); return el && el.dataset.view === wanted; }, { timeout }, view);
        const nextClass = () => page.$eval('[data-cal-action="next-step"]', (el) => (el.classList.contains('btn-primary') ? 'primary' : 'tertiary'));
        const dot = () => page.$eval('[data-cal-scan-dot]', (el) => !el.hidden);
        const state = () => h.state(page);
        const text = (selector) => page.$eval(selector, (el) => el.innerText.replace(/\s+/g, ' ').trim());

        /** card → panel → file → (consent) → start; resolves on the waiting screen. */
        async function startScan(file) {
            if (!(await page.$('[data-cal-scan-panel]'))) await page.click('[data-cal-action="scan-open"]');
            await page.waitForSelector('[data-cal-scan-panel] #calScanFile');
            await (await page.$('#calScanFile')).uploadFile(file);
            await waitPanel('chosen');
            for (const k of ['consent0', 'consent1']) if (await page.$(`[data-k="${k}"]`)) await page.click(`label:has([data-k="${k}"])`);
            await page.waitForFunction(() => !document.querySelector('[data-cal-action="scan-start"]').disabled, { timeout: 10000 });
            await page.click('[data-cal-action="scan-start"]');
            await waitPanel('wait');
        }

        // every 404 the browser got, whoever asked (an <img>, a fetch, a stylesheet)
        const missing = [];
        page.on('response', (response) => {
            if (response.status() === 404 && !/_wdt|favicon/.test(response.url())) missing.push(`${response.request().method()} ${response.url().replace(h.config.base, '')}`);
        });

        /**
         * „Odebrat obrázek“ of a place, until the place is empty. The cards are redrawn when a picture
         * or the page's size arrives; a click whose press and release meet such a redraw lands on
         * nothing — so: click, look, and click again if the picture is still there.
         */
        async function removePicture(slot) {
            const empty = () => page.$eval(`[data-slot="${slot}"]`, (el) => !el.textContent.includes('.png'));
            for (let attempt = 0; attempt < 4 && !(await empty()); attempt += 1) {
                await page.click(`[data-k="picdrop${slot}"]`).catch(() => null);
                await page.waitForFunction((name) => !document.querySelector(`[data-slot="${name}"]`).textContent.includes('.png'), { timeout: 4000 }, slot).catch(() => null);
            }
            assert.equal(await empty(), true, `the place ${slot} is empty`);
        }

        let calendarId = null;
        try {
            calendarId = await h.createCalendar(page);
            await h.gotoStep(page, 2);

            await check('T1 locked: an editor of a project without AI sken asks for access — and the card says the request was sent', async () => {
                project(h, 'ai_scan_enabled = false');
                try {
                await h.reload(page);
                await h.gotoStep(page, 2);
                await waitCard('locked');
                assert.match(await text('[data-cal-scan-card]'), /Naskenovat design.*Premium.*Požádat o přístup/);
                assert.equal(await page.$('#calScanGalleryModal'), null, 'no gallery component for somebody who cannot scan');
                await shot('scan-00a-locked');
                await page.click('[data-cal-scan-card] [data-act="request"]');
                await page.waitForSelector('dialog[open] [data-send]');
                assert.match(await text('dialog[open]'), /AI sken.*Premium.*Stránka vašeho kalendáře → stejné rozvržení za pár minut\..*placená funkce/);
                await page.type('dialog[open] [data-message]', 'Chceme převzít vzhled loňského kalendáře');
                await shot('scan-00b-request-dialog');
                await page.click('dialog[open] [data-send]');
                await page.waitForFunction(() => /Žádost odeslána/.test((document.querySelector('dialog[open]') || {}).textContent || ''), { timeout: 15000 });
                await page.click('dialog[open] [data-done]');
                await waitCard('request_sent');
                assert.match(await text('[data-cal-scan-card]'), /Odesláno \d+\. \d+\. \d{4}\. Dáme vám vědět\..*Žádost odeslána/);
                assert.equal(await page.$eval('[data-cal-scan-card] .btn', (el) => el.disabled), true);
                assert.equal(psql(`SELECT origin || ':' || status FROM ai_access_request WHERE project_id = ${quote(h.config.projectId)}`), 'calendars:open');
                // …and it is still so after a reload (the server's answer, not the page's memory)
                await h.reload(page);
                await h.gotoStep(page, 2);
                await waitCard('request_sent');
                await shot('scan-00c-request-sent');
                } finally {
                    psql(`DELETE FROM ai_access_request WHERE project_id = ${quote(h.config.projectId)}`);
                    project(h, 'ai_scan_enabled = true');
                }
            });

            await check('T1 limit: the month\'s scans are used up — the button is disabled and the line says when it resets', async () => {
                project(h, 'ai_scan_monthly_limit = 0');
                try {
                    await h.reload(page);
                    await h.gotoStep(page, 2);
                    await waitCard('limit_reached');
                    assert.equal(await page.$eval('[data-cal-scan-card] .btn', (el) => el.disabled), true);
                    assert.match(await text('[data-cal-scan-card] .as-act-line'), /Projekt má skeny pro tento měsíc vyčerpané\. Další budou od 1\. \p{L}+\./u);
                    await shot('scan-00d-limit');
                } finally {
                    project(h, 'ai_scan_monthly_limit = NULL');
                }
            });

            await check('T1 service waiting: the card as the entry\'s answer `service_waiting` draws it', async () => {
                // the entry answer is rewritten in the page — pausing the real service would stop other work on this machine
                const hook = await page.evaluateOnNewDocument(() => {
                    const original = window.fetch;
                    window.fetch = async (...args) => {
                        const response = await original(...args);
                        const url = typeof args[0] === 'string' ? args[0] : (args[0] && args[0].url) || '';
                        if (!/\/ai-scan\/entry/.test(url)) return response;
                        const body = await response.json();
                        if (body && body.entry) { body.entry.state = 'service_waiting'; body.entry.canStart = false; }
                        return new Response(JSON.stringify(body), { status: response.status, headers: { 'Content-Type': 'application/json' } });
                    };
                });
                try {
                    await h.reload(page);
                    await h.gotoStep(page, 2);
                    await waitCard('service_waiting');
                    assert.equal(await page.$eval('[data-cal-scan-card] .btn', (el) => el.disabled), true);
                    assert.match(await text('[data-cal-scan-card]'), /AI sken je dočasně nedostupný\. Víme o tom\./);
                    await shot('scan-00e-service-waiting');
                } finally {
                    await page.removeScriptToEvaluateOnNewDocument(hook.identifier);
                    await h.reload(page);
                    await h.gotoStep(page, 2);
                }
                await waitCard('enabled');
            });

            await check('C1 the action card sits under the two layout cards, outside the radiogroup', async () => {
                await page.waitForSelector('[data-cal-scan-card] [data-cal-action="scan-open"]', { timeout: 20000 });
                assert.equal(await page.$$eval('.cal-lays [role="radio"]', (els) => els.length), 2);
                assert.equal(await page.$('.cal-lays [data-cal-scan-card]'), null);
                assert.match(await text('[data-cal-scan-card]'), /Naskenovat design.*Premium.*Nahrát obrázek/);
                assert.equal(await cardState(), 'enabled'); // an editor of an entitled project — not an administrator's preview
                assert.doesNotMatch(await text('[data-cal-scan-card]'), /jako administrátor/);
                assert.ok(await page.$('#calScanGalleryModal'), 'the gallery picker is there for whoever may scan');
                assert.equal(await nextClass(), 'primary');
                assert.equal(await dot(), false);
                await shot('scan-01-card');
            });

            await check('C2 the panel opens on the upload zone; Pokračovat stays lavender while it only waits', async () => {
                await page.click('[data-cal-action="scan-open"]');
                await waitPanel('upload');
                assert.match(await text('[data-cal-scan-panel]'), /Přetáhněte sem obrázek stránky kalendáře|Klepněte a vyberte obrázek/);
                assert.equal(await page.$('[data-cal-scan-card]'), null);
                assert.equal(await nextClass(), 'primary');
                await shot('scan-02-upload');
            });

            await check('S1 a file that is no picture is refused in the browser', async () => {
                // (a PDF is accepted since 2026-11-13: its first page is scanned — a Photoshop file is still a source file)
                const bad = path.join(tmp, 'stranka.psd');
                fs.writeFileSync(bad, '8BPS fake');
                await (await page.$('#calScanFile')).uploadFile(bad);
                await page.waitForSelector('#calScanErr', { timeout: 8000 });
                assert.match(await text('#calScanErr'), /Zdrojové soubory \(PSD, AI, InDesign, SVG\) zatím neumíme/);
                assert.equal(await panelView(), 'upload');
            });

            await check('S1 a calendar page may be small: 977 × 457 px is taken with a note, under 300 px it is refused', async () => {
                const tiny = await logoPicture(browser, path.join(tmp, 'mala.png'), { width: 620, height: 290, colour: '#888', label: '' });
                await (await page.$('#calScanFile')).uploadFile(tiny);
                await page.waitForFunction(() => /620 × 290 px/.test((document.querySelector('#calScanErr') || {}).textContent || ''), { timeout: 8000 });
                assert.match(await text('#calScanErr'), /Kratší strana musí mít aspoň 300 px, lépe 600 px a víc\./);
                const small = await logoPicture(browser, path.join(tmp, 'stranka-977.png'), { width: 977, height: 457, colour: '#1f3a5f', label: '' });
                await (await page.$('#calScanFile')).uploadFile(small);
                await waitPanel('chosen');
                assert.match(await text('[data-cal-scan-panel]'), /977 × 457 px.*Malý obrázek — rozvržení může vyjít méně přesně/);
                await shot('scan-02b-small');
            });

            await check('S1 a picture dropped on the panel is taken like a chosen one', async () => {
                const dropped = await picture('pretazena.png');
                assert.equal(await dropFiles('[data-cal-scan-panel] [data-dropzone="page"]', [dropped]), true, 'the zone lights up under the file');
                await page.waitForFunction(() => /pretazena\.png/.test(document.querySelector('[data-cal-scan-panel]').textContent), { timeout: 8000 });
                assert.match(await text('[data-cal-scan-panel]'), /1600 × 748 px.*Dostatečné rozlišení/);
            });

            const first = await picture('stranka-1.png');
            await check('C2 a chosen picture: facts, consent, the start button — and Pokračovat steps back to tertiary', async () => {
                await (await page.$('#calScanFile')).uploadFile(first);
                await waitPanel('chosen');
                const body = await text('[data-cal-scan-panel]');
                assert.match(body, /1600 × 748 px/);
                assert.match(body, /Co převezmeme/);
                assert.doesNotMatch(body, /Kalendář už rozvržení má/);
                assert.equal(await page.$eval('[data-cal-action="scan-start"]', (el) => el.disabled), true);
                assert.match(await text('#calScanStartHint'), /Nejdřív potvrďte oba souhlasy/);
                assert.equal(await nextClass(), 'tertiary');
                await shot('scan-03-chosen');
            });

            await check('C3 start → waiting: the panel polls, the wizard stays usable, step 2 carries the dot', async () => {
                await startScan(first);
                assert.equal(await nextClass(), 'primary');
                assert.equal(await dot(), true);
                // the uploaded picture is the copy the status document names — nothing is guessed
                const named = await page.evaluate(() => window.__calendarScan.scan().picture);
                assert.ok(['original', 'working'].includes(named), `picture: ${named}`);
                await page.waitForFunction(() => { const img = document.querySelector('[data-cal-scan-panel] [data-region^="orig-"] img'); return img && img.complete && img.naturalWidth > 0; }, { timeout: 10000 });
                assert.match(await page.$eval('[data-cal-scan-panel] [data-region^="orig-"] img', (img) => img.getAttribute('src')), new RegExp(`/image/${named}$`));
                assert.equal(await page.$('[data-cal-scan-panel] img[data-fallback]'), null, 'no fallback chain');
                await shot('scan-04-wait');
                // the panel closes, the card says what is going on
                await page.click('[data-cal-action="scan-close"]');
                await page.waitForSelector('[data-cal-scan-card]');
                assert.match(await text('[data-cal-scan-card]'), /Zobrazit průběh/);
                // …and another step works meanwhile
                await h.gotoStep(page, 1);
                assert.equal(await dot(), true);
            });

            await check('C3 a running scan: the render the status names (`previewCandidate`) and the server\'s stage durations', async () => {
                await h.gotoStep(page, 2);
                await page.click('[data-cal-action="scan-open"]');
                await waitPanel('wait');
                // slow beats: the panel asks every 2 s and must see the stages and the render go by
                options.beatMs = 2500;
                options.paused = false;
                // a finished stage shows the duration of the server's stage log, not this browser's clock
                await page.waitForFunction(() => [...document.querySelectorAll('[data-cal-scan-panel] .as-stages li.done .dur')].some((el) => /\d/.test(el.textContent)), { timeout: 60000 });
                const shown = await page.evaluate(() => {
                    const stages = window.__calendarScan.scan().stages || {};
                    const done = [...document.querySelectorAll('[data-cal-scan-panel] .as-stages li.done')].map((li) => li.querySelector('.dur').textContent.trim());
                    return { logged: Object.values(stages).filter((x) => typeof x.seconds === 'number').length, done };
                });
                assert.ok(shown.logged >= 1, 'the status document carries a stage log');
                assert.ok(shown.done.some((text) => /^(\d+ s|\d+ min \d+ s)$/.test(text)), `durations: ${shown.done.join(' | ')}`);
                // the first render: the address carries the candidate the status document named
                await page.waitForFunction(() => /\/image\/candidate\?n=\d+&v=\d+/.test((document.querySelector('[data-cal-scan-panel] [data-region^="prev-"] img') || { src: '' }).src), { timeout: 60000 });
                options.beatMs = 8000; // …and the scan stays running while this check looks
                const [src, candidate] = await page.evaluate(() => [document.querySelector('[data-cal-scan-panel] [data-region^="prev-"] img').getAttribute('src'), window.__calendarScan.scan().previewCandidate]);
                assert.equal(Number(/n=(\d+)/.exec(src)[1]), candidate);
                await page.waitForFunction(() => { const img = document.querySelector('[data-cal-scan-panel] [data-region^="prev-"] img'); return img && img.complete && img.naturalWidth > 0; }, { timeout: 10000 });
                await h.shot(page, 'scan-04b-running');
                await page.click('[data-cal-action="scan-close"]');
                await page.waitForSelector('[data-cal-scan-card]');
                await h.gotoStep(page, 1);
            });

            await check('C3 the proposal is announced in any step; „Zkontrolovat“ opens step 2 with the review', async () => {
                options.paused = false; // (it is, since the check above)
                await h.waitToast(page, 'Návrh rozvržení je připravený', 60000);
                options.beatMs = 350;
                assert.equal(await dot(), true); // now: a proposal waits
                await page.click('.toast-n [data-cal-toast-action]');
                await page.waitForSelector('.cal-ws[data-cal-shown-step="2"]', { timeout: 10000 });
                await waitPanel('review');
                assert.equal(await dot(), false);
            });

            await check('C4 the review: live preview of the proposal, recognised rows, switches, one primary', async () => {
                await page.waitForFunction(() => document.querySelector('[data-proposal] svg'), { timeout: 15000 });
                const body = await text('[data-cal-scan-panel]');
                assert.match(body, /Co jsme rozpoznali/);
                assert.match(body, /Fotografie.*vpravo/);
                assert.match(body, /Kalendárium.*čísla vlevo/);
                assert.match(body, /Hlavička.*#1F3A5F/);
                assert.match(body, /Číslo týdne.*na stránce jsme nenašli/);
                assert.match(body, /Tohle kalendář neumí.*Ozdobný pruh/);
                assert.match(body, /Písmo čísel dnů: nejbližší dostupné/);
                assert.match(body, /Stránka má jiný poměr stran než váš kalendář \(297 × 150 mm\)/);
                assert.match(body, /Kalendář se nezmění, dokud rozvržení nepoužijete\. Návrh uchováme do \d+\. \d+\. \d{4}/);
                assert.deepEqual(await page.$$eval('[data-chg="feature"]', (els) => els.map((el) => [el.dataset.key, el.checked])), [['qr', true], ['events', true]]);
                assert.equal(await nextClass(), 'tertiary');
                assert.equal(await page.$$eval('[data-cal-scan-panel] .btn-primary', (els) => els.length), 1);
                // the proposal is drawn by the calendar's renderer: the header is navy
                assert.ok(await page.$eval('[data-proposal] svg', (svg) => svg.outerHTML.toUpperCase().includes('#1F3A5F')));
                await shot('scan-05-review');
                // Posuvník / Vedle sebe
                await page.click('[data-k="cmpslider"]');
                await page.waitForSelector('[data-cal-scan-panel] .as-handle');
                await page.waitForFunction(() => document.querySelector('[data-proposal] svg'));
                await shot('scan-06-review-slider');
                await page.click('[data-k="cmpside"]');
                await page.waitForFunction(() => document.querySelector('[data-proposal] svg'));
                // a switch left unticked is not applied
                await page.click('label:has([data-key="events"])');
                assert.deepEqual(await page.evaluate(() => [...window.__calendarScan.proposal().features.map((f) => f.key)]), ['qr', 'events']);
            });

            await check('C4 „Ukázat vrstvy“: outlines with letter tags and a legend; a recognised row lights its box', async () => {
                assert.equal(await page.$$eval('[data-cal-scan-panel] .as-box:not(.ghost)', (els) => els.length), 0);
                await page.click('label:has([data-k="layers"])');
                await page.waitForSelector('[data-cal-scan-panel] .as-legend');
                const tags = await page.$$eval('[data-cal-scan-panel] .as-box:not(.ghost) .as-ltag', (els) => els.map((el) => el.textContent).sort().join(''));
                assert.match(tags, /F/);
                assert.match(tags, /H/);
                assert.match(tags, /K/);
                assert.match(await text('[data-cal-scan-panel] .as-legend'), /F ?Fotografie.*K ?Kalendárium/);
                // the outline of the photo sits on the right half, as the proposal has it
                const photo = await page.$eval('[data-box="photo"]', (el) => { const f = el.parentElement.getBoundingClientRect(); const b = el.getBoundingClientRect(); return (b.left + b.width / 2 - f.left) / f.width; });
                assert.ok(photo > 0.5, `photo outline centre at ${photo}`);
                // (the row may be redrawn once while the page's size is being read: hover again then)
                await page.hover('.as-rec li[data-hl="photo"]').catch(() => page.hover('.as-rec li[data-hl="photo"]'));
                await page.waitForFunction(() => document.querySelector('.as-box[data-box="photo"]').classList.contains('hl'), { timeout: 3000 }).catch(() => null);
                assert.equal(await page.$eval('.as-box[data-box="photo"]', (el) => el.classList.contains('hl')), true, `hover: ${await page.evaluate(() => { const li = document.querySelector('.as-rec li[data-hl="photo"]'); const r = li.getBoundingClientRect(); const at = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2); return `${Math.round(r.top)} ${at && at.tagName}.${at && at.className} hover=${li.matches(':hover')}`; })}`);
                await shot('scan-06b-layers');
                await page.click('label:has([data-k="layers"])');
                await page.waitForFunction(() => !document.querySelector('[data-cal-scan-panel] .as-legend'));
                await page.waitForFunction(() => document.querySelector('[data-proposal] svg'));
            });

            await check('C4 „Jak se sken povedl?“ — a thumb is stored with the scan', async () => {
                assert.match(await text('[data-cal-scan-panel] .as-fb'), /Jak se sken povedl\?.*Dobře.*Špatně/);
                await page.click('[data-k="fbd"]');
                await page.waitForSelector('[data-k="fbt"]');
                await page.type('[data-k="fbt"]', 'Hlavička je moc nízká');
                await page.keyboard.press('Enter');
                await h.waitToast(page, 'Díky za hodnocení');
                assert.match(await text('[data-cal-scan-panel] .as-fb'), /Díky za hodnocení/);
                const scanId = await page.evaluate(() => window.__calendarScan.scan().id);
                assert.equal(psql(`SELECT rating || ':' || rating_note FROM ai_scan WHERE id = ${quote(scanId)}`), '-1:Hlavička je moc nízká');
            });

            await check('C4b pictures: a picture is held with the scan, the button counts what stays empty', async () => {
                await page.click('[data-cal-action="scan-pictures"]');
                await waitPanel('pictures');
                assert.match(await text('[data-cal-action="scan-apply"]'), /^Použít rozvržení · 2 obrázky doplníte později$/);
                const [chooser] = await Promise.all([page.waitForFileChooser(), page.click('[data-k="piclogo"]')]);
                await chooser.accept([logo]);
                await page.waitForFunction(() => /logo\.png/.test(document.querySelector('[data-slot="logo"]').textContent), { timeout: 15000 });
                assert.match(await text('[data-cal-action="scan-apply"]'), /^Použít rozvržení · 1 obrázek doplníte později$/);
                assert.match(await text('[data-cal-scan-panel] .as-foot'), /Uloží se hned\. Původní rozvržení vrátíte tlačítkem Vrátit původní — do \d+\. \d+\. \d{4}/);
                // the held picture shows in the live preview
                await page.waitForFunction(() => /image\/picture\?slot=logo/.test(document.querySelector('[data-proposal]').innerHTML), { timeout: 10000 });
                await shot('scan-07-pictures');
            });

            await check('C4b numbered places: the numbers over the proposal match the cards; an empty place shows the page\'s own pixels', async () => {
                const slots = await page.$$eval('[data-cal-scan-panel] .as-ph', (els) => els.map((el) => [el.dataset.slot, el.querySelector('.as-num').textContent]));
                assert.deepEqual(slots.map(([, n]) => n), slots.map((_, i) => String(i + 1)));
                assert.equal(await page.$$eval('[data-cal-scan-panel] .as-frame > .as-num', (els) => els.length), slots.length);
                // the logo is filled (solid outline), the header is not (the original shows through)
                assert.equal(await page.$eval('[data-box="pic-logo"]', (el) => el.classList.contains('done')), true, 'the filled place is solid');
                await page.waitForFunction(() => window.__calendarScan.proposal().source && window.__calendarScan.proposal().source.width > 0, { timeout: 10000 });
                assert.equal(await page.$eval('[data-box="pic-header"]', (el) => el.classList.contains('as-place') && /image\/working/.test(el.style.backgroundImage)), true, 'the empty place shows the page');
                // the card carries a crop of the uploaded page at that spot
                assert.equal(await page.$eval('[data-slot="header"] .as-ph-crop .crop', (el) => /image\/working/.test(el.style.backgroundImage)), true, 'the card has a crop of the page');
                await page.hover('[data-slot="header"] .as-ph-h');
                await page.waitForFunction(() => document.querySelector('[data-box="pic-header"]').classList.contains('hl'), { timeout: 3000 }).catch(() => null);
                assert.equal(await page.$eval('[data-box="pic-header"]', (el) => el.classList.contains('hl')), true, 'the card lights its place');
            });

            await check('C4b the gallery as a source: a picture of the project\'s gallery is copied to the scan', async () => {
                await page.click('[data-k="picgheader"]');
                await page.waitForSelector('#calScanGalleryModal.show', { timeout: 15000 });
                // the e2e project's gallery is empty: put one picture there through the gallery's own uploader
                const tile = '#calScanGalleryModal [data-image-gallery-id-param]';
                if (!(await page.$(tile))) {
                    await (await page.$('#calScanGalleryModal [data-gallery-uploader-target="input"]')).uploadFile(strip);
                    const confirm = await page.waitForSelector('#calScanGalleryModal [data-action*="gallery-uploader#upload"], #calScanGalleryModal [data-gallery-uploader-target="submit"]', { timeout: 4000 }).catch(() => null);
                    if (confirm) await confirm.click();
                }
                await page.waitForSelector(tile, { timeout: 30000 });
                await shot('scan-07b-gallery');
                await page.click(tile);
                await page.waitForFunction(() => !document.querySelector('#calScanGalleryModal.show'), { timeout: 15000 });
                await page.waitForFunction((name) => document.querySelector('[data-slot="header"]').textContent.includes(name), { timeout: 15000 }, GALLERY_FILE);
                assert.match(await text('[data-slot="header"]'), /1200 × 160 px/);
                await page.waitForFunction(() => /image\/picture\?slot=header/.test(document.querySelector('[data-proposal]').innerHTML), { timeout: 10000 });
                // the gallery keeps its file
                assert.equal(psql(`SELECT count(*) FROM file_upload WHERE project_id = ${quote(h.config.projectId)} AND original_name = ${quote(GALLERY_FILE)} AND deleted_at IS NULL`), '1');
            });

            await check('C4b drag and drop: one file on a card fills that place, several on the zone fill the empty places in order', async () => {
                await removePicture('header');
                await removePicture('logo');
                assert.equal(await dropFiles('[data-slot="header"] .as-ph-b', [strip]), true);
                await page.waitForFunction((name) => document.querySelector('[data-slot="header"]').textContent.includes(name), { timeout: 15000 }, GALLERY_FILE);
                assert.doesNotMatch(await text('[data-slot="logo"]'), /\.png/);
                await removePicture('header');
                // two files on the multi-drop zone: logo first, header second (the order of the places)
                await dropFiles('[data-cal-scan-panel] [data-dropzone="pictures"]', [logo, backdrop]);
                await page.waitForFunction(() => /logo\.png/.test(document.querySelector('[data-slot="logo"]').textContent) && /pozadi\.png/.test(document.querySelector('[data-slot="header"]').textContent), { timeout: 20000 });
                await h.waitToast(page, '2 obrázky přiřazeny');
                await shot('scan-07c-dropped');
                // back to what the next checks expect: the logo only
                await removePicture('header');
                assert.match(await text('[data-cal-action="scan-apply"]'), /^Použít rozvržení · 1 obrázek doplníte později$/);
            });

            await check('Applying waits for unsaved changes of the calendar', async () => {
                await page.click('[data-cal-name]', { clickCount: 3 });
                await page.type('[data-cal-name]', 'Kalendář ze skenu');
                await h.waitStatus(page, 'dirty');
                await page.waitForFunction(() => document.querySelector('[data-cal-action="scan-apply"]').disabled);
                assert.match(await text('#calScanApplyHint'), /Nejdřív uložte rozpracované změny/);
                await page.click('[data-k="scansave"]');
                await h.waitStatus(page, 'clean');
                await page.waitForFunction(() => !document.querySelector('[data-cal-action="scan-apply"]').disabled);
            });

            let applied = null;
            await check('„Použít rozvržení“ saves at once, reloads the calendar and opens step 3', async () => {
                await page.click('[data-cal-action="scan-apply"]');
                await page.waitForSelector('.cal-ws[data-cal-shown-step="3"]', { timeout: 20000 });
                await h.waitToast(page, 'Rozvržení převzato ze skenu');
                applied = await state();
                assert.equal(applied.layoutOrigin, 'scan');
                assert.equal(applied.design.preset, 'photoRight');
                assert.equal(applied.design.layout.styles.cal.numberSide, 'start');
                assert.equal(applied.design.layout.styles.head.bg.color, '#1F3A5F');
                assert.equal(applied.design.features.qr, true);
                assert.equal(applied.design.features.events, false);
                assert.match(applied.design.layout.styles.logo.asset, /^[0-9a-f-]{36}$/);
                assert.equal(applied.status, 'clean');
                assert.equal(applied.canUndo, false); // the step history starts again
                assert.match(applied.design.name, /Kalendář ze skenu/);
                await shot('scan-08-applied');
            });

            await check('After a reload the layout is still the scanned one; step 2 shows the third radio selected', async () => {
                await h.reload(page);
                assert.equal((await state()).layoutOrigin, 'scan');
                await h.gotoStep(page, 2);
                await page.waitForSelector('[data-preset="scan"][aria-checked="true"]');
                assert.equal(await page.$$eval('.cal-lays [role="radio"]', (els) => els.length), 3);
                assert.match(await text('[data-preset="scan"]'), /Naskenovaný design Rozvržení převzaté z vašeho kalendáře/);
                assert.equal(await page.$eval('[data-preset="photoRight"]', (el) => el.getAttribute('aria-checked')), 'false');
                await page.waitForSelector('[data-cal-scan-card] [data-cal-action="scan-restore"]');
                await shot('scan-09-step2-scanned');
            });

            await check('A preset card over a scanned layout replaces it with the default (no mirror) — and „Vrátit“ undoes it', async () => {
                await page.click('[data-preset="photoRight"]'); // the SAME layout choice the scan carries
                await page.waitForSelector('.cal-ws[data-cal-shown-step="3"]');
                await h.waitToast(page, 'Rozvržení nahrazeno výchozím');
                let s = await state();
                assert.equal(s.layoutOrigin, null);
                assert.equal(s.design.preset, 'photoRight');
                assert.equal(s.design.layout.styles.cal.numberSide, 'start'); // photoRight's own default…
                assert.equal(s.design.layout.styles.head.bg.color === '#1F3A5F', false); // …not the scan's styling
                assert.equal(s.status, 'dirty');
                await page.click('.toast-n [data-cal-toast-action]');
                s = await state();
                assert.equal(s.layoutOrigin, 'scan');
                assert.equal(s.design.layout.styles.head.bg.color, '#1F3A5F');
                assert.equal(s.status, 'clean');
                // …and really choosing it tells the server to drop the marker
                await h.gotoStep(page, 2);
                await page.click('[data-preset="photoLeft"]');
                await page.waitForSelector('.cal-ws[data-cal-shown-step="3"]');
                await h.save(page);
                assert.equal((await state()).layoutOrigin, null);
                await h.reload(page);
                s = await state();
                assert.equal(s.layoutOrigin, null);
                assert.equal(s.design.preset, 'photoLeft');
                await h.gotoStep(page, 2);
                assert.equal(await page.$('[data-preset="scan"]'), null);
            });

            await check('„Vrátit původní rozvržení“ asks first when the layout was changed since', async () => {
                await page.waitForSelector('[data-cal-scan-card] [data-cal-action="scan-restore"]');
                await page.click('[data-cal-scan-card] [data-cal-action="scan-restore"]');
                await page.waitForSelector('.cal-dialog.show');
                assert.match(await text('.cal-dialog.show'), /Vrátit původní rozvržení\?.*Od použití skenu jste rozvržení upravili\. Vrácením o tyto úpravy přijdete\. Texty, fotky a obsah stránek zůstanou\./);
                await shot('scan-10-restore-confirm');
                await h.answerDialog(page, 'cancel');
                assert.equal((await state()).design.preset, 'photoLeft');
                await page.click('[data-cal-scan-card] [data-cal-action="scan-restore"]');
                await h.answerDialog(page, 'confirm');
                await h.waitToast(page, 'Původní rozvržení vráceno');
                const s = await state();
                // the calendar had no layout before the scan: step 2 again, nothing chosen
                assert.equal(s.design.layout, null);
                assert.equal(s.layoutOrigin, null);
                assert.equal(s.design.features.qr, false);
                assert.match(s.design.name, /Kalendář ze skenu/); // nothing else went back
                await page.waitForSelector('.cal-ws[data-cal-shown-step="2"]');
                assert.equal(await page.$('[data-cal-action="scan-restore"]'), null);
            });

            let second = null;
            await check('A calendar WITH a layout: „Nahradit rozvržení“, and the toast\'s „Vrátit původní“ puts it straight back', async () => {
                await page.click('[data-preset="photoLeft"]');
                await page.waitForSelector('.cal-ws[data-cal-shown-step="3"]');
                await h.save(page);
                const before = (await state()).design.layout;
                await h.gotoStep(page, 2);
                await page.waitForSelector('[data-cal-scan-card] [data-cal-action="scan-open"]');
                // …and the scanner says the page showed two weeks: the review states plainly what was taken
                options.calendar = { notes: [{ code: 'fortnight_left_half' }] };
                await startScan(await picture('stranka-2.png'));
                await waitPanel('review', 60000);
                options.calendar = null;
                assert.match(await text('[data-cal-scan-fortnight]'), /Stránka má dva týdny, kalendář jeden.*návrh převzal jen levý týden a fotografii — pravý sloupec dnů v něm není\. Rozvržení čtrnáctidenního kalendáře zatím skenovat neumíme\./);
                assert.doesNotMatch(await text('[data-cal-scan-panel]'), /převzali jsme levou polovinu/);
                await shot('scan-10a-fortnight');
                assert.match(await text('[data-cal-scan-panel]'), /Číslo týdne.*zůstane jako dosud/);
                await page.click('[data-cal-action="scan-pictures"]');
                await waitPanel('pictures');
                assert.match(await text('[data-cal-action="scan-apply"]'), /^Nahradit rozvržení · 2 obrázky doplníte později$/);
                await page.click('[data-cal-action="scan-apply"]');
                await page.waitForSelector('.cal-ws[data-cal-shown-step="3"]', { timeout: 20000 });
                await h.waitToast(page, 'Rozvržení převzato ze skenu');
                second = await state();
                assert.equal(second.layoutOrigin, 'scan');
                await page.click('.toast-n [data-cal-toast-action]'); // Vrátit původní — nothing changed since: no question
                await h.waitToast(page, 'Původní rozvržení vráceno');
                const s = await state();
                assert.equal(s.layoutOrigin, null);
                assert.equal(s.design.preset, 'photoLeft');
                assert.deepEqual(s.design.layout.boxes, before.boxes);
                assert.equal(await page.$('.cal-dialog.show'), null);
            });

            let third = null;
            await check('An applied scan opened from its link (e-mail, MCP, history): what was done and „Vrátit původní rozvržení“', async () => {
                await h.gotoStep(page, 2);
                await page.waitForSelector('[data-cal-scan-card] [data-cal-action="scan-open"]');
                await startScan(await picture('stranka-2b.png'));
                await waitPanel('review', 60000);
                third = await page.evaluate(() => window.__calendarScan.scan().id);
                await page.click('[data-cal-action="scan-pictures"]');
                await waitPanel('pictures');
                await page.click('[data-cal-action="scan-apply"]');
                await page.waitForSelector('.cal-ws[data-cal-shown-step="3"]', { timeout: 20000 });
                await h.waitToast(page, 'Rozvržení převzato ze skenu');
                await page.goto(`${h.config.base}/calendar/${calendarId}?sken=${third}`, { waitUntil: 'networkidle2' });
                await h.waitReady(page);
                await page.waitForSelector('.cal-ws[data-cal-shown-step="2"]', { timeout: 15000 });
                await waitPanel('gone');
                assert.doesNotMatch(page.url(), /sken=/);
                const body = await text('[data-cal-scan-panel]');
                assert.match(body, /Rozvržení použito.*Rozvržení z tohoto skenu je použité.*Původní rozvržení můžete vrátit do \d+\. \d+\. \d{4}\. Texty, fotky a obsah stránek zůstanou\./);
                assert.ok(await page.$(`[data-cal-scan-panel] [data-cal-action="scan-restore"][data-scan="${third}"]`));
                assert.equal((await state()).layoutOrigin, 'scan');
                await shot('scan-10b-applied-link');
            });

            await check('History row: „Vrátit původní rozvržení“ puts the layout back and the row loses the action', async () => {
                await page.goto(`${h.config.base}/project/${h.config.projectId}/sablony/historie-skenu?filter=calendars`, { waitUntil: 'networkidle2' });
                const row = `.as-hrow[data-scan="${third}"]`;
                await page.waitForSelector(`${row} [data-bs-toggle="dropdown"]`);
                assert.match(await text(row), /Rozvržení použito/);
                await page.click(`${row} [data-bs-toggle="dropdown"]`);
                await page.waitForSelector(`${row} .dropdown-menu.show [data-action="ai-scan-history#restore"]`);
                assert.match(await text(`${row} .dropdown-menu.show`), /Vrátit původní rozvržení.*Otevřít kalendář/);
                await shot('scan-10c-history-menu');
                await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 30000 }), page.click(`${row} [data-action="ai-scan-history#restore"]`)]);
                assert.equal(psql(`SELECT coalesce(layout_origin, 'none') || ':' || layout_preset FROM calendar WHERE id = ${quote(calendarId)}`), 'none:photoLeft');
                assert.equal(await page.$(`${row} [data-action="ai-scan-history#restore"]`), null);
                assert.equal(psql(`SELECT description FROM calendar_history WHERE calendar_id = ${quote(calendarId)} ORDER BY created_at DESC, id DESC LIMIT 1`), 'Vrátil původní rozvržení');
                await h.openCalendar(page, calendarId);
            });

            await check('C5 the list card carries the pill; it opens step 2 on the proposal; „Zahodit“ throws it away', async () => {
                await h.gotoStep(page, 2);
                await page.waitForSelector('[data-cal-scan-card] [data-cal-action="scan-open"]');
                await startScan(await picture('stranka-3.png'));
                await waitPanel('review', 60000);
                await h.gotoList(page);
                await page.waitForSelector(`.cal-card[data-calendar-id="${calendarId}"] .cal-scan-pill[data-cal-scan-pill="ready"]`, { timeout: 15000 });
                assert.match(await text(`.cal-card[data-calendar-id="${calendarId}"] .cal-scan-pill`), /Návrh připraven/);
                await shot('scan-11-list-pill');
                await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2' }), page.click(`.cal-card[data-calendar-id="${calendarId}"] .cal-scan-pill`)]);
                await h.waitReady(page);
                await page.waitForSelector('.cal-ws[data-cal-shown-step="2"]');
                await waitPanel('review');
                assert.doesNotMatch(page.url(), /sken=/);
                // „Naskenovat znovu“ → the same picture goes back to the chosen-file screen; the proposal stays
                await page.click('[data-cal-action="scan-rescan"]');
                await page.waitForSelector('dialog[open] [data-mode="same"]');
                assert.match(await text('dialog[open]'), /Naskenovat znovu.*Nový sken může dopadnout jinak, ne nutně lépe\..*Stejný obrázek znovu.*Nahrát lepší obrázek.*Spotřebuje další sken/);
                await shot('scan-11b-rescan-dialog');
                await page.click('dialog[open] [data-mode="same"]');
                await waitPanel('chosen');
                assert.match(await text('[data-cal-scan-panel]'), /stranka-3\.png.*1600 × 748 px/);
                assert.equal(await page.$eval('[data-cal-action="scan-start"]', (el) => el.disabled), false);
                await page.goto(`${h.config.base}/calendar/${calendarId}?sken=1`, { waitUntil: 'networkidle2' });
                await h.waitReady(page);
                await waitPanel('review');
                await page.click('[data-cal-action="scan-discard"]');
                await h.answerDialog(page, 'confirm');
                await h.waitToast(page, 'Návrh zahozen');
                await page.waitForSelector('[data-cal-scan-card] [data-cal-action="scan-open"]');
                assert.match(await text('[data-cal-scan-card]'), /Nahrát obrázek/);
                assert.equal(psql(`SELECT count(*) FROM calendar_asset WHERE calendar_id = ${quote(calendarId)}`), '1'); // only the logo of the first apply
            });

            await check('S5 a stopped scan: the calendar\'s own card, „Nahrát jiný obrázek“ is the one primary', async () => {
                options.scenario = 'nographic';
                await startScan(await picture('stranka-4.png'));
                await waitPanel('fail', 60000);
                const body = await text('[data-cal-scan-panel]');
                assert.match(body, /Tohle nevypadá jako stránka kalendáře/);
                assert.match(body, /Sken se nezapočítal/);
                assert.equal(await page.$$eval('[data-cal-scan-panel] .btn-primary', (els) => els.length), 1);
                assert.equal(await nextClass(), 'tertiary');
                await shot('scan-12-stopped');
                options.scenario = 'happy';
                await page.click('[data-k="other"]');
                await waitPanel('upload');
            });

            await check('History: calendar scans are listed, open their calendar and can be filtered', async () => {
                await page.goto(`${h.config.base}/project/${h.config.projectId}/sablony/historie-skenu?filter=calendars`, { waitUntil: 'networkidle2' });
                const rows = await page.$$eval('.as-hrow', (els) => els.map((el) => ({ status: el.dataset.status, text: el.innerText.replace(/\s+/g, ' '), href: (el.querySelector('a.u-nm') || {}).href || null })));
                assert.ok(rows.length >= 4, `rows: ${rows.length}`);
                assert.ok(rows.every((row) => /Kalendáře/.test(row.text)));
                const accepted = rows.filter((row) => row.status === 'accepted');
                assert.equal(accepted.length, 3);
                assert.ok(accepted.every((row) => /Rozvržení použito/.test(row.text)));
                assert.ok(rows.filter((row) => row.href).every((row) => new RegExp(`/calendar/${calendarId}\\?sken=`).test(row.href)));
                assert.ok(await page.$('.fbar a.chip.on[href*="filter=calendars"]'));
                await shot('scan-13-history');
            });

            await check('History of a project that has ONLY the Kalendáře module: it opens, and „Nový sken“ leads to the calendar', async () => {
                project(h, `modules = '["calendars"]'`);
                try {
                    const response = await page.goto(`${h.config.base}/project/${h.config.projectId}/sablony/historie-skenu`, { waitUntil: 'networkidle2' });
                    assert.equal(response.status(), 200);
                    assert.match(page.url(), /historie-skenu/);
                    assert.ok((await page.$$('.as-hrow')).length >= 4);
                    assert.match(await page.$eval('a.back', (el) => `${el.textContent.trim()} ${el.getAttribute('href')}`), /Kalendáře .*\/calendars/);
                    const links = await page.$$eval('.head a, .head .dropdown-item, [data-new-scan]', (els) => els.map((el) => `${el.textContent.trim()} → ${el.getAttribute('href') || ''}`));
                    assert.ok(links.some((link) => new RegExp(`Nový sken → /calendar/${calendarId}\\?sken=1`).test(link)), links.join(' | '));
                    assert.ok(!links.some((link) => /z-obrazku/.test(link)), 'no template scan without the Šablony module');
                    await shot('scan-14-history-calendar-only');
                } finally {
                    project(h, `modules = '["manuals", "templates", "calendars"]'`);
                }
            });

            await check('Nothing the pages asked for was missing (no 404)', async () => {
                assert.deepEqual(missing, []);
            });
        } finally {
            // `layout_changed` IS a 409 — the answer the confirmation is built on, not an error of the page
            page.problems = page.problems.filter((problem) => !/409 \(Conflict\)/.test(problem));
            options.stopped = true;
            await served;
            if (lines.some((line) => line.startsWith('ERROR'))) console.error(`[fake-scanner] ${lines.filter((line) => line.startsWith('ERROR')).join(' | ')}`);
            if (calendarId) {
                // scans keep a calendar from being forgotten by the list only through their rows: drop them with it
                psql(`DELETE FROM ai_scan WHERE calendar_id = ${quote(calendarId)}; DELETE FROM ai_usage_event WHERE project_id = ${quote(h.config.projectId)}; DELETE FROM ai_credit_ledger WHERE project_id = ${quote(h.config.projectId)};`);
                await h.deleteCalendar(page, calendarId).catch(() => null);
            }
            fs.rmSync(tmp, { recursive: true, force: true });
        }
    },
};
