/*
 * Scope guards (handoff §9.6; phase P8a).
 *
 * 1. No AI / import control of handoff §2 anywhere: the script walks every screen and dialog of a
 *    7-day and a 14-day calendar with every feature switched on — list, steps 1–5, the Nahrát layout
 *    menu, the panel of every element and Kalendárium part, every content tool, the special-page
 *    menu, the day popover, Historie, Odkaz pro klienta, the client view — and after each stop
 *    scans the visible text AND the attributes (title, aria-label, placeholder, alt, value) of the
 *    whole document. The scan fails on: Naskenovat (design), Použít ukázkový kalendář, Z PDF nebo
 *    obrázku, Nahrát z Excelu / Excel, Simulovat kolize, Premium.
 * 2. A 14-day calendar never shows the Fotka vlevo / Fotka vpravo choice — in step 2, and nowhere
 *    else either (the same walk).
 * 3. Dimensions: the node suite `dimensions` greps the code for the literals; here a calendar whose
 *    page is 420 × 200 mm with a 3 mm bleed (page properties stored through the save endpoint —
 *    there is no UI for the page size yet) is opened in the real editor: limits, snapping to the
 *    page edges and centre, the mm values of Pozice a velikost and the step-5 preview all follow.
 */
const assert = require('assert/strict');
const ed = require('./lib/editor.cjs');

const FORBIDDEN = [/Naskenovat/i, /Použít ukázkový/i, /ukázkový kalendář/i, /Z PDF nebo obrázku/i, /Nahrát z Excelu/i, /Excel/i, /Simulovat/i, /Premium/i];
// (the bare word „AI“ is not scanned for: the app shell links to „AI (MCP server)“ on every page)
const PRESET_CHOICE = [/Fotka vlevo/, /Fotka vpravo/];

const FEATURES = ['timeChange', 'seasons', 'waste', 'events', 'shifts', 'specialPages', 'qr'];
const ELEMENTS = ['photo', 'cap', 'logo', 'head', 'month', 'cal', 'wk', 'qr', 'bg'];
const TOOLS = ['photos', 'waste', 'events', 'names', 'moon', 'shifts', 'qr'];

/** Everything a user can read on the page: text of visible nodes + the attributes that carry copy. */
const readable = (page) =>
    page.evaluate(() => {
        const out = [document.body.innerText];
        for (const el of document.querySelectorAll('[title], [aria-label], [placeholder], [alt], input[type="button"], input[type="submit"], option')) {
            for (const name of ['title', 'aria-label', 'placeholder', 'alt']) if (el.hasAttribute(name)) out.push(el.getAttribute(name));
            if (el.tagName === 'OPTION') out.push(el.textContent);
            if (el.tagName === 'INPUT' && /button|submit/.test(el.type)) out.push(el.value);
        }
        // SVG texts of the pages (innerText of an inline SVG is not reliable everywhere)
        for (const t of document.querySelectorAll('.cal-ws svg text')) out.push(t.textContent);
        return out.join('\n');
    });

module.exports = {
    name: 'scope-guards',
    checks: ['§9.6 no AI / import control', '§9.6 14-day no left / right choice', '§9.6 dimensions'],
    async run({ browser, page, h, check }) {
        const stops = [];
        const hits = [];
        /** Scans the document; `extra` = more patterns that must not appear at this stop. */
        async function scan(label, extra = []) {
            const text = await readable(page);
            stops.push(label);
            for (const pattern of [...FORBIDDEN, ...extra]) {
                const m = pattern.exec(text);
                if (m) hits.push(`${label}: "${text.slice(Math.max(0, m.index - 30), m.index + 40).replace(/\s+/g, ' ')}"`);
            }
            return text;
        }
        const click = async (selector) => {
            await page.$eval(selector, (el) => el.scrollIntoView({ block: 'center' }));
            await page.click(selector);
            await h.sleep(80);
        };
        const closeModal = async () => {
            await page.keyboard.press('Escape');
            await page.waitForFunction(() => !document.querySelector('.modal.show, .cal-tool-modal, .cal-dialog, .modal-backdrop'), { timeout: 5000 });
            await h.sleep(150);
        };

        async function walk(type) {
            const extra = type === 'fortnight' ? PRESET_CHOICE : [];
            ed.leaveFreely(page);
            const id = await h.createCalendar(page);
            for (const key of FEATURES) await click(`label:has([data-feature="${key}"])`);
            const features = (await h.state(page)).design.features;
            for (const key of FEATURES) assert.equal(features[key], true, `feature ${key} is on`);
            if (type === 'fortnight') await click('[data-type="fortnight"]');
            await scan(`${type} step 1`, extra);

            await h.gotoStep(page, 2);
            const step2 = await scan(`${type} step 2`, extra);
            if (type === 'fortnight') {
                assert.equal(await page.$('[data-preset="photoLeft"], [data-preset="photoRight"]'), null, 'no left / right card');
                assert.equal(await page.$$eval('[data-preset]', (n) => n.length), 1, 'one fixed layout');
                assert.match(step2, /Dvoustranné rozvržení/);
                await click('[data-cal-action="continue"]');
            } else {
                assert.match(step2, /Fotka vlevo/);
                assert.match(step2, /Fotka vpravo/);
                assert.equal(await page.$$eval('[data-preset]', (n) => n.length), 2, 'exactly the two layout cards — no scan card');
                await click('[data-preset="photoLeft"]');
            }
            await page.waitForSelector('.cal-ws[data-cal-shown-step="3"]');
            await h.save(page);

            // step 3: empty, the layout menu, every element panel, every Kalendárium part
            await scan(`${type} step 3`, extra);
            await click('[data-layer-menu]');
            const menu = await scan(`${type} step 3 › Nahrát layout`, extra);
            assert.match(menu, /Ze souboru layoutu/);
            assert.match(menu, /Uložit layout do souboru/);
            // the menu holds the sibling calendars of the same type plus the two file items — nothing else
            const items = await page.$$eval('.cal-laym [role="menuitem"]', (n) => n.map((x) => (x.dataset.layoutFrom ? 'sibling' : x.dataset.calAction)));
            assert.deepEqual(items.filter((i) => i !== 'sibling'), ['layout-file', 'layout-save']);
            assert.doesNotMatch(await page.$eval('.cal-laym', (el) => el.textContent), /PDF|obrázk/i);
            await page.keyboard.press('Escape');
            await h.sleep(80);
            if ((await h.state(page)).selection) await page.keyboard.press('Escape');
            for (const el of ELEMENTS) {
                if (!(await page.$(`[data-layer="${el}"]`))) continue;
                await click(`[data-layer="${el}"]`);
                await scan(`${type} step 3 › ${el}`, extra);
                if (el === 'cal') {
                    const parts = await page.$$eval('[data-part-row]', (n) => n.map((x) => x.dataset.partRow));
                    assert.ok(parts.length >= 8, `Kalendárium lists its parts (${parts.length})`);
                    for (const part of parts) {
                        await click(`[data-part-row="${part}"]`);
                        await scan(`${type} step 3 › cal › ${part}`, extra);
                    }
                }
            }
            await page.keyboard.press('Escape');
            await page.keyboard.press('Escape');

            // step 4: every tool, the special pages, a page, Styl stránek
            await h.gotoStep(page, 4);
            await scan(`${type} step 4`, extra);
            for (const tool of TOOLS) {
                assert.ok(await page.$(`[data-tool="${tool}"]`), `tool ${tool} is offered to the designer`);
                await click(`[data-tool="${tool}"]`);
                await page.waitForSelector(`.cal-tool-modal.show[data-tool-modal="${tool}"]`, { timeout: 5000 });
                await h.sleep(350);
                await scan(`${type} step 4 › tool ${tool}`, extra);
                if (tool === 'waste' || tool === 'events') {
                    // every file input of the two tools the prototype gave an Excel import
                    const accepts = await page.$$eval('.cal-tool-modal.show input[type="file"]', (n) => n.map((x) => x.accept));
                    for (const accept of accepts) assert.doesNotMatch(accept, /xls|sheet|csv/i, `${tool}: no spreadsheet input`);
                }
                await closeModal();
            }
            await click('[data-content-view="special"]');
            await scan(`${type} step 4 › Speciální stránky`, extra);
            await click('[data-cal-action="special-add"]');
            await scan(`${type} step 4 › special-page menu`, extra);
            await page.keyboard.press('Escape');
            await click('[data-page-thumb="1"]');
            await scan(`${type} step 4 › page panel`, extra);
            await click('[data-content-view="style"]');
            await scan(`${type} step 4 › Styl stránek`, extra);

            // step 5: the page, a day popover, Historie, Odkaz pro klienta
            await h.gotoStep(page, 5);
            await page.waitForSelector('.cal-5w .cal-page-svg svg');
            await scan(`${type} step 5`, extra);
            const week = await h.cal(page, (c) => c.pages().find((p) => p.kind === 'week').index);
            await page.$eval(`[data-page-thumb="${week}"]`, (el) => el.click());
            await page.waitForSelector('[data-day-plus], [data-day-row]');
            await h.sleep(250);
            await click('[data-day-plus], [data-day-row]');
            await page.waitForSelector('[data-cal-popover="plus"]');
            await scan(`${type} step 5 › day menu`, extra);
            // Odpad is offered only once a waste kind is switched on
            const options = await page.$$eval('[data-plus-option]', (n) => n.map((x) => x.dataset.plusOption));
            assert.ok(options.includes('event') && options.includes('comment'), `day menu options: ${options}`);
            for (const option of options) {
                if (!(await page.$('[data-cal-popover="plus"]'))) {
                    await click('[data-day-plus], [data-day-row]');
                    await page.waitForSelector('[data-cal-popover="plus"]');
                }
                await click(`[data-plus-option="${option}"]`);
                await page.waitForSelector(`[data-cal-popover="${option}"]`);
                await scan(`${type} step 5 › ${option} editor`, extra);
                await page.keyboard.press('Escape');
                await page.waitForFunction(() => !document.querySelector('[data-cal-popover]'));
            }
            await click('[data-cal-action="history"]');
            await page.waitForSelector('[data-cal-dialog="history"].show');
            await h.sleep(350);
            await scan(`${type} step 5 › Historie`, extra);
            await closeModal();
            await click('[data-cal-action="client-link"]');
            await page.waitForSelector('[data-cal-dialog="client-link"].show');
            await h.sleep(350);
            await scan(`${type} step 5 › Odkaz pro klienta`, extra);
            await closeModal();
            await click('[data-cal-action="export-pdf"]');
            await page.waitForSelector('[data-cal-dialog="export"].show');
            await h.sleep(350);
            await scan(`${type} step 5 › Stáhnout PDF`, extra);
            await closeModal();
            await h.save(page);

            // the client view
            const clientUrl = await page.evaluate(() => JSON.parse(document.querySelector('[data-calendar-workspace-target="bootstrap"]').textContent).env.clientUrl);
            await page.goto(clientUrl, { waitUntil: 'networkidle2' });
            await h.waitReady(page);
            await scan(`${type} client view`, extra);
            await click('[data-cal-action="export-pdf"]');
            await page.waitForSelector('[data-cal-dialog="export"].show');
            await h.sleep(350);
            await scan(`${type} client › Stáhnout PDF`, extra);
            await closeModal();
            for (const tool of await page.$$eval('[data-tool]', (n) => n.map((x) => x.dataset.tool))) {
                await click(`[data-tool="${tool}"]`);
                await page.waitForSelector(`.cal-tool-modal.show[data-tool-modal="${tool}"]`, { timeout: 5000 });
                await h.sleep(350);
                await scan(`${type} client › tool ${tool}`, extra);
                await closeModal();
            }

            // the list (card of this calendar, its menu-less actions)
            await h.gotoList(page);
            await scan(`${type} list`, type === 'fortnight' ? [] : []);
            return id;
        }

        const created = [];
        for (const type of ['week', 'fortnight']) {
            await check(`§9.6 ${type === 'week' ? '7 dní' : '14 dní'}: walk through every screen and dialog (all features on)`, async () => {
                created.push(await walk(type));
            });
        }

        await check('§9.6 no AI / import control (Naskenovat design, Použít ukázkový kalendář, Z PDF nebo obrázku, Nahrát z Excelu, Simulovat kolize) at any stop', async () => {
            assert.ok(stops.length >= 80, `the walk made ${stops.length} stops`);
            for (const needed of ['week step 2', 'week step 3 › Nahrát layout', 'week step 4 › tool waste', 'week step 4 › tool events', 'week step 5', 'fortnight step 2', 'fortnight step 5', 'week client view']) assert.ok(stops.includes(needed), `stop "${needed}" was scanned`);
            assert.deepEqual(hits.filter((hit) => !/Fotka v(levo|pravo)/.test(hit)), []);
        });

        await check('§9.6 a 14-day calendar never shows Fotka vlevo / Fotka vpravo — in no step, dialog or list card', async () => {
            assert.ok(stops.filter((s) => s.startsWith('fortnight')).length >= 40);
            assert.deepEqual(hits.filter((hit) => /Fotka v(levo|pravo)/.test(hit)), []);
        });

        // ---- dimensions -------------------------------------------------------------------------
        const PAGE = { width: 420, height: 200, bandHeight: 17, holes: { size: 5, radius: 0.8, pitch: 10, top: 5, margin: 6 } };
        const BLEED = 3;
        let big = null;
        await check('§9.6 dimensions: a 420 × 200 mm page with 3 mm bleed — the editor page, default boxes and Pozice a velikost are in its mm', async () => {
            big = await h.createCalendar(page);
            created.push(big);
            // no UI for the page size yet: the page properties go through the save endpoint, which accepts them
            await h.seed(page, { design: { page: PAGE, print: { bleed: true, bleedMm: BLEED, marks: true } } });
            await h.gotoStep(page, 2);
            await click('[data-preset="photoLeft"]');
            await page.waitForSelector('.cal-ws[data-cal-shown-step="3"]');
            await h.save(page);
            assert.deepEqual(await h.cal(page, (c) => c.page()), { width: 420, height: 200, bleedMm: 3, bandHeight: 17 });
            // the drawn page: viewBox in mm incl. bleed, px width = 426 mm × scale
            const svg = await page.$eval('.cal-cv .cal-page-svg svg', (el) => ({ vb: el.getAttribute('viewBox').split(' ').map(Number), w: el.getBoundingClientRect().width, h: el.getBoundingClientRect().height }));
            assert.deepEqual(svg.vb, [-3, -3, 426, 206]);
            const scale = await h.cal(page, (c) => c.scale());
            assert.ok(Math.abs(svg.w - 426 * scale) < 1 && Math.abs(svg.h - 206 * scale) < 1, 'the stage draws 426 × 206 mm');
            // default boxes are the default fractions of THIS page: the head spans it incl. bleed, the photo / kalendárium meet on one line
            const b = await ed.allBoxes(page, h);
            h.expectBox(b.head, { x: -3, y: -3, w: 426 }, 0.11, 'head');
            assert.ok(Math.abs(b.photo.x + b.photo.w - b.cal.x) < 0.11, 'photo right = kalendárium left');
            assert.ok(Math.abs(b.cal.x + b.cal.w - 423) < 0.11, 'the kalendárium reaches the right bleed edge at 423 mm');
            assert.ok(Math.abs(b.photo.y + b.photo.h - 203) < 0.11, 'the photo reaches the bottom bleed edge at 203 mm');
            assert.ok(b.cal.x > 250, `the kalendárium starts at ${b.cal.x} mm — beyond the width of the default page`);
            // Pozice a velikost shows these mm
            await ed.selectLayer(page, h, 'logo');
            const fields = await page.evaluate(() => Object.fromEntries(['x', 'y', 'w', 'h'].map((f) => [f, Number(document.querySelector(`[data-field="${f}"]`).value.replace(',', '.'))])));
            h.expectBox(fields, b.logo, 0.051, 'fields');
            assert.ok(fields.x > 200, 'the logo sits at an x that does not exist on a 310 mm page');
        });

        await check('§9.6 dimensions: limits follow the page — a typed X far outside stops at the bleed edge (423 mm), a drag far right too', async () => {
            if (!big) throw new Error('no calendar');
            await ed.selectLayer(page, h, 'logo');
            const before = (await ed.allBoxes(page, h)).logo;
            await page.$eval('[data-field="x"]', (el) => { el.focus(); el.select(); });
            await page.keyboard.type('9999');
            await page.keyboard.press('Enter');
            let logo = (await ed.allBoxes(page, h)).logo;
            assert.ok(Math.abs(logo.x + logo.w - 423) < 0.11, `right edge at the bleed edge, got ${logo.x + logo.w}`);
            assert.equal(Number((await page.$eval('[data-field="x"]', (el) => el.value)).replace(',', '.')), logo.x, 'the field shows the corrected value');
            await page.$eval('[data-field="y"]', (el) => { el.focus(); el.select(); });
            await page.keyboard.type('-500');
            await page.keyboard.press('Enter');
            logo = (await ed.allBoxes(page, h)).logo;
            assert.ok(Math.abs(logo.y + 3) < 0.11, `top at the upper bleed edge −3 mm, got ${logo.y}`);
            await page.evaluate(() => document.activeElement.blur());
            // back, then a real drag far past the bottom-left corner
            await ed.pressMod(page, 'z');
            await ed.pressMod(page, 'z');
            h.expectBox((await ed.allBoxes(page, h)).logo, before, 0.051, 'undo');
            const c = ed.centre(before);
            await h.dragMm(page, c, { x: -200, y: 600 }, { alt: true });
            logo = (await ed.allBoxes(page, h)).logo;
            h.expectBox(logo, { x: -3, y: 203 - before.h, w: before.w, h: before.h }, 0.11, 'dragged to the bottom-left bleed corner');
            await ed.pressMod(page, 'z');
        });

        await check('§9.6 dimensions: snapping to the page centre (210 mm), the trim edge (420 mm) and the band line (17 mm), each with a guide', async () => {
            if (!big) throw new Error('no calendar');
            await ed.selectLayer(page, h, 'logo');
            const start = (await ed.allBoxes(page, h)).logo;
            const from = ed.centre(start);
            const guides = () => page.$$eval('[data-guide]', (n) => n.filter((g) => !g.hidden && g.getClientRects().length).map((g) => g.dataset.guide));
            // centre: drop the logo centre 0.7 mm off the vertical centre line of the 420 mm page
            await h.dragMm(page, from, { x: 210.7, y: 120 }, { hold: true, steps: 20 });
            let live = (await ed.allBoxes(page, h)).logo;
            assert.ok(Math.abs(live.x + live.w / 2 - 210) < 0.06, `centre snapped to 210, got ${live.x + live.w / 2}`);
            assert.ok((await guides()).length >= 1, 'a guide is drawn');
            await page.mouse.up();
            assert.deepEqual(await guides(), [], 'no guide after release');
            // right trim edge at 420
            live = (await ed.allBoxes(page, h)).logo;
            await h.dragMm(page, ed.centre(live), { x: 420 - live.w / 2 + 0.6, y: 120 }, { hold: true, steps: 20 });
            let now = (await ed.allBoxes(page, h)).logo;
            assert.ok(Math.abs(now.x + now.w - 420) < 0.06, `right edge snapped to the trim at 420, got ${now.x + now.w}`);
            await page.mouse.up();
            // band line at 17 mm: the logo's top edge
            now = (await ed.allBoxes(page, h)).logo;
            await h.dragMm(page, ed.centre(now), { x: 150, y: 17 + now.h / 2 + 0.5 }, { hold: true, steps: 20 });
            const top = (await ed.allBoxes(page, h)).logo;
            assert.ok(Math.abs(top.y - 17) < 0.06, `top snapped to the band line at 17, got ${top.y}`);
            await page.mouse.up();
            for (let i = 0; i < 3; i += 1) await ed.pressMod(page, 'z');
            h.expectBox((await ed.allBoxes(page, h)).logo, start, 0.051, 'three undo steps');
        });

        await check('§9.6 dimensions: linking and the step-5 preview follow — photo edge takes the kalendárium along; preview 426 × 206 mm with crop marks, client 420 × 200', async () => {
            if (!big) throw new Error('no calendar');
            const before = await ed.allBoxes(page, h);
            await ed.resize(page, h, 'photo', 'e', -50, 0, { alt: true });
            const after = await ed.allBoxes(page, h);
            assert.ok(Math.abs(after.photo.w - (before.photo.w - 50)) < 0.11);
            assert.ok(Math.abs(after.cal.x - (before.cal.x - 50)) < 0.11 && Math.abs(after.cal.x + after.cal.w - 423) < 0.11, 'the kalendárium was resized, its right edge stays at 423');
            await h.save(page);
            await h.gotoStep(page, 5);
            await page.waitForSelector('.cal-5w .cal-page-svg svg');
            const week = await h.cal(page, (c) => c.pages().find((p) => p.kind === 'week').index);
            await page.$eval(`[data-page-thumb="${week}"]`, (el) => el.click());
            await h.sleep(300);
            const preview = await page.$eval('.cal-5w .cal-page-svg svg', (el) => ({ vb: el.getAttribute('viewBox').split(' ').map(Number), ratio: el.getBoundingClientRect().width / el.getBoundingClientRect().height, marks: Boolean(el.querySelector('[data-el="marks"]')), holes: el.querySelectorAll('[data-el="holes"] rect').length }));
            // bleed 3 + the crop marks' room around it
            assert.ok(preview.vb[2] >= 426 && preview.vb[3] >= 206, `viewBox ${preview.vb}`);
            assert.ok(Math.abs(preview.vb[0] + preview.vb[2] / 2 - 210) < 0.01 && Math.abs(preview.vb[1] + preview.vb[3] / 2 - 100) < 0.01, 'centred on the 420 × 200 page');
            assert.ok(Math.abs(preview.ratio - preview.vb[2] / preview.vb[3]) < 0.01);
            assert.equal(preview.marks, true);
            assert.equal(preview.holes, Math.floor((420 - 2 * 6 - 5) / 10) + 1, 'holes follow the page width and the pitch');
            const geometry = await h.cal(page, (c, i) => c.geometry(i).frames[0].inner, week);
            assert.ok(Math.abs(geometry.x + geometry.w - 420) < 0.01, 'rows are laid out up to the 420 mm trim edge');
            // reload: everything is still in the big page's mm
            await h.reload(page);
            assert.deepEqual(await h.cal(page, (c) => c.page()), { width: 420, height: 200, bleedMm: 3, bandHeight: 17 });
            assert.ok(Math.abs((await ed.allBoxes(page, h)).cal.x - after.cal.x) < 0.051);
            // the client sees the trimmed page
            const clientUrl = await page.evaluate(() => JSON.parse(document.querySelector('[data-calendar-workspace-target="bootstrap"]').textContent).env.clientUrl);
            const client = await h.newPage(browser);
            try {
                await client.goto(clientUrl, { waitUntil: 'networkidle2' });
                await h.waitReady(client);
                await client.$eval(`[data-page-thumb="${week}"]`, (el) => el.click());
                await h.sleep(300);
                assert.deepEqual(await client.$eval('.cal-5w .cal-page-svg svg', (el) => el.getAttribute('viewBox').split(' ').map(Number)), [0, 0, 420, 200]);
                assert.deepEqual(client.problems, []);
            } finally {
                await client.close();
            }
        });

        await check('cleanup', async () => {
            for (const id of created) await h.deleteCalendar(page, id);
        });
    },
};
