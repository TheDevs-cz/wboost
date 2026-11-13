# AI sken — runbook

What to do when AI scan misbehaves in production. Design: `architecture.md` (§5.2 states, §5.4 credits
and spend, §7 failures); the app as built: `docs/architecture/ai-scan.md`; the scanner: `scanner/README.md`;
what was tried before this was written: `reliability-report.md`.

Every command below was run against the local stack while this was written (G2a). What is **not yet
there** is said where it matters: the scanner runs on the box since 2026-11-13 (P4 — "Production" below is what was done and measured there), the admin tab
("AI skeny" on `/admin/usage`) shows and changes the same things as the console command, and the app
e-mails the admins about operating conditions (`app:ai-scan:watch`, every 5 minutes from cron — see
`docs/architecture/ai-scan.md` § "Operating e-mails"). What is still missing: "What is not built".

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
| Secrets | Infisical project `wboost`, env `prod`: `SCANNER_TOKEN` (web + scanner), `ANTHROPIC_API_KEY` (the scanner alone), `SCANNER_TOKEN_PREVIOUS` (only during a rotation). Every deploy renders them to `/srv/wboost/.env` and then moves the provider key out of it into `/srv/wboost/.env.scanner` (root, 0600), the one file only the scanner reads — `.env`, which `web` and the worker load, does not hold it (since 2026-11-13) |
| Rows | `ai_scan` (the scans), `ai_usage_event` (what each run spent), `ai_credit_ledger` (credits), `ai_scan_runtime` (the one row of switches) |

Locally the same commands are `docker compose exec web bin/console app:ai-scan:status …` in the
repository; a scanner is started by hand (`scanner/README.md` "Run it").

## Production (the box) — what runs, and the five things you do there

The scanner is one container of the `wboost` stack: 4 GiB memory limit, 3 CPUs (the box's exception to
its no-CPU-limit rule, infra D87), read-only root, a 768 MB `/tmp`, no capabilities, concurrency 1. It
sits on the network `wboost_scan` (`10.200.107.0/24`) with `web` and nothing else: it cannot resolve or
reach the database, Redis, MinIO or Gotenberg. `web` answers `/internal/scanner/*` only to a peer
address inside that subnet (`SCAN_NETWORK_CIDR`) that sends `SCANNER_TOKEN` — everybody else gets 404.
**That check in the app is the guarantee.** The public router adds a second, best-effort layer: it
answers 403 before the app is asked for `/internal/…` in the spellings the app would route there
(repeated or encoded slashes, an `/index.php/` prefix, any letter case — infra
`apps/wboost/compose.yaml`, router `wboost-internal-paths`). A spelling the edge pattern does not know
ends in the app's 404, not in an answer.

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
touch /srv/wboost/.scanner-hold && docker compose stop scanner    # stop it ON PURPOSE — the hold file FIRST
rm /srv/wboost/.scanner-hold && docker compose start scanner      # start it again
docker compose restart scanner                                    # a plain restart needs no hold file
```

`stop` sends SIGTERM: the running scan is released back to the queue and the scanner exits in ≈ 3 s
with code 0. When the release cannot reach the app, or its own 30 s limit runs out, it exits **1**;
when the 45 s grace period runs out Docker kills it (**137**). A stopped scanner makes the app say
`scanner_absent` after 60 s: uploads are refused, waiting scans wait (and fail refunded after 60
minutes).

**The hold file `/srv/wboost/.scanner-hold` is what makes a deliberate stop stick.** Three things
would otherwise start a stopped scanner again, and all three respect the file:

- the next app deploy — `deploy.sh` ends with `up -d scanner`, several times a day; with the file it
  prints a note and leaves the scanner alone (a changed pin is then not applied either);
- cron `scanner-revive` (below), when the stop ended in 137;
- nothing else: the container's policy is `restart: unless-stopped`, so a restart of the Docker
  daemon or of the host does **not** bring back a scanner that was stopped by hand (it does bring
  back one that was running).

The file only holds the *process* down. The switch for the *feature* is the mode (3. below, "Kill
switch"): with the mode `off` nothing is scanned whether a scanner runs or not.

**`docker kill` is not a restart.** Docker treats a kill as a manual stop and does not apply the
restart policy (found on 2026-11-13). It no longer matters: the host cron `scanner-revive` (every
minute, `~/www/lily.srv/apps/wboost/scanner-revive.sh`) starts a scanner container that is `exited`
with code **137** — measured: killed just after a tick, started again 57 s later, healthy 6 s after
that; a scan it was running is recovered by its lease like any crash. It only ever runs `docker start`
(nothing is re-created), only under the deploy lock (beside a running deploy it does nothing and looks
again a minute later), and it **leaves every other exit alone**: 0 (a clean stop) and 1 (a stop that
could not finish cleanly — the app was unreachable, which is exactly when somebody pulls the plug) stay
stopped. Until the evening of 2026-11-13 it revived any non-zero exit and so undid such a stop within
a minute. A stop that ran into the 45 s grace exits 137 like a kill — that is what the hold file is
for. A real crash, an OOM kill or a boot restart it by the policy (below: "Measured on the box", "A
host reboot"). It writes to `/var/log/lily/wboost-cron.log` only when it starts something or fails.

**3. Switch the mode** — no deploy, effective on the next request (also in the admin tab):

```bash
docker compose exec web bin/console app:ai-scan:status --mode=off      # everything stops, scans in progress fail refunded
docker compose exec web bin/console app:ai-scan:status --mode=paused   # nothing new starts, a running scan finishes
docker compose exec web bin/console app:ai-scan:status --mode=on
docker compose exec web bin/console app:ai-scan:status --admin-only=1  # 1 = only admins may start scans; production runs with 0 since 2026-11-13 (decision 16)
```

**4. Ship a scanner** (a new image of `scanner/`) — "Scanner image". The Scanner workflow pushes
`ghcr.io/thedevs-cz/wboost-scanner:sha-<full commit>`; nothing deploys it. The bump, in
`~/www/lily.srv`:

1. wait for the Scanner workflow of that commit to be green (the tag must exist:
   `docker buildx imagetools inspect ghcr.io/thedevs-cz/wboost-scanner:sha-<full commit>`);
2. `git pull --rebase`, change the sha in `apps/wboost/compose.yaml` (`scanner.image` — that one
   line), add a journal entry, push. The box receives the file within a minute; nothing restarts;
3. on the box: `docker compose exec -T web bin/console app:ai-scan:status --scans` — nothing in
   progress (a re-create mid-scan is clean, but why);
4. activate — wait for the next app deploy or run the command below;
5. check: `docker compose ps scanner` healthy on the new image, `status` → `Scanner seen … (version
   0.2.0+<sha>)`.

The pin is the **tag alone, on purpose without an `@sha256:` digest**: the tag carries a full commit
sha and is written once; with a digest beside it, a bump that changes only the sha in the tag would
keep running the old image without a word (Docker follows the digest and ignores the tag). If
`/srv/wboost/.scanner-hold` exists the deploy does not touch the scanner — remove it first.

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
  one), check `status`, delete `SCANNER_TOKEN_PREVIOUS` (through the API — `infisical secrets delete`
  answers 404, `~/www/lily.srv/docs/secrets.md`), deploy again at leisure (any app deploy does it).
  **Done once, on 2026-11-13, with a scan running through the first deploy: no failed scan.** Why it
  is safe: during the blue-green overlap both `web` generations accept the old token the scanner still
  sends; the scanner is re-created last, when only the new `web` (old + new) is left; the scan it was
  building is released and claimed again by the new container. The second deploy does not touch the
  scanner. To check without printing anything, compare fingerprints:
  `docker exec wboost-scanner-1 printenv SCANNER_TOKEN | tr -d '\n' | sha256sum | cut -c1-8` against
  the same over each comma-separated part of `web`'s `SCANNER_TOKEN`.
- Provider key: replace `ANTHROPIC_API_KEY` in Infisical, `./deploy.sh main` — the deploy writes it to
  `.env.scanner` and re-creates the scanner alone (a bare `docker compose up -d scanner` does not
  fetch anything from Infisical). Without a key the scanner stays up and healthy and reports the
  provider as blocked — nothing crash-loops. Production uses an API key only, never a subscription
  credential, and no login state is mounted into the container.

**What a push ships.** Release (`.github/workflows/release.yml`) runs after a successful Tests run and
builds exactly the commit that run tested (`workflow_run.head_sha`), not the head of `main`; the image
carries it as the label `org.opencontainers.image.revision` (and the tag `sha-<commit>`). Shipping runs
do not overlap, a docs-only / landing-only change since the last shipped commit is not shipped, and a
commit that is not on `main` or is older than the shipped one is refused. Only a Tests run of a *push*
to this repository's `main` can ship (not a pull request, whatever its branch is called). To retry a
failed Release use **"Re-run all jobs"**: "Re-run failed jobs" ships nothing on purpose (it would reuse
the checks' old answers). A green Release means "image pushed, the box accepted the deploy request" —
not that the rollout succeeded (the run does not wait for it). What runs:
`docker inspect -f '{{index .Config.Labels "org.opencontainers.image.revision"}}' $(docker ps -qf name=wboost-web)`.

### Roll back P4

1. `status --mode=off` — instant; the feature is inert, scans in progress fail refunded, `ready`
   proposals stay usable.
2. `touch /srv/wboost/.scanner-hold && docker compose stop scanner` — the spending process is gone
   and stays gone through app deploys, cron and a restart of Docker or the host ("Production" 2).
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
| `/internal/…` in other spellings, from the internet (evening of 2026-11-13) | `/internal%2Fscanner/claim`, `/index.php/internal/scanner/claim`, `//internal/…`, `/INTERNAL/…`, `/Index.PHP/internal/…`, `/index.php%2Finternal/…`: 403 at the edge (the first two reached the app before — 404 / 405 from it, nothing exposed). `/internal%252Fscanner/…` and `/internal%5Cscanner/…` are not known to the edge pattern: 404 from the app |
| Scanner cut from its network for 30 s mid-build (`docker network disconnect wboost_scan`) | beats and polls fail (`network`), the run goes on; `ready` on its first try, 88 s after the claim instead of ≈ 35 s; nothing restarted |
| Scanner cut from its network for 150 s mid-build | after 60 s the app says `scanner_absent` (the entry card: `service_waiting`, no new uploads; the running scan's page keeps its stage); the scanner aborts its run itself at 105 s (`app_unreachable`); the lease ends at 120 s; on reconnect the scan is put back (`restarted`), claimed at once and `ready` 40 s later. Lost run: `lease_lost`, $0.02 |
| Out of memory mid-build (limit lowered to 300 MiB for 20 s, `docker update`) | cgroup OOM kill, exit 137, restarted by the policy; while the limit stayed too low the scanner refused to start (exit 78, 7 quick restarts — it needs 3 GiB); 11 s after the limit was put back it was up, healthy 6 s later; the scan was put back when its lease ended (125 s after the claim) and `ready` 48 s after that. No open usage row, one settle. The box's `HostOOMKill` alert pages for such a kill and names the container (infra D89) |
| `docker kill` while idle | started again by cron `scanner-revive` 57 s later (worst case: killed just after a tick), healthy after 6 s more |
| Hold file + `docker kill`, idle (evening of 2026-11-13) | `exited (137)` through two cron ticks **and** an app deploy in between (its log: "the scanner is held down on purpose; NOT started"); hold file removed at 20:12:00 → started by the 20:12:01 tick, healthy 5 s later |
| `docker compose stop scanner` while the app is unreachable (network disconnected), idle, no hold file | exit 0 (an idle scanner has nothing to release, so the stop is clean) — still stopped 70 s later |
| A manual stop ending non-zero but not 137 (`docker kill -s QUIT` → exit 131; stands in for the scanner's own exit 1, which needs a scan in progress whose release fails), no hold file | still `exited (131)` after two cron ticks; nothing in the cron log |
| Token rotation with a scan running (`SCANNER_TOKEN_PREVIOUS`, two deploys) | no failed scan: the scan was released when the scanner was re-created with the new token and `ready` 70 s later; after the second deploy `web` accepts the new token only and the scanner was not touched |

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
| Mode | `on · admins + editors of entitled projects` (since 2026-11-13, decision 16: access needs only the per-project switch) | `paused` — set by hand or by the circuit breaker; `off` — set by hand; `on · admins only` — somebody set `--admin-only=1`: customers of switched-on projects cannot start scans |
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
| Stop the spending process itself | `touch /srv/wboost/.scanner-hold && docker compose stop scanner` — the hold file **first** | SIGTERM: runs aborted, one `release` each with the usage, exit ≤ 30 s (grace 45 s); released scans go back to `queued` without using their restart. After 60 s the app says `scanner_absent`: uploads refused, waiting scans fail after 60 min (`queue_timeout`, refunded). The file keeps it stopped: without it the next app deploy starts the scanner again, and cron `scanner-revive` does when the stop ended in a kill (exit 137) |
| Back | `status --mode=on` (and `rm /srv/wboost/.scanner-hold && docker compose start scanner`) | the next request starts scans again; failed scans are retried by their users ("Zkusit znovu", 2 per upload) |

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

1. Fix the cause with the provider: the key (Infisical → `ANTHROPIC_API_KEY`; then `./deploy.sh main`,
   which writes it to `.env.scanner` and re-creates the scanner), the workspace's spend limit, the
   credit balance.
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
docker compose ps -a scanner                   # restarting? unhealthy? exited — with which code?
ls -la /srv/wboost/.scanner-hold               # exists = somebody is holding it down on purpose
docker compose logs --tail 200 scanner         # JSON lines: ids, codes, durations — never a design's text
docker compose exec scanner node bin/scanner.mjs healthcheck
docker compose start scanner                   # start (never re-creates; `up -d --pull never scanner` applies a changed definition)
```

- A scanner that died mid-scan left a lease: 120 s after its last heartbeat the reaper puts the scan
  back (`restarted`); a second death fails it `interrupted`, refunded — a poisonous picture cannot
  loop, and the same picture failing twice in a project is refused for 30 days (`source_unscannable`).
- The container is `Exited` — it was stopped by hand (a crash or an OOM kill would have been restarted
  by the policy). By exit code:
  - **0** — a clean stop; **1** — a stop the scanner could not finish cleanly (its release did not
    reach the app, or its 30 s limit ran out). Both stay stopped: somebody decided so. Look for
    `/srv/wboost/.scanner-hold` and ask before starting it (`rm` the file, `docker compose start
    scanner`);
  - **137** — killed: `docker kill`, or a stop that outlasted the 45 s grace. Cron `scanner-revive`
    starts it within a minute — unless the hold file exists (then it was a deliberate stop) or a
    deploy is running. A line `scanner-revive: started …` in `/var/log/lily/wboost-cron.log` says it
    did;
  - **78** — it refused its configuration (below).
- An OOM kill at the scanner's own 4 GiB limit sends the box's `ContainerOwnLimitOOMKill` (warning,
  not the critical `HostOOMKill` — infra D87 addendum 2): contained, the scan is recovered by its
  lease. Once is a heavy picture; repeated is a limit or a leak to look at.
- `Restarting` with exit 78 in a loop: it refuses its configuration — see `Refused at start` below.
- `Refused at start`: the log says why — a concurrency the memory limit cannot hold (3 GiB for 1),
  a missing `SCANNER_APP_URL` / `SCANNER_TOKEN`.
- `app.lastError: not_scanner` in the health document: `web` does not see the scanner's address in
  `SCAN_NETWORK_CIDR`, or the tokens differ (`SCANNER_TOKEN` of `web` may hold `current,previous`
  during a rotation).

## A host reboot (not tested, by decision)

The box is shared and is not rebooted for a test. What happens, from the pieces that were tested:

- Every container of the stack has `restart: always` (the scanner `unless-stopped`: it comes back
  if it was running, and stays down if it was stopped by hand) and Docker starts at boot, so all of
  them come back without anybody acting; no start order is enforced by Docker after a boot. `web` and the worker
  wait for the database themselves; the scanner needs only `web` and retries every call (a scanner
  that starts before `web` answers stays `starting` / unhealthy until its first accepted poll, then
  healthy — the same path as every blue-green deploy and as the network cut above).
- A scan that was `running` or `checking` loses its scanner process exactly as in the kill and OOM
  cases: the row keeps its lease, 120 s after the last heartbeat the reaper (any status read by its user, or the
  scanner's own claim poll and 30 s tick — so in practice the moment a scanner is back) puts it back — `restarted`, claimed again, at most
  once; a second loss fails it `interrupted`, refunded. The lost run's usage row is closed `lease_lost`
  with its last heartbeat's figures. If the box is down longer than the lease, that happens on the
  first request after the boot.
- `queued` scans simply wait; one that waited 60 minutes in all fails `queue_timeout`, refunded.
- While the scanner is not seen for 60 s the app says `scanner_absent` (uploads refused); the e-mail
  "scanner not answering" goes out after 3 minutes and "back" when it returns — if the mailer is up.
- Uploaded pictures and proposals are in MinIO and Postgres, both on volumes; the scanner keeps
  nothing (its `/tmp` is a tmpfs).

After an unplanned reboot: `status` (scanner seen, no scan stuck in `running` with a lease in the
past — `status --reap` if nobody has read it yet), `docker compose ps`.

## Gotenberg's memory

The renders of a scan go to the stack's one Gotenberg (5 GiB limit), shared with exports. The local
soak showed its anonymous memory creeping (187 → 215 MB over 100 scans). Nothing new watches it: the
box's Prometheus already records every container (`container_memory_working_set_bytes`,
`container_memory_rss`, 30 days kept) and its alerts already cover this one —
`ContainerMemoryNearLimit` (75 % for 10 min), `ContainerMemoryCritical`, `ContainerMemoryLeakPredicted`
(a 2-day trend that would reach the limit in 4 days) and `HostOOMKill`, which names the killed
container. To look:

```bash
docker stats --no-stream wboost-gotenberg-1
# daily maximum of the last 14 days, MiB (swap max_over_time for min_over_time, working_set for rss):
docker exec prometheus wget -qO- "http://127.0.0.1:9090/api/v1/query_range?query=max_over_time(container_memory_working_set_bytes%7Bname%3D%22wboost-gotenberg-1%22%7D%5B1d%5D)%2F1048576&start=$(( $(date +%s) - 14*86400 ))&end=$(date +%s)&step=86400" \
  | python3 -c 'import sys,json,time; [print(time.strftime("%m-%d", time.localtime(t)), round(float(v))) for r in json.load(sys.stdin)["data"]["result"] for t, v in r["values"]]'
```

Figures on 2026-11-13, the container 12 days up (since the boot of 2026-11-01) and ten
scans of the checks in: 694–727 MiB working set now (14 % of the limit), of that ≈ 205 MiB anonymous, 160 MiB tmpfs,
the rest page cache. Per day over those 12 days: working set minimum 450–675 MiB, maximum 649–1 151 MiB
(23 % of the limit at most), anonymous minimum 132–188 MiB — it returns to the same floor every day; no
trend. Read the same series again after a week of real use; a rising daily **minimum** of the
anonymous figure is the sign of a leak, and the remedy is a restart of Gotenberg in a quiet minute
(`docker compose restart gotenberg` — exports fail for the few seconds it takes).

## Rollback, per phase

Everything is additive (migrations add tables and columns only) and inert while the mode is `off`.

| Phase | Roll back by | `checking` | `queued` | `running` | `ready` |
|---|---|---|---|---|---|
| P1–P3 (the app: job core, wizard, MCP) | `status --mode=off` — instant, no deploy. Reverting the app image is not needed and not enough (rows stay) | `failed interrupted`, refunded | `failed interrupted`, refunded | `failed interrupted`, refunded; the scanner aborts within 15 s | untouched — review, accept and discard keep working; expires 30 days after the scan ended |
| P4 (the scanner on the box) | `status --mode=off` first (above), then `touch /srv/wboost/.scanner-hold && docker compose stop scanner`; to remove it for good the rollback commit of `lily.srv` must also delete the service — reverting the compose push does not stop a running container | as above with `off`; with only the scanner stopped: waits, then `failed queue_timeout` after 60 min, refunded | same | SIGTERM → released → `queued`, restart not used → then as `queued` | untouched |
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

## Links in e-mails point to the wrong host

The mail about an ended scan is rendered by `messenger-consumer`, the reminder and the admins' alerts
by the cron commands — outside a request. Their links start with **`DEFAULT_URI`**
(`framework.router.default_uri`): `https://wboost.cz` unless the environment says otherwise (the default
is in the image's `.env` and in `config/services.php`; nothing has to be rendered from Infisical). To see
what a container would write, read-only:

```bash
docker compose exec -T web bin/console debug:container --env-var=DEFAULT_URI
docker compose exec -T messenger-consumer bin/console debug:config framework router | grep default_uri
```

`http://localhost/…` in a mail means the setting is missing in that container (an image older than
2026-11-13), not a mailer problem.

## What is not built

- **An e-mail for "a project paused for refusals"** (architecture §7 lists it): nothing pauses a project,
  so nothing is sent. The other operating e-mails exist — scanner not answering (3 min), scanner too
  old, provider blocked (10 min), circuit breaker, spend at 80 % / at the ceiling, a scan waiting
  > 15 min: once when it starts, one reminder a day, once when it ends, nothing while the mode is `off`.
  They depend on the cron line `*/5 * * * * … bin/console app:ai-scan:watch`; without it nothing is sent.
- **In the admin tab**: cancelling one scan and the per-scan history / usage rows — `status --cancel=<id>`
  and `status --scan=<id>` stay the way to do that; the scans table has no paging (in progress + the last
  50 ended).
- **Reconciliation** of recorded spend against the provider's report: an operations task (weekly,
  ± 10 %), first done on the ten production scans of P4.
