# WBoost AI scan — the scanner

The service that turns an uploaded picture into a **proposal**: a design document the app
validated and rendered itself, plus a report whose facts are measured. Design and contracts:
`docs/ai-scan/architecture.md` (§4 architecture, §4.9 timing and limits, §7 failures, §8 resources).
Measurements of this code: [`eval/RESULTS.md`](eval/RESULTS.md).

This directory is **step A** of phase P2: engine, instruments, definition, caps and finish logic,
runnable end to end against a local app through its MCP design tools. **Step B** wires the same
code to the app's job protocol (`/internal/scanner/*`) and adds the lanes, timers, health check,
shutdown and the production image — see "What step B adds".

## Run one scan

```bash
cd scanner
npm ci                                             # node ≥ 22; pulls the Agent SDK with its engine binary
python3.12 -m venv .venv && .venv/bin/pip install -r requirements.txt   # the OCR sidecar
export WBOOST_MCP_TOKEN_FILE=<file with a wb_mcp_… token of the local app>   # docs/ai-scan/prototypes/README.md §2
export SCANNER_DEV_CONTAINER=wboost-web-1          # lets the dev helper create an empty variant of the picture's size

node bin/scanner.mjs scan-file <picture> --project <projectId> [--kind template] [--out <dir>] \
     [--variant <variantId>] [--mcp-url http://localhost:8099/_mcp] [--engine sdk|messages] [--approximate] [--no-precheck]
```

It writes into `--out` (default `runs/<name>-<time>/`): `proposal.json` (the kept design document),
`render.webp` (the app's render of it), `report.json` (the finish report + per-element boxes),
`usage.json` (tokens and computed cost per step), `run.json` (stages, caps, tool calls, resources),
`candidates/NN.*`, `working.webp`, `preview.webp`, `finish.intake.json`, `finish.build.json`,
`beats.jsonl`. Exit code 0 = a proposal was produced.

**Credentials come from the environment only.** With `ANTHROPIC_API_KEY` unset the engine uses the
Claude plan this machine is logged in to (the rule for all development and testing); production
sets the key. Nothing reads `.env.local`. The evaluation runner removes a key from its environment.

## What happens in a scan

| Step | Code | Notes |
|---|---|---|
| Intake: normalise | `src/intake/normalise.mjs` (+ `normalise-child.mjs`) | a child process with a pixel limit decodes the upload: HEIC (system converter), CMYK, 16-bit, EXIF rotation, first frame, alpha on white → working copy (sRGB, 8 bit, long edge ≤ 4096 px) + preview. Limits: 10 MB, 8000 px a side, 36 Mpx, short side ≥ 600 px; child cut at 20 s |
| Intake: pre-check | `src/intake/precheck.mjs` | one Haiku call on the preview, own request settings (18 s, 1 retry, ≤ 38 s): design / calendar page / photo of a print / several designs / blurry or small / not a design → pass or a stop reason |
| Pre-pass (mandatory, no model) | `src/adapters/template.mjs` `prepass()` | OCR lines (RapidOCR sidecar, pinned threads) → measured ink box, glyph colour and backdrop per line; background fit (solid / linear / radial / complex); palette; font ranking of the project's faces for one line per text style (the app renders, the scanner compares glyph shapes and fits the size) |
| Model loop | `src/engine/` | Opus 5.5 at effort `high` gets the picture + the pre-pass as its first message and 7 tools: `scan_source`, `scan_crop`, `scan_measure`, `font_rank`, `compare_region`, `design_preview`, `design_patch` |
| Caps | `src/caps.mjs`, `src/tools/server.mjs`, `src/run.mjs` | ≤ 40 turns, $1.50, builder 300 s, run 360 s, ≤ 3 rendered candidates, ≤ 3 validation rejections, ≤ 18 pictures (each ≤ 2576 px, ≤ 3 MB), tool timeouts 30 / 95 s |
| Finish | `src/finish/` | keep-best by measurement on the pixels no candidate hides behind a place for a picture; report fields written from measurements (places for pictures, font substitutions with scores, left-out areas with measured difference, low-confidence texts, shrunk texts, palette snaps); when the model never finished, the scanner reports alone (`unfinished`) |

The scanner holds **no prompt and no schema**: system prompts, tool list, finish schema, caps,
models, effort and prices are the app-owned definition in `resources/ai-scan/definition/`
(sent in a claim answer; the file driver reads the directory).

## Layout

```
bin/scanner.mjs            CLI (scan-file)
src/run.mjs                intake lane + build lane of one job, heartbeat, renderer retry, release
src/protocol.mjs           JobApi / ScannerApi interface, definition + claim-context readers, HttpScannerApi (step B, not exercised)
src/drivers/file-job.mjs   a job from a file; artefacts to a directory
src/drivers/mcp-app.mjs    the local app over MCP: preview_design as "render", face sheets as "faces"
src/engine/                sdk.mjs (Agent SDK, locked down) · messages.mjs (plain Messages loop) · classify.mjs · pricing.mjs
src/intake/                normalise (+ child), precheck
src/instruments/           raster, textink, background, fonts, ocr (+ ocr/ocr.py)
src/adapters/              template.mjs (pre-pass, tools, feedback), mask.mjs
src/tools/                 server.mjs (dispatch, timeouts, image budget), images.mjs (image rule)
src/finish/                keepbest, report, text
src/caps.mjs, src/resources.mjs
dev/new-variant.php        development helper (runs inside the app's dev container)
test/                      node test runner — `npm test`
eval/                      the evaluation: sets.json, run.mjs, lib.mjs, score.mjs, engine-cli.mjs, report.mjs, RESULTS.md
```

## Engines

`src/engine/index.mjs` exposes one contract — `run(options) → {outcome, finish, usage, turns, error, facts}`
with `outcome` ∈ `finished | aborted | max_turns | max_budget | refusal | provider | error` — and two backends:

- **`sdk`** (default): `@anthropic-ai/claude-agent-sdk`, locked down — `tools: []`, only the
  in-process `scan` MCP server (`strictMcpConfig`), `allowedTools` + `permissionMode: 'dontAsk'`,
  `settingSources: []`, own `systemPrompt`, `persistSession: false`, empty `cwd`, pinned request
  environment. The config directory is isolated only when a key is the credential (an isolated
  directory hides the plan login).
- **`messages`**: `@anthropic-ai/sdk` loop over the same tool list and dispatcher; retries and delays
  exactly as the definition pins them. Needs `ANTHROPIC_API_KEY` — not run in development.

`eval/engine-cli.mjs` drives the same scanner with the `claude -p` binary; it exists only to
measure parity with the prototypes' engine.

## Tests and evaluation

```bash
npm test                                                  # 120+ tests, no model, no app; OCR tests need .venv
node eval/run.mjs --sets easy,photo,hard --runs 2         # real scans, one at a time, on the plan; needs the local app
node eval/report.mjs                                      # tables from eval/runs/**/record.json
```

`eval/sets.json` names the inputs (the prototypes' easy 7 + 3 and hard 12). The pictures are client
material and prototype artefacts — git-ignored; so are `eval/runs/`, `runs/`, `.venv/`, `.scratch/`.

## What step B adds

`main.mjs` (lanes: an intake lane that never waits behind builds, N build workers as child
processes), `timers.mjs` (tick, provider probe), `health.mjs`, `shutdown.mjs` (SIGTERM → abort,
usage, one `release`), `config.mjs`, the `HttpScannerApi` exercised against the app (contract
fixture), `Dockerfile` (node + python + libheif tools, engine binary, pinned OCR models), the
fault-proxy tests of the pinned request settings, and the calendar adapter.
