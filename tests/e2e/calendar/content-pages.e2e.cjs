/*
 * Step 4 „Obsah“: frame, page list, per-page panel, Fotky a popisky, QR kódy, Speciální stránky
 * (phase P6a; handoff C1, C12, G13, E21 + §8 "Content" rows about photos, bulk upload, special
 * pages and large images).
 *
 * create → layout → step 4 → navigation and zoom in mm → page caption / Vrátit na styl (C12) →
 * bulk upload in file-name order, captions from the embedded description, pixel sizes (C1) →
 * warnings on the thumbnail, in the chip and in the panel → per-page fields → special pages:
 * switch on, position, order, layouts, year, uploaded graphic / PDF / text, rename, replace,
 * delete (G13) → one QR code per week → Styl stránek = the step-3 editor (E21). Every data-changing block saves, reloads and asserts again.
 */
const assert = require('assert/strict');
const fs = require('fs');
const os = require('os');
const path = require('path');
const zlib = require('zlib');

// ---- test files (generated; nothing binary lives in the repository) ---------------------------

const CRC_TABLE = Array.from({ length: 256 }, (_, n) => {
    let c = n;
    for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
    return c >>> 0;
});
function crc32(buffer) {
    let c = 0xffffffff;
    for (const byte of buffer) c = CRC_TABLE[(c ^ byte) & 0xff] ^ (c >>> 8);
    return (c ^ 0xffffffff) >>> 0;
}
function chunk(type, data) {
    const head = Buffer.alloc(8);
    head.writeUInt32BE(data.length, 0);
    head.write(type, 4, 'latin1');
    const tail = Buffer.alloc(4);
    tail.writeUInt32BE(crc32(Buffer.concat([head.subarray(4), data])), 0);
    return Buffer.concat([head, data, tail]);
}
/** A plain RGB PNG of one colour with a darker frame; `description` is embedded as XMP `dc:description`. */
function png(width, height, [r, g, b], description) {
    const row = Buffer.alloc(1 + width * 3);
    const edge = Buffer.alloc(1 + width * 3);
    for (let x = 0; x < width; x++) {
        const inside = x >= 8 && x < width - 8;
        row.set(inside ? [r, g, b] : [r >> 1, g >> 1, b >> 1], 1 + x * 3);
        edge.set([r >> 1, g >> 1, b >> 1], 1 + x * 3);
    }
    const rows = [];
    for (let y = 0; y < height; y++) rows.push(y < 8 || y >= height - 8 ? edge : row);
    const header = Buffer.alloc(13);
    header.writeUInt32BE(width, 0);
    header.writeUInt32BE(height, 4);
    header.set([8, 2, 0, 0, 0], 8);
    const chunks = [Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]), chunk('IHDR', header)];
    if (description) {
        const xmp = `<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"><rdf:Description xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:description><rdf:Alt><rdf:li xml:lang="x-default">${description}</rdf:li></rdf:Alt></dc:description></rdf:Description></rdf:RDF></x:xmpmeta>`;
        chunks.push(chunk('iTXt', Buffer.concat([Buffer.from('XML:com.adobe.xmp\0\0\0\0\0', 'latin1'), Buffer.from(xmp, 'utf8')])));
    }
    chunks.push(chunk('IDAT', zlib.deflateSync(Buffer.concat(rows), { level: 6 })), chunk('IEND', Buffer.alloc(0)));
    return Buffer.concat(chunks);
}
/** A one-page PDF of the given size in mm with a filled rectangle. */
function pdf(widthMm, heightMm) {
    const w = ((widthMm * 72) / 25.4).toFixed(2);
    const h = ((heightMm * 72) / 25.4).toFixed(2);
    const stream = `0.2 0.45 0.7 rg 0 0 ${w} ${h} re f 1 1 1 rg 40 40 200 80 re f`;
    const objects = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        `<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ${w} ${h}] /Contents 4 0 R /Resources << >> >>`,
        `<< /Length ${stream.length} >>\nstream\n${stream}\nendstream`,
    ];
    let out = '%PDF-1.4\n';
    const offsets = [];
    objects.forEach((body, i) => {
        offsets.push(out.length);
        out += `${i + 1} 0 obj\n${body}\nendobj\n`;
    });
    const xref = out.length;
    out += `xref\n0 ${objects.length + 1}\n0000000000 65535 f \n${offsets.map((o) => `${String(o).padStart(10, '0')} 00000 n \n`).join('')}`;
    out += `trailer\n<< /Size ${objects.length + 1} /Root 1 0 R >>\nstartxref\n${xref}\n%%EOF\n`;
    return Buffer.from(out, 'latin1');
}

function makeFiles() {
    const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'wboost-cal-e2e-'));
    const write = (name, data) => {
        const file = path.join(dir, name);
        fs.writeFileSync(file, data);
        return file;
    };
    return {
        dir,
        // bulk upload: chosen in a "wrong" order on purpose, plus a file that is not a picture
        p01: write('01.png', png(2600, 1700, [200, 80, 60])),
        p02: write('02.png', png(2640, 1700, [70, 150, 90], 'Rybník za humny, foto: Jana Nová')),
        p10: write('10.png', png(2680, 1700, [60, 90, 190])),
        notes: write('poznamky.txt', 'tohle není fotka'),
        // 310 mm at 300 dpi — a print-size photo
        print: write('tisk.png', png(3661, 2440, [120, 120, 60])),
        small: write('mala.png', png(600, 400, [150, 60, 150])),
        portrait: write('navysku.png', png(1000, 1600, [60, 160, 160])),
        qr: write('qr.png', png(400, 400, [20, 20, 20])),
        graphic: write('Mapa obce.png', png(1240, 580, [230, 200, 120])),
        graphic2: write('jina-grafika.png', png(1240, 580, [120, 200, 230])),
        pagePdf: write('Hotova strana.pdf', pdf(310, 145)),
        text: write('Slovo starosty.txt', 'Milí občané,\nrok utekl jako voda a máme tu nový kalendář.\n\nVáš starosta'),
        text2: write('nahrada.txt', 'Náhradní text stránky.'),
        head: write('hlavicka.png', png(1600, 200, [40, 60, 120])),
    };
}

// ---- page helpers --------------------------------------------------------------------------

const exists = async (page, selector) => (await page.$(selector)) !== null;
const visible = (page, selector) =>
    page.evaluate((s) => {
        const el = document.querySelector(s);
        if (!el) return false;
        const style = getComputedStyle(el);
        const rect = el.getBoundingClientRect();
        return style.display !== 'none' && style.visibility !== 'hidden' && rect.width > 0 && rect.height > 0;
    }, selector);
const text = (page, selector) => page.$eval(selector, (el) => el.textContent.replace(/\s+/g, ' ').trim());
const ui = (page) => page.evaluate(() => document.querySelector('.cal-b5').dataset.view);
/** Text as rendered (hidden parts left out), whitespace collapsed. */
const shown = (page, selector) => page.$eval(selector, (el) => el.innerText.replace(/\s+/g, ' ').trim());
/** Assets of the calendar as the server sent them with the page (id → info). */
const bootAssets = (page) => page.evaluate(() => JSON.parse(document.querySelector('[data-calendar-workspace-target="bootstrap"]').textContent).assets || {});

async function upload(page, selector, ...files) {
    const input = await page.$(selector);
    assert.ok(input, `file input ${selector} is not on the page`);
    await input.uploadFile(...files);
}

async function typeInto(page, selector, value) {
    await page.$eval(selector, (el) => {
        el.focus();
        el.select();
    });
    await page.keyboard.press('Backspace');
    if (value) await page.type(selector, value);
}

async function openTool(page, h, key) {
    await page.click(`[data-tool="${key}"]`);
    await page.waitForSelector(`.cal-tool-modal.show[data-tool-modal="${key}"]`, { timeout: 5000 });
    await h.sleep(350);
}

async function closeTool(page, h) {
    await page.click('.cal-tool-modal.show [data-cal-action="modal-done"]');
    await page.waitForFunction(() => !document.querySelector('.cal-tool-modal'), { timeout: 5000 });
    await h.sleep(100);
}

/** Waits until `fn(window.__calendar, ...args)` is truthy. */
function waitCal(page, fn, ...args) {
    return page.waitForFunction(`(${fn.toString()})(window.__calendar, ...${JSON.stringify(args)})`, { timeout: 20000 });
}

async function showPage(page, index) {
    // the list is rebuilt (debounced) when the page list changes
    await page.waitForSelector(`[data-page-thumb="${index}"]`);
    await page.click(`[data-page-thumb="${index}"]`);
    await page.waitForFunction((i) => document.querySelector(`[data-page-thumb="${i}"]`)?.getAttribute('aria-current') === 'true', {}, index);
    await page.waitForSelector('.cal-m4c svg');
}

/** Markup of the page drawn in the middle (after the pending frame). */
async function stageSvg(page) {
    await page.evaluate(() => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve))));
    return page.$eval('.cal-m4c .cal-page-svg', (el) => el.innerHTML);
}

async function openStep4(page, h, id) {
    await h.openCalendar(page, id);
    if ((await page.$eval('.cal-ws', (el) => el.dataset.calShownStep)) !== '4') await h.gotoStep(page, 4);
    await page.waitForSelector('.cal-b5 [data-page-thumb="0"]');
}

module.exports = {
    name: 'content-pages',
    checks: ['C1', 'C12', 'G13', 'E21'],
    async run({ page, h, check }) {
        const files = makeFiles();
        // a failed check may leave unsaved changes behind: never hang on the browser's leave prompt
        page.on('dialog', (dialog) => dialog.accept().catch(() => null));
        let id = null;
        let weeks = [];
        let total = 0;
        const refresh = async () => {
            const pages = await h.cal(page, (c) => c.pages());
            weeks = pages.filter((p) => p.kind === 'week');
            total = pages.length;
            return pages;
        };

        await check('setup: a 7-day calendar with Fotka vlevo opens step 4', async () => {
            id = await h.createCalendar(page);
            await h.gotoStep(page, 2);
            await page.click('button[data-preset="photoLeft"]');
            await page.waitForSelector('.cal-ws[data-cal-shown-step="3"]');
            await h.gotoStep(page, 4);
            await page.waitForSelector('.cal-b5 [data-page-thumb="0"]');
            await refresh();
            assert.ok(weeks.length >= 52);
        });
        if (!id) return;

        await check('step-4 frame: tools, list entries, one thumbnail per page with its number', async () => {
            assert.deepEqual(await page.$$eval('.cal-t4 [data-tool]', (nodes) => nodes.map((n) => n.dataset.tool)), ['photos', 'waste', 'events', 'names', 'moon', 'shifts', 'qr']);
            assert.equal(await ui(page), 'style', 'Styl stránek is the first view');
            assert.equal(await page.$eval('[data-content-view="style"]', (el) => el.getAttribute('aria-current')), 'true');
            assert.equal(await text(page, '[data-content-view="style"] .cal-thn'), 'Styl stránek');
            assert.equal(await text(page, '[data-content-view="special"] .cal-thn'), 'Speciální stránky');
            await page.waitForSelector('[data-content-view="style"] svg');
            assert.equal(await page.$eval('.cal-b5-list .cal-thl', (el) => el.getAttribute('aria-label')), 'Strany kalendáře');
            assert.equal(await page.$eval('.cal-b5-side', (el) => el.getAttribute('aria-label')), 'Úpravy');
            const thumbs = await page.$$eval('[data-page-thumb]', (nodes) => nodes.map((n) => ({ index: n.dataset.pageThumb, number: n.querySelector('.cal-thno').textContent, label: n.getAttribute('aria-label') })));
            assert.equal(thumbs.length, total);
            thumbs.forEach((thumb, i) => {
                assert.equal(thumb.index, String(i));
                assert.equal(thumb.number, String(i + 1));
            });
            assert.equal(thumbs[0].label, 'Titulní strana');
            assert.equal(thumbs[total - 1].label, 'Zadní strana');
            assert.equal(thumbs[1].label, `${weeks[0].title}, ${weeks[0].subtitle}`);
            // thumbnails are rendered lazily: the first ones are drawn, the last ones only after scrolling
            await page.waitForSelector('[data-page-thumb="0"] svg');
            assert.equal(await exists(page, `[data-page-thumb="${total - 1}"] svg`), false, 'a thumbnail far below is not rendered yet');
            await page.$eval(`[data-page-thumb="${total - 1}"]`, (el) => el.scrollIntoView());
            await page.waitForSelector(`[data-page-thumb="${total - 1}"] svg`);
            await page.$eval('[data-page-thumb="0"]', (el) => el.scrollIntoView());
            assert.equal(await page.$eval('.cal-b5-side', (el) => el.textContent.includes('Změny se projeví na všech stranách kalendáře.')), true);
        });

        await check('middle: selected page with previous / next and zoom; the page measures 310 mm at every zoom', async () => {
            await showPage(page, 1);
            assert.equal(await ui(page), 'page');
            assert.equal(await text(page, '[data-page-title]'), weeks[0].title);
            assert.equal(await text(page, '.cal-m4t'), `${weeks[0].title} · ${weeks[0].subtitle}`);
            assert.equal(await page.$eval('[data-cal-action="page-prev"]', (el) => el.getAttribute('aria-label')), 'Předchozí strana');
            assert.equal(await page.$eval('[data-cal-action="page-next"]', (el) => el.getAttribute('aria-label')), 'Další strana');
            const geometry = await h.cal(page, (c) => ({ page: c.page(), scale: c.scale(), origin: c.toClient(0, 0), corner: c.toClient(c.page().width, c.page().height), has: Boolean(c.geometry()) }));
            assert.ok(geometry.scale > 1, 'the stage probe reports a scale');
            assert.equal(geometry.has, true, 'geometry of the shown week page');
            const rect = await page.$eval('.cal-m4c svg', (el) => {
                const r = el.getBoundingClientRect();
                return { x: r.x, y: r.y, w: r.width, h: r.height, viewBox: el.getAttribute('viewBox') };
            });
            assert.equal(rect.viewBox, `0 0 ${geometry.page.width} ${geometry.page.height}`, 'the page is shown trimmed');
            assert.ok(Math.abs(rect.w / geometry.scale - geometry.page.width) < 0.3, `drawn width ${rect.w / geometry.scale} mm`);
            assert.ok(Math.abs(rect.h / geometry.scale - geometry.page.height) < 0.3);
            assert.ok(Math.abs(geometry.origin.x - rect.x) < 0.6 && Math.abs(geometry.corner.x - (rect.x + rect.w)) < 0.6);

            await page.click('[data-cal-action="page-next"]');
            await page.waitForFunction(() => document.querySelector('[data-page-thumb="2"]').getAttribute('aria-current') === 'true');
            assert.equal(await text(page, '[data-page-title]'), weeks[1].title);
            await page.click('[data-cal-action="page-prev"]');
            await page.click('[data-cal-action="page-prev"]');
            assert.equal(await text(page, '[data-page-title]'), 'Titulní strana');
            assert.equal(await page.$eval('[data-cal-action="page-prev"]', (el) => el.disabled), true, 'no page before the cover');
            // the cover carries its own content fields (special-content.e2e.cjs); it never says "nothing to fill in"
            assert.equal(await shown(page, '.cal-p4h'), `Titulní strana strana 1 z ${total}`);
            assert.deepEqual(await page.$$eval('.cal-p4b [data-page-content="cover"] [data-content-field]', (nodes) => nodes.map((n) => n.dataset.contentField)), ['title', 'subtitle', 'note']);
            assert.doesNotMatch(await shown(page, '.cal-p4b'), /nemá nic k doplnění/);
            await showPage(page, total - 1);
            assert.equal(await page.$eval('[data-cal-action="page-next"]', (el) => el.disabled), true, 'no page after the back page');
            assert.match(await stageSvg(page), /PŘEHLED ROKU \d{4}/);

            await showPage(page, 1);
            const before = await h.cal(page, (c) => c.scale());
            await page.click('[data-cal-action="zoom-in"]');
            await page.waitForFunction((s) => window.__calendar.scale() > s * 1.2, {}, before);
            const zoomed = await h.cal(page, (c) => ({ scale: c.scale(), zoom: c.state().zoom }));
            assert.equal(zoomed.zoom, 1.25);
            assert.ok(Math.abs(zoomed.scale / before - 1.25) < 0.01);
            assert.equal(await text(page, '[data-cal-action="zoom-fit"]'), '125 %');
            const width = await page.$eval('.cal-m4c svg', (el) => el.getBoundingClientRect().width);
            assert.ok(Math.abs(width / zoomed.scale - geometry.page.width) < 0.3, 'still 310 mm wide in mm');
            await page.click('[data-cal-action="zoom-fit"]');
            await page.waitForFunction(() => window.__calendar.state().zoom === 1);
            await h.shot(page, 'step4-page');
        });

        await check('C12 page caption: on the page, dot on the thumbnail, pill; kept after save + reload; Vrátit na styl removes both', async () => {
            await showPage(page, 3);
            const key = weeks[2].key;
            assert.equal(await page.$eval('.cal-p4b', (el) => el.dataset.pagePanel), key);
            assert.equal(await text(page, '.cal-p4h .cap'), `${weeks[2].subtitle} · strana 4 z ${total}`);
            assert.equal(await visible(page, '[data-page-pill]'), false);
            assert.equal(await visible(page, '[data-cal-action="page-reset"]'), false);
            assert.equal(await page.$eval('[data-page-field="caption"]', (el) => `${el.placeholder}|${el.maxLength}|${el.getAttribute('aria-label')}`), 'Text popisku|80|Popisek fotografie');
            await page.type('[data-page-field="caption"]', 'Kaple sv. Anny, foto: obecní archiv');
            assert.equal(await page.$eval('[data-page-field="caption"]', (el) => document.activeElement === el), true, 'typing keeps the focus in the field');
            assert.deepEqual((await h.state(page)).content.pageCaptions[key], { text: 'Kaple sv. Anny, foto: obecní archiv' });
            assert.equal(await visible(page, '[data-page-pill]'), true, 'pill Upraveno');
            assert.equal(await text(page, '[data-page-pill]'), 'Upraveno');
            assert.match(await stageSvg(page), /Kaple sv\. Anny, foto: obecní archiv/);
            await page.waitForSelector('[data-page-thumb="3"][data-overridden]');
            assert.equal(await visible(page, '[data-page-thumb="3"] .cal-thd'), true, 'dot on the thumbnail');
            assert.equal(await page.$$eval('[data-page-thumb][data-overridden]', (nodes) => nodes.length), 1);
            // 80 characters at most
            await typeInto(page, '[data-page-field="caption"]', 'x'.repeat(95));
            assert.equal((await h.state(page)).content.pageCaptions[key].text.length, 80);
            await typeInto(page, '[data-page-field="caption"]', 'Kaple sv. Anny');
            await page.keyboard.press('Enter');
            await h.shot(page, 'step4-caption');

            await h.save(page);
            await openStep4(page, h, id);
            await showPage(page, 3);
            assert.deepEqual((await h.state(page)).content.pageCaptions[key], { text: 'Kaple sv. Anny' });
            assert.equal(await page.$eval('[data-page-field="caption"]', (el) => el.value), 'Kaple sv. Anny');
            assert.match(await stageSvg(page), /Kaple sv\. Anny/);
            assert.equal(await exists(page, '[data-page-thumb="3"][data-overridden]'), true);

            assert.equal(await text(page, '[data-cal-action="page-reset"]'), 'Vrátit na styl');
            await page.click('[data-cal-action="page-reset"]');
            assert.equal((await h.state(page)).content.pageCaptions[key], undefined);
            assert.equal(await page.$eval('[data-page-field="caption"]', (el) => el.value), '');
            assert.equal(await visible(page, '[data-page-pill]'), false);
            assert.equal(await visible(page, '[data-cal-action="page-reset"]'), false);
            assert.doesNotMatch(await stageSvg(page), /Kaple sv\. Anny/);
            await page.waitForFunction(() => !document.querySelector('[data-page-thumb][data-overridden]'));
            await h.save(page);
            await openStep4(page, h, id);
            assert.equal((await h.state(page)).content.pageCaptions[key], undefined);
            assert.equal(await exists(page, '[data-page-thumb][data-overridden]'), false);
        });

        await check('C1 bulk upload: 01, 02, 10 land on weeks 1, 2, 3 in file-name order; counter 3 of N; caption from the description; pixel sizes recorded', async () => {
            await openTool(page, h, 'photos');
            assert.equal(await text(page, '.cal-dpx b'), 'Nahrát všechny fotky najednou');
            assert.equal(await text(page, '.cal-dpx .cap'), 'Vyberte všechny fotky ze složky — přiřadí se k týdnům podle pořadí názvů souborů. Mají-li fotky vyplněný popisek, doplní se i popisky.');
            assert.equal(await page.$$eval('[data-photo-row]', (nodes) => nodes.length), weeks.length, 'one row per week');
            assert.equal(await text(page, '[data-photo-count]'), `Fotky: 0 z ${weeks.length}`);
            assert.equal(await page.$eval(`[data-photo-row="${weeks[0].key}"] [data-photo-file]`, (el) => el.getAttribute('aria-label')), `Fotka: ${weeks[0].title}`);
            assert.equal(await page.$eval(`[data-photo-row="${weeks[0].key}"] [data-photo-caption]`, (el) => `${el.placeholder}|${el.getAttribute('aria-label')}`), `Popisek fotky|Popisek: ${weeks[0].title}`);

            await upload(page, '[data-photo-bulk]', files.p10, files.notes, files.p02, files.p01);
            await waitCal(page, (c) => Object.keys(c.state().content.pagePhotos).length === 3);
            await page.waitForFunction(() => document.querySelector('[data-photo-progress]').hidden);
            const state = await h.state(page);
            assert.deepEqual(Object.keys(state.content.pagePhotos).sort(), weeks.slice(0, 3).map((w) => w.key).sort(), 'the first three weeks');
            for (const week of weeks.slice(0, 3)) {
                const photo = state.content.pagePhotos[week.key];
                assert.deepEqual([photo.zoom, photo.px, photo.py], [1, 0.5, 0.5], 'centred at 100 %');
            }
            assert.equal(await text(page, '[data-photo-count]'), `Fotky: 3 z ${weeks.length}`);
            assert.equal(await page.$$eval('[data-photo-row] .cal-ft.has img', (nodes) => nodes.length), 3);
            assert.deepEqual(state.content.pageCaptions, { [weeks[1].key]: { text: 'Rybník za humny, foto: Jana Nová' } }, 'the caption comes from the description embedded in 02');
            assert.equal(await page.$eval(`[data-photo-row="${weeks[1].key}"] [data-photo-caption]`, (el) => el.value), 'Rybník za humny, foto: Jana Nová');
            await h.shot(page, 'tool-photos');
            await closeTool(page, h);

            await h.save(page);
            await openStep4(page, h, id);
            const assets = await bootAssets(page);
            const saved = (await h.state(page)).content.pagePhotos;
            const names = weeks.slice(0, 3).map((week) => assets[saved[week.key].asset].name);
            assert.deepEqual(names, ['01.png', '02.png', '10.png'], 'file-name order from the first week');
            assert.deepEqual(weeks.slice(0, 3).map((week) => [assets[saved[week.key].asset].width, assets[saved[week.key].asset].height]), [[2600, 1700], [2640, 1700], [2680, 1700]], 'pixel size of every bulk upload');
            for (const index of [1, 2, 3]) await page.waitForSelector(`[data-page-thumb="${index}"][data-overridden]`);
            await showPage(page, 1);
            assert.match(await stageSvg(page), /data-el="photo"[\s\S]*<image /, 'the photo is drawn on its page');
        });

        await check('photo row: replace resets the crop, a print-size photo uploads with its pixel size, remove empties the row', async () => {
            await openTool(page, h, 'photos');
            const key = weeks[0].key;
            const first = (await h.state(page)).content.pagePhotos[key].asset;
            assert.equal(await page.$eval(`[data-photo-row="${key}"] .cal-ft`, (el) => el.title), 'Vyměnit fotku');
            assert.equal(await page.$eval(`[data-photo-row="${weeks[5].key}"] .cal-ft`, (el) => el.title), 'Nahrát fotku');
            await upload(page, `[data-photo-row="${key}"] [data-photo-file]`, files.print);
            await waitCal(page, (c, k, old) => c.state().content.pagePhotos[k].asset !== old, key, first);
            const photo = (await h.state(page)).content.pagePhotos[key];
            assert.deepEqual([photo.zoom, photo.px, photo.py], [1, 0.5, 0.5]);
            // caption typed in the row
            await page.type(`[data-photo-row="${weeks[5].key}"] [data-photo-caption]`, 'Hasičská zbrojnice');
            assert.deepEqual((await h.state(page)).content.pageCaptions[weeks[5].key], { text: 'Hasičská zbrojnice' });
            // remove the photo of week 3
            assert.equal(await page.$eval(`[data-photo-row="${weeks[2].key}"] [data-photo-remove]`, (el) => el.getAttribute('aria-label')), 'Odebrat fotku');
            await page.click(`[data-photo-row="${weeks[2].key}"] [data-photo-remove]`);
            assert.equal((await h.state(page)).content.pagePhotos[weeks[2].key], undefined);
            assert.equal(await text(page, '[data-photo-count]'), `Fotky: 2 z ${weeks.length}`);
            assert.equal(await visible(page, `[data-photo-row="${weeks[2].key}"] [data-photo-remove]`), false);
            await closeTool(page, h);
            await h.save(page);
            await openStep4(page, h, id);
            const assets = await bootAssets(page);
            const saved = (await h.state(page)).content;
            assert.deepEqual([assets[saved.pagePhotos[key].asset].name, assets[saved.pagePhotos[key].asset].width, assets[saved.pagePhotos[key].asset].height], ['tisk.png', 3661, 2440]);
            assert.ok(assets[saved.pagePhotos[key].asset].previewUrl, 'a print-size photo gets a preview derivative');
            assert.equal(saved.pagePhotos[weeks[2].key], undefined);
            assert.deepEqual(saved.pageCaptions[weeks[5].key], { text: 'Hasičská zbrojnice' });
            assert.deepEqual((await h.cal(page, (c) => c.warnings())).filter((w) => w.pageIndex === 1), [], 'a 3661 px photo raises no warning');
        });

        await check('warnings: a small and a portrait photo mark the thumbnail, the chip jumps to the next page with a warning, the panel lists them', async () => {
            await openTool(page, h, 'photos');
            await upload(page, `[data-photo-row="${weeks[3].key}"] [data-photo-file]`, files.small);
            await waitCal(page, (c, k) => Boolean(c.state().content.pagePhotos[k]), weeks[3].key);
            await upload(page, `[data-photo-row="${weeks[6].key}"] [data-photo-file]`, files.portrait);
            await waitCal(page, (c, k) => Boolean(c.state().content.pagePhotos[k]), weeks[6].key);
            await closeTool(page, h);
            const warnings = await h.cal(page, (c) => c.warnings());
            const pagesWithWarnings = [...new Set(warnings.map((w) => w.pageIndex))].sort((a, b) => a - b);
            assert.ok(pagesWithWarnings.includes(4) && pagesWithWarnings.includes(7), `pages with warnings: ${pagesWithWarnings}`);
            assert.ok(warnings.some((w) => w.pageIndex === 4 && w.kind === 'dpi'));
            assert.ok(warnings.some((w) => w.pageIndex === 7 && w.kind === 'portrait'));
            await page.waitForSelector('[data-page-thumb="4"][data-warn]');
            assert.equal(await page.$eval('[data-page-thumb="4"] .cal-wb', (el) => `${el.textContent}|${el.getAttribute('aria-label')}|${el.hidden}`), `!|${warnings.filter((w) => w.pageIndex === 4).length} upozornění|false`);
            assert.equal(await exists(page, '[data-page-thumb="2"][data-warn]'), false);
            await page.waitForFunction(() => !document.querySelector('[data-cal-action="warnings"]').hidden);
            assert.equal(await text(page, '[data-cal-action="warnings"]'), `${warnings.length} upozornění`);

            // from Styl stránek the chip goes to the first page with a warning, then on, then wraps round
            await page.click('[data-content-view="style"]');
            const seen = [];
            for (let i = 0; i < pagesWithWarnings.length + 1; i++) {
                await page.click('[data-cal-action="warnings"]');
                await h.sleep(60);
                seen.push(await page.$eval('[data-page-thumb][aria-current="true"]', (el) => Number(el.dataset.pageThumb)));
            }
            assert.deepEqual(seen, [...pagesWithWarnings, pagesWithWarnings[0]]);
            await showPage(page, 4);
            const listed = await page.$$eval('[data-page-warning]', (nodes) => nodes.map((n) => n.textContent.replace(/^!/, '').trim()));
            assert.deepEqual(listed, warnings.filter((w) => w.pageIndex === 4).map((w) => w.message));
            assert.equal(await text(page, '.cal-pw .lbl'), 'Upozornění');
            await h.shot(page, 'step4-warnings');
            // warnings never block saving
            await h.save(page);
        });

        await check('page panel shows only the fields that apply; own header / background picture and own photo per page', async () => {
            await showPage(page, 2);
            let labels = await page.$$eval('.cal-p4b > .cal-p4s:not(.cal-pw) > .lbl', (nodes) => nodes.map((n) => n.textContent));
            assert.deepEqual(labels, ['Fotografie', 'Popisek fotografie'], 'default style: the client supplies photos, no picture slots, no QR');
            assert.equal(await page.$eval('.cal-p4b', (el) => el.textContent.includes('Fotky doplní klient.')), true);
            assert.equal(await exists(page, '[data-page-field="photo"]'), false);

            // the style switches the photo to „Doplním sám“ and enables the header and background pictures
            const layout = (await h.state(page)).design.layout;
            layout.styles.photo.source = 'self';
            layout.styles.head.bg.imageOn = true;
            layout.styles.bg.imageOn = true;
            await h.seed(page, { design: { layout } });
            await openStep4(page, h, id);
            await showPage(page, 2);
            labels = await page.$$eval('.cal-p4b > .cal-p4s:not(.cal-pw) > .lbl', (nodes) => nodes.map((n) => n.textContent));
            assert.deepEqual(labels, ['Fotografie', 'Popisek fotografie', 'Obrázek v hlavičce', 'Fotka pozadí']);
            assert.equal(await text(page, '.cal-p4s:has([data-page-field="head"]) .cal-ftb .cap'), 'Výchozí ze stylu');
            const key = weeks[1].key;
            await upload(page, '[data-page-field="head"]', files.head);
            await waitCal(page, (c, k) => Boolean((c.state().content.pageImages[k] || {}).head), key);
            assert.equal(await text(page, '.cal-p4s:has([data-page-field="head"]) .cal-ftb .cap'), 'Jen pro tuto stranu');
            assert.match(await stageSvg(page), /data-el="head"[\s\S]*?<image /, 'the page uses its own header picture');
            await upload(page, '[data-page-field="bg"]', files.graphic);
            await waitCal(page, (c, k) => Boolean((c.state().content.pageImages[k] || {}).bg), key);
            // own photo of the page: replaces the bulk-uploaded one, crop reset, pixel size from the server
            const old = (await h.state(page)).content.pagePhotos[key].asset;
            await upload(page, '[data-page-field="photo"]', files.print);
            await waitCal(page, (c, k, o) => c.state().content.pagePhotos[k].asset !== o, key, old);
            await h.save(page);
            await openStep4(page, h, id);
            const saved = (await h.state(page)).content;
            const assets = await bootAssets(page);
            assert.equal(assets[saved.pageImages[key].head].name, 'hlavicka.png');
            assert.equal(assets[saved.pageImages[key].bg].name, 'Mapa obce.png');
            assert.deepEqual([assets[saved.pagePhotos[key].asset].width, assets[saved.pagePhotos[key].asset].height], [3661, 2440], 'pixel size recorded for the page-panel upload');

            // the cover has a background slot only; removing one picture keeps the other
            await showPage(page, 0);
            // (its own content — title, photo, logo — sits above, in `.cal-p4c`: special-content.e2e.cjs)
            assert.deepEqual(await page.$$eval('.cal-p4b > .cal-p4s > .lbl', (nodes) => nodes.map((n) => n.textContent)), ['Fotka pozadí']);
            await showPage(page, 2);
            await page.click('[data-page-remove="head"]');
            assert.deepEqual((await h.state(page)).content.pageImages[key], { head: null, bg: saved.pageImages[key].bg });
            await page.click('[data-cal-action="page-reset"]');
            const after = (await h.state(page)).content;
            for (const collection of ['pagePhotos', 'pageCaptions', 'pageImages', 'pageQr']) assert.equal(after[collection][key], undefined, collection);
            await h.save(page);
        });

        await check('G13 special pages: switching one on adds a page at the chosen position; position and order change the list; numbers update', async () => {
            await page.click('[data-content-view="special"]');
            assert.equal(await ui(page), 'special');
            assert.equal(await text(page, '.cal-p4h b'), 'Speciální stránky');
            assert.equal(await text(page, '.cal-spb .cap'), 'Speciální stránky jsou vypnuté.');
            assert.equal(await text(page, '[data-page-title]'), 'Speciální stránky');
            await page.click('.cal-b5-side [data-cal-action="feature-on"]');
            await h.waitToast(page, 'Zapnuto');
            assert.equal((await h.state(page)).design.features.specialPages, true);
            assert.equal(await text(page, '.cal-spb > .cap'), 'Zaškrtnuté stránky se vygenerují automaticky. Pořadí změníte přetažením.');
            assert.deepEqual(await page.$$eval('[data-special]', (nodes) => nodes.map((n) => `${n.dataset.special}:${n.querySelector('.cal-check-t').textContent}`)), ['intro:Úvodní slovo', 'contacts:Důležité kontakty', 'year:Roční kalendárium', 'waste:Plánovací kalendář svozu odpadů', 'notes:Poznámky']);
            assert.equal((await h.cal(page, (c) => c.pages())).length, total, 'nothing is switched on by the feature itself');
            assert.equal(await visible(page, '[data-special-empty]'), true);
            assert.equal(await text(page, '[data-special-empty]'), 'Zapněte některou speciální stránku vpravo.');

            // Úvodní slovo on → second page
            await page.click('[data-special="intro"] label.cal-check');
            let pages = await h.cal(page, (c) => c.pages());
            assert.equal(pages.length, total + 1);
            assert.deepEqual([pages[1].key, pages[1].title, pages[1].subtitle], ['sp:intro', 'Úvodní slovo', 'Speciální strana']);
            await page.waitForFunction((n) => document.querySelectorAll('[data-page-thumb]').length === n, {}, total + 1);
            assert.equal(await shown(page, '[data-page-thumb="1"] .cal-thn b'), '2 Úvodní slovo');
            assert.equal(await text(page, '[data-page-thumb="2"] .cal-thno'), '3', 'the first week moved to page 3');
            assert.equal(await text(page, '[data-page-title]'), 'Úvodní slovo');
            assert.match(await stageSvg(page), /ÚVODNÍ SLOVO/);
            assert.equal(await page.$eval('[data-special="intro"]', (el) => el.classList.contains('on') && el.classList.contains('sel')), true);
            // the page's texts are edited on the page itself: „Upravit obsah“ leads there (special-content.e2e.cjs)
            assert.equal(await text(page, '[data-special="intro"] [data-special-edit]'), 'Upravit obsah');
            assert.equal(await exists(page, '[data-special="contacts"] [data-special-edit]'), false, 'not while the page is off');

            // layouts as thumbnails
            assert.deepEqual(await page.$$eval('[data-special="intro"] [data-special-layout] .cap', (nodes) => nodes.map((n) => n.textContent)), ['Fotka + text', 'Jen text', 'Portrét + dva sloupce']);
            assert.equal(await page.$$eval('[data-special="intro"] [data-special-layout] svg', (nodes) => nodes.length), 3);
            assert.equal(await page.$eval('[data-special="intro"] [role="radiogroup"]', (el) => el.getAttribute('aria-label')), 'Rozvržení: Úvodní slovo');
            await page.click('[data-special="intro"] [data-special-layout="2"]');
            assert.equal((await h.state(page)).content.specialPages.intro.layout, 2);
            assert.match(await stageSvg(page), /PORTRÉT/);
            assert.equal(await page.$eval('[data-special="intro"] [data-special-layout="2"]', (el) => el.getAttribute('aria-checked')), 'true');

            // position: before the back page
            assert.deepEqual(await page.$$eval('[data-special="intro"] [data-special-position] button', (nodes) => nodes.map((n) => n.textContent)), ['Za titulní stranou', 'Před zadní stranou']);
            await page.click('[data-special="intro"] [data-special-position] [data-value="back"]');
            pages = await h.cal(page, (c) => c.pages());
            assert.equal(pages[total - 1].key, 'sp:intro', 'second to last');
            assert.equal(pages[1].kind, 'week');
            await page.waitForFunction((i) => document.querySelector(`[data-page-thumb="${i}"] .cal-thn b`)?.textContent.includes('Úvodní slovo'), {}, total - 1);
            assert.equal(await text(page, `[data-page-thumb="${total - 1}"] .cal-thno`), String(total));
            await page.click('[data-special="intro"] [data-special-position] [data-value="front"]');

            // Důležité kontakty on → behind Úvodní slovo; dragging it above changes the order
            await page.click('[data-special="contacts"] label.cal-check');
            pages = await h.cal(page, (c) => c.pages());
            assert.deepEqual(pages.slice(1, 3).map((p) => p.key), ['sp:intro', 'sp:contacts']);
            const grip = await (await page.$('[data-special="contacts"] .cal-grip')).boundingBox();
            const target = await (await page.$('[data-special="intro"]')).boundingBox();
            await page.mouse.move(grip.x + grip.width / 2, grip.y + grip.height / 2);
            await page.mouse.down();
            await page.mouse.move(grip.x + grip.width / 2, grip.y - 10, { steps: 4 });
            await page.mouse.move(target.x + target.width / 2, target.y + 6, { steps: 14 });
            await h.sleep(150);
            await page.mouse.up();
            await waitCal(page, (c) => c.pages()[1].key === 'sp:contacts');
            pages = await h.cal(page, (c) => c.pages());
            assert.deepEqual(pages.slice(1, 3).map((p) => p.key), ['sp:contacts', 'sp:intro']);
            assert.deepEqual(await page.$$eval('[data-special]', (nodes) => nodes.map((n) => n.dataset.special)), ['contacts', 'intro', 'year', 'waste', 'notes']);
            await page.waitForFunction(() => document.querySelector('[data-page-thumb="1"] .cal-thn b')?.textContent.includes('Důležité kontakty'));

            // Náhled chooses the page in the middle
            await page.click('[data-special="contacts"] [data-special-preview]');
            assert.equal(await text(page, '[data-page-title]'), 'Důležité kontakty');
            assert.match(await stageSvg(page), /DŮLEŽITÉ KONTAKTY/);
            assert.equal(await page.$eval('[data-special="contacts"] [data-special-preview]', (el) => el.getAttribute('aria-pressed')), 'true');
            await h.shot(page, 'step4-special');

            await h.save(page);
            await openStep4(page, h, id);
            pages = await h.cal(page, (c) => c.pages());
            assert.deepEqual(pages.slice(0, 4).map((p) => p.key), ['cover', 'sp:contacts', 'sp:intro', weeks[0].key]);
            assert.equal((await h.state(page)).content.specialPages.intro.layout, 2);
            assert.equal(pages.length, total + 2);
        });

        await check('Roční kalendárium: this / next year, back page turns to client contacts; the plus menu adds the waste plan', async () => {
            await page.click('[data-content-view="special"]');
            await page.waitForSelector('[data-special="year"]');
            await page.click('[data-special="year"] label.cal-check');
            const year = (await h.state(page)).design.year;
            let pages = await h.cal(page, (c) => c.pages());
            const back = pages[pages.length - 1];
            assert.deepEqual([back.backVariant, back.subtitle], ['contacts', 'Kontakty klienta']);
            assert.deepEqual(await page.$$eval('[data-special="year"] [data-special-year] button', (nodes) => nodes.map((n) => n.textContent)), [`Rok ${year}`, `Následující rok ${year + 1}`]);
            assert.equal((await h.state(page)).content.specialPages.year.nextYear, true, 'the following year by default');
            assert.match(await stageSvg(page), new RegExp(`ROČNÍ KALENDÁRIUM ${year + 1}`));
            await page.click('[data-special="year"] [data-special-year] [data-value="0"]');
            assert.match(await stageSvg(page), new RegExp(`ROČNÍ KALENDÁRIUM ${year}<`));
            assert.equal((await stageSvg(page)).match(/data-month="/g).length, 12);
            await page.click('[data-special="year"] [data-special-layout="2"]');
            assert.match(await stageSvg(page), new RegExp(`ROČNÍ KALENDÁRIUM ${year} / ${year + 1}`));
            assert.equal((await stageSvg(page)).match(/data-month="/g).length, 24);
            await showPage(page, pages.length - 1);
            const backSvg = await stageSvg(page);
            assert.match(backSvg, /data-variant="contacts"/);
            assert.doesNotMatch(backSvg, /PŘEHLED ROKU/);

            // plus menu: the generated kinds that are not on, then the two uploads
            await page.click('[data-cal-action="special-add"]');
            await page.waitForSelector('.cal-spam');
            assert.equal(await page.$eval('[data-cal-action="special-add"]', (el) => `${el.getAttribute('aria-label')}|${el.getAttribute('aria-expanded')}`), 'Přidat speciální stránku|true');
            const menu = await page.$$eval('.cal-spam > *', (nodes) => nodes.map((n) => n.textContent.replace(/\s+/g, ' ').trim()));
            assert.deepEqual(menu, ['Plánovací kalendář svozu odpadůVygeneruje se automaticky', 'PoznámkyVygeneruje se automaticky', 'Nahrát grafikuHotová strana jako PDF nebo obrázek', 'Text z WorduVysází se do stylu kalendáře']);
            await h.shot(page, 'step4-plus-menu');
            // Esc closes the menu, not the view
            await page.keyboard.press('Escape');
            await page.waitForFunction(() => !document.querySelector('.cal-spam'));
            await page.click('[data-cal-action="special-add"]');
            await page.waitForSelector('.cal-spam [data-special-add="waste"]');
            await page.click('.cal-spam [data-special-add="waste"]');
            await page.waitForFunction(() => !document.querySelector('.cal-spam'));
            assert.equal(await ui(page), 'special');
            assert.equal((await h.state(page)).content.specialPages.waste.on, true);
            assert.equal(await text(page, '[data-page-title]'), 'Plánovací kalendář svozu odpadů');
            assert.match(await stageSvg(page), new RegExp(`PLÁNOVACÍ KALENDÁŘ SVOZU ODPADŮ ${year}`));
            pages = await h.cal(page, (c) => c.pages());
            assert.equal(pages.length, total + 4);
            await h.shot(page, 'step4-special-waste');
            // switching a generated kind off removes its page, the settings stay
            await page.click('[data-special="waste"] label.cal-check');
            assert.equal((await h.cal(page, (c) => c.pages())).length, total + 3);
            assert.equal((await h.state(page)).content.specialPages.waste.on, false);
            await h.save(page);
        });

        await check('uploaded special pages: Nahrát grafiku (picture and PDF), Text z Wordu, rename, replace the file, delete with a toast', async () => {
            const count = async () => (await h.cal(page, (c) => c.pages())).length;
            const before = await count();
            const addVia = async (kind, file) => {
                await page.click('[data-cal-action="special-add"]');
                await page.waitForSelector(`.cal-spam [data-special-upload="${kind}"]`);
                await upload(page, `.cal-spam [data-special-upload="${kind}"]`, file);
            };
            const uploaded = async () => Object.entries((await h.state(page)).content.specialPages).filter(([, item]) => item.kind === 'image' || item.kind === 'text');

            await addVia('image', files.graphic);
            await waitCal(page, (c, n) => c.pages().length === n, before + 1);
            let own = await uploaded();
            assert.equal(own.length, 1);
            const [graphicId, graphic] = own[0];
            assert.deepEqual([graphic.kind, graphic.name, graphic.fileName, graphic.on, graphic.position, typeof graphic.asset], ['image', 'Mapa obce', 'Mapa obce.png', true, 'front', 'string']);
            assert.equal(await text(page, '[data-page-title]'), 'Mapa obce', 'the new page is shown');
            const svg = await stageSvg(page);
            assert.match(svg, /data-kind="image"[^>]*><image [^>]*preserveAspectRatio="xMidYMid slice"/, 'the graphic covers the page');
            const pageOf = (await h.cal(page, (c) => c.pages())).find((p) => p.key === `sp:${graphicId}`);
            assert.deepEqual([pageOf.title, pageOf.subtitle], ['Mapa obce', 'Vlastní strana']);

            // rename
            assert.equal(await page.$eval(`[data-special="${graphicId}"] [data-special-name]`, (el) => `${el.value}|${el.getAttribute('aria-label')}`), 'Mapa obce|Název stránky');
            await typeInto(page, `[data-special="${graphicId}"] [data-special-name]`, 'Mapa katastru');
            assert.equal(await page.$eval(`[data-special="${graphicId}"] [data-special-name]`, (el) => document.activeElement === el), true, 'renaming keeps the focus');
            assert.equal((await h.state(page)).content.specialPages[graphicId].name, 'Mapa katastru');
            await page.keyboard.press('Enter');
            await page.waitForFunction((i) => [...document.querySelectorAll('[data-page-thumb] .cal-thn b')].some((b) => b.textContent.includes('Mapa katastru')), {}, graphicId);
            assert.equal(await text(page, `[data-special="${graphicId}"] .cal-check-t`), 'Mapa katastru');

            // a PDF: its first page becomes the picture of the page
            await addVia('image', files.pagePdf);
            await waitCal(page, (c, n) => c.pages().length === n, before + 2);
            own = await uploaded();
            const [pdfId, pdfPage] = own.find(([itemId]) => itemId !== graphicId);
            assert.deepEqual([pdfPage.kind, pdfPage.name, pdfPage.fileName, typeof pdfPage.asset], ['image', 'Hotova strana', 'Hotova strana.pdf', 'string']);
            assert.match(await stageSvg(page), /data-kind="image"[^>]*><image /);

            // text document
            await addVia('text', files.text);
            await waitCal(page, (c, n) => c.pages().length === n, before + 3);
            own = await uploaded();
            const [textId, textPage] = own.find(([, item]) => item.kind === 'text');
            assert.deepEqual([textPage.name, textPage.fileName, textPage.asset], ['Slovo starosty', 'Slovo starosty.txt', null]);
            assert.match(textPage.text, /^Milí občané,\nrok utekl jako voda/);
            const textSvg = await stageSvg(page);
            assert.match(textSvg, /SLOVO STAROSTY/, 'heading in the header style');
            assert.match(textSvg, /data-el="special-text"[\s\S]*Milí občané,[\s\S]*Váš starosta/);
            await h.shot(page, 'step4-special-text');

            // replace the file of the text page by a picture and back by another text
            await upload(page, `[data-special="${textId}"] [data-special-file]`, files.graphic2);
            await waitCal(page, (c, i) => c.state().content.specialPages[i].kind === 'image', textId);
            let replaced = (await h.state(page)).content.specialPages[textId];
            assert.deepEqual([replaced.kind, replaced.text, replaced.fileName, replaced.name], ['image', null, 'jina-grafika.png', 'Slovo starosty'], 'the name stays');
            await upload(page, `[data-special="${textId}"] [data-special-file]`, files.text2);
            await waitCal(page, (c, i) => c.state().content.specialPages[i].kind === 'text', textId);
            replaced = (await h.state(page)).content.specialPages[textId];
            assert.deepEqual([replaced.kind, replaced.text, replaced.asset], ['text', 'Náhradní text stránky.', null]);

            await h.save(page);
            await openStep4(page, h, id);
            assert.equal(await count(), before + 3);
            const assets = await bootAssets(page);
            const stored = (await h.state(page)).content.specialPages;
            assert.equal(assets[stored[graphicId].asset].name, 'Mapa obce.png');
            assert.ok(assets[stored[pdfId].asset].width > 2000, `the PDF page is rasterised for print (${assets[stored[pdfId].asset].width} px wide)`);
            assert.equal(stored[graphicId].name, 'Mapa katastru');

            // delete: the page leaves the list, a toast confirms; a generated kind has no delete
            await page.click('[data-content-view="special"]');
            await page.waitForSelector(`[data-special="${pdfId}"]`);
            assert.equal(await exists(page, '[data-special="intro"] [data-special-delete]'), false);
            assert.equal(await page.$eval(`[data-special="${pdfId}"] [data-special-delete]`, (el) => el.getAttribute('aria-label')), 'Smazat stránku');
            await page.click(`[data-special="${pdfId}"] [data-special-delete]`);
            await h.waitToast(page, 'Stránka „Hotova strana“ smazána');
            assert.equal(await count(), before + 2);
            assert.equal((await h.state(page)).content.specialPages[pdfId], undefined);
            assert.equal(await exists(page, `[data-special="${pdfId}"]`), false);
            // the toast of the first delete lies over the lower right corner of the panel for a moment
            await page.$eval(`[data-special="${textId}"] [data-special-delete]`, (el) => el.click());
            await page.$eval(`[data-special="${graphicId}"] [data-special-delete]`, (el) => el.click());
            await h.save(page);
            await openStep4(page, h, id);
            assert.equal(await count(), before);
            assert.deepEqual(Object.keys((await h.state(page)).content.specialPages).sort(), ['contacts', 'intro', 'waste', 'year']);
        });

        await check('QR kódy: off notice → Zapnout → one code per page mode → a code on one week, on its page and in its panel', async () => {
            await openTool(page, h, 'qr');
            assert.equal(await text(page, '.cal-tool-off p'), 'QR kódy jsou vypnuté v kroku 1, v kalendáři se nezobrazí.');
            await page.click('.cal-tool-modal [data-cal-action="feature-on"]');
            await page.waitForSelector('[data-cal-action="qr-per-page"]');
            assert.equal(await text(page, '.cal-qroff p'), 'QR kód je teď stejný na všech stranách — nahrajete ho ve Stylu stránek › QR kód.');
            assert.equal(await text(page, '[data-cal-action="qr-per-page"]'), 'Na každé straně jiný QR kód');
            await page.click('[data-cal-action="qr-per-page"]');
            await page.waitForSelector('[data-qr-row]');
            assert.equal((await h.state(page)).design.layout.styles.qr.perPage, true);
            assert.equal(await page.$$eval('[data-qr-row]', (nodes) => nodes.length), weeks.length, 'one row per week');
            assert.equal(await text(page, '.cal-qrn'), `QR kód pro každý týden — strana bez QR kódu ho nemá. Vyplněno 0 z ${weeks.length}.`);
            const key = weeks[1].key;
            assert.equal(await page.$eval(`[data-qr-row="${key}"] [data-qr-file]`, (el) => el.getAttribute('aria-label')), `QR kód: ${weeks[1].title}`);
            assert.equal(await page.$eval(`[data-qr-row="${key}"] .cal-ft`, (el) => el.title), 'Nahrát QR kód');
            assert.equal(await text(page, `[data-qr-row="${key}"] .cal-qrc .cap`), 'Bez QR kódu');
            await upload(page, `[data-qr-row="${key}"] [data-qr-file]`, files.qr);
            await waitCal(page, (c, k) => Boolean(c.state().content.pageQr[k]), key);
            assert.equal(await text(page, '.cal-qrn'), `QR kód pro každý týden — strana bez QR kódu ho nemá. Vyplněno 1 z ${weeks.length}.`);
            assert.equal(await page.$eval(`[data-qr-row="${key}"] .cal-ft`, (el) => el.title), 'Vyměnit QR kód');
            assert.equal(await page.$eval(`[data-qr-row="${key}"] [data-qr-remove]`, (el) => el.getAttribute('aria-label')), 'Odebrat QR kód');
            await h.shot(page, 'tool-qr');
            await closeTool(page, h);

            const pages = await h.cal(page, (c) => c.pages());
            const index = pages.find((p) => p.key === key).index;
            const other = pages.find((p) => p.key === weeks[2].key).index;
            await showPage(page, index);
            assert.match(await stageSvg(page), /<g data-el="qr"><image /, 'the code is on its page');
            await page.waitForSelector(`[data-page-thumb="${index}"][data-overridden]`);
            assert.equal(await text(page, '.cal-p4s:has([data-page-field="qr"]) .lbl'), 'QR kód této strany');
            assert.equal(await text(page, '.cal-p4s:has([data-page-field="qr"]) .cal-ftb .cap'), 'Jen pro tuto stranu');
            await showPage(page, other);
            assert.match(await stageSvg(page), /<g data-el="qr"><\/g>/, 'a page without a code has none');
            assert.equal(await text(page, '.cal-p4s:has([data-page-field="qr"]) .cal-ftb .cap'), 'Bez QR kódu');

            await h.save(page);
            await openStep4(page, h, id);
            const saved = await h.state(page);
            assert.equal(saved.design.features.qr, true);
            assert.equal(saved.design.layout.styles.qr.perPage, true);
            assert.equal((await bootAssets(page))[saved.content.pageQr[key].asset].name, 'qr.png');
            // remove it in the page panel
            await showPage(page, index);
            await page.click('[data-page-remove="qr"]');
            assert.equal((await h.state(page)).content.pageQr[key], undefined);
            await page.waitForFunction((i) => !document.querySelector(`[data-page-thumb="${i}"]`).hasAttribute('data-overridden'), {}, index);
            await h.save(page);
        });

        await check('QR kód shared by all pages: a page can still carry its own code (handoff §4.6), which wins over the shared one', async () => {
            const qrHref = async () => ((await stageSvg(page)).match(/<g data-el="qr"><image [^>]*href="([^"]+)"/) || [])[1] || null;
            const section = '.cal-p4s:has([data-page-field="qr"])';
            const pages = await h.cal(page, (c) => c.pages());
            const key = weeks[1].key;
            const index = pages.find((p) => p.key === key).index;
            const other = pages.find((p) => p.key === weeks[2].key).index;
            const before = (await h.state(page)).design.layout;
            const shared = (asset) => ({ ...before, styles: { ...before.styles, qr: { perPage: false, asset } } });
            await h.seed(page, { design: { layout: shared(null) } });
            await openStep4(page, h, id);
            await showPage(page, index);
            assert.equal(await text(page, `${section} .lbl`), 'Jiný QR kód jen pro tuto stranu');
            assert.equal(await text(page, `${section} .cal-ftb .cap`), 'Výchozí ze stylu');
            assert.equal(await qrHref(), null, 'no shared code yet');
            // the page's own code, while the calendar has no shared one
            await upload(page, '[data-page-field="qr"]', files.qr);
            await waitCal(page, (c, k) => Boolean(c.state().content.pageQr[k]), key);
            assert.equal(await text(page, `${section} .cal-ftb .cap`), 'Jen pro tuto stranu');
            const first = (await h.state(page)).content.pageQr[key].asset;
            assert.ok(await qrHref(), 'the own code is drawn although the code is shared');
            await showPage(page, other);
            assert.equal(await qrHref(), null, 'other pages are untouched');
            await h.save(page);
            // that picture becomes the shared code; the page then gets a different one of its own
            await h.seed(page, { design: { layout: shared(first) } });
            await openStep4(page, h, id);
            await showPage(page, other);
            const sharedHref = await qrHref();
            assert.ok(sharedHref, 'the shared code is on a page without its own');
            assert.equal(await text(page, `${section} .cal-ftb .cap`), 'Výchozí ze stylu');
            assert.equal(await visible(page, '[data-page-remove="qr"]'), false);
            await showPage(page, index);
            await upload(page, '[data-page-field="qr"]', files.print);
            await waitCal(page, (c, k, o) => c.state().content.pageQr[k].asset !== o, key, first);
            const ownHref = await qrHref();
            assert.ok(ownHref && ownHref !== sharedHref, 'the page shows its own code, not the shared one');
            assert.equal(await text(page, `${section} .cal-ftb .cap`), 'Jen pro tuto stranu');
            await page.waitForSelector(`[data-page-thumb="${index}"][data-overridden]`);
            await h.shot(page, 'page-qr-own-shared');
            await h.save(page);
            await openStep4(page, h, id);
            await showPage(page, index);
            assert.equal(await qrHref(), ownHref, 'after a reload too');
            await showPage(page, other);
            assert.equal(await qrHref(), sharedHref);
            // Odebrat: the page falls back to the shared code
            await showPage(page, index);
            await page.click('[data-page-remove="qr"]');
            await waitCal(page, (c, k) => !c.state().content.pageQr[k], key);
            assert.equal(await qrHref(), sharedHref);
            assert.equal(await text(page, `${section} .cal-ftb .cap`), 'Výchozí ze stylu');
            await h.save(page);
            // back to one code per page for what follows
            await h.seed(page, { design: { layout: { ...before, styles: { ...before.styles, qr: { perPage: true, asset: null } } } } });
            await openStep4(page, h, id);
        });

        await check('E21 Styl stránek: the step-3 editor inside step 4 (E1 select, E3 drag the logo, E12 kalendárium part)', async () => {
            await page.click('[data-content-view="style"]');
            await page.waitForSelector('.cal-b5[data-view="style"] .cal-cv');
            assert.equal(await visible(page, '.cal-hist'), true, 'undo / redo are offered in Styl stránek');
            await page.waitForFunction(() => window.__calendar.scale() > 0);
            const start = await h.boxes(page);
            const centre = (box) => ({ x: box.x + box.w / 2, y: box.y + box.h / 2 });
            const click = async (mm) => {
                const pt = await h.cal(page, (c, p) => c.toClient(p.x, p.y), mm);
                await page.mouse.click(pt.x, pt.y);
                await h.sleep(60);
            };
            // E1: click the photo (away from the logo, the caption and the QR code)
            await click({ x: start.photo.x + start.photo.w * 0.3, y: start.photo.y + start.photo.h * 0.4 });
            assert.deepEqual((await h.state(page)).selection, { el: 'photo' });
            assert.equal(await page.$$eval('[data-handle]', (nodes) => nodes.length), 8);
            assert.equal(await text(page, '[data-frame-tag]'), 'Fotografie');
            assert.equal(await visible(page, '[data-cal-action="style-back"]'), true, 'the panel shows the properties with the way back to Vrstvy');
            assert.equal(await text(page, '[data-cal-action="style-back"]'), 'Vrstvy');
            await page.click('[data-cal-action="style-back"]');
            assert.equal((await h.state(page)).selection, null);
            assert.equal(await visible(page, '.cal-b5-layers'), true);
            assert.equal(await page.$eval('[data-layer="photo"]', (el) => Boolean(el)), true);

            // E3: drag the logo 30 mm to the left
            const from = centre(start.logo);
            await click(from);
            assert.deepEqual((await h.state(page)).selection, { el: 'logo' });
            await h.dragMm(page, from, { x: from.x - 30, y: from.y }, { alt: true });
            const moved = await h.boxes(page);
            h.expectBox(moved.logo, { x: start.logo.x - 30, y: start.logo.y, w: start.logo.w, h: start.logo.h }, 0.25, 'logo');
            h.expectBox(moved.photo, start.photo, 0.11, 'photo');
            await h.save(page);
            await openStep4(page, h, id);
            h.expectBox((await h.boxes(page)).logo, moved.logo, 0.11, 'logo after reload');
            // … and on any other week page
            await showPage(page, 6);
            const logoOnPage = await page.$eval('.cal-m4c [data-el="logo"]', (el) => {
                const inner = el.querySelector('rect, image');
                return Number(inner.getAttribute('x'));
            });
            assert.ok(Math.abs(logoOnPage - moved.logo.x) < 0.5, `logo at ${logoOnPage} mm on another week page`);

            // E12: kalendárium, then a day number
            await page.click('[data-content-view="style"]');
            await page.waitForSelector('.cal-b5[data-view="style"] .cal-cv');
            await page.waitForFunction(() => window.__calendar.scale() > 0 && window.__calendar.geometry());
            const geometry = await h.cal(page, (c) => c.geometry());
            const row = geometry.frames[0].rows[1];
            const number = row.parts.find((p) => p.key === 'num');
            assert.ok(number, 'a workday number in the second row');
            const target = { x: number.rect.x + number.rect.w / 2, y: number.rect.y + number.rect.h / 2 };
            await click(target);
            assert.deepEqual([(await h.state(page)).selection.el, (await h.state(page)).selection.part], ['cal', undefined], 'first the whole block');
            await click(target);
            assert.deepEqual([(await h.state(page)).selection.el, (await h.state(page)).selection.part], ['cal', 'num'], 'then the workday numbers');
            assert.ok((await page.$$eval('[data-part-box]', (nodes) => nodes.length)) >= 4, 'highlighted in every workday row');
            await page.keyboard.press('Escape');
            await page.keyboard.press('Escape');
        });

        await check('narrow screen: the panels stack under the page instead of disappearing', async () => {
            await page.setViewport({ width: 820, height: 1000 });
            await showPage(page, 1);
            await h.sleep(250);
            const boxes = await page.evaluate(() => {
                const rect = (s) => {
                    const r = document.querySelector(s).getBoundingClientRect();
                    return { top: r.top + window.scrollY, bottom: r.bottom + window.scrollY, w: r.width, h: r.height };
                };
                return { main: rect('.cal-b5-main'), list: rect('.cal-b5-list'), side: rect('.cal-b5-side'), doc: document.documentElement.scrollWidth, win: window.innerWidth };
            });
            assert.ok(boxes.main.h > 100 && boxes.list.h > 40 && boxes.side.h > 40, 'all three are shown');
            assert.ok(boxes.list.top >= boxes.main.bottom - 1 && boxes.side.top >= boxes.list.bottom - 1, 'page, then the list, then the panel');
            assert.ok(boxes.doc <= boxes.win + 1, 'no horizontal page scroll');
            await h.shot(page, 'step4-820');
            await page.setViewport({ width: 1440, height: 900 });
        });

        await check('cleanup', async () => {
            fs.rmSync(files.dir, { recursive: true, force: true });
            if ((await h.state(page)).status !== 'clean') await h.save(page);
            await h.gotoList(page);
            assert.equal(await h.deleteCalendar(page, id), true);
        });
    },
};
