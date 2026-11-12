// Hostile and awkward uploads through the intake's own child process: each ends in a NAMED outcome
// (a stop reason + a detail code, or a working copy) within a memory and a time bound — never in a crash
// of the scanner. Fixtures are generated here; the HEIC one is a synthetic picture (test/fixtures/).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, writeFileSync, rmSync, readFileSync, existsSync } from 'node:fs';
import { deflateSync, crc32 } from 'node:zlib';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import sharp from 'sharp';
import { normaliseInChild } from '../src/intake/normalise.mjs';

const HERE = dirname(fileURLToPath(import.meta.url));
const base = (w, h, background = { r: 200, g: 30, b: 30 }) => sharp({ create: { width: w, height: h, channels: 3, background } });
const noise = (w, h) => sharp({ create: { width: w, height: h, channels: 3, noise: { type: 'gaussian', mean: 128, sigma: 40 } } });
/** A full-size picture with real detail that still fits the 10 MB upload limit: a small noise field, enlarged. */
const large = async (w, h) => sharp(await noise(Math.round(w / 12), Math.round(h / 12)).png().toBuffer()).resize(w, h, { fit: 'fill', kernel: 'cubic' }).jpeg({ quality: 70 }).toBuffer();

/** A PNG that declares w × h pixels of 1-bit grey and deflates to almost nothing: a decompression bomb. */
function bombPng(w, h) {
    const chunk = (type, data) => { const len = Buffer.alloc(4); len.writeUInt32BE(data.length); const body = Buffer.concat([Buffer.from(type), data]); const crc = Buffer.alloc(4); crc.writeUInt32BE(crc32(body) >>> 0); return Buffer.concat([len, body, crc]); };
    const ihdr = Buffer.alloc(13); ihdr.writeUInt32BE(w, 0); ihdr.writeUInt32BE(h, 4); ihdr[8] = 1; ihdr[9] = 0;
    const raw = Buffer.alloc((Math.ceil(w / 8) + 1) * h); // every row: filter byte 0 + zeros
    return Buffer.concat([Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]), chunk('IHDR', ihdr), chunk('IDAT', deflateSync(raw, { level: 9 })), chunk('IEND', Buffer.alloc(0))]);
}

const MEMORY_BOUND_MB = 900;   // the intake child: 393 MB measured on the largest legal upload; the lane's share of the limit is 1 GiB
const TIME_BOUND_MS = 20_000;  // §4.9: normalise ≤ 20 s, then the child is killed

async function fixtures() {
    const png = await base(1000, 1000).png({ compressionLevel: 0 }).toBuffer();
    const jpeg = await noise(1600, 1200).jpeg().toBuffer();
    const frame = (c) => sharp({ create: { width: 700, height: 700, channels: 3, background: c } }).raw().toBuffer();
    return [
        // name, bytes, expected {ok, stop?, detail?}, extra check
        ['decompression bomb: 30000 × 30000 declared, 110 KB on disk', bombPng(30000, 30000), { ok: false, stop: 'unusable_input', detail: 'too_many_pixels' }],
        ['a side over 8000 px: 8100 × 700', bombPng(8100, 700), { ok: false, stop: 'unusable_input', detail: 'side_too_long' }],
        ['decompression bomb inside the side limit: 8000 × 8000 = 64 Mpx', bombPng(8000, 8000), { ok: false, stop: 'unusable_input', detail: 'too_many_pixels' }],
        ['the largest legal upload: 36 Mpx (7200 × 5000)', await large(7200, 5000), { ok: true }, (r) => assert.deepEqual([r.meta.workingWidth, r.meta.workingHeight], [4096, 2844])],
        ['one pixel row over 36 Mpx', bombPng(7200, 5001), { ok: false, stop: 'unusable_input', detail: 'too_many_pixels' }],
        ['CMYK JPEG', await base(1200, 800).toColourspace('cmyk').jpeg().toBuffer(), { ok: true }, (r) => assert.ok(r.meta.facts.includes('converted_from_cmyk'))],
        ['16-bit PNG', await base(1000, 700).toColourspace('rgb16').png().toBuffer(), { ok: true }, (r) => assert.ok(r.meta.facts.includes('reduced_to_8_bit'))],
        ['animated GIF', await sharp(Buffer.concat([await frame('#00ff00'), await frame('#ff00ff')]), { raw: { width: 700, height: 1400, channels: 3, pageHeight: 700 } }).gif({ loop: 0 }).toBuffer(), { ok: true }, (r) => assert.ok(r.meta.facts.includes('first_frame_only'))],
        ['truncated PNG (a third of the file)', png.subarray(0, Math.floor(png.length / 3)), { ok: false, stop: 'unusable_input', detail: 'undecodable' }],
        ['truncated JPEG (half of the file)', jpeg.subarray(0, Math.floor(jpeg.length / 2)), { ok: false, stop: 'unusable_input', detail: 'undecodable' }],
        ['a JPEG header and nothing else', jpeg.subarray(0, 300), { ok: false, stop: 'unusable_input', detail: 'undecodable' }],
        ['not a picture', Buffer.from('%PDF-1.7 this is not a picture at all'), { ok: false, stop: 'unusable_input', detail: 'undecodable' }],
        ['an empty file', Buffer.alloc(0), { ok: false, stop: 'unusable_input', detail: 'empty' }],
        ['SVG', Buffer.from('<svg xmlns="http://www.w3.org/2000/svg" width="800" height="800"><rect width="800" height="800"/></svg>'), { ok: false, stop: 'unusable_input', detail: 'vector' }],
        ['more than 10 MB', Buffer.concat([await noise(3000, 3000).png({ compressionLevel: 0 }).toBuffer(), Buffer.alloc(0)]), { ok: false, stop: 'unusable_input', detail: 'too_many_bytes' }],
        ['too small to scan: 500 px short side', await base(900, 500).png().toBuffer(), { ok: false, stop: 'blurry_or_small', detail: 'too_small' }],
    ];
}

test('hostile fixtures: each ends in a named outcome within the memory and time bound', async (t) => {
    const dir = mkdtempSync(join(tmpdir(), 'scan-hostile-'));
    t.after(() => rmSync(dir, { recursive: true, force: true }));
    const rows = [];
    for (const [name, bytes, expected, check] of await fixtures()) {
        const file = join(dir, 'upload.bin');
        writeFileSync(file, bytes);
        const t0 = Date.now();
        const r = await normaliseInChild(file, dir);
        const ms = Date.now() - t0;
        rows.push({ name, outcome: r.ok ? 'working copy' : `${r.stop} / ${r.detail}`, peakRssMb: r.resources?.peakRssMb ?? null, ms });
        assert.deepEqual({ ok: r.ok, ...(r.ok ? {} : { stop: r.stop, detail: r.detail }) }, expected, name);
        assert.ok(r.resources && r.resources.peakRssMb < MEMORY_BOUND_MB, `${name}: ${r.resources?.peakRssMb} MB`);
        assert.ok(ms < TIME_BOUND_MS, `${name}: ${ms} ms`);
        if (r.ok) assert.ok(existsSync(r.files.working) && existsSync(r.files.preview), name);
        check?.(r);
    }
    t.diagnostic(rows.map((r) => `${r.name}: ${r.outcome} · ${r.peakRssMb} MB · ${r.ms} ms`).join('\n'));
    // the bombs never reach the decoder: their outcome costs no more than an ordinary small picture
    assert.ok(rows.filter((r) => r.name.startsWith('decompression bomb')).every((r) => r.peakRssMb < 250), JSON.stringify(rows.slice(0, 2)));
});

test('HEIC: converted where the system has a decoder, named `heic_unsupported` where it has none — never a crash', async (t) => {
    const dir = mkdtempSync(join(tmpdir(), 'scan-heic-'));
    t.after(() => rmSync(dir, { recursive: true, force: true }));
    const r = await normaliseInChild(join(HERE, 'fixtures', 'synthetic.heic'), dir);
    if (r.ok) { assert.equal(r.meta.format, 'heic'); assert.deepEqual([r.meta.workingWidth, r.meta.workingHeight], [900, 700]); } else assert.deepEqual([r.stop, r.detail], ['unusable_input', 'heic_unsupported']);
    assert.ok(r.resources.peakRssMb < MEMORY_BOUND_MB);
    t.diagnostic(`HEIC here: ${r.ok ? 'converted' : 'no decoder (heic_unsupported)'} · ${r.resources.peakRssMb} MB`);
    // a HEIC that is cut short is unusable either way
    writeFileSync(join(dir, 'cut.heic'), readFileSync(join(HERE, 'fixtures', 'synthetic.heic')).subarray(0, 2000));
    const cut = await normaliseInChild(join(dir, 'cut.heic'), dir);
    assert.equal(cut.ok, false); assert.equal(cut.stop, 'unusable_input'); assert.ok(['undecodable', 'heic_unsupported'].includes(cut.detail), cut.detail);
});

test('a decoder that runs away is killed at the limit and the outcome is named; an abort ends it at once', async (t) => {
    const dir = mkdtempSync(join(tmpdir(), 'scan-slow-'));
    t.after(() => rmSync(dir, { recursive: true, force: true }));
    writeFileSync(join(dir, 'max.jpg'), await large(7200, 5000));
    const slow = await normaliseInChild(join(dir, 'max.jpg'), dir, { timeoutMs: 50 });
    assert.deepEqual([slow.ok, slow.stop, slow.detail], [false, 'unusable_input', 'intake_timeout']);
    const controller = new AbortController();
    setTimeout(() => controller.abort(), 30);
    const t0 = Date.now();
    const aborted = await normaliseInChild(join(dir, 'max.jpg'), dir, { signal: controller.signal });
    assert.deepEqual([aborted.ok, aborted.detail], [false, 'aborted']); assert.ok(Date.now() - t0 < 1000);
    // a child that cannot hold the picture in its heap dies alone: named, not a crash of the caller
    const starved = await normaliseInChild(join(dir, 'max.jpg'), dir, { heapMb: 8 });
    assert.equal(typeof starved.ok, 'boolean');
    if (!starved.ok) assert.ok(['intake_crashed', 'intake_error'].includes(starved.detail), starved.detail);
});
