/*
 * Own layers on chosen pages (feedback K20 P2 — docs/calendar/custom-layers-plan.md §3–§5,
 * model/layers.js page sets, editor/stage-page.js, render/page.js bands, ui/pagepanel.js).
 *
 * create → Fotka vlevo → step 4: a „Prázdná strana“ right after the cover → its page panel
 * „Přidat vrstvu jen na tuto stranu“ → step 3 on that page with „Přidat“ open → a rectangle there
 * only; the stage page switcher → the cover: only own layers are editable, a layer added there is
 * „jen tato strana“, moved below every built-in it is drawn UNDER the cover's content; a built-in
 * row takes the stage back to the sample; „Na stránkách“ (všechny strany / vybrané / jen tato);
 * `?krok=3&strana=` survives a reload; step 4 „Skrýt zde“ / „Zobrazit zde“; the print PDF: page
 * order and the layers on the cover, the blank page, a week page and the back (pixels).
 */
const assert = require('assert/strict');
const fs = require('fs');
const os = require('os');
const path = require('path');
const { execFileSync, spawn } = require('child_process');
const e = require('./lib/editor.cjs');

const ROOT = path.resolve(__dirname, '../../..');
const DIALOG = '[data-cal-dialog="export"]';
const tool = (name) => {
    try {
        execFileSync('which', [name], { stdio: 'ignore' });
        return true;
    } catch (err) {
        return false;
    }
};
const POPPLER = ['pdfinfo', 'pdftoppm'].every(tool);

/** `[r, g, b]` at a point in mm from the top-left corner of the sheet. */
function pixelAt(file, pageNo, xMm, yMm) {
    const out = execFileSync('pdftoppm', ['-r', '254', '-f', String(pageNo), '-l', String(pageNo), '-x', String(Math.round(xMm * 10)), '-y', String(Math.round(yMm * 10)), '-W', '1', '-H', '1', file], { maxBuffer: 1024 * 1024 });
    return [...out.subarray(out.length - 3)];
}
const rgbOf = (hex) => [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16));
const closeTo = (actual, hex, label) => {
    const want = rgbOf(hex);
    assert.ok(actual.every((v, i) => Math.abs(v - want[i]) <= 12), `${label}: expected ${hex} (${want}), got ${actual}`);
};

module.exports = {
    name: 'layer-pages',
    checks: ['K20 P2'],
    async run({ page, h, check }) {
        const layout = async () => (await h.state(page)).design.layout;
        const ownKeys = async () => (await layout()).order.filter((k) => k.startsWith('u:'));
        const itemOf = async (key) => (await layout()).layers[key.slice(2)];
        const stagePage = async () => (await h.state(page)).stagePage;
        const stageKey = () => page.$eval('.cal-cv svg[data-page]', (el) => el.dataset.page);
        const pages = () => h.cal(page, (c) => c.pages());
        async function addFromOpenMenu(entry) {
            const before = await ownKeys();
            await page.waitForSelector(`[data-layer-add-menu] [data-layer-add="${entry}"]`, { timeout: 5000 });
            await page.click(`[data-layer-add="${entry}"]`);
            await page.waitForFunction((n) => window.__calendar.state().design.layout.order.filter((k) => k.startsWith('u:')).length === n, { timeout: 20000 }, before.length + 1);
            const key = (await ownKeys()).find((k) => !before.includes(k));
            await page.waitForSelector(`[data-props="${key}"]`, { timeout: 5000 });
            return key;
        }
        async function add(entry) {
            await page.click('[data-cal-action="layer-add"]');
            return addFromOpenMenu(entry);
        }
        async function pickStagePage(value) {
            await page.select('select[data-stage-page]', value);
            await page.waitForFunction((v) => document.querySelector('.cal-cv svg[data-page]') && (v === '' || document.querySelector('.cal-cv svg[data-page]').dataset.page === v), { timeout: 5000 }, value);
        }

        let id = null;
        let blankKey = null;
        await check('setup: Fotka vlevo; step 4 „+“ right after the cover › Prázdná strana', async () => {
            const made = await e.newCalendar(page, h, {});
            id = made.id;
            await h.gotoStep(page, 4);
            await page.click('.cal-thins[data-slot="front:0"] [data-cal-action="page-insert"]');
            await page.waitForSelector('.cal-spam [data-special-add-blank]');
            await page.click('.cal-spam [data-special-add-blank]');
            await page.waitForFunction(() => Object.values(window.__calendar.state().content.specialPages || {}).some((p) => p.kind === 'blank'), { timeout: 5000 });
            const content = (await h.state(page)).content;
            const [spId, item] = Object.entries(content.specialPages).find(([, p]) => p.kind === 'blank');
            assert.equal(item.name, 'Prázdná strana');
            assert.equal(item.on, true);
            blankKey = `sp:${spId}`;
            const list = await pages();
            assert.equal(list[1].key, blankKey, 'right after the cover');
            await page.waitForSelector(`[data-page-panel="${blankKey}"] [data-page-layers]`, { timeout: 5000 });
            assert.match(await page.$eval('[data-page-layers]', (el) => el.textContent), /Prázdná strana nemá vlastní obsah/);
            await h.shot(page, 'layer-pages-blank-step4');
        });
        if (!id) return;

        let sticker = null;
        await check('„Přidat vrstvu jen na tuto stranu“ → step 3 on the blank page, „Přidat“ open → a rectangle only there', async () => {
            await page.click('[data-cal-action="page-layer-add"]');
            await page.waitForSelector('.cal-ws[data-cal-shown-step="3"]', { timeout: 10000 });
            assert.equal(await stagePage(), blankKey);
            sticker = await addFromOpenMenu('rect');
            assert.deepEqual((await itemOf(sticker)).pages, [blankKey]);
            assert.equal(await stageKey(), blankKey);
            assert.equal(await page.$$eval(`.cal-cv svg[data-page] [data-layer="${sticker.slice(2)}"]`, (l) => l.length), 1);
            assert.match(await page.$eval('.cal-hint', (el) => el.textContent), /jen vlastní vrstvy/);
            assert.match(page.url(), /krok=3&strana=sp%3A/);
            assert.equal(await page.$eval('select[data-prop="layer.pages"]', (el) => el.value), 'here', 'Na stránkách: jen tato strana');
            await h.shot(page, 'layer-pages-blank-step3');
        });

        let under = null;
        await check('the cover: only own layers are hit; a new layer is „jen tato strana“; below every built-in = under the content', async () => {
            await pickStagePage('cover');
            assert.equal(await stagePage(), 'cover');
            under = await add('ellipse');
            assert.deepEqual((await itemOf(under)).pages, ['cover']);
            const start = (await layout()).boxes[under];
            await h.dragMm(page, { x: start.x + start.w / 2, y: start.y + start.h / 2 }, { x: start.x + start.w / 2 - 30, y: start.y + start.h / 2 + 5 }, { alt: true });
            h.expectBox((await layout()).boxes[under], { x: start.x - 30, y: start.y + 5 }, 0.15, 'dragged on the cover');
            // a click where the week page has the photo: on the cover nothing built-in is selectable
            const photo = (await layout()).boxes.photo;
            await e.clickMm(page, h, { x: photo.x + 3, y: photo.y + 3 });
            const sel = (await h.state(page)).selection;
            assert.ok(!sel || sel.el === 'bg' || sel.el.startsWith('u:'), `no built-in selected: ${JSON.stringify(sel)}`);
            // to the very bottom of the stack: the divider appears and the cover draws it first
            await e.dragRow(page, under, 'photo', true);
            const order = (await layout()).order;
            assert.equal(order[order.length - 1], under, `order ${order.join(',')}`);
            assert.ok(await page.$('[data-layer-under]'), 'the „pod obsahem ostatních stran“ divider');
            const svgOrder = await page.$$eval('.cal-cv svg[data-page] [data-el]', (list) => list.map((n) => (n.dataset.el === 'layer' ? `u:${n.dataset.layer}` : n.dataset.el)));
            assert.ok(svgOrder.indexOf(under) >= 0 && svgOrder.indexOf(under) < svgOrder.indexOf('cover'), `under the cover's content: ${svgOrder.join(',')}`);
            assert.ok((await page.$$eval('[data-layer-item] .cal-elr.away', (l) => l.length)) > 0, 'built-in rows dimmed on the cover');
            await h.shot(page, 'layer-pages-cover-step3');
        });

        let all = null;
        await check('a built-in row goes back to the sample; „Na stránkách“: all pages, chosen pages, only this one', async () => {
            await page.click('[data-layer="logo"]');
            await h.sleep(80);
            assert.equal(await stagePage(), null);
            assert.equal((await h.state(page)).selection.el, 'logo');
            all = await add('rect');
            assert.equal((await itemOf(all)).pages, 'weeks', 'D2: on the sample a new layer is on every week page');
            await page.select(`[data-props="${all}"] select[data-prop="layer.pages"]`, 'pick');
            await h.sleep(80);
            const picked = await itemOf(all);
            assert.ok(Array.isArray(picked.pages) && picked.pages.length === 1, `one page: ${JSON.stringify(picked.pages)}`);
            assert.ok(await page.$(`[data-props="${all}"] [data-layer-page="w05"]`), 'the checklist');
            await page.$eval(`[data-props="${all}"] [data-layer-page="w05"]`, (el) => el.closest('label').click());
            await h.sleep(80);
            assert.ok((await itemOf(all)).pages.includes('w05'));
            await page.select(`[data-props="${all}"] select[data-prop="layer.pages"]`, 'all');
            await h.sleep(80);
            assert.equal((await itemOf(all)).pages, 'all');
            assert.match(await page.$eval(`[data-layer="${all}"]`, (el) => el.textContent), /všude/);
            // to a corner of the page, a whole-page brand mark
            const box = (await layout()).boxes[all];
            await h.dragMm(page, { x: box.x + box.w / 2, y: box.y + box.h / 2 }, { x: 285, y: 128 }, { alt: true });
            const placed = (await layout()).boxes[all];
            assert.ok(placed.x > 200, `moved right: ${JSON.stringify(placed)}`);
            await pickStagePage('back');
            assert.equal(await page.$$eval(`.cal-cv svg[data-page] [data-layer="${all.slice(2)}"]`, (l) => l.length), 1, 'drawn on the back');
            await page.select('select[data-stage-page]', '');
            await h.sleep(80);
        });

        await check('save; `?krok=3&strana=cover` after a reload shows the cover', async () => {
            await h.save(page);
            await pickStagePage('cover');
            assert.match(page.url(), /strana=cover/);
            await h.reload(page);
            await page.waitForSelector('.cal-ws[data-cal-shown-step="3"]', { timeout: 10000 });
            assert.equal(await stagePage(), 'cover');
            await page.waitForFunction(() => document.querySelector('.cal-cv svg[data-page]') && document.querySelector('.cal-cv svg[data-page]').dataset.page === 'cover', { timeout: 5000 });
        });

        await check('step 4 page panel: the layers of the page, „Skrýt zde“ / „Zobrazit zde“ (except), on the undo stack', async () => {
            await h.gotoStep(page, 4);
            const index = (await pages()).findIndex((p) => p.key === 'w05');
            await page.$eval(`[data-page-thumb="${index}"]`, (el) => {
                el.scrollIntoView({ block: 'center' });
                el.click();
            });
            await page.waitForSelector(`[data-page-panel="w05"] [data-page-layer="${all}"]`, { timeout: 5000 });
            await page.click(`[data-page-layer-toggle="${all}"]`);
            await h.sleep(80);
            assert.deepEqual((await itemOf(all)).except, ['w05']);
            await page.waitForFunction((lid) => !document.querySelector(`svg[data-page="w05"] [data-layer="${lid}"]`), { timeout: 5000 }, all.slice(2));
            assert.match(await page.$eval(`[data-page-layer="${all}"]`, (el) => el.textContent), /skrytá na této straně/);
            await h.shot(page, 'layer-pages-step4-hidden');
            await page.click(`[data-page-layer-toggle="${all}"]`);
            await h.sleep(80);
            assert.deepEqual((await itemOf(all)).except, []);
            // design, on the undo stack of the editor (undo / redo are shown in step 3 and „Styl stránek“)
            assert.equal((await h.state(page)).canUndo, true);
            // hidden on w05 for the PDF below
            await page.click(`[data-page-layer-toggle="${all}"]`);
            await h.sleep(80);
            assert.deepEqual((await itemOf(all)).except, ['w05']);
            await h.save(page);
        });

        await check('the print PDF: pages in order, the layers on the cover, the blank page, a week and the back', async () => {
            if (!POPPLER) throw new Error('SKIPPED — poppler (pdfinfo, pdftoppm) is not installed on this host');
            const downloads = fs.mkdtempSync(path.join(os.tmpdir(), 'wb-cal-layerpages-'));
            const consumer = spawn('docker', ['compose', 'exec', '-T', 'web', 'bin/console', 'messenger:consume', 'async', '--time-limit=600', '-q'], { cwd: ROOT, stdio: 'ignore' });
            try {
                await h.gotoStep(page, 5);
                const session = await page.createCDPSession();
                await session.send('Browser.setDownloadBehavior', { behavior: 'allowAndName', downloadPath: downloads, eventsEnabled: true });
                const done = new Promise((resolve, reject) => {
                    const timer = setTimeout(() => reject(new Error('no download in time')), 300000);
                    session.on('Browser.downloadProgress', (event) => {
                        if (event.state !== 'completed') return;
                        clearTimeout(timer);
                        resolve(path.join(downloads, event.guid));
                    });
                });
                await page.$eval('[data-cal-action="export-pdf"]', (el) => el.click());
                await page.waitForSelector(`${DIALOG}.show[data-cal-export-state="choose"]`, { timeout: 5000 });
                await h.sleep(350);
                await page.$eval(`${DIALOG} input[data-cal-export-variant="print"]`, (el) => el.click());
                await page.$eval(`${DIALOG} [data-cal-action="export-start"]`, (el) => el.click());
                await page.waitForSelector(`${DIALOG}[data-cal-export-state="done"]`, { timeout: 300000 });
                const file = await done;
                if (h.config.shots) fs.copyFileSync(file, path.join(h.config.shots, 'layer-pages.pdf'));
                const list = await pages();
                const info = execFileSync('pdfinfo', [file], { encoding: 'utf8' });
                assert.equal(Number(info.match(/Pages:\s+(\d+)/)[1]), list.length, 'one PDF page per calendar page');
                const d = (await h.state(page)).design;
                const B = d.print.bleed ? d.print.bleedMm : 0;
                const M = d.print.marks ? B + 8 : B;
                const l = d.layout;
                const centre = (key) => ({ x: M + l.boxes[key].x + l.boxes[key].w / 2, y: M + l.boxes[key].y + l.boxes[key].h / 2 });
                const at = (pageKey, key) => {
                    const no = list.findIndex((p) => p.key === pageKey) + 1;
                    const c = centre(key);
                    return pixelAt(file, no, c.x, c.y);
                };
                assert.equal(list.findIndex((p) => p.key === blankKey), 1, 'the blank page is page 2');
                closeTo(at(blankKey, sticker), l.layers[sticker.slice(2)].fill.color, 'the rectangle on the blank page');
                for (const key of ['cover', blankKey, 'w03', 'back']) closeTo(at(key, all), l.layers[all.slice(2)].fill.color, `the „všechny strany“ mark on ${key}`);
                const w05 = at('w05', all);
                assert.ok(!w05.every((v, i) => Math.abs(v - rgbOf(l.layers[all.slice(2)].fill.color)[i]) <= 12), 'hidden on w05 (except)');
                if (h.config.shots) {
                    for (const [no, name] of [[1, 'cover'], [2, 'blank']]) execFileSync('pdftoppm', ['-r', '60', '-f', String(no), '-l', String(no), '-png', '-singlefile', file, path.join(h.config.shots, `layer-pages-pdf-${name}`)]);
                }
            } finally {
                try {
                    execFileSync('docker', ['compose', 'exec', '-T', 'web', 'bin/console', 'messenger:stop-workers', '-q'], { cwd: ROOT, stdio: 'ignore' });
                } catch (err) {
                    // it stops at its time limit
                }
                consumer.kill();
            }
        });

        await check('screens (SHOTS): step 3 on the cover with a layer selected, step 4 on the blank page — 1440 / 390, dark / light', async () => {
            if (!h.config.shots) return;
            // the export dialog of the check above is still open
            await page.$eval(`${DIALOG} [data-cal-action="modal-done"]`, (el) => el.click()).catch(() => null);
            await page.waitForFunction((sel) => !document.querySelector(`${sel}.show`), { timeout: 5000 }, DIALOG).catch(() => null);
            const blankIndex = (await pages()).findIndex((p) => p.key === blankKey);
            const go = async (n) => {
                await page.$eval(`[data-cal-step="${n}"]`, (el) => el.click());
                await page.waitForSelector(`.cal-ws[data-cal-shown-step="${n}"]`, { timeout: 15000 });
            };
            const views = [
                [3, async () => {
                    await go(3);
                    await pickStagePage('cover');
                    await page.click(`[data-layer="${all}"]`);
                }],
                [4, async () => {
                    await go(4);
                    await page.$eval(`[data-page-thumb="${blankIndex}"]`, (el) => el.click());
                    await page.waitForSelector(`[data-page-panel="${blankKey}"]`, { timeout: 5000 });
                }],
            ];
            for (const [step, open] of views) {
                await page.setViewport({ width: 1440, height: 900 });
                await open();
                for (const width of [1440, 390]) {
                    await page.setViewport({ width, height: width > 500 ? 900 : 844 });
                    for (const theme of ['dark', 'light']) {
                        await page.evaluate((t) => document.documentElement.setAttribute('data-theme', t), theme);
                        await h.sleep(300);
                        await h.shot(page, `layer-pages-s${step}-${width}-${theme}`);
                    }
                }
            }
            await page.setViewport({ width: 1440, height: 900 });
            await page.evaluate(() => document.documentElement.setAttribute('data-theme', 'dark'));
        });

        await h.deleteCalendar(page, id).catch(() => null);
    },
};
