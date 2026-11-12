#!/usr/bin/env node
// scanner — command line of the WBoost AI scan scanner.
//
//   scanner [serve]          the service: claims scans from the app (SCANNER_APP_URL, SCANNER_TOKEN) until SIGTERM
//   scanner healthcheck      exit 0 while the running service is healthy (the container healthcheck)
//   scanner selftest         sharp, the OCR sidecar and its models, the engine binary, a HEIC decoder — no network needed
//   scanner version
//   scanner scan-file <image> --project <projectId> [--kind template] [--variant <variantId>] [--out <dir>]
//                     [--engine sdk|messages] [--mcp-url http://localhost:8099/_mcp] [--approximate] [--no-precheck]
//
// `serve` reads its configuration from the environment only (src/config.mjs).
// `scan-file` runs ONE scan of a picture on disk against a local app through its MCP design tools and writes
// proposal.json (the design document), render.webp (the kept candidate), report.json, usage.json and
// run.json into --out. Credentials come from the environment only: with ANTHROPIC_API_KEY unset the
// engine uses the Claude plan this machine is logged in to; the MCP token comes from WBOOST_MCP_TOKEN
// or WBOOST_MCP_TOKEN_FILE.
import { readFileSync, writeFileSync, appendFileSync, mkdirSync, mkdtempSync, rmSync, realpathSync } from 'node:fs';
import { pathToFileURL } from 'node:url';
import { basename, extname, join, resolve } from 'node:path';
import { tmpdir } from 'node:os';
import { FileJobApi } from '../src/drivers/file-job.mjs';
import { engine as pickEngine } from '../src/engine/index.mjs';
import { scanOnce } from '../src/run.mjs';
import { ResourceProbe } from '../src/resources.mjs';

export function parseArgs(argv) {
    const out = { _: [] };
    for (let i = 0; i < argv.length; i++) {
        const a = argv[i];
        if (!a.startsWith('--')) { out._.push(a); continue; }
        const key = a.slice(2);
        if (key.startsWith('no-')) { out[key.slice(3)] = false; continue; }
        const next = argv[i + 1];
        if (next === undefined || next.startsWith('--')) out[key] = true; else { out[key] = next; i++; }
    }
    return out;
}

/** One scan of a file. → the summary that is also written to <out>/run.json. */
export async function scanFile({ image, project, kind = 'template', variant = null, out = null, engineName = undefined, mcpUrl = undefined, token, approximate = false, precheck = true, slot = 0, container = undefined, log = null, signal = null }) {
    const outDir = resolve(out ?? join('runs', `${basename(image, extname(image))}-${new Date().toISOString().replace(/[:.]/g, '-')}`));
    mkdirSync(outDir, { recursive: true });
    const scratchDir = mkdtempSync(join(tmpdir(), 'wboost-scan-'));
    const scanner = new FileJobApi({ image, kind, projectId: project, variantId: variant, outDir, token, mcpUrl, approximate, slot, container });
    const engine = pickEngine(engineName);
    const probe = new ResourceProbe().start();
    const started = Date.now();
    const calls = [];
    // every tool call with its arguments: a private run artefact (replay, debugging) — never logged elsewhere
    const record = (r) => { appendFileSync(join(outDir, 'tools.jsonl'), JSON.stringify(r) + '\n'); const { args, ...brief } = r; calls.push(brief); log?.(brief); };
    let result;
    try {
        if (!precheck) {
            // development shortcut: normalise only, no model call for the pre-check
            const { normaliseInChild } = await import('../src/intake/normalise.mjs');
            const dir = join(scratchDir, 'intake'); mkdirSync(dir, { recursive: true });
            probe.mark('intake:normalise');
            const n = await normaliseInChild(resolve(image), dir, {});
            if (!n.ok) result = { intake: { outcome: 'stopped', reason: n.stop, preCheck: { detail: n.detail } }, build: null };
            else {
                writeFileSync(join(outDir, 'working.webp'), readFileSync(n.files.working));
                writeFileSync(join(outDir, 'preview.webp'), readFileSync(n.files.preview));
                scanner.state = 'queued';
                result = await scanOnce({ scanner, engine, scratchDir, signal, probeResources: probe, log: record, skipIntake: true });
                result.intake = { outcome: 'passed', preCheck: null, sourceMeta: n.meta, skipped: true };
            }
        } else {
            result = await scanOnce({ scanner, engine, scratchDir, signal, probeResources: probe, log: record });
        }
    } finally {
        if (process.env.SCANNER_KEEP_SCRATCH !== '1') rmSync(scratchDir, { recursive: true, force: true });
    }
    const resources = await probe.stop();
    const b = result.build, i = result.intake;
    if (b?.outcome === 'proposed') {
        writeFileSync(join(outDir, 'proposal.json'), JSON.stringify(b.proposal, null, 2));
        writeFileSync(join(outDir, `render.${b.render.mimeType === 'image/png' ? 'png' : 'webp'}`), b.render.data);
        writeFileSync(join(outDir, 'report.json'), JSON.stringify({ ...b.report, boxes: b.boxes }, null, 2));
    }
    const usage = { preCheck: i?.usage ?? null, builder: b?.usage ?? null, totalCostMicroUsd: (i?.usage?.costMicroUsd ?? 0) + (b?.usage?.costMicroUsd ?? 0) };
    writeFileSync(join(outDir, 'usage.json'), JSON.stringify(usage, null, 2));
    const summary = {
        image: resolve(image), kind, project, variant: scanner.app.variantId, engine: engine.name, credential: process.env.ANTHROPIC_API_KEY ? 'api-key' : 'plan', outDir,
        outcome: b?.outcome ?? i?.outcome ?? 'none', reason: b?.error ?? b?.reason ?? i?.reason ?? null, detail: b?.detail ?? null,
        wallMs: Date.now() - started,
        intake: i ? { outcome: i.outcome, reason: i.reason ?? null, preCheck: i.preCheck ?? null, sourceMeta: i.sourceMeta ?? null, timings: i.timings ?? null, engine: i.preCheckEngine ? { outcome: i.preCheckEngine.outcome, wallMs: i.preCheckEngine.wallMs, apiMs: i.preCheckEngine.apiMs, error: i.preCheckEngine.error } : null } : null,
        build: b ? { outcome: b.outcome, candidate: b.candidate ?? null, candidates: b.candidates ?? [], unfinished: b.report?.unfinished ?? null, stopReason: b.report?.stopReason ?? null, keepBest: b.report?.measures?.keepBest ?? null, modelFinish: b.modelFinish ?? null, rankings: b.rankings ?? [], stats: b.stats } : null,
        usage, resources, appRenders: { count: scanner.app.renders, ms: scanner.app.renderMs }, toolCalls: calls,
    };
    writeFileSync(join(outDir, 'run.json'), JSON.stringify(summary, null, 2));
    return summary;
}

async function main() {
    const [command = 'serve', ...rest] = process.argv.slice(2);
    if (command === 'serve') {
        const { serve } = await import('../src/main.mjs');
        try { await serve(); } catch (e) {
            console.error(e?.name === 'ConfigError' ? e.message : (e?.stack ?? String(e)));
            process.exit(e?.name === 'ConfigError' ? 78 : 1);
        }
        return; // runs until a signal
    }
    if (command === 'healthcheck') { const { healthcheck } = await import('../src/health.mjs'); process.exit(await healthcheck()); }
    if (command === 'selftest') { const { selftest } = await import('../src/selftest.mjs'); const r = await selftest(); console.log(JSON.stringify(r, null, 2)); process.exit(r.ok ? 0 : 1); }
    if (command === 'version') { const { packageVersion } = await import('../src/config.mjs'); console.log(`${packageVersion()}${process.env.SCANNER_BUILD ? `+${process.env.SCANNER_BUILD.slice(0, 12)}` : ''}`); process.exit(0); }
    const args = parseArgs(rest);
    if (command !== 'scan-file' || !args._[0] || !args.project) {
        console.error('usage: scanner [serve] | healthcheck | selftest | version | scan-file <image> --project <projectId> [--kind template] [--variant <id>] [--out <dir>] [--engine sdk|messages] [--mcp-url <url>] [--approximate] [--no-precheck]');
        process.exit(2);
    }
    const token = (process.env.WBOOST_MCP_TOKEN ?? (process.env.WBOOST_MCP_TOKEN_FILE ? readFileSync(process.env.WBOOST_MCP_TOKEN_FILE, 'utf8') : '')).trim();
    if (!token) { console.error('set WBOOST_MCP_TOKEN or WBOOST_MCP_TOKEN_FILE (a personal access token of the local app)'); process.exit(2); }
    const controller = new AbortController();
    for (const sig of ['SIGINT', 'SIGTERM']) process.once(sig, () => controller.abort());
    const s = await scanFile({ image: args._[0], project: args.project, kind: args.kind ?? 'template', variant: args.variant ?? null, out: args.out ?? null, engineName: args.engine, mcpUrl: args['mcp-url'], token, approximate: !!args.approximate, precheck: args.precheck !== false, signal: controller.signal,
        log: (r) => console.error(`  ${r.tool} ${r.ms} ms${r.isError ? ' (error)' : ''}`) });
    const u = s.usage;
    console.log(JSON.stringify({ outcome: s.outcome, reason: s.reason, candidate: s.build?.candidate ?? null, unfinished: s.build?.unfinished ?? null, wallS: Math.round(s.wallMs / 1000), turns: s.build?.stats?.engine?.turns ?? null, costUsd: +(u.totalCostMicroUsd / 1e6).toFixed(4), out: s.outDir }, null, 2));
    process.exit(s.outcome === 'proposed' ? 0 : 1);
}

if (import.meta.url === pathToFileURL(realpathSync(process.argv[1])).href) await main();
