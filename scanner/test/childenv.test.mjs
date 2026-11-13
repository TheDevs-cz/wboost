// Who gets which variable (src/childenv.mjs): every process that reads untrusted bytes — the intake's normalise
// child, the HEIC / PDF converters, the OCR sidecar — runs without the app token and without a provider
// credential; a run's worker gets the app token over IPC, never in its environment. The environments are read
// from the processes themselves: stand-ins that print the names of their variables.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, mkdirSync, writeFileSync, readFileSync, readdirSync, chmodSync, rmSync, existsSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { SECRET_VARIABLES, decoderEnv, ocrEnv, workerEnv, onPath } from '../src/childenv.mjs';
import { normaliseInChild } from '../src/intake/normalise.mjs';
import { runOcr } from '../src/instruments/ocr.mjs';
import { engineEnv } from '../src/engine/sdk.mjs';
import { HttpScannerApi } from '../src/protocol.mjs';
import { JobRunner } from '../src/lanes.mjs';
import { startFakeApp, definitionWith, TOKEN } from './support/fake-app.mjs';

const HERE = dirname(fileURLToPath(import.meta.url));
const SECRETS = { SCANNER_TOKEN: 'app-token-that-must-not-leak-0123456789', ANTHROPIC_API_KEY: 'sk-ant-not-a-key-must-not-leak', ANTHROPIC_AUTH_TOKEN: 'auth-token-must-not-leak', CLAUDE_CODE_OAUTH_TOKEN: 'plan-token-must-not-leak' };
/** The scanner's environment in production, and then some. */
const scannerEnv = (extra = {}) => ({ ...process.env, ...SECRETS, SCANNER_APP_URL: 'http://web', DATABASE_URL: 'postgres://not-for-a-decoder', ...extra });
/** What a shell, the operating system or node itself (UV_USE_IO_URING, under emulation) adds to any process. */
const AMBIENT = ['PWD', 'OLDPWD', 'SHLVL', '_', '__CF_USER_TEXT_ENCODING', 'UV_USE_IO_URING'];
const clean = (names, allowed, what) => {
    for (const s of SECRET_VARIABLES) assert.ok(!names.includes(s), `${what} must not see ${s}`);
    assert.deepEqual(names.filter((n) => !allowed.includes(n) && !AMBIENT.includes(n)), [], `${what}: only the allowlist`);
};
const executable = (file, body) => { writeFileSync(file, body); chmodSync(file, 0o755); return file; };

test('the lists: a decoder gets an allowlist, the worker everything but the app token, the engine no app token', () => {
    assert.deepEqual([...SECRET_VARIABLES].sort(), Object.keys(SECRETS).sort());
    assert.deepEqual(decoderEnv({}, scannerEnv({ PATH: '/bin', TMPDIR: '/tmp/run-1' })), { PATH: '/bin', LANG: 'C', TMPDIR: '/tmp/run-1' });
    assert.deepEqual(decoderEnv({}, {}), { PATH: '/usr/local/bin:/usr/bin:/bin', LANG: 'C' });
    assert.deepEqual(ocrEnv(2, scannerEnv({ PATH: '/bin', TMPDIR: '/tmp/run-1', HOME: '/home/node' })), { PATH: '/bin', LANG: 'C', TMPDIR: '/tmp/run-1', OMP_NUM_THREADS: '2', OMP_THREAD_LIMIT: '2', PYTHONUTF8: '1', PYTHONIOENCODING: 'utf-8', PYTHONDONTWRITEBYTECODE: '1', HOME: '/home/node' });
    const worker = workerEnv(scannerEnv(), { SCANNER_WORKER: '1' });
    assert.equal(worker.SCANNER_TOKEN, undefined);
    // the engine is started by the worker: both credentials of the provider reach it — and a machine with neither keeps its HOME (the plan login)
    assert.deepEqual([worker.ANTHROPIC_API_KEY, worker.CLAUDE_CODE_OAUTH_TOKEN, worker.SCANNER_WORKER, worker.HOME], [SECRETS.ANTHROPIC_API_KEY, SECRETS.CLAUDE_CODE_OAUTH_TOKEN, '1', process.env.HOME]);
    const scratchDir = mkdtempSync(join(tmpdir(), 'scan-env-'));
    for (const base of [scannerEnv(), { PATH: '/bin', HOME: '/home/dev', SCANNER_TOKEN: SECRETS.SCANNER_TOKEN }, { PATH: '/bin', SCANNER_TOKEN: SECRETS.SCANNER_TOKEN, CLAUDE_CODE_OAUTH_TOKEN: 'plan' }]) {
        const env = engineEnv({ base, settings: {}, scratchDir });
        assert.equal(env.SCANNER_TOKEN, undefined, 'the engine never sees the app token');
        assert.equal(env.ANTHROPIC_API_KEY, base.ANTHROPIC_API_KEY); assert.equal(env.CLAUDE_CODE_OAUTH_TOKEN, base.CLAUDE_CODE_OAUTH_TOKEN);
        // the plan login of a developer's machine is found through the untouched HOME
        if (!base.ANTHROPIC_API_KEY && !base.CLAUDE_CODE_OAUTH_TOKEN) assert.equal(env.HOME, '/home/dev');
    }
    rmSync(scratchDir, { recursive: true, force: true });
    assert.equal(onPath('sh', '/nowhere:/bin'), '/bin/sh'); assert.equal(onPath('no-such-program-here', '/bin'), null);
});

test('the normalise child and the converters it starts: PATH, LANG, TMPDIR — no token, no provider key', async (t) => {
    const dir = mkdtempSync(join(tmpdir(), 'scan-childenv-'));
    t.after(() => rmSync(dir, { recursive: true, force: true }));
    // the child itself: a stand-in script that answers with the names of its variables
    const script = join(dir, 'names.mjs');
    writeFileSync(script, 'console.log(JSON.stringify({ ok: false, stop: "env", detail: Object.keys(process.env), values: Object.values(process.env).join("\\n") }));\n');
    const r = await normaliseInChild(join(HERE, 'fixtures', 'synthetic.heic'), dir, { env: scannerEnv(), script });
    assert.equal(r.stop, 'env');
    clean(r.detail, ['PATH', 'LANG', 'TMPDIR'], 'the normalise child');
    for (const v of Object.values(SECRETS)) assert.ok(!r.values.includes(v));

    // the converters: stand-ins on the PATH that write their environment down and fail
    const bin = join(dir, 'bin'), seen = join(dir, 'seen');
    mkdirSync(bin); mkdirSync(seen);
    for (const name of ['heif-convert', 'heif-dec', 'magick', 'sips', 'pdfinfo', 'pdftoppm']) executable(join(bin, name), `#!/bin/sh\nenv > "${seen}/${name}"\nexit 1\n`);
    const env = scannerEnv({ PATH: `${bin}:/usr/bin:/bin` });
    const heic = await normaliseInChild(join(HERE, 'fixtures', 'synthetic.heic'), dir, { env });
    assert.deepEqual([heic.ok, heic.stop, heic.detail], [false, 'unusable_input', 'undecodable'], 'every converter ran and failed');
    writeFileSync(join(dir, 'x.pdf'), '%PDF-1.4 a stand-in');
    const pdf = await normaliseInChild(join(dir, 'x.pdf'), dir, { env });
    assert.deepEqual([pdf.ok, pdf.stop, pdf.detail], [false, 'unusable_input', 'pdf_unreadable']);
    const ran = readdirSync(seen).sort();
    assert.ok(ran.includes('heif-convert') && ran.includes('pdfinfo'), `converters that ran: ${ran}`);
    for (const name of ran) {
        const text = readFileSync(join(seen, name), 'utf8');
        clean(text.trim().split('\n').map((l) => l.split('=')[0]), ['PATH', 'LANG'], name);
        for (const v of Object.values(SECRETS)) assert.ok(!text.includes(v), `${name} must not see a secret`);
    }
});

test('the OCR sidecar: the decoder\'s environment, its thread pools, HOME — no token, no provider key', async (t) => {
    const dir = mkdtempSync(join(tmpdir(), 'scan-ocrenv-'));
    t.after(() => rmSync(dir, { recursive: true, force: true }));
    const python = executable(join(dir, 'python'), '#!/bin/sh\nenv > "$(dirname "$0")/seen"\nprintf \'{"lines":[],"threads":%s}\\n\' "$OMP_NUM_THREADS"\n');
    const r = await runOcr(join(HERE, 'fixtures', 'ocr-line.png'), { threads: 2, python, env: scannerEnv() });
    assert.equal(r.threads, 2);
    const text = readFileSync(join(dir, 'seen'), 'utf8');
    clean(text.trim().split('\n').map((l) => l.split('=')[0]), ['PATH', 'LANG', 'TMPDIR', 'HOME', 'OMP_NUM_THREADS', 'OMP_THREAD_LIMIT', 'PYTHONUTF8', 'PYTHONIOENCODING', 'PYTHONDONTWRITEBYTECODE'], 'the OCR sidecar');
    for (const v of Object.values(SECRETS)) assert.ok(!text.includes(v));
});

test('a run\'s worker: the app token arrives with the job over IPC and is not in its environment; the provider credential is', async (t) => {
    const timing = { heartbeatSeconds: 0.1, heartbeatAttemptSeconds: 0.08, selfAbortSeconds: 0.7, killGraceSeconds: 0.3, releaseAttemptSeconds: 0.3, appCallAttemptSeconds: 0.3, appOutageToleranceSeconds: 0.6, runCapSeconds: 2.4, intakeLeaseSeconds: 1 };
    const app = await startFakeApp({ files: definitionWith({ timing }) }); t.after(() => app.close());
    const scratchRoot = mkdtempSync(join(tmpdir(), 'scan-workerenv-'));
    t.after(() => rmSync(scratchRoot, { recursive: true, force: true }));
    const log = join(mkdtempSync(join(tmpdir(), 'scan-workerenv-log-')), 'worker.log');
    await app.addScan({ lane: 'build', design: { canvas: { width: 200, height: 100 }, elements: [{ kind: 'background', id: 'bg', fill: '#ffffff' }] }, original: Buffer.from('original'), options: { stub: { log } } });
    const scanner = new HttpScannerApi({ baseUrl: app.url, token: TOKEN, scannerVersion: 't', timing });
    const { job } = await scanner.claim('build', 1);
    const s = await new JobRunner({ scanner, job, options: { scratchRoot, workerPath: join(HERE, 'support', 'stub-worker.mjs'), threads: 1, env: scannerEnv({ SCANNER_TOKEN: TOKEN }) } }).run();
    assert.equal(s.outcome, 'proposed', 'the worker reached the app with the token it was handed');
    assert.ok(existsSync(log));
    const seen = JSON.parse(readFileSync(log, 'utf8').trim().split('\n')[0]);
    assert.ok(!seen.envNames.includes('SCANNER_TOKEN'), 'no app token in the worker\'s environment');
    assert.equal(seen.tokenByIpc, true);
    assert.ok(seen.envNames.includes('ANTHROPIC_API_KEY') && seen.envNames.includes('CLAUDE_CODE_OAUTH_TOKEN'), 'the engine is started here');
    assert.ok(seen.envNames.includes('SCANNER_WORKER') && seen.envNames.includes('TMPDIR'));
});
