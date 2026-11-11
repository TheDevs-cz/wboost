# Kalendáře — end-to-end scripts

Real-browser checks of the calendar module: puppeteer-core drives Chrome against the running dev
stack, clicks and types like a user, saves, reloads and asserts through the read-only hook
`window.__calendar` (docs/calendar/architecture.md §D.4, §D.10, §F). Not part of CI.

## Run

```bash
docker compose up -d                           # the app must answer on BASE
node tests/e2e/calendar/run.cjs                # every *.e2e.cjs
node tests/e2e/calendar/run.cjs shell          # only scripts whose file name contains "shell"
SHOTS=/tmp/cal-shots node tests/e2e/calendar/run.cjs   # + screenshots (h.shot) and one per failed check
HEADFUL=1 node tests/e2e/calendar/run.cjs shell        # watch it
```

The runner prints one PASS / FAIL line per check and exits non-zero on any failure. Each script
also gets a final check "console is free of errors" (page errors and `console.error`).

## One-time setup

puppeteer-core lives **outside the repository** (the repo has no `package.json`):

```bash
mkdir -p ~/.cache/wboost-e2e && cd ~/.cache/wboost-e2e && npm init -y && npm i puppeteer-core
```

Chrome: the newest "Chrome for Testing" under `~/.cache/puppeteer/chrome/` is used when present
(`npx @puppeteer/browsers install chrome@stable --path ~/.cache/puppeteer`), otherwise the installed
Google Chrome.

## Environment

| Variable | Default | Meaning |
| --- | --- | --- |
| `BASE` | `http://localhost:8099` | app URL (`docker compose port web 80`) |
| `PUPPETEER_DIR` | `~/.cache/wboost-e2e` | directory containing `node_modules/puppeteer-core` |
| `CHROME` | newest `~/.cache/puppeteer/chrome/*`, then `/Applications/Google Chrome.app` | Chrome binary |
| `USER_EMAIL` / `USER_PASS` | `e2e-calendar@test.cz` / `E2eCalendar123` | the dedicated e2e account |
| `PROJECT_ID` | `0e2ecafe-0000-7000-8000-000000000001` | the dedicated e2e project |
| `E2E_NO_FIXTURE` | – | `1` = do not create or clean the fixture (bring your own account + project) |
| `SHOTS` | – | directory for screenshots |
| `HEADFUL` | – | `1` = show the browser |

## Test data — never real data

The dev database is usually a copy of production, so the scripts work only in their own project:

- `ensureFixture()` (run by `run.cjs`) registers the user `USER_EMAIL` through
  `bin/console app:user:register` (a plain user, not an admin — it sees no other project) and inserts
  the project `PROJECT_ID` „E2E Kalendáře“ owned by it, with the module Kalendáře switched on.
  Kalendáře is an admin-only preview module, so it is written straight into `project.modules`
  instead of going through „Objevte další moduly“.
- `purgeCalendars()` deletes every calendar of that project before and after a run; scripts also
  delete what they create through the UI.

Both use `docker compose exec` (services `web`, `postgres`). To remove the fixture completely:

```bash
docker compose exec -T postgres psql -U postgres -d wboost \
  -c "DELETE FROM project WHERE id = '0e2ecafe-0000-7000-8000-000000000001'" \
  -c "DELETE FROM \"user\" WHERE email = 'e2e-calendar@test.cz'"
```

## Writing a script

`tests/e2e/calendar/<name>.e2e.cjs` exports `{ name, checks: ['E1', …], run(ctx) }`;
`ctx = { browser, page, h, check }`. `page` is already logged in; `h` is `lib/harness.cjs`:

| Helper | Does |
| --- | --- |
| `createCalendar(page)` → id | Vytvořit kalendář through the UI; the page ends in step 1 |
| `openCalendar(page, id)`, `reload(page)`, `waitReady(page)` | navigation that waits for fonts + the first step (`window.__calendar.ready`) |
| `gotoStep(page, n)` | clicks the step and waits until its module is mounted (`.cal-ws[data-cal-shown-step]`) |
| `cal(page, fn, ...args)` | evaluates `fn(window.__calendar, ...args)`; shortcuts `state(page)`, `boxes(page)` |
| `seed(page, payload)` | POSTs a §C.5 payload to the save URL with the page's CSRF token, then reloads |
| `save(page)`, `waitStatus(page, status)` | Uložit and wait for „Vše uloženo“ and a quiet network |
| `dragMm(page, fromMm, toMm, { steps, alt, hold })` | real mouse drag between trim-relative mm points (`toClient`) |
| `dragHandle(page, handle, dxMm, dyMm, opts)` | drags `[data-handle]` of the selected element by mm |
| `expectBox(actual, expected, tolMm = 0.11)` | box comparison in mm |
| `answerDialog(page, choice)` | answers a `confirmDialog` / `choiceDialog` (`confirm`, `cancel`, `save`, `discard`, `stay`) |
| `waitToast(page, pattern)`, `toasts(page)` | kit toasts |
| `deleteCalendar(page, id)` | trash → confirmation → Smazat on the list |
| `newPage(browser, viewport)`, `login(page)` | a second logged-in page (e.g. a client and a designer side by side) |
| `shot(page, name)` | screenshot into `SHOTS` (no-op without it) |

Editor scripts share `lib/editor.cjs` (phase P4): `newCalendar` (UI → step 3, saved), `restore`
(seed a saved design back and reload), `allBoxes` / `assertBoxes` / `withBoxes` (every stored box
before and after), `selectLayer`, `clickMm`, `resize` (a handle of a Vrstvy-selected element),
`dragRow` (reorder in Vrstvy), `svgRectOf` / `assertRect` / `frameRect` / `handleCentre` (overlay
checked against the page SVG), `pressMod`, `leaveFreely`.

Rules: wrap every handoff check in `await check('E3 …', async () => { … })` so one failure does not
hide the rest; a data-changing scenario saves, reloads and asserts again; never use native
`confirm()` paths; Bootstrap modals fade for 300 ms — use `answerDialog` or wait before clicking in
them; the Symfony debug toolbar is hidden by `newPage` because it swallows clicks near the bottom.

A module that is still a P3 stub carries `data-cal-stub="<phase>"` — a script that needs the real
thing can detect that and skip.
