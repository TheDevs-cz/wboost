# AI scan — infrastructure digest (exploration, 2026-11-12)

Read-only exploration of the infra repo `~/www/lily.srv` (HEAD `0ae761c`, 2026-11-11) for the
dedicated scan worker. Nothing was changed, nothing was run on the box (no ssh). Companion to
`digest-platform.md` — its §4 (job pattern) and §5 (box measurements, first worker proposal) are
not repeated; where this file corrects that proposal it says so.

Tags:

- **[M]** read directly in a file (path:line)
- **[I]** inferred from what was read
- **[S]** suggestion, not a fact

Paths without a prefix are in `~/www/lily.srv`. `wboost:` = this repo. `Dxx` = `docs/decisions.md`.

**Three findings that change the plan in `digest-platform.md` §5:**

1. **Per-container CPU limits are rejected by a standing decision (D31).** `cpus:` on the scan
   worker is an exception that needs its own decision entry. One precedent exists (`online-cv`).
2. **Deploys are serialized box-wide.** A deploy that waits for a running scan holds the lock for
   every app on the box.
3. **The wboost consumer command has no `exec`.** Whether SIGTERM reaches PHP at all is unverified;
   if it does not, a long grace period makes every deploy wait the full period and then kill the scan.

---

## 1. How wboost is deployed today

### Services [M] (`apps/wboost/compose.yaml`)

| Service | Image | Limits | Health | Notes |
|---|---|---|---|---|
| `web` `:32-122` | `ghcr.io/thedevs-cz/wboost:${IMAGE_TAG:-main}` `:33` | 1g / res 256m / pids 512 `:59-61` | curl liveness, 15 s interval, `start_period: 40s` `:68-73` | `FRANKENPHP_WORKER=1` `:38`, `stop_grace_period: 30s` `:75`, nets `traefik` + `internal` `:87-89` |
| `messenger-consumer` `:125-164` | same image `:126` | 768m / res 192m / pids 512 `:149-151` | **disabled** `:145-148` | command `:128`, `SKIP_DATABASE_MIGRATIONS` `:144`, `stop_grace_period: 30s` `:154`, net `internal` only |
| `db` `:167-209` | `postgres:16.14@sha256:…` (D58 pin) `:171` | 512m | `pg_isready` | `max_connections=100` `:184` |
| `redis` `:224-245` | `redis:7.2.14@sha256:…` `:225` | 768m | `redis-cli ping` | cache only, `maxmemory 512mb allkeys-lru`, **no password** `:227` |
| `minio` `:248-302` | pinned release `:253` | 1g | (scraped) | public `img.wboost.cz` |
| `gotenberg` `:305-354` | `gotenberg/gotenberg:8` (floating) `:306` | 5g / res 1g / pids 512 `:326-328` | curl `/health` `:346-351` | see §5 |
| `adminer`, `db-exporter` `:357-407` | pinned | 128m, 64m | | |

File header hard rules `:11-16`: no `container_name`, no published ports, **`mem_limit` on every
service**, web has a healthcheck, secrets from `.env`. There is no `cpus:`, no `deploy.resources`,
no `logging:` block anywhere in the file.

### Labels [M]

- `web`: Traefik router/service/retry `:92-116`; `app: "wboost"` and `blackbox.url` (D26) `:118-119`;
  `messenger.stats: "true"`, `messenger.age.pg: "db"` (D32) `:121-122`.
- `messenger-consumer`: only `traefik.enable: "false"` and `app: "wboost"` `:162-164`.
- No service carries `mem.alert`.

### Deploy sequence [M] (`apps/wboost/deploy.sh`)

1. Create external volumes `:27-29`.
2. `dump_secrets wboost` → `/srv/wboost/.env` `:32`.
3. `pull_image web` — one pull, web and consumer share the image `:35-36`.
4. `docker compose up -d db redis minio gotenberg adminer db-exporter` — no-op when unchanged `:42`.
5. Migrations: none explicit. The new web container runs
   `doctrine:migrations:migrate --all-or-nothing` on boot (`wboost:.docker/on-startup.sh:27-28`)
   inside the rollout's health gate; they must be backward-compatible across one deploy `:44-48`.
6. `rollout web` `:51`, `REPLICAS=1` `:12`.
7. `restart_consumers messenger-consumer` `:54`.
8. `install_cron wboost` `:58`.

Trigger: `wboost:.github/workflows/release.yml:75-86` (after green tests on `main`, reusable
`_ship.yml@v1`, `await_deploy: false`) → HMAC hook `wboost` (`infra/webhook/deploy.sh:53`) → host
executor → `/srv/wboost/deploy.sh <tag>` (`CLAUDE.md:66-73`).

### Blue-green mechanics [M] (`deploy/lib.sh`)

- `rollout()` `:610-781`: normalize to N → scale N→2N `--no-recreate` `:671` → wait each new
  container `healthy`, abort and revert on `RestartCount > 1` or **180 s** `:621`, `:690-712` →
  sleep 5 s `:763-764` → `docker stop` + `rm` each old container, 2 s apart `:767-774` → scale back
  to N `:779`.
- wboost uses the **default** cutover. The drain cutover is opt-in (`ROLLOUT_DRAIN=1`, D76,
  `lib.sh:241-289`) and only `myspeedpuzzling` exports it (`apps/myspeedpuzzling/deploy.sh:19`).
- Old web containers are stopped with their own `stop_grace_period` (30 s).

### What a deploy restarts, and how [M]

| What | How | Grace |
|---|---|---|
| `web` | new container up and healthy, then old stopped | 30 s `compose.yaml:75` |
| `messenger-consumer` | `docker compose up -d --force-recreate <svc>` — **every deploy, unconditionally** (`lib.sh:791-800`) | 30 s `compose.yaml:154` |
| db / redis / minio / gotenberg / adminer / exporter | only if their config or image changed (`deploy.sh:39-42`) | default |

`restart_consumers` is documented as "SIGTERM + the app's stop-grace let in-flight messages drain"
(`lib.sh:786-789`). It has no idle check, no changed-only gate and no `--no-deps`.

### Consumer command [M]

`compose.yaml:128`:
`bash -c 'wait-for-it db:5432 -- sleep 5 && bin/console messenger:consume async -vv --time-limit 3600 --memory-limit 256M'`

- **No `exec`.** `myspeedpuzzling` changed the same line to `… && exec bin/console messenger:consume …`
  with the comment "`exec` makes the consumer PID-1's child that receives SIGTERM, so a stop finishes
  the current message gracefully" (`apps/myspeedpuzzling/compose.yaml:375-379`, journal
  `docs/journal.md:2041-2047`). wboost, wtips, sendvery, fajnesklady and fantasy-academy still use
  the old form.
- **[I]** With the old form a `docker stop` may never reach the PHP worker. For a 30 s grace and
  sub-second messages nobody notices. For a scan it decides whether the grace period drains or kills.
  Not verified on the box.

### Executor constraints [M]

- **One deploy at a time on the whole box**: `deploy/run-queue.sh:33-36`, `:62-68` (flock for the
  whole drain), `deploy/lily-deploy-runner.service:62-63`.
- A deploy must finish in **1700 s** (`run-queue.sh:85`, `:190`; systemd `TimeoutStartSec=1800`,
  `lily-deploy-runner.service:67`).
- The executor runs `ProtectHome=true` (`lily-deploy-runner.service:46`): it cannot pull a private
  ghcr package without the box credential (§6).

### Cron [M] (`apps/wboost/cron.d/wboost`)

Two nightly jobs `:33-34` (02:00 `app:gallery:purge-trash`, 02:30 `app:storage:scan`), each
`lily-cron-run wboost <slug> -- docker compose … run --rm --no-deps messenger-consumer sentry-cli monitors run … -- bin/console …`.
`--no-deps` is mandatory (D58, `:23-27`). Cron one-offs reuse the **`messenger-consumer` service
definition**, so they inherit its limits and environment.

### Config delivery [M]

`apps/wboost/` is reconcile class D: a push to the infra repo rsyncs it to `/srv/wboost/` and
**never activates it**; `compose.yaml` / `deploy.sh` take effect on the app's next deploy
(`CLAUDE.md:54-61`, `deploy/reconcile.manifest:104-110`, `:154`). The cron file is a class-C row and
is live on push (`reconcile.manifest:183`).

---

## 2. Resource-governance conventions of the box

### Required on every service [M]

| Convention | Source | Enforced by |
|---|---|---|
| `mem_limit` (box has no swap, D2) | D2 `docs/decisions.md:16-21`; D12 `:281-283`; `apps/wboost/compose.yaml:14` | `lib.sh:73-82` only **warns** |
| No `container_name`, no published ports | `apps/wboost/compose.yaml:12-13` | convention |
| Liveness signal: healthcheck **or** scrape target | D26 rule 1, `docs/decisions.md:807-816` | `ContainerUnhealthy` |
| `app:` label (log label) | `apps/.template/compose.yaml:89-90` | convention |
| `security_opt: no-new-privileges:true`, `pids_limit`, `mem_reservation`, `restart: always` | present on every wboost service | convention, no decision id found |
| Stateful base images pinned `tag@sha256` | D58, `CLAUDE.md:117-124` | convention |
| Cron `compose run` carries `--no-deps` | D58, `CLAUDE.md:112-116` | convention |

- **Logging** is set once in the daemon, not per service: driver `local`, `max-size 10m`,
  `max-file 3` (`infra/docker/daemon.json:3-7`, D12 `docs/decisions.md:273`). No compose file needs a
  `logging:` block. All container output is shipped to Loki (`docs/secrets.md:64-65`) — **a worker
  must never log a secret or a user's document text at info level** [I].
- **Workers are the tolerated exception to D26**: every worker on the box disables the image's HTTP
  healthcheck and relies on `restart: always` (`apps/wboost/compose.yaml:145-148`,
  `apps/boliga-api/compose.yaml:190-194`). So a **hung** worker is watched by nothing [I].

### CPU: host-level only, by decision [M]

**D31** (`docs/decisions.md:1155-1209`): "CPU = host-level only; **NO per-container CPU limits**"
`:1166-1173`. The alternative "add per-container CPU limits (`cpus:`)" is listed as rejected
`:1192-1195`. The throttling alert is parked "by decision" and its metric is disabled in cAdvisor
(`infra/observability/prometheus/rules/container-alerts.yml:51-64`).

Whole repo, grep for `cpus|cpu_shares|cpuset|cpu_quota`:

- `infra/traefik/compose.yaml:51` — `cpu_shares: 4096` (priority, caps nothing; D31 `:1184-1185`).
- `apps/myspeedpuzzling/compose.yaml:1022` — `cpus: 2` on `online-cv`. Approved by the owner in the
  journal (`docs/journal.md:833-838`, "Jan's yes 2026-10-14"), **without a decision entry amending D31**.

`digest-platform.md:219` calls `cpus` "a new convention". More exactly: it contradicts a recorded
decision and has one undocumented precedent.

### Memory alerting [M] (`infra/observability/prometheus/rules/lily-alerts.yml`)

| Alert | Condition | Line |
|---|---|---|
| `ContainerMemoryNearLimit` | working set 75–95 % of `mem_limit` for 10 m | `:112-124` |
| `ContainerMemoryCritical` | ≥ 95 % for 5 m | `:151-161` |
| `ContainerCacheMemoryNearLimit` / `…Critical` | rss 50–70 % / ≥ 70 %, only `mem.alert="rss"` containers | `:211-251` |
| `ContainerOOMKilled` | any cgroup OOM kill in 5 m | `:273-274` |
| `ContainerMemoryLeakPredicted` | 2-day slope reaches the limit within 4 days, container ≥ 2 days old, growth is anon | `:327-352` |
| `HostOOMKill` | host-wide kill counter | `:59-60` |

**`mem.alert` classes — D52** (`docs/decisions.md:2974-3111`). There is exactly one class:
`mem.alert: "rss"` = cache-class, "a container whose job is to **be** a disk cache". Users today:
`apps/myspeedpuzzling/compose.yaml:881` (images-cache) and `apps/fotbal-fm/compose.yaml:417` (uploads
nginx). No label = the default working-set tiers.

- It was explicitly **rejected** for ordinary app containers: D70 `docs/decisions.md:4639-4640`
  ("on an ordinary app it disables the working-set OOM warning") and D65 `:4378`.
- **A scan worker is not cache-class and must not carry the label** [I].
- Trap if it is ever added: labels are fixed at container creation, so it needs a recreate and a
  check that the series carries it (D52 `:3073-3081`).

**Page cache counts.** Working set = usage − inactive file, so the hot pages of a large binary are
billed to the container. D62 addendum: one first LibreOffice run charged +475 MiB of page cache to
`fajnesklady-web` and paged (`lily-alerts.yml:317-320`). A 342 MB agent binary plus ONNX models will
do the same on their first run — size the limit for anon **plus** those files [I].

### Other alerts a new worker can trip [M]

| Alert | Condition | Relevance |
|---|---|---|
| `ContainerHighCPU` | one container > 1.5 cores for 30 m (`container-alerts.yml:83-85`) | fires on a long OCR queue if the worker may use > 1.5 cores; can never fire at `cpus: 1.5` |
| `ContainerRestartLoop` | > 3 restarts of one container in 15 m, for 5 m (`container-alerts.yml:40-44`) | fires if the worker exits after every job (`--limit 1`, or `--memory-limit` exceeded on each job) |
| `HostHighCPU` / `…Critical` | host > 75 % for 15 m / > 95 % for 20 m (`host-alerts.yml:52-63`) | 2 threads of 16 cannot reach it |
| `MessengerFailedMessages` (+Critical) | oldest message in `failed` ≥ 1 h / ≥ 24 h (`messenger-alerts.yml:35-54`) | a scan handler that throws lands here |
| `MessengerConsumerUnmonitored` | a `messenger:consume` container in a project without `messenger.stats` (`messenger-alerts.yml:114-116`) | wboost already has the label on `web` |
| `LilyCronJobFailed` | a `lily-cron-run` job exits non-zero (`CLAUDE.md:109-111`) | any new scan cron |
| `DiskWillFillSoon` / `DiskFull` | `lily-alerts.yml:379-403` | image growth, §6 |

- `lily_messenger_messages{app,transport}` is emitted per transport from `messenger:stats`
  (`infra/maintenance/lily-messenger-metrics.sh:181-188`), so a `scan` transport's backlog becomes a
  metric with no infra change. **No alert rule reads queue depth** — only the failed transport [M].
- **Nothing alerts on a stuck scan or on Anthropic spend** [I].

### How other apps define workers [M]

- **Second queue, second service, same image** — the exact shape planned here:
  `myspeedpuzzling` `digest-consumer` (`apps/myspeedpuzzling/compose.yaml:441-497`), a copy of
  `messenger-consumer` consuming only `content_digest_emails` "so a Monday's paced digest wave never
  waits in front of the transactional e-mails", restarted by
  `restart_consumers messenger-consumer digest-consumer` (`apps/myspeedpuzzling/deploy.sh:89`).
  512m, pids 512, grace 30 s, healthcheck disabled, no CPU limit.
- **CPU-heavy helper as its own image and container**: `online-cv`
  (`apps/myspeedpuzzling/compose.yaml:994-1033`): Python + OpenCV, `ghcr.io/myspeedpuzzling/online-cv:sha-7c03b5f`
  **pinned by sha tag**, built by a separate workflow in the app repo, `mem_limit: 1536m`, `cpus: 2`,
  `ONLINE_CV_WORKERS: "1"`, `ONLINE_CV_THREADS: "2"`, `read_only: true`, tmpfs `/tmp`, `cap_drop: ALL`,
  `no-new-privileges`, `internal` network only, an allow-list of hosts it may fetch from. The
  consumer calls it over HTTP. Deploy: `docker compose pull --quiet online-cv` + it is in the `up -d`
  list (`apps/myspeedpuzzling/deploy.sh:55`, `:69`) — recreated only when its tag is bumped.
- **Sidekiq** (`apps/boliga-api/compose.yaml:179-201`, `apps/boliga-api/deploy.sh:93`): 512m, grace
  30 s sized to the worker's own shutdown timeout.
- Longest grace period on the box: 120 s, a Postgres (`apps/myspeedpuzzling/compose.yaml:548`).
  **No worker has more than 30 s** [M].

### Disk and images [M]

- Weekly prune, Monday 03:30 (`infra/crontab:66`): keeps running images + the 2 newest tagged per
  repo; dangling images are kept **14 days** as rollback targets
  (`infra/maintenance/lily-image-prune.sh:10-24`, `:31-32`).
- wboost deploys the moving tag `main`, so every deploy leaves one dangling image for up to 14 days.
- No decision sets a maximum image size or build time (searched `decisions.md`, `backlog.md`,
  `software.md`, `CLAUDE.md`, `apps/README.md`).
- Box numbers are in `digest-platform.md:165` (627 G free, 340 images = 89 GB).

---

## 3. Secrets

### Flow [M]

1. One Infisical project per app, named like the app dir; environment `prod`
   (`docs/secrets.md:8-13`, D9 `docs/decisions.md:159-185`).
2. At every deploy `dump_secrets wboost` logs in with the box-only machine identity and writes
   `/srv/wboost/.env`, mode 0600 (`deploy/lib.sh:112-161`). Key names and counts only are logged `:161`.
3. `web` and `messenger-consumer` load the whole file with `env_file: .env`
   (`apps/wboost/compose.yaml:35-36`, `:129-130`). Compose also reads it for `${VAR}` interpolation
   (`:176`, `:257-258`).
4. Infisical is a deploy-time dependency only; a running container never talks to it (D9 `:164-172`).
5. Non-secret config is written as plain `environment:` values in `compose.yaml` (`:40-57`).

### `ANTHROPIC_API_KEY` [M]

- **Not declared anywhere in the infra repo** for wboost: no match in `apps/wboost/`, and no journal
  or decision entry records adding it. The only Anthropic mentions are sendvery's
  (`docs/decisions.md:1419`, `:1440`, `docs/journal.md:5795`).
- `digest-platform.md:186` found the key name in production's rendered `.env`. The D33 migration
  entry counts 10 keys (`docs/journal.md:5965`); the rendered file has 11. So it was added to the
  Infisical project later, by hand, unjournaled [I].
- It reaches a container only through `env_file`, so **today every wboost container with `env_file`
  has it**, including `web` and the nightly cron one-offs [I].

### Adding a variable [M]

- **Secret**: set it in Infisical project `wboost` → `prod` (UI, or CLI). The deploy machine identity
  is project **admin** on `wboost` because it created the project (`docs/decisions.md:1345-1347`), so
  it can write from the box. The CLI echoes values — always `>/dev/null` (`docs/secrets.md:126-127`).
  Then redeploy; nothing in the infra repo changes (`docs/secrets.md:107-110`).
- **Non-secret** (model id, thread counts, limits): a line under `environment:` in
  `apps/wboost/compose.yaml`, pushed to the infra repo, live on the next deploy.
- **Ordering rule**: a key must exist in Infisical **before** a compose line that references it as
  `${KEY:?…}` is deployed, or interpolation fails the whole app's deploy (`docs/secrets.md:98-99`).
  Example of that form: `apps/terlicko/compose.yaml:79`.
- A secret consumed only by a sidecar is passed by interpolation, not `env_file`, so the sidecar
  gets that one key and not the app's whole environment (bot-blocker,
  `apps/myspeedpuzzling/compose.yaml:1046-1052`).
- `docs/secrets.md` is updated only when the *flow* changes (`CLAUDE.md:181`), not per key.

---

## 4. What adding `scan-consumer` requires in the infra repo

### 4.1 Compose service [S]

Variant A — one container, the worker runs python / node / the agent binary as subprocesses.
Inserted after `messenger-consumer` in `apps/wboost/compose.yaml`:

```yaml
  # --- scan-consumer: AI scan worker — its OWN queue so a 1-5 min scan never
  # holds e-mails/events on `async` (the myspeedpuzzling digest-consumer shape).
  scan-consumer:
    image: ghcr.io/thedevs-cz/wboost:${IMAGE_TAG:-main}
    restart: always
    # `exec`: PHP must be the process that receives SIGTERM, or the grace period
    # below is a 6-minute wait followed by a kill (myspeedpuzzling, 2026-09-18).
    command: "bash -c 'wait-for-it db:5432 -- sleep 5 && exec bin/console messenger:consume scan -vv --time-limit 3600 --memory-limit 384M'"
    env_file:
      - .env                                   # carries ANTHROPIC_API_KEY
    environment:
      DATABASE_HOST: "db"
      MESSENGER_TRANSPORT_DSN: "doctrine://default"
      REDIS_CACHE_DSN: "redis://redis:6379/1"
      GOTENBERG_DSN: "http://gotenberg:3000"
      UPLOADS_BASE_URL: "https://img.wboost.cz"
      S3_ENDPOINT: "http://wboost-minio-1:9000"   # internal (D86)
      S3_REGION: "global"
      S3_BUCKET_NAME: "wboost"
      TRUSTED_PROXIES: "PRIVATE_SUBNETS"
      OAUTH2_PRIVATE_KEY_PASSPHRASE: ""
      SKIP_DATABASE_MIGRATIONS: "true"         # only web migrates
      # Thread pools: a CPU quota does not change what nproc reports (16), so
      # every pool must be pinned or it starts 16 threads inside a 2-thread quota.
      OMP_NUM_THREADS: "2"
      OPENBLAS_NUM_THREADS: "2"
      MAGICK_THREAD_LIMIT: "2"
      MAGICK_MEMORY_LIMIT: "256MiB"
      MAGICK_MAP_LIMIT: "512MiB"
    healthcheck:
      disable: true                            # not an HTTP server (house rule for workers)
    scale: 1                                   # concurrency = replicas; raise HERE, not with --scale
    cpus: 2                                    # EXCEPTION to D31 — needs the new decision entry
    mem_limit: 3g                              # PHP 384M + OCR ~250M + node + agent ~1G + page cache of the binaries
    mem_reservation: 256m
    pids_limit: 512
    security_opt:
      - no-new-privileges:true
    stop_grace_period: 360s                    # >= the job's own hard timeout + margin
    depends_on:                                # NOT gotenberg: floating tag, see 4.5
      db:
        condition: service_healthy
      redis:
        condition: service_healthy
    networks:
      - internal
    labels:
      traefik.enable: "false"
      app: "wboost"
```

Notes on the choices:

- **No `mem.alert` label** — default working-set tiers are the right ones (§2).
- **`mem_limit`**: 3g assumes the Agent SDK runs inside this container at concurrency 1. Without the
  agent, 1.5g. At concurrency 2 in one container, add ~1 GiB per extra scan. The paper sum of limits
  goes 119.8 → ~122.8 GiB of 125 (`digest-platform.md:168`); no rule in the repo caps that sum.
- **`cpus: 2`** matches the only precedent (`online-cv`). D31-conform alternatives: no cap and
  `cpu_shares: 512` (yields under contention, caps nothing), or no CPU setting and rely on pinned
  thread pools. With `cpus:` set, throttling is invisible: the metric is disabled
  (`container-alerts.yml:56-58`).
- **`scale:`** in the file, because `restart_consumers` runs `up -d --force-recreate <svc>` with no
  `--scale` (`lib.sh:797`), so a hand-made `--scale scan-consumer=2` is reverted by the next deploy.
  This corrects `digest-platform.md:218`.
- **Do not use `--limit 1`** to free memory between scans: > 3 restarts in 15 minutes trips
  `ContainerRestartLoop`. `--memory-limit` counts PHP only, not subprocesses.
- Environment is duplicated per service in this file (house style; no YAML anchors in wboost).
- The existing `messenger-consumer` stays untouched: cron one-offs run on its definition.

Variant B — the house pattern for a heavy non-PHP tool (`online-cv`): keep `scan-consumer` as a
light PHP worker (`mem_limit: 1g`, no CPU setting) and add a sidecar:

```yaml
  scanner:
    image: ghcr.io/thedevs-cz/wboost-scanner:sha-<short>   # pinned; bump here to ship
    restart: always
    environment:
      ANTHROPIC_API_KEY: "${ANTHROPIC_API_KEY:?ANTHROPIC_API_KEY must be set in .env}"   # this key only
      SCANNER_WORKERS: "1"
      OMP_NUM_THREADS: "2"
    read_only: true
    tmpfs:
      - /tmp:size=256m                         # counts against mem_limit
    cpus: 2
    mem_limit: 2g
    mem_reservation: 256m
    pids_limit: 256
    cap_drop:
      - ALL
    security_opt:
      - no-new-privileges:true
    stop_grace_period: 360s
    healthcheck:                               # an HTTP service CAN satisfy D26
      test: ["CMD-SHELL", "curl -fsS http://127.0.0.1:8000/health || exit 1"]
      interval: 30s
      timeout: 5s
      retries: 3
      start_period: 20s
    networks:
      - scan                                   # NOT `internal`: no route to db / redis / minio
    labels:
      traefik.enable: "false"
      app: "wboost-scanner"
```

plus a `scan:` bridge network joined by `scan-consumer` and `scanner` only. See §6 for why.

### 4.2 `deploy.sh` [S]

What exists today already *is* a drain, on three conditions: SIGTERM reaches PHP (`exec`), the grace
period exceeds the job's hard timeout, and the worker stops fetching after the current message
(Symfony's worker does on SIGTERM [I]). Minimal change:

```bash
# ---- 6. restart the workers --------------------------------------------------
restart_consumers messenger-consumer

# ---- 7. install the versioned host cron (D30) --------------------------------
install_cron "$APP"

# ---- 8. scan worker LAST: SIGTERM lets the running scan finish (stop_grace_period
# 360s). Everything else of this deploy is already live, so a wait here delays
# nothing of wboost — but it HOLDS THE BOX-WIDE DEPLOY LOCK for up to 6 minutes.
restart_consumers scan-consumer
```

Costs and limits of that:

- **The lock.** One deploy at a time on the box (`run-queue.sh:33-36`): another app's deploy waits
  up to the grace period behind a wboost deploy that is waiting for a scan. At concurrency 2, the
  two replicas' waits may add up if compose stops them one after another (not verified).
- Budget: 1700 s per deploy (`run-queue.sh:85`); 360 s fits.
- The image changes on every app deploy, so a changed-only gate (the fotbal-fm pattern,
  `apps/fotbal-fm/deploy.sh:71-97`) would almost never skip the restart for a same-image worker. It
  would skip it for a sha-pinned sidecar (variant B) — its `up -d` is a no-op until the tag is bumped.
- During the wait the old worker runs **old code against the migrated schema** for up to 6 minutes.
  Migrations touching the scan job table must stay backward-compatible for that long, not just for
  the few seconds of a web cutover (`apps/wboost/deploy.sh:44-48`).

Alternative "restart only when idle" [S], if the lock wait proves unacceptable:

```bash
# skip the restart while a scan runs; the worker's --time-limit 3600 exit does NOT
# pick up the new image (restart: always reuses the old container), so a skipped
# restart must be retried — e.g. by a */10 cron that runs this same block.
if docker compose exec -T scan-consumer bin/console app:ai-scan:busy --quiet; then
  echo "== scan-consumer: a scan is running — restart deferred"
else
  restart_consumers scan-consumer
fi
```

It needs an app command that exits 0 while a job row is `running` and fresh, and a retry path;
between check and stop a new scan can still start (the grace period remains the safety net). More
moving parts than the blocking version — start with the blocking one.

`messenger:stop-workers` is **not** usable for this: the signal lives in `cache.app`, which is the
shared Redis (`wboost:config/packages/cache.php:10-11`), so it would stop every wboost worker, and
`restart: always` then restarts the old container with the old image.

Changing `restart_consumers` itself (`deploy/lib.sh`) is a separate matter: `deploy/` is not in the
reconcile manifest, so a `lib.sh` change needs a manual rsync to the box
(`docs/backlog.md:187-195`). Keep the logic in `apps/wboost/deploy.sh`.

### 4.3 Reconcile manifest, webhook, cron

- **Manifest: no new row** [M]. `wboost | D | apps/wboost` (`deploy/reconcile.manifest:154`) delivers
  `compose.yaml` and `deploy.sh`; `wboost-cron` (`:183`) delivers the cron file.
- **Webhook: no change** for variant A. For a second image from the same repo, a second
  path-filtered CI caller can post the **same** `wboost` hook (the D55 shape,
  `infra/webhook/deploy.sh:64-68`), or the sidecar is sha-pinned in compose and shipped by an infra
  push (the `online-cv` shape).
- **Cron** [S] — only if the app wants a sweeper (stale scans → failed + credit refund, retention of
  uploaded sources). Form required by `CLAUDE.md:106-116`:

  ```
  */10 * * * * root lily-cron-run wboost ai-scan-reap -- docker compose --file /srv/wboost/compose.yaml run --rm --no-deps messenger-consumer bin/console app:ai-scan:reap >> /var/log/lily/wboost-cron.log 2>&1
  ```

  Run it on the `messenger-consumer` definition, not on `scan-consumer` (a one-off there would
  inherit the 3g limit and the scan environment). Each tick starts a container; a nightly job gets
  a `sentry-cli monitors run` wrapper like the two existing ones, a `*/10` one usually does not
  (D37, `docs/decisions.md:1600`).

### 4.4 Rollout order [I]

1. **App repo first**: ship the `scan` transport, the handler and the command(s) with the feature
   switched off. Until the image knows the transport, `messenger:consume scan` exits at once and
   `restart: always` loops it → `ContainerRestartLoop`.
2. **Infisical**: confirm `ANTHROPIC_API_KEY` (and any new key) before a compose line uses `${…:?}`.
3. **Infra push — cron first, compose second, as two pushes** if both change: compose files land
   within seconds, `/etc/cron.d` rows only on the ≤ 5-minute timer (`docs/decisions.md:3734-3738`).
4. **Activation** = the next wboost deploy (any app push, or `./deploy.sh main` by the operator under
   `flock /run/lily-deploy-runner.lock`, as in `docs/journal.md:96`). The reconciler reports
   NEEDS-WINDOW until then.
5. Verify: container up, `docker inspect` shows the limits, a test scan, a deploy during a running
   scan (the scan must finish; the deploy log shows the wait).

### 4.5 `depends_on` and Gotenberg [I]

`restart_consumers` runs `up -d --force-recreate <svc>` without `--no-deps`, so compose also brings
the service's dependencies up to date. `db` and `redis` are digest-pinned, so that is a no-op.
`gotenberg/gotenberg:8` is a **floating tag** left floating on purpose (D58,
`docs/decisions.md:3728-3729`). Listing it under `depends_on` would let a worker restart re-create
Gotenberg under web traffic whenever the local tag has moved. Leave it out; the worker retries.

### 4.6 Documentation the repo's rules require [M]

`CLAUDE.md:170-188` — "document every server change, in the same session":

| Entry | Why |
|---|---|
| `docs/journal.md`, newest first: date · what · where · why (reference the Dxx) | mandatory for every change on the box, including the activating deploy `:177` |
| `docs/decisions.md`, new **D87** (D86 is the latest, `:5545`) with alternatives considered and why rejected | "a choice between alternatives" `:179`. Content: dedicated scan worker vs the shared consumer; the `cpus:` exception to D31 (and that `online-cv` is the same exception); grace-period drain vs idle-gated restart; scanner in the app image vs its own image; shared vs own Gotenberg |
| `docs/software.md` row | only if a new component arrives (a scanner image / Agent SDK sidecar) `:178`. Note: there is no `wboost (app)` row today, only MinIO `:50` and `wboost-landing` `:65` |
| `docs/backlog.md` | deferred items (spend alert, queue-depth alert, hung-worker liveness) `:183` |
| `docs/secrets.md` | not needed (flow unchanged) `:181` |

Two more rules apply:

- **Technology choices are walked through with the owner, not rubber-stamped** (`CLAUDE.md:192-211`):
  2–4 alternatives, a comparison table, a recommendation, the outcome recorded. Running the Claude
  Agent SDK on the box is such a choice.
- **Explicit confirmation before any state-changing action on the box** (`CLAUDE.md:226-230`).

---

## 5. Gotenberg

### Provisioning [M] (`apps/wboost/compose.yaml:305-354`)

- One container per app; only wboost runs one (grep over `apps/`). Image `gotenberg/gotenberg:8`,
  floating.
- `command: ["gotenberg", "--chromium-auto-start"]` `:315`. No `--chromium-max-queue-size`,
  deliberately `:311-314`. Default Chromium concurrency 6 `:316-317`; default API timeout 30 s
  (`docs/journal.md:3894-3895`).
- `mem_limit: 5g`, reservation 1g, pids 512 `:326-328`; `/tmp` is a 1g tmpfs that counts against the
  limit `:336-341`. **No CPU limit** (`docs/backlog.md:199-200`: "gotenberg uncapped").
- Sized after the 2026-08-05 incident: at 512m a Chromium was OOM-killed mid-render, `HostOOMKill`
  paged, 24 user-facing screenshots failed in 24 h (`compose.yaml:316-325`,
  `docs/journal.md:3880-3950`). Load then: ~69 requests/h peak against ~3.5 requests/s capacity.
- Reached as `http://gotenberg:3000` on the `internal` network, unauthenticated `:43`, `:135`.

### Would the scan worker share it? [I]

Yes, unless a second instance is added: `GOTENBERG_DSN` is one value for the app, and three kinds of
traffic already share the container — synchronous screenshots from `web` ("a stall is a
user-visible failure, not a retryable batch job", `compose.yaml:322-323`), the calendar PDF export
from the consumer, and soon scan verification renders.

### Contention risk [I]

- **Slots**: 6 concurrent Chromium conversions. Scans at concurrency 1–2 add at most 1–2. Low.
- **Memory**: 5g was sized for ~1.8g of Chromium working set + 1g tmpfs. One or two more ordinary
  page renders fit. A large-format or multi-page render per scan iteration is the unknown — the
  calendar export alone reached 835 MiB in dev (`digest-platform.md:192`).
- **CPU**: uncapped, so a render loop in a scan competes with user screenshots only for host CPU,
  which idles at ~90 %. Low.
- **Failure coupling**: a Gotenberg OOM kills whichever Chromium is rendering — possibly a user's
  export, caused by a scan. It also pages (`ContainerOOMKilled`, `HostOOMKill`).
- **Repeated renders**: an agent that renders, compares and re-renders multiplies the load by its
  iteration count. Cap iterations in the job.

### Options [S]

1. **Share, with guard rails** (recommended to start): scan-side render concurrency 1, a per-scan
   render budget, the same 3× busy retry the export uses, a longer client timeout than the web
   path's. Watch `wboost-gotenberg-1` working set for a week.
2. **Own instance** `gotenberg-scan` (same image, `mem_limit: 2g`, internal only) and
   `GOTENBERG_DSN` overridden on `scan-consumer`. Full isolation of user traffic; costs ~0.7 GiB
   resident with `--chromium-auto-start` (the existing one idles at 679 MiB,
   `digest-platform.md:176`) and 2 GiB of paper limit. Take it if scans render in a loop.
3. `--chromium-max-queue-size` is "the documented lever if real load ever approaches capacity"
   (`compose.yaml:311-314`) — it adds a way to fail for web traffic too; not for this.

---

## 6. Extra software in the image

### What the conventions say [M]

- **No rule on image size or build time** exists in the repo.
- **Supply chain**: stateful images digest-pinned (D58); stateless app-local images may float; the
  sidecar precedents are sha-tag pinned (`online-cv`) or `:latest` (bot-blocker,
  `apps/myspeedpuzzling/deploy.sh:50-53`). Third-party code with privileges is treated with
  suspicion (`docs/decisions.md:1542`), and the first container with Docker-socket access got its own
  decision (D59). Nothing forbids pip/npm packages in an app image.
- **ghcr visibility**: the executor cannot pull a private package (`docs/secrets.md:129-141`, D33
  `docs/decisions.md:1325-1334`). A **new** ghcr package starts private; `online-cv` was pulled once
  by hand to prove access before its first deploy (`docs/journal.md:834-835`). `wboost` itself is
  public.
- **Rollback images**: dangling images of the moving `main` tag are kept 14 days (§2).

### Consequences of growing the web image [I]

- The app image is built on `ghcr.io/thedevs-cz/php:8.5-wboost` (`wboost:Dockerfile:1`), which lives
  in another repo (`TheDevs-cz/docker`). Python 3.13 and node 26 are already in it
  (`digest-platform.md:239-240`).
- **Where the layer sits decides the disk cost.** Heavy dependencies in the base image are one
  shared layer under every `main` build. Installed in the app `Dockerfile` *after* `COPY . .` they
  are a new ~0.6–1 GB layer per deploy, each kept 14 days: at several deploys a day that is tens of
  GB. In the app `Dockerfile` *before* the `composer.json` copy they are cached as long as their
  manifest files do not change.
- Every container of the image carries the tools: `web` (public), `messenger-consumer`, the cron
  one-offs. More attack surface on the public container, slower pull on every deploy, longer CI.
- No conflict with a written rule. The cost is disk churn, pull time and exposure.

### Does a separate scanner image fit the house pattern better? [S]

Yes — it is the `online-cv` pattern almost exactly (§2), and it fits this case on five counts:

| Concern | Same image (variant A) | Separate `scanner` image (variant B) |
|---|---|---|
| Disk / pull | +0.6–1 GB on every app image unless it sits in the base | built only when the scanner changes; sha-pinned |
| Deploys | recreated on every app deploy → waits for the running scan | `up -d` is a no-op until the tag is bumped |
| Limits | one cgroup for PHP + OCR + node + agent; one OOM kills all | own `mem_limit` / `cpus` / thread pins per tool |
| Secrets | whole app `.env` next to the agent | `ANTHROPIC_API_KEY` only |
| Blast radius | agent subprocess sits on `internal` with db, password-less redis, minio, unauthenticated Gotenberg | own network, `read_only`, `cap_drop: ALL` |

Cost of B: a second image and workflow, an HTTP (or queue) contract between worker and sidecar, a
public ghcr package or the box pull credential, a `software.md` row. If the sidecar itself holds a
scan in memory, a sidecar restart still kills that scan — but that happens only on a tag bump, a
deliberate act.

A middle path if the Agent SDK is dropped: OCR + image tools only, installed in the **base** image,
variant A. Small, no second contract.

---

## 7. Risks and open questions for the owner

### Risks

1. **SIGTERM may not reach PHP** in the current command form (no `exec`,
   `apps/wboost/compose.yaml:128`). Verify on the box before trusting any grace period:
   `docker compose stop -t 60 messenger-consumer` during a long message and read the exit code
   (143 / 0 = drained, 137 = killed). The same question applies to subprocesses: a scan that is
   killed must not leave an orphan python/node process — and PHP must forward the stop to them.
2. **Box-wide deploy lock** held for up to the grace period (§4.2). wboost deploys on every push to
   `main`; other apps queue behind it.
3. **D31 conflict**: `cpus:` needs a decision entry; without one the next reader removes it or
   copies it.
4. **Prompt injection → network**: the scanned image is untrusted input. An agent with shell or
   fetch tools on the `internal` network can reach Postgres (password in the same `.env`), Redis
   (no password), MinIO and Gotenberg (which will fetch any URL it is given). Variant A puts it
   there by default.
5. **Old code on a new schema for minutes**, not seconds (§4.2).
6. **Gotenberg coupling**: a scan can fail a user's export (§5).
7. **Memory alerts on first run**: page cache of a 342 MB binary and the models counts toward the
   75 % warning (§2). Size the limit accordingly; do not answer the alert with `mem.alert`.
8. **Nothing watches the things that matter**: a hung worker (healthcheck disabled), a growing
   `scan` queue, Anthropic spend. The existing alerts cover OOM, restart loops, failed messages.
9. **Sum of memory limits** reaches ~122.8 of 125 GiB on paper with variant A. Real use is 26 GiB,
   but the next services have no paper headroom.
10. **Uploaded scan sources live in MinIO, which has no backup** (`docs/backlog.md:721-724`,
    `:1025-1027`) and is public-read by bucket policy (`docs/decisions.md:1303-1306`). Third-party
    designs uploaded for scanning would be reachable by URL unless stored under a non-guessable key
    or outside the public bucket.
11. **Logs go to Loki**: `-vv` on the consumer logs message handling; OCR text or prompts logged at
    that level leave the container.
12. **`ANTHROPIC_API_KEY` in production is undocumented** (§3): who created it, which workspace,
    which spend limit — unknown from the repo.

### Open questions

1. CPU: hard cap (`cpus: 2`, amend D31), soft priority (`cpu_shares`), or pinned thread pools only?
2. Is the Agent SDK sidecar wanted at all on the shared box (~1 GiB per concurrent scan, a 342 MB
   binary, tool access)? If yes: its own image and network (variant B)?
3. Deploy behaviour: is a wait of up to 6 minutes under the box-wide lock acceptable, or should the
   restart be idle-gated — or should scans simply be killed and refunded on deploy, with a short
   grace period?
4. The job's hard timeout — the grace period is derived from it (5 min scan → 360 s).
5. Concurrency 1 or 2 at launch? (2 = `scale: 2`, double the memory limit in variant B's sidecar,
   two stop waits per deploy.)
6. Shared Gotenberg with a render budget, or a second instance from day one?
7. Heavy dependencies in the base image (`TheDevs-cz/docker`), in the app `Dockerfile`, or in a
   scanner image? Who maintains the base image build?
8. Should `scan-consumer` get a real liveness signal (heartbeat file + healthcheck) so D26 holds for
   it, unlike the other workers?
9. Wanted alerts: queue depth of `scan`, oldest queued scan age, daily spend? (Backlog items in the
   infra repo, rules under `infra/observability/prometheus/rules/`.)
10. Where do scan uploads live — the public bucket, a private prefix, or a local volume with a
    retention cron (the `myspeedpuzzling` result-images pattern, D81 `docs/decisions.md:5344-5357`)?
11. Fix the missing `exec` on the existing `messenger-consumer` in the same change? (It affects
    today's calendar PDF exports on every deploy, if finding 3 holds.)

---

## Method note

Files read in `~/www/lily.srv`: `CLAUDE.md`; `apps/wboost/{compose.yaml,deploy.sh,cron.d/wboost}`;
`infra/webhook/deploy.sh`; `deploy/{reconcile.manifest,lib.sh,run-queue.sh,lily-deploy-runner.service}`;
`docs/decisions.md` (D2, D8, D9, D12, D26, D31, D32, D33, D52, D58 in full or in the cited ranges;
D62, D65, D70, D76 in part; headings of all 86); `docs/journal.md` (grep for wboost, gotenberg,
`mem.alert`, `cpus`, `online-cv`, consumer `exec`; entries of 2026-08-05, 2026-10-14, 2026-11-09);
`docs/secrets.md`; `docs/software.md` and `docs/backlog.md` by grep; `apps/README.md`;
`apps/.template/`; `apps/myspeedpuzzling/{compose.yaml,deploy.sh}`; `apps/fotbal-fm/deploy.sh`;
`apps/boliga-api/compose.yaml`; the four Prometheus rule files; `infra/docker/daemon.json`;
`infra/maintenance/{lily-image-prune.sh,lily-messenger-metrics.sh}`; `infra/crontab` by grep.
In this repo: `Dockerfile`, `.github/workflows/release.yml`, `.docker/on-startup.sh`,
`config/packages/cache.php`, `docs/ai-scan/digest-platform.md` §4–§8.

Not verified (would need the box): signal delivery to the consumer, how compose stops two replicas
of one service, real memory of the OCR / agent processes, the Infisical key list. No secret value
was read or printed.
