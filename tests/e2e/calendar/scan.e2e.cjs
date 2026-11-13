/*
 * AI scan in the calendar wizard — „Naskenovat design“ (docs/ai-scan/ux.md §6,
 * docs/architecture/calendars.md § "Calendar scan — UI"):
 *
 *   card → panel → upload → consent → start → waiting (the wizard stays usable, the dot, the toast)
 *   → review (live preview, recognised rows, switches) → pictures → apply → step 3 → „Vrátit původní“,
 *   the preset card over a scanned layout, restore with the confirmation after later edits,
 *   the list pill, discard.
 *
 * No model is called: tests/e2e/ai-scan/lib/fake-scanner.cjs plays the scanner over the real
 * scanner API and takes ONLY the scans of the e2e calendar project. It needs the AI scan switched
 * on (`bin/console app:ai-scan:status --mode=on`); the script works as an ADMINISTRATOR of its own
 * (`e2e-calendar-admin@test.cz`), so it runs under "admins only" and changes no operating setting.
 */
const fs = require('fs');
const os = require('os');
const path = require('path');
const { execFileSync } = require('child_process');
const fake = require('../ai-scan/lib/fake-scanner.cjs');

const ROOT = path.resolve(__dirname, '../../..');
const ADMIN = 'e2e-calendar-admin@test.cz';
const PASSWORD = 'E2eCalendar123';
const quote = (text) => `'${String(text).replace(/'/g, "''")}'`;

function compose(args, input) {
    return execFileSync('docker', ['compose', 'exec', '-T', ...args], { cwd: ROOT, input, encoding: 'utf8', stdio: ['pipe', 'pipe', 'pipe'] });
}
const psql = (sql) => compose(['postgres', 'psql', '-U', 'postgres', '-d', 'wboost', '-v', 'ON_ERROR_STOP=1', '-At'], sql).trim();

/** The administrator, and nothing left of earlier runs: the project's scans, their usage, the consent. */
function fixture(h) {
    if (psql(`SELECT count(*) FROM "user" WHERE email = ${quote(ADMIN)}`) === '0') compose(['web', 'bin/console', 'app:user:register', ADMIN, PASSWORD]);
    psql(`
        UPDATE "user" SET roles = '["ROLE_ADMIN"]', ai_scan_consent_version = NULL, ai_scan_consent_at = NULL WHERE email = ${quote(ADMIN)};
        DELETE FROM ai_scan WHERE project_id = ${quote(h.config.projectId)};
        DELETE FROM ai_usage_event WHERE project_id = ${quote(h.config.projectId)};
        DELETE FROM ai_credit_ledger WHERE project_id = ${quote(h.config.projectId)};
    `);
}

async function loginAs(page, h, email) {
    const session = await page.createCDPSession();
    await session.send('Network.clearBrowserCookies');
    await session.detach();
    await page.goto(`${h.config.base}/login`, { waitUntil: 'networkidle2' });
    await page.type('input[name="_username"]', email);
    await page.type('input[name="_password"]', PASSWORD);
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2' }), page.click('button[type="submit"]')]);
    if (/\/login/.test(page.url())) throw new Error(`Login failed for ${email}`);
}

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

async function logoPicture(browser, file) {
    const page = await browser.newPage();
    await page.setViewport({ width: 360, height: 120 });
    await page.setContent('<body style="margin:0;height:120px;background:#c8102e;color:#fff;font:800 60px sans-serif;display:grid;place-items:center">LOGO</body>');
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

        let calendarId = null;
        try {
            await loginAs(page, h, ADMIN);
            calendarId = await h.createCalendar(page);
            await h.gotoStep(page, 2);

            await check('C1 the action card sits under the two layout cards, outside the radiogroup', async () => {
                await page.waitForSelector('[data-cal-scan-card] [data-cal-action="scan-open"]', { timeout: 20000 });
                assert.equal(await page.$$eval('.cal-lays [role="radio"]', (els) => els.length), 2);
                assert.equal(await page.$('.cal-lays [data-cal-scan-card]'), null);
                assert.match(await text('[data-cal-scan-card]'), /Naskenovat design.*Premium.*Nahrát obrázek/);
                assert.equal(await nextClass(), 'primary');
                assert.equal(await dot(), false);
                await h.shot(page, 'scan-01-card');
            });

            await check('C2 the panel opens on the upload zone; Pokračovat stays lavender while it only waits', async () => {
                await page.click('[data-cal-action="scan-open"]');
                await waitPanel('upload');
                assert.match(await text('[data-cal-scan-panel]'), /Přetáhněte sem obrázek stránky kalendáře|Klepněte a vyberte obrázek/);
                assert.equal(await page.$('[data-cal-scan-card]'), null);
                assert.equal(await nextClass(), 'primary');
                await h.shot(page, 'scan-02-upload');
            });

            await check('S1 a file that is no picture is refused in the browser', async () => {
                const bad = path.join(tmp, 'stranka.pdf');
                fs.writeFileSync(bad, '%PDF-1.4 not a picture');
                await (await page.$('#calScanFile')).uploadFile(bad);
                await page.waitForSelector('#calScanErr', { timeout: 8000 });
                assert.match(await text('#calScanErr'), /PDF a zdrojové soubory zatím neumíme/);
                assert.equal(await panelView(), 'upload');
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
                await h.shot(page, 'scan-03-chosen');
            });

            await check('C3 start → waiting: the panel polls, the wizard stays usable, step 2 carries the dot', async () => {
                await startScan(first);
                assert.equal(await nextClass(), 'primary');
                assert.equal(await dot(), true);
                await h.shot(page, 'scan-04-wait');
                // the panel closes, the card says what is going on
                await page.click('[data-cal-action="scan-close"]');
                await page.waitForSelector('[data-cal-scan-card]');
                assert.match(await text('[data-cal-scan-card]'), /Zobrazit průběh/);
                // …and another step works meanwhile
                await h.gotoStep(page, 1);
                assert.equal(await dot(), true);
            });

            await check('C3 the proposal is announced in any step; „Zkontrolovat“ opens step 2 with the review', async () => {
                options.paused = false;
                await h.waitToast(page, 'Návrh rozvržení je připravený', 60000);
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
                await h.shot(page, 'scan-05-review');
                // Posuvník / Vedle sebe
                await page.click('[data-k="cmpslider"]');
                await page.waitForSelector('[data-cal-scan-panel] .as-handle');
                await page.waitForFunction(() => document.querySelector('[data-proposal] svg'));
                await h.shot(page, 'scan-06-review-slider');
                await page.click('[data-k="cmpside"]');
                // a switch left unticked is not applied
                await page.click('label:has([data-key="events"])');
                assert.deepEqual(await page.evaluate(() => [...window.__calendarScan.proposal().features.map((f) => f.key)]), ['qr', 'events']);
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
                await h.shot(page, 'scan-07-pictures');
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
                await h.shot(page, 'scan-08-applied');
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
                await h.shot(page, 'scan-09-step2-scanned');
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
                await h.shot(page, 'scan-10-restore-confirm');
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
                await startScan(await picture('stranka-2.png'));
                await waitPanel('review', 60000);
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

            await check('C5 the list card carries the pill; it opens step 2 on the proposal; „Zahodit“ throws it away', async () => {
                await h.gotoStep(page, 2);
                await page.waitForSelector('[data-cal-scan-card] [data-cal-action="scan-open"]');
                await startScan(await picture('stranka-3.png'));
                await waitPanel('review', 60000);
                await h.gotoList(page);
                await page.waitForSelector(`.cal-card[data-calendar-id="${calendarId}"] .cal-scan-pill[data-cal-scan-pill="ready"]`, { timeout: 15000 });
                assert.match(await text(`.cal-card[data-calendar-id="${calendarId}"] .cal-scan-pill`), /Návrh připraven/);
                await h.shot(page, 'scan-11-list-pill');
                await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2' }), page.click(`.cal-card[data-calendar-id="${calendarId}"] .cal-scan-pill`)]);
                await h.waitReady(page);
                await page.waitForSelector('.cal-ws[data-cal-shown-step="2"]');
                await waitPanel('review');
                assert.doesNotMatch(page.url(), /sken=/);
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
                await h.shot(page, 'scan-12-stopped');
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
                assert.equal(accepted.length, 2);
                assert.ok(accepted.every((row) => /Rozvržení použito/.test(row.text)));
                assert.ok(rows.filter((row) => row.href).every((row) => new RegExp(`/calendar/${calendarId}\\?sken=`).test(row.href)));
                assert.ok(await page.$('.fbar a.chip.on[href*="filter=calendars"]'));
                await h.shot(page, 'scan-13-history');
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
