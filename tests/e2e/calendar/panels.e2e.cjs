/*
 * Shared controls and the element panels of the editor's right column (phase P5a; handoff E9, E18,
 * G12, L9 through the fields, §6.1 limits table and shared-control rules).
 *
 * create → Fotka vlevo → step 3 → every element panel: Pozice a velikost (typed values, decimal
 * comma, − / +, corrected limits, linked Š), font size → auto-grow, limits, HEX rules, uploads
 * (logo, QR, background picture), Tvar, the "week display is off" notice, the background panel with
 * the gradient editor, a slider as ONE undo step. Data-changing scenarios save, reload and re-assert.
 *
 * Elements are selected through the Vrstvy rows (`[data-layer]`), never through the stage.
 */
const assert = require('assert/strict');
const fs = require('fs');
const os = require('os');
const path = require('path');
const zlib = require('zlib');

const near = (a, b, tol = 0.051) => Math.abs(a - b) <= tol;

const LOGO_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 40 40" width="40" height="40"><circle cx="20" cy="20" r="18" fill="#C84B55"/><path d="M11 21l6 6 12-14" fill="none" stroke="#fff" stroke-width="4"/></svg>';

/** A small black / white checker PNG (stands in for a QR code and for a background picture). */
function checkerPng(size = 64, cell = 8) {
    const raw = Buffer.alloc((size * 3 + 1) * size);
    for (let y = 0; y < size; y++) {
        const row = y * (size * 3 + 1);
        for (let x = 0; x < size; x++) {
            const v = (Math.floor(x / cell) + Math.floor(y / cell)) % 2 ? 255 : 0;
            raw.fill(v, row + 1 + x * 3, row + 4 + x * 3);
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
    header.set([8, 2, 0, 0, 0], 8);
    return Buffer.concat([Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]), chunk('IHDR', header), chunk('IDAT', zlib.deflateSync(raw)), chunk('IEND', Buffer.alloc(0))]);
}

module.exports = {
    name: 'panels',
    checks: ['E9', 'E18', 'G12', 'L9 (typed)'],
    async run({ page, h, check }) {
        const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'wboost-cal-panels-'));
        const logoFile = path.join(tmp, 'logo.svg');
        const qrFile = path.join(tmp, 'qr.png');
        fs.writeFileSync(logoFile, LOGO_SVG);
        fs.writeFileSync(qrFile, checkerPng());

        const styles = async () => (await h.state(page)).design.layout.styles;
        const prop = (p) => `[data-prop="${p}"]`;
        const field = (f) => `[data-field="${f}"]`;
        const value = (selector) => page.$eval(selector, (el) => el.value);
        const shown = (selector) =>
            page.evaluate((s) => {
                const el = document.querySelector(s);
                return Boolean(el) && el.getClientRects().length > 0;
            }, selector);
        const title = () => page.$eval('[data-props-title]', (el) => el.textContent.trim());

        /** Selects an element through its Vrstvy row and waits for its panel. */
        async function select(el) {
            const now = (await h.state(page)).selection;
            if (!now || now.el !== el || now.part) {
                if (now && now.el === el) await page.click(`[data-layer="${el}"]`);
                await page.click(`[data-layer="${el}"]`);
            }
            await page.waitForFunction((key) => window.__calendar.state().selection && window.__calendar.state().selection.el === key && !window.__calendar.state().selection.part, { timeout: 5000 }, el);
            if (el !== 'cal') await page.waitForSelector(`[data-props="${el}"]`, { timeout: 5000 });
        }

        /** Types into a text field like a user: focus, select all, type, Enter. */
        async function type(selector, text) {
            await page.$eval(selector, (el) => {
                el.scrollIntoView({ block: 'center' });
                el.focus();
                el.select();
            });
            await page.keyboard.press('Backspace');
            if (text !== '') await page.keyboard.type(text);
            await page.keyboard.press('Enter');
            await h.sleep(40);
        }

        async function clickSeg(path_, v) {
            await page.$eval(`${prop(path_)} [data-value="${v}"]`, (el) => el.scrollIntoView({ block: 'center' }));
            await page.click(`${prop(path_)} [data-value="${v}"]`);
            await h.sleep(40);
        }

        /** Clicks the label of a hidden native checkbox / switch. */
        async function toggle(path_) {
            await page.$eval(prop(path_), (el) => el.closest('label').scrollIntoView({ block: 'center' }));
            await page.evaluate((s) => document.querySelector(s).closest('label').click(), prop(path_));
            await h.sleep(40);
        }

        async function upload(selector, file) {
            const input = await page.$(selector);
            assert.ok(input, `${selector} is missing`);
            await input.uploadFile(file);
        }

        /** Trim-relative mm rectangle of an element drawn on the stage page, measured in the DOM. */
        const measured = (el) =>
            page.evaluate((key) => {
                const svg = document.querySelector('.cal-cv svg[data-page]');
                const node = svg.querySelector(`[data-el="${key}"]`);
                const vb = svg.viewBox.baseVal;
                const s = svg.getBoundingClientRect();
                const r = node.getBoundingClientRect();
                const k = vb.width / s.width;
                return { x: vb.x + (r.left - s.left) * k, y: vb.y + (r.top - s.top) * k, w: r.width * k, h: r.height * k, pageWidthMm: vb.width, pageWidthPx: s.width };
            }, el);

        let id = null;
        await check('setup: Fotka vlevo, step 3, empty state', async () => {
            id = await h.createCalendar(page);
            await h.gotoStep(page, 2);
            await page.click('[data-preset="photoLeft"]');
            await page.waitForSelector('.cal-ws[data-cal-shown-step="3"]', { timeout: 10000 });
            await page.waitForSelector('.cal-cv svg[data-page]');
            assert.equal((await h.state(page)).selection, null);
            assert.equal(await page.$eval('.cal-noprop', (el) => el.textContent.trim()), 'Vyberte prvek na stránce nebo v seznamu vlevo');
        });
        if (!id) return;

        const page_ = await h.cal(page, (c) => c.page());

        await check('E9 typed X = 20 and Š = 80 for the logo, measured on the page', async () => {
            await select('logo');
            assert.equal(await title(), 'Logo');
            assert.match(await page.$eval('[data-props="logo"] .cal-pph', (el) => el.textContent), /Platí pro všechny strany kalendáře/);
            const before = await h.boxes(page);
            for (const f of ['x', 'y', 'w', 'h']) assert.equal(await value(field(f)), String(before.logo[f]).replace('.', ','), `field ${f} shows the box`);
            await type(field('x'), '20');
            await type(field('w'), '80');
            const after = await h.boxes(page);
            h.expectBox(after.logo, { x: 20, y: before.logo.y, w: 80, h: before.logo.h }, 0.001, 'logo');
            for (const key of Object.keys(before)) if (key !== 'logo') assert.deepEqual(after[key], before[key], `${key} did not move`);
            // the rendered page: 20 mm from the left trim edge, 80 mm wide, against the real page width
            const m = await measured('logo');
            assert.ok(near(m.pageWidthMm, page_.width + 2 * page_.bleedMm, 0.01), 'the page SVG spans trim + bleed');
            // (the LOGO placeholder is drawn 0.2 mm inside its frame)
            assert.ok(near(m.x, 20.2, 0.15), `left edge drawn at ${m.x.toFixed(2)} mm`);
            assert.ok(near(m.x + m.w, 99.8, 0.15), `right edge drawn at ${(m.x + m.w).toFixed(2)} mm`);
            assert.ok(near((m.w / m.pageWidthMm) * (page_.width + 2 * page_.bleedMm), 79.6, 0.15), 'width as a share of the page width');
            await h.save(page);
            await h.reload(page);
            h.expectBox((await h.boxes(page)).logo, { x: 20, w: 80 }, 0.001, 'logo after reload');
        });

        await check('Pozice a velikost: decimal comma, − / +, arrow keys, values outside the limits are corrected and shown', async () => {
            await select('logo');
            await type(field('y'), '40,5');
            assert.equal((await h.boxes(page)).logo.y, 40.5);
            await page.click('.cal-ml:has([data-field="y"]) button[aria-label="Více"]');
            assert.equal((await h.boxes(page)).logo.y, 41);
            assert.equal(await value(field('y')), '41');
            await page.click('.cal-ml:has([data-field="y"]) button[aria-label="Méně"]');
            await page.click('.cal-ml:has([data-field="y"]) button[aria-label="Méně"]');
            assert.equal((await h.boxes(page)).logo.y, 40);
            // typed text that is no number changes nothing
            await type(field('y'), 'abc');
            assert.equal((await h.boxes(page)).logo.y, 40);
            assert.equal(await value(field('y')), '40');
            // far outside → the outer bleed edge, size kept; the field shows the corrected number
            await type(field('x'), '9999');
            let b = (await h.boxes(page)).logo;
            assert.ok(near(b.x + b.w, page_.width + page_.bleedMm, 0.001), `right edge at the bleed edge, got ${b.x + b.w}`);
            assert.equal(b.w, 80);
            assert.equal(await value(field('x')), String(b.x).replace('.', ','));
            await type(field('x'), '-9999');
            b = (await h.boxes(page)).logo;
            assert.equal(b.x, -page_.bleedMm);
            assert.equal(await value(field('x')), String(-page_.bleedMm).replace('.', ','));
            // below the minimum size → the minimum, the left edge fixed
            await type(field('x'), '20');
            await type(field('w'), '1');
            b = (await h.boxes(page)).logo;
            assert.equal(b.w, 6);
            assert.equal(b.x, 20);
            assert.equal(await value(field('w')), '6');
            await type(field('h'), '0');
            assert.equal((await h.boxes(page)).logo.h, 4);
            assert.equal(await value(field('h')), '4');
            // wider than the page → the right edge stops at the bleed edge
            await type(field('w'), '5000');
            b = (await h.boxes(page)).logo;
            assert.equal(b.x, 20);
            assert.ok(near(b.x + b.w, page_.width + page_.bleedMm, 0.001));
            assert.equal(await value(field('w')), String(b.w).replace('.', ','));
            await type(field('w'), '24');
            await type(field('h'), '24');
            // Esc restores the shown value and changes nothing
            await page.$eval(field('x'), (el) => {
                el.focus();
                el.select();
            });
            await page.keyboard.type('77');
            await page.keyboard.press('Escape');
            assert.equal((await h.boxes(page)).logo.x, 20);
            assert.equal(await value(field('x')), '20');
            assert.equal((await h.state(page)).selection.el, 'logo', 'Esc in a field does not deselect');
        });

        await check('L9 typed X / Y move nothing else; a typed photo Š takes the kalendárium along; with linking off only the photo changes', async () => {
            assert.equal((await h.state(page)).design.linkElements, true);
            await select('logo');
            let before = await h.boxes(page);
            await type(field('x'), '150');
            await type(field('y'), '50');
            let after = await h.boxes(page);
            h.expectBox(after.logo, { x: 150, y: 50, w: before.logo.w, h: before.logo.h }, 0.001, 'logo');
            for (const key of Object.keys(before)) if (key !== 'logo') assert.deepEqual(after[key], before[key], `${key} unchanged by a move`);

            await select('photo');
            before = await h.boxes(page);
            assert.ok(near(before.photo.x + before.photo.w, before.cal.x, 0.001), 'photo right edge = kalendárium left edge');
            const calRight = before.cal.x + before.cal.w;
            await type(field('w'), String(before.photo.w - 40).replace('.', ','));
            after = await h.boxes(page);
            h.expectBox(after.photo, { x: before.photo.x, y: before.photo.y, w: before.photo.w - 40, h: before.photo.h }, 0.001, 'photo');
            h.expectBox(after.cal, { x: before.cal.x - 40, y: before.cal.y, w: before.cal.w + 40, h: before.cal.h }, 0.001, 'cal follows');
            assert.ok(near(after.cal.x + after.cal.w, calRight, 0.001), 'the far edge of the kalendárium stayed');
            for (const key of Object.keys(before)) if (!['photo', 'cal'].includes(key)) assert.deepEqual(after[key], before[key], `${key} unchanged by the linked resize`);
            // one undo step brings both back
            await page.click('[data-cal-action="undo"]');
            assert.deepEqual(await h.boxes(page), before, 'one undo restores photo and kalendárium');
            assert.equal(await value(field('w')), String(before.photo.w).replace('.', ','), 'the field follows the undo');

            const link = await page.$('[data-cal-action="link"]');
            assert.ok(link, 'the stage has the Propojit prvky button');
            await link.click();
            assert.equal((await h.state(page)).design.linkElements, false);
            await select('photo');
            await type(field('w'), String(before.photo.w - 40).replace('.', ','));
            after = await h.boxes(page);
            assert.equal(after.photo.w, before.photo.w - 40);
            for (const key of Object.keys(before)) if (key !== 'photo') assert.deepEqual(after[key], before[key], `${key} unchanged with linking off`);
            await page.click('[data-cal-action="undo"]');
            await page.click('[data-cal-action="link"]');
            assert.equal((await h.state(page)).design.linkElements, true);
            assert.deepEqual(await h.boxes(page), before);
        });

        await check('E18 month font size → the frame grows to fit, nothing is clipped; persists', async () => {
            await select('month');
            const before = (await h.boxes(page)).month;
            assert.equal(await value(prop('month.size')), String((await styles()).month.size).replace('.', ','));
            await type(prop('month.size'), '40');
            assert.equal((await styles()).month.size, 40);
            const after = (await h.boxes(page)).month;
            assert.ok(after.w > before.w + 20, `frame grew: ${before.w} → ${after.w}`);
            // default alignment of Fotka vlevo is right: the right edge stays, the frame grows to the left
            assert.ok(near(after.x + after.w, before.x + before.w, 0.11), 'the anchored edge stayed');
            const widths = await h.cal(page, (c) => c.pages().filter((p) => p.kind === 'week').map((p, i) => c.geometry(p.index).textWidths.month));
            assert.ok(Math.max(...widths) <= after.w + 0.11, `widest month text ${Math.max(...widths).toFixed(1)} mm fits ${after.w} mm`);
            assert.equal(await value(field('w')), String(after.w).replace('.', ','), 'Š shows the grown frame');
            // the text really is inside the frame on the page
            const fits = await page.evaluate(() => {
                const g = document.querySelector('.cal-cv svg[data-page] [data-el="month"]');
                const t = g.querySelector('text').getBoundingClientRect();
                const svg = document.querySelector('.cal-cv svg[data-page]');
                const k = svg.viewBox.baseVal.width / svg.getBoundingClientRect().width;
                return { left: svg.viewBox.baseVal.x + (t.left - svg.getBoundingClientRect().left) * k, right: svg.viewBox.baseVal.x + (t.right - svg.getBoundingClientRect().left) * k };
            });
            assert.ok(fits.left >= after.x - 0.2 && fits.right <= after.x + after.w + 0.2, `text ${fits.left.toFixed(1)}–${fits.right.toFixed(1)} inside ${after.x}–${after.x + after.w}`);
            // the frame never shrinks by itself
            await type(prop('month.size'), '15');
            assert.equal((await h.boxes(page)).month.w, after.w);
            await type(prop('month.size'), '40');
            await h.save(page);
            await h.reload(page);
            assert.equal((await styles()).month.size, 40);
            h.expectBox((await h.boxes(page)).month, after, 0.001, 'month after reload');
            // back to the default for the rest of the script (two undo-able changes)
            await select('month');
            await type(prop('month.size'), '15');
        });

        await check('limits table: values outside the range are corrected to the nearest limit and shown', async () => {
            await select('month');
            await type(prop('month.size'), '999');
            assert.equal((await styles()).month.size, 60);
            assert.equal(await value(prop('month.size')), '60');
            await type(prop('month.size'), '1');
            assert.equal((await styles()).month.size, 3);
            assert.equal(await value(prop('month.size')), '3');
            await type(prop('month.size'), '15,2');
            assert.equal((await styles()).month.size, 15, 'typed sizes land on the 0.5 pt grid');
            assert.equal(await value(prop('month.size')), '15');

            await select('photo');
            await type(prop('photo.radius'), '99');
            assert.equal((await styles()).photo.radius, 30);
            assert.equal(await value(prop('photo.radius')), '30');
            await type(prop('photo.radius'), '-4');
            assert.equal((await styles()).photo.radius, 0);
            assert.equal(await shown(prop('photo.border.width')), false, 'border settings hidden while the border is off');
            await toggle('photo.border.on');
            assert.equal((await styles()).photo.border.on, true);
            assert.equal(await shown(prop('photo.border.width')), true);
            await type(prop('photo.border.width'), '50');
            assert.equal((await styles()).photo.border.width, 10);
            await type(prop('photo.border.width'), '0');
            assert.equal((await styles()).photo.border.width, 0.1);
            assert.equal(await value(prop('photo.border.width')), '0,1');
            assert.equal(await shown(prop('photo.shadow.blur')), false);
            await toggle('photo.shadow.on');
            assert.equal(await shown(prop('photo.shadow.blur')), true);
            await type(prop('photo.shadow.dx'), '-99');
            assert.equal((await styles()).photo.shadow.dx, -10);
            await type(prop('photo.shadow.dy'), '99');
            assert.equal((await styles()).photo.shadow.dy, 10);
            await type(prop('photo.shadow.opacity'), '250');
            assert.equal((await styles()).photo.shadow.opacity, 100);
            assert.equal(await value(prop('photo.shadow.opacity')), '100');
            await type(prop('photo.shadow.blur'), '31');
            assert.equal((await styles()).photo.shadow.blur, 30);
            await clickSeg('photo.source', 'self');
            assert.equal((await styles()).photo.source, 'self');
            await clickSeg('photo.source', 'client');

            await select('cap');
            assert.equal(await shown(prop('cap.pad')), true, 'padding is offered while the caption background is on');
            await type(prop('cap.pad'), '99');
            assert.equal((await styles()).cap.pad, 20);
            assert.equal(await value(prop('cap.pad')), '20');
            await type(prop('cap.pad'), '3');
            await clickSeg('cap.align', 'middle');
            await clickSeg('cap.valign', 'bottom');
            const s = await styles();
            assert.equal(s.cap.align, 'middle');
            assert.equal(s.cap.valign, 'bottom');
            // switching the caption background off hides the padding
            await toggle('cap.bg.colorOn');
            assert.equal((await styles()).cap.bg.colorOn, false);
            assert.equal(await shown(prop('cap.pad')), false);
            await toggle('cap.bg.colorOn');
            assert.equal(await shown(prop('cap.pad')), true);
        });

        await check('HEX field: 6 and 3 digits with or without #, extra swatch for an off-palette colour, invalid value rejected with a toast', async () => {
            await select('month');
            const hex = `${prop('month.color')} [data-hex]`;
            const original = (await styles()).month.color;
            await type(hex, 'c84b55');
            assert.equal((await styles()).month.color, '#C84B55');
            assert.equal(await value(hex), 'C84B55');
            assert.equal(await page.$eval(`${prop('month.color')} [data-custom]`, (el) => `${el.dataset.color}|${el.getAttribute('aria-checked')}|${el.getAttribute('aria-label')}`), '#C84B55|true|Vlastní #C84B55');
            await type(hex, '#fa0');
            assert.equal((await styles()).month.color, '#FFAA00');
            assert.equal(await page.$eval(`${prop('month.color')} [data-custom]`, (el) => el.dataset.color), '#FFAA00');
            // the page shows it
            assert.equal(await page.$eval('.cal-cv svg[data-page] [data-el="month"] text', (el) => el.getAttribute('fill')), '#FFAA00');
            for (const bad of ['zzzzzz', '12', '#12345', '']) {
                await page.evaluate(() => document.querySelectorAll('.toast-n').forEach((n) => n.remove()));
                await type(hex, bad);
                await h.waitToast(page, 'Zadejte barvu jako HEX, např\\. C84B55');
                assert.equal((await styles()).month.color, '#FFAA00', `"${bad}" keeps the old colour`);
                assert.equal(await value(hex), 'FFAA00', `"${bad}": the field shows the old colour again`);
            }
            // a palette swatch: selected, the extra swatch goes away
            await page.click(`${prop('month.color')} [data-color="#2F78B7"]`);
            assert.equal((await styles()).month.color, '#2F78B7');
            assert.equal(await page.$(`${prop('month.color')} [data-custom]`), null);
            assert.equal(await page.$eval(`${prop('month.color')} [data-color="#2F78B7"]`, (el) => el.getAttribute('aria-checked')), 'true');
            assert.equal(await value(hex), '2F78B7');
            assert.equal(await page.$(`${prop('month.color')} [data-color=""]`), null, 'no „Bez barvy“ where a text needs a colour');
            await page.click(`${prop('month.color')} [data-color="${original}"]`);
            // other month controls
            await toggle('month.upper');
            assert.equal((await styles()).month.upper, false);
            assert.match(await page.$eval('.cal-cv svg[data-page] [data-el="month"] text', (el) => el.textContent), /[a-zěščřžýáíéůú]{3}/, 'the sample month is no longer upper case');
            await toggle('month.upper');
            await clickSeg('month.align', 'start');
            assert.equal((await styles()).month.align, 'start');
            await clickSeg('month.align', 'end');
        });

        await check('fonts: only project fonts are offered; the cut list belongs to the chosen font', async () => {
            await select('month');
            const fonts = await page.evaluate(() => JSON.parse(document.querySelector('[data-calendar-workspace-target="bootstrap"]').textContent).env.fonts);
            const names = [...new Set(fonts.map((f) => f.fontName))];
            const offered = await page.$$eval(`${prop('month.family')} option`, (nodes) => nodes.map((n) => n.textContent));
            const s = await styles();
            if (!names.length) {
                assert.deepEqual(offered, ['Výchozí písmo'], 'a project without fonts offers the built-in font only');
                assert.deepEqual(await page.$$eval(`${prop('month.weight')} option`, (nodes) => nodes.map((n) => n.textContent)), ['Regular', 'Medium', 'Semi Bold', 'Bold']);
                await page.select(prop('month.weight'), 'w:500');
                assert.equal((await styles()).month.weight, 500);
                assert.equal((await styles()).month.family, null);
                await page.select(prop('month.weight'), 'w:700');
                assert.equal((await styles()).month.weight, 700);
                return;
            }
            assert.deepEqual(offered, s.month.family ? names : ['Výchozí písmo', ...names]);
            const target = names[names.length - 1];
            const wanted = s.month.weight;
            await page.select(prop('month.family'), target);
            const next = (await styles()).month;
            const face = fonts.find((f) => f.family === next.family);
            assert.equal(face.fontName, target);
            assert.equal(next.weight, wanted, 'the wanted weight is kept');
            const best = Math.min(...fonts.filter((f) => f.fontName === target).map((f) => Math.abs(f.weight - wanted)));
            assert.equal(Math.abs(face.weight - wanted), best, 'the nearest cut of the new font');
            assert.equal((await page.$$eval(`${prop('month.weight')} option`, (nodes) => nodes.length)), fonts.filter((f) => f.fontName === target).length);
        });

        await check('logo upload (SVG) shows on the page and persists; Přizpůsobit / Vyplnit; Odebrat', async () => {
            await select('logo');
            assert.equal(await shown(prop('logo.fit')), false, 'fit is offered only with a file');
            assert.match(await page.$eval('[data-props="logo"] .cal-himgb .cap', (el) => el.textContent), /Nahrajte logo \(SVG, PNG\)/);
            await upload(prop('logo.asset'), logoFile);
            await page.waitForFunction(() => window.__calendar.state().design.layout.styles.logo.asset, { timeout: 15000 });
            const asset = (await styles()).logo.asset;
            assert.match(asset, /^[0-9a-f-]{36}$/);
            await page.waitForSelector('.cal-cv svg[data-page] [data-el="logo"] image');
            assert.equal(await page.$eval('[data-props="logo"] .cal-himgb .cap', (el) => el.textContent), 'Nahrané logo');
            assert.equal(await shown('[data-props="logo"] .cal-himgp img'), true, 'the tile previews the file');
            assert.equal(await shown(prop('logo.fit')), true);
            assert.equal(await page.$eval('.cal-cv svg[data-page] [data-el="logo"] image', (el) => el.getAttribute('preserveAspectRatio')), 'xMidYMid meet');
            await clickSeg('logo.fit', 'cover');
            assert.equal(await page.$eval('.cal-cv svg[data-page] [data-el="logo"] image', (el) => el.getAttribute('preserveAspectRatio')), 'xMidYMid slice');
            await h.save(page);
            await h.reload(page);
            const s = await styles();
            assert.equal(s.logo.asset, asset);
            assert.equal(s.logo.fit, 'cover');
            await page.waitForSelector('.cal-cv svg[data-page] [data-el="logo"] image');
            const loaded = await page.evaluate(async () => {
                const href = document.querySelector('.cal-cv svg[data-page] [data-el="logo"] image').getAttribute('href');
                const response = await fetch(href);
                return response.ok;
            });
            assert.equal(loaded, true, 'the stored file is served');
            await select('logo');
            assert.equal(await shown('[data-props="logo"] .cal-himgp img'), true);
            await page.click('[data-props="logo"] [data-upload-remove]');
            assert.equal((await styles()).logo.asset, null);
            assert.equal(await page.$('.cal-cv svg[data-page] [data-el="logo"] image'), null);
            await page.click('[data-cal-action="undo"]');
            assert.equal((await styles()).logo.asset, asset, 'removing a logo is undoable');
            assert.equal(await shown('[data-props="logo"] .cal-himgp img'), true, 'the tile follows the undo');
        });

        await check('G12 Tvar changes every week page: 1. týden / Týden 1 / T1; persists', async () => {
            await select('wk');
            assert.equal(await title(), 'Číslo týdne');
            const texts = () => h.cal(page, (c) => c.pages().filter((p) => p.kind === 'week').map((p) => p.week.weekText));
            let all = await texts();
            assert.equal(all.length, 53);
            assert.ok(all.every((t) => /^\d+\. týden$/.test(t)), `form a: ${all[0]}`);
            await clickSeg('wk.form', 'b');
            all = await texts();
            assert.ok(all.every((t) => /^Týden \d+$/.test(t)), `form b: ${all[0]}`);
            assert.match(await page.$eval('.cal-cv svg[data-page] [data-el="wk"] text', (el) => el.textContent), /^Týden \d+$/);
            await clickSeg('wk.form', 'c');
            all = await texts();
            assert.ok(all.every((t) => /^T\d+$/.test(t)), `form c: ${all[0]}`);
            assert.match(await page.$eval('.cal-cv svg[data-page] [data-el="wk"] text', (el) => el.textContent), /^T\d+$/);
            await h.save(page);
            await h.reload(page);
            assert.equal((await styles()).wk.form, 'c');
            assert.ok((await texts()).every((t) => /^T\d+$/.test(t)));
            await select('wk');
            assert.equal(await page.$eval(`${prop('wk.form')} [data-value="c"]`, (el) => el.getAttribute('aria-pressed')), 'true');
            await clickSeg('wk.form', 'a');
            await clickSeg('wk.align', 'start');
            assert.equal((await styles()).wk.align, 'start');
            await clickSeg('wk.align', 'end');
        });

        await check('Číslo týdne: with week display off in step 1 the panel says so', async () => {
            await h.gotoStep(page, 1);
            await page.evaluate(() => document.querySelector('[data-feature="weekNumbers"]').closest('label').click());
            assert.equal((await h.state(page)).design.features.weekNumbers, false);
            await h.gotoStep(page, 3);
            if (!(await page.$('[data-layer="wk"]'))) throw new Error('the Vrstvy list has no Číslo týdne row while the feature is off');
            await page.click('[data-layer="wk"]');
            await page.waitForSelector('[data-props="wk"]');
            assert.match(await page.$eval('[data-props="wk"]', (el) => el.textContent), /Číslo týdne je vypnuté v kroku 1\./);
            assert.equal(await page.$(prop('wk.form')), null);
            assert.equal(await page.$(field('x')), null);
            await h.gotoStep(page, 1);
            await page.evaluate(() => document.querySelector('[data-feature="weekNumbers"]').closest('label').click());
            await page.evaluate(() => document.querySelector('[data-feature="qr"]').closest('label').click());
            const f = (await h.state(page)).design.features;
            assert.equal(f.weekNumbers, true);
            assert.equal(f.qr, true);
            await h.gotoStep(page, 3);
        });

        await check('QR kód: one code for all pages (upload) or „Na každé straně jiný QR kód“; persists', async () => {
            await select('qr');
            assert.equal(await title(), 'QR kód');
            assert.equal((await styles()).qr.perPage, false);
            assert.equal(await shown(prop('qr.asset')) || (await shown('[data-props="qr"] .cal-himg')), true);
            await upload(prop('qr.asset'), qrFile);
            await page.waitForFunction(() => window.__calendar.state().design.layout.styles.qr.asset, { timeout: 15000 });
            await page.waitForSelector('.cal-cv svg[data-page] [data-el="qr"] image');
            assert.equal(await page.$eval('[data-props="qr"] .cal-himgb .cap', (el) => el.textContent), 'Stejný na všech týdenních stranách');
            await toggle('qr.perPage');
            assert.equal((await styles()).qr.perPage, true);
            assert.equal(await shown('[data-props="qr"] .cal-himg'), false, 'the single-code tile is hidden');
            assert.match(await page.$eval('[data-props="qr"]', (el) => el.innerText), /QR kódy nahrajete v kroku Obsah › QR kódy, nebo u každé strany po vygenerování\./);
            await h.save(page);
            await h.reload(page);
            assert.equal((await styles()).qr.perPage, true);
            await select('qr');
            await toggle('qr.perPage');
            assert.equal((await styles()).qr.perPage, false);
            assert.equal(await shown('[data-props="qr"] .cal-himg'), true);
            assert.equal(await shown('[data-props="qr"] .cal-himgp img'), true, 'the uploaded code is still there');
        });

        await check('Pozadí: independent Barva / Přechod / Obrázek layers and the gradient editor; persists', async () => {
            await select('bg');
            assert.equal(await title(), 'Pozadí');
            assert.match(await page.$eval('[data-props="bg"] .cal-pph', (el) => el.textContent), /Celá stránka až do krajů spadávky/);
            assert.equal(await page.$(field('x')), null, 'Pozadí has no position');
            let bg = (await styles()).bg;
            assert.equal(bg.colorOn, true);
            assert.equal(await shown(prop('bg.color')), true);
            assert.equal(await shown('[data-props="bg"] .cal-gr'), false);
            assert.equal(await shown('[data-props="bg"] .cal-himg'), false);

            await toggle('bg.gradient.on');
            bg = (await styles()).bg;
            assert.equal(bg.gradient.on, true);
            assert.equal(bg.colorOn, true, 'the layers are independent');
            assert.equal(await shown('[data-props="bg"] .cal-gr'), true);
            assert.equal(bg.gradient.stops.length, 2);
            assert.equal(await page.$('[data-grad-remove]'), null, 'two stops cannot be removed');
            await page.waitForSelector('.cal-cv svg[data-page] [data-el="bg"] linearGradient');

            // add: in the middle of the widest gap
            await page.click('[data-props="bg"] [data-grad-add]');
            bg = (await styles()).bg;
            assert.deepEqual(bg.gradient.stops.map((s) => s.pos), [0, 50, 100]);
            await page.click('[data-props="bg"] [data-grad-add]');
            assert.deepEqual((await styles()).bg.gradient.stops.map((s) => s.pos), [0, 25, 50, 100]);
            assert.equal((await page.$$('[data-props="bg"] [data-grad-remove]')).length, 4);
            await page.click('[data-props="bg"] [data-grad-remove="1"]');
            assert.deepEqual((await styles()).bg.gradient.stops.map((s) => s.pos), [0, 50, 100]);
            // stop fields: position and opacity (corrected to 0–100)
            await type(prop('bg.gradient.stops.1.pos'), '30');
            await type(prop('bg.gradient.stops.1.opacity'), '250');
            await type(prop('bg.gradient.stops.0.opacity'), '40');
            bg = (await styles()).bg;
            assert.equal(bg.gradient.stops[1].pos, 30);
            assert.equal(bg.gradient.stops[1].opacity, 100);
            assert.equal(bg.gradient.stops[0].opacity, 40);
            assert.equal(await value(prop('bg.gradient.stops.1.opacity')), '100');
            // stop colour through the shared picker
            await page.click(prop('bg.gradient.stops.2.color'));
            assert.equal(await shown('[data-props="bg"] [data-grad-picker="2"]'), true);
            await type('[data-props="bg"] [data-grad-picker="2"] [data-hex]', '123456');
            assert.equal((await styles()).bg.gradient.stops[2].color, '#123456');
            await page.click(prop('bg.gradient.stops.2.color'));
            assert.equal(await shown('[data-props="bg"] [data-grad-picker="2"]'), false);
            // swap reverses the stops
            const beforeSwap = (await styles()).bg.gradient.stops;
            await page.click('[data-props="bg"] [data-grad-swap]');
            const swapped = (await styles()).bg.gradient.stops;
            assert.deepEqual(swapped, beforeSwap.map((s) => ({ ...s, pos: 100 - s.pos })).reverse());
            assert.deepEqual(swapped.map((s) => s.pos), [0, 70, 100]);
            // remove down to two: the remove buttons disappear
            await page.click('[data-props="bg"] [data-grad-remove="1"]');
            assert.equal((await styles()).bg.gradient.stops.length, 2);
            assert.equal(await page.$('[data-props="bg"] [data-grad-remove]'), null);
            // type: radial hides the angle
            assert.equal(await shown(prop('bg.gradient.angle')), true);
            await page.select(prop('bg.gradient.type'), 'radial');
            assert.equal((await styles()).bg.gradient.type, 'radial');
            assert.equal(await shown(prop('bg.gradient.angle')), false);
            await page.waitForSelector('.cal-cv svg[data-page] [data-el="bg"] radialGradient');
            await page.select(prop('bg.gradient.type'), 'linear');
            assert.equal(await shown(prop('bg.gradient.angle')), true);

            // picture layer
            await toggle('bg.imageOn');
            assert.equal((await styles()).bg.imageOn, true);
            assert.equal(await shown('[data-props="bg"] .cal-himg'), true);
            assert.equal(await page.$eval('[data-props="bg"] .cal-himgb .cap', (el) => el.textContent), 'Nahrajte obrázek');
            await upload(prop('bg.asset'), qrFile);
            await page.waitForFunction(() => window.__calendar.state().design.layout.styles.bg.asset, { timeout: 15000 });
            await page.waitForSelector('.cal-cv svg[data-page] [data-el="bg"] image');
            assert.equal(await page.$eval('[data-props="bg"] .cal-himgb .cap', (el) => el.textContent), 'Výchozí fotka — u jednotlivých stran ji lze vyměnit');
            await clickSeg('bg.fit', 'contain');
            assert.equal((await styles()).bg.fit, 'contain');
            // colour layer off — the other two stay
            await toggle('bg.colorOn');
            bg = (await styles()).bg;
            assert.equal(bg.colorOn, false);
            assert.equal(bg.gradient.on, true);
            assert.equal(bg.imageOn, true);
            assert.equal(await shown(prop('bg.color')), false);

            await h.save(page);
            await h.reload(page);
            const saved = (await styles()).bg;
            assert.deepEqual(saved, bg, 'the whole background survives the reload');
            await select('bg');
            assert.equal(await shown('[data-props="bg"] .cal-gr'), true);
            assert.equal(await shown('[data-props="bg"] .cal-himgp img'), true);
            await toggle('bg.colorOn');
            assert.equal((await styles()).bg.colorOn, true);
            assert.equal(await shown(prop('bg.color')), true);
        });

        await check('a slider redraws the page while dragged and is ONE undo step', async () => {
            await select('bg');
            // a discrete change first, so the single undo below can be told apart from "everything undone"
            await page.click(`${prop('bg.color')} [data-color="#CBC7D1"]`);
            assert.equal((await styles()).bg.color, '#CBC7D1');
            assert.equal((await styles()).bg.colorOpacity, 100);
            const slider = await page.$(prop('bg.colorOpacity'));
            await slider.evaluate((el) => el.scrollIntoView({ block: 'center' }));
            const box = await slider.boundingBox();
            const y = box.y + box.height / 2;
            await page.mouse.move(box.x + box.width - 8, y);
            await page.mouse.down();
            const seen = new Set();
            for (let i = 1; i <= 8; i++) {
                await page.mouse.move(box.x + box.width - 8 - (i * (box.width - 16)) / 10, y);
                await h.sleep(30);
                seen.add((await styles()).bg.colorOpacity);
            }
            assert.ok(seen.size >= 4, `the value follows the drag live: ${[...seen].join(', ')}`);
            const mid = (await styles()).bg.colorOpacity;
            assert.ok(mid < 60, `mid-drag value ${mid}`);
            assert.equal(await page.$eval('.cal-cv svg[data-page] [data-el="bg"] rect', (el) => Number(el.getAttribute('fill-opacity'))), mid / 100, 'the page is redrawn during the drag');
            await page.mouse.up();
            await h.sleep(60);
            const end = (await styles()).bg.colorOpacity;
            assert.equal(end, mid);
            assert.equal(end % 5, 0, 'opacity moves in 5 % steps');
            assert.match(await page.$eval(`label:has(${prop('bg.colorOpacity')}) .cal-szv`, (el) => el.textContent), new RegExp(`^${end} %$`));
            await page.click('[data-cal-action="undo"]');
            let bg = (await styles()).bg;
            assert.equal(bg.colorOpacity, 100, 'one undo restores the opacity');
            assert.equal(bg.color, '#CBC7D1', '… and nothing more');
            assert.equal(Number(await value(prop('bg.colorOpacity'))), 100, 'the slider follows the undo');
            await page.click('[data-cal-action="redo"]');
            assert.equal((await styles()).bg.colorOpacity, end);
            await page.click('[data-cal-action="undo"]');

            // the gradient angle slider behaves the same
            const angle = await page.$(prop('bg.gradient.angle'));
            await angle.evaluate((el) => el.scrollIntoView({ block: 'center' }));
            const a = await angle.boundingBox();
            const before = (await styles()).bg.gradient.angle;
            await page.mouse.move(a.x + 8 + ((a.width - 16) * before) / 360, a.y + a.height / 2);
            await page.mouse.down();
            for (let i = 1; i <= 6; i++) await page.mouse.move(a.x + a.width - 8 - i * 6, a.y + a.height / 2);
            await page.mouse.up();
            await h.sleep(60);
            bg = (await styles()).bg;
            assert.notEqual(bg.gradient.angle, before);
            await page.click('[data-cal-action="undo"]');
            assert.equal((await styles()).bg.gradient.angle, before, 'one undo restores the angle');
            assert.equal((await styles()).bg.color, '#CBC7D1');
        });

        await check('Hlavička: background panel with the default-picture copy; Popisek and Fotografie panels are complete', async () => {
            await select('head');
            assert.equal(await title(), 'Hlavička');
            assert.ok(await page.$(field('h')), 'Hlavička has a position');
            await toggle('head.bg.imageOn');
            assert.equal((await styles()).head.bg.imageOn, true);
            assert.equal(await page.$eval('[data-props="head"] .cal-himgb .cap', (el) => el.textContent), 'Prázdné místo — obrázek vloží klient, nebo nahrajte výchozí');
            await page.waitForFunction(() => /OBRÁZEK HLAVIČKY/.test(document.querySelector('.cal-cv svg[data-page] [data-el="head"]').textContent), { timeout: 3000 });
            await toggle('head.bg.imageOn');
            await select('cap');
            for (const p of ['cap.family', 'cap.weight', 'cap.size', 'cap.align', 'cap.valign', 'cap.color', 'cap.bg.colorOn', 'cap.pad']) assert.ok(await page.$(prop(p)), `Popisek: ${p}`);
            await select('photo');
            for (const p of ['photo.source', 'photo.radius', 'photo.border.on', 'photo.border.width', 'photo.border.color', 'photo.shadow.on', 'photo.shadow.blur', 'photo.shadow.opacity', 'photo.shadow.dx', 'photo.shadow.dy', 'photo.shadow.color']) assert.ok(await page.$(prop(p)), `Fotografie: ${p}`);
            await select('cal');
            assert.ok(await page.$('.cal-props-kal'), 'the Kalendárium is handed to mountKalProps');
            assert.equal(await page.$('[data-props="cal"] ~ *'), null, '… and nothing else');
        });

        await check('everything saved survives a reload; the status returns to „Vše uloženo“', async () => {
            await h.save(page);
            const before = (await h.state(page)).design.layout;
            await h.reload(page);
            const s = await h.state(page);
            assert.deepEqual(s.design.layout, before);
            assert.equal(s.status, 'clean');
        });

        if (h.config.shots) {
            await select('month');
            await h.shot(page, 'panels-month');
            await select('bg');
            await h.shot(page, 'panels-bg');
            await select('photo');
            await h.shot(page, 'panels-photo');
        }

        await h.deleteCalendar(page, id);
        fs.rmSync(tmp, { recursive: true, force: true });
    },
};
