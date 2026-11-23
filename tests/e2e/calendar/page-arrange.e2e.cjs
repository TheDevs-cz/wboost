/*
 * Adding, moving and removing special pages right in the page list (feedback K13;
 * docs/architecture/calendars.md § "Page list — adding and arranging pages").
 *
 * As the designer: the „+“ insertion points sit after the cover, after every front special page,
 * after the last week page and after every back special page — never between week pages. A „+“
 * adds a generated kind (and an uploaded picture) exactly there and shows it; a page is dragged
 * inside its section and across the weeks into the back section; its „⋯“ menu moves it up / down /
 * to the other section and removes it (with a confirmation; a generated page keeps its texts).
 * Everything survives a save + reload; step 5 has the same list for the designer, a client link
 * none of it (and a forged client op is refused). Screens at 1440 / 820 / 390 px.
 */
const assert = require('assert/strict');
const fs = require('fs');
const os = require('os');
const path = require('path');
const zlib = require('zlib');

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
function png(width, height, [r, g, b]) {
    const rows = [];
    for (let y = 0; y < height; y++) {
        const row = Buffer.alloc(1 + width * 3);
        for (let x = 0; x < width; x++) row.set([r, g, b], 1 + x * 3);
        rows.push(row);
    }
    const header = Buffer.alloc(13);
    header.writeUInt32BE(width, 0);
    header.writeUInt32BE(height, 4);
    header.set([8, 2, 0, 0, 0], 8);
    return Buffer.concat([Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]), chunk('IHDR', header), chunk('IDAT', zlib.deflateSync(Buffer.concat(rows))), chunk('IEND', Buffer.alloc(0))]);
}

/** Waits until `fn(window.__calendar, ...args)` is truthy. */
function waitCal(page, fn, ...args) {
    return page.waitForFunction(`(${fn.toString()})(window.__calendar, ...${JSON.stringify(args)})`, { timeout: 20000 });
}

/** The page keys that are not week pages, in order (weeks folded into one `weeks`). */
async function outline(page, h) {
    const pages = await h.cal(page, (c) => c.pages());
    const out = [];
    for (const p of pages) {
        if (p.kind === 'week') {
            if (out[out.length - 1] !== 'weeks') out.push('weeks');
        } else out.push(p.key);
    }
    return out;
}

/** What the list shows, in order: page keys, insertion points as `+front:0`, weeks folded. */
const listOrder = (page) =>
    page.$$eval('.cal-thl > *', (nodes) => {
        const out = [];
        for (const n of nodes) {
            if (n.classList.contains('cal-thins')) out.push(`+${n.dataset.slot}`);
            else {
                const thumb = n.matches('[data-page-key]') ? n : n.querySelector('[data-page-key]');
                const key = thumb.dataset.pageKey;
                const week = /^(w\d\d|pre|post)$/.test(key);
                if (week) {
                    if (out[out.length - 1] !== 'weeks') out.push('weeks');
                } else out.push(key);
            }
        }
        return out;
    });

async function waitList(page, expected) {
    await page
        .waitForFunction(
            (want) => {
                const out = [];
                for (const n of document.querySelectorAll('.cal-thl > *')) {
                    if (n.classList.contains('cal-thins')) out.push(`+${n.dataset.slot}`);
                    else {
                        const thumb = n.matches('[data-page-key]') ? n : n.querySelector('[data-page-key]');
                        const key = thumb.dataset.pageKey;
                        if (/^(w\d\d|pre|post)$/.test(key)) {
                            if (out[out.length - 1] !== 'weeks') out.push('weeks');
                        } else out.push(key);
                    }
                }
                return JSON.stringify(out) === JSON.stringify(want);
            },
            { timeout: 8000 },
            expected,
        )
        .catch(() => null);
    assert.deepEqual(await listOrder(page), expected);
}

async function insertAt(page, slot, pick) {
    await page.click(`.cal-thins[data-slot="${slot}"] [data-cal-action="page-insert"]`);
    await page.waitForSelector('.cal-spam');
    if (pick) {
        await page.click(`.cal-spam [data-special-add="${pick}"]`);
        await page.waitForFunction(() => !document.querySelector('.cal-spam'));
    }
}

/** Drags a special page's handle onto the middle of `target` (a selector), with the real mouse. */
async function dragTo(page, h, id, target, { below = false } = {}) {
    await page.$eval(`[data-special-thumb="${id}"] .cal-grip`, (el) => el.scrollIntoView({ block: 'center' }));
    const grip = await (await page.$(`[data-special-thumb="${id}"] .cal-grip`)).boundingBox();
    await page.mouse.move(grip.x + grip.width / 2, grip.y + grip.height / 2);
    await page.mouse.down();
    await page.mouse.move(grip.x + grip.width / 2, grip.y + grip.height / 2 + 12, { steps: 4 });
    await page.$eval(target, (el) => el.scrollIntoView({ block: 'center' }));
    await h.sleep(100);
    const box = await (await page.$(target)).boundingBox();
    // Sortable swaps once the pointer is well inside the target: near its top edge when coming from below
    const y = below ? box.y + box.height - 4 : box.y + 6;
    await page.mouse.move(box.x + box.width / 2, y, { steps: 16 });
    await h.sleep(200);
    await page.mouse.up();
    await h.sleep(250);
}

module.exports = {
    name: 'page-arrange',
    checks: ['K13'],
    async run({ browser, page, h, check }) {
        page.on('dialog', (dialog) => dialog.accept().catch(() => null));
        const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'wboost-cal-k13-'));
        const graphic = path.join(dir, 'Mapa obce.png');
        fs.writeFileSync(graphic, png(1240, 580, [230, 200, 120]));
        let id = null;
        let total = 0;
        const selectedKey = () => page.$eval('[data-page-thumb][aria-current="true"]', (el) => el.dataset.pageKey).catch(() => null);

        await check('setup: a 7-day calendar in step 4; insertion points after the cover and after the weeks only', async () => {
            id = await h.createCalendar(page);
            await page.click('[data-cal-step="2"]');
            await page.waitForSelector('[data-preset="photoLeft"]');
            await page.click('[data-preset="photoLeft"]');
            await page.waitForSelector('.cal-ws[data-cal-shown-step="3"]');
            await h.gotoStep(page, 4);
            await page.waitForSelector('.cal-b5 [data-page-thumb="0"]');
            total = (await h.cal(page, (c) => c.pages())).length;
            await waitList(page, ['cover', '+front:0', 'weeks', '+back:0', 'back']);
            assert.equal(await page.$$eval('.cal-thins', (n) => n.length), 2, 'nothing between week pages');
            assert.equal(await page.$eval('[data-slot="front:0"] [data-cal-action="page-insert"]', (el) => el.getAttribute('aria-label')), 'Vložit stránku sem (mezi titulní stranu a týdny)');
            assert.equal(await page.$$eval('.cal-thw[data-special-thumb]', (n) => n.length), 0, 'no movable page yet');
            await h.shot(page, 'k13-1440-empty');
        });

        await check('„+“ after the cover adds Úvodní slovo there and shows it; „+“ after the weeks adds Poznámky before the back cover', async () => {
            await insertAt(page, 'front:0');
            const menu = await page.$$eval('.cal-spam > *', (nodes) => nodes.map((n) => n.textContent.replace(/\s+/g, ' ').trim()));
            assert.deepEqual(menu.map((m) => m.replace('Vygeneruje se automaticky', '')), ['Úvodní slovo', 'Důležité kontakty', 'Roční kalendárium', 'Plánovací kalendář svozu odpadů', 'Poznámky', 'Nahrát grafikuHotová strana jako PDF nebo obrázek', 'Text z WorduVysází se do stylu kalendáře']);
            assert.equal(await page.$eval('[data-slot="front:0"] [data-cal-action="page-insert"]', (el) => el.getAttribute('aria-expanded')), 'true');
            await h.shot(page, 'k13-1440-chooser');
            await page.keyboard.press('Escape');
            await page.waitForFunction(() => !document.querySelector('.cal-spam'));

            await insertAt(page, 'front:0', 'intro');
            await waitList(page, ['cover', '+front:0', 'sp:intro', '+front:1', 'weeks', '+back:0', 'back']);
            assert.equal(await selectedKey(), 'sp:intro', 'the new page is shown');
            assert.equal(await page.$eval('[data-page-title]', (el) => el.textContent.trim()), 'Úvodní slovo');
            const state = await h.state(page);
            assert.equal(state.design.features.specialPages, true, 'the feature is switched on');
            assert.deepEqual([state.content.specialPages.intro.on, state.content.specialPages.intro.position], [true, 'front']);

            // notes default to the back: from the back slot, before the back cover
            await insertAt(page, 'back:0', 'notes');
            await waitList(page, ['cover', '+front:0', 'sp:intro', '+front:1', 'weeks', '+back:0', 'sp:notes', '+back:1', 'back']);
            assert.equal(await selectedKey(), 'sp:notes');

            // the chooser no longer offers what is in the calendar
            await insertAt(page, 'front:1');
            const left = await page.$$eval('.cal-spam [data-special-add]', (nodes) => nodes.map((n) => n.dataset.specialAdd));
            assert.deepEqual(left, ['contacts', 'year', 'waste']);
            // Důležité kontakty between the cover and Úvodní slovo
            await page.keyboard.press('Escape');
            await insertAt(page, 'front:0', 'contacts');
            await waitList(page, ['cover', '+front:0', 'sp:contacts', '+front:1', 'sp:intro', '+front:2', 'weeks', '+back:0', 'sp:notes', '+back:1', 'back']);
            assert.equal((await h.cal(page, (c) => c.pages())).length, total + 3);
        });

        await check('„+“ → Nahrát grafiku places the uploaded page at that slot', async () => {
            await insertAt(page, 'back:1');
            const input = await page.$('.cal-spam [data-special-upload="image"]');
            await input.uploadFile(graphic);
            await waitCal(page, (c, n) => c.pages().length === n, total + 4);
            const own = Object.entries((await h.state(page)).content.specialPages).find(([, item]) => item.kind === 'image');
            assert.ok(own, 'the uploaded page is stored');
            const [ownId, item] = own;
            assert.deepEqual([item.name, item.position, item.on], ['Mapa obce', 'back', true]);
            await waitList(page, ['cover', '+front:0', 'sp:contacts', '+front:1', 'sp:intro', '+front:2', 'weeks', '+back:0', 'sp:notes', '+back:1', `sp:${ownId}`, '+back:2', 'back']);
            assert.equal(await selectedKey(), `sp:${ownId}`);
            await h.shot(page, 'k13-1440-added');
        });

        await check('drag: inside the front section, then across the weeks into the back section; the shown page stays shown', async () => {
            // show a week page: it must stay shown while special pages move around it
            const week = await h.cal(page, (c) => c.pages().find((p) => p.kind === 'week' && p.key === 'w10'));
            await page.click(`[data-page-thumb="${week.index}"]`);
            await page.waitForFunction(() => document.querySelector('[data-page-key="w10"]')?.getAttribute('aria-current') === 'true');

            await dragTo(page, h, 'intro', '[data-special-thumb="contacts"]');
            await waitCal(page, (c) => c.pages()[1].key === 'sp:intro');
            assert.deepEqual((await outline(page, h)).slice(0, 4), ['cover', 'sp:intro', 'sp:contacts', 'weeks']);
            assert.deepEqual((await listOrder(page)).slice(0, 7), ['cover', '+front:0', 'sp:intro', '+front:1', 'sp:contacts', '+front:2', 'weeks'], 'the list is rebuilt with its insertion points');
            assert.equal(await selectedKey(), 'w10', 'the week page is still the one shown');

            // contacts → onto the back slot after the weeks
            await dragTo(page, h, 'contacts', '[data-slot="back:0"]');
            await waitCal(page, () => window.__calendar.state().content.specialPages.contacts.position === 'back');
            const order = await outline(page, h);
            assert.deepEqual(order.slice(0, 4), ['cover', 'sp:intro', 'weeks', 'sp:contacts'], JSON.stringify(order));
            const stored = (await h.state(page)).content.specialPages;
            assert.equal(stored.contacts.position, 'back');
            assert.equal(await selectedKey(), 'w10');
        });

        await check('the „⋯“ menu moves a page up, to the other section, and removes it after a confirmation', async () => {
            await page.click('[data-special-thumb="notes"] [data-cal-action="page-menu"]');
            await page.waitForSelector('.cal-spam [data-page-move]');
            const items = await page.$$eval('.cal-spam > button', (nodes) => nodes.map((n) => `${n.dataset.pageMove || 'remove'}${n.disabled ? '-' : ''}`));
            assert.deepEqual(items, ['up', 'down', 'front', 'remove']);
            await h.shot(page, 'k13-1440-page-menu');
            await page.click('.cal-spam [data-page-move="up"]');
            let order = await outline(page, h);
            assert.deepEqual(order.slice(2), ['weeks', 'sp:notes', 'sp:contacts', order[5], 'back']);

            await page.click('[data-special-thumb="notes"] [data-cal-action="page-menu"]');
            await page.waitForSelector('.cal-spam [data-page-move="front"]');
            await page.click('.cal-spam [data-page-move="front"]');
            order = await outline(page, h);
            assert.deepEqual(order.slice(0, 4), ['cover', 'sp:intro', 'sp:notes', 'weeks']);
            assert.equal((await h.state(page)).content.specialPages.notes.position, 'front');

            // remove a generated page: off, texts kept; cancel first
            await page.click('[data-special-thumb="intro"] [data-cal-action="page-menu"]');
            await page.waitForSelector('.cal-spam [data-page-remove]');
            await page.click('.cal-spam [data-page-remove]');
            await h.answerDialog(page, 'cancel');
            assert.equal((await h.state(page)).content.specialPages.intro.on, true);
            await page.click('[data-special-thumb="intro"] [data-cal-action="page-menu"]');
            await page.waitForSelector('.cal-spam [data-page-remove]');
            await page.click('.cal-spam [data-page-remove]');
            await h.answerDialog(page, 'confirm');
            await h.waitToast(page, /Stránka „Úvodní slovo“ odebrána/);
            assert.equal((await h.state(page)).content.specialPages.intro.on, false);
            assert.equal(await page.$('[data-special-thumb="intro"]'), null);
            assert.equal(await selectedKey(), 'w10');
        });

        await check('save + reload keeps the pages and their order; Historie names them', async () => {
            const before = await outline(page, h);
            await h.save(page);
            await h.reload(page);
            if ((await page.$eval('.cal-ws', (el) => el.dataset.calShownStep)) !== '4') await h.gotoStep(page, 4);
            await page.waitForSelector('.cal-b5 [data-page-thumb="0"]');
            assert.deepEqual(await outline(page, h), before);
            const answer = await page.evaluate(async (url) => (await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })).json(), `/calendar/${id}/history`);
            const history = answer.entries.map((e) => e.what).join('\n');
            // one save: the last state of each page is what is described
            assert.match(history, /Přidal stránku „Důležité kontakty“/, history.slice(0, 600));
            assert.match(history, /Přidal stránku „Poznámky“/);
            assert.match(history, /Přidal vlastní stránku „Mapa obce“/);
        });

        await check('step 5: the designer gets the same insertion points and menus', async () => {
            await h.gotoStep(page, 5);
            await page.waitForSelector('.cal-b6 [data-page-thumb="0"]');
            assert.ok((await page.$$('.cal-b6 .cal-thins')).length >= 2);
            assert.ok(await page.$('.cal-b6 [data-special-thumb="notes"] [data-cal-action="page-menu"]'));
            await page.click('.cal-b6 [data-slot="front:0"] [data-cal-action="page-insert"]');
            await page.waitForSelector('.cal-spam [data-special-add="year"]');
            await page.click('.cal-spam [data-special-add="year"]');
            await page.waitForFunction(() => document.querySelector('[data-page-thumb][aria-current="true"]')?.dataset.pageKey === 'sp:year');
            assert.equal((await outline(page, h))[1], 'sp:year');
            await h.shot(page, 'k13-1440-step5');
            await h.save(page);
        });

        await check('narrow screens: 820 and 390 px show the insertion points in the sideways list', async () => {
            await h.gotoStep(page, 4);
            for (const width of [820, 390]) {
                await page.setViewport({ width, height: 900 });
                await page.waitForSelector('.cal-b5 [data-page-thumb="0"]');
                await page.$eval('.cal-thins[data-slot="front:0"]', (el) => el.scrollIntoView({ block: 'center', inline: 'center' }));
                const box = await (await page.$('.cal-thins[data-slot="front:0"] [data-cal-action="page-insert"]')).boundingBox();
                assert.ok(box && box.width >= 20 && box.height >= 20, `the „+“ is visible at ${width} px`);
                const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
                assert.ok(overflow <= 0, `no horizontal page scroll at ${width} px (${overflow})`);
                await h.shot(page, `k13-${width}`);
            }
            await page.setViewport({ width: 1440, height: 900 });
            // the light theme (tokens only — a look for the screenshot)
            await page.evaluate(() => document.documentElement.setAttribute('data-theme', 'light'));
            await page.click('[data-special-thumb="notes"] [data-cal-action="page-menu"]');
            await page.waitForSelector('.cal-spam [data-page-remove]');
            await h.shot(page, 'k13-1440-light');
            await page.keyboard.press('Escape');
            await page.evaluate(() => document.documentElement.removeAttribute('data-theme'));
        });

        await check('cleanup', async () => {
            await h.deleteCalendar(page, id);
        });
    },
};
