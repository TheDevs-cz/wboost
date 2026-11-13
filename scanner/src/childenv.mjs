// The environments of the scanner's child processes — who gets which variable.
//
//   main process            everything (it holds SCANNER_TOKEN and talks to the app)
//   a run's worker          everything BUT the app token: the token it needs reaches it with the job, over IPC, and
//                           is never in its environment. The provider credential stays — the worker is where the
//                           engine is started (ANTHROPIC_API_KEY, or CLAUDE_CODE_OAUTH_TOKEN, or neither: the
//                           plan login of a developer's machine, found through HOME)
//   a decoder               a process that reads untrusted bytes — the intake's normalise child, the HEIC / PDF
//                           converters, the OCR sidecar — gets a short allowlist: no token, no provider credential,
//                           nothing else of the scanner's environment
//   the engine              engine/sdk.mjs `engineEnv` (the provider credential, never the app token)
import { existsSync, statSync } from 'node:fs';
import { delimiter, join } from 'node:path';

/** Variables no decoder may ever see. The tests hold every child environment against this list. */
export const SECRET_VARIABLES = Object.freeze(['SCANNER_TOKEN', 'ANTHROPIC_API_KEY', 'ANTHROPIC_AUTH_TOKEN', 'CLAUDE_CODE_OAUTH_TOKEN']);

const DEFAULT_PATH = '/usr/local/bin:/usr/bin:/bin';
/** What a decoder may inherit besides the PATH: where temporary files go (a run's scratch directory). */
const DECODER_PASS = ['TMPDIR'];

/** The environment of a process that decodes untrusted bytes: an allowlist, plus what the caller names. */
export function decoderEnv(extra = {}, base = process.env) {
    const env = { PATH: base.PATH || DEFAULT_PATH, LANG: 'C' };
    for (const key of DECODER_PASS) if (base[key]) env[key] = base[key];
    return { ...env, ...extra };
}

/**
 * The OCR sidecar (python + onnxruntime): the decoder's environment, its pinned thread pools, UTF-8 on its
 * stdout whatever the locale, and HOME — python looks for its user site there; the OCR models are inside the
 * virtualenv (fetched when the image is built), nothing is read from a cache in the home.
 */
export function ocrEnv(threads, base = process.env) {
    return decoderEnv({
        OMP_NUM_THREADS: String(threads), OMP_THREAD_LIMIT: String(threads),
        PYTHONUTF8: '1', PYTHONIOENCODING: 'utf-8', PYTHONDONTWRITEBYTECODE: '1',
        ...(base.HOME ? { HOME: base.HOME } : {}),
    }, base);
}

/** The environment of a run's worker process: the scanner's own without the app token (see the table above). */
export function workerEnv(base = process.env, extra = {}) {
    const env = { ...base };
    delete env.SCANNER_TOKEN;
    return { ...env, ...extra };
}

const found = new Map();
/** The absolute path of a program on the PATH, or null. Asked once per program and PATH. */
export function onPath(bin, path = process.env.PATH || DEFAULT_PATH) {
    const key = `${bin}\n${path}`;
    if (!found.has(key)) {
        let hit = null;
        for (const dir of path.split(delimiter)) {
            if (!dir) continue;
            const p = join(dir, bin);
            try { if (existsSync(p) && statSync(p).isFile()) { hit = p; break; } } catch { /* unreadable entry */ }
        }
        found.set(key, hit);
    }
    return found.get(key);
}
