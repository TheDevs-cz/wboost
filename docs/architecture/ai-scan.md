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

**Who has it (decision 16).** There is no legal gate and no rollout stage in
the app: access needs only the per-project switch. Production runs mode `on`
with `admin_only = 0` since 2026-11-13, so **an editor of a switched-on
project is a real user of the wizard** (and an admin of any project); the
in-wizard consent lines and the dialog "Jak s obrázky nakládáme" are the only
disclosure and are part of the start, not a gate to configure. In a project
that is not switched on, the ONE thing an editor sees is the locked card
"Šablona z obrázku" with a live "Požádat o přístup" (a viewer sees nothing):
no strip, no badge, no request from any page (`AiScanListSurfacesTest`,
`AiScanShellAndAdminTest`). An admin switches a project on in "Funkce
Premium" on the edit-project page (or on the user's page, or in the admin
tab).

**The switches still default to inert** — a fresh database has mode `off`,
`admin_only` on, every project's entitlement off, and the scanner endpoints
answer 404 while `SCAN_NETWORK_CIDR` / `SCANNER_TOKEN` are unset — so a new
environment does nothing until somebody sets the mode in the admin tab "AI
skeny" or with `app:ai-scan:status`. `admin_only` stays as an operating
switch (start is then admins only); it is not a rollout phase.

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
| Admin tab (P4) | `Query/{GetAiUsageOverview,AiUsageOverview}`, `Controller/Admin/{AdminUsageController,SetAiScanRuntimeController}`, `templates/admin/_usage_ai_scans.html.twig`, `assets/controllers/ai_scan_admin_table_controller.js`, `ai-*` in `assets/styles/nocturne/admin.css` |
| Operating e-mails (P4) | `Value/AiScan/AiScanAlertCode`, `Services/AiScan/{AiScanConditions,AiScanCondition,AiScanAlerts}`, `Entity/{AiScanAlert,AiScanProjectPause}`, `ConsoleCommands/AiScanWatchConsoleCommand`, `templates/emails/ai_scan_alert.html.twig` |
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
| 7 / 6 | lost build lease: `queued` once, then `failed interrupted`; a build handed back (`release`): `queued` up to three times a try, the fourth `failed interrupted` | `buildLeaseLost` (reaper); `releaseBuild` | — / refund |
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
- `releases` — builds the scanner handed back in the current try (rule 7,
  `release`): a release consumes no automatic restart and starts the queue
  timeout again, so it has a bound of its own —
  `AiScanLimits::MAX_BUILD_RELEASES` = **3**; one more (event
  `build_release_again`) ends the scan `failed interrupted`, refunded, its
  usage closed, counted by the circuit breaker, **not** against the picture.
  Reset by a retry / continue. Why 3: a release needs a scanner restart or a
  provider block to land inside a run of this scan (≤ W = 6 min); deploys of
  the scanner happen a few times a day, so a scan meets one, rarely two —
  four in a row is a loop, and before the bound such a scan was claimed and
  paid for without end (`ScannerReleaseCapTest`).
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
- `stage_log` / `text_boxes` — § "Stage log" below.

**The reaper** (`AiScanReaper`) is the one writer of time-based transitions.
It runs on every scanner `claim` and `tick`, in the nightly purge — and
**lazily on any status or list read that meets an overdue row**
(`AiScans::current()`), so a dead scanner delays nothing a user looks at.

### Stage log

`ai_scan.stage_log` (JSONB) says what the current TRY went through:
`{<stage>: {at, end?, note?, params?}}`, epoch seconds. It is what lets the
waiting screen show the real duration of every finished stage and the note it
ended with, whoever watched (ux.md S3) — the status document's `stages`.

- `claimIntake` starts it (`{check}`), `claimBuild` drops the build's stages
  and opens `read` (an automatic restart keeps the check of the try), a retry
  keeps a passed check and drops the rest.
- The heartbeat opens a stage the first time it names it and puts the note on
  the stage it is about: `texts_found` / `rows_found` belong to `read` even
  though the scanner sends them with the next stage's name; every other note
  to the stage of that beat. A beat without a note changes none.
- **Every transition closes the stage the scan was in** (`end`), unless it
  writes the log itself — one assignment added in
  `AiScanTransitions::applyReturning()`, so no ending can forget it.
- Read side (`AiScans::stages()`): a stage's seconds = its `end`, else the
  next stage's `at`; the running stage has none. JSONB does not keep key
  order — the order is `AiScanStage`'s.

`ai_scan.text_boxes` (JSONB) — `{width, height, boxes: [[x, y, w, h], …]}`:
where the current run found lines of text, in the pixels of the working copy
(`ScannerApi::textBoxes()`: whole numbers, inside the picture, at most 300; no
text). Sent in a heartbeat as `textBoxes` — by the real scanner since
2026-11-13: the ink boxes of the OCR lines, with the first beats after the
reading until one is acknowledged, for templates and calendars —, cleared by every claim and retry,
and shown only while the scan is in progress (the status document's
`textBoxes`) — "Tyto texty jsme našli".

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
- **Starts are judged one after the other.** The project row
  (`SELECT … FOR UPDATE`) serialises the starts of one project; the guards
  that count ACROSS projects — a user's starts of the day, the scans waiting
  in total, the day's total spend — were passed twice by one user starting in
  two projects at the same moment. `AiScans::assertMayStart()` therefore
  takes one transaction-level advisory lock (`pg_advisory_xact_lock`, class
  of the render limiter, key 0) before it counts; it is held until the start
  commits, always taken after the project row, and nothing takes a project
  row while holding it (`AiScanRaceTest::testOneUserStartingInTwoProjects…`).
- **Usage** (`ai_usage_event`, FK-less, survives the scan, the project and the
  user): one row per `(scan, run, step)`, opened at claim, upserted from every
  heartbeat (figures are cumulative; `GREATEST`, so a late beat takes nothing
  back), closed with an outcome when the run ends — however it ends. A killed
  run therefore has a closed row with the last heartbeat's figures. Cost: the
  price table of `definition.json` when it knows the model, otherwise the
  engine's own figure — and **never more than three engine budgets per row**
  (`AiScanLimits::USAGE_COST_CAP_BUDGETS` × `caps.engineBudgetMicroUsd` =
  $4.50; `RecordAiUsage::costCapMicroUsd()`): the figures are another
  process's word, clamped only to 0–2·10⁹, so one broken report could book
  $2000, close the day's ceilings and falsify the admin's sums.
  `engine_cost_micro_usd` keeps what was said. A row at the cap, a row the
  table did not price, and a finished build without a token are announced to
  the admins (`usage_suspect`, § "Operating e-mails"). **Cache writes are priced by lifetime**: the scanner
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
- **Spend history is never deleted — not with the project either.** Deleting
  a project cascades its `ai_scan` rows, but `ai_usage_event` and
  `ai_credit_ledger` are FK-less on purpose and no code deletes their rows:
  the money was spent. The sums (`AiScanSpend`: today, the month, both
  ceilings) are plain sums over `ai_usage_event` and keep counting a deleted
  project's rows; the usage row carries its own snapshot (`project_name`,
  `owner_email`, `user_email`), so it stays readable. How the admin tab shows
  such rows: § "Admin tab" ("Smazaný projekt"). Never clean these rows up.
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
whose row is missing, `expired`, `discarded`, or `accepted` with its date
cleared; deleting a project deletes
`ai-scan/{projectId}/` (`DeleteProjectHandler`). See `storage-inventory.md`.

**"We delete the proposal and the picture" holds for the row too**
(`AiScanTransitions::SCRUB`, review of 2026-11-13). When a scan is
discarded, when it expires, and when the retention of an accepted scan runs
out, one statement empties every column that holds something read from the
picture or typed into the review: `proposal`, `candidates`, `fixes`,
`report`, `text_boxes`, `pictures`, `restore`, and `source_meta.name` (the
uploaded file's name). Codes, counts, times, the stage log, the history and
the usage stay — the lists and the admin tab read only those.

| When | Files | Row |
|---|---|---|
| `discard` | deleted at once | scrubbed in the transition |
| expiry (`ready`, `failed`, `stopped`, `refused`, `cancelled` → `expired`) | deleted after the transition | scrubbed in the transition, `expires_at` cleared |
| `accepted`, before `expires_at` | kept | kept — the read-only page and „Vrátit původní“ need them |
| `accepted` / `discarded`, `expires_at` passed (purge) | deleted | scrubbed; `expires_at` cleared **only when the delete succeeded** |

- A file delete that fails is never lost: the accepted / discarded row keeps
  its date and is found again the next night; and the 24-hour sweep takes the
  directory of any row that should have no files.
- The purge ends with a net (`scrubbed` in its output): rows that are past
  that point and still hold something — rows that got there before the rule
  was complete, or a statement that was skipped. Idempotent, usually 0; it
  is what cleaned the rows that existed on 2026-11-13.
- What reads such a row: the history and the strip (`fileName: null`,
  `hasProposal: false`, no `open_proposal`), the wizard's page (the "gone"
  views — „Návrh i obrázek jsme smazali“, „Sken a původní obrázek jsme po 30
  dnech smazali“), `GET /ai-scan/{id}/proposal` (`proposal: null`; a calendar
  scan answers `applied` alone), the admin tab, the e-mail (`obrázek` instead
  of the name). `AiScanRetentionScrubTest` walks them for each of the three.

The bucket itself: created by `.docker/setup-minio.sh` locally (and by
`app:ai-scan:fake-run` when missing); in production the box's `deploy.sh`
asserts "exists, no anonymous policy" on every deploy (infra repo
`~/www/lily.srv/apps/wboost/deploy.sh`, decision D87). `app:ai-scan:status`
says whether it is reachable — by LISTING the prefix: a HEAD of an object in a
bucket that does not exist is an ordinary 404 and proves nothing.

## Upload

`AiScanUpload::inspect()` reads the HEADER only — magic bytes for the type
(JPEG, PNG, WebP, HEIC, **PDF**; SVG and everything else refused), the byte size
(≤ 10 000 000), and the pixel size the header declares (each side ≤ 8000,
≤ 36 Mpx, short side ≥ 600 — **≥ 300 for kind `calendar`**; HEIC and PDF are
accepted on bytes alone). Nothing is decoded in the web process: normalising is
the scanner's intake.

**A PDF is a picture of its first page** (owner decision O4, 2026-11-13). `web`
accepts a file that starts with `%PDF-` (`application/pdf`, stored as
`original.pdf`); the scanner's intake rasterises page 1 with poppler's
`pdftoppm` inside its decoding child process — the page at 300 dpi (an A4
becomes 2480 × 3508 px), the long edge never above the working copy's 4096 px
nor below 1600 px — and from there the scan is an ordinary one. **That
raster is for reading only: the template takes the page's own size.** The
intake reports it with `passed` — `sourceMeta.pagePt` `[w, h]` in points
(scanner images before 2026-11-14 send only `pageMm`, to 0.1 mm; both are
kept in `ai_scan.source_meta`) — and § "Accept" turns it into the format. No page
picker; the page's own text and font names are not read (follow-up F1,
`docs/ai-scan/architecture.md`). A PDF that is encrypted, broken or empty is
`stopped unusable_input` (refunded). Order of a roll-out: the scanner image
first (an image without poppler stops every PDF as `unusable_input`), then
the app. Mirrors: `sniffType()` in `assets/ai-scan/file-checks.js` (`pdf`; the
browser shows no preview and reads no size, as for HEIC), the `ACCEPT` lists
and captions of `assets/ai-scan/page/upload.js` and
`assets/calendar/ui/scan/views.js`, `COPY` (`type`, `source`), the MCP tool's
docblock and refusal text; the skill's `references/tools.md` still lists the
four picture types — to be corrected with the next skill release.

The calendar minimum (`AiScanLimits::UPLOAD_MIN_SHORT_SIDE_CALENDAR`) has
three mirrors that must move together: `kinds.calendar.upload.minShortSidePx`
of `definition.json` (the scanner's intake merges a kind's `upload` over the
shared one), `MIN_SHORT_SIDE_CALENDAR` in `assets/ai-scan/file-checks.js`
(`inspectFiles(files, {minShortSide})`), and the sentences that name the
number (`CAL.uploadSmall`, the MCP tool's docblock and refusal text; the
skill's `references/tools.md` still names 600 px for every kind — stricter
than the server, to be corrected with the next skill release, a wording
change alone is not worth a version). Why 300: `scanner/eval/RESULTS.md` §9.5 —
real calendar pages are often 977 × 457 px exports, every line is still
found at a 300 px short side, and what suffers is the exact face and a few
tenths of a point, which the review says under 600 px instead of refusing.

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
| `GET /project/{projectId}/sablony/historie-skenu` (`ai_scan_history`) | `canSeeProjectScans()`; the project needs the module Šablony OR Kalendáře (neither → „Objevte další moduly“) |
| `POST /project/{projectId}/ai-scan/access-request` | `ProjectVoter::EDIT` (the handler refuses whoever can already start scans there) |
| `GET /admin/ai-scan/access-requests`, `POST /admin/ai-scan/access-request/{id}/{approve\|dismiss}` | `ROLE_ADMIN` |
| `POST /project/{projectId}/ai-scan/entitlement` | `ROLE_ADMIN` + session CSRF token `project_ai_scan` |
| `GET /admin/usage` (tab "AI skeny"; query `ai_scan`, `ai_project`, `ai_page`), `POST /admin/ai-scan/runtime` (`admin_ai_scan_runtime`) | `ROLE_ADMIN`; the POST + session CSRF token `admin_ai_scan_runtime` |
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
  A project whose starts are paused today (three refusals by the provider,
  the start guard's own count) carries the pill `Pozastaveno do zítřka · N
  odmítnutí`; "Skeny" on each row opens the scans table narrowed to it.
- **Skeny** — every scan in progress (oldest first, tinted; on page 1 only),
  then the ended ones, **50 a page** (`?ai_page=<n>`, 1-based; "Novější" /
  "Starší"; out of range = the nearest page). **Eight columns, so the table
  fits a 1440 px window without sideways scrolling** — related figures share
  a cell on two lines (`.ai-sub` is the second line; the header repeats the
  pair): when / user (the e-mail is cut with an ellipsis, whole in `title`),
  project (a link that narrows the table to it) / module · channel, state /
  code, duration / try (↻ = restarted), cost / tokens (the split in
  `title`), credit of the current try (`rezervován` / `započten` /
  `vrácen`) / rating (rated scans only), memory / CPU (`resources.peakRssMb` / `cpuSeconds` of
  the last heartbeat) and the row menu `⋯`: "Otevřít sken" (`ai_scan_page`;
  a calendar scan opens `calendar_workspace?sken=`), "Otevřít projekt",
  "Zobrazit hodnocení" (rated scans only — a dialog with 👍 / 👎, who rated
  and the note) and "Využití skenu". Adding a column means re-measuring at
  1440 px (the table's `scrollWidth` ≤ its `.ai-wrap`'s `clientWidth`).
  **State names are by kind where the kind matters**: an accepted scan is
  "Šablona vytvořena", an accepted *calendar* scan "Rozvržení použito" (the
  wording of the user's history page); the other states read the same.

**Filters by link — a URL contract** (other pages link here; always with
`#ai-skeny`, which opens the tab):

| URL | Shows |
|---|---|
| `/admin/usage?ai_scan=<scan uuid>#ai-skeny` | that scan's row and, under it, every `ai_usage_event` row of the scan (run · step, model, when, duration, turns, input / output / cache tokens, cost, outcome + code) with their sum. The usage is shown even when the scan row is gone. Wins over `ai_project` |
| `/admin/usage?ai_project=<project uuid>#ai-skeny` | only that project's scans; paging keeps the filter (`&ai_page=`). The id of a **deleted** project that left usage or ledger rows: no scans (they went with it) and, in their place, its newest 200 usage rows (each linking to `?ai_scan=`) with their sum |
| `/admin/usage?ai_page=<n>#ai-skeny` | page n of the ended scans |
  **A deleted project that left spend behind** (usage or ledger rows whose
  `project_id` no project has — § "Credits and spend") is one more row of the
  first table per project id, after the living ones (`ProjectRow.deleted`):
  "Smazaný projekt" with the name its newest usage row remembers and the
  first 8 characters of the id, the remembered owner e-mail, its counted
  scans and cost — no link to the project, no switch, no limit; its only
  link is "Využití" (`?ai_project=<its id>`). The spend of today / the month
  in "Provoz" counts it as before.

A value that is not a uuid (or an array, or an unknown project) is ignored —
never a 500. "Zrušit filtr" goes to `/admin/usage#ai-skeny`. Linked from: the
scans' row menu, the project rows ("Skeny"), the admin's "Funkce Premium" box
on the edit-project page (`Využití →`, ux.md §9.2), the mail about a paused
project, and the wizard page's "Využití tohoto skenu" (admins only).
`AiScanUrls::adminProject()` builds the absolute project link.

**The row menu is a `popover` element**, not a Bootstrap dropdown: `.ai-wrap`
is a size container and scrolls sideways when a table does not fit (between
the card layout at 760 px and the scans table's ~860 px), so anything positioned inside it is
clipped. The menus are rendered after the table, opened by the row's
button (`popovertarget`), and `ai_scan_admin_table_controller.js` only places
the open menu at its button, keeps it there while the page scrolls, and puts
the focus back on the button when a rating dialog closes. The menu column is
`position: sticky; right: 0`. The rating dialogs are Bootstrap modals
rendered outside the cards, one per rated scan of the page.

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
each cell carries its label in `data-l` — a two-line cell names both, e.g.
`Náklady · tokeny`). The tab list lives in
`usage_stats_controller.js` (`TABS`).

Everything of ux.md §9.4 is built. One addition: the menu's fourth item,
"Využití skenu".

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
| `usage_suspect` | a usage row of the last 24 h cannot be taken at its word: the price table did not price it although something was reported (`price_version` null — an unknown model, the cost is the scanner's own figure), a build that delivered a proposal reported no tokens, or a cost at / an engine figure above the cap of $4.50 a run. The mail counts each reason and names the model. One reminder a day while such rows keep coming; **closes silently** (like `spend_80`) once 24 h passed without one | the oldest such row |

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

**A project paused for refusals** (architecture.md §5.4, §7) is not one of
the conditions above — it belongs to one project and one day. The pause is
never stored: `AiScans::assertMayStart()` counts the project's `refused`
usage rows closed today (Europe/Prague) and refuses the start with
`project_paused` from the third (`AiScanLimits::PROJECT_REFUSALS_PER_DAY`);
at midnight the count is zero again. `AiScanConditions::pausedProjects()`
makes the same count for every project, and `AiScanAlerts::run()` sends
**one mail per project and day**:

- the claim is the INSERT of the `(project_id, day)` row of
  **`ai_scan_project_pause`** (`… ON CONFLICT DO NOTHING`; whoever inserted
  it sends — `claimProjectPause()`); a mail the mailer refuses deletes the
  row again and the command exits 1;
- no reminder and no "back to normal" — it ends by itself;
- **mode `off`: not announced** (as everything else); if the mode comes back
  the same day, it is announced then. No recipients → nothing is claimed;
- the mail: project name and id, the number of refusals, the day, when starts
  resume (the next midnight), the mode, the code `project_paused`, what to
  do, and a button to `/admin/usage?ai_project=<id>#ai-skeny`. No picture
  text, no address. The watch prints `opened project_paused <project id>`;
- the watch runs every five minutes, so three refusals in the last minutes
  of a day may pass unannounced.

The admin tab shows the same count as a pill on the project's row.

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
| `POST {id}/beat` | `{run, seq, stage?, note?, noteParams?, round?, textBoxes?, usage, resources?}` — `textBoxes` (optional, additive): `{width, height, boxes: [[x, y, w, h], …]}`, the lines of text the reading found (§ "Stage log") | `{ok, cancel, mode, leaseExpiresAt}`; `cancel: true` (+ `status`) when the scan was cancelled or interrupted — stop. Extends the lease (build: 120 s). `seq` must grow; an older one changes nothing |
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
upload's header declared (null for HEIC and PDF).

`usage` is **cumulative for this `(scan, run, lane)`**:
`{provider, model, inputTokens, outputTokens, cacheReadTokens, cacheWriteTokens, cacheWrite5mTokens?, cacheWrite1hTokens?, engineCostMicroUsd, turns, durationMs}`
— `cacheWriteTokens` is every cache write; the two optional fields split it by
lifetime (absent = all 5-minute). Anything else in the object is ignored.

What is stored of what the scanner sends is closed, on purpose — no free text
of a picture reaches a column the screens read:

- `stage` / `note` are the codes of §5.6; `noteParams` only the note's named
  integers; `preCheck` a flat map of codes, numbers and booleans; of
  `sourceMeta` only the whole numbers `width`, `height`, `workingWidth`,
  `workingHeight`, `workingBytes` and the two pairs of a PDF's first page,
  `pagePt` and `pageMm` (`ScannerApi::sourceFacts()`).
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
- **A "left-out thing" that lies on built layers is dropped** before the
  template report is stored (`Template/OmittedOnLayers::without()`): an
  `omitted` entry whose box lies for at least half on layers the scan built
  — texts by their ink, pictures; shapes too for an `effect` or an entry the
  scanner added itself (`by: scanner`) — is a difference OF those layers, and
  the review would offer a place for a picture on top of the texts. The
  scanner applies the same rule (`omittedIsCovered` in
  `scanner/src/finish/report.mjs`, `measures.omittedOnLayers`); the app's
  copy also covers scanner images before it and proposals stored before it
  (§ "The proposal document"). Why: production, 2026-11-13 — the model named
  a headline's shadow both an approximation and an omitted `effect`; on the
  119 recorded scans all 34 entries the scanner had added itself lay on built
  layers (`scanner/eval/RESULTS.md` §12).

  entry in the compilation context that only this path passes
  (`DesignPreflight::review(…, $extraAssets)`), stored once in the public
  bucket at `ai-scan/stand-in-v1.png`. After accept (P3) empty places
  reference a per-project gallery row of `FileSource::ScanStandIn`, which no
  gallery listing shows and which can never be moved to the bin
  (`FileUpload::moveToTrash()`).
- **Font faces.** `FontFacesRenderer` renders lines of text in a project's
  faces as one picture with the cell geometry; the scanner measures and ranks.
  The scanner's verification render asks for a line at THREE times its fitted
  size (≤ 400 px, the endpoint's bound) and compares it with the source at
  sub-pixel positions; the verdict thresholds are `fonts.*` of
  `definition.json` (version 8: 0.88 / 0.85 from 12 glyphs / 0.78 from 20,
  `verifiedSmallInkPx` 22) — `scanner/eval/RESULTS.md` §10.1.
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
| `set_text` | `id`, `text` (≤ 2000, line breaks kept) | any text — **everywhere the text lives**: the element's `text` AND its input's `sampleValue` (the scanner writes both; the sample is what a saved template renders, pre-fills on the fill page and exports), and `maxLength` grows to 1.5 × the new text when it was shorter (`ApplyFixes::inputForText()`) |
| `confirm_text` | `id`, `text?` | a doubtful text is right (optionally corrected) |
| `set_input` | `id`, `input: bool` | `vyplňuje uživatel` / `pevný text` (the DSL's `input.locked`) |
| `rename` | `id`, `name` (≤ 40, plain) | a layer's name |
| `undo_shrink` | — | shrunk texts get the scanner's `fittedSize` back |
| `revert_palette` | — | colours snapped to the palette go back to the measured ones |
| `add_placeholder` / `keep_omitted` | `omitted` (index into `report.omitted`) | a left-out thing becomes a place `misto-{n+1}` over its box / stays out. `add_placeholder` on an entry that lies on built layers (`OmittedOnLayers`) is `invalid_fix` |
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
  `GET /ai-scan/{id}/image/proposal?v={seq}`), `canvas`, `format {unit,
  width, height, pxWidth, pxHeight}` — the size the template WILL have
  (`ScanProposal::dimension()`, § "Accept") —, `source` (the size the boxes
  are measured in), `rating`;
- `counts {texts, shapes, places, background}` — `background` is true for a
  colour / gradient background only (a background photo is a place), and
  `toDecide` — the counter of the summary line and the tab;
- `elements[]` in stack order: `id`, `type` `text` \| `shape` \| `place` \|
  `background`, `name`, `box` (source px) and per type `text`, `input`,
  `font`, `size`, `colour`, `doubtful` / `shape`, `fill`;
- `placeholders[]` in card order: `id`, `number`, `name`, `role`,
  `background`, `added`, `kind`, `kindLocked`, `fit`, `box` (source px),
  `canvasBox`, `width`, `height` (pixels **in the template** — the canvas
  box scaled to `format`; what `tooSmall` compares a picture with),
  `decidedEmpty`, `picture` (`fileId`, `url`,
  `name`, `width`, `height`, `uploaded`, `tooSmall`, `fitApplied`, `missing`);
  `pictures {total, filled, unfilled, greyBackground}` — `unfilled` is the
  tail of the create button;
- `decisions[]`: `key`, `type` `font` (with `pattern`
  `nearest_available` \| `undetermined`, `group`, `role`, `face`,
  `sizeChangePercent`, `texts`, `boxes`) \| `doubtful_text` (`id`, `reason`,
  `text`, `box`) \| `omitted` (`omitted`, `code`, `box`, `placeId`) \|
  `missing_font` / `missing_picture` (F15), `decided`, `decision`, `fixes[]`
  (the fix types it accepts). The left-out rows count as ONE thing to decide,
  and so do open font rows that ask the same thing (same `pattern` and
  `face` — a project with one face gets "we used the nearest: X" for every
  group; the review shows them as one card, § "UI"). A left-out row that lies
  on built layers and has no decision is not listed (a proposal stored
  before the rule);
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

The template's size (`ScanProposal::dimension()`), in this order — the first
that has the picture's ratio (± 2 %) and a long edge ≤ 5000 px; the document
is scaled to it (`ApplyFixes::scaled()`), a print size comes without bleed:

1. the format the upload sent — `options {targetUnit: px|mm|cm, targetWidth,
   targetHeight}`;
2. **for a PDF, its page** (`ScanProposal::pdfPageFormat()` from
   `source_meta.pagePt`, else `pageMm`) — the browser cannot read a PDF's
   size, so S2 offers no format for one and says „Rozměr převezmeme z první
   stránky PDF“. A PDF says how large the page is, not what it was drawn in:
   an A format (A3–A6, ± 1 mm) → that print format in mm; whole points →
   pixels 1 : 1 (Figma, Illustrator: 1080 × 1350 pt); whole millimetres → mm
   (a flyer 100 × 200); whole CSS pixels at 0.75 pt (Canva: 810 × 1012.5 pt)
   → those pixels; otherwise points as pixels, rounded. Known limit: a Canva
   page whose points are whole too (1080 × 1080 px = 810 × 810 pt) comes out
   as 810 px — the size is changed in the editor;
3. the proposal's canvas in px (the working copy's size).

Before 2026-11-14 step 2 did not exist and a 1080 × 1350 page became a
3276 × 4096 px template.

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

**The host of a link made outside a request.** This mail is rendered by the
messenger consumer, the reminder by `app:ai-scan:purge` and the admins'
alerts by `app:ai-scan:watch` — no request exists there, and without
`framework.router.default_uri` every such link was `http://localhost/…`
(found by the review of 2026-11-13). `config/packages/routing.php` now reads
it from **`DEFAULT_URI`**: `https://wboost.cz` in `.env` and as the parameter
default in `config/services.php` (so production needs nothing in its
environment), `http://localhost:8080` in `.env.dev` (a remapped `WEB_PORT`
goes to `.env.dev.local`), `http://localhost` in `.env.test`. Inside a
request the request's own host still wins. No other mail of the application
is rendered outside a request today — every other handler that builds an
absolute URL runs in the web request that dispatched it — but one that
moves to the consumer or a command is covered by the same setting.
`AiScanMailLinksOutsideARequestTest` renders the ended-scan mail and an
alert in a kernel that never handled a request and asserts the host. Check a
running environment with `bin/console debug:container --env-var=DEFAULT_URI`
or `bin/console debug:config framework router`.

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
| `ai_scan_status` | `GET /ai-scan/{id}` | — | `{scan}` — the status document (state, `waiting`, `restarted`, stage, note, `round`, `previewSeq` (grows with every stored render, across runs and tries — it only says "changed"), `previewCandidate` (the number of the running run's latest render, for `image/candidate?n=`; null otherwise), `stages` `{<stage>: {seconds, note, noteParams}}` — the stage log —, `textBoxes` (in progress only), `picture` `working` \| `original` \| `null` — the copy of the upload a browser can show now —, queue position and estimates, `retriesLeft`, `canRetry`, `canContinue`, `code`, `expiresAt`, result ids) |
| `ai_scan_proposal` | `GET /ai-scan/{id}/proposal` | — | `{scan, proposal}` (above) |
| `ai_scan_image` | `GET /ai-scan/{id}/image/{original\|working\|preview\|candidate\|proposal}` | `?n=` (candidate), `?v=` (cache) | the bytes, `Cache-Control: private, max-age=300`; 404 when gone (a missing `candidate`: 204) |
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
(`open_proposal`, `open_template`, `retry`, `continue`, `cancel`, `discard`)
and `options` (the format the scan was started with: `targetUnit`,
`targetWidth`, `targetHeight`).

**No page asks for a picture that does not exist.** `picture` says which copy
of the upload there is: `working` (and the small `preview`) once the pre-check
passed, `original` before that unless it is a HEIC or a PDF, `null` when the files are
gone. The wizard shows `image/{picture}`, the strip and the history
`image/preview` or `image/original` accordingly, a `ready` scan
`image/proposal` (which falls back to the kept candidate). There is no
client-side fallback chain any more — a 404 on `ai_scan_image` is a bug.
One race remains by nature — a run's renders are deleted when it ends, a
moment after a poll may have named one — so a missing `candidate` is answered
**204** (no body, `no-store`), never 404.
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
| `ai_scan_new` | `/project/{projectId}/sablony/z-obrazku` | `canSeeProjectScans` (403 otherwise) AND entitled — an editor without the entitlement is redirected to the template list with an `info` flash that says AI scan is off here and names „Požádat o přístup“ on the card (or that the request is already sent) |
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
- Stages: `stage` + `note` + `noteParams` + `round` for the running one;
  **the duration of every finished stage and the note it ended with come from
  `stages`** (the server's stage log), so a reload, another tab or somebody
  else's browser shows the same list. `sessionStorage` (`wb-as:{scan}:{try}`)
  keeps only when this browser first saw the try and the two clocks that
  change the card's text.
- The preview pane: the dimmed original with the sweep; once the reading
  reported its lines (`textBoxes`) **the original with the found lines
  outlined — "Tyto texty jsme našli ({n})"**; from the first render
  `GET ai_scan_image/candidate?n={previewCandidate}&v={previewSeq}`. The
  renders of a run are deleted when it ends, so nothing asks for one of a
  run that is over: after an automatic restart the page keeps showing the
  last render it had (the element's address is unchanged — the browser's
  cache answers).
- The uploaded picture is `image/{picture}` (above) — nothing is guessed.
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

**Texts are textareas** (`textField()` in `page/review.js`): a text of the
design may have line breaks, and an `<input>` drops them — it showed „Jarní
slavnostive městě“ and would have saved it so. One row per line; in „Vrstvy“
Enter is a new line and the change is sent on blur; in „Přečetli jsme to
správně?“ Enter confirms and Shift+Enter is the new line.

**One font question, one card.** Open font decisions with the same `pattern`
and `face` are drawn as one card (`fontQuestion()`): „Vybrat jiné písmo“,
„Nahrát správné písmo“ and „Nechat u všech“ send the fix to every group, one
after the other; „Rozhodnout jednotlivě (n)“ unfolds the groups with their
own buttons. A decided group leaves the card and gets its own „Vrátit“ row.

**A cancelled scan** opened later (`goneView()`): while the picture is kept
and a scan may start, „Stejný obrázek znovu“ puts the stored original back
on S2 („Než začneme“ — format, consent, what it costs; nothing starts by
itself), next to „Nahrát jiný obrázek“.

- **Card in "Nová šablona"** (`templates/ai_scan/_entry_card.html.twig`,
  `ai_scan_entry()` = `AiScanLists::entry()`): the states of ux.md T1. Its
  sentence says „(obrázek nebo PDF)“ — the accepted types are listed once, on
  the upload zone (`JPG, PNG, WebP, HEIC nebo PDF (první stránka)`).
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
  chips as links, the row menu from the row's `actions[]`. Empty, in a
  project without AI scan (`entry.state` `locked` / `request_sent`), it shows
  the locked state — „AI sken v tomto projektu není zapnutý“ with „Požádat o
  přístup“ (the `ai-scan-entry` controller, `origin` = the module the page
  hangs under) or „Žádost o přístup odeslána …“ — never an invitation to
  upload; empty while nothing can start for another reason it invites nobody.
- **Shell** — `docs/architecture/app-shell.md` § "AI scan signals".
- **Export dialog**: forms whose variants may have unfilled pictures
  (`ai_scan_unfilled()` > 0 — an upper bound) carry
  `ai-scan-export-guard`: the same POST is sent with `Accept:
  application/json` first; a 409 `unfilled_pictures` shows the dialog,
  "Exportovat přesto" submits the form with `allowUnfilled=1`, and a file that
  comes back instead is saved from that response (one render). Publish handles
  the 409 in `variant_publish_controller.js`. A template without a stand-in
  picture is not touched by any of this. The answer travels as a field that
  exists for that one submit; **`fill_draft_controller.js` leaves
  `allowUnfilled` out of its unsaved-work snapshot** — with it in the
  baseline every confirmed export left the fill page "unsaved" and leaving
  it asked (found by running the confirmed exports for real, 2026-11-13).
  The same day's walk of the publish dialog found that its "Exportovat
  přesto" sent nothing (a `return` in front of the repeat in
  `variant_publish_controller.js`); `around.e2e.cjs` now holds both.
- **Admin**: the open access requests of a project are rows in "Funkce
  Premium" (`ai_scan_admin_controller.js` → `ai_scan_access_request_resolve`);
  the admin's user page has the switch per owned project (`form=` anchors,
  `return_user` — a uuid — brings the admin back). The tab "AI skeny" is
  § "Admin tab".

### Deviations from `docs/ai-scan/ux.md` (UI)

- **S3 "Tyto texty jsme našli"** is drawn from `textBoxes`; a scanner that
  sends none (an image older than this field) leaves the dimmed original with
  the sweep until the first rendered candidate. In places for pictures the
  running preview shows what the candidate render shows (the stand-in), not
  the original's pixels — that overlay needs the proposal's boxes, which
  exist only at the end.
- **"Vybrat jiné písmo ▾"** is a popover listing the project's faces, each in
  its own typeface (`proposal.fontFiles` → one `@font-face` per face, loaded
  when the picker opens), the face in use ticked, arrow keys / Home / End /
  Escape; its last row **"Přidat písmo…"** uploads a font file into the
  project and switches the group to it (the same path as "Nahrát správné
  písmo", which stays as a button of its own).
- **S2 fonts row**: "Přidat" uploads font files inline; "Přidat písmo Outfit"
  is left out on purpose — the app bundles no font file it may hand to a
  project (Outfit is the interface's web font, loaded from the font CDN), and
  a project without a font is one upload away from starting.
- **"Naskenovat znovu"**: "Stejný obrázek znovu" starts a new scan of the
  stored original in the same format at once (the dialog has already said
  what it costs); "Stejný obrázek, jiný formát" puts the original back on S2,
  where the format is chosen. Both are the browser re-uploading the original
  it fetched — there is no server-side "rescan", so a new scan is always an
  ordinary start with every start rule. When the immediate start is refused
  (a limit, the queue, a consent whose wording changed) the picture lands on
  S2, which shows the reason.
- **No project-logo pre-fill; "Změnit na tvar" uses the colour the browser
  measured; an empty "Součást šablony" place renders as a centred grey square;
  "Vyplnit" is the browser's crop** — a file uploaded into a place (or
  uploaded in this session) is cropped to the place's ratio; a gallery picture
  of another ratio is fitted whole and the card says so.
- A full-page background place shows no dimmed original in the viewer (it
  would cover the whole proposal) and its card has no `⋯` menu.
- **History**: the page hangs under Šablony when the project has that
  module, else under Kalendáře (back link, sidebar item) — a project with
  only Kalendáře reaches it too. "Nový sken" is the menu `Šablona z obrázku` ·
  `Naskenovat design kalendáře…` when the project has both modules, a plain
  link with one of them. A calendar scan starts inside a calendar: the
  calendar item opens the scan panel of the project's ONLY 7-day calendar
  (`/calendar/{id}?sken=1`), or — with none or several — the calendar list
  (then the label ends with `…`).
- **"Využití tohoto skenu"** (admins only) sits at the right end of the back
  row of every screen that shows a scan, not only the read-only one, and
  opens `/admin/usage?ai_scan={id}#ai-skeny`.
- The read-only page lists a decision as open or settled from the proposal;
  it does not know what was changed in the editor afterwards.

## Calendar scans (P5)

A picture of **one page of a desk calendar** becomes the proposed
**layout** of an existing calendar (`ai_scan.calendar_id`, given at the
upload; another project's calendar is `wrong_state`; a project without fonts
is fine). **The scan targets the calendar's own type** (since 2026-11-13,
§ "14-day calendars" below): a 7-day calendar gets one of the 7-day layouts,
a 14-day one (`type: fortnight`) the two-sided layout with its second frames. The job, the states, credits, storage and
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
  `supported: false` (the calendar is gone) → the build fails. For a 14-day
  calendar: `type: "fortnight"`, `presets: ["twoSided"]`, `defaultLayout
  {preset: "twoSided", layout}` (with `cal2` / `month2` / `wk2`), and a
  sample page of two weeks.
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
  **The picture shows nothing the customer put into the calendar**
  (`CalendarCandidateContent::neutral()`, review of 2026-11-13): the
  candidate's picture goes back to the scanner and on to the AI provider,
  and the consent speaks of the uploaded picture, what is read from it, the
  project's font names and palette only. Until then the sample week was
  drawn with the calendar's real content — its events and their clubs, extra
  names, waste dates, the week's photo, caption, page pictures and QR code.
  Now the in-memory document carries the content of a calendar nobody has
  filled in (`CalendarContentSeed::initial()`), whatever is stored; the
  renderer's sample texts (caption, LOGO / QR placeholders) stand where
  nothing is. When the candidate has `events` on, two sample events
  („Ukázková akce“) are put into it (`withSampleEvents`) so the picture
  shows where event texts land. The layout holds no content either — a
  candidate always starts from a preset's default — and the claim's
  `context.calendar` never did. Tests: `CalendarScanTest::testACandidateIsDrawnWithoutTheCalendarsOwnContent`
  (the document handed to the renderer) and, through the real Gotenberg,
  `CalendarRenderDocumentGotenbergTest::testACandidateShowsNothingOfTheCalendarsContent`
  (the same pixels with and without content). A trade-off to know: a
  calendar's waste dates and shifts are not in a candidate either, so the
  scan cannot see where THEY would land — as for every calendar scanned
  before its content exists, which is the usual order.
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
- **Small pages**: the calendar pre-pass runs the OCR at a 2000 px long edge
  when the working copy is smaller (≤ 3 ×; boxes come back in source px) —
  `RESULTS.md` §9.5.
- The contract fixture (`scanner-api.v1.json`) holds a calendar scan too
  (appended with `record-contract.mjs --append-calendar`): the claim with
  `context.calendar`, a render from commands, two refused command lists, the
  calendar report. `ScannerContractFixtureTest` replays it with the suite's
  fake candidate picture; the REAL render through Gotenberg is
  `CalendarRenderDocumentGotenbergTest`, which CI runs in its own job
  (`calendar-render` of `.github/workflows/test.yml` — the page sent to
  Gotenberg is self-contained, so a service container is enough).
- `numberSide: "middle"` is accepted on the model's patch path since P5 (the
  editor always offered it); nothing under `assets/calendar/` changed for
  scans — `CalendarExportFingerprintTest` pins the renderer fingerprint.

### 14-day calendars (`type: fortnight`)

Built 2026-11-13; measurements `scanner/eval/RESULTS.md` §11.

- **Who is handed the build.** `kinds.calendar.variants.fortnight` of
  `definition.json` names its own instrument (`requiresInstruments: ["mm14"]`),
  its instructions (`system.calendar.md` + `system.calendar.fortnight.md`)
  and its candidate tool (`calendar_preview.fortnight` of `tools.json`).
  `ScannerApi::claim()` asks `AiScanDefinition::canBuildVariant('calendar',
  'fortnight', $instruments)`; a scanner that does not announce `mm14` gets a
  claim query with `AND NOT EXISTS (… calendar.type = 'fortnight')` — it
  keeps building templates and 7-day calendars, and the 14-day scan waits
  `queued` for an image that can build it (the queue timeout applies). The
  same mechanism as `mm` for the kind, one level down. **Release order:** the
  app with the definition first (an older image is simply never handed such a
  scan), then the image, then the pin.
- **Candidate.** `CalendarCandidate` no longer looks at the type: the default
  preset is the type's first (`CalendarType::presets()[0]`), a preset of the
  other type is the model's own refusal (`preset_type_mismatch`, an answer
  with `candidate: null`). The sample page is `w02`, or `w03` in a year whose
  14-day pages are the odd weeks; with `events` on, a third sample event
  lands in the second week column.
- **The scanner** sends every second frame explicitly: one the model does not
  name is the mirror image of its first frame (`scanner/README.md`).
- **Report.** `type` = `week` | `fortnight` (the calendar's). Notes when the
  page and the calendar disagree: `fortnight_left_half` (a two-week picture
  for a 7-day calendar — the left week was rebuilt) and
  `week_page_on_fortnight` (a one-week picture for a 14-day calendar — the
  second column mirrors the first). A page that fits its calendar has
  neither.
- **Proposal / accept / restore.** `CalendarScanProposal::preset()` knows
  `twoSided`; an element the report calls `absent` keeps the calendar's
  current box **and its second frame** (`month2`, `wk2`). Accept refuses
  (`wrong_state`) a proposal whose preset does not fit the calendar's type
  NOW (the type was changed after the scan). The snapshot and restore are
  type-agnostic. Tests: `CalendarFortnightScanTest`.
- **Browser.** The step-2 card is offered on 14-day calendars; the review's
  alert is `mismatchAlert` (`CAL.mismatch[code]`) and appears only for the two
  notes above — `calendars.md` § "Calendar scan — UI".

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
`rating` (null | 1 | -1 — „Jak se sken povedl?“), `applied` (null until accepted: `{restoreUntil, restorable, restored, changed,
features}`).

### Pictures are held, not uploaded

`POST /calendar/{calendarId}/scan/{scanId}/picture` (`slot` + `picture` — a
file —, `slot` + `fileId` — a picture of the project's gallery —, or `slot` +
`remove=1`) keeps a picture **with the scan** — `picture-{slot}.{ext}`
in its private directory, named in `ai_scan.pictures.slots` by a conditional
update (`… AND status = 'ready'`). JPEG / PNG / WebP by their bytes (≤ 60 Mpx
by the header) or an SVG, ≤ 10 000 000 bytes. A gallery pick COPIES the file's
bytes (`CalendarScanPictures::holdGalleryFile`): the proposal does not depend
on what happens to the gallery file afterwards, and the gallery is not
changed; a file of another project, a trashed one or a non-gallery upload is
`invalid_fix` (`field: picture`). Held pictures are served from the app's own
origin for the preview (`GET /ai-scan/{id}/image/picture?slot=`) — with
`Content-Security-Policy: … sandbox`, so an SVG opened as a document runs no
script and has no origin of ours. They become calendar assets only at accept. Calendar assets can never be deleted, so a
discarded or expired proposal must leave none — and leaves none: the scan
directory goes with the scan.

### Accept — `AcceptCalendarScan`

`POST /calendar/{calendarId}/scan/{scanId}/accept` (`features[]`). Under the
scan's row lock and the calendar's row lock, in the bus transaction:

1. a repeat on an `accepted` scan answers with the first result
   (`created: false`) — except `unfilledPictures`, which is always counted
   against the calendar as it is NOW (the scan's slots whose picture the
   calendar's layout does not carry), for the apply and for a repeat alike;
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
  scan whose snapshot is still there. Both are read **within the project of
  the URL** (`project_id = … AND calendar_id = …`): the caller is checked
  against the project only and the calendar id is a query parameter, so a
  calendar of another project answers with nothing. Rows carry `calendarId`; calendar rows
  are named after the calendar and may offer `open_calendar` and `restore`.
- `GET calendar_card` adds `scan`: `running | queued | ready | failed | null`
  (designers only) — the pill of ux.md C5.
- E-mails and MCP link to `/calendar/{id}?sken={scanId}` (`AiScanUrls::scan`).
- MCP `scan_design`: `start` with `kind: "calendar"` + `calendarId` (a 7-day
  or a 14-day calendar; the docblock says which page fits which); `result`
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
| `app:ai-scan:purge` | reaper, expiry, files and row texts of accepted / discarded scans past their date, orphan files, leftover candidate renders, the scrub net, leftover usage rows, due e-mails — nightly host cron on the box (`ai-scan-purge`, 03:00 Europe/Prague) |
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
  state × event), `AiScanPurgeAndStorageTest`, `AiScanRetentionScrubTest`
  (what leaves the row at discard / expiry / the end of an accepted scan's
  retention, the pages that still answer, a failing storage, the sweep and
  the net), `AiScanUsageGuardsTest` (the cost cap, `usage_suspect`),
  `AiScanMailLinksOutsideARequestTest`,
  `tests/Controller/Internal/ScannerReleaseCapTest`.
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
  scanner API. Not part of CI. They need the stack's one build slot and
  clear its cache, so beside other work they run against an app container of
  their own (`E2E_WEB_CONTAINER`, `E2E_DB` — README there). What they do NOT
  fake: the confirmed exports of the group and the document fill pages run
  for real (the ZIP and the PDF are saved and their bytes checked). What they
  stub, at the edges only: the publish dialog's question needs a linked
  Facebook account and a social format, neither of which a scanned template
  has in dev — `around` inserts a `social_account` row and the preset marker
  for one check and answers Meta's two calls (the page list, the confirmed
  publish) itself; the dialog, the question over it and the app's own 409
  are real. `UnfilledPicturesWebExportTest` covers the publish endpoint's
  rule in PHPUnit.
- P4: `tests/Controller/Admin/AdminAiScansTabTest` (who sees and writes the
  tab, every form of the strip against the runtime row, `off` asking first
  and interrupting scans, the page empty / with requests, projects and
  scans; the row menu and the rating dialog, paging — 120 ended scans, pages
  of 50, in-progress on page 1 only, nonsense page numbers —, `?ai_scan=`
  with every usage row and after the scan row is gone, `?ai_project=` with
  paging, ignored non-uuids, the "Využití →" link of the edit-project page,
  the paused pill), `tests/Services/AiScan/AiScanAlertsTest` (each condition
  opens once, the reminder, the end, the quiet half hour, nothing while
  `off`, a claim won once, the watch command; the paused project: once per
  project and day, again the next day, not while `off` but once the mode is
  back, its claim won once, nothing without recipients — time is passed to
  `run($now)`). The tab's writes, the row menu, paging, filters and every
  alert mail were walked once in a real browser (2026-11-13); that walk is
  not a script of the repository.
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
  `tests/e2e/calendar/scan.e2e.cjs`. **14-day calendars:**
  `tests/Controller/Calendar/CalendarFortnightScanTest` (a scanner without
  `mm14` is not handed the build but still builds a 7-day one; the claim's
  context; a candidate with second frames, a preset of the other type as an
  answer; accept, restore; an absent element keeps both frames; a proposal
  for 14 days refused on a calendar that became a 7-day one), the node suite
  `scan-marker` (the layout card over a scanned two-sided layout), and the
  three "14 days" checks of the e2e script.
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
- A new column that holds something read from the picture, typed into the
  review or named by the user goes into `AiScanTransitions::SCRUB` **and**
  `SCRUBBABLE` — otherwise it outlives the deletion the screens promise.
- Nothing of a customer's own content may enter a candidate render or a
  claim's `context`: what goes to the scanner goes to the AI provider, and
  the consent names the uploaded picture, the project's fonts and palette —
  nothing else. Extending either means changing the consent copy and its
  version first.
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
- JSONB does not keep key order: `stage_log` is read in `AiScanStage`'s
  order, never in the stored one. And a `?` in a JSONB expression is a
  placeholder to DBAL — use `->` / `jsonb_exists()`, not the `?` operator.
- A menu or popup inside `.ai-wrap` is clipped (it scrolls sideways): use a
  `popover` element or render it outside the wrapper.
- `ai_scan_project_pause` is only the claim of a mail. Never read it to
  decide whether a project may start — count the usage rows.
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
