/*
 * Every way a scan can go other than well (ux.md S3, S5, §2.3, §11): cancel while waiting and
 * while running, the service not working, a pre-check stop with „Zkusit přesto“, a refused
 * picture, a failure with „Zkusit znovu“ until the picture is refused, leaving mid-run and
 * coming back, „Zahodit“.
 */
const assert = require('assert/strict');

module.exports = {
    name: 'states',
    // a stopped / failed scan has no normalised copy: the page falls back from one picture to the other
    // … and the retry of a picture that failed twice is refused with 409 `no_retries_left`
    allowed: [/\/image\/(working|original).*404|status of 404/, /status of 409 .*\/retry$/],
    async run({ browser, page, h, scanner, check }) {
        const status = () => h.scan(page, (app) => app.scan && app.scan.status);
        const card = () => h.text(page, '.as-status');
        const answer = async (confirm) => {
            await page.waitForSelector('dialog[open]');
            await page.click(confirm ? 'dialog[open] [data-confirm]' : 'dialog[open] [data-close][data-autofocus]');
            await page.waitForFunction(() => !document.querySelector('dialog'));
        };

        await check('waiting on the service: no position, no estimate, the honest sentence', async () => {
            scanner.options.paused = true;
            await h.sleep(1500); // a call of the fake scanner that is on its way would count as "seen"
            // The scanner has not called for a minute → the app says so (derived, never stored).
            h.psql("UPDATE ai_scan_runtime SET scanner_seen_at = now() - interval '5 minutes'");
            const file = await h.poster(browser, 'ceka.png');
            await page.goto(h.urls.scanNew(), { waitUntil: 'networkidle2' });
            await h.choose(page, file);
            await h.waitView(page, 'chosen');
            await page.waitForFunction(() => /AI sken je dočasně nedostupný\. Víme o tom\. Zkuste to později\./.test(document.querySelector('.as-side').textContent), { timeout: 10000 });
            assert.equal(await page.$eval('[data-k="start"]', (el) => el.disabled), true);
            h.psql('UPDATE ai_scan_runtime SET scanner_seen_at = now()');
        });

        await check('cancel while waiting: not counted, back on S2 with the picture', async () => {
            const file = await h.poster(browser, 'zrusit.png');
            await h.start(page, file);
            await h.waitView(page, 'wait');
            assert.match(await card(), /Kontrolujeme obrázek…|Čeká ve frontě|Sken čeká/);
            assert.match(await page.title(), /^(Kontrola…|Ve frontě) — zrusit\.png/);
            await h.press(page, 'cancel');
            await page.waitForSelector('dialog[open]');
            assert.equal(await page.$eval('dialog[open]', (el) => el.getAttribute('role')), 'alertdialog');
            assert.match(await h.text(page, 'dialog[open]'), /Zrušit sken\? .*Sken se zastaví a nezapočítá se\. Obrázek u nás zůstane 30 dní, můžete ho použít znovu\..*Pokračovat ve skenu.*Zrušit sken/);
            await answer(false);
            assert.equal(await page.evaluate(() => document.activeElement.dataset.k), 'cancel', 'focus returns to the button');
            await h.press(page, 'cancel');
            await answer(true);
            await h.waitToast(page, 'Sken zrušen');
            await h.waitView(page, 'chosen');
            assert.match(await h.text(page, '.as-file-b'), /zrusit\.png/);
            assert.ok(page.url().endsWith('/z-obrazku'));
            assert.equal(h.psql(`SELECT COALESCE(SUM(delta), 0) FROM ai_credit_ledger WHERE project_id = '${h.config.projectId}' AND scan_id IN (SELECT id FROM ai_scan WHERE status = 'cancelled')`), '0', 'reserve and refund cancel out');
        });

        await check('queued: position text, then cancel while running says it counts', async () => {
            scanner.options.paused = false;
            scanner.options.beatMs = 2500;
            const file = await h.poster(browser, 'bezi.png');
            await h.start(page, file);
            await page.waitForFunction(() => window.__aiScan.scan.status === 'running', { timeout: 30000 });
            await h.press(page, 'cancel');
            await page.waitForSelector('dialog[open]');
            assert.match(await h.text(page, 'dialog[open]'), /Sken už běží\. Zastavíme ho, ale započítá se\. Obrázek u nás zůstane 30 dní\./);
            await answer(true);
            await h.waitToast(page, 'Sken zrušen');
            await h.waitView(page, 'chosen');
            scanner.options.beatMs = 400;
            await h.sleep(3500); // the fake run notices the cancel at its next heartbeat
        });

        await check('pre-check stop is not a failure: neutral card, „Zkusit přesto“ once, straight to the queue', async () => {
            scanner.options.scenario = 'stop';
            const file = await h.poster(browser, 'fotka-tisku.png');
            const id = await h.start(page, file);
            await h.waitView(page, 'fail', 30000);
            const text = await card();
            assert.match(text, /Potřebuje jiný obrázek Tohle vypadá jako fotka tisku Fotka z ruky zkreslí rozměry i barvy/);
            assert.match(text, /Sken se nezapočítal\. Kontrola obrázku — zastaveno/);
            assert.match(text, /Zkusit přesto spotřebuje 1 sken.*Jde to jen jednou\./);
            assert.equal(await page.$$eval('.as-status .rose, .as-status .fail', (n) => n.length), 0, 'no rose on a stop');
            assert.ok(await h.exists(page, '.as-result-ico.neutral'));
            // the picture stays beside the card (the scan was started in this page — no reload)
            await page.waitForFunction(() => { const img = document.querySelector('.as-pic-one img'); return img && img.complete && img.naturalWidth > 0; }, { timeout: 10000 });
            await h.press(page, 'anyway');
            await h.waitView(page, 'wait');
            await h.waitView(page, 'review', 40000);
            assert.equal(h.psql(`SELECT try FROM ai_scan WHERE id = '${id}'`), '2');
            // The page of a scan that is finished with has no „Zkusit přesto“ any more.
            scanner.options.scenario = 'happy';
        });

        await check('discard a proposal: confirm dialog, toast, back to the upload', async () => {
            await h.press(page, 'disc');
            await page.waitForSelector('dialog[open]');
            assert.match(await h.text(page, 'dialog[open]'), /Zahodit návrh\? .*Návrh se smaže a sken zůstane započítaný\. Obrázky, které jste k němu nahráli, přesuneme do Koše v galerii\./);
            await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2' }), page.click('dialog[open] [data-confirm]')]);
            await h.waitToast(page, 'Návrh zahozen');
            await h.waitView(page, 'upload');
        });

        await check('refused by the provider: its own card, „Napsat nám“, no retry', async () => {
            scanner.options.scenario = 'refuse';
            const file = await h.poster(browser, 'odmitnuto.png');
            await h.start(page, file);
            await h.waitView(page, 'fail', 40000);
            const text = await card();
            assert.match(text, /Odmítnuto Tento obrázek AI odmítla zpracovat/);
            assert.ok(await h.exists(page, '.as-status a[href^="mailto:"]'));
            assert.equal(await h.exists(page, '[data-k="retry"]'), false);
            assert.match(await page.title(), /^Tento obrázek AI odmítla zpracovat/);
        });

        let failedId = null;
        await check('failure: the failing stage has the rose ✖, leaving and returning keeps the card', async () => {
            scanner.options.scenario = 'fail';
            const file = await h.poster(browser, 'nepovedlo.png');
            failedId = await h.start(page, file);
            await page.waitForFunction(() => window.__aiScan.scan.status === 'running', { timeout: 30000 });
            // leave mid-run and come back: the scan went on without the page
            await page.goto(h.urls.templates(), { waitUntil: 'networkidle2' });
            await h.sleep(1500);
            await page.goto(h.urls.scan(failedId), { waitUntil: 'networkidle2' });
            await page.waitForFunction(() => ['wait', 'fail'].includes(document.querySelector('.as-page').dataset.view));
            await h.waitView(page, 'fail', 40000);
            const text = await card();
            assert.match(text, /Nepodařilo se Návrh se nepodařilo sestavit Obrázek jsme přečetli, ale návrh se nepodařilo poskládat/);
            assert.match(text, /Zkusit znovu.*Vybrat jiný obrázek.*Zahodit/);
            assert.ok(await h.exists(page, '.as-stages .fail .i-x-circle'));
        });

        await check('„Zkusit znovu“ is bounded: after the picture failed twice the button is gone', async () => {
            await h.press(page, 'retry');
            await h.waitView(page, 'wait');
            await h.waitView(page, 'fail', 40000);
            assert.match(await card(), /Nepodařilo se · 2\. pokus/);
            // A picture that failed twice is refused — by the retry, or already on the card.
            if (await h.exists(page, '[data-k="retry"]')) {
                await h.press(page, 'retry');
                await page.waitForFunction(() => !document.querySelector('[data-k="retry"]') || document.querySelector('.as-page').dataset.view === 'wait', { timeout: 15000 });
                if ((await h.view(page)) === 'wait') await h.waitView(page, 'fail', 40000);
            }
            await page.waitForFunction(() => /Tento obrázek se nám nedaří zpracovat\. Zkuste jiný\./.test(document.querySelector('.as-status').textContent), { timeout: 15000 });
            assert.equal(await h.exists(page, '[data-k="retry"]'), false);
            assert.match(await card(), /Vybrat jiný obrázek/);
            scanner.options.scenario = 'happy';
        });

        await check('„Zahodit“ on the result card: confirm, toast on the template list, gone from the strip', async () => {
            await h.press(page, 'discard');
            await page.waitForSelector('dialog[open]');
            await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2' }), page.click('dialog[open] [data-confirm]')]);
            assert.ok(page.url().endsWith('/templates'));
            await h.waitToast(page, 'Sken zahozen');
            assert.equal(h.psql(`SELECT status FROM ai_scan WHERE id = '${failedId}'`), 'discarded');
            await page.goto(h.urls.scan(failedId), { waitUntil: 'networkidle2' });
            await h.waitView(page, 'gone');
            assert.match(await card(), /Zahozeno Sken byl zahozen/);
        });

        await check('phone: status card first, the stage list folded under „Průběh“, no sideways scroll', async () => {
            await page.setViewport({ width: 390, height: 844 });
            scanner.options.beatMs = 1500;
            const file = await h.poster(browser, 'telefon.png');
            await h.start(page, file);
            await page.waitForFunction(() => window.__aiScan.scan.status === 'running', { timeout: 30000 });
            const order = await page.evaluate(() => {
                const status = document.querySelector('.as-status').getBoundingClientRect().top;
                const pics = document.querySelector('.as-pics').getBoundingClientRect().top;
                return { statusFirst: status < pics, open: document.querySelector('.as-prubeh').open, overflow: document.documentElement.scrollWidth - window.innerWidth, now: !!document.querySelector('.as-nowline') && getComputedStyle(document.querySelector('.as-nowline')).display !== 'none' };
            });
            assert.deepEqual(order, { statusFirst: true, open: false, overflow: 0, now: true });
            await h.waitView(page, 'review', 60000);
            const review = await page.evaluate(() => {
                const top = (selector) => document.querySelector(selector).getBoundingClientRect().top + window.scrollY;
                return { order: top('.as-sumcard') < top('.as-compare') && top('.as-compare') < top('.as-panel-card') && top('.as-panel-card') < top('.as-foot'), sticky: getComputedStyle(document.querySelector('.as-foot')).position, overflow: document.documentElement.scrollWidth - window.innerWidth, touch: getComputedStyle(document.querySelector('.as-compare')).touchAction, crops: document.querySelectorAll('.as-it .as-crop').length > 0 };
            });
            assert.deepEqual(review, { order: true, sticky: 'static', overflow: 0, touch: 'pan-y', crops: true });
            await page.setViewport({ width: 1440, height: 900 });
        });

        await check('reduced motion: nothing animates on the waiting and review screens', async () => {
            await page.emulateMediaFeatures([{ name: 'prefers-reduced-motion', value: 'reduce' }]);
            await page.reload({ waitUntil: 'networkidle2' });
            await h.waitView(page, 'review');
            const moving = await page.evaluate(() => [...document.querySelectorAll('.as-page *')].filter((el) => {
                const names = [getComputedStyle(el).animationName, getComputedStyle(el, '::after').animationName, getComputedStyle(el, '::before').animationName];
                return names.some((name) => name && name !== 'none');
            }).length);
            assert.equal(moving, 0);
            await page.emulateMediaFeatures([{ name: 'prefers-reduced-motion', value: 'no-preference' }]);
        });
    },
};
