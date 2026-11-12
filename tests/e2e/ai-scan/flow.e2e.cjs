/*
 * The whole wizard as a user walks it (ux.md S1–S4b, T4): upload with the browser's checks →
 * fonts → consent → start → kontrola / sken with the real stages → the review opens by itself →
 * fix a font, correct a text, add a place, edit a layer → leave and come back → pictures (several
 * at once, one into a place, kind, remove + undo) → one create button → the editor → the scan's
 * page is read-only and the template's card says where it came from.
 */
const fs = require('fs');
const path = require('path');
const assert = require('assert/strict');

const FONT = path.resolve(__dirname, '../../Fixtures/fonts/Inter-ExtraBold-StaticSubset.ttf');

module.exports = {
    name: 'flow',
    async run({ browser, page, h, scanner, check }) {
        let scanId = null;
        const poster = await h.poster(browser);
        const proposal = () => h.scan(page, (app) => app.proposal);

        await check('S1 refuses a PDF, a tiny picture and two files — nothing is sent', async () => {
            await page.goto(h.urls.scanNew(), { waitUntil: 'networkidle2' });
            await h.waitView(page, 'upload');
            assert.match(await h.text(page, '.as-step'), /Krok 1 ze 4 · Nahrání/);
            const pdf = path.join(h.config.tmp, 'letak.pdf');
            fs.writeFileSync(pdf, '%PDF-1.7 fake');
            await h.choose(page, pdf);
            await page.waitForSelector('#asDropErr');
            assert.match(await h.text(page, '#asDropErr'), /PDF a zdrojové soubory zatím neumíme/);
            const tiny = await h.picture(browser, 'mala.png', 400, 300);
            await h.choose(page, tiny);
            await page.waitForFunction(() => /příliš malý \(400 × 300 px\)/.test(document.querySelector('#asDropErr').textContent));
            const input = await page.$('#asFile');
            await input.uploadFile(poster, tiny);
            await page.waitForFunction(() => /Nahrajte jen jeden obrázek/.test(document.querySelector('#asDropErr').textContent));
            assert.equal(page.requests.filter((r) => /^POST .*\/ai-scan$/.test(r)).length, 0, 'no upload request');
        });

        await check('S2 shows what the browser found; a project without a font gets one inline', async () => {
            await h.choose(page, poster);
            await h.waitView(page, 'chosen');
            assert.match(await h.text(page, '.as-file-b'), /plakat\.png 900 × 1200 px · 3:4/);
            assert.match(await h.text(page, '.as-checks'), /Formát a velikost jsou v pořádku.*Malý obrázek — drobné texty nemusíme přečíst.*Obrázek je ostrý/);
            if (await h.exists(page, '.as-fonts.warn')) {
                assert.equal(await page.$eval('[data-k="start"]', (el) => el.disabled), true);
                assert.match(await h.text(page, '#asStartHint'), /Projekt zatím nemá žádné písmo/);
                const input = await page.$('#asFontFile');
                await input.uploadFile(FONT);
                await page.waitForFunction(() => !document.querySelector('.as-fonts.warn') && /Písma projektu · \d+ řez/.test(document.querySelector('.as-fonts').textContent), { timeout: 15000 });
            }
            assert.match(await h.text(page, '.as-fonts'), /Písma projektu · \d+ (řez|řezy|řezů)/);
        });

        await check('the first scan needs both consents; the keyboard reaches the start button', async () => {
            assert.equal(await page.$eval('[data-k="start"]', (el) => el.disabled), true);
            assert.match(await h.text(page, '#asStartHint'), /Nejdřív potvrďte oba souhlasy/);
            await page.focus('[data-k="consent0"]');
            await page.keyboard.press('Space');
            await page.keyboard.press('Tab'); // the link inside the first statement
            await page.keyboard.press('Tab');
            await page.keyboard.press('Space');
            await page.keyboard.press('Tab');
            assert.equal(await page.evaluate(() => document.activeElement.dataset.k), 'start');
            assert.equal(await page.$eval('[data-k="start"]', (el) => el.disabled), false);
            assert.match(await h.text(page, '.as-credit'), /Spotřebuje 1 sken/);
        });

        await check('the privacy dialog is modal, closes on Escape and gives the focus back', async () => {
            await h.press(page, 'privacy');
            await page.waitForSelector('dialog[open]');
            assert.equal(await page.evaluate(() => document.querySelector('dialog[open]').contains(document.activeElement)), true);
            for (let i = 0; i < 6; i += 1) await page.keyboard.press('Tab');
            assert.equal(await page.evaluate(() => document.querySelector('dialog[open]').contains(document.activeElement) || document.activeElement === document.body), true, 'Tab stays in the dialog');
            await page.keyboard.press('Escape');
            await page.waitForFunction(() => !document.querySelector('dialog'));
            assert.equal(await page.evaluate(() => document.activeElement.dataset.k), 'privacy');
        });

        await check('S3: kontrola → sken with the real stages, a round pill, no lavender button', async () => {
            scanner.options.beatMs = 700;
            await page.focus('[data-k="start"]');
            await page.keyboard.press('Enter');
            await h.waitView(page, 'wait');
            scanId = await h.scan(page, (app) => app.scan.id);
            assert.ok(page.url().endsWith(`/z-obrazku/${scanId}`), 'one URL per scan');
            assert.equal(await page.$$eval('.as-page .btn-primary', (n) => n.length), 0, 'no primary while waiting');
            assert.match(await h.text(page, '.as-leave'), /Můžete odejít — dáme vědět/);
            await page.waitForFunction(() => window.__aiScan.scan.status === 'running', { timeout: 20000 });
            assert.match(await page.title(), /^Sken běží — plakat\.png/);
            const labels = await page.$$eval('.as-stages li > span:nth-child(2)', (n) => n.map((e) => e.firstChild.textContent));
            assert.deepEqual(labels, ['Kontrola obrázku', 'Čtení textů', 'Rozbor grafiky', 'Sestavení šablony', 'Porovnání a doladění', 'Dokončení']);
            await page.waitForFunction(() => /Nalezeny 2 texty/.test(document.querySelector('.as-stages').textContent), { timeout: 20000 });
            await page.waitForFunction(() => /kolo 1 ze 2/.test(document.querySelector('.as-stages').textContent), { timeout: 30000 });
            assert.ok(await h.exists(page, '.as-pics img[src*="/image/candidate"]'), 'the first rendered preview is shown');
            assert.match(await page.$eval('#asLive', (el) => el.textContent), /Sken běží/);
            assert.ok((await page.$$eval('.as-stages .done .dur', (n) => n.map((e) => e.textContent).filter(Boolean))).length >= 2, 'finished stages carry their duration');
        });

        await check('S4 opens by itself: summary, counters, the proposal is not a template yet', async () => {
            await h.waitView(page, 'review', 40000);
            await h.waitToast(page, 'Návrh je připravený');
            assert.match(await h.text(page, '.as-sum'), /Hotovo: 2 texty, 1 tvar, 2 místa pro obrázky, pozadí\. Zbývají rozhodnout 3 věci\./);
            assert.match(await h.text(page, '.as-sumcard'), /Tohle je návrh — šablona zatím neexistuje/);
            assert.match(await h.text(page, '.as-step'), /Krok 3 ze 4 · Kontrola/);
            assert.match(await h.text(page, '.as-foot'), /Návrh se stane šablonou, až ho vytvoříte\. Uchováme ho do \d+\. \d+\. \d{4}/);
            assert.equal(await page.evaluate(() => document.activeElement.classList.contains('as-sum')), true, 'focus is on the review heading');
            assert.equal(await page.$$eval('.as-page .btn-primary', (n) => n.length), 1, 'one primary');
            assert.equal(h.psql(`SELECT count(*) FROM template WHERE project_id = '${h.config.projectId}'`), '0');
        });

        await check('font: nearest available → choose another face, Vrátit, Nechat', async () => {
            assert.match(await h.text(page, '.as-it'), /Písmo nadpisů jsme nahradili V projektu jsme stejné písmo nenašli\. Použili jsme nejbližší: .+ velikost jsme upravili o 4 %.+Týká se 1 textu\./);
            assert.doesNotMatch(await h.text(page, '.as-panel-card'), /Vypadá jako/);
            const faces = (await proposal()).fonts;
            const other = faces[faces.length - 1];
            await page.select('[data-chg="chooseFont"]', other);
            await h.settled(page);
            assert.equal((await proposal()).toDecide, 2);
            assert.equal((await proposal()).elements.find((e) => e.id === 'headline').font, other);
            assert.match(await h.text(page, '.as-it.decided'), /Písmo nadpisů: .+ — texty jsme přepočítali/);
            await page.click('.as-it.decided [data-act="undo"]');
            await h.settled(page);
            assert.equal((await proposal()).toDecide, 3);
            await page.click('[data-act="keepFont"]');
            await h.settled(page);
            assert.match(await h.text(page, '.as-it.decided'), /Písmo nadpisů: necháváte/);
        });

        await check('doubtful text: corrected and confirmed with Enter', async () => {
            const input = '[data-k="dtsub"]';
            await page.click(input, { clickCount: 3 });
            await page.type(input, 'Sobota 14. června 2026');
            await page.keyboard.press('Enter');
            await h.settled(page);
            assert.equal((await proposal()).elements.find((e) => e.id === 'sub').text, 'Sobota 14. června 2026');
            assert.equal((await proposal()).toDecide, 1);
        });

        await check('left-out thing: its own crop, becomes a place for a picture', async () => {
            assert.ok(await h.exists(page, '.as-leftrow .as-leftcrop'));
            await page.click('[data-act="addPlace"]');
            await h.settled(page);
            assert.equal((await proposal()).placeholders.length, 3);
            assert.match(await h.text(page, '.as-sum'), /3 místa pro obrázky.+Nic k rozhodnutí\./);
            assert.match(await h.text(page, '.as-panel-body'), /Pro informaci \(5\)/);
        });

        await check('Vrstvy: any text is correctable, a switch makes it fixed, undo of the shrink', async () => {
            await h.press(page, 'tablay');
            await page.waitForSelector('.as-layers');
            await page.click('[data-k="ltheadline"]', { clickCount: 3 });
            await page.type('[data-k="ltheadline"]', 'Den otevřených dveří 2026');
            await page.keyboard.press('Tab');
            await h.settled(page);
            await page.$eval('[data-k="lswheadline"]', (el) => el.click());
            await h.settled(page);
            const headline = (await proposal()).elements.find((e) => e.id === 'headline');
            assert.equal(headline.text, 'Den otevřených dveří 2026');
            assert.equal(headline.input, false);
            await h.press(page, 'tabdec');
            await page.$eval('.as-info summary', (el) => el.click());
            await page.click('[data-k="doshrink"]');
            await h.settled(page);
            assert.equal((await proposal()).information.find((r) => r.code === 'shrunk').undone, true);
        });

        await check('compare: two modes, slider by keyboard, layer outlines', async () => {
            await page.focus('.as-handle');
            await page.keyboard.press('End');
            assert.equal(await page.$eval('.as-handle', (el) => el.getAttribute('aria-valuetext')), '100 % původní');
            await page.keyboard.press('ArrowLeft');
            assert.equal(await page.$eval('.as-handle', (el) => el.getAttribute('aria-valuenow')), '95');
            await page.$eval('[data-k="layers"]', (el) => el.click());
            assert.ok((await page.$$('.as-box:not(.ghost)')).length >= 4);
            await h.press(page, 'cmpside');
            assert.ok(await h.exists(page, '.as-side2'));
            assert.equal(await page.evaluate(() => window.localStorage.getItem('wb-as-cmp')), 'side');
            await h.press(page, 'cmpslider');
        });

        await check('leaving and coming back loses nothing', async () => {
            await page.goto(h.urls.templates(), { waitUntil: 'networkidle2' });
            await page.goto(h.urls.scan(scanId), { waitUntil: 'networkidle2' });
            await h.waitView(page, 'review');
            const p = await proposal();
            assert.equal(p.elements.find((e) => e.id === 'sub').text, 'Sobota 14. června 2026');
            assert.equal(p.placeholders.length, 3);
            assert.equal(p.toDecide, 0);
        });

        await check('S4b: several pictures at once go to the empty places in order', async () => {
            await h.press(page, 'next');
            await h.waitView(page, 'pictures');
            assert.match(await h.text(page, '.as-step'), /Krok 4 ze 4 · Fotky a loga/);
            assert.match(await h.text(page, '[data-k="create"]'), /Vytvořit šablonu · 1 obrázek doplníte později/);
            const logo = await h.picture(browser, 'logo.png', 280, 120);
            const photo = await h.picture(browser, 'fotka.png', 800, 600, '#0b6e4f');
            const input = await page.$('#asPics');
            await input.uploadFile(logo, photo);
            await h.waitToast(page, '2 obrázky přiřazeny');
            await h.settled(page);
            const places = (await proposal()).placeholders;
            assert.deepEqual(places.map((ph) => Boolean(ph.picture)), [true, true, false]);
            assert.equal(places[0].name, 'Logo', 'a filled place keeps its name');
            assert.match(await h.text(page, '[data-k="create"]'), /Vytvořit šablonu a otevřít editor/);
        });

        await check('S4b: kind, one file into a place, remove a place and bring it back', async () => {
            const id = (await proposal()).placeholders[2].id;
            await page.click(`[data-k="ph${id}fixed"]`);
            await h.settled(page);
            assert.match(await h.text(page, '[data-k="create"]'), /1 obrázek doplníte později/);
            assert.match(await h.text(page, '.as-foot'), /Prázdná místa zůstanou v šabloně šedá/);
            await page.click(`[data-k="phn${id}"]`).catch(() => null); // sets the target; the native picker never opens in headless
            await h.scan(page, (app, placeId) => { app.placeTarget = placeId; }, id);
            const input = await page.$('#asPlaceFile');
            await input.uploadFile(await h.picture(browser, 'ikona.png', 180, 120, '#d1495b'));
            await h.settled(page);
            assert.equal((await proposal()).pictures.unfilled, 0);
            await page.click(`[data-k="phmenu${id}"]`);
            await page.click(`.as-ph-menu [data-act="removePlace"]`);
            await h.settled(page);
            assert.equal((await proposal()).placeholders.length, 2);
            assert.match(await h.text(page, '.as-ph-list .as-it.decided'), /smazáno/);
            await page.click('.as-ph-list .as-it.decided [data-act="undo"]');
            await h.settled(page);
            assert.equal((await proposal()).placeholders.length, 3);
        });

        await check('one create button → the editor, with the toast; two presses make one template', async () => {
            await h.shot(page, 'flow-pictures');
            await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 60000 }), h.press(page, 'create')]);
            assert.match(page.url(), /\/template-variant\/[0-9a-f-]{36}\/editor$/);
            await h.waitToast(page, 'vytvořena ze skenu — \\d+ prvk');
            assert.equal(h.psql(`SELECT count(*) FROM template WHERE project_id = '${h.config.projectId}'`), '1');
            assert.equal(h.psql(`SELECT status FROM ai_scan WHERE id = '${scanId}'`), 'accepted');
        });

        await check('after creating: the scan page is read-only, the list card says „Ze skenu“', async () => {
            await page.goto(h.urls.scan(scanId), { waitUntil: 'networkidle2' });
            await h.waitView(page, 'readonly');
            assert.match(await h.text(page, '.as-head'), /Sken: .+ Šablona vytvořena/);
            assert.match(await h.text(page, '.as-panel-card'), /Šablona už existuje, proto se tady nedá nic měnit/);
            assert.equal(await page.$$eval('.as-page input:not([type="checkbox"]):not([disabled]), .as-page [data-act="undo"]', (n) => n.length), 0, 'nothing to change');
            assert.ok(await h.exists(page, '[data-k="open"]'));
            await page.goto(h.urls.templates(), { waitUntil: 'networkidle2' });
            assert.match(await page.$eval('.tp-page', (el) => el.innerText), /Ze skenu/);
        });
    },
};
