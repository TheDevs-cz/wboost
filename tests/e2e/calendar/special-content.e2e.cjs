/*
 * Content of the cover, the back and the special pages (handoff §10.13; docs/architecture/calendars.md
 * § "Content of the cover, back and special pages").
 *
 * As the designer in step 4: the cover (title, subtitle, line, photo with its crop, layout, logo
 * position), the intro (three paragraphs with bold, signature, portrait), the contacts (six groups
 * with mixed line types, reordered by dragging, a highlighted block, the notes column), the back
 * contacts — the rendered SVG is asserted in mm, then everything is saved, reloaded and asserted
 * again. As the client on `/k/{token}`: „Upravit obsah stránky“ in step 5 edits the intro text and
 * a contact line and uploads the cover photo; the designer reads „Klient“ in Historie; forged
 * client requests that change a layout are refused. An over-full page raises a warning on its
 * thumbnail. Last, the „Náhled k odsouhlasení“ PDF carries the intro and contact texts.
 *
 * The export is an asynchronous job: the script starts a Messenger consumer of its own, like
 * pdf-export.e2e.cjs. The PDF check needs poppler (`pdftotext`) on the host.
 */
const assert = require('assert/strict');
const fs = require('fs');
const os = require('os');
const path = require('path');
const zlib = require('zlib');
const { execFileSync, spawn } = require('child_process');

const ROOT = path.resolve(__dirname, '../../..');
const DIALOG = '[data-cal-dialog="export"]';
const FORBIDDEN = 'Tuto změnu může udělat jen grafik.';

// ---- generated pictures (nothing binary lives in the repository) -----------------------------

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
/** An RGB (or, with `alpha`, RGBA with a transparent border) PNG of one colour. */
function png(width, height, [r, g, b], alpha = false) {
    const bytes = alpha ? 4 : 3;
    const rows = [];
    for (let y = 0; y < height; y++) {
        const row = Buffer.alloc(1 + width * bytes);
        for (let x = 0; x < width; x++) {
            const edge = x < width * 0.2 || x >= width * 0.8 || y < height * 0.1;
            const dark = (Math.floor(x / 40) + Math.floor(y / 40)) % 2 === 0;
            const px = [dark ? r : r >> 1, dark ? g : g >> 1, dark ? b : b >> 1];
            if (alpha) px.push(edge ? 0 : 255);
            row.set(px, 1 + x * bytes);
        }
        rows.push(row);
    }
    const header = Buffer.alloc(13);
    header.writeUInt32BE(width, 0);
    header.writeUInt32BE(height, 4);
    header.set([8, alpha ? 6 : 2, 0, 0, 0], 8);
    return Buffer.concat([Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]), chunk('IHDR', header), chunk('IDAT', zlib.deflateSync(Buffer.concat(rows), { level: 6 })), chunk('IEND', Buffer.alloc(0))]);
}

function tool(name) {
    try {
        execFileSync(name, ['-v'], { stdio: 'ignore' });
        return true;
    } catch (error) {
        return error.code !== 'ENOENT';
    }
}

// ---- page helpers ------------------------------------------------------------------------------

const boot = (page) => page.evaluate(() => JSON.parse(document.querySelector('[data-calendar-workspace-target="bootstrap"]').textContent));
const content = (page, h) => h.cal(page, (c) => c.state().content);

/** Types into a field the way a person does (the field is cleared first). */
async function typeInto(page, selector, value) {
    await page.waitForSelector(selector);
    await page.$eval(selector, (el) => {
        el.scrollIntoView({ block: 'center' });
        el.focus();
        el.select();
    });
    await page.keyboard.press('Backspace');
    if (value) await page.type(selector, value);
}

/** A long text: set at once, announced by the `input` event the field listens to (a paste). */
async function paste(page, selector, value) {
    await page.$eval(
        selector,
        (el, text) => {
            el.focus();
            el.value = text;
            el.dispatchEvent(new Event('input', { bubbles: true }));
        },
        value,
    );
}

async function showPage(page, index) {
    await page.waitForSelector(`[data-page-thumb="${index}"]`);
    await page.$eval(`[data-page-thumb="${index}"]`, (el) => el.click());
    await page.waitForFunction((i) => document.querySelector(`[data-page-thumb="${i}"]`)?.getAttribute('aria-current') === 'true', {}, index);
}

/** The page drawn large (step 4 or step 5), after the pending frame: its `<text>` elements and markup. */
async function stage(page) {
    await page.evaluate(() => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve))));
    return page.evaluate(() => {
        const svg = document.querySelector('.cal-m4c .cal-page-svg svg, .cal-5w .cal-page-svg svg');
        const texts = [...svg.querySelectorAll('text')].map((t) => ({
            text: t.textContent,
            x: Number(t.getAttribute('x')),
            y: Number(t.getAttribute('y')),
            size: Number(t.getAttribute('font-size')),
            anchor: t.getAttribute('text-anchor') || 'start',
            field: t.getAttribute('data-field') || (t.closest('[data-field]') ? t.closest('[data-field]').getAttribute('data-field') : null),
            group: t.closest('[data-group]') ? t.closest('[data-group]').getAttribute('data-group') : null,
            column: t.closest('[data-group]') ? Number(t.closest('[data-group]').getAttribute('data-column')) : null,
            spread: t.getAttribute('word-spacing'),
            bold: [...t.querySelectorAll('tspan')].filter((s) => s.getAttribute('font-weight') === '700' || /bold/i.test(s.getAttribute('font-family') || '')).map((s) => s.textContent.trim()),
        }));
        const image = (selector) => {
            const node = svg.querySelector(selector);
            return node ? { x: Number(node.getAttribute('x')), y: Number(node.getAttribute('y')), w: Number(node.getAttribute('width')), h: Number(node.getAttribute('height')), href: node.getAttribute('href') } : null;
        };
        return { key: svg.getAttribute('data-page'), markup: svg.innerHTML, texts, photo: image('[data-el="page-photo"] image'), logo: image('[data-el="page-logo"] image') };
    });
}

const field = (drawn, name) => drawn.texts.filter((t) => t.field === name);
const near = (a, b, tol, label) => assert.ok(Math.abs(a - b) <= tol, `${label}: expected ${b} ± ${tol}, got ${a}`);

/**
 * Drags an element with the real mouse until it sits directly above another one (SortableJS runs
 * with `forceFallback`). The target is measured again after every move: rows shift as they swap,
 * rows that are still animating are skipped, and the panel scrolls while the pointer is near its
 * edge — so the pointer follows the target until the dragged row has landed above it.
 */
async function dragAbove(page, h, fromSelector, toSelector) {
    await page.$eval(fromSelector, (el) => el.scrollIntoView({ block: 'center' }));
    const from = await (await page.$(fromSelector)).boundingBox();
    const x = from.x + from.width / 2;
    await page.mouse.move(x, from.y + from.height / 2);
    await page.mouse.down();
    await page.mouse.move(x, from.y + from.height / 2 - 8, { steps: 4 });
    const landed = () => page.$eval(toSelector, (el) => Boolean(el.previousElementSibling && el.previousElementSibling.classList.contains('sortable-ghost')));
    for (let i = 0; i < 8 && !(await landed()); i++) {
        const to = await (await page.$(toSelector)).boundingBox();
        await page.mouse.move(to.x + to.width / 2 + (i % 2), to.y + 5, { steps: i === 0 ? 16 : 4 });
        await h.sleep(180);
    }
    assert.ok(await landed(), `${fromSelector} was not dragged above ${toSelector}`);
    await page.mouse.up();
    await h.sleep(150);
}

async function openStep4(page, h, id) {
    await h.openCalendar(page, id);
    if ((await page.$eval('.cal-ws', (el) => el.dataset.calShownStep)) !== '4') await h.gotoStep(page, 4);
    await page.waitForSelector('.cal-b5 [data-page-thumb="0"]');
}

const INTRO = [
    'člověk by to ani nečekal a rok utekl jako voda. Vstoupili jsme do nového roku plní očekávání, co nám přinese či vezme. Pro letošek jsou připraveny nové výzvy a zkoušky k prověření naší trpělivosti.',
    'Abyste si i v novém roce mohli vše dobře naplánovat, připravili jsme pro Vás opět obecní kalendář. Najdete v něm kulturní i sportovní akce, na které se můžete těšit.',
    'Těším se a věřím, že tento kalendář bude Vaším věrným pomocníkem při plánování volného času.',
];
const GROUPS = [
    ['Obecní úřad', [['Po', '7 – 12, 13 – 17'], ['St', '7 – 12, 13 – 17'], ['T:', '558 733 131'], ['E:', 'obec@testov.cz']]],
    ['Knihovna', [['', 'Otevřeno každé pondělí odpoledne'], ['T:', '725 714 176']]],
    ['Pošta', [['Po–Pá', '13 – 16']]],
    ['Sběrný dvůr', [['Čt', '12:15 – 17:30'], ['So', '9:15 – 14:30']]],
    ['Poruchové linky', [['Voda', '800 292 300'], ['Plyn', '1239']]],
    ['Důležitá čísla', [['Policie ČR', '158'], ['Hasiči', '150'], ['Záchranná služba', '155']]],
];

module.exports = {
    name: 'special-content',
    checks: ['§10.13 content of the cover, back and special pages', 'roles', 'warnings', 'PDF'],
    async run({ browser, page, h, check }) {
        const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'wboost-cal-special-'));
        const write = (name, data) => {
            const file = path.join(dir, name);
            fs.writeFileSync(file, data);
            return file;
        };
        const files = {
            cover: write('titulka.png', png(2400, 1200, [60, 130, 90])),
            portrait: write('portret.png', png(600, 800, [70, 90, 150], true)),
            clientCover: write('od-klienta.png', png(1800, 1200, [170, 90, 60])),
        };
        page.on('dialog', (dialog) => dialog.accept().catch(() => null));

        let id = null;
        let pages = [];
        let clientUrl = null;
        const indexOf = (key) => pages.findIndex((p) => p.key === key);
        const refresh = async () => {
            pages = await h.cal(page, (c) => c.pages());
            return pages;
        };
        const sp = (kind, o = {}) => ({ kind, on: true, layout: 0, position: 'front', nextYear: false, name: '', asset: null, text: null, fileName: null, pos: 0, ...o });

        await check('setup: a 7-day calendar with Úvodní slovo, Důležité kontakty and Roční kalendárium switched on', async () => {
            id = await h.createCalendar(page);
            await h.gotoStep(page, 2);
            await page.click('button[data-preset="photoLeft"]');
            await page.waitForSelector('.cal-ws[data-cal-shown-step="3"]');
            await h.gotoStep(page, 4);
            await h.save(page);
            const design = (await h.state(page)).design;
            await h.seed(page, {
                design: { features: { ...design.features, specialPages: true, events: true, waste: true } },
                content: [
                    { c: 'specialPages', id: 'intro', v: sp('intro', { layout: 2, pos: 0 }) },
                    { c: 'specialPages', id: 'contacts', v: sp('contacts', { layout: 1, pos: 1 }) },
                    { c: 'specialPages', id: 'year', v: sp('year', { position: 'back', pos: 2 }) },
                ],
            });
            await openStep4(page, h, id);
            await refresh();
            assert.deepEqual(pages.slice(0, 3).map((p) => p.key), ['cover', 'sp:intro', 'sp:contacts']);
            assert.equal(pages[pages.length - 1].backVariant, 'contacts');
            clientUrl = (await boot(page)).env.clientUrl;
        });
        if (!id) return;

        // ---- cover -------------------------------------------------------------------------------
        await check('cover: an unfilled cover shows the default title and a photo slot — no invented client name', async () => {
            await showPage(page, 0);
            await page.waitForSelector('[data-page-content="cover"] [data-content-field="title"]');
            const drawn = await stage(page);
            assert.deepEqual(field(drawn, 'title').map((t) => t.text), [`Kalendář ${(await h.state(page)).design.year}`]);
            assert.ok(drawn.markup.includes('TITULNÍ FOTOGRAFIE') && !drawn.markup.includes('Název klienta'));
            assert.equal(await page.$eval('[data-content-field="title"]', (el) => el.placeholder), `Kalendář ${(await h.state(page)).design.year}`);
            assert.equal(await page.$$eval('[data-cover-layout]', (nodes) => nodes.length), 4);
            await page.waitForSelector('[data-cover-layout="2"] svg');
            assert.equal(await page.$eval('[data-cover-layout="0"]', (el) => el.getAttribute('aria-checked')), 'true');
        });

        await check('cover: title, subtitle and line are drawn as typed, live; counters follow', async () => {
            await typeInto(page, '[data-content-field="title"]', 'Kalendář obce Testov 2027');
            let drawn = await stage(page);
            assert.deepEqual(field(drawn, 'title').map((t) => t.text), ['Kalendář obce Testov 2027']);
            assert.equal(await page.$eval('[data-page-content="cover"] [data-counter]', (el) => el.textContent), '25 / 80');
            assert.equal(await page.$eval('[data-content-field="title"]', (el) => el.maxLength), 80);
            await typeInto(page, '[data-content-field="subtitle"]', 'Ze života v obci');
            await typeInto(page, '[data-content-field="note"]', 'www.testov.cz');
            drawn = await stage(page);
            const [title] = field(drawn, 'title');
            const [subtitle] = field(drawn, 'subtitle');
            const [note] = field(drawn, 'note');
            assert.equal(subtitle.text, 'Ze života v obci');
            assert.equal(note.text, 'www.testov.cz');
            const { width, height } = (await h.state(page)).design.page;
            assert.ok(title.y > height * 0.7 && subtitle.y > title.y && subtitle.y < height, 'the title band is at the bottom of the page');
            near(title.x, subtitle.x, 0.01, 'title and subtitle share the left edge');
            assert.ok(note.anchor === 'end' && note.x > width * 0.8, 'the line is right-aligned');
            assert.ok(title.size > subtitle.size * 1.8);
            assert.equal(await h.cal(page, (c) => c.state().status), 'dirty');
            // the field keeps focus while the page redraws
            assert.equal(await page.evaluate(() => document.activeElement.dataset.contentField), 'note');
        });

        await check('cover: a photo is uploaded, zoomed and its crop dragged — the page draws exactly that crop', async () => {
            const input = await page.$('[data-content-photo]');
            await input.uploadFile(files.cover);
            await page.waitForFunction(() => window.__calendar.state().content.pageContent.cover?.photo, { timeout: 20000 });
            let photo = (await content(page, h)).pageContent.cover.photo;
            assert.deepEqual([photo.zoom, photo.px, photo.py], [1, 0.5, 0.5], 'a new photo starts centred at 100 %');
            await page.waitForSelector('[data-photo-crop] img[src]');
            // zoom to 180 %
            await page.$eval('[data-photo-zoom]', (el) => {
                el.value = '180';
                el.dispatchEvent(new Event('input', { bubbles: true }));
            });
            // drag the crop preview to the right and down: the picture follows the pointer
            await page.$eval('[data-photo-crop]', (el) => el.scrollIntoView({ block: 'center' }));
            const box = await (await page.$('[data-photo-crop]')).boundingBox();
            await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
            await page.mouse.down();
            await page.mouse.move(box.x + box.width / 2 + box.width * 0.2, box.y + box.height / 2 + box.height * 0.15, { steps: 8 });
            await page.mouse.up();
            photo = (await content(page, h)).pageContent.cover.photo;
            assert.equal(photo.zoom, 1.8);
            assert.ok(photo.px < 0.5 && photo.py < 0.5, `dragging right / down shows more of the left / top: ${photo.px}, ${photo.py}`);
            // the page: cover scale × zoom into the bleed box, positioned by px / py (the maths of week photos)
            const design = (await h.state(page)).design;
            const B = design.print.bleed ? design.print.bleedMm : 0;
            const frame = { x: -B, y: -B, w: design.page.width + 2 * B, h: design.page.height + 2 * B };
            const dh = Math.max(frame.w / 2, frame.h) * photo.zoom;
            const drawn = await stage(page);
            near(drawn.photo.h, dh, 0.02, 'photo height');
            near(drawn.photo.w, dh * 2, 0.02, 'photo width');
            near(drawn.photo.x, frame.x + (frame.w - dh * 2) * photo.px, 0.02, 'photo x');
            near(drawn.photo.y, frame.y + (frame.h - dh) * photo.py, 0.02, 'photo y');
            assert.ok(!drawn.markup.includes('TITULNÍ FOTOGRAFIE'));
            // the preview shows the same crop
            const preview = await page.$eval('[data-photo-crop] img', (el) => ({ w: parseFloat(el.style.width), left: parseFloat(el.style.left) }));
            near(preview.w, ((dh * 2) / frame.w) * 100, 0.2, 'preview width in %');
            near(preview.left, (1 - (dh * 2) / frame.w) * photo.px * 100, 0.2, 'preview left in %');
        });

        await check('cover: the layout thumbnails and the logo position change the page; the logo lies over the photo', async () => {
            await page.$eval('[data-cover-layout="1"]', (el) => el.click());
            let drawn = await stage(page);
            const { width } = (await h.state(page)).design.page;
            assert.ok(drawn.markup.includes('data-layout="1"'));
            assert.ok(field(drawn, 'title')[0].anchor === 'middle', 'framed photo: the title is centred under it');
            near(field(drawn, 'title')[0].x, width / 2, 0.01, 'title x');
            assert.ok(drawn.photo.w < width * 2 && (await content(page, h)).pageSetup.cover.layout === 1);
            await page.$eval('[data-cover-layout="2"]', (el) => el.click());
            drawn = await stage(page);
            assert.equal(drawn.photo, null, '„Bez fotky“ draws no photo');
            assert.equal(await page.$('[data-content-photo]'), null, 'and offers no photo field');
            await page.$eval('[data-cover-layout="0"]', (el) => el.click());
            await page.waitForSelector('[data-content-photo]');
            assert.equal((await content(page, h)).pageSetup.cover, undefined, 'back at the defaults: no stored options');
            // logo: top centre, over the photo
            await page.$eval('[data-setup="logo"] [data-value="tc"]', (el) => el.click());
            await page.$eval('[data-setup="logoSize"] [data-value="l"]', (el) => el.click());
            drawn = await stage(page);
            assert.deepEqual((await content(page, h)).pageSetup.cover, { layout: 0, logo: 'tc', logoSize: 'l' });
            const logoAt = drawn.markup.indexOf('data-el="page-logo"');
            assert.ok(logoAt > drawn.markup.indexOf('data-el="page-photo"') && logoAt > drawn.markup.indexOf('data-el="cover-band"'), 'the logo is painted after the photo and the band');
            const box = /data-el="page-logo"[^>]*><rect x="([\d.]+)" y="([\d.]+)" width="([\d.]+)"/.exec(drawn.markup).slice(1).map(Number);
            near(box[0] + box[2] / 2, width / 2, 0.02, 'logo centre');
            await page.$eval('[data-setup="logo"] [data-value="none"]', (el) => el.click());
            assert.ok(!(await stage(page)).markup.includes('data-el="page-logo"'));
            await page.$eval('[data-setup="logo"] [data-value="tc"]', (el) => el.click());
            await h.shot(page, 'special-cover');
        });

        // ---- intro -------------------------------------------------------------------------------
        await check('intro: heading, salutation, three paragraphs with bold, signature and a portrait', async () => {
            await showPage(page, indexOf('sp:intro'));
            await page.waitForSelector('[data-page-content="sp:intro"] [data-content-field="body"]');
            let drawn = await stage(page);
            assert.deepEqual(field(drawn, 'heading').map((t) => t.text), ['ÚVODNÍ SLOVO'], 'the default heading');
            assert.ok(drawn.markup.includes('PORTRÉT'), 'an unfilled portrait slot');
            await typeInto(page, '[data-content-field="heading"]', 'Slovo starostky');
            await typeInto(page, '[data-content-field="salutation"]', 'Milí občané,');
            // first paragraph typed, the rest pasted; then a word is made bold with the button
            await typeInto(page, '[data-content-field="body"]', INTRO[0].slice(0, 40));
            await paste(page, '[data-content-field="body"]', INTRO.join('\n\n'));
            await page.$eval('[data-content-field="body"]', (el) => {
                const at = el.value.indexOf('nové výzvy a zkoušky');
                el.focus();
                el.setSelectionRange(at, at + 'nové výzvy a zkoušky'.length);
            });
            await page.click('[data-format="bold"]');
            const stored = (await content(page, h)).pageContent['sp:intro'];
            assert.ok(stored.body.includes('**nové výzvy a zkoušky**'));
            assert.equal(stored.body.split('\n\n').length, 3);
            assert.equal(await page.$eval('[data-page-content="sp:intro"] [data-content-field="body"]', (el) => el.maxLength), 4000);
            await typeInto(page, '[data-content-field="signName"]', 'Ing. Jana Nováková');
            await typeInto(page, '[data-content-field="signRole"]', 'starostka obce');
            await (await page.$('[data-content-photo]')).uploadFile(files.portrait);
            await page.waitForFunction(() => window.__calendar.state().content.pageContent['sp:intro']?.photo, { timeout: 20000 });

            drawn = await stage(page);
            const { width, height } = (await h.state(page)).design.page;
            assert.deepEqual(field(drawn, 'heading').map((t) => t.text), ['Slovo starostky']);
            const body = field(drawn, 'body');
            assert.equal(body[0].text, 'Milí občané,');
            assert.ok(!drawn.markup.includes('**'), 'the marks are not printed');
            assert.deepEqual(body.flatMap((t) => t.bold).join(' ').replace(/\s+/g, ' ').trim(), 'nové výzvy a zkoušky');
            const text = body.map((t) => t.text).join(' ').replace(/[\s ]+/g, ' ');
            for (const paragraph of INTRO) assert.ok(text.includes(paragraph.slice(-40).replace(/\s+/g, ' ')), `the end of „${paragraph.slice(0, 20)}…“ is on the page`);
            // layout „Portrét + dva sloupce“: two columns right of the portrait, inside the page
            const columns = [...new Set(body.map((t) => t.x))].sort((a, b) => a - b);
            assert.equal(columns.length, 2);
            assert.ok(drawn.photo && drawn.photo.x + drawn.photo.w <= columns[0] + 40 && columns[0] > width * 0.2);
            assert.ok(body.every((t) => t.y > height * 0.2 && t.y < height && t.x < width));
            // the paragraph gap is larger than the line step
            const ys = [...new Set(body.filter((t) => t.x === columns[0]).map((t) => t.y))];
            const steps = ys.slice(1).map((y, i) => y - ys[i]);
            assert.ok(Math.max(...steps) > Math.min(...steps) * 1.3);
            // signature under the portrait, bold name over the role
            const [name] = field(drawn, 'sign-name');
            const [role] = field(drawn, 'sign-role');
            assert.ok(name.text === 'Ing. Jana Nováková' && role.text === 'starostka obce' && role.y > name.y && name.x < columns[0]);
            assert.ok(/portret|\.png|\.webp/i.test(drawn.photo.href));
        });

        await check('intro: „Do bloku“ spreads the lines; another layout keeps the same content', async () => {
            await page.$eval('[data-setup="align"] [data-value="justify"]', (el) => el.click());
            let drawn = await stage(page);
            const spread = field(drawn, 'body').filter((t) => t.spread !== null);
            assert.ok(spread.length >= 4, `justified lines: ${spread.length}`);
            assert.deepEqual((await content(page, h)).pageSetup['sp:intro'], { align: 'justify' });
            await page.$eval('[data-setup="align"] [data-value="left"]', (el) => el.click());
            assert.equal(field(await stage(page), 'body').filter((t) => t.spread !== null).length, 0);
            // „Jen text“ has no photo: the field goes, the stored photo stays for when the layout comes back
            await h.save(page);
            await h.seed(page, { content: [{ c: 'specialPages', id: 'intro', v: sp('intro', { layout: 1, pos: 0 }) }] });
            await openStep4(page, h, id);
            await refresh();
        });

        // ---- contacts ----------------------------------------------------------------------------
        const groupIds = async () => (await page.$$eval('[data-page-content="sp:contacts"] [data-group]', (nodes) => nodes.map((n) => n.dataset.group)));
        await check('contacts: six groups with mixed lines are added through the panel', async () => {
            await showPage(page, indexOf('sp:contacts'));
            await page.waitForSelector('[data-page-content="sp:contacts"] [data-group-add]');
            assert.ok((await stage(page)).markup.includes('data-el="groups" data-empty="1"'), 'bars while the page is unfilled');
            for (const [heading, lines] of GROUPS) {
                await page.$eval('[data-group-add]', (el) => {
                    el.scrollIntoView({ block: 'center' });
                    el.click();
                });
                // a new group opens with one empty line and the heading focused
                await page.waitForFunction(() => document.activeElement && document.activeElement.hasAttribute('data-group-heading'));
                await page.keyboard.type(heading);
                const gid = await page.evaluate(() => document.activeElement.closest('[data-group]').dataset.group);
                for (let i = 0; i < lines.length; i++) {
                    const [label, value] = lines[i];
                    const row = `.cal-pc [data-group="${gid}"] [data-line="${i}"]`;
                    if (i > 0) {
                        // Enter in the last line continues with a new one
                        await page.focus(`.cal-pc [data-group="${gid}"] [data-line="${i - 1}"] [data-line-value]`);
                        await page.keyboard.press('Enter');
                        await page.waitForSelector(row);
                    }
                    if (label) await page.type(`${row} [data-line-label]`, label);
                    await page.type(`${row} [data-line-value]`, value);
                }
            }
            const groups = Object.values((await content(page, h)).pageGroups).sort((a, b) => a.pos - b.pos);
            assert.deepEqual(groups.map((g) => [g.heading, g.lines.map((l) => [l.label, l.value])]), GROUPS);
            assert.ok(groups.every((g) => g.page === 'sp:contacts' && g.highlight === false));
            assert.equal((await groupIds()).length, 6);
        });

        await check('contacts: the page flows the groups into three balanced columns; labels and values align', async () => {
            const drawn = await stage(page);
            const { width, height } = (await h.state(page)).design.page;
            const headings = field(drawn, 'group-heading');
            assert.deepEqual(headings.map((t) => t.text), GROUPS.map(([heading]) => heading));
            assert.deepEqual([...new Set(headings.map((t) => t.column))], [0, 1, 2], 'every column is used, in order');
            const xs = [...new Set(headings.map((t) => t.x))].sort((a, b) => a - b);
            assert.equal(xs.length, 3);
            assert.ok(xs[0] > 5 && xs[2] < width * 0.75);
            assert.ok(drawn.texts.filter((t) => t.group).every((t) => t.y > height * 0.2 && t.y < height - 3 && t.x < width - 5));
            // Obecní úřad: four values start at one x, right of their labels
            const urad = drawn.texts.filter((t) => t.group === headings[0].group);
            const values = urad.filter((t) => t.field === 'line-value');
            const labels = urad.filter((t) => t.field === 'line-label');
            assert.deepEqual(labels.map((t) => t.text), ['Po', 'St', 'T:', 'E:']);
            assert.ok(values.length === 4 && values.every((t) => Math.abs(t.x - values[0].x) < 0.001) && values[0].x > labels[0].x + 1);
            assert.ok(labels.every((t, i) => Math.abs(t.y - values[i].y) < 0.001), 'label and value share the baseline');
            // a free line starts at the column edge
            const free = drawn.texts.find((t) => t.text === 'Otevřeno každé pondělí odpoledne');
            assert.ok(free && free.field === 'line' && Math.abs(free.x - headings[1].x) < 0.001);
            // the heading is directly followed by its first line
            for (const heading of headings) {
                const lines = drawn.texts.filter((t) => t.group === heading.group && t !== heading);
                assert.ok(lines.length && lines.every((t) => t.column === heading.column) && lines[0].y > heading.y && lines[0].y - heading.y < heading.size * 2);
            }
        });

        await check('contacts: groups and lines are reordered by dragging; a group is highlighted; the notes column', async () => {
            const before = await groupIds();
            // collapse the open groups so the list is short, then drag the last group to the top
            for (const gid of before) {
                if (await page.$(`.cal-pc [data-group="${gid}"] [data-group-toggle][aria-expanded="true"]`)) await page.$eval(`.cal-pc [data-group="${gid}"] [data-group-toggle]`, (el) => el.click());
            }
            await dragAbove(page, h, `.cal-pc [data-group="${before[5]}"] .cal-gh > .cal-grip`, `.cal-pc [data-group="${before[0]}"]`);
            await page.waitForFunction((first) => document.querySelector('[data-page-content="sp:contacts"] [data-group]').dataset.group === first, {}, before[5]);
            const after = await groupIds();
            assert.deepEqual(after, [before[5], ...before.slice(0, 5)]);
            let groups = Object.entries((await content(page, h)).pageGroups).sort((a, b) => a[1].pos - b[1].pos);
            assert.deepEqual(groups.map(([gid]) => gid), after, 'the order is stored as pos');
            let drawn = await stage(page);
            assert.equal(field(drawn, 'group-heading')[0].text, 'Důležitá čísla', 'the page follows');

            // lines of Obecní úřad: the last line (E:) goes first
            const urad = before[0];
            await page.$eval(`.cal-pc [data-group="${urad}"] [data-group-toggle]`, (el) => el.click());
            await page.waitForSelector(`.cal-pc [data-group="${urad}"] [data-line="3"]`);
            await dragAbove(page, h, `.cal-pc [data-group="${urad}"] [data-line="3"] .cal-grip`, `.cal-pc [data-group="${urad}"] [data-line="0"]`);
            await page.waitForFunction((gid) => window.__calendar.state().content.pageGroups[gid].lines[0].label === 'E:', {}, urad);
            assert.deepEqual((await content(page, h)).pageGroups[urad].lines.map((l) => l.label), ['E:', 'Po', 'St', 'T:']);
            assert.deepEqual(await page.$$eval(`.cal-pc [data-group="${urad}"] [data-line-label]`, (nodes) => nodes.map((n) => n.value)), ['E:', 'Po', 'St', 'T:']);
            // remove a line, add it back
            await page.$eval(`.cal-pc [data-group="${urad}"] [data-line="0"] [data-line-remove]`, (el) => el.click());
            await page.waitForFunction((gid) => window.__calendar.state().content.pageGroups[gid].lines.length === 3, {}, urad);
            await page.$eval(`.cal-pc [data-group="${urad}"] [data-line-add]`, (el) => el.click());
            await page.waitForSelector(`.cal-pc [data-group="${urad}"] [data-line="3"]`);
            await page.type(`.cal-pc [data-group="${urad}"] [data-line="3"] [data-line-label]`, 'E:');
            await page.type(`.cal-pc [data-group="${urad}"] [data-line="3"] [data-line-value]`, 'obec@testov.cz');

            // the emergency numbers become the highlighted block
            const numbers = before[5];
            await page.$eval(`.cal-pc [data-group="${numbers}"] [data-group-toggle]`, (el) => el.click());
            await page.waitForSelector(`.cal-pc [data-group="${numbers}"] [data-group-highlight]`);
            await page.$eval(`.cal-pc [data-group="${numbers}"] [data-group-highlight]`, (el) => el.click());
            await page.waitForFunction((gid) => window.__calendar.state().content.pageGroups[gid].highlight === true, {}, numbers);
            drawn = await stage(page);
            const box = /<g data-group="[^"]+" data-column="\d"><rect x="([\d.]+)" y="([\d.]+)" width="([\d.]+)" height="([\d.]+)"[^>]*data-highlight="1"/.exec(drawn.markup).slice(1).map(Number);
            const inside = drawn.texts.filter((t) => t.group === numbers);
            assert.deepEqual(inside.map((t) => t.text), ['Důležitá čísla', 'Policie ČR', '158', 'Hasiči', '150', 'Záchranná služba', '155']);
            assert.ok(inside.every((t) => t.x >= box[0] && t.x <= box[0] + box[2] + 0.01 && t.y > box[1] && t.y < box[1] + box[3]));
            assert.ok(inside.filter((t) => /^\d+$/.test(t.text)).every((t) => t.anchor === 'end'), 'the numbers are right-aligned');

            // notes: ruled lines on the right, their heading in the header band, groups keep clear of them
            await page.$eval('[data-setup-check="notes"]', (el) => el.click());
            await page.waitForSelector('[data-content-field="notesHeading"]');
            drawn = await stage(page);
            const [notes] = field(drawn, 'notes-heading');
            assert.equal(notes.text, 'POZNÁMKY');
            assert.ok((drawn.markup.match(/<g data-el="ruled">(.*?)<\/g>/)[1].match(/<line /g) || []).length >= 8);
            assert.ok(drawn.texts.filter((t) => t.group).every((t) => t.x < notes.x), 'groups stay left of the notes');
            await typeInto(page, '[data-content-field="notesHeading"]', 'Moje poznámky');
            assert.equal(field(await stage(page), 'notes-heading')[0].text, 'Moje poznámky');
            groups = Object.values((await content(page, h)).pageGroups);
            assert.equal(groups.length, 6);
            await h.shot(page, 'special-contacts');
        });

        // ---- back --------------------------------------------------------------------------------
        await check('back page (contacts variant): organisation, address, phone · e-mail · web and the imprint, centred', async () => {
            await showPage(page, pages.length - 1);
            await page.waitForSelector('[data-page-content="back"] [data-content-field="name"]');
            assert.equal(await page.$('[data-content-field="heading"]'), null, 'the year-overview fields are not offered');
            await typeInto(page, '[data-content-field="name"]', 'Obec Testov');
            await typeInto(page, '[data-content-field="address"]', 'Hlavní 1');
            await page.keyboard.press('Enter');
            await page.keyboard.type('739 85 Testov');
            await typeInto(page, '[data-content-field="phone"]', '+420 558 000 111');
            await typeInto(page, '[data-content-field="email"]', 'obec@testov.cz');
            await typeInto(page, '[data-content-field="web"]', 'www.testov.cz');
            await typeInto(page, '[data-content-field="imprint"]', 'Vydala obec Testov v roce 2026');
            const drawn = await stage(page);
            const { width, height } = (await h.state(page)).design.page;
            const texts = drawn.texts.filter((t) => t.text !== 'LOGO');
            assert.deepEqual(texts.map((t) => [t.field, t.text]), [
                ['imprint', 'Vydala obec Testov v roce 2026'],
                ['name', 'Obec Testov'],
                ['address', 'Hlavní 1'],
                ['address', '739 85 Testov'],
                ['reach', '+420 558 000 111  ·  obec@testov.cz  ·  www.testov.cz'],
            ]);
            assert.ok(texts.every((t) => t.anchor === 'middle' && Math.abs(t.x - width / 2) < 0.01));
            assert.ok(texts[0].y > height * 0.9 && texts[1].y < texts[2].y && texts[3].y < texts[4].y);
            assert.equal((await content(page, h)).pageContent.back.address, 'Hlavní 1\n739 85 Testov');
        });

        // ---- save, reload ------------------------------------------------------------------------
        let saved = null;
        await check('everything survives save and reload: stored items, rendered pages, Historie', async () => {
            const before = await content(page, h);
            await h.save(page);
            await openStep4(page, h, id);
            await refresh();
            const after = await content(page, h);
            for (const collection of ['pageContent', 'pageGroups', 'pageSetup']) assert.deepEqual(after[collection], before[collection], collection);
            saved = after;
            assert.deepEqual(Object.keys(after.pageContent).sort(), ['back', 'cover', 'sp:contacts', 'sp:intro']);
            assert.deepEqual(after.pageSetup, { cover: { layout: 0, logo: 'tc', logoSize: 'l' }, 'sp:contacts': { clubLegend: true, wasteLegend: false, notes: true } });

            await showPage(page, 0);
            let drawn = await stage(page);
            assert.deepEqual(field(drawn, 'title').map((t) => t.text), ['Kalendář obce Testov 2027']);
            assert.ok(drawn.photo && drawn.photo.href, 'the cover photo is drawn after the reload');
            assert.equal(await page.$eval('[data-content-field="subtitle"]', (el) => el.value), 'Ze života v obci');
            await showPage(page, indexOf('sp:intro'));
            drawn = await stage(page);
            assert.equal(field(drawn, 'heading')[0].text, 'Slovo starostky');
            assert.ok(field(drawn, 'body').flatMap((t) => t.bold).join(' ').includes('nové výzvy'));
            assert.equal(field(drawn, 'sign-name')[0].anchor, 'end', '„Jen text“: the signature is right-aligned under the text');
            assert.equal(drawn.photo, null);
            assert.equal(await page.$('[data-content-photo]'), null);
            await showPage(page, indexOf('sp:contacts'));
            drawn = await stage(page);
            assert.equal(field(drawn, 'group-heading')[0].text, 'Důležitá čísla');
            assert.ok(drawn.markup.includes('data-highlight="1"') && field(drawn, 'notes-heading')[0].text === 'Moje poznámky');
            await showPage(page, pages.length - 1);
            assert.ok(field(await stage(page), 'name')[0].text === 'Obec Testov');

            const history = await page.evaluate(async () => {
                const b = JSON.parse(document.querySelector('[data-calendar-workspace-target="bootstrap"]').textContent);
                return (await (await fetch(b.env.urls.history, { credentials: 'same-origin', headers: { Accept: 'application/json' } })).json()).entries.map((e) => e.what);
            });
            for (const sentence of ['Upravil texty strany — Titulní strana', 'Upravil vzhled strany — Titulní strana', 'Upravil texty strany — Úvodní slovo', 'Přidal skupinu „Obecní úřad“ — Důležité kontakty', 'Upravil vzhled strany — Důležité kontakty', 'Upravil texty strany — Zadní strana']) {
                assert.ok(history.includes(sentence), `Historie has „${sentence}“ — got ${history.slice(0, 14).join(' | ')}`);
            }
        });

        // ---- step 5, the designer ------------------------------------------------------------------
        await check('step 5 (designer): „Upravit obsah stránky“ opens the same fields over the page; week pages have none', async () => {
            await h.gotoStep(page, 5);
            await page.waitForSelector('.cal-b6');
            await showPage(page, 0);
            await page.waitForSelector('[data-cal-action="page-content"]:not([hidden])');
            await page.click('[data-cal-action="page-content"]');
            await page.waitForSelector('[data-cal-popover="page-content"] [data-content-field="title"]');
            assert.equal(await page.$eval('[data-cal-popover="page-content"] [data-content-field="title"]', (el) => el.value), 'Kalendář obce Testov 2027');
            assert.ok(await page.$('[data-cal-popover="page-content"] [data-cover-layout="0"]'), 'the designer also has the layout here');
            await typeInto(page, '[data-cal-popover="page-content"] [data-content-field="note"]', 'www.testov.cz/kalendar');
            assert.equal(field(await stage(page), 'note')[0].text, 'www.testov.cz/kalendar', 'the large page redraws while typing');
            await h.shot(page, 'special-step5-popover');
            // the popover belongs to its page
            await showPage(page, indexOf('sp:contacts') + 1);
            await page.waitForFunction(() => !document.querySelector('[data-cal-popover="page-content"]'));
            assert.equal(await page.$eval('[data-cal-action="page-content"]', (el) => el.hidden), true, 'a week page has no content to edit here');
            await h.save(page);
        });

        // ---- the client --------------------------------------------------------------------------
        const anonymous = await browser.createBrowserContext();
        const client = await h.newPage(anonymous);
        client.on('dialog', (dialog) => dialog.accept().catch(() => null));
        const clientContent = () => h.cal(client, (c) => c.state().content);
        const openClientContent = async (key) => {
            const index = (await h.cal(client, (c) => c.pages())).findIndex((p) => p.key === key);
            await showPage(client, index);
            await client.waitForSelector('[data-cal-action="page-content"]:not([hidden])');
            await client.$eval('[data-cal-action="page-content"]', (el) => el.click());
            await client.waitForSelector(`[data-cal-popover="page-content"] [data-page-content="${key}"]`);
        };

        await check('client: edits the intro text and a contact line through step 5 and uploads the cover photo — no layout controls', async () => {
            const response = await client.goto(clientUrl, { waitUntil: 'networkidle2' });
            assert.equal(response.status(), 200);
            await h.waitReady(client);
            assert.equal(await h.cal(client, (c) => c.state().mode), 'client');

            await openClientContent('sp:intro');
            for (const selector of ['[data-setup]', '[data-setup-check]', '[data-cover-layout]']) assert.equal(await client.$(`[data-cal-popover="page-content"] ${selector}`), null, `${selector} is the designer's`);
            await typeInto(client, '[data-cal-popover="page-content"] [data-content-field="salutation"]', 'Vážení spoluobčané,');
            await client.$eval('[data-cal-popover="page-content"] [data-content-field="body"]', (el) => {
                el.focus();
                el.setSelectionRange(el.value.length, el.value.length);
            });
            await client.keyboard.type(' Opraveno klientem.');
            let drawn = await stage(client);
            assert.equal(field(drawn, 'body')[0].text, 'Vážení spoluobčané,');
            assert.ok(field(drawn, 'body').map((t) => t.text).join(' ').includes('Opraveno klientem.'), 'the large page redraws for the client too');
            await client.keyboard.press('Escape');
            await client.waitForFunction(() => !document.querySelector('[data-cal-popover="page-content"]'));

            await openClientContent('sp:contacts');
            const groups = Object.entries(await clientContent().then((c) => c.pageGroups)).sort((a, b) => a[1].pos - b[1].pos);
            const [posta] = groups.find(([, g]) => g.heading === 'Pošta');
            await client.$eval(`[data-cal-popover="page-content"] [data-group="${posta}"] [data-group-toggle]`, (el) => el.click());
            await typeInto(client, `[data-cal-popover="page-content"] [data-group="${posta}"] [data-line="0"] [data-line-value]`, '8 – 11, 13 – 16');
            assert.ok((await stage(client)).texts.some((t) => t.text === '8 – 11, 13 – 16'));
            assert.equal(await client.$('[data-cal-popover="page-content"] [data-setup-check="notes"]'), null);
            await client.keyboard.press('Escape');

            await openClientContent('cover');
            assert.equal(await client.$('[data-cal-popover="page-content"] [data-cover-layout]'), null);
            const previous = (await clientContent()).pageContent.cover.photo.asset;
            await (await client.$('[data-cal-popover="page-content"] [data-content-photo]')).uploadFile(files.clientCover);
            await client.waitForFunction((old) => window.__calendar.state().content.pageContent.cover.photo.asset !== old, { timeout: 20000 }, previous);
            await client.keyboard.press('Escape');
            await h.shot(client, 'special-client');

            await h.save(client);
            const stored = await clientContent();
            assert.equal(stored.pageContent['sp:intro'].salutation, 'Vážení spoluobčané,');
            assert.ok(stored.pageContent['sp:intro'].body.endsWith('Opraveno klientem.') && stored.pageContent['sp:intro'].body.includes('**nové výzvy a zkoušky**'));
            assert.equal(stored.pageGroups[posta].lines[0].value, '8 – 11, 13 – 16');
            assert.deepEqual(stored.pageSetup, saved.pageSetup, 'the designer\'s options are untouched');
        });

        await check('designer sees the client\'s texts and „Klient“ entries in Historie', async () => {
            await h.openCalendar(page, id);
            const stored = await content(page, h);
            assert.equal(stored.pageContent['sp:intro'].salutation, 'Vážení spoluobčané,');
            const rows = await page.evaluate(async () => {
                const b = JSON.parse(document.querySelector('[data-calendar-workspace-target="bootstrap"]').textContent);
                return (await (await fetch(b.env.urls.history, { credentials: 'same-origin', headers: { Accept: 'application/json' } })).json()).entries;
            });
            const byClient = rows.filter((r) => r.who === 'Klient').map((r) => r.what);
            for (const sentence of ['Upravil texty strany — Úvodní slovo', 'Upravil skupinu „Pošta“ — Důležité kontakty', 'Vyměnil fotku — Titulní strana']) {
                assert.ok(byClient.includes(sentence), `Klient: „${sentence}“ — got ${JSON.stringify(rows.slice(0, 6))}`);
            }
            // and in the dialog itself
            if ((await page.$eval('.cal-ws', (el) => el.dataset.calShownStep)) !== '5') await h.gotoStep(page, 5);
            await page.$eval('[data-cal-action="history"]', (el) => el.click());
            await page.waitForSelector('[data-cal-dialog="history"].show .cal-hrow', { timeout: 8000 });
            const shown = await page.$$eval('[data-cal-dialog="history"] .cal-hrow', (nodes) => nodes.map((n) => `${n.querySelector('.pill').textContent.trim()}: ${n.querySelector('.cal-hwhat').textContent.trim()}`));
            assert.ok(shown.includes('Klient: Upravil texty strany — Úvodní slovo'), shown.slice(0, 8).join(' | '));
            // a Bootstrap modal ignores a close while it is still fading in
            await h.sleep(450);
            await page.$eval('[data-cal-dialog="history"] [data-cal-action="modal-done"]', (el) => el.click());
            await page.waitForFunction(() => !document.querySelector('[data-cal-dialog="history"]'));
        });

        await check('forged client requests that change a layout, the page list or a page\'s options are refused whole', async () => {
            const before = await h.state(page);
            const forged = async (payload) =>
                client.evaluate(async (body) => {
                    const b = JSON.parse(document.querySelector('[data-calendar-workspace-target="bootstrap"]').textContent);
                    const form = new FormData();
                    form.append('_token', b.env.csrf);
                    form.append('payload', JSON.stringify(body));
                    const response = await fetch(b.env.urls.save, { method: 'POST', body: form, credentials: 'same-origin', headers: { Accept: 'application/json' } });
                    return { status: response.status, json: await response.json().catch(() => null) };
                }, payload);
            const intro = before.content.specialPages.intro;
            const cases = [
                ['layout of a special page', { content: [{ c: 'specialPages', id: 'intro', v: { ...intro, layout: 0 } }] }],
                ['a special page switched off', { content: [{ c: 'specialPages', id: 'contacts', v: { ...before.content.specialPages.contacts, on: false } }] }],
                ['cover layout', { content: [{ c: 'pageSetup', id: 'cover', v: { layout: 2, logo: 'none', logoSize: 's' } }] }],
                ['alignment', { content: [{ c: 'pageSetup', id: 'sp:intro', v: { align: 'justify' } }] }],
                ['options smuggled beside a text', { content: [{ c: 'pageContent', id: 'sp:year', v: { heading: 'Ano', note: '' } }, { c: 'pageSetup', id: 'sp:contacts', v: { clubLegend: false, wasteLegend: false, notes: false } }] }],
            ];
            for (const [name, payload] of cases) {
                const answer = await forged(payload);
                assert.equal(answer.status, 403, `${name}: ${JSON.stringify(answer.json)}`);
                assert.equal(answer.json.error, FORBIDDEN, name);
            }
            // a layout hidden inside a content item is not a known key: refused as invalid
            const smuggled = await forged({ content: [{ c: 'pageContent', id: 'cover', v: { ...before.content.pageContent.cover, layout: 2 } }] });
            assert.equal(smuggled.status, 422);
            const foreign = await forged({ content: [{ c: 'pageContent', id: 'w05', v: { title: 'x', subtitle: '', note: '', photo: null } }] });
            assert.equal(foreign.status, 422, 'no content on a week page');
            await h.reload(page);
            const after = await h.state(page);
            assert.equal(after.revision, before.revision, 'no forged request bumped the revision');
            assert.deepEqual(after.content.pageSetup, before.content.pageSetup);
            assert.deepEqual(after.content.specialPages, before.content.specialPages);
            assert.equal(after.content.pageContent['sp:year'], undefined);
        });

        // ---- overflow → warning ------------------------------------------------------------------
        await check('too many contacts: the type shrinks first, then the page warns — on the thumbnail, in the chip and in the panel', async () => {
            await openStep4(page, h, id);
            await refresh();
            const index = indexOf('sp:contacts');
            assert.equal(await h.cal(page, (c) => c.warnings().length), 0, 'no warning with the six groups');
            const sizeBefore = Number(/data-el="groups" data-size="([\d.]+)"/.exec((await showPage(page, index), await stage(page)).markup)[1]);
            const many = Array.from({ length: 34 }, (_, i) => ({ c: 'pageGroups', id: `over${String(i).padStart(2, '0')}xx`, v: { page: 'sp:contacts', heading: `Další kontakt ${i + 1}`, lines: [{ label: 'Telefon', value: '558 000 000' }, { label: 'E-mail', value: 'urad@testov.cz' }, { label: '', value: 'Úřední hodiny po domluvě' }], highlight: false, pos: 100 + i } }));
            await h.seed(page, { content: many.slice(0, 8) });
            await openStep4(page, h, id);
            await showPage(page, index);
            let drawn = await stage(page);
            assert.ok(Number(/data-el="groups" data-size="([\d.]+)"/.exec(drawn.markup)[1]) < sizeBefore, 'fourteen groups: smaller type');
            assert.equal(await h.cal(page, (c) => c.warnings().length), 0, '…and still no warning');
            await h.seed(page, { content: many.slice(8) });
            await openStep4(page, h, id);
            await showPage(page, index);
            drawn = await stage(page);
            const warnings = await h.cal(page, (c, i) => c.warnings().filter((w) => w.pageIndex === i).map((w) => [w.kind, w.message]), index);
            assert.deepEqual(warnings, [['overflow', 'Kontakty se na stránku nevejdou — uberte řádky, nebo zvolte rozvržení s více sloupci']]);
            const { width, height } = (await h.state(page)).design.page;
            assert.ok(drawn.texts.every((t) => t.y > 0 && t.y < height && t.x > 0 && t.x < width), 'nothing is drawn outside the page');
            await page.waitForSelector(`[data-page-thumb="${index}"][data-warn="1"]`);
            await page.waitForSelector('[data-cal-action="warnings"][data-count="1"]:not([hidden])');
            assert.match(await page.$eval('[data-page-warning="overflow"]', (el) => el.textContent), /Kontakty se na stránku nevejdou/);
            await h.shot(page, 'special-overflow');
            // typing in the panel while the warning is shown keeps the focus
            await typeInto(page, '[data-content-field="heading"]', 'Kontakty');
            assert.equal(await page.evaluate(() => document.activeElement.dataset.contentField), 'heading');
            // four columns hold more: the warning goes with the designer's layout choice
            await h.seed(page, { content: [...many.slice(20).map((op) => ({ ...op, v: null })), { c: 'specialPages', id: 'contacts', v: sp('contacts', { layout: 2, pos: 1 }) }, { c: 'pageSetup', id: 'sp:contacts', v: { clubLegend: true, wasteLegend: false, notes: false } }] });
            await openStep4(page, h, id);
            assert.equal(await h.cal(page, (c) => c.warnings().length), 0, 'four columns without the notes hold twenty-six groups');
            await h.seed(page, { content: many.slice(0, 20).map((op) => ({ ...op, v: null })) });
        });

        // ---- PDF -------------------------------------------------------------------------------
        await check('„Náhled k odsouhlasení“: the PDF carries the cover, intro, contact and back texts as text', async () => {
            if (!tool('pdftotext')) throw new Error('SKIPPED — poppler (pdftotext) is not installed on this host');
            const downloads = fs.mkdtempSync(path.join(os.tmpdir(), 'wb-cal-special-pdf-'));
            const consumer = spawn('docker', ['compose', 'exec', '-T', 'web', 'bin/console', 'messenger:consume', 'async', '--time-limit=600', '-q'], { cwd: ROOT, stdio: 'ignore' });
            try {
                await openStep4(page, h, id);
                const session = await page.createCDPSession();
                await session.send('Browser.setDownloadBehavior', { behavior: 'allowAndName', downloadPath: downloads, eventsEnabled: true });
                const finished = new Promise((resolve, reject) => {
                    const timer = setTimeout(() => reject(new Error('no download finished in time')), 240000);
                    session.on('Browser.downloadProgress', (event) => {
                        if (event.state !== 'completed') return;
                        clearTimeout(timer);
                        resolve(path.join(downloads, event.guid));
                    });
                });
                await page.$eval('[data-cal-action="export-pdf"]', (el) => el.click());
                await page.waitForSelector(`${DIALOG}.show[data-cal-export-state="choose"]`, { timeout: 8000 });
                await h.sleep(350);
                await page.$eval(`${DIALOG} input[data-cal-export-variant="approval"]`, (el) => el.click());
                await page.$eval(`${DIALOG} [data-cal-action="export-start"]`, (el) => el.click());
                await page.waitForSelector(`${DIALOG}[data-cal-export-state="done"]`, { timeout: 240000 });
                const file = await finished;
                if (h.config.shots) {
                    fs.mkdirSync(h.config.shots, { recursive: true });
                    fs.copyFileSync(file, path.join(h.config.shots, 'special-approval.pdf'));
                }
                const run = (args) => execFileSync('pdftotext', args, { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 }).replace(/[\s ]+/g, ' ');
                const pageText = (n) => run(['-f', String(n), '-l', String(n), file, '-']);
                const count = pages.length;
                assert.match(pageText(1), /Kalendář obce Testov 2027/);
                assert.match(pageText(1), /Ze života v obci/);
                const intro = pageText(indexOf('sp:intro') + 1);
                assert.match(intro, /Slovo starostky/);
                assert.match(intro, /Vážení spoluobčané,/);
                assert.match(intro, /nové výzvy a zkoušky/);
                assert.match(intro, /Opraveno klientem\./);
                assert.match(intro, /Ing\. Jana Nováková/);
                assert.ok(!intro.includes('**'), 'no formatting marks in the PDF');
                const contacts = pageText(indexOf('sp:contacts') + 1);
                for (const text of ['Obecní úřad', '558 733 131', 'obec@testov.cz', 'Pošta', '8 – 11, 13 – 16', 'Důležitá čísla', 'Záchranná služba', '155']) assert.ok(contacts.includes(text), `„${text}“ is on the contacts page of the PDF`);
                const back = pageText(count);
                assert.match(back, /Obec Testov/);
                assert.match(back, /\+420 558 000 111/);
                assert.match(back, /Vydala obec Testov v roce 2026/);
                await page.$eval(`${DIALOG} [data-cal-action="modal-done"]`, (el) => el.click());
            } finally {
                try {
                    execFileSync('docker', ['compose', 'exec', '-T', 'web', 'bin/console', 'messenger:stop-workers', '-q'], { cwd: ROOT, stdio: 'ignore' });
                } catch (e) {
                    // it stops at its time limit
                }
                consumer.kill();
            }
        });

        await client.close().catch(() => null);
        await anonymous.close().catch(() => null);
        fs.rmSync(dir, { recursive: true, force: true });
    },
};
