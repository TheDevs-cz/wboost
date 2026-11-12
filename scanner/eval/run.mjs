#!/usr/bin/env node
// Evaluation runner: scans the existing sets through the real scanner (file-job driver → the local
// app's MCP design tools), one scan at a time, and scores each with the prototypes' scoring.
//
//   node eval/run.mjs --sets easy,photo,hard [--only A1-kandidat,B3-letak-a4] [--runs 1] [--label sdk] [--engine sdk|cli]
//                     [--project <id>] [--no-precheck] [--concurrency 1]
//
// Output: eval/runs/<label>/<id>/r<N>/ (run.json, proposal.json, report.json, usage.json, final.png,
// final.canvas.json, score.json, sheet.jpg, record.json). A finished run is skipped on the next call.
// Development rule: the engine runs on the Claude plan this machine is logged in to — an API key in
// the environment is REMOVED here. When the plan reports its limit as reached the runner writes
// eval/runs/_STOP and exits; it never switches credentials.
import { readFileSync, writeFileSync, existsSync, mkdirSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { scanFile, parseArgs } from '../bin/scanner.mjs';
import { ENGINES } from '../src/engine/index.mjs';
import { McpClient } from '../src/drivers/mcp-app.mjs';
import { LOCAL_STAND_IN } from '../src/drivers/file-job.mjs';
import { runCli } from './engine-cli.mjs';
import { scoreScan, REPO } from './lib.mjs';

delete process.env.ANTHROPIC_API_KEY; delete process.env.ANTHROPIC_AUTH_TOKEN;
ENGINES.cli = runCli;

const HERE = dirname(fileURLToPath(import.meta.url));
const args = parseArgs(process.argv.slice(2));
const sets = JSON.parse(readFileSync(join(HERE, 'sets.json'), 'utf8'));
const label = args.label ?? 'sdk';
const engineName = args.engine ?? 'sdk';
const runs = Number(args.runs ?? 1);
const project = args.project ?? process.env.PROJECT;
const mcpUrl = args['mcp-url'] ?? process.env.WBOOST_MCP_URL ?? 'http://localhost:8099/_mcp';
const token = (process.env.WBOOST_MCP_TOKEN ?? readFileSync(process.env.WBOOST_MCP_TOKEN_FILE, 'utf8')).trim();
const postgres = process.env.SCANNER_DEV_POSTGRES ?? 'wboost-postgres-1';
const concurrency = Number(args.concurrency ?? 1);
const only = typeof args.only === 'string' ? args.only.split(',') : null;
const RUNS = join(HERE, 'runs');
const STOP = join(RUNS, '_STOP');
mkdirSync(RUNS, { recursive: true });
if (existsSync(STOP) && !args.force) { console.error(`eval/runs/_STOP exists (${readFileSync(STOP, 'utf8').trim()}) — the plan limit was hit; remove the file to go on`); process.exit(3); }

const inputs = String(args.sets ?? 'easy').split(',').flatMap((s) => (sets[s] ?? []).map((it) => ({ ...it, group: s }))).filter((it) => !only || only.includes(it.id));
const jobs = [];
for (let r = 1; r <= runs; r++) for (const it of inputs) jobs.push({ ...it, r });

const canvasOf = (variantId) => execFileSync('docker', ['exec', postgres, 'psql', '-U', 'postgres', '-d', 'wboost', '-Atc', `select canvas from template_variant where id='${variantId}'`], { encoding: 'utf8', maxBuffer: 64 << 20 }).trim();

async function one(job, slot) {
    const outDir = join(RUNS, label, job.id, `r${job.r}`);
    if (existsSync(join(outDir, 'record.json'))) return JSON.parse(readFileSync(join(outDir, 'record.json'), 'utf8'));
    mkdirSync(outDir, { recursive: true });
    const image = resolve(REPO, job.image), truth = job.truth ? resolve(REPO, job.truth) : null;
    const t0 = Date.now();
    let s;
    try {
        s = await scanFile({ image, project, out: outDir, engineName, mcpUrl, token, precheck: args.precheck !== false, slot, container: process.env.SCANNER_DEV_CONTAINER,
            log: (r) => process.stderr.write(`    [${job.id} r${job.r}] ${r.tool} ${r.ms} ms${r.isError ? ' (error)' : ''}\n`) });
    } catch (e) {
        s = { outcome: 'harness_error', reason: String(e?.message ?? e).slice(0, 300), wallMs: Date.now() - t0 };
    }
    const facts = s.build?.stats?.engine?.facts ?? {};
    const record = {
        id: job.id, group: job.group, run: job.r, label, engine: engineName, outcome: s.outcome, reason: s.reason ?? null, detail: s.detail ?? null,
        wallS: +(s.wallMs / 1000).toFixed(1), intake: s.intake ? { outcome: s.intake.outcome, verdict: s.intake.preCheck?.verdict ?? null, confidence: s.intake.preCheck?.confidence ?? null, normaliseMs: s.intake.timings?.normaliseMs ?? null, precheckMs: s.intake.timings?.precheckMs ?? null, costUsd: (s.usage?.preCheck?.costMicroUsd ?? 0) / 1e6 } : null,
        turns: s.build?.stats?.engine?.turns ?? null, apiS: s.build?.stats?.engine?.apiMs ? +(s.build.stats.engine.apiMs / 1000).toFixed(1) : null,
        stagesMs: s.build?.stats?.stages ?? null, prepass: s.build?.stats?.prepass ?? null,
        candidates: s.build?.candidates ?? [], kept: s.build?.candidate ?? null, keepBest: s.build?.keepBest ?? null, unfinished: s.build?.unfinished ?? null, stopReason: s.build?.stopReason ?? null,
        caps: s.build?.stats?.caps ?? null, toolCalls: (s.toolCalls ?? []).reduce((m, c) => ({ ...m, [c.tool]: (m[c.tool] ?? 0) + 1 }), {}), toolErrors: (s.toolCalls ?? []).filter((c) => c.isError).length,
        usage: s.usage?.builder ?? null, costUsd: s.usage?.builder ? s.usage.builder.costMicroUsd / 1e6 : null, engineCostUsd: s.usage?.builder?.engineCostMicroUsd != null ? s.usage.builder.engineCostMicroUsd / 1e6 : null,
        engineFacts: { maxModelGapMs: facts.maxModelGapMs ?? null, retries: facts.retries?.length ?? 0, rateLimit: facts.rateLimit ?? null, resultSubtype: facts.resultSubtype ?? null, terminalReason: facts.terminalReason ?? null, exitCode: facts.exitCode ?? null, apiKeySource: facts.init?.apiKeySource ?? null, version: facts.init?.version ?? null, meterEstimate: facts.meterEstimate ?? null },
        resources: s.resources ?? null, appRenders: s.appRenders ?? null, score: null,
    };
    if (s.outcome === 'proposed') {
        try {
            // the saved canvas of the kept design (what the app would store) and its full-size export
            const design = JSON.parse(readFileSync(join(outDir, 'proposal.json'), 'utf8'));
            const report = JSON.parse(readFileSync(join(outDir, 'report.json'), 'utf8'));
            const mcp = new McpClient(mcpUrl, token);
            const standIns = JSON.parse(readFileSync(join(HERE, '..', '.scratch', 'standins.json'), 'utf8'));
            const real = JSON.parse(JSON.stringify(design).split(LOCAL_STAND_IN).join(standIns[`${mcpUrl}|${project}`]));
            const saved = await mcp.call('set_design', { variantId: s.variant, design: real, acknowledgeLosses: true });
            if (!saved.json?.saved) throw new Error(`set_design refused: ${JSON.stringify(saved.json?.issues ?? saved.json).slice(0, 300)}`);
            const ex = await mcp.call('export_variant', { variantId: s.variant });
            writeFileSync(join(outDir, 'final.png'), ex.image.data);
            writeFileSync(join(outDir, 'final.canvas.json'), canvasOf(s.variant));
            record.score = await scoreScan({ image, truth, fonts: resolve(REPO, sets.fonts), finalPng: join(outDir, 'final.png'), finalCanvas: join(outDir, 'final.canvas.json'), design, outDir, report });
            record.report = { placeholders: report.placeholders.length, fonts: report.fonts.map((f) => ({ group: f.group, face: f.face, score: f.score, matched: f.matched, sizeRatio: f.sizeRatio, bestFace: f.bestFace, bestScore: f.bestScore, by: f.by, ranked: f.ranked })), approximations: report.approximations.length, omitted: report.omitted.length, lowConfidenceTexts: report.lowConfidenceTexts.length, shrunk: report.shrunk.length };
        } catch (e) { record.scoreError = String(e?.message ?? e).slice(0, 300); }
    }
    writeFileSync(join(outDir, 'record.json'), JSON.stringify(record, null, 2));
    const planLimited = facts.rateLimit?.status === 'rejected' || s.build?.stats?.engine?.error?.code === 'plan_limit' || s.intake?.engine?.error?.code === 'plan_limit';
    if (planLimited) writeFileSync(STOP, `${new Date().toISOString()} plan limit reported during ${job.id} r${job.r}\n`);
    console.error(`[${label}] ${job.id} r${job.r}: ${record.outcome}${record.reason ? ' ' + record.reason : ''} kept=${record.kept} cands=${record.candidates.length} turns=${record.turns} wall=${record.wallS}s cost=$${record.costUsd?.toFixed(3)} masked=${record.score?.imageMasked?.composite ?? '-'} textIoU=${record.score?.canvas?.text?.meanBoxIoU ?? '-'}${record.scoreError ? ' SCORE ERROR ' + record.scoreError : ''}`);
    return record;
}

// workers: each has its own slot (its own scratch variant), so two scans never share a variant
let next = 0, failures = 0;
await Promise.all(Array.from({ length: concurrency }, async (_, slot) => {
    while (next < jobs.length) {
        if (existsSync(STOP)) return;
        const job = jobs[next++];
        const r = await one(job, slot);
        // never loop on failures: three harness / provider failures in a row end the batch
        failures = ['proposed', 'stopped'].includes(r.outcome) ? 0 : failures + 1;
        if (failures >= 3) { console.error('three failures in a row — stopping the batch'); next = jobs.length; }
    }
}));
