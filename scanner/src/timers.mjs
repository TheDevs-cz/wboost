// The scanner's timers (architecture §4.9): the heartbeat of a running job, the 30 s tick, the provider
// probe. All of them live in the scanner's MAIN process — a model turn, a blocked engine or a busy
// worker never delays one.
import { timingOf } from './protocol.mjs';

const cut = (promise, ms, code) => {
    let t;
    return Promise.race([promise, new Promise((_, no) => { t = setTimeout(() => no(Object.assign(new Error(code), { code })), ms); })]).finally(() => clearTimeout(t));
};

/**
 * Heartbeat of one job.
 *
 * - a beat leaves every H (15 s) on a timer; each is its own attempt, cut at 10 s, and the next one is
 *   never queued behind a hung one;
 * - it carries the stage / note / round the UX shows and the CUMULATIVE usage of the run;
 * - the answer may end the run: `cancel` (cancelled, or interrupted by mode `off`), 409 (`stale_run`,
 *   `lease_lost`), 410 (`gone`);
 * - self-abort A: when 105 s have passed since the SEND time of the last acknowledged beat, the run is
 *   aborted (`app_unreachable`) — the engine must stop spending before the app gives up the lease (120 s).
 *
 * `state()` → {stage, note, noteParams, round, usage, resources, textBoxes?}; `onAbort(reason, detail)` is called once.
 */
export class Heartbeat {
    constructor({ api, timing = {}, state, onAbort, now = () => Date.now(), onBeat = null }) {
        this.api = api; this.timing = timingOf(timing); this.state = state; this.onAbort = onAbort; this.now = now; this.onBeat = onBeat;
        this.seq = 0; this.acked = 0; this.failed = 0; this.inFlight = 0;
        this.lastAckedSend = null; this.lastAnswer = null; this.lastError = null; this.aborted = null; this.stopped = false;
        this.timer = null; this.abortTimer = null;
    }

    start() {
        this.lastAckedSend = this.now(); // the claim answer is the first acknowledgement
        this.#armSelfAbort();
        this.timer = setInterval(() => { this.beat(); }, this.timing.heartbeatSeconds * 1000);
        return this;
    }

    #armSelfAbort() {
        clearTimeout(this.abortTimer);
        if (this.stopped || this.aborted) return;
        const left = this.lastAckedSend + this.timing.selfAbortSeconds * 1000 - this.now();
        this.abortTimer = setTimeout(() => this.#abort('app_unreachable', { sinceLastAckedSendMs: this.now() - this.lastAckedSend }), Math.max(0, left));
    }

    #abort(reason, detail = null) {
        if (this.aborted || this.stopped) return;
        this.aborted = { reason, detail, at: this.now() };
        clearTimeout(this.abortTimer);
        try { this.onAbort?.(reason, detail); } catch { /* the caller's problem */ }
    }

    /** Sends one beat now (also used for the closing beat of a run that ends without finish / release). */
    async beat({ final = false } = {}) {
        if (this.stopped && !final) return null;
        const sentAt = this.now();
        const s = this.state?.() ?? {};
        const body = { seq: ++this.seq, stage: s.stage ?? undefined, note: s.note ?? null, noteParams: s.noteParams ?? null, ...(Number.isInteger(s.round) ? { round: s.round } : {}), usage: s.usage ?? {}, ...(s.resources ? { resources: s.resources } : {}),
            // where the reading found lines of text (the waiting screen outlines them): sent until one beat carrying them was acknowledged
            ...(s.textBoxes && s.textBoxes !== this.ackedTextBoxes ? { textBoxes: s.textBoxes } : {}) };
        this.inFlight++;
        try {
            // the client cuts the attempt itself; the outer cut covers a client that would not
            const r = await cut(this.api.beat(body), this.timing.heartbeatAttemptSeconds * 1000 + 250, 'beat_timeout');
            this.acked++;
            if (body.textBoxes) this.ackedTextBoxes = body.textBoxes;
            this.lastAnswer = { at: this.now(), cancel: !!r?.cancel, mode: r?.mode ?? null, status: r?.status ?? null };
            if (sentAt > this.lastAckedSend) { this.lastAckedSend = sentAt; this.#armSelfAbort(); }
            this.onBeat?.({ seq: body.seq, ok: true, answer: r });
            // `cancel`: the scan left this run — cancelled by the user, interrupted by mode `off`, or ended some other way
            if (r?.cancel) this.#abort(r.status === 'cancelled' ? 'cancelled' : r.mode === 'off' ? 'mode_off' : 'ended', { status: r.status ?? null });
            else if (r?.mode === 'off') this.#abort('mode_off', { status: r.status ?? null });
            return r;
        } catch (e) {
            this.failed++;
            this.lastError = { seq: body.seq, code: e?.code ?? 'error', status: e?.status ?? null };
            this.onBeat?.({ seq: body.seq, ok: false, code: e?.code ?? 'error', status: e?.status ?? null });
            if (e?.status === 410 || e?.code === 'gone') this.#abort('gone');
            else if (e?.status === 409 || e?.code === 'lease_lost' || e?.code === 'stale_run') this.#abort('lease_lost', { code: e.code });
            return null;
        } finally { this.inFlight--; }
    }

    stop() { this.stopped = true; clearInterval(this.timer); clearTimeout(this.abortTimer); }
    summary() { return { beats: this.seq, acked: this.acked, failed: this.failed, aborted: this.aborted?.reason ?? null }; }
}

/**
 * Runs `fn` now and then every `everyMs`, one run at a time (a slow run delays the next, never overlaps
 * it). → {stop(), runNow()}.
 */
export function every(everyMs, fn, { immediate = true, onError = null } = {}) {
    let stopped = false, timer = null, running = null;
    const run = async () => {
        if (stopped || running) return running;
        running = (async () => { try { await fn(); } catch (e) { onError?.(e); } finally { running = null; if (!stopped) timer = setTimeout(run, everyMs); } })();
        return running;
    };
    if (immediate) run(); else timer = setTimeout(run, everyMs);
    return { stop: () => { stopped = true; clearTimeout(timer); }, runNow: () => { clearTimeout(timer); return run(); }, idle: () => running ?? Promise.resolve() };
}
