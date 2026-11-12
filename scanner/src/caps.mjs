// Hard caps of one run (architecture §4.9): turns, engine budget, wall clock, rendered candidates,
// validation rejections, images given to the model. Counters only — who aborts what is decided by
// the caller that asks `exceeded()`.
export const DEFAULT_LIMITS = Object.freeze({ turns: 40, budgetUsd: 1.5, builderMs: 300_000, runMs: 360_000, candidates: 3, rejections: 3, images: 18 });

export function limitsFromDefinition(def = {}) {
    const caps = def.caps ?? {}, timing = def.timing ?? {};
    const pick = (v, d) => (typeof v === 'number' && Number.isFinite(v) && v > 0 ? v : d);
    return {
        turns: pick(caps.turns, DEFAULT_LIMITS.turns),
        budgetUsd: pick(caps.engineBudgetMicroUsd, DEFAULT_LIMITS.budgetUsd * 1e6) / 1e6,
        builderMs: pick(timing.builderCapSeconds, DEFAULT_LIMITS.builderMs / 1000) * 1000,
        runMs: pick(timing.runCapSeconds, DEFAULT_LIMITS.runMs / 1000) * 1000,
        candidates: pick(caps.candidates, DEFAULT_LIMITS.candidates),
        rejections: pick(caps.validationRejections, DEFAULT_LIMITS.rejections),
        images: pick(caps.images, DEFAULT_LIMITS.images),
    };
}

export class Caps {
    constructor(limits = DEFAULT_LIMITS, now = () => Date.now()) {
        this.limits = { ...DEFAULT_LIMITS, ...limits };
        this.now = now;
        this.runStartedAt = now();
        this.builderStartedAt = null;
        this.turns = 0; this.costUsd = 0; this.candidates = 0; this.rejections = 0; this.images = 0;
        this.providerLostMs = 0;
    }
    startBuilder() { this.builderStartedAt = this.now(); }
    noteTurns(n) { this.turns = Math.max(this.turns, n); }
    noteCost(usd) { if (Number.isFinite(usd)) this.costUsd = Math.max(this.costUsd, usd); }
    noteProviderLost(ms) { this.providerLostMs += Math.max(0, ms); }

    /** A rendered candidate may be produced only while fewer than the cap exist. */
    candidatesLeft() { return Math.max(0, this.limits.candidates - this.candidates); }
    rejectionsLeft() { return Math.max(0, this.limits.rejections - this.rejections); }
    imagesLeft() { return Math.max(0, this.limits.images - this.images); }
    takeCandidate() { if (this.candidatesLeft() < 1) return false; this.candidates++; return true; }
    takeRejection() { this.rejections++; return this.rejectionsLeft() > 0; }
    /** Grants up to `n` images; returns how many may be sent. */
    takeImages(n = 1) { const g = Math.min(n, this.imagesLeft()); this.images += g; return g; }

    builderElapsedMs() { return this.builderStartedAt === null ? 0 : this.now() - this.builderStartedAt; }
    builderLeftMs() { return this.limits.builderMs - this.builderElapsedMs(); }
    runLeftMs() { return this.limits.runMs - (this.now() - this.runStartedAt); }

    /** The first cap that is used up, or null. */
    exceeded() {
        if (this.runLeftMs() <= 0) return 'run_time';
        if (this.builderStartedAt !== null && this.builderLeftMs() <= 0) return 'builder_time';
        if (this.turns >= this.limits.turns) return 'turns';
        if (this.costUsd >= this.limits.budgetUsd) return 'budget';
        return null;
    }
    /** §4.9: a cap hit counts as the provider's fault when ≥ `share` of the builder time used was lost to it. */
    providerLostDominates(share = 0.5) {
        const used = this.builderElapsedMs();
        return used > 0 && this.providerLostMs / used >= share;
    }
    snapshot() {
        return { turns: this.turns, costUsd: +this.costUsd.toFixed(6), candidates: this.candidates, rejections: this.rejections, images: this.images,
            builderMs: this.builderElapsedMs(), runMs: this.now() - this.runStartedAt, providerLostMs: this.providerLostMs, limits: this.limits };
    }
}
