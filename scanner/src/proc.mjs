// Process trees: who descends from a pid, and how to make sure all of it is gone.
// A run's child process is the leader of its own process group; the engine binary (started by the Agent
// SDK) and the OCR sidecar are in it. An abort kills the GROUP and then every descendant that was seen
// (a process that moved to another group or session is still found by its parent chain), and verifies.
import { execFileSync } from 'node:child_process';
import { readFileSync, readdirSync } from 'node:fs';

/** → [{pid, ppid, pgid, comm}] of every process the caller may see. */
export function processTable() {
    if (process.platform === 'linux') {
        const rows = [];
        for (const d of readdirSync('/proc')) {
            if (!/^\d+$/.test(d)) continue;
            try {
                const stat = readFileSync(`/proc/${d}/stat`, 'utf8');
                const close = stat.lastIndexOf(')');
                const f = stat.slice(close + 2).split(' '); // state ppid pgrp …
                if (f[0] === 'Z') continue; // a zombie holds no memory and runs nothing: its parent (or init) reaps it
                rows.push({ pid: +d, ppid: +f[1], pgid: +f[2], comm: stat.slice(stat.indexOf('(') + 1, close) });
            } catch { /* ended while we looked */ }
        }
        return rows;
    }
    try {
        return execFileSync('ps', ['-axo', 'pid=,ppid=,pgid=,stat=,comm='], { encoding: 'utf8', maxBuffer: 16 << 20 }).split('\n')
            .map((l) => l.trim().match(/^(\d+)\s+(\d+)\s+(\d+)\s+(\S+)\s+(.*)$/)).filter((m) => m && !m[4].startsWith('Z'))
            .map((m) => ({ pid: +m[1], ppid: +m[2], pgid: +m[3], comm: m[5] }));
    } catch { return []; }
}

/** Every descendant of `root` (not `root` itself), nearest first. */
export function descendants(root, table = processTable()) {
    const kids = new Map();
    for (const r of table) { if (!kids.has(r.ppid)) kids.set(r.ppid, []); kids.get(r.ppid).push(r); }
    const out = [];
    const walk = (pid) => { for (const c of kids.get(pid) ?? []) { out.push(c); walk(c.pid); } };
    walk(root);
    return out;
}

export function alive(pid) {
    try { process.kill(pid, 0); } catch (e) { return e.code === 'EPERM'; }
    // a zombie still answers signal 0: ask the table
    return processTable().some((r) => r.pid === pid);
}

const signal = (target, sig) => { try { process.kill(target, sig); return true; } catch { return false; } };

/**
 * Kills the process group led by `pid`, `pid` itself and every descendant seen now or in `known`
 * (pids recorded earlier), then waits until none is left.
 * → {killed: [pid], survivors: [pid], ms}
 */
export async function killTree(pid, { known = [], waitMs = 3000, sig = 'SIGKILL' } = {}) {
    const started = Date.now();
    const seen = new Set([pid, ...known]);
    for (const d of descendants(pid)) seen.add(d.pid);
    signal(-pid, sig);
    for (const p of seen) signal(p, sig);
    // children that were being spawned while we looked
    for (const d of descendants(pid)) if (!seen.has(d.pid)) { seen.add(d.pid); signal(d.pid, sig); }
    let survivors = [...seen];
    while (true) {
        const table = processTable();
        const live = new Set(table.map((r) => r.pid));
        survivors = [...seen].filter((p) => live.has(p));
        if (!survivors.length || Date.now() - started >= waitMs) break;
        for (const p of survivors) signal(p, 'SIGKILL');
        await new Promise((ok) => setTimeout(ok, 50));
    }
    return { killed: [...seen], survivors, ms: Date.now() - started };
}

/** The memory limit of this container in bytes (cgroup v2, then v1), or null when there is none. */
export function memoryLimitBytes(read = (p) => readFileSync(p, 'utf8')) {
    for (const p of ['/sys/fs/cgroup/memory.max', '/sys/fs/cgroup/memory/memory.limit_in_bytes']) {
        try {
            const v = read(p).trim();
            if (v === 'max') return null;
            const n = Number(v);
            if (Number.isFinite(n) && n > 0 && n < 2 ** 60) return n;
        } catch { /* not this cgroup version */ }
    }
    return null;
}

/** cgroup v2 `memory.peak` / `memory.current` in bytes, or null. */
export function cgroupMemory(read = (p) => readFileSync(p, 'utf8')) {
    const num = (p) => { try { const n = Number(read(p).trim()); return Number.isFinite(n) ? n : null; } catch { return null; } };
    return { peak: num('/sys/fs/cgroup/memory.peak'), current: num('/sys/fs/cgroup/memory.current') };
}
