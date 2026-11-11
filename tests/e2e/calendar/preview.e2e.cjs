/*
 * Step 5 — Náhled and its floating editors (phase P7a; handoff C2, C9, C10, C11, §10.14).
 *
 * frame + probe → photo upload / zoom / real pointer pan / replace / remove (C2) → caption editor →
 * plus menu: Odpad, Akce, Komentář, removal list (C9, §10.14) → popover Enter / Esc / drag / clamp →
 * bleed and crop marks follow step 1 (C11) → the client sees the trimmed page (C10) → 14-day page:
 * plus buttons on the outer side of each column.
 */
const assert = require('assert/strict');
const fs = require('fs');
const os = require('os');
const path = require('path');
const zlib = require('zlib');

// ---- a tiny PNG encoder: test photos are made on the fly, nothing binary is committed -----------

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

/** An RGB gradient (so every crop looks different), rows stored with the Sub filter — a few kB. */
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

// ---- helpers ------------------------------------------------------------------------------------

const exists = async (page, selector) => (await page.$(selector)) !== null;
const near = (actual, expected, tol, label) => assert.ok(Math.abs(actual - expected) <= tol, `${label}: expected ${expected} ± ${tol}, got ${actual}`);
const rectOf = (page, selector) =>
    page.$eval(selector, (el) => {
        const r = el.getBoundingClientRect();
        return { left: r.left, top: r.top, right: r.right, bottom: r.bottom, width: r.width, height: r.height, cx: r.left + r.width / 2, cy: r.top + r.height / 2 };
    });
const bootstrap = (page) => page.evaluate(() => JSON.parse(document.querySelector('[data-calendar-workspace-target="bootstrap"]').textContent));
const dayLabel = (date) => `${Number(date.slice(8))}. ${Number(date.slice(5, 7))}.`;
const POP = '.cal-pop';

module.exports = {
    name: 'preview',
    checks: ['C2', 'C9', 'C10', 'C11', '§10.14'],
    async run({ browser, page, h, check }) {
        const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'wb-cal-p7a-'));
        const photoA = gradientPng(1600, 1000, path.join(tmp, 'foto-a.png'));
        const photoB = gradientPng(1200, 900, path.join(tmp, 'foto-b.png'));
        let id = null;
        let clientUrl = null;
        let week = null; // { index, key, title, days }
        let day = null;
        let kindBefore = null;

        const state = () => h.state(page);
        const svgViewBox = (p = page) => p.$eval('.cal-5w svg', (el) => el.getAttribute('viewBox'));

        async function showPage(index, p = page) {
            const key = await h.cal(p, (c, i) => c.pages()[i].key, index);
            await p.click(`[data-page-thumb="${index}"]`);
            await p.waitForSelector(`.cal-5w svg[data-page="${key}"]`, { timeout: 5000 });
        }

        /** A step change stores the step silently; let that request finish before the next change. */
        async function stepTo(n) {
            await h.gotoStep(page, n);
            await page.waitForNetworkIdle({ idleTime: 200, timeout: 10000 });
        }

        async function history() {
            await page.click('[data-cal-action="history"]');
            await page.waitForSelector('[data-cal-dialog="history"].show .cal-hrow', { timeout: 8000 });
            const rows = await page.$$eval('[data-cal-dialog="history"] .cal-hrow', (nodes) => nodes.map((n) => ({ who: n.querySelector('.pill').textContent.trim(), what: n.querySelector('.cal-hwhat').textContent.trim() })));
            await h.sleep(350);
            await page.click('[data-cal-dialog="history"] [data-cal-action="modal-done"]');
            await page.waitForFunction(() => !document.querySelector('[data-cal-dialog="history"]'), { timeout: 5000 });
            return rows;
        }

        async function openPlus(date, p = page) {
            await p.click(`[data-day-plus="${date}"]`);
            await p.waitForSelector(`${POP}[data-cal-popover="plus"]`, { timeout: 3000 });
        }

        async function popoverGone(p = page) {
            await p.waitForFunction((selector) => !document.querySelector(selector), { timeout: 3000 }, POP);
        }

        async function setZoom(percent) {
            // a real click on the track, then arrow keys to the exact value
            const box = await (await page.$('[data-photo-zoom]')).boundingBox();
            await page.mouse.click(box.x + (box.width * (percent - 100)) / 200, box.y + box.height / 2);
            for (let i = 0; i < 80; i++) {
                const value = Number(await page.$eval('[data-photo-zoom]', (el) => el.value));
                if (value === percent) break;
                await page.focus('[data-photo-zoom]');
                await page.keyboard.press(value < percent ? 'ArrowRight' : 'ArrowLeft');
            }
            assert.equal(Number(await page.$eval('[data-photo-zoom]', (el) => el.value)), percent);
        }

        await check('setup: a 7-day calendar with a layout, waste and events on, opened in step 5', async () => {
            id = await h.createCalendar(page);
            await h.gotoStep(page, 2);
            await page.click('[data-preset="photoLeft"]');
            await page.waitForSelector('.cal-ws[data-cal-shown-step="3"]');
            await h.save(page);
            const s = await state();
            kindBefore = { ...s.content.wasteKinds.bio, on: true };
            await h.seed(page, {
                design: { features: { ...s.design.features, waste: true, events: true, captions: true }, step: 5 },
                content: [
                    { c: 'wasteKinds', id: 'bio', v: kindBefore },
                    { c: 'clubs', id: 'clubZS000', v: { code: 'ZŠ', name: 'Základní škola', pos: 0 } },
                ],
            });
            assert.equal(await page.$eval('.cal-ws', (el) => el.dataset.calShownStep), '5');
            clientUrl = (await bootstrap(page)).env.clientUrl;
            const pages = await h.cal(page, (c) => c.pages());
            const year = (await state()).design.year;
            week = pages.find((p) => p.kind === 'week' && p.week.days.every((d) => d.startsWith(String(year))) && p.week.days[0].slice(5, 7) === '02');
            assert.ok(week, 'a February week page exists');
            day = week.week.days[2];
        });
        if (!week) return;

        await check('frame: toolbar, thumbnails, previous / next, the page fits and the probe answers', async () => {
            assert.equal(await exists(page, '[data-cal-stub="P7a"]'), false, 'the stub is gone');
            assert.deepEqual(await page.$$eval('.cal-b6 [data-tool]', (nodes) => nodes.map((n) => n.dataset.tool)), ['photos', 'waste', 'events', 'names', 'moon', 'shifts', 'qr']);
            for (const action of ['client-link', 'history', 'page-prev', 'page-next']) assert.ok(await exists(page, `.cal-b6 [data-cal-action="${action}"]`), action);
            const count = await h.cal(page, (c) => c.pages().length);
            assert.equal(await page.$$eval('.cal-b6-list [data-page-thumb]', (nodes) => nodes.length), count);
            assert.equal(await page.$eval('[data-cal-action="page-prev"]', (el) => el.disabled), true);
            assert.match(await page.$eval('.cal-5n', (el) => el.textContent), new RegExp(`1 / ${count}`));
            await page.click('[data-cal-action="page-next"]');
            await page.waitForFunction((n) => document.querySelector('.cal-5n').textContent.includes(`2 / ${n}`), {}, count);
            assert.equal(await page.$eval('[data-page-thumb="1"]', (el) => el.getAttribute('aria-current')), 'true');
            await page.click('[data-cal-action="page-prev"]');
            await page.waitForFunction((n) => document.querySelector('.cal-5n').textContent.includes(`1 / ${n}`), {}, count);
            assert.equal(await exists(page, '[data-day-plus]'), false, 'the cover has no day buttons');
            assert.equal(await page.$eval('.cal-5ph', (el) => el.hidden), true, 'the cover has no photo controls');

            await showPage(week.index);
            const scale = await h.cal(page, (c) => c.scale());
            assert.ok(scale > 1, `scale ${scale}`);
            const a = await h.cal(page, (c) => c.toClient(0, 0));
            const b = await h.cal(page, (c) => c.toClient(100, 50));
            near(b.x - a.x, 100 * scale, 0.5, 'toClient x');
            near(b.y - a.y, 50 * scale, 0.5, 'toClient y');
            assert.equal(await h.cal(page, (c) => c.geometry().frames[0].rows.length), 7);
            const stage = await rectOf(page, '.cal-5stage');
            const sheet = await rectOf(page, '.cal-5w');
            assert.ok(sheet.left >= stage.left && sheet.right <= stage.right && sheet.top >= stage.top && sheet.bottom <= stage.bottom, 'the page lies inside the stage');
            await h.shot(page, 'preview-designer');
        });

        await check('C10 designer: the page is shown with bleed and crop marks', async () => {
            const p = await h.cal(page, (c) => c.page());
            assert.equal(p.bleedMm, 5);
            assert.equal(await page.$eval('.cal-b6', (el) => el.dataset.view), 'print');
            assert.equal(await page.$$eval('.cal-5w svg [data-el="marks"] line', (nodes) => nodes.length), 8);
            const [x, y, w, hh] = (await svgViewBox()).split(' ').map(Number);
            assert.ok(x < -p.bleedMm && y < -p.bleedMm, 'the viewBox reaches beyond the bleed');
            near(w, p.width - 2 * x, 0.01, 'viewBox width');
            near(hh, p.height - 2 * y, 0.01, 'viewBox height');
            // the bleed ring is really drawn: the trim corner lies inside the picture by more than the bleed
            const corner = await h.cal(page, (c) => c.toClient(0, 0));
            const sheet = await rectOf(page, '.cal-5w');
            const scale = await h.cal(page, (c) => c.scale());
            assert.ok(corner.x - sheet.left > p.bleedMm * scale, 'bleed + marks margin left of the trim');
        });

        let assetA = null;
        await check('C2 upload: Nahrát fotku stores the photo centred at 100 % with its pixel size', async () => {
            assert.equal(await page.$eval('.cal-5up span:not(.i)', (el) => el.textContent), 'Nahrát fotku');
            assert.equal(await page.$eval('.cal-5z', (el) => el.hidden), true, 'no zoom without a photo');
            await (await page.$('[data-photo-upload]')).uploadFile(photoA);
            await page.waitForFunction((key) => Boolean(window.__calendar.state().content.pagePhotos[key]), { timeout: 15000 }, week.key);
            const item = (await state()).content.pagePhotos[week.key];
            assetA = item.asset;
            assert.deepEqual({ zoom: item.zoom, px: item.px, py: item.py }, { zoom: 1, px: 0.5, py: 0.5 });
            assert.equal(await page.$eval('.cal-5up span:not(.i)', (el) => el.textContent), 'Vyměnit fotku');
            assert.equal(await page.$eval('.cal-5z', (el) => el.hidden), false);
            assert.ok(await exists(page, '.cal-5w svg g[data-el="photo"] image'), 'the photo is drawn');
            await page.waitForSelector(`[data-page-thumb="${week.index}"][data-overridden]`, { timeout: 3000 });
            await h.save(page);
        });
        if (!assetA) return;

        let crop = null;
        await check('C2 zoom 200 % + real pointer drag change the crop; it persists after save + reload', async () => {
            await setZoom(200);
            assert.equal((await state()).content.pagePhotos[week.key].zoom, 2);
            const box = (await h.boxes(page)).photo;
            // cover scale × zoom of a 1600 × 1000 photo
            const dh = Math.max(box.w / 1.6, box.h) * 2;
            const dw = dh * 1.6;
            const image = () => page.$eval('.cal-5w svg g[data-el="photo"] image', (el) => ({ x: Number(el.getAttribute('x')), y: Number(el.getAttribute('y')), w: Number(el.getAttribute('width')), h: Number(el.getAttribute('height')) }));
            near((await image()).w, dw, 0.01, 'drawn width at 200 %');
            const centre = { x: Math.max(box.x, 0) + 40, y: box.y + box.h / 2 };
            // mid-drag: the picture follows the pointer, nothing is stored yet
            await h.dragMm(page, centre, { x: centre.x + 20, y: centre.y + 10 }, { hold: true });
            near((await image()).x, box.x + (box.w - dw) * 0.5 + 20, 0.5, 'image x mid-drag');
            assert.equal((await state()).content.pagePhotos[week.key].px, 0.5, 'nothing stored mid-drag');
            await page.mouse.up();
            await h.sleep(80);
            const item = (await state()).content.pagePhotos[week.key];
            near(item.px, 0.5 + 20 / (box.w - dw), 0.01, 'px');
            near(item.py, 0.5 + 10 / (box.h - dh), 0.01, 'py');
            assert.ok(item.px < 0.5 && item.py < 0.5, 'dragging right / down reveals the left / top of the photo');
            near((await image()).x, box.x + (box.w - dw) * item.px, 0.01, 'image x after the drag');
            // a drag far beyond the frame stops at the photo's edge
            await h.dragMm(page, centre, { x: centre.x + 400, y: centre.y + 400 });
            const edge = (await state()).content.pagePhotos[week.key];
            assert.deepEqual({ px: edge.px, py: edge.py }, { px: 0, py: 0 });
            await h.dragMm(page, centre, { x: centre.x - 30, y: centre.y - 12 });
            crop = (await state()).content.pagePhotos[week.key];
            near(crop.px, -(-30) / (dw - box.w), 0.01, 'px after the second drag');
            assert.equal((await state()).status, 'dirty');
            await h.save(page);
            await h.reload(page);
            assert.equal(await page.$eval('.cal-ws', (el) => el.dataset.calShownStep), '5');
            await showPage(week.index);
            const stored = (await state()).content.pagePhotos[week.key];
            assert.deepEqual(stored, crop, 'crop and zoom survived the reload');
            assert.equal(Number(await page.$eval('[data-photo-zoom]', (el) => el.value)), 200);
            near((await image()).x, box.x + (box.w - dw) * crop.px, 0.01, 'image x after reload');
            const asset = (await bootstrap(page)).assets[assetA];
            assert.deepEqual({ width: asset.width, height: asset.height }, { width: 1600, height: 1000 }, 'the upload recorded the pixel size');
            await h.shot(page, 'preview-photo-crop');
        });

        await check('C2 history: upload and crop are listed', async () => {
            const rows = await history();
            const what = rows.map((r) => r.what);
            assert.ok(what.includes(`Nahrál fotku — ${week.title}`), what.join(' | '));
            assert.ok(what.includes(`Posunul fotku — ${week.title}`), what.join(' | '));
            assert.ok(rows.every((r) => r.who === 'Grafik'));
        });

        await check('a replaced photo returns to centred 100 %; remove clears it', async () => {
            await (await page.$('[data-photo-upload]')).uploadFile(photoB);
            await page.waitForFunction((key, old) => window.__calendar.state().content.pagePhotos[key].asset !== old, { timeout: 15000 }, week.key, assetA);
            const item = (await state()).content.pagePhotos[week.key];
            assert.deepEqual({ zoom: item.zoom, px: item.px, py: item.py }, { zoom: 1, px: 0.5, py: 0.5 });
            assert.equal(Number(await page.$eval('[data-photo-zoom]', (el) => el.value)), 100);
            await h.save(page);
            await page.click('[data-photo-remove]');
            assert.equal((await state()).content.pagePhotos[week.key], undefined);
            assert.equal(await page.$eval('.cal-5up span:not(.i)', (el) => el.textContent), 'Nahrát fotku');
            assert.equal(await exists(page, '.cal-5w svg g[data-el="photo"] image'), false);
            await h.save(page);
            const what = (await history()).map((r) => r.what);
            assert.ok(what.includes(`Vyměnil fotku — ${week.title}`), what.join(' | '));
            assert.ok(what.includes(`Odebral fotku — ${week.title}`), what.join(' | '));
        });

        await check('caption editor: live 80-character counter, Enter saves, Esc discards', async () => {
            await page.click('[data-caption-edit]');
            await page.waitForSelector(`${POP}[data-cal-popover="caption"]`);
            assert.equal(await page.$eval(`${POP} .cal-pop-t`, (el) => el.textContent), 'Popisek fotky');
            assert.equal(await page.evaluate(() => document.activeElement.dataset.field), 'caption', 'the field has focus');
            assert.equal(await page.$eval(`${POP} [data-counter]`, (el) => el.textContent), '0 / 80 znaků');
            await page.keyboard.type('Zámek v zimě, foto J. Novák');
            assert.equal(await page.$eval(`${POP} [data-counter]`, (el) => el.textContent), '27 / 80 znaků');
            await page.keyboard.press('Enter');
            await popoverGone();
            assert.deepEqual((await state()).content.pageCaptions[week.key], { text: 'Zámek v zimě, foto J. Novák' });
            assert.match(await page.$eval('.cal-5w svg [data-el="cap"]', (el) => el.textContent), /Zámek v zimě/);
            assert.equal(await page.evaluate(() => document.activeElement.hasAttribute('data-caption-edit')), true, 'focus returns to the pencil');

            await page.click('[data-caption-edit]');
            await page.waitForSelector(`${POP}[data-cal-popover="caption"]`);
            assert.equal(await page.$eval(`${POP} [data-field="caption"]`, (el) => el.value), 'Zámek v zimě, foto J. Novák');
            await page.keyboard.type('x'.repeat(90));
            assert.equal(await page.$eval(`${POP} [data-field="caption"]`, (el) => el.value.length), 80, 'no more than 80 characters');
            assert.equal(await page.$eval(`${POP} [data-counter]`, (el) => el.textContent), '80 / 80 znaků');
            await page.keyboard.press('Escape');
            await popoverGone();
            assert.deepEqual((await state()).content.pageCaptions[week.key], { text: 'Zámek v zimě, foto J. Novák' }, 'Esc saved nothing');

            await page.click('[data-caption-edit]');
            await page.waitForSelector(`${POP}[data-cal-popover="caption"]`);
            await page.click(`${POP} [data-popover-close]`);
            await popoverGone();
            assert.equal((await state()).content.pageCaptions[week.key].text, 'Zámek v zimě, foto J. Novák');
        });

        await check('plus buttons sit outside the kalendárium on its outer side, one per day', async () => {
            const dates = await page.$$eval('[data-day-plus]', (nodes) => nodes.map((n) => n.dataset.dayPlus));
            assert.deepEqual(dates, week.week.days);
            const frame = await h.cal(page, (c) => c.geometry().frames[0]);
            const p = await h.cal(page, (c) => c.page());
            assert.ok(frame.box.x + frame.box.w / 2 > p.width / 2, 'Fotka vlevo: the kalendárium is on the right');
            const edge = await h.cal(page, (c, x) => c.toClient(x, 0), Math.min(frame.box.x + frame.box.w, p.width + p.bleedMm));
            for (const row of frame.rows) {
                const r = await rectOf(page, `[data-day-plus="${row.date}"]`);
                assert.ok(r.left >= edge.x, `${row.date}: right of the kalendárium`);
                const centre = await h.cal(page, (c, y) => c.toClient(0, y), row.y + row.h / 2);
                near(r.cy, centre.y, 1, `${row.date}: vertically on its row`);
            }
            assert.equal(await page.$eval(`[data-day-plus="${day}"]`, (el) => el.getAttribute('aria-label')), 'Přidat do dne');
        });

        await check('popover: opens near the click, drags by its grip, stays inside the viewport, close discards', async () => {
            await openPlus(day);
            const button = await rectOf(page, `[data-day-plus="${day}"]`);
            let pop = await rectOf(page, POP);
            const view = await page.evaluate(() => ({ w: window.innerWidth, h: window.innerHeight }));
            assert.ok(pop.left >= 12 && pop.right <= view.w - 12 && pop.top >= 12 && pop.bottom <= view.h - 12, 'inside the viewport');
            const gap = Math.min(Math.abs(pop.top - button.bottom), Math.abs(button.top - pop.bottom));
            assert.ok(gap <= 14, `opens right under / above the button (gap ${gap})`);
            assert.ok(Math.abs(pop.cx - button.cx) <= pop.width / 2 + 12, 'horizontally at the button');
            assert.equal(await page.$eval(`${POP} .cal-pop-t`, (el) => el.textContent), `Přidat — ${dayLabel(day)} ${day.slice(0, 4)}`);
            assert.equal(await page.$eval(`${POP} [data-popover-grip]`, (el) => el.title), 'Přetažením přesunete');
            assert.equal(await page.$eval(`${POP} [data-popover-close]`, (el) => el.getAttribute('aria-label')), 'Zavřít bez uložení');

            const grip = await rectOf(page, `${POP} [data-popover-grip]`);
            await page.mouse.move(grip.cx, grip.cy);
            await page.mouse.down();
            await page.mouse.move(grip.cx - 220, grip.cy - 90, { steps: 8 });
            await page.mouse.up();
            const moved = await rectOf(page, POP);
            near(moved.left, pop.left - 220, 1.5, 'dragged left');
            near(moved.top, pop.top - 90, 1.5, 'dragged up');

            const grip2 = await rectOf(page, `${POP} [data-popover-grip]`);
            await page.mouse.move(grip2.cx, grip2.cy);
            await page.mouse.down();
            await page.mouse.move(view.w - 3, 3, { steps: 6 });
            await page.mouse.up();
            await h.sleep(50);
            pop = await rectOf(page, POP);
            assert.ok(pop.right <= view.w - 11 && pop.bottom <= view.h - 11 && pop.left >= 12 && pop.top >= 12, `clamped into the viewport (${JSON.stringify(pop)})`);

            // the next editor opens in place — where the user left the popover
            await page.click(`${POP} [data-plus-option="comment"]`);
            await page.waitForSelector(`${POP}[data-cal-popover="comment"]`);
            const after = await rectOf(page, POP);
            assert.ok(Math.abs(after.right - pop.right) < 2 || Math.abs(after.left - pop.left) < 2, 'the editor replaced the menu in place');
            await page.keyboard.type('nic');
            await page.click(`${POP} [data-popover-close]`);
            await popoverGone();
            assert.equal(Object.keys((await state()).content.comments).length, 0, 'close saved nothing');
            assert.equal(await page.evaluate(() => document.activeElement.dataset.dayPlus || ''), day, 'focus returns to the plus button');
        });

        await check('C9 Odpad: an extra collection on that day only; the regular rule is untouched', async () => {
            const bins = () => page.$$eval('.cal-5w svg g[data-date]', (rows) => Object.fromEntries(rows.map((row) => [row.dataset.date, row.querySelectorAll('[data-part="waste"]').length])));
            const before = await bins();
            assert.equal(before[day], 0, 'no collection on that day by the rule');
            await openPlus(day);
            assert.deepEqual(await page.$$eval(`${POP} [data-plus-option]`, (nodes) => nodes.map((n) => `${n.dataset.plusOption}:${n.querySelector('b').textContent}:${n.querySelector('.cap').textContent}`)), ['waste:Odpad:svoz v tento den', 'event:Akce:událost v kalendáři', 'comment:Komentář:poznámka pro grafika']);
            await page.click(`${POP} [data-plus-option="waste"]`);
            await page.waitForSelector(`${POP}[data-cal-popover="waste"]`);
            assert.equal(await page.$eval(`${POP} .cal-pop-t`, (el) => el.textContent), `Odpad — ${dayLabel(day)} ${day.slice(0, 4)}`);
            assert.deepEqual(await page.$$eval(`${POP} [data-field="kind"] option`, (nodes) => nodes.map((n) => n.textContent)), ['Bio odpad'], 'only the kinds that are switched on');
            assert.equal(await page.$eval(`${POP} [data-popover-confirm]`, (el) => el.textContent.trim()), 'Přidat');
            await page.keyboard.press('Enter');
            await popoverGone();
            await h.waitToast(page, `Svoz přidán — Bio odpad, ${dayLabel(day).replace(/\./g, '\\.')}`);
            const s = await state();
            const extras = Object.values(s.content.wasteExtras);
            assert.deepEqual(extras, [{ kind: 'bio', date: day }]);
            assert.deepEqual(s.content.wasteKinds.bio, kindBefore, 'the rule of the kind did not change');
            assert.deepEqual(await bins(), { ...before, [day]: 1 }, 'the bin icon appears on that day and on no other');
        });

        await check('C9 Akce: an empty name is not saved and keeps focus; Enter adds the event with its organiser', async () => {
            await openPlus(day);
            await page.click(`${POP} [data-plus-option="event"]`);
            await page.waitForSelector(`${POP}[data-cal-popover="event"]`);
            assert.equal(await page.$eval(`${POP} [data-field="name"]`, (el) => el.placeholder), 'Název akce');
            assert.deepEqual(await page.$$eval(`${POP} [data-field="club"] option`, (nodes) => nodes.map((n) => n.textContent)), ['Bez pořadatele', 'Základní škola']);
            await page.keyboard.press('Enter');
            await h.sleep(60);
            assert.ok(await exists(page, `${POP}[data-cal-popover="event"]`), 'the popover stays open');
            assert.equal(await page.evaluate(() => document.activeElement.dataset.field), 'name', 'the name keeps focus');
            await page.keyboard.type('   ');
            await page.click(`${POP} [data-popover-confirm]`);
            await h.sleep(60);
            assert.equal(await page.evaluate(() => document.activeElement.dataset.field), 'name');
            assert.equal(Object.keys((await state()).content.events).length, 0, 'nothing saved');
            await page.$eval(`${POP} [data-field="name"]`, (el) => {
                el.value = '';
            });
            await page.keyboard.type('Masopust');
            assert.equal(await page.$eval(`${POP} [data-counter]`, (el) => el.textContent), '8 / 80 znaků');
            await page.select(`${POP} [data-field="club"]`, 'clubZS000');
            await page.focus(`${POP} [data-field="name"]`);
            await page.keyboard.press('Enter');
            await popoverGone();
            await h.waitToast(page, `Akce přidána — ${dayLabel(day).replace(/\./g, '\\.')}`);
            assert.deepEqual(Object.values((await state()).content.events), [{ date: day, name: 'Masopust', club: 'clubZS000', icon: null }]);
            assert.match(await page.$eval(`.cal-5w svg g[data-date="${day}"]`, (el) => el.textContent), /ZŠ · Masopust/);
        });

        await check('C9 Komentář: empty is not sent; the bubble shows 1 and opens the thread', async () => {
            assert.equal(await exists(page, `[data-day-comments="${day}"]`), false);
            await openPlus(day);
            await page.click(`${POP} [data-plus-option="comment"]`);
            await page.waitForSelector(`${POP}[data-cal-popover="comment"]`);
            assert.equal(await page.$eval(`${POP} [data-field="text"]`, (el) => el.placeholder), 'Napište komentář…');
            assert.equal(await page.$eval(`${POP} [data-popover-confirm]`, (el) => el.textContent.trim()), 'Odeslat');
            await page.click(`${POP} [data-popover-confirm]`);
            await h.sleep(60);
            assert.ok(await exists(page, `${POP}[data-cal-popover="comment"]`), 'stays open');
            assert.equal(await page.evaluate(() => document.activeElement.dataset.field), 'text', 'the comment keeps focus');
            assert.equal(Object.keys((await state()).content.comments).length, 0);
            await page.keyboard.type('Prosím jinou fotku');
            await page.keyboard.press('Enter'); // a new line, not a confirmation
            assert.ok(await exists(page, POP));
            await page.keyboard.press('Backspace');
            await page.click(`${POP} [data-popover-confirm]`);
            await popoverGone();
            const comments = Object.values((await state()).content.comments);
            assert.equal(comments.length, 1);
            assert.deepEqual({ date: comments[0].date, text: comments[0].text, author: comments[0].author }, { date: day, text: 'Prosím jinou fotku', author: 'designer' });
            assert.equal(await page.$eval(`[data-day-comments="${day}"]`, (el) => el.textContent), '1');
            const plus = await rectOf(page, `[data-day-plus="${day}"]`);
            const bubble = await rectOf(page, `[data-day-comments="${day}"]`);
            assert.ok(bubble.left > plus.right, 'the bubble sits further out than the plus');
            await page.click(`[data-day-comments="${day}"]`);
            await page.waitForSelector(`${POP}[data-cal-popover="comment"] [data-comment-thread]`);
            assert.match(await page.$eval(`${POP} .cal-5cm`, (el) => el.textContent), /Grafik.*Prosím jinou fotku/s);
            await page.keyboard.press('Escape');
            await popoverGone();
        });

        await check('C9 persistence + history: three entries after save and reload', async () => {
            await h.save(page);
            await h.reload(page);
            await showPage(week.index);
            const s = await state();
            assert.equal(Object.keys(s.content.wasteExtras).length, 1);
            assert.equal(Object.keys(s.content.events).length, 1);
            assert.equal(Object.keys(s.content.comments).length, 1);
            assert.equal(s.content.pageCaptions[week.key].text, 'Zámek v zimě, foto J. Novák');
            assert.equal(await page.$eval(`[data-day-comments="${day}"]`, (el) => el.textContent), '1');
            const what = (await history()).map((r) => r.what);
            for (const sentence of [`Přidal svoz Bio odpad — ${dayLabel(day)}`, `Přidal akci „Masopust“ — ${dayLabel(day)}`, `Komentář k ${dayLabel(day)}: Prosím jinou fotku`, `Upravil popisek — ${week.title}`]) {
                assert.ok(what.includes(sentence), `${sentence} ∉ ${what.join(' | ')}`);
            }
        });

        await check('§10.14: the plus menu lists the day’s extra collection and event with a remove button; comments stay', async () => {
            await openPlus(day);
            const rows = await page.$$eval(`${POP} .cal-5day li`, (nodes) => nodes.map((n) => ({ name: n.querySelector('span:not(.i)').textContent, aria: n.querySelector('button').getAttribute('aria-label'), key: n.querySelector('button').dataset.dayRemove.split('/')[0] })));
            assert.equal(await page.$eval(`${POP} .cal-5day h4`, (el) => el.textContent), 'V tento den');
            assert.deepEqual(rows, [
                { name: 'Bio odpad', aria: 'Odebrat Bio odpad', key: 'wasteExtras' },
                { name: 'Masopust', aria: 'Odebrat Masopust', key: 'events' },
            ]);
            await page.click(`${POP} [data-day-remove^="wasteExtras/"]`);
            assert.equal(Object.keys((await state()).content.wasteExtras).length, 0);
            assert.ok(await exists(page, `${POP}[data-cal-popover="plus"]`), 'the menu stays open');
            assert.equal(await page.$$eval(`${POP} .cal-5day li`, (n) => n.length), 1);
            await page.click(`${POP} [data-day-remove^="events/"]`);
            assert.equal(Object.keys((await state()).content.events).length, 0);
            assert.equal(await exists(page, `${POP} .cal-5day`), false, 'nothing left to remove');
            await page.keyboard.press('Escape');
            await popoverGone();
            assert.equal(Object.keys((await state()).content.comments).length, 1, 'comments are permanent');
            // another day's menu has no removal list
            await openPlus(week.week.days[4]);
            assert.equal(await exists(page, `${POP} .cal-5day`), false);
            await page.keyboard.press('Escape');
            await h.save(page);
            const what = (await history()).map((r) => r.what);
            assert.ok(what.some((w) => w.startsWith('Odebral svoz Bio odpad')), what.join(' | '));
            assert.ok(what.includes('Odebral akci „Masopust“'), what.join(' | '));
        });

        await check('a tool opened from step 5 closes the floating editor (keys go to the dialog)', async () => {
            await openPlus(day);
            await page.click('.cal-b6 [data-tool="names"]');
            await page.waitForSelector('.cal-tool-modal.show');
            assert.equal(await exists(page, POP), false);
            await h.sleep(350);
            await page.click('.cal-tool-modal [data-cal-action="modal-done"]');
            await page.waitForFunction(() => !document.querySelector('.cal-tool-modal'));
        });

        await check('C11: with bleed and crop marks off in step 1 the preview shows neither', async () => {
            const p = await h.cal(page, (c) => c.page());
            // crop marks off, bleed on → the bleed ring without marks
            await stepTo(1);
            await page.click('label:has([data-print="marks"])');
            await stepTo(5);
            await showPage(week.index);
            assert.equal(await page.$eval('.cal-b6', (el) => el.dataset.view), 'print');
            assert.equal(await exists(page, '.cal-5w svg [data-el="marks"]'), false);
            assert.deepEqual((await svgViewBox()).split(' ').map(Number), [-p.bleedMm, -p.bleedMm, p.width + 2 * p.bleedMm, p.height + 2 * p.bleedMm]);
            // bleed off too → the trimmed page
            await stepTo(1);
            await page.click('label:has([data-print="bleed"])');
            assert.deepEqual({ bleed: (await state()).design.print.bleed, marks: (await state()).design.print.marks }, { bleed: false, marks: false });
            await stepTo(5);
            await showPage(week.index);
            assert.equal(await page.$eval('.cal-b6', (el) => el.dataset.view), 'trim');
            assert.equal(await exists(page, '.cal-5w svg [data-el="marks"]'), false);
            assert.deepEqual((await svgViewBox()).split(' ').map(Number), [0, 0, p.width, p.height]);
            const corner = await h.cal(page, (c) => c.toClient(0, 0));
            const sheet = await rectOf(page, '.cal-5w');
            near(corner.x, sheet.left, 0.5, 'the trim corner is the corner of the picture');
            assert.equal(await page.$$eval('[data-day-plus]', (n) => n.length), 7, 'the day buttons still work on the trimmed page');
            await h.shot(page, 'preview-designer-trimmed');
            // marks on without bleed → marks around the trim
            await stepTo(1);
            await page.click('label:has([data-print="marks"])');
            await stepTo(5);
            assert.equal(await page.$eval('.cal-b6', (el) => el.dataset.view), 'print');
            assert.equal(await page.$$eval('.cal-5w svg [data-el="marks"] line', (n) => n.length), 8);
            // back to the defaults for the client check
            await stepTo(1);
            await page.click('label:has([data-print="bleed"])');
            assert.deepEqual((await state()).design.print, { bleed: true, bleedMm: 5, marks: true });
            await stepTo(5);
            await h.save(page);
        });

        await check('C10 client: the trimmed page, four tools, and the same editors — a comment is saved as Klient', async () => {
            const client = await h.newPage(browser);
            try {
                await client.goto(clientUrl, { waitUntil: 'networkidle2' });
                await h.waitReady(client);
                assert.equal((await h.state(client)).mode, 'client');
                assert.equal(await client.$eval('.cal-b6', (el) => el.dataset.view), 'trim');
                const p = await h.cal(client, (c) => c.page());
                assert.equal(p.bleedMm, 5, 'the calendar itself has a bleed');
                assert.deepEqual((await svgViewBox(client)).split(' ').map(Number), [0, 0, p.width, p.height]);
                assert.equal(await exists(client, '.cal-5w svg [data-el="marks"]'), false);
                assert.deepEqual(await client.$$eval('.cal-b6 [data-tool]', (nodes) => nodes.map((n) => n.dataset.tool)), ['photos', 'waste', 'events', 'names']);
                assert.equal(await exists(client, '[data-cal-action="client-link"]'), false);
                assert.equal(await exists(client, '[data-cal-step]'), false, 'no step bar');
                assert.ok(await exists(client, '[data-cal-action="history"]'));
                await showPage(week.index, client);
                assert.equal(await client.$$eval('[data-day-plus]', (n) => n.length), 7);
                assert.ok(await exists(client, '[data-caption-edit]'));
                assert.ok(await exists(client, '[data-photo-upload]'));
                assert.equal(await client.$eval(`[data-day-comments="${day}"]`, (el) => el.textContent), '1');
                await openPlus(day, client);
                await client.click(`${POP} [data-plus-option="comment"]`);
                await client.waitForSelector(`${POP}[data-cal-popover="comment"] [data-comment-thread]`);
                await client.keyboard.type('Fotku pošleme zítra');
                await client.keyboard.down('Control');
                await client.keyboard.press('Enter');
                await client.keyboard.up('Control');
                await popoverGone(client);
                assert.equal(await client.$eval(`[data-day-comments="${day}"]`, (el) => el.textContent), '2');
                await h.shot(client, 'preview-client');
                await h.save(client);
                assert.deepEqual(client.problems, [], 'client console');
            } finally {
                await client.close();
            }
            await h.reload(page);
            const comments = Object.values((await state()).content.comments).sort((a, b) => a.at.localeCompare(b.at));
            assert.deepEqual(comments.map((c) => `${c.author}:${c.text}`), ['designer:Prosím jinou fotku', 'client:Fotku pošleme zítra']);
            const rows = await history();
            assert.ok(rows.some((r) => r.who === 'Klient' && r.what === `Komentář k ${dayLabel(day)}: Fotku pošleme zítra`), JSON.stringify(rows.slice(0, 4)));
        });

        await check('14 days: both columns get plus buttons on their outer sides', async () => {
            const second = await h.createCalendar(page);
            await page.click('[data-type="fortnight"]');
            await h.gotoStep(page, 2);
            await page.waitForSelector('[data-cal-action="continue"]');
            await page.click('[data-cal-action="continue"]');
            await page.waitForSelector('.cal-ws[data-cal-shown-step="3"]');
            await h.gotoStep(page, 5);
            const pages = await h.cal(page, (c) => c.pages());
            const fortnight = pages.filter((p) => p.kind === 'week')[2];
            assert.equal(fortnight.week.days.length, 14);
            await showPage(fortnight.index);
            assert.deepEqual(await page.$$eval('[data-day-plus]', (nodes) => nodes.map((n) => n.dataset.dayPlus).sort()), fortnight.week.days.slice().sort());
            const frames = await h.cal(page, (c) => c.geometry().frames);
            const p = await h.cal(page, (c) => c.page());
            assert.equal(frames.length, 2);
            for (const frame of frames) {
                const left = frame.box.x + frame.box.w / 2 < p.width / 2;
                const edgeMm = left ? Math.max(frame.box.x, -p.bleedMm) : Math.min(frame.box.x + frame.box.w, p.width + p.bleedMm);
                const edge = await h.cal(page, (c, x) => c.toClient(x, 0), edgeMm);
                for (const row of frame.rows) {
                    const r = await rectOf(page, `[data-day-plus="${row.date}"]`);
                    if (left) assert.ok(r.right <= edge.x, `${row.date}: left of the first column`);
                    else assert.ok(r.left >= edge.x, `${row.date}: right of the second column`);
                }
            }
            assert.equal(frames[0].box.x + frames[0].box.w / 2 < p.width / 2, true, 'first week on the left');
            assert.equal(frames[1].box.x + frames[1].box.w / 2 > p.width / 2, true, 'second week on the right');
            // a comment on a day of the second column lands on that day
            const date = frames[1].rows[3].date;
            await openPlus(date);
            await page.click(`${POP} [data-plus-option="comment"]`);
            await page.waitForSelector(`${POP}[data-cal-popover="comment"]`);
            await page.keyboard.type('Druhý týden');
            await page.click(`${POP} [data-popover-confirm]`);
            await popoverGone();
            assert.equal(await page.$eval(`[data-day-comments="${date}"]`, (el) => el.textContent), '1');
            const plus = await rectOf(page, `[data-day-plus="${date}"]`);
            const bubble = await rectOf(page, `[data-day-comments="${date}"]`);
            assert.ok(bubble.left > plus.right, 'outwards on the right column');
            await h.shot(page, 'preview-fortnight');
            await h.save(page);
            await h.deleteCalendar(page, second);
        });

        await check('cleanup', async () => {
            await h.deleteCalendar(page, id);
            fs.rmSync(tmp, { recursive: true, force: true });
        });
    },
};
