// The scanner service: one main process that talks to the app, and one child process per run.
//
//   main process   config · protocol handshake (tick) · the two lanes' claim polls · every heartbeat ·
//                  provider probe · health · shutdown · scratch
//   child process  one intake or one build (worker.mjs): instruments, engine, the model loop, finish
//
// Nothing here knows a prompt, a schema or a limit: they arrive with each claim (the definition).
import { fork } from 'node:child_process';
import { existsSync, mkdirSync, mkdtempSync, readdirSync, rmSync } from 'node:fs';
import { execFile } from 'node:child_process';
import { join } from 'node:path';
import { HttpScannerApi, PROTOCOL_VERSION, INSTRUMENTS, DEFAULT_TIMING } from './protocol.mjs';
import { Lane, JobRunner, WORKER } from './lanes.mjs';
import { every } from './timers.mjs';
import { startHealthServer } from './health.mjs';
import { installShutdown } from './shutdown.mjs';
import { killTree } from './proc.mjs';
import { loadConfig, describeConfig } from './config.mjs';
import { ocrPython } from './instruments/ocr.mjs';

export function logger(mode = 'json', write = (line) => process.stdout.write(line + '\n')) {
    if (mode === 'silent') return () => {};
    // ids, codes, counts and durations only — never OCR text, a design, a prompt or bytes
    return (level, msg, fields = {}) => write(mode === 'text'
        ? `${new Date().toISOString()} ${level.toUpperCase().padEnd(5)} ${msg} ${Object.entries(fields).filter(([, v]) => v !== null && v !== undefined).map(([k, v]) => `${k}=${typeof v === 'object' ? JSON.stringify(v) : v}`).join(' ')}`
        : JSON.stringify({ t: new Date().toISOString(), level, msg, ...fields }));
}

/** Does the OCR sidecar start here? → the instruments this scanner can offer. */
export function checkInstruments(python = ocrPython()) {
    return new Promise((resolve) => {
        execFile(python, ['-c', 'import rapidocr, onnxruntime'], { timeout: 30_000 }, (err) => {
            resolve(err ? INSTRUMENTS.filter((i) => i !== 'ocr') : [...INSTRUMENTS]);
        });
    });
}

export class Scanner {
    /**
     * @param config  loadConfig()
     * @param deps    {log, fetchImpl, workerPath, engine, timing, instruments} — tests replace the worker, the engine and the clock of the lanes
     */
    constructor(config, deps = {}) {
        this.config = config;
        this.log = deps.log ?? logger(config.log);
        this.timing = { ...DEFAULT_TIMING, ...(deps.timing ?? {}) };
        this.deps = { workerPath: WORKER, engine: config.engine, instruments: null, ...deps };
        this.api = null; this.lanes = {}; this.ticker = null; this.prober = null; this.health = null;
        this.startedAt = Date.now(); this.stopping = false; this.stopped = null;
        this.app = { mode: null, providerState: null, maxRunning: null, protocolVersion: null };
        this.provider = { state: 'ok', error: null, since: null, probes: 0, report: null };
        this.protocol = { ours: PROTOCOL_VERSION, app: null, accepted: null };
        this.lastDefinition = null; this.ended = [];
        this.probeChild = null;
    }

    async start() {
        const { config, log } = this;
        log('info', 'scanner starting', describeConfig(config));
        // scratch: swept at start — whatever a killed scanner left behind
        rmSync(config.scratchDir, { recursive: true, force: true });
        mkdirSync(config.scratchDir, { recursive: true });
        const instruments = this.deps.instruments ?? await checkInstruments();
        if (!instruments.includes('ocr')) log('error', 'the OCR sidecar does not start: the app will refuse this scanner as too old', { python: ocrPython() });
        this.api = new HttpScannerApi({ baseUrl: config.appUrl, token: config.token, scannerVersion: config.version, instruments, timing: this.timing, fetchImpl: this.deps.fetchImpl ?? globalThis.fetch, record: this.deps.record ?? null });
        const runnerOptions = { scratchRoot: config.scratchDir, engine: this.deps.engine, workerPath: this.deps.workerPath, threads: config.threadsPerRun, log, workerEnv: this.deps.workerEnv, killGraceMs: this.deps.killGraceMs };
        const makeRunner = (job) => { this.lastDefinition = job.definition ?? this.lastDefinition; return new JobRunner({ scanner: this.api, job, options: runnerOptions }); };
        const canClaim = () => !this.stopping && this.protocol.accepted !== false;
        const common = { scanner: this.api, pollMs: this.timing.claimPollSeconds * 1000, canClaim, makeRunner, log, onAnswer: (lane, r) => this.#claimAnswer(lane, r), onEnd: (lane, s) => this.#jobEnded(lane, s) };
        // the intake lane never waits behind a build: its own loop, its own slot
        this.lanes.intake = new Lane({ name: 'intake', slots: 1, capacity: 1, ...common });
        this.lanes.build = new Lane({ name: 'build', slots: config.concurrency, capacity: config.concurrency, ...common });
        this.health = startHealthServer({ port: config.healthPort || null, host: config.healthHost, file: config.healthFile, status: () => this.status() });
        const port = await this.health.ready;
        // the handshake: the first tick says which protocol the app speaks and whether the provider is blocked
        this.ticker = every(this.timing.tickSeconds * 1000, () => this.#tick());
        await this.ticker.idle();
        this.lanes.intake.start(); this.lanes.build.start();
        log('info', 'scanner started', { healthPort: port, instruments: instruments.length, protocol: PROTOCOL_VERSION, appProtocol: this.protocol.app, mode: this.app.mode });
        return this;
    }

    async #tick() {
        try {
            const report = this.provider.report; // a probe result is reported once, by the next tick
            const t = await this.api.tick({ provider: report });
            if (report && this.provider.report === report) this.provider.report = null;
            this.app = { mode: t.mode ?? null, providerState: t.providerState ?? null, maxRunning: t.maxRunning ?? null, protocolVersion: t.protocolVersion ?? null };
            this.protocol.app = t.protocolVersion ?? null;
            // the app accepts N and N − 1; a scanner AHEAD of the app is refused the same way
            const accepted = t.protocolVersion === undefined || t.protocolVersion === null ? this.api.protocolAccepted : (PROTOCOL_VERSION === t.protocolVersion || PROTOCOL_VERSION === t.protocolVersion - 1);
            if (accepted !== this.protocol.accepted) this.log(accepted === false ? 'error' : 'info', accepted === false ? 'protocol mismatch: this scanner does not claim' : 'protocol accepted', { ours: PROTOCOL_VERSION, app: t.protocolVersion ?? null });
            this.protocol.accepted = accepted;
            if (t.providerState === 'blocked' && this.provider.state !== 'blocked') this.#providerBlocked('reported_by_app');
        } catch (e) {
            if (e?.code === 'protocol_too_old') { if (this.protocol.accepted !== false) this.log('error', 'protocol mismatch: this scanner does not claim', { ours: PROTOCOL_VERSION, app: e.body?.protocolVersion ?? null }); this.protocol.accepted = false; this.protocol.app = e.body?.protocolVersion ?? this.protocol.app; }
            else this.log('warn', 'tick failed', { code: e?.code ?? 'error', status: e?.status ?? null });
        }
    }

    #claimAnswer(lane, r) {
        if (r.error) { if (r.error.code === 'protocol_too_old') this.protocol.accepted = false; return; }
        if (r.mode) this.app.mode = r.mode;
        if (r.reason === 'provider_blocked' && this.provider.state !== 'blocked') this.#providerBlocked('reported_by_app');
        if (r.reason === 'scanner_too_old' && this.tooOldLogged !== true) { this.tooOldLogged = true; this.log('error', 'the app refuses this scanner as too old (an instrument it requires is missing)', { lane }); }
    }

    #jobEnded(lane, s) {
        this.ended.push({ at: Date.now(), scan: s.scan, lane, run: s.run, outcome: s.outcome, reason: s.reason });
        if (this.ended.length > 50) this.ended.shift();
        if (s.closing?.providerBlocked) this.#providerBlocked(s.result?.providerError ?? s.error ?? 'unknown');
        // an intake that passed made a `queued` scan: the build lane asks at once instead of at its next poll
        if (lane === 'intake' && s.outcome === 'passed') this.lanes.build?.wake?.();
    }

    /** The provider is blocked (billing / auth class, confirmed by a probe): claims are refused by the app; probe every 5 min. */
    #providerBlocked(error) {
        if (this.stopping) return;
        this.provider = { ...this.provider, state: 'blocked', error, since: Date.now() };
        this.log('error', 'provider blocked: probing until it answers', { error, everyS: this.timing.providerProbeSeconds });
        this.prober?.stop();
        this.prober = every(this.timing.providerProbeSeconds * 1000, () => this.#probe(), { immediate: error === 'reported_by_app' });
    }

    /** One 1-token request in a child process of its own. */
    async #probe() {
        if (this.stopping || this.provider.state !== 'blocked') return;
        const scratchDir = mkdtempSync(join(this.config.scratchDir, 'probe-'));
        let definition = null;
        try { definition = JSON.parse(this.lastDefinition?.files?.['definition.json'] ?? 'null'); } catch { /* none seen yet */ }
        const result = await new Promise((resolve) => {
            const child = this.probeChild = fork(this.deps.workerPath, [], { detached: true, stdio: ['ignore', 'inherit', 'inherit', 'ipc'], env: { ...process.env, SCANNER_WORKER: '1', TMPDIR: scratchDir, ...(this.deps.workerEnv ?? {}) } });
            let r = null;
            const timer = setTimeout(() => { killTree(child.pid); }, 60_000);
            child.on('message', (m) => { if (m?.type === 'result') r = m.result; });
            child.on('exit', () => { clearTimeout(timer); resolve(r); });
            child.on('error', () => { clearTimeout(timer); resolve(null); });
            child.send({ type: 'probe', model: definition?.models?.preCheck ?? this.config.probeModel, settings: definition?.preCheck ?? { requestTimeoutSeconds: 18, retries: 1 }, scratchDir, engine: this.deps.engine });
        });
        if (this.probeChild?.pid) await killTree(this.probeChild.pid, { waitMs: 1000 });
        this.probeChild = null;
        rmSync(scratchDir, { recursive: true, force: true });
        this.provider.probes++;
        if (result?.outcome === 'ok') {
            this.log('info', 'provider answers again', { probes: this.provider.probes });
            this.provider = { state: 'ok', error: null, since: null, probes: 0, report: { state: 'ok' } };
            this.prober?.stop(); this.prober = null;
            await this.ticker?.runNow();
        } else {
            this.provider.report = { state: 'blocked', error: result?.error ?? this.provider.error ?? 'unknown' };
            this.log('warn', 'provider still blocked', { probes: this.provider.probes, error: result?.error ?? null });
        }
    }

    /** Pids of the run children that are alive (the shutdown limit kills their trees). */
    children() {
        const pids = [];
        for (const lane of Object.values(this.lanes)) for (const r of lane.active) if (r.child?.pid) pids.push(r.child.pid);
        if (this.probeChild?.pid) pids.push(this.probeChild.pid);
        return pids;
    }

    /** Shutdown: stop claiming → abort, kill, one release per job → scratch removed. */
    stop(reason = 'stop') {
        this.stopped ??= (async () => {
            this.stopping = true;
            this.ticker?.stop(); this.prober?.stop();
            const lanes = Object.values(this.lanes);
            const jobs = lanes.flatMap((l) => [...l.active]);
            this.log('info', 'stopping', { reason, jobs: jobs.length });
            // claiming stops first; a poll in flight is cut
            const stopLanes = Promise.all(lanes.map((l) => l.stop()));
            const released = await Promise.all(jobs.map((j) => j.shutdown().catch(() => null)));
            await stopLanes;
            if (this.probeChild?.pid) await killTree(this.probeChild.pid, { waitMs: 1000 });
            await this.health?.close();
            rmSync(this.config.scratchDir, { recursive: true, force: true });
            this.log('info', 'stopped', { jobs: jobs.length, released: released.filter((s) => s?.closing?.call === 'release' && !s.closing.failed).length });
            return released;
        })();
        return this.stopped;
    }

    status() {
        const now = Date.now();
        const lanes = Object.fromEntries(Object.entries(this.lanes).map(([k, l]) => [k, { ...l.status(), held: this.protocol.accepted === false }]));
        return {
            now, startedAt: this.startedAt, stopping: this.stopping, version: this.config.version, lanes,
            app: { lastOkAgoS: this.api?.lastOkAt ? Math.round((now - this.api.lastOkAt) / 1000) : null, lastError: this.api?.lastError ? { code: this.api.lastError.code, status: this.api.lastError.status ?? null, agoS: Math.round((now - this.api.lastError.at) / 1000) } : null },
            mode: this.app.mode, provider: { state: this.provider.state, error: this.provider.error, probes: this.provider.probes },
            protocol: this.protocol, concurrency: this.config.concurrency,
            scratch: existsSync(this.config.scratchDir) ? readdirSync(this.config.scratchDir).length : 0,
        };
    }
}

/** `scanner serve`: runs until SIGTERM / SIGINT. */
export async function serve(env = process.env) {
    const config = loadConfig(env);
    const scanner = new Scanner(config);
    const shutdownMs = DEFAULT_TIMING.shutdownSeconds * 1000;
    const { shutdown } = installShutdown({ scanner, limitMs: shutdownMs, log: scanner.log });
    process.on('uncaughtException', (e) => { scanner.log('error', 'uncaught exception', { error: String(e?.stack ?? e).slice(0, 500) }); shutdown('uncaught_exception'); });
    process.on('unhandledRejection', (e) => { scanner.log('error', 'unhandled rejection', { error: String(e?.stack ?? e).slice(0, 500) }); });
    await scanner.start();
    return scanner;
}
