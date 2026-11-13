# WBoost AI scan — the scanner

The service that turns an uploaded picture into a **proposal** — a template design, or the layout of
a weekly desk calendar (kind `calendar`, "Calendar scans" below) — that the app validated and rendered
itself, plus a report whose facts are measured. Design and contracts:
`docs/ai-scan/architecture.md` (§4 architecture, §4.9 timing and limits, §7 failures, §8 resources);
the wire contract with the app: `docs/architecture/ai-scan.md` § "Scanner API".
Measurements of this code: [`eval/RESULTS.md`](eval/RESULTS.md).

It claims scans from the app (`/internal/scanner/*`), works on each in a child process of its own
and sends back candidates, a report and usage. It holds **no prompt and no schema** — system prompts,
tool list, finish schema, caps, timing, models, effort and prices are the app-owned definition in
`resources/ai-scan/definition/`, sent with every claim — and **no credential**: everything comes
from the environment.

## Run it

```bash
cd scanner
npm ci                                             # node ≥ 22; pulls the Agent SDK with its engine binary
python3 -m venv .venv && .venv/bin/pip install -r requirements.txt   # the OCR sidecar

# against the local app (docker compose, web on :8099): mode on, and web must accept this machine's address
#   docker compose exec web bin/console app:ai-scan:status --mode=on
#   .env.dev.local:  SCAN_NETWORK_CIDR=172.31.250.0/24,127.0.0.1/32,::1/128,<the address web sees you from>
SCANNER_APP_URL=http://localhost:8099 SCANNER_TOKEN=dev-scanner-token-not-a-secret-0000 node bin/scanner.mjs serve
```

With `ANTHROPIC_API_KEY` unset the engine uses the Claude plan this machine is logged in to (the
rule for all development and testing); production sets the key. Nothing reads `.env.local`.

| Command | Does |
|---|---|
| `scanner [serve]` | the service; runs until SIGTERM / SIGINT |
| `scanner healthcheck` | exit 0 while the running service is healthy — the container healthcheck |
| `scanner selftest` | sharp, the OCR sidecar with its models, the engine binary, a HEIC upload, scratch — needs no network |
| `scanner version` | package version `+` commit |
| `scanner scan-file <picture> --project <id> …` | one scan of a file through the app's MCP design tools (development, the evaluation) |

### Configuration (environment only — `src/config.mjs`)

| Variable | Default | |
|---|---|---|
| `SCANNER_APP_URL` | — required | base URL of the app as the scanner reaches it (`http://web` on the scan network) |
| `SCANNER_TOKEN` | — required | bearer token of `/internal/scanner/*` (≥ 24 characters; the app also accepts the previous one during a rotation) |
| `ANTHROPIC_API_KEY` | unset = plan login | the provider credential in production. `CLAUDE_CODE_OAUTH_TOKEN` (from `claude setup-token`) is the plan's token for a machine without a login — a container in development |
| `SCANNER_CONCURRENCY` | `1` | builds at once. Refused at start when the container's memory limit cannot hold it: 3 GiB for 1, 5 for 2, 7 for 3 |
| `SCANNER_ENGINE` | `sdk` | `sdk` (Agent SDK) or `messages` (plain Messages loop; needs the key) |
| `SCANNER_SCRATCH_DIR` | `<tmp>/wboost-scanner` | per-run scratch root; swept at start, removed at stop |
| `SCANNER_HEALTH_PORT` / `SCANNER_HEALTH_HOST` | `8787` / `127.0.0.1` | `GET /health`; port `0` = no listener |
| `SCANNER_HEALTH_FILE` | — | the health document is also written here every 5 s |
| `SCANNER_VERSION` / `SCANNER_BUILD` | package version `+` build | what the app records as the scanner's version (the image bakes the commit into `SCANNER_BUILD`) |
| `SCANNER_OCR_PYTHON` | `.venv/bin/python` | the python that has rapidocr (the image sets it) |
| `SCANNER_PROBE_MODEL` | `claude-haiku-5-5` | model of the 1-token provider probe before any definition was seen |
| `SCANNER_LOG` | `json` | `json`, `text` or `silent`. Log lines carry ids, codes, counts and durations — never OCR text, a design, a prompt or bytes |

## Process shape

```
main process (bin/scanner.mjs serve → src/main.mjs)
 ├─ tick                every 30 s and at start: protocol handshake, operating mode, provider state, the app's reaper
 ├─ intake lane         claim poll every 5 s · 1 slot                      ┐ two independent loops: a pre-check
 ├─ build lane          claim poll every 5 s · SCANNER_CONCURRENCY slots   ┘ never waits behind a running build
 ├─ per claimed job     JobRunner: forks ONE child process (its own process group), owns the heartbeat timer,
 │                      the abort, the kill of the child's process tree, the release, the scratch directory
 ├─ provider probe      every 5 min while the provider is blocked (a child of its own); "ok" is reported by the next tick
 ├─ GET /health         200 / 503
 └─ SIGTERM             stop claiming → abort every run (≤ 5 s, then SIGKILL of the group, exit verified)
                        → ONE release per job (≤ 20 s) → scratch removed → exit, within 30 s whatever happens

child process of one run (src/worker.mjs)
 intake:  source → normalise (a child of its own: pixel limit, small heap, raised OOM score) → artefacts
          → pre-check (Haiku) → finish passed | stopped | failed provider_unavailable | refused
 build:   working copy → pre-pass (OCR sidecar, background, palette, font ranking) → model loop under caps
          (engine binary of the Agent SDK) → keep-best → verification render of the fonts → measured report → finish
```

| Rule (architecture §4.9) | Where |
|---|---|
| Heartbeat every 15 s on a timer of the **main** process, one attempt ≤ 10 s, never queued behind a hung one; carries stage / note / round and the cumulative usage | `src/timers.mjs` `Heartbeat`, `src/lanes.mjs` |
| Self-abort 105 s after the **send time** of the last acknowledged beat; 409 / 410 / `cancel` / mode `off` end the run at once | `Heartbeat` |
| One app-call attempt ≤ 30 s; a failing call is retried until 60 s (R) have passed since its first attempt; 409 / 410 never | `src/protocol.mjs` `HttpScannerApi.call` |
| `render` is idempotent by `(run, seq)`: every retry carries the same seq; a busy or absent renderer is retried inside R | `src/run.mjs` `withRendererRetry` |
| `finish` is idempotent per `(scan, run)`; a repeat or a late one is told the scan's state and is not an error | `forJob().finish` |
| Usage is closed on every path: `finish` (the child), `release` (shutdown, provider blocked), a closing beat (cancelled, interrupted, lost, crashed) | `JobRunner.#close` |
| A child that dies or hangs is never released (a poisonous input would come straight back): the lease lapses, the app's reaper restarts the scan once, then fails it | `JobRunner` |
| Thread pools pinned to `floor(3 ÷ concurrency)`; a concurrency the memory limit cannot hold is refused at start | `src/config.mjs` |
| The timing the service needs before its first claim (claim poll, tick, the HTTP client's attempt and tolerance, probe, shutdown, kill grace) are constants — `DEFAULT_TIMING` — and a test holds them equal to the definition's `timing`, key by key; a run takes its own timing from its claim's definition | `src/protocol.mjs`, `test/timers.test.mjs` |
| A run that hangs while the heartbeat lives is killed at run cap + R + one attempt + 15 s = 465 s (intake: 150 s) and never released | `src/lanes.mjs` |
| No model fallback: a refused turn is never re-run on another model (`CLAUDE_CODE_DISABLE_REFUSAL_FALLBACK=1`); were the harness to do it anyway, the run is stopped as a refusal and the other model's tokens are priced by its own row | `src/engine/sdk.mjs` |

### Health

`GET /health` (or `scanner healthcheck`): `200 {"status":"ok"}` while every lane with a free slot has
completed a claim poll within 60 s; `503` with `reasons` (`intake_claim_poll_overdue`,
`build_claim_poll_overdue`, `stopping`) otherwise — the app is unreachable, or refuses this scanner's
network or token (`app.lastError`: `not_scanner` = 404). A scanner the app calls too old stays
healthy and says so (`protocol.accepted: false`, the lanes are `held`).

## What happens in a scan

| Step | Code | Notes |
|---|---|---|
| Intake: normalise | `src/intake/normalise.mjs` (+ `normalise-child.mjs`) | a child process with a pixel limit decodes the upload: HEIC (system converter), CMYK, 16-bit, EXIF rotation, first frame, alpha on white → working copy (sRGB, 8 bit, long edge ≤ 4096 px) + preview. Limits: 10 MB, 8000 px a side, 36 Mpx, short side ≥ 600 px; child cut at 20 s |
| Intake: pre-check | `src/intake/precheck.mjs` | one Haiku call on the preview, own request settings (18 s, 1 retry, ≤ 38 s): design / calendar page / photo of a print / several designs / blurry or small / not a design → pass or a stop reason |
| Pre-pass (mandatory, no model) | `src/adapters/template.mjs` `prepass()` | OCR lines (RapidOCR sidecar, pinned threads) → measured ink box, glyph colour and backdrop per line; background fit; palette; font ranking of the project's faces for one line per text style (the app renders, the scanner compares glyph shapes and fits the size) |
| Model loop | `src/engine/` | Opus 5.5 at effort `high` gets the picture + the pre-pass as its first message and 7 tools: `scan_source`, `scan_crop`, `scan_measure`, `font_rank`, `compare_region`, `design_preview`, `design_patch` |
| Caps | `src/caps.mjs`, `src/tools/server.mjs`, `src/run.mjs` | ≤ 40 turns, $1.50, builder 300 s, run 360 s, ≤ 3 rendered candidates, ≤ 3 validation rejections, ≤ 18 pictures (each ≤ 2576 px, ≤ 3 MB), tool timeouts 30 / 95 s |
| Calendar scans | `src/adapters/calendar.mjs`, `src/finish/calendar-report.mjs` | the same runner with millimetre instruments — see "Calendar scans" |
| Finish | `src/finish/`, `src/instruments/fonts.mjs` | keep-best by measurement; **font verification**: each text group's own source lines are rendered by the app in the face and at the size the kept design uses and compared with the source pixels word by word — that score decides `measured_match` / `nearest_available`; report fields written from measurements; when the model never finished, the scanner reports alone (`unfinished`) |

## Calendar scans (kind `calendar`)

One page of a weekly desk calendar → a **layout** of the WBoost calendar the scan was started for
(boxes in mm, type in pt, colours, fills, rules, what is shown). Same lanes, caps, image rule,
heartbeat, engine and keep-best as a template scan; what differs:

| | |
|---|---|
| Unit | **page millimetres**, origin = the top-left trim corner — the unit the calendar's model takes. Every tool reads and answers mm through the **page box** (where the page is in the picture: the whole picture, a detected plain margin, or `set_page_box`) |
| Pre-pass | OCR lines (boxes, ink height, `sizeIfCapitalsPt`), the **row ladder** (thin rules of one pitch → the Kalendárium frame they imply), palette, a font ranking when the project has fonts (a calendar scan runs without any) |
| Candidate | `calendar_preview`: `preset` + `features` (ON only) + one patch of `boxes` / `order` / `styles` → the calendar model's commands → the app runs them on the calendar's document **in memory** and draws the sample week (`render {commands}`); `"patch": true` merges over the previous candidate. A patch the model refuses counts as a validation rejection |
| Feedback | numbers in mm: masked `mae` (the photo, logo and QR boxes are the user's pictures), the rule ladder of source vs render with its `delta` and the arithmetic to apply, per element `mae` / `move`, for month and week label `inkHeightRatio` (the wording differs from the source's, so widths are never compared) |
| Report | `src/finish/calendar-report.mjs`: closed lists only — `recognised`, `needsUpload`, `featuresOffered`, `fonts` (pattern from the rankings), `notExpressible` (code + source-px box), `notes`; `pageBox` / `photoBox` in source px; an element the kept layout hides is `absent` whatever the model said |
| Instruments | the scanner announces `mm`; the definition asks for it for `kinds.calendar` only — an image without it is never handed a calendar build and keeps serving templates |
| Never | no picture is cut out of the source; a feature is only switched on; no font is required |

Tools the model gets: `scan_source`, `scan_crop`, `scan_measure`, `font_rank`, `compare_region` (their
calendar variants in `tools.json`, `id` `<name>.calendar`), `calendar_preview`, `set_page_box`.
Measurements: `eval/RESULTS.md` §9; `node eval/calendar.mjs` runs and scores them.

## Layout

```
bin/scanner.mjs            CLI: serve · healthcheck · selftest · version · scan-file
src/main.mjs               the service: lanes, tick + handshake, provider probe, health, stop
src/config.mjs             configuration from the environment; the memory rule
src/protocol.mjs           HttpScannerApi (the app's scanner endpoints), definition + claim-context readers, default timing
src/lanes.mjs              Lane (claim poll, slots) · JobRunner (child process, heartbeat, abort, kill, release, scratch)
src/timers.mjs             Heartbeat · every()
src/worker.mjs             the child process of one run
src/health.mjs             /health, the healthcheck command
src/shutdown.mjs           SIGTERM / SIGINT → ordered stop inside the limit
src/proc.mjs               process trees (descendants, killTree), cgroup memory
src/selftest.mjs           `scanner selftest`
src/run.mjs                one intake / one build against a JobApi: caps, renderer retry, finish payloads
src/drivers/               file-job.mjs + mcp-app.mjs — a job from a file through the app's MCP tools (scan-file, the evaluation)
src/engine/                sdk.mjs (Agent SDK, locked down) · messages.mjs (plain Messages loop) · classify.mjs · pricing.mjs
src/intake/                normalise (+ child), precheck
src/instruments/           raster, textink, background, fonts (ranking + verification), ocr (+ ocr/ocr.py)
src/adapters/              template.mjs (pre-pass, tools, feedback), calendar.mjs (the same in page mm), mask.mjs
src/tools/                 server.mjs (dispatch, timeouts, image budget), images.mjs (image rule)
src/finish/                keepbest, report (templates), calendar-report, text
src/caps.mjs, src/resources.mjs
dev/app-user.mjs           the USER's side through the app's web endpoints: start, status, cancel, retry, continue, wait
dev/scan.php               reads what the app stored of a scan (row, usage rows, ledger, proposal, pictures) — inside the dev container
dev/new-variant.php        helper of the file-job driver
test/                      node test runner — `npm test` (+ `npm run test:slow`); test/contract/ = the contract fixture
eval/                      the evaluation and the measurements — see below
Dockerfile, requirements.lock, .dockerignore     the image
```

## The image

```bash
docker build --platform linux/amd64 --build-arg SCANNER_BUILD=$(git rev-parse HEAD) -t wboost-scanner scanner
docker run --rm --network none --read-only --tmpfs /tmp:rw,exec,size=256m wboost-scanner node bin/scanner.mjs selftest
```

`node:24.22.3-bookworm-slim` pinned by digest · production `node_modules` with the one engine binary
(linux-x64, glibc) · a virtualenv with the OCR stack pinned to the package (`requirements.lock`), the
OCR models fetched at build time · `libheif-examples` for HEIC · `tini` · user `node` (uid 1000) ·
no `npm`, no tests, no evaluation, no prompt, no credential. It writes under `/tmp` only (scratch,
the engine's home and configuration — per run), so the root file system can be read-only.
`HEALTHCHECK` = `node bin/scanner.mjs healthcheck`. Size and memory: `eval/RESULTS.md` §5.

CI (`.github/workflows/scanner.yml`): tests on every push that touches `scanner/` or
`resources/ai-scan/`; on `main` the image is built and pushed to
**`ghcr.io/thedevs-cz/wboost-scanner`** — tags `sha-<commit>`, `main`, `latest` — and its self-test
runs with no network. Nothing is deployed from here; the box pins a tag.

What the deployment needs (`~/www/lily.srv/apps/wboost/compose.yaml`, phase P4):

| | |
|---|---|
| image | `ghcr.io/thedevs-cz/wboost-scanner:sha-<commit>` |
| environment | `SCANNER_APP_URL=http://web` (the project-unique name on the `scan` network), `SCANNER_TOKEN`, `ANTHROPIC_API_KEY`; optional `SCANNER_CONCURRENCY` |
| app side (`web`) | `SCAN_NETWORK_CIDR=<subnet of the scan network>`, `SCANNER_TOKEN=<current>[,<previous>]`, the private bucket; the public router must refuse `/internal/` |
| network | `scan` only (shared with `web`); no route to db, redis, minio, gotenberg |
| limits | `mem_limit: 4g` at concurrency 1 — a large build peaked at 1.8 GiB in the image, an intake next to it adds ≤ 0.7 GiB (`eval/RESULTS.md` §0.5); the scanner itself refuses to start below 3 g. Concurrency 2: 6 g (not measured as a service). `cpus: 3`, `pids_limit: 512` |
| lifecycle | `init: true`, `stop_grace_period: 45s`, `read_only: true` + `tmpfs: /tmp` (≥ 512 MB), no `depends_on` on `web` |
| healthcheck | `["CMD", "node", "bin/scanner.mjs", "healthcheck"]`, interval 15 s, timeout 5 s, retries 3, start period 30 s |

## Engines

`src/engine/index.mjs` exposes one contract — `run(options) → {outcome, finish, usage, turns, error, facts}`
with `outcome` ∈ `finished | aborted | max_turns | max_budget | refusal | provider | error` — and two backends:

- **`sdk`** (default): `@anthropic-ai/claude-agent-sdk`, locked down — `tools: []`, only the
  in-process `scan` MCP server (`strictMcpConfig`), `allowedTools` + `permissionMode: 'dontAsk'`,
  `settingSources: []`, own `systemPrompt`, `persistSession: false`, empty `cwd`, pinned request
  environment, **no refusal fallback**. With a credential from the environment (a key, or the plan
  token) the engine's configuration directory and home are inside the run's scratch; with the plan
  login of a developer's machine they are not (an isolated directory hides the login).
- **`messages`**: `@anthropic-ai/sdk` loop over the same tool list and dispatcher; retries and delays
  exactly as the definition pins them. Needs `ANTHROPIC_API_KEY` — not run in development.

`eval/engine-cli.mjs` drives the same scanner with the `claude -p` binary; it exists only to
measure parity with the prototypes' engine.

## Tests

```bash
npm test                # 230 tests, ≈ 45 s — no model, no app
npm run test:slow       # the app-outage rules at their real times (≈ 85 s)
```

| File | Covers |
|---|---|
| `timers.test.mjs` | the heartbeat at the real numbers on a mocked clock: 13 beats in 200 s; self-abort at exactly 105 s from the send time; a 60 s outage in the worst alignment survives, 120 s aborts; a hung beat; 409 / 410 / cancel / mode off |
| `protocol.test.mjs` | the HTTP client against a stand-in app: headers, shapes, retries inside R, a lost answer, idempotent render and finish, a late finish, one-attempt beat / release, an app-backed tool through an outage and a slow render |
| `lanes.test.mjs` | one job with a scripted child: beats while the child's event loop is blocked; cancel, mode off, lost lease, deleted project; self-abort; the kill of the process tree **by pid** (in-group, own group, grandchild); crash; hang; shutdown release; provider blocked; scratch empty |
| `main.test.mjs` | the service: config and the memory rule; the intake lane next to a running build; **two workers never cross scans**; protocol mismatch; too old by instruments; provider probe; shutdown inside the limit; health |
| `service.test.mjs` | `scanner serve` as a real process with the real child, the real OCR sidecar and the **real engine binary** against a fault proxy and a stand-in app: a build, an intake, a refusal (no other model asked), **SIGTERM mid-build** (engine dead by pid, one release with usage, exit 0, scratch gone) |
| `engine-faults.test.mjs` | both engines against the fault proxy: pinned retries and delays, request timeout, stream-idle watchdog, what is never retried, refusal — and the fallback detection when the switch is taken away |
| `hostile.test.mjs` | decompression bombs, 36 Mpx, CMYK, 16-bit, animated, truncated, HEIC, SVG, empty, > 10 MB — each a named outcome within a memory and time bound |
| `contract.test.mjs` | the contract fixture replayed from the scanner's side (`test/contract/README.md` — the app's CI replays it from its side) |
| `calendar.test.mjs` | the calendar adapter: row ladder, page-box detection, picture mask, tools in mm, `calendar_preview` (commands, patch, caps, a refused patch), the report's closed lists, a whole calendar build against a stand-in calendar model, the `commands` render of the client |
| `run`, `tools`, `finish`, `intake`, `instruments`, `engine`, `caps`, `classify` | step A: the build and intake of one job, caps, the image rule, the report, the instruments |

## Evaluation and measurements (`eval/`)

```bash
node eval/run.mjs --sets easy,photo,hard --runs 2         # real scans through the file driver, on the plan
node eval/report.mjs                                      # tables from eval/runs/**/record.json
node eval/precheck-eval.mjs                               # the pre-check alone
node eval/engine-facts.mjs sdk|messages|cli               # fault injection against eval/fake-provider.mjs — no model
node eval/e2e.mjs <input …>                               # real scans through the REAL app job core (the service must run)
node eval/calendar.mjs --calendar <id> <input …>          # calendar scans through the real job core, scored with the prototype's scorer (CALENDAR_PROTO_DIR)
node eval/font-verify.mjs                                 # the font verification on the recorded scans, both error rates
node eval/record-contract.mjs                             # records test/contract/scanner-api.v1.json against the local app
node eval/g2a.mjs soak|faults|queue|limits|kill|orphans|idle|audit   # the local reliability gate (G2a) — docs/ai-scan/reliability-report.md
eval/container-run.sh                                     # one scan in the linux/amd64 image; cgroup memory peak, wall time
```

`eval/sets.json` names the inputs (the prototypes' easy 7 + 3 and hard 12). The pictures are client
material and prototype artefacts — git-ignored; so are `eval/runs/`, `runs/`, `.venv/`, `.scratch/`.
`dev/app-user.mjs` needs `WBOOST_LOGIN_FILE` (a file with `email=…` / `password=…` of a local
admin, kept outside the repository).
