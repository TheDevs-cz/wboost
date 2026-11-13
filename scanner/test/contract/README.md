# Contract fixture — scanner ↔ app

`scanner-api.v1.json` holds 57 exchanges recorded on 2026-11-12 between the scanner's client
(`scanner/src/protocol.mjs`) and the **real** local app's `/internal/scanner/*` endpoints, walked
without a model on a synthetic picture: tick, claim (nothing / intake / build), source, artifact,
beat (live, older seq, after cancel, after finish), faces, render (candidate, same seq, rejected
design, past the cap), finish (every intake and build outcome, a repeat, a late one), release (intake,
build, after cancel), and the answers that end a run or refuse the caller (409 `stale_run` /
`lease_lost`, 410 `gone`, `protocol_too_old`, 404).

Nine more exchanges were appended on 2026-11-13 (`record-contract.mjs --append-calendar <calendarId>`,
which walks only this and leaves the template flows byte for byte): **a calendar scan** — the intake
claim of kind `calendar`, the build claim whose `context.calendar` carries the calendar, a beat, a
render from model `commands` (the answer adds `view`, `page`, `preset`, `layout`, `features`), a
command list the calendar model refuses (a switch OFF) and one with an unknown layout key (both
`candidate: null` with issues), and the finish with the calendar report. The app's replay draws that
candidate with its fake picture, so it needs no Gotenberg.

It holds no secret and no picture: scan ids are `<scan:N>`, lease tokens
`<lease:<scan>:<lane>:<run>>`, the bearer token `<SCANNER_TOKEN>`, pictures are byte counts, the
definition is file names with character counts.

## Scanner side (in CI: `test/contract.test.mjs`)

For every exchange the client must send exactly the recorded request and read the recorded answer
the way the scanner relies on. A change of `protocol.mjs` that alters a request shape fails here.

## App side — the replay the app's CI owes (architecture §10 P2: "replayed in the app's CI")

A functional test in the app (suggested: `tests/Controller/Internal/ScannerContractFixtureTest.php`)
reads this file and, per scan of the recording, creates a scan the way `ScannerApiTest` does, then sends
each recorded request in order with the placeholders replaced (`<scan:N>` → the test's scan id,
`<lease:…>` → the lease token of the claim answer it just got, byte bodies → any picture of that size,
`<SCANNER_TOKEN>` → the test token) and compares the answer with the recorded one:

- the HTTP status and `ok` / `code` / `status` / `applied` / `cancel` / `candidate` (null or a number) /
  `reason` must be equal;
- for every other property: the same set of keys and the same JSON type (ids, tokens, dates, sizes
  and pictures differ by nature).

Exchanges that need the outside world are driven by the test, in the recorded order: an upload
before each "claim intake → a job" (the steps are named `scan N: …`), a user cancel before
"scan 3: beat after the user cancelled".

**App N against scanner N − 1.** The file name carries the protocol version. When the app moves to
protocol N + 1 it keeps replaying `scanner-api.vN.json` until scanners speaking N are gone (the app
accepts N and N − 1); the scanner build that speaks N + 1 records `scanner-api.v(N+1).json` next to it.

## Recording it again

After a change of the contract (`docs/architecture/ai-scan.md` § "Scanner API"), with the local app
running, mode `on`, and **no** scanner service running:

```bash
cd scanner
WBOOST_LOGIN_FILE=<file with email=… / password=… of a local admin> node eval/record-contract.mjs
npm test            # the replay must pass against the new recording
```
