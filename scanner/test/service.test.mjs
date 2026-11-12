// The real thing without a model: `scanner serve` as its own process, the real child process per run, the
// real instruments (OCR sidecar), the real engine binary of the Agent SDK — against a local fault proxy in
// place of the provider (eval/fake-provider.mjs) and a stand-in for the app. Needs scanner/.venv.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { existsSync, mkdtempSync, readdirSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import sharp from 'sharp';
import { ocrPython } from '../src/instruments/ocr.mjs';
import { alive, descendants } from '../src/proc.mjs';
import { startFakeProvider } from '../eval/fake-provider.mjs';
import { startFakeApp, definitionWith, until, TOKEN } from './support/fake-app.mjs';

const HERE = dirname(fileURLToPath(import.meta.url));
const BIN = join(HERE, '..', 'bin', 'scanner.mjs');
const skip = !existsSync(ocrPython()) && 'the build runs the OCR pre-pass: needs scanner/.venv';
const DESIGN = { canvas: { width: 800, height: 600 }, elements: [
    { kind: 'background', id: 'bg', fill: '#ffffff' },
    { kind: 'shape', id: 'karta', shape: 'rectangle', x: 40, y: 40, width: 720, height: 200, fill: '#0b3d6b' },
    { kind: 'image', id: 'logo', asset: 'standin', x: 640, y: 440, width: 120, height: 120, input: { name: 'Logo' } },
] };
const FINISH = { status: 'done', preferredCandidate: 1, headline: '', placeholders: [{ id: 'logo', name: 'Logo', role: 'logo', fillable: true }], fonts: [], approximations: [], omitted: [], lowConfidenceTexts: [] };
const preview = { tools: [{ name: 'design_preview', input: { design: DESIGN } }] };

/** Starts `scanner serve` against a fake app and a fake provider. */
async function serve(t, { plan, timing = {}, engine = {} }) {
    const provider = await startFakeProvider({ plan });
    const app = await startFakeApp({ files: definitionWith({ timing, engine }) });
    const scratch = mkdtempSync(join(tmpdir(), 'scan-serve-'));
    const lines = [];
    const child = spawn(process.execPath, [BIN, 'serve'], {
        stdio: ['ignore', 'pipe', 'pipe'],
        env: { ...process.env, SCANNER_APP_URL: app.url, SCANNER_TOKEN: TOKEN, SCANNER_SCRATCH_DIR: join(scratch, 'root'), SCANNER_HEALTH_PORT: '0', SCANNER_LOG: 'json',
            ANTHROPIC_API_KEY: 'sk-ant-not-a-key', ANTHROPIC_BASE_URL: provider.url, ANTHROPIC_AUTH_TOKEN: '', CLAUDE_CODE_OAUTH_TOKEN: '' },
    });
    let buf = '';
    child.stdout.on('data', (d) => { buf += d; let i; while ((i = buf.indexOf('\n')) >= 0) { const line = buf.slice(0, i); buf = buf.slice(i + 1); try { lines.push(JSON.parse(line)); } catch { lines.push({ raw: line }); } } });
    let stderr = ''; child.stderr.on('data', (d) => { stderr += d; });
    const exited = new Promise((ok) => child.on('exit', (code, signal) => ok({ code, signal })));
    t.after(async () => { if (child.exitCode === null && child.signalCode === null) { child.kill('SIGKILL'); await exited; } await app.close(); await provider.close(); });
    await until(() => lines.some((l) => l.msg === 'scanner started'), { timeoutMs: 60_000, what: `the scanner to start (${stderr.slice(-300)})` });
    return { app, provider, child, lines, exited, scratchRoot: join(scratch, 'root'), stderr: () => stderr };
}
const ended = (s, lane) => s.lines.find((l) => l.msg === 'run ended' && l.lane === lane);

test('serve: claims a build, runs the pre-pass and the engine binary, finishes the proposal; the usage is closed with the finish', { skip }, async (t) => {
    const s = await serve(t, { plan: [preview, { final: FINISH }] });
    const scan = await s.app.addScan({ design: DESIGN });
    await until(() => scan.status === 'ready', { timeoutMs: 90_000, what: `the scan to be ready (${JSON.stringify(s.lines.slice(-3))})` });
    assert.deepEqual([scan.finish.outcome, scan.finish.candidate, scan.finish.report.status, scan.finish.report.unfinished], ['proposed', 1, 'proposed', false]);
    assert.equal(scan.finish.report.boxes, undefined); assert.ok(Array.isArray(scan.finish.boxes));
    assert.deepEqual(scan.finish.report.placeholders.map((p) => [p.id, p.role]), [['logo', 'logo']]);
    assert.ok(scan.finish.usage.inputTokens > 0 && scan.finish.usage.turns >= 2 && scan.finish.usage.model === 'claude-opus-5-5', JSON.stringify(scan.finish.usage));
    assert.equal(scan.candidates.length, 1);
    const stages = [...new Set(scan.beats.map((b) => b.stage))];
    assert.ok(stages.every((x) => ['read', 'analyse', 'build', 'refine', 'finish'].includes(x)), `stages are the codes of §5.6: ${stages}`);
    await until(() => ended(s, 'build'));
    assert.deepEqual([ended(s, 'build').outcome, ended(s, 'build').status, ended(s, 'build').applied], ['proposed', 'ready', true]);
    assert.equal(s.provider.state.requests.every((r) => r.model === 'claude-opus-5-5'), true);
    assert.deepEqual(readdirSync(s.scratchRoot), []);
    // nothing of a picture or a design is in the log: ids, codes, counts
    assert.equal(JSON.stringify(s.lines).includes('karta'), false);
    s.child.kill('SIGTERM');
    assert.deepEqual(await s.exited, { code: 0, signal: null });
});

test('serve: a refusal ends the scan as refused — the refused turn is never re-run on another model', { skip }, async (t) => {
    const s = await serve(t, { plan: [{ refusal: true }] });
    const scan = await s.app.addScan({ design: DESIGN });
    await until(() => scan.status === 'refused', { timeoutMs: 90_000, what: 'the scan to be refused' });
    assert.equal(scan.finish.outcome, 'refused');
    assert.deepEqual([...new Set(s.provider.state.requests.map((r) => r.model))], ['claude-opus-5-5'], 'no request went to a fallback model');
    assert.equal(scan.finish.usage.model, 'claude-opus-5-5'); assert.ok(scan.finish.usage.inputTokens > 0, 'the refused request\'s usage is recorded');
    s.child.kill('SIGTERM'); await s.exited;
});

test('serve: SIGTERM mid-build → the engine and its children are dead, ONE release carries the usage, exit 0 within the limit, scratch gone', { skip }, async (t) => {
    // the model renders one candidate, then its next answer never comes (a stalled stream)
    const s = await serve(t, { plan: [preview, { stall: 600_000 }] });
    const scan = await s.app.addScan({ design: DESIGN });
    await until(() => scan.candidates.length === 1 && s.provider.state.main >= 2, { timeoutMs: 90_000, what: 'the build to be mid-turn' });
    const before = descendants(s.child.pid);
    assert.ok(before.some((p) => /claude/i.test(p.comm)), `the engine binary runs: ${before.map((p) => p.comm).join(', ')}`);
    assert.ok(readdirSync(s.scratchRoot).length === 1);
    const t0 = Date.now();
    s.child.kill('SIGTERM');
    const exit = await s.exited;
    const ms = Date.now() - t0;
    assert.deepEqual(exit, { code: 0, signal: null });
    assert.ok(ms < 30_000, `exit ${ms} ms after SIGTERM (limit 30 s; kill grace 5 s + one release)`);
    assert.deepEqual(before.filter((p) => alive(p.pid)).map((p) => p.comm), [], 'engine and worker processes are gone — checked by pid');
    assert.equal(s.app.calls('release').length, 1);
    assert.deepEqual([scan.release.reason, scan.status, scan.finish], ['shutdown', 'queued', null]);
    assert.ok(scan.release.usage.inputTokens > 0 && scan.release.usage.turns >= 1, `usage on the release: ${JSON.stringify(scan.release.usage)}`);
    assert.equal(existsSync(s.scratchRoot), false, 'the scratch root is removed');
    const stopped = s.lines.find((l) => l.msg === 'shutdown finished');
    assert.equal(stopped.how, 'clean');
});

test('serve: an intake — the original is normalised in its own child, the pre-check runs on the engine, the scan is queued and then built', { skip }, async (t) => {
    const verdict = { final: { verdict: 'design', confidence: 'high' } };
    const s = await serve(t, { plan: [verdict, verdict, preview, { final: FINISH }] });
    const original = await sharp({ create: { width: 900, height: 700, channels: 3, background: '#ffffff' } }).composite([{ input: await sharp({ create: { width: 500, height: 200, channels: 3, background: '#0b3d6b' } }).png().toBuffer(), left: 60, top: 60 }]).png().toBuffer();
    const scan = await s.app.addScan({ lane: 'intake', original });
    await until(() => scan.status !== 'checking', { timeoutMs: 90_000, what: 'the intake to finish' });
    assert.ok(['queued', 'running', 'ready'].includes(scan.status), scan.status);
    assert.equal((await sharp(scan.artifacts.working).metadata()).width, 900);
    assert.ok(scan.artifacts.preview.length > 100);
    await until(() => scan.status === 'ready' || scan.status === 'failed', { timeoutMs: 90_000, what: 'the build to finish' });
    assert.equal(scan.status, 'ready', JSON.stringify(s.lines.filter((l) => l.msg === 'run ended')));
    assert.deepEqual([scan.intakeTries, scan.run], [1, 1]);
    const intake = ended(s, 'intake');
    assert.deepEqual([intake.outcome, intake.status, intake.model], ['passed', 'queued', 'claude-haiku-5-5']);
    s.child.kill('SIGTERM'); await s.exited;
});
