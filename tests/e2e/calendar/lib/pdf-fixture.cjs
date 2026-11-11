/*
 * A calendar worth exporting, built through the page's own endpoints (upload + save) — for the PDF
 * export scripts and for measuring a full year by hand:
 *
 *   node tests/e2e/calendar/lib/pdf-fixture.cjs [week|fortnight] [photoCount] [photoWidth]
 *
 * keeps the calendar and prints its id (the runner's purge removes it with the next run).
 *
 * Photos are drawn in the page (a canvas with fine noise over a gradient, so the JPEG has the weight
 * of a real photograph — ~3 MB at 3600 × 2400) and uploaded with the page's cookies and CSRF token;
 * nothing binary is committed and no temp file is written.
 */
const assert = require('assert/strict');

const bootstrap = (page) => page.evaluate(() => JSON.parse(document.querySelector('[data-calendar-workspace-target="bootstrap"]').textContent));

/**
 * Draws a noisy photograph and uploads it as `kind`; answers `{ id, width, height, bytes }`.
 * `seed` changes the colours (every week gets a different picture), `label` is painted large in the
 * middle so a crop can be recognised in the exported page.
 */
async function uploadPhoto(page, { width, height, seed = 1, label = '', kind = 'photo', quality = 0.92, type = 'image/jpeg', noise = 70, maxBytes = 0 }) {
    const answer = await page.evaluate(
        async (o) => {
            const boot = JSON.parse(document.querySelector('[data-calendar-workspace-target="bootstrap"]').textContent);
            const canvas = document.createElement('canvas');
            canvas.width = o.width;
            canvas.height = o.height;
            const g = canvas.getContext('2d');
            const hue = (o.seed * 47) % 360;
            const gradient = g.createLinearGradient(0, 0, o.width, o.height);
            gradient.addColorStop(0, `hsl(${hue}, 60%, 45%)`);
            gradient.addColorStop(1, `hsl(${(hue + 80) % 360}, 55%, 30%)`);
            g.fillStyle = gradient;
            g.fillRect(0, 0, o.width, o.height);
            // fine noise: what makes a JPEG as heavy as a photograph
            const tile = 256;
            const noise = g.createImageData(tile, tile);
            let s = o.seed * 2654435761;
            for (let i = 0; i < noise.data.length; i += 4) {
                s = (s * 1664525 + 1013904223) >>> 0;
                const v = s >>> 24;
                noise.data[i] = v;
                noise.data[i + 1] = (v * 7) & 255;
                noise.data[i + 2] = (v * 13) & 255;
                noise.data[i + 3] = o.noise;
            }
            const tileCanvas = document.createElement('canvas');
            tileCanvas.width = tile;
            tileCanvas.height = tile;
            tileCanvas.getContext('2d').putImageData(noise, 0, 0);
            g.fillStyle = g.createPattern(tileCanvas, 'repeat');
            g.fillRect(0, 0, o.width, o.height);
            // a one-pixel checker in a corner + thin rings: shows at once whether the picture was resampled
            g.fillStyle = '#FFFFFF';
            for (let y = 0; y < 64; y++) for (let x = y % 2; x < 64; x += 2) g.fillRect(x, y, 1, 1);
            g.strokeStyle = 'rgba(255,255,255,.85)';
            g.lineWidth = Math.max(2, o.width / 600);
            for (let r = o.height / 8; r < o.height / 2; r += o.height / 8) {
                g.beginPath();
                g.arc(o.width / 2, o.height / 2, r, 0, Math.PI * 2);
                g.stroke();
            }
            if (o.label) {
                g.fillStyle = '#FFFFFF';
                g.font = `700 ${Math.round(o.height / 5)}px sans-serif`;
                g.textAlign = 'center';
                g.textBaseline = 'middle';
                g.fillText(o.label, o.width / 2, o.height / 2);
            }
            let quality = o.quality;
            let blob = await new Promise((resolve) => canvas.toBlob(resolve, o.type, quality));
            // `maxBytes`: step the quality down until the file fits (a photo "just under the upload cap")
            while (o.maxBytes && blob.size > o.maxBytes && quality > 0.3) {
                quality -= 0.004;
                blob = await new Promise((resolve) => canvas.toBlob(resolve, o.type, quality));
            }
            const form = new FormData();
            form.append('_token', boot.env.csrf);
            form.append('kind', o.kind);
            form.append('file', new File([blob], `${o.label || 'foto'}.${o.type === 'image/png' ? 'png' : 'jpg'}`, { type: o.type }));
            const response = await fetch(boot.env.urls.asset, { method: 'POST', body: form, credentials: 'same-origin', headers: { Accept: 'application/json' } });
            return { status: response.status, json: await response.json().catch(() => null), bytes: blob.size, quality };
        },
        { width, height, seed, label, kind, quality, type, noise, maxBytes },
    );
    assert.equal(answer.status, 200, `upload failed: ${JSON.stringify(answer.json)}`);
    return { id: answer.json.id, width: answer.json.width, height: answer.json.height, bytes: answer.bytes, quality: answer.quality, previewUrl: answer.json.previewUrl };
}

/**
 * @param opts `{ type: 'week'|'fortnight', photos: number|'all', bigWidth, width, special: bool }`
 * @returns `{ id, clientUrl, pages, photos: { [pageKey]: upload }, big: pageKey }` — the page ends in step 5, saved
 */
async function buildCalendar(page, h, opts = {}) {
    const type = opts.type || 'week';
    const id = await h.createCalendar(page);
    if (type === 'fortnight') {
        await page.click('[data-type="fortnight"]');
        await page.waitForFunction(() => window.__calendar.state().design.type === 'fortnight', { timeout: 8000 });
        // the one 14-day layout is chosen for you: step 2 only continues
        await h.gotoStep(page, 2);
        await page.click('[data-cal-action="continue"]');
    } else {
        await h.gotoStep(page, 2);
        await page.click('[data-preset="photoLeft"]');
    }
    await page.waitForSelector('.cal-ws[data-cal-shown-step="3"]');
    await h.save(page);

    let s = await h.state(page);
    const year = s.design.year;
    const kind = (key, over) => ({ ...s.content.wasteKinds[key], on: true, ...over });
    await h.seed(page, {
        design: { name: opts.name || `PDF export ${type}`, features: { ...s.design.features, waste: true, events: true, captions: true, moon: true, specialPages: opts.special !== false }, step: 5 },
        content: [
            { c: 'wasteKinds', id: 'bio', v: kind('bio', { mode: 'rule', weekdays: [2], interval: '2e' }) },
            { c: 'wasteKinds', id: 'plast', v: kind('plast', { mode: 'rule', weekdays: [4], interval: '4w' }) },
            { c: 'clubs', id: 'clubSDH0001', v: { code: 'SDH', name: 'Sbor dobrovolných hasičů', pos: 0 } },
            { c: 'events', id: 'evMasopust01', v: { date: `${year}-02-13`, name: 'Masopustní průvod obcí', club: 'clubSDH0001', icon: null } },
            { c: 'events', id: 'evPout000001', v: { date: `${year}-08-15`, name: 'Pouť a žehnání úrody', club: null, icon: null } },
            { c: 'events', id: 'evVanoce0001', v: { date: `${year}-12-05`, name: 'Rozsvícení vánočního stromu', club: null, icon: null } },
            ...(opts.special !== false ? [{ c: 'specialPages', id: 'intro', v: { kind: 'intro', on: true, layout: 0, position: 'front', nextYear: false, name: '', asset: null, text: 'Vážení spoluobčané, přejeme vám klidný a úspěšný rok. Žluťoučký kůň úpěl ďábelské ódy.', fileName: null, pos: 0 } }] : []),
        ],
    });

    const pages = await h.cal(page, (c) => c.pages().map((p) => ({ index: p.index, key: p.key, kind: p.kind, title: p.title })));
    const weeks = pages.filter((p) => p.kind === 'week');
    const count = opts.photos === 'all' ? weeks.length : Math.min(weeks.length, opts.photos === undefined ? 3 : opts.photos);
    // spread over the year; the first chosen week carries the big photo
    const chosen = [];
    for (let i = 0; i < count; i++) chosen.push(weeks[Math.min(weeks.length - 1, Math.floor(((i + 0.5) * weeks.length) / count))]);
    const photos = {};
    const ops = [];
    let big = null;
    for (const [i, week] of chosen.entries()) {
        const isBig = i === 0;
        const width = isBig ? opts.bigWidth || 3600 : opts.width || 2000;
        const upload = await uploadPhoto(page, { width, height: Math.round((width * 2) / 3), seed: i + 1, label: week.key });
        photos[week.key] = upload;
        if (isBig) big = week.key;
        ops.push({ c: 'pagePhotos', id: week.key, v: { asset: upload.id, zoom: i === 1 ? 1.6 : 1, px: i === 1 ? 0.2 : 0.5, py: i === 1 ? 0.8 : 0.5 } });
        ops.push({ c: 'pageCaptions', id: week.key, v: { text: `Foto ${week.key}: výhled na obec, archiv` } });
        if (opts.progress) opts.progress(i + 1, chosen.length);
    }
    for (let i = 0; i < ops.length; i += 40) await h.seed(page, { content: ops.slice(i, i + 40) });
    s = await h.state(page);
    const boot = await bootstrap(page);
    return { id, clientUrl: boot.env.clientUrl, year, type, pages, photos, big, revision: s.revision };
}

module.exports = { buildCalendar, uploadPhoto, bootstrap };

if (require.main === module) {
    (async () => {
        const h = require('./harness.cjs');
        h.ensureFixture();
        const browser = await h.launch();
        const page = await h.newPage(browser);
        await h.login(page);
        const [type = 'week', photos = '3', width = '2000'] = process.argv.slice(2);
        const built = await buildCalendar(page, h, { type, photos: photos === 'all' ? 'all' : Number(photos), width: Number(width), bigWidth: Math.max(3600, Number(width)), progress: (n, of) => process.stderr.write(`\rphoto ${n}/${of}`) });
        process.stderr.write('\n');
        console.log(JSON.stringify({ id: built.id, clientUrl: built.clientUrl, pages: built.pages.length, big: built.big, photoBytes: Object.values(built.photos).reduce((sum, p) => sum + p.bytes, 0) }));
        await browser.close();
    })().catch((error) => {
        console.error(error);
        process.exit(1);
    });
}
