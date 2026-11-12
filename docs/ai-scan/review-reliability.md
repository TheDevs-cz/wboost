# AI sken — reliability review of `architecture.md` (rev. 2) and the failure states of `ux.md`

2026-11-12. Scope: will this be a reliable production service. Not reviewed: output quality, model
choice, UX copy. Read: `architecture.md` (all), `ux.md` S2, S3, §2.4–2.6, §4–6, `digest-platform.md`,
`digest-infra.md`, `research-providers.md` §1.2, §1.5, §3.2; app code (`CalendarExport*`,
`config/packages/{messenger,sentry,rate_limiter}.php`, `sensiolabs_gotenberg.yaml`,
`NormalizeImageFormat`, `TemplateVariantImageRenderer`, `CompilationContextFactory`); infra repo
(`apps/wboost/compose.yaml`, `deploy.sh`, `cron.d/wboost`, `deploy/lib.sh`, Traefik middlewares).
Provider facts were re-read today on the official pages (URLs in the findings). Nothing was run;
the box was not touched.

Abbreviations: A = `docs/ai-scan/architecture.md`, UX = `docs/ai-scan/ux.md`, DP / DI = the two
digests, INF = `~/www/lily.srv`. "Lease", "attempt", "event", "finish" as in A §4.5.

**Counts: 4 blockers, 19 majors, 17 minors.**

---

## 1. Blockers

### B1. The lease is extended only by progress events, and normal silences are longer than the lease

- **Location:** A §4.9 (`Lease | 60 s, extended by every event`, line 433), A §4.5 `POST {id}/events`
  (line 326), A §7 "App deploy mid-scan … retries app calls for 60 s" (line 635).
- **Evidence:** events carry `stage, note code` — they are sent when a tool runs. UX S3 line 413:
  "between notes only the elapsed time moves". No timer heartbeat is specified anywhere. Silences
  that occur in a healthy scan:
  - one model turn that writes a whole design at effort `high` (thinking + several thousand output
    tokens) — no tool call until it ends;
  - provider retries inside the engine: the Claude Code harness "retries transient failures up to
    10 times with exponential backoff", 529 backoff up to 32 s per retry, each attempt may wait
    `API_TIMEOUT_MS` = 600 s for headers (<https://code.claude.com/docs/en/errors.md>,
    <https://code.claude.com/docs/en/agent-sdk/typescript>); the design's own backoff ends with a
    30 s wait (A line 628);
  - a Gotenberg render: client timeout 20 s / 25 s (`config/packages/sensiolabs_gotenberg.yaml:22-23`),
    "retried 3×" (A line 636) = up to 75 s;
  - an app outage: the scanner retries for 60 s — exactly the lease length, so any event older than
    0 s at the start of the outage already loses.
- **Scenario:** scan running, last event at t = 0; Opus writes the first design for 70 s. At t = 60
  the browser's 2 s poll runs the lost-lease check (A line 539) and moves the scan to `queued`,
  attempt 1 used. At t = 70 the scanner calls `render` with attempt 1 → refused. The scanner aborts,
  claims the same scan again, the same turn is slow again → `failed interrupted`, F7, refund. The
  provider was paid twice, nothing was recorded (B2), and it repeats for every input of that
  complexity. The same happens on every app deploy that leaves the app unreachable for more than
  (60 s − age of the last event).
- **Change:** (1) heartbeat on a timer in the scanner's main loop, every 15 s, independent of the
  agent (`POST events` with `stage` unchanged, carrying cumulative usage — see B2); (2) lease =
  heartbeat × 4 + the app-outage retry window, e.g. 15 s / 150 s, and the app-call retry window
  shorter than the lease (60 s is fine then); (3) the hang guard is the scanner's own attempt
  deadline (360 s), not the lease; (4) per-tool timeouts (SDK `createSdkMcpServer({timeout})`,
  OCR subprocess, app calls) so a hung tool ends as a tool error, not as silence; (5) a `finish`
  or `render` that arrives after expiry is accepted when the scan is `queued` by requeue, the
  attempt number is still the latest and nobody claimed it (see M7).

### B2. Provider spend is recorded only by `finish`; every path without a `finish` records nothing

- **Location:** A §4.5 `finish … usage` (line 329), A §5.1 `ai_usage_event` (line 501), A §4.9
  "daily USD ceiling" (line 436), A §6 "Cost runaway" (line 612), P6 launch gate "billed cost"
  (line 852).
- **Evidence:** `usage` is a field of `finish` only; `events` carry `{attempt, seq, stage, note
  code, round?}`. Paths that end without a `finish`: scanner killed / OOM / recreated, lease lost
  (B1), box reboot, engine aborted at the wall-clock cap (whether an aborted SDK query yields a
  `result` message with usage is not documented on the pages read — §6 below), cancel (the flag
  returns "on the next event; engine aborted", A line 638 — no `finish` is specified for it),
  project deleted mid-scan (row gone, `finish` → 404), MinIO or app error on `finish` itself.
- **Scenario:** an upload that makes the OCR process exceed the memory limit (M14) is scanned,
  the pre-check and 20 agent turns are paid ($0.30), the container is OOM-killed, attempt 2 does the
  same, the scan fails with a refund. `ai_usage_event` has no row; "Dnes utraceno" shows $0; the
  daily ceiling never moves. Combined with B3 the ceiling can be passed many times over while the
  admin table shows nothing. The P6 gate compares "billed cost" against rows that omit exactly the
  failed attempts — the 10 % failure overhead of A §8.3 can never be checked.
- **Change:** the scanner accumulates token counts per assistant message itself and sends the
  cumulative figures with every heartbeat; the app upserts one usage row per `(scan, attempt,
  step)` on every heartbeat and closes it on `finish`, lease loss, cancel or purge with the last
  figures and an `outcome`. The daily ceiling is evaluated on recorded spend **plus** the caps of
  everything in flight (`running × $1.50`). A weekly reconciliation of `ai_usage_event` against the
  provider's usage report for the dedicated workspace is part of operations, with a tolerance.

### B3. Failures, cancels and stops are free and unlimited, so the only spend limit a user meets is the global one

- **Location:** A §5.1 ledger (line 502: "refund +1 on failure, refusal, cancel, pre-check stop"),
  A §5.2 `failed → queued: „Zkusit znovu“ (new reserve)`, UX S3 "A cancelled scan is never counted"
  (line 469), UX §5 `retry`, `cancel` (lines 1641-1642), A §4.9 caps ($1.50 per attempt, 2 attempts).
- **Evidence:** the monthly limit counts "reserves minus refunds" (A line 505). A scan that fails,
  is cancelled or is stopped gives the credit back, and nothing else counts those attempts.
- **Scenario 1 (person):** entitled editor, limit 10. Start a scan, wait 4 minutes, press
  `Zrušit sken`; repeat. Each cycle costs up to $1.50 of provider spend and 0 credits. 30 cycles =
  $45, limit untouched. **Scenario 2 (agent):** an MCP client told to "scan this" loops
  `start → status(failed) → retry` on a picture that always hits the turn cap with no candidate
  (`failed timeout`, refund); 2 attempts × $1.50 per loop, at machine speed, until the daily ceiling
  stops every customer. **Scenario 3 (poison):** B2's OOM picture retried by a puzzled user ten
  times: 20 OOM kills, `ContainerOOMKilled` pages, zero credits used.
- **Change:** a second counter that refunds do not touch: started scans per project and per user
  per day (e.g. 3 × the monthly limit ÷ 30, minimum 5), and a per-project daily USD budget on
  recorded spend (B2). After 2 non-delivered scans of the same source hash, `retry` is refused with
  a code ("this picture cannot be scanned") — web and MCP. `retry` over MCP returns
  `retriesLeft`. The global ceiling stays as the last line, not the first.

### B4. The pixel work lands in the `web` container (1 GiB, one replica, the whole app), with no bound on working resolution

- **Location:** A §4.5 `render`, `faces` (lines 327-328), A §4.6 `FitTextToWidth` "reads each
  line's ink width with Imagick" (line 348), A §4.9 "Source ≤ 10 000 000 bytes, ≤ 60 Mpx" (line 434),
  A §6 "60 Mpx by header before decoding, `NormalizeImageFormat`" (line 606), A §8.1 (measures the
  scanner and Gotenberg only), P0 build step 11.
- **Evidence:** `web` has `mem_limit: 1g` (INF `apps/wboost/compose.yaml:59`), `REPLICAS=1`
  (`deploy.sh:12`), measured 377 MiB idle (DP §5). DP line 223: "The **web** container must not do
  any of this work in-request: it has 1 GiB and serves everyone." No global ImageMagick limit
  exists (only two local `setResourceLimit` blocks in `src/Services/Print/`). DP §5: a 60 Mpx RGBA
  bitmap is ≈ 240 MB at 8 bit, ≈ 480 MB at Q16. The canvas cap is 10 000 × 10 000
  (`TemplateVariantImageRenderer.php:166`). HEIC cannot be sized from its header in PHP
  (`NormalizeImageFormat.php:43-46`: "reports the tile size rather than the picture's"), so "by
  header before decoding" does not hold for the one format that is always transcoded in `web`.
  Nothing in A says the source is downscaled before it becomes the template's canvas, the candidate
  render, or the input of the ink-box measurement.
- **Scenario:** an 8000 × 7500 px JPEG (9 MB, legal). Upload: `source-preview.webp` is made in the
  web request — one 480 MB decode. Scan: three candidates rendered at 60 Mpx through the shared
  Gotenberg (5 GiB, six Chromium slots shared with every user's export — INF `compose.yaml:316-326`),
  each PNG decoded again in `web` for per-element ink boxes. `web` crosses 1 GiB, is OOM-killed,
  `wboost.cz` is down until the restart, FrankenPHP worker state is lost, `ContainerOOMKilled`
  pages. At concurrency 2 two such requests overlap. A HEIC with a large grid does it at upload.
- **Change:** (1) one ingest step, outside `web`'s request if possible, that decodes once under
  explicit Imagick limits (memory, map, area, disk, time) and stores a **normalised working copy**
  (sRGB, 8 bit, first frame, EXIF-upright, long edge ≤ e.g. 4096 px, ≤ 16 Mpx); everything after
  uses the copy; the original is kept only for the viewer. (2) Candidate renders have a pixel cap
  independent of the template's output size (render at a scale factor; A4 at 150 dpi). (3) Imagick
  limits set for the scanner-API request paths. (4) P0 item 11 and the P4 box tests measure **web**
  RSS during `render` / `faces` / upload, with the largest legal input, at concurrency 1 and 2.
  (5) Decide on numbers whether `/internal/scanner/*` is served by a second instance of the app
  image with its own `mem_limit` (reachable only on the `scan` network) — it removes the coupling
  to the public site and to blue-green.

---

## 2. Majors

### M1. "Scanner absent" (no claim for 5 min) fires during every long scan at concurrency 1

- **Location:** A §5.2 line 541-542, A §7 line 634, A §4.9 (attempt 360 s).
- **Evidence:** a worker claims when it is free; at concurrency 1 there is no claim while a scan
  runs; one attempt may run 360 s > 300 s.
- **Scenario:** a hard input runs 5 min 10 s. From minute 5 every upload is refused with
  "Dočasně nedostupné" and the entry cards flip, although the scanner is healthy.
- **Change:** liveness = last authenticated scanner call of any kind (claim, event, heartbeat),
  threshold 3 × heartbeat; idle workers poll `claim` every ≤ 10 s. Separate states for "scanner
  silent" and "scanner too old / refused at claim" (M12).

### M2. The "provider budget or auth" class is keyed on the wrong signal and pauses everyone on one misread

- **Location:** A §7 line 629, E25 (line 135).
- **Evidence:** <https://platform.claude.com/docs/en/api/rate-limits>: "`retry-after` … Not every 429
  carries it; the spend-cap 429 never does", and "On the Messages API, `error.details.error_code`
  is `enforced_spend_limit_reached`. Use it to tell this response apart from a rate limit."
  Acceleration-limit 429s exist. A self-set limit is HTTP 400 `invalid_request_error` whose message
  starts "You have reached your specified … API usage limits" — the same status as a per-scan
  request error. 402 `billing_error` exists (<https://platform.claude.com/docs/en/api/errors>). The
  Agent SDK reports `'billing_error' | 'rate_limit' | 'overloaded' | 'authentication_failed' |
  'account_on_hold' | 'model_not_found' | …` on the assistant message, not the HTTP response
  (<https://code.claude.com/docs/en/agent-sdk/typescript>) — whether header presence is visible to
  the scanner through the SDK was not found.
- **Scenario:** a throttling 429 without `retry-after` during a busy minute → `provider_blocked` →
  all claims refused, admins e-mailed, cards say "dočasně nedostupné" until "an admin or the next
  successful probe" clears it — and no component is named that runs a probe while claims are
  refused. Conversely a retired model id (404 `not_found_error`) or an `account_on_hold` fails every
  scan individually as `failed build`, each with a Sentry event and a refund, and is never paused.
- **Change:** classify on `error.details.error_code`, the 400 message prefix, 401 / 402 / 403, and
  the SDK's `billing_error` / `authentication_failed` / `account_on_hold`; verify in P0 what the SDK
  exposes. Add a generic circuit breaker: N (3) consecutive scans ending in the same non-input
  error class pause intake and alert, whatever the class. Name the prober: the scanner sends a
  1-token Haiku request every 5 min while blocked and reports through `claim`. Store the flag in
  Postgres (M17).

### M3. The retry numbers of §7 are not the engine's, and a provider slowdown turns into a charged, unfinished result

- **Location:** A §7 line 628 ("backoff ×3 (2, 8, 30 s)"), line 631 (cap hit with a candidate →
  `ready`, **settle**), A §4.9.
- **Evidence:** the harness retries 10 times by default (`CLAUDE_CODE_MAX_RETRIES`, capped 15), the
  stream idle watchdog is 300 s, `API_TIMEOUT_MS` 600 s — all equal to or above the 300 s builder
  cap. `maxBudgetUsd` "can pass this value" (same SDK page) — as A states.
- **Scenario:** 529s for three minutes. The engine retries silently (no "({n}/3)" note is possible
  unless retry events are forwarded), the 300 s cap fires with one early candidate, the scan is
  `ready`, flagged "nedokončeno", and the customer's credit is settled for a result cut short by the
  provider. Or no candidate: `failed timeout` (F6, "trval příliš dlouho") instead of
  `provider_unavailable` (F5).
- **Change:** set the engine's retry count and delays explicitly in the definition; forward retry
  events as notes; track "seconds lost to provider retries" per attempt; a cap hit where that share
  is above a threshold is `failed provider_unavailable` with a refund, not a settled result. Pin
  these env values in `definition.json` so the Messages-loop engine behaves the same.

### M4. Image limits of the Messages API are not designed for; the failure arrives mid-scan as a non-retryable 400 / 413

- **Location:** A §4.5 `scan_source` "the picture 1:1" (line 304) vs A §6 "the picture (resized)"
  (line 607); A §8.1 "A4 at 2480 × 3508 px is the heavy case".
- **Evidence (<https://platform.claude.com/docs/en/build-with-claude/vision>):** max 8000 × 8000 px;
  10 MB per image **base64-encoded**; "If a single API request contains more than 20 images, a
  stricter per-image dimension limit applies to every image in that request … including images from
  earlier conversation turns that you resend and images nested inside `tool_result` … rejected with
  an `invalid_request_error`" (keep each ≤ 3000 px or ≤ 20 images); request limit 128 MB, enforced
  as 413 before the API. The prototype agent used 12–36 turns and 3–7 previews plus crops and
  comparisons (E2), i.e. more than 20 images in one conversation.
- **Scenario:** A4 candidate renders returned at 2480 × 3508; at the 21st image the next request is
  rejected; the scan fails after most of its spend. Or: a 9 MB PNG source sent 1:1 is 12 MB in
  base64 → 400 on the first turn of every such upload.
- **Change:** one rule in the tool server: every image handed to the model is re-encoded, long edge
  ≤ 2576 px (the model's own limit), ≤ 3 MB; a per-scan image-count and byte budget; older
  comparison images are not resent beyond a window (subject to the append-only history rule of the
  model in use). A unit test with 25 images and an A4 render.

### M5. The state machine has transitions with no definition, and two documents disagree on what a retry is

- **Location:** A §5.2 diagram (lines 520-537) vs A §7 and UX §2.5.
- **Evidence / gaps:**
  1. `running → queued` for provider-blocked ("the attempt is not consumed", A line 629) is not in
     the diagram; neither is `queued → failed` by the purge (line 540).
  2. `stopped → queued` ("Zkusit přesto") has no reserve in the diagram; A line 627 says "refund
     until continued". The limit, queue-full, one-in-flight and provider-blocked checks at that
     moment are not stated. Only F3a may continue (UX line 1293); nothing says the server enforces it.
  3. `failed → queued` on the same row (A) vs "creates a new job … recorded as an attempt of the
     same scan" (UX lines 1311-1313). With one row, `attempt` is already 2 after an interrupted
     scan, so the user's retry starts at the automatic-attempt limit: its first lost lease fails it.
  4. Terminal states are not declared absorbing. `cancel` then a late `finish(proposed)` for the
     still-valid attempt: "idempotent per `(scan, attempt)`" does not say which wins — a cancelled
     and refunded scan can become `ready` and settled, or the paid result is dropped silently.
  5. When `attempt` increments (claim or requeue) is not stated; every stale-attempt check depends
     on it.
- **Change:** one transition table in A (the one in §3 below, corrected), each row with owner,
  guard, ledger effect; `auto_attempt` (0–1, reset by a user retry) separate from `run` (1..n, the
  usage key); terminal states absorbing — a late `finish` on a terminal row records usage only.

### M6. Refund / settle idempotency rests on "row lock" in prose; three writers can fail the same scan

- **Location:** A §5.2 line 539 ("detected at every claim and on every status read"), line 540
  (purge), A §7 line 641, A §5.1 line 505.
- **Evidence:** the status endpoint is polled every 2 s per tab (UX line 399) plus the shell poll;
  each read may perform `running → failed` + refund + e-mail scheduling; so may a claim and the
  purge. The existing pattern only *reads* stale as failed and never writes
  (`src/Services/Calendar/CalendarExports.php:112-115`, `CalendarExport.php:49,153`), so the app has
  no precedent for a transition performed by a GET. The ledger is append-only with no stated unique
  constraint.
- **Scenarios:** two tabs poll at the same instant after the second lease loss → two refunds (the
  project gains a credit). A status read at t = lease + 10 ms overwrites an event that extended the
  lease at t = lease − 5 ms unless the row is re-read under lock. Refund in the next month: reserve
  31 Oct 23:58, refund 1 Nov 00:04 — "reserves minus refunds in the calendar month" makes November
  −1 (one free scan) and leaves October charged.
- **Change:** every transition is `UPDATE … WHERE id = ? AND status = ? AND run = ?` and proceeds
  only on one affected row; ledger unique index on `(scan_id, reserve_seq, reason)` for settle and
  refund; the monthly count attributes a refund to its reserve's month (join on the reserve). Lease
  expiry is performed by one owner (M10), reads only *present* it.

### M7. Results that were paid for are thrown away: late `finish`, non-idempotent `render`, storage errors at `finish`

- **Location:** A §4.5 (lines 327-331), A §7 lines 635-636.
- **Evidence:** "the app checks attempt and lease"; only `finish` is declared idempotent; `render`
  "stores the candidate" and the tool server allows 3.
- **Scenarios:** (a) `finish` arrives 2 s after expiry → refused → the whole scan runs again
  (double provider spend, 2–6 more minutes) or fails if it was attempt 2. (b) the old `web`
  container is stopped during a deploy while a `render` is in flight (grace 30 s, INF
  `compose.yaml:75`); the scanner retries; the app renders twice and the candidate counter reaches
  the cap one render early, or two rows carry the same number. (c) MinIO is down for 90 s at
  `finish`: `proposal.webp` cannot be written, `finish` answers 500 for longer than the retry
  window, the lease expires, the scan is rerun.
- **Change:** an idempotency key `(run, seq)` on `render`, `faces`, `events`; `finish` persists the
  outcome, report and usage in the database first and treats the image write as retryable work the
  app owns; a late `finish` is accepted under the rule of B1 (5).

### M8. Switching the entitlement off — or `AI_SCAN_ADMIN_ONLY` on — locks users out of scans they already paid for

- **Location:** A §6 line 577-578 (voter = admin, or editor of an *entitled* project; admin only
  while the flag is on), line 586 (`claim` only for entitled or already running), vs UX F12 (line
  1307: "The scan finishes and stays reviewable") and UX §4.2 line 1527 ("Rozpracované skeny se
  dokončí").
- **Scenario:** an admin switches a project off (or turns `AI_SCAN_ADMIN_ONLY` on as the P6
  rollback). The voter guards status, fixes, accept, discard and image serving, so the customer gets
  403 on a `ready` proposal whose credit is settled. A `queued` scan of that project is never
  claimed, shows "Čeká ve frontě" and blocks the project's single slot until the purge (M10).
- **Change:** two permissions: `start` (entitlement + flags) and `use` (requester or project editor,
  for any existing scan). De-entitlement cancels `queued` scans with a refund at once and lets
  `running` ones finish. A functional test for both flags against every post-start route.

### M9. The kill switch needs a deploy, has no defined effect on work in progress, and the P4 rollback does not stop the scanner

- **Location:** A §4.9 "Switches" (line 436), P1 rollback "everything refuses while
  `AI_SCAN_ENABLED` is off", P4 rollback "flag off; revert the compose push" (line 831).
- **Evidence:** env values reach the containers through `dump_secrets` at deploy (INF
  `deploy.sh:32`), deploys are serialized box-wide and may queue (DI §1). `apps/wboost/` is
  reconcile class D: a push "never activates it" (DI "Config delivery"), so reverting the compose
  file does not remove a running `restart: always` container.
- **Scenario:** spend spike at 22:00. Turning the flag off needs a secret change and a deploy
  behind whatever is in the queue; the running scan and the scanner keep going; "everything
  refuses" also blocks review and accept of finished proposals.
- **Change:** a database-backed runtime switch on the admin tab with three positions — on, paused
  (no new uploads, no claims, reviews work), off — effective on the next request, returned to the
  scanner in `claim` and `events` (so a pause aborts running scans if asked). Document the hard
  stop (`docker compose stop scanner`, and that the next deploy's `up -d` starts it again unless
  the service is removed). Each phase's rollback states what happens to `queued`, `running` and
  `ready` rows.

### M10. Stuck work is noticed only when somebody reads it; "12 hours" is 12–36; nothing alerts

- **Location:** A §5.2 lines 539-542, A §4.11 "Cron entries 1", A §7 lines 643-647 ("Infra alerts
  are a backlog item").
- **Evidence:** the purge is nightly; DI line 209: "Nothing alerts on a stuck scan or on Anthropic
  spend"; no alert rule reads queue depth (DI §2).
- **Scenario:** the scanner dies at 14:05 during a scan; the user closed the tab. No claim, no
  status read: the row stays `running`, the admin strip counts it as running, the project's slot is
  taken, the failure e-mail goes out after the 02:00 purge. A scan queued at 14:10 on a blocked
  provider is 11 h 50 min old at 02:00 and is failed the night after, 36 h later.
- **Change:** a reaper every minute (expired leases, queue age, provider-blocked age) as the single
  owner of time-based transitions — a scheduled message on the existing consumer or a loop in a
  small always-on command, not `compose run` per minute. Alerts before P4, not after: oldest queued
  > 15 min, scanner silent > 2 min, ≥ 3 consecutive failures, spend ≥ 80 % of the ceiling, scanner
  OOM. The scanner's `/health` must fail when the claim loop has not completed a poll for 60 s (a
  listening port proves nothing).

### M11. Scanner upgrade, SIGTERM and long-lived-process hygiene are unspecified

- **Location:** A §7 line 633 (restart = lease expiry), P4 (INF `deploy.sh`: "pull + `up -d` of the
  pinned scanner"), E18 (node 1.15 GB after ten scans in one process), A §8.2.
- **Evidence:** no grace period, drain rule or release call is specified; DI §4.2 shows the
  trade-off (a 360 s grace holds the box-wide deploy lock). No recycling rule exists for a process
  whose memory was measured to grow.
- **Scenarios:** a tag bump during a scan kills it: attempt consumed, spend lost and unrecorded
  (B2); a second bump or an OOM in the rerun → the user gets F7. After some tens of scans the node
  process reaches the limit and the OOM kill is the recycle — always in the middle of a scan. An
  aborted engine whose child process survives keeps calling the provider while the scan reruns.
- **Change:** on SIGTERM: stop claiming; finish the running scan within a stated grace (e.g. 120 s,
  `stop_grace_period` accordingly); otherwise call `release` (requeue, attempt **not** consumed,
  usage recorded) and exit. Recycle the worker process between scans after N scans or above an RSS
  threshold. Kill the engine and OCR **process groups** on abort and verify exit; `init: true` in
  compose. State these in A §7 and test them on the box.

### M12. Protocol skew: the app deploys on every push, the scanner is pinned, and nothing tests one against the other

- **Location:** A §4.4, §4.5 (`claim {scannerVersion, instruments}`), A §12 "Two repos' deploy
  order — `requiresInstruments` handshake".
- **Evidence:** only `claim` carries a version. During blue-green two app versions answer the
  scanner at once (INF `deploy/lib.sh` rollout; Docker DNS returns both containers, including the
  new one before it is healthy). P1 tests the app against a fake scanner, P2 the scanner against a
  stub app.
- **Scenarios:** a push changes the `render` answer; the pinned scanner fails every scan until
  somebody bumps the tag. A scanner refused at `claim` for missing instruments looks alive ("last
  seen 5 s ago") while the queue grows until the purge.
- **Change:** `protocolVersion` in every call, the app supports N and N−1; a contract fixture
  generated by the scanner build and replayed in the app's CI (and the reverse); "scanner refused:
  too old" as its own state on the admin strip with an alert, and uploads refused in that state.

### M13. One static secret is the whole authorisation of the scanner surface

- **Location:** A §4.5 lines 318-320, 331-332; A §3.2 R7.
- **Evidence:** the "internal host name" check is satisfied by any container that can reach
  `web:80`; `web` is on the box-wide external `traefik` network (INF `compose.yaml:88`) shared by
  the other tenants' stacks. `GET {id}/source` has no attempt in its signature. `SCANNER_TOKEN`
  never expires and is not bound to a job. `ANTHROPIC_API_KEY` is already in `/srv/wboost/.env`,
  loaded by `env_file` into `web` and `messenger-consumer` (DP line 186; INF `compose.yaml:35,129`).
- **Scenario (reliability, not only security):** at concurrency 2 a worker bug posts worker A's
  candidate to scan B — the app accepts it, because attempt numbers are small integers that match.
  A leaked token lets any neighbour container read every stored source, finish scans with invented
  usage, and render at will through the shared Gotenberg.
- **Change:** `claim` returns a random lease token (hash stored on the row), required on every later
  call for that scan and rotated on requeue — one column, no second credential type. Bind the
  scanner endpoints to requests arriving on the `scan` network interface, not to a Host header.
  Rotation procedure for `SCANNER_TOKEN` (two valid values during a rotation). Remove the unused
  Anthropic key from the app's `.env` or state why `web` needs it.

### M14. A poison input takes the single worker twice and can be resubmitted

- **Location:** A §6 "Upload validation" (line 606), A §4.9, A §8.2 (3 g limit = 2.65 GiB estimate,
  image decode not in the sum).
- **Evidence:** PNG / JPEG / GIF / WebP pass `NormalizeImageFormat` byte-for-byte
  (`NormalizeImageFormat.php:38-56`): CMYK JPEG, 16-bit PNG, animated GIF / WebP, progressive
  bombs within 10 MB and 60 Mpx reach the instruments as uploaded. OCR on a 60 Mpx frame in float32
  is ≈ 720 MB before the model runs; the OCR budget in A §8.2 is 0.35 GiB.
- **Scenario:** the input crashes or OOMs the OCR; requeue; the same again; `failed interrupted`,
  refund, "Zkusit znovu" offered. Every other customer waited two attempts.
- **Change:** B4's normalised working copy is what the scanner downloads; a deterministic crash in
  the pre-pass (before any model call) is `stopped unusable_input` with no automatic second
  attempt; the source hash is remembered (B3). Fixture set of hostile files in `scanner/test`.

### M15. Gotenberg: where the 3 retries run, session locks, and the coupling to user exports

- **Location:** A §7 line 636, A §4.6 (fixes are synchronous app calls), A §8.2 "Gotenberg".
- **Evidence:** PHP `max_execution_time` is 30 s and the client is capped at 20 / 25 s precisely so a
  slow render is a catchable error; the config comment records what happened otherwise: "the
  session row stays locked for those 30 s, so every other request of the same user … queues behind
  it" (`sensiolabs_gotenberg.yaml:6-23`). Gotenberg is one shared container on a floating tag
  (`gotenberg/gotenberg:8`, INF `compose.yaml:306`), no queue cap by decision.
- **Scenarios:** three retries inside one `render` request = a fatal at 30 s. A font swap in the
  review holds the user's session lock for up to 25 s while that user's 2 s polls and other tabs
  queue. A scan's A4 renders push Gotenberg over its limit and the Chromium that dies is a
  customer's export (DI §5 "Failure coupling").
- **Change:** retries only in the scanner, one render per app request; status and "my scans"
  endpoints close the session before any work; `GOTENBERG_SCAN_DSN` as a separate setting from day
  one (same value at launch) so a second instance is a config change; the scanner serialises its
  own renders (1 at a time across workers) until P0's numbers say otherwise.

### M16. The planned race tests cannot be races under the suite's transaction wrapper

- **Location:** P1 tests (line 792: "double claim, stale attempt, idempotent finish"), A §7 line 641.
- **Evidence:** `phpunit.xml.dist:32` and `config/packages/test/dama_doctrine_test_bundle.php` — every
  test runs in one transaction on one shared connection, so `FOR UPDATE SKIP LOCKED`, lock waits and
  lost updates between two requests cannot occur in it. Messages are routed `sync` in tests, so
  delayed e-mails and consumer restarts are not exercised either.
- **Change:** a small concurrency suite outside the wrapper (two real connections / two processes
  against the test database) for: two claims, claim vs cancel, event vs expiry, finish vs cancel,
  accept vs accept, accept vs discard, accept vs purge, two refunds. Listed in §4.

### M17. Where the operational state lives is not stated; in the cache it disappears

- **Location:** A P1 `ProviderState`, "scanner last seen", queue estimate "cached for 15 s", daily
  ceiling, P6 "rate limiter".
- **Evidence:** Redis is cache-only, `allkeys-lru`, no persistence (INF `compose.yaml` redis
  command); rate limiters built on the cache "answer 'miss', so the limiter would silently never
  limit" when the backend is unreachable (`config/packages/rate_limiter.php:45-52`).
- **Scenario:** Redis restarts: `provider_blocked` is gone and scans run into the blocked provider
  again; "last seen" is empty and uploads are refused; the access-request and upload limiters allow
  everything.
- **Change:** flags, last-seen and ceiling counters in Postgres (one `ai_scan_runtime` row or
  columns); limits that protect money are database counts (B3), the cache limiter is only a
  courtesy.

### M18. Entry points missing from the enforcement table; calendar accept is a two-party commit with no owner in the middle

- **Location:** A §6 table (lines 580-587), A §4.6 "Accept, calendars" (lines 367-372).
- **Evidence:** not in the table: `MyScans` (shell), retry / continue / cancel, rating,
  `SetProjectAiScan`, and the **calendar save carrying `scan: <id>`** — an existing endpoint that
  also exists on the public client-link family `/k/{token}` (`CalendarExportHttp.php` docblock names
  both families). Calendar accept = browser `replaceLayout` + a later save; held pictures "become
  calendar assets only at accept".
- **Scenarios:** a client-link save posts `scan:` of a scan it may not see. The user applies the
  layout, the save fails or the tab closes: the scan stays `ready`, expires after 30 days, the held
  pictures are deleted while the unsaved browser document referenced them. Two tabs apply the same
  scan.
- **Change:** add the rows to the table and tests; the `scan` field is refused on `/k/{token}` and
  checked against calendar, project and state; the server performs "accept" in the save transaction
  (assets created, ids returned, scan marked) idempotently, and the browser swaps ids from the
  answer.

### M19. Sentry and log noise are designed in, and request data can carry what §6 says is never stored

- **Location:** A §6 "Stored" (line 608), A §7 "Sentry for build failures and protocol violations
  only" (line 645), A §4.5 (3 validation rejections, stale attempts, refusals).
- **Evidence:** `config/packages/sentry.php:27-36`: `send_default_pii: true`, tracing on, only 403 /
  404 / 405 / 429 ignored — "Other 4xx still report — by choice". A stale attempt (409), a rejected
  design (422), a refused upload (limit, consent, queue full) are 4xx by nature. The scanner's
  stdout goes to Loki (DI §2).
- **Scenario:** each scan with two rejected designs books Sentry events whose request context can
  include the posted design — OCR text of a customer's artwork; a provider outage books one issue
  per scan.
- **Change:** the scanner API answers expected refusals as 200 with `{ok: false, code}` (or
  exceptions on the ignore list); a `before_send` rule drops request bodies for
  `/internal/scanner/*` and the scan routes; one aggregated Sentry event per error class per hour; a
  test that greps a full fake run's logs and captured events for a marker string placed in the
  picture's text.

---

## 3. Minors

| # | Location | Problem → change |
|---|---|---|
| m1 | A §5.3 purge "deletes every object whose scan row is missing" | An upload between the storage write and the database commit is deleted by a purge running at that moment. → only objects older than 24 h. |
| m2 | UX F2; INF `compose.yaml:115-116` (`wboost-retry`, 3 attempts) | A start POST replayed by Traefik or by the user after a timeout creates nothing but answers `scan_in_progress` for the user's own scan. → client-generated scan id as idempotency key; the second POST returns the first scan. |
| m3 | UX e-mails (lines 1266-1277), A §7 | "Zkusit znovu" in the mail must open a page, never retry on GET (mail scanners follow links). No mail when `requested_by` is null (deleted user) although `requester_email` remains. The consumer is recreated on every deploy without `exec` (E22): set `notified_at` in the same transaction that dispatches, accept "at most once". |
| m4 | A §5.1 queue estimate | Uses delivered scans only and ignores requeues: optimistic exactly when things go wrong. → include failed durations and add one attempt cap when the running scan is on attempt 2. |
| m5 | A §4.9, §6 daily ceiling | Day boundary, time zone, when it is checked (upload only, or also at claim), and what a user with a queued scan sees are not stated. The provider's month is UTC, the app's is Europe/Prague. → state all four; check at claim too. |
| m6 | A §5.1 `result_template_id`, `calendar_id` set null | Deleting a template made from a scan: FK behaviour not stated (500 or dangling link). A `ready` calendar scan whose calendar was deleted cannot be accepted and waits 30 days. → `SET NULL`; scans of a deleted calendar become `discarded`. |
| m7 | A §5.1 `project_id` FK cascade | Project deleted mid-scan: every scanner call answers 404; not stated that the scanner treats 404 / 410 as cancel, so the engine may run to its cap; later `preview.webp` writes recreate the deleted prefix (the purge removes them). → 410 = abort; usage still recorded (FK-less table). |
| m8 | UX S3 (2 s poll), A §5.1 | The polled row carries `proposal`, `events`, `report` JSON. → the status query selects scalar columns only; `events` capped at 200 is fine. |
| m9 | A §8.2 | 3 g limit against a 2.65 GiB estimate = 88 %: inside `ContainerMemoryNearLimit` (75–95 % for 10 min) by construction. Concurrency and `mem_limit` are two knobs. → limit so that the measured p99 is below 70 %; the scanner reads `memory.max` at start and refuses a concurrency it cannot hold. |
| m10 | A §8.2 `cpus: 3` | ONNX at 3 threads per worker and libvips' default pool (= 16) under one 3-CPU quota: at concurrency 3 OCR is throttled and the throttling metric is disabled on the box (DI §2, D31). → pin thread pools per worker to `floor(3 / concurrency)`. |
| m11 | A §4.6 stand-in gallery file, `CompilationContextFactory.php:153-159` | The per-project stand-in is an ordinary gallery file: it can be trashed and purged after 7 days, and the code comment requires a source guard when `FileSource` gains a case. → hidden from the gallery, excluded from trash; the guard added with the enum case. |
| m12 | A §7 lines 631-632 | "Cap hit with a valid candidate → ready" and "no / inconsistent report → failed build" contradict when the agent never reached `finish`. → define the report the scanner writes alone (measured fields + empty model fields + flag). |
| m13 | A §8.2 provider headroom | Assumes the Start tier; new organisations "may start in the Evaluation tier, with limits below the standard limits" (rate-limits page). → read the tier of the production organisation at G1. |
| m14 | P4 compose change | Adding the `scan` network to `web` and a `scanner` service: no `depends_on: web` (compose would touch a blue-green service), and check that `web` still resolves `db` / `gotenberg` of its own stack (D78 / D86 name-resolution traps). |
| m15 | A §4.11 `app:ai-scan:fake-run` | Ships in the production image. → refuses to run unless `APP_ENV` ≠ prod or an explicit flag. |
| m16 | A §7 refusal → refund | Repeated refused uploads are free and can put the organisation's API access at risk (`account_on_hold` exists as an SDK error). → count refusals per project; 3 in a day pause that project and notify admins. |
| m17 | A §8.1 tmpfs, P2 | Per-scan scratch directory cleanup on abort is not stated; tmpfs counts toward the limit. → remove in `finally`, sweep at worker start. |

---

## 4. Transition table and verdicts

Owner = who performs it. Verdict: **ok** / **gap** (undefined) / **race** / **wrong**.

| # | From → to | Trigger / owner | Atomic + idempotent as designed? | Verdict |
|---|---|---|---|---|
| 1 | — → `queued` | upload; `RequestAiScan` on `command_bus` | storage write is outside the transaction; "1 per project" and "queue ≤ 10" are check-then-insert | **race**: two tabs. Needs a project row lock (as `RequestCalendarExportHandler:39`) or a partial unique index; m1, m2 |
| 2 | `queued` → `running` | `claim`; scanner | `FOR UPDATE SKIP LOCKED`, row lock | **ok**; add lease token (M13); untestable under DAMA (M16) |
| 3 | `queued` → `cancelled` | user | conditional update needed vs claim | **race** with 2: define that cancel wins only if still `queued`, else becomes 8 |
| 4 | `queued` → `failed` (`scanner_unavailable`) | nightly purge | not in the diagram | **gap**; 12–36 h (M10) |
| 5 | `queued` stays, never claimed | project de-entitled | — | **wrong**: silent wait (M8) |
| 6 | `running` → `ready` | `finish(proposed)`; scanner | idempotent per attempt; refused after lease expiry | **race / wrong**: late finish discarded (M7); image write inside (M7c) |
| 7 | `running` → `stopped` / `refused` / `failed` | `finish`; scanner | as 6 | **ok** apart from 6's issues; usage only here (B2) |
| 8 | `running` → `cancelled` | user; flag on next event | app-side moment of the status change not stated; no `finish` for usage | **gap**: absorbing rule and usage (M5, B2); can wait up to the next event (B1) |
| 9 | `running` → `queued` (lease lost, attempt 1) | claim, **status read**, purge | three writers, prose "row lock" | **race** (M6); false positives (B1) |
| 10 | `running` → `failed` (lease lost, attempt 2) | as 9 | + refund | **race**: double refund (M6) |
| 11 | `running` → `queued` (provider blocked, attempt kept) | scanner report | not in the diagram | **gap** (M5); misclassification (M2) |
| 12 | `stopped` → `queued` | "Zkusit přesto"; user | reserve and guards unstated | **gap** (M5) |
| 13 | `failed` → `queued` | "Zkusit znovu"; user / MCP | new reserve; attempt counter not reset; unlimited | **wrong / gap** (B3, M5) |
| 14 | `ready` → `accepted` (template) | `AcceptTemplateScan`, one `command_bus` transaction | idempotent by design; the transaction contains a Gotenberg render and a storage write (`StoreTemplateVariantPreviewImage`) | **ok with caveats**: a row lock held across a ≤ 25 s render; a rolled-back accept leaves a preview object in the public bucket; test accept vs accept, vs discard, vs purge outside DAMA |
| 15 | `ready` → `accepted` (calendar) | browser apply + calendar save with `scan:` | split across client and server | **gap** (M18) |
| 16 | `ready` → `discarded` | user | conditional update vs 14 | **race** if not conditional |
| 17 | `ready` → `expired` | nightly purge | vs accept at the same minute | **race**: purge must lock the row and re-check status before deleting files |
| 18 | `accepted` / `discarded` / `expired` / `cancelled` / `refused` → — | terminal | not declared absorbing | **gap** (M5.4) |
| 19 | any → row deleted | project deletion (cascade) | scanner sees 404 | **gap** (m7) |

Timing consistency (as designed):

| Quantity | Value | Conflicts with |
|---|---|---|
| Lease | 60 s, event-driven | model turns, engine retries (≤ 10 × ≤ 32 s), render 3 × 25 s, app-call retry window 60 s (B1) |
| Attempt cap | 360 s (builder 300 s) | "scanner absent" 300 s (M1); engine `API_TIMEOUT_MS` 600 s and stream idle 300 s never fire first (M3) |
| Attempts | 2 → ≈ 12 min + queue | UI "obvykle 1–3 min", slow-run line at 3 min; estimate from delivered scans only (m4) |
| Queue-age failure | 12 h, checked nightly | effective 12–36 h (M10) |
| UI poll | 2 s per tab + 10 s shell | performs transitions on read (M6); session lock (M15) |
| E-mail | 2 min after the end, single consumer | accepted in A §12; lost or doubled on consumer recreate (m3) |
| Deploy | old `web` stopped with 30 s grace; health wait ≤ 180 s | non-idempotent `render` retry (M7b) |

---

## 5. Failure injection on paper

"Clear" = the user ends in a state that says what happened, with the upload kept and the credit
right. Left behind = anything orphaned.

| Injected failure | What the design does | User clear? credit? | Left behind |
|---|---|---|---|
| Scanner killed mid-scan | lease expiry → requeue once → `failed interrupted` | yes after ≤ 60 s + rerun; refund on final failure | provider spend unrecorded (B2) |
| Scanner OOM | same; `restart: always` | same; repeats on the rerun if input-driven (M14) | unrecorded spend; `ContainerOOMKilled` page |
| Box reboot | same as kill; scanner may start before the app (claim retries) | yes | same |
| App deploy during a scan | "nothing" | **no** when the app is unreachable longer than 60 s minus the last event's age (B1); duplicate render possible (M7b) | duplicate candidate |
| Scanner deploy during a scan | not specified; behaves as kill | rerun; F7 if it happens twice (M11) | unrecorded spend |
| DB migration during a scan | additive; old web keeps serving | yes; a long `ALTER` on `ai_scan` blocks events → lease (B1) | — |
| Protocol skew | handshake on `claim` only | queue grows silently until the purge (M12) | queued rows, reserves held |
| Gotenberg slow / down / recreated | render retried 3×, then `failed renderer_unavailable`, refund | yes if the retries are in the scanner (M15); accept: nothing created, stays `ready` | — |
| Gotenberg OOM caused by a scan | not handled | another user's export fails (M15, B4) | page |
| MinIO down | not listed in A §7 | upload: F2. Mid-scan: `source` / `render` / `finish` fail → retries → lease → rerun or `failed` with the wrong code (M7c) | possibly half-written previews (purge) |
| Redis down | not listed | flags and limiters lost if kept in the cache (M17) | — |
| Provider 429 with `retry-after`, 529, 5xx | engine retries (10, not 3), then failed / cap | F6 instead of F5; possibly charged unfinished result (M3) | — |
| Provider 429 without `retry-after` | `provider_blocked`, global pause | honest text, but may be a false pause with no prober (M2) | queued rows up to 36 h |
| Provider timeout / stalled stream | stream watchdog 300 s = builder cap | `failed timeout`, refund | spend unrecorded if aborted without a result (B2) |
| Refusal | `refused`, refund | yes | repeatable for free (m16) |
| Context length / too many images / request too large | not listed | `failed build` + Sentry after most of the spend (M4) | Sentry noise (M19) |
| Invalid designs repeatedly | 3 rejections → `failed build` + Sentry, refund | yes | one Sentry event per rejection unless handled (M19) |
| Agent never calls `finish` | turn / time cap → `ready` flagged, or failed | contradictory rows (m12); settled although unfinished | — |
| Tool call hangs | no per-tool timeout stated; builder cap 300 s | lease lost first (B1) → rerun | orphan subprocess unless the group is killed (M11) |
| Huge / bomb / 60 Mpx upload | header check, 10 MB | **web OOM possible** (B4); scanner OOM (M14) | — |
| Wrong MIME, SVG | sniffing; SVG refused | yes | — |
| HEIC | transcoded by ImageMagick in `web` | size check by header not possible (B4) | — |
| Animated / CMYK / 16-bit | passed through unchanged | undefined colours and memory in OCR and measure (M14) | — |
| Text in the picture instructing the agent | declared data; tools without side effects; closed codes; caps | yes — worst case a wrong proposal or burned budget; design asset ids are project-scoped (verified, `CompilationContextFactory.php:139-160`) | — |
| Partition scanner ↔ app | 60 s retries, then lease | engine keeps spending until its own cap unless the scanner aborts when it cannot heartbeat — not stated | unrecorded spend |
| Clock skew | one host, one clock; lease judged by the app only | not an issue; keep all expiry decisions on the database clock | — |
| Disk full | not listed | uploads fail (F2); Postgres failure takes the whole app, not only scans | — |
| Entitlement off / admin-only on mid-scan | voter denies; claim refuses queued | **no** (M8) | settled credit, unreachable proposal |
| User deleted | `requested_by` null, e-mail kept | mail goes to a deleted account (m3) | — |
| Project deleted | cascade | scanner behaviour undefined (m7) | objects re-created after deletion |
| Two tabs | one in flight per project | start race (table row 1); double refund (M6) | — |

---

## 6. Tests

### 6.1 Would the plan's tests catch it?

| Failure | Caught by a planned test? |
|---|---|
| B1 lease vs silent turn | **No.** P1 fake scanner and P2 stub app do not hold a turn longer than the lease; P4 "app deploy during a scan (it finishes)" passes only by luck of timing |
| B2 unrecorded spend | **No.** P1 "killing it requeues once, then fails with a refund" asserts the ledger, not usage |
| B3 free retry loop | **No.** |
| B4 web memory | **No.** P0 / P4 measure the scanner and Gotenberg |
| M1 absent rule | **No** (needs a scan > 5 min) |
| M2 / M3 provider classes | Partly: P2 "provider-budget classification → no attempt consumed" against a stub; no test with real response shapes or through the SDK |
| M4 image limits | **No.** |
| M5 / M6 transitions, double refund | Partly: unit "state machine", "ledger and monthly limit across a month boundary" (catches the month bug only if a refund crosses the boundary); no concurrent test (M16) |
| M7 late finish, duplicate render | Partly: "stale attempt", "idempotent finish" assert the current, lossy behaviour |
| M8 de-entitlement | Partly: "de-entitled project not claimable" asserts the behaviour that strands the user |
| M9 kill switch | **No.** |
| M10 stuck `running` unread | **No.** |
| M11 scanner upgrade / SIGTERM / growth | Partly: P4 `docker compose stop scanner` (requeue once); no soak |
| M12 skew | **No.** |
| M13 token scope | Partly: "secret, internal host" |
| M14 hostile uploads | Partly: "size, type" refusals |
| M15 Gotenberg | Partly: P4 "a calendar PDF export during a scan" |
| M17 Redis loss | **No.** |
| M18 calendar accept, client link | Partly: P5 "held pictures become assets only at accept and vanish on discard" |
| M19 Sentry / logs | Partly: P6 "no Sentry event for a locked-card click" |

### 6.2 Missing tests

**Unit (app)**
- Transition table as data: every (state, trigger) pair either transitions or is refused; terminal
  states absorb `finish`, `events`, `cancel`.
- Ledger: one settle or one refund per reserve under repeated calls; refund attributed to the
  reserve's month; DST days; reserve at 23:59:59 Prague on the last day.
- Usage upsert per `(scan, run, step)` from heartbeats; totals equal the last cumulative figures.
- Daily ceiling = recorded + in-flight caps; started-scans counter unaffected by refunds.
- Provider error classifier against recorded real payloads: spend-cap 429 with
  `enforced_spend_limit_reached`, throttle 429 with and without `retry-after`, 400 workspace limit,
  400 many-image, 401, 402, 403, 404 model, 413, 500, 504, 529, mid-stream error event, refusal.

**Unit (scanner)**
- Heartbeat continues while the engine is blocked for 200 s; stops and aborts when the app
  answers 409 / 410 or is unreachable past the lease.
- Image budget: 25 images, an A4 render, a 9 MB PNG — everything sent is within the limits of M4.
- Hostile fixtures: decompression bomb, 60 Mpx, CMYK JPEG, 16-bit PNG, animated GIF and WebP,
  truncated file, wrong extension, HEIC grid — each ends in a named outcome within a memory bound.
- Abort kills the engine and OCR process groups (no child left; assert by pid).
- SIGTERM: drains within the grace, else `release`.
- Two workers: candidates and usage never cross scans.

**Functional / concurrency (outside the DAMA wrapper, two connections)**
- Two claims; claim vs cancel; event vs expiry at the boundary; finish vs cancel; late finish
  after requeue (accepted) and after a second claim (refused); two lease-loss detections → one
  refund; accept × 2; accept vs discard; accept vs purge; two starts in one project.
- Idempotent `render` / `faces` / `events` by `(run, seq)`.
- Entitlement off and `AI_SCAN_ADMIN_ONLY` on against every post-start route; queued scan cancelled
  with a refund.
- Calendar save with `scan:` through `/k/{token}` is refused; with a foreign scan id is refused.
- Runtime switch: paused keeps reviews working, stops uploads and claims.
- Sentry and log capture of a complete fake run, including refusals and rejected designs: zero
  events for expected refusals, marker text absent everywhere.
- Contract: the scanner's recorded protocol fixture replayed against app HEAD; app N with scanner
  N−1.

**Fault injection on the box (P4, each with the expected row states, ledger, usage and alert)**
- `docker kill -s KILL` the scanner mid-turn, mid-render, mid-`finish`; `docker update --memory` low
  enough to OOM it; reboot the box mid-scan.
- App deploy mid-scan ×5 at different stages, one with a slow migration; scanner tag bump mid-scan;
  two deploys in a row.
- `docker compose stop gotenberg` / `pause` (slow) during a scan and during accept; the same for
  `minio` and `redis`; `db` restart.
- Network: disconnect the scanner from the `scan` network for 30 s, 90 s, 10 min.
- Provider: a local fault proxy in front of `api.anthropic.com` (`ANTHROPIC_BASE_URL`) returning
  each class of §6.2 "classifier", a 4-minute 529 period, a stalled stream, a 200 followed by a
  mid-stream error.
- The largest legal upload and the hostile fixtures through the real upload, with `web`, scanner
  and Gotenberg memory recorded.
- From a container on the `traefik` network of another stack: `/internal/scanner/*` with and
  without the token.
- Kill switch: time from the click to the last provider request.

**Soak / queue**
- 100 scans back to back at concurrency 1 (fake provider with realistic sizes, real instruments and
  renders): RSS of every scanner process and of `web` per scan, no growth trend, no orphan process,
  tmpfs empty at the end, no object without a row.
- Queue: 10 queued across 10 projects, an 11th refused; estimates vs actual; one poison input in
  the middle; a cancel of every second scan; 20 tabs polling.
- Concurrency 2 and 3 with normal traffic (fill-page renders, a template export, a calendar PDF
  export): latency of that traffic, Gotenberg and `web` memory, CPU throttling.
- A 24 h idle period followed by a scan (connections, tokens, page cache, cold start).

### 6.3 Pre-launch reliability checklist

Before P4 (admins in production):
1. Timer heartbeat, lease and attempt numbers as in B1, with the timing table of §4 re-derived and
   written into A §4.9.
2. Usage recorded incrementally; a killed scan shows its spend on the admin tab (B2).
3. Started-scans counter and per-project daily budget in place; retry bounded (B3).
4. Working-copy normalisation and render pixel cap; Imagick limits; `web` RSS measured with the
   largest legal input at the planned concurrency, peak below 70 % of its limit (B4).
5. Transition table implemented as conditional updates; ledger unique index; concurrency suite
   green (M5, M6, M16).
6. Runtime switch (on / paused / off) and the hard-stop procedure written in the runbook and tried
   (M9).
7. Reaper running; alerts live: oldest queued, scanner silent, consecutive failures, spend 80 %,
   scanner OOM, scanner refused as too old (M10, M12).
8. Scanner SIGTERM behaviour, grace period, process-group kill, worker recycling verified on the
   box (M11).
9. Lease token; scanner endpoints unreachable from another stack's container even with a spoofed
   Host header (M13).
10. Provider classifier verified against real responses through the chosen engine; engine retry
    settings pinned; prober exists (M2, M3).
11. Image budget enforced in the tool server (M4).
12. Fault-injection list of §6.2 executed once, results in the infra journal.
13. Soak of 100 scans without memory trend or leftovers.
14. Sentry: zero events from a full run with refusals; no picture text in logs or events (M19).
15. Dedicated workspace with its own spend limit; the organisation's tier read; the old unused key
    removed from the app's `.env` or justified (M13, m13).
16. Runbook: stuck queue, stuck scan, spend spike, provider blocked, scanner too old, rollback per
    phase with what happens to `queued` / `running` / `ready` rows.

Before P6 (customers):
17. De-entitlement and admin-only behaviour as in M8, tested.
18. Reconciliation of recorded usage against the provider's report for the first 50 scans within
    the stated tolerance (B2).
19. Abuse limits of B3 and m16 exercised with an MCP client in a loop.
20. Concurrency stays 1 unless the concurrency-2 soak passed with normal traffic.

---

## 7. Could not check

- **Agent SDK abort semantics:** whether an aborted `query()` yields a `result` message with usage,
  what the subprocess exit codes mean, and the fields of `api_retry` messages. The reference page
  (<https://code.claude.com/docs/en/agent-sdk/typescript>) was read to 200 000 of 332 000
  characters; the abort and exit-code text was not in that part. B2's change does not depend on it.
- **Whether the SDK exposes HTTP status, headers or `error.details.error_code`** to the caller, or
  only the `SDKAssistantMessageError` enum (M2).
- **How a refusal (`stop_reason: "refusal"`) surfaces through the Agent SDK** and whether the
  harness retries or continues after it.
- **In-process SDK tool servers returning image content** (already listed in A §12).
- **Length of real model turns** at effort `high` for a full design — B1 holds for the engine
  retries and the outage window regardless; the turn length needs P0's timing data.
- **FrankenPHP worker thread count** of the production image (base image `ghcr.io/thedevs-cz/php`),
  so how many scanner `render` calls and polls `web` can hold at once is unknown.
- **Sentry request-body capture** for these routes with the installed SDK version and
  `send_default_pii: true` — inferred from the configuration, not observed.
- **Docker network order and DNS for `web`** after a third network is added; **signal delivery** to
  a node PID 1 in the scanner image — box tests.
- **The box itself:** nothing was verified on it (no ssh by instruction): the existing
  `ANTHROPIC_API_KEY`'s workspace and tier, MinIO bucket policy for a new bucket, current Gotenberg
  version behind the floating tag.
- **`deploy/lib.sh` rollout details** beyond the digest's description (read only `restart_consumers`
  and `pull_image`).
- **Evaluation-tier limits** are unpublished; the headroom figures of A §8.2 cannot be checked for a
  new organisation.
- **`digest-calendar.md`, `digest-templates.md`, `research-techniques.md`, the prototypes** were not
  read; calendar save-contract details in M18 come from A §4.6 and the export code only.
