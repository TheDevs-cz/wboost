/*
 * Stáhnout PDF (docs/architecture/calendars.md § "PDF export").
 *
 * A calendar with project fonts, photos on several weeks (one ≥ 3600 px wide, one cropped at 160 %),
 * events, waste, moon and a special page is exported through the UI — both variants as the
 * designer (from step 5, with unsaved changes first), the print variant as the client on
 * `/k/{token}` — and the downloaded files are inspected with the poppler tools on the host
 * (`pdfinfo`, `pdffonts`, `pdfimages`, `pdftotext`, `pdftoppm`): page count, page size and boxes in
 * mm, embedded fonts, pictures at their native pixels, live text. Then the other ways into the same
 * dialog (list card, the Dokončit toast), the history lines, reuse of an unchanged export, the
 * binding of an export id to its calendar, and a 14-day calendar.
 *
 * The export is an asynchronous job: the script starts a Messenger consumer of its own
 * (`messenger:consume async`, time-limited) because the dev stack runs none.
 *
 * Without poppler on the host the file checks are skipped with a message (macOS: `brew install poppler`).
 * With SHOTS set, pages of the exported files are written there as PNG — look at them.
 */
const assert = require('assert/strict');
const fs = require('fs');
const os = require('os');
const path = require('path');
const { execFileSync, spawn } = require('child_process');
const { buildCalendar } = require('./lib/pdf-fixture.cjs');

const ROOT = path.resolve(__dirname, '../../..');
const MM_PER_PT = 25.4 / 72;
const DIALOG = '[data-cal-dialog="export"]';

function tool(name) {
    try {
        execFileSync(name, ['-v'], { stdio: 'ignore' });
        return true;
    } catch (error) {
        return error.code !== 'ENOENT';
    }
}
const POPPLER = ['pdfinfo', 'pdffonts', 'pdfimages', 'pdftotext', 'pdftoppm'].every(tool);
const run = (name, args) => execFileSync(name, args, { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 });

/** `{ pages, boxes: { MediaBox, CropBox, BleedBox, TrimBox } in mm as { w, h, x0, y0, x1, y1 } }` of page 1 (all pages when `all`). */
function pdfInfo(file) {
    const out = run('pdfinfo', ['-box', '-f', '1', '-l', '9999', file]);
    const pages = Number(/^Pages:\s+(\d+)/m.exec(out)[1]);
    const perPage = [];
    for (const match of out.matchAll(/^Page\s+(\d+)\s+(MediaBox|CropBox|BleedBox|TrimBox):\s+([\d.-]+)\s+([\d.-]+)\s+([\d.-]+)\s+([\d.-]+)/gm)) {
        const index = Number(match[1]) - 1;
        const [x0, y0, x1, y1] = match.slice(3).map((v) => Number(v) * MM_PER_PT);
        perPage[index] = perPage[index] || {};
        perPage[index][match[2]] = { x0, y0, x1, y1, w: x1 - x0, h: y1 - y0 };
    }
    return { pages, perPage, title: (/^Title:\s+(.*)$/m.exec(out) || [])[1] || '' };
}

const pdfFonts = (file) =>
    run('pdffonts', [file])
        .split('\n')
        .slice(2)
        .filter(Boolean)
        .map((line) => {
            const cols = line.trim().split(/\s{2,}|\s(?=(?:yes|no)\s)/);
            return { name: cols[0].replace(/^[A-Z]{6}\+/, ''), embedded: /\syes\s+(yes|no)\s+(yes|no)\s+\d+\s+\d+\s*$/.test(line) };
        });

const pdfImages = (file) =>
    run('pdfimages', ['-list', file])
        .split('\n')
        .slice(2)
        .filter(Boolean)
        .map((line) => line.trim().split(/\s+/))
        .map((c) => ({ page: Number(c[0]), type: c[2], width: Number(c[3]), height: Number(c[4]), enc: c[8] }))
        .filter((image) => image.type === 'image');

/** `r,g,b` of the pixel at a point given in mm from the top-left corner of the sheet (one-pixel PPM from pdftoppm). */
function pixelAt(file, pageNo, xMm, yMm) {
    const dpi = 254; // 10 px per mm
    const out = execFileSync('pdftoppm', ['-r', String(dpi), '-f', String(pageNo), '-l', String(pageNo), '-x', String(Math.round(xMm * 10)), '-y', String(Math.round(yMm * 10)), '-W', '1', '-H', '1', file], { maxBuffer: 1024 * 1024 });
    return [...out.subarray(out.length - 3)].join(',');
}
/** Colours in the middle of the first binding hole and in the gap right of it (default page; `inset` = trim from the sheet edge, mm). */
const holeProbe = (file, pageNo, inset) => [pixelAt(file, pageNo, inset + 4.85 + 2.15, inset + 4 + 2.15), pixelAt(file, pageNo, inset + 4.85 + 4.3 + 1.85, inset + 4 + 2.15)];

const near = (actual, expected, tol, label) => assert.ok(Math.abs(actual - expected) <= tol, `${label}: expected ${expected} ± ${tol}, got ${actual}`);
const exists = async (page, selector) => (await page.$(selector)) !== null;
const boot = (page) => page.evaluate(() => JSON.parse(document.querySelector('[data-calendar-workspace-target="bootstrap"]').textContent));

module.exports = {
    name: 'pdf-export',
    checks: ['§10.9 PDF export', 'roles', 'entry points'],
    async run({ browser, page, h, check }) {
        const downloads = fs.mkdtempSync(path.join(os.tmpdir(), 'wb-cal-pdf-'));
        const keep = h.config.shots || downloads;
        const psql = (sql) => execFileSync('docker', ['compose', 'exec', '-T', 'postgres', 'psql', '-U', 'postgres', '-d', 'wboost', '-v', 'ON_ERROR_STOP=1', '-At'], { cwd: ROOT, input: sql, encoding: 'utf8' }).trim();

        // the dev stack runs no consumer: one of our own, gone by itself after 15 minutes
        const consumer = spawn('docker', ['compose', 'exec', '-T', 'web', 'bin/console', 'messenger:consume', 'async', '--time-limit=900', '-q'], { cwd: ROOT, stdio: 'ignore' });
        const stopConsumer = () => {
            try {
                execFileSync('docker', ['compose', 'exec', '-T', 'web', 'bin/console', 'messenger:stop-workers', '-q'], { cwd: ROOT, stdio: 'ignore' });
            } catch (e) {
                // it stops at its time limit
            }
            consumer.kill();
        };

        /** Downloads started in `target` land in `downloads`; resolves with the path of the next finished one. */
        async function watchDownloads(target) {
            const session = await target.createCDPSession();
            // per browser context: the anonymous client lives in a context of its own
            const contextId = target.browserContext().id;
            await session.send('Browser.setDownloadBehavior', { behavior: 'allowAndName', downloadPath: downloads, eventsEnabled: true, ...(contextId ? { browserContextId: contextId } : {}) });
            const names = new Map();
            const waiting = [];
            const finished = [];
            session.on('Browser.downloadWillBegin', (event) => names.set(event.guid, event.suggestedFilename));
            session.on('Browser.downloadProgress', (event) => {
                if (event.state !== 'completed') return;
                const file = { path: path.join(downloads, event.guid), name: names.get(event.guid) || '' };
                const next = waiting.shift();
                if (next) next(file);
                else finished.push(file);
            });
            return {
                next: (timeout = 240000) =>
                    finished.length
                        ? Promise.resolve(finished.shift())
                        : new Promise((resolve, reject) => {
                              const timer = setTimeout(() => reject(new Error('no download finished in time')), timeout);
                              waiting.push((file) => {
                                  clearTimeout(timer);
                                  resolve(file);
                              });
                          }),
            };
        }

        async function openDialog(target, opener = '[data-cal-action="export-pdf"]') {
            await target.$eval(opener, (el) => el.click());
            await target.waitForSelector(`${DIALOG}.show[data-cal-export-state="choose"]`, { timeout: 5000 });
            await h.sleep(350);
        }

        async function closeDialog(target) {
            await target.$eval(`${DIALOG} [data-cal-action="modal-done"]`, (el) => el.click());
            await target.waitForFunction((selector) => !document.querySelector(selector), { timeout: 5000 }, DIALOG);
        }

        /** Chooses a variant, presses `button`, follows the progress to the end; returns `{ file, progress: [texts] }`. */
        async function exportThrough(target, watcher, variant, button = 'export-start') {
            await target.$eval(`${DIALOG} input[data-cal-export-variant="${variant}"]`, (el) => el.click());
            await target.evaluate((selector) => {
                window.__pdfSeen = [];
                const node = document.querySelector(`${selector} [data-cal-export-progress]`);
                new MutationObserver(() => window.__pdfSeen.push(node.textContent)).observe(node, { childList: true, characterData: true, subtree: true });
            }, DIALOG);
            const pending = watcher.next();
            await target.$eval(`${DIALOG} [data-cal-action="${button}"]`, (el) => el.click());
            await target.waitForSelector(`${DIALOG}[data-cal-export-state="done"]`, { timeout: 240000 });
            const file = await pending;
            return { file, progress: await target.evaluate(() => window.__pdfSeen), link: await target.$eval(`${DIALOG} a[data-cal-action="export-download"]`, (el) => ({ href: el.getAttribute('href'), hidden: el.hidden, text: el.textContent.trim() })) };
        }

        const keepFile = (file, name) => {
            const target = path.join(keep, name);
            fs.mkdirSync(keep, { recursive: true });
            fs.copyFileSync(file.path, target);
            return target;
        };

        /** A page of the PDF as a PNG in SHOTS (whole page at `dpi`, or a crop in px at that dpi). */
        const png = (file, pageNo, name, dpi = 110, crop = null) => {
            if (!h.config.shots || !POPPLER) return;
            const args = ['-r', String(dpi), '-f', String(pageNo), '-l', String(pageNo), '-png', '-singlefile'];
            if (crop) args.push('-x', String(crop.x), '-y', String(crop.y), '-W', String(crop.w), '-H', String(crop.h));
            run('pdftoppm', [...args, file, path.join(h.config.shots, name)]);
        };

        let built = null;
        let designerWatch = null;
        let printFile = null;
        let approvalFile = null;
        let design = null;

        try {
            await check('setup: a 7-day calendar with fonts, photos (one 3600 px, one cropped), events, waste, moon and a special page', async () => {
                built = await buildCalendar(page, h, { type: 'week', photos: 3, bigWidth: 3600, width: 2000 });
                design = (await h.state(page)).design;
                assert.equal(design.print.bleed, true);
                assert.equal(design.print.marks, true);
                assert.ok(built.pages.some((p) => p.key === 'sp:intro'), 'the special page is in the page list');
                assert.equal(await page.$eval('.cal-ws', (el) => el.dataset.calShownStep), '5');
                designerWatch = await watchDownloads(page);
            });
            if (!built) return;

            await check('step 5: „Stáhnout PDF“ is a labelled button next to „Odkaz pro klienta“; the dialog offers the two variants', async () => {
                const acts = await page.$$eval('.cal-5acts > *', (nodes) => nodes.map((n) => n.dataset.calAction || n.className));
                assert.equal(acts[0], 'export-pdf');
                assert.equal(acts[1], 'client-link');
                assert.equal(await page.$eval('[data-cal-action="export-pdf"]', (el) => el.textContent.trim()), 'Stáhnout PDF');
                await h.shot(page, 'pdf-step5-toolbar');
                await openDialog(page);
                assert.equal(await page.$eval(`${DIALOG} .modal-title`, (el) => el.textContent), 'Stáhnout PDF');
                const cards = await page.$$eval(`${DIALOG} .rcard`, (nodes) => nodes.map((n) => ({ title: n.querySelector('.rt').textContent, text: n.querySelector('.rsub').textContent, value: n.querySelector('input').value, checked: n.querySelector('input').checked })));
                assert.deepEqual(cards.map((c) => [c.value, c.title, c.checked]), [['print', 'Pro tisk', true], ['approval', 'Náhled k odsouhlasení', false]]);
                assert.match(cards[0].text, /spadávkou a ořezovými značkami/);
                assert.match(cards[1].text, /poslat e.mailem/); // a non-breaking hyphen
                // nothing unsaved: one primary action, no note
                assert.equal(await page.$eval(`${DIALOG} [data-cal-export-unsaved]`, (el) => el.hidden), true);
                assert.equal(await page.$eval(`${DIALOG} [data-cal-action="export-save-start"]`, (el) => el.hidden), true);
                assert.equal(await page.$eval(`${DIALOG} [data-cal-action="export-start"]`, (el) => el.textContent.trim()), 'Připravit PDF');
                assert.equal(await page.$$eval(`${DIALOG} .btn-primary`, (nodes) => nodes.filter((n) => !n.hidden).length), 1, 'one primary action');
                await h.shot(page, 'pdf-dialog-choose');
                await closeDialog(page);
            });

            await check('unsaved changes: the dialog says the export uses the saved state; „Uložit a exportovat“ saves, then exports for print with progress', async () => {
                await page.focus('input[data-cal-name]');
                await page.$eval('input[data-cal-name]', (el) => el.select());
                await page.keyboard.type('Obecní kalendář');
                await page.keyboard.press('Tab');
                await h.waitStatus(page, 'dirty');
                await openDialog(page);
                assert.equal(await page.$eval(`${DIALOG} [data-cal-export-unsaved]`, (el) => el.hidden), false);
                assert.match(await page.$eval(`${DIALOG} [data-cal-export-unsaved]`, (el) => el.textContent), /Export použije uložený stav — nejdřív uložte změny\./);
                assert.equal(await page.$eval(`${DIALOG} [data-cal-action="export-save-start"]`, (el) => el.textContent.trim()), 'Uložit a exportovat');
                assert.equal(await page.$eval(`${DIALOG} [data-cal-action="export-start"]`, (el) => el.textContent.trim()), 'Exportovat uložený stav');
                assert.equal(await page.$$eval(`${DIALOG} .btn-primary`, (nodes) => nodes.filter((n) => !n.hidden).length), 1, 'one primary action');
                await h.shot(page, 'pdf-dialog-unsaved');

                const result = await exportThrough(page, designerWatch, 'print', 'export-save-start');
                await h.waitStatus(page, 'clean');
                assert.equal((await h.state(page)).design.name, 'Obecní kalendář');
                assert.ok(result.progress.some((text) => /^Připravuji PDF…/.test(text)), `progress texts: ${JSON.stringify(result.progress)}`);
                assert.ok(result.progress.some((text) => new RegExp(`^Připravuji PDF… \\d+ z ${built.pages.length} stran$`).test(text)), `„N z ${built.pages.length} stran“ was shown: ${JSON.stringify(result.progress)}`);
                assert.equal(result.link.hidden, false);
                assert.equal(result.link.text, 'Stáhnout');
                assert.match(result.link.href, /\/calendar\/[0-9a-f-]{36}\/export\/[0-9a-f-]{36}\/download$/);
                assert.equal(result.file.name, 'Obecní kalendář - tisk.pdf');
                assert.match(await page.$eval(`${DIALOG} .cal-exp-file`, (el) => el.textContent), /Obecní kalendář - tisk\.pdf/);
                printFile = keepFile(result.file, 'week-print.pdf');
                await h.shot(page, 'pdf-dialog-done');
            });
            if (!printFile) return;

            await check('„Jiná varianta“ → Náhled k odsouhlasení is exported and downloaded too', async () => {
                await page.click(`${DIALOG} [data-cal-action="export-again"]`);
                await page.waitForSelector(`${DIALOG}[data-cal-export-state="choose"]`);
                const result = await exportThrough(page, designerWatch, 'approval');
                assert.equal(result.file.name, 'Obecní kalendář - náhled.pdf');
                approvalFile = keepFile(result.file, 'week-approval.pdf');
                await closeDialog(page);
            });

            await check('print file: every page, trim + 2 × (bleed + mark band), TrimBox / BleedBox, fonts embedded, photos at native pixels, live text', async () => {
                if (!POPPLER) throw new Error('SKIPPED — poppler (pdfinfo, pdffonts, pdfimages, pdftotext) is not installed on this host');
                const info = pdfInfo(printFile);
                assert.equal(info.pages, built.pages.length);
                assert.equal(info.title, 'Obecní kalendář');
                const { width: W, height: H } = design.page;
                const B = design.print.bleedMm;
                const M = B + 8; // bleed + 2 mm gap + 5 mm mark + 1 mm
                for (const boxes of info.perPage) {
                    near(boxes.MediaBox.w, W + 2 * M, 0.02, 'media width (mm)');
                    near(boxes.MediaBox.h, H + 2 * M, 0.02, 'media height (mm)');
                    near(boxes.TrimBox.w, W, 0.02, 'trim width');
                    near(boxes.TrimBox.h, H, 0.02, 'trim height');
                    near(boxes.TrimBox.x0 - boxes.MediaBox.x0, M, 0.02, 'trim inset left');
                    near(boxes.MediaBox.y1 - boxes.TrimBox.y1, M, 0.02, 'trim inset top');
                    near(boxes.BleedBox.w, W + 2 * B, 0.02, 'bleed box width');
                    near(boxes.BleedBox.h, H + 2 * B, 0.02, 'bleed box height');
                }
                const fonts = pdfFonts(printFile);
                assert.ok(fonts.length > 0 && fonts.every((f) => f.embedded), `every font is embedded: ${JSON.stringify(fonts)}`);
                const project = (await boot(page)).env.fonts.map((f) => f.fontName.replace(/\s+/g, ''));
                assert.ok(project.length > 0, 'the e2e project has fonts (run fonts.e2e.cjs once)');
                assert.ok(fonts.some((f) => project.some((name) => f.name.replace(/[\s-]/g, '').toLowerCase().includes(name.toLowerCase()))), `a project font (${project.join(', ')}) is in the file: ${fonts.map((f) => f.name).join(', ')}`);

                const images = pdfImages(printFile);
                const weekPage = (key) => built.pages.findIndex((p) => p.key === key) + 1;
                for (const [key, photo] of Object.entries(built.photos)) {
                    const image = images.find((i) => i.page === weekPage(key));
                    assert.ok(image, `the photo of ${key} is an image object on page ${weekPage(key)}`);
                    assert.deepEqual([image.width, image.height], [photo.width, photo.height], `${key}: native pixels`);
                    assert.equal(image.enc, 'jpeg', `${key}: the uploaded JPEG data, not re-encoded`);
                }
                assert.equal(built.photos[built.big].width, 3600);
                // nothing but the photos is a raster: no page was flattened into a picture
                assert.equal(images.length, Object.keys(built.photos).length, `image objects: ${JSON.stringify(images)}`);

                const text = run('pdftotext', ['-f', String(weekPage(built.big)), '-l', String(weekPage(built.big)), printFile, '-']);
                assert.match(text, /Pondělí/);
                assert.match(text, /Neděle/);
                assert.match(text, /\b20\d\d\b/, 'month + year text');
                const all = run('pdftotext', [printFile, '-']);
                assert.match(all, /SDH · Masopustní průvod/, 'the event with its club');
                assert.match(all, /Pouť a žehnání úrody/);
                assert.match(all, /Foto w\d\d: výhled na obec, archiv/);

                const first = weekPage(built.big);
                // the binding holes are punched at the bindery: the print file does not paint them
                const [hole, gap] = holeProbe(printFile, first, M);
                assert.equal(hole, gap, 'no hole squares in the print file');
                png(printFile, first, 'pdf-week-print-big', 110);
                png(printFile, weekPage(Object.keys(built.photos)[1]), 'pdf-week-print-cropped', 110);
                png(printFile, 1, 'pdf-week-print-cover', 80);
                png(printFile, built.pages.findIndex((p) => p.key === 'sp:intro') + 1, 'pdf-week-print-special', 80);
                png(printFile, built.pages.length, 'pdf-week-print-back', 80);
                // 1200 dpi crops: glyph edges of the day rows, and the top-left crop marks + bleed corner
                const px = (mm) => Math.round((mm / 25.4) * 1200);
                png(printFile, first, 'pdf-week-print-1200dpi-text', 1200, { x: px(M + W - 60), y: px(M + 30), w: px(50), h: px(22) });
                png(printFile, first, 'pdf-week-print-1200dpi-corner', 1200, { x: 0, y: 0, w: px(26), h: px(26) });
            });

            await check('approval file: trimmed pages, preview-size photos as JPEG, a fraction of the print file', async () => {
                if (!POPPLER) throw new Error('SKIPPED — poppler is not installed on this host');
                const info = pdfInfo(approvalFile);
                assert.equal(info.pages, built.pages.length);
                for (const boxes of info.perPage) {
                    near(boxes.MediaBox.w, design.page.width, 0.02, 'media width (mm)');
                    near(boxes.MediaBox.h, design.page.height, 0.02, 'media height (mm)');
                    near(boxes.TrimBox.w, design.page.width, 0.02, 'trim = the page');
                }
                const images = pdfImages(approvalFile);
                assert.equal(images.length, Object.keys(built.photos).length);
                for (const image of images) {
                    assert.equal(Math.max(image.width, image.height), 1600, 'the preview derivative');
                    assert.equal(image.enc, 'jpeg');
                }
                assert.ok(pdfFonts(approvalFile).every((f) => f.embedded));
                assert.match(run('pdftotext', [approvalFile, '-']), /Pondělí/);
                // …while the approval file shows the finished product, holes included
                const [hole, gap] = holeProbe(approvalFile, built.pages.findIndex((p) => p.key === built.big) + 1, 0);
                assert.notEqual(hole, gap, 'the hole squares are in the approval file');
                const ratio = fs.statSync(approvalFile).size / fs.statSync(printFile).size;
                assert.ok(ratio < 0.5, `approval is ${Math.round(ratio * 100)} % of the print file`);
                png(approvalFile, built.pages.findIndex((p) => p.key === built.big) + 1, 'pdf-week-approval-big', 110);
            });

            await check('Historie records both exports as the designer\'s; an unchanged calendar is answered with the same export', async () => {
                await page.click('[data-cal-action="history"]');
                await page.waitForSelector('[data-cal-dialog="history"].show .cal-hrow', { timeout: 8000 });
                const rows = await page.$$eval('[data-cal-dialog="history"] .cal-hrow', (nodes) => nodes.map((n) => `${n.querySelector('.pill').textContent.trim()}: ${n.querySelector('.cal-hwhat').textContent.trim()}`));
                await h.sleep(350);
                await page.click('[data-cal-dialog="history"] [data-cal-action="modal-done"]');
                await page.waitForFunction(() => !document.querySelector('[data-cal-dialog="history"]'), { timeout: 5000 });
                assert.ok(rows.includes('Grafik: Stáhl PDF pro tisk'), rows.slice(0, 6).join(' | '));
                assert.ok(rows.includes('Grafik: Stáhl PDF k odsouhlasení'), rows.slice(0, 6).join(' | '));

                const before = psql(`SELECT count(*) FROM calendar_export WHERE calendar_id = '${built.id}'`);
                assert.equal(before, '2');
                await openDialog(page);
                const started = Date.now();
                const again = await exportThrough(page, designerWatch, 'print');
                assert.ok(Date.now() - started < 8000, 'the finished file is offered at once');
                assert.equal(fs.statSync(again.file.path).size, fs.statSync(printFile).size);
                assert.equal(psql(`SELECT count(*) FROM calendar_export WHERE calendar_id = '${built.id}'`), '2', 'no new job');
                await closeDialog(page);
            });

            // ---- the client on the public link --------------------------------------------------
            let other = null;
            await check('client /k/{token}: the same button and dialog; a changed calendar is rendered anew with progress; the file is the print variant', async () => {
                const anonymous = await browser.createBrowserContext();
                const client = await h.newPage(anonymous);
                await client.goto(built.clientUrl, { waitUntil: 'networkidle2' });
                await h.waitReady(client);
                assert.equal(await client.$eval('[data-cal-action="export-pdf"]', (el) => el.textContent.trim()), 'Stáhnout PDF');
                assert.equal(await exists(client, '[data-cal-action="client-link"]'), false);
                await h.shot(client, 'pdf-client-toolbar');
                // the client changes a caption and saves: the calendar is no longer what the designer exported
                const b = await boot(client);
                const saved = await client.evaluate(
                    async (urls, csrf, key) => {
                        const form = new FormData();
                        form.append('_token', csrf);
                        form.append('payload', JSON.stringify({ content: [{ c: 'pageCaptions', id: key, v: { text: 'Popisek od klienta' } }] }));
                        return (await fetch(urls.save, { method: 'POST', body: form, credentials: 'same-origin' })).status;
                    },
                    b.env.urls,
                    b.env.csrf,
                    built.big,
                );
                assert.equal(saved, 200);
                await client.reload({ waitUntil: 'networkidle2' });
                await h.waitReady(client);

                const watch = await watchDownloads(client);
                await openDialog(client);
                const result = await exportThrough(client, watch, 'print');
                assert.match(result.link.href, /^\/k\/[a-f0-9]{64}\/export\/[0-9a-f-]{36}\/download$/);
                assert.ok(result.progress.some((text) => / z \d+ stran$/.test(text)), `progress was shown: ${JSON.stringify(result.progress)}`);
                assert.equal(result.file.name, 'Obecní kalendář - tisk.pdf');
                const file = keepFile(result.file, 'week-print-client.pdf');
                if (POPPLER) {
                    const info = pdfInfo(file);
                    assert.equal(info.pages, built.pages.length);
                    near(info.perPage[0].MediaBox.w, design.page.width + 2 * (design.print.bleedMm + 8), 0.02, 'the client gets the print size');
                    assert.match(run('pdftotext', [file, '-']), /Popisek od klienta/);
                }
                await h.shot(client, 'pdf-client-dialog-done');
                await closeDialog(client);
                const rows = psql(`SELECT author_kind || ': ' || description FROM calendar_history WHERE calendar_id = '${built.id}' ORDER BY created_at DESC, id DESC LIMIT 3`);
                assert.match(rows, /client: Stáhl PDF pro tisk/);

                // ---- an export id is bound to its calendar ---------------------------------------
                other = await buildCalendar(page, h, { type: 'week', photos: 0, special: false, name: 'Cizí kalendář' });
                await openDialog(page);
                const foreign = await exportThrough(page, designerWatch, 'approval');
                await closeDialog(page);
                const foreignId = /export\/([0-9a-f-]{36})\/download$/.exec(foreign.link.href)[1];
                const token = /\/k\/([a-f0-9]{64})$/.exec(built.clientUrl)[1];
                const statuses = await client.evaluate(
                    async (t, id) => {
                        const out = [];
                        for (const url of [`/k/${t}/export/${id}`, `/k/${t}/export/${id}/download`]) out.push((await fetch(url, { credentials: 'same-origin' })).status);
                        return out;
                    },
                    token,
                    foreignId,
                );
                assert.deepEqual(statuses, [404, 404], 'another calendar\'s export is not readable through this link');
                const crossed = await page.evaluate(async (calendarId, id) => (await fetch(`/calendar/${calendarId}/export/${id}`, { credentials: 'same-origin' })).status, built.id, foreignId);
                assert.equal(crossed, 404, 'nor through another calendar\'s address');
                page.problems = page.problems.filter((p) => !/404/.test(p)); // the refused probe above
                assert.deepEqual(client.problems.filter((p) => !/404/.test(p)), [], 'client console');
                await client.close();
                await anonymous.close();
            });

            // ---- the other ways into the same dialog ----------------------------------------------
            await check('list: a card with a layout has „PDF“ (Stáhnout PDF) and opens the same dialog; a card without a layout has none', async () => {
                const emptyId = await h.createCalendar(page);
                await h.gotoList(page);
                assert.equal(await exists(page, `.cal-card[data-calendar-id="${emptyId}"] [data-cal-action="export-pdf"]`), false, 'no layout → no PDF action');
                const button = `.cal-card[data-calendar-id="${built.id}"] [data-cal-action="export-pdf"]`;
                assert.equal(await page.$eval(button, (el) => el.textContent.trim()), 'PDF');
                assert.equal(await page.$eval(button, (el) => el.getAttribute('title')), 'Stáhnout PDF');
                assert.match(await page.$eval(button, (el) => el.getAttribute('aria-label')), /^Stáhnout PDF – Obecní kalendář$/);
                await h.shot(page, 'pdf-list-cards');
                const listWatch = await watchDownloads(page);
                await openDialog(page, button);
                assert.match(await page.$eval(`${DIALOG} .cal-exp-name`, (el) => el.textContent), /Obecní kalendář/);
                assert.equal(await page.$eval(`${DIALOG} [data-cal-export-unsaved]`, (el) => el.hidden), true);
                await h.shot(page, 'pdf-list-dialog');
                const result = await exportThrough(page, listWatch, 'approval');
                assert.equal(result.file.name, 'Obecní kalendář - náhled.pdf');
                await closeDialog(page);
                await h.deleteCalendar(page, emptyId);
            });

            await check('Dokončit → the list toast offers „Stáhnout PDF“ for that calendar', async () => {
                await h.openCalendar(page, built.id);
                await h.gotoStep(page, 5);
                await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2' }), page.click('[data-cal-action="next-step"]')]);
                await page.waitForSelector('.toast-n [data-cal-toast-action]', { timeout: 8000 });
                assert.equal(await page.$eval('.toast-n [data-cal-toast-action]', (el) => el.textContent.trim()), 'Stáhnout PDF');
                await h.shot(page, 'pdf-list-toast');
                await page.click('.toast-n [data-cal-toast-action]');
                await page.waitForSelector(`${DIALOG}.show[data-cal-export-state="choose"]`, { timeout: 5000 });
                assert.match(await page.$eval(`${DIALOG} .cal-exp-name`, (el) => el.textContent), /Obecní kalendář/);
                await h.sleep(350);
                await closeDialog(page);
            });

            // ---- 14 days ------------------------------------------------------------------------
            await check('14-day calendar: page count = page list, print size, month and week number per column', async () => {
                const two = await buildCalendar(page, h, { type: 'fortnight', photos: 2, bigWidth: 3600, width: 2400, name: 'Čtrnáctidenní' });
                const d = (await h.state(page)).design;
                const watch = await watchDownloads(page);
                await openDialog(page);
                const result = await exportThrough(page, watch, 'print');
                await closeDialog(page);
                const file = keepFile(result.file, 'fortnight-print.pdf');
                if (!POPPLER) throw new Error('SKIPPED — poppler is not installed on this host');
                const info = pdfInfo(file);
                assert.equal(info.pages, two.pages.length);
                near(info.perPage[0].MediaBox.w, d.page.width + 2 * (d.print.bleedMm + 8), 0.02, 'media width (mm)');
                near(info.perPage[0].MediaBox.h, d.page.height + 2 * (d.print.bleedMm + 8), 0.02, 'media height (mm)');
                near(info.perPage[0].TrimBox.w, d.page.width, 0.02, 'trim width');
                const first = two.pages.findIndex((p) => p.kind === 'week') + 1;
                const text = run('pdftotext', ['-f', String(first), '-l', String(first), file, '-']);
                const pages = await h.cal(page, (c) => c.pages().filter((p) => p.kind === 'week').map((p) => ({ months: p.week.monthTexts, weeks: p.week.weekTexts })));
                assert.equal(pages[0].months.length, 2);
                const flat = text.replace(/\s+/g, ' ');
                for (const expected of [...pages[0].weeks]) assert.ok(flat.includes(expected), `„${expected}“ is on the first 14-day page: ${flat.slice(0, 300)}`);
                assert.equal((text.match(/Pondělí/g) || []).length, 2, 'two week columns');
                png(file, first, 'pdf-fortnight-print-first', 110);
                png(file, two.pages.findIndex((p) => p.key === two.big) + 1, 'pdf-fortnight-print-photo', 110);
                await h.deleteCalendar(page, two.id);
            });

            await check('cleanup', async () => {
                if (other) await h.deleteCalendar(page, other.id);
                await h.deleteCalendar(page, built.id);
                assert.equal(psql(`SELECT count(*) FROM calendar_export WHERE calendar_id = '${built.id}'`), '0', 'exports go with the calendar');
            });
        } finally {
            stopConsumer();
        }
    },
};
