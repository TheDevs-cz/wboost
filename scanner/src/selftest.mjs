// `scanner selftest`: is everything a scan needs inside this image / on this machine — without the app,
// without the provider, without the network (the image is checked with `--network none`)?
//   sharp · the OCR sidecar with its models (a fixed picture → the expected line) · the engine binary of the
//   Agent SDK for this platform · a HEIC decoder · a writable scratch root · not running as root
import { execFile } from 'node:child_process';
import { createRequire } from 'node:module';
import { mkdirSync, rmSync, writeFileSync, existsSync, readdirSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import sharp from 'sharp';
import { runOcr, mergeLines, ocrPython } from './instruments/ocr.mjs';
import { normaliseInChild } from './intake/normalise.mjs';
import { packageVersion } from './config.mjs';
import { memoryLimitBytes } from './proc.mjs';

const HERE = dirname(fileURLToPath(import.meta.url));
const run = (bin, args, timeout = 20_000) => new Promise((ok) => execFile(bin, args, { timeout }, (err, stdout, stderr) => ok({ ok: !err, out: String(stdout || stderr).trim().split('\n')[0] ?? '', error: err ? String(err.code ?? err.message) : null })));

/** The engine binary the Agent SDK starts on this platform (its optional dependency for os / cpu / libc). */
export function engineBinary() {
    const require = createRequire(import.meta.url);
    const sdk = dirname(require.resolve('@anthropic-ai/claude-agent-sdk'));
    const scope = dirname(sdk);
    const found = readdirSync(scope).filter((d) => d.startsWith(`claude-agent-sdk-${process.platform}-${process.arch}`)).map((d) => join(scope, d, process.platform === 'win32' ? 'claude.exe' : 'claude')).filter((p) => existsSync(p));
    return found;
}

export async function selftest({ scratch = process.env.SCANNER_SCRATCH_DIR || join(tmpdir(), 'wboost-scanner') } = {}) {
    const checks = [];
    const check = async (name, fn) => { const t0 = Date.now(); try { const detail = await fn(); checks.push({ name, ok: true, ms: Date.now() - t0, ...(detail ?? {}) }); } catch (e) { checks.push({ name, ok: false, ms: Date.now() - t0, error: String(e?.message ?? e).slice(0, 200) }); } };
    await check('user', async () => { const uid = process.getuid?.() ?? null; if (uid === 0) throw new Error('running as root'); return { uid }; });
    await check('sharp', async () => {
        const png = await sharp({ create: { width: 64, height: 64, channels: 3, background: '#0b3d6b' } }).png().toBuffer();
        const m = await sharp(await sharp(png).webp({ lossless: true }).toBuffer()).metadata();
        if (m.format !== 'webp' || m.width !== 64) throw new Error('round trip failed');
        return { vips: sharp.versions.vips };
    });
    await check('ocr', async () => {
        const r = await runOcr(join(HERE, 'instruments', 'ocr', 'selftest.png'), { threads: 2, timeoutMs: 120_000 });
        const text = mergeLines(r.lines).map((l) => l.text).join(' | ');
        if (text !== 'Letni kemp 2027') throw new Error(`the OCR sidecar read "${text.slice(0, 40)}"`);
        return { python: ocrPython(), seconds: r.seconds, loadSeconds: r.loadSeconds, peakRssMb: r.peakRssMb };
    });
    await check('engine', async () => {
        const bins = engineBinary();
        if (!bins.length) throw new Error(`no engine binary for ${process.platform}-${process.arch}`);
        const v = await run(bins[0], ['--version']);
        if (!v.ok) throw new Error(`the engine binary does not start: ${v.error}`);
        return { version: v.out, binaries: bins.length };
    });
    await check('heic', async () => {
        // a real HEIC (HEVC) through the intake's own child process: the converter AND its decoder must be there
        const dir = join(scratch, 'selftest-heic'); mkdirSync(dir, { recursive: true });
        try {
            const r = await normaliseInChild(join(HERE, 'intake', 'selftest.heic'), dir);
            if (!r.ok) throw new Error(`a HEIC upload ends as ${r.stop} / ${r.detail}`);
            return { format: r.meta.format, working: `${r.meta.workingWidth}x${r.meta.workingHeight}`, peakRssMb: r.resources?.peakRssMb ?? null };
        } finally { rmSync(dir, { recursive: true, force: true }); }
    });
    await check('scratch', async () => { const d = join(scratch, 'selftest'); mkdirSync(d, { recursive: true }); writeFileSync(join(d, 'x'), 'x'); rmSync(d, { recursive: true, force: true }); return { dir: scratch }; });
    const limit = memoryLimitBytes();
    return { ok: checks.every((c) => c.ok), version: `${packageVersion()}${process.env.SCANNER_BUILD ? `+${process.env.SCANNER_BUILD.slice(0, 12)}` : ''}`, node: process.version, platform: `${process.platform}/${process.arch}`, memoryLimitMb: limit === null ? null : Math.round(limit / 1048576), checks };
}
