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
import { decoderEnv, onPath } from '../childenv.mjs';

const run = promisify(execFile);
const HERE = dirname(fileURLToPath(import.meta.url));

export const UPLOAD_LIMITS = Object.freeze({ maxBytes: 10_000_000, maxSidePx: 8000, maxPixels: 36_000_000, minShortSidePx: 600 });
export const WORKING_COPY = Object.freeze({ longEdgePx: 4096, previewLongEdgePx: 1600 });

const stop = (code, detail) => ({ ok: false, stop: code, detail });
const isHeic = (buf) => buf.length > 12 && buf.toString('latin1', 4, 8) === 'ftyp' && /^(heic|heix|hevc|hevx|heim|heis|mif1|msf1)$/.test(buf.toString('latin1', 8, 12));

const isPdf = (buf) => buf.subarray(0, 1024).includes('%PDF-');
/** A converter gets the PATH and nothing else of the scanner's environment (no token, no provider key). */
const TOOL_ENV = decoderEnv({}, { PATH: process.env.PATH });
/**
 * The address space an external converter (heif-convert, pdfinfo, pdftoppm) may map: 1.5 GiB. The largest legal
 * upload needs a fraction of it; a file whose header lies about its size, or a PDF with an enormous embedded
 * picture, fails to allocate and the converter ends — named `undecodable` / `pdf_unreadable` — instead of
 * taking the container's memory. Enforced with util-linux's `prlimit` where it is on the PATH (the image);
 * a developer's macOS has none and runs the converter unlimited.
 */
export const TOOL_ADDRESS_SPACE_BYTES = 1536 * 1024 * 1024;

/**
 * Runs one external converter: minimal environment, the address-space limit, SIGKILL at the timeout.
 * A program that is not installed is `ENOENT`, with or without the limiter in front of it.
 */
export async function runTool(bin, args, { timeoutMs = 15_000, addressSpaceBytes = TOOL_ADDRESS_SPACE_BYTES, env = TOOL_ENV } = {}) {
    const program = onPath(bin, env.PATH);
    if (!program) throw Object.assign(new Error(`${bin} is not installed`), { code: 'ENOENT' });
    const limiter = process.platform === 'linux' && addressSpaceBytes > 0 ? onPath('prlimit', env.PATH) : null;
    const argv = limiter ? [limiter, `--as=${Math.round(addressSpaceBytes)}`, '--', program, ...args] : [program, ...args];
    return run(argv[0], argv.slice(1), { timeout: timeoutMs, killSignal: 'SIGKILL', env });
}

/**
 * The canvas a HEIF file DECLARES (the largest `ispe` property of its `meta` box), read without decoding
 * anything → {width, height} | null. The converter is never started on a file that declares more than the
 * upload limits allow — or declares nothing.
 */
export function heicDeclaredSize(buf) {
    let meta = null;
    for (let at = 0; at + 8 <= buf.length;) {
        let size = buf.readUInt32BE(at), head = 8;
        if (size === 1) { if (at + 16 > buf.length) break; size = Number(buf.readBigUInt64BE(at + 8)); head = 16; } else if (size === 0) size = buf.length - at;
        if (size < head) break;
        if (buf.toString('latin1', at + 4, at + 8) === 'meta') { meta = buf.subarray(at + head, Math.min(buf.length, at + size)); break; }
        at += size;
    }
    if (!meta) return null;
    let width = 0, height = 0;
    // ispe: size 20, 'ispe', version + flags, width, height
    for (let at = meta.indexOf('ispe', 4, 'latin1'); at !== -1 && at + 16 <= meta.length; at = meta.indexOf('ispe', at + 4, 'latin1')) {
        if (meta.readUInt32BE(at - 4) !== 20) continue;
        const w = meta.readUInt32BE(at + 8), h = meta.readUInt32BE(at + 12);
        if (w * h > width * height) { width = w; height = h; }
    }
    return width && height ? { width, height } : null;
}
export const PDF_PAGE = Object.freeze({ dpi: 300, minLongEdgePx: 1600 });

/**
 * Page 1 of a PDF as a PNG. Resolution: the page at `dpi` (300 — the resolution a print template is made at:
 * an A4 becomes 2480 × 3508 px, the size a designer would give that template), but the long edge never above
 * the working copy's bound (a poster) and never below `minLongEdgePx` (a business card or a page authored in
 * "pixels" would come out too small to read its small print).
 * → {png, pages, pageMm: [w, h]} | {error: 'pdf_unsupported' | 'pdf_unreadable' | 'pdf_encrypted' | 'pdf_empty'}
 */
async function pdfFirstPage(buf, { longEdgePx, dpi = PDF_PAGE.dpi, minLongEdgePx = PDF_PAGE.minLongEdgePx, timeoutMs = 15_000, addressSpaceBytes = TOOL_ADDRESS_SPACE_BYTES }) {
    const dir = await mkdtemp(join(tmpdir(), 'scan-pdf-'));
    try {
        const src = join(dir, 'in.pdf'), out = join(dir, 'page');
        await writeFile(src, buf);
        let info;
        try { info = (await runTool('pdfinfo', ['-f', '1', '-l', '1', src], { timeoutMs, addressSpaceBytes })).stdout; } catch (e) {
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
        try { await runTool('pdftoppm', ['-f', '1', '-l', '1', '-singlefile', '-png', '-scale-to', String(long), src, out], { timeoutMs, addressSpaceBytes }); } catch (e) {
            return { error: e?.code === 'ENOENT' ? 'pdf_unsupported' : 'pdf_unreadable' };
        }
        const mm = (pt) => +(pt * 25.4 / 72).toFixed(1);
        return { png: await readFile(`${out}.png`), pages, pageMm: [mm(wPt), mm(hPt)], dpi: Math.round(long / Math.max(wPt, hPt) * 72) };
    } catch { return { error: 'pdf_unreadable' }; } finally { await rm(dir, { recursive: true, force: true }); }
}

/**
 * HEIC (HEVC) is not in sharp's prebuilt decoder: convert with a system tool when one exists.
 * → {png} | {error: 'heic_unsupported' (no converter is installed) | 'undecodable' (one ran and failed: a broken
 *   file, a timeout, or a picture it could not hold inside its address-space limit)}
 */
async function heicToPng(buf, { addressSpaceBytes = TOOL_ADDRESS_SPACE_BYTES } = {}) {
    const dir = await mkdtemp(join(tmpdir(), 'scan-heic-'));
    try {
        const src = join(dir, 'in.heic'), out = join(dir, 'out.png');
        await writeFile(src, buf);
        const attempts = [['heif-convert', [src, out]], ['heif-dec', [src, out]], ['magick', [src + '[0]', out]], ...(process.platform === 'darwin' ? [['sips', ['-s', 'format', 'png', src, '--out', out]]] : [])];
        let ran = false;
        for (const [bin, args] of attempts) {
            try { await runTool(bin, args, { timeoutMs: 30_000, addressSpaceBytes }); return { png: await readFile(out) }; } catch (e) { if (e?.code !== 'ENOENT') ran = true; /* try the next tool */ }
        }
        return { error: ran ? 'undecodable' : 'heic_unsupported' };
    } finally { await rm(dir, { recursive: true, force: true }); }
}

/**
 * → {ok: true, working: Buffer, workingPng: Buffer, preview: Buffer, meta} or {ok: false, stop, detail}.
 * `stop` is a stop reason of architecture §5.6: `unusable_input` or `blurry_or_small`.
 */
export async function normalise(input, { limits = UPLOAD_LIMITS, working = WORKING_COPY, format = 'webp', toolAddressSpaceBytes = TOOL_ADDRESS_SPACE_BYTES } = {}) {
    limits = { ...UPLOAD_LIMITS, ...limits }; // a caller that leaves a limit out does not switch the check off
    let buf = Buffer.isBuffer(input) ? input : await readFile(input);
    if (buf.length === 0) return stop('unusable_input', 'empty');
    if (buf.length > limits.maxBytes) return stop('unusable_input', 'too_many_bytes');
    const open = (b) => sharp(b, { limitInputPixels: limits.maxPixels, failOn: 'error', pages: 1 });
    let meta, converted = false, pdf = null;
    if (isPdf(buf)) {
        pdf = await pdfFirstPage(buf, { longEdgePx: working.longEdgePx, addressSpaceBytes: toolAddressSpaceBytes });
        if (pdf.error) return stop('unusable_input', pdf.error);
        buf = pdf.png;
    }
    try {
        meta = await open(buf).metadata();
        if (meta.format === 'heif' && meta.compression !== 'av1') throw new Error('hevc');
    } catch (e) {
        const tooMany = /pixel limit/i.test(String(e.message));
        if (!isHeic(buf)) return stop('unusable_input', tooMany ? 'too_many_pixels' : 'undecodable');
        // The limits are applied to what the file DECLARES before the system converter sees it: the header sharp
        // read, and the container's own size property. A HEIC that declares nothing is not converted at all.
        if (tooMany) return stop('unusable_input', 'too_many_pixels');
        const declared = [meta?.width && meta?.height ? { width: meta.width, height: meta.height } : null, heicDeclaredSize(buf)].filter(Boolean);
        if (!declared.length) return stop('unusable_input', 'undecodable');
        if (declared.some((d) => Math.max(d.width, d.height) > limits.maxSidePx)) return stop('unusable_input', 'side_too_long');
        if (declared.some((d) => d.width * d.height > limits.maxPixels)) return stop('unusable_input', 'too_many_pixels');
        const heic = await heicToPng(buf, { addressSpaceBytes: toolAddressSpaceBytes });
        if (heic.error) return stop('unusable_input', heic.error);
        buf = heic.png; converted = true;
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
export function normaliseInChild(inputPath, outDir, { timeoutMs = 20_000, heapMb = 384, format = 'webp', limits = UPLOAD_LIMITS, working = WORKING_COPY, toolAddressSpaceBytes = TOOL_ADDRESS_SPACE_BYTES, onSpawn = null, signal = null, env = process.env, script = join(HERE, 'normalise-child.mjs') } = {}) {
    return new Promise((resolve) => {
        // The child LEADS a process group of its own — also below a run's worker — so that the timeout, an abort
        // and its own death take the converters it started (heif-convert, pdftoppm) with it: killing the node
        // process alone would leave them running. The main process still finds all of them through the parent
        // chain when it kills a run's tree (proc.mjs). Its environment is the decoder's: no token, no provider key.
        const child = spawn(process.execPath, [`--max-old-space-size=${heapMb}`, script, inputPath, outDir, JSON.stringify({ format, limits, working, toolAddressSpaceBytes })], { stdio: ['ignore', 'pipe', 'pipe'], detached: true, env: decoderEnv({}, env) });
        onSpawn?.(child.pid);
        let out = '', done = false;
        const kill = () => { try { process.kill(-child.pid, 'SIGKILL'); } catch { try { child.kill('SIGKILL'); } catch { /* gone */ } } };
        // (on every ending: whatever of the group outlived the node process is killed with it)
        const finish = (v) => { if (done) return; done = true; clearTimeout(timer); signal?.removeEventListener('abort', onAbort); kill(); resolve(v); };
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
