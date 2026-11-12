import { test } from 'node:test';
import assert from 'node:assert/strict';
import { existsSync } from 'node:fs';
import { mkdtempSync, writeFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import sharp from 'sharp';
import * as im from '../src/instruments/raster.mjs';
import { lineInk } from '../src/instruments/textink.mjs';
import { fitBackground, backgroundFill } from '../src/instruments/background.mjs';
import { softIou, batchLines, rankFaces, cellInk } from '../src/instruments/fonts.mjs';
import { mergeLines, runOcr, ocrPython } from '../src/instruments/ocr.mjs';
import { fakeApp } from './support/fakes.mjs';

const svgRaster = async (w, h, body, bg = '#ffffff') => {
    const { data, info } = await sharp(Buffer.from(`<svg xmlns="http://www.w3.org/2000/svg" width="${w}" height="${h}"><rect width="100%" height="100%" fill="${bg}"/>${body}</svg>`)).removeAlpha().raw().toBuffer({ resolveWithObject: true });
    return { data, width: info.width, height: info.height };
};
const filled = (w, h, f) => { const data = Buffer.alloc(w * h * 3); for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) { const c = f(x, y); data[(y * w + x) * 3] = c[0]; data[(y * w + x) * 3 + 1] = c[1]; data[(y * w + x) * 3 + 2] = c[2]; } return { data, width: w, height: h }; };

test('ink(): tight box, bands and background of a region', async () => {
    const img = await svgRaster(300, 200, '<rect x="40" y="30" width="200" height="20" fill="#102030"/><rect x="40" y="80" width="120" height="20" fill="#102030"/>', '#f0f0f0');
    const k = im.ink(img);
    assert.deepEqual(k.box, { x: 40, y: 30, w: 200, h: 70 });
    assert.equal(k.background, '#f0f0f0');
    assert.deepEqual(k.bands.map((b) => [b.y, b.h, b.w]), [[30, 20, 200], [80, 20, 120]]);
});

test('ink() by colour ignores other colours', async () => {
    const img = await svgRaster(300, 100, '<rect x="10" y="10" width="100" height="30" fill="#ff0000"/><rect x="150" y="50" width="100" height="30" fill="#0000ff"/>');
    assert.deepEqual(im.ink(img, { colour: [0, 0, 255] }).box, { x: 150, y: 50, w: 100, h: 30 });
});

test('mae() is 0 for identical rasters, ignores masked pixels', async () => {
    const a = await svgRaster(100, 100, '<rect x="0" y="0" width="50" height="100" fill="#000"/>');
    const b = await svgRaster(100, 100, '');
    assert.equal(im.mae(a, a), 0);
    assert.ok(Math.abs(im.mae(a, b) - 0.5) < 0.01);
    const mask = new Uint8Array(100 * 100);
    for (let y = 0; y < 100; y++) mask.fill(1, y * 100, y * 100 + 50);
    assert.equal(im.mae(a, b, null, mask), 0, 'the differing half is masked');
    assert.equal(im.mae(a, b, { x: 60, y: 0, w: 40, h: 100 }), 0, 'a box outside the difference');
});

test('bestShift() finds how far the render must move', async () => {
    const s = await svgRaster(200, 200, '<rect x="60" y="70" width="50" height="40" fill="#000"/>');
    const r = await svgRaster(200, 200, '<rect x="48" y="79" width="50" height="40" fill="#000"/>');
    const sh = im.bestShift(im.gray(s), im.gray(r), 200, 200, { x: 20, y: 20, w: 160, h: 160 }, 24);
    assert.deepEqual([sh.dx, sh.dy], [12, -9]);
    assert.ok(sh.errorAfter < sh.errorBefore);
});

test('dominantColours() and deltaE()', async () => {
    const img = await svgRaster(100, 100, '<rect x="0" y="0" width="100" height="25" fill="#cc0000"/>', '#ffffff');
    const d = im.dominantColours(img, 4);
    assert.equal(d[0].hex, '#ffffff'); assert.ok(Math.abs(d[0].share - 0.75) < 0.02);
    assert.equal(d[1].hex, '#cc0000');
    assert.equal(im.deltaE([10, 20, 30], [10, 20, 30]), 0);
    assert.ok(im.deltaE([255, 0, 0], [0, 0, 255]) > 40);
    assert.ok(im.deltaE([200, 30, 30], [202, 31, 29]) < 2);
});

test('clipBox / iou / coverage', () => {
    const img = { width: 100, height: 50 };
    assert.deepEqual(im.clipBox(img, { x: -10, y: 40, w: 30, h: 30 }), { x: 0, y: 40, w: 20, h: 10 });
    assert.equal(im.iou({ x: 0, y: 0, w: 10, h: 10 }, { x: 5, y: 0, w: 10, h: 10 }), 50 / 150);
    assert.equal(im.coverage({ x: 0, y: 0, w: 10, h: 10 }, { x: 5, y: 0, w: 100, h: 100 }), 0.5);
});

test('fitBackground(): solid, linear (angle, end colours), radial, complex', () => {
    const solid = fitBackground(filled(300, 400, () => [18, 52, 86]));
    assert.equal(solid.kind, 'solid'); assert.equal(solid.colour, '#123456');
    assert.equal(backgroundFill(solid), '#123456');

    const lin = fitBackground(filled(300, 400, (x, y) => [Math.round(255 * y / 399), 0, 255 - Math.round(255 * y / 399)]));
    assert.equal(lin.kind, 'linear'); assert.equal(lin.angle, 90);
    assert.ok(im.dist(im.parseHex(lin.from), [0, 0, 255]) < 12 && im.dist(im.parseHex(lin.to), [255, 0, 0]) < 12, JSON.stringify(lin));
    assert.deepEqual(backgroundFill(lin), { type: 'linear', angle: 90, from: lin.from, to: lin.to });

    const horizontal = fitBackground(filled(300, 400, (x) => [0, Math.round(200 * x / 299), 0]));
    assert.equal(horizontal.kind, 'linear'); assert.equal(horizontal.angle, 0);

    const rad = fitBackground(filled(300, 300, (x, y) => { const t = Math.min(1, Math.hypot(x - 150, y - 150) / 200); return [Math.round(240 - 200 * t), Math.round(240 - 200 * t), 255]; }));
    assert.equal(rad.kind, 'radial');
    assert.ok(Math.abs(rad.centre.x - 150) < 25 && Math.abs(rad.centre.y - 150) < 25, JSON.stringify(rad));

    let seed = 7; const rnd = () => (seed = (seed * 1103515245 + 12345) & 0x7fffffff) / 0x7fffffff;
    const noise = fitBackground(filled(300, 300, () => [rnd() * 255, rnd() * 255, rnd() * 255]));
    assert.equal(noise.kind, 'complex'); assert.equal(backgroundFill(noise), null);
});

test('fitBackground() does not let occupied pixels vote', () => {
    const img = filled(300, 300, (x, y) => (x > 50 && x < 250 && y > 100 && y < 200 ? [0, 0, 0] : [250, 250, 250]));
    const fit = fitBackground(img, (x, y) => x > 44 && x < 256 && y > 94 && y < 206);
    assert.equal(fit.kind, 'solid'); assert.equal(fit.colour, '#fafafa');
});

test('lineInk(): box, colour and backdrop of a line whose OCR box is loose and short', async () => {
    // "glyphs": six blocks on a tinted backdrop; the core box covers only the first four
    const blocks = [0, 1, 2, 3, 4, 5].map((i) => `<rect x="${100 + i * 40}" y="60" width="28" height="36" fill="#0b0b5e"/>`).join('');
    const img = await svgRaster(500, 160, blocks, '#e8e2d0');
    const k = lineInk(img, { x: 96, y: 54, w: 160, h: 48 });
    assert.deepEqual(k.box, { x: 100, y: 60, w: 228, h: 36 }, 'the line is walked to its end');
    assert.equal(k.colourHex, '#0b0b5e'); assert.equal(k.bgHex, '#e8e2d0');
    assert.equal(k.n, 6 * 28 * 36);
    assert.equal(lineInk(img, { x: 10, y: 120, w: 60, h: 30 }), null, 'flat colour: no line');
});

test('mergeLines(): fragments on one baseline become one line, in reading order', () => {
    const lines = mergeLines([
        { text: 'trénink?', conf: 0.99, box: { x: 484, y: 155, w: 171, h: 52 } },
        { text: 'Náročný', conf: 0.86, box: { x: 324, y: 159, w: 171, h: 48 } },
        { text: 'Dnes', conf: 0.99, box: { x: 326, y: 218, w: 86, h: 39 } },
        { text: ' raději', conf: 0.95, box: { x: 402, y: 218, w: 99, h: 42 } },
        { text: 'Jinde', conf: 0.9, box: { x: 900, y: 220, w: 80, h: 40 } },
        { text: '  ', conf: 0.5, box: { x: 0, y: 0, w: 5, h: 5 } },
    ]);
    assert.deepEqual(lines.map((l) => l.text), ['Náročný trénink?', 'Dnes raději', 'Jinde']);
    assert.equal(lines[0].conf, 0.86, 'the weakest fragment decides');
    assert.deepEqual(lines[0].box, { x: 324, y: 155, w: 331, h: 52 });
    assert.deepEqual(lines.map((l) => l.id), [1, 2, 3]);
});

test('softIou(): 1 for identical coverage, lower for a different weight, tolerant of a 1 px shift', () => {
    const cov = (w, h, f) => { const a = new Uint8Array(w * h); let sum = 0; for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) { const v = f(x, y) ? 255 : 0; a[y * w + x] = v; sum += v; } return { w, h, a, sum }; };
    const a = cov(60, 20, (x, y) => y >= 5 && y < 15);
    assert.equal(softIou(a, a), 1);
    assert.equal(softIou(a, cov(60, 20, (x, y) => y >= 6 && y < 16)), 1, 'a one-pixel offset is searched');
    const thin = softIou(a, cov(60, 20, (x, y) => y >= 8 && y < 12));
    assert.ok(thin > 0.3 && thin < 0.5, String(thin));
});

test('batchLines(): lines of similar size share a render, at most 8 per call', () => {
    const lines = [...Array(10).fill({ ink: { h: 29 } }), { ink: { h: 120 } }, { ink: { h: 30 } }];
    const b = batchLines(lines);
    assert.equal(b.length, 3);
    assert.deepEqual(b.map((x) => x.indexes.length), [8, 3, 1]);
    assert.ok(b[2].size > 150 && b[0].size >= 40 && b[0].size <= 42);
});

test('rankFaces(): the face whose rendered shape matches the source wins, with a fitted size; a blank line stays unranked', async () => {
    // source "line": a bar 300 × 28 — the fake app draws Regular as 0.35 × size, Bold as 0.7 × size thick
    const img = await svgRaster(600, 100, '<rect x="50" y="30" width="300" height="28" fill="#000"/>');
    const k = lineInk(img, { x: 50, y: 28, w: 300, h: 32 });
    const app = fakeApp();
    const r = await rankFaces({ lines: [{ text: 'x'.repeat(15), ink: k }, { text: 'nothing here', ink: null }], faces: ['Sans (Regular)', 'Sans (Bold)'], renderFaces: (q) => app.faces(q), loadRaster: (d) => im.loadRgb(d), availableScore: 0.8 });
    const [line, blank] = r.results;
    assert.equal(line.best.face, 'Sans (Bold)');
    assert.ok(line.best.score > 0.9 && line.runnerUp.score < 0.7, JSON.stringify(line.candidates));
    assert.equal(line.available, true);
    assert.equal(line.best.size, 40, 'size at which the face is as wide as the source: 300 / (15 × 0.5)');
    assert.deepEqual([blank.best, blank.available, blank.note], [null, false, 'no_ink']);
    assert.equal(r.sheets, 1);
});

test('rankFaces(): no face reaches the score → not available; the sheet budget is respected', async () => {
    const img = await svgRaster(600, 100, '<rect x="50" y="30" width="300" height="6" fill="#000"/>');
    const k = lineInk(img, { x: 50, y: 28, w: 300, h: 10 });
    const app = fakeApp();
    const r = await rankFaces({ lines: [{ text: 'x'.repeat(15), ink: k }], faces: ['Sans (Regular)', 'Sans (Bold)'], renderFaces: (q) => app.faces(q), loadRaster: (d) => im.loadRgb(d), availableScore: 0.8 });
    assert.equal(r.results[0].available, false);
    assert.equal(r.results[0].best.face, 'Sans (Regular)', 'still the closest one — the substitute');
    const none = await rankFaces({ lines: [{ text: 'x'.repeat(15), ink: k }], faces: ['Sans (Regular)'], renderFaces: (q) => app.faces(q), loadRaster: (d) => im.loadRgb(d), maxSheets: 0 });
    assert.equal(none.sheets, 0); assert.equal(none.truncated, true);
});

test('cellInk(): empty cell → null', async () => {
    assert.equal(cellInk(await svgRaster(100, 50, ''), { x: 0, y: 0, w: 100, h: 50 }), null);
});

test('mergeLines(): a fragment boxed tall by a descender still joins its line; a neighbour of another size does not', () => {
    // what the detector returns for "Letni kemp 2027" set in DejaVu Sans: "kemp" is boxed 1.6× as tall as its neighbours
    const frag = (text, x, y, w, h) => ({ text, conf: 1, box: { x, y, w, h } });
    assert.deepEqual(mergeLines([frag('kemp', 237, 60, 244, 124), frag('Letni', 56, 76, 192, 78), frag('2027', 466, 73, 202, 81)]).map((l) => l.text), ['Letni kemp 2027']);
    // the same line with a still shorter last fragment (its height is 0.56 of the union box, 0.9 of the typical fragment)
    assert.deepEqual(mergeLines([frag('Letni', 56, 76, 192, 78), frag('kemp', 237, 60, 244, 124), frag('2027', 466, 80, 202, 70)]).map((l) => l.text), ['Letni kemp 2027']);
    // a big numeral beside a line of small text, and the line below, stay apart
    assert.deepEqual(mergeLines([frag('3', 100, 50, 60, 150), frag('small text', 180, 100, 200, 40), frag('next line', 180, 150, 180, 40)]).map((l) => l.text).sort(), ['3', 'next line', 'small text']);
});

const hasOcr = existsSync(ocrPython());
test('OCR sidecar: reads a line with its box, on the pinned thread count (fixed picture: the same result on arm64 and x86)', { skip: !hasOcr && 'no python with rapidocr (scanner/.venv)' }, async () => {
    const r = await runOcr(join(import.meta.dirname, 'fixtures', 'ocr-line.png'), { threads: 2, timeoutMs: 120_000 });
    assert.equal(r.threads, 2);
    // the raw fragments are part of the contract: the detector and recogniser are pinned (requirements.txt)
    assert.deepEqual(r.lines.map((l) => [l.text, l.box.x, l.box.y, l.box.w, l.box.h]).sort((a, b) => a[1] - b[1]), [['Letni', 56, 76, 192, 78], ['kemp', 237, 60, 244, 124], ['2027', 466, 73, 202, 81]]);
    const lines = mergeLines(r.lines);
    assert.deepEqual(lines.map((l) => [l.text, l.box]), [['Letni kemp 2027', { x: 56, y: 60, w: 612, h: 124 }]]);
    assert.ok(r.peakRssMb > 50 && r.cpuSeconds > 0);
});

test('OCR sidecar: a line set in whatever sans-serif this machine has comes back as ONE line', { skip: !hasOcr && 'no python with rapidocr (scanner/.venv)' }, async (t) => {
    const dir = mkdtempSync(join(tmpdir(), 'scan-ocr-'));
    try {
        const file = join(dir, 'line.png');
        writeFileSync(file, await sharp(Buffer.from('<svg xmlns="http://www.w3.org/2000/svg" width="900" height="240"><rect width="100%" height="100%" fill="#fff"/><text x="60" y="140" font-size="72" font-family="Helvetica, Arial, DejaVu Sans, sans-serif" fill="#111">Letni kemp 2027</text></svg>')).png().toBuffer());
        const r = await runOcr(file, { threads: 2, timeoutMs: 120_000 });
        if (!r.lines.length) return t.skip('this machine has no font to draw the line with');
        const lines = mergeLines(r.lines);
        assert.equal(lines.length, 1, `fragments: ${JSON.stringify(r.lines.map((l) => [l.text, l.box.x, l.box.y, l.box.w, l.box.h]))}`);
        assert.match(lines[0].text.replace(/\s+/g, ' '), /Letni kemp 2027/i);
    } finally { rmSync(dir, { recursive: true, force: true }); }
});

test('OCR sidecar: an abort kills the process group', { skip: !hasOcr && 'no python with rapidocr (scanner/.venv)' }, async () => {
    const dir = mkdtempSync(join(tmpdir(), 'scan-ocr-'));
    try {
        const file = join(dir, 'big.png');
        writeFileSync(file, await sharp({ create: { width: 3000, height: 3000, channels: 3, background: '#fff' } }).png().toBuffer());
        const controller = new AbortController();
        let pid = null;
        const p = runOcr(file, { signal: controller.signal, onSpawn: (x) => { pid = x; } });
        setTimeout(() => controller.abort(), 150);
        await assert.rejects(p, { code: 'aborted' });
        await new Promise((ok) => setTimeout(ok, 300));
        assert.throws(() => process.kill(pid, 0), { code: 'ESRCH' }, 'the sidecar is gone');
    } finally { rmSync(dir, { recursive: true, force: true }); }
});
