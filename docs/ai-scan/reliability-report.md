# AI sken — reliability report, gate G2a (the part that runs locally)

`architecture.md` §10 "Gates": G2a = items 1–3, 5, 10–12 of the reliability checklist, run before the
scanner goes onto the production box (P4); G2b = items 4, 6–9, run on the box. This is the evidence
for G2a: per item what was run, what was observed, pass or fail, and the commit that fixed what failed.
Run on 2026-11-12 / 13.

**Verdict: G2a passes.** Eleven defects were found and fixed on the way (below); what remains is listed under "Open before the scanner goes onto the box" — none of it blocks P4 from starting, one of it (operating e-mails) has to exist before admins rely on the feature.

| Item | | Result |
|---|---|---|
| 1 | Timing table of §4.9 re-derived and checked against both sides | pass — 7 mismatches fixed |
| 2 | A killed scan shows its spend | pass — real model; 1 defect fixed |
| 3 | Daily starts, retry bounds, per-project budget, daily ceiling — in a loop | pass |
| 5 | Fault-injection suite, with the review races; in CI | pass — 16 tests, 5 × green locally, a CI job |
| 10 | Provider error classifier through the chosen engine; probe and auto-clear | pass — 15 scenarios; 1 defect fixed |
| 11 | Soak, queue test, a scan after idling | pass — 100 / 100; 2 defects fixed; Gotenberg to watch |
| 12 | Runbook | pass — written, the switches tried |

## 0. How it was run

- **App**: the real application, not a stand-in — the code of this repository in a container of the
  app's image (FrankenPHP, as in development), with a database, a private bucket, a cache and a scanner
  token of its own, so that the other work going on in the shared development stack could neither
  claim these scans nor be disturbed by them. Postgres, MinIO and Redis are the development ones;
  renders go to a real Gotenberg (a dedicated one for the soak, so that its memory is the soak's).
  Three such stacks ran side by side (soak · faults, limits · real-model runs, queue, idle).
- **Scanner**: `node bin/scanner.mjs serve` as a process of its own on the host (macOS 27 / arm64,
  node 25) — the real service: lanes, heartbeat, one child process per run, the real engine binary of
  the Agent SDK, the real OCR sidecar, the app's real `render` / `faces`.
- **Provider**: fake unless a row says "real model". The fake (`scanner/eval/fake-provider.mjs`, driven
  by `scanner/eval/g2a.mjs`) answers by what a request is: the pre-check gets "design", a builder
  request gets the recorded tool call of its turn from an earlier real scan of the same picture
  (`eval/runs/sdk/<input>/r1`) — or the injected fault. Every turn reports usage with a 1-hour cache
  write, so that pricing is exercised. Real-model runs used the owner's Claude plan
  (`ANTHROPIC_API_KEY` unset), concurrency 1: three kill tests (six partial builds) and two whole scans, about $0.90 of computed cost.
- **Users**: scans are started through the app's own endpoints — a logged-in session posting to
  `POST /project/{id}/ai-scan`, and an MCP client calling `scan_design` with a personal access token.
  No row was written by hand to start or move a scan.
- **Tooling** (committed): `scanner/eval/g2a.mjs` — `soak`, `faults`, `queue`, `limits`, `kill`,
  `orphans`, `idle`, `audit`. Raw output: `scanner/eval/runs/_g2a/` (git-ignored).
- **The machine was busy** the whole time (other agents' test runs and containers, the owner's own
  programs; load average 14–27 on 12 cores). Durations below are therefore upper bounds — a replayed
  scan that takes 75 s on a quiet machine took 100–300 s — and three findings (§ "Found and fixed",
  7–9) exist only because calls that normally take 2 s took 30. Memory figures are not affected.

## A. App-side follow-ups

| # | What | Change | Test | Commit |
|---|---|---|---|---|
| 1 | Cache writes priced at the 5-minute rate only | `AiUsageFigures` reads the split the scanner sends (`cacheWriteTokens` = all, `cacheWrite5mTokens`, `cacheWrite1hTokens`); `AiScanDefinition::costMicroUsd()` prices the 1-hour part with `cacheWrite1h`; the 1-hour count is stored (`ai_usage_event.cache_write_1h_tokens`, migration `Version20261113090000`). `price_version` kept. The scanner's heartbeat usage lost the split on its way from the run's process to the main process (`wireUsage`) — fixed, so beats, releases and closing beats carry it like `finish` does | `AiScanValuesTest::testThePriceTableChargesCacheWritesByLifetimeAtTheOfficialMultipliers`, `::testUsageFiguresReadTheCacheWriteSplit`; `ScannerApiTest::testCacheWritesArePricedByTheirLifetime`; scanner `run.test.mjs` | `f2c3ce78` |
| 2 | Usage of a run whose lease was replaced is dropped | a usage row keeps the hash of the lease token that opened it (`ai_usage_event.lease_token_hash`); a `beat` / `finish` / `release` refused with `lease_lost` or `gone` still records its `usage` on the row that token opened (`ScannerApi::recordReplacedRun()`), and nothing else: the answer stays 409 / 410, no state, ledger line or provider state changes | `ScannerApiTest::testUsageOfAReplacedRunIsRecordedOnItsOwnRow`, `::testUsageAfterAReleaseARetryAndADeletedScanIsStillRecorded`; fault injection `AiScanRaceTest::testAReplacedRunsUsageIsRecordedAndChangesNoState` (the second claim racing the old run's late beat and finish, 6 rounds, both orders seen) | `f2c3ce78` |
| 3 | `SCAN_NETWORK_CIDR` for local runs | `docs/architecture/ai-scan.md` "Local use" and the comment in `.env.dev` name `.env.dev.local` and say why (`.env.dev` is loaded after `.env.local`); `scanner/README.md` already did | — | `f2c3ce78` |
| 4 | Contract fixture replay on the app side | `tests/Controller/Internal/ScannerContractFixtureTest` replays all 57 recorded exchanges of `scanner/test/contract/scanner-api.v1.json` against the real controllers (1 307 assertions): same status, `ok` / `code` / `status` / `applied` / `cancel` / `candidate` / `reason` equal, every other property present with the same JSON type; for every recording of a protocol version the app accepts (N and N − 1). It is in the default PHPUnit run, i.e. in the Tests workflow | the test itself | `f2c3ce78` |

**Prices checked against the provider's page** (platform.claude.com/docs/en/about-claude/pricing, read
2026-11-12): a 5-minute cache write is 1.25 × the base input price, a 1-hour write 2 ×, a cache read
0.1 × — 0.05 × on Opus 5.5 and Sonnet 5.5. The table in `definition.json` (version `2026-11-03`) has
exactly these values for its four models; a test now asserts the multipliers. Not in the table, on
purpose: Haiku 5.5's higher price for prompts over 100 000 tokens — the pre-check sends one picture of
≤ 1568 px.

**Recorded against the engine's own figure:**

| Rows | Recorded before | Engine | Recorded now |
|---|---|---|---|
| The 20 builder rows of real scans stored locally (Opus 5.5, all cache writes 1-hour — the plan asks for that), re-priced from their tokens | $3.1155 (−18.2 %) | $3.8098 | **$3.8098 (0.0 %, every row within a micro-dollar)** |
| Pre-check rows of the same scans made by the final scanner build | $0.000124 each | $0.000155 | $0.000155 |
| 100 replayed scans of the soak (the engine binary's own `total_cost_usd` against the app's rows; 1-hour writes reported by the fake provider) | — | $26.524 | **$26.524 (0.0 %)** |
| A real scan after the fix (the scan after idling, item 11) | — | $0.2514 | **$0.2514** |

One thing recorded cost cannot contain: a run that is killed never reports the engine's figure (it
comes with the engine's result), so its row has `engine_cost_micro_usd = 0` and the table price of the
last heartbeat's tokens — which is the point of pricing from tokens.

## 1. Timing table (§4.9), re-derived

Measured stage times (`scanner/eval/RESULTS.md` §0 and §6: 66 scans through the file driver, 8 through
the service against the app, the unhappy paths; quiet machine):

| Stage | Measured | Bound that wraps it | Margin |
|---|---|---|---|
| normalise | 0.1–4.5 s (5.7 s for the largest legal upload, emulated x86) | 20 s, then the child is killed | ≥ 3.5 × |
| pre-check | 1.9–2.9 s | 18 s a request, 38 s in all | 6 × / 13 × |
| intake, claim → finish | 3.7–8.1 s | cap 120 s = work ≤ 60 s + R 60 s; lease 150 s | 15 × |
| pre-pass | 5–15 s through the app's `faces` | W − builder cap = 60 s | 4 × |
| model loop | 20–122 s, ≤ 15 turns | builder cap 300 s, 40 turns | 2.5 × |
| a turn's first output | ≤ 6 s | stream idle 60 s; request 120 s | 10 × |
| keep-best + font verification + report | 1–3.3 s | inside W | — |
| build run (pre-pass + loop + finish) | 37–112 s (≤ 150 s in step A) | run cap W 360 s; hang guard 465 s | 2.4 × |
| whole scan as the user sees it | 42–121 s | "Trvá to déle" at 180 s | not reached |
| an app-backed tool through a 55 s outage + a 25 s render | 80–85 s | tool timeout 95 s | 10 s |
| self-abort | 105.003 s after the send time of the last acknowledged beat | 105 s; lease 120 s | 15 s for the kill (2 s used) |
| cancel / mode off → engine stopped | 7.1 s / 7.0 s | ≤ H = 15 s | 2 × |
| lost lease → requeue | 113–117 s after the kill | L 120 s + a reaper call | — |

Derivation, outer bound by outer bound — nothing has to move:

- **H 15 s · attempt 10 s · R 60 s · A 105 s · L 120 s.** A beat attempt (10 s) is shorter than the
  period, so beats never queue. A must outlast an outage of R: the last beat before it is up to H old,
  the first after it leaves up to H later and is acknowledged within one attempt:
  15 + 60 + 15 + 10 = 100 s < 105 s. L must outlast A plus the kill: 105 + 5 = 110 s < 120 s. Measured:
  the abort came at 105.003 s and the engine was dead 2 s later; the reaper acted at 113–117 s.
- **App call 30 s · R 60 s · tool 95 s.** Attempts start only inside R and each is ≤ 30 s, so a call
  ends ≤ 90 s < 95 s — now true for a whole render sequence too (fixed, below). Measured 80–85 s.
- **Intake: 20 + 38 + transfers ≤ 60 s of work, + R = 120 s cap, + one attempt = 150 s lease.**
  Measured ≤ 8.1 s.
- **Builder 300 s < run cap 360 s < hang guard 465 s (= W + R + one attempt + 15).** W − builder = 60 s
  for the pre-pass and the report: measured ≤ 15 + 3.3 s.
- **Queue: 8 waiting × W + one restart = 54 min < Q = 60 min** at concurrency 1.
- **Shutdown: kill grace 5 s + one release 20 s = 25 s ≤ 30 s < the 45 s stop grace** (the latter is a
  compose setting of P4).

Every number, where it lives, and whether document, app and scanner agree
(`def` = `resources/ai-scan/definition/definition.json`, which the app owns and sends with every claim;
`DT` = `DEFAULT_TIMING` in `scanner/src/protocol.mjs`):

| Quantity | Document | App | Definition | Scanner | |
|---|---|---|---|---|---|
| Heartbeat H | 15 s | `AiScanLimits::HEARTBEAT_SECONDS` (asserted equal to def) | `timing.heartbeatSeconds` 15 | `timers.mjs` `Heartbeat`, from the run's definition | ✔ |
| Beat attempt | 10 s | — | `heartbeatAttemptSeconds` 10 | `Heartbeat` (def) + the HTTP cut in `protocol.mjs` (DT) | ✔ after fix 1 |
| Claim poll | 5 s | — | `claimPollSeconds` 5 | `main.mjs` (DT) | ✔ after fix 2 |
| App call attempt | 30 s | PHP limit 30 s; a render raises its own to 60 s (`FontFacesRenderer`, `TemplateVariantImageRenderer`) | `appCallAttemptSeconds` 30 | `HttpScannerApi.attempt` (DT) | ✔ document corrected |
| Outage tolerance R | 60 s | — | `appOutageToleranceSeconds` 60 | `HttpScannerApi.call` (DT), `withRendererRetry` and the closing beat (def) | ✔ after fix 3 |
| Self-abort A | 105 s from the send time | — | `selfAbortSeconds` 105 | `Heartbeat` (`lastAckedSend`) | ✔ |
| Lease L | 120 s | `AiScanLimits::LEASE_SECONDS`: `claimBuild`, `ScannerApi::beat` | `leaseSeconds` 120 | not read — the scanner depends on A, not on L (asserted: A + kill grace < L) | ✔ |
| Intake lease | 150 s | `AiScanLimits::INTAKE_LEASE_SECONDS`: `claimIntake` | `intakeLeaseSeconds` 150 | the intake's hang guard (`lanes.mjs`) | ✔ |
| Reaper | claim, tick, purge, status and list reads | `ScannerApi::claim` / `tick`, `AiScanPurge::run`, `AiScans::current`, `AiScanLists` | `tickSeconds` 30 | `main.mjs` tick (DT) | ✔ |
| Intake cap | 120 s | — | `intakeCapSeconds` 120 | `run.mjs` `runIntake` | ✔ |
| Normalise | 20 s | — | `normaliseSeconds` 20 | `run.mjs` → `normaliseInChild` | ✔ |
| Pre-check request | 18 s, 1 retry after 2 s, ≤ 38 s | — | `preCheck.{requestTimeoutSeconds, retries, retryDelaysSeconds, maxSeconds}` | `intake/precheck.mjs` | ✔ after fix 1 |
| Builder cap | 300 s | — | `builderCapSeconds` 300 | `caps.mjs` | ✔ |
| Run cap W | 360 s | `AiScanLimits::RUN_CAP_SECONDS` (the estimate's restart penalty) | `runCapSeconds` 360 | `caps.mjs`, `run.mjs` | ✔ |
| Hang guard | 465 s / 150 s | — | derived | `lanes.mjs` `JobRunner` | ✔ added to the document |
| Closing beat | repeated ≤ R after a self-abort | recorded on the run's own row (follow-up 2) | derived | `lanes.mjs` `#close` | ✔ added to the document |
| Automatic restart | 1 per try | `AiScanTransitions::buildLeaseLost` (`auto_restart`) | — | — | ✔ |
| Model request | 120 s; idle 60 s; 4 retries; delays 2, 8, 20, 30 s | — | `engine.*` | `engine/sdk.mjs` reads timeout, idle and retries; **the delays are read only by `engine/messages.mjs`** | ✗ for the SDK engine — measured in item 10, document corrected |
| Provider-lost share | 50 % | — | `caps.providerLostShare` 0.5 | `caps.mjs`, `run.mjs` | ✔ |
| Effort | high / high / low | — | `effort.*` | `run.mjs` (builder), `precheck.mjs` (pre-check) | ✔ |
| Tool timeouts | 30 s / 95 s | — | `localToolTimeoutSeconds`, `appToolTimeoutSeconds` | `run.mjs` → tool server | ✔ |
| Render client | 20 s (25 s in all) | `config/packages/sensiolabs_gotenberg.yaml` `timeout: 20`, `max_duration: 25` | — | — | ✔ |
| Scan renders at once | 2 | `ScanRenderLimiter::SLOTS` | — | — | ✔ |
| Scanner absent S | 60 s | `AiScanLimits::SCANNER_ABSENT_SECONDS` (`AiScanRuntimeSnapshot`); refreshed by every scanner call, written at most every 5 s | — | `/health` has its own 60 s | ✔ |
| Queue | 4 per project, 8 waiting | `AiScanLimits::PROJECT_IN_PROGRESS_MAX`, `WAITING_TOTAL_MAX` (`AiScans::assertMayStart`) | — | — | ✔ exercised in item 11 |
| Queue timeout Q | 60 min from `waiting_since` | `AiScanLimits::QUEUE_TIMEOUT_SECONDS` (`AiScanReaper`) | — | — | ✔ |
| Provider probe | 5 min | — | `providerProbeSeconds` 300 | `main.mjs` (DT) | ✔ measured in item 10 |
| Shutdown | kill 5 s, release 20 s, exit ≤ 30 s | — | `killGraceSeconds` 5, `releaseAttemptSeconds` 20, `shutdownSeconds` 30 | `lanes.mjs`, `protocol.mjs`, `main.mjs` (DT) | ✔ after fix 1 |
| UI poll | 2 s / 10 s | `assets/ai-scan/page/app.js`, `ai_scan_shell_controller.js`, `ai_scan_strip_controller.js` | — | — | ✔ |
| Estimate | "cached 15 s" | **not cached**: computed on every status read of a waiting scan (`AiScanQueueEstimate`) | — | — | document corrected (fix 4); cost measured in item 11 |
| "Trvá to déle" | 180 s | `assets/ai-scan/page/wait.js` | — | — | ✔ |
| E-mail | ≥ 120 s after the end | `AiScanNotifications` (delay 120 s; the handler's condition 110 s leaves the queue 10 s of slack) | — | — | ✔ |
| Failed card for other editors | 24 h | `AiScanLists` | — | — | ✔ |
| Retention · reminder | 30 days · 3 days before | `AiScanLimits::RETENTION_DAYS`, `AiScanNotifications` | — | — | ✔ |
| Upload | 10 000 000 B, 8000 px, 36 Mpx, 600 px | `AiScanUpload` (header), `assets/ai-scan/file-checks.js` | `upload.*` | `intake/normalise.mjs` (decoded) | ✔ |
| Working copy | ≤ 4096 px | — | `workingCopy.longEdgePx` | `normalise.mjs` | ✔ |
| Template from a scan | ≤ 5000 px | `AiScanLimits::TEMPLATE_MAX_LONG_EDGE` (`TemplateProposal`) | `templateMaxLongEdgePx` | from the claim's context | ✔ |
| Model run | 40 turns, $1.50, 3 candidates, 18 images of ≤ 2576 px / 3 MB | budget: `AiScanSpend`; candidates: `ScannerApi::render` | `caps.*`, `imageRule.*` | `caps.mjs`, `tools/images.mjs` | ✔ |
| Daily starts | 10 / 20 | `AiScanRuntime` defaults, `AiScans::assertMayStart` | — | — | ✔ exercised in item 3 |
| Retries | 2 + 1 "Zkusit přesto" | `AiScanLimits::MAX_RETRIES`, `AiScanTransitions::retry` / `continueAnyway` | — | — | ✔ exercised in item 3 |
| Ceilings | $20 / $5 a day | `AiScanRuntime` defaults, `AiScanSpend` | `caps.engineBudgetMicroUsd` | — | ✔ exercised in item 3 |
| Concurrency | `maxRunning` 1 | `AiScanRuntime`, `ScannerApi::claim` | — | `config.mjs` (memory rule) | ✔ |

Mismatches found and what was done:

1. **Three numbers had two names, and the app's test asserted the one nobody reads.**
   `timing.beatAttemptSeconds` / `heartbeatAttemptSeconds`, `timing.shutdownReleaseSeconds` /
   `releaseAttemptSeconds`, `preCheckRequest.*` / `preCheck.*`: the scanner reads the second of each
   pair, `AiScanValuesTest` pinned the first. A change of the value the scanner reads would have gone
   unnoticed. The unread keys are removed, the kill grace (5 s, until now only a scanner default) is in
   the definition, and the test asserts the keys the scanner reads plus four more pairs of the table.
2. **The scanner's transport timing did not come from the definition** (claim poll, tick, attempt,
   tolerance of the HTTP client, probe, shutdown): the service needs them before its first claim, so
   they are constants. A scanner test now holds every one of them equal to the definition's value and
   fails on a timing key nobody reads.
3. **A render sequence could outlast R + one attempt**: after a `renderer_busy` answer the HTTP client
   opened its own 60 s window for the next call. The window is now one (the call is told when it ends);
   test "a busy renderer and then an app that is away…" fails without the fix (3.3 s for a 2 s window).
4. **The document said the estimate is cached for 15 s; the code computes it per read.** The code is
   right (never older than the poll, nothing in the cache) and its cost is small (item 11), so the
   document was changed — and the estimate now reads a kind's durations once per estimate instead of
   once per scan ahead.
5. **PHP's limit**: the document equated the app-call attempt with "PHP's limit, 30 s"; a render raises
   its own limit to 60 s. The scanner's attempt is the binding bound; document corrected.
6. **Not in the document**: the hang guard and the closing-beat repeat — added to §4.9.
7. **The SDK engine does not use the pinned retry delays** — see item 10 for what it does; §4.9 says so now.

**Result: pass** — the table holds on measured times; document, app, definition and scanner agree
after the changes above (commit `f2c3ce78`).

## 2. A killed scan shows its spend

**Run** (`g2a.mjs kill`, real model on the plan; three executions, the last after the scanner fix
below): a real design is uploaded; when the build has sent a heartbeat that carries tokens, the
scanner's main process gets `SIGKILL` (what an OOM kill or `docker kill` is: no handler runs, nothing
is released); a new scanner is started; the restarted run is killed the same way. The last execution
(scan `pripomenuti`):

| | First kill | Second kill |
|---|---|---|
| Usage row at the kill (`builder`, open) | run 1: 4 in · 809 out · 25 270 cache read · 6 330 cache written (all 1-hour) · 2 turns · **$0.0719** | run 2: 4 in · 1 236 out · 29 088 read · 2 849 written · 2 turns · **$0.0533** |
| App after the lease lapsed | `queued`, `restarted` — 122 s after the kill (the user's status poll reaped it) | `failed interrupted` — 120 s after the kill |
| The same row afterwards | closed, outcome `lease_lost`, the same figures | closed, `lease_lost` / `interrupted`, the same figures |
| Ledger | `reserve −1` | `reserve −1`, `refund +1` |
| What the scanner left behind | the run's process and the engine: both gone 2.3 s after the kill | the same, 2.3 s |

`app:ai-scan:status --scan=<id>` lists `pre_check#1 passed $0.0006 · builder#1 lease_lost $0.0719 ·
builder#2 lease_lost $0.0533`; the scan's total ($0.1258) is the sum of its rows, no row is open, and
`Spend today` contains it. The two earlier executions gave the same picture ($0.1427 + $0.0279;
$0.0829 + $0.0150). The fake-provider variant (`g2a.mjs orphans`, 2 rounds) adds the other half: after
the kill a new scanner claims the scan once the lease has lapsed and delivers it — `builder#1 lease_lost
$0.0700`, `builder#2 proposed $0.3493`, one `reserve`, one `settle`; the dead scanner's scratch
directory (1 entry) is swept by the next start.

What a killed run's row cannot hold: what it spent between its last heartbeat and the kill (≤ 15 s of
a turn), and the engine's own figure (that arrives with the engine's result). The admin tab that will
show these rows is P4; until then the status command is the view.

**Found and fixed**: after the kill the engine binary outlived the run's process — with the real
provider the run's process was gone 2.4 s after the kill and the engine 4.7–5.0 s after it (two
executions). The run's process now ends its own process tree on every exit (`scanner/src/worker.mjs`
`endTree`; `service.test.mjs` "the main process is SIGKILLed mid-build…"): 2.3 s for both, measured
with the real provider.

**Result: pass.**

## 3. Daily starts, retry bounds, the project budget, the daily ceiling — in a loop

**Run** (`g2a.mjs limits`): an MCP client (JSON-RPC over `/_mcp`, a personal access token of a user
with the `ai:scan` scope) calling `scan_design`, and the same account's web session posting to the
upload endpoint, each in a loop against the app in production mode with the scanner running (fake
provider; a replayed build is recorded at $0.27). Limits were set with `app:ai-scan:status` to the
values named.

| Loop | Limit | Observed | What a refusal looks like |
|---|---|---|---|
| MCP: `start` + `cancel` × 16 | 10 starts a day per user (the default) | 10 started, 6 refused. Ten reserves, ten refunds — **a refund gives no start back**. The web upload right after: refused too | MCP: tool error `daily_starts_reached: the daily number of scan starts for this user or project is used up; continue tomorrow.` · web: HTTP 429 `{ok: false, code: "daily_starts_reached"}` |
| Web: upload + cancel × 26 | 20 starts a day per project (the default; the user's limit raised out of the way) | 20 started, 6 refused | HTTP 429 `daily_starts_reached` |
| MCP: `retry` in a loop on one upload whose every build is made to fail (`provider_unavailable`) | 2 retries per upload | failed → retry accepted (`retriesLeft` 2 → 1) → failed → accepted (→ 0) → failed → **refused**; the web's retry after that: refused. Three reserves, three refunds; `try` = 3 | MCP: `no_retries_left: both retries of this upload are used up.` · web: HTTP 409 `no_retries_left` |
| MCP: 6 scans run to the end | the project's monthly limit set to 3 more | 3 `ready`, then 3 refused | `limit_reached: the project used up its scans for this month. {"limit":3}` |
| MCP: 8 `start`s 0.4 s apart | project ceiling **$1.60** a day (one run reserves $1.50) | 3 accepted before the first build began, 5 refused once it ran. **One** build ran ($0.27 recorded); the other two stay `queued` with `waiting: "paused"` and no estimate — recorded $0.27 + $1.50 no longer fits $1.60. Further starts refused on MCP and web; a scan in another project is accepted and built. Spent in the project: **$0.27 of $1.60** | `temporarily_unavailable: AI scan is temporarily unavailable (paused, or the scanner is away); try later.` · web: HTTP 503 `temporarily_unavailable` |
| Web: 6 uploads over two projects 0.3 s apart | total ceiling **$1.90** a day | 6 accepted (nothing was running yet). **Two** builds ran (0 + 1.50 ≤ 1.90, then 0.27 + 1.50 ≤ 1.90); the third (0.54 + 1.50) was never claimed: four scans wait as `paused`. The next upload: refused. `status`: `Spend today $0.54 of $1.90` | HTTP 503 `temporarily_unavailable` |

So a loop cannot spend past a ceiling: uploads are let in only while recorded spend + $1.50 ×
(running + 1) fits, **and the same condition is applied again at every build claim**, so scans that
were let in while nothing ran are still built one by one only while the next run's whole budget fits.
The most a day can cost is the ceiling; the most one run can cost is $1.50 (the engine's budget cap).
What is not reserved: the pre-check of scans already let in (≤ 8 waiting × $0.0002).

Scans held by a ceiling end by themselves: after 60 minutes of waiting they fail as `queue_timeout`,
refunded (or are built after midnight Europe/Prague if that comes first).

**Found**: nothing to fix. One thing to know: the refusal for a ceiling is the generic
`temporarily_unavailable` — a user is not told that it is the budget (by design: §5.6 has no code for it).

**Result: pass.**

## 5. Fault-injection suite

`vendor/bin/phpunit --group fault-injection` — every racer is a forked process with its own kernel and
database connection, released at the same instant, against a real Postgres and outside the test
transaction. 16 tests, each race repeated 4–6 times per run:

| Race | Holds whoever wins |
|---|---|
| two / three build claims (`max_running` 1; one scan) | one job, one run number |
| claim × cancel · finish × cancel | one transition; the ledger line matches the state the cancel found |
| beat × reaper at the lease's last second | extended or requeued — never both |
| reaper × 3 on a second lost lease · two tabs polling a lost lease | one refund · one restart |
| three starts in a project with one place left · the same `requestId` twice | one more scan · one scan |
| transition-level accept × 2, accept × discard, accept × purge | one of them |
| **new — a replaced run's usage**: the second claim against the first run's late beat and late finish | run 1's row holds its last figures in both orders; run 2's state, lease, ledger untouched |
| **new — `AcceptTemplateScan` × 3** (the real message: creates the template) | one template, every caller gets its id, one `created: true` |
| **new — accept × `DiscardAiScan`** | a whole template and a refused discard, or a discarded scan and no template |
| **new — accept × the purge's expiry** | accepted with its template, or expired and nothing created |
| **new — a fix (`ApplyAiScanFix`) × accept** | the fix was answered "saved" and is in the template, or was refused `wrong_state` and is nowhere |
| **new — two tabs applying fixes** (three different items; then the same item twice) | all three saved, `rev` = 3; the same item: one of the two values, `rev` grows by 2 |

The accept takes its row lock later than a purge or a fix takes theirs, so released together it always
came second; every other round the competitor now starts 80 ms into the accept. Tally of who won,
over the five runs (`RACE_TALLY=1`): a replaced run — the late finish first 22, the second claim first 8; accept × discard — 19 / 1; accept × expiry — 16 / 4; fix × accept — the accept first 12, the fix first 8; two tabs on one item — 11 / 9.

**Stable over 5 consecutive runs** in a container and database of its own: 5 of 5 green (434, 432, 427, 436, 432 assertions — the count depends on which side wins), 2 min 57 s – 4 min 49 s each on the loaded machine.

**In CI**: yes — job `fault-injection` of the Tests workflow (its own Postgres service; it commits, so
it cannot share a run with the main suite; it fails if `pcntl` is missing rather than skipping):
green on `731e4165` (the first push, `f2c3ce78`, had a YAML mistake in that job and the workflow did not parse — fixed in the next commit).

**Result: pass — 16 tests, 5 × green locally, a CI job.**

## 10. Provider error classifier through the chosen engine; the probe

**Run** (`g2a.mjs faults`): the real scanner service with the real engine binary of the Agent SDK;
its provider is the fault proxy, which answers the pre-check or the builder (or everything, the
1-token probe included) with the injected fault. Each scenario is one scan started through the upload
endpoint.

| Injected | At | Scanner: class → what it did | Requests the engine made | App state | Ledger · usage | Provider state · breaker |
|---|---|---|---|---|---|---|
| 429 `rate_limit_error` | builder | transient → retries used up | 5 (waits 1.0, 1.2, 2.5, 4.4 s) | `failed provider_unavailable` in 33 s | reserve, **refund** · row closed `failed`, $0 | ok · 1 × `provider_unavailable` |
| 529 `overloaded_error` | builder | transient → given up early | 3 (waits 0.6, 1.1 s) | `failed provider_unavailable` | reserve, refund · $0 | ok · 1 × |
| 500 `api_error` | builder | transient → retries used up | 5 (0.6, 1.2, 2.0, 4.0 s) | `failed provider_unavailable` | reserve, refund · $0 | ok · 1 × |
| 529 twice, then answers | builder | transient → retried, went on | 2 failed + 11 | **`ready`** in 34 s — the user sees nothing | reserve, settle · $0.27 | ok · 0 |
| stream that never answers | builder | the stream is cut at 60 s, the request repeated without a stream is cut at 120 s, again 120 s → the builder cap (300 s) with all of it lost to the provider | 3 | **`failed provider_unavailable`** after 312 s (was `failed timeout` — fixed, below) | reserve, refund · $0.005 | ok · 1 × |
| 400 `invalid_request_error` | builder | request → not retried | 1 | `failed build` in 12 s | reserve, refund · $0 | ok · 1 × `build` |
| refusal (`stop_reason: refusal`) | builder | refusal → one repeat on the same model, no other model asked | 2, both Opus 5.5 | `refused` | reserve, refund · $0.01 | ok · 0 (counts toward the project's 3 a day) |
| 401 on the builder, the probe answers | builder | billing / auth → **probe ok** → a failure of that scan | 5 + the probe | `failed build` | reserve, refund | **ok** · 1 × `build` |
| 402 `billing_error` on the builder and the probe | builder | billing / auth → probe fails → **`release` with `provider_blocked`** | 1 + the probe | back to `queued` (restart not used), `waiting: provider_blocked` | reserve only · row closed `released` | **blocked** · 0 |
| 401 `authentication_error` everywhere | pre-check | billing / auth → probe fails → intake `release` | 1 + probe | stays `checking`, unclaimed, `waiting: provider_blocked` | reserve only | blocked · 0 |
| 403 `permission_error` everywhere | pre-check | same (`permission`) | | same | reserve only | blocked · 0 |
| 400 "Your credit balance is too low…" everywhere | pre-check | same (`usage_limit`) | | same | reserve only | blocked · 0 |
| 429 with `enforced_spend_limit_reached` everywhere | pre-check | same (`spend_limit`) — not mistaken for a throttle | | same | reserve only | blocked · 0 |
| 529 | pre-check | transient → its one retry | 2 | `failed provider_unavailable` in 5 s (rule 3c) | reserve, refund | ok · 0 |
| refusal | pre-check | refusal | 2 | `refused` (rule 3c) | reserve, refund | ok · 0 |

**The blocked state and its auto-clear** (the same in all six blocking rows): while blocked an upload
is refused (`temporarily_unavailable`), nothing is claimed and no request reaches the provider except
the probe; the fault is then taken away and nothing else is touched. The scanner's next probe succeeds
— **278–295 s after the provider came back** (the probe runs every 300 s) — its next tick reports `ok`,
the scan is claimed again and ends `ready` with one `reserve` and one `settle`; the released run's
usage row stays closed as `released`.

Also observed: three refusals in the project within the day (two of the rows above and one from
earlier) made the next upload answer `project_paused` — the rule of §5.4. And three failures of one
class in a row would pause the feature (the breaker counts are in the last column; the runs were
separated on purpose).

**What the SDK engine actually does with a transient error** (the pinned delays 2 / 8 / 20 / 30 s are
honoured only by the Messages-loop engine): 429 and 5xx — 4 retries with a back-off of about
0.5 · 2ⁿ s (≈ 9 s of waiting in all); 529 — 2 retries, then it gives up; 401 — retried like a 5xx
before it is reported. A provider outage longer than ~10 s therefore fails a scan as
`provider_unavailable` (refunded; the user retries) where the plan's 60 s of waiting would have ridden
it out. Accepted as it is: nothing is charged and the retry is one click — but it is a known
difference from §4.9, now written there.

**Found and fixed**:

- A provider that never answers ended the scan as `failed timeout` — counted against the picture (two
  such and it is `source_unscannable`) — because provider-lost time counted retry *delays* only, and a
  request cut by the cap had not failed yet. The time of a failed request and the wait of a request the
  provider never answered now count (`engine/sdk.mjs`, `run.mjs`; tests "the builder cap cuts a request
  the provider never answered…", "a stream that stalls every time…").
- The fault proxy's stall did not stall a request without a stream, which the engine falls back to
  (`eval/fake-provider.mjs`).

Not covered here: a **real** provider's error responses (the proxy's are shaped after the API's
documented ones) — the first real 429 / 529 in production should be read in the scanner log against
this table; and the real-model refusal, which could not be provoked.

**Result: pass.**

## 11. Soak, queue, a scan after idling

All three against the app **as it runs in production** (`APP_ENV=prod`, FrankenPHP worker mode,
memory limit 1 g like the box's `web`) — the first attempts ran against a development-mode app, where
a status request costs 100 × as much, and measured the debug toolbar rather than the feature.

### Soak — 100 scans back to back

**Run** (`g2a.mjs soak --scans 100`): one scanner process for the whole run (concurrency 1), five
pictures in rotation — three social formats, an A4 flyer with 43 text lines, an A3 page (2480 × 3508,
the largest canvas of the sets) — each scan uploaded through the web endpoint, pre-checked, built from
its recording (1 060 builder requests in all, real OCR, real `faces` and `render` calls to a Gotenberg
of its own), finished and read back. Memory sampled after every scan; the scanner's process tree every
1.5 s during it. 61 minutes.

| | Result |
|---|---|
| Outcomes | **100 × `ready`**; each scan 8–104 s |
| Per scan in the app | two usage rows, both closed (`pre_check#1 passed`, `builder#1 proposed`); one `reserve`, one `settle`; the scan's totals = the sums of its rows — all 100 |
| Recorded cost against the engine's own figure | **$26.524 = $26.524** (1-hour cache writes in every turn) |
| Scanner, resident memory between scans (the one long-lived process) | 96 → 97 → 99 → 99 MB by quarter; min 87, max 110; slope **+0.02 MB per scan**, −0.02 over the last 50 — no trend |
| Scanner, peak of the whole process tree during a scan | 633–984 MB for the four smaller formats, **1 332–1 617 MB for the A3 page** (no trend within a format); the 4 g limit of the deployment holds it at 40 % |
| Scanner processes left after a scan | **1** (the service itself) after every one of the 100 — no orphan engine, OCR sidecar or run process |
| Scratch directory entries after a scan | **0** after every one |
| `web` (worker mode), anonymous memory | 293 → 300 → 307 → 300 MB by quarter; max 309; slope +0.12 MB per scan over the whole run, **−0.19 over the last 50** — it rose while the 16 workers warmed up and then stopped. 30 % of its 1 g limit |
| Gotenberg, anonymous memory | 187 → 204 → 210 → 215 MB by quarter; max 244; slope +0.36 MB per scan, +0.11 over the last 50 — still creeping, slowly (Chromium). **To watch on the box** (G2b item 4, "Gotenberg watched for a week") |
| Private bucket afterwards | every one of the 100 scans holds exactly `original`, `working`, `preview` and its **one kept** candidate render; no object without a row |
| Rows afterwards | none `checking` / `queued` / `running`; no open usage row; no try without exactly one closing ledger line |
| Stop | SIGTERM → exit 0 in 6 ms (idle), scratch root removed |

The only things left behind are the 164 queued e-mail messages (`messenger_messages`): this stack runs
no consumer; in production the consumer sends or drops them (seen scans send nothing).

**Found and fixed** — in the two-scan smoke run before the soak: a `ready` scan kept **every**
candidate render until its expiry 30 days later (§5.3 says only the kept one outlives the run), and a
restarted or retried run inherited the earlier run's files. Candidates other than the kept one are now
dropped at `finish`, an earlier run's at the next build claim, and what a run left without a finish (a
cancel, a lost lease, mode `off`) by the nightly purge (`AiScanStorage::dropCandidates`;
`ScannerApiTest::testOnlyTheKeptCandidateRenderOutlivesItsRun`,
`AiScanPurgeAndStorageTest::testThePurgeDropsCandidateRendersARunLeftBehindWhenItEndedWithoutAFinish`).

One caveat about the soak's code: a run's process loads the scanner's code when it starts, and four
of the scanner fixes of this report were written while the soak ran — its later scans ran on them. The
service process (lanes, heartbeat, claim) was the same from scan 1 to 100.

### Queue — 8 waiting, a poison input, cancels in both phases, 20 tabs polling

**Run** (`g2a.mjs queue`, three executions; the last one on a quiet stack is quoted): ten uploads over
two projects with no scanner claiming, then a scanner (model turns slowed to 2.5 s so that scans last),
twenty logged-in sessions polling `GET /ai-scan/{id}` every 2 s each for the whole test.

| Step | Observed |
|---|---|
| 10 uploads, nothing claiming | 8 accepted (4 per project), the 9th and 10th and a fifth in the first project refused: HTTP 429 `project_queue_full`; 8 scans waiting — the total cap |
| The last one in | `checking`, position 7, estimate start 7–17 min, finish 8–20 min (from the durations of the last 50 runs in that database) |
| Cancel while waiting | `cancelled` at once; `reserve`, **`refund`** |
| Cancel while running | `cancelled` at once; `reserve`, **`settle`** (counted); usage row closed `cancelled` with $0.1753; the scanner stopped the run after its next heartbeat (11.8 s in the second execution) |
| Poison input — the run's process killed (`SIGKILL`) twice | first kill: the scanner sees `crashed`, sends its closing beat, does **not** release; 121 s later the app requeues (`restarted`). Second kill: 121 s later `failed interrupted`, **`refund`**, `failure_count` 1 (a second failure of the same picture within 30 days → `source_unscannable`); usage rows `builder#1 lease_lost $0.1427`, `builder#2 lease_lost $0.1239`; one scanner process left each time |
| The queue behind it | drained in 473 s: 5 `ready`, 2 `cancelled`, 1 `failed` — nothing stuck behind the poison scan |
| 20 tabs polling | **4 680 polls, 0 errors; p50 17 ms, p95 32 ms, p99 44 ms, max 510 ms**; `web` anonymous memory 276 → 299 MB |
| Afterwards | no scan in progress, no open usage row, scratch empty, one scanner process |

The estimate is computed on every one of those polls (§4.9 said "cached 15 s"; it is not, and at
17 ms a poll it does not need to be).

In the second execution 215 of 4 899 polls failed, all within one half-minute: five PHP workers hit
the 30 s execution limit waiting for the shared development Postgres and MinIO, which at that moment
also served two other stacks and the forked fault-injection suite. It did not happen in the first
execution (1 115 polls) or the third (4 680), and the scans were unaffected. Recorded here because a
stalled database does this to status polls: a poll that fails is repeated two seconds later.

### A scan after idling

**Run** (`g2a.mjs idle --minutes 45 --real`, real model): a real scan, then the scanner left alone
for 45 minutes with nothing to claim, then a real scan. A 24-hour idle period was **not** waited for;
what 24 hours could break and what was done about each:

| What could go stale | Observed over 45 min idle / why 24 h is no different |
|---|---|
| The scanner process | resident memory 82 → 91 MB (max 103), one process, scratch empty, sampled every minute; 0 failed ticks, 0 warnings, 0 errors in its log; the app saw it 6 s before the end (it polls every 5 s, so in 24 h it makes ~35 000 claim polls and ~2 900 ticks — 45 min is 540 and 90 of them, all answered) |
| Connections | the scanner holds none: every app call is its own HTTP request; the app in worker mode reconnects its database connection per request cycle |
| The provider credential | the plan's token was refreshed by the engine binary as needed (the first scan of this session ran hours after the previous one); a production API key does not expire |
| Claim delay after idling | the scan was claimed **2.1 s** after its upload |
| The scan after | `ready` in 60.7 s; `pre_check#1 passed`, `builder#1 proposed`; one `reserve`, one `settle`; **recorded $0.2514 = the engine's $0.2514** |

What is left for the box (G2b): the same after a real night — container page cache evicted, a
blue-green app deploy in between — which the ten production scans of P4 cover by themselves.

**Result: pass** (the 24-hour idle is simulated — see above; Gotenberg's slow growth is handed to G2b).

## 12. Runbook

`docs/ai-scan/runbook.md`: the one command and how to read it; kill switch; stuck queue; stuck scan;
spend spike; provider blocked; scanner too old; scanner down; rollback per phase with the fate of
`checking` / `queued` / `running` / `ready` rows; the nightly purge; what is not built.

The status command could show the runtime row and counts only — nothing about a single scan, and
nothing could end one by hand. Added for the runbook (commit `f2c3ce78`, tested in
`AiScanAdminSwitchAndLogsTest::testTheStatusCommandListsScansShowsOneWithItsSpendAndCancelsOne`):
`--scans` (every scan in progress, the last 15 ended), `--scan=<id>` (history, usage rows, ledger),
`--cancel=<id>` (the user's own cancel).

The kill switch was tried (`g2a.mjs modes`, production-mode app, scanner running, slow model turns):

| Switch | A `running` scan | A `queued` scan | Uploads | Scanner |
|---|---|---|---|---|
| `--mode=paused` | finished and was delivered (`ready`, settled) 22 s later | stayed `queued`, `waiting: paused`; claimed and delivered after `--mode=on` | 503 `temporarily_unavailable` | no claim while paused |
| `--mode=off` | `failed interrupted`, refunded, its usage row closed with $0.1954 | `failed interrupted`, refunded | 503 | the last provider request left 1.1 s after the switch |

A `ready` proposal could still be discarded while the mode was `off`.

**Result: pass** — with what the runbook itself lists as not built (operating e-mails to admins, the
admin tab).

## Found and fixed

| # | Found by | What | Fix |
|---|---|---|---|
| 1 | item 1 | three definition numbers had two names; the app's test pinned the unread ones | one name each, kill grace in the definition, both sides' tests |
| 2 | item 1 | the scanner's transport timing was not tied to the definition | a scanner test holds them equal |
| 3 | item 1 | a busy renderer followed by an outage opened a second tolerance window | one window (`startBy`) |
| 4 | item 1 | the estimate read a kind's durations once per scan ahead | once per estimate |
| 5 | item 2 | the engine binary outlived a killed scanner's run by 2–3 s | the run's process ends its own tree |
| 6 | item 10 | a provider that never answers → `failed timeout`, counted against the picture | failed and unanswered requests count as provider-lost → `provider_unavailable` |
| 7 | item 11 (loaded machine) | a render the app could not answer within the tolerance → `failed build`, counted against the picture and toward the breaker | `renderer_unavailable` (refunded, not counted) |
| 8 | item 11 | candidate renders that were not kept stayed in the bucket for 30 days | dropped at finish, at the next claim, by the purge |
| 9 | follow-up 1 | heartbeat usage lost the cache-write split on its way to the main process | `wireUsage` carries it |
| 10 | item 12 | nothing showed or ended a single scan from the console | `status --scans / --scan / --cancel` |
| 11 | running the suite on a loaded machine | `set_time_limit(600)` of the document export outlived its call on the command line and killed the test process 10 minutes later | not set under the CLI |

All in commit `f2c3ce78` (the workflow fix `731e4165`; CI on it: Tests — tests, phpstan, fault-injection,
migrations — and Scanner green).

## Open before the scanner goes onto the box — for P4 / G2b, none blocks starting P4

1. **No operating e-mail to admins exists** (provider blocked, circuit breaker, ceilings at 80 % /
   100 %, oldest waiting > 15 min, scanner too old, project paused). §7 lists them; nothing sends
   them. Until then a blocked provider or a paused feature is seen only by whoever runs
   `app:ai-scan:status` (or by users, as "Sken čeká"). **This should exist before admins rely on the
   feature** — it is G2b item 7 and the one item here that is real work.
2. **The admin tab** (P4) — the status command stands in.
3. **Gotenberg's memory** crept from 187 to 215 MB over 100 scans and had not stopped; on the box it is
   shared with exports and has a limit — G2b item 4 and the week of watching in P4's acceptance.
4. **The SDK engine's own retry back-off** (≈ 9 s in all, 2 retries on 529) is shorter than the plan's
   60 s: a provider blip of more than ~10 s fails the scan (refunded, one click to retry). Accepted for
   P4; if production shows it often, the options are the Messages-loop engine or a scanner-level
   re-run — a decision, not a defect.
5. **A real provider's error bodies** and a real refusal were not seen (the proxy's are shaped after
   the documented ones) — read the first ones in production against item 10.
6. **Everything measured here is macOS / arm64** with the app in a local container: limits, the
   network boundary, x86 timings, the hostile uploads through the real upload and the deploy-mid-scan
   cases are G2b items 4, 6–9.
7. Not a defect, to know: a ceiling refusal is the generic `temporarily_unavailable`; a killed run's
   row lacks what it spent in its last ≤ 15 s.

## What ran where

| Check | Where |
|---|---|
| Full PHPUnit suite, PHPStan (max), migrations-up-to-date, the scanner's 220-odd tests and image self-test | **CI only** (Tests and Scanner workflows on `f2c3ce78` / `731e4165`) — the machine was too loaded to run them locally to the end |
| The narrow PHPUnit tests of every changed class (79 tests), the scanner tests of every changed module | locally, before each push |
| The fault-injection group | locally 5 × in a row (16 tests each, all green, 3–5 min) **and** in CI |
| Everything in items 2, 3, 10, 11, 12 | locally only — CI has no scanner, no engine binary and no Gotenberg |


## G2b — on the box (2026-11-13, phase P4): the first part

The scanner was put onto the production box on 2026-11-13 (infra repo `~/www/lily.srv`: commit
`b30b33c`, decision D87, journal entry of that day). What was checked there, with the operating mode
`on · admins only`, on five scans of a **synthetic test picture** (1080 × 1350, no customer material)
in the owner's own project, started through the public web by a temporary admin and discarded
afterwards. Scanner `0.2.0+4eb593e4ed25`, definition `5-bf02f6ce3237`, x86-64, production API key.

| Check | Observed | |
|---|---|---|
| Limits applied (`docker inspect`) | memory 4 GiB, reservation 512 MiB, 3 CPUs, 512 pids, read-only root, no capabilities, `no-new-privileges`, stop timeout 45 s, restart `always`; PID 1 `tini`; network `wboost_scan` only | pass |
| Heartbeat | `app:ai-scan:status`: `Scanner seen N s ago (version 0.2.0+4eb593e4ed25)`, N ≤ 8; container healthy 17 s after start | pass |
| Network boundary | the scanner resolves `web` only — not `db`, `redis`, `minio`, `gotenberg` nor their container names; a direct connect to the database's address times out; the provider is reachable | pass |
| `/internal/scanner/*` from outside | internet: 403 (edge rule; 404 before); another stack's container on the box: 404; `web` on its other network with the right token: 404; scan network with a wrong token: 404 | pass |
| One undisturbed scan — x86 figures | `ready` 36 s after upload (intake 4 s, build 32 s); **$0.169** recorded = the engine's figure (Haiku pre-check $0.0004, Opus build $0.168, 6 turns, 2 candidates); scanner cgroup **peak 561 MiB**; 13.9 s CPU, 5 throttled periods (1.5 s) under 3 CPUs; credit reserved and settled once | pass — an easy picture; a hard design peaked at 1.8 GiB locally |
| Scanner process killed while idle (host `kill -9`) | restarted by the policy; healthy and seen by the app after 7 s | pass |
| Scanner process killed mid-build | container back after 1 s; the scan stayed `running` until its lease lapsed, then `build_lease_lost → queued (restarted)` 121 s after the kill, claimed 1 s later, `ready` 34 s after that. Killed run: usage row closed `lease_lost` with $0.017 (its last heartbeat); second run $0.112; open usage rows 0; one settle | pass |
| `docker compose restart scanner` mid-build (= a tag bump) | shutdown 2.6 s (`run aborted`, one release); the scan re-claimed 3 s later, restart not consumed, `ready` 36 s later | pass |
| App deploy with the scanner idle, and with a scan running | blue-green `web` replaced under the scanner (23 s); the scanner container was not touched (`StartedAt` unchanged); the running scan finished `ready` on its first try, no `restarted` | pass |
| The box | the 132 containers of other projects kept their start times and restart counts; load 3.3 on 16 cores; nothing unhealthy | pass |
| Spend | $0.64 for the five scans; Gotenberg 500 → 681 MiB of 5 GiB | — |

Found on the way:

- **`docker kill` is not a crash.** Docker treats it as a manual stop and does not apply the restart
  policy — the scanner stayed down for three minutes during the first try (mode was `off`). A crash is
  simulated with a `kill -9` of the container's process from the host; the runbook says so.
- **`app:ai-scan:status` said `Private bucket reachable` before the bucket existed** — a HEAD of an
  object in a missing bucket is an ordinary 404. `AiScanStorage::reachable()` now lists the prefix,
  which fails on a missing bucket (checked locally against a bucket that does not exist).
- `app:ai-scan:fake-run` refuses in production by design, so "mid-run" was tested with real scans of
  the synthetic picture.

**Still open of G2b after the first part** — most of it was done later the same day, see the next
section; what stays open is listed at its end.

## G2b — on the box, the second part (2026-11-13, "fill the gaps")

Same box, same rules: only the container `wboost-scanner-1` was killed, constrained or disconnected;
`web`, the database, Redis, MinIO, Gotenberg and every other project's container were left alone
(the 136 other containers have the same start times and restart counts before and after). Four scans
of **synthetic posters** drawn for the purpose (1080 × 1350, no customer material) in the owner's
internal project, started through the public web by a temporary admin (deleted afterwards), all
discarded. Scanner `0.2.0+7e7e78bc8b4a`, definition `6-142dae97f82b`, mode
`on · admins + editors of entitled projects`, no other scan in progress at any of the tests.

| Check | Observed | |
|---|---|---|
| Scanner cut from its network for **30 s** mid-build (`docker network disconnect wboost_scan wboost-scanner-1`, then `connect`) | two beats and one poll failed (`network`), the engine's run went on; `ready` on its **first try** 88 s after the claim (≈ 35 s undisturbed), $0.215; container not restarted, same address after the reconnect | pass |
| Cut for **150 s** mid-build | app: `scanner_absent` from 60 s (entry card `service_waiting`, `canStart: false`; the running scan's status document unchanged — stage `read`, no waiting reason); scanner: `run aborted · app_unreachable` at 105 s after the last acknowledged beat; lease over at 120 s; the row stayed `running` with a lease in the past until the scanner came back (nothing else reads it), then at the reconnect `build_lease_lost → queued (restarted)` and `build_claim` in the same second, `ready` 40 s later. Usage: run 1 `lease_lost` $0.022, run 2 $0.138; open usage rows 0; one reserve, one settle | pass |
| **Out of memory** mid-build: `docker update --memory 300m --memory-swap 300m` with 369 MiB in use (scanner 137 MiB, the run's worker 240 MiB, the engine 202 MiB), limit put back to 4 GiB / 8 GiB after 20 s | docker events: `oom`, `die 137`, then the policy restarted it 7 times with `die 78` — the scanner refuses to start under 3 GiB ("needs a memory limit of 3 GiB, this container has 0.29 GiB") — and once more 11 s after the limit was restored: started, healthy 6 s later, restart count 8. The scan: `running` until its lease ended, `build_lease_lost → queued (restarted)` 125 s after the claim, claimed in the same second, `ready` 48 s later. Usage: run 1 `lease_lost` $0.00 (killed 13 s into the run, before its first heartbeat carried figures), run 2 $0.168; open usage rows 0; one settle. `docker inspect` afterwards: memory 4 294 967 296, swap 8 589 934 592, reservation 536 870 912 — as declared | pass |
| **`docker kill`** (the trap of the first part) | idle scanner killed 3 s after a cron tick: `exited (137)`, started by cron `scanner-revive` 57 s later (`scanner-revive: started wboost-scanner-1 (was exited with code 137)`), healthy 6 s after that, seen by the app. A clean `docker compose stop` exits 0 and is left alone (checked: stopped across a cron tick, still `exited (0)`; with `.scanner-hold` present the script does nothing either) | pass — closed by infra `scanner-revive.sh` + cron |
| **`SCANNER_TOKEN` rotation** through `SCANNER_TOKEN_PREVIOUS` | 1. previous := current, a new token minted on the box into tmpfs and stored (no value shown); 2. `./deploy.sh main` with a scan in its build: `web` 401 → 402 now accepts `new,previous` (fingerprints compared, not values), worker re-created, scanner re-created last with the new token — the scan was released (`build_release → queued`, 26 s in, $0.061 recorded for the released run), claimed by the new container one second later and `ready` 69 s after that; 3. `SCANNER_TOKEN_PREVIOUS` deleted, the next deploy (the app's own CI deploy, `web` → 403): `web` accepts the new token only, the scanner container was not touched and is seen. **No failed scan, no refused call in the scanner's log** | pass |
| Provider key out of `.env` | the same deploy: `.env` 12 keys, none of them the provider key; `.env.scanner` (root, 0600) 1 line; the scanner holds `ANTHROPIC_API_KEY` and `SCANNER_TOKEN` and no other credential; `web`'s and the worker's provider key empty; provider `ok`, the scan above built with it | pass |
| The app's dependencies failing, seen from the scanner (renderer, storage) | **not run on the box**: the scanner cannot reach Gotenberg, MinIO, Redis or the database at all, and the only way to make a render or a file read fail is to break those production services or to use `app:ai-scan:fake-run`, which refuses in production; there is no admin fault hook. Covered locally (items 1 and 11: `renderer_busy` / `renderer_unavailable` / `storage_unavailable`, the 60 s tolerance, fix 7) and by the two network cuts above, which are the scanner's view of "the app does not answer" | not run, by the rule of the box |
| Host reboot mid-scan | **not run, by decision** (a shared box). What would happen is written in the runbook ("A host reboot") from the restart policies (`always` on all nine containers, Docker enabled at boot) and the lease recovery measured above | documented |
| Gotenberg's memory | 12 days of the box's own Prometheus series, the container up since 2026-11-01 and the ten scans of the checks in: working set 694–727 MiB at the time of the tests (14 % of 5 GiB; ≈ 205 MiB anonymous, 160 MiB tmpfs, the rest page cache); daily minimum 450–675 MiB, daily maximum 649–1 151 MiB, daily minimum of the anonymous part 132–188 MiB with no trend. The existing alerts (`ContainerMemoryNearLimit`, `…Critical`, `…LeakPredicted`, `HostOOMKill`) cover it; the runbook has the one command that prints the series ("Gotenberg's memory") | no creep visible; the week of real use is still to come |
| The box | the 136 containers of other projects: same `StartedAt` and restart counts before and after; nothing unhealthy | pass |
| Spend | **$0.75** for the four scans ($0.215, $0.161, $0.168, $0.204) | — |

Found on the way:

- **A lease that ended is noticed only when somebody reads the row or a scanner polls.** With the
  scanner away and nobody looking, the row says `running` with a lease in the past (150 s cut: 45 s).
  Harmless — the next claim poll, the scanner's 30 s tick or the user's status read reaps it — but
  `status --scans` can show it; `status --reap` clears it.
- **An OOM under a limit that stays too low is a restart loop** (exit 78, Docker's back-off), not a
  scan loop: the scanner never claims under 3 GiB. It ends by itself when the limit is right again.
- **A run killed before its first heartbeat records $0** although the engine had begun (the report's
  item 2 says "≤ 15 s of spend may be missing" — this is that case at its extreme).
- The app's alert for a lost scanner (3 min) did not go out: every outage here was kept under it.
  The box's own `HostOOMKill` alert did page for the forced OOM (17:12; the per-container alert stayed
  silent because the kill took PID 1 — the infra repo's D89 was written from it the same evening).

**The release pipeline** (the gap "a commit can reach production before its own Tests run"):
confirmed — in a `workflow_run` event `github.sha` is the head of `main` when the event fires, and
the shared ship workflow checked out and built that (run 43340365916 was started by the Tests of
`ab97581f` and built `93f8e982`, whose own Tests then failed). Fixed with an optional `ref` input in
`TheDevs-cz/ci` (`_ship.yml` / `_build.yml` / `_deploy.yml`, default = the old behaviour for every
other project) and in `release.yml`: the tested commit is what is built, shipping runs are
serialised, a commit older than the shipped one is refused, the last shipped commit is read from the
run's title. Proved by real pushes the same evening:

| Release run | `main` when it fired | Tests run that fired it | Result |
|---|---|---|---|
| 43460370562 | `dd2a0c47` (the workflow change itself, its Tests still waiting) | `18164616`, docs only | preflight: nothing to ship — before the fix `dd2a0c47` would have been built and shipped untested |
| 43462340587 | `dd2a0c47` | `dd2a0c47` | built and shipped `dd2a0c47` (through the shared workflow's branch) |
| 43464919181 | `6c67c5f4` | `405e30e8` (two commits behind `main`) | built `405e30e8` through `TheDevs-cz/ci@v1`; on the box `wboost-web-404` carries `org.opencontainers.image.revision = 405e30e8…` and its image is the local `:main` |

The default path of the shared workflow (no `ref` — every other project) was run once in that
repository before `v1` was moved: same tags as before (`sha-<event commit>`, the branch).
The "older than shipped" refusal was checked against the real history (`dd2a0c47` after `405e30e8`:
refused) but not by a live run. Re-running the two Release runs of 16:12 and 16:24 UTC is not possible
(they point at the shared repository's test branch, deleted since) — re-run any later one instead.

**A hard design on x86** (2026-11-13 evening, after the scanner bump to `sha-fcf402c9`, definition
`8-0c3435ed1918`; infra journal of that hour). Two scans of SYNTHETIC inputs in the owner's project
`Mikeš`, started through the public web by a temporary admin (deleted afterwards), both discarded:

| Scan | Outcome | whole scan | recorded cost | scanner cgroup |
|---|---|---|---|---|
| the dense A4 flyer of the hard set (`B3-letak-a4`, 1240 × 1754 px — authored for the evaluation; 40 texts, 3 places, 9 shapes in the proposal) | `ready`, 2 candidates, 6 verification renders | **110 s** (intake 4 s, build 103 s) | **$0.482** (pre-check $0.0005, build $0.481) | `memory.peak` **699 MiB** of 4 GiB — 616 MiB of it is the self-test at the container's start, so the scan added ≈ 80 MiB to the high-water mark; **29.0 s CPU**, 11 throttled periods (0.2 s) under 3 CPUs; no OOM kill |
| a calendar page (a render of the app's test set, 1600 × 748 px) | `ready`, 2 candidates | 54 s (build 48 s) | $0.224 | peak unchanged; 15.7 s CPU |

Read against the local figures: the same flyer took 112 s and $0.546 through the local app on the plan
(`scanner/eval/RESULTS.md` §10.7) and peaked at 876 MB in the image under Rosetta (§0.5) — the box is
neither slower nor hungrier. The largest canvas of the sets (A3, 2480 × 3508 px, 1.8 GiB under Rosetta)
is client material and was not run on production; the 4 GiB limit therefore still rests on the emulated
figure for that size. No provider retry occurred. `Mikeš` had used its 10 scans of the month: its limit
was raised to 12 for these two and put back to 10 (the infra journal says so; the project stands at 12 of
10 until December or until the owner raises the limit).

**Still open of G2b:** a real host reboot (by decision); Gotenberg / MinIO / Redis stopped or paused
and a `db` restart (production services — not to be broken for a test); a slow migration during a
deploy mid-scan; a calendar PDF export during a scan; the hostile uploads through the real upload;
the LARGEST canvas (A3 at 300 dpi) on x86 — a hard design of ordinary size is measured above; Gotenberg
read again after a week of real use; billed cost against recorded usage after the first admin scans
(twelve scans exist, all of them checks, $2.24 recorded — the provider's own report was not read).
