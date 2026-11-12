#!/usr/bin/env node
// The unhappy paths, for real, through the REAL app job core and the real scanner service (model on the
// plan): the scanner killed mid-build (twice), the app unreachable for 30 s and for 120 s mid-scan, a cancel
// during the check and during the build, operating mode `off` mid-scan, uploads that cannot be scanned.
// Every scenario starts its scans through the app's upload endpoint and reads what the app stored.
//
//   WBOOST_LOGIN_FILE=… node eval/unhappy.mjs [kill outage30 outage120 cancel-check cancel-build mode-off unusable]
//
// "The app unreachable" = the TCP path between the scanner and the app is cut (a forwarder on :8199 this
// script owns): for the scanner that is an app that is down — connections refused, open ones reset — while
// the shared dev container stays up for whoever else works in it. Writes eval/runs/_e2e/unhappy-*.json.
import { spawn, execFileSync } from 'node:child_process';
import { createServer, connect } from 'node:net';
import { writeFileSync, mkdirSync, createWriteStream, readFileSync, mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import sharp from 'sharp';
import { AppUser } from '../dev/app-user.mjs';
import { appShow, digest } from './e2e.mjs';

const HERE = dirname(fileURLToPath(import.meta.url));
const ROOT = resolve(HERE, '..', '..');
const project = process.env.PROJECT ?? '01a1cf47-d5e5-71a3-a917-efe29965cc15';
const container = process.env.SCANNER_DEV_CONTAINER ?? 'wboost-web-1';
const APP = 'http://localhost:8099', VIA = 8199;
const out = join(HERE, 'runs', '_e2e'); mkdirSync(out, { recursive: true });
const sets = JSON.parse(readFileSync(join(HERE, 'sets.json'), 'utf8'));
const pictureOf = (id) => join(ROOT, [...sets.easy, ...sets.photo, ...sets.hard].find((x) => x.id === id).image);
const sleep = (ms) => new Promise((ok) => setTimeout(ok, ms));
const now = () => Date.now();

// ---- the path scanner → app, which can be cut
function forwarder() {
    let open = true; const sockets = new Set();
    const server = createServer((c) => {
        if (!open) { c.destroy(); return; }
        const up = connect(8099, '127.0.0.1'); sockets.add(c); sockets.add(up);
        c.pipe(up); up.pipe(c);
        const end = () => { c.destroy(); up.destroy(); sockets.delete(c); sockets.delete(up); };
        c.on('error', end); up.on('error', end); c.on('close', end); up.on('close', end);
    });
    return new Promise((ok) => server.listen(VIA, '127.0.0.1', () => ok({
        cut() { open = false; for (const s of sockets) s.destroy(); }, restore() { open = true; },
        close: () => new Promise((done) => { for (const s of sockets) s.destroy(); server.close(done); }),
    })));
}

// ---- the scanner service as its own process
const logs = [];
function startScanner(name) {
    const env = { ...process.env, SCANNER_APP_URL: `http://127.0.0.1:${VIA}`, SCANNER_TOKEN: 'dev-scanner-token-not-a-secret-0000', SCANNER_LOG: 'json', SCANNER_HEALTH_PORT: '0' };
    delete env.ANTHROPIC_API_KEY; delete env.ANTHROPIC_AUTH_TOKEN;
    const child = spawn(process.execPath, [join(HERE, '..', 'bin', 'scanner.mjs'), 'serve'], { env, stdio: ['ignore', 'pipe', 'pipe'] });
    const file = createWriteStream(join(out, `unhappy-${name}.scanner.log`), { flags: 'a' });
    let buf = '';
    child.stdout.on('data', (d) => { file.write(d); buf += d; let i; while ((i = buf.indexOf('\n')) >= 0) { try { logs.push({ at: now(), ...JSON.parse(buf.slice(0, i)) }); } catch { /* not JSON */ } buf = buf.slice(i + 1); } });
    child.stderr.on('data', (d) => file.write(d));
    const exited = new Promise((ok) => child.on('exit', (code, signal) => ok({ code, signal })));
    return { child, exited, kill: (sig) => { child.kill(sig); return exited; } };
}
const started = async () => { for (let i = 0; i < 200; i++) { if (logs.some((l) => l.msg === 'scanner started' && l.at > mark)) return; await sleep(100); } throw new Error('the scanner did not start'); };
let mark = 0;
const logged = (since, test) => logs.filter((l) => l.at >= since && test(l));
const untilLog = async (since, test, timeoutS = 200) => { for (let i = 0; i < timeoutS * 10; i++) { const l = logged(since, test)[0]; if (l) return l; await sleep(100); } return null; };

// ---- the user
const user = await new AppUser({ baseUrl: APP }).login();
async function upload(picture) {
    const t0 = now();
    const s = await user.start(project, picture);
    if (!s.ok) return { refused: s.code, http: s.http };
    const timeline = [];
    const watch = async ({ until, stage = null, timeoutS = 600 } = {}) => user.wait(s.scan.id, { until, stage, timeoutS, onChange: (x) => { timeline.push({ t: +((now() - t0) / 1000).toFixed(1), status: x.status, stage: x.stage ?? null, note: x.note ?? null, waiting: x.waiting ?? null, restarted: x.restarted ?? null, code: x.code ?? null }); console.error(`    +${timeline.at(-1).t}s ${x.status} ${x.stage ?? ''} ${x.note ?? ''} ${x.waiting ?? ''} ${x.restarted ? 'restarted' : ''} ${x.code ?? ''}`); } });
    return { id: s.scan.id, t0, timeline, watch, since: () => +((now() - t0) / 1000).toFixed(1) };
}
const END = ['ready', 'failed', 'stopped', 'refused', 'cancelled'];
const midBuild = async (scan) => { const r = await scan.watch({ until: END, stage: 'build', timeoutS: 300 }); const s = r.scan ?? r; if (s.status !== 'running') throw new Error(`the scan ended before the build: ${s.status}`); await sleep(6000); return s; };
const result = (name, scan, extra = {}) => { const show = appShow(scan.id, 'show', { container }); const r = { scenario: name, ...digest(show), failureCount: show.scan.failure_count, autoRestart: show.scan.auto_restart, timeline: scan.timeline, ...extra }; writeFileSync(join(out, `unhappy-${name}.json`), JSON.stringify(r, null, 1)); console.log(JSON.stringify({ scenario: name, status: r.status, code: r.code, run: r.run, usageRows: r.usageRows, openUsageRows: r.openUsageRows, ledger: r.ledger, credit: r.credit, consistent: r.consistent, events: r.events, ...extra })); return r; };
const consoleCmd = (...args) => execFileSync('docker', ['exec', container, 'bin/console', ...args], { encoding: 'utf8' });

const fwd = await forwarder();
const scenarios = {
    /** The scanner is killed (SIGKILL) mid-build: the app restarts the scan once; killed again: failed interrupted, refunded. */
    async kill() {
        mark = now(); let sc = startScanner('kill'); await started();
        const scan = await upload(pictureOf('B3-letak-a4'));
        await midBuild(scan);
        const k1 = scan.since(); const exit1 = await sc.kill('SIGKILL'); const killedAt1 = now();
        console.error(`    scanner killed at +${k1}s (${exit1.signal})`);
        // nobody claims: the status read of the user is what reaps the lost lease
        const requeued = await scan.watch({ until: ['queued', ...END], timeoutS: 200 });
        const requeueS = +((now() - killedAt1) / 1000).toFixed(0);
        const afterFirst = digest(appShow(scan.id, 'show', { container }));
        mark = now(); sc = startScanner('kill'); await started();
        await midBuild(scan);
        const k2 = scan.since(); await sc.kill('SIGKILL'); const killedAt2 = now();
        console.error(`    scanner killed again at +${k2}s`);
        await scan.watch({ until: END, timeoutS: 200 });
        const failS = +((now() - killedAt2) / 1000).toFixed(0);
        return result('kill', scan, { killedAtS: [k1, k2], statusAfterFirstKill: (requeued.scan ?? requeued).status, restartedShown: scan.timeline.some((x) => x.restarted), secondsFromKillToRequeue: requeueS, secondsFromSecondKillToFailed: failS, afterFirstKill: { status: afterFirst.status, run: afterFirst.run, usageRows: afterFirst.usageRows, ledger: afterFirst.ledger } });
    },
    /** The app is unreachable for `seconds` mid-build. */
    async outage(seconds) {
        mark = now(); const sc = startScanner(`outage${seconds}`); await started();
        const scan = await upload(pictureOf(seconds >= 100 ? 'B3-letak-a4' : 'B1-koncert'));
        await midBuild(scan);
        const cutAt = now(); fwd.cut(); console.error(`    app unreachable from +${scan.since()}s for ${seconds} s`);
        await sleep(seconds * 1000); fwd.restore(); console.error(`    app reachable again at +${scan.since()}s`);
        await scan.watch({ until: END, timeoutS: 900 });
        const aborted = logged(cutAt, (l) => l.msg === 'run aborted')[0] ?? null;
        const failedBeats = logged(cutAt, (l) => l.msg === 'beat not acknowledged').length;
        await sleep(1500);
        const r = result(`outage${seconds}`, scan, { outageS: seconds, failedBeats, scannerAborted: aborted ? { reason: aborted.reason, secondsAfterCut: +((aborted.at - cutAt) / 1000).toFixed(0) } : null, runsEnded: logged(cutAt, (l) => l.msg === 'run ended' && l.scan === scan.id).map((l) => `${l.lane}#${l.run} ${l.outcome}${l.reason ? `/${l.reason}` : ''} closing=${l.closing ?? '–'}`) });
        await sc.kill('SIGTERM');
        return r;
    },
    outage30() { return this.outage(30); },
    outage120() { return this.outage(120); },
    /** The user cancels while the intake holds the scan. */
    async 'cancel-check'() {
        mark = now(); const sc = startScanner('cancel-check'); await started();
        const scan = await upload(pictureOf('A3-terapie-a4'));
        const claimed = await untilLog(scan.t0, (l) => l.msg === 'claimed' && l.lane === 'intake' && l.scan === scan.id, 30);
        const cancel = await user.act(scan.id, 'cancel');
        const at = scan.since();
        await scan.watch({ until: END, timeoutS: 60 });
        const ended = await untilLog(scan.t0, (l) => l.msg === 'run ended' && l.scan === scan.id, 60);
        await sleep(6000); // a build claim must not follow
        const r = result('cancel-check', scan, { cancelledAtS: at, intakeWasClaimed: !!claimed, cancelAnswer: { ok: cancel.ok, status: cancel.scan?.status ?? null, code: cancel.code ?? null }, intakeEnded: ended ? `${ended.outcome}${ended.reason ? `/${ended.reason}` : ''} status=${ended.status ?? '–'} applied=${ended.applied ?? '–'}` : null, buildClaims: logged(scan.t0, (l) => l.msg === 'claimed' && l.lane === 'build' && l.scan === scan.id).length });
        await sc.kill('SIGTERM');
        return r;
    },
    /** The user cancels while the build runs: counted; the scanner learns from its next beat. */
    async 'cancel-build'() {
        mark = now(); const sc = startScanner('cancel-build'); await started();
        const scan = await upload(pictureOf('B3-letak-a4'));
        await midBuild(scan);
        const t = now(); const cancel = await user.act(scan.id, 'cancel');
        const aborted = await untilLog(t, (l) => l.msg === 'run aborted' && l.scan === scan.id, 40);
        const ended = await untilLog(t, (l) => l.msg === 'run ended' && l.scan === scan.id, 40);
        await scan.watch({ until: END, timeoutS: 30 });
        await sleep(1000);
        const r = result('cancel-build', scan, { cancelAnswer: { ok: cancel.ok, status: cancel.scan?.status ?? null }, scannerAborted: aborted ? { reason: aborted.reason, secondsAfterCancel: +((aborted.at - t) / 1000).toFixed(1) } : null, runEnded: ended ? { outcome: ended.outcome, reason: ended.reason, closing: ended.closing, secondsAfterCancel: +((ended.at - t) / 1000).toFixed(1) } : null });
        await sc.kill('SIGTERM');
        return r;
    },
    /** An admin switches the operating mode off mid-build: failed interrupted at once, refunded. */
    async 'mode-off'() {
        mark = now(); const sc = startScanner('mode-off'); await started();
        const scan = await upload(pictureOf('B3-letak-a4'));
        await midBuild(scan);
        const t = now(); consoleCmd('app:ai-scan:status', '--mode=off');
        const status = (await user.status(scan.id)).scan;
        const aborted = await untilLog(t, (l) => l.msg === 'run aborted' && l.scan === scan.id, 40);
        const ended = await untilLog(t, (l) => l.msg === 'run ended' && l.scan === scan.id, 40);
        const refusedUpload = await user.start(project, pictureOf('informace-s-fotkou'));
        await sleep(6000);
        const claimsWhileOff = logged(t + 2000, (l) => l.msg === 'claimed').length;
        consoleCmd('app:ai-scan:status', '--mode=on');
        await scan.watch({ until: END, timeoutS: 30 });
        const r = result('mode-off', scan, { statusRightAfterTheSwitch: `${status.status}/${status.code ?? ''}`, scannerAborted: aborted ? { reason: aborted.reason, secondsAfterSwitch: +((aborted.at - t) / 1000).toFixed(1) } : null, runEnded: ended ? { outcome: ended.outcome, reason: ended.reason, closing: ended.closing } : null, uploadWhileOff: refusedUpload.code ?? (refusedUpload.ok ? 'accepted' : null), claimsWhileOff });
        await sc.kill('SIGTERM');
        return r;
    },
    /** Uploads that cannot be scanned: refused by the app's header check; unusable for the intake; not a design for the pre-check. */
    async unusable() {
        mark = now(); const sc = startScanner('unusable'); await started();
        const dir = mkdtempSync(join(tmpdir(), 'scan-unusable-'));
        const noise = await sharp({ create: { width: 1600, height: 1200, channels: 3, noise: { type: 'gaussian', mean: 128, sigma: 40 } } }).jpeg().toBuffer();
        writeFileSync(join(dir, 'truncated.jpg'), noise.subarray(0, Math.floor(noise.length / 2)));      // the header is whole: the app accepts it
        writeFileSync(join(dir, 'vector.svg'), '<svg xmlns="http://www.w3.org/2000/svg" width="800" height="800"><rect width="800" height="800"/></svg>');
        writeFileSync(join(dir, 'small.png'), await sharp({ create: { width: 500, height: 400, channels: 3, background: '#ffffff' } }).png().toBuffer());
        writeFileSync(join(dir, 'blank.png'), await sharp({ create: { width: 1200, height: 1200, channels: 3, background: '#ffffff' } }).png().toBuffer());
        const rows = {};
        for (const f of ['vector.svg', 'small.png']) { const s = await user.start(project, join(dir, f)); rows[f] = { refusedAtUpload: s.code ?? null, http: s.http, reason: s.reason ?? null }; }
        for (const f of ['truncated.jpg', 'blank.png']) {
            const scan = await upload(join(dir, f));
            if (scan.refused) { rows[f] = { refusedAtUpload: scan.refused }; continue; }
            await scan.watch({ until: END, timeoutS: 120 });
            await sleep(1000);
            const d = digest(appShow(scan.id, 'show', { container }));
            rows[f] = { status: d.status, code: d.code, stop: (await user.status(scan.id)).scan?.stopReason ?? null, usageRows: d.usageRows, ledger: d.ledger, credit: d.credit, openUsageRows: d.openUsageRows, seconds: scan.since() };
        }
        writeFileSync(join(out, 'unhappy-unusable.json'), JSON.stringify(rows, null, 1)); console.log(JSON.stringify({ scenario: 'unusable', ...rows }));
        await sc.kill('SIGTERM');
        return rows;
    },
};

const wanted = process.argv.slice(2).length ? process.argv.slice(2) : ['unusable', 'cancel-check', 'cancel-build', 'mode-off', 'outage30', 'outage120', 'kill'];
try {
    for (const name of wanted) {
        console.error(`== ${name}`);
        try { await scenarios[name](); } catch (e) { console.log(JSON.stringify({ scenario: name, error: String(e?.message ?? e) })); }
        await sleep(1500);
    }
} finally {
    try { consoleCmd('app:ai-scan:status', '--mode=on'); } catch { /* the app is away */ }
    await fwd.close();
}
process.exit(0);
