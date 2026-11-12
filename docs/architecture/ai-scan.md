# AI sken — the job core (P1)

"AI sken" turns a flat picture of a design into an editable template (later:
a calendar layout). A picture is uploaded, a **scanner** — a separate service
under `scanner/`, built in its own phase — works on it with a model, and the
user reviews a proposal. This file describes what lives in the application:
the job, its states, credits and spend, storage, permissions and the API the
scanner calls. Design and reasons: `docs/ai-scan/architecture.md` (§4–§7),
decisions: `docs/ai-scan/decisions.md`, screens: `docs/ai-scan/ux.md`.

**What exists today (P1):** everything below, proven with a fake scanner
(`app:ai-scan:fake-run`). **What does not:** the wizard, accept (creating the
template), notifications, the access request, the MCP tool (P3); the scanner
on the box and the admin tab (P4); calendars (P5).

**The feature is inert unless switched on.** Operating mode defaults to `off`,
`admin_only` to on, every project's entitlement to off, and the scanner
endpoints answer 404 while `SCAN_NETWORK_CIDR` / `SCANNER_TOKEN` are unset
(the default, and production today). The only UI is the admin's switch on the
edit-project page.

## Map

| Piece | Where |
|---|---|
| Entities (schema) | `src/Entity/{AiScan,AiUsageEvent,AiCreditLedgerEntry,AiScanRuntime}.php`; columns on `Project` (`aiScanEnabled`, `aiScanMonthlyLimit`, `aiScanEnabledAt/By`) and `User` (`aiScanConsentVersion/At`) |
| Codes, limits, the transition table | `src/Value/AiScan/**` — `AiScanTransitionTable` is the table as data, `AiScanLimits` the app-side numbers |
| State changes | `Services/AiScan/AiScanTransitions` (every rule), `AiScanReaper` (time-based ones), `AiScanPurge` (nightly) |
| Money | `AiScanCredits` (ledger), `AiScanSpend` (ceilings), `RecordAiUsage` (usage rows) |
| Reads | `AiScans` (start guards, status document, `waiting`), `AiScanQueueEstimate`, `Repository/AiScanRepository` |
| Runtime row | `AiScanRuntimeState` / `AiScanRuntimeSnapshot` |
| Scanner API | `Services/AiScan/ScannerApi` behind `Controller/Internal/Scanner*Controller` (9), guarded by `Services/Security/ScannerServiceAuthenticator` |
| Render tools | `Services/AiScan/Template/{TransientVariant,TemplateProposal,FontFacesRenderer,ScanRenderLimiter}`, `AiScanStandIn`, `Services/Font/FitTextToWidth` |
| User side | messages `Message/AiScan/*` + handlers; `Controller/AiScan/*` (JSON); `Controller/Project/SetProjectAiScanController` (admin switch) |
| Permissions | `AiScanAccess` + `Services/Security/AiScanVoter` |
| Definition sent to the scanner | `resources/ai-scan/definition/**`, read by `AiScanDefinition` |
| Commands | `app:ai-scan:status`, `app:ai-scan:purge`, `app:ai-scan:fake-run` |

## The one rule about writing a scan

**After the request that creates it, a scan row is changed only by conditional
updates** — `UPDATE ai_scan SET … WHERE id = ? AND status = ? [AND run = ?]` —
in `AiScanTransitions`, and a transition happened only if that statement
changed one row. The web request and the scanner's call race for the same
row; this is what makes "exactly one of them wins" true. The ledger line, the
closed usage row and the history event are written in the same database
transaction, only then.

So: a loaded `AiScan` entity is a **snapshot**. Never `flush()` changes made on
it; re-read it (`AiScanRepository::get()` refreshes). The transition methods
take ids and return whether THIS call made the move. Which states an event
applies to is read from `AiScanTransitionTable`, never repeated in a query.

## States

`checking` → `queued` → `running` → `ready` → `accepted`; side exits
`stopped`, `failed`, `refused`, `cancelled`; then `discarded` / `expired`.
`accepted`, `discarded`, `expired` are absorbing. The rules (numbers as in
`docs/ai-scan/architecture.md` §5.2):

| Rule | Move | Method | Credit |
|---|---|---|---|
| 1 | — → `checking` | `RequestAiScanHandler` (project row locked) | reserve |
| 1a / 3d | intake takes / gives back the lease | `claimIntake` / `releaseIntake` | — |
| 2 / 3 | `checking` → `queued` / `stopped` | `intakePassed` / `intakeStopped` | — / refund |
| 3c | `checking` → `failed provider_unavailable` / `refused` | `intakeFailed` / `intakeRefused` | refund |
| 3a / 3b | lost intake lease: once more / `stopped unusable_input` | `intakeLeaseLost` (reaper) | — / refund |
| 4 | `queued` → `running` | `claimBuild` | — |
| 5 / 6 | `running` → `ready` / `failed` / `refused` | `finishProposed` / `finishFailed` / `finishRefused` | settle / refund |
| 7 / 6 | lost build lease: `queued` once, then `failed interrupted` | `buildLeaseLost` (reaper); `releaseBuild` | — / refund |
| 8 / 9 | cancel while waiting / while running | `cancel` | refund / **settle** |
| 10 | entitlement off → waiting scans `cancelled` | `cancelForEntitlementOff` | refund |
| 11 | waited longer than 60 min → `failed queue_timeout` | `queueTimeout` (reaper) | refund |
| 12 | "Zkusit přesto": `stopped` (photo of a print, once) → `queued` | `continueAnyway` | reserve |
| 13 | "Zkusit znovu": `failed` → `queued`, or → `checking` when no pre-check is stored | `retry` | reserve |
| 13a | mode `off` → everything in progress `failed interrupted` | `interruptForModeOff` | refund |
| 14 | `ready` → `accepted` (does not move `expires_at`) | `accept` — called by P3's `AcceptTemplateScan` | — |
| 15 / 16 | → `discarded` / `expired` | `discard` / `expire` (purge) | — |

Columns worth knowing:

- `try` — the user's starts of this upload (1–4: upload, one continue, two
  retries). Also the ledger key.
- `run` — grows at every BUILD claim; `intake_tries` grows at every INTAKE
  claim. They are the `run` of the lane's calls and the key of its usage row,
  and **are never reused**: a released or retried intake gets a new number, so
  no two attempts share a usage row. `intake_losses` counts lost intake leases
  in the current try (the second stops the scan).
- `lease_token_hash` — sha256 of the latest claim's lease token. Kept when the
  REAPER puts a run back (that run's late `finish` is still accepted while
  nobody has claimed the scan again); cleared by release, retry and continue.
- `lease_expires_at` — null = nobody holds the scan.
- `waiting_since` — since when it waits in `checking` / `queued`; the queue
  timeout counts from it.
- `failure_count` — failed tries that count against the picture: `timeout`,
  `build`, `interrupted` after a second lost lease, an intake that died twice.
  Two within 30 days in a project make the picture `source_unscannable`.
- `pre_check` — non-null once the intake passed (the working copy exists);
  what decides where a retry goes.
- `candidates` — the current run's rendered candidates (number, the design
  document, issues); `kept_candidate` + `proposal` after a finish.
- `events` — the scan's history, last 200.

**The reaper** (`AiScanReaper`) is the one writer of time-based transitions.
It runs on every scanner `claim` and `tick`, in the nightly purge — and
**lazily on any status or list read that meets an overdue row**
(`AiScans::current()`), so a dead scanner delays nothing a user looks at.

## Credits and spend — two separate controls

- **Ledger** (`ai_credit_ledger`, append-only, FK-less): a start writes
  `reserve` (−1) with the month in Europe/Prague as `period`; the end of that
  try writes exactly one `settle` (0 — it counts) or `refund` (+1), with the
  reserve's period. Unique `(scan_id, try, reason)`; "settle XOR refund" is in
  the insert (`AiScanCredits::close()`). Monthly use = −Σ delta of the period.
  Daily starts = reserves of the day — a refund gives no start back.
- **Spend** (`AiScanSpend`): recorded spend of the day (Europe/Prague) from
  `ai_usage_event` **plus $1.50 for every running run**. A run may start only
  while that plus its own $1.50 fits both ceilings (total, project). Checked
  at upload and at every build claim.
- **Usage** (`ai_usage_event`, FK-less, survives the scan, the project and the
  user): one row per `(scan, run, step)`, opened at claim, upserted from every
  heartbeat (figures are cumulative; `GREATEST`, so a late beat takes nothing
  back), closed with an outcome when the run ends — however it ends. A killed
  run therefore has a closed row with the last heartbeat's figures. Cost: the
  price table of `definition.json` when it knows the model, otherwise the
  engine's own figure.
- **Circuit breaker**: three scans in a row ending in the same failure class
  set the mode to `paused` (`AiScanRuntimeState::scanEnded()`).

## Storage

Scan files are in a second, **private** bucket (`S3_PRIVATE_BUCKET_NAME`,
default `wboost-private`; Flysystem `oneup_flysystem.private_filesystem`; no
anonymous policy): `ai-scan/{projectId}/{scanId}/{secret}/` with
`original.{ext}`, `working.webp`, `preview.webp`, `candidate-{n}.webp`.
`secret` is 128 random bits on the row, in no URL, e-mail or log. Bytes leave
only through `AiScanImageController` (USE permission, buffered,
`Cache-Control: private`) and the scanner API. There is no public or presigned
URL. The app never decodes these files.

Deletion: `discard` deletes the scan's files at once; the nightly purge
expires scans 30 days after their end and deletes directories older than 24 h
whose row is missing or expired; deleting a project deletes
`ai-scan/{projectId}/` (`DeleteProjectHandler`). See `storage-inventory.md`.

The bucket itself: created by `.docker/setup-minio.sh` locally (and by
`app:ai-scan:fake-run` when missing); in production it comes with the scanner
deployment — `app:ai-scan:status` says whether it is reachable.

## Upload

`AiScanUpload::inspect()` reads the HEADER only — magic bytes for the type
(JPEG, PNG, WebP, HEIC; SVG and everything else refused), the byte size
(≤ 10 000 000), and the pixel size the header declares (each side ≤ 8000,
≤ 36 Mpx, short side ≥ 600; HEIC is accepted on bytes alone). Nothing is
decoded in the web process: normalising is the scanner's intake.

## Permissions

`AiScanAccess`, exposed as `AiScanVoter::START` (on a `Project`) and
`AiScanVoter::USE` (on an `AiScan`):

- **start** — an admin, or an editor of a project with `aiScanEnabled` (an
  admin only while `admin_only`), and the mode is `on`;
- **use** — an admin, the requester, or any editor of the scan's project —
  independent of the entitlement, of `admin_only` and of the mode, so switching
  any of them never strands a scan.

| Route | Check |
|---|---|
| `POST /project/{projectId}/ai-scan` (upload) | entitled, else `not_enabled`; mode and every limit answer with their own code |
| `POST /ai-scan/{id}/retry`, `/continue` | USE + entitled (a new start) |
| `GET /ai-scan/{id}`, `GET /ai-scan/{id}/image/{which}`, `POST /ai-scan/{id}/cancel`, `/discard` | USE |
| `GET /ai-scans/mine` | the caller's own scans |
| `POST /project/{projectId}/ai-scan/entitlement` | `ROLE_ADMIN` + session CSRF token `project_ai_scan` |
| `/internal/scanner/*` | the scanner only (below) |

The JSON POSTs use the stateless CSRF id `ai_scan`: send `_token=csrf-token`
from a same-origin fetch (the `Origin` header is what is checked).

**A refusal is an answer, never an exception**: `{ok: false, code, …}` with a
status (`AiScanRefusal::httpStatus()`), thrown inside handlers as
`AiScanRefused` and unwrapped by the controllers — so it books no Sentry
issue. Codes: `docs/ai-scan/architecture.md` §5.6.

## Runtime row and operating mode

`ai_scan_runtime` has one row: `mode` (`on` / `paused` / `off`), `admin_only`,
`max_running`, the two ceilings, daily starts, provider state, when the
scanner was last seen and whether it is too old, the breaker's counters. Read
on every use (never cached), so a change takes effect on the next request:

```bash
docker compose exec web bin/console app:ai-scan:status                 # what is going on
docker compose exec web bin/console app:ai-scan:status --mode=on       # also: paused, off
docker compose exec web bin/console app:ai-scan:status --admin-only=0 --max-running=2
```

`paused` refuses uploads and claims and changes no scan; `off` also fails
every scan in progress as `interrupted`, refunded. `waiting` (shown on a
waiting scan, never stored) is derived from this row: `paused`,
`scanner_absent` (no scanner call for 60 s), `scanner_too_old`,
`provider_blocked`.

## Scanner API — the contract between the app and `scanner/`

Base path **`/internal/scanner`**, served by `web`.

**Who may call.** Both must hold, otherwise **404 with an empty body**:

1. the TCP peer address (`REMOTE_ADDR` — not the forwarded client address)
   lies in `SCAN_NETWORK_CIDR` (comma-separated CIDRs), and the request
   carries neither `X-Forwarded-For` nor `Forwarded`;
2. `Authorization: Bearer <SCANNER_TOKEN>` — the variable may hold
   `current,previous` during a rotation; a value shorter than 24 characters
   counts as not configured.

**Every call** sends `X-Scanner-Protocol: 1` (the app accepts N and N − 1 of
`definition.json`'s `protocolVersion`; anything else is answered
`{ok: false, code: "protocol_too_old"}` and recorded as a scanner that is too
old). **Every per-scan call** also sends `X-Scan-Lease: <leaseToken>` (from
the claim answer) and the claim's `run` — in the JSON body, or `?run=` for
`source` and `artifact`.

**Answers.** JSON `{ok: true, …}` or `{ok: false, code, …}`. The HTTP status
tells the scanner what to do with the run:

| Status | Codes | Meaning |
|---|---|---|
| 200 | `ok: true`; or `protocol_too_old`, `renderer_busy`, `renderer_unavailable`, `candidate_limit`, `missing_artifact`, `invalid_request`, `unsupported_kind` | carry on (retry a busy / unavailable renderer inside the outage tolerance) |
| 409 | `stale_run` (the `run` is not the claim's, or a `finish` that did not apply — with `status`), `lease_lost` (wrong / missing lease token, or the scan left the run) | this run is over: abort |
| 410 | `gone` | the scan does not exist (project deleted): abort |
| 503 | `storage_unavailable` | storage is down: retry inside the tolerance |
| 404 | — | not the scanner (network / token) |

**Calls.**

| Call | Request | Answer |
|---|---|---|
| `POST claim` | `{lane: "intake"\|"build", scannerVersion, instruments: [..], capacity}` | `{ok, mode, job}` or `{ok, mode, job: null, reason}` — `reason`: `null` (nothing to do), `paused`, `off`, `provider_blocked`, `scanner_too_old` (an instrument of `requiresInstruments` is missing), `spend_ceiling`. Runs the reaper first |
| `GET {id}/source?run=N` | — | bytes: the original (intake) or `working.webp` (build) |
| `PUT {id}/artifact/{working\|preview}?run=N` | raw bytes, ≤ 25 MB; intake only | `{ok, bytes}` |
| `POST {id}/beat` | `{run, seq, stage?, note?, noteParams?, round?, usage, resources?}` | `{ok, cancel, mode, leaseExpiresAt}`; `cancel: true` (+ `status`) when the scan was cancelled or interrupted — stop. Extends the lease (build: 120 s). `seq` must grow; an older one changes nothing |
| `POST {id}/render` | `{run, seq, design}` | rendered: `{ok, candidate: n, issues, width, height, image: {contentType: "image/webp", base64}}`; rejected by validation: `{ok, candidate: null, issues}` (nothing stored). Idempotent by `(run, seq)`. At most `caps.candidates` per run, then `candidate_limit` |
| `POST {id}/faces` | `{run, seq, lines: [..≤ 8], faces?: [face strings], size?: px (8–400, default 64)}` | `{ok, width, height, cells: [{face, line, x, y, width, height, fontSize}], image}` — one row per `(face, line)`, black on white, row height `2 × size`, left padding 24 px. Stores nothing |
| `POST {id}/finish` (intake) | `{run, outcome: "passed", preCheck?, sourceMeta?, usage}` · `{outcome: "stopped", reason, …}` · `{outcome: "failed", error: "provider_unavailable", …}` · `{outcome: "refused", …}` | `{ok, applied: true, status, code}`; `passed` needs the working copy stored first (`missing_artifact`) |
| `POST {id}/finish` (build) | `{run, outcome: "proposed", candidate, report, boxes?, usage}` · `{outcome: "failed", error: "provider_unavailable"\|"renderer_unavailable"\|"timeout"\|"build", usage}` · `{outcome: "refused", usage}` | `{ok, applied: true, status, code}`. A report that does not fit the finish contract, or a `candidate` not rendered in this run, ends the scan as `failed build` (answered `status: "failed", code: "build"`) |
| `POST {id}/release` | `{run, usage, reason?: "provider_blocked", providerError?}` | `{ok, applied, status}` — build → `queued`, intake → unclaimed; no restart and no intake loss is consumed |
| `POST tick` | `{scannerVersion, provider?: {state: "ok"\|"blocked", error?}}` | `{ok, mode, providerState, maxRunning, reaped, protocolVersion}` — every 30 s and at start |

`job` in a claim answer:

```json
{
  "id": "…", "kind": "template", "lane": "build", "run": 1, "try": 1, "approximate": false,
  "leaseToken": "64 hex", "leaseSeconds": 120,
  "options": {},
  "source": {"sha256": "…", "contentType": "image/webp", "bytes": null, "width": 900, "height": 1200},
  "definition": {"version": "4-9bc182d2e6d2", "files": {"definition.json": "…", "…": "…"}},
  "context": {
    "fonts": [{"family": "Rubik (Rubik Bold)", "font": "Rubik", "face": "Rubik Bold", "weight": 700, "italic": false}],
    "palette": ["#c8102e"],
    "standInAssetId": "00000000-0000-4000-8000-0000000a15ca",
    "templateMaxLongEdgePx": 5000
  }
}
```

`context` is in build claims only; an intake claim has `leaseSeconds: 150` and
the original's `contentType` / `bytes`. `source.width` / `height` are what the
upload's header declared (null for HEIC).

`usage` is **cumulative for this `(scan, run, lane)`**:
`{provider, model, inputTokens, outputTokens, cacheReadTokens, cacheWriteTokens, engineCostMicroUsd, turns, durationMs}`.

What is stored of what the scanner sends is closed, on purpose — no free text
of a picture reaches a column the screens read:

- `stage` / `note` are the codes of §5.6; `noteParams` only the note's named
  integers; `preCheck` a flat map of codes, numbers and booleans.
- `report` is checked by `AiScanReportValidator` against the finish contract
  (§4.7, the report `scanner/src/finish/report.mjs` builds): `status`,
  `candidate`, `headline`, `unfinished` and the lists `placeholders`, `fonts`,
  `simplifications`, `omitted`, `lowConfidenceTexts`, `shrunk`, `paletteSnaps`
  are required, and the item fields the screens read are typed (`code`, `id`,
  `name` ≤ 40 plain characters, `hex`, `box`, numbers, booleans). Everything
  else the scanner adds (measures, evaluation figures, `boxes`) is accepted
  when it is as closed: numbers, booleans, null and PLAIN strings (≤ 120
  characters, no markup, no line breaks), nested to a small depth.
- `boxes` (what was measured on the kept candidate) keeps numbers, booleans
  and code / id / hex strings, to a bounded depth.

The design document of each candidate is stored as sent — it IS the proposal.

`app:ai-scan:fake-run` (`AiScanFakeRunConsoleCommand`) is the executable
example of this contract: it plays the scanner through these endpoints,
in-process.

## Render tools

- **A candidate persists no template.** `TransientVariant` builds a `Template`
  + `TemplateVariant` in memory, sized by the document's own canvas (long edge
  ≤ 5000 px); `TemplateProposal::candidate()` runs the same preflight
  `preview_design` runs and one `CandidateRenderer` render.
- **Stand-in.** A scan never takes a picture out of the source; places are
  filled later. In candidate renders the reserved asset id
  `AiScanStandIn::ASSET_ID` resolves to one bundled neutral picture — an extra
  entry in the compilation context that only this path passes
  (`DesignPreflight::review(…, $extraAssets)`), stored once in the public
  bucket at `ai-scan/stand-in-v1.png`. After accept (P3) empty places
  reference a per-project gallery row of `FileSource::ScanStandIn`, which no
  gallery listing shows and which can never be moved to the bin
  (`FileUpload::moveToTrash()`).
- **Font faces.** `FontFacesRenderer` renders lines of text in a project's
  faces as one picture with the cell geometry; the scanner measures and ranks.
- **At most two scan renders at once**: `ScanRenderLimiter` holds one of two
  Postgres advisory locks per render; a third caller gets `renderer_busy` at
  once, and a request that dies loses its database session and with it the
  lock.
- **Metric fit.** `FitTextToWidth`: `size = sourceLineWidth ÷
  advanceWidth(face, text, 1 px)` from the font file's advance table — no
  pixels, no browser.

## Logs and Sentry

Ids, codes and counts only — never OCR text, designs, prompts or bytes.
Expected refusals are answers, not exceptions. `AiScanSentryScrubber`
(`before_send`) drops the body, query and auth headers of any event raised on
a scan route. A test plants a marker everywhere a picture's text could travel
and greps a whole run's log records.

## Commands

| Command | Does |
|---|---|
| `app:ai-scan:status [--mode=…] [--admin-only=0\|1] [--max-running=N] [--daily-ceiling-usd=…] [--project-ceiling-usd=…] [--daily-starts-user=N] [--daily-starts-project=N] [--reap]` | shows the state; with options writes the runtime row |
| `app:ai-scan:purge` | reaper, expiry, orphan files, leftover usage rows — nightly cron (to be added to the box's cron with the scanner deployment) |
| `app:ai-scan:fake-run <project-id> <user-email> [--scenario=happy\|stop\|fail\|refuse\|cancel\|hang\|all] [--beats=N] [--beat-interval=S] [--no-render]` | a fake scanner; **refuses in production** |

Local use: `.env.dev` sets a dev token and the local `scan` network
(`compose.yaml`) plus `127.0.0.1` (the fake run calls in-process). A scanner
started on the host reaches the app from the Docker gateway — add that address
to `SCAN_NETWORK_CIDR` in `.env.local`.

```bash
docker compose exec web bin/console app:ai-scan:status --mode=on
docker compose exec web bin/console app:ai-scan:fake-run <project-id> <admin-email> --scenario=all
docker compose exec web bin/console app:ai-scan:status --mode=off
```

## Tests

- Unit: `tests/Value/AiScan`, `tests/Services/AiScan/{AiScanValuesTest,AiScanQueueEstimateTest,AiScanReportValidatorTest}`, `tests/Services/Security/ScannerServiceAuthenticatorTest`.
- Against the database: `tests/Services/AiScan/AiScanTransitionsTest` (every
  state × event), `AiScanPurgeAndStorageTest`.
- Functional: `tests/Controller/Internal/ScannerApiTest`,
  `tests/Controller/AiScan/*` (the permission matrix, every start refusal, the
  admin switch, the commands, the log capture). Shared set-up:
  `tests/AiScanTesting` (a trait).
- **Fault injection** — real races between forked processes, outside DAMA's
  transaction; not in the default run:

  ```bash
  docker compose exec web vendor/bin/phpunit --group fault-injection
  ```

  It commits to the test database and cleans up after itself; needs `pcntl`.

## Traps

- Do not change a scan through the ORM (see "The one rule").
- A new state or event goes into `AiScanTransitionTable` first; both
  transition tests walk every state × event and will name what is missing.
- `SCANNER_TOKEN` / `SCAN_NETWORK_CIDR` have empty defaults in `.env` AND in
  `config/services.php`: a deploy before they exist in the environment must
  boot, inert.
- The peer check reads `REMOTE_ADDR`, not `Request::getClientIp()` — the latter
  honours `X-Forwarded-For` from trusted proxies and would let the public
  router's requests through.
- Advisory locks are per database session and re-entrant: under DAMA's single
  connection a test must hold them from a SECOND connection to see
  `renderer_busy`.
- `resources/ai-scan/definition/` is sent to the scanner verbatim; its numbers
  are mirrored in `AiScanLimits` and `AiScanValuesTest` compares the two.
