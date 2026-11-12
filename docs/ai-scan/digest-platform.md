# AI scan — platform digest (exploration, 2026-11-12)

Read-only exploration of the repo and of the production box (`lily.srv.thedevs.cz`,
`/srv/wboost`) to prepare the platform side of "AI scan" (upload a picture of a
design → a background job calls an LLM → an editable calendar / template).
No production code was written. Every statement is tagged:

- **[M]** measured / read directly (file:line, or command output on the box, 2026-11-12 ~11:15 CET)
- **[I]** inferred from what was read
- **[S]** suggestion — a design proposal, not a fact

Paths are relative to the repo root unless they start with `/srv` (the box) or
`~/www/lily.srv` (the infra repo; `apps/wboost/compose.yaml` there has the same
430 lines as `/srv/wboost/compose.yaml` — line numbers below apply to both **[I]**).

---

## 1. Module / toggle mechanism

### What exists [M]

| Piece | Where |
|---|---|
| Enum of switchable modules (label, description, icon, landing route, `defaults()`) | `src/Value/ProjectModule.php:17-85` |
| Storage: `project.modules` JSON list, `hasModule` / `enabledModules` / `disabledModules` / `enableModule` / `disableModule` | `src/Entity/Project.php:57-67`, `:148-209` |
| History row per switch (`project_module_change`: project, module, enabled, changedAt, changedBy) | `src/Entity/ProjectModuleChange.php:25-54` |
| Enable: POST + CSRF, **`ProjectVoter::EDIT`** (any project editor), redirects to the module landing or back to the admin card (`back=edit|dashboard`) | `src/Controller/Project/EnableProjectModuleController.php:37-72` |
| Enable handler: writes the change row, **e-mails `$adminNotificationRecipients` only when a non-admin enabled it** | `src/MessageHandler/Project/EnableProjectModuleHandler.php:46-88`; recipients bound from `SIGNUP_NOTIFICATION_EMAILS` in `config/services.php:49-51`; mail template `templates/emails/project_module_enabled.html.twig` |
| Disable: **`ROLE_ADMIN` only** | `src/Controller/Project/DisableProjectModuleController.php:34-63`, handler `src/MessageHandler/Project/DisableProjectModuleHandler.php:34-51` |
| "Objevte další moduly" page (lists `project.disabledModules`, switch for editors, lock text for read-only members) | `src/Controller/Project/ProjectModulesController.php:23-32`, `templates/project_modules.html.twig:29-58` |
| Guard: `#[RequiresProjectModule]` on LANDING controllers only → redirect to the discover page with an info flash | `src/Attribute/RequiresProjectModule.php:19-26`, `src/Services/ProjectModule/ProjectModuleGuardListener.php:22-71` |
| Nav: one `if nav_project.hasModule(...)` per module; discover item while any module is off | `templates/_shell/_sidebar.html.twig:50-112`, `:128-132` |
| Admin switch cards "Moduly projektu" (+ history on the settings page) | `templates/edit_project.html.twig:24-75`, `templates/project_dashboard.html.twig:162-185` |
| Doc | `docs/architecture/app-shell.md:172-209` |

**`ProjectModule::isInPreview()` no longer exists [M].** It was added in commit
`4f55eab9` (2026-11-11) and removed at the calendar launch in `9e63a5f8`
(`docs/architecture/calendars.md:516-523`). `grep -rn isInPreview src templates tests`
returns nothing. The memory note that still mentions it is stale. What the gate
consisted of (from `git show 4f55eab9`), i.e. what has to be re-created:

1. `ProjectModule::isInPreview(): bool` (returned `$this === self::Calendars`);
2. `EnableProjectModuleController`: `if ($module->isInPreview() && !isGranted('ROLE_ADMIN')) throw AccessDenied` before the CSRF check;
3. `project_modules.html.twig`: `offered_modules = project.disabledModules|filter(m => not m.inPreview or is_granted('ROLE_ADMIN'))`;
4. `_sidebar.html.twig`: the discover item's condition (one line changed);
5. `tests/Controller/Project/ProjectModulesTest.php` cases.

The guard is **decluttering, not access control** (`app-shell.md:203-209`): item
pages, API and MCP are deliberately not gated. AI scan spends money, so it needs a
real authorisation check at the request endpoint — the module guard alone is not enough **[I]**.

### How a per-project switch + admin-only preview + CTA can reuse it [S]

AI scan is a *capability inside* Kalendáře / Šablony (a button "Vytvořit z obrázku"),
not a nav destination with a landing route. Three options:

| Option | What | Pro | Con |
|---|---|---|---|
| **A. New `ProjectModule::AiScan` case** | reuse enum, JSON list, change log, admin cards, mail | zero new tables; admin cards and history come free | needs `landingRoute()`/nav decisions for something that has no page; **any project editor can enable a module** (`EnableProjectModuleController.php:43`) → the 4f55eab9 gate must be restored *permanently* as "admin-enable-only", and the discover page must render it as a CTA instead of a switch; `disabledModules is not empty` keeps the "Objevte další moduly" nav item alive in every project |
| **B. Separate project feature flag** (`project.features` JSON list or `ai_scan_enabled` bool + a change log row; a `ProjectFeature` enum mirroring `ProjectModule`) | independent of module semantics | clean authz (`AiScanVoter`), no nav side effects, room for more paid features | new column + migration + its own card rows (can sit in the same "Moduly projektu" box as a second group) |
| **C. Per-user capability** on `User` (same shape as `mcpScopes`: JSON list on the user, edited in `admin/edit_user`) | `src/Entity/User.php:60-71`, `:138-157`; UI section "AI aplikace" `templates/admin/edit_user.html.twig:106-123` | matches "switch on from user administration" literally | usage/credits are naturally per project; a user flag alone says nothing about which project pays |

How "per project" and "per user" can relate **[S]**:

- **Recommended**: the *project* flag is the entitlement (credits and usage hang on the project / its owner); *who may press the button* = `ROLE_ADMIN` always, otherwise `project_edit` on a project with the flag on. Phase 1 ("admins only") = nobody has the flag, so only admins see the real button; everyone else sees the CTA. Phase 2 = an admin flips the flag per project.
- "From user administration": `admin/edit_user` already lists the user's projects (`templates/admin/edit_user.html.twig:82-104`, rows from `GetProjectAccess`, `src/Controller/Admin/EditUserController.php:58`). Put a per-project AI switch on each **owned** project row there (plus the same switch in the "Moduly projektu" cards). That gives one source of truth (the project flag) reachable from both screens.
- Optional ceiling: a per-user allow/deny (option C) AND-ed with the project flag, only if a project must be on while some of its members must not spend credits. Not needed for phase 1.
- Effective check in one place: `AiScanAccess::canScan(User, Project)` = `isAdmin || (project.hasFeature(AiScan) && voter EDIT [&& user allowed])`; Twig gets `ai_scan_state(project)` ∈ `available | cta | hidden`.

CTA for others: render the same entry button; when the state is `cta` it opens a modal instead of the upload dialog (§3).

---

## 2. Usage / event log

### What exists [M]

- `export_event` — `src/Entity/ExportEvent.php:33-82`. Immutable, **FK-less, denormalised on purpose** (`:27-31`): `exportedAt`, `templateType`, `channel` (`src/Value/ExportChannel.php:15-19`: web/api/mcp/facebook/instagram), template id+name, variant id, project id+name, owner id+email, `triggeredByUserId` (nullable), `format`. Indexes on `exported_at`, `owner_id`, `project_id`.
- Recording: one service, `src/Services/Usage/RecordExportUsage.php:28-74` — resolves labels, dispatches `RecordTemplateExport` on the command bus, **swallows and logs every failure** (`:42`, `:66-72`); the current user comes from `Security` (`:51`). Handler `src/MessageHandler/Usage/RecordTemplateExportHandler.php:23-40` (uuid7 id).
- Call sites (last line before the response): `TemplateVariantDownloadController.php:165`, `TemplateVariantPublishController.php:161`, `TemplateGroupExportController.php:145`, `TemplateGroupExportVariantController.php`, `TemplateDocumentExportController.php:130`, MCP `ExportVariantTool.php:210`, `ExportDocumentTool.php:204` (+ the API processors).
- Report: `/admin/usage`, `src/Controller/Admin/AdminUsageController.php:25-34` → three read models (`GetUsageOverview`, `GetUserActivity`, `GetStorageOverview`). `src/Query/GetUsageOverview.php:33-66`, `:161` is raw SQL over `export_event` grouped by owner / project / `date_trunc('month', exported_at)`; chart series built in PHP (`:189-250`).
- Template: `templates/admin/usage.html.twig` — tabs + KPI tiles + chart cards, `data-controller="usage-stats"` (`:51`), charts as `data-series` / `data-categories` JSON (`:112-113`, `:258-260`, `:378-379`), heat map (`:132`), ApexCharts as a standalone script (`:477-479`).
- A second FK-less analytics table, `user_activity_day` (per user per day, native UPSERT, raw-SQL read): `src/Entity/UserActivityDay.php:18-52`.
- Calendar exports are **not** in `export_event`; they have their own job table (§4) and a history line.

### How scan usage + credits could extend / parallel it [S]

Do not overload `export_event` (its columns are template-export specific and non-null). Add a parallel table in the same style:

`ai_usage_event` (immutable, FK-less, denormalised): `id` uuid7, `occurred_at`, `kind` (calendar_scan / template_scan / …), `scan_job_id`, `attempt`, `project_id` + `project_name`, `owner_id` + `owner_email`, `triggered_by_user_id` + e-mail, `provider`, `model`, `input_tokens`, `output_tokens`, cache read/write tokens, `cost_micro_usd` (integer, computed at write time from a versioned price table so later price changes do not rewrite history), `credits_charged`, `outcome` (succeeded / failed / refused / cancelled / timeout), `error_code`, `duration_ms`, `request_id` of the provider. Indexes like `export_event`.

- **One row per provider call**, not per job: retries and multi-step pipelines cost money each time; the job row (§4) carries the totals.
- Written **from the consumer**, where `Security::getUser()` is null — unlike `RecordExportUsage.php:51`, the requester must travel on the job row.
- Same "tracking never breaks the job" rule, but the other way round for billing: a provider call whose usage cannot be recorded should be logged at error level with the token numbers, so it can be replayed.
- Credits/limits: a separate append-only `ai_credit_ledger` (`project_id`, `delta`, `reason` grant/scan/refund/adjustment, `ref` = scan job id, `created_by`, `created_at`); balance = `SUM(delta)`. Reserve on request (inside the row-locking request handler, §4), settle or refund when the job ends. Limits (per project / per user / per day) are queries over the same two tables. Billing later reads the ledger.
- Admin view: a fourth tab on `/admin/usage` (the page already has the tab + chart + table kit) fed by a `GetAiUsageOverview` query in the style of `GetUsageOverview`; a per-project summary line in the "Moduly projektu" card next to the switch.

---

## 3. Fake-door modal and admin user screens

### Fake door (Facebook / Instagram) [M]

- Modal markup only: `templates/_social_publish_fake_door.html.twig:1-33` (`#social-publish-fake-door`, Bootstrap modal, class `fi-door`, one "Rozumím" button).
- Included once per page by `templates/base.html.twig:129`.
- Triggers (`data-bs-toggle="modal" data-bs-target="#social-publish-fake-door"`): nav `templates/_shell/_sidebar.html.twig:113-126` (real link when `facebook_connected()`, `src/Services/Twig/TwigExtension.php:53`), fill page `templates/components/VariantFiller.html.twig:353-364`, group fill `templates/template_group_fill.html.twig:122-123`.
- **It records nothing and sends nothing**: no controller, no route, no JS, no table. Interest is not measured. Pinned only by `tests/Controller/Project/ProjectModulesTest.php` (nav order).

### What a "request AI — contact us" CTA needs beyond it [S]

Reuse the modal shell (`fi-door` look), add a real POST:

- Closest existing "store AND mail, never lose it" pattern: `LandingLead` (`src/Entity/LandingLead.php:18-70`, `src/Controller/Landing/LandingLeadController.php`, `src/MessageHandler/Landing/CaptureLandingLeadHandler.php`, listed at `/admin/landing-leads`, rate-limited in `config/packages/rate_limiter.php`).
- Closest "request → admin approves" pattern: `RegistrationRequest` shown inline on the users page with a count badge on the nav item (`src/Controller/Admin/AdminUsersController.php:37-50`, `templates/_shell/_sidebar.html.twig:141-147`).
- Proposal: `ai_access_request` (user, project, message, created_at, status) + e-mail to `$adminNotificationRecipients` with a deep link to the project's switch (same shape as `EnableProjectModuleHandler.php:70-87`); requests listed where the switch lives. One click for the admin: "Zapnout AI pro projekt X".

### Admin user administration [M]

| Screen | Where |
|---|---|
| Users list (me, pending invitations, others; registration requests inline; per-user project access) | `src/Controller/Admin/AdminUsersController.php:29-51`, `templates/admin/users.html.twig` |
| Edit user = profile page: name, role picker, read-only "Projekty" list (owned + shared), "AI aplikace" (MCP scopes) | `src/Controller/Admin/EditUserController.php:29-60`, `templates/admin/edit_user.html.twig:63-124`, `src/FormData/EditUserFormData.php`, message `EditUser` |
| Invite / resend / delete / signup requests | `src/Controller/Admin/InviteUserController.php`, `ResendInvitationController.php`, `DeleteUserController.php`, `AdminRegistrationRequestsController.php`, `DismissRegistrationRequestController.php` |
| Roles | `src/Entity/User.php:23-24` (`ROLE_DESIGNER`, `ROLE_ADMIN`), `:102-126` |
| Sharing | managed per project (the edit-user page says so, `edit_user.html.twig:85`) |

Where a switch would live **[S]**: (a) project level — a row in the admin "Moduly projektu" boxes (`edit_project.html.twig:29`, `project_dashboard.html.twig:165`); (b) from user administration — on each owned-project row of `edit_user.html.twig:89-102`; (c) user level — a new `up-sec` next to "AI aplikace" (`:106`). **The whole edit-user page is one `<form>`** (`:34` … `:130`): per-row switches there must use `form="…"` anchors or sit outside it — never a nested form (CLAUDE.md cross-cutting rule).

---

## 4. Async job pattern to mirror (calendar PDF export)

### What exists [M]

- **Buses** — `config/packages/messenger.php:13-30`: `command_bus` (default, `doctrine_transaction` middleware) and `job_bus` (no middleware, so flushed progress is visible while the handler runs).
- **Transports** — `:31-53`: `sync`, `failed` (`doctrine://default?queue_name=failed`), `async` (`MESSENGER_TRANSPORT_DSN`, `auto_setup: false`, retry 3× with 30 s → 5 min → 15 min). In production the transport is Doctrine/Postgres, Redis is cache only (`/srv/wboost/compose.yaml:26-27`).
- **Routing** — `:54-59`: `WarmupCache`, `WBoost\Web\Events\*`, `SendEmailMessage`, `RunCalendarExport` → all on the **one** `async` queue. Tests route the job and mail `sync` (`config/packages/test/messenger.php:13-19`).
- **Job row** — `src/Entity/CalendarExport.php:36-169`: `status` queued/running/done/failed (`src/Value/Calendar/CalendarExportStatus.php:9-12`), `progressDone`/`progressTotal`, `path`, `sizeBytes`, `error` (a Czech sentence ≤ 255, technical cause goes to the log), `updatedAt` = heartbeat, `finishedAt`, requester kind + user (`SET NULL`), input fingerprint (`calendarRevision`, `rendererHash`). Methods `start/advance/finish/fail`, `isStale` (`STALE_AFTER_SECONDS = 600`, `:49`, `:153-157`), `isPending`.
- **Request** — `src/Services/Calendar/CalendarExports.php:85-99`: dispatch `RequestCalendarExport` on the command bus (handler locks the calendar row so two requests end in one job — `src/MessageHandler/Calendar/RequestCalendarExportHandler.php:37-46`), then dispatch `RunCalendarExport` on the injected `job_bus` (`:47-48`, `:94-96`) only for a row it created. Reuse of a finished/pending job with the same inputs: `:62-78`.
- **Run** — message `src/Message/Calendar/RunCalendarExport.php:15-21`; handler `src/MessageHandler/Calendar/RunCalendarExportHandler.php:16-28` (`#[AsMessageHandler(bus: 'job_bus')]`); worker `src/Services/Calendar/CalendarPdfExporter.php:50-92`: returns silently when the row is gone or final, **never throws**, maps exception classes to user sentences, flushes progress after every step (`:98-111`), cleans its work dir in `finally`, prunes old results (`:166-191`).
- **Failure semantics** — no Messenger retry (the user has "Zkusit znovu"); a busy Gotenberg is retried 3× inside the job; a stalled row *reads* as failed after 10 min of silence and stops blocking new requests, nothing rewrites it (`CalendarExports.php:112-115`; `docs/architecture/calendars.md:847-864`).
- **HTTP** — `src/Services/Calendar/CalendarExportHttp.php:65-158` behind `src/Controller/Calendar/CalendarExportController.php:37`, `:57`, `:66`, `:76` (POST request, GET current, GET status, GET download) and the client-link twins. Download is buffered ≤ 32 MB, otherwise a 302 to storage (`CalendarExports.php:43`, `:140-148`) — never a `StreamedResponse`.
- **Browser** — `assets/calendar/ui/export.js:36` (`POLL_MS = 1500`), poll loop `:196-220` with a failure counter; on opening, the dialog GETs "what is there" and picks up a running job started anywhere (`calendars.md:1084-1090`).
- **CLI twin** for foreground runs: `bin/console app:calendar:export-pdf` (`calendars.md:865-868`).
- **Doc**: `docs/architecture/calendars.md:836-884`, numbers `:1093-1127`, limits `:1129-1160`.

### Mirror for scans [S]

`ai_scan_job` row: id, project, requester user (+ denormalised e-mail), `kind`, source upload path(s), `status` (queued/running/done/failed/cancelled), `stage` (text shown to the user: "Čtu obrázek…", "Skládám rozvržení…") instead of page counts, `updated_at` heartbeat, `finished_at`, `error` (Czech sentence) + `error_code`, result reference (created calendar / template id, or a result JSON path), token/cost totals, `seen_at` / `notified_at` (§8).

- `RequestAiScan` on the command bus: authorise, lock the project row, check concurrency per project/user and the credit balance, reserve credits, create the row; then `RunAiScan` on `job_bus`.
- **Own transport, own consumer** (§5): `scan` = `doctrine://default?queue_name=scan`, routed only `RunAiScan`. Today e-mails and domain events queue behind a running export on the single consumer (`calendars.md:1160`); a 1–5 minute scan on `async` would hold every e-mail for that long.
- Never throw; provider 429 / overloaded → bounded in-job backoff, then `failed` + refund. Do **not** rely on the `async` retry strategy (a retried handler would call the provider again and double-charge).
- Heartbeat: a single LLM call can be silent for minutes. Either stream the response and touch the row every N seconds, or give scans a longer stale window than 600 s. The stale rule must also *refund* (the export never needed that).
- Test routing `sync` + a fake provider client; dev has no consumer.

---

## 5. The production box

### Measured [M] (ssh root@lily.srv.thedevs.cz, 2026-11-12 11:12–11:20 CET)

- **CPU**: Intel i9-9900K, 1 socket, 8 cores / 16 threads (`nproc` = 16).
- **RAM**: 125 GiB total, 26 GiB used, 105 GiB buff/cache, **98 GiB available**. **Swap: 0**.
- **Disk**: `/` = `/dev/md1` (RAID1) 937 G, 263 G used, **627 G free (30 %)**. Docker: 340 images 89 GB (41 GB reclaimable), volumes 117.6 GB.
- **Load**: uptime 11 days, load average 2.42 / 3.16 / 2.92 (later 2.06 / 2.95 / 2.86); `vmstat 1 3` → 87–93 % idle, no swap activity.
- **Containers**: 136 running. Other tenants: myspeedpuzzling (2× web, 2× api, 2 consumers, db with a 24 GiB limit using 8.5 GiB, imgproxy, listmonk, mercure), fotbal-fm (several), fajnesklady (+ staging), sendvery, wtips, boliga, terlicko / masfb / sagena / msceladna / zs-celadna / pribor-pruvodce (Strapi + frontends + dbs), scubaswtrinec, plus infra (traefik, alloy, loki, tempo, prometheus, grafana, alertmanager, crowdsec, infisical, umami, webhook).
- **Sum of all containers' memory limits: 119.8 GiB** of 125 GiB — on paper the box is almost fully committed, in practice 26 GiB is used.
- **CPU limits**: none on any wboost container (`NanoCpus=0`, `CpuQuota=0`, no cpuset). Exactly one container on the whole box has one (`myspeedpuzzling-online-cv-1`, 2.0 CPUs).
- **wboost containers** (`docker stats --no-stream`, `docker inspect`):

| Container | CPU % | Mem used / limit | Reservation | pids limit | Notes |
|---|---|---|---|---|---|
| `wboost-web-379` | 0.00 | 376.6 MiB / 1 GiB | 256 MiB | 512 | FrankenPHP worker mode, blue-green, `REPLICAS=1` |
| `wboost-messenger-consumer-1` | 0.00 | 39.1 MiB / 768 MiB | 192 MiB | 512 | RestartCount 9, up 12 min at capture |
| `wboost-gotenberg-1` | 0.14 | 678.8 MiB / 5 GiB | 1 GiB | 512 | + 1 GiB `/tmp` tmpfs counted in the limit |
| `wboost-minio-1` | 0.05 | 455.5 MiB / 1 GiB | 256 MiB | 256 | |
| `wboost-db-1` | 6.42 | 84.4 MiB / 512 MiB | 128 MiB | 256 | pg 16.14, `shared_buffers=128MB`, `max_connections=100`, `work_mem=8MB` |
| `wboost-redis-1` | 0.16 | 3.4 MiB / 768 MiB | 128 MiB | 128 | cache only, `maxmemory 512mb allkeys-lru` |
| `wboost-adminer-1` / `-db-exporter-1` / `-landing-web-11` | ~0 | 8 / 128, 10 / 64, 14 / 48 MiB | | | |

  No wboost container has `OOMKilled=true`.
- **Compose** (`/srv/wboost/compose.yaml`, environment values not printed): header "HARD RULES … `mem_limit` on EVERY service (no swap → unbounded == host OOM)" `:11-16`; `web` `mem_limit: 1g`, `pids_limit: 512` `:59-61`; **consumer command** `:128`: `bin/console messenger:consume async -vv --time-limit 3600 --memory-limit 256M`, `mem_limit: 768m` `:149-151`, `stop_grace_period: 30s` `:154`, `SKIP_DATABASE_MIGRATIONS` `:144`, healthcheck disabled `:147-148`; db `:167-209`; redis `:224-245`; gotenberg `:305-354` (`--chromium-auto-start`, 5 g, the 2026-08-05 OOM story `:316-325`). No `deploy.resources`, no `cpus:` anywhere in the file.
- **Deploy** (`/srv/wboost/deploy.sh`): `dump_secrets wboost` renders `.env` from Infisical `:32`; `pull_image web` `:36`; `rollout web` (blue-green) `:51`; **`restart_consumers messenger-consumer` on every deploy** `:54`; `install_cron` `:58`. Triggered by `.github/workflows/release.yml:75-86` (reusable `_ship.yml`, HMAC webhook) after green tests on `main`.
- **Cron** (`/srv/wboost/cron.d/wboost`): 02:00 `app:gallery:purge-trash`, 02:30 `app:storage:scan`, each as `docker compose run --rm --no-deps messenger-consumer … bin/console …` wrapped in `lily-cron-run` + `sentry-cli monitors run`.
- **Secrets**: `/srv/wboost/.env` (0600, rendered 2026-11-12 01:58). Key names only: `ANTHROPIC_API_KEY APP_SECRET DATABASE_URL MAILER_DSN OAUTH2_ENCRYPTION_KEY OAUTH2_PRIVATE_KEY OAUTH2_PUBLIC_KEY POSTGRES_PASSWORD S3_ACCESS_KEY S3_SECRET_KEY SENTRY_DSN`. **`ANTHROPIC_API_KEY` is already present in production's rendered `.env`**; nothing in the repo reads it (grep over `src config resources .env* docs` finds no reference).

### Inferred [I]

- The consumer's RestartCount 9 / short uptime is `--time-limit 3600` exiting and `restart: always` bringing it back, plus deploy restarts — not crashes.
- `docker inspect` shows `MemorySwap` = 2 × memory, but the host has no swap, so the memory limit is the hard ceiling.
- Peak consumer numbers for the heaviest existing job are in `docs/architecture/calendars.md:1093-1127` (dev stack): PHP peak 89 MB, consumer RSS ≤ 128 MB, Gotenberg ≤ 835 MiB.
- A scan worker is I/O-bound: it waits on one HTTPS call for most of its 1–5 minutes. Its local CPU/memory cost is image preparation (decode + downscale of an upload of up to 10 MB / tens of megapixels through Imagick: a 60 MP RGBA bitmap is ~240 MB at 8 bit, ~480 MB at Q16) and, if the pipeline verifies its result, a Gotenberg render (which is charged to the gotenberg container, not the worker).

### Proposal for a scan worker [S]

A **separate compose service** `scan-consumer`, same image as `messenger-consumer`:

```yaml
scan-consumer:
  image: ghcr.io/thedevs-cz/wboost:${IMAGE_TAG:-main}
  command: "bash -c 'wait-for-it db:5432 -- sleep 5 && bin/console messenger:consume scan -vv --time-limit 3600 --memory-limit 384M'"
  cpus: 1.5              # hard ceiling: 1.5 of 16 threads (~9 %)
  mem_limit: 1g          # house rule: every service has one
  mem_reservation: 192m
  pids_limit: 256
  stop_grace_period: 330s   # ≥ the job's own hard timeout, see traps
  environment:
    SKIP_DATABASE_MIGRATIONS: "true"
    MAGICK_THREAD_LIMIT: "2"
    MAGICK_MEMORY_LIMIT: "256MiB"
    MAGICK_MAP_LIMIT: "512MiB"
  healthcheck: { disable: true }
```

Reasoning:

- **Concurrency 1** to start (one process = one job at a time); the queue absorbs bursts. Raise to 2 by `--scale scan-consumer=2` (the file forbids `container_name`, so scaling works) once provider rate limits and cost per scan are known. Concurrency is a *spend* throttle as much as a CPU one.
- **`cpus: 1.5`**: the box idles at ~90 % with load ~2–3 on 16 threads, so 1.5 threads cannot starve anything; it bounds the only CPU-heavy phase (Imagick). The file has no CPU limits today, so this is a new convention — `cpus` is the compose-v2 key that sets `NanoCpus`.
- **`mem_limit: 1g`**: above the 768 m of the general consumer because of full-size image decoding; `--memory-limit 384M` restarts the worker between jobs if PHP grows; Imagick is capped separately because its pixel cache is outside PHP's `memory_limit`. Adding 1 GiB takes the sum of limits to ~120.8 GiB of 125 GiB — fine against 98 GiB actually available, but the "limits sum" headroom is nearly used up for future services.
- **Disk**: negligible (uploads ≤ 10 MB, results are JSON/rows).
- **DB**: one more connection of 100.
- The **web** container must not do any of this work in-request: it has 1 GiB and serves everyone.
- Add a per-project and a global "scans in flight" cap in `RequestAiScan` and a daily spend ceiling (§2) — CPU limits do not stop a queue of 200 paid calls.
- `deploy.sh` needs `restart_consumers messenger-consumer scan-consumer`; the infra repo change lives in `~/www/lily.srv/apps/wboost/`.

---

## 6. Tooling in the image

### Measured in `wboost-web-379` (image `ghcr.io/thedevs-cz/wboost:main`) [M]

| Tool | Result |
|---|---|
| OS | Debian 13 (trixie) |
| PHP | 8.5.12 CLI, ZTS; `curl`, `gd`, `sodium`, `pcntl` loaded; CLI `memory_limit=512M`, `upload_max_filesize=50M` |
| Imagick ext | 3.8.1; formats PDF, HEIC, WEBP, AVIF, SVG, TIFF, PSD, AI, EPS all available |
| Ghostscript | `gs` 10.05.1 |
| Node | v26.13.0, npm 11.20.0 |
| Python | 3.13.5 |
| sentry-cli | 3.8.2 |
| `magick` / `convert` CLI | **not found** (extension only) |
| `qpdf`, `pdftoppm` (poppler) | **not found** |
| Chromium / Chrome | **not found** — rendering is the separate `gotenberg/gotenberg:8` container |

- Image build: `Dockerfile:1-24` on the base `ghcr.io/thedevs-cz/php:8.5-wboost` (not defined in this repo); composer install, `importmap:install`, `asset-map:compile`. Startup `.docker/on-startup.sh` (migrations on boot unless `SKIP_DATABASE_MIGRATIONS=true`), `.docker/php/wboost.ini` (`max_input_vars`).
- Node is already used server-side through `symfony/process`: `src/Services/Calendar/CalendarModelBridge.php:26-27`, `:108` (`resources/calendar/model.mjs`).
- Existing helpers a scan pipeline can reuse: `src/Services/Calendar/RasterizePdfPage.php` (PDF first page → PNG, bounded resolution), `ExtractDocumentText.php`, `ImportCalendarLayout.php`, `CalendarPageRenderer.php` (headless page render), `NormalizeImageFormat` (HEIC, see `docs/architecture/gallery-and-uploads.md`).
- Dependencies (`composer.json`): `symfony/http-client` `:64`, `symfony/process` `:73`, `symfony/rate-limiter` `:76`, `symfony/ux-turbo` `:86`, `mcp/sdk` `:39`, `symfony/mcp-bundle` `:69`, `sentry/sentry-symfony` `:52`. **No Anthropic or OpenAI SDK, no `symfony/lock`, no `symfony/notifier`, no Mercure.** There is no `package.json`; `importmap.php` has no LLM client.

### Would have to be added [S]

- An LLM client: a scoped `symfony/http-client` client is enough (long `timeout` / `max_duration`, unlike the Gotenberg scoped client's 20 / 25 s — `calendars.md:1138-1142`); whether to take a vendor SDK instead is for the provider-side digest to decide against current documentation.
- `ANTHROPIC_API_KEY=` (blank) in `.env` with a comment, a `%env()%` binding with a default as in `config/services.php:24-40` (the "deploy before the Infisical value exists must not 500" pattern), `.env.test` value for the fake client. Production already has the key name.
- `scan` transport + routing in `config/packages/messenger.php`, `sync` routing in `config/packages/test/messenger.php`; the queue table is shared with `async` (Doctrine transport, `queue_name` column), so no table migration is needed for the transport itself **[I]**.
- No new binary is required for image → model input (Imagick covers decode / downscale / PDF first page).
- `symfony/lock` only if concurrency is enforced with locks instead of row locks (the codebase uses row locks).

---

## 7. Notification when a scan finishes

Requirement (owner): scans run in the background; the user must be told when one finishes whether they stayed on the page, moved elsewhere in the app, or closed the tab.

### What exists [M]

| Mechanism | State | Where |
|---|---|---|
| Notification centre / bell / inbox | **does not exist** | no entity, no route; the shell has no desktop top bar — only the phone bar `.mbar` (`templates/base.html.twig:95-99`) |
| Flash messages (next page render, session) | exists | `templates/_flashes.html.twig:1-16`, included at `templates/base.html.twig:113`. Written with `addFlash()` in a request — **a consumer process has no session, it cannot flash** |
| Toasts (client-side, current page only) | exist as CSS kit + two private JS copies, **no shared helper** | CSS `assets/styles/nocturne/feedback.css:61-65`; `assets/calendar/ui/dom.js:107-135` (with an action button: "Stáhnout PDF", "Zkusit znovu"); `assets/controllers/project_palette_controller.js:335-346` |
| Polling a job row | exists (calendar export) | `assets/calendar/ui/export.js:36`, `:196-220`; endpoints §4; the dialog re-discovers a running job on open |
| Server-rendered count badge on a nav item | exists | `templates/_shell/_sidebar.html.twig:144-147` (`.count`, Twig function `pending_registration_requests_count`, `src/Services/Twig/TwigExtension.php:44`) — refreshed on every full page load |
| Turbo Streams | exist only as **responses to form posts**; `<html data-turbo="false">` (`templates/base.html.twig:14`), forms opt in | `templates/_export_version_curation.stream.html.twig`, `_fill_draft_saved.stream.html.twig`, `_manual_preview_regions.stream.html.twig` |
| Mercure / SSE / WebSocket | **does not exist** (no package, no `EventSource` in app code) | `composer.json` |
| Service worker / web push | **does not exist**: only `public/manifest.json` (icons, name "App") linked at `templates/base.html.twig:51`; no `serviceWorker.register` anywhere in `assets`, `templates`, `public`, `landing` | the "stale SW on :8080" note in memory refers to a foreign worker cached on the dev origin, not to app code **[I]** |
| Transactional e-mail | exists | `config/packages/mailer.php:9-18` (From / envelope `robot@wboost.cz`); `SendEmailMessage` routed `async` (`config/packages/messenger.php:57`); nine standalone inline-styled table templates in `templates/emails/` (no shared layout, e.g. `project_shared.html.twig:1-25`); senders: `EnableProjectModuleHandler.php:70-87` (to admins), `src/Services/ProjectSharedMailer.php:30` (to a user), `ApproveWeeklyMenuHandler.php:64` / `DenyWeeklyMenuHandler.php:64` (result of a pending action → the closest precedent) |
| Nocturne `email.css` | **not** transactional mail — it styles the E-maily (signature) module | `assets/styles/nocturne/email.css:1-6` |
| `symfony/notifier` | not installed | |

### What would have to be built, and the cheapest reliable option [S]

The job row is the single source of truth; three thin readers on top of it, no new infrastructure:

1. **Stayed on the page** — poll the status endpoint like the export dialog (1.5–3 s), show stage text, then open the result. Already a proven pattern.
2. **Elsewhere in the app** —
   - server-rendered indicator on every page: a Twig function `ai_scans_indicator()` (the user's pending + finished-unseen scans) drawn in the sidebar. Natural places: a `.count`-style badge / spinner dot on the module item the scan belongs to (Kalendáře `_sidebar.html.twig:107-111`, Šablony `:65`), and for phones the `.mbar-side` slot (`base.html.twig:98`). In rail mode `.hide-c` hides counts, so the collapsed state needs a dot on the icon.
   - a small poll in the `shell` Stimulus controller (`base.html.twig:86-90`) that runs **only while the page was rendered with a pending scan** (data attribute), every ~10 s, and on completion shows a toast with an "Otevřít" action and updates the badge. This needs the toast helper extracted into one shared module (today two copies).
3. **Closed the tab** — e-mail. When the job ends, dispatch a delayed message (`DelayStamp` ~60–90 s on the Doctrine transport) `NotifyScanFinished`; its handler sends "Váš kalendář z obrázku je připraven" / "Sken se nepodařil" with a deep link **only if `seen_at` is still null** (the status poll sets `seen_at` when it delivers a final state to the requester). So a user who watched it finish gets no mail, everyone else gets exactly one. Template: copy the `weekly_menu_approval_result` shape.

Why not the others: Mercure/SSE = a new service on the box plus Traefik routing for a feature used a few times a day; web push = service worker + VAPID keys + a permission prompt, unreliable on iOS, and still needs the e-mail fallback; a full notification centre is a product of its own (worth it only once there are more event types — the `seen_at` column on the job row is a forward-compatible start).

Cost of the recommended option: one Twig function + one JSON endpoint ("my scans"), ~40 lines in the shell controller, a shared toast module, one e-mail template, two columns on the job row.

---

## 8. Traps

1. **`isInPreview()` is gone** — re-create it from commit `4f55eab9`; do not look for it. And module enable is open to every project editor (`EnableProjectModuleController.php:43`): a paid capability must not ride on that unchanged.
2. **The module guard is not access control** (`app-shell.md:203-209`). The scan request endpoint, the MCP surface and any API need their own voter. Memory says everything must be MCP-controllable — decide explicitly whether scans are exposed there (spend through an AI client).
3. **`command_bus` wraps handlers in a transaction** (`config/packages/messenger.php:16-20`): progress flushed there is invisible. Jobs go on `job_bus` with `#[Autowire(service: 'job_bus')]` / `#[AsMessageHandler(bus: 'job_bus')]`.
4. **One queue, one consumer today** (`compose.yaml:128`): a scan on `async` delays every e-mail and domain event by minutes, and a PDF export delays scans. Separate transport + service.
5. **Every deploy restarts consumers** (`deploy.sh:54`) and `main` deploys on every push; `stop_grace_period` is 30 s (`compose.yaml:154`). Symfony's worker finishes the current message on SIGTERM, but a 1–5 min job outlives 30 s and gets SIGKILLed mid-call → the provider call is paid, the row goes stale. Needs a longer grace period on the scan service, a refund-on-stale rule and idempotent result writing.
6. **Doctrine transport redelivery**: a message not acked within the transport's `redeliver_timeout` (default 1 h) is handed out again; with the "handler returns when the row is final" guard (`CalendarPdfExporter.php:59-61`) that is harmless — keep that guard, and add "row is `running` and not stale → skip" so a redelivery cannot start a second paid call.
7. **Messenger retry = double charge**: keep the never-throw rule; retry inside the job with a budget.
8. **Stale rule vs. a silent LLM call**: 600 s of silence = failed for exports (`CalendarExport.php:49`); an LLM request without streaming gives no heartbeat.
9. **Usage is recorded in the consumer**: no `Security` user there (`RecordExportUsage.php:51` would record null). Carry the requester on the job row. Analytics tables are FK-less and denormalised by convention (`ExportEvent.php:27-31`, `UserActivityDay.php:24-29`).
10. **No swap on the box and `mem_limit` is mandatory** (`compose.yaml:14`); Imagick's pixel cache is outside PHP's `memory_limit` (the CMYK pass had to pin a 64 MB cache — commits `9d511fee`, `371410e6`). Cap Imagick by environment/policy, and downscale before anything else.
11. **Cron jobs reuse the `messenger-consumer` service definition** (`cron.d/wboost`): do not put scan-specific limits or commands on that service; add a new one.
12. **Sum of memory limits is already 119.8 / 125 GiB** — each new service eats the paper headroom.
13. **Uploads**: 10 MB decimal everywhere (CLAUDE.md), HEIC is normalised on upload; a new path-storing column must be added to `BuildStorageReferenceIndex` or the nightly storage scan reports the scan sources as orphans (`docs/architecture/storage-inventory.md`). Decide retention of uploaded scan sources (they may be third-party designs).
14. **Sentry books every 4xx** (memory note): "not enabled" / "no credits" answers should not be `HttpException`s logged at error, or each CTA click becomes an issue.
15. **Never nest forms** — the edit-user page is one form (`edit_user.html.twig:34-130`); per-project switches there need `form=` anchors. Twig empty hash → `[]` breaks Stimulus Object values.
16. **Flashes cannot be written by a worker**; `data-turbo="false"` site-wide; scripts inserted by Live Component morphs never run — the shell poll must load from `base.html.twig`, not from a component.
17. **`.env` is rendered from Infisical at deploy**: a variable read without a default 500s every request of a deploy made before the value exists (`config/services.php:24-40` shows the guard pattern). `ANTHROPIC_API_KEY` already exists in production's `.env`, unused — confirm which Anthropic workspace / spend limit it belongs to before the first call.
18. **Dev and CI have no consumer**: route `RunAiScan` `sync` in tests with a fake provider; never call the real API from the suite.
19. **Side finding, unrelated to the feature**: the untracked local `compose.override.yaml` contains a GitHub token in plain text (`COMPOSER_AUTH`). Not committed (`git ls-files` does not list it), value not reproduced here.

---

## Method note

Box commands were inspection only (`nproc`, `lscpu`, `free`, `df`, `uptime`, `docker ps/stats/inspect/system df`, `vmstat`, `cat`/`sed` of compose, deploy and cron files with environment values masked, `docker exec` of version commands in the web container). One deviation from strictly read-only: a `docker stats` snapshot was redirected to a temp file in `/dev/shm` (tmpfs) and removed in the same command; nothing else was written, restarted or pulled. `.env` values were never printed — key names only.
