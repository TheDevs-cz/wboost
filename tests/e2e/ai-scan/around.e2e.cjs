/*
 * What surrounds the wizard (ux.md T1, T4, T5, §7, §8, §9.1): the locked card and a real access
 * request, silence for a user without scans, the enabled card, the strip, the shell's ring →
 * toast → count, history, and the export question for a template with an unfilled picture.
 */
const assert = require('assert/strict');

// the wizard's JSON endpoints — not the stylesheet and modules under /assets/ that every page links
const aiRequests = (page) => page.requests.filter((r) => !/ \/assets\//.test(r) && /\/ai-scans?\b|\/ai-scan\//.test(r));

module.exports = {
    name: 'around',
    // a scan under the pre-check has no normalised copy yet: its card falls back from one picture to the other
    // … and the export's first answer IS a 409 `unfilled_pictures` (the question)
    // No 404 is allowed: the lists ask only for pictures that exist (`row.picture`).
    allowed: [/status of 409 .*\/template-variant\/[0-9a-f-]+\/(download|publish)/],
    async run({ browser, page, h, scanner, check }) {
        const cfg = h.config;

        await check('a project without AI scan: the locked card, and not one AI request from the page', async () => {
            await h.login(page, cfg.lockedEmail);
            page.requests.length = 0;
            await page.goto(h.urls.templates(cfg.lockedProjectId), { waitUntil: 'networkidle2' });
            await h.sleep(11000); // longer than the 10 s poll
            assert.deepEqual(aiRequests(page), [], 'no status, list or badge request');
            assert.equal(await h.exists(page, '.as-strip'), false);
            assert.equal(await page.$$eval('[data-ai-scan-signal]:not([hidden])', (n) => n.length), 0, 'no badge');
            await h.openNew(page);
            await page.waitForSelector('#templateNewModal.show .tp-type.prem');
            assert.equal(await page.$eval('.tp-type.prem', (el) => el.dataset.aiScanState), 'locked');
            assert.match(await h.text(page, '.tp-type.prem'), /Šablona z obrázku Premium .*texty, tvary, barvy a místa pro obrázky\. Požádat o přístup/);
            assert.equal(await page.$$eval('#templateNewModal .tp-type', (n) => n.length >= 2), true, 'the other cards are there');
            // the new page is closed to the user as well
            const response = await page.goto(h.urls.scanNew(cfg.lockedProjectId), { waitUntil: 'networkidle2' });
            assert.ok(response.url().endsWith('/templates'));
        });

        await check('„Požádat o přístup“: one dialog (never a modal over a modal), Escape, a real request, the card flips', async () => {
            await h.openNew(page);
            await page.waitForSelector('#templateNewModal.show .tp-type.prem .tp-type-go');
            await h.sleep(400);
            await page.click('.tp-type.prem .tp-type-go');
            await page.waitForSelector('dialog[open].as-dlg-m');
            await page.waitForFunction(() => !document.querySelector('#templateNewModal.show'));
            assert.match(await h.text(page, 'dialog[open]'), /AI sken Premium Hotová grafika → upravitelná šablona za pár minut\..*AI sken je placená funkce, kterou zapínáme pro každý projekt zvlášť.*K čemu ho chcete použít\? nepovinné.*Odešleme: vaše jméno a e‑mail, projekt „E2E AI sken — zamčeno“ a tuto zprávu\. Dostane ji tým WBoost\./);
            assert.equal(await page.evaluate(() => document.activeElement.tagName), 'TEXTAREA');
            await page.keyboard.press('Escape');
            await page.waitForFunction(() => !document.querySelector('dialog'));
            assert.equal(h.psql(`SELECT count(*) FROM ai_access_request WHERE project_id = '${cfg.lockedProjectId}'`), '0');

            await h.openNew(page);
            await page.waitForSelector('#templateNewModal.show .tp-type.prem .tp-type-go');
            await h.sleep(400);
            await page.click('.tp-type.prem .tp-type-go');
            await page.waitForSelector('dialog[open] [data-message]');
            await page.type('dialog[open] [data-message]', 'Chceme převést loňské plakáty na šablony (e2e).');
            await page.click('dialog[open] [data-send]');
            await page.waitForFunction(() => /Žádost odeslána/.test(document.querySelector('dialog[open]').textContent), { timeout: 15000 });
            assert.match(await h.text(page, 'dialog[open]'), /Ozveme se vám na e2e-aiscan-locked@test\.cz/);
            await page.click('dialog[open] [data-done]');
            assert.equal(h.psql(`SELECT status || ':' || origin FROM ai_access_request WHERE project_id = '${cfg.lockedProjectId}'`), 'open:templates');
            await page.reload({ waitUntil: 'networkidle2' });
            await h.openNew(page);
            await page.waitForSelector('#templateNewModal.show .tp-type.prem');
            assert.equal(await page.$eval('.tp-type.prem', (el) => el.dataset.aiScanState), 'request_sent');
            assert.match(await h.text(page, '.tp-type.prem'), /Odesláno \d+\. \d+\. \d{4}\. Dáme vám vědět\..*Žádost odeslána/);
            assert.equal(await page.$eval('.tp-type.prem .tp-type-go', (el) => el.disabled), true);
        });

        await check('the request reached the administrators’ mailbox', async () => {
            await h.sleep(1500);
            const mails = await h.mails();
            assert.ok(mails !== null, `the mail catcher answers on ${cfg.mailer}`);
            assert.ok(mails.some((m) => /E2E AI sken — zamčeno/.test(m.raw) || /AI sken/.test(m.subject)), 'a mail about the request is in the catcher');
        });

        let scanId = null;
        let variantId = null;
        await check('entitled editor: the enabled card leads to the upload; a scan in progress shows in the strip and the sidebar', async () => {
            await h.login(page, cfg.email);
            await page.goto(h.urls.templates(), { waitUntil: 'networkidle2' });
            await h.openNew(page);
            await page.waitForSelector('#templateNewModal.show .tp-type.prem');
            assert.equal(await page.$eval('.tp-type.prem', (el) => el.dataset.aiScanState), 'enabled');
            assert.ok((await page.$eval('.tp-type.prem a.tp-type-go', (el) => el.getAttribute('href'))).endsWith('/sablony/z-obrazku'));

            scanner.options.paused = true;
            scanId = await h.start(page, await h.poster(browser, 'letak.png'));
            page.requests.length = 0;
            await page.goto(h.urls.templates(), { waitUntil: 'networkidle2' });
            await page.waitForSelector(`.as-strip .as-scan[data-scan="${scanId}"]`);
            assert.match(await h.text(page, '.as-strip-h'), /Skeny 1.*Historie skenů/);
            assert.match(await h.text(page, `.as-scan[data-scan="${scanId}"]`), /letak\.png.*(Kontrola obrázku|Ve frontě)/);
            assert.ok(await h.exists(page, '[data-ai-scan-nav] .as-ring'), 'the peach ring on the module item');
        });

        await check('elsewhere in the app: the page polls every 10 s, then ring → toast with an action → count', async () => {
            scanner.options.paused = false;
            scanner.options.beatMs = 300;
            await h.waitToast(page, 'Návrh šablony „.+“ je připravený ke kontrole', 60000);
            assert.ok(page.requests.some((r) => /GET \/ai-scans\/mine/.test(r)), 'the shell polled');
            assert.match(await page.$eval('.toast-n.as-toast', (el) => el.querySelector('a, button').textContent), /Zkontrolovat/);
            await page.waitForSelector('[data-ai-scan-nav] .count.as-pch');
            assert.equal(await h.exists(page, '[data-ai-scan-nav] .as-ring'), false);
            await page.waitForFunction((id) => /Připraveno ke kontrole/.test(document.querySelector(`.as-scan[data-scan="${id}"]`).textContent), { timeout: 15000 }, scanId);
            assert.ok(page.requests.some((r) => /GET \/project\/.+\/ai-scans\?/.test(r)), 'the strip polled'); // its own 10 s timer
            // the toast's action (it stays 10 s) opens the scan; seeing it clears the badge
            await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2' }), page.click('.toast-n.as-toast a')]);
            await h.waitView(page, 'review');
            await page.waitForFunction(() => !document.querySelector('[data-ai-scan-nav] .count.as-pch'), { timeout: 15000 });
        });

        await check('nothing in progress: the list shows the waiting proposal and no page polls', async () => {
            await page.goto(h.urls.templates(), { waitUntil: 'networkidle2' });
            await page.waitForSelector(`.as-strip .as-scan[data-scan="${scanId}"]`);
            await h.sleep(1500);
            page.requests.length = 0;
            await h.sleep(11500); // longer than the 10 s poll
            assert.deepEqual(aiRequests(page), []);
            await page.goto(h.urls.scan(scanId), { waitUntil: 'networkidle2' });
            await h.waitView(page, 'review');
        });

        await check('create with an empty „Součást šablony“ place: the label says so; the card gets „K doplnění: 1“', async () => {
            await h.press(page, 'next');
            await h.waitView(page, 'pictures');
            assert.match(await h.text(page, '[data-k="create"]'), /Vytvořit šablonu · 1 obrázek doplníte později/);
            await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 60000 }), h.press(page, 'create')]);
            variantId = /template-variant\/([0-9a-f-]{36})\/editor/.exec(page.url())[1];
            await page.goto(h.urls.templates(), { waitUntil: 'networkidle2' });
            const cards = await page.$eval('.tp-page', (el) => el.innerText.replace(/\s+/g, ' '));
            assert.match(cards, /Ze skenu/);
            assert.match(cards, /K doplnění: 1/);
            assert.equal(await h.exists(page, `.as-scan[data-scan="${scanId}"]`), false, 'an accepted scan has no card');
        });

        await check('„Historie skenů“ lists the scan with its pill and its menu', async () => {
            await page.goto(h.urls.history(), { waitUntil: 'networkidle2' });
            assert.match(await h.text(page, '.head'), /Historie skenů/);
            assert.match(await h.text(page, '.as-hrow'), /Šablona vytvořena/);
            assert.ok(await h.exists(page, `.as-hrow a[href$="/z-obrazku/${scanId}"]`));
        });

        await check('export asks first: dialog with the count, Escape keeps the page, „Exportovat přesto“ sends allowUnfilled', async () => {
            await page.goto(`${cfg.base}/template-variant/${variantId}/export`, { waitUntil: 'networkidle2' });
            const button = 'form [data-variant-fill-overlay-target="exportButton"].btn-primary';
            await page.waitForSelector(button);
            await page.waitForFunction((selector) => !document.querySelector(selector).disabled, { timeout: 30000 }, button);
            await page.click(button);
            await page.waitForSelector('dialog[open]', { timeout: 30000 });
            assert.match(await h.text(page, 'dialog[open]'), /Šablona má 1 nedoplněný obrázek V exportu bude na jeho místě šedý zástupný obrázek\. Doplňte ho v editoru, nebo exportujte přesto\..*Exportovat přesto.*Otevřít editor/);
            assert.ok((await page.$eval('dialog[open] a.btn-primary', (el) => el.getAttribute('href'))).includes(`/template-variant/${variantId}/editor`));
            await page.keyboard.press('Escape');
            await page.waitForFunction(() => !document.querySelector('dialog'));
            await page.click(button);
            await page.waitForSelector('dialog[open] [data-export-anyway]', { timeout: 30000 });
            const sent = new Promise((resolve) => page.on('request', (request) => { if (request.method() === 'POST' && /allowUnfilled/.test(request.postData() || '')) resolve(request.url()); }));
            await page.click('dialog[open] [data-export-anyway]');
            const url = await Promise.race([sent, h.sleep(20000).then(() => null)]);
            assert.ok(url && url.includes(variantId), 'the form went again with allowUnfilled=1');
            await h.sleep(500);
            assert.equal(await page.evaluate(() => window.dispatchEvent(new Event('beforeunload', { cancelable: true }))), true, 'no unsaved-changes prompt after the confirmed export');
        });

        // The publish dialog needs a linked Facebook account and a social format. Neither exists for
        // a scanned template in dev, so both are stubbed AT THE EDGES only: a row in `social_account`,
        // the preset marker on the variant, and Meta's two answers (the list of pages, the publish
        // itself). The dialog, its question and the app's own 409 are the real thing.
        await check('publish asks first too: the question over the publish dialog, „Exportovat přesto“ repeats it with allowUnfilled', async () => {
            const user = h.psql(`SELECT id FROM "user" WHERE email = ${h.quote(cfg.email)}`);
            h.psql(`
                INSERT INTO social_account (id, user_id, provider, provider_user_id, access_token, scopes, display_name, needs_reconnect, connected_at)
                VALUES ('0e2ea15c-0000-7000-8000-00000000fb01', '${user}', 'facebook', 'e2e-ai-scan-stub', 'e2e-stub-not-a-token', '[]', 'E2E stub', false, now())
                ON CONFLICT (id) DO NOTHING;
                UPDATE template_variant SET dimension_preset = '4:5' WHERE id = '${variantId}';
            `);
            const posts = [];
            const onRequest = (request) => {
                const url = request.url();
                if (/\/social\/facebook\/destinations|facebook.*destinations/.test(url) && request.method() === 'GET') {
                    request.respond({ status: 200, contentType: 'application/json', body: JSON.stringify({ connected: true, pages: [{ id: 'e2e-page', name: 'E2E stránka', instagram: null }] }) }).catch(() => null);
                } else if (request.method() === 'POST' && /\/publish$/.test(url)) {
                    const confirmed = /name="allowUnfilled"\s+1|allowUnfilled/.test(request.postData() || '');
                    posts.push(confirmed);
                    // The first POST is the app's own answer (409 unfilled_pictures); the confirmed one would call Meta.
                    if (confirmed) request.respond({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true }) }).catch(() => null);
                    else request.continue().catch(() => null);
                } else request.continue().catch(() => null);
            };
            try {
                await page.goto(`${cfg.base}/template-variant/${variantId}/export`, { waitUntil: 'domcontentloaded' });
                await page.waitForSelector('.fi-share.dropdown-toggle', { timeout: 60000 });
                // Meta is answered here from now on; everything else goes to the app untouched.
                await page.setRequestInterception(true);
                page.on('request', onRequest);
                await page.click('.fi-share.dropdown-toggle');
                await page.click('[data-action="variant-publish#open"][data-variant-publish-platform-param="facebook"]');
                await page.waitForSelector('[data-variant-publish-target="modal"].is-open');
                await page.waitForSelector('[data-variant-publish-target="destinations"] input[type="radio"]:checked', { timeout: 15000 });
                await page.click('[data-variant-publish-target="submit"]');
                await page.waitForSelector('dialog[open]', { timeout: 30000 });
                assert.match(await h.text(page, 'dialog[open]'), /Šablona má 1 nedoplněný obrázek .*Exportovat přesto.*Otevřít editor/);
                assert.deepEqual(posts, [false], 'the first publish went without the confirmation and the app refused it');
                await page.keyboard.press('Escape');
                await page.waitForFunction(() => !document.querySelector('dialog'));
                assert.ok(await h.exists(page, '[data-variant-publish-target="modal"].is-open'), 'Escape closes the question, not the publish dialog');
                assert.deepEqual(posts, [false], 'nothing was published');
                await page.click('[data-variant-publish-target="submit"]');
                await page.waitForSelector('dialog[open] [data-export-anyway]', { timeout: 30000 });
                await page.click('dialog[open] [data-export-anyway]');
                await page.waitForFunction(() => /Hotovo — příspěvek byl publikován/.test(document.querySelector('[data-variant-publish-target="status"]').textContent), { timeout: 30000 });
                assert.deepEqual(posts, [false, false, true], 'the confirmed publish carries allowUnfilled=1');
            } finally {
                page.off('request', onRequest);
                await page.setRequestInterception(false).catch(() => null);
                h.psql(`
                    DELETE FROM social_account WHERE id = '0e2ea15c-0000-7000-8000-00000000fb01';
                    UPDATE template_variant SET dimension_preset = NULL WHERE id = '${variantId}';
                `);
            }
        });
    },
};
