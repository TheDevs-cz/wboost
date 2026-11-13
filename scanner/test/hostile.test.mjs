// Hostile and awkward uploads through the intake's own child process: each ends in a NAMED outcome
// (a stop reason + a detail code, or a working copy) within a memory and a time bound — never in a crash
// of the scanner. Fixtures are generated here; the HEIC one is a synthetic picture (test/fixtures/).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, mkdirSync, writeFileSync, rmSync, readFileSync, readdirSync, chmodSync, existsSync } from 'node:fs';
import { deflateSync, crc32 } from 'node:zlib';
import { execFileSync } from 'node:child_process';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import sharp from 'sharp';
import { normaliseInChild, runTool, heicDeclaredSize, TOOL_ADDRESS_SPACE_BYTES } from '../src/intake/normalise.mjs';
import { onPath } from '../src/childenv.mjs';
import { alive } from '../src/proc.mjs';

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

const POPPLER = (() => { try { execFileSync('pdfinfo', ['-v'], { stdio: 'ignore' }); return true; } catch { return false; } })();
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
        ['not a picture', Buffer.from('MZ this is not a picture at all'), { ok: false, stop: 'unusable_input', detail: 'undecodable' }],
        ['a file that only claims to be a PDF', Buffer.from('%PDF-1.7 this is not a document at all'), { ok: false, stop: 'unusable_input', detail: POPPLER ? 'pdf_unreadable' : 'pdf_unsupported' }],
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

test('HEIC: converted where the system has a converter, named `heic_unsupported` where it has none (`undecodable` where it has one without a decoder) — never a crash', async (t) => {
    const dir = mkdtempSync(join(tmpdir(), 'scan-heic-'));
    t.after(() => rmSync(dir, { recursive: true, force: true }));
    const r = await normaliseInChild(join(HERE, 'fixtures', 'synthetic.heic'), dir);
    if (r.ok) { assert.equal(r.meta.format, 'heic'); assert.deepEqual([r.meta.workingWidth, r.meta.workingHeight], [900, 700]); } else { assert.equal(r.stop, 'unusable_input'); assert.ok(['heic_unsupported', 'undecodable'].includes(r.detail), r.detail); }
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

// ---------------------------------------------------------------------------------- the external converters

/** The synthetic HEIC with every size property (`ispe`) of its header rewritten: it CLAIMS w × h, its data is 900 × 700. */
function heicClaiming(w, h) {
    const buf = Buffer.from(readFileSync(join(HERE, 'fixtures', 'synthetic.heic')));
    let n = 0;
    for (let at = buf.indexOf('ispe', 0, 'latin1'); at !== -1; at = buf.indexOf('ispe', at + 4, 'latin1')) { buf.writeUInt32BE(w, at + 8); buf.writeUInt32BE(h, at + 12); n++; }
    assert.ok(n >= 1, 'the fixture has a size property');
    return buf;
}

/** A one-page A4 PDF whose only content is a w × h picture, 1 bit a pixel, deflated to almost nothing. */
function pdfWithPicture(w, h) {
    const data = deflateSync(Buffer.alloc(Math.ceil(w / 8) * h), { level: 9 });
    const content = 'q 595 0 0 842 0 0 cm /Im0 Do Q';
    const parts = [Buffer.from('%PDF-1.4\n')], offsets = [];
    const size = () => parts.reduce((a, b) => a + b.length, 0);
    const add = (n, body) => { offsets[n] = size(); parts.push(Buffer.from(`${n} 0 obj\n`), Buffer.isBuffer(body) ? body : Buffer.from(body), Buffer.from('\nendobj\n')); };
    add(1, '<< /Type /Catalog /Pages 2 0 R >>');
    add(2, '<< /Type /Pages /Kids [3 0 R] /Count 1 >>');
    add(3, '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /XObject << /Im0 4 0 R >> >> /Contents 5 0 R >>');
    add(4, Buffer.concat([Buffer.from(`<< /Type /XObject /Subtype /Image /Width ${w} /Height ${h} /ColorSpace /DeviceGray /BitsPerComponent 1 /Filter /FlateDecode /Length ${data.length} >>\nstream\n`), data, Buffer.from('\nendstream')]));
    add(5, `<< /Length ${content.length} >>\nstream\n${content}\nendstream`);
    const xref = size();
    parts.push(Buffer.from(`xref\n0 6\n0000000000 65535 f \n${[1, 2, 3, 4, 5].map((n) => `${String(offsets[n]).padStart(10, '0')} 00000 n \n`).join('')}trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n${xref}\n%%EOF\n`));
    return Buffer.concat(parts);
}

const executable = (file, body) => { writeFileSync(file, body); chmodSync(file, 0o755); return file; };
const PRLIMIT = process.platform === 'linux' && !!onPath('prlimit');

test('a HEIC that claims a huge canvas is refused from its header — the system converter is never started', async (t) => {
    const dir = mkdtempSync(join(tmpdir(), 'scan-heic-claim-'));
    t.after(() => rmSync(dir, { recursive: true, force: true }));
    assert.deepEqual(heicDeclaredSize(readFileSync(join(HERE, 'fixtures', 'synthetic.heic'))), { width: 900, height: 700 });
    assert.deepEqual(heicDeclaredSize(heicClaiming(30000, 30000)), { width: 30000, height: 30000 });
    assert.equal(heicDeclaredSize(Buffer.from('not a container at all, and long enough to look into')), null);
    // converters that would leave a trace if they were started
    const bin = join(dir, 'bin'), started = join(dir, 'started');
    mkdirSync(bin); mkdirSync(started);
    for (const name of ['heif-convert', 'heif-dec', 'magick', 'sips']) executable(join(bin, name), `#!/bin/sh\ntouch "${started}/${name}"\nexit 1\n`);
    const env = { ...process.env, PATH: `${bin}:/usr/bin:/bin` };
    for (const [w, h, details] of [[30000, 30000, ['too_many_pixels', 'side_too_long']], [8100, 700, ['side_too_long']], [7200, 5001, ['too_many_pixels']], [65535, 65535, ['too_many_pixels', 'side_too_long']]]) {
        writeFileSync(join(dir, 'claim.heic'), heicClaiming(w, h));
        const t0 = Date.now();
        const r = await normaliseInChild(join(dir, 'claim.heic'), dir, { env });
        assert.deepEqual([r.ok, r.stop], [false, 'unusable_input'], `${w} × ${h}`);
        assert.ok(details.includes(r.detail), `${w} × ${h}: ${r.detail}`);
        assert.ok(r.resources.peakRssMb < 250 && Date.now() - t0 < 5000, `${w} × ${h}: ${r.resources.peakRssMb} MB, ${Date.now() - t0} ms`);
        assert.deepEqual(readdirSync(started), [], `${w} × ${h}: no converter was started`);
    }
    // the control: the same file with an honest header does reach the converter
    const honest = await normaliseInChild(join(HERE, 'fixtures', 'synthetic.heic'), dir, { env });
    assert.deepEqual([honest.ok, honest.detail], [false, 'undecodable']);
    assert.ok(readdirSync(started).length >= 1);
});

test('a PDF with a huge embedded picture ends bounded: a page-sized working copy or `pdf_unreadable`, inside the time and memory bound', { skip: !POPPLER && 'no poppler here' }, async (t) => {
    const dir = mkdtempSync(join(tmpdir(), 'scan-pdf-bomb-'));
    t.after(() => rmSync(dir, { recursive: true, force: true }));
    for (const side of [30000, 60000]) { // 0.9 and 3.6 gigapixels in a file of ≈ 0.1 / 0.4 MB
        writeFileSync(join(dir, 'bomb.pdf'), pdfWithPicture(side, side));
        const t0 = Date.now();
        const r = await normaliseInChild(join(dir, 'bomb.pdf'), dir);
        const ms = Date.now() - t0;
        t.diagnostic(`${side} × ${side} px in a PDF: ${r.ok ? `working copy ${r.meta.workingWidth} × ${r.meta.workingHeight}` : `${r.stop} / ${r.detail}`} · ${r.resources?.peakRssMb ?? '?'} MB · ${ms} ms`);
        // the rasteriser is asked for the PAGE at ≤ 4096 px, never for the picture: it either draws that or gives up
        if (r.ok) { assert.equal(r.meta.format, 'pdf'); assert.ok(Math.max(r.meta.workingWidth, r.meta.workingHeight) <= 4096); assert.ok(r.resources.peakRssMb < MEMORY_BOUND_MB); }
        else { assert.equal(r.stop, 'unusable_input'); assert.ok(['pdf_unreadable', 'intake_timeout'].includes(r.detail), r.detail); }
        assert.ok(ms < TIME_BOUND_MS + 2000, `${ms} ms`);
    }
});

test('the converters run under an address-space limit: one that asks for more ends, and the upload is refused by name', { skip: !PRLIMIT && 'no prlimit here (util-linux; the image has it)' }, async (t) => {
    const dir = mkdtempSync(join(tmpdir(), 'scan-aslimit-'));
    t.after(() => rmSync(dir, { recursive: true, force: true }));
    // the limit a converter runs under is the one the scanner names (ulimit -v speaks KiB)
    assert.equal((await runTool('sh', ['-c', 'ulimit -v'])).stdout.trim(), String(TOOL_ADDRESS_SPACE_BYTES / 1024));
    assert.equal(TOOL_ADDRESS_SPACE_BYTES, 1536 * 1024 * 1024);
    // a converter that cannot have the memory it wants (here: any at all) fails — the scanner goes on and names the outcome
    const pdf = await normaliseInChild(join(HERE, '..', 'src', 'intake', 'selftest.pdf'), dir, { toolAddressSpaceBytes: 8 << 20 });
    assert.deepEqual([pdf.ok, pdf.stop, pdf.detail], [false, 'unusable_input', POPPLER ? 'pdf_unreadable' : 'pdf_unsupported']);
    const heic = await normaliseInChild(join(HERE, 'fixtures', 'synthetic.heic'), dir, { toolAddressSpaceBytes: 8 << 20 });
    assert.deepEqual([heic.ok, heic.stop], [false, 'unusable_input']); assert.ok(['undecodable', 'heic_unsupported'].includes(heic.detail), heic.detail);
    // a program that is not installed is still told apart from one that failed
    await assert.rejects(runTool('no-such-converter', []), (e) => e.code === 'ENOENT');
});

test('the intake timeout kills the converter with the child: nothing of its process group is left running', async (t) => {
    const dir = mkdtempSync(join(tmpdir(), 'scan-group-'));
    t.after(() => rmSync(dir, { recursive: true, force: true }));
    // a rasteriser that never comes back, with a child of its own — both write their pids down
    const bin = join(dir, 'bin'); mkdirSync(bin);
    const pids = join(dir, 'pids');
    executable(join(bin, 'pdfinfo'), `#!/bin/sh\nsleep 300 &\necho "$$ $!" > "${pids}"\nwait\n`);
    writeFileSync(join(dir, 'x.pdf'), '%PDF-1.4 a stand-in');
    const env = { ...process.env, PATH: `${bin}:/usr/bin:/bin` };
    let childPid = null;
    const t0 = Date.now();
    const r = await normaliseInChild(join(dir, 'x.pdf'), dir, { timeoutMs: 4000, env, onSpawn: (pid) => { childPid = pid; } });
    assert.deepEqual([r.ok, r.stop, r.detail], [false, 'unusable_input', 'intake_timeout']);
    assert.ok(Date.now() - t0 < 6000);
    assert.ok(existsSync(pids), 'the converter had started');
    const converter = readFileSync(pids, 'utf8').trim().split(' ').map(Number);
    assert.equal(converter.length, 2);
    const gone = async () => { for (let i = 0; i < 40; i++) { if (![childPid, ...converter].some((p) => alive(p))) return true; await new Promise((ok) => setTimeout(ok, 50)); } return false; };
    assert.ok(await gone(), `still alive: ${[childPid, ...converter].filter((p) => alive(p))}`);

    // the same for an abort from outside
    rmSync(pids);
    const controller = new AbortController();
    const aborted = normaliseInChild(join(dir, 'x.pdf'), dir, { env, signal: controller.signal, onSpawn: (pid) => { childPid = pid; } });
    for (let i = 0; i < 100 && !existsSync(pids); i++) await new Promise((ok) => setTimeout(ok, 50));
    await new Promise((ok) => setTimeout(ok, 50));
    const second = readFileSync(pids, 'utf8').trim().split(' ').map(Number);
    controller.abort();
    assert.equal((await aborted).detail, 'aborted');
    converter.splice(0, 2, ...second);
    assert.ok(await gone(), `still alive after the abort: ${[childPid, ...converter].filter((p) => alive(p))}`);
});
