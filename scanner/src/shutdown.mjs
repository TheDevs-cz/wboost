// Shutdown (architecture §4.9 "Scanner shutdown", §7): on SIGTERM / SIGINT the scanner
//   1. stops claiming — at once;
//   2. aborts every running job: the run's child process gets ≤ 5 s to stop its engine and report its
//      last usage, then its process group (engine, OCR sidecar) is killed and the exit verified;
//   3. reports usage with ONE `release` attempt per job, cut at 20 s (a release that does not arrive
//      becomes a lost lease — rule 7);
//   4. removes the scratch root and exits, within 30 s whatever happens (compose: stop_grace_period 45s).
// No drain: a tag bump must not hold the box-wide deploy lock.
import { killTree } from './proc.mjs';

/**
 * @param scanner  {stop(reason) → Promise, children() → [pid]}
 * @param limitMs  the hard limit for the whole shutdown (30 s)
 * → {uninstall(), shutdown(reason) → Promise<exit code>}
 */
export function installShutdown({ scanner, limitMs = 30_000, log = () => {}, exit = (code) => process.exit(code), signals = ['SIGTERM', 'SIGINT'], proc = process }) {
    let running = null;
    const say = (...args) => { try { log(...args); } catch { /* a log that cannot be written never keeps the process alive */ } };
    const shutdown = (reason) => {
        if (running) return running;
        const started = Date.now();
        say('info', 'shutdown started', { reason });
        running = (async () => {
            let timer;
            const limit = new Promise((resolve) => { timer = setTimeout(() => resolve('limit'), limitMs); });
            const how = await Promise.race([scanner.stop(reason).then(() => 'clean', (e) => { say('error', 'shutdown failed', { error: String(e?.message ?? e).slice(0, 200) }); return 'failed'; }), limit]);
            clearTimeout(timer);
            const code = how === 'clean' ? 0 : 1;
            try {
                if (how !== 'clean') {
                    // the limit: nothing of a run may outlive the scanner
                    for (const pid of scanner.children?.() ?? []) await killTree(pid, { waitMs: 500 });
                }
                say(how === 'clean' ? 'info' : 'error', 'shutdown finished', { how, ms: Date.now() - started });
            } finally { exit(code); } // whatever went wrong above: the process ends
            return code;
        })();
        return running;
    };
    const handlers = signals.map((sig) => { const h = () => { shutdown(sig); }; proc.on(sig, h); return [sig, h]; });
    return { shutdown, uninstall: () => { for (const [sig, h] of handlers) proc.off(sig, h); } };
}
