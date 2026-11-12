#!/usr/bin/env node
// Resource measurement without a credential: replays the tool calls of a RECORDED scan through the
// real engine binary (Agent SDK), the real instruments (normalise, OCR, font ranking, compare) and
// the real renders of the local app — the model's answers come from eval/fake-provider.mjs.
// What it measures is honest for memory and CPU of the scanner's processes; it says nothing about
// quality, cost or the time a provider takes.
//
//   node eval/replay.mjs --recording <run dir of a finished scan> --image <picture> --project <id> --out <dir>
//                        [--mcp-url …] [--forward 8097=wboost-scan-web:80] [--slot 0] [--think-ms 0]
//
// --forward opens a loopback TCP forward inside a container, so that the app sees the Host header it
// allows (localhost). --think-ms delays every fake model answer (a provider that takes time).
import { readFileSync, writeFileSync, existsSync } from 'node:fs';
import { createServer, connect } from 'node:net';
import { join } from 'node:path';
import os from 'node:os';
import { startFakeProvider, planFromRecording } from './fake-provider.mjs';
import { parseArgs } from '../bin/scanner.mjs';

const args = parseArgs(process.argv.slice(2));
if (args.forward) {
    const [port, target] = String(args.forward).split('=');
    const [host, tport] = target.split(':');
    // a second replay in the same container finds the forward already there
    await new Promise((ok) => createServer((c) => { const up = connect(Number(tport), host); c.pipe(up); up.pipe(c); c.on('error', () => up.destroy()); up.on('error', () => c.destroy()); }).on('error', ok).listen(Number(port), '127.0.0.1', ok));
}
const rec = String(args.recording);
const tools = readFileSync(join(rec, 'tools.jsonl'), 'utf8').trim().split('\n').map((l) => JSON.parse(l));
const run = JSON.parse(readFileSync(join(rec, 'run.json'), 'utf8'));
const fake = await startFakeProvider({ plan: planFromRecording(tools, run.build?.modelFinish), latencyMs: Number(args['think-ms'] ?? 0) });
process.env.ANTHROPIC_BASE_URL = fake.url;
process.env.ANTHROPIC_API_KEY = 'sk-ant-fake-key-for-the-local-fake-provider';
const token = (process.env.WBOOST_MCP_TOKEN ?? readFileSync(process.env.WBOOST_MCP_TOKEN_FILE, 'utf8')).trim();
const { scanFile } = await import('../bin/scanner.mjs');
const cgroup = (f) => { try { return Number(readFileSync(`/sys/fs/cgroup/${f}`, 'utf8').trim()); } catch { return null; } };
const t0 = Date.now();
const s = await scanFile({ image: String(args.image), project: String(args.project), out: String(args.out), mcpUrl: args['mcp-url'], token, precheck: false, slot: Number(args.slot ?? 0) });
const summary = {
    recording: rec, platform: `${process.platform}/${process.arch}`, cpus: os.cpus().length, cpuModel: os.cpus()[0]?.model ?? null, outcome: s.outcome, reason: s.reason, wallS: +((Date.now() - t0) / 1000).toFixed(1),
    replayedToolCalls: tools.length, toolCalls: s.toolCalls?.length ?? 0, candidates: s.build?.candidates?.length ?? 0, mainRequests: fake.state.main,
    stagesMs: s.build?.stats?.stages ?? null, prepass: s.build?.stats?.prepass ?? null, resources: s.resources, appRenders: s.appRenders,
    cgroup: { memoryPeakMb: cgroup('memory.peak') !== null ? Math.round(cgroup('memory.peak') / 1048576) : null, memoryCurrentMb: cgroup('memory.current') !== null ? Math.round(cgroup('memory.current') / 1048576) : null, memoryMaxMb: Number.isFinite(cgroup('memory.max')) ? Math.round(cgroup('memory.max') / 1048576) : null },
};
writeFileSync(join(String(args.out), 'replay.json'), JSON.stringify(summary, null, 2));
console.log(JSON.stringify(summary));
await fake.close();
process.exit(0);
