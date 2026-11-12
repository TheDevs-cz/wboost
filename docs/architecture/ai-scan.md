# AI sken — the job core (P1) and the review, accept, notifications, access request and MCP (P3, backend)

"AI sken" turns a flat picture of a design into an editable template (later:
a calendar layout). A picture is uploaded, a **scanner** — a separate service
under `scanner/`, built in its own phase — works on it with a model, and the
user reviews a proposal. This file describes what lives in the application:
the job, its states, credits and spend, storage, permissions and the API the
scanner calls. Design and reasons: `docs/ai-scan/architecture.md` (§4–§7),
decisions: `docs/ai-scan/decisions.md`, screens: `docs/ai-scan/ux.md`.

**What exists today:** the job core (P1), proven with a fake scanner
(`app:ai-scan:fake-run`), and the backend of P3 — the review's fixes and
pictures, accept (creating the template), the export rule for unfilled
pictures, notifications, the access request, the JSON endpoints and the MCP
tools `scan_design` and `create_template` (everything from § "Review state"
on). **What does not:** the wizard's pages, scripts and styles (P3 UI — built
on the endpoints below); the scanner on the box and the admin tab (P4);
calendars (P5).

**The feature is inert unless switched on.** Operating mode defaults to `off`,
`admin_only` to on, every project's entitlement to off, and the scanner
endpoints answer 404 while `SCAN_NETWORK_CIDR` / `SCANNER_TOKEN` are unset
(the default, and production today). The only UI is the admin's switch on the
edit-project page (and the `ai:scan` checkbox among a user's MCP scopes on the
admin's user page): P3's backend adds no page, no navigation entry and no card.

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
| Review (P3) | `Services/AiScan/Template/{ApplyFixes,ScanProposal,ScanProposalView}` — replay of fixes, state, the document the screens read; messages `ApplyAiScanFix`, `RateAiScan` |
| Pictures (P3) | `Services/AiScan/Template/{ScanPictureUpload,ScanGalleryFolders,ScanUploads}`; `AiScanStandIn::projectFile()` |
| Accept (P3) | `Message/AiScan/AcceptTemplateScan` + handler |
| Export rule (P3) | `Services/AiScan/Template/{UnfilledPictures,UnfilledExportGuard}` — used by every export controller, API processor and MCP export tool |
| Notifications (P3) | `Services/AiScan/{AiScanNotifications,AiScanUrls}`, message `SendAiScanEmail`, `templates/emails/ai_scan.html.twig` |
| Access request (P3) | `Entity/AiAccessRequest`, messages `RequestAiAccess` / `ResolveAiAccessRequest`, `Services/AiScan/AiAccessRequests` |
| Lists (P3) | `Services/AiScan/AiScanLists` — history, strip, entry card, "my scans" + badge |
| MCP (P3) | `Mcp/Tool/{ScanDesignTool,CreateTemplateTool}`, `McpScope::AiScan` |

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
| `GET /ai-scan/{id}`, `/proposal`, `/image/{which}`; `POST /ai-scan/{id}/cancel`, `/discard`, `/fix`, `/preview`, `/pictures`, `/accept`, `/rating`, `/seen` | USE |
| `GET /ai-scans/mine` | the caller's own scans |
| `GET /project/{projectId}/ai-scans`, `/ai-scan/entry` | `AiScanAccess::canSeeProjectScans()` — an admin or a project editor; a read-only member gets 403 |
| `POST /project/{projectId}/ai-scan/access-request` | `ProjectVoter::EDIT` (the handler refuses whoever can already start scans there) |
| `GET /admin/ai-scan/access-requests`, `POST /admin/ai-scan/access-request/{id}/{approve\|dismiss}` | `ROLE_ADMIN` |
| `POST /project/{projectId}/ai-scan/entitlement` | `ROLE_ADMIN` + session CSRF token `project_ai_scan` |
| `/internal/scanner/*` | the scanner only (below) |
| MCP `scan_design` | scope `ai:scan`, then START / USE per action |

The JSON POSTs use the stateless CSRF id `ai_scan`: send `_token=csrf-token`
from a same-origin fetch (the `Origin` header is what is checked) — as a form
field, in a JSON body, or as the header `X-CSRF-Token`.

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

## Review state (P3)

A `ready` scan carries two more JSON columns, written only by
`ScanProposal::save()` — a conditional update `… AND status = 'ready'` under
the row lock (`SELECT … FOR UPDATE`), so after accept the scan is read-only:

```json
// ai_scan.fixes
{"v": 1, "rev": 7, "name": "Výsledek zápasu",
 "items": {"font:headline": {"type": "choose_font", "face": "Rubik (Rubik Bold)"},
           "text:sub": {"type": "set_text", "text": "…"}, "confirm:sub": {"type": "confirm_text"},
           "place:logo": {"type": "placeholder_to_shape", "op": "shape", "shape": "circle"},
           "omitted:0": {"type": "add_placeholder", "decision": "place"}, "shrink": {…}, "palette": {…}},
 "preview": {"rev": 7, "seq": 3, "stale": false}}
// ai_scan.pictures
{"places": {"foto": {"fileId": "<gallery id>", "kind": "fixed", "fit": "contain"}, "bg": {"none": true}},
 "uploads": ["<gallery ids uploaded FOR this scan>"]}
```

**Replay, not mutation.** `ai_scan.proposal.document` is never changed.
`ApplyFixes::replay()` applies `items` and `places` to it in a fixed order
every time (added places → place removals / merges / shapes → texts, names,
input / fixed → undo of the shrink → font swaps with the metric re-fit →
palette revert → pictures). So "Vrátit" is deleting an item (`undo`), two tabs
meet per item (the last write of an item wins) and the order of clicks never
matters. `rev` grows with every saved change; `preview.rev` is the revision
the stored picture shows.

**Every fix is validated, compiled, saved, rendered.** `ScanProposal::apply()`
refuses a fix that names something the proposal does not have (`invalid_fix`
+ `field`), replays, runs the design preflight on the result (a fix that would
BREAK the design is `invalid_fix`; one that leaves an already broken design
broken is let through — otherwise a font and a picture both gone could never be
repaired), saves. `ApplyAiScanFixHandler` then renders once through the
transient path (`TemplateProposal::candidate()`, one of the two scan render
slots) into `proposal.webp` in the scan's private directory. A renderer that
is busy or away does not undo the fix: the answer says `previewStale: true`
and `POST /ai-scan/{id}/preview` renders again.

### Fixes

`POST /ai-scan/{id}/fix` with `{"fix": {"type": …}}`; MCP `apply` takes the
same objects in `fixes[]`.

| `type` | Fields | Does |
|---|---|---|
| `choose_font` | `group`, `face` (a face string of the project) | every text of the report's font group switches and is re-fitted: `size = source line width ÷ advance at 1 px` (`FitTextToWidth`), from the scanner's `sourceLineWidths`; without a usable measurement the rendered width is kept. "Nahrát správné písmo" = the project's ordinary font upload, then this fix |
| `keep_font` | `group` | "Nechat" — decided, nothing changes |
| `set_text` | `id`, `text` (≤ 2000) | any text |
| `confirm_text` | `id`, `text?` | a doubtful text is right (optionally corrected) |
| `set_input` | `id`, `input: bool` | `vyplňuje uživatel` / `pevný text` (the DSL's `input.locked`) |
| `rename` | `id`, `name` (≤ 40, plain) | a layer's name |
| `undo_shrink` | — | shrunk texts get the scanner's `fittedSize` back |
| `revert_palette` | — | colours snapped to the palette go back to the measured ones |
| `add_placeholder` / `keep_omitted` | `omitted` (index into `report.omitted`) | a left-out thing becomes a place `misto-{n+1}` over its box / stays out |
| `remove_placeholder` | `id`, `colour?` | the place goes; a removed BACKGROUND becomes a colour (a design always has a background) |
| `merge_placeholders` | `ids` (≥ 2, no background) | one place over their common box; the lowest in the stack keeps its id |
| `placeholder_to_shape` | `id`, `shape` `rectangle` \| `circle` \| `star`, `colour?` | see "Deviations" for the colour |
| `set_kind` | `id`, `kind` `fixed` \| `input` | "Součást šablony" / "Mění se při každém exportu"; refused on a background |
| `set_fit` | `id`, `fit` `contain` \| `cover` | |
| `set_picture` | `id`, `fileId` \| `null` | a gallery picture of the project / "Odebrat obrázek" |
| `no_picture` | `id` | "Bez ukázky" / "Nechat šedé pozadí" — decided, stays empty |
| `assign_pictures` | `fileIds` (≤ 50) | gallery multi-pick: into the empty places in card order; answers `assigned` |
| `move_picture` | `from`, `to` | "Patří jinam" — the two places swap |
| `set_name` | `name` (3–30, the template form's limits) | the template's name |
| `undo` | `key` (an item key) | "Vrátit" of anything in `items` |

### The proposal document

`GET /ai-scan/{id}/proposal` → `{ok, scan: <status document>, proposal}`;
`proposal` (`ScanProposalView`, null without a template proposal) is codes,
numbers, ids, boxes and the design's own texts — no prose:

- `readOnly`, `name`, `nameChosen`, `unfinished`, `expiresAt`, `revision`,
  `preview {which: "candidate"|"proposal", seq, stale}` (the picture is
  `GET /ai-scan/{id}/image/proposal?v={seq}`), `canvas`, `source` (the size
  the boxes are measured in), `rating`;
- `counts {texts, shapes, places, background}` — `background` is true for a
  colour / gradient background only (a background photo is a place), and
  `toDecide` — the counter of the summary line and the tab;
- `elements[]` in stack order: `id`, `type` `text` \| `shape` \| `place` \|
  `background`, `name`, `box` (source px) and per type `text`, `input`,
  `font`, `size`, `colour`, `doubtful` / `shape`, `fill`;
- `placeholders[]` in card order: `id`, `number`, `name`, `role`,
  `background`, `added`, `kind`, `kindLocked`, `fit`, `box` (source px),
  `canvasBox`, `width`, `height`, `decidedEmpty`, `picture` (`fileId`, `url`,
  `name`, `width`, `height`, `uploaded`, `tooSmall`, `fitApplied`, `missing`);
  `pictures {total, filled, unfilled, greyBackground}` — `unfilled` is the
  tail of the create button;
- `decisions[]`: `key`, `type` `font` (with `pattern`
  `nearest_available` \| `undetermined`, `group`, `role`, `face`,
  `sizeChangePercent`, `texts`, `boxes`) \| `doubtful_text` (`id`, `reason`,
  `text`, `box`) \| `omitted` (`omitted`, `code`, `box`, `placeId`) \|
  `missing_font` / `missing_picture` (F15), `decided`, `decision`, `fixes[]`
  (the fix types it accepts). The left-out rows count as ONE thing to decide;
- `information[]`: `font_match` (the measured-match pattern), `shrunk` and
  `palette` (with `undo` — the fix type — `undoKey` and `undone`),
  `simplification` (`simplification` = the code, `box`), `pictures_not_taken`,
  `note`;
- `fonts[]` — the project's face strings for the picker.

The original's typeface is never named: `sourceLooksLike` stays in the stored
report and is not part of this document.

## Pictures (P3)

Pictures added to a proposal's places are **ordinary gallery files**.
`POST /ai-scan/{id}/pictures` (`ScanPictureUpload`) stores each file through
the gallery's own command (`UploadFile` — HEIC → JPEG, pixel size recorded)
in the folder `AI sken / {název}` (`ScanGalleryFolders`, found by
`FileDirectory::$systemKey` `ai-scan` / `ai-scan:{scanId}`), with the gallery's
limits: **10 000 000 bytes** per file, pictures only, ≤ 30 files a request.
Their ids are remembered in `pictures.uploads`: `ScanUploads::trash()` moves
them to the gallery's Koš when the proposal is discarded or expires (the
nightly purge); accept keeps them. Pictures picked from the gallery are never
trashed. Nothing new stores a storage path, so `BuildStorageReferenceIndex` is
unchanged.

Kind and fit at accept (`ApplyFixes::pictures()`):

| Place | Filled | Empty |
|---|---|---|
| `fixed` ("Součást šablony") | a decorative image — the compiler contain-fits it into the box | a decorative image on the **project's stand-in row** |
| `input` ("Mění se…") | a fillable slot with the picture as its sample; with `contain` the frame takes the sample's ratio | a fillable slot without a sample |
| background | fillable background with the sample | fillable background on the stand-in row (a background always carries a picture) |

`cover` is the browser's crop of the chosen picture to the place's ratio before
upload; a picture of another ratio is fitted whole (`picture.fitApplied` says
`contain`). The project's stand-in row is `AiScanStandIn::projectFile()`:
one `FileUpload` of `FileSource::ScanStandIn` per project, a copy of the
bundled picture under `file-upload/{projectId}/`, created at the first accept
that needs it.

## Accept (P3)

`AcceptTemplateScan` (rule 14) — under the row lock, in the bus transaction:
a repeat on an `accepted` scan answers with the first ids (`created: false`);
`staleReferences()` (a face the project no longer has, a picture trashed)
→ `stale_references`, nothing created, the proposal document lists
`missing_font` / `missing_picture`; the template document
(`ScanProposal::document($scan, $state, $standInRowId)`) is compiled and
rendered on a transient variant BEFORE anything exists (`renderer_busy` leaves
nothing behind); then `AddTemplate` (uncategorised), `AddTemplateVariant`,
`EditTemplateVariantCanvasEditor`, `StoreTemplateVariantPreviewImage`, and
`AiScanTransitions::accept()`.

The template's size: the proposal's canvas in px, or — when the upload sent
`options {targetUnit: px|mm|cm, targetWidth, targetHeight}` with the picture's
ratio (± 2 %) and a long edge ≤ 5000 px — that format, the document scaled to
it (`ApplyFixes::scaled()`); a print size comes without bleed.

## Unfilled pictures — the export rule (P3, O11)

`UnfilledPictures` finds canvas objects whose `assetPath` is still the
project's stand-in file: a decorative one is unfilled until replaced in the
editor; a fillable one (the background) only for an export that supplies no
picture for its input. `UnfilledExportGuard` applies it on every export path:

| Path | Without `allowUnfilled` |
|---|---|
| Web: variant download, publish, group export (+ variant), document export | nothing rendered or recorded; **409** — JSON `{ok: false, code: "unfilled_pictures", count, confirmField: "allowUnfilled", editorUrl}` for `Accept: application/json` / XHR (publish: always), otherwise the `export_failed` page with the Czech sentence. The dialog sends the form again with `allowUnfilled=1` |
| REST API: the three export operations | **400** `{error, code: "unfilled_pictures", count, variantId, editorUrl}` unless the body has `allowUnfilled: true` |
| MCP: `export_variant`, `export_document` | a tool error starting `unfilled_pictures:` unless `allowUnfilled: true` |

`UnfilledPictures::count($variant)` is the number behind the pill
`K doplnění: {n}`.

## Notifications (P3)

`AiScanNotifications`: **seen** (`POST /ai-scan/{id}/seen`, the REQUESTER's
look only; MCP `status` / `result` on an ended `mcp` scan count too) clears the
badge and cancels the e-mail. Every transition into `ready`, `stopped`,
`failed` or `refused` (`AiScanTransitions::applyReturning()`) queues one
`SendAiScanEmail` on the `async` transport with a 120 s delay; the handler
decides again from the row and claims `notified_at` with a conditional update
(`… AND notified_at IS NULL AND seen_at IS NULL AND finished_at <= now − 110 s`),
so a late, repeated or stale message sends nothing twice, and a retry (which
clears `seen_at` / `notified_at`) gets its own mail. The nightly purge
(`sendDue()`) sends what no message sent (scans ended within 48 h) and the
**one reminder** three days before a `ready` proposal expires (`reminded_at`).
To the requester only; none when the account is gone. The mail's button opens
`AiScanUrls::scan()` — the route **`ai_scan_page`** (`projectId`, `scanId`),
or the project's template list while that route does not exist.

## Access request (P3)

`ai_access_request` (`AiAccessRequest`): user (set null) + copied e-mail and
name, project (cascade), `origin` `templates` \| `calendars`, `message`
(≤ 500), `status` `open` \| `approved` \| `dismissed`, resolved at / by.
`RequestAiAccess`: one OPEN request per user and project (a repeat answers with
the open one; partial unique index `uniq_ai_access_request_open`), at most
five requests of a user in 24 hours (`rate_limited`, counted in the table),
`wrong_state` for somebody who can already start scans there; e-mailed to the
admins' notification list (`SIGNUP_NOTIFICATION_EMAILS`) with a link to the
project's switch. `ResolveAiAccessRequest`: once (conditional update);
`approve` switches the project on through `SetProjectAiScan`, approves the
project's other open requests and e-mails each requester.

## Endpoints of the wizard (P3) — JSON, for the UI

All answers are JSON (`{ok: true, …}` / `{ok: false, code, …}`) except the
pictures. Refusal statuses: `AiScanRefusal::httpStatus()` — 403 `not_enabled`;
409 `wrong_state`, `stale_references`, `no_retries_left`, `source_unscannable`;
422 `invalid_fix`, `unsupported_image`, `consent_required`, `no_fonts`; 429
limits, `rate_limited`; 503 `temporarily_unavailable`, `renderer_busy`.
Every POST needs the CSRF token (above).

| Route name | Method, path | Request | Answer |
|---|---|---|---|
| `ai_scan_start` | `POST /project/{projectId}/ai-scan` | multipart: `picture`, `kind`, `requestId`, `consent`, `options` (JSON; `targetUnit` / `targetWidth` / `targetHeight` = the format), `calendarId` | 201 `{scan}`; every start refusal |
| `ai_scan_status` | `GET /ai-scan/{id}` | — | `{scan}` — the status document (state, `waiting`, `restarted`, stage, note, `round`, `previewSeq`, queue position and estimates, `retriesLeft`, `canRetry`, `canContinue`, `code`, `expiresAt`, result ids) |
| `ai_scan_proposal` | `GET /ai-scan/{id}/proposal` | — | `{scan, proposal}` (above) |
| `ai_scan_image` | `GET /ai-scan/{id}/image/{original\|working\|preview\|candidate\|proposal}` | `?n=` (candidate), `?v=` (cache) | the bytes, `Cache-Control: private, max-age=300`; 404 when gone |
| `ai_scan_fix` | `POST /ai-scan/{id}/fix` | `{fix: {type, …}}` (JSON body, or a form field holding the JSON) | `{previewStale, assigned, proposal}`; `invalid_fix` (+ `field`), `wrong_state` |
| `ai_scan_fix` | `POST /ai-scan/{id}/preview` | — | `{previewStale, proposal}` |
| `ai_scan_pictures` | `POST /ai-scan/{id}/pictures` | multipart `pictures[]`, optional `place` (then exactly one file) | `{assigned, fileIds, previewStale, proposal}`; `invalid_fix` with `field` `pictures` \| `size` \| `type` (+ `file`), `wrong_state` |
| `ai_scan_accept` | `POST /ai-scan/{id}/accept` | — | `{templateId, variantId, editorUrl, elements, unfilledPictures, created, scan}`; `stale_references` (+ `fonts`, `pictures`, `proposal`), `renderer_busy`, `invalid_fix`, `wrong_state` |
| `ai_scan_action` | `POST /ai-scan/{id}/{cancel\|discard\|retry\|continue}` | — | `{scan}`; `wrong_state`, `no_retries_left`, start refusals |
| `ai_scan_feedback` | `POST /ai-scan/{id}/rating` | `rating` `1` \| `-1`, `note?` (≤ 500, kept after a 👎 only) | `{ok}`; `invalid_fix`, `wrong_state` (not `ready` / `accepted`) |
| `ai_scan_feedback` | `POST /ai-scan/{id}/seen` | — | `{seen: bool}` |
| `ai_scans_mine` | `GET /ai-scans/mine` | — | `{scans: [row], badge: {inProgress, attention}}` |
| `ai_scans_project` | `GET /project/{projectId}/ai-scans` | `?view=history` (default) + `?filter=all\|templates\|calendars\|waiting\|done\|failed`, or `?view=strip&kind=` | `{scans: [row], templates: {templateId: scanId}}` (templates made from a scan in the last 7 days — the pill `Ze skenu`) |
| `ai_scan_entry` | `GET /project/{projectId}/ai-scan/entry` | `?kind=` | `{entry: {state, canStart, requestSentAt, credits {limit, used, left}, nextMonth, consent {version, given}, fonts {faces}, queue {position, estimatedStartSeconds, estimatedFinishSeconds}, scans, historyCount}}` — `state`: `enabled` \| `admin_preview` \| `locked` \| `request_sent` \| `limit_reached` \| `service_waiting` |
| `ai_scan_access_request` | `POST /project/{projectId}/ai-scan/access-request` | `origin`, `message?` | 201 / 200 `{id, created, sentAt, email}`; `rate_limited`, `wrong_state` |
| `ai_scan_access_requests` | `GET /admin/ai-scan/access-requests` | `?projectId=` | `{requests: [{id, projectId, projectName, requesterName, requesterEmail, origin, message, createdAt}]}` |
| `ai_scan_access_request_resolve` | `POST /admin/ai-scan/access-request/{id}/{approve\|dismiss}` | — | `{status}`; `wrong_state` |

A list **row** is the status document plus `projectId`, `projectName`, `name`
(the template's, the proposal's, else the file name), `fileName`, `mine`,
`requestedBy`, `channel`, `seen`, `hasProposal`, `hasPicture`, `actions[]`
(`open_proposal`, `open_template`, `retry`, `continue`, `cancel`, `discard`).
The strip shows somebody else's stopped / failed / refused scan for 24 hours.

Route names the pages must use (links in e-mails and MCP answers):
**`ai_scan_page`** (`/project/{projectId}/sablony/z-obrazku/{scanId}`) and
**`ai_scan_history`**.

## MCP (P3)

`scan_design` (`ScanDesignTool`, scope `ai:scan` — implies nothing, off by
default): `start`, `status`, `result`, `apply`, `list`, `cancel`, `retry`,
`continue`; `restore` answers `wrong_state` until calendar scans exist. Every
action dispatches the web's messages with channel `mcp`; refusals are tool
errors starting with the code. `start` never gives consent
(`consent_required` until the user agreed in the web). `apply` saves its
fixes without a render each and renders once at accept. `create_template`
(`CreateTemplateTool`, scope `templates:design`) creates an empty standalone
template + variant. The tools' docblocks are their descriptions and are
written to be enough without the plugin skill.

## Deviations from `docs/ai-scan/ux.md` (backend, P3)

- **Project logo pre-fill** is left out, as ux.md S4b allows: a project stores
  an icon path, not a reusable gallery file.
- **"Změnit na tvar … in the colour measured there"**: the report carries no
  colour per place and the app does not decode pictures. The fix takes an
  optional `colour` (the browser can sample the original it already draws);
  without it the shape gets the first of the report's `originalColours`, else
  a neutral grey.
- **An empty "Součást šablony" place** is a decorative image on the stand-in:
  the compiler contain-fits the square stand-in into the place's box, so it
  shows as a centred grey square, not a grey box of the place's size.
- **`Vyplnit` (cover)** relies on the browser's crop; the server fits a
  picture of another ratio whole rather than distorting it.
- **The 20 s render timeout** of architecture.md §4.6 is the renderer client's
  own timeout; a review render is one attempt and never retried.
- MCP `start` takes no `name` (the name is given at `apply`).

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
- P3: `tests/Controller/AiScan/{AiScanReviewTest,AiScanReviewPermissionsTest,AiScanNotificationsAndAccessTest}`,
  `tests/Mcp/Tool/{ScanDesignTest,CreateTemplateTest,ExportUnfilledPicturesTest}`,
  `tests/Controller/UnfilledPicturesWebExportTest`, `tests/Api/UnfilledPicturesApiExportTest`,
  `tests/Services/AiScan/Template/UnfilledPicturesTest`. `AiScanTesting::ready()`
  makes a `ready` scan with a rich proposal.
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
- A FILLED place is an ordinary picture in the replayed document: ask
  `ScanProposal::replay()['places']` which ids are places, never the document.
- Fixes are checked with the preflight inside the request; a new fix type
  needs its row in § "Fixes", in `ScanDesignTool`'s description and a test.
- The stand-in is recognised by `assetPath`, not `assetId`: the path is what
  the renderer needs, so it cannot go stale when the editor swaps a picture.
- `resources/ai-scan/definition/` is sent to the scanner verbatim; its numbers
  are mirrored in `AiScanLimits` and `AiScanValuesTest` compares the two.
