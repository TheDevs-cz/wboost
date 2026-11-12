// OCR instrument: runs the RapidOCR sidecar (one short python process per call, thread count pinned)
// and merges its word groups into lines.
import { spawn } from 'node:child_process';
import { existsSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { union } from './raster.mjs';

const HERE = dirname(fileURLToPath(import.meta.url));
export const OCR_SCRIPT = join(HERE, 'ocr', 'ocr.py');

/** The python that has rapidocr: SCANNER_OCR_PYTHON, else scanner/.venv, else python3 on the PATH. */
export function ocrPython(env = process.env) {
    if (env.SCANNER_OCR_PYTHON) return env.SCANNER_OCR_PYTHON;
    const venv = resolve(HERE, '..', '..', '.venv', 'bin', 'python');
    return existsSync(venv) ? venv : 'python3';
}

/**
 * Runs the sidecar on an image file. The child is the leader of its own process group, so an abort
 * (signal) or the timeout kills python and every thread pool it started.
 * → {lines: [{text, conf, box}], seconds, loadSeconds, peakRssMb, cpuSeconds, wallMs, pid}
 */
export function runOcr(imagePath, { threads = 3, scale = 1, timeoutMs = 30_000, signal = null, python = ocrPython(), onSpawn = null } = {}) {
    return new Promise((done, fail) => {
        const started = Date.now();
        const child = spawn(python, [OCR_SCRIPT, imagePath, '--threads', String(threads), '--scale', String(scale)], {
            stdio: ['ignore', 'pipe', 'pipe'], detached: true,
            env: { ...process.env, OMP_NUM_THREADS: String(threads), OMP_THREAD_LIMIT: String(threads) },
        });
        onSpawn?.(child.pid);
        let out = '', err = '', settled = false;
        const kill = () => { try { process.kill(-child.pid, 'SIGKILL'); } catch { /* gone */ } };
        const finish = (fn, v) => { if (settled) return; settled = true; clearTimeout(timer); signal?.removeEventListener('abort', onAbort); fn(v); };
        const timer = setTimeout(() => { kill(); finish(fail, Object.assign(new Error(`OCR timed out after ${timeoutMs} ms`), { code: 'tool_timeout' })); }, timeoutMs);
        const onAbort = () => { kill(); finish(fail, Object.assign(new Error('OCR aborted'), { code: 'aborted' })); };
        signal?.addEventListener('abort', onAbort, { once: true });
        if (signal?.aborted) onAbort();
        child.stdout.on('data', (d) => { out += d; });
        child.stderr.on('data', (d) => { err += d; });
        child.on('error', (e) => finish(fail, Object.assign(new Error(`OCR could not start (${python}): ${e.message}`), { code: 'ocr_unavailable' })));
        child.on('close', (code) => {
            if (code !== 0) return finish(fail, Object.assign(new Error(`OCR exited ${code}: ${err.trim().split('\n').pop() ?? ''}`), { code: 'ocr_failed' }));
            try {
                const j = JSON.parse(out.trim().split('\n').pop());
                j.lines = j.lines.map((l) => ({ text: l.text, conf: l.conf, box: { x: l.box[0], y: l.box[1], w: l.box[2], h: l.box[3] } }));
                finish(done, { ...j, wallMs: Date.now() - started, pid: child.pid });
            } catch (e) { finish(fail, Object.assign(new Error(`OCR output unreadable: ${e.message}`), { code: 'ocr_failed' })); }
        });
    });
}

/**
 * PP-OCR detects word groups; glue fragments that sit on one baseline into lines (left to right).
 * A fragment's box height says little about the type size — a word with a descender or an accent is
 * boxed up to 1.6× as tall as its neighbour "2027" — so fragments are compared with the TYPICAL
 * (median) fragment height of the line, never with the union box, which only grows.
 */
export function mergeLines(lines) {
    const items = lines.filter((l) => String(l.text).trim() !== '').map((l) => ({ ...l })).sort((a, b) => a.box.x - b.box.x);
    const out = [];
    const typical = (o) => { const h = [...o.heights].sort((p, q) => p - q); return h[h.length >> 1]; };
    for (const it of items) {
        let host = null;
        for (const o of out) {
            const a = o.box, b = it.box, last = o.last;
            const ha = typical(o);
            const vOverlap = Math.min(a.y + a.h, b.y + b.h) - Math.max(a.y, b.y);
            const gap = b.x - (a.x + a.w);
            const hRatio = Math.min(ha, b.h) / Math.max(ha, b.h);
            // the neighbour it would continue: their vertical centres must sit on one line
            const centres = Math.abs((last.y + last.h / 2) - (b.y + b.h / 2)) < 0.4 * Math.min(last.h, b.h);
            if (vOverlap > 0.6 * Math.min(ha, b.h) && hRatio > 0.5 && centres && gap < 0.9 * Math.max(ha, b.h) && gap > -0.5 * Math.min(a.w, b.w)) { host = o; break; }
        }
        if (!host) { out.push({ text: it.text.trim(), conf: it.conf, box: { ...it.box }, heights: [it.box.h], last: it.box }); continue; }
        // overlapping fragments repeat characters ("bilé" + "é krvinky,"): drop the duplicated prefix
        let add = it.text;
        const gap = it.box.x - (host.box.x + host.box.w);
        if (gap < 0) {
            for (let k = Math.min(3, add.length, host.text.length); k > 0; k--) {
                if (host.text.endsWith(add.slice(0, k))) { add = add.slice(k); break; }
            }
        }
        host.text = (host.text + ' ' + add.trim()).replace(/\s+/g, ' ').trim();
        host.conf = Math.min(host.conf, it.conf);
        host.box = union([host.box, it.box]);
        host.heights.push(it.box.h); host.last = it.box;
    }
    return out.sort((a, b) => (a.box.y + a.box.h / 2) - (b.box.y + b.box.h / 2) || a.box.x - b.box.x)
        .map((l, i) => ({ id: i + 1, text: l.text, conf: +l.conf.toFixed(3), box: { x: Math.round(l.box.x), y: Math.round(l.box.y), w: Math.round(l.box.w), h: Math.round(l.box.h) } }));
}
