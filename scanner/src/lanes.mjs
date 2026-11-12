// Lanes and jobs (architecture §4.1, §4.2, §4.9).
//
// Two lanes poll the app for work, each on its own loop: the INTAKE lane (normalise + pre-check, one at
// a time) and the BUILD lane (N at a time, N = concurrency). They share nothing, so a pre-check never
// waits behind a running build.
//
// A claimed job is run by a JobRunner in the main process: it forks one child process for the run
// (worker.mjs), keeps the heartbeat on its own timer, aborts and kills the child's process group, sends
// the release, and removes the run's scratch directory on every path.
import { fork } from 'node:child_process';
import { mkdtempSync, mkdirSync, rmSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { Heartbeat } from './timers.mjs';
import { timingOf } from './protocol.mjs';
import { killTree, descendants } from './proc.mjs';

const HERE = dirname(fileURLToPath(import.meta.url));
export const WORKER = join(HERE, 'worker.mjs');

const EMPTY_USAGE = Object.freeze({ provider: 'anthropic', model: null, inputTokens: 0, outputTokens: 0, cacheReadTokens: 0, cacheWriteTokens: 0, engineCostMicroUsd: 0, turns: 0, durationMs: 0 });
/** Outcomes whose `finish` the worker has sent: nothing is left to say to the app. */
const FINISHED = new Set(['passed', 'stopped', 'proposed', 'failed', 'refused']);

export function timingOfJob(job) {
    try { return timingOf(JSON.parse(job.definition.files['definition.json']).timing); } catch { return timingOf(); }
}

export class JobRunner {
    /**
     * @param scanner   HttpScannerApi of the main process
     * @param job       the claim answer's job
     * @param options   {scratchRoot, engine, workerPath, threads, log, killGraceMs}
     */
    constructor({ scanner, job, options = {} }) {
        this.scanner = scanner; this.job = job; this.lane = job.lane;
        this.o = { scratchRoot: null, engine: 'sdk', workerPath: WORKER, threads: 3, log: () => {}, env: process.env, ...options };
        this.timing = timingOfJob(job);
        this.api = scanner.forJob(job);
        this.state = { stage: this.lane === 'intake' ? 'check' : 'read', note: null, noteParams: null, round: null, usage: { ...EMPTY_USAGE }, resources: null };
        this.child = null; this.heartbeat = null; this.abortReason = null; this.result = null; this.startedAt = Date.now();
        this.knownPids = new Set(); this.done = null; this.scratchDir = null; this.summary = null;
        this.ids = { scan: job.id, lane: this.lane, run: job.run };
    }

    /** Cumulative usage as the app reads it; the duration grows while the run is silent. */
    #usage() { return { ...this.state.usage, durationMs: Math.max(this.state.usage.durationMs ?? 0, Date.now() - this.startedAt) }; }

    /** Asks the run to stop. The run loop kills the child's process group when it has not ended within the grace. */
    abort(reason, detail = null) {
        if (this.abortReason) return;
        this.abortReason = reason;
        this.o.log('info', 'run aborted', { ...this.ids, reason, ...(detail ?? {}) });
        try { if (this.child?.connected) this.child.send({ type: 'abort', reason }); } catch { /* gone */ }
        this.onAbort?.();
    }

    /** SIGTERM path: abort, kill, one release — resolves when this job is given back. */
    shutdown() { this.closingCancelled = true; this.abort('shutdown'); return this.done ?? Promise.resolve(); }

    run() { this.done ??= this.#run(); return this.done; }

    async #run() {
        const { timing, job, lane } = this;
        mkdirSync(this.o.scratchRoot, { recursive: true });
        this.scratchDir = mkdtempSync(join(this.o.scratchRoot, `${lane}-${String(job.id).slice(0, 8)}-r${job.run}-`));
        const killGraceMs = this.o.killGraceMs ?? timing.killGraceSeconds * 1000;
        // the hang guard of the main process: the run cap W (or the intake cap) plus one finish inside the
        // app-call rule — a child that outlives it is killed, and the lease is left to lapse
        const guardMs = this.o.guardMs ?? (lane === 'intake'
            ? timing.intakeLeaseSeconds * 1000
            : (timing.runCapSeconds + timing.appOutageToleranceSeconds + timing.appCallAttemptSeconds + 15) * 1000);
        let exit = null, kill = null, closing = null;
        try {
            const ended = new Promise((resolve) => {
                this.child = fork(this.o.workerPath, [], {
                    detached: true, // the leader of its own process group: the engine and the OCR sidecar are killed with it
                    stdio: ['ignore', 'inherit', 'inherit', 'ipc'],
                    env: { ...this.o.env, SCANNER_WORKER: '1', TMPDIR: this.scratchDir, SCANNER_OCR_THREADS: String(this.o.threads), OMP_NUM_THREADS: String(this.o.threads), ...(this.o.workerEnv ?? {}) },
                });
                this.child.on('message', (m) => {
                    if (m?.type === 'state' && m.state) {
                        const s = m.state;
                        Object.assign(this.state, { stage: s.stage ?? this.state.stage, note: s.note ?? null, noteParams: s.noteParams ?? null, round: s.round ?? this.state.round, resources: s.resources ?? this.state.resources });
                        if (s.usage) this.state.usage = { ...this.state.usage, ...s.usage };
                    } else if (m?.type === 'result') {
                        this.result = m.result;
                        if (m.result?.usage) this.state.usage = { ...this.state.usage, ...m.result.usage };
                    }
                });
                this.child.on('error', (e) => resolve({ code: null, signal: null, error: String(e?.message ?? e) }));
                this.child.on('exit', (code, signal) => resolve({ code, signal }));
            });
            this.child.send({
                type: 'job', lane, job, scratchDir: this.scratchDir, engine: this.o.engine,
                app: { baseUrl: this.scanner.baseUrl, token: this.scanner.token, scannerVersion: this.scanner.scannerVersion, timing: this.scanner.timing },
            });
            // the heartbeat: a timer of THIS process, started with the claim — independent of the child, the engine, a turn
            if (lane === 'build') {
                this.heartbeat = new Heartbeat({
                    api: this.api, timing, onAbort: (reason, detail) => this.abort(reason, detail),
                    state: () => ({ stage: this.state.stage, note: this.state.note, noteParams: this.state.noteParams, round: this.state.round, usage: this.#usage(), resources: this.state.resources }),
                    onBeat: (b) => { if (!b.ok) this.o.log('warn', 'beat not acknowledged', { ...this.ids, seq: b.seq, code: b.code, status: b.status }); },
                }).start();
            }
            const guard = setTimeout(() => this.abort('hung'), guardMs);
            const aborted = new Promise((resolve) => { this.onAbort = resolve; if (this.abortReason) resolve(); });
            const first = await Promise.race([ended.then((e) => ({ exit: e })), aborted.then(() => ({ aborted: true }))]);
            if (first.exit) exit = first.exit;
            else {
                // aborted: the child gets the grace to stop its engine and report its last usage, then the group is killed
                for (const d of descendants(this.child.pid)) this.knownPids.add(d.pid);
                exit = await Promise.race([ended, new Promise((resolve) => setTimeout(() => resolve(null), killGraceMs))]);
            }
            clearTimeout(guard);
            // whatever happened: nothing of this run may be left running (the engine and OCR process groups, by pid)
            kill = await killTree(this.child.pid, { known: [...this.knownPids] });
            if (kill.survivors.length) this.o.log('error', 'processes survived the kill', { ...this.ids, survivors: kill.survivors.length });
            if (!exit) exit = await Promise.race([ended, new Promise((resolve) => setTimeout(() => resolve({ code: null, signal: 'SIGKILL' }), 2000))]);
            closing = await this.#close(exit);
        } catch (e) {
            this.o.log('error', 'run failed in the main process', { ...this.ids, error: String(e?.message ?? e).slice(0, 200) });
            if (this.child?.pid) kill = await killTree(this.child.pid, { known: [...this.knownPids] });
        } finally {
            this.heartbeat?.stop();
            rmSync(this.scratchDir, { recursive: true, force: true });
        }
        const r = this.result;
        const outcome = r?.outcome ?? (this.abortReason ? 'aborted' : 'crashed');
        this.summary = {
            ...this.ids, outcome, reason: this.abortReason ?? r?.reason ?? null, error: r?.error ?? null, detail: r?.detail ?? null,
            finish: r?.finish ?? null, closing, exit, killed: kill ? { processes: kill.killed.length, survivors: kill.survivors.length, ms: kill.ms } : null,
            beats: this.heartbeat?.summary() ?? null, usage: this.#usage(), wallMs: Date.now() - this.startedAt, result: r ?? null,
        };
        this.o.log(outcome === 'crashed' || outcome === 'error' ? 'error' : 'info', 'run ended', {
            ...this.ids, outcome, reason: this.summary.reason, error: this.summary.error,
            // what went wrong inside the scanner, as its own short message (an error of the code or a library — never text of a picture)
            detail: typeof r?.detail === 'string' ? r.detail.replace(/[^A-Za-z0-9 _.:()\/-]/g, '').slice(0, 120) : null,
            status: r?.finish?.status ?? closing?.status ?? null, applied: r?.finish?.applied ?? null,
            closing: closing?.call ?? null, wallMs: this.summary.wallMs, turns: this.summary.usage.turns, model: this.summary.usage.model, exit: exit?.signal ?? exit?.code ?? null,
        });
        return this.summary;
    }

    /**
     * The last word to the app, so that the usage row is closed on every path:
     *   finish sent by the child      → nothing more;
     *   shutdown                       → one `release` (build → queued, intake → unclaimed; no restart consumed);
     *   provider blocked (probe failed)→ one `release` with `reason: provider_blocked`;
     *   cancelled / interrupted / lost → one closing beat: the app records the usage of a run that no longer holds the scan;
     *   the child died or hung          → one closing beat with the last figures; the lease lapses and the reaper
     *                                    restarts the scan once, then fails it (`interrupted`) — never a release,
     *                                    which would hand a poisonous input straight back.
     */
    async #close(exit) {
        const r = this.result, usage = this.#usage();
        this.heartbeat?.stop();
        if (r && FINISHED.has(r.outcome) && !this.abortReason) return { call: null, status: r.finish?.status ?? null };
        if (r && FINISHED.has(r.outcome) && r.finish) return { call: null, status: r.finish.status ?? null }; // finished in the same moment it was aborted
        const release = async (payload) => {
            try { const j = await this.api.release(payload); return { call: 'release', applied: j?.applied ?? null, status: j?.status ?? null }; } catch (e) { return { call: 'release', failed: e?.code ?? 'error' }; }
        };
        if (r?.outcome === 'blocked') return { ...(await release({ usage, reason: 'provider_blocked', providerError: r.providerError ?? r.error ?? null })), providerBlocked: true };
        if (this.abortReason === 'shutdown' || r?.outcome === 'released') return release({ usage, reason: 'shutdown' });
        if (this.lane === 'intake' || this.abortReason === 'gone') return { call: null };
        if (!this.heartbeat) return { call: null };
        // One closing beat. After a self-abort the app is away by definition: the beat is repeated for as long as
        // the app-outage tolerance (60 s) — this job's slot stays taken meanwhile, so the scan is not claimed again
        // before its last figures are in. A 409 is an answer too: the app records the usage of a run it has put back.
        const until = Date.now() + (this.abortReason === 'app_unreachable' ? this.timing.appOutageToleranceSeconds * 1000 : 0);
        for (let attempts = 1; ; attempts++) {
            const b = await this.heartbeat.beat({ final: true });
            const refused = !b && [409, 410].includes(this.heartbeat.lastError?.status);
            if (b || refused) return { call: 'beat', acknowledged: true, status: b?.status ?? null, code: refused ? this.heartbeat.lastError.code : null, attempts };
            if (Date.now() >= until || this.closingCancelled) return { call: 'beat', acknowledged: false, attempts };
            await new Promise((ok) => setTimeout(ok, Math.min(5000, this.timing.appOutageToleranceSeconds * 1000 / 12)));
        }
    }
}

/**
 * One lane: a poll loop with `slots` jobs at once.
 * `canClaim()` lets the scanner hold a lane back (shutting down, protocol mismatch).
 */
export class Lane {
    constructor({ name, slots = 1, scanner, capacity = slots, pollMs = 5000, canClaim = () => true, makeRunner, onAnswer = null, onEnd = null, log = () => {}, now = () => Date.now() }) {
        Object.assign(this, { name, slots, scanner, capacity, pollMs, canClaim, makeRunner, onAnswer, onEnd, log, now });
        this.active = new Set(); this.stopped = true; this.loop = null; this.wake = null;
        this.lastPollAt = null; this.lastPollOkAt = null; this.lastLoopAt = null; this.lastReason = null; this.lastError = null; this.claimed = 0; this.ended = 0;
        this.abortPoll = null;
    }

    start() { if (!this.stopped) return this; this.stopped = false; this.loop = this.#loop(); return this; }

    /**
     * Stops claiming. Running jobs are not touched — the caller shuts them down. A claim that is in flight is
     * given `graceMs` to answer (a job it hands out is released at once) before it is cut: a cut claim whose
     * answer carried a job would leave that scan to a lost lease.
     */
    async stop({ graceMs = 3000 } = {}) {
        this.stopped = true; this.wake?.();
        let t;
        await Promise.race([this.loop, new Promise((ok) => { t = setTimeout(ok, graceMs); })]);
        clearTimeout(t);
        this.abortPoll?.abort();
        await this.loop;
    }

    async #loop() {
        while (!this.stopped) {
            this.lastLoopAt = this.now();
            let claimedOne = false;
            if (this.active.size < this.slots && this.canClaim()) {
                this.lastPollAt = this.now();
                this.abortPoll = new AbortController();
                try {
                    const r = await this.scanner.claim(this.name, this.capacity, { signal: this.abortPoll.signal });
                    this.lastPollOkAt = this.now(); this.lastReason = r.reason; this.lastError = null;
                    this.onAnswer?.(this.name, r);
                    if (r.job && this.stopped) {
                        // claimed in the moment the scanner began to stop: give it straight back
                        try { await r.api.release({ usage: { ...EMPTY_USAGE }, reason: 'shutdown' }); } catch { /* a lost lease then */ }
                    } else if (r.job) { claimedOne = true; this.#start(r.job); }
                } catch (e) {
                    if (!this.stopped) { this.lastError = { code: e?.code ?? 'error', status: e?.status ?? null, at: this.now() }; this.onAnswer?.(this.name, { error: e }); }
                }
            }
            if (this.stopped) break;
            // a claim that handed out a job is followed by the next one at once while a slot is free
            if (claimedOne && this.active.size < this.slots) continue;
            await new Promise((resolve) => { const t = setTimeout(resolve, this.pollMs); this.wake = () => { clearTimeout(t); resolve(); }; });
            this.wake = null;
        }
    }

    #start(job) {
        this.claimed++;
        const runner = this.makeRunner(job);
        this.active.add(runner);
        this.log('info', 'claimed', { scan: job.id, lane: this.name, run: job.run, try: job.try ?? null, definition: job.definition?.version ?? null });
        runner.run().then((summary) => { this.onEnd?.(this.name, summary); }, (e) => { this.log('error', 'runner failed', { scan: job.id, lane: this.name, error: String(e?.message ?? e).slice(0, 200) }); })
            .finally(() => { this.active.delete(runner); this.ended++; this.wake?.(); });
    }

    status() {
        const now = this.now();
        const ago = (t) => (t === null ? null : Math.round((now - t) / 1000));
        return { slots: this.slots, active: this.active.size, claimed: this.claimed, ended: this.ended, lastPollAgoS: ago(this.lastPollAt), lastPollOkAgoS: ago(this.lastPollOkAt), lastLoopAgoS: ago(this.lastLoopAt), lastReason: this.lastReason, lastError: this.lastError?.code ?? null, running: !this.stopped };
    }
}
