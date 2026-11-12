// Resource probe: samples the scanner's own process tree (node, the engine binary, the OCR sidecar,
// the intake child) — RSS, CPU time, cores in use — and attributes the samples to the stage that was
// running. Used for the measurements of architecture §8 and for the `resources` field of a heartbeat.
import { execFile } from 'node:child_process';
import { readFileSync, readdirSync } from 'node:fs';

const LINUX = process.platform === 'linux';
const TICK = 100; // Linux USER_HZ

function psTable() {
    return new Promise((resolve) => {
        execFile('ps', ['-axo', 'pid=,ppid=,rss=,time=,comm='], { maxBuffer: 16 << 20 }, (err, out) => {
            if (err) return resolve([]);
            resolve(out.split('\n').map((l) => l.trim().match(/^(\d+)\s+(\d+)\s+(\d+)\s+(\S+)\s+(.*)$/)).filter(Boolean).map((m) => ({ pid: +m[1], ppid: +m[2], rssKb: +m[3], cpuS: parseTime(m[4]), comm: m[5] })));
        });
    });
}
/** `ps` cpu time: [[dd-]hh:]mm:ss[.ff] */
export function parseTime(t) {
    let days = 0;
    if (t.includes('-')) { const [d, rest] = t.split('-'); days = +d; t = rest; }
    const parts = t.split(':').map(Number);
    const s = parts.pop() ?? 0, m = parts.pop() ?? 0, h = parts.pop() ?? 0;
    return days * 86400 + h * 3600 + m * 60 + s;
}
function procTable() {
    const rows = [];
    for (const d of readdirSync('/proc')) {
        if (!/^\d+$/.test(d)) continue;
        try {
            const stat = readFileSync(`/proc/${d}/stat`, 'utf8');
            const close = stat.lastIndexOf(')');
            const comm = stat.slice(stat.indexOf('(') + 1, close);
            const f = stat.slice(close + 2).split(' ');
            // after "pid (comm) ": state ppid … utime(14) stime(15) … rss(24, pages)
            rows.push({ pid: +d, ppid: +f[1], rssKb: (+f[21]) * 4, cpuS: ((+f[11]) + (+f[12])) / TICK, comm });
        } catch { /* the process ended while we looked */ }
    }
    return rows;
}

export function classify(comm, pid, self = process.pid) {
    if (pid === self) return 'node';
    const c = comm.toLowerCase();
    if (c.includes('claude')) return 'engine';
    if (c.includes('python')) return 'ocr';
    if (c.includes('node')) return 'node-child';
    return 'other';
}

export class ResourceProbe {
    constructor({ intervalMs = 500, rootPid = process.pid } = {}) {
        this.intervalMs = intervalMs; this.rootPid = rootPid;
        this.samples = 0; this.stage = 'start'; this.timer = null; this.busy = false;
        this.classes = {};  // class → {peakRssKb, rssSum, rssSamples, cpuS}
        this.stages = {};   // stage → {ms, cpuS, peakRssKb, peakCores}
        this.pids = new Map(); // pid → {cls, cpuS}
        this.peakTotalRssKb = 0; this.totalRssSum = 0; this.peakCores = 0; this.last = null; this.startedAt = Date.now();
    }
    mark(stage) { this.stage = stage; }
    start() { this.timer = setInterval(() => this.sample(), this.intervalMs); this.timer.unref?.(); return this; }
    async sample() {
        if (this.busy) return; this.busy = true;
        try {
            const table = LINUX ? procTable() : await psTable();
            const now = Date.now();
            const children = new Map();
            for (const r of table) { if (!children.has(r.ppid)) children.set(r.ppid, []); children.get(r.ppid).push(r); }
            const tree = [];
            const walk = (pid) => { for (const c of children.get(pid) ?? []) { tree.push(c); walk(c.pid); } };
            const self = table.find((r) => r.pid === this.rootPid);
            if (self) tree.push(self);
            walk(this.rootPid);
            let total = 0, cpuNow = 0; const byClass = {};
            for (const r of tree) {
                if (r.comm === 'ps' || r.comm.endsWith('/ps')) continue;
                const cls = classify(r.comm, r.pid, this.rootPid);
                total += r.rssKb;
                byClass[cls] = (byClass[cls] ?? 0) + r.rssKb;
                const prev = this.pids.get(r.pid);
                this.pids.set(r.pid, { cls, cpuS: Math.max(prev?.cpuS ?? 0, r.cpuS) });
            }
            for (const v of this.pids.values()) cpuNow += v.cpuS;
            for (const [cls, kb] of Object.entries(byClass)) {
                const c = this.classes[cls] ??= { peakRssKb: 0, rssSum: 0, rssSamples: 0 };
                c.peakRssKb = Math.max(c.peakRssKb, kb); c.rssSum += kb; c.rssSamples++;
            }
            this.samples++; this.totalRssSum += total; this.peakTotalRssKb = Math.max(this.peakTotalRssKb, total);
            const st = this.stages[this.stage] ??= { ms: 0, cpuS: 0, peakRssKb: 0, peakCores: 0 };
            st.peakRssKb = Math.max(st.peakRssKb, total);
            if (this.last) {
                const dt = (now - this.last.t) / 1000, dcpu = Math.max(0, cpuNow - this.last.cpu);
                st.ms += now - this.last.t; st.cpuS += dcpu;
                if (dt > 0.2) { const cores = dcpu / dt; st.peakCores = Math.max(st.peakCores, cores); this.peakCores = Math.max(this.peakCores, cores); }
            }
            this.last = { t: now, cpu: cpuNow };
        } finally { this.busy = false; }
    }
    async stop() { clearInterval(this.timer); await this.sample(); return this.summary(); }
    summary() {
        const mb = (kb) => Math.round(kb / 1024);
        const cpuByClass = {};
        for (const v of this.pids.values()) cpuByClass[v.cls] = (cpuByClass[v.cls] ?? 0) + v.cpuS;
        // the scanner's own node process existed before the probe: count only what it burned since
        return {
            platform: `${process.platform}/${process.arch}`, samples: this.samples, wallMs: Date.now() - this.startedAt,
            peakRssMb: mb(this.peakTotalRssKb), avgRssMb: this.samples ? mb(this.totalRssSum / this.samples) : 0, peakCores: +this.peakCores.toFixed(2),
            cpuSeconds: +Object.values(this.stages).reduce((a, s) => a + s.cpuS, 0).toFixed(2),
            byProcess: Object.fromEntries(Object.entries(this.classes).map(([k, c]) => [k, { peakRssMb: mb(c.peakRssKb), avgRssMb: mb(c.rssSum / Math.max(1, c.rssSamples)), cpuSecondsTotal: +(cpuByClass[k] ?? 0).toFixed(2) }])),
            byStage: Object.fromEntries(Object.entries(this.stages).map(([k, s]) => [k, { ms: s.ms, cpuSeconds: +s.cpuS.toFixed(2), peakRssMb: mb(s.peakRssKb), peakCores: +s.peakCores.toFixed(2) }])),
        };
    }
}
