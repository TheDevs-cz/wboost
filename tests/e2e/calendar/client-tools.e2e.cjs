/*
 * The client fills the calendar in — every client tool through the real UI (phase P8a; handoff R3
 * and §8 "A client changes something → saved, visible to the designer, recorded in Historie as
 * Klient").
 *
 * The designer creates a 7-day calendar (Fotka vlevo) with Odpady and Akce switched on in step 1
 * and saves. An anonymous browser context then opens the public link `/k/{token}` and works like a
 * client would, with real clicks, key presses and a real file input — nothing is seeded:
 *   1. Fotky a popisky   a photo and a caption in the row of one week
 *   2. Odpady            Bio odpad switched on, one weekday, weekly
 *   3. Akce a spolky     an event with a typed date and name
 *   4. Jména navíc       an extra name on a typed date
 *   5. the page itself   plus button of a day → Komentář
 * Uložit, reload: everything is still in the client's document, on the drawn page and in the
 * dialogs. The designer reloads: the same content in the state and on the step-5 page, and Historie
 * lists one row per change as Klient (and the Klient filter shows exactly those).
 *
 * All dated items land in the week of 3 March: `03` / `03` reads the same in day-first and
 * month-first locales, so the date fields can be typed on the keyboard.
 */
const assert = require('assert/strict');
const fs = require('fs');
const os = require('os');
const path = require('path');
const zlib = require('zlib');

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const M = '.cal-tool-modal.show';
const POP = '.cal-pop';
const STAGE = '.cal-5w svg[data-page]';

const CAPTION = 'Masopust na návsi';
const EVENT = 'Pouť';
const NAME = 'Vasil';
const COMMENT = 'Prosíme jinou fotku';

const pad = (n) => String(n).padStart(2, '0');
const dayLabel = (date) => `${Number(date.slice(8))}. ${Number(date.slice(5, 7))}.`;
const boot = (page) => page.evaluate(() => JSON.parse(document.querySelector('[data-calendar-workspace-target="bootstrap"]').textContent));
const exists = async (page, selector) => (await page.$(selector)) !== null;

/** An RGB gradient PNG (landscape, a few kB whatever the size). */
function gradientPng(width, height, file) {
    const row = width * 3 + 1;
    const raw = Buffer.alloc(row * height);
    for (let y = 0; y < height; y++) {
        raw[y * row] = 1; // Sub filter
        let prev = [0, 0, 0];
        for (let x = 0; x < width; x++) {
            const px = [Math.floor((x * 255) / width), Math.floor((y * 255) / height), 140];
            for (let i = 0; i < 3; i++) raw[y * row + 1 + x * 3 + i] = (px[i] - prev[i]) & 0xff;
            prev = px;
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
    header.writeUInt32BE(width, 0);
    header.writeUInt32BE(height, 4);
    header.set([8, 2, 0, 0, 0], 8);
    fs.writeFileSync(file, Buffer.concat([Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]), chunk('IHDR', header), chunk('IDAT', zlib.deflateSync(raw, { level: 9 })), chunk('IEND', Buffer.alloc(0))]));
    return file;
}

async function openTool(page, key) {
    await page.click(`[data-tool="${key}"]`);
    await page.waitForSelector(`.cal-tool-modal.show[data-tool-modal="${key}"]`, { timeout: 8000 });
    await sleep(350);
}

async function closeTool(page) {
    await page.click(`${M} [data-cal-action="modal-done"]`);
    await page.waitForFunction(() => !document.querySelector('.cal-tool-modal'), { timeout: 8000 });
    await sleep(150);
}

/** Types a date into a native date field (day and month must be equal: locale-independent). */
async function typeDate(page, selector, date) {
    const [y, m, d] = date.split('-');
    assert.equal(m, d, 'typeDate needs day = month');
    // focus (not a click: a click lands in whichever segment is under the pointer) → first segment
    await page.focus(selector);
    await page.keyboard.type(`${d}${m}${y}`);
    await page.keyboard.press('Enter');
    await sleep(60);
}

async function showPage(page, h, index) {
    const key = await h.cal(page, (c, i) => c.pages()[i].key, index);
    await page.click(`[data-page-thumb="${index}"]`);
    await page.waitForSelector(`.cal-5w svg[data-page="${key}"]`, { timeout: 5000 });
    await sleep(150);
}

/** What the rows of a week page print (from the generated geometry). */
const weekData = (page, h, index) =>
    h.cal(
        page,
        (c, i) => {
            const out = {};
            for (const frame of c.geometry(i).frames) {
                for (const row of frame.rows) {
                    out[row.date] = {
                        waste: row.info.icons.filter((icon) => icon.category === 'waste').map((icon) => icon.kindId),
                        events: row.info.events.map((e) => e.text),
                        names: row.info.names,
                    };
                }
            }
            return out;
        },
        index,
    );

/** What is really drawn on the large step-5 page. */
const drawnPage = (page) =>
    page.evaluate((stage) => {
        const svg = document.querySelector(stage);
        const texts = (date, part) => [...svg.querySelectorAll(`[data-el="cal"] [data-date="${date}"] text[data-part="${part}"]`)].map((n) => n.textContent);
        const image = svg.querySelector('[data-el="photo"] image');
        return {
            key: svg.dataset.page,
            photo: image ? image.getAttribute('href') || image.getAttribute('xlink:href') : null,
            caption: [...svg.querySelectorAll('[data-el="cap"] text')].map((n) => n.textContent).join(' '),
            days: Object.fromEntries(
                [...svg.querySelectorAll('[data-el="cal"] [data-date]')].map((g) => [
                    g.dataset.date,
                    { ev: texts(g.dataset.date, 'ev').join(' '), name: texts(g.dataset.date, 'name').join(' '), waste: g.querySelectorAll('[data-part="waste"]').length },
                ]),
            ),
        };
    }, STAGE);

/** Rows of Historie, newest first; opens the dialog when it is not open. */
async function historyRows(page) {
    if (!(await page.$('[data-cal-dialog="history"].show'))) {
        await page.click('[data-cal-action="history"]');
        await page.waitForSelector('[data-cal-dialog="history"].show', { timeout: 5000 });
        await sleep(350);
    }
    await page.waitForFunction(() => document.querySelector('[data-cal-dialog="history"] .cal-hist-list') && !document.querySelector('[data-cal-dialog="history"] [aria-busy]'), { timeout: 8000 });
    return page.$$eval('[data-cal-dialog="history"] .cal-hrow', (nodes) => nodes.map((n) => ({ who: n.querySelector('.pill').textContent.trim(), attr: n.dataset.who, what: n.querySelector('.cal-hwhat').textContent.trim() })));
}

async function closeHistory(page) {
    await page.keyboard.press('Escape');
    await page.waitForFunction(() => !document.querySelector('[data-cal-dialog="history"]'), { timeout: 5000 });
}

module.exports = {
    name: 'client-tools',
    checks: ['R3', '§8 client change → saved, visible to the designer, Historie as Klient'],
    async run({ browser, page, h, check }) {
        const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'wb-cal-client-tools-'));
        const photoFile = gradientPng(2700, 1800, path.join(tmp, 'masopust.png'));
        let id = null;
        let ready = false;
        let Y = 0;
        let week = null; // { index, key, title, days[] }
        let eventDay = '';
        let wasteDay = '';
        let wasteWeekday = 0;
        let commentDay = '';
        const done = {};

        const anonymous = await browser.createBrowserContext();
        const client = await h.newPage(anonymous);
        // a failed check may leave unsaved changes behind: never let `beforeunload` block a navigation
        for (const p of [page, client]) p.on('dialog', (dialog) => dialog.accept().catch(() => null));

        /** The saved content as both sides must see it; `who` names the page in messages. */
        async function assertContent(p, who) {
            const s = await h.state(p);
            const c = s.content;
            const photo = c.pagePhotos[week.key];
            assert.ok(photo && photo.asset, `${who}: photo of ${week.key}`);
            assert.deepEqual(c.pageCaptions[week.key], { text: CAPTION }, `${who}: caption`);
            const bio = c.wasteKinds.bio;
            assert.deepEqual([bio.on, bio.mode, bio.weekdays, bio.interval], [true, 'rule', [wasteWeekday], 'w'], `${who}: Bio odpad`);
            assert.deepEqual(Object.values(c.events), [{ date: eventDay, name: EVENT, club: null, icon: null }], `${who}: event`);
            assert.deepEqual(Object.values(c.names), [{ date: eventDay, name: NAME }], `${who}: extra name`);
            const comments = Object.values(c.comments);
            assert.equal(comments.length, 1, `${who}: one comment`);
            assert.deepEqual({ date: comments[0].date, text: comments[0].text, author: comments[0].author }, { date: commentDay, text: COMMENT, author: 'client' }, `${who}: the comment is the client's`);
            return s;
        }

        /** The same content on the large page of step 5. */
        async function assertDrawn(p, who) {
            await showPage(p, h, week.index);
            const data = await weekData(p, h, week.index);
            assert.deepEqual(data[wasteDay].waste, ['bio'], `${who}: bio collection on ${wasteDay}`);
            assert.deepEqual(data[eventDay].events, [EVENT], `${who}: event of ${eventDay}`);
            assert.match(data[eventDay].names, new RegExp(` / ${NAME}$`), `${who}: name day + extra name`);
            const drawn = await drawnPage(p);
            assert.equal(drawn.key, week.key);
            assert.ok(drawn.photo, `${who}: the photo is drawn`);
            assert.match(drawn.caption, new RegExp(CAPTION), `${who}: the caption is drawn`);
            assert.match(drawn.days[eventDay].ev, new RegExp(EVENT), `${who}: the event is drawn`);
            assert.match(drawn.days[eventDay].name, new RegExp(NAME), `${who}: the extra name is drawn`);
            assert.ok(drawn.days[wasteDay].waste >= 1, `${who}: the bin of ${wasteDay} is drawn`);
            assert.equal(drawn.days[commentDay === wasteDay ? eventDay : commentDay].waste, 0, `${who}: no bin on another day`);
            assert.equal(await p.$eval(`[data-day-comments="${commentDay}"]`, (el) => el.textContent), '1', `${who}: comment bubble`);
        }

        await check('setup: the designer creates a 7-day calendar with Odpady and Akce on, saves; the client link opens', async () => {
            id = await h.createCalendar(page);
            let features = (await h.state(page)).design.features;
            for (const key of ['waste', 'events']) {
                if (!features[key]) await page.click(`label:has([data-feature="${key}"])`);
            }
            await h.gotoStep(page, 2);
            await page.click('[data-preset="photoLeft"]');
            await page.waitForSelector('.cal-ws[data-cal-shown-step="3"]', { timeout: 10000 });
            await h.gotoStep(page, 5);
            await h.save(page);
            const s = await h.state(page);
            features = s.design.features;
            assert.deepEqual([s.design.type, features.waste, features.events, features.captions], ['week', true, true, true]);
            assert.deepEqual([Object.keys(s.content.events || {}), Object.keys(s.content.names || {}), Object.keys(s.content.pagePhotos || {}), Object.keys(s.content.comments || {})], [[], [], [], []], 'nothing is filled in yet');
            Y = s.design.year;
            eventDay = `${Y}-03-03`;
            const weeks = await h.cal(page, (c) => c.pages().map((p, index) => ({ index, key: p.key, title: p.title, kind: p.kind, days: p.kind === 'week' ? c.geometry(index).frames[0].rows.map((r) => r.date) : [] })).filter((p) => p.kind === 'week'));
            week = weeks.find((w) => w.days.includes(eventDay));
            assert.ok(week && week.days.length === 7, 'the week of 3 March');
            const at = week.days.indexOf(eventDay);
            wasteDay = week.days[(at + 1) % 7];
            commentDay = week.days[(at + 2) % 7];
            wasteWeekday = week.days.indexOf(wasteDay); // rows run Monday … Sunday, weekday 0 = Monday
            assert.equal((new Date(`${wasteDay}T00:00:00Z`).getUTCDay() + 6) % 7, wasteWeekday);

            const clientUrl = (await boot(page)).env.clientUrl;
            assert.match(clientUrl, /\/k\/[a-f0-9]{64}$/);
            const response = await client.goto(clientUrl, { waitUntil: 'networkidle2' });
            assert.equal(response.status(), 200);
            await h.waitReady(client);
            assert.equal((await h.state(client)).mode, 'client');
            assert.equal(await client.$('#sidebar'), null, 'the client is not inside the app (no designer session)');
            assert.deepEqual(await client.$$eval('[data-tool]', (nodes) => nodes.map((n) => n.dataset.tool)), ['photos', 'waste', 'events', 'names']);
            ready = true;
        });
        if (!ready) {
            await anonymous.close().catch(() => null);
            if (id) await h.deleteCalendar(page, id);
            return;
        }

        await check('client · Fotky a popisky: a photo through the file input of a week row and a typed caption', async () => {
            await openTool(client, 'photos');
            const row = `${M} [data-photo-row="${week.key}"]`;
            const total = await client.$$eval(`${M} [data-photo-row]`, (n) => n.length);
            assert.equal(await client.$eval(`${M} [data-photo-count]`, (el) => el.dataset.photoCount), `0/${total}`);
            await client.$eval(row, (el) => el.scrollIntoView({ block: 'center' }));
            const input = await client.$(`${row} [data-photo-file]`);
            await input.uploadFile(photoFile);
            await client.waitForFunction((key) => Boolean(window.__calendar.state().content.pagePhotos[key]), { timeout: 20000 }, week.key);
            await client.waitForSelector(`${row} .cal-ft.has img`, { timeout: 5000 });
            await client.click(`${row} [data-photo-caption]`);
            await client.keyboard.type(CAPTION);
            await client.keyboard.press('Enter');
            const s = await h.state(client);
            assert.deepEqual(s.content.pageCaptions[week.key], { text: CAPTION });
            assert.deepEqual({ zoom: s.content.pagePhotos[week.key].zoom, px: s.content.pagePhotos[week.key].px }, { zoom: 1, px: 0.5 });
            assert.equal(await client.$eval(`${M} [data-photo-count]`, (el) => el.dataset.photoCount), `1/${total}`);
            assert.equal(s.status, 'dirty');
            await closeTool(client);
            await showPage(client, h, week.index);
            const drawn = await drawnPage(client);
            assert.ok(drawn.photo, 'the photo is on the page');
            assert.match(drawn.caption, new RegExp(CAPTION));
            assert.deepEqual(await h.cal(client, (c, i) => c.warnings().filter((w) => w.pageIndex === i).map((w) => w.kind), week.index), [], 'a 2700 px landscape photo raises no warning');
            done.photos = true;
        });

        await check('client · Odpady: Bio odpad switched on, one weekday, every week', async () => {
            await openTool(client, 'waste');
            assert.equal(await exists(client, `${M} [data-cal-action="feature-on"]`), false, 'the feature is on: no notice');
            await client.click(`${M} [data-waste-tab="bio"]`);
            assert.equal((await h.state(client)).content.wasteKinds.bio.on, false);
            await client.click(`${M} label:has([data-waste-on])`);
            await client.waitForSelector(`${M} [data-weekday="${wasteWeekday}"]`);
            const pressed = () => client.$$eval(`${M} [data-weekday]`, (nodes) => nodes.filter((n) => n.getAttribute('aria-pressed') === 'true').map((n) => Number(n.dataset.weekday)));
            if (!(await pressed()).includes(wasteWeekday)) await client.click(`${M} [data-weekday="${wasteWeekday}"]`);
            for (const other of await pressed()) {
                if (other !== wasteWeekday) await client.click(`${M} [data-weekday="${other}"]`);
            }
            await client.select(`${M} [data-waste-interval]`, 'w');
            const bio = (await h.state(client)).content.wasteKinds.bio;
            assert.deepEqual([bio.on, bio.mode, bio.weekdays, bio.interval], [true, 'rule', [wasteWeekday], 'w']);
            assert.match(await client.$eval(`${M} [data-waste-total]`, (el) => el.textContent), /^Vychází \d+ svoz/);
            await closeTool(client);
            const data = await weekData(client, h, week.index);
            assert.deepEqual(week.days.filter((d) => data[d].waste.includes('bio')), [wasteDay], 'one collection in the week, on the chosen weekday');
            assert.ok((await drawnPage(client)).days[wasteDay].waste >= 1, 'the bin is drawn');
            done.waste = true;
        });

        await check('client · Akce a spolky: an event with a typed name and date', async () => {
            await openTool(client, 'events');
            await client.click(`${M} [data-event-add]`);
            await client.waitForSelector(`${M} [data-event-row]`);
            assert.equal(await client.evaluate(() => document.activeElement.matches('[data-event-name]')), true, 'the new event name has focus');
            await client.keyboard.type(EVENT);
            const eventId = Object.keys((await h.state(client)).content.events)[0];
            await typeDate(client, `${M} [data-event-row="${eventId}"] [data-event-date]`, eventDay);
            assert.deepEqual((await h.state(client)).content.events[eventId], { date: eventDay, name: EVENT, club: null, icon: null });
            assert.equal(await client.$eval(`${M} [data-month="3"]`, (el) => el.dataset.count), '1', 'the month strip counts it in March');
            await closeTool(client);
            assert.deepEqual((await weekData(client, h, week.index))[eventDay].events, [EVENT]);
            assert.match((await drawnPage(client)).days[eventDay].ev, new RegExp(EVENT));
            done.events = true;
        });

        await check('client · Jména navíc: an extra name on a typed date', async () => {
            const before = (await weekData(client, h, week.index))[eventDay].names;
            await openTool(client, 'names');
            await client.click(`${M} [data-name-add]`);
            await client.waitForSelector(`${M} [data-name-row]`);
            await client.keyboard.type(NAME);
            const nameId = Object.keys((await h.state(client)).content.names)[0];
            await typeDate(client, `${M} [data-name-row="${nameId}"] [data-name-date]`, eventDay);
            assert.deepEqual((await h.state(client)).content.names[nameId], { date: eventDay, name: NAME });
            const expected = [before, NAME].filter(Boolean).join(' / ');
            assert.equal(await client.$eval(`${M} [data-name-row="${nameId}"] [data-name-preview]`, (el) => el.textContent), expected);
            await closeTool(client);
            assert.equal((await weekData(client, h, week.index))[eventDay].names, expected);
            assert.match((await drawnPage(client)).days[eventDay].name, new RegExp(NAME));
            done.names = true;
        });

        await check('client · step-5 page: plus of a day → Komentář → typed → Odeslat', async () => {
            await showPage(client, h, week.index);
            assert.equal(await exists(client, `[data-day-comments="${commentDay}"]`), false);
            await client.click(`[data-day-plus="${commentDay}"]`);
            await client.waitForSelector(`${POP}[data-cal-popover="plus"]`, { timeout: 3000 });
            await client.click(`${POP} [data-plus-option="comment"]`);
            await client.waitForSelector(`${POP}[data-cal-popover="comment"] [data-field="text"]`);
            await client.click(`${POP} [data-field="text"]`);
            await client.keyboard.type(COMMENT);
            await client.click(`${POP} [data-popover-confirm]`);
            await client.waitForFunction((selector) => !document.querySelector(selector), { timeout: 3000 }, POP);
            const comments = Object.values((await h.state(client)).content.comments);
            assert.equal(comments.length, 1);
            assert.deepEqual({ date: comments[0].date, text: comments[0].text }, { date: commentDay, text: COMMENT });
            assert.equal(await client.$eval(`[data-day-comments="${commentDay}"]`, (el) => el.textContent), '1');
            done.comment = true;
        });

        await check('client · Uložit: status, toast; after a reload everything is still there (state, page, dialogs)', async () => {
            assert.deepEqual(done, { photos: true, waste: true, events: true, names: true, comment: true }, 'every tool was used');
            assert.equal(await client.$eval('[data-cal-status]', (el) => el.dataset.calStatus), 'dirty');
            assert.equal(await client.$eval('[data-cal-status]', (el) => el.textContent.trim()), 'Neuložené změny');
            await client.click('[data-cal-action="save"]');
            await h.waitToast(client, /Změny uloženy/);
            await h.waitStatus(client, 'clean');
            await client.waitForNetworkIdle({ idleTime: 200, timeout: 10000 }).catch(() => null);
            await h.reload(client);
            const s = await assertContent(client, 'client after reload');
            assert.equal(s.status, 'clean');
            assert.equal(s.mode, 'client');
            await assertDrawn(client, 'client after reload');
            await h.shot(client, 'client-tools-page');

            // … and in the dialogs
            await openTool(client, 'photos');
            const row = `${M} [data-photo-row="${week.key}"]`;
            assert.equal(await client.$eval(`${row} [data-photo-caption]`, (el) => el.value), CAPTION);
            assert.ok(await exists(client, `${row} .cal-ft.has img`), 'the row shows the photo');
            await closeTool(client);
            await openTool(client, 'waste');
            await client.click(`${M} [data-waste-tab="bio"]`);
            await client.waitForSelector(`${M} [data-weekday="${wasteWeekday}"]`);
            assert.deepEqual(await client.$$eval(`${M} [data-weekday]`, (nodes) => nodes.filter((n) => n.getAttribute('aria-pressed') === 'true').map((n) => Number(n.dataset.weekday))), [wasteWeekday]);
            assert.equal(await client.$eval(`${M} [data-waste-interval]`, (el) => el.value), 'w');
            await closeTool(client);
            await openTool(client, 'events');
            await client.click(`${M} [data-ev-view] button[data-value="y"]`);
            assert.deepEqual(await client.$$eval(`${M} [data-event-row]`, (rows) => rows.map((r) => `${r.querySelector('[data-event-date]').value}|${r.querySelector('[data-event-name]').value}`)), [`${eventDay}|${EVENT}`]);
            await closeTool(client);
            await openTool(client, 'names');
            assert.deepEqual(await client.$$eval(`${M} [data-name-row]`, (rows) => rows.map((r) => `${r.querySelector('[data-name-date]').value}|${r.querySelector('[data-name-text]').value}`)), [`${eventDay}|${NAME}`]);
            await closeTool(client);
            // the comment thread
            await client.click(`[data-day-comments="${commentDay}"]`);
            await client.waitForSelector(`${POP}[data-cal-popover="comment"] [data-comment-thread]`);
            assert.match(await client.$eval(`${POP} [data-comment-thread]`, (el) => el.textContent), new RegExp(COMMENT));
            await client.keyboard.press('Escape');
            await client.waitForFunction((selector) => !document.querySelector(selector), { timeout: 3000 }, POP);
            assert.equal((await h.state(client)).status, 'clean', 'looking around changed nothing');
        });

        await check('designer: after a reload every change is in the state and drawn on the step-5 page', async () => {
            await h.reload(page);
            if ((await page.$eval('.cal-ws', (el) => el.dataset.calShownStep)) !== '5') await h.gotoStep(page, 5);
            const s = await assertContent(page, 'designer');
            assert.equal(s.mode, 'designer');
            await assertDrawn(page, 'designer');
            await h.shot(page, 'client-tools-designer');
        });

        await check('designer: Historie has one row per client change as Klient; the Klient filter shows exactly those', async () => {
            const rows = await historyRows(page);
            const client6 = rows.filter((r) => r.who === 'Klient');
            assert.ok(client6.every((r) => r.attr === 'Klient'), 'data-who="Klient"');
            const expected = {
                photo: new RegExp(`^Nahrál fotku — ${week.title}$`),
                caption: new RegExp(`^Upravil popisek — ${week.title}$`),
                waste: /^Upravil svoz — Bio odpad$/,
                event: new RegExp(`^Přidal akci „${EVENT}“ — ${dayLabel(eventDay).replace(/\./g, '\\.')}`),
                name: new RegExp(`^Přidal jméno „?${NAME}“? — ${dayLabel(eventDay).replace(/\./g, '\\.')}`),
                comment: new RegExp(`^Komentář k ${dayLabel(commentDay).replace(/\./g, '\\.')}: ${COMMENT}$`),
            };
            for (const [what, pattern] of Object.entries(expected)) {
                assert.equal(client6.filter((r) => pattern.test(r.what)).length, 1, `${what}: exactly one Klient row matching ${pattern} in ${JSON.stringify(client6.map((r) => r.what))}`);
            }
            assert.equal(client6.length, 6, `six client rows: ${JSON.stringify(client6.map((r) => r.what))}`);
            assert.ok(rows.some((r) => r.who === 'Grafik' && r.what === 'Vytvořil kalendář'), 'the designer\'s own rows are listed too');
            assert.ok(rows.findIndex((r) => r.who === 'Klient') < rows.findIndex((r) => r.who === 'Grafik'), 'the client\'s changes are the newest');

            await page.click('[data-cal-dialog="history"] [data-history-filter] [data-value="Klient"]');
            const onlyClient = await historyRows(page);
            assert.deepEqual(onlyClient.map((r) => r.what).sort(), client6.map((r) => r.what).sort());
            assert.ok(onlyClient.every((r) => r.who === 'Klient' && r.attr === 'Klient'));
            await page.click('[data-cal-dialog="history"] [data-history-filter] [data-value="Grafik"]');
            assert.ok((await historyRows(page)).every((r) => r.who === 'Grafik'));
            await page.click('[data-cal-dialog="history"] [data-history-filter] [data-value="all"]');
            assert.equal((await historyRows(page)).length, rows.length);
            await h.shot(page, 'client-tools-history');
            await closeHistory(page);
        });

        if (client.problems.length) await check('console of the client page is free of errors', async () => assert.deepEqual(client.problems, []));
        await anonymous.close().catch(() => null);
        await h.deleteCalendar(page, id);
        fs.rmSync(tmp, { recursive: true, force: true });
    },
};
