// Configuration: the environment, nothing else. No file is read, no secret is logged.
//
//   SCANNER_APP_URL          base URL of the app as the scanner reaches it, e.g. http://web  (required)
//   SCANNER_TOKEN            the bearer token of /internal/scanner/* (required, ≥ 24 characters)
//   ANTHROPIC_API_KEY        the provider credential in production. Unset = the Claude plan this machine is
//                            logged in to (development only); CLAUDE_CODE_OAUTH_TOKEN is the plan's token for
//                            a machine without a login (a container in development)
//   SCANNER_CONCURRENCY      builds at once, default 1 (the app's `max_running` decides below that)
//   SCANNER_ENGINE           sdk (default) | messages
//   SCANNER_SCRATCH_DIR      per-run scratch root, default <tmp>/wboost-scanner — swept at start
//   SCANNER_HEALTH_PORT      port of GET /health, default 8787; 0 = no listener
//   SCANNER_HEALTH_HOST      default 127.0.0.1 (the container healthcheck runs inside the container)
//   SCANNER_HEALTH_FILE      optional: the health document is also written to this file every 5 s
//   SCANNER_VERSION          reported to the app; default = package version (+ SCANNER_BUILD, the commit)
//   SCANNER_OCR_PYTHON       the python that has rapidocr (the image sets it)
//   SCANNER_PROBE_MODEL      model of the 1-token provider probe before any definition was seen
//   SCANNER_MEMORY_LIMIT_MB  overrides the cgroup memory limit read at start (tests)
//   SCANNER_LOG              json (default) | text | silent
import { readFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { memoryLimitBytes } from './proc.mjs';

const HERE = dirname(fileURLToPath(import.meta.url));
const GIB = 1024 ** 3;

export class ConfigError extends Error { constructor(problems) { super(`scanner configuration: ${problems.join('; ')}`); this.name = 'ConfigError'; this.problems = problems; } }

export function packageVersion() {
    try { return JSON.parse(readFileSync(join(HERE, '..', 'package.json'), 'utf8')).version ?? '0.0.0'; } catch { return '0.0.0'; }
}

/**
 * Memory one configuration needs (architecture §8.2, measured in eval/RESULTS.md §5; rule: peak ≤ 70 % of
 * the limit): 3 GiB at concurrency 1, 5 at 2, 7 at 3 — 1 GiB for the intake lane and the main process plus
 * 2 GiB per build.
 */
export const memoryNeededBytes = (concurrency) => (1 + 2 * concurrency) * GIB;
/** The largest concurrency a memory limit holds; Infinity without a limit. */
export const capacityOf = (limitBytes) => (limitBytes === null ? Infinity : Math.max(0, Math.floor((limitBytes / GIB - 1) / 2 + 1e-9)));

export function loadConfig(env = process.env, { readMemoryLimit = memoryLimitBytes } = {}) {
    const problems = [];
    const int = (name, fallback, min, max) => {
        const raw = env[name];
        if (raw === undefined || raw === '') return fallback;
        const n = Number(raw);
        if (!Number.isInteger(n) || n < min || n > max) { problems.push(`${name} must be a whole number ${min}–${max}`); return fallback; }
        return n;
    };
    let appUrl = (env.SCANNER_APP_URL ?? '').trim().replace(/\/+$/, '');
    if (!appUrl) problems.push('SCANNER_APP_URL is not set');
    else { try { const u = new URL(appUrl); if (!/^https?:$/.test(u.protocol)) throw new Error('scheme'); } catch { problems.push('SCANNER_APP_URL is not an http(s) URL'); } }
    const token = (env.SCANNER_TOKEN ?? '').trim();
    if (!token) problems.push('SCANNER_TOKEN is not set'); else if (token.length < 24) problems.push('SCANNER_TOKEN is shorter than 24 characters (the app counts that as not configured)');
    const concurrency = int('SCANNER_CONCURRENCY', 1, 1, 8);
    const engine = env.SCANNER_ENGINE || 'sdk';
    if (!['sdk', 'messages'].includes(engine)) problems.push('SCANNER_ENGINE must be sdk or messages');
    if (engine === 'messages' && !env.ANTHROPIC_API_KEY) problems.push('SCANNER_ENGINE=messages needs ANTHROPIC_API_KEY');
    const build = (env.SCANNER_BUILD ?? '').trim().slice(0, 12);
    const version = (env.SCANNER_VERSION ?? `${packageVersion()}${build ? `+${build}` : ''}`).trim();
    if (!/^[A-Za-z0-9._+-]{1,32}$/.test(version)) problems.push('SCANNER_VERSION must be 1–32 characters of A-Z a-z 0-9 . _ + -');
    const limitMb = int('SCANNER_MEMORY_LIMIT_MB', null, 1, 10_000_000);
    const memoryLimit = limitMb !== null ? limitMb * 1024 * 1024 : readMemoryLimit();
    // the scanner refuses a concurrency its memory limit cannot hold (architecture §7, process rules)
    if (capacityOf(memoryLimit) < concurrency) problems.push(`SCANNER_CONCURRENCY=${concurrency} needs a memory limit of ${memoryNeededBytes(concurrency) / GIB} GiB, this container has ${(memoryLimit / GIB).toFixed(2)} GiB`);
    const log = env.SCANNER_LOG || 'json';
    if (!['json', 'text', 'silent'].includes(log)) problems.push('SCANNER_LOG must be json, text or silent');
    if (problems.length) throw new ConfigError(problems);
    return Object.freeze({
        appUrl, token, concurrency, engine, version, log,
        scratchDir: resolve(env.SCANNER_SCRATCH_DIR || join(tmpdir(), 'wboost-scanner')),
        healthPort: int('SCANNER_HEALTH_PORT', 8787, 0, 65535), healthHost: env.SCANNER_HEALTH_HOST || '127.0.0.1', healthFile: env.SCANNER_HEALTH_FILE || null,
        probeModel: env.SCANNER_PROBE_MODEL || 'claude-haiku-5-5',
        memoryLimitBytes: memoryLimit,
        // thread pools are pinned to floor(3 ÷ concurrency): the service as a whole is capped at 3 CPUs
        threadsPerRun: Math.max(1, Math.floor(3 / concurrency)),
        credential: env.ANTHROPIC_API_KEY ? 'api-key' : env.CLAUDE_CODE_OAUTH_TOKEN ? 'plan-token' : 'plan-login',
    });
}

/** What may be printed of a configuration. */
export function describeConfig(c) {
    return { appUrl: c.appUrl, token: `set (${c.token.length} characters)`, concurrency: c.concurrency, engine: c.engine, version: c.version, scratchDir: c.scratchDir, health: c.healthPort ? `${c.healthHost}:${c.healthPort}` : 'off', credential: c.credential, memoryLimitMb: c.memoryLimitBytes === null ? null : Math.round(c.memoryLimitBytes / 1024 / 1024), threadsPerRun: c.threadsPerRun };
}
