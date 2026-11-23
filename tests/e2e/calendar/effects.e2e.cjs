/*
 * Layer effects of the pictures („Efekty“, K2 — docs/calendar/effects-plan.md, model/effects.js,
 * render/effects.js, ui/controls/effects.js).
 *
 * create → Fotka vlevo → step 3: the photo's Efekty (add, open, blend, colour, a slider as ONE undo
 * step, eye, order inside a kind, duplicate, remove) drawn on the stage; the logo's effects on an
 * uploaded PNG (outline effects); the header / background pictures' Efekty only while their picture
 * is on; save + reload; step 4: „Efekty fotky“ of the cover photo (designer). Then a rough timing of
 * the editor with and without effects (logged, loosely bounded).
 */
const assert = require('assert/strict');
const fs = require('fs');
const os = require('os');
const path = require('path');
const zlib = require('zlib');

/** A small RGBA PNG: a filled circle on transparency (a logo with an outline). */
function circlePng(size = 64) {
    const raw = Buffer.alloc((size * 4 + 1) * size);
    for (let y = 0; y < size; y++) {
        const row = y * (size * 4 + 1);
        for (let x = 0; x < size; x++) {
            const inside = (x - size / 2) ** 2 + (y - size / 2) ** 2 < (size * 0.4) ** 2;
            raw.set(inside ? [255, 255, 255, 255] : [0, 0, 0, 0], row + 1 + x * 4);
        }
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
    header.writeUInt32BE(size, 0);
    header.writeUInt32BE(size, 4);
    header.set([8, 6, 0, 0, 0], 8);
    return Buffer.concat([Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]), chunk('IHDR', header), chunk('IDAT', zlib.deflateSync(raw)), chunk('IEND', Buffer.alloc(0))]);
}

module.exports = {
    name: 'effects',
    checks: ['K2'],
    async run({ page, h, check }) {
        const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'wboost-cal-effects-'));
        const logoFile = path.join(tmp, 'logo.png');
        fs.writeFileSync(logoFile, circlePng());

        const styles = async () => (await h.state(page)).design.layout.styles;
        const prop = (p) => `[data-prop="${p}"]`;
        const stage = (selector) => page.$$eval(`.cal-cv svg[data-page] ${selector}`, (list) => list.length);
        const shown = (selector) =>
            page.evaluate((s) => {
                const el = document.querySelector(s);
                return Boolean(el) && el.getClientRects().length > 0;
            }, selector);

        async function select(el) {
            await page.click(`[data-layer="${el}"]`);
            await page.waitForFunction((key) => window.__calendar.state().selection && window.__calendar.state().selection.el === key, { timeout: 5000 }, el);
            await page.waitForSelector(`[data-props="${el}"]`, { timeout: 5000 });
        }
        async function click(selector) {
            await page.$eval(selector, (el) => el.scrollIntoView({ block: 'center' }));
            await page.click(selector);
            await h.sleep(40);
        }
        /** Drags a range input from its value to the far `side` (−1 left, +1 right) — one gesture. */
        async function drag(selector, side) {
            const slider = await page.$(selector);
            await slider.evaluate((el) => el.scrollIntoView({ block: 'center' }));
            const box = await slider.boundingBox();
            const y = box.y + box.height / 2;
            const from = box.x + box.width / 2;
            await page.mouse.move(from, y);
            await page.mouse.down();
            for (let i = 1; i <= 8; i++) {
                await page.mouse.move(from + (side * i * (box.width / 2 - 6)) / 8, y);
                await h.sleep(25);
            }
            await page.mouse.up();
            await h.sleep(60);
        }

        page.on('response', (r) => {
            if (r.status() === 404) console.log(`      404 ${r.url().slice(0, 160)}`);
        });
        let id = null;
        await check('setup: Fotka vlevo, step 3, the photo panel has Efekty', async () => {
            id = await h.createCalendar(page);
            await h.gotoStep(page, 2);
            await page.click('[data-preset="photoLeft"]');
            await page.waitForSelector('.cal-ws[data-cal-shown-step="3"]', { timeout: 10000 });
            await select('photo');
            assert.ok(await shown('[data-props="photo"] [data-fx-add="dropShadow"]'));
            assert.ok(await shown('[data-props="photo"] [data-fx-add="gradient"]'));
            assert.deepEqual((await styles()).photo.effects, []);
            assert.equal(await stage('[data-fx]'), 0);
        });
        if (!id) return;

        await check('a drop shadow and a gradient overlay are added open, drawn on the stage, overlays listed first', async () => {
            await click('[data-props="photo"] [data-fx-add="dropShadow"]');
            await click('[data-props="photo"] [data-fx-add="gradient"]');
            const fx = (await styles()).photo.effects;
            assert.deepEqual(fx.map((e) => e.kind), ['gradient', 'dropShadow'], 'a new effect goes on top of the list');
            assert.equal(fx[1].blend, 'multiply', 'Photoshop default of a shadow');
            assert.equal(await shown(prop('photo.effects.0.scale')), true, 'the new gradient is open');
            assert.equal(await shown(prop('photo.effects.1.size')), false, 'one row open at a time');
            assert.deepEqual(await page.$$eval('[data-props="photo"] [data-fx-row]', (rows) => rows.map((r) => r.dataset.fxKind)), ['gradient', 'dropShadow']);
            assert.equal(await stage('[data-el="photo"] [data-fx="gradient"]'), 1);
            assert.equal(await stage('[data-el="photo"] [data-fx="dropShadow"]'), 1);
            assert.equal(await stage('[data-el="photo"] image[filter], [data-el="photo"] [mask]'), 0, 'nothing filters the picture itself');
        });

        await check('blend mode, gradient type and the shadow colour write the style', async () => {
            const options = await page.$$eval(`${prop('photo.effects.0.blend')} option`, (list) => list.map((o) => o.textContent));
            assert.equal(options.length, 16);
            assert.deepEqual(options.slice(0, 3), ['Normální', 'Ztmavit', 'Násobit']);
            assert.ok(!options.includes('Rozpustit') && !options.includes('Lineárně zesvětlit (Přidat)'));
            await page.select(prop('photo.effects.0.blend'), 'multiply');
            assert.equal((await styles()).photo.effects[0].blend, 'multiply');
            assert.equal(await stage('[data-el="photo"] [style*="mix-blend-mode:multiply"]'), 2, 'overlay + shadow multiply');
            await page.select(prop('photo.effects.0.type'), 'radial');
            assert.equal((await styles()).photo.effects[0].type, 'radial');
            assert.equal(await stage('[data-el="photo"] radialGradient'), 1);
            await click('[data-fx-open="1"]');
            assert.equal(await shown(prop('photo.effects.1.size')), true);
            await click(`${prop('photo.effects.1.color')} [data-color="#6E6977"]`);
            assert.equal((await styles()).photo.effects[1].color, '#6E6977');
        });

        await check('a slider drag is ONE undo step and redraws the stage', async () => {
            const before = (await styles()).photo.effects[1].size;
            await drag(prop('photo.effects.1.size'), 1);
            const after = (await styles()).photo.effects[1].size;
            assert.ok(after > before, `size ${before} → ${after}`);
            const sigma = await page.$eval('.cal-cv svg[data-page] [data-el="photo"] feGaussianBlur', (el) => Number(el.getAttribute('stdDeviation')));
            assert.ok(Math.abs(sigma - after / 2) < 0.06, `σ ${sigma} for size ${after}`);
            await page.click('[data-cal-action="undo"]');
            assert.equal((await styles()).photo.effects[1].size, before, 'one undo restores the size');
            assert.equal((await styles()).photo.effects.length, 2, '… and nothing more');
        });

        await check('eye, order inside a kind, duplicate and remove', async () => {
            await page.click('[data-fx-row="1"] [data-prop="photo.effects.1.on"]');
            assert.equal((await styles()).photo.effects[1].on, false);
            assert.equal(await stage('[data-el="photo"] [data-fx="dropShadow"]'), 0, 'a hidden effect is not drawn');
            await page.click('[data-fx-row="1"] [data-prop="photo.effects.1.on"]');
            assert.equal(await page.$('[data-fx-row="1"] [data-fx-up]'), null, 'the only shadow cannot move');
            await page.click('[data-fx-row="0"] [data-fx-copy]');
            let fx = (await styles()).photo.effects;
            assert.deepEqual(fx.map((e) => e.kind), ['gradient', 'gradient', 'dropShadow']);
            await page.$eval(prop('photo.effects.0.scale'), (el) => el.scrollIntoView({ block: 'center' }));
            await page.click('[data-fx-row="1"] [data-fx-up]');
            fx = (await styles()).photo.effects;
            assert.equal(await stage('[data-el="photo"] [data-fx="gradient"]'), 2);
            await page.click('[data-fx-row="1"] [data-fx-remove]');
            fx = (await styles()).photo.effects;
            assert.deepEqual(fx.map((e) => e.kind), ['gradient', 'dropShadow']);
        });

        await check('the logo: an uploaded PNG casts its shadow from its outline', async () => {
            await select('logo');
            const input = await page.$('[data-props="logo"] input[type="file"]');
            await input.uploadFile(logoFile);
            await page.waitForFunction(() => Boolean(window.__calendar.state().design.layout.styles.logo.asset), { timeout: 20000 });
            await click('[data-props="logo"] [data-fx-add="dropShadow"]');
            await click('[data-props="logo"] [data-fx-add="gradient"]');
            await page.waitForFunction(() => document.querySelectorAll('.cal-cv svg[data-page] [data-el="logo"] [data-fx]').length === 2, { timeout: 5000 });
            assert.equal(await stage('[data-el="logo"] feImage'), 1, 'the overlay follows the outline');
            assert.equal(await stage('[data-el="logo"] filter feGaussianBlur'), 1);
        });

        await check('P3: inner shadow, outer and inner glow — fields of their kind, drawn in Photoshop order', async () => {
            await select('photo');
            for (const kind of ['innerShadow', 'outerGlow', 'innerGlow']) await click(`[data-props="photo"] [data-fx-add="${kind}"]`);
            const fx = (await styles()).photo.effects;
            assert.deepEqual(fx.slice(0, 3).map((e) => e.kind), ['innerGlow', 'outerGlow', 'innerShadow']);
            assert.equal(await shown(prop('photo.effects.0.spread')), true, 'the new inner glow is open');
            assert.equal(await page.$(prop('photo.effects.0.angle')), null, 'a glow has no angle');
            assert.match(await page.$eval(`label:has(${prop('photo.effects.0.spread')})`, (el) => el.textContent), /Zúžení/);
            const order = await page.$$eval('.cal-cv svg[data-page] [data-el="photo"] [data-fx]', (l) => l.map((n) => n.dataset.fx));
            assert.deepEqual(order, ['dropShadow', 'outerGlow', 'gradient', 'innerGlow', 'innerShadow']);
            for (let i = 0; i < 3; i++) await click('[data-props="photo"] [data-fx-row="0"] [data-fx-remove]');
            assert.equal((await styles()).photo.effects.length, 2);
        });

        await check('header and background pictures offer Efekty only while their picture is on', async () => {
            await select('head');
            assert.equal(await shown(prop('head.bg.effects.0.on')), false);
            assert.equal(await shown('[data-props="head"] [data-fx-add="gradient"]'), false);
            await page.evaluate(() => document.querySelector('[data-prop="head.bg.imageOn"]').closest('label').click());
            await h.sleep(60);
            assert.equal(await shown('[data-props="head"] [data-fx-add="gradient"]'), true);
            await click('[data-props="head"] [data-fx-add="gradient"]');
            assert.equal((await styles()).head.bg.effects.length, 1);
            await select('bg');
            assert.equal(await shown('[data-props="bg"] [data-fx-add="gradient"]'), false);
        });

        await check('everything survives save + reload', async () => {
            const before = await styles();
            await h.save(page);
            await h.reload(page);
            const after = await styles();
            for (const key of ['photo', 'logo']) assert.deepEqual(after[key].effects, before[key].effects, key);
            assert.deepEqual(after.head.bg.effects, before.head.bg.effects);
            assert.equal(after.photo.shadow, undefined);
        });

        await check('step 4: the cover photo field has „Efekty fotky“ (designer) — the style of every special photo', async () => {
            await h.gotoStep(page, 4);
            await page.waitForSelector('[data-page-thumb="0"]', { timeout: 10000 });
            await page.$eval('[data-page-thumb="0"]', (el) => el.click());
            await page.waitForSelector('.cal-pcfx [data-fx-add="dropShadow"]', { timeout: 10000 });
            await click('.cal-pcfx [data-fx-add="dropShadow"]');
            assert.equal((await styles()).specialPhoto.effects.length, 1);
            assert.equal((await h.state(page)).canUndo, true, 'a style change, so undoable');
            await click('.cal-pcfx [data-fx-remove]');
            assert.deepEqual((await styles()).specialPhoto.effects, []);
        });

        await check('P2: a week page takes its own photo effects in the step-4 page panel; „Zpět na styl“ and „Vrátit na styl“', async () => {
            await page.$eval('[data-page-thumb="1"]', (el) => el.click());
            await page.waitForSelector('[data-page-fx="photo"] [data-page-fx-own="photo"]', { timeout: 10000 });
            const key = await page.$eval('[data-page-panel]', (el) => el.dataset.pagePanel);
            assert.match(await page.$eval('[data-page-fx="photo"]', (el) => el.textContent), /Podle stylu · 2 efekty/);
            await click('[data-page-fx-own="photo"]');
            const content = async () => (await h.state(page)).content.pageEffects || {};
            assert.equal((await content())[key].photo.length, 2, 'the style list is copied');
            assert.equal(await shown('[data-page-pill]'), true, 'Upraveno');
            await click('[data-page-fx="photo"] [data-fx-add="gradient"]');
            assert.equal((await content())[key].photo.length, 3);
            assert.equal((await styles()).photo.effects.length, 2, 'the style is untouched');
            assert.equal(await page.$$eval('.cal-m4c svg[data-page] [data-el="photo"] [data-fx="gradient"]', (l) => l.length), 2, 'the page shows its own list');
            await click('[data-page-fx-reset="photo"]');
            assert.equal((await content())[key], undefined, 'back to the style: the item is gone');
            await click('[data-page-fx-own="photo"]');
            await click('[data-cal-action="page-reset"]');
            assert.equal((await content())[key], undefined, '„Vrátit na styl“ removes it too');
            await h.save(page);
        });

        await check('timing: the editor with effects stays responsive (logged)', async () => {
            await h.gotoStep(page, 3);
            await select('photo');
            await page.waitForSelector(prop('photo.effects.0.opacity'), { visible: true, timeout: 5000 }).catch(async () => {
                await click('[data-fx-open="0"]');
            });
            const measure = () =>
                page.evaluate(async (selector) => {
                    const input = document.querySelector(selector);
                    const frame = () => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve)));
                    const times = [];
                    for (let i = 0; i < 20; i++) {
                        const t0 = performance.now();
                        input.value = String(40 + (i % 2) * 20);
                        input.dispatchEvent(new Event('input', { bubbles: true }));
                        await frame();
                        times.push(performance.now() - t0);
                    }
                    input.dispatchEvent(new Event('change', { bubbles: true }));
                    times.sort((a, b) => a - b);
                    return { median: times[10], max: times[19] };
                }, prop('photo.effects.0.opacity'));
            const withFx = await measure();
            // the same drag with every effect of the photo and the logo hidden
            const all = await styles();
            for (const [k, e] of all.photo.effects.entries()) if (e.on) await page.click(`[data-prop="photo.effects.${k}.on"]`);
            const without = await measure();
            console.log(`      effects timing (stage redraw per slider step, 2 frames): with effects median ${withFx.median.toFixed(1)} ms / max ${withFx.max.toFixed(1)} ms; hidden median ${without.median.toFixed(1)} ms / max ${without.max.toFixed(1)} ms`);
            assert.ok(withFx.median < 120, `median ${withFx.median} ms`);
            await h.save(page);
            const t0 = Date.now();
            await h.gotoStep(page, 4);
            await page.waitForFunction(() => document.querySelectorAll('[data-page-thumb] svg').length >= 3, { timeout: 20000 });
            console.log(`      step 4 with ${await page.$$eval('[data-page-thumb]', (l) => l.length)} thumbnails: ${Date.now() - t0} ms`);
        });

        await check('cleanup', async () => {
            await h.deleteCalendar(page, id);
        });
    },
};
