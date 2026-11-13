// Intake, step 1: turn an upload into the working copy everything else uses — sRGB, 8 bit, first
// frame, upright, alpha flattened on white, long edge ≤ 4096 px — plus a small preview.
// The web process never decodes a picture; this is the only place an untrusted file is decoded, and
// it runs in a child process of its own (normaliseInChild) with a decoder pixel limit.
// A PDF is taken as a PICTURE of its first page (owner decision O4): rasterised here by poppler's
// `pdftoppm`, then treated like any other upload. No page picker, no reading of its text or fonts.
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

const isPdf = (buf) => buf.subarray(0, 1024).includes('%PDF-');
/** A converter gets the PATH and nothing else of the scanner's environment (no token, no provider key). */
const TOOL_ENV = { PATH: process.env.PATH ?? '/usr/local/bin:/usr/bin:/bin', LANG: 'C' };
export const PDF_PAGE = Object.freeze({ dpi: 300, minLongEdgePx: 1600 });

/**
 * Page 1 of a PDF as a PNG. Resolution: the page at `dpi` (300 — the resolution a print template is made at:
 * an A4 becomes 2480 × 3508 px, the size a designer would give that template), but the long edge never above
 * the working copy's bound (a poster) and never below `minLongEdgePx` (a business card or a page authored in
 * "pixels" would come out too small to read its small print).
 * → {png, pages, pageMm: [w, h]} | {error: 'pdf_unsupported' | 'pdf_unreadable' | 'pdf_encrypted' | 'pdf_empty'}
 */
async function pdfFirstPage(buf, { longEdgePx, dpi = PDF_PAGE.dpi, minLongEdgePx = PDF_PAGE.minLongEdgePx, timeoutMs = 15_000 }) {
    const dir = await mkdtemp(join(tmpdir(), 'scan-pdf-'));
    try {
        const src = join(dir, 'in.pdf'), out = join(dir, 'page');
        await writeFile(src, buf);
        let info;
        try { info = (await run('pdfinfo', ['-f', '1', '-l', '1', src], { timeout: timeoutMs, env: TOOL_ENV })).stdout; } catch (e) {
            if (e?.code === 'ENOENT') return { error: 'pdf_unsupported' };
            return { error: /Incorrect password|encrypt/i.test(String(e?.stderr ?? '')) ? 'pdf_encrypted' : 'pdf_unreadable' };
        }
        const pages = Number(/^Pages:\s+(\d+)/m.exec(info)?.[1] ?? 0);
        const size = /^Page\s+1 size:\s+([\d.]+) x ([\d.]+) pts/m.exec(info);
        const turned = [90, 270].includes(Number(/^Page\s+1 rot:\s+(\d+)/m.exec(info)?.[1] ?? 0));
        if (!pages || !size) return { error: 'pdf_empty' };
        const [wPt, hPt] = turned ? [Number(size[2]), Number(size[1])] : [Number(size[1]), Number(size[2])];
        if (!(wPt > 0 && hPt > 0)) return { error: 'pdf_empty' };
        const long = Math.round(Math.max(minLongEdgePx, Math.min(longEdgePx, Math.max(wPt, hPt) * dpi / 72)));
        try { await run('pdftoppm', ['-f', '1', '-l', '1', '-singlefile', '-png', '-scale-to', String(long), src, out], { timeout: timeoutMs, env: TOOL_ENV }); } catch (e) {
            return { error: e?.code === 'ENOENT' ? 'pdf_unsupported' : 'pdf_unreadable' };
        }
        const mm = (pt) => +(pt * 25.4 / 72).toFixed(1);
        return { png: await readFile(`${out}.png`), pages, pageMm: [mm(wPt), mm(hPt)], dpi: Math.round(long / Math.max(wPt, hPt) * 72) };
    } catch { return { error: 'pdf_unreadable' }; } finally { await rm(dir, { recursive: true, force: true }); }
}

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
    let meta, converted = false, pdf = null;
    if (isPdf(buf)) {
        pdf = await pdfFirstPage(buf, { longEdgePx: working.longEdgePx });
        if (pdf.error) return stop('unusable_input', pdf.error);
        buf = pdf.png;
    }
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
            format: pdf ? 'pdf' : converted ? 'heic' : meta.format, bytes: Buffer.isBuffer(input) ? input.length : buf.length, width, height,
            workingWidth: info.width, workingHeight: info.height, workingFormat: format, scale: +(info.width / width).toFixed(5),
            space: meta.space ?? null, depth: meta.depth ?? null, hasAlpha: !!meta.hasAlpha, orientation: meta.orientation ?? 1,
            frames: pdf ? pdf.pages : (meta.pages ?? 1), density: pdf ? pdf.dpi : (meta.density ?? null),
            ...(pdf ? { pageMm: pdf.pageMm } : {}),
            facts: [
                ...(meta.space && meta.space !== 'srgb' && meta.space !== 'rgb' ? [`converted_from_${meta.space}`] : []),
                ...(meta.depth && meta.depth !== 'uchar' ? ['reduced_to_8_bit'] : []),
                ...(pdf ? ['pdf_first_page'] : []),
                ...((pdf ? pdf.pages : (meta.pages ?? 1)) > 1 ? ['first_frame_only'] : []),
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
export function normaliseInChild(inputPath, outDir, { timeoutMs = 20_000, heapMb = 384, format = 'webp', limits = UPLOAD_LIMITS, working = WORKING_COPY, onSpawn = null, signal = null } = {}) {
    return new Promise((resolve) => {
        const detached = process.env.SCANNER_WORKER !== '1'; // inside a run's child process it stays in that process's group
        const child = spawn(process.execPath, [`--max-old-space-size=${heapMb}`, join(HERE, 'normalise-child.mjs'), inputPath, outDir, JSON.stringify({ format, limits, working })], { stdio: ['ignore', 'pipe', 'pipe'], detached });
        onSpawn?.(child.pid);
        let out = '', done = false;
        const kill = () => { try { if (detached) process.kill(-child.pid, 'SIGKILL'); else child.kill('SIGKILL'); } catch { /* gone */ } };
        const finish = (v) => { if (done) return; done = true; clearTimeout(timer); signal?.removeEventListener('abort', onAbort); resolve(v); };
        const timer = setTimeout(() => { kill(); finish({ ok: false, stop: 'unusable_input', detail: 'intake_timeout' }); }, timeoutMs);
        const onAbort = () => { kill(); finish({ ok: false, stop: 'unusable_input', detail: 'aborted' }); };
        signal?.addEventListener('abort', onAbort, { once: true });
        if (signal?.aborted) onAbort();
        child.stdout.on('data', (d) => { out += d; });
        child.on('error', () => finish({ ok: false, stop: 'unusable_input', detail: 'intake_crashed' }));
        child.on('close', (code, sig) => {
            try { finish({ ...JSON.parse(out.trim().split('\n').pop()), exit: { code, signal: sig } }); } catch { finish({ ok: false, stop: 'unusable_input', detail: 'intake_crashed', exit: { code, signal: sig } }); }
        });
    });
}
