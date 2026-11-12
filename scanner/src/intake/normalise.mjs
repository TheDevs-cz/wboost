// Intake, step 1: turn an upload into the working copy everything else uses — sRGB, 8 bit, first
// frame, upright, alpha flattened on white, long edge ≤ 4096 px — plus a small preview.
// The web process never decodes a picture; this is the only place an untrusted file is decoded, and
// it runs in a child process of its own (normaliseInChild) with a decoder pixel limit.
import sharp from 'sharp';
import { spawn, execFile } from 'node:child_process';
import { promisify } from 'node:util';
import { readFile, writeFile, mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const run = promisify(execFile);
const HERE = dirname(fileURLToPath(import.meta.url));

export const UPLOAD_LIMITS = Object.freeze({ maxBytes: 10_000_000, maxSidePx: 8000, maxPixels: 36_000_000, minShortSidePx: 600 });
export const WORKING_COPY = Object.freeze({ longEdgePx: 4096, previewLongEdgePx: 1600 });

const stop = (code, detail) => ({ ok: false, stop: code, detail });
const isHeic = (buf) => buf.length > 12 && buf.toString('latin1', 4, 8) === 'ftyp' && /^(heic|heix|hevc|hevx|heim|heis|mif1|msf1)$/.test(buf.toString('latin1', 8, 12));

/** HEIC (HEVC) is not in sharp's prebuilt decoder: convert with a system tool when one exists. */
async function heicToPng(buf) {
    const dir = await mkdtemp(join(tmpdir(), 'scan-heic-'));
    try {
        const src = join(dir, 'in.heic'), out = join(dir, 'out.png');
        await writeFile(src, buf);
        const attempts = [['heif-convert', [src, out]], ['heif-dec', [src, out]], ['magick', [src + '[0]', out]], ...(process.platform === 'darwin' ? [['sips', ['-s', 'format', 'png', src, '--out', out]]] : [])];
        for (const [bin, args] of attempts) {
            try { await run(bin, args, { timeout: 30_000 }); return await readFile(out); } catch { /* try the next tool */ }
        }
        return null;
    } finally { await rm(dir, { recursive: true, force: true }); }
}

/**
 * → {ok: true, working: Buffer, workingPng: Buffer, preview: Buffer, meta} or {ok: false, stop, detail}.
 * `stop` is a stop reason of architecture §5.6: `unusable_input` or `blurry_or_small`.
 */
export async function normalise(input, { limits = UPLOAD_LIMITS, working = WORKING_COPY, format = 'webp' } = {}) {
    let buf = Buffer.isBuffer(input) ? input : await readFile(input);
    if (buf.length === 0) return stop('unusable_input', 'empty');
    if (buf.length > limits.maxBytes) return stop('unusable_input', 'too_many_bytes');
    const open = (b) => sharp(b, { limitInputPixels: limits.maxPixels, failOn: 'error', pages: 1 });
    let meta, converted = false;
    try {
        meta = await open(buf).metadata();
        if (meta.format === 'heif' && meta.compression !== 'av1') throw new Error('hevc');
    } catch (e) {
        if (!isHeic(buf)) return stop('unusable_input', /pixel limit/i.test(String(e.message)) ? 'too_many_pixels' : 'undecodable');
        const png = await heicToPng(buf);
        if (!png) return stop('unusable_input', 'heic_unsupported');
        buf = png; converted = true;
        try { meta = await open(buf).metadata(); } catch { return stop('unusable_input', 'undecodable'); }
    }
    if (meta.format === 'svg') return stop('unusable_input', 'vector');
    // EXIF orientations 5–8 swap the sides
    const swap = (meta.orientation ?? 1) >= 5;
    const width = swap ? meta.height : meta.width, height = swap ? meta.width : meta.height;
    if (!width || !height) return stop('unusable_input', 'undecodable');
    if (Math.max(width, height) > limits.maxSidePx) return stop('unusable_input', 'side_too_long');
    if (width * height > limits.maxPixels) return stop('unusable_input', 'too_many_pixels');
    if (Math.min(width, height) < limits.minShortSidePx) return stop('blurry_or_small', 'too_small');
    const pipeline = () => open(buf).rotate().flatten({ background: '#ffffff' }).toColourspace('srgb')
        .resize({ width: working.longEdgePx, height: working.longEdgePx, fit: 'inside', withoutEnlargement: true });
    let raw;
    try {
        raw = await pipeline().removeAlpha().raw({ depth: 'uchar' }).toBuffer({ resolveWithObject: true });
    } catch (e) {
        return stop('unusable_input', /pixel limit/i.test(String(e.message)) ? 'too_many_pixels' : 'undecodable');
    }
    const { info } = raw;
    const from = () => sharp(raw.data, { raw: { width: info.width, height: info.height, channels: info.channels } });
    const workingPng = await from().png({ compressionLevel: 3 }).toBuffer();
    const workingBytes = format === 'png' ? workingPng : await from().webp({ lossless: true, effort: 2 }).toBuffer();
    const preview = await from().resize({ width: working.previewLongEdgePx, height: working.previewLongEdgePx, fit: 'inside', withoutEnlargement: true }).webp({ quality: 82 }).toBuffer();
    return {
        ok: true, working: workingBytes, workingPng, preview,
        meta: {
            format: converted ? 'heic' : meta.format, bytes: Buffer.isBuffer(input) ? input.length : buf.length, width, height,
            workingWidth: info.width, workingHeight: info.height, workingFormat: format, scale: +(info.width / width).toFixed(5),
            space: meta.space ?? null, depth: meta.depth ?? null, hasAlpha: !!meta.hasAlpha, orientation: meta.orientation ?? 1,
            frames: meta.pages ?? 1, density: meta.density ?? null,
            facts: [
                ...(meta.space && meta.space !== 'srgb' && meta.space !== 'rgb' ? [`converted_from_${meta.space}`] : []),
                ...(meta.depth && meta.depth !== 'uchar' ? ['reduced_to_8_bit'] : []),
                ...((meta.pages ?? 1) > 1 ? ['first_frame_only'] : []),
                ...((meta.orientation ?? 1) !== 1 ? ['rotated_upright'] : []),
                ...(meta.hasAlpha ? ['alpha_flattened'] : []),
                ...(info.width !== width ? ['downscaled'] : []),
            ],
        },
    };
}

/**
 * Runs normalise() in a child process with a small heap and (on Linux) a raised OOM score, so a
 * hostile file takes the child down, not the scanner. Writes working.<fmt>, working.png and
 * preview.webp into `outDir`. → {ok, meta, files} | {ok: false, stop, detail}; a crash or a timeout is
 * reported as {ok: false, stop: 'unusable_input', detail: 'intake_crashed' | 'intake_timeout'}.
 */
export function normaliseInChild(inputPath, outDir, { timeoutMs = 20_000, heapMb = 384, format = 'webp', limits = UPLOAD_LIMITS, working = WORKING_COPY, onSpawn = null } = {}) {
    return new Promise((resolve) => {
        const child = spawn(process.execPath, [`--max-old-space-size=${heapMb}`, join(HERE, 'normalise-child.mjs'), inputPath, outDir, JSON.stringify({ format, limits, working })], { stdio: ['ignore', 'pipe', 'pipe'], detached: true });
        onSpawn?.(child.pid);
        let out = '', done = false;
        const finish = (v) => { if (done) return; done = true; clearTimeout(timer); resolve(v); };
        const timer = setTimeout(() => { try { process.kill(-child.pid, 'SIGKILL'); } catch { /* gone */ } finish({ ok: false, stop: 'unusable_input', detail: 'intake_timeout' }); }, timeoutMs);
        child.stdout.on('data', (d) => { out += d; });
        child.on('error', () => finish({ ok: false, stop: 'unusable_input', detail: 'intake_crashed' }));
        child.on('close', (code, signal) => {
            try { finish({ ...JSON.parse(out.trim().split('\n').pop()), exit: { code, signal } }); } catch { finish({ ok: false, stop: 'unusable_input', detail: 'intake_crashed', exit: { code, signal } }); }
        });
    });
}
