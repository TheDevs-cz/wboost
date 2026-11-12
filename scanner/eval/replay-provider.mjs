#!/usr/bin/env node
// A provider stand-in for a machine that has no credential (the linux/amd64 container in development):
// answers the pre-check with "design" and then replays the tool calls of a RECORDED scan of the same
// picture, so that the real service — protocol, lanes, instruments, engine binary, the app's renders —
// runs a whole scan. It says nothing about quality, cost or the time a provider takes.
//
//   node eval/replay-provider.mjs --recording eval/runs/sdk/<input>/r1 [--port 8790] [--think-ms 1500] [--stand-in <asset id>]
// Point the scanner at it: ANTHROPIC_BASE_URL=http://<host>:8790  ANTHROPIC_API_KEY=<any>
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { startFakeProvider, planFromRecording } from './fake-provider.mjs';
import { LOCAL_STAND_IN } from '../src/drivers/file-job.mjs';

const arg = (name, fallback) => { const i = process.argv.indexOf(`--${name}`); return i > 0 ? process.argv[i + 1] : fallback; };
const rec = arg('recording');
if (!rec) { console.error('usage: replay-provider.mjs --recording <run dir> [--port 8790] [--think-ms 0] [--stand-in <asset id>]'); process.exit(2); }
// the recording was made through the local MCP driver, whose stand-in picture has another id than the app's
const standIn = arg('stand-in', '00000000-0000-4000-8000-0000000a15ca');
const tools = readFileSync(join(rec, 'tools.jsonl'), 'utf8').split(LOCAL_STAND_IN).join(standIn).trim().split('\n').map((l) => JSON.parse(l));
const run = JSON.parse(readFileSync(join(rec, 'run.json'), 'utf8'));
const verdict = { final: { verdict: 'design', confidence: 'high' } };
// the structured answer of the pre-check takes two requests (the tool call, then the closing text)
const plan = [verdict, verdict, ...planFromRecording(tools, run.build?.modelFinish)];
const fake = await startFakeProvider({ plan, port: Number(arg('port', 8790)), host: '0.0.0.0', latencyMs: Number(arg('think-ms', 0)) });
console.log(JSON.stringify({ url: fake.url, port: fake.port, steps: plan.length, toolCalls: tools.length }));
process.on('SIGTERM', async () => { console.log(JSON.stringify({ requests: fake.state.requests.length, main: fake.state.main, models: [...new Set(fake.state.requests.map((r) => r.model))] })); await fake.close(); process.exit(0); });
