# AI sken — runbook

What to do when AI scan misbehaves in production. Design: `architecture.md` (§5.2 states, §5.4 credits
and spend, §7 failures); the app as built: `docs/architecture/ai-scan.md`; the scanner: `scanner/README.md`;
what was tried before this was written: `reliability-report.md`.

Every command below was run against the local stack while this was written (G2a). What is **not yet
there** is said where it matters: the scanner runs on the box since 2026-11-13 (P4 — "Production" below is what was done and measured there), the admin tab
("AI skeny") comes with P4, and the app sends **no operating e-mail to admins** yet — until then the
console command is the only view (see "What is not built").

## Where things are

| | |
|---|---|
| The box | `ssh root@lily.srv.thedevs.cz`, then `cd /srv/wboost` |
| App | compose service `web` (blue-green deploys on every push to `main`); long jobs: `messenger-consumer` |
| Scanner | compose service `scanner` (container `wboost-scanner-1`), image `ghcr.io/thedevs-cz/wboost-scanner:sha-<commit>` — pinned in `~/www/lily.srv/apps/wboost/compose.yaml`, never moved by an app push; network `wboost_scan` only |
| Infra definition | `~/www/lily.srv/apps/wboost/` (`compose.yaml`, `deploy.sh`, `cron.d/wboost`), decision **D87** in its `docs/decisions.md`, what was done in its `docs/journal.md` |
| Console | `docker compose exec web bin/console app:ai-scan:status …` — written `status …` below |
| Scan files | private bucket `wboost-private` (the stack's MinIO, no anonymous policy — `deploy.sh` asserts it on every deploy), `ai-scan/{project}/{scan}/{secret}/…` |
| Logs | scanner: `docker compose logs scanner` (JSON lines; also in Loki, label `app="wboost-scanner"`); app: `docker compose logs web`, Sentry; cron (`ai-scan-purge`, `ai-scan-watch`): `/var/log/lily/wboost-cron.log` (Loki `app="cron"`) |
| Secrets | Infisical project `wboost`, env `prod`: `SCANNER_TOKEN` (web + scanner), `ANTHROPIC_API_KEY` (the scanner alone — `web` and the worker get it blanked), `SCANNER_TOKEN_PREVIOUS` (only during a rotation). Rendered to `/srv/wboost/.env` by every deploy |
| Rows | `ai_scan` (the scans), `ai_usage_event` (what each run spent), `ai_credit_ledger` (credits), `ai_scan_runtime` (the one row of switches) |

Locally the same commands are `docker compose exec web bin/console app:ai-scan:status …` in the
repository; a scanner is started by hand (`scanner/README.md` "Run it").

## Production (the box) — what runs, and the five things you do there

The scanner is one container of the `wboost` stack: 4 GiB memory limit, 3 CPUs (the box's exception to
its no-CPU-limit rule, infra D87), read-only root, a 768 MB `/tmp`, no capabilities, concurrency 1. It
sits on the network `wboost_scan` (`10.200.107.0/24`) with `web` and nothing else: it cannot resolve or
reach the database, Redis, MinIO or Gotenberg. `web` answers `/internal/scanner/*` only to a peer
address inside that subnet (`SCAN_NETWORK_CIDR`) that sends `SCANNER_TOKEN`; the public router refuses
`/internal/` itself (403) before the app is asked.

All commands: on the box (`root@lily.srv.thedevs.cz`), in `/srv/wboost`.

**1. Look.**

```bash
docker compose ps scanner                               # Up … (healthy)
docker compose exec web bin/console app:ai-scan:status  # mode, scanner seen N s ago, provider, spend
docker compose logs --tail 100 scanner                  # ids, codes, durations — never a design's text
docker stats --no-stream wboost-scanner-1               # ≈ 85 MiB idle; 0.5–1.8 GiB during a scan
```

The admin tab `/admin/usage` → "AI skeny" shows the same and changes the mode and the limits.

**2. Stop and start the scanner.**

```bash
docker compose stop scanner     # SIGTERM: the running scan is released back to the queue, exit in ≈ 3 s (limit 30 s)
docker compose up -d scanner    # start again; `restart scanner` = both
```

A stopped scanner makes the app say `scanner_absent` after 60 s: uploads are refused, waiting scans
wait (and fail refunded after 60 minutes). **Never `docker kill` it**: Docker treats a kill as a manual
stop and does not start the container again — it stays down until `docker compose up -d scanner`
(found on 2026-11-13). A real crash, an OOM kill or a reboot does restart it (`restart: always`).

**3. Switch the mode** — no deploy, effective on the next request (also in the admin tab):

```bash
docker compose exec web bin/console app:ai-scan:status --mode=off      # everything stops, scans in progress fail refunded
docker compose exec web bin/console app:ai-scan:status --mode=paused   # nothing new starts, a running scan finishes
docker compose exec web bin/console app:ai-scan:status --mode=on
docker compose exec web bin/console app:ai-scan:status --admin-only=1  # 1 = only admins may start scans (the state until the customer launch)
```

**4. Ship a scanner** (a new image of `scanner/`). The Scanner workflow pushes
`ghcr.io/thedevs-cz/wboost-scanner:sha-<full commit>`; nothing deploys it. In `~/www/lily.srv`:
change the sha in `apps/wboost/compose.yaml` (`scanner.image`), add a journal line, push (the box
receives the file within a minute, nothing restarts), then either wait for the next app deploy or

```bash
cd /srv/wboost && flock /run/lily-deploy-runner.lock ./deploy.sh main
```

`deploy.sh` brings the scanner up **last**, after the new `web` is live, and touches it only when its
definition changed — an ordinary app deploy leaves a running scan alone. A re-create mid-scan is
clean (the scan is released and claimed again by the new container, its one restart is not used).
The app accepts the scanner's protocol N and N − 1, so "app first, scanner second" is always safe;
when the app needs a newer scanner it says so (`Scanner … · TOO OLD`, the admin tab, an e-mail).
Roll back a scanner the same way with the previous sha.

**5. Rotate the scanner's token / replace the provider key.** Values live in Infisical only
(project `wboost`, `prod`); never print them (`infisical secrets set … >/dev/null`, pattern in
`~/www/lily.srv/docs/secrets.md`).

- Token: copy the current value to `SCANNER_TOKEN_PREVIOUS`, write a new `SCANNER_TOKEN`
  (≥ 24 characters), `./deploy.sh main` (web accepts both, the scanner is re-created with the new
  one), check `status`, delete `SCANNER_TOKEN_PREVIOUS`, deploy again at leisure.
- Provider key: replace `ANTHROPIC_API_KEY`, `./deploy.sh main` (only the scanner's definition
  changes, so only it is re-created). Without a key the scanner stays up and healthy and reports the
  provider as blocked — nothing crash-loops. Production uses an API key only, never a subscription
  credential, and no login state is mounted into the container.

### Roll back P4

1. `status --mode=off` — instant; the feature is inert, scans in progress fail refunded, `ready`
   proposals stay usable.
2. `docker compose stop scanner` — the spending process is gone.
3. For good: in `~/www/lily.srv` take out the `scanner` service, the `scan` network and web's AI-scan
   variables (D87 "Rollback"), push, `./deploy.sh main`, then delete the stopped scanner container
   with compose — a reverted compose file alone leaves a running container running. The bucket
   `wboost-private` stays until somebody deletes it by hand.

### Measured on the box (G2b, 2026-11-13)

Five scans of a synthetic test picture in an internal project, by a temporary admin, all discarded.
Figures and the checks that are still open: `reliability-report.md` § "G2b".

| | |
|---|---|
| One scan, undisturbed | `ready` 36 s after upload; $0.17 (Haiku pre-check + Opus build, 6 turns); scanner peak 561 MiB; 14 s CPU, 5 throttled periods under 3 CPUs |
| Scanner process killed while idle | container back and seen by the app in 7 s |
| Scanner process killed mid-build | container back in 1 s; the scan stays `running` until its lease ends (120 s), is put back (`restarted`), claimed and `ready` 34 s later; the killed run's usage row is closed `lease_lost` with its last figures |
| `docker compose restart scanner` mid-build | shutdown 2.6 s; released, claimed again 3 s later, restart not used; `ready` |
| App deploy (blue-green `web`) with a scan running | the scanner container is not touched; the scan finishes on its first try |
| `/internal/scanner/*` | internet: 403; another stack's container: 404; `web`'s other networks, right token: 404; scan network, wrong token: 404 |

## The one command

```bash
status                       # mode, waiting reason, scanner, provider, breaker, counts, oldest waiting, spend, limits, bucket
status --scans               # every scan in progress (oldest first) + the last 15 ended: id, project, who, state, stage, try / run, age, lease, spend
status --scan=<id>           # one scan: its columns, its history, its usage rows (spend per run and step), its ledger lines
status --cancel=<id>         # cancel a scan in progress, exactly as its user's "Zrušit sken" (refund while waiting, counted while running)
status --reap                # run the reaper now (lost leases, queue timeouts)
status --mode=on|paused|off  # the operating mode — effective on the NEXT request, no deploy
status --admin-only=1|0  --max-running=N  --daily-ceiling-usd=20  --project-ceiling-usd=5  --daily-starts-user=10  --daily-starts-project=20
```

How to read `status`:

| Line | Normal | Means trouble when |
|---|---|---|
| Mode | `on · admins only` (before P6) | `paused` — set by hand or by the circuit breaker; `off` — set by hand |
| Waiting reason | `—` | `paused`, `scanner_absent` (no scanner call for 60 s), `scanner_too_old`, `provider_blocked` — this is what a waiting user is shown ("Sken čeká") and why uploads are refused (`temporarily_unavailable`, HTTP 503) |
| Scanner | `seen N s ago`, N < 30 (an idle scanner polls every 5 s) | N ≥ 60, `never seen`, `· TOO OLD` |
| Provider | `ok` | `blocked · <code>` |
| Circuit breaker | `0 consecutive failures` | `3 consecutive <class>` — the mode is then `paused` |
| Scans | counts by state | `checking` / `queued` / `running` that do not move |
| Oldest waiting since | `—` or a time minutes ago | a time more than 15 minutes ago |
| Open usage rows | 0, or 1–2 while a scan runs | more than scans in progress (the nightly purge closes leftovers) |
| Spend today | far below the ceiling | `recorded + $1.50 × running` at the ceiling: uploads refused, queued scans wait |
| Private bucket | `reachable` | `NOT reachable` — uploads and renders fail |

## Kill switch

| Want | Do | Effect, measured locally |
|---|---|---|
| No new scans, let running ones finish | `status --mode=paused` | uploads refused (503 `temporarily_unavailable`), nothing claimed; a `running` scan finishes and is delivered; `checking` / `queued` scans wait and fail after 60 min as `queue_timeout`, refunded |
| Stop everything now | `status --mode=off` | at the switch every `checking` / `queued` / `running` scan → `failed interrupted`, **refunded**; the scanner learns it from its next heartbeat and kills the engine (7 s measured, ≤ 15 s); `ready` proposals stay usable (review, accept, discard do not depend on the mode) |
| Stop the spending process itself | `docker compose stop scanner` | SIGTERM: runs aborted, one `release` each with the usage, exit ≤ 30 s (grace 45 s); released scans go back to `queued` without using their restart. After 60 s the app says `scanner_absent`: uploads refused, waiting scans fail after 60 min (`queue_timeout`, refunded) |
| Back | `status --mode=on` (and `docker compose up -d scanner`) | the next request starts scans again; failed scans are retried by their users ("Zkusit znovu", 2 per upload) |

`paused` is also what the **circuit breaker** sets: three scans in a row ending in the same failure
class. Look at `status --scans` for the common cause before switching back to `on`.

## Stuck queue — scans wait and nothing starts

1. `status`. The **Waiting reason** names four of the causes; act by the matching section below
   (`paused` → why was it paused; `scanner_absent` → "Scanner down"; `scanner_too_old`;
   `provider_blocked`).
2. Waiting reason `—` but nothing is claimed:
   - **Spend today** at a ceiling → the build lane is told `spend_ceiling`. Raise the ceiling for the day
     (`status --daily-ceiling-usd=40`) or wait for midnight Europe/Prague. A project at its own ceiling
     holds only that project's scans.
   - **Limits** `max running 1` and a scan `running` → the queue is simply busy. `status --scans` shows
     how long the running one has been at it; see "Stuck scan".
   - The scanner is seen but unhealthy: `docker compose ps scanner`,
     `docker compose exec scanner node bin/scanner.mjs healthcheck` (prints the health document:
     `reasons`, `app.lastError` — `not_scanner` = the app refuses its network or token).
3. Nothing lasts for ever: a scan that waits 60 minutes fails as `queue_timeout`, refunded, and its user
   may retry. The estimate users see assumes a moving queue; it is `null` while a waiting reason is set.

## Stuck scan — one scan does not move

```bash
status --scan=<id>
```

| It shows | Means | Do |
|---|---|---|
| `running`, **lease ends in** > 0 and the usage row's figures still grow between two looks | it is working (a hard design takes up to 6 min, a restarted one 12) | nothing |
| `running`, lease in the past | the run died and nobody has read the row since | `status --reap` (any status read by its user does the same): first loss → `queued` (`restarted`), second → `failed interrupted`, refunded |
| `running` for more than 7 minutes with a live lease | a scanner whose run hangs while its heartbeat lives — its own guard kills a run at 465 s | `status --cancel=<id>` (counted) or wait; if it repeats, `docker compose restart scanner` and keep the scan id for the scanner log |
| `checking` for minutes, lease set | the intake hangs — bounded at 150 s, then checked once more, then `stopped unusable_input`, refunded | nothing; `status --reap` if the scanner is gone |
| `queued` / `checking`, no lease | waiting — see "Stuck queue" | |

`status --cancel=<id>` is the only way to end one scan by hand; it is the user's own cancel (the row
changes by the same conditional update), so it cannot race a finish. Never edit `ai_scan` rows by SQL:
credits and usage are written in the same transaction as the state.

**A killed scan shows its spend**: `status --scan=<id>` lists one usage row per run and step; a run
that died has its row closed as `lease_lost` with the figures of its last heartbeat (and anything the
run reported later is added to that row, never dropped).

## Spend spike

1. `status` → **Spend today** against the ceiling; `status --scans` → the **Spend** column and who.
2. One project: lower its ceiling for everybody (`status --project-ceiling-usd=2`) or switch that
   project off (edit-project page → "AI sken", an admin; its waiting scans are cancelled, refunded).
3. One user or an agent in a loop: `status --daily-starts-user=3`. An MCP client can be cut off at
   `/user-profile/connected-apps` (the user) or by removing `ai:scan` from the user's MCP scopes
   (admin's user page).
4. Everything: `status --mode=paused`, or the hard stop above.

What already bounds it without anybody acting: a run may start only while recorded spend
+ $1.50 × (running runs + itself) fits the day's total ($20) and the project's ($5) ceiling; 10 starts
a day per user, 20 per project; 2 retries and 1 "Zkusit přesto" per upload; a refund gives no start
back; one run is cut at $1.50, 40 turns, 6 minutes. The provider workspace's own monthly limit is the
outer guard (G1).

## Provider blocked

`status` → `Provider blocked · <code>`, waiting reason `provider_blocked`. The scanner met a billing
or authentication answer (401, 403, 402, a credit-balance or spend-limit message) **and its 1-token
probe failed too**. The scan that met it went back to `queued` without using its restart; nothing is
claimed; uploads are refused.

1. Fix the cause with the provider: the key (Infisical → `ANTHROPIC_API_KEY` of the scanner; then
   `docker compose up -d scanner` to load it), the workspace's spend limit, the credit balance.
2. Do nothing else: the scanner probes every 5 minutes and reports `ok` with its next tick (30 s);
   the queue then moves by itself. To skip the wait: `docker compose restart scanner` (a starting
   scanner that hears `blocked` from the app probes at once).
3. Scans that waited longer than 60 minutes meanwhile have failed as `queue_timeout`, refunded.

A probe that **succeeds** means the answer was about that one request: the scan fails as `build`
(refunded) and the provider stays `ok`.

## Scanner too old

`status` → `Scanner … · TOO OLD`, waiting reason `scanner_too_old`; the scanner's log says
`protocol mismatch: this scanner does not claim` or `the app refuses this scanner as too old (an
instrument it requires is missing)`. The app accepts protocol N and N − 1 and requires the instruments
of `resources/ai-scan/definition/definition.json`.

- Pin the scanner tag that matches the app (`sha-<commit>` of the app's commit that changed the
  protocol or the instrument list) in `~/www/lily.srv/apps/wboost/compose.yaml`, deploy the box.
- Or roll the app back (below) if the app change was the mistake.
- The flag clears with the first accepted call of a good scanner. Meanwhile uploads are refused and
  waiting scans time out after 60 minutes, refunded.

## Scanner down

`status` → `Scanner seen N s ago` with N ≥ 60 (`scanner_absent`).

```bash
docker compose ps scanner                      # restarting? unhealthy? OOM-killed (exit 137)?
docker compose logs --tail 200 scanner         # JSON lines: ids, codes, durations — never a design's text
docker compose exec scanner node bin/scanner.mjs healthcheck
docker compose up -d scanner                   # start / recreate
```

- A scanner that died mid-scan left a lease: 120 s after its last heartbeat the reaper puts the scan
  back (`restarted`); a second death fails it `interrupted`, refunded — a poisonous picture cannot
  loop, and the same picture failing twice in a project is refused for 30 days (`source_unscannable`).
- The container is `Exited` and does not come back: somebody used `docker kill` (see "Production" 2) —
  `docker compose up -d scanner`.
- `Refused at start`: the log says why — a concurrency the memory limit cannot hold (3 GiB for 1),
  a missing `SCANNER_APP_URL` / `SCANNER_TOKEN`.
- `app.lastError: not_scanner` in the health document: `web` does not see the scanner's address in
  `SCAN_NETWORK_CIDR`, or the tokens differ (`SCANNER_TOKEN` of `web` may hold `current,previous`
  during a rotation).

## Rollback, per phase

Everything is additive (migrations add tables and columns only) and inert while the mode is `off`.

| Phase | Roll back by | `checking` | `queued` | `running` | `ready` |
|---|---|---|---|---|---|
| P1–P3 (the app: job core, wizard, MCP) | `status --mode=off` — instant, no deploy. Reverting the app image is not needed and not enough (rows stay) | `failed interrupted`, refunded | `failed interrupted`, refunded | `failed interrupted`, refunded; the scanner aborts within 15 s | untouched — review, accept and discard keep working; expires 30 days after the scan ended |
| P4 (the scanner on the box) | `status --mode=off` first (above), then `docker compose stop scanner`; to remove it for good the rollback commit of `lily.srv` must also delete the service — reverting the compose push does not stop a running container | as above with `off`; with only the scanner stopped: waits, then `failed queue_timeout` after 60 min, refunded | same | SIGTERM → released → `queued`, restart not used → then as `queued` | untouched |
| A scanner tag that misbehaves | pin the previous `sha-<commit>` and deploy the box (the app accepts protocol N − 1) | waits ≤ 1 min for the new container | same | released by the old one, claimed by the new one | untouched |
| An app deploy that breaks the scanner API | redeploy the previous app image (blue-green). Schema of this release is additive, so the previous code runs on it | wait (the scanner retries every call for 60 s; longer → `restarted`) | same | survives an outage ≤ 60 s; longer → self-abort at 105 s, `queued`, claimed again | untouched |
| P5 (calendars) | switch the action card off with the entitlement; previous scanner tag | — | — | — | applied layouts stay; "Vrátit původní" works until the scan's date |
| P6 (customers) | `status --admin-only=1` | finish normally | finish normally | finish normally | customers keep and can finish their proposals; they cannot start new scans (`not_enabled`) |

After any rollback: `status` must show no `checking` / `queued` / `running` scan and `Open usage rows 0`
(the nightly `app:ai-scan:purge` closes leftovers and drops orphan files; run it by hand if you do not
want to wait: `docker compose exec web bin/console app:ai-scan:purge`).

## Nightly

`app:ai-scan:purge` (host cron `ai-scan-purge`, 03:00 Europe/Prague, in `~/www/lily.srv/apps/wboost/cron.d/wboost`): reaper, expiry (30 days after a scan ended), files of expired /
missing rows older than 24 h, leftover candidate renders, open usage rows of scans that are no longer
in progress, due e-mails and the one reminder 3 days before a proposal expires. Safe to run at any time
and twice.

## What is not built (as of G2a)

- **Operating e-mails to admins** (provider blocked, circuit breaker, a ceiling at 80 % / 100 %, oldest
  waiting > 15 min, scanner too old, a project paused for refusals — architecture §7): none is sent.
  Until they exist somebody has to look at `status`; the scanner's `/health` feeds the box's
  unhealthy-container alert only once the service is deployed. This is on the list for G2b (item 7).
- **The admin tab** ("AI skeny", P4): `status --scans` / `--scan` stand in for it.
- **Reconciliation** of recorded spend against the provider's report: an operations task (weekly,
  ± 10 %), first done on the ten production scans of P4.
