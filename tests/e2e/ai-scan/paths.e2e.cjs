/*
 * The paths a walk with `uploadFile()` skips (docs/architecture/ai-scan.md § "UI", "Traps"): every
 * hidden file input is opened by its button through the real file chooser; dialogs and the `⋯` menu
 * by keyboard, with the focus back on the opener; "Oříznout, aby místo vyplnil"; the gallery picker;
 * and the export question on the group and the document fill pages (ux.md T4) — answered
 * „Exportovat přesto“, the ZIP and the PDF are really rendered and saved by the browser.
 */
const fs = require('fs');
const path = require('path');
const assert = require('assert/strict');

module.exports = {
    name: 'paths',
    // the export's first answer IS a 409 `unfilled_pictures` (the question)
    allowed: [/status of 409 .*\/template-(variant|group|document)\//],
    async run({ browser, page, h, scanner, check }) {
        const cfg = h.config;
        const proposal = () => h.scan(page, (app) => app.proposal);
        const focusK = () => page.evaluate(() => (document.activeElement && document.activeElement.dataset.k) || null);
        /** Clicks a control and answers the file chooser it opens. */
        const pickThrough = async (selector, files) => {
            const [chooser] = await Promise.all([page.waitForFileChooser({ timeout: 10000 }), page.click(selector)]);
            const multiple = chooser.isMultiple();
            await chooser.accept(files);
            return multiple;
        };
        const closed = () => page.waitForFunction(() => !document.querySelector('dialog'));

        await check('S1 / S2: the drop zone, „Vybrat jiný obrázek“ and „Přidat“ open the page’s own file inputs', async () => {
            await page.goto(h.urls.scanNew(), { waitUntil: 'networkidle2' });
            await h.waitView(page, 'upload');
            assert.equal(await pickThrough('[data-k="drop"]', [await h.poster(browser, 'prvni.png')]), false, 'one picture only');
            await h.waitView(page, 'chosen');
            assert.match(await h.text(page, '.as-file-b'), /prvni\.png/);
            await pickThrough('[data-k="other"]', [await h.poster(browser, 'druhy.png')]);
            await page.waitForFunction(() => /druhy\.png/.test(document.querySelector('.as-file-b').textContent));
            assert.equal(await pickThrough('[data-k="fontadd"]', [h.FONT]), true, 'several font files at once');
            await h.waitToast(page, 'Písmo přidáno|tento řez už v projektu je');
        });

        await check('custom width: an invalid value blocks the start with its hint, a valid one shows the height', async () => {
            await page.select('[data-k="fchoice"]', 'custom');
            await page.waitForSelector('[data-k="fcustom"]');
            assert.equal(await focusK(), 'fcustom');
            await page.type('[data-k="fcustom"]', '99999');
            await page.keyboard.press('Tab');
            await page.waitForFunction(() => /Zadejte šířku v pixelech — delší strana nejvýše 5000 px\./.test(document.querySelector('.as-side').textContent));
            assert.equal(await page.$eval('[data-k="start"]', (el) => el.disabled), true);
            await h.fill(page, '[data-k="fcustom"]', '1080');
            await page.keyboard.press('Tab');
            await page.waitForFunction(() => /× 1440 px/.test(document.querySelector('.as-side').textContent));
            await page.select('[data-k="fchoice"]', 'source');
        });

        await check('review: „Naskenovat znovu“ and „Zahodit“ are modal, keep Tab inside, Escape gives the focus back', async () => {
            scanner.options.beatMs = 300;
            for (const k of ['consent0', 'consent1']) if (await h.exists(page, `[data-k="${k}"]`)) await h.press(page, k);
            await page.waitForFunction(() => !document.querySelector('[data-k="start"]').disabled, { timeout: 8000 });
            await h.press(page, 'start');
            await h.waitView(page, 'review', 90000);
            for (const [k, title] of [['resc', 'Naskenovat znovu'], ['disc', 'Zahodit návrh?']]) {
                await page.focus(`[data-k="${k}"]`);
                await page.keyboard.press('Enter');
                await page.waitForSelector('dialog[open]');
                assert.match(await h.text(page, 'dialog[open] h2'), new RegExp(title.replace('?', '\\?')));
                assert.equal(await page.evaluate(() => document.querySelector('dialog[open]').contains(document.activeElement)), true, 'focus starts inside');
                for (let i = 0; i < 8; i += 1) {
                    await page.keyboard.press('Tab');
                    assert.equal(await page.evaluate(() => document.querySelector('dialog[open]').contains(document.activeElement) || document.activeElement === document.body), true, 'Tab stays inside');
                }
                await page.keyboard.press('Escape');
                await closed();
                assert.equal(await focusK(), k, 'focus returns to the opener');
            }
            assert.equal(await h.view(page), 'review', 'nothing was discarded');
        });

        await check('review: „Nahrát správné písmo“ opens the font input; the uploaded face is chosen for the group', async () => {
            const before = (await proposal()).toDecide;
            // another face than the one the project has (h.FONT), so choosing it is a real change
            assert.equal(await pickThrough('[data-act="uploadFont"]', [path.join(path.dirname(h.FONT), 'Inter-VariableSubset.ttf')]), false);
            await h.settled(page);
            await page.waitForFunction((n) => window.__aiScan.proposal.toDecide === n - 1, { timeout: 20000 }, before);
            assert.match(await h.text(page, '.as-it.decided'), /Písmo nadpisů: .+ — texty jsme přepočítali/);
            // The same upload from inside the picker: „Přidat písmo…“ is its last row. A face
            // the project already has is not stored twice — it is chosen.
            await page.click('.as-it.decided [data-act="undo"]');
            await h.settled(page);
            await page.click('[data-act="fontMenu"]');
            await page.waitForSelector('.as-fontmenu');
            assert.equal(await pickThrough('.as-fontmenu [data-act="uploadFont"]', [path.join(path.dirname(h.FONT), 'Inter-VariableSubset.ttf')]), false);
            await h.waitToast(page, 'tento řez už v projektu je|Písmo přidáno');
            await h.settled(page);
            await page.waitForFunction((n) => window.__aiScan.proposal.toDecide === n - 1, { timeout: 20000 }, before);
            assert.equal(await h.exists(page, '.as-fontmenu'), false, 'the picker closed');
        });

        let fotoId = null;
        let logoId = null;
        await check('pictures: the multi zone opens a multi-file chooser; a wrong ratio is fitted whole and says so', async () => {
            await h.press(page, 'next');
            await h.waitView(page, 'pictures');
            const places = (await proposal()).placeholders;
            logoId = places[0].id;
            fotoId = places[1].id;
            const logo = await h.picture(browser, 'logo.png', 280, 120);
            const wide = await h.picture(browser, 'siroka.png', 1200, 300, '#0b6e4f');
            assert.equal(await pickThrough('[data-k="bulk"]', [logo, wide]), true);
            await h.waitToast(page, '2 obrázky přiřazeny');
            await h.settled(page);
            const foto = (await proposal()).placeholders.find((ph) => ph.id === fotoId);
            assert.equal(foto.fit, 'cover');
            assert.equal(foto.picture.fitApplied, 'contain');
            assert.match(await h.text(page, `[data-region="ph-${fotoId}"]`), /Obrázek má jiný poměr stran než místo — vejde se celý\. Oříznout, aby místo vyplnil/);
        });

        await check('„Oříznout, aby místo vyplnil“: the browser crops the file uploaded in this session and puts it back', async () => {
            await page.click(`[data-k="phcrop${fotoId}"]`);
            await h.settled(page);
            await page.waitForFunction((id) => { const ph = window.__aiScan.proposal.placeholders.find((x) => x.id === id); return ph.picture && ph.picture.fitApplied === 'cover'; }, { timeout: 20000 }, fotoId);
            assert.doesNotMatch(await h.text(page, `[data-region="ph-${fotoId}"]`), /vejde se celý/);
        });

        await check('the `⋯` menu by keyboard: opens with the focus on its first item, Escape returns it to the button', async () => {
            await page.focus(`[data-k="phmenu${fotoId}"]`);
            await page.keyboard.press('Enter');
            await page.waitForSelector('.as-ph-menu .menu');
            assert.equal(await page.evaluate(() => document.activeElement.classList.contains('mi')), true);
            assert.equal(await page.$eval(`[data-k="phmenu${fotoId}"]`, (el) => el.getAttribute('aria-expanded')), 'true');
            await page.keyboard.press('Escape');
            await page.waitForFunction(() => !document.querySelector('.as-ph-menu .menu'));
            assert.equal(await focusK(), `phmenu${fotoId}`);
        });

        await check('one place: „Odebrat obrázek“, then „Nahrát“ opens the single-file input for that place', async () => {
            await page.click(`[data-k="phr${logoId}"]`);
            await h.settled(page);
            assert.equal((await proposal()).placeholders.find((ph) => ph.id === logoId).picture, null);
            assert.equal(await pickThrough(`[data-k="phn${logoId}"]`, [await h.picture(browser, 'logo-2.png', 280, 120, '#d1495b')]), false);
            await h.settled(page);
            await page.waitForFunction((id) => Boolean(window.__aiScan.proposal.placeholders.find((x) => x.id === id).picture), { timeout: 20000 }, logoId);
        });

        await check('gallery picker: the project’s gallery opens in its modal, picking a picture fills the place', async () => {
            await page.click(`[data-k="phr${logoId}"]`);
            await h.settled(page);
            await page.click(`[data-k="phg${logoId}"]`);
            await page.waitForSelector('#aiScanGalleryModal.show', { timeout: 15000 });
            // the pictures uploaded above live in the folder „AI sken / {název}“ — walk down to a tile
            const tile = '#aiScanGalleryModal [data-image-gallery-id-param]';
            for (let depth = 0; depth < 3 && !(await h.exists(page, tile)); depth += 1) {
                const folder = await page.$('#aiScanGalleryModal [data-live-action-param="openDirectory"]:not(.gal-trash-b):not(.gal-crumb)');
                if (!folder) break;
                await folder.click();
                await h.sleep(1500);
            }
            await page.waitForSelector(tile, { timeout: 15000 });
            const picked = await page.$eval(tile, (el) => el.getAttribute('data-image-gallery-id-param'));
            await page.click(tile);
            await page.waitForFunction(() => !document.querySelector('#aiScanGalleryModal.show'), { timeout: 15000 });
            await h.settled(page);
            await page.waitForFunction((id) => Boolean(window.__aiScan.proposal.placeholders.find((x) => x.id === id).picture), { timeout: 20000 }, logoId);
            // (whether it counts as „uploaded for this scan“ depends on which folder the walk met first)
            assert.equal((await proposal()).placeholders.find((ph) => ph.id === logoId).picture.fileId, picked, 'the picked gallery file fills the place');
        });

        let templateId = null;
        let variantId = null;
        await check('a template with one unfilled „Součást šablony“ place is created', async () => {
            await page.click(`[data-k="phr${fotoId}"]`);
            await h.settled(page);
            await page.click(`[data-k="ph${fotoId}fixed"]`);
            await h.settled(page);
            assert.match(await h.text(page, '[data-k="create"]'), /Vytvořit šablonu · 1 obrázek doplníte později/);
            await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 90000 }), h.press(page, 'create')]);
            variantId = /template-variant\/([0-9a-f-]{36})\/editor/.exec(page.url())[1];
            templateId = h.psql(`SELECT template_id FROM template_variant WHERE id = '${variantId}'`);
        });

        let groupId = null;
        let documentId = null;
        await check('(set-up as an administrator) a synchronised group from that template, and a document with it as a page', async () => {
            await h.login(page, cfg.adminEmail);
            await page.goto(`${cfg.base}/project/${cfg.projectId}/add-template-group?sourceVariantId=${variantId}`, { waitUntil: 'networkidle2' });
            await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 90000 }), page.$eval('form[name="template_group_form"]', (form) => form.requestSubmit())]);
            groupId = (/template-group\/([0-9a-f-]{36})/.exec(page.url()) || [])[1];
            assert.ok(groupId, `the group's editor opened (${page.url()})`);

            await page.goto(`${cfg.base}/project/${cfg.projectId}/add-template-document`, { waitUntil: 'networkidle2' });
            await page.type('[name="template_document_form[name]"]', 'E2E dokument');
            await page.select('[name="template_document_form[dimension][unit]"]', 'px');
            for (const [field, value] of [['width', '900'], ['height', '1200']]) await h.fill(page, `[name="template_document_form[dimension][${field}]"]`, value);
            await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 90000 }), page.$eval('form[name="template_document_form"]', (form) => form.requestSubmit())]);
            documentId = (/template-document\/([0-9a-f-]{36})/.exec(page.url()) || [])[1];
            assert.ok(documentId, `the document's page opened (${page.url()})`);
            // the page's own "Připojit stránky" form (it carries the token)
            await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 90000 }), page.evaluate((template) => {
                const form = document.querySelector('form[action$="/attach"]');
                form.querySelector(`input[name="templateIds[]"][value="${template}"]`).checked = true;
                form.requestSubmit();
            }, templateId)]);
            assert.equal(h.psql(`SELECT count(*) FROM template_document_page WHERE document_id = '${documentId}'`), '1');
            await h.login(page, cfg.email);
        });

        /** Export on a fill page: the question, Escape keeps the page, „Exportovat přesto“ repeats the POST with allowUnfilled. */
        const asksFirst = async (url, button, count, magic) => {
            await page.goto(url, { waitUntil: 'domcontentloaded' }); // the fill pages keep rendering previews — never "idle"
            await page.waitForSelector(button, { timeout: 60000 });
            await page.waitForFunction((selector) => !document.querySelector(selector).disabled, { timeout: 60000 }, button);
            await page.click(button);
            await page.waitForSelector('dialog[open]', { timeout: 60000 });
            assert.match(await h.text(page, 'dialog[open]'), new RegExp(`Šablona má ${count}.*Exportovat přesto.*Otevřít editor`));
            assert.equal(await page.evaluate(() => document.activeElement.textContent.trim()), 'Otevřít editor', 'the safe answer has the focus');
            await page.keyboard.press('Escape');
            await closed();
            assert.equal(page.url(), url, 'Escape keeps the page');
            await page.click(button);
            await page.waitForSelector('dialog[open] [data-export-anyway]', { timeout: 60000 });
            // „Exportovat přesto“ for real: the same POST goes again with allowUnfilled=1 and the
            // file it answers with is saved by the browser — every dimension / page is rendered.
            const cdp = await browser.target().createCDPSession();
            const dir = fs.mkdtempSync(path.join(cfg.tmp, 'download-'));
            await cdp.send('Browser.setDownloadBehavior', { behavior: 'allowAndName', downloadPath: dir, eventsEnabled: true });
            const downloaded = new Promise((resolve) => {
                cdp.on('Browser.downloadProgress', (event) => { if (event.state === 'completed') resolve(path.join(dir, event.guid)); else if (event.state === 'canceled') resolve(null); });
            });
            const posted = new Promise((resolve) => {
                const onRequest = (request) => { if (request.method() === 'POST' && /allowUnfilled/.test(request.postData() || '')) { page.off('request', onRequest); resolve(request.url()); } };
                page.on('request', onRequest);
            });
            await page.click('dialog[open] [data-export-anyway]');
            assert.ok(await Promise.race([posted, h.sleep(30000).then(() => null)]), 'the form went again with allowUnfilled=1');
            const file = await Promise.race([downloaded, h.sleep(240000).then(() => null)]);
            await cdp.send('Browser.setDownloadBehavior', { behavior: 'default' }).catch(() => null);
            await cdp.detach().catch(() => null);
            assert.ok(file && fs.existsSync(file), 'the export came back as a file');
            const bytes = fs.readFileSync(file);
            assert.ok(bytes.length > 2000 && bytes.subarray(0, magic.length).toString('latin1') === magic, `a real ${magic === 'PK' ? 'ZIP' : 'PDF'} (${bytes.length} bytes, starts ${JSON.stringify(bytes.subarray(0, 4).toString('latin1'))})`);
            assert.equal(page.url(), url, 'the page stays');
            // … and is not "unsaved" because of the answer: leaving it asks nothing.
            assert.equal(await page.evaluate(() => window.dispatchEvent(new Event('beforeunload', { cancelable: true }))), true, 'no unsaved-changes prompt after the confirmed export');
        };

        await check('group fill page: the ZIP export asks first, then really exports', async () => {
            await asksFirst(`${cfg.base}/template-group/${groupId}/fill`, 'form [data-group-fill-target="exportButton"].btn-primary', '1 nedoplněný obrázek', 'PK');
        });

        await check('document fill page: the PDF export asks first, then really exports', async () => {
            await asksFirst(`${cfg.base}/template-document/${documentId}/fill`, 'form [data-group-fill-target="exportButton"].btn-primary', '1 nedoplněný obrázek', '%PDF');
        });
    },
};
