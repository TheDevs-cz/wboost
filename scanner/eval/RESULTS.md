# AI scan scanner — measurements (P2)

Measured on 2026-11-12 with the code of this directory (commits `5de927ea` … `5fea7491`; round 3 and
the parity runs ran on the last of them). **Everything below was produced by this scanner** — not by
a prototype — unless a row says "prototype". Verdicts on the M / E scales are **the builder's own**,
from opening the renders next to the inputs; they are not a designer's rating.

| What | Where it ran | How much |
|---|---|---|
| Proposed configuration (Opus 5.5 `high`, Agent SDK, mandatory pre-pass, caps, keep-best, Haiku pre-check) | macOS 27 / arm64, node 25, the owner's Claude plan (no API key), concurrency 1; app = a copy of the repo at `c0114c22` in its own container (FrankenPHP worker mode) with the dev database, MinIO and the shared Gotenberg | easy 7 + photo 3 + hard 12 inputs × **3 runs = 66 scans**, all `proposed` |
| Parity with the prototypes' engine (`claude -p` 2.1.322 driving the same scanner) | same | 6 hard inputs × 1 run |
| Pre-check alone | same | 37 pictures × 1 run |
| Engine facts | same machine, fake provider (no model) | 18 scenarios × SDK, 17 × Messages loop, 7 × `claude -p` |
| Resources per scan | native (all 66 + 6 scans) **and** a `linux/amd64` container (`node:24-bookworm-slim`, `--cpus 3`, memory limit set) on Docker Desktop for Apple silicon — x86 code under Rosetta, **not an x86 machine**; the model replaced by a replay of recorded tool calls | container: 3 × concurrency 1, 3 × concurrency 2 |

Computed cost = tokens × list prices of 2026-11-03 (`definition.json`); nothing was billed. The plan
requested the 1-hour cache lifetime for every run, so cache writes are priced at the 1-hour rate
($8 / MTok); the "5-minute basis" column re-prices them at $5 — what a key run pays by default and
the basis of the prototypes' easy-set figure. All 66 + 6 + 37 runs: ≈ $19 computed; the plan's
weekly window went from 3 % to 19 % (other agents used it too).

## 0. Step B — the service against the real app (2026-11-12)

Sections 1–8 below are step A (the scanner core through the file driver; 66 scans). This section is
step B: the same core as a **service** — `scanner serve` — against the app's real job core
(`/internal/scanner/*`), commits `ff549a63` and the one after it. Model runs on the owner's Claude
plan, concurrency 1, macOS 27 / arm64, node 25; the app = the local dev stack (web on :8099).
Scans were started through the app's own upload endpoint (`POST /project/{id}/ai-scan`, a logged-in
session — `dev/app-user.mjs`), never by writing rows. Everything below was run; nothing is estimated
unless it says so.

### 0.1 Eight designs end to end (`eval/e2e.mjs`)

Upload → `checking` → intake claim → normalise → pre-check → `queued` → build claim → pre-pass → model
loop → keep-best → font verification → report → `finish` → `ready`. What the app stored afterwards:

| Design (set) | scan | outcome | whole scan s | kept / candidates | stages the status showed | usage rows (app-priced) | engine's figure | ledger | fonts in the report |
|---|---|---|---|---|---|---|---|---|---|
| informace-s-fotkou (easy) | `01a1d12a` | ready | 41.6 | 1 / 1 | read → build | pre_check $0.0001 · builder $0.1042 | $0.123 | reserve −1, settle | 2 × measured_match (0.93, 0.86) |
| argumenty (easy) | `01a1d12b` | ready | 75.3 | 3 / 3 | read → build → refine | $0.0004 · $0.2774 | $0.352 | reserve, settle | 2 × measured_match, 1 × nearest_available (0.79 — wrong, see 0.3) |
| pripomenuti-photo (photo) | `01a1d12c` | ready | 50.7 | 2 / 2 | read → build | $0.0004 · $0.1542 | $0.196 | reserve, settle | 3 × measured_match (0.98, 0.91, 0.93) |
| vedeli-jste (easy) | `01a1d140` | ready | 57.7 | 3 / 3 | read → build → refine | $0.0004 · $0.1733 | $0.221 | reserve, settle | 2 × measured_match (0.94, 0.82) |
| A1-kandidat (hard) | `01a1d12d` | ready | 72.8 | 2 / 2 | read → build → finish | $0.0004 · $0.2131 | $0.269 | reserve, settle | 5 × nearest_available (≤ 0.82) |
| B3-letak-a4 (hard) | `01a1d12e` | ready | 120.9 | 3 / 3 | read → build → refine | $0.0004 · $0.4676 | $0.603 | reserve, settle | 5 × nearest_available (≤ 0.70) |
| B1-koncert (hard) | `01a1d130` | ready | 92.5 | 2 / 2 | read → build → refine | $0.0004 · $0.2560 | $0.315 | reserve, settle | 5 × nearest_available (≤ 0.36) |
| A3-terapie-a4 (hard, 2480 × 3508) | `01a1d13e` | ready | 98.9 | 2 / 2 | read → build → refine | $0.0001 · $0.2974 | $0.376 | reserve, settle | 4 × nearest_available (≤ 0.78) |

- Every scan: exactly two usage rows (`pre_check#1`, `builder#1`), both **closed**, no open row; the
  scan row's token and cost totals equal the sums of its usage rows (checked for all eight); the
  ledger holds one `reserve −1` and one `settle 0` per scan; stored: the proposal document, the
  report, the kept candidate's render (`candidate-N.webp`), the working copy and the preview.
- Whole scan 41–121 s (mean 76 s), from the upload to `ready` as the 2 s status poll saw it — the
  claim polls (≤ 5 s for the intake; the build lane is woken when an intake passes) and the heartbeat's
  stage delay included.
- **The app books less than the engine reports** (−18 … −22 %): the plan asks for 1-hour cache
  writes and the app prices every cache write at the 5-minute rate (`AiScanDefinition::costMicroUsd`
  reads one `cacheWriteTokens`). On a key the engine's default is the 5-minute cache and the two agree;
  the scanner sends the split (`cacheWrite5mTokens`, `cacheWrite1hTokens`) for the app to use — see 0.7.
- The first A3 scan through the app **failed** (`failed build`, 20 s, refunded): the app renders one
  picture per `faces` call and a ranking sheet of six 300-dpi lines in 15 faces came back above the
  raster loader's 40 Mpx limit. Fixed: ranking sheets are rendered at ≤ 64 px and every sheet is kept
  below 30 Mpx / 16 000 px by asking for fewer lines per call (`src/instruments/fonts.mjs`); the row
  above is the scan after the fix. Step A could not see this — the MCP driver renders small pages.
- Three `failed build` scans in a row (that bug, twice more while reproducing it) tripped the app's
  **circuit breaker**: mode `paused`, uploads refused `temporarily_unavailable` — as designed.

### 0.2 Unhappy paths, for real (`eval/unhappy.mjs`)

Each on a real scan with the real model, mid-build unless said otherwise. "App unreachable" = the TCP
path between the scanner and the app cut (a forwarder the script owns: connections refused, open ones
reset) — the shared dev container was not stopped, because another agent was working in it; for the
scanner that is an app that is down.

| Path | Observed | Spec (§5.2, §7) | |
|---|---|---|---|
| Scanner killed (`SIGKILL`) 6 s into the build | no orphan process (the run's child ends itself when its parent is gone; engine dead). 113 s later the user's status poll reaped the lost lease: `queued`, `restarted`; usage row `builder#1` closed `lease_lost` with the last heartbeat's figures ($0.0058); a new scanner claimed run 2 | lease lost after L = 120 s from the last beat; requeue once; usage closed with the last heartbeat's figures | ✔ |
| … killed again in run 2 | 116 s later `failed interrupted`; ledger `reserve −1`, `refund +1`; no open usage row | second lost lease → `failed interrupted`, refund | ✔ |
| App unreachable 30 s | 2 beats unacknowledged, no abort, calls retried; `ready` on run 1; `settle` | outage < R: the run survives | ✔ |
| App unreachable 120 s | 8 beats unacknowledged; **self-abort 105.003 s after the send time of the last acknowledged beat** (98 s after the cut); engine dead 2 s later; the app: `build_lease_lost` → `queued` (`restarted`); claimed again as run 2 the moment the app was back → `ready`; ledger: one `reserve`, one `settle` | abort at A = 105 s, lease ends at 120 s, restart, no double charge | ✔ — but run 1's spend during the outage was not recorded (row `builder#1 lease_lost $0`): the app was away for the closing beat. Now the closing beat is repeated for 60 s after a self-abort (commit after `ff549a63`) |
| Cancel during the check | `cancelled` at once, `refund`; the intake's next call got 409 and stopped before the pre-check (no model call); no build claim followed | rule 8 | ✔ |
| Cancel during the build | `cancelled` at once, **`settle`** (counted); the scanner learnt it from the next beat 7.1 s later, engine killed, closing beat; usage row closed `cancelled` with the figures up to the abort | rule 9: ≤ 15 s, counted | ✔ |
| Operating mode `off` mid-build | `failed interrupted` at the switch, `refund`; scanner aborted 7.0 s later (`mode_off`), closing beat; uploads refused `temporarily_unavailable`; no claim while off | rule 13a | ✔ |
| Upload the app refuses: SVG, 500 × 400 px | `unsupported_image` (HTTP 422, reason `type` / `small`) — no scan | header check in `web` | ✔ |
| Truncated JPEG with a whole header | accepted by the app; intake: `stopped unusable_input` in 8.5 s, `refund`, usage row closed (no model call) | cannot be normalised → stopped | ✔ |
| A blank page | pre-check: `stopped not_expected_kind` in 8.9 s, `refund`, Haiku usage $0.0004 | pre-check stop | ✔ |

Found on the way: two scanner processes whose log reader had gone away (their parent script died) spun in
an exception loop — every log line raised `EPIPE`, the handler logged it — and ignored SIGTERM. Fixed: a
log line that cannot be written is dropped, a broken stdout never reaches the exception handler, and
the shutdown always ends the process (`test/service.test.mjs`, the last test). They had claimed nothing.

A real refusal by the provider could not be provoked with these inputs; the path is covered against
the fault proxy with the real engine binary (`test/service.test.mjs`, `test/engine-faults.test.mjs`).

### 0.3 Font verification — both error rates, before and after (`eval/font-verify.mjs`)

Built: at the finish stage each text group's own source lines (≤ 2, the longest) are rendered by the
app **in the face and at the size the kept design uses** and compared with the source pixels word by
word (`verifyFaces`, `wordIou`); that score decides `measured_match` / `nearest_available`. Measured
on the 66 recorded scans of step A (the same 223 groups as the "before"), no model, 333 renders:

| | present font reported as a substitute (easy + photo, 68 groups) | absent font reported as a match (hard, 155 groups) |
|---|---|---|
| before: the ranking score ≥ 0.85 | **44 %** (30 / 68) | 0 % (0 / 155) |
| after, one threshold 0.80 | 18 % (12) | 5 % (8) |
| after, one threshold 0.85 | 26 % (18) | 0 % |
| **after, the rule in `definition.json`: ≥ 0.85, and ≥ 0.80 for a line of ≥ 12 glyphs** | **18 %** (12 / 68) | **0 %** (0 / 155) |

- 217 of 223 groups were verified (the rest have no usable source line and keep the ranking's verdict).
- Verified score, true face present: median 0.91, p10 0.73, min 0.68. Absent: median 0.53, p90 0.77,
  max 0.83 — on lines of ≥ 12 glyphs max 0.76, on shorter ones max 0.83. A near twin overlaps a few
  bold glyphs almost as well as the face itself, hence the higher bar for short lines. The margin to
  the bar is 0.02–0.04: thin. The fixed target (absent → substitute in 100 %) is kept; **the
  provisional target ≤ 10 % for present fonts is not met** (18 %).
- The 12 remaining errors are 4 lines, all Arial regular at 32–40 px with 20–33 glyphs (0.68–0.79):
  the template's renderer (Fabric canvas text) and the face sheet (HTML text) place thin strokes a
  fraction of a pixel apart. Comparing word by word instead of the whole line lifted these from
  0.70 to 0.79; a comparison that tolerates half a pixel on thin strokes is the next step.
- Through the real app (0.1): 9 of 10 groups with the true face reported `measured_match`; 19 of 19
  groups with the face absent reported `nearest_available`.
- Cost: 2–6 renders at the finish stage, 0.9–3.3 s (≤ 6 per scan by the definition).

### 0.4 Refusals — no silent model fallback

- **Finding confirmed and switched off.** By default the engine binary (2.1.322) re-runs a turn a
  safety classifier flagged: first once more on the same model, then on another one — Opus 5.5 →
  `claude-opus-4-8` for `cyber`, → Opus 5 for `bio` — and the session stays there. Documented:
  code.claude.com/docs/en/model-config ("Automatic model fallback") and env-vars:
  `CLAUDE_CODE_DISABLE_REFUSAL_FALLBACK` — "Set to 1 to turn off the automatic model switch when a
  safety classifier flags a request". The scanner sets it (`src/engine/sdk.mjs`).
- Measured against the fault proxy with the real binary: without the switch the requests go to
  `claude-opus-5-5`, `claude-opus-5-5`, `claude-opus-4-8`; with it `claude-opus-5-5`,
  `claude-opus-5-5` and the turn ends as a refusal (`model_refusal_no_fallback`) → the scan is
  `refused`. The one repeat on the same model stays (the switch for it, `CLAUDE_CODE_DISABLE_REFUSAL_RETRY`,
  exists in the binary but is not documented — not used).
- Should a harness ignore the switch: the `model_refusal_fallback` message stops the run at once, the
  outcome is a refusal, and the tokens the other model already used are in the usage under its own
  name, priced by its own row; a usage with more than one model is sent as `model: "mixed"` with the
  scanner's own sum, which the app books when its table does not know the model (tested).
- **`claude-opus-4-8` is a real, current model id** (Claude Opus 4.8, $5 / $25 per MTok; cache read
  $0.50, 5-minute write $6.25, 1-hour write $10) — not an artefact. Its row stays in `definition.json`.

### 0.5 The image

`ghcr.io/thedevs-cz/wboost-scanner:sha-<commit>` (also `main`, `latest`), linux/amd64 —
**447 MB to pull** (compressed), 1.08 GB unpacked: node_modules 411 MB (the engine binary 327 MB,
sharp), the OCR virtualenv 386 MB (onnxruntime, OpenCV headless, the models), Debian + node 273 MB.
Step A's image carried two engine binaries and an unpinned Python stack: 742 + 410 MB before the base.
Built and pushed by CI; its self-test (`scanner selftest`: sharp, OCR on a fixed picture, engine
binary, a HEIC upload, scratch) passes on the x86 runner with **no network and a read-only root**.

One whole scan in the image against the local app (`eval/container-run.sh`: `--memory 3g --cpus 3
--read-only --tmpfs /tmp --init`, the real service and protocol, the app's renders; Docker Desktop
on Apple silicon = x86 under Rosetta; the model's answers **replayed** from the recorded scan with
1.5 s per answer, because a container has no plan login — with `CLAUDE_CODE_OAUTH_TOKEN` or a key the
same script talks to the provider):

| Scan | outcome | whole scan s | cgroup `memory.peak` | idle peak (start) | CPU-s (emulated) | OOM kills | scratch after | `docker stop` |
|---|---|---|---|---|---|---|---|---|
| A3-terapie-a4 (2480 × 3508, the largest canvas of the sets) | ready | 74.8 | **1803 MB** (60 % of 3 g) | 438 MB | 57 | 0 | empty | < 1 s, exit 0 |
| B3-letak-a4 (1240 × 1754, 43 text lines) | ready | 76.6 | 876 MB | 243 MB | 56 | 0 | empty | < 1 s, exit 0 |

`memory.peak` counts page cache and the `/tmp` tmpfs (scratch, the engine's home). The A3 figure is
twice step A's 961 MB for the same picture: the service is two node processes (main + the run's
child), the intake runs in the same container, and the font verification holds more rasters.
**Reading for the limit:** one large build peaks at 1.8 GiB; an intake next to it adds up to 0.7 GiB
(normalise child 0.58 GiB measured in `test/hostile.test.mjs` on 36 Mpx, the pre-check engine 0.3) →
2.5 GiB worst case = 83 % of 3 g. The scanner starts in 3 g (its own rule), but the 70 % rule asks for
**`mem_limit: 4g` at concurrency 1** (63 %) until the box says otherwise (P4 repeats it on x86).

### 0.6 Tests and what they do not cover

`npm test`: 213 tests, ≈ 35 s, no model, no app; `npm run test:slow`: the outage rules at their real
times (85 s). Both run in CI on every push that touches `scanner/` (ubuntu, node 24, python 3.11, the
image's pinned OCR stack). The list of §10 P2 and where each is: README "Tests".

Not covered: a real provider refusal; real x86 hardware and the box's network (G2b); concurrency 2
with real model runs (two workers are tested with a scripted child, not measured for memory); the
Messages-loop engine on real inputs (needs a key); HEIC with a grid of tiles from a phone (the fixture
is a synthetic single-image HEIC); a soak of 100 scans (G2a item 11); the calendar adapter.

### 0.7 Found in the app while running against it (not changed — `src/` belongs to the app phases)

1. **`.env.dev` wins over `.env.local`** (Symfony loads `.env.local` before `.env.<env>`), so the
   documented "add your address to `SCAN_NETWORK_CIDR` in `.env.local`" has no effect in dev; the file
   that works is `.env.dev.local` (`docs/architecture/ai-scan.md` "Local use", the comment in `.env.dev`).
2. **Cache writes are priced at one rate.** `AiUsageFigures` reads `cacheWriteTokens` only and
   `costMicroUsd()` multiplies it by `cacheWrite`; 1-hour writes (`cacheWrite1h`, in the price table)
   are under-priced by 37 %. Needed: read `cacheWrite1hTokens` (the scanner sends it) and price it with
   `cacheWrite1h`. Matters when the engine asks for the 1-hour cache (the plan does; a key by default not).
3. **Usage of a run the app has replaced is dropped.** A beat or finish carrying the previous claim's
   lease token is refused before the usage is recorded (`ScannerApi::held()` compares with the latest
   hash only). After a self-abort the scanner now repeats its closing beat and holds its slot, which
   closes the gap for outages up to 165 s; beyond that a run's last spend is lost unless the app keeps
   the hash of the previous run for usage-only calls.
4. The contract fixture's app-side replay is owed (`test/contract/README.md`).
5. Minor: `lease_expires_at` stays set on a finished scan; the face "Arial Italique" is reported
   `italic: false` in a claim's context (project font data).

## 1. Headline

| Set (3 runs each) | proposed | unfinished | turns | candidates | wall s (mean, range; p95) | cost $ (mean, range; p95) | cost $, 5-min basis | text words | text box IoU | face exact | size agreement | "closely matching" texts (§4.10) | pictures P / R |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| easy (7 own exports) | 21 / 21 | 0 | 7.0 (5–8) | 2.6 | 54 (35–96); 85 | 0.196 (0.136–0.335); 0.283 | 0.156 | 1.00 | 0.93 | 1.00 | 1.00 | 0.94 | 0.94 / 0.63 |
| photo (3 exports over a photo) | 9 / 9 | 0 | 6.4 (5–8) | 2.0 | 45 (28–76); 76 | 0.148 (0.104–0.192); 0.192 | 0.117 | 1.00 | 0.90 | 1.00 | 1.00 | 0.89 | 1.00 / 0.89 |
| hard (10 designs + 2 degraded; fonts absent) | 36 / 36 | 0 | 9.8 (4–15) | 2.3 | 83 (32–154); 134 | 0.315 (0.147–0.577); 0.556 | 0.259 | 0.92 | 0.57 | 0.00 | 0.89 | 0.17 | 0.85 / 0.71 |

Wall clock is the whole scan: normalise (0.1–4.5 s) + pre-check (1.9–2.9 s) + pre-pass (3–29 s) +
model loop (20–122 s) + finish (< 2.1 s). The provider's share of the wall clock (the engine's API
time) is 67 % (easy), 58 % (photo), 78 % (hard).

### Against the prototype agent (one run per input, measuring optional, no candidate cap)

| | easy: prototype | easy: scanner | hard: prototype | hard: scanner |
|---|---|---|---|---|
| turns | 16.9 | **7.0** | 22.7 | **9.8** |
| rendered candidates | 2.9 | 2.6 | 3.4 (7 on one input) | **2.3** (never above 3) |
| wall s | 55 | 54 (whole scan incl. intake) | 95 (44–149) | **83** (32–154) |
| cost $ | 0.22 (5-min basis) | **0.156** (5-min basis) | 0.50 (1-hour basis) | **0.315** (1-hour basis) |
| text words / box IoU / size agreement | 1.00 / 0.92 / – | 1.00 / 0.93 / 1.00 | 0.86 / 0.57 / 0.89 | 0.92 / 0.57 / 0.89 |
| face exact | 1.00 | 1.00 | 0 of 128 | 0 (fonts absent by construction) |
| pictures P / R | 0.86 / 0.67 | 0.94 / 0.63 | 0.81 / 0.67 | 0.85 / 0.71 |
| finished alone | 7 / 7 | 21 / 21 | 24 / 24 | 36 / 36 |

**The pre-pass and the caps helped cost and turns and did not hurt the measured quality**: −29 %
(easy) and −37 % (hard) cost, less than half the turns, the same text and picture figures. Wall
clock moved less (−13 % on hard) because this round's renders were slower (1.3 s a render through
the copy of the app, 0.8–1.0 s in the prototype round) and a scan now includes the intake and
3–29 s of pre-pass. No run touched a cap: turns ≤ 15 of 40, cost ≤ $0.58 of $1.50, model loop
≤ 122 s of 300, pictures ≤ 6 of 18. The candidate cap bound in 26 of 66 runs (3 candidates used);
it cost nothing visible — corrections 2 and 3 move text by pixels.

### Verdicts (the builder's own; run 1 of every input, run 2 of eight)

M = matches the source (3 same at a glance · 2 small visible differences · 1 clearly different · 0 wrong);
E = editable as a designer would build it (3 as is · 2 < 2 min · 1 real rework · 0 unusable).

| Input | M | E | What is visibly off (run 1; run 2 where it differed) |
|---|---|---|---|
| informace-s-fotkou, vedeli-jste, otazka-carousel, argumenty, vite-ze-2-strana | 3 | 3 | — (argumenty: the dotted connectors are thin line shapes) |
| pripomenuti, mouzaika-s-textem | 3 | 2 | the stand-in "photo" of these exports is a flat silhouette: the scan rebuilt it from shapes instead of declaring a background picture place (an artefact of the easy set, see the photo set) |
| informace-s-fotkou-photo, pripomenuti-photo | 3 | 3 | — |
| mouzaika-s-textem-photo | 2 | 2 | the translucent tiles and grid lines over the photo were left inside the background place, not rebuilt as layers |
| A1-kandidat, C1 (JPEG 60) | 2 | 3 | ring text of the sticker straightened; the diagonal colour band is a rectangle. Run 2 made the whole sticker one picture place instead (same input, different layer type) |
| A2-cestovatelska | 2 | 3 | slanted date tab is a rectangle |
| A3-terapie-a4 | 2 | 3 | arrow-shaped date ribbon is a rectangle; substitute face slightly wider |
| A4-napsali | 2 | 3 | slab serif replaced by a sans (reported as a substitute) |
| A5-poukaz-dl | 3 | 3 | — |
| B1-koncert | **1** | 3 | run 1 set the condensed heavy headline in a thin face (Arial Narrow) — the right width, the wrong weight; run 2 chose a heavy face but the sticker text wrapped into three lines |
| B2-sleva | 2 | 3 | two-colour "-30 %" is one colour; rotated starburst is a picture place; strikethrough is a line |
| B3-letak-a4, C2 (720 px) | 2 | 3 | all 32 texts placed, serif → sans substitute, rotated card straightened |
| B4-citat | 2 | 3 | coloured words inside the quote are one colour (one fill field kept — the rule), vertical text set horizontally, both glows rebuilt |
| B5-otevreno | 2 | 3 | diagonal panel edge straight, outlined word filled, headline in a lighter face |

Run 1: M ≥ 2 on 21 of 22 inputs (M 3 on 10), E ≥ 2 on 22 of 22 (E 3 on 19), none at 0. On the
prototypes' four-step scale the hard set reads 9 "as is" / 3 "< 2 min" / 0 / 0 — the prototype
agent's own result (9 / 3 / 0 / 0, its author's verdicts). What the measurements cannot show and the
renders do: **the weakest point is the substitute face's weight** (B1, B5) — ranking by glyph shape
at a fitted width prefers a narrow light face over a wide heavy one when the original is condensed
and heavy; and **the same object changes layer type between runs** (A1's sticker), as in the
prototypes.

Run-to-run spread (mean range over an input's 3 runs): text box IoU 0.06, "closely matching" share
0.07, cost $0.06, wall 25 s.

### "Closely matching" texts (architecture §4.10), share of truth texts

| Set | all five | words | face right or reported as a substitute | size ± 5 % | colour ΔE ≤ 3 | box IoU ≥ 0.7 |
|---|---|---|---|---|---|---|
| easy | 0.94 | 1.00 | 1.00 | 1.00 | 1.00 | 0.94 |
| photo | 0.89 | 1.00 | 1.00 | 1.00 | 1.00 | 0.89 |
| hard | 0.17 | 0.92 | 0.91 | 0.39 | 0.81 | 0.40 |

On the hard set the five-property measure is dominated by size and box: with a substitute face the
size that keeps the line width is not the true size (median chosen / true 0.96), and the scorer's
estimated box follows the size. This measure is fair only where the font is in the project; the
"font present, other renderer" case still has no input (owner's designs, P6 gate).

## 2. Fonts: what "available" can and cannot tell

Scores of the face the kept design uses, per text group, from the scanner's own ranking (the app
renders, the scanner compares glyph coverage):

| | groups | score, low → high (sample) | reported `measured_match` at 0.85 |
|---|---|---|---|
| easy + photo — the true faces are in the project, same renderer | 68 | 0.38 … 0.75–0.87 … 0.95 | 38 (56 %) |
| hard — the true faces are absent | 155 (21 could not be ranked) | 0 … 0.43 … 0.82 | **0** |

- **An absent font was reported as a substitute in 100 % of cases** (0 of 155 groups "matched") —
  the target of §4.10. The model's own claim was never used.
- **A present font was wrongly reported as a substitute in 44 % of groups.** The two distributions
  overlap between 0.72 and 0.83 (a near twin such as Inter ↔ Arial or Red Hat Display ↔ Montserrat
  scores up to 0.83; a thin true face at a resampled size scores as low as 0.75), so no single
  threshold separates them. At 0.80 five absent faces would be called present.
- **Built in step B (§0.3):** the verification render — the group's own lines in the design's face
  at the design's size, compared with the source without resampling, word by word. The wrong
  "substitute" verdicts went from 44 % to 18 %; an absent font is still never called a match.
- X9 (metric fit vs pixel fit): the app-side `FitTextToWidth` was not available to this round; the
  pixel fit alone gives size agreement 1.00 on the easy sets (every text within ± 5 %).

## 3. Engine facts

Established with `eval/engine-facts.mjs`: each engine runs against `eval/fake-provider.mjs` (a local
stand-in for `/v1/messages`; the Agent SDK's real binary 2.1.322, the `claude -p` binary and the
Messages loop all point at it with a dummy key). No model, no cost. Real-model observations are
marked "plan run".

| Question | Agent SDK 0.3.322 (binary 2.1.322) | Messages loop (`@anthropic-ai/sdk` 1.8.0) |
|---|---|---|
| Does an aborted run yield usage? | **No result message** after `abortController.abort()` (the iterator throws "Claude Code process aborted by user"). Usage survives only because the scanner meters stream events itself (`includePartialMessages`): input, cache and the running output count of every request seen. Without stream events the per-message usage carried 62 of 2 513 output tokens (plan run) — a 20 % under-count of cost | usage of every finished request is in hand; a request cut mid-stream is lost |
| Exit codes | not exposed by the SDK; failures arrive as a thrown `Error("Claude Code returned an error result: …")` after a `result` with `is_error: true` (its `subtype` is still `success`; `terminal_reason` says `api_error`, `image_error`, `max_turns`, `completed`). The binary itself (`claude -p`): 0 success, 1 any error result (provider error, refusal, max turns), 143 after SIGTERM with no result line | n/a |
| Status / error detail | `api_error_status` on the result, `error_status` + a coarse word on `api_retry` messages and on the failing assistant message (`rate_limit`, `server_error`, `authentication_failed`, `invalid_request`, `model_not_found`, `unknown`). **`error.details.error_code` is not passed on**: a spend-cap 429 looks like any 429 (`rate_limit`), a 402 and a workspace-limit 400 arrive as `unknown`. The classifier therefore uses status + the message text ("spend limit", "usage limits") and the 1-token probe confirms | status, error `type` and `details.error_code` directly |
| Retries | `CLAUDE_CODE_MAX_RETRIES=4` is honoured for 429 / 500 / 401 (!), **but the waits cannot be pinned**: 0.5, 1.1, 2.4, 4.7 s (≈ 9 s in total, not 2 / 8 / 20 / 30 s); `retry-after` is honoured (3 s → 3 s). **529 stops after 2 retries** whatever the setting ("Repeated 529 Overloaded errors"). 402, 403, 400, 404 are not retried | exactly the definition: 4 retries after 2, 8, 20, 30 s; billing / auth / request errors are not retried |
| Stalled stream | `CLAUDE_BYTE_STREAM_IDLE_TIMEOUT_MS` works (10 s set → retried at 10.3 s, no `api_retry` message is emitted for it). `CLAUDE_STREAM_IDLE_TIMEOUT_MS` cannot go below 5 min, so the byte watchdog is the one pinned. `API_TIMEOUT_MS` bounds the wait for response headers only — "120 s for the whole request" cannot be pinned on this engine; the builder cap ends such a request | own watchdogs: no stream event for `streamIdleSeconds` → abort and retry; whole request > `requestTimeoutSeconds` → abort and retry (the SDK's `timeout` stops counting once the response starts) |
| Refusal | a `system` message `model_refusal_no_fallback` (with the category) + an assistant message with `error: invalid_request` + an error result. **By default the harness first re-ran the refused turn on `claude-opus-4-8` by itself** (usage shows both models). Decided: no silent fallback — switched off with `CLAUDE_CODE_DISABLE_REFUSAL_FALLBACK=1`, and detected and stopped should it happen anyway (§0.4) | `stop_reason: "refusal"` + `stop_details.category`; no fallback unless the definition asks for one |
| Longest silent turn (66 plan runs, effort `high`) | 6.1 s between a tool result and the first streamed block of the next turn (median 1 s); the model loop's longest turn sits inside a 122 s loop of 4–15 turns | — |
| Can in-process tools return images? | **Yes.** An in-process MCP server (`createSdkMcpServer`, raw JSON-Schema tools on its low-level server) returns `{type: "image", data, mimeType}` blocks and the model reads them (smoke test: a number drawn into a PNG was read back; every scan's candidate renders travel this way) | tool_result image blocks |
| Lock-down | `tools: []` leaves exactly the scan tools + the harness's own `StructuredOutput` (init message). No helper-model requests were seen (0 Haiku calls). With `CLAUDE_CONFIG_DIR` set to an empty directory the plan login is not found ("Not logged in · Please run /login", `authentication_failed`) — so the config directory is isolated only when a key is the credential | no harness at all |
| Request shape | `max_tokens` 100 000, adaptive thinking, the effort given, structured output through the `StructuredOutput` tool | `max_tokens` 32 000, adaptive thinking, `output_config.effort` + `format` |
| Plan limit | every run reports `rate_limit_event` (weekly window, utilisation); `status: rejected` is classified `billing_auth / plan_limit` and the evaluation stops | n/a |

**SDK vs Messages loop on real inputs: unmeasured.** The Messages API needs an API key (or plan API
credits through `ant auth`, which this machine does not have); the owner's rule for development is
the plan only. The loop is covered by unit tests with a fake client and by the fault scenarios above
against the fake provider — its request shape and error handling are exercised, its quality, cost
and time are not.

OCR is architecture-independent: the same pictures give byte-identical fragments (texts and boxes)
on darwin/arm64 and in the linux/amd64 image (2 pictures, 3 + 42 fragments).

### SDK vs `claude -p` on six hard inputs

| Input | SDK run 1 / run 2 / run 3 | `claude -p` |
|---|---|---|
| A1-kandidat | 11 turns, 2 cand., 98 s, $0.32, IoU 0.66 · 8 turns, 2 cand., 49 s, $0.21, IoU 0.81 · 10 turns, 2 cand., 57 s, $0.22, IoU 0.69 | 9 turns, 2 cand., 67 s, $0.24, IoU 0.78 |
| A3-terapie-a4 | 11 turns, 3 cand., 154 s, $0.42, IoU 0.68 · 9 turns, 2 cand., 90 s, $0.33, IoU 0.66 · 13 turns, 3 cand., 115 s, $0.39, IoU 0.66 | 14 turns, 3 cand., 103 s, $0.36, IoU 0.68 |
| A5-poukaz-dl | 8 turns, 2 cand., 66 s, $0.32, IoU 0.81 · 8 turns, 2 cand., 58 s, $0.19, IoU 0.77 · 7 turns, 2 cand., 54 s, $0.16, IoU 0.72 | 8 turns, 2 cand., 51 s, $0.17, IoU 0.75 |
| B1-koncert | 11 turns, 2 cand., 90 s, $0.34, IoU 0.55 · 11 turns, 3 cand., 95 s, $0.39, IoU 0.51 · 8 turns, 2 cand., 68 s, $0.22, IoU 0.45 | 11 turns, 3 cand., 97 s, $0.32, IoU 0.56 |
| B3-letak-a4 | 14 turns, 2 cand., 117 s, $0.56, IoU 0.36 · 4 turns, 2 cand., 134 s, $0.48, IoU 0.38 · 13 turns, 2 cand., 124 s, $0.54, IoU 0.36 | 4 turns, 2 cand., 95 s, $0.44, IoU 0.40 |
| B5-otevreno | 14 turns, 3 cand., 78 s, $0.31, IoU 0.37 · 10 turns, 2 cand., 79 s, $0.26, IoU 0.45 · 12 turns, 2 cand., 79 s, $0.26, IoU 0.40 | 13 turns, 2 cand., 84 s, $0.29, IoU 0.40 |

Means of the six: SDK (18 runs) vs `claude -p` (6 runs) — see the generated tables (`cli hard` vs the
same inputs of `sdk hard`). On every input the `claude -p` run lies inside the spread of the SDK
runs for turns, candidates, cost, time and text figures: **the two engines are at parity**, as
expected — the SDK starts the same binary. One difference: without stream events the longest wait
for the first model output of a turn reads 10–24 s on `claude -p`; with them (SDK) the stream is
never silent for more than 6 s.

## 4. Pre-check (Haiku 5.5, effort `low`, own request settings)

37 pictures: 26 positives (the 22 eval inputs, 4 calendar pages) and 11 negatives made from them
(`eval/precheck-eval.mjs`).

| Expected | n | right | wrong |
|---|---|---|---|
| pass | 26 | 26 | — |
| `photo_of_print` (a design on a desk, turned, uneven light, noise — synthetic) | 3 | 3 | — |
| `several_designs` | 2 | 1 | two posters side by side passed as one design |
| `blurry_or_small` | 2 | 1 | a 300 px JPEG-20 copy blown up passed (confidence `medium`) |
| `not_expected_kind` (blank, a page of text, an abstract illustration, a design sent as a calendar) | 4 | 3 | the abstract illustration passed as a design — a defensible reading |

No good upload was stopped (0 of 26). Time 2.2 s median, 2.8 s max (bound: 38 s); $0.0003 a call.
The negatives are few and synthetic; real photos of prints are owed.

## 5. Resources

**Native (darwin/arm64), all 66 scans**, sampled every 0.5 s over the scanner's process tree:

| | easy | photo | hard |
|---|---|---|---|
| peak RSS of the tree, MB | 837 (608–1003) | 946 (813–1035) | 1092 (770–1556) |
| average RSS, MB | 661 | 721 | 808 |
| engine binary: peak MB | 328 (303–345) | 333 | 337 (304–367) |
| scanner node process: peak MB | 439 (324–528) | 506 | 605 (459–808) |
| OCR sidecar: peak MB (lives ≈ 1–3 s) | up to 519 | up to 520 | up to 893 (2480 × 3508) |
| CPU-seconds per scan | 7.7 (4.5–14.2) | 7.1 | 9.7 (4.9–21.4) |
| peak cores | 1.8 (one sample 5.1) | 2.0 | 2.0 (max 4.9) |
| app renders per scan | 7.7 | 7.4 | 12.8 (≤ 3 candidates; the rest are face sheets of the local MCP driver — the app's `faces` call needs 1–3) |

**linux/amd64 container (Rosetta), replayed scans**, `--cpus 3`; cgroup `memory.peak` includes page cache:

| Run | concurrency | cgroup peak MB | tree RSS peak / average MB | node / engine / OCR peak MB | CPU-s (emulated) | peak cores |
|---|---|---|---|---|---|---|
| A1 (1080 × 1350) | 1 | 851 | 692 / 450 | 443 / 268 / 376 | 19 | 2.4 |
| A3 (2480 × 3508, the largest canvas of the sets) | 1 | **961** | 1010 / 676 | 735 / 286 / 654 | 37 | 2.7 |
| B3 (1240 × 1754, 43 text lines) | 1 | 756 | 797 / 606 | 505 / 292 / 439 | 37 | 3.0 |
| A3 + A3 | 2 | **1814** | 1001 + 1019 | 724 / 292 / 654 each | 72 | 3.0 (capped) |
| A3 + A3, OCR on 1 thread each | 2 | 1871 | 999 + 1030 | — | 68 | 3.0 |
| A1 + A3 (three large renders at once elsewhere on the machine) | 2 | 1276 | — | — | — | one run ended `renderer_unavailable` (see §7) |

Largest legal upload (35.4 Mpx JPEG → 2896 × 4096 working copy): normalise child 3.8 s native,
5.7 s in the container, peak RSS **393 MB**; OCR of that working copy 1.7 s, **790 MB**.
OCR output is identical on both architectures (same fragments, same boxes).

Reading for the container limit (rule: peak ≤ 70 % of the limit): one build peaks at ≈ 1.0 GiB
measured (≈ 1.2 GiB expected on a 4096 px working copy: node 0.45 + OCR 0.8 during the pre-pass);
the intake lane next to it adds a 0.4 GiB normalise child and a 0.3 GiB pre-check engine →
≈ 1.9 GiB worst case at concurrency 1 → **3 g** (the plan's estimate was 4.5 g); concurrency 2:
1.87 GiB measured for two large builds, ≈ 3.1 GiB worst case with the intake → **5 g** (estimate
7 g). `cpus: 3` holds: the tree used ≤ 3 cores except single samples, and pinning OCR to 1 thread
at concurrency 2 changed nothing measurable. That image was 742 MB of `node_modules` (two engine
binaries) + 410 MB of Python. **Superseded for the service by §0.5**: the production image, and a
whole scan in it peaking at 1.8 GiB → 4 g at concurrency 1.

## 6. Timing table (§4.9) against measured stage times

| Quantity of §4.9 | Bound | Measured (66 scans) | Margin |
|---|---|---|---|
| normalise | 20 s | 0.1–4.5 s; 5.7 s for the largest legal upload under emulation | ≥ 3.5× |
| pre-check | 38 s | 1.9–2.9 s | 13× |
| intake cap | 120 s | ≤ 7.4 s of work | 16× |
| pre-pass | W − builder = 60 s | 3–29 s with the local driver's face sheets (≤ 12 renders of 1.3 s); the app's `faces` call needs 1–3 renders | 2× locally |
| builder cap | 300 s | model loop 20–122 s | 2.5× |
| run cap W | 360 s | pre-pass + loop + finish ≤ 150 s | 2.4× |
| keep-best + report | "local, seconds" | 0–2.1 s (finish may rank a group: one render) | — |
| stream idle 60 s | — | longest wait for a turn's first output 6 s | 10× |
| model request 120 s | — | not observable through the SDK (only headers are bounded); no turn exceeded ≈ 60 s of the 122 s loop | — |
| turns 40 / $1.50 / images 18 | — | ≤ 15 / ≤ $0.58 / ≤ 6 | 2.6× / 2.6× / 3× |
| candidates 3 | — | 3 used in 26 of 66 runs | binds, by design |
| "Trvá to déle" at 180 s | — | whole scan ≤ 154 s | the line is never shown on these sets |
| tool timeouts 30 / 95 s | — | local tools < 0.2 s; app-backed tools 1.5–6 s, 60 s when the renderer was away | — |

Nothing in the table has to move. Two values could be lowered once production timings exist
(builder cap towards 200 s, image budget towards 10); both are left as they are.

## 7. Found while measuring

- **The Agent SDK cannot pin the retry delays, stops 529 early and hides `error_code`** (§3). The
  definition's `retryDelaysSeconds` is honoured only by the Messages loop.
- **The harness re-runs a refused turn on `claude-opus-4-8`** by default. Decided: forbidden;
  switched off and guarded in step B (§0.4).
- **A renderer that stays away** (three large renders at once on a shared Gotenberg) made one
  render exceed the 60 s tolerance; the run was first reported `failed build`, now
  `failed renderer_unavailable`.
- **OCR line merge** split a line when one fragment was boxed 1.6× as tall as its neighbours
  (found by CI on the runner's font); fixed, test on a fixed picture.
- **The local MCP driver is not the production path**: `preview_design` returns pictures ≤ 1200 px,
  so on canvases above that (A3, A5) the scanner measured upscaled renders; face sheets need many
  small pages. The app's `render` / `faces` endpoints return the renderer's bytes untouched.
- A plan-run reports usage per message with a stale output count; the scanner meters stream events
  instead (now equal to the engine's final figure on every run).

## 8. Provisional bars (mean of 3 runs minus the observed spread)

| Bar | easy / photo (font in the project) | hard (font absent) |
|---|---|---|
| scans finishing within the caps | ≥ 95 % (66 of 66) | same |
| text words right | ≥ 0.98 | ≥ 0.85 |
| text box IoU | ≥ 0.85 | ≥ 0.50 |
| "closely matching" texts | ≥ 0.85 | not a bar (see §1) |
| absent font reported as a substitute | — | 100 % |
| present font wrongly reported as a substitute | ≤ 20 % with the verification render (measured 18 %; before 44 %); target ≤ 10 % | — |
| pictures P / R | ≥ 0.85 / ≥ 0.55 | ≥ 0.75 / ≥ 0.60 |
| cost per scan, mean / p95 | ≤ $0.25 / ≤ $0.35 | ≤ $0.40 / ≤ $0.65 |
| whole scan, mean / p95 | ≤ 70 s / ≤ 100 s | ≤ 100 s / ≤ 160 s |
| M ≥ 2 / E ≥ 2 (builder's verdicts) | 100 % / 100 % | ≥ 90 % / 100 % |
| pre-check: good uploads stopped | 0 | 0 |

## Tables (generated by `node eval/report.mjs`)

### Per run

| label | input | run | outcome | kept / cands | turns | wall s | model s | cost $ | masked comp. | text exact | text IoU | face | size agr. | close | pict. P / R | unfinished |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| cli | A1-kandidat | 1 | proposed | 2 / 2 | 9 | 67 | 58 | 0.243 | 0.925 | 1.00 | 0.78 | 0.00 | 0.97 | 0.20 | 1.00 / 0.50 | no |
| cli | A3-terapie-a4 | 1 | proposed | 2 / 3 | 14 | 103 | 89 | 0.365 | 0.994 | 1.00 | 0.68 | 0.00 | 0.95 | 0.40 | 0.60 / 0.38 | no |
| cli | A5-poukaz-dl | 1 | proposed | 2 / 2 | 8 | 51 | 39 | 0.165 | 1.000 | 1.00 | 0.75 | 0.00 | 0.97 | 0.67 | 1.00 / 0.67 | no |
| cli | B1-koncert | 1 | proposed | 3 / 3 | 11 | 97 | 85 | 0.321 | 0.989 | 1.00 | 0.56 | 0.00 | 0.80 | 0.00 | 1.00 / 1.00 | no |
| cli | B3-letak-a4 | 1 | proposed | 2 / 2 | 4 | 95 | 85 | 0.445 | 0.898 | 0.94 | 0.40 | 0.00 | 0.91 | 0.03 | 1.00 / 1.00 | no |
| cli | B5-otevreno | 1 | proposed | 1 / 2 | 13 | 84 | 75 | 0.288 | 0.849 | 0.86 | 0.40 | 0.00 | 0.82 | 0.00 | 0.33 / 0.50 | no |
| sdk | argumenty | 1 | proposed | 3 / 3 | 8 | 96 | 79 | 0.335 | 0.989 | 1.00 | 0.94 | 1.00 | 1.00 | 1.00 | – / 0.00 | no |
| sdk | argumenty | 2 | proposed | 2 / 2 | 7 | 61 | 54 | 0.283 | 0.964 | 1.00 | 0.92 | 1.00 | 1.00 | 1.00 | – / 0.00 | no |
| sdk | argumenty | 3 | proposed | 2 / 2 | 7 | 63 | 56 | 0.257 | 0.985 | 1.00 | 0.93 | 1.00 | 1.00 | 1.00 | – / 0.00 | no |
| sdk | informace-s-fotkou | 1 | proposed | 1 / 1 | 8 | 55 | 37 | 0.157 | 0.999 | 1.00 | 0.99 | 1.00 | 1.00 | 1.00 | 1.00 / 0.67 | no |
| sdk | informace-s-fotkou | 2 | proposed | 2 / 2 | 6 | 39 | 32 | 0.170 | 0.994 | 1.00 | 0.98 | 1.00 | 1.00 | 1.00 | 0.50 / 0.33 | no |
| sdk | informace-s-fotkou | 3 | proposed | 2 / 2 | 6 | 36 | 29 | 0.136 | 0.994 | 1.00 | 0.99 | 1.00 | 1.00 | 1.00 | 0.50 / 0.33 | no |
| sdk | mouzaika-s-textem | 1 | proposed | 3 / 3 | 8 | 85 | 62 | 0.237 | 0.988 | 1.00 | 0.96 | 1.00 | 1.00 | 1.00 | 1.00 / 0.50 | no |
| sdk | mouzaika-s-textem | 2 | proposed | 3 / 3 | 8 | 58 | 51 | 0.237 | 0.994 | 1.00 | 0.97 | 1.00 | 1.00 | 1.00 | 1.00 / 0.50 | no |
| sdk | mouzaika-s-textem | 3 | proposed | 3 / 3 | 8 | 61 | 54 | 0.212 | 0.995 | 1.00 | 0.97 | 1.00 | 1.00 | 1.00 | 1.00 / 0.50 | no |
| sdk | otazka-carousel | 1 | proposed | 3 / 3 | 6 | 49 | 36 | 0.185 | 0.982 | 1.00 | 0.94 | 1.00 | 1.00 | 1.00 | 1.00 / 1.00 | no |
| sdk | otazka-carousel | 2 | proposed | 3 / 3 | 6 | 41 | 35 | 0.192 | 0.995 | 1.00 | 0.95 | 1.00 | 1.00 | 1.00 | 1.00 / 1.00 | no |
| sdk | otazka-carousel | 3 | proposed | 3 / 3 | 6 | 46 | 39 | 0.168 | 0.991 | 1.00 | 0.95 | 1.00 | 1.00 | 1.00 | 1.00 / 1.00 | no |
| sdk | pripomenuti | 1 | proposed | 2 / 2 | 7 | 59 | 39 | 0.182 | 0.988 | 1.00 | 0.82 | 1.00 | 1.00 | 0.75 | 1.00 / 0.50 | no |
| sdk | pripomenuti | 2 | proposed | 2 / 2 | 7 | 41 | 35 | 0.185 | 0.986 | 1.00 | 0.83 | 1.00 | 1.00 | 0.75 | 1.00 / 0.50 | no |
| sdk | pripomenuti | 3 | proposed | 2 / 2 | 7 | 58 | 52 | 0.197 | 0.989 | 1.00 | 0.84 | 1.00 | 1.00 | 0.75 | 1.00 / 0.50 | no |
| sdk | vedeli-jste | 1 | proposed | 3 / 3 | 7 | 61 | 41 | 0.150 | 0.961 | 1.00 | 0.99 | 1.00 | 1.00 | 1.00 | 1.00 / 1.00 | no |
| sdk | vedeli-jste | 2 | proposed | 3 / 3 | 5 | 37 | 30 | 0.168 | 0.984 | 1.00 | 0.74 | 1.00 | 1.00 | 0.50 | 1.00 / 1.00 | no |
| sdk | vedeli-jste | 3 | proposed | 3 / 3 | 7 | 40 | 34 | 0.155 | 0.936 | 1.00 | 0.99 | 1.00 | 1.00 | 1.00 | 1.00 / 1.00 | no |
| sdk | vite-ze-2-strana | 1 | proposed | 3 / 3 | 7 | 75 | 57 | 0.182 | 0.994 | 1.00 | 0.96 | 1.00 | 1.00 | 1.00 | 1.00 / 1.00 | no |
| sdk | vite-ze-2-strana | 2 | proposed | 3 / 3 | 8 | 38 | 31 | 0.183 | 0.992 | 1.00 | 0.96 | 1.00 | 1.00 | 1.00 | 1.00 / 1.00 | no |
| sdk | vite-ze-2-strana | 3 | proposed | 3 / 3 | 8 | 35 | 29 | 0.149 | 0.986 | 1.00 | 0.94 | 1.00 | 1.00 | 1.00 | 1.00 / 1.00 | no |
| sdk | informace-s-fotkou-photo | 1 | proposed | 2 / 3 | 8 | 76 | 47 | 0.192 | 0.994 | 1.00 | 0.96 | 1.00 | 1.00 | 1.00 | 1.00 / 0.67 | no |
| sdk | informace-s-fotkou-photo | 2 | proposed | 1 / 2 | 6 | 33 | 24 | 0.148 | 0.988 | 1.00 | 0.92 | 1.00 | 1.00 | 1.00 | 1.00 / 0.67 | no |
| sdk | informace-s-fotkou-photo | 3 | proposed | 2 / 2 | 5 | 28 | 20 | 0.104 | 0.991 | 1.00 | 0.95 | 1.00 | 1.00 | 1.00 | 1.00 / 0.67 | no |
| sdk | mouzaika-s-textem-photo | 1 | proposed | 1 / 1 | 5 | 51 | 25 | 0.109 | 0.997 | 1.00 | 0.94 | 1.00 | 1.00 | 1.00 | 1.00 / 1.00 | no |
| sdk | mouzaika-s-textem-photo | 2 | proposed | 2 / 2 | 6 | 29 | 21 | 0.129 | 0.997 | 1.00 | 0.96 | 1.00 | 1.00 | 1.00 | 1.00 / 1.00 | no |
| sdk | mouzaika-s-textem-photo | 3 | proposed | 1 / 1 | 6 | 37 | 28 | 0.107 | 0.997 | 1.00 | 0.94 | 1.00 | 1.00 | 1.00 | 1.00 / 1.00 | no |
| sdk | pripomenuti-photo | 1 | proposed | 2 / 2 | 7 | 66 | 43 | 0.184 | 0.962 | 1.00 | 0.76 | 1.00 | 1.00 | 0.50 | 1.00 / 1.00 | no |
| sdk | pripomenuti-photo | 2 | proposed | 2 / 2 | 7 | 37 | 30 | 0.169 | 0.993 | 1.00 | 0.82 | 1.00 | 1.00 | 0.75 | 1.00 / 1.00 | no |
| sdk | pripomenuti-photo | 3 | proposed | 3 / 3 | 8 | 49 | 42 | 0.189 | 0.992 | 1.00 | 0.83 | 1.00 | 1.00 | 0.75 | 1.00 / 1.00 | no |
| sdk | A1-kandidat | 1 | proposed | 2 / 2 | 11 | 98 | 75 | 0.323 | 0.932 | 1.00 | 0.66 | 0.00 | 0.93 | 0.20 | 1.00 / 0.50 | no |
| sdk | A1-kandidat | 2 | proposed | 2 / 2 | 8 | 49 | 41 | 0.212 | 0.958 | 1.00 | 0.81 | 0.00 | 0.96 | 0.20 | 1.00 / 0.75 | no |
| sdk | A1-kandidat | 3 | proposed | 2 / 2 | 10 | 57 | 49 | 0.215 | 0.955 | 1.00 | 0.69 | 0.00 | 0.97 | 0.20 | 1.00 / 0.75 | no |
| sdk | A2-cestovatelska | 1 | proposed | 2 / 2 | 6 | 68 | 44 | 0.209 | 0.971 | 0.89 | 0.63 | 0.00 | 0.80 | 0.00 | 1.00 / 0.29 | no |
| sdk | A2-cestovatelska | 2 | proposed | 2 / 2 | 7 | 49 | 39 | 0.216 | 0.971 | 0.89 | 0.61 | 0.00 | 0.79 | 0.00 | 1.00 / 0.29 | no |
| sdk | A2-cestovatelska | 3 | proposed | 2 / 2 | 8 | 54 | 46 | 0.201 | 0.975 | 0.89 | 0.66 | 0.00 | 0.79 | 0.22 | 1.00 / 0.29 | no |
| sdk | A3-terapie-a4 | 1 | proposed | 3 / 3 | 11 | 154 | 117 | 0.417 | 0.997 | 1.00 | 0.68 | 0.00 | 0.96 | 0.40 | 1.00 / 0.63 | no |
| sdk | A3-terapie-a4 | 2 | proposed | 2 / 2 | 9 | 90 | 75 | 0.331 | 0.994 | 1.00 | 0.66 | 0.00 | 0.96 | 0.50 | 1.00 / 0.63 | no |
| sdk | A3-terapie-a4 | 3 | proposed | 3 / 3 | 13 | 115 | 101 | 0.392 | 0.995 | 1.00 | 0.66 | 0.00 | 0.96 | 0.40 | 0.60 / 0.38 | no |
| sdk | A4-napsali | 1 | proposed | 2 / 2 | 6 | 42 | 25 | 0.149 | 0.999 | 1.00 | 0.86 | 0.00 | 0.91 | 0.00 | 0.67 / 0.50 | no |
| sdk | A4-napsali | 2 | proposed | 2 / 2 | 6 | 32 | 25 | 0.155 | 0.999 | 1.00 | 0.72 | 0.00 | 0.92 | 0.00 | 0.67 / 0.50 | no |
| sdk | A4-napsali | 3 | proposed | 3 / 3 | 5 | 42 | 35 | 0.147 | 0.995 | 1.00 | 0.72 | 0.00 | 0.92 | 0.00 | 0.67 / 0.50 | no |
| sdk | A5-poukaz-dl | 1 | proposed | 2 / 2 | 8 | 66 | 53 | 0.318 | 0.999 | 1.00 | 0.81 | 0.00 | 0.97 | 0.67 | 1.00 / 0.67 | no |
| sdk | A5-poukaz-dl | 2 | proposed | 2 / 2 | 8 | 58 | 46 | 0.187 | 1.000 | 1.00 | 0.77 | 0.00 | 0.96 | 0.67 | 1.00 / 0.67 | no |
| sdk | A5-poukaz-dl | 3 | proposed | 2 / 2 | 7 | 54 | 42 | 0.165 | 0.996 | 1.00 | 0.72 | 0.00 | 0.97 | 0.67 | 1.00 / 0.67 | no |
| sdk | B1-koncert | 1 | proposed | 2 / 2 | 11 | 90 | 76 | 0.339 | 0.985 | 1.00 | 0.55 | 0.00 | 0.90 | 0.17 | 0.50 / 0.50 | no |
| sdk | B1-koncert | 2 | proposed | 2 / 3 | 11 | 95 | 82 | 0.394 | 0.986 | 1.00 | 0.51 | 0.00 | 0.84 | 0.17 | 1.00 / 1.00 | no |
| sdk | B1-koncert | 3 | proposed | 2 / 2 | 8 | 68 | 56 | 0.220 | 0.990 | 1.00 | 0.45 | 0.00 | 0.86 | 0.00 | 1.00 / 1.00 | no |
| sdk | B2-sleva | 1 | proposed | 3 / 3 | 10 | 88 | 76 | 0.342 | 0.890 | 0.63 | 0.58 | 0.00 | 0.84 | 0.38 | 0.67 / 1.00 | no |
| sdk | B2-sleva | 2 | proposed | 3 / 3 | 12 | 92 | 80 | 0.321 | 0.886 | 0.63 | 0.59 | 0.00 | 0.84 | 0.38 | 0.67 / 1.00 | no |
| sdk | B2-sleva | 3 | proposed | 3 / 3 | 9 | 80 | 68 | 0.291 | 0.883 | 0.75 | 0.67 | 0.00 | 0.96 | 0.38 | 0.67 / 1.00 | no |
| sdk | B3-letak-a4 | 1 | proposed | 2 / 2 | 14 | 117 | 106 | 0.556 | 0.912 | 0.88 | 0.36 | 0.00 | 0.92 | 0.03 | 1.00 / 1.00 | no |
| sdk | B3-letak-a4 | 2 | proposed | 1 / 2 | 4 | 134 | 122 | 0.485 | 0.908 | 0.94 | 0.38 | 0.00 | 0.90 | 0.03 | 1.00 / 1.00 | no |
| sdk | B3-letak-a4 | 3 | proposed | 2 / 2 | 13 | 124 | 114 | 0.536 | 0.919 | 0.88 | 0.36 | 0.00 | 0.91 | 0.03 | 1.00 / 1.00 | no |
| sdk | B4-citat | 1 | proposed | 3 / 3 | 9 | 89 | 78 | 0.339 | 0.781 | 0.86 | 0.35 | 0.00 | 0.79 | 0.00 | 0.67 / 1.00 | no |
| sdk | B4-citat | 2 | proposed | 3 / 3 | 8 | 85 | 73 | 0.284 | 0.771 | 0.86 | 0.40 | 0.00 | 0.90 | 0.14 | 1.00 / 1.00 | no |
| sdk | B4-citat | 3 | proposed | 2 / 3 | 11 | 104 | 93 | 0.343 | 0.775 | 0.86 | 0.37 | 0.00 | 0.76 | 0.00 | 0.67 / 1.00 | no |
| sdk | B5-otevreno | 1 | proposed | 3 / 3 | 14 | 78 | 68 | 0.314 | 0.851 | 0.71 | 0.37 | 0.00 | 0.83 | 0.00 | 0.33 / 0.50 | no |
| sdk | B5-otevreno | 2 | proposed | 2 / 2 | 10 | 79 | 69 | 0.257 | 0.877 | 0.86 | 0.45 | 0.00 | 0.82 | 0.00 | 0.33 / 0.50 | no |
| sdk | B5-otevreno | 3 | proposed | 1 / 2 | 12 | 79 | 70 | 0.265 | 0.815 | 0.86 | 0.40 | 0.00 | 0.83 | 0.00 | 0.33 / 0.50 | no |
| sdk | C1-kandidat-jpeg60 | 1 | proposed | 2 / 2 | 9 | 57 | 49 | 0.245 | 0.918 | 1.00 | 0.64 | 0.00 | 0.95 | 0.00 | 1.00 / 0.50 | no |
| sdk | C1-kandidat-jpeg60 | 2 | proposed | 2 / 2 | 12 | 82 | 75 | 0.299 | 0.943 | 1.00 | 0.73 | 0.00 | 0.93 | 0.20 | 1.00 / 0.50 | no |
| sdk | C1-kandidat-jpeg60 | 3 | proposed | 2 / 2 | 9 | 70 | 62 | 0.246 | 0.920 | 1.00 | 0.64 | 0.00 | 0.94 | 0.00 | 1.00 / 0.50 | no |
| sdk | C2-letak-720 | 1 | proposed | 2 / 2 | 13 | 120 | 112 | 0.577 | 0.908 | 0.88 | 0.34 | 0.00 | 0.86 | 0.03 | 1.00 / 1.00 | no |
| sdk | C2-letak-720 | 2 | proposed | 2 / 2 | 14 | 122 | 115 | 0.548 | 0.906 | 0.88 | 0.34 | 0.00 | 0.85 | 0.03 | 1.00 / 1.00 | no |
| sdk | C2-letak-720 | 3 | proposed | 2 / 2 | 15 | 107 | 99 | 0.506 | 0.908 | 0.88 | 0.34 | 0.00 | 0.86 | 0.03 | 1.00 / 1.00 | no |

### Means per label and set

| label | set | proposed | unfinished | turns | candidates | wall s | p95 s | cost $ | p95 $ | cost $ (5-min cache basis) | k tokens | text exact | text IoU | face exact | size agr. | close texts | masked comp. | pictures P / R |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| cli | hard | 6 / 6 | 0 | 9.8 (4.0–14.0) | 2.3 (2.0–3.0) | 83 (51–103) | 103 | 0.304 (0.165–0.445) | 0.445 | 0.253 | 121 | 0.97 | 0.59 | 0.00 | 0.90 | 0.22 | 0.942 | 0.82 / 0.67 |
| sdk | easy | 21 / 21 | 0 | 7.0 (5.0–8.0) | 2.6 (1.0–3.0) | 54 (35–96) | 85 | 0.196 (0.136–0.335) | 0.283 | 0.156 | 94 | 1.00 | 0.93 | 1.00 | 1.00 | 0.94 | 0.985 | 0.94 / 0.63 |
| sdk | photo | 9 / 9 | 0 | 6.4 (5.0–8.0) | 2.0 (1.0–3.0) | 45 (28–76) | 76 | 0.148 (0.104–0.192) | 0.192 | 0.117 | 80 | 1.00 | 0.90 | 1.00 | 1.00 | 0.89 | 0.990 | 1.00 / 0.89 |
| sdk | hard | 36 / 36 | 0 | 9.8 (4.0–15.0) | 2.3 (2.0–3.0) | 83 (32–154) | 134 | 0.315 (0.147–0.577) | 0.556 | 0.259 | 124 | 0.92 | 0.57 | 0.00 | 0.89 | 0.17 | 0.930 | 0.85 / 0.71 |

### Stages and resources (proposed runs)

| label | set | normalise s | pre-check s | pre-pass s | model loop s | finish s | provider share of wall | longest silent turn s | peak RSS MB (tree) | avg RSS MB | engine peak MB | node peak MB | OCR peak MB | CPU-s | peak cores | app renders |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| cli | hard | 1.3 (0.3–4.1) | 2.3 (2.0–2.9) | 7.0 (5.4–8.3) | 72 (39–89) | 0.3 (0.0–0.7) | 80 % | 18 (10–24) | 1131 (661–1424) | 792 (540–929) | 340 (333–349) | 637 (341–885) | 536 (97–875) | 10.1 (6.7–16.4) | 2.0 (1.0–3.0) | 13.8 (10.0–17.0) |
| sdk | easy | 0.5 (0.1–0.9) | 2.1 (1.9–2.4) | 7.3 (3.1–19.4) | 44 (29–79) | 0.1 (0.0–0.6) | 67 % | 2 (1–5) | 837 (608–1003) | 661 (473–770) | 328 (303–345) | 439 (324–528) | 354 (62–519) | 7.7 (4.5–14.2) | 1.8 (0.7–5.1) | 7.7 (5.0–10.0) |
| sdk | photo | 1.0 (0.8–1.1) | 2.2 (2.0–2.4) | 10.0 (3.8–22.9) | 31 (20–47) | 0.0 (0.0–0.0) | 58 % | 1 (1–1) | 946 (813–1035) | 721 (614–795) | 333 (327–340) | 506 (458–551) | 417 (127–520) | 7.1 (5.3–9.6) | 2.0 (1.0–4.0) | 7.4 (7.0–8.0) |
| sdk | hard | 1.1 (0.3–4.5) | 2.2 (1.9–2.9) | 8.2 (3.8–28.6) | 71 (25–122) | 0.2 (0.0–2.1) | 78 % | 2 (1–6) | 1092 (770–1556) | 808 (671–997) | 337 (304–367) | 605 (459–808) | 516 (79–893) | 9.7 (4.9–21.4) | 2.0 (0.8–4.9) | 12.8 (5.0–17.0) |

### Pre-check

| label | set | verdicts | seconds | cost $ |
| --- | --- | --- | --- | --- |
| cli | hard | design 6 | 2.3 (2.0–2.9) | 0.0001 (0.0001–0.0002) |
| sdk | easy | design 21 | 2.1 (1.9–2.4) | 0.0003 (0.0001–0.0006) |
| sdk | photo | design 9 | 2.2 (2.0–2.4) | 0.0003 (0.0001–0.0006) |
| sdk | hard | design 36 | 2.2 (1.9–2.9) | 0.0003 (0.0001–0.0006) |

### Keep-best and fonts

| label | set | runs with > 1 candidate | model preferred ≠ kept | kept ≠ last | decided by text fit | font groups | matched | face score, matched (mean) | face score, not matched (mean) |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| cli | hard | 6 / 6 | 2 | 2 | 4 | 31 | 0 | – | 0.42 |
| sdk | easy | 20 / 21 | 0 | 0 | 0 | 47 | 26 | 0.91 | 0.68 |
| sdk | photo | 7 / 9 | 4 | 2 | 7 | 21 | 12 | 0.89 | 0.65 |
| sdk | hard | 36 / 36 | 4 | 4 | 18 | 155 | 0 | – | 0.43 |
