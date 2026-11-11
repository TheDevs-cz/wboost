/*
 * Completion of phase P8a — the gaps the other scripts left open, all through the real UI:
 *
 *   1  „Bez barvy“ swatch (Pozadí kalendária): colour → no colour → Ctrl+Z → no colour, saved as `null`
 *   2  a real `.docx` (zip built here) as a „Text z Wordu“ special page
 *   3  drag-and-drop of three files onto the bulk photo zone (CDP `Input.dispatchDragEvent`; a
 *      synthetic `DragEvent` with real `File` objects only if Chrome ignores the dragged files)
 *   4  a print-size JPEG (3700 × 2500 px, 7–9 MB) through the step-4 photo row and through step 5;
 *      a file just over 10 MB is refused by the page (step 5) and by the server (step 4)
 *   5  step 4 of a 14-day calendar: 29 pages, 27 photo rows, both frames, caption, Styl stránek
 *   6  step 5 of a 14-day calendar at 1440 px: photo, crop, an event in each column, comment, Historie
 *   7  C7 second half: the event icon is put among the row icons with Kalendárium › Ikony › Přidat ikonu
 *
 * Every block creates the calendar it needs, saves, reloads and asserts again; the calendars are
 * deleted at the end. COMPLETION_ONLY=<regex> runs the matching checks only (a development aid).
 */
const assert = require('assert/strict');
const fs = require('fs');
const os = require('os');
const path = require('path');
const zlib = require('zlib');
const e = require('./lib/editor.cjs');

// ---- test files (generated; nothing binary lives in the repository) ---------------------------

const CRC_TABLE = Array.from({ length: 256 }, (_, n) => {
    let c = n;
    for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
    return c >>> 0;
});
function crc32(buffer) {
    if (typeof zlib.crc32 === 'function') return zlib.crc32(buffer) >>> 0;
    let c = 0xffffffff;
    for (const byte of buffer) c = CRC_TABLE[(c ^ byte) & 0xff] ^ (c >>> 8);
    return (c ^ 0xffffffff) >>> 0;
}

/** A zip archive (deflate, UTF-8 names) of `[{ name, data }]` — all a `.docx` needs. */
function zip(entries) {
    const locals = [];
    const central = [];
    let offset = 0;
    for (const entry of entries) {
        const name = Buffer.from(entry.name, 'utf8');
        const data = Buffer.from(entry.data, 'utf8');
        const packed = zlib.deflateRawSync(data, { level: 9 });
        const local = Buffer.alloc(30);
        local.writeUInt32LE(0x04034b50, 0);
        local.writeUInt16LE(20, 4); // version needed
        local.writeUInt16LE(0x0800, 6); // UTF-8 names
        local.writeUInt16LE(8, 8); // deflate
        local.writeUInt16LE(0, 10); // time
        local.writeUInt16LE(0x5b21, 12); // date 2025-09-01
        local.writeUInt32LE(crc32(data), 14);
        local.writeUInt32LE(packed.length, 18);
        local.writeUInt32LE(data.length, 22);
        local.writeUInt16LE(name.length, 26);
        local.writeUInt16LE(0, 28);
        const head = Buffer.alloc(46);
        head.writeUInt32LE(0x02014b50, 0);
        head.writeUInt16LE(20, 4); // made by
        local.copy(head, 6, 4, 30); // version needed … extra length
        head.writeUInt32LE(offset, 42);
        locals.push(local, name, packed);
        central.push(head, name);
        offset += local.length + name.length + packed.length;
    }
    const directory = Buffer.concat(central);
    const end = Buffer.alloc(22);
    end.writeUInt32LE(0x06054b50, 0);
    end.writeUInt16LE(entries.length, 8);
    end.writeUInt16LE(entries.length, 10);
    end.writeUInt32LE(directory.length, 12);
    end.writeUInt32LE(offset, 16);
    return Buffer.concat([...locals, directory, end]);
}

const xmlEsc = (text) => text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

/** A minimal Word document: one `w:p` per paragraph, the second one split into two runs like Word does. */
function docx(paragraphs) {
    const run = (text) => `<w:r><w:t xml:space="preserve">${xmlEsc(text)}</w:t></w:r>`;
    const body = paragraphs
        .map((text, index) => {
            const cut = index === 1 ? text.indexOf(' ', 12) + 1 : 0;
            return `<w:p>${cut ? run(text.slice(0, cut)) + run(text.slice(cut)) : run(text)}</w:p>`;
        })
        .join('');
    return zip([
        {
            name: '[Content_Types].xml',
            data: '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>',
        },
        {
            name: '_rels/.rels',
            data: '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>',
        },
        {
            name: 'word/document.xml',
            data: `<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>${body}<w:sectPr><w:pgSz w:w="11906" w:h="16838"/></w:sectPr></w:body></w:document>`,
        },
    ]);
}

/**
 * A real JPEG drawn in the page (canvas → `toBlob`) and handed to node. `noise` (0–255) is the
 * amplitude of per-pixel noise over a gradient — it is what gives a print-size file its megabytes;
 * with `bytes: [min, max]` the quality is searched until the file size lies in that range.
 */
async function jpeg(page, width, height, { noise = 0, quality = 0.85, bytes = null, tint = 0 } = {}) {
    const base64 = await page.evaluate(
        async (w, hgt, amp, q, range, hue) => {
            const canvas = document.createElement('canvas');
            canvas.width = w;
            canvas.height = hgt;
            const g = canvas.getContext('2d');
            const gradient = g.createLinearGradient(0, 0, w, hgt);
            gradient.addColorStop(0, `hsl(${hue}, 55%, 42%)`);
            gradient.addColorStop(1, `hsl(${(hue + 70) % 360}, 60%, 68%)`);
            g.fillStyle = gradient;
            g.fillRect(0, 0, w, hgt);
            g.fillStyle = 'rgba(255, 255, 255, 0.55)';
            g.fillRect(w * 0.08, hgt * 0.1, w * 0.3, hgt * 0.3);
            if (amp > 0) {
                const image = g.getImageData(0, 0, w, hgt);
                const d = image.data;
                let seed = 0x9e3779b9;
                for (let i = 0; i < d.length; i += 4) {
                    for (let k = 0; k < 3; k++) {
                        seed ^= seed << 13;
                        seed ^= seed >>> 17;
                        seed ^= seed << 5;
                        const v = d[i + k] + (((seed >>> 0) % (2 * amp + 1)) - amp);
                        d[i + k] = v < 0 ? 0 : v > 255 ? 255 : v;
                    }
                }
                g.putImageData(image, 0, 0);
            }
            const encode = (value) => new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', value));
            let blob = await encode(q);
            if (range) {
                let lo = 0.3;
                let hi = 1;
                for (let i = 0; i < 10 && (blob.size < range[0] || blob.size > range[1]); i++) {
                    if (blob.size > range[1]) hi = q;
                    else lo = q;
                    q = (lo + hi) / 2;
                    blob = await encode(q);
                }
            }
            return new Promise((resolve) => {
                const reader = new FileReader();
                reader.onload = () => resolve(String(reader.result).split(',')[1]);
                reader.readAsDataURL(blob);
            });
        },
        width,
        height,
        noise,
        quality,
        bytes,
        tint,
    );
    return Buffer.from(base64, 'base64');
}

// ---- page helpers --------------------------------------------------------------------------

const M = '.cal-tool-modal.show';
const POP = '.cal-pop';
const PRINT_W = 3700;
const PRINT_H = 2500;
const LIMIT = 10000000;
const TOO_LARGE = 'Soubor je větší než 10 MB.';
const PARAGRAPHS = ['Vážení spoluobčané, držíte v rukou nový obecní kalendář.', 'Děkuji všem spolkům, hasičům i dětem ze školy za příspěvky a fotografie.', 'Přeji vám šťastný rok plný zdraví — vaše starostka Žofie Šťastná'];

const exists = async (page, selector) => (await page.$(selector)) !== null;
const text = (page, selector) => page.$eval(selector, (el) => el.textContent.replace(/\s+/g, ' ').trim());
const squash = (value) => String(value).replace(/\s+/g, '');
const near = (actual, expected, tol, label) => assert.ok(Math.abs(actual - expected) <= tol, `${label}: expected ${expected} ± ${tol}, got ${actual}`);
const dayLabel = (date) => `${Number(date.slice(8))}. ${Number(date.slice(5, 7))}.`;
const bootAssets = (page) => page.evaluate(() => JSON.parse(document.querySelector('[data-calendar-workspace-target="bootstrap"]').textContent).assets || {});
const errorToasts = (page) => page.$$eval('.toast-n.err', (nodes) => nodes.map((n) => n.textContent.trim()));
const rectOf = (page, selector) =>
    page.$eval(selector, (el) => {
        const r = el.getBoundingClientRect();
        return { left: r.left, top: r.top, right: r.right, bottom: r.bottom, width: r.width, height: r.height, cx: r.left + r.width / 2, cy: r.top + r.height / 2 };
    });

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

/** Puts a date into a native date field the way the browser's own picker does (value + change). */
async function pickDate(page, selector, value) {
    await page.$eval(
        selector,
        (el, v) => {
            el.value = v;
            el.dispatchEvent(new Event('input', { bubbles: true }));
            el.dispatchEvent(new Event('change', { bubbles: true }));
        },
        value,
    );
}

/** Waits until `fn(window.__calendar, ...args)` is truthy. */
function waitCal(page, fn, args = [], timeout = 20000) {
    return page.waitForFunction(`(${fn.toString()})(window.__calendar, ...${JSON.stringify(args)})`, { timeout });
}

module.exports = {
    name: 'completion',
    checks: ['Bez barvy', 'docx', 'drag-and-drop', 'print-size photo', '14-day step 4', '14-day step 5', 'C7'],
    async run({ page, h, check }) {
        const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'wboost-cal-completion-'));
        const created = [];
        const uploads = {}; // asset id → the server's answer, for every upload of this page
        let dropRoute = null;
        // a failed check may leave unsaved changes behind: never hang on the browser's leave prompt
        page.on('dialog', (dialog) => dialog.accept().catch(() => null));
        page.on('response', async (response) => {
            if (response.request().method() !== 'POST' || !/\/asset$/.test(response.url()) || response.status() !== 200) return;
            const info = await response.json().catch(() => null);
            if (info && info.id) uploads[info.id] = info;
        });

        const only = process.env.COMPLETION_ONLY ? new RegExp(process.env.COMPLETION_ONLY) : null;
        const record = check;
        check = async (id, fn) => {
            if (only && !only.test(id) && !/^cleanup/.test(id)) return null;
            return record(id, async () => {
                await page.mouse.up().catch(() => null);
                await page.keyboard.up('Alt').catch(() => null);
                await fn();
            });
        };

        const write = (name, data) => {
            const file = path.join(tmp, name);
            fs.writeFileSync(file, data);
            return file;
        };
        const state = () => h.state(page);
        const styles = async () => (await state()).design.layout.styles;
        const geometry = (index) => h.cal(page, (c, i) => c.geometry(i === null ? undefined : i), index === undefined ? null : index);
        const weeksOf = () => h.cal(page, (c) => c.pages().filter((p) => p.kind === 'week'));
        const shownStep = () => page.$eval('.cal-ws', (el) => el.dataset.calShownStep);

        /** Creates a calendar through steps 1 and 2 and leaves it saved in step 3. */
        async function createCalendar({ type = 'week', features = [], year = null } = {}) {
            e.leaveFreely(page);
            const id = await h.createCalendar(page);
            created.push(id);
            if (year && (await state()).design.year !== year) {
                await page.click(`[data-year="${year}"]`);
                await waitCal(page, (c, y) => c.state().design.year === y, [year]);
            }
            const on = (await state()).design.features;
            for (const key of features) if (!on[key]) await page.click(`label:has([data-feature="${key}"])`);
            if (type === 'fortnight') {
                await page.click('[data-type="fortnight"]');
                await h.gotoStep(page, 2);
                await page.waitForSelector('[data-cal-action="continue"]');
                await page.click('[data-cal-action="continue"]');
            } else {
                await h.gotoStep(page, 2);
                await page.click('[data-preset="photoLeft"]');
            }
            await page.waitForSelector('.cal-ws[data-cal-shown-step="3"]', { timeout: 10000 });
            await h.save(page);
            return id;
        }

        async function openStep(id, n) {
            await h.openCalendar(page, id);
            if ((await shownStep()) !== String(n)) await h.gotoStep(page, n);
            if (n === 4) await page.waitForSelector('.cal-b5 [data-page-thumb="0"]');
            if (n === 5) await page.waitForSelector('.cal-b6 [data-page-thumb="0"]');
        }

        async function openTool(key) {
            await page.click(`[data-tool="${key}"]`);
            await page.waitForSelector(`${M}[data-tool-modal="${key}"]`, { timeout: 8000 });
            await h.sleep(350);
        }

        async function closeTool() {
            await page.click(`${M} [data-cal-action="modal-done"]`);
            await page.waitForFunction(() => !document.querySelector('.cal-tool-modal'), { timeout: 8000 });
            await h.sleep(150);
        }

        /** Step 4: shows a page in the middle. */
        async function showPage4(index) {
            await page.waitForSelector(`[data-page-thumb="${index}"]`);
            await page.$eval(`[data-page-thumb="${index}"]`, (el) => el.scrollIntoView({ block: 'center' }));
            await page.click(`[data-page-thumb="${index}"]`);
            await page.waitForFunction((i) => document.querySelector(`[data-page-thumb="${i}"]`)?.getAttribute('aria-current') === 'true', {}, index);
            await page.waitForSelector('.cal-m4c svg');
        }

        /** Step 4: markup of the page drawn in the middle (after the pending frame). */
        async function stageSvg() {
            await page.evaluate(() => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve))));
            return page.$eval('.cal-m4c .cal-page-svg', (el) => el.innerHTML);
        }

        /** Step 5: shows a page as the large page. */
        async function showPage5(index) {
            const key = await h.cal(page, (c, i) => c.pages()[i].key, index);
            await page.$eval(`[data-page-thumb="${index}"]`, (el) => el.scrollIntoView({ block: 'center' }));
            await page.click(`[data-page-thumb="${index}"]`);
            await page.waitForSelector(`.cal-5w svg[data-page="${key}"]`, { timeout: 8000 });
        }

        async function popoverGone() {
            await page.waitForFunction((selector) => !document.querySelector(selector), { timeout: 3000 }, POP);
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

        async function selectKalPart(part) {
            const now = await e.selection(page, h);
            if (!now || now.el !== 'cal') await e.selectLayer(page, h, 'cal');
            if (!now || now.el !== 'cal' || now.part !== part) await page.click(`[data-part-row="${part}"]`);
            await page.waitForSelector(`[data-kal-panel="${part}"]`, { timeout: 5000 });
        }

        async function clickInView(selector) {
            await page.$eval(selector, (el) => el.scrollIntoView({ block: 'center' }));
            await page.click(selector);
            await h.sleep(40);
        }

        // =========================================================================================
        // 1 — „Bez barvy“
        // =========================================================================================

        await check('1 „Bez barvy“: colour fills the Kalendárium, Bez barvy stores null and removes the fill, Ctrl+Z brings the colour back, null survives save + reload', async () => {
            const id = await createCalendar();
            const prop = '[data-prop="cal.bg.color"]';
            const COLOR = '#CBC7D1';
            /** Fill of the block background: the plain rect that opens each Kalendárium frame. */
            const fills = () => page.$$eval('.cal-cv svg[data-page] [data-el="cal"]', (frames) => frames.map((frame) => (frame.querySelector(':scope > rect:not([data-part])') || { getAttribute: () => null }).getAttribute('fill')));
            const checked = (color) => page.$eval(`${prop} [data-color="${color}"]`, (el) => el.getAttribute('aria-checked'));
            const selectBlock = async () => {
                await e.selectLayer(page, h, 'cal');
                await page.waitForSelector('[data-kal-panel="block"]', { timeout: 5000 });
                await page.$eval(prop, (el) => el.scrollIntoView({ block: 'center' }));
            };

            await selectBlock();
            assert.equal(await page.$eval(`${prop} [data-color=""]`, (el) => `${el.getAttribute('aria-label')}|${el.getAttribute('aria-checked')}`), 'Bez barvy|true', 'a new calendar has no block background');
            assert.equal((await styles()).cal.bg.color, null);
            assert.deepEqual(await fills(), [null]);

            // a colour: stored, drawn, and kept by a save
            await clickInView(`${prop} [data-color="${COLOR}"]`);
            assert.equal((await styles()).cal.bg.color, COLOR);
            assert.deepEqual(await fills(), [COLOR], 'the page shows the fill');
            assert.deepEqual([await checked(COLOR), await checked('')], ['true', 'false']);
            await h.save(page);
            await h.reload(page);
            assert.equal((await styles()).cal.bg.color, COLOR, 'the colour was saved');
            await selectBlock();
            assert.deepEqual(await fills(), [COLOR]);

            // Bez barvy
            await clickInView(`${prop} [data-color=""]`);
            assert.equal((await styles()).cal.bg.color, null, 'Bez barvy stores null');
            assert.deepEqual(await fills(), [null], 'the fill is gone from the page');
            assert.deepEqual([await checked(''), await checked(COLOR)], ['true', 'false']);
            assert.equal(await page.$eval(`${prop} [data-hex]`, (el) => `${el.value}|${el.placeholder}`), '|bez barvy');
            assert.equal((await state()).status, 'dirty');

            // one Ctrl+Z
            await e.pressMod(page, 'z');
            assert.equal((await styles()).cal.bg.color, COLOR, 'one undo step brings the colour back');
            assert.deepEqual(await fills(), [COLOR]);
            await page.waitForSelector(`${prop} [data-color="${COLOR}"][aria-checked="true"]`, { timeout: 3000 });
            assert.equal(await checked(''), 'false');
            assert.equal((await state()).status, 'clean', 'back at the saved value');

            // and again, for good
            await clickInView(`${prop} [data-color=""]`);
            assert.equal((await styles()).cal.bg.color, null);
            await h.save(page);
            await h.reload(page);
            assert.equal((await styles()).cal.bg.color, null, 'null after save + reload');
            await selectBlock();
            assert.deepEqual(await fills(), [null]);
            assert.equal(await checked(''), 'true');
            assert.equal(await exists(page, `${prop} [data-color][aria-checked="true"]:not([data-color=""])`), false, 'no colour swatch is marked');
        });

        // =========================================================================================
        // 2–4 — one 7-day calendar in step 4
        // =========================================================================================

        let content = null; // id of the 7-day calendar of checks 2–4
        const contentCalendar = async () => {
            if (!content) {
                content = await createCalendar();
                await h.gotoStep(page, 4);
                await page.waitForSelector('.cal-b5 [data-page-thumb="0"]');
            } else {
                await openStep(content, 4);
            }
            return content;
        };

        await check('2 real .docx: „Text z Wordu“ adds a text page with the three paragraphs in order, diacritics intact; kept after save + reload', async () => {
            const id = await contentCalendar();
            const file = write('Slovo starostky.docx', docx(PARAGRAPHS));
            assert.equal(fs.readFileSync(file).subarray(0, 4).toString('latin1'), 'PK\x03\x04');
            const before = await h.cal(page, (c) => c.pages().length);
            assert.equal((await state()).design.features.specialPages, false, 'special pages are off in a new calendar');

            await page.click('[data-content-view="special"]');
            await page.click('[data-cal-action="special-add"]');
            await page.waitForSelector('.cal-spam [data-special-upload="text"]');
            assert.match(await page.$eval('.cal-spam [data-special-upload="text"]', (el) => el.accept), /\.docx/);
            await upload(page, '.cal-spam [data-special-upload="text"]', file);
            await waitCal(page, (c, n) => c.pages().length === n, [before + 1]);
            assert.deepEqual(await errorToasts(page), []);

            const own = Object.entries((await state()).content.specialPages).filter(([, item]) => item.kind === 'text');
            assert.equal(own.length, 1, 'one text page');
            const [textId, item] = own[0];
            assert.deepEqual([item.kind, item.name, item.fileName, item.asset, item.on, item.position], ['text', 'Slovo starostky', 'Slovo starostky.docx', null, true, 'front']);
            assert.ok(item.fileName.endsWith('.docx'));
            assert.equal(item.text, PARAGRAPHS.join('\n'), 'one line per paragraph, split runs joined, diacritics intact');
            assert.equal((await state()).design.features.specialPages, true, 'adding a page switches the feature on');

            const drawn = async () => {
                const svg = await stageSvg();
                assert.match(svg, /data-el="special-text"/);
                const printed = squash(await page.$eval('.cal-m4c [data-el="special-text"]', (el) => el.textContent));
                let from = 0;
                for (const paragraph of PARAGRAPHS) {
                    const at = printed.indexOf(squash(paragraph), from);
                    assert.ok(at >= from, `the page prints „${paragraph}“ in order (got: ${printed.slice(0, 200)})`);
                    from = at + squash(paragraph).length;
                }
                assert.match(svg, /SLOVO STAROSTKY/, 'heading from the file name');
            };
            assert.equal(await text(page, '[data-page-title]'), 'Slovo starostky', 'the new page is shown');
            await drawn();
            const pageOf = (await h.cal(page, (c) => c.pages())).find((p) => p.key === `sp:${textId}`);
            assert.deepEqual([pageOf.title, pageOf.subtitle, pageOf.index], ['Slovo starostky', 'Vlastní strana', 1]);
            await h.shot(page, 'completion-docx');

            await h.save(page);
            await openStep(id, 4);
            assert.equal(await h.cal(page, (c) => c.pages().length), before + 1);
            assert.deepEqual((await state()).content.specialPages[textId], item, 'the item after save + reload');
            await showPage4(1);
            assert.equal(await text(page, '[data-page-title]'), 'Slovo starostky');
            await drawn();
        });

        await check('3 drag-and-drop on the bulk zone: b, a, c dropped → a, b, c on the first three weeks; over state, counter, pixel sizes; kept after save + reload', async () => {
            const id = await contentCalendar();
            const sizes = { 'a.jpg': [1200, 800], 'b.jpg': [1300, 800], 'c.jpg': [1400, 800] };
            const files = {};
            for (const [name, [w, hgt]] of Object.entries(sizes)) files[name] = write(name, await jpeg(page, w, hgt, { tint: w % 360 }));
            const dropped = [files['b.jpg'], files['a.jpg'], files['c.jpg']];
            const weeks = await weeksOf();
            // weeks that already carry a photo (a rerun of one check never does; checks in order never do)
            assert.deepEqual((await state()).content.pagePhotos, {}, 'no photo before the drop');

            await openTool('photos');
            const zone = `${M} label.cal-dpx`;
            assert.equal(await text(page, '[data-photo-count]'), `Fotky: 0 z ${weeks.length}`);
            assert.ok(await exists(page, `${zone} [data-photo-bulk]`), 'the zone wraps the bulk input');
            const box = await rectOf(page, zone);
            const over = () => page.$eval(zone, (el) => el.classList.contains('over'));
            const started = () => h.cal(page, (c) => Object.keys(c.state().content.pagePhotos).length > 0 || !document.querySelector('[data-photo-progress]').hidden);
            assert.equal(await over(), false);

            // route A: a real browser drag of files (CDP)
            const cdp = await page.createCDPSession();
            const data = { items: [], files: dropped, dragOperationsMask: 1 };
            await cdp.send('Input.dispatchDragEvent', { type: 'dragEnter', x: box.cx, y: box.cy, data });
            await cdp.send('Input.dispatchDragEvent', { type: 'dragOver', x: box.cx, y: box.cy, data });
            let sawOver = await over();
            if (sawOver) {
                await cdp.send('Input.dispatchDragEvent', { type: 'drop', x: box.cx, y: box.cy, data });
                await page.waitForFunction(() => Object.keys(window.__calendar.state().content.pagePhotos).length > 0 || !document.querySelector('[data-photo-progress]').hidden, { timeout: 4000 }).catch(() => null);
            }
            if (sawOver && (await started())) {
                dropRoute = 'CDP Input.dispatchDragEvent';
            } else {
                // route B: Chrome ignored the dragged files — real DragEvents carrying File objects
                await cdp.send('Input.dispatchDragEvent', { type: 'dragCancel', x: box.cx, y: box.cy, data }).catch(() => null);
                dropRoute = 'synthetic DragEvent with File objects';
                const payload = dropped.map((file) => ({ name: path.basename(file), base64: fs.readFileSync(file).toString('base64') }));
                await page.evaluate(
                    (selector, list, x, y) => {
                        const transfer = new DataTransfer();
                        for (const entry of list) {
                            const bytes = Uint8Array.from(atob(entry.base64), (ch) => ch.charCodeAt(0));
                            transfer.items.add(new File([bytes], entry.name, { type: 'image/jpeg' }));
                        }
                        window.__dropTransfer = transfer;
                        const target = document.elementFromPoint(x, y);
                        if (!target.closest(selector)) throw new Error('the zone is not under the pointer');
                        for (const type of ['dragenter', 'dragover']) target.dispatchEvent(new DragEvent(type, { bubbles: true, cancelable: true, clientX: x, clientY: y, dataTransfer: transfer }));
                    },
                    zone,
                    payload,
                    box.cx,
                    box.cy,
                );
                sawOver = await over();
                await page.evaluate((x, y) => document.elementFromPoint(x, y).dispatchEvent(new DragEvent('drop', { bubbles: true, cancelable: true, clientX: x, clientY: y, dataTransfer: window.__dropTransfer })), box.cx, box.cy);
            }
            await cdp.detach().catch(() => null);
            console.log(`    (drag-and-drop route: ${dropRoute})`);
            assert.equal(sawOver, true, 'the zone shows its "over" state during dragover');

            await waitCal(page, (c) => Object.keys(c.state().content.pagePhotos).length === 3, [], 30000);
            await page.waitForFunction(() => document.querySelector('[data-photo-progress]').hidden);
            assert.equal(await over(), false, 'the "over" state is gone after the drop');
            assert.deepEqual(await errorToasts(page), []);
            const photos = (await state()).content.pagePhotos;
            assert.deepEqual(Object.keys(photos).sort(), weeks.slice(0, 3).map((w) => w.key).sort(), 'the first three weeks');
            const landed = weeks.slice(0, 3).map((week) => uploads[photos[week.key].asset]);
            assert.deepEqual(landed.map((info) => info && info.name), ['a.jpg', 'b.jpg', 'c.jpg'], 'file-name order from the first week');
            assert.deepEqual(landed.map((info) => [info.width, info.height]), [sizes['a.jpg'], sizes['b.jpg'], sizes['c.jpg']], 'pixel sizes recorded');
            for (const week of weeks.slice(0, 3)) assert.deepEqual([photos[week.key].zoom, photos[week.key].px, photos[week.key].py], [1, 0.5, 0.5]);
            assert.equal(await text(page, '[data-photo-count]'), `Fotky: 3 z ${weeks.length}`);
            assert.equal(await page.$$eval('[data-photo-row] .cal-ft.has img', (nodes) => nodes.length), 3);
            await h.shot(page, 'completion-drop');
            await closeTool();

            await h.save(page);
            await openStep(id, 4);
            const assets = await bootAssets(page);
            const saved = (await state()).content.pagePhotos;
            assert.deepEqual(saved, photos);
            assert.deepEqual(weeks.slice(0, 3).map((week) => [assets[saved[week.key].asset].name, assets[saved[week.key].asset].width, assets[saved[week.key].asset].height]), [['a.jpg', ...sizes['a.jpg']], ['b.jpg', ...sizes['b.jpg']], ['c.jpg', ...sizes['c.jpg']]]);
            await showPage4(weeks[0].index);
            assert.match(await stageSvg(), /data-el="photo"[\s\S]*<image /, 'the photo is drawn on its page');
            await openTool('photos');
            assert.equal(await text(page, '[data-photo-count]'), `Fotky: 3 z ${weeks.length}`);
            await closeTool();
        });

        await check('4 print-size photo: a 3700 px, 7–9 MB JPEG uploads in step 4 and in step 5 without a dpi warning; a file over 10 MB is refused by the page and by the server', async () => {
            const id = await contentCalendar();
            const big = await jpeg(page, PRINT_W, PRINT_H, { noise: 60, quality: 0.9, bytes: [7200000, 8800000] });
            assert.ok(big.length > 6000000 && big.length < LIMIT, `the print photo has ${big.length} bytes`);
            assert.deepEqual([big[0], big[1]], [0xff, 0xd8], 'a JPEG');
            const print = write('tisk-310mm.jpg', big);
            const over = write('prilis-velka.jpg', Buffer.concat([big, Buffer.alloc(LIMIT + 1 - big.length)]));
            assert.equal(fs.statSync(over).size, LIMIT + 1, 'one byte over the limit');
            console.log(`    (print photo: ${PRINT_W} × ${PRINT_H} px, ${(big.length / 1e6).toFixed(2)} MB)`);

            const weeks = await weeksOf();
            const [w4, w5, refused4, refused5] = [weeks[10], weeks[12], weeks[14], weeks[16]];
            const hasPhoto = (c, key) => Boolean(c.state().content.pagePhotos[key]);
            const dpiOf = async (index) => (await h.cal(page, (c) => c.warnings())).filter((w) => w.pageIndex === index);
            const imageDrawn = (root) =>
                page.waitForFunction(
                    (selector) => {
                        const image = document.querySelector(`${selector} [data-el="photo"] image`);
                        return Boolean(image && (image.getAttribute('href') || image.getAttribute('xlink:href')));
                    },
                    { timeout: 8000 },
                    root,
                );

            // (a) the photo row of a week in step 4
            await openTool('photos');
            await page.$eval(`[data-photo-row="${w4.key}"]`, (el) => el.scrollIntoView({ block: 'center' }));
            await upload(page, `[data-photo-row="${w4.key}"] [data-photo-file]`, print);
            await waitCal(page, hasPhoto, [w4.key], 90000);
            assert.deepEqual(await errorToasts(page), [], 'no error toast in step 4');
            const a = (await state()).content.pagePhotos[w4.key];
            assert.deepEqual([a.zoom, a.px, a.py], [1, 0.5, 0.5], 'centred at 100 %');
            assert.deepEqual([uploads[a.asset].width, uploads[a.asset].height, uploads[a.asset].name], [PRINT_W, PRINT_H, 'tisk-310mm.jpg'], 'the real pixel size is recorded');
            assert.ok(uploads[a.asset].previewUrl, 'a print-size photo gets a preview derivative');
            await closeTool();
            await showPage4(w4.index);
            await imageDrawn('.cal-m4c');
            assert.deepEqual(await dpiOf(w4.index), [], 'no warning on the step-4 page');

            // (b) „Nahrát fotku“ of step 5 on another week
            await h.gotoStep(page, 5);
            await showPage5(w5.index);
            assert.equal(await h.cal(page, (c) => c.state().zoom), 1, '100 % zoom');
            await upload(page, '[data-photo-upload]', print);
            await waitCal(page, hasPhoto, [w5.key], 90000);
            assert.deepEqual(await errorToasts(page), [], 'no error toast in step 5');
            const b = (await state()).content.pagePhotos[w5.key];
            assert.notEqual(b.asset, a.asset, 'its own upload');
            assert.deepEqual([b.zoom, b.px, b.py], [1, 0.5, 0.5]);
            assert.deepEqual([uploads[b.asset].width, uploads[b.asset].height], [PRINT_W, PRINT_H]);
            await imageDrawn('.cal-5w');
            assert.equal(await exists(page, '[data-photo-warning]'), false, 'no warning mark on the photo');
            const warnings = await h.cal(page, (c) => c.warnings());
            assert.deepEqual(warnings.filter((w) => w.kind === 'dpi' && [w4.index, w5.index].includes(w.pageIndex)), [], 'no low-quality warning for a print-size photo');
            assert.deepEqual(warnings.filter((w) => [w4.index, w5.index].includes(w.pageIndex)), [], 'no warning at all on the two pages');
            await h.shot(page, 'completion-print-photo');

            // over the limit in step 5: refused by the page before it is sent
            await showPage5(refused5.index);
            let posted = 0;
            const count = (request) => {
                if (request.method() === 'POST' && /\/asset$/.test(request.url())) posted += 1;
            };
            page.on('request', count);
            await upload(page, '[data-photo-upload]', over);
            await h.waitToast(page, 'Soubor je větší než 10 MB\\.');
            assert.deepEqual(await errorToasts(page), [TOO_LARGE]);
            assert.equal(posted, 0, 'step 5 refuses the file without sending it');
            assert.equal((await state()).content.pagePhotos[refused5.key], undefined, 'nothing stored');
            assert.equal(await exists(page, '.cal-5w svg g[data-el="photo"] image'), false);
            await page.waitForFunction(() => !document.querySelector('.toast-n.err'), { timeout: 9000 });

            // over the limit in the step-4 row: the file is sent and the server refuses it
            await h.gotoStep(page, 4);
            await page.waitForSelector('.cal-b5 [data-page-thumb="0"]');
            await openTool('photos');
            await page.$eval(`[data-photo-row="${refused4.key}"]`, (el) => el.scrollIntoView({ block: 'center' }));
            const problems = page.problems.length;
            await upload(page, `[data-photo-row="${refused4.key}"] [data-photo-file]`, over);
            await h.waitToast(page, 'Soubor je větší než 10 MB\\.', 60000);
            page.off('request', count);
            assert.deepEqual(await errorToasts(page), [TOO_LARGE]);
            assert.equal(posted, 1, 'the row sends the file; the refusal is the server\'s');
            assert.equal((await state()).content.pagePhotos[refused4.key], undefined, 'nothing stored');
            assert.equal(await page.$eval(`[data-photo-row="${refused4.key}"] .cal-ft`, (el) => el.classList.contains('has')), false);
            // the refused request is the one console error this script expects (the browser logs every 4xx)
            const added = page.problems.splice(problems);
            assert.ok(added.length <= 1 && added.every((p) => /422/.test(p)), `only the refused upload may log an error: ${added.join(' | ')}`);
            await closeTool();

            await h.save(page);
            await openStep(id, 4);
            const saved = (await state()).content.pagePhotos;
            const assets = await bootAssets(page);
            assert.deepEqual([saved[w4.key], saved[w5.key]], [a, b], 'both photos after save + reload');
            for (const item of [a, b]) {
                assert.deepEqual([assets[item.asset].width, assets[item.asset].height], [PRINT_W, PRINT_H]);
                assert.ok(assets[item.asset].previewUrl);
            }
            assert.equal(saved[refused4.key], undefined);
            assert.equal(saved[refused5.key], undefined);
            assert.deepEqual((await h.cal(page, (c) => c.warnings())).filter((w) => [w4.index, w5.index].includes(w.pageIndex)), [], 'still no warning after the reload');
            await showPage4(w5.index);
            await imageDrawn('.cal-m4c');
        });

        // =========================================================================================
        // 5–6 — a 14-day calendar
        // =========================================================================================

        let fortnight = null;
        const fortnightCalendar = async (step) => {
            if (!fortnight) {
                fortnight = await createCalendar({ type: 'fortnight', features: ['events'], year: 2027 });
                await h.gotoStep(page, step);
            } else {
                await openStep(fortnight, step);
            }
            await page.waitForSelector(`${step === 4 ? '.cal-b5' : '.cal-b6'} [data-page-thumb="0"]`);
            return fortnight;
        };

        await check('5 step 4 of a 14-day calendar: 29 thumbnails, 27 photo rows with two-week ranges, both frames with the photo between, page caption, Styl stránek moves each frame on its own; kept after save + reload', async () => {
            const id = await fortnightCalendar(4);
            const s = await state();
            assert.deepEqual([s.design.type, s.design.year, s.design.preset, s.design.features.events], ['fortnight', 2027, 'twoSided', true]);
            const pages = await h.cal(page, (c) => c.pages());
            const weeks = pages.filter((p) => p.kind === 'week');
            assert.deepEqual([pages.length, weeks.length, pages[0].kind, pages[28].kind], [29, 27, 'cover', 'back']);
            assert.ok(weeks.every((w) => w.week.days.length === 14 && w.week.frames.length === 2));
            const thumbs = await page.$$eval('[data-page-thumb]', (nodes) => nodes.map((n) => n.getAttribute('aria-label')));
            assert.equal(thumbs.length, 29, 'cover, 27 two-week pages, back');
            assert.deepEqual([thumbs[0], thumbs[28]], ['Titulní strana', 'Zadní strana']);
            assert.equal(thumbs[1], `${weeks[0].title}, ${weeks[0].subtitle}`);
            assert.deepEqual([weeks[0].title, weeks[0].subtitle, weeks[1].title, weeks[1].subtitle], ['53. a 1. týden', '28. 12. – 10. 1.', '2. a 3. týden', '11. 1. – 24. 1.']);
            assert.equal(weeks[26].week.days[13] >= '2027-12-31', true, 'the last page covers 31 December');

            // Fotky a popisky: one row per two-week page
            await openTool('photos');
            const rows = await page.$$eval('[data-photo-row]', (nodes) => nodes.map((n) => [n.dataset.photoRow, n.querySelector('.cal-pht b').textContent, n.querySelector('.cal-pht .cap').textContent]));
            assert.equal(rows.length, 27);
            assert.deepEqual(rows, weeks.map((w) => [w.key, w.title, w.subtitle]));
            for (const row of rows) {
                assert.match(row[1], /^\d+\. a \d+\. týden$/, row[1]);
                assert.match(row[2], /^\d+\. \d+\. – \d+\. \d+\.$/, row[2]);
            }
            assert.equal(await text(page, '[data-photo-count]'), 'Fotky: 0 z 27');
            await closeTool();

            // a page in the middle of the year: both frames, the photo between them
            const mid = weeks[13];
            await showPage4(mid.index);
            assert.equal(await text(page, '[data-page-title]'), mid.title);
            const boxes = await h.boxes(page);
            assert.ok(boxes.cal && boxes.cal2, 'boxes cal and cal2');
            const svg = await page.$eval('.cal-m4c .cal-page-svg', (root) => {
                const mm = (el) => {
                    const r = el.getBoundingClientRect();
                    return { left: r.left, right: r.right, dates: [...el.querySelectorAll('[data-date]')].map((g) => g.dataset.date) };
                };
                const photo = root.querySelector('[data-el="photo"]').getBoundingClientRect();
                return { f0: mm(root.querySelector('[data-el="cal"][data-frame="0"]')), f1: mm(root.querySelector('[data-el="cal"][data-frame="1"]')), frames: root.querySelectorAll('[data-el="cal"]').length, photo: { left: photo.left, right: photo.right } };
            });
            assert.equal(svg.frames, 2, 'two Kalendárium frames');
            assert.deepEqual(svg.f0.dates, mid.week.frames[0], 'first week in frame 0');
            assert.deepEqual(svg.f1.dates, mid.week.frames[1], 'second week in frame 1');
            assert.ok(boxes.cal.x + boxes.cal.w <= boxes.photo.x + 0.11 && boxes.photo.x + boxes.photo.w <= boxes.cal2.x + 0.11, `the photo box lies between the frames (${JSON.stringify([boxes.cal, boxes.photo, boxes.cal2])})`);
            const midX = (svg.photo.left + svg.photo.right) / 2;
            assert.ok(svg.f0.left < midX && svg.f0.right <= svg.photo.left + 1.5 && svg.f1.left >= svg.photo.right - 1.5, 'drawn: week 1 · photo · week 2');
            const g = await geometry();
            assert.deepEqual(g.frames.map((f) => f.rows.length), [7, 7]);
            await h.shot(page, 'completion-fortnight-page');

            // page caption
            assert.equal(await page.$eval('.cal-p4b', (el) => el.dataset.pagePanel), mid.key);
            await page.type('[data-page-field="caption"]', 'Rozhledna na Čupku, foto: Šárka Žáčková');
            assert.deepEqual((await state()).content.pageCaptions[mid.key], { text: 'Rozhledna na Čupku, foto: Šárka Žáčková' });
            assert.match(await stageSvg(), /Rozhledna na Čupku, foto: Šárka Žáčková/);
            assert.match(await page.$eval('.cal-m4c [data-el="cap"]', (el) => el.textContent), /Rozhledna na Čupku/);
            await page.keyboard.press('Enter');
            await page.waitForSelector(`[data-page-thumb="${mid.index}"][data-overridden]`);
            assert.equal(await page.$$eval('[data-page-thumb][data-overridden]', (nodes) => nodes.length), 1, 'only that thumbnail is marked');

            // Styl stránek: the editor; each frame is selected and dragged on its own
            await page.click('[data-content-view="style"]');
            await page.waitForSelector('.cal-b5[data-view="style"] .cal-cv');
            await page.waitForFunction(() => window.__calendar.scale() > 0);
            const start = await e.allBoxes(page, h);
            const moves = { cal: { frame: 0, dx: 6, dy: 4 }, cal2: { frame: 1, dx: -8, dy: 3 } };
            let expected = start;
            for (const [key, move] of Object.entries(moves)) {
                const from = e.centre(expected[key]);
                await e.clickMm(page, h, from);
                await h.sleep(60);
                const selection = (await state()).selection;
                assert.deepEqual([selection.el, selection.frame || 0, selection.part], ['cal', move.frame, undefined], `a click selects frame ${move.frame}`);
                assert.equal(await page.$$eval('[data-handle]', (nodes) => nodes.filter((n) => n.offsetParent !== null).length), 8, 'handles of the selected frame');
                e.assertRect(await e.frameRect(page), await page.evaluate((b, k) => {
                    const o = window.__calendar.toClient(b.x, b.y);
                    return { x: o.x, y: o.y, w: b.w * k, h: b.h * k };
                }, expected[key], await h.cal(page, (c) => c.scale())), `selection frame of ${key}`, 1.5);
                await h.dragMm(page, from, { x: from.x + move.dx, y: from.y + move.dy }, { alt: true });
                expected = e.withBoxes(expected, { [key]: { x: expected[key].x + move.dx, y: expected[key].y + move.dy } });
                const now = await e.allBoxes(page, h);
                for (const k of Object.keys(expected)) h.expectBox(now[k], expected[k], 0.25, `${k} after dragging ${key}`);
                assert.equal((await state()).selection.frame || 0, move.frame, 'the dragged frame stays selected');
                await page.keyboard.press('Escape');
            }
            const moved = await e.allBoxes(page, h);
            assert.ok(Math.abs(moved.cal.x - start.cal.x) > 5 && Math.abs(moved.cal2.x - start.cal2.x) > 7, 'both frames moved');
            assert.ok(Math.abs(moved.cal.y - moved.cal2.y) > 0.5, 'each by its own distance');
            await h.shot(page, 'completion-fortnight-style');

            await h.save(page);
            await openStep(id, 4);
            const after = await e.allBoxes(page, h);
            e.assertBoxes(after, moved, 'boxes after save + reload');
            assert.deepEqual((await state()).content.pageCaptions[mid.key], { text: 'Rozhledna na Čupku, foto: Šárka Žáčková' });
            await showPage4(mid.index);
            assert.match(await stageSvg(), /Rozhledna na Čupku/);
            assert.equal(await exists(page, `[data-page-thumb="${mid.index}"][data-overridden]`), true);
            const frames = (await geometry()).frames;
            near(frames[0].box.x, moved.cal.x, 0.11, 'frame 0 on a content page');
            near(frames[1].box.x, moved.cal2.x, 0.11, 'frame 1 on a content page');
        });

        await check('6 step 5 of a 14-day calendar at 1440 px: photo, zoom + drag crop, an event from the left and from the right plus button printed clear of the day texts, event box ≥ 15 mm, comment, Historie; kept after save + reload', async () => {
            const id = await fortnightCalendar(5);
            assert.deepEqual(await page.evaluate(() => [window.innerWidth, window.innerHeight]), [1440, 900]);
            const p = await h.cal(page, (c) => c.page());
            const weeks = await weeksOf();
            const target = weeks.find((w) => w.week.days[0] === '2027-03-08');
            assert.ok(target, 'the page of 8–21 March 2027');
            await showPage5(target.index);
            assert.equal(await h.cal(page, (c) => c.state().zoom), 1);

            // photo: upload, zoom, crop
            const photo = write('nahled.jpg', await jpeg(page, 1600, 1000, { tint: 200 }));
            await upload(page, '[data-photo-upload]', photo);
            await waitCal(page, (c, key) => Boolean(c.state().content.pagePhotos[key]), [target.key]);
            let item = (await state()).content.pagePhotos[target.key];
            assert.deepEqual([item.zoom, item.px, item.py], [1, 0.5, 0.5]);
            assert.deepEqual([uploads[item.asset].width, uploads[item.asset].height], [1600, 1000]);
            assert.ok(await exists(page, '.cal-5w svg g[data-el="photo"] image'), 'the photo is drawn');
            await h.save(page);

            const track = await (await page.$('[data-photo-zoom]')).boundingBox();
            await page.mouse.click(track.x + track.width / 2, track.y + track.height / 2);
            for (let i = 0; i < 120; i++) {
                const value = Number(await page.$eval('[data-photo-zoom]', (el) => el.value));
                if (value === 200) break;
                await page.focus('[data-photo-zoom]');
                await page.keyboard.press(value < 200 ? 'ArrowRight' : 'ArrowLeft');
            }
            assert.equal((await state()).content.pagePhotos[target.key].zoom, 2);
            const box = (await h.boxes(page)).photo;
            const dh = Math.max(box.w / 1.6, box.h) * 2;
            const dw = dh * 1.6;
            const centre = { x: box.x + box.w / 2, y: box.y + box.h / 2 };
            await h.dragMm(page, centre, { x: centre.x + 12, y: centre.y + 7 });
            item = (await state()).content.pagePhotos[target.key];
            near(item.px, 0.5 + 12 / (box.w - dw), 0.01, 'px after the drag');
            near(item.py, 0.5 + 7 / (box.h - dh), 0.01, 'py after the drag');
            const crop = item;
            await h.save(page);

            // plus buttons: left frame on the left, right frame on the right
            const frames = (await geometry()).frames;
            assert.equal(frames.length, 2);
            assert.equal(await exists(page, '[data-day-row]'), false, 'at 1440 px every day has its own plus button');
            assert.equal(await page.$$eval('[data-day-plus]', (nodes) => nodes.length), 14);
            const leftDate = frames[0].rows[2].date;
            const rightDate = frames[1].rows[4].date;
            assert.deepEqual([leftDate, rightDate], [target.week.days[2], target.week.days[11]]);
            const leftEdge = await h.cal(page, (c, x) => c.toClient(x, 0), Math.max(frames[0].box.x, -p.bleedMm));
            const rightEdge = await h.cal(page, (c, x) => c.toClient(x, 0), Math.min(frames[1].box.x + frames[1].box.w, p.width + p.bleedMm));
            assert.ok((await rectOf(page, `[data-day-plus="${leftDate}"]`)).right <= leftEdge.x + 0.5, 'the plus of the first week sits left of its column');
            assert.ok((await rectOf(page, `[data-day-plus="${rightDate}"]`)).left >= rightEdge.x - 0.5, 'the plus of the second week sits right of its column');

            const names = { [leftDate]: 'Hasičský ples', [rightDate]: 'Farmářský trh' };
            for (const [date, name] of Object.entries(names)) {
                await page.click(`[data-day-plus="${date}"]`);
                await page.waitForSelector(`${POP}[data-cal-popover="plus"]`, { timeout: 3000 });
                assert.equal(await page.$eval(`${POP} .cal-pop-t`, (el) => el.textContent), `Přidat — ${dayLabel(date)} 2027`);
                await page.click(`${POP} [data-plus-option="event"]`);
                await page.waitForSelector(`${POP}[data-cal-popover="event"]`);
                await page.keyboard.type(name);
                await page.keyboard.press('Enter');
                await popoverGone();
                await h.waitToast(page, `Akce přidána — ${dayLabel(date).replace(/\./g, '\\.')}`);
                // the toast lies in the lower right corner, where the next popover opens
                await page.waitForFunction(() => !document.querySelector('.toast-n'), { timeout: 8000 });
            }
            assert.deepEqual(Object.values((await state()).content.events).map((ev) => [ev.date, ev.name, ev.club]).sort(), [[leftDate, 'Hasičský ples', null], [rightDate, 'Farmářský trh', null]].sort());

            // both print in their rows, inside the page and clear of the day number / weekday
            const printed = async () => {
                const trim = { a: await h.cal(page, (c) => c.toClient(0, 0)), b: await h.cal(page, (c, w, hgt) => c.toClient(w, hgt), p.width, p.height) };
                for (const [date, name] of Object.entries(names)) {
                    const row = await page.$eval(
                        `.cal-5w svg g[data-date="${date}"]`,
                        (gEl) => {
                            const box = (el) => {
                                const r = el.getBoundingClientRect();
                                return { part: el.dataset.part, text: el.textContent, left: r.left, top: r.top, right: r.right, bottom: r.bottom };
                            };
                            return { frame: gEl.closest('[data-frame]').dataset.frame, ev: [...gEl.querySelectorAll('text[data-part="ev"]')].map(box), other: [...gEl.querySelectorAll('text[data-part]')].filter((el) => /^(num|day)/.test(el.dataset.part)).map(box) };
                        },
                    );
                    assert.equal(row.frame, date === leftDate ? '0' : '1');
                    assert.ok(row.ev.length >= 1, `${date}: the event is printed in its row`);
                    assert.equal(row.ev.map((t) => t.text).join(' ').replace(/\s+/g, ' ').trim(), name, `${date}: the whole name is printed`);
                    assert.ok(row.other.some((t) => /^num/.test(t.part)) && row.other.some((t) => t.part === 'day'), `${date}: day number and weekday are drawn (${row.other.map((t) => t.part)})`);
                    for (const ev of row.ev) {
                        assert.ok(ev.left >= trim.a.x - 0.5 && ev.right <= trim.b.x + 0.5 && ev.top >= trim.a.y - 0.5 && ev.bottom <= trim.b.y + 0.5, `${date}: „${ev.text}“ lies inside the page (${JSON.stringify(ev)})`);
                        for (const o of row.other) {
                            const apart = ev.right <= o.left + 0.5 || ev.left >= o.right - 0.5 || ev.bottom <= o.top + 0.5 || ev.top >= o.bottom - 0.5;
                            assert.ok(apart, `${date}: „${ev.text}“ overlaps the ${o.part} text „${o.text}“ (${JSON.stringify([ev, o])})`);
                        }
                    }
                }
                const g = await geometry();
                for (const frame of g.frames) {
                    for (const row of frame.rows) {
                        assert.ok(row.eventBox, `${row.date}: the row has an event box`);
                        assert.ok(row.eventBox.w >= 15, `${row.date}: the event box is ${row.eventBox.w.toFixed(2)} mm wide (at least 15 mm)`);
                        assert.equal(Boolean(row.eventOverflow), false, `${row.date}: no event overflow`);
                    }
                }
            };
            await printed();

            // a comment on a day of the second week
            await page.click(`[data-day-plus="${rightDate}"]`);
            await page.waitForSelector(`${POP}[data-cal-popover="plus"]`, { timeout: 3000 });
            await page.click(`${POP} [data-plus-option="comment"]`);
            await page.waitForSelector(`${POP}[data-cal-popover="comment"]`);
            await page.keyboard.type('Trh začíná už v 8 hodin');
            await page.click(`${POP} [data-popover-confirm]`);
            await popoverGone();
            assert.equal(await page.$eval(`[data-day-comments="${rightDate}"]`, (el) => el.textContent), '1');
            await h.shot(page, 'completion-fortnight-preview');

            await h.save(page);
            await h.reload(page);
            assert.equal(await shownStep(), '5');
            await showPage5(target.index);
            const saved = (await state()).content;
            assert.deepEqual(saved.pagePhotos[target.key], crop, 'photo, zoom and crop');
            assert.equal(Number(await page.$eval('[data-photo-zoom]', (el) => el.value)), 200);
            assert.deepEqual(Object.values(saved.events).map((ev) => [ev.date, ev.name]).sort(), [[leftDate, 'Hasičský ples'], [rightDate, 'Farmářský trh']].sort());
            const comments = Object.values(saved.comments);
            assert.deepEqual(comments.map((c) => [c.date, c.text, c.author]), [[rightDate, 'Trh začíná už v 8 hodin', 'designer']]);
            assert.equal(await page.$eval(`[data-day-comments="${rightDate}"]`, (el) => el.textContent), '1');
            await printed();

            const what = (await history()).map((r) => r.what);
            for (const sentence of [`Nahrál fotku — ${target.title}`, `Posunul fotku — ${target.title}`, `Přidal akci „Hasičský ples“ — ${dayLabel(leftDate)}`, `Přidal akci „Farmářský trh“ — ${dayLabel(rightDate)}`, `Komentář k ${dayLabel(rightDate)}: Trh začíná už v 8 hodin`]) {
                assert.ok(what.includes(sentence), `Historie lists „${sentence}“ (${what.join(' | ')})`);
            }
        });

        // =========================================================================================
        // 7 — C7 through the Ikony panel
        // =========================================================================================

        await check('7 C7 through the UI: club ZŠ + event in Akce a spolky, Kalendárium › Ikony › Přidat ikonu › Akce → the day shows the event icon and „ZŠ · název“; kept after save + reload', async () => {
            const id = await createCalendar({ features: ['events'] });
            const Y = (await state()).design.year;
            const date = `${Y}-06-01`;
            const plain = `${Y}-06-03`;
            assert.equal((await styles()).icons.order.includes('ev'), false, 'the event icon is not among the row icons by default');

            // step 4: the club and its event
            await h.gotoStep(page, 4);
            await page.waitForSelector('[data-tool="events"]');
            await openTool('events');
            await page.click(`${M} [data-club-add]`);
            await page.waitForSelector(`${M} [data-club-code]`);
            await typeInto(page, `${M} [data-club-code]`, 'ZŠ');
            await typeInto(page, `${M} [data-club-name]`, 'Základní škola');
            await page.click(`${M} [data-event-add]`);
            await page.waitForSelector(`${M} [data-event-row]`);
            await page.keyboard.type('Den dětí');
            await pickDate(page, `${M} [data-event-date]`, date);
            const s = await state();
            const clubId = Object.keys(s.content.clubs)[0];
            assert.deepEqual(s.content.clubs[clubId], { code: 'ZŠ', name: 'Základní škola', pos: 0 });
            assert.deepEqual(Object.values(s.content.events), [{ date, name: 'Den dětí', club: clubId, icon: null }]);
            await closeTool();

            const index = (await h.cal(page, (c) => c.pages())).find((pg) => pg.kind === 'week' && pg.week.days.includes(date)).index;
            const rowOf = async (day) => {
                const row = (await geometry(index)).frames[0].rows.find((r) => r.date === day);
                return {
                    info: row.info.icons.filter((i) => i.category === 'ev').length,
                    drawn: row.items.filter((i) => i.type === 'icon' && i.ref.category === 'ev').length,
                    icons: row.items.filter((i) => i.type === 'icon').length,
                    events: row.info.events.map((ev) => ev.text),
                };
            };
            assert.deepEqual(await rowOf(date), { info: 0, drawn: 0, icons: (await rowOf(date)).icons, events: ['ZŠ · Den dětí'] }, 'before: the text prints, no event icon');

            // step 3: Kalendárium › Ikony › Přidat ikonu › Akce
            await h.gotoStep(page, 3);
            await page.waitForFunction(() => window.__calendar.scale() > 0);
            await selectKalPart('icon');
            assert.equal(await page.$eval('.cal-props-kal [data-props-title]', (el) => el.textContent), 'Ikony');
            const listed = () => page.$$eval('[data-icon-order]', (nodes) => nodes.map((n) => n.dataset.iconOrder));
            const before = (await styles()).icons.order;
            assert.equal((await listed()).includes('ev'), false, 'Akce is not listed among the row icons yet');
            await clickInView('[data-cal-action="icon-add"]');
            await page.waitForSelector('[data-icon-add="ev"]');
            assert.equal(await page.$eval('[data-icon-add="ev"]', (el) => el.textContent.trim()), 'Akce');
            await page.click('[data-icon-add="ev"]');
            const order = (await styles()).icons.order;
            assert.deepEqual(order, [...before, 'ev'], 'styles.icons.order now ends with the event category');
            assert.equal((await listed()).includes('ev'), true, 'Akce is listed in Ikony v řádku');
            assert.equal((await state()).canUndo, true, 'adding the icon is an undo step');

            const withIcon = await rowOf(date);
            assert.deepEqual([withIcon.info, withIcon.drawn, withIcon.events], [1, 1, ['ZŠ · Den dětí']], 'the row of the event has the event icon');
            const without = await rowOf(plain);
            assert.deepEqual([without.info, without.drawn, without.events], [0, 0, []], 'a day without an event has none');

            // on the page itself (step 5)
            const onPage = async () => {
                if ((await shownStep()) !== '5') await h.gotoStep(page, 5);
                await showPage5(index);
                const rows = await page.$$eval('.cal-5w svg g[data-date]', (nodes) => Object.fromEntries(nodes.map((n) => [n.dataset.date, { icons: n.querySelectorAll(':scope > g[data-part]').length, text: n.textContent }])));
                const expected = await rowOf(date);
                assert.equal(expected.drawn, 1);
                assert.equal(rows[date].icons, expected.icons, 'every icon of the row is drawn');
                assert.ok(rows[date].icons >= 1, 'an icon is drawn in the row of the event');
                assert.equal(rows[date].icons - rows[plain].icons, expected.icons - (await rowOf(plain)).icons);
                assert.match(rows[date].text, /ZŠ · Den dětí/, 'the text prints „ZŠ · Den dětí“');
                assert.doesNotMatch(rows[plain].text, /Den dětí/);
            };
            await onPage();
            const all = await h.cal(page, (c) => {
                const out = [];
                c.pages().forEach((pg, i) => {
                    if (pg.kind !== 'week') return;
                    for (const frame of c.geometry(i).frames) for (const row of frame.rows) if (row.info.icons.some((icon) => icon.category === 'ev')) out.push(row.date);
                });
                return out;
            });
            assert.deepEqual(all, [date], 'the event icon shows on the day of the event only');
            await h.shot(page, 'completion-c7');

            await h.save(page);
            await h.reload(page);
            assert.deepEqual((await styles()).icons.order, order, 'the order after save + reload');
            const again = await rowOf(date);
            assert.deepEqual([again.info, again.drawn, again.events], [1, 1, ['ZŠ · Den dětí']]);
            await onPage();
            assert.ok(id);
        });

        await check('cleanup', async () => {
            fs.rmSync(tmp, { recursive: true, force: true });
            for (const id of created) {
                await h.openCalendar(page, id).catch(() => null);
                await h.gotoList(page);
                await h.deleteCalendar(page, id);
            }
            await h.gotoList(page);
            assert.equal(await page.$$eval('.cal-card[data-calendar-id]', (nodes) => nodes.length), 0, 'no calendar of this script is left');
        });
    },
};
