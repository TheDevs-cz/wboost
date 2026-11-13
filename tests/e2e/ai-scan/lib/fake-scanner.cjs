#!/usr/bin/env node
/*
 * A fake scanner for the AI scan wizard's end-to-end scripts and for trying the UI by hand: it
 * plays `scanner/` over the real scanner API (docs/architecture/ai-scan.md § "Scanner API"),
 * for WHATEVER scan waits — so scans started in the browser move. No model is called.
 *
 *   node tests/e2e/ai-scan/lib/fake-scanner.cjs                       # serve until Ctrl-C, every scan ends ready
 *   node tests/e2e/ai-scan/lib/fake-scanner.cjs --scenario=stop       # the pre-check stops the next scan (photo of a print)
 *   node tests/e2e/ai-scan/lib/fake-scanner.cjs --scenario=fail --once
 *
 * A CALENDAR scan (kind `calendar`, tests/e2e/calendar/scan.e2e.cjs) gets a calendar candidate —
 * `render` with model `commands` — and the calendar report (docs/architecture/ai-scan.md
 * § "Calendar scans"); `options.calendar` may override parts of that report.
 *
 * Scenarios: happy · stop (photo_of_print) · blurry · fail (build) · provider · refuse ·
 * hang (claims a build, beats `--beats` times, then stops answering).
 * `--project=<id>` serves only that project's scans (looked up with psql through docker compose);
 * without it EVERY waiting scan is taken — do not run it beside a real scanner.
 * `options.idleMs` (default 700) is the pause between two empty claim rounds.
 * Options: --beat-ms=1200 (pause between heartbeats) · --beats=6 · --once (one scan, then exit) ·
 * --intake-only (pass the pre-check, leave the scan queued).
 *
 * Needs the dev stack: mode `on` (`bin/console app:ai-scan:status --mode=on`) and this machine's
 * address in SCAN_NETWORK_CIDR (.env.dev.local; scanner/README.md). Never against production.
 */
const BASE = process.env.BASE || 'http://localhost:8099';
const TOKEN = process.env.SCANNER_TOKEN || 'dev-scanner-token-not-a-secret-0000';
const VERSION = '0.0.0+fake-e2e';

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

function parseArgs(argv) {
    const options = { scenario: 'happy', beatMs: 1200, beats: 6, once: false, intakeOnly: false };
    for (const arg of argv) {
        const [key, value] = arg.replace(/^--/, '').split('=');
        if (key === 'scenario') options.scenario = value;
        else if (key === 'beat-ms') options.beatMs = Number(value);
        else if (key === 'beats') options.beats = Number(value);
        else if (key === 'once') options.once = true;
        else if (key === 'intake-only') options.intakeOnly = true;
        else if (key === 'project') options.accepts = projectFilter([value]);
    }
    return options;
}

async function call(method, path, { body = null, raw = null, lease = null } = {}) {
    const headers = { Authorization: `Bearer ${TOKEN}`, 'X-Scanner-Protocol': '1' };
    if (lease) headers['X-Scan-Lease'] = lease;
    let payload;
    if (raw) { headers['Content-Type'] = 'application/octet-stream'; payload = raw; } else if (body) { headers['Content-Type'] = 'application/json'; payload = JSON.stringify(body); }
    const response = await fetch(`${BASE}/internal/scanner/${path}`, { method, headers, body: payload });
    const buffer = Buffer.from(await response.arrayBuffer());
    let json = null;
    try { json = JSON.parse(buffer.toString('utf8')); } catch (e) { json = null; }
    if (response.status === 404) throw new Error(`The scanner API answered 404 to ${path}: this machine is not inside SCAN_NETWORK_CIDR, or the token is wrong (.env.dev.local; scanner/README.md).`);
    return { status: response.status, json, buffer };
}

/** `accepts(scanId)` for scans of the given projects (dev stack: psql through docker compose). */
function projectFilter(projectIds) {
    const { execFileSync } = require('child_process');
    const path = require('path');
    const known = new Map();
    return (scanId) => {
        if (!/^[0-9a-f-]{36}$/i.test(scanId)) return false;
        if (!known.has(scanId)) {
            const out = execFileSync('docker', ['compose', 'exec', '-T', 'postgres', 'psql', '-U', 'postgres', '-d', process.env.E2E_DB || 'wboost', '-At', '-c', `SELECT project_id FROM ai_scan WHERE id = '${scanId}'`], { cwd: path.resolve(__dirname, '../../../..'), encoding: 'utf8' }).trim();
            known.set(scanId, out);
        }
        return projectIds.includes(known.get(scanId));
    };
}

const usage = (step) => ({ provider: 'fake', model: 'fake-model', inputTokens: 1200 * step, outputTokens: 300 * step, cacheReadTokens: 800 * step, cacheWriteTokens: 100, engineCostMicroUsd: 40000 * step, turns: step, durationMs: 1500 * step });

/** The design the fake build "recognises" — it matches lib/poster.cjs (900 × 1200). */
function design(job) {
    const face = job.context.fonts[0].family;
    const standIn = job.context.standInAssetId;
    return {
        canvas: { width: 900, height: 1200 },
        elements: [
            { kind: 'background', id: 'bg', fill: '#f4f1ea' },
            { kind: 'shape', id: 'pruh', shape: 'rectangle', fill: '#c8102e', x: 0, y: 1000, width: 900, height: 200 },
            { kind: 'image', id: 'foto', asset: standIn, x: 100, y: 400, width: 400, height: 300, input: { name: 'Fotografie' } },
            { kind: 'image', id: 'logo', asset: standIn, x: 700, y: 60, width: 140, height: 60, input: { name: 'Logo' } },
            { kind: 'text', id: 'headline', text: 'Den otevřených dveří', font: face, size: 64, color: '#c8102e', x: 80, y: 120, width: 600 },
            { kind: 'text', id: 'sub', text: 'Sobota 14. června', font: face, size: 36, x: 80, y: 240, width: 600 },
        ],
    };
}

function report(job, candidate) {
    const face = job.context.fonts[0].family;
    return {
        status: 'proposed', candidate, headline: 'headline', unfinished: false, stopReason: null,
        counts: { texts: 2, shapes: 1, places: 2, background: true },
        placeholders: [
            { id: 'logo', name: 'Logo', role: 'logo', fillable: true, box: [700, 60, 140, 60] },
            { id: 'foto', name: 'Fotografie', role: 'photo', fillable: true, box: [100, 400, 400, 300] },
        ],
        fonts: [
            { group: 'headline', role: 'headline', texts: ['headline'], pattern: 'nearest_available', face, closeness: 0.62, mismatch: 0.38, sizeChangePercent: -4, sourceClass: 'sans', sourceLooksLike: null, guessSupported: false },
            { group: 'body', role: 'body', texts: ['sub'], pattern: 'measured_match', face, closeness: 0.93, mismatch: 0.07, sizeChangePercent: 0, sourceClass: 'sans', sourceLooksLike: null, guessSupported: false },
        ],
        simplifications: [{ code: 'shadow_omitted', element: 'headline', box: [80, 120, 600, 90], n: 1 }],
        approximations: [],
        omitted: [{ code: 'picture', box: [620, 820, 180, 120], diff: 0.2, by: 'model' }],
        lowConfidenceTexts: [{ id: 'sub', reason: 'small', box: [80, 240, 420, 50], by: 'model' }],
        shrunk: [{ id: 'sub' }],
        paletteSnaps: [{ from: '#c9112f', to: '#c8102e' }],
        notes: [],
    };
}

const boxes = () => [
    { id: 'bg', kind: 'background', box: [0, 0, 900, 1200] },
    { id: 'pruh', kind: 'shape', box: [0, 1000, 900, 200] },
    { id: 'foto', kind: 'image', box: [100, 400, 400, 300] },
    { id: 'logo', kind: 'image', box: [700, 60, 140, 60] },
    { id: 'headline', kind: 'text', box: [80, 120, 600, 90], colour: '#c8102e', lines: 1, sourceLineWidths: [560], fittedSize: 64 },
    { id: 'sub', kind: 'text', box: [80, 240, 420, 50], colour: '#000000', lines: 1, sourceLineWidths: [300], fittedSize: 44 },
];

// ---- a calendar scan -------------------------------------------------------------------------

/** The model commands of the fake calendar candidate: Fotka vpravo, a navy header, numbers on the left. */
const calendarCommands = () => [
    { op: 'preset', preset: 'photoRight' },
    { op: 'layout', boxes: { logo: { x: 262, y: 14, w: 34, h: 11 } }, styles: {
        head: { bg: { color: '#1F3A5F' } },
        month: { color: '#FFFFFF', align: 'start' },
        cal: { numberSide: 'start' },
        num: { color: '#1F3A5F' },
    } },
];

/** The same for a 14-day calendar: the one layout `twoSided`, both week columns narrower and lower, the photo wider. */
const fortnightCommands = () => [
    { op: 'preset', preset: 'twoSided' },
    { op: 'layout', boxes: { cal: { x: -5, y: 31, w: 79, h: 107 }, cal2: { x: 236, y: 31, w: 79, h: 107 }, photo: { x: 74, y: 31, w: 162, h: 107 } }, styles: {
        head: { bg: { color: '#1F3A5F' } },
        month: { color: '#FFFFFF' },
        num: { color: '#1F3A5F' },
    } },
];

/** The calendar report; `rendered` is the app's answer to `render` (`width`, `height`, `layout`). */
function calendarReport(candidate, rendered, overrides = {}) {
    const w = rendered.width || 1600;
    const h = rendered.height || 748;
    const view = rendered.view || { x: 0, y: 0, w: 310, h: 145 };
    const photo = rendered.layout && rendered.layout.boxes ? rendered.layout.boxes.photo : null;
    const px = (mm) => Math.round((mm / view.w) * w);
    return {
        status: 'proposed', candidate, unfinished: false, type: 'week',
        // the fake working copy IS the uploaded picture: the page fills it
        pageBox: [0, 0, w, h],
        photoBox: photo ? [Math.max(0, px(photo.x)), Math.max(0, px(photo.y)), Math.min(w, px(photo.w)), Math.min(h, px(photo.h))] : null,
        recognised: [
            { element: 'photo', state: 'found' }, { element: 'cap', state: 'approximate' }, { element: 'cal', state: 'found' },
            { element: 'month', state: 'found' }, { element: 'wk', state: 'absent' }, { element: 'head', state: 'found' },
            { element: 'logo', state: 'found' }, { element: 'qr', state: 'absent' }, { element: 'bg', state: 'found' },
        ],
        needsUpload: [{ slot: 'logo' }, { slot: 'header' }],
        featuresOffered: [{ key: 'qr' }, { key: 'events' }],
        fonts: [{ group: 'num', pattern: 'nearest_available', face: null, closeness: 0.61 }, { group: 'month', pattern: 'undetermined', face: null }],
        notExpressible: [{ code: 'ornament', box: [0, Math.round(h * 0.86), Math.round(w * 0.3), Math.round(h * 0.08)] }],
        notes: [{ code: 'ratio_adapted', w: 297, h: 150 }],
        measures: { mae: 0.031 },
        ...overrides,
    };
}

async function buildCalendar(job, options, log) {
    const run = new Run(job, log);
    const steps = [{ stage: 'read' }, { stage: 'read', note: 'rows_found', noteParams: { n: 7 } }, { stage: 'analyse', note: 'measuring' }, { stage: 'analyse', note: 'fonts' }, { stage: 'build', note: null }];
    try {
        for (const step of steps.slice(0, Math.max(1, options.beats))) { await run.beat(step); await sleep(options.beatMs); }
        if (options.scenario === 'hang') { log(`${job.id} build → hanging (no more heartbeats)`); return 'hang'; }
        if (options.scenario === 'provider') { await run.post('finish', { outcome: 'failed', error: 'provider_unavailable', usage: usage(3) }); log(`${job.id} build → failed provider`); return 'failed'; }
        // a scan builds for the calendar's own type: the claim says which
        const fortnight = Boolean(job.context && job.context.calendar && job.context.calendar.type === 'fortnight');
        const rendered = await run.post('render', { seq: ++run.seq, commands: fortnight ? fortnightCommands() : calendarCommands() });
        if (options.scenario === 'fail' || rendered.candidate === null || rendered.candidate === undefined) {
            if (rendered.candidate === null) log(`render rejected: ${JSON.stringify(rendered.issues).slice(0, 400)}`);
            await run.post('finish', { outcome: 'failed', error: 'build', usage: usage(4) });
            log(`${job.id} build → failed build`);
            return 'failed';
        }
        await sleep(options.beatMs);
        await run.beat({ stage: 'refine', round: 1, note: null });
        await sleep(options.beatMs);
        await run.beat({ stage: 'finish', round: 1 });
        await run.post('finish', { outcome: 'proposed', candidate: rendered.candidate, report: calendarReport(rendered.candidate, rendered, { ...(fortnight ? { type: 'fortnight', needsUpload: [], notes: [] } : {}), ...(options.calendar || {}) }), usage: usage(6) });
        log(`${job.id} build → proposed (calendar candidate ${rendered.candidate})`);
        return 'ready';
    } catch (error) {
        if (error.cancelled || (error.answer && error.answer.status === 409)) { log(`${job.id} build stopped: the app ended the run`); return 'cancelled'; }
        throw error;
    }
}

async function claim(lane) {
    // `mm` = the calendar instrument: without it the app hands out no calendar build; `mm14` = 14-day calendars
    const answer = await call('POST', 'claim', { body: { lane, scannerVersion: VERSION, instruments: ['ocr', 'crop', 'measure', 'font_rank', 'compare', 'mm', 'mm14'], capacity: 1 } });
    return answer.json && answer.json.job ? answer.json.job : null;
}

class Run {
    constructor(job, log) { this.job = job; this.seq = 0; this.log = log; }
    async post(endpoint, body) {
        const answer = await call('POST', `${this.job.id}/${endpoint}`, { body: { run: this.job.run, ...body }, lease: this.job.leaseToken });
        if (answer.status !== 200 || !answer.json || answer.json.ok !== true) throw Object.assign(new Error(`${endpoint} answered ${answer.status} ${JSON.stringify(answer.json)}`), { answer });
        return answer.json;
    }
    async beat(body) {
        const answer = await this.post('beat', { seq: ++this.seq, usage: usage(this.seq), ...body });
        if (answer.cancel) throw Object.assign(new Error('cancelled by the app'), { cancelled: true });
        return answer;
    }
}

async function intake(job, options, log) {
    const run = new Run(job, log);
    const source = await call('GET', `${job.id}/source?run=${job.run}`, { lease: job.leaseToken });
    if (source.status !== 200) throw new Error(`source answered ${source.status}`);
    // A real intake normalises here; the fake one hands the original back as the working copy.
    for (const which of ['working', 'preview']) await call('PUT', `${job.id}/artifact/${which}?run=${job.run}`, { raw: source.buffer, lease: job.leaseToken });
    await run.beat({ stage: 'check' });
    await sleep(options.beatMs);
    const stop = { stop: 'photo_of_print', blurry: 'blurry_or_small', nographic: 'not_expected_kind', several: 'several_designs', corrupt: 'unusable_input' }[options.scenario];
    if (stop && (job.try || 1) === 1) {
        await run.post('finish', { outcome: 'stopped', reason: stop, preCheck: { verdict: stop }, usage: usage(1) });
        log(`${job.id} intake → stopped (${stop})`);
        return 'stopped';
    }
    await run.post('finish', { outcome: 'passed', preCheck: { verdict: 'ok', confidence: 0.97 }, usage: usage(1) });
    log(`${job.id} intake → passed`);
    return 'passed';
}

/** The poster's headline and line, in the pixels of the uploaded picture. */
function foundTexts(job) {
    const width = (job.source && job.source.width) || 900;
    const height = (job.source && job.source.height) || 1200;
    const k = width / 900;
    return { width, height, boxes: [[80, 130, 620, 72], [80, 246, 300, 40]].map((box) => box.map((v) => Math.round(v * k))) };
}

async function build(job, options, log) {
    if (job.kind === 'calendar') return buildCalendar(job, options, log);
    const run = new Run(job, log);
    const steps = [
        // As the real scanner does it: the texts it found come with the next stage, and with
        // their boxes (the poster's two lines) for „Tyto texty jsme našli“.
        { stage: 'read' }, { stage: 'analyse', note: 'texts_found', noteParams: { n: 2 }, textBoxes: foundTexts(job) },
        { stage: 'analyse', note: 'zooming' }, { stage: 'analyse', note: 'measuring' }, { stage: 'analyse', note: 'fonts' },
        { stage: 'build', note: null },
    ];
    try {
        for (const step of steps.slice(0, Math.max(1, options.beats))) { await run.beat(step); await sleep(options.beatMs); }
        if (options.scenario === 'unfinished') job.unfinished = true;
        if (options.scenario === 'hang') { log(`${job.id} build → hanging (no more heartbeats)`); return 'hang'; }
        if (options.scenario === 'refuse') { await run.post('finish', { outcome: 'refused', usage: usage(3) }); log(`${job.id} build → refused`); return 'refused'; }
        if (options.scenario === 'provider') { await run.post('finish', { outcome: 'failed', error: 'provider_unavailable', usage: usage(3) }); log(`${job.id} build → failed provider`); return 'failed'; }
        if (options.scenario === 'timeout') { await run.post('finish', { outcome: 'failed', error: 'timeout', usage: usage(3) }); log(`${job.id} build → failed timeout`); return 'failed'; }
        const rendered = await run.post('render', { seq: ++run.seq, design: design(job) });
        if (options.scenario === 'fail' || rendered.candidate === null || rendered.candidate === undefined) {
            if (rendered.candidate === null) log(`render rejected: ${JSON.stringify(rendered.issues).slice(0, 400)}`);
            await run.post('finish', { outcome: 'failed', error: 'build', usage: usage(4) });
            log(`${job.id} build → failed build`);
            return 'failed';
        }
        await sleep(options.beatMs);
        await run.beat({ stage: 'refine', round: 1, note: null });
        await sleep(options.beatMs);
        await run.beat({ stage: 'finish', round: 1 });
        await sleep(Math.min(600, options.beatMs));
        await run.post('finish', { outcome: 'proposed', candidate: rendered.candidate, report: { ...report(job, rendered.candidate), ...(job.unfinished ? { unfinished: true, stopReason: 'time_limit' } : {}) }, boxes: boxes(), usage: usage(6) });
        log(`${job.id} build → proposed (candidate ${rendered.candidate})`);
        return 'ready';
    } catch (error) {
        if (error.cancelled || (error.answer && error.answer.status === 409)) { log(`${job.id} build stopped: the app ended the run`); return 'cancelled'; }
        throw error;
    }
}

/**
 * Serves until `options.stopped` is set (or forever). `options` is read on every loop, so a
 * test may change `scenario`, `beatMs`, `paused` (claims nothing while true) between scans.
 */
async function serve(options, log = (line) => console.log(`[fake-scanner] ${line}`)) {
    let done = 0;
    let lastTick = 0;
    for (;;) {
        if (options.stopped) return;
        // the tick goes out while paused too: a scanner that says nothing for 60 s reads as absent
        if (options.paused && Date.now() - lastTick <= 20000) { await sleep(300); continue; }
        if (Date.now() - lastTick > 20000) {
            const tick = await call('POST', 'tick', { body: { scannerVersion: VERSION, provider: { state: 'ok' } } });
            if (tick.status !== 200 || !tick.json || tick.json.mode !== 'on') throw new Error(`tick answered ${tick.status}, mode ${tick.json && tick.json.mode}. Switch AI scan on: bin/console app:ai-scan:status --mode=on`);
            lastTick = Date.now();
        }
        if (options.paused) { await sleep(300); continue; }
        // `options.accepts(scanId)` (the e2e harness: only scans of the e2e projects) — anything
        // else is given back at once, so a real scanner working beside us keeps its scans.
        const foreign = async (job) => {
            if (!options.accepts || options.accepts(job.id)) return false;
            await call('POST', `${job.id}/release`, { body: { run: job.run, usage: {} }, lease: job.leaseToken });
            log(`${job.id} is not ours — released`);
            await sleep(1500);
            return true;
        };
        const intakeJob = await claim('intake');
        if (intakeJob) { if (await foreign(intakeJob)) continue; await intake(intakeJob, options, log); if (options.intakeOnly && options.once) return; continue; }
        if (!options.intakeOnly) {
            const buildJob = await claim('build');
            if (buildJob) { if (await foreign(buildJob)) continue; await build(buildJob, options, log); done += 1; if (options.once) return; continue; }
        }
        if (options.once && done > 0) return;
        await sleep(options.idleMs || 700);
    }
}

module.exports = { serve, parseArgs, projectFilter };

if (require.main === module) {
    serve(parseArgs(process.argv.slice(2))).catch((error) => { console.error(`[fake-scanner] ${error.message}`); process.exit(1); });
}
