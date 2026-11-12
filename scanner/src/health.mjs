// Health of the scanner process for a container healthcheck (architecture §7, observability):
// GET /health answers 200 while the scanner does its job and 503 when it does not — a lane with a free
// slot has not completed a claim poll for 60 s (the app is unreachable, or refuses this scanner's network
// or token), or the scanner is stopping. The body says why, in codes and numbers only.
//   node bin/scanner.mjs healthcheck      exit 0 / 1 — the compose healthcheck command
import { createServer } from 'node:http';
import { writeFile, rename } from 'node:fs/promises';

export const UNHEALTHY_AFTER_S = 60;

/**
 * @param s {startedAt, now, stopping, lanes: {name: Lane.status()}, app: {lastOkAt, lastError}, provider, protocol}
 * → {ok, reasons: [code], …}
 */
export function assess(s, { unhealthyAfterS = UNHEALTHY_AFTER_S } = {}) {
    const reasons = [];
    const upS = Math.round((s.now - s.startedAt) / 1000);
    if (s.stopping) reasons.push('stopping');
    for (const [name, lane] of Object.entries(s.lanes ?? {})) {
        if (!lane.running) { if (!s.stopping) reasons.push(`${name}_lane_stopped`); continue; }
        // a lane whose slots are all busy does not poll; its loop must still turn
        if (lane.active >= lane.slots) { if (lane.lastLoopAgoS !== null && lane.lastLoopAgoS > unhealthyAfterS + 600) reasons.push(`${name}_lane_stuck`); continue; }
        if (lane.held) continue; // held back on purpose (protocol mismatch): reported below, not a dead scanner
        const ago = lane.lastPollOkAgoS ?? upS; // never polled successfully: counted from the start
        if (ago > unhealthyAfterS) reasons.push(`${name}_claim_poll_overdue`);
    }
    return { ok: reasons.length === 0, reasons, upS };
}

export function startHealthServer({ port, host = '127.0.0.1', status, file = null, fileEveryMs = 5000 }) {
    const document = () => { const s = status(); const a = assess(s); return { code: a.ok ? 200 : 503, body: { status: a.ok ? 'ok' : 'unhealthy', reasons: a.reasons, upS: a.upS, version: s.version, lanes: s.lanes, app: s.app, provider: s.provider, protocol: s.protocol, mode: s.mode, concurrency: s.concurrency } }; };
    let server = null, timer = null;
    const ready = new Promise((resolve, reject) => {
        if (!port && port !== 0) return resolve(null);
        server = createServer((req, res) => {
            if (req.method !== 'GET' || (req.url !== '/health' && req.url !== '/')) { res.writeHead(404).end(); return; }
            const d = document();
            res.writeHead(d.code, { 'Content-Type': 'application/json', 'Cache-Control': 'no-store' }).end(JSON.stringify(d.body));
        });
        server.on('error', reject);
        server.listen(port, host, () => resolve(server.address().port));
    });
    if (file) {
        const write = async () => { try { const d = document(); await writeFile(`${file}.tmp`, JSON.stringify({ ...d.body, at: new Date().toISOString() })); await rename(`${file}.tmp`, file); } catch { /* a health file that cannot be written is the checker's finding */ } };
        write(); timer = setInterval(write, fileEveryMs); timer.unref?.();
    }
    return { ready, document, close: () => new Promise((resolve) => { clearInterval(timer); if (!server) return resolve(); server.closeAllConnections?.(); server.close(() => resolve()); }) };
}

/** The healthcheck command: asks the scanner of this container. → exit code. */
export async function healthcheck({ port = Number(process.env.SCANNER_HEALTH_PORT ?? 8787), host = process.env.SCANNER_HEALTH_HOST || '127.0.0.1', timeoutMs = 4000, out = console.log } = {}) {
    try {
        const res = await fetch(`http://${host === '0.0.0.0' ? '127.0.0.1' : host}:${port}/health`, { signal: AbortSignal.timeout(timeoutMs) });
        const body = await res.json().catch(() => ({}));
        out(JSON.stringify({ status: body.status ?? res.status, reasons: body.reasons ?? [] }));
        return res.status === 200 ? 0 : 1;
    } catch (e) {
        out(JSON.stringify({ status: 'unreachable', error: String(e?.cause?.code ?? e?.name ?? 'error') }));
        return 1;
    }
}
