// A build lease on the REAL local app without a model: starts a scan through the app's own upload
// endpoint (a synthetic picture), plays the intake as the scanner would minus the pre-check call, claims
// the build and keeps it alive with the real heartbeat. Used to measure things that need the app's
// `faces` / `render` endpoints (font verification) and to record the contract fixture.
// The scanner service must NOT be running against the same app meanwhile (it would take the claim).
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { readFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import sharp from 'sharp';
import { HttpScannerApi } from '../src/protocol.mjs';
import { Heartbeat } from '../src/timers.mjs';
import { normaliseInChild } from '../src/intake/normalise.mjs';
import { AppUser } from '../dev/app-user.mjs';

export const SYNTHETIC_SVG = '<svg xmlns="http://www.w3.org/2000/svg" width="1080" height="1350"><rect width="1080" height="1350" fill="#f4f1ea"/><rect x="80" y="90" width="920" height="420" fill="#0b3d6b"/><circle cx="820" cy="1050" r="160" fill="#c8102e"/><rect x="80" y="600" width="520" height="36" fill="#1c1c1c"/><rect x="80" y="670" width="380" height="36" fill="#1c1c1c"/></svg>';
const EMPTY = { provider: 'anthropic', model: null, inputTokens: 0, outputTokens: 0, cacheReadTokens: 0, cacheWriteTokens: 0, engineCostMicroUsd: 0, turns: 0, durationMs: 0 };

/**
 * → {user, scanId, scanner, intakeJob, job, api, heartbeat, close()}
 * `record` receives every request / answer of the scanner's client (the contract fixture).
 */
export async function holdBuildLease({ appUrl = process.env.WBOOST_APP_URL ?? 'http://localhost:8099', token = process.env.SCANNER_TOKEN ?? 'dev-scanner-token-not-a-secret-0000', projectId, record = null, picture = null, heartbeat = true } = {}) {
    const dir = mkdtempSync(join(tmpdir(), 'scan-lease-'));
    const file = join(dir, 'synthetic.png');
    writeFileSync(file, picture ?? await sharp(Buffer.from(SYNTHETIC_SVG)).png().toBuffer());
    const scanner = new HttpScannerApi({ baseUrl: appUrl, token, scannerVersion: '0.2.0+eval', record });
    await scanner.tick(); // the app must have seen a scanner before it accepts an upload
    const user = await new AppUser({ baseUrl: appUrl }).login();
    const started = await user.start(projectId, file);
    if (!started.ok) throw new Error(`the app refused the upload: ${started.code}`);
    const scanId = started.scan.id;
    const claimOf = async (lane) => { for (let i = 0; i < 20; i++) { const c = await scanner.claim(lane, 1); if (c.job) { if (c.job.id !== scanId) throw new Error(`claimed another scan (${c.job.id}): stop the scanner service and clear waiting scans first`); return c; } await new Promise((ok) => setTimeout(ok, 500)); } throw new Error(`no ${lane} job (mode ${(await scanner.tick()).mode})`); };
    const intake = await claimOf('intake');
    writeFileSync(join(dir, 'original.bin'), await intake.api.source());
    const n = await normaliseInChild(join(dir, 'original.bin'), dir);
    if (!n.ok) throw new Error(`normalise: ${n.stop} ${n.detail}`);
    const working = await readFile(n.files.working);
    await intake.api.putArtifact('working', working);
    await intake.api.putArtifact('preview', await readFile(n.files.preview));
    const intakeFinish = await intake.api.finish({ outcome: 'passed', preCheck: { verdict: 'design', confidence: 'high', approximate: false, ms: 0 }, sourceMeta: { ...n.meta, workingBytes: working.length }, usage: { ...EMPTY } });
    const build = await claimOf('build');
    const state = { stage: 'analyse', note: 'fonts', noteParams: null, round: 0, usage: { ...EMPTY } };
    const hb = heartbeat ? new Heartbeat({ api: build.api, timing: scanner.timing, state: () => state, onAbort: (r) => { console.error(`lease lost: ${r}`); } }).start() : null;
    rmSync(dir, { recursive: true, force: true });
    return {
        user, scanId, scanner, intakeJob: intake.job, intakeFinish, job: build.job, api: build.api, heartbeat: hb, state,
        /** Gives the scan back and cancels it as the user: credit refunded, nothing left waiting. */
        async close({ release = true } = {}) {
            hb?.stop();
            let released = null;
            if (release) { try { released = await build.api.release({ usage: { ...EMPTY }, reason: 'shutdown' }); } catch (e) { released = { failed: e.code }; } }
            const cancelled = await user.act(scanId, 'cancel');
            return { released, cancelled };
        },
    };
}
