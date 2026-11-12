# AI sken — verification of revision 3 against the three reviews

2026-11-12. Checked: `architecture.md` (rev. 3, "A") and `ux.md` (rev. 3, "UX") against `review.md`
(R1), `review-reliability.md` (RR) and `review-ux.md` (RU). The dispositions appendix (A, Appendix A)
was used only to locate the claimed change; every verdict is from the design text itself. Code facts
were spot-checked in `/Users/janmikes/www/wboost` (at `371410e6`) and `/Users/janmikes/www/lily.srv`
(at `0ae761c`). Nothing was run, nothing else was edited, nothing committed.

Verdicts: **R** resolved · **P** partly (what is left) · **N** not resolved · **R+** resolved, but the
fix introduces a new problem.

| | R | P | N | R+ | Total |
|---|---|---|---|---|---|
| Blockers (11) | 7 | 1 | 0 | 3 | 11 |
| Majors (52) | 36 | 12 | 1 | 3 | 52 |
| All | 43 | 13 | 1 | 6 | 63 |

---

## 1. The 63 findings

### 1.1 `review.md` (3 blockers, 12 majors)

| Id | Finding | Verdict | Where checked | What is left / new problem |
|---|---|---|---|---|
| B1 | Recommended configuration never run | R | A status line, §1.2, §2.2 X1–X3, P0 | Approval is asked for direction + P0 only; every figure names its system. The effort level of the template agent is stated nowhere (only "calendar agent at `high`" in P0) |
| B2 | No real foreign design scanned | R | A P0 inputs (a)–(c), §4.10, X4 | Depends on the owner supplying the inputs (O1); the risk row says which claims stay unmeasured otherwise |
| B3 | Quality bar not defensible | R | A §4.10, P2 acceptance | Five-property definition, two blind scales, bars at mean − spread, hard IoU and run-to-run bars, no waiver. Left: the designer is still unnamed (O2); one bar is "fixed now" without data (§3 below) |
| M1 | Client material on the consumer subscription | **N** | A §6 "Development credential", §11, P0 "Credential" | The review asked for the opposite of what the text now says. The text records an owner decision (2026-11-12) that client examples run on the subscription. I cannot verify that decision anywhere but in this document. P0 adds more outside-made designs on the same credential; gate G1 covers "production" only, so nothing gates them |
| M2 | Simpler builders dismissed | P | A §3.1, P0 "Runs" | Hybrid and Messages loop are live alternatives with both sides' evidence. Left: Sonnet and the Messages loop run **once** per input (the review's point was that single runs are inside the noise); the Messages loop runs only on the self-made hard 12 |
| M3 | Calendars on the agent | R | A §1.1, §3.1, P0, O3, O7 | — |
| M4 | Font swap / re-fit has no mechanism | R | A §4.6, X9, P1 `FitTextToWidth` | Method stated, error measured in P0, fallback named. Code check: `FontMetricsLoader` / `TextMeasurer` exist (`src/Mcp/Design/Measure/`) and sum isolated glyph advances; the class comment says it ignores kerning / GPOS, and the scanner's source width is an ink width, not an advance width — X9 is a real question, not a formality |
| M5 | "Weak result" refund undefined | R | A §5.4 last paragraph, §4.7; UX S4 banner | No refund on a weak result; `unfinished` is information only |
| M6 | Scan sources guessable, bucket public | R | A §5.3, P4 | Private bucket + secret segment + controller-only access. Code check: one bucket `wboost` today (`oneup_flysystem.php:21`) |
| M7 | Legal gate too late; consent over-promises | R | A §6 gate G1, §4.10 last sentence; UX S2 | Left: P4's goal says "WBoost's own pictures until G1 passes" while the order line puts G1 before P4 |
| M8 | "Admins only" does not limit exposure | P | A §4.6 stand-in, P3 rollback, runtime `admin_only`; UX T1 | Stand-in restricted to `scan-api` renders; `admin_only` exists. Left: the live "Požádat o přístup" card now ships in P3 — the opposite of this finding, chosen after RU M21 — and that choice is **not** in the owner decisions (R1 listed it as missing) |
| M9 | Over-built for v1 | P | A §4.11 | As the appendix says: 5 tables, 1 credential; ledger kept by owner decision; +1 container, +1 runtime table. Left: §4.11 undercounts — `layoutOrigin` is a new calendar design key (design keys are columns / validated keys: `CalendarDesignRules::KEYS`), P5 has no migration row |
| M10 | Phase order verifies the risk last | R+ | A §10 order line, P3, P4, G2 | P0 first, PDF as input adapter, MCP with each wizard. New: the order "G1 + G2 → P4" cannot hold — G2 item 9 and the §6 scanner-API test need the box (P4), and P4's acceptance is "G2 passed". P3's acceptance needs P2's real scanner although they are drawn parallel |
| M11 | Placeholder flow misses steps | R | A §4.6, §4.7, §5.3 `held/`, S9; UX S4b | All seven gaps have text. See RU M10 for the background-place problem |
| M12 | Spend-limit responses handled as outages | R | A §7 class table, G1 | — |

### 1.2 `review-reliability.md` (4 blockers, 19 majors)

| Id | Finding | Verdict | Where checked | What is left / new problem |
|---|---|---|---|---|
| B1 | Lease extended only by events | R+ | A §4.9, §4.5 `beat`, §5.2 row 5 | Timer heartbeat 15 s; A = 90 s, L = 120 s; arithmetic holds. New: tool timeout 30 s is shorter than what a tool must outlast — one app render may take 25 s and "the scanner retries 3× with backoff" (§7), and every app call "is retried for 60 s" (R). A deploy cutover or a slow render becomes a tool error inside a paid run |
| B2 | Spend recorded only by `finish` | R | A §5.1 `ai_usage_event`, §5.4, P1 acceptance, G2 item 2 | Residual by design: up to 15 s of usage after the last heartbeat |
| B3 | Failures and cancels free and unlimited | R | A §5.4, §5.2 rows 12–13, §4.9; UX §2.2–2.3, §10 | `source_unscannable` is a refusal of `retry` only; a fresh `start` with the same picture is bounded by daily starts alone. Table columns in §5.4 are misaligned (retry bounds sit under "Real spend") |
| B4 | Pixel work in `web` | R | A §1.1, R8, R9, §4.5, §4.9, §6 "Upload validation", §8.1, G2 item 4 | Code checks: `web` `mem_limit: 1g`, `REPLICAS=1` (infra `compose.yaml:59`, `deploy.sh:12`); the renderer asks Gotenberg for the final format and does not decode in PHP (`TemplateVariantImageRenderer.php:629`). The scanner's own sizing breaks the 70 % rule (§2.2 C) |
| M1 | "Scanner absent" fires during long scans | R | A §4.9 S | — |
| M2 | Provider class keyed on the wrong signal | R | A §7, §5.1 runtime row, P0 engine facts | — |
| M3 | Retry numbers not the engine's | P | A §4.4, §4.9, §7 | Retry count and delays pinned; retry note exists. Left: request timeout and stream-idle watchdog are not pinned (the review named both). "≤ 60 s lost to provider retries per run" contradicts the "> 50 % of builder time lost" rule (§2.2 B). With 2 retries (10 s) any provider blip over ≈ 10 s fails every running scan |
| M4 | Image limits of the API | R | A §4.5 image rule, §4.9, P2 tests | — |
| M5 | State machine gaps | P | A §5.2 | Table, `try` / `auto_restart` / `run`, absorbing states are there. Left: (a) no rule for a lost **intake** lease — a `checking` scan whose scanner died has no transition but the 60-min timeout, and no attempt counter; (b) no transition for mode `off` on running scans (UX §9.4 gives it an outcome, A has none); (c) rule 12 sends "Zkusit přesto" to `checking`, where the same pre-check stops it again (UX says `queued`); (d) "calendar deleted → `discarded`" contradicts `accepted` being absorbing |
| M6 | Refund / settle idempotency | R | A §5.1 ledger, §5.2 preamble | — |
| M7 | Paid results thrown away | R | A §4.5 `render` / `finish`, §5.2 row 5 | Who retries the image copy "by the app" is not named (no worker, no rule in the reaper) |
| M8 | De-entitlement locks users out | R | A §6, §5.2 row 10; UX §9, F12 | — |
| M9 | Kill switch needs a deploy | P | A §5.1 runtime row, P4 rollback, G2 items 6, 12 | Runtime mode exists. Left: effect of `off` on running scans is only in UX; P4's rollback says rows are "failed and refunded by the reaper after Q" in the same sentence that stops the scanner — the reaper's only frequent caller — so it is the nightly purge, up to 24 h |
| M10 | Stuck work noticed only when read | P | A §4.9 reaper, §7 observability, §12 | As the appendix says. Left, beyond what it admits: "reads present stale states" is asserted in §12 but neither document defines how a `running` scan with an expired lease is shown (`waiting` applies to `checking` / `queued` only) |
| M11 | Scanner upgrade / SIGTERM | R | A §4.9 shutdown, §7 process rules, P2 tests, G2 item 8 | Infra check: `ContainerUnhealthy` and `ContainerRestartLoop` rules exist |
| M12 | Protocol skew | R | A §4.5, §7, P2 tests | `scanner_too_old` is missing from UX's `waiting` values (§2.1 no. 2) |
| M13 | One static secret | P | A §4.5, §6 network / secrets | As the appendix says: the Anthropic key stays in the app's `.env` (now also in `scan-api`), deferred to D87. `scan-api` also listens on the stack's `internal` network, so "only on the scan network" is not literally true |
| M14 | Poison input | P | A §5.2 row 3, §5.4, P0 (f), P2 tests | Left: only a *reported* deterministic crash is covered. An OOM or hard crash in intake has no rule (M5 a), and intake shares the container and memory limit with builder runs, so it can kill an unrelated running scan and consume its restart. How the intake "memory bound" is enforced is not stated |
| M15 | Gotenberg retries, session lock, coupling | P | A §4.6, §7, §8.2, §12 | One render per request, session released, serialised scanner renders. Left: the separate DSN setting the appendix cites "(§4.6, §7, §8.2)" is in none of those sections, in no P1 config file and in no P4 compose line — only a risk row says "own instance is a setting". The "database counter" of in-flight renders has no recovery rule when a request dies |
| M16 | Race tests under DAMA | R | A P1 fault-injection suite | Code check: `dama/doctrine-test-bundle` (`composer.json:98`) |
| M17 | Operational state in the cache | R | A §5.1 `ai_scan_runtime`, §5.4 | — |
| M18 | Entry points; calendar accept | R | A §6 table, §4.6 | Code check: `Calendar::$revision` exists; `SaveCalendar` carries no revision today, so the equality check is new code |
| M19 | Sentry / log noise | R | A §6 "Logs and Sentry", P1 functional tests | Code check: `sentry.php:27-34` as cited |

### 1.3 `review-ux.md` (4 blockers, 21 majors)

| Id | Finding | Verdict | Where checked | What is left / new problem |
|---|---|---|---|---|
| B1 | Failed / stopped / refused have no way out | R | A §5.2 rows 15–16; UX §2.1, T5, §8 | The 24 h rule for other members covers `failed` only |
| B2 | Queue text lies when nothing runs | R | A §5.1 estimate; UX S3, S5 F5r / F7q, §11 | F7q's text ("AI sken byl delší dobu mimo provoz") is also shown when the queue was merely long or a spend ceiling was reached |
| B3 | Calendar "one undo step" | R+ | A §4.6, §5.2 row 18; UX §6 "Applying" | Code facts hold: snapshot = `layout`, `captions`, `preset` (`store.js:178-181`); undo step only when a layout existed (`:489-507`); history is a 255-char sentence (`CalendarHistoryEntry.php:54-55`). New: (1) "Vrátit původní" within 30 days overwrites layout **and all features** with the stored snapshot — work done after applying is lost without a warning or a revision check; (2) accept requires the stored revision to equal the caller's, but a client's content save also bumps the revision, and there is no copy for that refusal |
| B4 | No visual design | **P** | UX header, §12; `ux-mock/` | Only the specification changed. `ux-mock/index.html` is the old file (11:40, before the reviews) and still shows "texty, obrázky i barvy", "Písma a barvy", "Logo sponzora je vyříznuté"; `shots-v2/` is empty. The redraw is no phase's deliverable. "Complete wireframes" overstates it: no drawing for the queued / waiting card, S5 variants, C2–C3, history, request dialog, or any phone layout |
| M1 | Review does not say the template is not created | R | UX S4 action bar, §7 | — |
| M2 | Expiry silent | P | UX S4, T5, §7, §8 | Date on review, card and e-mail. Left: no reminder (declined, stated); what happens to pictures uploaded in S4b when the proposal *expires* is not said (only discard is) |
| M3 | Photo compared against grey boxes | R | UX S3, S4, S4b, §12 | — |
| M4 | Font guess stated as fact | R | UX S4 font item; A §4.7 `guessSupported` | — |
| M5 | Texts correctable only when doubted | R | UX S4 "Vrstvy"; A §4.6; UX §10 `set_text` | — |
| M6 | Report and picture never together on a phone | R | UX principle 4, S4, S4b | — |
| M7 | Slider blocks scrolling | R | UX S4 viewer | — |
| M8 | Placeholder step is one-by-one | R | UX S4b; A §12 | Logo pre-fill rests on an unverified assumption that A lists. Code: `FileSource` has one case and `Project` holds only an icon path, so the logo is not a gallery file today |
| M9 | "Later" and primary do the same; pictures leak | R | UX S4b, T4; A §4.6, O11 | Closed for web; API / MCP is an owner decision (O11) |
| M10 | Fit mode; duplicate controls | R+ | UX S4b | New: a full-page background is "always `Mění se…`" and may be left `Bez ukázky`, but the compiler refuses `fillable: true` on a background without an asset (`DesignCompiler.php:422-426`). A says empty places get the stand-in; UX says such a place "needs no guard". Which one holds, and what an API export then shows, is undefined |
| M11 | Stage notes need parameters | R | A §4.5 `beat`, §5.1; UX S3 | Stage and note codes are listed only in UX |
| M12 | PDF: documents disagree | R+ | A §1.3, §4.3, O4; UX S1 | Both conditional on O4. New (if O4 = yes): the page picker sits in S2 with "a thumbnail made by the scanner in the checking step", which happens after S2 |
| M13 | Pre-check after the queue | R | A §4.2, §4.9 intake; UX §7, F3a | — |
| M14 | One scan per project | R+ | A §4.9 queue; UX §2.2, T1 | New: "1 running or checking + 3 waiting" cannot be met as written — a new upload enters `checking` at once, also while another scan of the project runs |
| M15 | Fixes after accept | R | A §4.6; UX T4 | — |
| M16 | "K dořešení" asks for clicks | R | UX S4 | — |
| M17 | Rescan sells a credit for a free fix | R | UX S4 action bar | — |
| M18 | Calendar scan switches features off | R | A §4.4, §4.7 `featuresOn`; UX C4 | — |
| M19 | Scan card as a radio | R | UX C1 | Code check: siblings are `role="radio"` calling `choosePreset` (`step2.js:70`) |
| M20 | Consent does not match | R | UX S2; A §6 | Draft until G1. "Lidé s přístupem do projektu" vs "read-only members see nothing" |
| M21 | "Real request" and "Premium" | P | UX T1, §9, §9.1; A O14 | As the appendix says (ruling): request ships with the wizard; Premium is described, price is O14. Conflicts with R1 M8 without an owner decision |

---

## 2. Cross-document consistency

The header of both documents says states, names, numbers and limits are identical. Extracted and
compared: states (11), transitions (18 rows vs UX §2.1 exits), `waiting` values, timing and limit
numbers (A §4.9 vs UX §2.2), credit rules (A §5.4 vs UX §2.3), MCP actions and fields (A §9 vs UX §10).

Agree: the 11 states; upload limits (10 000 000 bytes, 8000 px, 36 Mpx, 600 px); 5000 px template cap;
6 min / ≈ 12 min; 3-min line; queue 1 + 3 / 20 / 60 min; 2 correction rounds = 3 candidates; 30-day
retention; e-mail 2 min; monthly limit 10; daily starts 10 / 20; 2 retries; polling 2 s / 10 s; the
counting rules; the nine MCP actions.

### 2.1 Mismatches between `architecture.md` and `ux.md`

| # | Topic | `architecture.md` | `ux.md` |
|---|---|---|---|
| 1 | "Zkusit přesto" target | §5.2 row 12: `stopped → checking` | §2.1: `stopped → queued` |
| 2 | `waiting` values | §5.1: four, incl. `scanner_too_old` | §2.1, S3, §10: three |
| 3 | Start while the provider is blocked | §5.2 row 1 refuses for mode, limits, queues, scanner absent; §7 refuses *claims* only | S2, T1: start disabled |
| 4 | Mode `Vypnuto` on running scans | no transition in §5.2; P4 rollback says "stops running scans" | §9.4: fail as interrupted, not counted |
| 5 | When a picture is "unscannable" | §5.4: "not delivered twice … within 30 days" (reads as including cancels and pre-check stops), per project | §2.2, F9: "failed twice" |
| 6 | "Zkusit přesto" and the retry bound | §5.4: "counts as a retry" | §2.2 bounds only "Zkusit znovu"; `continue` returns no `retriesLeft` |
| 7 | Error and refusal codes | names `provider_unavailable`, `scanner_unavailable`, `timeout`, `build`, `interrupted`, `unusable_input`, `source_unscannable`; no code for the renderer failure; no list | F-numbers only; 11 MCP refusal codes that A does not list; none for "project paused after 3 refusals" or a spend ceiling |
| 8 | Stage and note codes | none (only `provider_retry`) | six stages, seven note codes |
| 9 | Pixel size check | §6: HEIC by bytes only in `web`, size checked by the scanner | S1: "by the server always" |
| 10 | Who sees a scan | §6: "the project's members"; use permission: editors | §9: editors; read-only members see nothing |
| 11 | Estimate freshness | §4.9: cached 15 s | S3: recomputed on every poll |
| 12 | Calendar features field | finish `featuresOn[]` | MCP `featuresOffered[]` / `features[]` |

### 2.2 Numbers that are internally inconsistent

| | Where | Problem |
|---|---|---|
| A | A §4.9 tool timeout 30 s vs render 20 / 25 s, §7 "retries 3× with backoff", R = 60 s | the timeout is shorter than one retry of the thing it wraps; the check line "tool timeout < L" tests the wrong bound |
| B | A §4.9 "≤ 60 s lost to provider retries per run" vs §7 "> 50 % of the builder time lost" | 50 % of 300 s is 150 s; if the first holds the second never fires. The first is not derivable either: 2 retries per request × up to 40 turns, request timeout not pinned |
| C | A §8.2 scanner limit 4 g for an estimate of 2.65 + 0.3 GiB, "sized so the measured p99 stays under 70 %" | 2.95 / 4 = 74 %; G2 item 4 demands peaks under 70 %. Same at concurrency 2 (4.85 / 6.5 = 75 %) |
| D | A §4.9 Q = 60 min vs "Q > queue × usual duration" | holds only at the 1.7-min mean. At the document's own upper usual duration (3 min) 20 waiting scans take 60 min; at the default p75 (150 s) 50 min plus the running scan and a 12-min restart. A healthy, busy queue then fails scans as `scanner_unavailable` |
| E | A §4.9 / UX §2.2 "1 running or checking + 3 waiting" | see RU M14; also unstated whether `checking` counts toward the global 20 |
| F | A §4.9 intake cap 60 s = R 60 s | one app outage consumes the whole intake cap; no rule follows (RR M5 a) |
| G | A §4.9 "deploy cutover of `scan-api` is seconds" | unverified; the same image's healthcheck has `start_period: 40s` and runs migrations on boot unless `SKIP_DATABASE_MIGRATIONS` is set, which the P4 compose list does not mention. Above 90 s every app deploy aborts running scans |
| H | A §4.9 "Retention … for every state … then `expired`" vs §5.2 row 16 | `accepted` and `discarded` never become `expired` |
| I | A §5.4 ceilings $10 a day with $1.50 reserved per running scan | the effective ceiling is $8.50; with the per-project $5, two projects use the whole day |
| J | A §10 order line vs G2 / P4 | see R1 M10 |

### 2.3 Presented as measured or fixed, not supported by `prototypes/*/results.md`

| Where | Claim | What the results say |
|---|---|---|
| A §4.10 "Fixed now: fonts absent are reported as substitutes 100 % (measured)" | reads as a measured 100 % | the flag was the model's word: Opus 86 of 90, Sonnet 42 of 55 and 8 of 8 wrongly "matched" (HR A5); the ranking score was under 0.75 for 120 of 128 texts (HR §5). The scanner-measured flag never ran |
| A §4.9 "usual 60–110 s", "20 × 1.7 min" | basis of the 3-min line and of Q | means are 55 s (easy) and 95 s (hard); neither range is in a results file |
| A §8.1 "≈ 78 % waiting on the provider — measured" | measured | derived from "API time ≈ 43 s" of 55 s on the easy set (T1 line 201) |
| A §1.2 "single call + measurement 0.493" | C3 | the 0.493 is "C3 + optimiser" (CAL line 21) |
| A §1.2 easy set "$0.22 (0.14–0.35)" with "55 s (37–72)" | one run | cost range is the summary row, time range is "run 2"; that row's cost range is 0.16–0.29 |

All other quoted prototype figures that were checked match (E1–E5, E6–E10, E12–E15, E17–E18).

### 2.4 Leftovers and appendix claims that the text does not carry

- `ux-mock/index.html`: old mock, contradicts the design (RU B4).
- "attempt" survives in A §6 (scanner API row) and §5.4 ("running attempts"); the unit is now `run`.
- `beat` has a `round?` field and UX also sends `round {n}` as a note.
- MCP `start` keeps `acknowledgeExternalProcessing: true` next to `consent_required`.
- Appendix RR M15: "own DSN setting (§4.6, §7, §8.2)" — not in those sections.
- Appendix R1 m20 / m21: "effort … stated consistently (§4.4, §4.9, §7)" — §4.9 has no effort row; no template effort value exists.
- Appendix R1 M10: "no parallel phase depends on another" — P3's acceptance needs P2.
- Appendix RU M2: "uploads' fate" — only for discard.
- Appendix RU B4: "complete wireframes".
- A P1 "Owns": `src/Command/…Command.php`; the repo uses `src/ConsoleCommands/*ConsoleCommand.php`.
- A S9 / P5: the fingerprint covers `assets/calendar/{model,generate,render,editor,headless}/*.js` and two templates (`CalendarModuleInliner.php:30, 80-90`), not `resources/calendar/model.mjs` — the claim holds for that file, but a new design key normally also touches `assets/calendar/model/`, which would change the fingerprint.

---

## 3. Open before the documents go to the owner

Blockers:

1. **RU B4** — no redrawn mock; decide who draws it and when, or say plainly that the owner approves text only. Remove or label the old mock.
2. **RR B1 (new)** — tool timeout against render retries and the 60-s app retry window.
3. **RU B3 (new)** — "Vrátit původní" overwriting later work; accept refused after a client's save.

State machine (one pass over A §5.2 and UX §2.1 fixes most of §2.1):

4. Lost intake lease / intake crash; mode `off` on running scans; "Zkusit přesto" target; `scanner_too_old` in UX; start while the provider is blocked; presentation of a `running` scan with a dead scanner.
5. The per-project queue rule, restated so that `checking` fits.

Numbers:

6. Provider-retry bound vs the 50 % rule; pin request and stream timeouts.
7. Scanner memory limit vs the 70 % rule.
8. Queue timeout vs a full queue.

Owner decisions that the text takes or hides:

9. **R1 M1** — client material on the consumer subscription: the document records a decision; the owner should confirm it in his own words, since the research says "do not" and P0 extends it.
10. When the live "Požádat o přístup" card ships (R1 M8 vs RU M21) — add to §11.
11. Single-run arms in P0 (Sonnet, Messages loop) — accept or add runs.

Smaller, but wrong as written:

12. Background place `Bez ukázky` vs the compiler; PDF page picker order; the Gotenberg DSN setting; `layoutOrigin` storage and P5 migration; "100 % (measured)" in §4.10; the order line G1 + G2 → P4; P4 rollback relying on a stopped scanner's reaper.
13. One shared table of error codes, refusal codes, stage and note codes — today no such list exists in either document.
