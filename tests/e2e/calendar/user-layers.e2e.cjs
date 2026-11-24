/*
 * Own layers (feedback K20 P1 — docs/calendar/custom-layers-plan.md, model/layers.js, render/layers.js,
 * ui/editor/layers.js + props-layer.js).
 *
 * create → Fotka vlevo → step 3: „Přidat“ a rectangle, a rounded rectangle, an ellipse, a line and an
 * uploaded picture (each on top, selected, one undo step); drag / resize / snap in mm; the properties
 * (fill, stroke, radius, line cap, blend, opacity, name); reorder under the photo; the eye; rename,
 * duplicate and delete from the row menu; the Delete key + undo; save + reload; step 5 draws them;
 * the client link draws them and offers nothing to select; a forged client save of a layer is 403.
 */
const assert = require('assert/strict');
const fs = require('fs');
const os = require('os');
const path = require('path');
const zlib = require('zlib');
const e = require('./lib/editor.cjs');

/** A small opaque RGB PNG, 4 : 1 (a partner-logo strip). */
function stripPng(w = 160, hgt = 40) {
    const raw = Buffer.alloc((w * 3 + 1) * hgt);
    for (let y = 0; y < hgt; y++) {
        const row = y * (w * 3 + 1);
        for (let x = 0; x < w; x++) raw.set(x < w / 2 ? [200, 16, 46] : [0, 78, 124], row + 1 + x * 3);
    }
    const chunk = (type, data) => {
        const body = Buffer.concat([Buffer.from(type, 'ascii'), data]);
        const out = Buffer.alloc(body.length + 8);
        out.writeUInt32BE(data.length, 0);
        body.copy(out, 4);
        out.writeUInt32BE(zlib.crc32(body) >>> 0, body.length + 4);
        return out;
    };
    const header = Buffer.alloc(13);
    header.writeUInt32BE(w, 0);
    header.writeUInt32BE(hgt, 4);
    header.set([8, 2, 0, 0, 0], 8);
    return Buffer.concat([Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]), chunk('IHDR', header), chunk('IDAT', zlib.deflateSync(raw)), chunk('IEND', Buffer.alloc(0))]);
}

module.exports = {
    name: 'user-layers',
    checks: ['K20'],
    async run({ browser, page, h, check }) {
        const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'wboost-cal-layers-'));
        const pngFile = path.join(tmp, 'partneri.png');
        fs.writeFileSync(pngFile, stripPng());

        const layout = async () => (await h.state(page)).design.layout;
        const ownKeys = async () => (await layout()).order.filter((k) => k.startsWith('u:'));
        const stage = (selector) => page.$$eval(`.cal-cv svg[data-page] ${selector}`, (list) => list.length);
        const selected = async () => {
            const s = (await h.state(page)).selection;
            return s ? s.el : null;
        };
        async function add(entry) {
            const before = await ownKeys();
            await page.click('[data-cal-action="layer-add"]');
            await page.waitForSelector(`[data-layer-add="${entry}"]`, { timeout: 5000 });
            await page.click(`[data-layer-add="${entry}"]`);
            await page.waitForFunction((n) => window.__calendar.state().design.layout.order.filter((k) => k.startsWith('u:')).length === n, { timeout: 20000 }, before.length + 1);
            const key = (await ownKeys()).find((k) => !before.includes(k));
            await page.waitForSelector(`[data-props="${key}"]`, { timeout: 5000 });
            return key;
        }
        const boxOf = async (key) => (await layout()).boxes[key];
        const itemOf = async (key) => (await layout()).layers[key.slice(2)];
        const undo = async () => {
            await page.click('[data-cal-action="undo"]');
            await h.sleep(60);
        };
        const redo = async () => {
            await page.click('[data-cal-action="redo"]');
            await h.sleep(60);
        };

        let id = null;
        let rect = null;
        await check('setup: Fotka vlevo, step 3, Vrstvy has „Přidat“', async () => {
            id = await h.createCalendar(page);
            await h.gotoStep(page, 2);
            await page.click('[data-preset="photoLeft"]');
            await page.waitForSelector('.cal-ws[data-cal-shown-step="3"]', { timeout: 10000 });
            assert.ok(await page.$('[data-cal-action="layer-add"]'));
            assert.deepEqual((await layout()).layers, {});
        });
        if (!id) return;

        await check('„Přidat“ › Obdélník: centred, on top, selected, drawn, one undo step', async () => {
            await page.click('[data-cal-action="layer-add"]');
            const entries = await page.$$eval('[data-layer-add-menu] [data-layer-add]', (list) => list.map((n) => n.dataset.layerAdd));
            for (const entry of ['image', 'rect', 'rounded', 'ellipse', 'line']) assert.ok(entries.includes(entry), entry);
            await page.keyboard.press('Escape');
            assert.equal(await page.$('[data-layer-add-menu]'), null, 'Esc closes the menu');
            assert.ok((await h.state(page)).selection === null, '… and keeps the selection');
            rect = await add('rect');
            const l = await layout();
            assert.equal(l.order[0], rect, 'on top of the stack');
            assert.equal(await selected(), rect);
            const box = l.boxes[rect];
            const page0 = (await h.state(page)).design.page;
            assert.ok(Math.abs(box.x + box.w / 2 - page0.width / 2) < 0.1 && Math.abs(box.y + box.h / 2 - page0.height / 2) < 0.1, 'centred');
            assert.equal(await stage(`[data-el="layer"][data-layer="${rect.slice(2)}"] rect`), 1);
            assert.equal(await page.$eval('[data-layer-item]', (el) => el.dataset.layerItem), rect, 'the top row of Vrstvy');
            assert.match(await page.$eval(`[data-layer="${rect}"]`, (el) => el.textContent), /Obdélník 1/);
            await undo();
            assert.deepEqual((await layout()).layers, {}, 'undo removes it');
            await redo();
            assert.ok(await itemOf(rect), 'redo brings it back');
        });

        await check('drag, resize and snap in mm; never linked to the photo', async () => {
            await page.click(`[data-layer="${rect}"]`).catch(() => null);
            if ((await selected()) !== rect) await page.click(`[data-layer="${rect}"]`);
            const start = await boxOf(rect);
            const photoBefore = (await layout()).boxes.photo;
            await h.dragMm(page, { x: start.x + start.w / 2, y: start.y + start.h / 2 }, { x: start.x + start.w / 2 + 20, y: start.y + start.h / 2 + 10 }, { alt: true });
            h.expectBox(await boxOf(rect), { x: start.x + 20, y: start.y + 10, w: start.w, h: start.h }, 0.15, 'moved');
            await h.dragHandle(page, 'e', 12, 0, { alt: true });
            h.expectBox(await boxOf(rect), { w: start.w + 12 }, 0.15, 'resized');
            // snapping: the left edge dragged to 0.4 mm from the trim lands on it
            const moved = await boxOf(rect);
            await h.dragMm(page, { x: moved.x + moved.w / 2, y: moved.y + moved.h / 2 }, { x: 0.4 + moved.w / 2, y: moved.y + moved.h / 2 });
            assert.equal((await boxOf(rect)).x, 0, 'snapped to the trim');
            assert.deepEqual((await layout()).boxes.photo, photoBefore, 'the photo never moved');
            await page.keyboard.press('ArrowRight');
            assert.equal((await boxOf(rect)).x, 0.3, 'arrows nudge');
        });

        await check('properties: fill colour, stroke, radius, opacity, blend, name — each writes the item', async () => {
            await page.click(`[data-props="${rect}"] [data-prop="layer.fill.color"] [data-color="#2F78B7"]`);
            assert.equal((await itemOf(rect)).fill.color, '#2F78B7');
            await page.$eval(`[data-props="${rect}"] [data-prop="layer.stroke.on"]`, (el) => el.closest('label').click());
            await h.sleep(60);
            assert.equal((await itemOf(rect)).stroke.on, true);
            await page.click(`[data-props="${rect}"] [data-prop="layer.stroke.style"] [data-value="dash"]`);
            assert.equal((await itemOf(rect)).stroke.style, 'dash');
            assert.equal(await stage(`[data-layer="${rect.slice(2)}"] rect[stroke-dasharray]`), 1);
            const radius = await page.$(`[data-props="${rect}"] input[data-prop="layer.radius"]`);
            await radius.click({ clickCount: 3 });
            await radius.type('4');
            await page.keyboard.press('Enter');
            await h.sleep(60);
            assert.equal((await itemOf(rect)).radius, 4);
            await page.select(`[data-props="${rect}"] [data-prop="layer.blend"]`, 'multiply');
            assert.equal((await itemOf(rect)).blend, 'multiply');
            assert.equal(await stage(`[data-layer="${rect.slice(2)}"][style*="mix-blend-mode:multiply"]`), 1);
            const name = await page.$(`[data-props="${rect}"] [data-prop="layer.name"]`);
            await name.click({ clickCount: 3 });
            await name.type('Pruh přes stránku');
            await page.keyboard.press('Enter');
            await h.sleep(60);
            assert.equal((await itemOf(rect)).name, 'Pruh přes stránku');
            assert.match(await page.$eval(`[data-layer="${rect}"]`, (el) => el.textContent), /Pruh přes stránku/);
            assert.match(await page.$eval('[data-frame-tag]', (el) => el.textContent), /Pruh přes stránku/);
        });

        let ellipse = null;
        let line = null;
        let rounded = null;
        let picture = null;
        await check('the other kinds: rounded rectangle, ellipse, line (its cap), an uploaded picture with its aspect', async () => {
            rounded = await add('rounded');
            assert.equal((await itemOf(rounded)).kind, 'rect');
            assert.equal((await itemOf(rounded)).radius, 3);
            ellipse = await add('ellipse');
            assert.equal(await stage(`[data-layer="${ellipse.slice(2)}"] ellipse`), 1);
            line = await add('line');
            assert.equal(await stage(`[data-layer="${line.slice(2)}"] line`), 1);
            await page.click(`[data-props="${line}"] [data-prop="layer.stroke.cap"] [data-value="round"]`);
            assert.equal((await itemOf(line)).stroke.cap, 'round');
            const before = await ownKeys();
            await page.click('[data-cal-action="layer-add"]');
            const input = await page.$('[data-layer-file]');
            await page.keyboard.press('Escape');
            await input.uploadFile(pngFile);
            await page.waitForFunction((n) => window.__calendar.state().design.layout.order.filter((k) => k.startsWith('u:')).length === n, { timeout: 20000 }, before.length + 1);
            picture = (await ownKeys()).find((k) => !before.includes(k));
            const item = await itemOf(picture);
            assert.equal(item.kind, 'image');
            assert.ok(item.asset, 'the uploaded asset');
            const box = await boxOf(picture);
            assert.ok(Math.abs(box.w / box.h - 4) < 0.05, `aspect 4:1, got ${box.w} × ${box.h}`);
            await page.waitForFunction((key) => document.querySelectorAll(`.cal-cv svg[data-page] [data-layer="${key}"] image`).length === 1, { timeout: 5000 }, picture.slice(2));
            assert.equal(await stage('[data-el="layer"] image[filter], [data-el="layer"] image[mask]'), 0);
        });

        await check('reorder under the photo: drawn below it, listed below it', async () => {
            await e.dragRow(page, ellipse, 'photo', true);
            const order = (await layout()).order;
            assert.ok(order.indexOf(ellipse) > order.indexOf('photo'), `order ${order.join(',')}`);
            const svgOrder = await page.$$eval('.cal-cv svg[data-page] [data-el]', (list) => list.map((n) => (n.dataset.el === 'layer' ? `u:${n.dataset.layer}` : n.dataset.el)));
            assert.ok(svgOrder.indexOf(ellipse) < svgOrder.indexOf('photo'), 'painted before (under) the photo');
        });

        await check('the eye hides a layer on the page and in the PDF view; again shows it', async () => {
            await page.click(`[data-layer-eye="${line}"]`);
            assert.equal((await boxOf(line)).hidden, true);
            assert.equal(await stage(`[data-layer="${line.slice(2)}"]`), 0);
            await page.click(`[data-layer-eye="${line}"]`);
            assert.equal((await boxOf(line)).hidden, undefined);
            assert.equal(await stage(`[data-layer="${line.slice(2)}"]`), 1);
        });

        let copy = null;
        await check('row menu: rename in place, duplicate (above, shifted 3 mm), delete; Delete key + undo', async () => {
            await page.click(`[data-layer-menu-of="${rounded}"]`);
            await page.click('[data-layer-action="rename"]');
            const input = await page.waitForSelector(`[data-layer-name="${rounded}"]`, { timeout: 3000 });
            await input.type('Rám');
            await page.keyboard.press('Enter');
            await h.sleep(60);
            assert.equal((await itemOf(rounded)).name, 'Rám');
            const before = await ownKeys();
            await page.click(`[data-layer-menu-of="${rounded}"]`);
            await page.click('[data-layer-action="duplicate"]');
            await h.sleep(60);
            copy = (await ownKeys()).find((k) => !before.includes(k));
            assert.ok(copy, 'a copy');
            const order = (await layout()).order;
            assert.equal(order.indexOf(copy), order.indexOf(rounded) - 1, 'right above the original');
            const a = await boxOf(rounded);
            h.expectBox(await boxOf(copy), { x: a.x + 3, y: a.y + 3, w: a.w, h: a.h }, 0.01, 'shifted');
            assert.equal(await selected(), copy);
            // the Delete key removes the selected own layer, undo brings it back
            await page.click('.cal-cv', { offset: { x: 2, y: 2 } }).catch(() => null);
            await page.click(`[data-layer="${copy}"]`);
            await page.keyboard.press('Delete');
            await h.sleep(60);
            assert.equal(await itemOf(copy), undefined);
            await h.waitToast(page, /Vrstva smazána/);
            await undo();
            assert.ok(await itemOf(copy), 'undo brings it back');
            await page.click(`[data-layer-menu-of="${copy}"]`);
            await page.click('[data-layer-action="delete"]');
            await h.sleep(60);
            assert.equal(await itemOf(copy), undefined, 'deleted from the menu');
            // a built-in element is never deleted by the key
            await page.click('[data-layer="logo"]');
            await page.keyboard.press('Delete');
            assert.ok((await layout()).boxes.logo, 'the logo stays');
        });

        let saved = null;
        await check('save + reload: the layers come back exactly', async () => {
            await h.save(page);
            saved = await layout();
            await h.reload(page);
            const again = await layout();
            assert.deepEqual(again.layers, saved.layers);
            assert.deepEqual(again.order, saved.order);
            for (const key of Object.keys(saved.layers)) assert.deepEqual(again.boxes[`u:${key}`], saved.boxes[`u:${key}`]);
            assert.equal((await h.state(page)).status, 'clean');
            await h.shot(page, 'user-layers-step3');
        });

        await check('step 5 draws the layers on every week page', async () => {
            await h.gotoStep(page, 5);
            await page.waitForFunction(() => document.querySelectorAll('svg[data-page] [data-el="layer"]').length > 0, { timeout: 10000 });
            const ids = await page.$$eval('svg[data-page] [data-el="layer"]', (list) => [...new Set(list.map((n) => n.dataset.layer))]);
            assert.equal(ids.length, Object.keys(saved.layers).length);
            await h.shot(page, 'user-layers-step5');
        });

        await check('the client link: drawn, nothing to select; a forged layer save is 403', async () => {
            const b = await page.evaluate(() => JSON.parse(document.querySelector('[data-calendar-workspace-target="bootstrap"]').textContent));
            const context = await browser.createBrowserContext();
            const client = await h.newPage(context);
            try {
                await client.goto(b.env.clientUrl, { waitUntil: 'networkidle2' });
                await h.waitReady(client);
                await client.waitForFunction(() => document.querySelectorAll('svg[data-page] [data-el="layer"]').length > 0, { timeout: 10000 });
                assert.equal(await client.$('button[data-layer], [data-layer-eye], [data-cal-action="layer-add"], [data-handle]'), null, 'no layer UI for a client');
                const answer = await client.evaluate(async (layoutWithLayer) => {
                    const boot = JSON.parse(document.querySelector('[data-calendar-workspace-target="bootstrap"]').textContent);
                    const form = new FormData();
                    form.append('_token', boot.env.csrf);
                    form.append('payload', JSON.stringify({ design: { layout: layoutWithLayer } }));
                    const response = await fetch(boot.env.urls.save, { method: 'POST', body: form, credentials: 'same-origin', headers: { Accept: 'application/json' } });
                    return { status: response.status, json: await response.json() };
                }, saved);
                assert.equal(answer.status, 403);
                assert.equal(answer.json.error, 'Tuto změnu může udělat jen grafik.');
                await h.shot(client, 'user-layers-client');
            } finally {
                await context.close();
            }
        });

        await h.deleteCalendar(page, id).catch(() => null);
    },
};
