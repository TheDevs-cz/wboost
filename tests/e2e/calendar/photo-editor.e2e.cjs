/*
 * „Upravit fotku“ — the photo editor (feedback K14, docs/calendar/photo-editor-plan.md).
 *
 * step 5 (designer): open beside the toolbar, quarter turn, mirror, straighten, zoom keys, the page
 * behind previews the draft, Esc discards, Hotovo writes ONE item with rotate / flip, the SVG
 * `<image>` gets a transform (never a filter), History says „Upravil fotku“ → the quick zoom keeps
 * the turn → step 4 page panel „Upravit“ → „Fotky a popisky“ inline under the row → cover photo
 * („Upravit výřez“ in the page content) → the client edits a photo too → a phone-width sheet.
 */
const assert = require('assert/strict');
const fs = require('fs');
const os = require('os');
const path = require('path');
const zlib = require('zlib');

const CRC = (() => {
    const table = new Uint32Array(256);
    for (let n = 0; n < 256; n++) {
        let c = n;
        for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
        table[n] = c >>> 0;
    }
    return (buffer) => {
        let c = 0xffffffff;
        for (const byte of buffer) c = table[(c ^ byte) & 0xff] ^ (c >>> 8);
        return (c ^ 0xffffffff) >>> 0;
    };
})();
function chunk(type, data) {
    const head = Buffer.alloc(4);
    head.writeUInt32BE(data.length);
    const body = Buffer.concat([Buffer.from(type, 'ascii'), data]);
    const tail = Buffer.alloc(4);
    tail.writeUInt32BE(CRC(body));
    return Buffer.concat([head, body, tail]);
}
function gradientPng(width, height, file) {
    const row = width * 3 + 1;
    const raw = Buffer.alloc(row * height);
    for (let y = 0; y < height; y++) {
        raw[y * row] = 1;
        let prev = [0, 0, 0];
        for (let x = 0; x < width; x++) {
            const px = [Math.floor((x * 255) / width), Math.floor((y * 255) / height), 140];
            for (let i = 0; i < 3; i++) raw[y * row + 1 + x * 3 + i] = (px[i] - prev[i]) & 0xff;
            prev = px;
        }
    }
    const ihdr = Buffer.alloc(13);
    ihdr.writeUInt32BE(width, 0);
    ihdr.writeUInt32BE(height, 4);
    ihdr[8] = 8;
    ihdr[9] = 2;
    fs.writeFileSync(file, Buffer.concat([Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]), chunk('IHDR', ihdr), chunk('IDAT', zlib.deflateSync(raw, { level: 9 })), chunk('IEND', Buffer.alloc(0))]));
    return file;
}

const PE = '.cal-pe-pop';
const visible = (page, selector) => page.$eval(selector, (el) => el.offsetParent !== null && !el.hidden).catch(() => false);

module.exports = {
    name: 'photo-editor',
    checks: ['K14'],
    async run({ browser, page, h, check }) {
        const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'wb-cal-k14-'));
        const photo = gradientPng(1500, 1000, path.join(tmp, 'krajina.png'));
        let id = null;
        let week = null;
        let asset = null;
        let clientUrl = null;
        const state = () => h.state(page);
        const item = async (key = week.key) => (await state()).content.pagePhotos[key];

        async function showPage(index, p = page) {
            const key = await h.cal(p, (c, i) => c.pages()[i].key, index);
            await p.click(`[data-page-thumb="${index}"]`);
            await p.waitForSelector(`.cal-5w svg[data-page="${key}"]`, { timeout: 5000 });
        }
        const photoImage = (p = page) => p.$eval('.cal-5w svg g[data-el="photo"] image', (el) => ({ transform: el.getAttribute('transform'), filter: el.getAttribute('filter'), x: Number(el.getAttribute('x')) }));

        await check('setup: a 7-day calendar with a layout in step 5, a photo on a week page', async () => {
            id = await h.createCalendar(page);
            await h.gotoStep(page, 2);
            await page.click('[data-preset="photoLeft"]');
            await page.waitForSelector('.cal-ws[data-cal-shown-step="3"]');
            await h.save(page);
            const s = await state();
            // the designer places the photos (step 4's page panel offers the photo only then)
            const layout = { ...s.design.layout, styles: { ...s.design.layout.styles, photo: { ...s.design.layout.styles.photo, source: 'self' } } };
            await h.seed(page, { design: { features: { ...s.design.features, captions: true }, layout, step: 5 } });
            clientUrl = JSON.parse(await page.$eval('[data-calendar-workspace-target="bootstrap"]', (el) => el.textContent)).env.clientUrl;
            const pages = await h.cal(page, (c) => c.pages());
            week = pages.find((p) => p.kind === 'week' && p.index > 3);
            await showPage(week.index);
            await (await page.$('[data-photo-upload]')).uploadFile(photo);
            await page.waitForFunction((key) => Boolean(window.__calendar.state().content.pagePhotos[key]), { timeout: 15000 }, week.key);
            asset = (await item()).asset;
            await h.save(page);
            assert.equal(await visible(page, '[data-photo-edit]'), true, '„Upravit fotku“ is in the toolbar');
        });
        if (!week) return;

        await check('step 5: Esc discards — the page shows the stored crop again and nothing is written', async () => {
            await page.click('[data-photo-edit]');
            await page.waitForSelector(`${PE} [data-photo-editor]`, { timeout: 3000 });
            assert.equal(await page.evaluate(() => document.activeElement?.matches('[data-pe-stage]')), true, 'the stage has focus');
            await page.click(`${PE} [data-pe-action="rotate-right"]`);
            assert.match((await photoImage()).transform || '', /rotate\(90\)/, 'the page behind previews the draft');
            await page.keyboard.press('Escape');
            await page.waitForFunction(() => !document.querySelector('.cal-pe-pop'), { timeout: 3000 });
            assert.equal((await photoImage()).transform, null, 'the preview went back');
            assert.deepEqual(await item(), { asset, zoom: 1, px: 0.5, py: 0.5 });
        });

        await check('step 5: turn, mirror, straighten, zoom keys → Hotovo writes one item; transform, no filter; History', async () => {
            await page.click('[data-photo-edit]');
            await page.waitForSelector(`${PE} [data-photo-editor]`, { timeout: 3000 });
            await page.click(`${PE} [data-pe-action="rotate-left"]`);
            await page.click(`${PE} [data-pe-action="mirror"]`);
            assert.equal(await page.$eval(`${PE} [data-pe-action="mirror"]`, (el) => el.getAttribute('aria-pressed')), 'true');
            // straighten with the range's own keys: +0.5° five times
            await page.focus(`${PE} [data-pe="straighten"]`);
            for (let i = 0; i < 5; i++) await page.keyboard.press('ArrowRight');
            assert.match(await page.$eval(`${PE} [data-pe="straighten"] ~ output, ${PE} .cal-pe-row:nth-child(2) output`, (el) => el.textContent), /0,5°/);
            await page.focus(`${PE} [data-pe-stage]`);
            await page.keyboard.press('+');
            await page.keyboard.press('ArrowLeft');
            assert.match(await page.$eval(`${PE} [data-pe-info]`, (el) => el.textContent), /1\s500 × 1\s000\s*px · \d+\s*dpi/);
            await page.click(`${PE} [data-pe-action="done"]`);
            await page.waitForFunction(() => !document.querySelector('.cal-pe-pop'), { timeout: 3000 });
            const stored = await item();
            assert.equal(stored.asset, asset);
            assert.equal(stored.flip, true);
            // −90° turned, then mirrored (the angle changes sign), then straightened by +0.5°
            assert.equal(stored.rotate, 90.5);
            assert.ok(stored.zoom > 1.09 && stored.zoom < 1.11, `zoom ${stored.zoom}`);
            const image = await photoImage();
            assert.match(image.transform, /^translate\([\d.]+ [\d.]+\) rotate\(90\.5\) scale\(-1 1\)$/);
            assert.equal(image.filter, null);
            await h.save(page);
            await page.click('[data-cal-action="history"]');
            await page.waitForSelector('[data-cal-dialog="history"].show .cal-hrow', { timeout: 8000 });
            const lines = await page.$$eval('[data-cal-dialog="history"] .cal-hwhat', (nodes) => nodes.map((n) => n.textContent.trim()));
            assert.ok(lines.some((l) => l.startsWith('Upravil fotku')), lines.join(' | '));
            await h.sleep(350);
            await page.click('[data-cal-dialog="history"] [data-cal-action="modal-done"]');
            await page.waitForFunction(() => !document.querySelector('[data-cal-dialog="history"]'), { timeout: 5000 });
        });

        await check('step 5: reload keeps it; the quick zoom and a drag keep the turn and the mirror', async () => {
            await h.reload(page);
            await showPage(week.index);
            assert.match((await photoImage()).transform, /rotate\(90\.5\) scale\(-1 1\)/);
            await page.focus('[data-photo-zoom]');
            await page.keyboard.press('ArrowRight');
            await page.waitForFunction((key) => window.__calendar.state().content.pagePhotos[key].zoom > 1.1, { timeout: 3000 }, week.key);
            const stored = await item();
            assert.equal(stored.rotate, 90.5);
            assert.equal(stored.flip, true);
            await h.save(page);
        });

        await check('step 4: the page panel has „Upravit“; Obnovit + Hotovo stores the pre-K14 item again', async () => {
            await h.gotoStep(page, 4);
            await page.click(`[data-page-thumb="${week.index}"]`);
            await page.waitForSelector('[data-page-edit="photo"]', { visible: true, timeout: 5000 });
            await page.click('[data-page-edit="photo"]');
            await page.waitForSelector(`${PE} [data-photo-editor]`, { timeout: 3000 });
            await page.click(`${PE} [data-pe-action="reset"]`);
            await page.click(`${PE} [data-pe-action="done"]`);
            await page.waitForFunction(() => !document.querySelector('.cal-pe-pop'), { timeout: 3000 });
            assert.deepEqual(await item(), { asset, zoom: 1, px: 0.5, py: 0.5 }, 'rotate / flip are gone, not stored as 0 / false');
            await h.save(page);
        });

        await check('„Fotky a popisky“: the pencil opens the editor inline under its row; Hotovo writes', async () => {
            await page.click('[data-tool="photos"]');
            await page.waitForSelector('.cal-tool-modal.show[data-tool-modal="photos"]', { timeout: 8000 });
            await h.sleep(350);
            const row = `[data-photo-row="${week.key}"]`;
            assert.equal(await visible(page, `${row} [data-photo-edit]`), true);
            await page.click(`${row} [data-photo-edit]`);
            await page.waitForSelector(`[data-photo-editing="${week.key}"] [data-photo-editor]`, { timeout: 3000 });
            await page.click(`[data-photo-editing="${week.key}"] [data-pe-action="rotate-right"]`);
            await page.click(`[data-photo-editing="${week.key}"] [data-pe-action="done"]`);
            await page.waitForFunction(() => !document.querySelector('[data-photo-editing]'), { timeout: 3000 });
            assert.equal((await item()).rotate, 90);
            // Esc inside the inline editor closes the editor, not the tool
            await page.click(`${row} [data-photo-edit]`);
            await page.waitForSelector(`[data-photo-editing="${week.key}"] [data-photo-editor]`, { timeout: 3000 });
            const focused = await page.evaluate(() => `${document.activeElement?.tagName}.${document.activeElement?.className} in=${Boolean(document.activeElement?.closest('[data-photo-editing]'))}`);
            assert.match(focused, /in=true/, `focus before Esc: ${focused}`);
            await page.keyboard.press('Escape');
            await page.waitForFunction(() => !document.querySelector('[data-photo-editing]'), { timeout: 3000 });
            assert.ok(await page.$('.cal-tool-modal.show'), 'the tool stays open');
            await page.click('.cal-tool-modal.show [data-cal-action="modal-done"]');
            await page.waitForFunction(() => !document.querySelector('.cal-tool-modal'), { timeout: 8000 });
            await h.save(page);
        });

        await check('cover: „Upravit výřez“ in the page content turns the cover photo', async () => {
            await h.seed(page, { content: [{ c: 'pageContent', id: 'cover', v: { title: '', subtitle: '', note: '', photo: { asset, zoom: 1, px: 0.5, py: 0.5 } } }] });
            await h.gotoStep(page, 4);
            await page.click('[data-page-thumb="0"]');
            await page.waitForSelector('[data-photo-crop] + .cal-cropc [data-photo-edit], .cal-cropc [data-photo-edit]', { visible: true, timeout: 5000 });
            await page.click('.cal-cropc [data-photo-edit]');
            await page.waitForSelector('[data-photo-editing="cover"] [data-photo-editor]', { timeout: 3000 });
            await page.click('[data-photo-editing="cover"] [data-pe-action="mirror"]');
            await page.click('[data-photo-editing="cover"] [data-pe-action="done"]');
            await page.waitForFunction(() => window.__calendar.state().content.pageContent.cover?.photo?.flip === true, { timeout: 3000 });
            const markup = await page.$eval('.cal-m4c svg g[data-el="page-photo"]', (el) => el.innerHTML);
            assert.match(markup, /transform="translate\([^)]*\) scale\(-1 1\)"/);
            await h.save(page);
        });

        await check('client: the client opens the same editor in step 5 and its turn is stored', async () => {
            const context = await browser.createBrowserContext();
            const client = await h.newPage(context);
            try {
                await client.goto(clientUrl, { waitUntil: 'networkidle2' });
                await client.waitForSelector('.cal-ws[data-cal-ready]', { timeout: 15000 }).catch(() => client.waitForSelector('.cal-5w svg', { timeout: 15000 }));
                await showPage(week.index, client);
                await client.click('[data-photo-edit]');
                await client.waitForSelector(`${PE} [data-photo-editor]`, { timeout: 3000 });
                await client.click(`${PE} [data-pe-action="rotate-right"]`);
                await client.click(`${PE} [data-pe-action="done"]`);
                await client.waitForFunction(() => !document.querySelector('.cal-pe-pop'), { timeout: 3000 });
                await client.click('[data-cal-action="save"]').catch(() => {});
                await client.waitForSelector('[data-cal-status="clean"]', { timeout: 10000 });
            } finally {
                await context.close();
            }
            await h.reload(page);
            assert.equal((await item()).rotate, 180, 'the client turned the designer’s 90° by another 90°');
        });

        await check('phone: the editor is a sheet docked to the bottom, the stage fits', async () => {
            const phone = await h.newPage(browser, { width: 390, height: 780 });
            try {
                await h.openCalendar(phone, id);
                await h.gotoStep(phone, 5);
                await showPage(week.index, phone);
                await phone.click('[data-photo-edit]');
                await phone.waitForSelector(`${PE} [data-photo-editor]`, { timeout: 3000 });
                const r = await phone.$eval(PE, (el) => el.getBoundingClientRect().toJSON());
                assert.ok(r.left >= 0 && r.right <= 390 && r.bottom <= 780, JSON.stringify(r));
                const stage = await phone.$eval(`${PE} [data-pe-stage]`, (el) => el.getBoundingClientRect().toJSON());
                assert.ok(stage.width > 100 && stage.right <= 390, JSON.stringify(stage));
                await h.shot(phone, 'photo-editor-phone');
                await phone.keyboard.press('Escape');
            } finally {
                await phone.close();
            }
        });

        await check('cleanup', async () => {
            await h.deleteCalendar(page, id);
        });
    },
};
