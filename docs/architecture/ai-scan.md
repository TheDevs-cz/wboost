# AI sken — the job core (P1), the review, accept, notifications, access request and MCP (P3 backend) and the wizard (P3 UI), the admin tab and operating e-mails (P4), calendar scans (P5)

"AI sken" turns a flat picture of a design into an editable template — or a
picture of one desk-calendar page into the layout of a calendar. A picture is
uploaded, a **scanner** — a separate service
under `scanner/`, built in its own phase — works on it with a model, and the
user reviews a proposal. This file describes what lives in the application:
the job, its states, credits and spend, storage, permissions and the API the
scanner calls. Design and reasons: `docs/ai-scan/architecture.md` (§4–§7),
decisions: `docs/ai-scan/decisions.md`, screens: `docs/ai-scan/ux.md`.

**What exists today:** the job core (P1), proven with a fake scanner
(`app:ai-scan:fake-run`); the backend of P3 — the review's fixes and
pictures, accept (creating the template), the export rule for unfilled
pictures, notifications, the access request, the JSON endpoints and the MCP
tools `scan_design` and `create_template` (everything from § "Review state"
on); and the **wizard in the browser** (§ "UI"): the page "Šablona z obrázku",
the card in "Nová šablona", the scan cards above the template list, "Historie
skenů", the shell's badge and toast, the export dialog and the admin's
switches; and the app side of P4: the **admin tab "AI skeny"** on
`/admin/usage` (§ "Admin tab") and the **operating e-mails to admins** with
`app:ai-scan:watch` (§ "Operating e-mails"); and **calendar scans** (P5,
§ "Calendar scans"): the calendar adapter's render tools, accept with a
restorable snapshot, „Vrátit původní“, the marker `calendar.layout_origin`,
MCP `scan_design` for calendars and the step-2 panel of the calendar wizard.
The scanner on the box is infrastructure — `docs/ai-scan/runbook.md`.

**The feature is inert unless switched on.** Operating mode defaults to `off`,
`admin_only` to on, every project's entitlement to off, and the scanner
endpoints answer 404 while `SCAN_NETWORK_CIDR` / `SCANNER_TOKEN` are unset
(the default, and production today). In that state the ONE thing a user sees
is the locked card "Šablona z obrázku" with a live "Požádat o přístup" (an
editor of a project; a viewer sees nothing): no strip, no badge, no request
from any page (`AiScanListSurfacesTest`, `AiScanShellAndAdminTest`). An admin
switches a project on in "Funkce Premium" on the edit-project page (or on the
user's page, or in the admin tab) and the mode in the admin tab "AI skeny" or
with `app:ai-scan:status`.

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
| Commands | `app:ai-scan:status`, `app:ai-scan:watch`, `app:ai-scan:purge`, `app:ai-scan:fake-run` |
| Admin tab (P4) | `Query/{GetAiUsageOverview,AiUsageOverview}`, `Controller/Admin/{AdminUsageController,SetAiScanRuntimeController}`, `templates/admin/_usage_ai_scans.html.twig`, `ai-*` in `assets/styles/nocturne/admin.css` |
| Operating e-mails (P4) | `Value/AiScan/AiScanAlertCode`, `Services/AiScan/{AiScanConditions,AiScanCondition,AiScanAlerts}`, `Entity/AiScanAlert`, `ConsoleCommands/AiScanWatchConsoleCommand`, `templates/emails/ai_scan_alert.html.twig` |
| Review (P3) | `Services/AiScan/Template/{ApplyFixes,ScanProposal,ScanProposalView}` — replay of fixes, state, the document the screens read; messages `ApplyAiScanFix`, `RateAiScan` |
| Pictures (P3) | `Services/AiScan/Template/{ScanPictureUpload,ScanGalleryFolders,ScanUploads}`; `AiScanStandIn::projectFile()` |
| Accept (P3) | `Message/AiScan/AcceptTemplateScan` + handler |
| Export rule (P3) | `Services/AiScan/Template/{UnfilledPictures,UnfilledExportGuard}` — used by every export controller, API processor and MCP export tool |
| Notifications (P3) | `Services/AiScan/{AiScanNotifications,AiScanUrls}`, message `SendAiScanEmail`, `templates/emails/ai_scan.html.twig` |
| Access request (P3) | `Entity/AiAccessRequest`, messages `RequestAiAccess` / `ResolveAiAccessRequest`, `Services/AiScan/AiAccessRequests` |
| Lists (P3) | `Services/AiScan/AiScanLists` — history, strip, entry card, "my scans" + badge |
| MCP (P3) | `Mcp/Tool/{ScanDesignTool,CreateTemplateTool}`, `McpScope::AiScan` |
| Wizard page (P3 UI) | `Controller/AiScan/AiScanPageController` (`ai_scan_new`, `ai_scan_page`), `templates/ai_scan/page.html.twig`, `assets/controllers/ai_scan_page_controller.js`, `assets/ai-scan/page/**` |
| Lists (P3 UI) | `Controller/AiScan/AiScanHistoryController` (`ai_scan_history`), `templates/ai_scan/{history,_entry_card}.html.twig`, `Twig/AiScanExtension`, `assets/controllers/ai_scan_{entry,strip,history}_controller.js`, `assets/ai-scan/lists.js` |
| Shell, export, admin (P3 UI) | `Twig/{AiScanShellExtension,AiScanExportExtension}`, `templates/_shell/_ai_scan_signal.html.twig`, `assets/controllers/ai_scan_{shell,export_guard,admin}_controller.js`, `assets/ai-scan/export-guard.js`, `assets/shell/toast.js` |
| Shared browser code | `assets/ai-scan/{kit,copy,file-checks}.js`, `assets/styles/nocturne/ai-scan.css` |
| Calendar scans (P5) | `Services/AiScan/Calendar/{CalendarCandidate,CalendarCandidatePicture,CalendarScanProposal,CalendarScanProposalView,CalendarScanPictures}`, messages `AcceptCalendarScan` / `RestoreCalendarScan` + handlers, `Controller/Calendar/CalendarScanController`, `Calendar::$layoutOrigin`, `CalendarPageRenderer::renderDocument()`; browser: `assets/calendar/ui/{step2,scan,topbar}.js` — `calendars.md` |

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
| 14 | `ready` → `accepted` (does not move `expires_at`) | `accept` — called by `AcceptTemplateScan`; `acceptCalendar` (stores the snapshot in `restore`) — called by `AcceptCalendarScan` | — |
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
  engine's own figure. **Cache writes are priced by lifetime**: the scanner
  sends the total (`cacheWriteTokens`) and its split (`cacheWrite5mTokens`,
  `cacheWrite1hTokens`); the 1-hour part is stored in `cache_write_1h_tokens`
  and charged at `cacheWrite1h` (2 × input), the rest at `cacheWrite`
  (1.25 ×). A payload with the total only is all 5-minute.
- **Usage belongs to the run, not to the lease.** A usage row keeps the hash
  of the lease token that opened it (`ai_usage_event.lease_token_hash`). A
  `beat`, `finish` or `release` of a run that is no longer the scan's latest
  claim — replaced after a lost lease, released, retried, or the scan row is
  gone — is still refused (409 / 410) and changes no state, but its `usage`
  is recorded on that run's own row (`ScannerApi::recordReplacedRun()`):
  spend that happened is never dropped, and the ceilings count it.
- **Circuit breaker**: three scans in a row ending in the same failure class
  set the mode to `paused` (`AiScanRuntimeState::scanEnded()`).

## Storage

Scan files are in a second, **private** bucket (`S3_PRIVATE_BUCKET_NAME`,
default `wboost-private`; Flysystem `oneup_flysystem.private_filesystem`; no
anonymous policy): `ai-scan/{projectId}/{scanId}/{secret}/` with
`original.{ext}`, `working.webp`, `preview.webp`, `candidate-{n}.webp`,
`proposal.webp` (templates) and `picture-{slot}.{ext}` (pictures held for a
calendar proposal's places).
`secret` is 128 random bits on the row, in no URL, e-mail or log. Bytes leave
only through `AiScanImageController` (USE permission, buffered,
`Cache-Control: private`) and the scanner API. There is no public or presigned
URL. The app never decodes these files.

Deletion: `discard` deletes the scan's files at once; the nightly purge
expires scans 30 days after their end and deletes directories older than 24 h
whose row is missing or expired; deleting a project deletes
`ai-scan/{projectId}/` (`DeleteProjectHandler`). See `storage-inventory.md`.

The bucket itself: created by `.docker/setup-minio.sh` locally (and by
`app:ai-scan:fake-run` when missing); in production the box's `deploy.sh`
asserts "exists, no anonymous policy" on every deploy (infra repo
`~/www/lily.srv/apps/wboost/deploy.sh`, decision D87). `app:ai-scan:status`
says whether it is reachable — by LISTING the prefix: a HEAD of an object in a
bucket that does not exist is an ordinary 404 and proves nothing.

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
| `GET /project/{projectId}/sablony/z-obrazku` (`ai_scan_new`, the wizard's page) | `canSeeProjectScans()` (403), then the entitlement — without it a redirect to the template list |
| `GET /project/{projectId}/sablony/z-obrazku/{scanId}` (`ai_scan_page`) | USE; 404 for a scan of another project |
| `GET /project/{projectId}/sablony/historie-skenu` (`ai_scan_history`) | `canSeeProjectScans()` |
| `POST /project/{projectId}/ai-scan/access-request` | `ProjectVoter::EDIT` (the handler refuses whoever can already start scans there) |
| `GET /admin/ai-scan/access-requests`, `POST /admin/ai-scan/access-request/{id}/{approve\|dismiss}` | `ROLE_ADMIN` |
| `POST /project/{projectId}/ai-scan/entitlement` | `ROLE_ADMIN` + session CSRF token `project_ai_scan` |
| `GET /admin/usage` (tab "AI skeny"), `POST /admin/ai-scan/runtime` (`admin_ai_scan_runtime`) | `ROLE_ADMIN`; the POST + session CSRF token `admin_ai_scan_runtime` |
| `POST /calendar/{calendarId}/scan/{scanId}/{accept\|restore\|picture}` (`calendar_scan`) | `calendar_edit` on the calendar (the designer) AND USE on the scan; a scan of another calendar is 404. No `/k/{token}` twin exists |
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
scanner was last seen and whether it is too old, the breaker's counters and
`breaker_tripped_at` (set when the BREAKER pauses the feature, cleared
whenever an admin sets a mode — it is what tells its pause from one set by
hand). Read
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

The same row is written from the admin tab (below). `SetAiScanRuntime` with
nothing set writes nothing — ask `changesNothing()`, never `== new
SetAiScanRuntime()` (loosely compared, `false` and `0` equal "leave as it
is": `--admin-only=0` alone used to be ignored).

## Admin tab "AI skeny" (P4)

The fourth tab of `/admin/usage` (`#ai-skeny`; ux.md §9.4) — the web version
of `app:ai-scan:status`. `GetAiUsageOverview::overview()` reads everything
(raw SQL, no entity, **no reaper**: looking at the page changes nothing) and
`templates/admin/_usage_ai_scans.html.twig` draws four blocks:

- **Provoz** — the mode segment, "Jen administrátoři", the three numbers
  (denní strop, strop projektu, souběžně), the facts line (waiting, oldest
  waiting, running, scanner last seen + version, provider) and the spend of
  today against the ceiling (with what running scans reserve) and of the
  month. Rose `.notice`s, only while the mode is not `off`:
  `scanner_absent` (the users' 60 s), `scanner_too_old`, `provider_blocked`,
  `spend_ceiling` (today's total + one run's budget no longer fits — what
  refuses uploads), `breaker_tripped`.
- **Žádosti o přístup** — the open requests of every project; each row is its
  own `ai-scan-admin` controller (the existing JSON endpoints).
- **Projekty** — projects with AI scan on or with any use; the others fold
  under "Ostatní projekty". The switch is the edit-project form
  (`project_ai_scan_set`) with `return_to=admin_ai_scans`, which brings the
  admin back here. Month / total = counted scans (ledger), cost = usage rows.
- **Skeny** — every scan in progress (oldest first, tinted), then the last
  50 ended: when, project, user, module, state + code, try (↻ = restarted),
  duration, tokens, cost, credit of the current try (`rezervován` /
  `započten` / `vrácen`), rating, channel, memory / CPU (`resources.peakRssMb`
  / `cpuSeconds` of the last heartbeat) and the links "Otevřít sken"
  (`ai_scan_page`) and "Otevřít projekt".

**Writes** are plain forms (never nested) to `POST /admin/ai-scan/runtime`
(`SetAiScanRuntimeController`, route `admin_ai_scan_runtime`), which
dispatches the same `SetAiScanRuntime` the console command does — so a change
is effective on the next request and `off` runs rule 13a. Fields, all
optional, only what is sent changes: `mode` `on` \| `paused` \| `off`,
`confirmed`, `admin_only` `0` \| `1`, `daily_ceiling_usd`,
`project_ceiling_usd` (0–1000, decimal comma accepted), `max_running` (1–8).
**`mode=off` needs `confirmed=1`** unless the mode is `off` already: the
"Vypnuto" button opens a Bootstrap modal whose own form sends it. A value
that makes no sense is a flash notice and a redirect, never an exception.
The answer is always a redirect to `/admin/usage#ai-skeny`.

Tables become cards in a narrow container (`.ai-wrap` is a size container;
each cell carries its label in `data-l`). The tab list lives in
`usage_stats_controller.js` (`TABS`).

Not built of ux.md §9.4: a row menu (the two links are plain), "Zobrazit
hodnocení" as a dialog (the note of a 👎 is the pill's `title`), the review
page's link "Využití tohoto skenu", and paging of the scans table.

## Operating e-mails (P4)

`AiScanConditions::evaluate()` reads the runtime row, the queue and today's
usage and answers one `AiScanCondition` per `AiScanAlertCode`:

| Code | True when | Since |
|---|---|---|
| `scanner_absent` | no scanner call for 180 s (or never), and it is not "too old" | `scanner_seen_at` |
| `scanner_too_old` | `scanner_too_old` | first seen by the watch |
| `provider_blocked` | `provider_state = blocked` — announced after it lasted 10 min | first seen by the watch |
| `breaker_tripped` | mode `paused` AND `breaker_tripped_at` set | `breaker_tripped_at` |
| `spend_80` | RECORDED spend of the day ≥ 80 % of the daily ceiling, and one more run still fits | first seen |
| `spend_ceiling` | recorded spend + one run's budget > the daily ceiling (`AiScanSpend::dayExhausted()`) | first seen |
| `queue_stalled` | the oldest `checking` / `queued` scan waits > 15 min | its `waiting_since` |

The spend conditions use recorded spend on purpose: it only grows within a
day, while the total with reservations goes up and down with every run.

`AiScanAlerts::run()` — what **`app:ai-scan:watch`** runs — sends to the
admins' notification list (`SIGNUP_NOTIFICATION_EMAILS`, as the access
request does), with one row per code in **`ai_scan_alert`** (`active_since`,
`opened_at`, `last_sent_at`, `cleared_at`):

- a condition that is true and not announced is announced once
  (`opened_at`); `provider_blocked` only after its 10 minutes;
- while it lasts: **one reminder per 24 h** (`last_sent_at`);
- when an announced condition ends: **one "back to normal"** mail — except
  `spend_80`, which closes silently;
- a condition that comes back is not announced again sooner than 30 min
  after its last mail (`REOPEN_QUIET_SECONDS`);
- **mode `off`: nothing opens and nothing is reminded**; a condition that was
  announced and really ended is still closed with its mail (and one that
  still holds stays open, silently);
- no recipients configured → nothing is evaluated.

**Every mail is claimed first** by a conditional update of the row
(`claimOpen` / `claimReminder` / `claimClose` — "… WHERE opened_at IS NULL",
"… WHERE last_sent_at = <what I read>", "… WHERE opened_at = <what I read>");
whoever changed the row sends, so two runs at once send one mail. A mail the
mailer refuses gives its claim back (the next run sends it) and the command
exits 1. The mails are fixed Czech sentences with codes, numbers and times:
what happened, since when, what to do, a button to `/admin/usage#ai-skeny`.

Not built of architecture.md §7's list: "a project paused for refusals"
(nothing pauses a project).

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
| 409 | `stale_run` (the `run` is not the claim's, or a `finish` that did not apply — with `status`), `lease_lost` (wrong / missing lease token, or the scan left the run) | this run is over: abort. The `usage` of a `beat` / `finish` / `release` is recorded all the same when the lease token is one the app handed out for that run |
| 410 | `gone` | the scan does not exist (project deleted): abort (usage recorded as above) |
| 503 | `storage_unavailable` | storage is down: retry inside the tolerance |
| 404 | — | not the scanner (network / token) |

**Calls.**

| Call | Request | Answer |
|---|---|---|
| `POST claim` | `{lane: "intake"\|"build", scannerVersion, instruments: [..], capacity}` | `{ok, mode, job}` or `{ok, mode, job: null, reason}` — `reason`: `null` (nothing to do), `paused`, `off`, `provider_blocked`, `scanner_too_old` (an instrument of `requiresInstruments` is missing), `spend_ceiling`. Runs the reaper first. **A build claim hands out only kinds this scanner can build**: a kind may name instruments of its own (`kinds.<kind>.requiresInstruments` — calendars: `mm`); a scanner without them keeps getting the other kinds and never sees that kind's scans (they wait; after 60 min `queue_timeout`, refunded) |
| `GET {id}/source?run=N` | — | bytes: the original (intake) or `working.webp` (build) |
| `PUT {id}/artifact/{working\|preview}?run=N` | raw bytes, ≤ 25 MB; intake only | `{ok, bytes}` |
| `POST {id}/beat` | `{run, seq, stage?, note?, noteParams?, round?, usage, resources?}` | `{ok, cancel, mode, leaseExpiresAt}`; `cancel: true` (+ `status`) when the scan was cancelled or interrupted — stop. Extends the lease (build: 120 s). `seq` must grow; an older one changes nothing |
| `POST {id}/render` | template: `{run, seq, design}` · calendar: `{run, seq, commands}` (§ "Calendar scans") | rendered: `{ok, candidate: n, issues, width, height, image: {contentType: "image/webp", base64}}` (+ for a calendar `view`, `page`, `preset`, `layout`, `features`); rejected by validation: `{ok, candidate: null, issues}` (nothing stored). Idempotent by `(run, seq)`. At most `caps.candidates` per run, then `candidate_limit` |
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

A calendar build's `context` also carries `calendar` (§ "Calendar scans") and
its `fonts` may be empty.

`context` is in build claims only; an intake claim has `leaseSeconds: 150` and
the original's `contentType` / `bytes`. `source.width` / `height` are what the
upload's header declared (null for HEIC).

`usage` is **cumulative for this `(scan, run, lane)`**:
`{provider, model, inputTokens, outputTokens, cacheReadTokens, cacheWriteTokens, cacheWrite5mTokens?, cacheWrite1hTokens?, engineCostMicroUsd, turns, durationMs}`
— `cacheWriteTokens` is every cache write; the two optional fields split it by
lifetime (absent = all 5-minute). Anything else in the object is ignored.

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
- A **calendar** report has its own contract (`CALENDAR_SCALARS` /
  `CALENDAR_LISTS` of the validator): `status`, `candidate`, `unfinished`,
  `type`, `pageBox`, `photoBox` (nullable) and the lists `recognised`
  (`element`, `state`), `needsUpload` (`slot`), `featuresOffered` (`key`),
  `fonts` (`group`, `pattern`, `face`), `notExpressible` (`code`, `box`),
  `notes` (`code`) — codes, keys and boxes only; extras under the same rule.

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
- `changes[]` — places the user removed (`op: remove`), merged away
  (`merge`, with `with[]`) or turned into a shape (`shape`, with `shape`):
  `key` (the item key `undo` takes), `id`, `name` (the scanner's). They are
  gone from `placeholders`, so this is what lets the pictures step offer
  "Vrátit" after a reload (added with the UI);
- `fonts[]` — the project's face strings for the picker.

A place keeps the name the scanner gave it when it is filled as "Součást
šablony" (then it is a plain picture in the replayed document and has no
input): `name` falls back to the element's name, a `rename` fix, the original
document's input name.

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

## UI (P3) — the wizard in the browser

Specification: `docs/ai-scan/ux.md` (revision 5; behaviour and the exact Czech
copy) and the mock `docs/ai-scan/ux-mock/` (the visual target; git-ignored).
Styles: `assets/styles/nocturne/ai-scan.css` (`as-*`; tokens only; `--on-pch`
in `tokens.css`). One lavender primary per view, peach = AI and "now", mint =
done, one moving element per view (the sweep), nothing moves under
`prefers-reduced-motion`.

### The page "Šablona z obrázku"

`AiScanPageController` — two routes, one template, nothing happens on a GET:

| Route | Path | Who |
|---|---|---|
| `ai_scan_new` | `/project/{projectId}/sablony/z-obrazku` | `canSeeProjectScans` (403 otherwise) AND entitled — an editor without the entitlement is redirected to the template list, where the card says what to do |
| `ai_scan_page` | `/project/{projectId}/sablony/z-obrazku/{scanId}` | `AiScanVoter::USE`; the scan must belong to the project and be a template scan (404) |

The template hands the browser one JSON value (`data-ai-scan-page-boot-value`:
project, the scan's list row or null, the entry facts, URL patterns built
with the nil uuid) and holds the gallery picker (`Project:ImageGallery` in
`#aiScanGalleryModal`) **outside** the controller's element and outside any
form. `ai_scan_page_controller.js` (lazy) starts `ScanApp`
(`assets/ai-scan/page/app.js`), which owns the state and draws every screen
as a function of it:

| Screen (`data-view` on `.as-page`) | When | File |
|---|---|---|
| `upload` (S1), `chosen` (S2, T2) | no scan | `page/upload.js` + `file-checks.js` |
| `wait` (S3), `fail` (S5), `gone` | `checking` / `queued` / `running`; `stopped` / `failed` / `refused`; `cancelled` / `discarded` / `expired` | `page/wait.js` |
| `review` (S4), `readonly` (T4) | `ready`; `accepted` | `page/review.js` |
| `pictures` (S4b) | `ready`, step "Fotky a loga" (`#obrazky`) | `page/pictures.js` |

**Rendering.** A screen is a string of markup (the mock's structure);
`morph()` replaces only the `data-region` parts whose markup changed, so a
poll never rebuilds a picture, and focus (`data-k`), unsent text and the
panel's scroll survive a re-render. Events are delegated: `data-act` (click),
`data-chg` (change), `data-enter`, `data-hl` (hover / focus highlights the
box with the same `data-box`). Everything that did not come from the code
goes through `esc()`.

**The status / poll contract as consumed.**

- `GET ai_scan_status` every **2 s** while the scan is `checking` / `queued` /
  `running`, and never otherwise. A failed poll keeps the last state and tries
  again; 403 / 404 reloads the page.
- Phase texts come from `status`, `waiting` (no position, no estimate, no
  "Jste na řadě"), `restarted`, `queuePosition`, `estimatedStartSeconds` /
  `estimatedFinishSeconds` (always shown as a range of minutes; under one
  minute "během chvíle"; the browser never counts down).
- Stages: `stage` + `note` + `noteParams` + `round`. The document carries the
  CURRENT stage only, so **the duration of a finished stage and the note it
  ended with are what this browser saw** — kept per scan and try in
  `sessionStorage` (`wb-as:{scan}:{try}`), so a reload keeps them; a stage that
  ran while nobody watched is ticked without a duration.
- The running preview is `GET ai_scan_image/candidate?n={previewSeq}`; the
  uploaded picture is `working` once the pre-check passed (any browser can
  show it — also a HEIC), `original` before that and after a stop.
- The end: `ready` → `GET ai_scan_proposal`, the review opens by itself with
  the focus on its heading and a toast; other ends → the result card.
  `POST …/seen` is sent when the requester's browser got a final state.
  Phase and stage changes are announced in a visually hidden live region
  (`#asLive`); the ticking time is outside it. The tab title follows the
  phase.
- A start, retry, continue, cancel, discard, accept and "seen" dispatch the
  window event `ai-scan:changed` — the shell reads the badge again.

**Review and pictures.** Every change is one `POST ai_scan_fix`, sent one at a
time in the order of the clicks; the answer's `proposal` replaces the state
(nothing about a fix is kept in the browser, so two tabs meet per item).
`previewStale: true` shows "Náhled se nepodařilo obnovit · Zkusit znovu"
(`POST …/preview`). Uploads go to `POST ai_scan_pictures` (several files → the
empty places in order; one file + `place`). Gallery picks listen to the
gallery's `asset-selected` window event; the multi-pick collects ids in a bar
under the gallery and sends one `assign_pictures`. Crops, the dimmed original
inside empty places and the layer outlines are CSS backgrounds / boxes drawn
from `image/working` and `image/proposal` with the document's `box` values —
nothing is decoded except for "Změnit na tvar" (the average colour of the
place) and "Vyplnit" (the crop of an uploaded file).

**Accept** waits for the fix queue, posts `ai_scan_accept`, puts the toast
`Šablona „…“ vytvořena ze skenu — {n} prvků` into `sessionStorage`
(`assets/shell/toast.js` → shown by the next page's shell) and opens
`editorUrl`. `stale_references` reopens the review with the `missing_font` /
`missing_picture` items.

**Upload checks in the browser** (`file-checks.js`) mirror `AiScanUpload`:
type by magic bytes, bytes ≤ 10 000 000, the header's pixel size (short side
≥ 600, side ≤ 8000, ≤ 36 Mpx); plus a sharpness hint (Laplacian on a ≤ 1024 px
copy) and "small" (< 1000 px) as warnings that never block. A refused file is
never sent. The request id is created when the file is chosen and reused on a
network retry.

### Lists, shell, export, admin

- **Card in "Nová šablona"** (`templates/ai_scan/_entry_card.html.twig`,
  `ai_scan_entry()` = `AiScanLists::entry()`): the states of ux.md T1.
  `locked` opens the request dialog (a native `<dialog>`; the Bootstrap modal
  closes first) and posts `ai_scan_access_request`.
- **Strip above the template list** (`ai_scan_strip_controller.js`): cards
  drawn from the entry's rows inlined as JSON; it polls
  `ai_scans_project?view=strip` every 10 s only while a card is in progress
  and is not rendered at all without a card. More than six cards fold under
  "Zobrazit dalších {n}".
- **Template cards**: `ai_scan_template_marks()` → the pills `Ze skenu`
  (7 days, links to the scan's page) and `K doplnění: {n}`; costs nothing for
  a project that never accepted a scan.
- **"Historie skenů"** (`ai_scan_history`,
  `/project/{projectId}/sablony/historie-skenu`): server-rendered rows, filter
  chips as links, the row menu from the row's `actions[]`.
- **Shell** — `docs/architecture/app-shell.md` § "AI scan signals".
- **Export dialog**: forms whose variants may have unfilled pictures
  (`ai_scan_unfilled()` > 0 — an upper bound) carry
  `ai-scan-export-guard`: the same POST is sent with `Accept:
  application/json` first; a 409 `unfilled_pictures` shows the dialog,
  "Exportovat přesto" submits the form with `allowUnfilled=1`, and a file that
  comes back instead is saved from that response (one render). Publish handles
  the 409 in `variant_publish_controller.js`. A template without a stand-in
  picture is not touched by any of this.
- **Admin**: the open access requests of a project are rows in "Funkce
  Premium" (`ai_scan_admin_controller.js` → `ai_scan_access_request_resolve`);
  the admin's user page has the switch per owned project (`form=` anchors,
  `return_user` — a uuid — brings the admin back). The tab "AI skeny" is
  § "Admin tab".

### Deviations from `docs/ai-scan/ux.md` (UI)

- **S3 preview "Tyto texty jsme našli"** (the original with the found lines
  outlined) is not drawn: the status document carries no boxes during a run.
  The pane shows the dimmed original with the sweep until the first rendered
  candidate.
- **Per-stage durations** are what the browser observed (above).
- **"Vybrat jiné písmo"** is a native select of the project's face strings,
  not a list drawn in each typeface.
- **S2 fonts row**: "Přidat" uploads font files inline; "Přidat písmo Outfit"
  is left out (nothing in the app installs a bundled font).
- **"Naskenovat znovu" → "Stejný obrázek…"** puts the stored original back on
  S2 (the browser fetches it) instead of starting at once — the user sees the
  format and the credit line; there is no server-side "rescan".
- **No project-logo pre-fill; "Změnit na tvar" uses the colour the browser
  measured; an empty "Součást šablony" place renders as a centred grey square;
  "Vyplnit" is the browser's crop** — a file uploaded into a place (or
  uploaded in this session) is cropped to the place's ratio; a gallery picture
  of another ratio is fitted whole and the card says so.
- A full-page background place shows no dimmed original in the viewer (it
  would cover the whole proposal) and its card has no `⋯` menu.
- **History** has no `Šablony` / `Kalendáře` chips and "Nový sken" is a plain
  link (calendar scans do not exist yet); the read-only page has no "Využití
  tohoto skenu" link and the admin box no "Využití →".
- The read-only page lists a decision as open or settled from the proposal;
  it does not know what was changed in the editor afterwards.

## Calendar scans (P5)

A picture of **one page of a weekly desk calendar** becomes the proposed
**layout** of an existing 7-day calendar (`ai_scan.calendar_id`, given at the
upload; 14-day calendars and another project's calendar are `wrong_state`; a
project without fonts is fine). The job, the states, credits, storage and
notifications are the shared core; what differs is below. The rule of
`calendars.md` holds throughout: **the calendar's JavaScript generates, the
server stores and guards** — a candidate is built by the calendar's own model
in node, the browser draws the proposal with the calendar's own renderer.

### The scanner's side

- **Claim** — `job.context.calendar` (`CalendarCandidate::context()`):
  `{supported, type, year, page (the materialised design.page), bleedMm,
  features (the switches as they are), hasLayout, presets, samplePage {key,
  from, to, title}, defaultLayout {preset: "photoLeft", layout}}` — the whole
  default layout, i.e. every box and every style key the patch path takes.
  `supported: false` (calendar gone, 14 days) → the build fails.
- **Render** — `{run, seq, commands}`: a list of ≤ 8 commands of
  `resources/calendar/model.mjs`, a closed subset checked by
  `CalendarCandidate`: `{op: "preset", preset}` (always a RESET to that
  preset's default — a candidate never inherits the calendar's styling),
  `{op: "configure", features: {key: true}}` (ON only; any other key or a
  `false` is `invalid_command`), `{op: "layout", boxes?, order?, styles?}`
  (the validated patch: unknown keys and enum values are refused with the
  model's message, numbers are clamped and come back as `info` issues).
  The commands run on the stored calendar's document **in memory**
  (`CalendarModelBridge::run`), the sample week page — `w02`, a week that
  never holds a public holiday — is drawn by
  `CalendarPageRenderer::renderDocument()` (trim box, 1600 px, sample texts
  on, WebP; behind `CalendarCandidatePictureInterface`, faked in tests).
  When the candidate has `events` on and the sample week holds no event,
  two sample events are put into the in-memory document (`withSampleEvents`)
  so the picture shows where event texts land.
  **Nothing is saved**: no revision, no history line, no asset. The answer
  adds `view` (the mm box the picture shows), `page`, `preset`, the
  materialised `layout` and `features`. A refusal by the model is
  `{ok: true, candidate: null, issues: [{severity: "error", stage: "model",
  code, message}]}`.
- **What is stored per candidate** (`candidates[].design`):
  `{kind: "calendar", preset, featuresOn[], layoutJson, page, view}` — the
  layout as a JSON **string**, because a layout holds maps that are empty or
  keyed by digits and the column is read as PHP arrays. `finish` copies the
  kept one into `proposal.document`.
- **Finish** — the calendar report (above).
- Tools of a kind may be per-kind variants: an entry of `tools.json` with an
  `id` (`scan_crop.calendar`) named in `kinds.calendar.tools`; the scanner
  resolves by `id`, then by name. Template entries are unchanged.
- The contract fixture (`scanner-api.v1.json`) was re-recorded with `mm` in
  the claim; it holds template flows only (a calendar render would need
  Gotenberg in the default group).
- `numberSide: "middle"` is accepted on the model's patch path since P5 (the
  editor always offered it); nothing under `assets/calendar/` changed for
  scans — `CalendarExportFingerprintTest` pins the renderer fingerprint.

### The proposal, computed against the calendar as it is

The stored proposal never changes. What the review shows and what accept
saves is `CalendarScanProposal::plan($scan, $calendar, $pictures)`:

1. the proposed layout, **every asset reference dropped** (a proposal may name
   no picture);
2. for an element the report calls `absent` (of `cap month wk head logo qr
   bg`) — when the calendar HAS a layout — the calendar's current box and
   style group instead ("an element not found keeps the calendar's current
   setting"); without a layout the candidate's own state stays (the scanner
   hides what it did not find);
3. per place for a picture (`needsUpload[].slot`: `logo` → `styles.logo.asset`,
   `header` → `styles.head.bg.asset` + `imageOn`, `background` →
   `styles.bg.asset` + `imageOn`): the picture held for it, else the picture
   the calendar already has there (`current`), else nothing.

Switches: `featuresOffered` ∩ known features ∩ what the calendar has OFF;
accept turns on only those the user ticked. A scan never switches anything
off and never touches content.

`GET /ai-scan/{id}/proposal` answers this for a calendar scan
(`CalendarScanProposalView`): `kind: "calendar"`, `calendarId`, `readOnly`,
`unfinished`, `expiresAt`, `candidate`, `source {width, height}`, `pageBox` /
`photoBox` (source px — the browser's preview only), `page`, `view`,
`hasLayout`, `preset`, `layout`, `recognised[{element, state, kept}]`,
`features[{key}]`, `pictures[{slot, picture {url, name, width, height} | null,
current}]`, `fonts[{group, pattern, face, closeness}]`, `projectFaces`,
`notExpressible[{code, box}]`, `notes[{code, w?, h?}]`, `approximate`,
`applied` (null until accepted: `{restoreUntil, restorable, restored, changed,
features}`).

### Pictures are held, not uploaded

`POST /calendar/{calendarId}/scan/{scanId}/picture` (`slot` + `picture`, or
`slot` + `remove=1`) keeps a picture **with the scan** — `picture-{slot}.{ext}`
in its private directory, named in `ai_scan.pictures.slots` by a conditional
update (`… AND status = 'ready'`). JPEG / PNG / WebP by their bytes, ≤ 10 000 000
bytes, ≤ 60 Mpx by the header; no SVG (the preview serves held pictures from
the app's own origin: `GET /ai-scan/{id}/image/picture?slot=`). They become
calendar assets only at accept. Calendar assets can never be deleted, so a
discarded or expired proposal must leave none — and leaves none: the scan
directory goes with the scan.

### Accept — `AcceptCalendarScan`

`POST /calendar/{calendarId}/scan/{scanId}/accept` (`features[]`). Under the
scan's row lock and the calendar's row lock, in the bus transaction:

1. a repeat on an `accepted` scan answers with the first result
   (`created: false`);
2. the snapshot of the calendar **as stored now** (`CalendarScanProposal::snapshot`:
   `layoutJson`, `preset`, `featuresJson`, `layoutOrigin`, `step`) — a save
   through the client link in between changed content only and is simply
   kept;
3. held pictures → calendar assets (`CalendarAssetUploader::storeFile`, kinds
   `logo` / `image`);
4. `plan()` → model commands `[configure {ticked: true}, replace_layout]` →
   the payload → **`SaveCalendar`** (the one write path) with `preset` = the
   proposal's, `setLayoutOrigin: true, layoutOrigin: "scan"` and the history
   sentence `Převzal rozvržení ze skenu`;
5. `AiScanTransitions::acceptCalendar()` stores `restore` = the snapshot +
   `appliedHash` (`CalendarScanProposal::hash(layout, features)` of what was
   written — key order and `5` vs `5.0` do not matter), `features`, `assets`.

Answer: `{calendarId, revision, features, pictures, unfilledPictures,
restoreUntil, created, document (a save answer: calendar, assets, savedAt,
layoutOrigin), scan, applied}`. Refusals: `wrong_state`, `invalid_fix`
(`field: pictures | layout`), `temporarily_unavailable` (node).
`expires_at` does not move.

### Restore — `RestoreCalendarScan`

`POST …/restore` (`confirm`). Writes the snapshot's layout, layout choice,
switches and marker back through `SaveCalendar` (`Vrátil původní rozvržení`)
— nothing else: content, the name, the year and the pictures that became
assets stay. A calendar that had no layout goes back to step 2. If
`hash(layout, features)` of the stored calendar ≠ `appliedHash` the caller
must send `confirm` — otherwise `layout_changed` (409), nothing written.
Available while the scan's `expires_at` lies ahead (the nightly purge then
nulls `restore`); a repeat answers `restored: false`. The snapshot marks
`restoredAt` (`markRestored`).

### The marker — `calendar.layout_origin`

One nullable column (`Version20261113190000`), `scan` or null. Set by accept,
put back by restore, **cleared by `SaveCalendarHandler` when a save changes
`preset`** — and, because choosing the preset card that equals the stored
`preset` changes no key, by a save whose payload carries top-level
`"layoutOrigin": null` (the only value accepted from a browser; a client's
request with it is refused whole). It travels beside the document
(`env.layoutOrigin` in the designer's bootstrap, `layoutOrigin` in a
designer's save answer — a client gets neither), never
inside `calendar` — the design's JavaScript does not know it. Editing a
scanned layout in step 3 keeps the marker.

### Lists, links, MCP

- `GET ai_scan_entry?kind=calendar&calendarId=` — the entry of ONE calendar:
  its scans only, and `applied {scanId, restoreUntil}` for its latest applied
  scan whose snapshot is still there. Rows carry `calendarId`; calendar rows
  are named after the calendar and may offer `open_calendar` and `restore`.
- `GET calendar_card` adds `scan`: `running | queued | ready | failed | null`
  (designers only) — the pill of ux.md C5.
- E-mails and MCP link to `/calendar/{id}?sken={scanId}` (`AiScanUrls::scan`).
- MCP `scan_design`: `start` with `kind: "calendar"` + `calendarId`; `result`
  (layout, recognised, featuresOffered, pictures, …); `apply` with
  `features[]`; `restore` with `confirm` — `layout_changed` without it.
  Applying and restoring need `calendar_edit` (`designer_only`). Held pictures
  are web-only: over MCP a picture is uploaded with `upload_calendar_asset`
  after `apply`.

### Deviations from architecture.md / ux.md (backend, P5)

- The `render` body is `commands` as specified, but restricted to three ops
  and always reset to a preset first; the candidate's `view` is the trim box.
- Report `recognised[]` carries no "fact code": the browser derives the fact
  beside each element from the layout itself.
- Font decisions of the calendar review are information only — a face is
  changed in step 3; there is no `choose_font` fix for calendars and
  `ai_scan.fixes` stays null.
- "Element not found keeps the calendar's current setting" applies when the
  calendar has a layout; for a calendar without one the candidate decides
  (an absent logo / month / week number / QR is hidden by the scanner).
- A design save that only re-chooses the SAME preset over MCP
  (`set_calendar_layout preset + reset`) does not clear the marker.
- A failed accept after a held picture was stored leaves that file (not the
  row) in the calendar's directory — the storage report lists it as orphaned.

## MCP (P3)

`scan_design` (`ScanDesignTool`, scope `ai:scan` — implies nothing, off by
default): `start`, `status`, `result`, `apply`, `list`, `cancel`, `retry`,
`continue`, `restore` (calendar scans — § "Calendar scans"). Every
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
| `app:ai-scan:watch` | no options. Evaluates the operating conditions and e-mails the admins what is due (§ "Operating e-mails"). One line per action (`opened <code>`, `reminded <code>`, `cleared <code>`), nothing when there is nothing to do; exit 0, or 1 when a mail could not be handed over (`failed <code> …`, the claim is given back). Cron, every 5 minutes; safe to run twice at once |
| `app:ai-scan:purge` | reaper, expiry, orphan files, leftover usage rows — nightly host cron on the box (`ai-scan-purge`, 03:00 Europe/Prague) |
| `app:ai-scan:fake-run <project-id> <user-email> [--scenario=happy\|stop\|fail\|refuse\|cancel\|hang\|all] [--beats=N] [--beat-interval=S] [--no-render]` | a fake scanner; **refuses in production** |

Local use: `.env.dev` sets a dev token and the local `scan` network
(`compose.yaml`) plus `127.0.0.1` (the fake run calls in-process). A scanner
started on the host reaches the app from the Docker gateway — put the whole
list, with that address added, into **`.env.dev.local`** (git-ignored):

```dotenv
# .env.dev.local
SCAN_NETWORK_CIDR=172.31.250.0/24,127.0.0.1/32,::1/128,<the address web sees the host from>
```

Not `.env.local`: Symfony loads `.env`, `.env.local`, `.env.dev`, then
`.env.dev.local`, so the value of `.env.dev` overrides `.env.local` and the
scanner keeps getting 404. The address is the gateway of the compose network
(`docker network inspect wboost_default`) or, on Docker Desktop, an address of
`192.168.65.0/24`; a scanner whose `/health` says `app.lastError:
"not_scanner"` while the token is right is missing from this list.

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
- **Contract fixture** — `tests/Controller/Internal/ScannerContractFixtureTest`
  replays `scanner/test/contract/scanner-api.v<N>.json` (recorded by the
  scanner build against the real app) against the real controllers, for every
  protocol version the app accepts; the scanner's suite replays the same file
  against its client. Part of the default run, so it is in the Tests workflow.
  A deliberate contract change: edit § "Scanner API", record the fixture again
  (`scanner/test/contract/README.md`), commit both.
- P3 UI: `tests/Controller/AiScan/{AiScanPageTest,AiScanListSurfacesTest,AiScanShellAndAdminTest}`
  (who gets which page, the rollout state, the shell's markup), and the
  browser scripts `tests/e2e/ai-scan/` (README there): puppeteer drives a real
  Chrome through the wizard against the dev stack while
  `tests/e2e/ai-scan/lib/fake-scanner.cjs` plays the scanner over the real
  scanner API. Not part of CI.
- P4: `tests/Controller/Admin/AdminAiScansTabTest` (who sees and writes the
  tab, every form of the strip against the runtime row, `off` asking first
  and interrupting scans, the page empty / with requests, projects and
  scans), `tests/Services/AiScan/AiScanAlertsTest` (each condition opens
  once, the reminder, the end, the quiet half hour, nothing while `off`, a
  claim won once, the watch command — time is passed to `run($now)`).
- P5: `tests/Controller/Calendar/CalendarScanTest` (claim context, candidates
  from commands, the instrument filter, the report contract, the proposal
  document, accept — once, with pictures, after a client-link save, refused on
  `/k/{token}` / to a read-only member / with a foreign scan —, restore and
  its confirmation, retention, discard, the marker, the entry and the card
  pill), `tests/Mcp/Tool/ScanDesignTest` (calendar flow),
  `tests/Services/Calendar/CalendarExportFingerprintTest` (the renderer
  fingerprint is what it was), `CalendarRenderDocumentGotenbergTest`
  (`--group gotenberg`: `renderDocument` = `render` for the stored document),
  `tests/FaultInjection/CalendarScanRaceTest`; browser:
  `tests/e2e/calendar/scan.e2e.cjs`.
- **Fault injection** — real races between forked processes, outside DAMA's
  transaction (`tests/FaultInjection/`: `AiScanRaceTest` — claims, cancels,
  the reaper, starts, a replaced run's usage; `AiScanReviewRaceTest` — accept
  × 2, accept vs discard, accept vs expiry, fix vs accept, two tabs fixing;
  `CalendarScanRaceTest` — accept × 3, accept vs discard, accept vs a client's
  save, restore × 2);
  not in the default run — a job of its own in the Tests workflow:

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
- The watch's claims are conditional updates on `ai_scan_alert`; under DAMA's
  single connection two "processes" are two calls with what the first one
  read (`AiScanAlertsTest::testAClaimIsWonOnce`).
- An absolutely positioned child (`.visually-hidden`) of a horizontally
  scrolling table widens the PAGE unless the scroller is positioned —
  `.ai-wrap` is `position: relative` for that.
- Advisory locks are per database session and re-entrant: under DAMA's single
  connection a test must hold them from a SECOND connection to see
  `renderer_busy`.
- A FILLED place is an ordinary picture in the replayed document: ask
  `ScanProposal::replay()['places']` which ids are places, never the document.
- Fixes are checked with the preflight inside the request; a new fix type
  needs its row in § "Fixes", in `ScanDesignTool`'s description and a test.
- The stand-in is recognised by `assetPath`, not `assetId`: the path is what
  the renderer needs, so it cannot go stale when the editor swaps a picture.
- UI: after a JS / CSS edit the dev cache serves stale asset digests — one
  404 in the importmap stops EVERY controller (`rm -rf public/assets` +
  `cache:clear`). The page's controllers are `stimulusFetch: 'lazy'`; only
  `ai_scan_shell_controller`, `ai_scan_export_guard_controller` (and
  `assets/shell/toast.js`) load on every page. The export guard must stay
  eager: the fill form comes with a deferred Live Component and is usable at
  once, so a lazy guard would miss a quick submit (→ the 409 error page).
- UI: never build a file input on the fly and click it — the pages keep
  their hidden inputs (`#asFile`, `#asPics`, `#asPlaceFile`, `#asFontFile`,
  `#asReviewFont`) in the markup, which is also what the e2e scripts fill.
- UI: `app:ai-scan:fake-run` drives only its OWN scan. To move a scan started
  in the browser use `node tests/e2e/ai-scan/lib/fake-scanner.cjs
  --project=<id>` (without `--project` it takes every waiting scan — never
  beside a real scanner).
- `resources/ai-scan/definition/` is sent to the scanner verbatim; its numbers
  are mirrored in `AiScanLimits` and `AiScanValuesTest` compares the two.
- A calendar LAYOUT must never pass through a PHP array (empty and
  digit-keyed maps become lists): it is a `stdClass` tree in memory and a JSON
  string in `ai_scan` columns (`layoutJson`). Read it with
  `CalendarScanProposal::layout()`.
- A new scan KIND that an older scanner image cannot build must name an
  instrument in `kinds.<kind>.requiresInstruments` — otherwise the pinned
  image claims it, crashes, and the scan ends `interrupted`.
- The calendar accept and restore go through `SaveCalendar`: never write
  `layout`, `features` or `layout_origin` from a scan handler directly.
