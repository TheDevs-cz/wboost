# AI sken — end-to-end scripts

Real-browser checks of the AI scan wizard ("Šablona z obrázku",
`docs/ai-scan/ux.md`, `docs/architecture/ai-scan.md` § "UI"): puppeteer-core
drives Chrome against the running dev stack, uploads, clicks and types like a
user and asserts on what the page shows (plus the read-only hook
`window.__aiScan` — the page's `ScanApp`). Not part of CI. The conventions are
those of `tests/e2e/calendar/` (one-time setup of puppeteer-core: its README).

**No model is called.** `lib/fake-scanner.cjs` plays the scanner over the real
scanner API (`/internal/scanner/*`) inside the runner's process, for the e2e
projects' scans only — a real scanner working beside it keeps its scans.

## Run

```bash
docker compose up -d                          # the app must answer on BASE
node tests/e2e/ai-scan/run.cjs                # every *.e2e.cjs
node tests/e2e/ai-scan/run.cjs flow           # only scripts whose file name contains "flow"
SHOTS=/tmp/ai-shots node tests/e2e/ai-scan/run.cjs    # + screenshots (h.shot) and one per failed check
HEADFUL=1 node tests/e2e/ai-scan/run.cjs flow         # watch it
```

One PASS / FAIL line per check; non-zero exit on any failure; every script gets
a final check "console is free of errors".

The stack must let this machine call the scanner API: its address in
`SCAN_NETWORK_CIDR` (`.env.dev.local`, see `scanner/README.md`). The runner
clears the dev asset cache first (`E2E_KEEP_CACHE=1` skips it), then asks for
every asset the importmap names, one after another, before a browser does —
a page that requests dozens of uncompiled modules at once right after a cache
clear used to get a 500 for some of them — and switches
the operating mode to `on` for everybody (`app:ai-scan:status --mode=on
--admin-only=0`) — it leaves it that way.

| Variable | Default | Meaning |
| --- | --- | --- |
| `BASE` | `http://localhost:8099` | app URL |
| `PUPPETEER_DIR` | `~/.cache/wboost-e2e` | directory containing `node_modules/puppeteer-core` |
| `CHROME` | newest `~/.cache/puppeteer/chrome/*`, then the installed Google Chrome | Chrome binary |
| `USER_EMAIL` / `USER_PASS` | `e2e-aiscan@test.cz` / `E2eAiScan123` | the entitled editor |
| `PROJECT_ID` | `0e2ea15c-0000-7000-8000-000000000001` | its project "E2E AI sken" |
| `MAILER` | `http://localhost:8027` | the mail catcher's API (the access request's mail) |
| `SCANNER_TOKEN` | the dev token of `.env.dev` | bearer of the scanner API |
| `E2E_NO_FIXTURE` | – | `1` = bring your own accounts and projects |
| `E2E_WEB_CONTAINER`, `E2E_DB` | – | run against an app container of your own (below) |
| `E2E_KEEP` | – | `1` = leave the scans and templates of the run in place |
| `SHOTS`, `HEADFUL` | – | screenshots directory; show the browser |

## Beside other work on the same machine

The scripts clear the dev cache, switch the operating mode and need the ONE
build slot (`max running 1`) for themselves — a real scanner or somebody
else's run on the same stack makes them fail by timeout. Give them an app of
their own: a second web container on the stack's network with the tree
mounted, its own `var/`, database, private bucket and scanner token —

```bash
docker run -d --name wboost-e2e-web --network wboost_default -p 8093:80 \
  -v "$PWD":/app -v wboost-e2e-var:/app/var \
  -v "$PWD/.docker/php/wboost.ini":/usr/local/etc/php/conf.d/zz-wboost.ini:ro \
  -e 'DATABASE_URL=postgresql://postgres:postgres@postgres:5432/wboost_e2e?serverVersion=16&charset=utf8' \
  -e S3_PRIVATE_BUCKET_NAME=wboost-e2e-private -e REDIS_CACHE_DSN=redis://redis:6379/7 -e XDEBUG_MODE=off \
  -e SCANNER_TOKEN=e2e-scanner-token-not-a-secret-000000 \
  -e 'SCAN_NETWORK_CIDR=172.31.250.0/24,127.0.0.1/32,::1/128,172.16.0.0/12,192.168.65.0/24' \
  -e UPLOADS_BASE_URL=http://localhost:19010 ghcr.io/thedevs-cz/php:8.5-wboost
docker compose exec -T postgres psql -U postgres -c 'CREATE DATABASE wboost_e2e'
docker exec wboost-e2e-web sh -c 'chmod -R 777 var && bin/console doctrine:migrations:migrate -n'
docker compose exec -T minio sh -c 'mc alias set local http://localhost:9000 wboost wboostminio && mc mb -p local/wboost-e2e-private'

BASE=http://localhost:8093 E2E_WEB_CONTAINER=wboost-e2e-web E2E_DB=wboost_e2e \
  SCANNER_TOKEN=e2e-scanner-token-not-a-secret-000000 node tests/e2e/ai-scan/run.cjs
```

(`UPLOADS_BASE_URL` = the stack's Minio port; the public bucket and the mail
catcher are shared, which is harmless.) After a new migration run the
`migrate` line again.

## Test data — never real data

`ensureFixture()` registers two plain users (neither is an admin, each sees
only its own project) and one administrator (`e2e-aiscan-admin@test.cz`, used
by `paths` only to set up a template group and a document, which need the
designer role): the editor of "E2E AI sken" (AI scan on, no monthly
limit) and the owner of "E2E AI sken — zamčeno" (AI scan off — the locked card
and the access request). `purge()` deletes the scans, their usage rows (the fake
model's cost would add up to the project's daily spend ceiling), templates and
access requests of those two projects before and after a run. Whichever script
starts the first scan uploads a font of this repository through the page (a
scan cannot start in a project without one) and leaves it there. Every script
runs alone as well (`run.cjs states`). The uploaded picture is drawn by the
browser at run time (`lib/poster.cjs`) — nothing binary is in the repository
and nobody's material is used.

## Scripts

| File | Covers |
| --- | --- |
| `flow.e2e.cjs` | S1 refusals → S2 facts, inline font upload, consent by keyboard, privacy dialog → S3 stages with the server's durations and notes / „Tyto texty jsme našli“ outlines / round / preview / live region → S4 opens by itself → the font picker (faces in their own typeface, keyboard), text, left-out thing, layers, compare → leave and return → S4b multi upload, kind, single upload, remove + undo → create → editor + toast → read-only page, `Ze skenu` |
| `paths.e2e.cjs` | every hidden file input opened by its button through the real file chooser (picture, fonts, several pictures, one place), custom width, „Přidat písmo…“ inside the font picker, the dialogs and the `⋯` menu by keyboard with the focus back on the opener, „Oříznout, aby místo vyplnil“, the gallery picker, the export question on the group and the document fill pages |
| `states.e2e.cjs` | service not working, cancel while waiting / running, pre-check stop + „Zkusit přesto“, „Naskenovat znovu“ (the same picture starts at once, another format goes through S2), discard a proposal, refused, failed + leave / return, retry until the picture is refused, „Zahodit“, phone layout, reduced motion |
| `around.e2e.cjs` | the locked card → request dialog (focus, Escape) → request sent + mail; no AI request on pages of a user without scans; the enabled card, the strip, the shell's ring → toast → count, no polling once nothing is in progress, history; export with an unfilled picture |

## Driving a scan by hand

```bash
node tests/e2e/ai-scan/lib/fake-scanner.cjs --project=<project-id> --beat-ms=2500            # every scan ends ready
node tests/e2e/ai-scan/lib/fake-scanner.cjs --project=<project-id> --scenario=stop --once   # photo of a print
```

Scenarios: `happy`, `unfinished`, `stop`, `blurry`, `nographic`, `several`,
`corrupt`, `fail`, `provider`, `timeout`, `refuse`, `hang`. Without `--project`
it takes EVERY waiting scan — never run it beside a real scanner.
