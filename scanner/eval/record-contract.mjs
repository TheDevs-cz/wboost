#!/usr/bin/env node
// Records the CONTRACT FIXTURE: every kind of request the scanner's client (src/protocol.mjs) sends to the
// app's `/internal/scanner/*` endpoints and what the REAL local app answers — walked without a model, on a
// synthetic picture. The result (test/contract/scanner-api.v<N>.json) is committed: no client picture, no
// secret (tokens, lease tokens and ids are placeholders; pictures are byte counts; the definition is file names).
//
//   WBOOST_LOGIN_FILE=… node eval/record-contract.mjs [--project <id>] [--out test/contract/scanner-api.v1.json]
//   WBOOST_LOGIN_FILE=… node eval/record-contract.mjs --append-calendar <calendarId> [--project <id>]
// Needs the local app with mode `on` and NO scanner service running.
//
// `--append-calendar <id of a 7-day calendar of the project>` walks ONLY a calendar scan (claim with the
// calendar context, a render from model commands, a command the model refuses, the calendar report) and
// APPENDS its exchanges to the existing fixture as the next `<scan:N>` — the recorded template flows stay
// byte for byte what they were.
import { writeFileSync, mkdtempSync, readFileSync } from 'node:fs';
import { readFile } from 'node:fs/promises';
import { execFileSync } from 'node:child_process';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import sharp from 'sharp';
import { HttpScannerApi, PROTOCOL_VERSION, ScannerApiError } from '../src/protocol.mjs';
import { normaliseInChild } from '../src/intake/normalise.mjs';
import { AppUser } from '../dev/app-user.mjs';
import { SYNTHETIC_SVG } from './lease.mjs';

const HERE = dirname(fileURLToPath(import.meta.url));
const arg = (name, fallback) => { const i = process.argv.indexOf(`--${name}`); return i > 0 ? process.argv[i + 1] : fallback; };
const appUrl = process.env.WBOOST_APP_URL ?? 'http://localhost:8099', token = process.env.SCANNER_TOKEN ?? 'dev-scanner-token-not-a-secret-0000';
const project = arg('project', process.env.PROJECT ?? '01a1cf47-d5e5-71a3-a917-efe29965cc15');
const out = resolve(arg('out', join(HERE, '..', 'test', 'contract', `scanner-api.v${PROTOCOL_VERSION}.json`)));
const appendCalendar = arg('append-calendar', null);
const existing = appendCalendar ? JSON.parse(readFileSync(out, 'utf8')) : null;
// scans of an appended recording are numbered after the ones the fixture already holds
const scanBase = existing ? Math.max(0, ...[...JSON.stringify(existing).matchAll(/<scan:(\d+)>/g)].map((m) => Number(m[1]))) : 0;
const USAGE = (n) => ({ provider: 'anthropic', model: 'claude-opus-5-5', inputTokens: 1000 * n, outputTokens: 100 * n, cacheReadTokens: 0, cacheWriteTokens: 0, engineCostMicroUsd: 6000 * n, turns: n, durationMs: 1000 * n });
const ZERO = { provider: 'anthropic', model: null, inputTokens: 0, outputTokens: 0, cacheReadTokens: 0, cacheWriteTokens: 0, engineCostMicroUsd: 0, turns: 0, durationMs: 0 };

// ---- recording: every exchange of the client, then sanitised
const raw = [];
let step = 'start';
const scanner = new HttpScannerApi({ baseUrl: appUrl, token, scannerVersion: '0.2.0+contract', record: (e) => raw.push({ step, ...e }) });
const ids = new Map(), leases = new Map(); // real value → placeholder
const scanName = (id) => { if (!ids.has(id)) ids.set(id, `<scan:${scanBase + ids.size + 1}>`); return ids.get(id); };
const mark = (name) => { step = name; };
const call = async (name, fn) => { mark(name); try { return await fn(); } catch (e) { if (e instanceof ScannerApiError) return { error: e }; throw e; } };

const dir = mkdtempSync(join(tmpdir(), 'scan-contract-'));
const picture = join(dir, 'synthetic.png');
writeFileSync(picture, await sharp(Buffer.from(SYNTHETIC_SVG)).png().toBuffer());
const user = await new AppUser({ baseUrl: appUrl }).login();
const claimOwn = async (name, lane, scanId) => { for (let i = 0; i < 30; i++) { const c = await call(name, () => scanner.claim(lane, 1)); if (c.job) { if (c.job.id !== scanId) throw new Error('claimed a scan that is not ours: stop the scanner service first'); leases.set(c.job.leaseToken, `<lease:${scanName(scanId)}:${lane}:${c.job.run}>`); return c; } raw.pop(); await new Promise((ok) => setTimeout(ok, 500)); } throw new Error(`no ${lane} job`); };
const startScan = async (options = {}) => { const s = await user.start(project, picture, options); if (!s.ok) throw new Error(`upload refused: ${s.code}`); scanName(s.scan.id); return s.scan.id; };
const intakeOf = async (scanId, tag) => {
    const c = await claimOwn(`${tag}: claim intake → a job`, 'intake', scanId);
    mark(`${tag}: source (the original, as bytes)`);
    writeFileSync(join(dir, 'original.bin'), await c.api.source());
    const n = await normaliseInChild(join(dir, 'original.bin'), dir);
    return { c, n };
};

if (!appendCalendar) {
await call('tick (every 30 s and at start)', () => scanner.tick());
await call('claim with nothing to do', () => scanner.claim('intake', 1));

// ---- scan 1: intake passed → build → faces, renders (valid, rejected, repeated, over the cap) → finish proposed → what a finished run is told
const s1 = await startScan();
const i1 = await intakeOf(s1, 'scan 1');
await call('scan 1: finish intake `passed` before the working copy is stored', () => i1.c.api.finish({ outcome: 'passed', preCheck: { verdict: 'design', confidence: 'high', approximate: false, ms: 2100 }, usage: ZERO }));
const working = await readFile(i1.n.files.working);
await call('scan 1: artifact working', () => i1.c.api.putArtifact('working', working));
await call('scan 1: artifact preview', async () => i1.c.api.putArtifact('preview', await readFile(i1.n.files.preview)));
await call('scan 1: finish intake `passed`', () => i1.c.api.finish({ outcome: 'passed', preCheck: { verdict: 'design', confidence: 'high', approximate: false, ms: 2100 }, sourceMeta: { ...i1.n.meta, workingBytes: working.length }, usage: { ...USAGE(1), model: 'claude-haiku-5-5', engineCostMicroUsd: 150 } }));
await call('scan 1: finish intake again (a repeat records usage only)', () => i1.c.api.finish({ outcome: 'passed', usage: ZERO }));
const b1 = await claimOwn('scan 1: claim build → a job with the context', 'build', s1);
const face = b1.job.context.fonts.map((f) => `${f.font} (${f.face})`)[0];
const design = (shift = 0) => ({ canvas: { width: 1080, height: 1350 }, elements: [
    { kind: 'background', id: 'pozadi', fill: '#f4f1ea' },
    { kind: 'shape', id: 'panel', shape: 'rectangle', name: 'Panel', x: 80 + shift, y: 90, width: 920, height: 420, fill: '#0b3d6b' },
    { kind: 'image', id: 'obrazek', asset: b1.job.context.standInAssetId, x: 660, y: 890, width: 320, height: 320, input: { name: 'Obrázek' } },
    { kind: 'text', id: 'nadpis', text: 'Letní kemp 2027', font: face, size: 64, color: '#ffffff', x: 120, y: 160, width: 800 },
] });
await call('scan 1: source (the working copy, as bytes)', () => b1.api.source());
await call('scan 1: beat', () => b1.api.beat({ seq: 1, stage: 'analyse', note: 'texts_found', noteParams: { n: 2 }, round: 0, textBoxes: { width: 1080, height: 1350, boxes: [[80, 600, 520, 36], [80, 670, 380, 36]] }, usage: USAGE(1), resources: { peakRssMb: 640, cpuSeconds: 3.2 } }));
await call('scan 1: beat with an older seq (changes nothing)', () => b1.api.beat({ seq: 1, stage: 'build', note: null, noteParams: null, usage: USAGE(1) }));
await call('scan 1: faces', () => b1.api.faces({ seq: 1, lines: ['Letní kemp 2027'], faces: [face], size: 64 }));
await call('scan 1: faces without lines (invalid request)', () => b1.api.faces({ seq: 2, lines: [] }));
await call('scan 1: render → candidate 1', () => b1.api.render({ seq: 3, design: design() }));
await call('scan 1: render with the same seq (idempotent: the stored candidate)', () => b1.api.render({ seq: 3, design: design() }));
await call('scan 1: render a design the validation rejects', () => b1.api.render({ seq: 4, design: { ...design(), elements: [...design().elements, { kind: 'text', id: 'spatne', text: 'x', font: 'No Such Font (Regular)', size: 20, color: '#000000', x: 10, y: 10, width: 100 }] } }));
await call('scan 1: render → candidate 2', () => b1.api.render({ seq: 5, design: design(10) }));
await call('scan 1: render → candidate 3', () => b1.api.render({ seq: 6, design: design(20) }));
await call('scan 1: render past the cap (candidate_limit)', () => b1.api.render({ seq: 7, design: design(30) }));
await call('scan 1: a call with another run number (stale_run)', () => scanner.forJob({ ...b1.job, run: b1.job.run + 1 }).beat({ seq: 9, usage: USAGE(2) }));
await call('scan 1: a call with a wrong lease token (lease_lost)', () => scanner.forJob({ ...b1.job, leaseToken: 'f'.repeat(64) }).beat({ seq: 9, usage: USAGE(2) }));
const report = {
    status: 'proposed', unfinished: false, stopReason: null, candidate: 2, headline: 'nadpis',
    placeholders: [{ id: 'obrazek', name: 'Obrázek', role: 'photo', fillable: true, box: [660, 890, 320, 320] }],
    fonts: [{ group: 'nadpis', role: 'headline', texts: ['nadpis'], pattern: 'measured_match', closeness: 0.93, mismatch: 0.07, sizeChangePercent: 0, face, verified: true, verifiedScore: 0.93 }],
    simplifications: [], omitted: [{ code: 'other', box: [80, 600, 520, 110], diff: 0.09, by: 'scanner' }], approximations: [], lowConfidenceTexts: [{ id: 'nadpis', reason: 'not_found_by_ocr', by: 'scanner', box: [120, 160, 800, 74] }],
    shrunk: [], paletteSnaps: [], notes: [], counts: { texts: 1, shapes: 1, places: 1, background: true }, measures: { mae: 0.03, textFit: 0.1 },
};
const boxes = [{ id: 'panel', kind: 'shape', box: [90, 90, 920, 420] }, { id: 'nadpis', kind: 'text', box: [120, 160, 800, 74], ink: null, sourceInk: null, colour: '#ffffff', lines: 1, sourceLineWidths: [], fittedSize: null }];
await call('scan 1: finish build `proposed`', () => b1.api.finish({ outcome: 'proposed', candidate: 2, report, boxes, usage: USAGE(4) }));
await call('scan 1: finish again (the answer of the first was lost) → stale_run with the state', () => b1.api.finish({ outcome: 'proposed', candidate: 2, report, boxes, usage: USAGE(4) }));
await call('scan 1: beat after the finish (the run no longer holds the scan)', () => b1.api.beat({ seq: 10, usage: USAGE(4) }));
await call('scan 1: render after the finish (lease_lost)', () => b1.api.render({ seq: 11, design: design() }));

// ---- scan 2: stopped by the intake
const s2 = await startScan();
const i2 = await intakeOf(s2, 'scan 2');
await call('scan 2: finish intake `stopped`', () => i2.c.api.finish({ outcome: 'stopped', reason: 'photo_of_print', preCheck: { verdict: 'photo_of_print', confidence: 'high', approximate: false, ms: 2000, continuable: true }, usage: { ...USAGE(1), model: 'claude-haiku-5-5' } }));

// ---- scan 3: the intake is released (shutdown), claimed again; the build is cancelled by the user mid-run; a late finish
const s3 = await startScan();
const i3a = await intakeOf(s3, 'scan 3');
await call('scan 3: release intake (shutdown): the lease is given back', () => i3a.c.api.release({ usage: ZERO, reason: 'shutdown' }));
const i3 = await intakeOf(s3, 'scan 3 (second intake claim: a new run number)');
await i3.c.api.putArtifact('working', working); await i3.c.api.putArtifact('preview', await readFile(i3.n.files.preview)); raw.splice(-2);
await call('scan 3: finish intake `passed`', () => i3.c.api.finish({ outcome: 'passed', preCheck: { verdict: 'design', confidence: 'medium', approximate: false, ms: 1900 }, sourceMeta: { ...i3.n.meta, workingBytes: working.length }, usage: ZERO }));
const b3a = await claimOwn('scan 3: claim build', 'build', s3);
await call('scan 3: release build (shutdown): queued again, the restart not consumed', () => b3a.api.release({ usage: USAGE(1), reason: 'shutdown' }));
const b3 = await claimOwn('scan 3: claim build again (run 2)', 'build', s3);
await call('scan 3: beat', () => b3.api.beat({ seq: 1, stage: 'build', note: 'measuring', noteParams: null, round: 0, usage: USAGE(2) }));
await user.act(s3, 'cancel');
await call('scan 3: beat after the user cancelled (cancel: true)', () => b3.api.beat({ seq: 2, stage: 'build', usage: USAGE(3) }));
await call('scan 3: a late finish after the cancel (usage only, stale_run)', () => b3.api.finish({ outcome: 'failed', error: 'build', usage: USAGE(3) }));
await call('scan 3: release after the cancel (applied: false)', () => b3.api.release({ usage: USAGE(3), reason: 'shutdown' }));

// ---- scan 4: failed and refused finishes
const s4 = await startScan();
const i4 = await intakeOf(s4, 'scan 4');
await call('scan 4: finish intake `failed provider_unavailable` (rule 3c)', () => i4.c.api.finish({ outcome: 'failed', error: 'provider_unavailable', usage: ZERO }));
const s5 = await startScan();
const i5 = await intakeOf(s5, 'scan 5');
await i5.c.api.putArtifact('working', working); await i5.c.api.putArtifact('preview', await readFile(i5.n.files.preview)); raw.splice(-2);
await i5.c.api.finish({ outcome: 'passed', preCheck: { verdict: 'design' }, usage: ZERO }); raw.pop();
const b5 = await claimOwn('scan 5: claim build', 'build', s5);
await call('scan 5: finish build `failed` with an error code', () => b5.api.finish({ outcome: 'failed', error: 'renderer_unavailable', usage: USAGE(1) }));
const s6 = await startScan();
const i6 = await intakeOf(s6, 'scan 6');
await call('scan 6: finish intake `refused` (rule 3c)', () => i6.c.api.finish({ outcome: 'refused', usage: { ...USAGE(1), model: 'claude-haiku-5-5' } }));

// ---- answers that do not depend on a scan
await call('a scan that does not exist (gone)', () => scanner.forJob({ id: '00000000-0000-7000-8000-000000000000', run: 1, leaseToken: 'a'.repeat(64) }).beat({ seq: 1, usage: ZERO }));
const old = new HttpScannerApi({ baseUrl: appUrl, token, scannerVersion: '0.0.1+old', record: (e) => raw.push({ step, protocolHeader: '0', ...e }), fetchImpl: (url, init) => fetch(url, { ...init, headers: { ...init.headers, 'X-Scanner-Protocol': '0' } }) });
await call('a protocol version the app does not accept (protocol_too_old)', () => old.tick());
await call('tick again: the app recorded the old scanner; the next accepted call clears it', () => scanner.tick());
const stranger = new HttpScannerApi({ baseUrl: appUrl, token: 'not-the-scanner-token-0123456789', scannerVersion: 'x', record: (e) => raw.push({ step, wrongToken: true, ...e }) });
mark('a wrong bearer token: 404 with an empty body'); try { await stranger.attempt('POST', 'tick', { body: { scannerVersion: 'x' } }); } catch { /* recorded */ }
for (const id of [s1, s2, s4, s5, s6]) { const st = (await user.status(id)).scan?.status; if (['ready', 'failed', 'stopped', 'refused', 'cancelled'].includes(st)) await user.act(id, 'discard'); }
} else {
    // ---- a calendar scan: the build claim carries the calendar, a render takes model COMMANDS, the report is the calendar's
    await scanner.tick(); raw.pop(); // the app starts nothing while it has not heard from a scanner (the tick itself is in the fixture already)
    const sc = await startScan({ kind: 'calendar', calendarId: appendCalendar });
    const tag = `scan ${scanBase + 1} (calendar)`;
    const ic = await intakeOf(sc, tag);
    const working = await readFile(ic.n.files.working);
    await ic.c.api.putArtifact('working', working); await ic.c.api.putArtifact('preview', await readFile(ic.n.files.preview)); raw.splice(-2);
    await call(`${tag}: finish intake \`passed\``, () => ic.c.api.finish({ outcome: 'passed', preCheck: { verdict: 'design', confidence: 'high', approximate: false, ms: 2000 }, sourceMeta: { ...ic.n.meta, workingBytes: working.length }, usage: ZERO }));
    const bc = await claimOwn(`${tag}: claim build → a job with the calendar context`, 'build', sc);
    const commands = [
        { op: 'preset', preset: 'photoRight' },
        { op: 'configure', features: { qr: true } },
        { op: 'layout', boxes: { logo: { x: 262, y: 8, w: 36, h: 14 } }, styles: { head: { bg: { color: '#1F3A5F' } }, cal: { numberSide: 'start' } } },
    ];
    await call(`${tag}: beat`, () => bc.api.beat({ seq: 1, stage: 'read', note: 'rows_found', noteParams: { n: 7 }, round: 0, usage: USAGE(1) }));
    const rendered = await call(`${tag}: render → candidate 1 (model commands)`, () => bc.api.render({ seq: 2, commands }));
    await call(`${tag}: render with a command the calendar model refuses (a switch OFF)`, () => bc.api.render({ seq: 3, commands: [{ op: 'preset', preset: 'photoLeft' }, { op: 'configure', features: { moon: false } }] }));
    await call(`${tag}: render with a layout key the model does not know`, () => bc.api.render({ seq: 4, commands: [{ op: 'preset', preset: 'photoLeft' }, { op: 'layout', styles: { cal: { noSuchKey: 1 } } }] }));
    const width = rendered.width ?? 1600, height = rendered.height ?? 748;
    const calendarReport = {
        status: 'proposed', candidate: 1, unfinished: false, type: 'week', pageBox: [0, 0, width, height], photoBox: [0, Math.round(height * 0.19), Math.round(width * 0.63), Math.round(height * 0.81)],
        recognised: [{ element: 'photo', state: 'found' }, { element: 'cap', state: 'approximate' }, { element: 'cal', state: 'found' }, { element: 'month', state: 'found' }, { element: 'wk', state: 'absent' }, { element: 'head', state: 'found' }, { element: 'logo', state: 'found' }, { element: 'qr', state: 'absent' }, { element: 'bg', state: 'found' }],
        needsUpload: [{ slot: 'logo' }], featuresOffered: [{ key: 'qr' }],
        fonts: [{ group: 'num', pattern: 'nearest_available', face: null, closeness: 0.61 }], notExpressible: [{ code: 'ornament', box: [0, Math.round(height * 0.86), Math.round(width * 0.3), Math.round(height * 0.08)] }],
        notes: [{ code: 'ratio_adapted', w: 297, h: 150 }], measures: { mae: 0.031 },
    };
    await call(`${tag}: finish build \`proposed\` (the calendar report)`, () => bc.api.finish({ outcome: 'proposed', candidate: 1, report: calendarReport, usage: USAGE(3) }));
    const st = (await user.status(sc)).scan?.status; if (st === 'ready') await user.act(sc, 'discard');
}

// ---- sanitise
const swap = (s) => { let t = String(s); for (const [real, name] of [...ids, ...leases]) t = t.split(real).join(name); return t; };
const clean = (v, key = '') => {
    if (v === null || typeof v !== 'object') return typeof v === 'string' ? swap(v) : v;
    if (Array.isArray(v)) return v.map((x) => clean(x));
    const o = {};
    for (const [k, x] of Object.entries(v)) {
        if (k === 'base64' && typeof x === 'string') o[k] = `<${Buffer.from(x, 'base64').length} bytes of ${v.contentType ?? 'image'}>`;
        else if (k === 'files' && key === 'definition') o[k] = Object.fromEntries(Object.entries(x).map(([name, content]) => [name, `<${content.length} characters>`]));
        else if (k === 'leaseToken') o[k] = leases.get(x) ?? '<lease>';
        else if (k === 'fonts' && Array.isArray(x) && key === 'context') o[k] = [...x.slice(0, 2), `<${x.length - 2} more faces of the same shape>`];
        else if (k === 'sha256') o[k] = '<sha256 of the upload>';
        else if (k === 'leaseExpiresAt') o[k] = '<ISO 8601 date>';
        else o[k] = clean(x, k);
    }
    return o;
};
const entries = raw.map((e) => ({
    step: e.step,
    request: { method: e.method, path: `/internal/scanner/${swap(e.path)}`, headers: { Authorization: e.wrongToken ? 'Bearer <a wrong token>' : 'Bearer <SCANNER_TOKEN>', 'X-Scanner-Protocol': e.protocolHeader ?? String(PROTOCOL_VERSION), ...(e.lease ? { 'X-Scan-Lease': leases.get(e.leaseToken) ?? '<a lease token the app does not know>' } : {}) }, ...(e.rawBytes !== null && e.rawBytes !== undefined ? { body: `<${e.rawBytes} bytes>` } : e.body ? { body: clean(e.body) } : {}) },
    response: e.status === null ? { error: e.error } : { status: e.status, contentType: (e.contentType ?? '').split(';')[0], ...(e.responseBytes !== null && e.responseBytes !== undefined ? { body: `<${e.responseBytes} bytes>` } : { body: clean(e.response) }) },
}));
let commit = null; try { commit = execFileSync('git', ['rev-parse', 'HEAD'], { cwd: HERE, encoding: 'utf8' }).trim(); } catch { /* not a checkout */ }
const fixture = existing ? {
    ...existing,
    calendar: { recordedAt: new Date().toISOString().slice(0, 10), commit, note: `the exchanges of <scan:${scanBase + 1}> (a calendar scan) were recorded separately and appended` },
    entries: [...existing.entries, ...entries],
} : {
    what: 'Recorded exchanges between the scanner\'s client (scanner/src/protocol.mjs) and the app\'s /internal/scanner/* endpoints.',
    protocolVersion: PROTOCOL_VERSION, recordedAt: new Date().toISOString().slice(0, 10), recordedAgainst: { app: 'local dev stack', commit }, scannerVersion: '0.2.0',
    placeholders: 'Scan ids are <scan:N>, lease tokens <lease:<scan>:<lane>:<run>>, the bearer token <SCANNER_TOKEN>; pictures are byte counts; definition files are character counts; the picture uploaded is synthetic.',
    entries,
};
writeFileSync(out, JSON.stringify(fixture, null, 1) + '\n');
console.log(`${entries.length} exchanges ${existing ? `appended (${fixture.entries.length} in all)` : ''} → ${out}`);
for (const e of entries) console.log(`  ${String(e.response.status ?? e.response.error).padEnd(4)} ${e.request.method.padEnd(4)} ${e.request.path.replace('/internal/scanner/', '').padEnd(46)} ${e.step}`);
