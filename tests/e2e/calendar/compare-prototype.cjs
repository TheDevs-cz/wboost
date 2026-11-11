#!/usr/bin/env node
/*
 * Prototype comparison (docs/calendar/architecture.md §F "P8a", handoff §9 "Compare screenshots of
 * the sample page against the prototype for the default layouts").
 *
 *   node tests/e2e/calendar/compare-prototype.cjs            # writes docs/calendar/report-assets/
 *   OUT=/tmp/cmp node tests/e2e/calendar/compare-prototype.cjs
 *
 * For Fotka vlevo and Fotka vpravo it
 *   1. opens docs/calendar/prototype/wboost-prototyp.html in its own browser context, creates a
 *      project and a calendar there by clicking, chooses the layout, saves, and reads the default
 *      layout from `localStorage['wb-cal-<pid>']`;
 *   2. creates the same calendar in the running app through the UI;
 *   3. compares every default box as a fraction of the page (both clipped to the trim), tolerance
 *      1 % of the page width / height;
 *   4. screenshots both sample pages (step 3, nothing selected), puts them side by side into
 *      `sample-<preset>.png` (≤ 1400 px wide) and computes the mean pixel difference on a canvas.
 * Exit code 1 when a box is off by more than the tolerance. Not an `*.e2e.cjs` script on purpose:
 * it writes files and its pixel number is a record, not a gate.
 *
 * The prototype is untrusted data: it runs only in its own incognito context with every request
 * blocked except the Google Fonts stylesheet / font files it links (the same Outfit the app loads —
 * without it the two pages would be compared in different typefaces). Nothing is read from it but
 * the layout numbers and the pixels.
 */
const fs = require('fs');
const path = require('path');
const h = require('./lib/harness.cjs');
const ed = require('./lib/editor.cjs');

const ROOT = path.resolve(__dirname, '../../..');
const PROTOTYPE = path.join(ROOT, 'docs/calendar/prototype/wboost-prototyp.html');
const OUT = process.env.OUT || path.join(ROOT, 'docs/calendar/report-assets');
const TOL = 0.01;
const SHOT_W = 692; // two of them + gutter = 1400 px

// prototype units (map §3): page 1000 × 468, 1 unit = 0.21 mm in its own (wrong) scale
const PW = 1000;
const PH = 468;
const KEYS = ['photo', 'cap', 'logo', 'head', 'month', 'cal', 'wk'];

const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

/** A box clipped to `[0, w] × [0, h]`, as fractions of the page. */
function fractions(box, w, hgt) {
    const x1 = Math.max(0, box.x);
    const y1 = Math.max(0, box.y);
    const x2 = Math.min(w, box.x + box.w);
    const y2 = Math.min(hgt, box.y + box.h);
    return { x: x1 / w, y: y1 / hgt, w: (x2 - x1) / w, h: (y2 - y1) / hgt };
}

async function prototypeLayouts(browser) {
    const context = await browser.createBrowserContext();
    const page = await context.newPage();
    await page.setViewport({ width: 1440, height: 900, deviceScaleFactor: 2 });
    await page.setRequestInterception(true);
    page.on('request', (request) => {
        const url = request.url();
        if (/^(file|data|blob):/.test(url) || /^https:\/\/fonts\.(googleapis|gstatic)\.com\//.test(url)) request.continue();
        else request.abort();
    });
    page.on('dialog', (dialog) => dialog.dismiss().catch(() => null));
    await page.goto(`file://${PROTOTYPE}`, { waitUntil: 'load' });
    await wait(1200);
    const clickVisible = (selector) =>
        page.evaluate((q) => {
            const el = [...document.querySelectorAll(q)].find((n) => n.getClientRects().length);
            if (!el) throw new Error(`prototype: nothing visible matches ${q}`);
            el.click();
        }, selector);

    await clickVisible('[data-action="new"]');
    await wait(300);
    await page.type('#newName', 'Obec Testov');
    await clickVisible('#newSubmit');
    await wait(600);
    const pid = await page.evaluate(() => document.querySelector('a[href^="#projekt-"]').getAttribute('href').replace('#projekt-', '').split('/')[0]);

    const out = {};
    for (const [preset, key] of [['photoLeft', 'left'], ['photoRight', 'right']]) {
        await page.evaluate((p) => {
            window.location.hash = `projekt-${p}/kalendare`;
        }, pid);
        await wait(500);
        await clickVisible('[data-calnew]');
        await wait(500);
        // like for like: a new calendar of the app has no demo content, so the prototype's demo features go off
        await page.evaluate(() => {
            for (const key of ['ev.on', 'waste.on', 'shift.on', 'time.on', 'season.on', 'pages.on', 'qr.on']) {
                const el = document.querySelector(`[data-clg="${key}"]`);
                const box = el && (el.matches('input') ? el : el.querySelector('input'));
                if (box && box.checked) box.click();
            }
        });
        await wait(300);
        await clickVisible('[data-clstep="2"]');
        await wait(300);
        await clickVisible(`[data-clph="${key}"]`);
        await wait(500);
        await clickVisible('[data-clsave]');
        await wait(300);
        const stored = await page.evaluate((p) => JSON.parse(window.localStorage.getItem(`wb-cal-${p}`) || '[]')[0], pid);
        if (!stored || !stored.L || stored.photo !== key) throw new Error(`prototype: no stored ${key} layout`);
        await fontsReady(page);
        // the prototype's stage is wider than its scroll container: a copy of the sample page is laid over the
        // window at a fixed width and photographed there
        await page.evaluate((width) => {
            const svg = [...document.querySelectorAll('svg')].filter((n) => n.querySelector('[data-el="photo"]') && n.getClientRects().length).sort((x, y) => y.getBoundingClientRect().width - x.getBoundingClientRect().width)[0];
            const copy = svg.cloneNode(true);
            copy.removeAttribute('style');
            copy.setAttribute('width', String(width));
            copy.removeAttribute('height');
            const holder = document.createElement('div');
            holder.id = 'p8a-compare';
            holder.style.cssText = `position:fixed;left:0;top:0;z-index:2147483647;background:#fff;width:${width}px;line-height:0`;
            holder.append(copy);
            document.body.append(holder);
        }, SHOT_W);
        const shot = await (await page.$('#p8a-compare')).screenshot({ type: 'png' });
        await page.evaluate(() => document.getElementById('p8a-compare').remove());
        const viewBox = await page.evaluate(() => [...document.querySelectorAll('svg')].find((n) => n.querySelector('[data-el="photo"]') && n.getClientRects().length).getAttribute('viewBox').split(/[ ,]+/).map(Number));
        out[preset] = { boxes: stored.L.e, order: stored.L.z, year: stored.year, shot, viewBox };
    }
    await context.close();
    return out;
}

/** Bleed of the prototype's sample page in its units (its viewBox starts at −B). */
const protoBleed = (p) => -p.viewBox[0];

async function fontsReady(page) {
    await page.evaluate(() => document.fonts.ready);
    await wait(200);
}

async function appLayouts(browser) {
    const page = await h.newPage(browser, { width: 1440, height: 900, deviceScaleFactor: 2 });
    await h.login(page);
    const out = {};
    for (const preset of ['photoLeft', 'photoRight']) {
        const { id, design } = await ed.newCalendar(page, h, { preset });
        await fontsReady(page);
        const el = await page.$('.cal-cv .cal-page-svg svg');
        const shot = await el.screenshot({ type: 'png' });
        out[preset] = { boxes: design.layout.boxes, order: design.layout.order, page: design.page, year: design.year, shot, bleedMm: design.print.bleed ? design.print.bleedMm : 0 };
        await h.deleteCalendar(page, id);
    }
    await page.close();
    return out;
}

/** Side-by-side picture + mean absolute pixel difference (0–100 %), computed on a canvas in a blank page. */
async function compose(browser, left, right, labelLeft, labelRight, cropLeft, cropRight) {
    const page = await browser.newPage();
    const result = await page.evaluate(
        async (a, b, la, lb, width, cropA, cropB) => {
            const load = (data) =>
                new Promise((resolve, reject) => {
                    const img = new Image();
                    img.onload = () => resolve(img);
                    img.onerror = reject;
                    img.src = `data:image/png;base64,${data}`;
                });
            const [ia, ib] = await Promise.all([load(a), load(b)]);
            // only the trim area is compared: the prototype's bleed ring is wider in proportion (its 5 mm are 23.8 of
            // 1000 units), so the full pictures have different aspect ratios
            const height = Math.round((width * (ia.height * (1 - 2 * cropA.y))) / (ia.width * (1 - 2 * cropA.x)));
            const draw = (img, crop) => {
                const c = document.createElement('canvas');
                c.width = width;
                c.height = height;
                const g = c.getContext('2d');
                g.imageSmoothingQuality = 'high';
                g.drawImage(img, img.width * crop.x, img.height * crop.y, img.width * (1 - 2 * crop.x), img.height * (1 - 2 * crop.y), 0, 0, width, height);
                return c;
            };
            const ca = draw(ia, cropA);
            const cb = draw(ib, cropB);
            const da = ca.getContext('2d').getImageData(0, 0, width, height).data;
            const db = cb.getContext('2d').getImageData(0, 0, width, height).data;
            let sum = 0;
            let differing = 0;
            for (let i = 0; i < da.length; i += 4) {
                const d = (Math.abs(da[i] - db[i]) + Math.abs(da[i + 1] - db[i + 1]) + Math.abs(da[i + 2] - db[i + 2])) / 3;
                sum += d;
                if (d > 24) differing += 1;
            }
            const pixels = da.length / 4;
            const gutter = 16;
            const head = 26;
            const out = document.createElement('canvas');
            out.width = width * 2 + gutter;
            out.height = height + head;
            const g = out.getContext('2d');
            g.fillStyle = '#FFFFFF';
            g.fillRect(0, 0, out.width, out.height);
            g.fillStyle = '#2B2830';
            g.font = '600 13px Arial, sans-serif';
            g.fillText(la, 4, 17);
            g.fillText(lb, width + gutter + 4, 17);
            g.drawImage(ca, 0, head);
            g.drawImage(cb, width + gutter, head);
            return { png: out.toDataURL('image/png').split(',')[1], mean: (sum / pixels / 255) * 100, differing: (differing / pixels) * 100, aspect: [ia.width / ia.height, ib.width / ib.height] };
        },
        Buffer.from(left).toString('base64'),
        Buffer.from(right).toString('base64'),
        labelLeft,
        labelRight,
        SHOT_W,
        cropLeft,
        cropRight,
    );
    await page.close();
    return result;
}

(async () => {
    h.ensureFixture();
    const browser = await h.launch();
    const proto = await prototypeLayouts(browser);
    const app = await appLayouts(browser);
    fs.mkdirSync(OUT, { recursive: true });

    let failed = 0;
    const record = { tolerance: TOL, presets: {} };
    for (const preset of ['photoLeft', 'photoRight']) {
        const p = proto[preset];
        const a = app[preset];
        const rows = [];
        for (const key of KEYS) {
            const fp = fractions(p.boxes[key], PW, PH);
            const fa = fractions(a.boxes[key], a.page.width, a.page.height);
            const delta = Math.max(...['x', 'y', 'w', 'h'].map((f) => Math.abs(fp[f] - fa[f])));
            const ok = delta <= TOL;
            if (!ok) failed += 1;
            rows.push({ key, prototype: fp, app: fa, maxDelta: delta, ok });
        }
        const sameOrder = JSON.stringify(p.order.filter((k) => k !== 'qr')) === JSON.stringify(a.order.filter((k) => k !== 'qr'));
        if (!sameOrder) failed += 1;
        const pb = protoBleed(p);
        const ab = a.bleedMm;
        const picture = await compose(
            browser,
            p.shot,
            a.shot,
            `Prototyp — ${preset === 'photoLeft' ? 'Fotka vlevo' : 'Fotka vpravo'} (ořez)`,
            'Wboost (ořez)',
            { x: pb / (PW + 2 * pb), y: pb / (PH + 2 * pb) },
            { x: ab / (a.page.width + 2 * ab), y: ab / (a.page.height + 2 * ab) },
        );
        fs.writeFileSync(path.join(OUT, `sample-${preset}.png`), Buffer.from(picture.png, 'base64'));
        if (process.env.RAW === '1') {
            fs.writeFileSync(path.join(OUT, `raw-${preset}-prototype.png`), Buffer.from(p.shot));
            fs.writeFileSync(path.join(OUT, `raw-${preset}-app.png`), Buffer.from(a.shot));
        }
        record.presets[preset] = { boxes: rows.map((r) => ({ key: r.key, maxDeltaPct: Number((r.maxDelta * 100).toFixed(2)), ok: r.ok })), layerOrderEqual: sameOrder, meanPixelDifferencePct: Number(picture.mean.toFixed(2)), differingPixelsPct: Number(picture.differing.toFixed(2)) };

        console.log(`\n${preset} — boxes as page fractions (clipped to the trim), tolerance ${TOL * 100} %`);
        for (const r of rows) {
            const f = (v) => ['x', 'y', 'w', 'h'].map((k) => v[k].toFixed(4)).join(' ');
            console.log(`  ${r.ok ? 'ok  ' : 'FAIL'} ${r.key.padEnd(6)} prototype ${f(r.prototype)}   app ${f(r.app)}   max Δ ${(r.maxDelta * 100).toFixed(2)} %`);
        }
        console.log(`  layer order ${sameOrder ? 'equal' : `DIFFERS: ${p.order} vs ${a.order}`}`);
        console.log(`  sample page: mean pixel difference ${picture.mean.toFixed(2)} %, pixels differing visibly ${picture.differing.toFixed(2)} % → ${path.join(OUT, `sample-${preset}.png`)}`);
    }
    fs.writeFileSync(path.join(OUT, 'comparison.json'), `${JSON.stringify(record, null, 2)}\n`);
    await browser.close();
    process.exit(failed ? 1 : 0);
})().catch((error) => {
    console.error(error);
    process.exit(1);
});
