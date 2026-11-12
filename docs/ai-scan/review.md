# AI sken — independent review of `architecture.md` and `ux.md`

2026-11-12. Reviewer had no part in the proposal. Nothing under review was edited, no code changed,
nothing committed. "arch" = `architecture.md`, "ux" = `ux.md`, "HR" = `prototypes/hard-round/results.md`,
"T1" = `prototypes/t1-agent/results.md`, "T2" = `prototypes/t2-pipeline/results.md`,
"CAL" = `prototypes/calendar/results.md`, "RP" = `research-providers.md`, "DI" / "DC" / "DP" = digests.

Counts: **3 blockers, 12 majors, 22 minors.**

Short verdict: the code and infra facts the proposal cites are accurate (section "Checked and found
sound"). The weak part is the step from evidence to decision. The system proposed for approval is not
the system that was measured, nothing was measured on a real foreign design, and the quality bar is
drawn just under single-run results. The plan checks all three only in P3 and P8, after most of the
build. A one-week measurement round before approval would settle them.

---

## Blockers

### B1. The recommended configuration was never run; every number belongs to a different system

- **Where:** arch §1.1 (table), §1.2, §1.4 rows 1 and 3, §2.2 X1 / X2 / X3 / X8, §4.9, §12.3.
- **Evidence:**
  - All agent runs used `claude -p` 2.1.322, not the Agent SDK (T1 "What was run"; arch §1.4 row 3 says so).
  - "Measurements as input", per-group font ranking, the region score and the text-fit pass: "none was
    built or run" (HR §8, last-but-one bullet).
  - Arch §4.5 caps rendered candidates at 3. The measured hard runs used more: 3.4 on average, 4.4 on
    set B, 7 on B1 (HR T-B; HR §3 "It took B1 seven previews"). Set B is where the loop earned its
    +0.04. The verdicts 9 / 3 / 0 / 0 were given to uncapped runs.
  - `fonts[].matched` measured by the scanner, keep-best by the scanner, the Haiku pre-check: none exists
    (arch §12.3).
  - Calendars: the only agent runs were at effort `medium` (CAL "Measured vs assumed", line 261:
    "effort levels other than `medium`" not tested). Arch §4.9 sets `high` for both modules.
- **Why it matters:** the owner is asked to approve an architecture whose cost, time and quality figures
  (§1.2) come from a different engine, a different tool contract, different caps and, for calendars, a
  different effort level. The plan measures the real configuration in P3, after P1 and P2 have built
  ten tables, the protocol and the job tools.
- **Proposed change:** add a **P0 measurement round before approval of P1–P8**. It needs no app change:
  the prototype harness already drives the app over MCP. Run the proposed configuration (SDK, pre-pass
  in the first tool result, 3-candidate cap, scanner-side `matched` and keep-best, Opus `high` for both
  modules) on an API key, 3 runs per input, including the inputs of B2 below. Replace §1.2 with those
  numbers. Approve P1–P8 on that table.

### B2. No real foreign design was ever scanned; "closely match" and the font claim rest on self-rendered inputs

- **Where:** arch §1.2, §2.1 E1 / E3 / E8, §4.10, §12.3; O24.
- **Evidence:**
  - Easy set: "No original flat designs exist … every score measures round-tripping WBoost's own
    renderer, with fonts that are certainly in the project" (T1 "What was run").
  - Hard set A: five templates built in WBoost and re-exported locally (`examples/hard/README.md`
    "Set A"). Set B: HTML/CSS authored by the prototype author and rendered by headless Chrome, "closer
    to a stress test than to a sample" (HR §8). Set C: two degraded copies of A1 and B3, so the "12
    hard inputs" are 10 designs.
  - Font face "1.00" exists only where the source and the candidate were drawn by the same renderer
    with the same font file. On the hard set the fonts are absent by construction (0 of 128). There is
    **no input where the font is in the project and the picture came from another renderer**
    (Illustrator, Photoshop, Canva, a print PDF). That is the normal customer case: the brand fonts
    are in the project, the design was made elsewhere.
  - The proposed "font not available" rule is a glyph-overlap score below ≈ 0.75 (HR §5 item 1),
    calibrated on same-renderer (≈ 0.9) against absent-font inputs. Its behaviour on a present font
    with foreign kerning, tracking or hinting is unknown. It could report the customer's own brand font
    as missing.
  - Real calendar pages: 3, one with a hand-built reference (CAL §1).
- **Why it matters:** the owner's requirement is "texts as editable text with the right
  font/size/colour/position" on "a picture of an existing design". The one property users will judge
  first was only measured in the two cases that cannot occur together in production. Arch lists
  designers' originals under "must be re-measured before launch outside admins" (§4.10) and as P8 gate
  item 1, which is after everything is built.
- **Proposed change:** make O24 a precondition of approval, not of P8. Collect 15–20 real flat exports
  made outside WBoost (at least half with their true fonts uploaded to the scan project, some with
  letter-spacing) and 5 real calendar pages. Run them in P0. Report font face and "reported as missing"
  separately for "font present" and "font absent".

### B3. The quality bar is not defensible as defined

- **Where:** arch §4.10 pass-bar table; P3 acceptance; O24.
- **Evidence:**
  - Bars sit just under single-run measurements: text found hard ≥ 0.85 (now 0.86), placeholders hard
    P / R ≥ 0.80 / 0.65 (now 0.81 / 0.67), calendar type ≤ 0.35 pt (now 0.31), calendar categorical
    ≥ 98 % (now 98 %). HR §2 A2 reports picture precision / recall moving "by 0.3–0.5 between runs of
    the same design", so a bar 0.01 under one run is inside the noise.
  - "Now" is partly measured on something else: "100 % finish alone (48 / 48, 24 / 24)" are uncapped
    `claude -p` runs; font size "hard 0.89" is the all-set mean of a scorer-estimated figure (HR T-B).
  - Text box IoU has a bar for easy only. On hard it is 0.57 (0.48 on set B, HR T-B) and has no bar.
    Position is one of the four properties the owner named.
  - The deciding row is a 4-step verdict. The author's "usable as is" was given to B3, a Merriweather
    flyer returned in Montserrat throughout with a rotated card set straight (HR T-F), and is defined
    as "nothing to fix that the DSL could express" (HR §1). That is "as good as the tool allows", not
    "closely matches".
  - The scorer charges correct constructions (a split mixed-style line is "one hit + N extras", HR §9
    item 11). On set B the agent scores 0.74 text-exact against the pipeline's 0.88 while being judged
    better. A gate on that number steers the wrong way.
  - Calendar real pages: "within 0.05 of the hand-built page", but only Chotěbuz has one, the reference
    itself scores 0.626, and arch E16 says differences below 0.05 "mean nothing".
  - "Proposals the app accepts at finish: 100 %" is true by construction (arch §4.6).
  - P3 acceptance allows "or the deviation is written into this document and accepted".
- **Why it matters:** the owner asked for a defined, measurable bar. This one would pass or fail by
  run-to-run luck, and its human gate measures tool limits, not match.
- **Proposed change:**
  1. Define "closely match" in the owner's terms per text: right words, face (or reported substitute),
     size within ± 5 %, colour ΔE ≤ 3, box IoU ≥ 0.7. Report the share of texts meeting all five, per set.
  2. Split verdicts in two: "matches the source" and "editable as a designer would build it". A named
     designer rates blind, before P1.
  3. Set bars after the 3-run P0 round, at mean minus the observed spread.
  4. Add bars for hard text box IoU and for run-to-run agreement.
  5. Drop the tautological row. Fix the scorer's treatment of split lines before gating on text-exact.

---

## Majors

### M1. Client material was processed on the consumer subscription; the owner decision understates it

- **Where:** arch O3, §6 "Subscription for development", P3 ("runs locally on the subscription").
- **Evidence:** HR header: 36 scans "all on the owner's subscription". Set A is "production data of
  five client projects" including an election candidate's portrait (`examples/hard/README.md`). RP §4
  table: "Using real customer designs as test inputs while on the subscription: **Do not**" (consumer
  data terms, not the DPA). The legal page read today says OAuth is for "ordinary use of Claude Code"
  and developers building products "should use API key authentication"
  (<https://code.claude.com/docs/en/legal-and-compliance>). Arch O3 words this as "current practice,
  terms unclear".
- **Why it matters:** the research's own verdict is softened in the decision the owner reads, and B2
  asks for more client material.
- **Proposed change:** state O3 as: client material only on an API key, starting now. Re-run set A on
  the key in P0. If the owner is on Max, use the plan's monthly API credits through a linked Console
  key (RP §4 "cleanest route"), which also satisfies "subscription for local development".

### M2. The simpler builders are dismissed against the prototypes' own recommendation

- **Where:** arch §3 R2 / R3 / R4, §3.1, O1, O2.
- **Evidence:**
  - HR §7 recommends the opposite of R3: "**A hybrid … pipeline first, agent (Opus) when the pipeline's
    own checks fire**". Arch quotes HR §7 for the trigger rate but never says its author recommended
    the hybrid. The 10 / 12 trigger rate is on a set that is, by its author's word, a stress test. On
    the easy set the two "tied at $0.22 vs $0.05".
  - Sonnet agent: 6 / 6 / 0 / 0 at $0.21 against Opus 9 / 3 / 0 / 0 at $0.50, one run each, where "one
    verdict step on a single input is within that variance" (HR §8).
  - R4 (Messages loop in PHP) is "technically sufficient", rejected "only because it cannot run on the
    owner's subscription". That one premise carries the 342 MB engine, the second image and CI job, the
    claim / lease protocol and the job token. M1 shows the premise has an API-key answer.
- **Why it matters:** the most expensive structural choice rests on a reading of one requirement, and
  the cost choice on a stress set and single runs.
- **Proposed change:** in P0 run pipeline, agent-Sonnet-with-pre-pass and agent-Opus on the real inputs
  of B2, three runs each. Ask the owner whether "subscription for local development" is met by plan API
  credits. If yes, re-weigh R4 with the instruments as a small sidecar.

### M3. Calendars on the agent rest on one page, one run, the wrong effort and a prototype schema limit

- **Where:** arch §1.4 row 4, §4.9, P7, O1, O18.
- **Evidence:**
  - On 12 synthetic pages the single call is exact on structure at $0.047 and 13 s, and code
    measurement beats the agent on type (0.18 against 0.31 pt) (CAL §1).
  - The agent's win is Chotěbuz only (0.684 against 0.493). CAL §2 item 4 attributes it to "decisions
    the fixed C1 schema does not offer". That schema was cut down by the 75-property grammar limit and
    is "a limit of this prototype's schema, the app can" (CAL qualitative notes).
  - Arch §4.9 cites "[CAL §2]" for "`medium` failed". That failure was Sonnet. Opus at `medium` is the
    configuration that produced the calendar numbers. Opus `high` plus mm instruments plus
    unsaved-document render was never run (arch §12.3).
  - RP's recommendation: "Calendars: start with a single structured-output call plus one
    render-and-verify pass".
- **Proposed change:** take calendars out of the O1 decision. Decide P7's builder from a P0 calendar
  round on at least 5 real pages: single call with the full vocabulary as tagged lists plus measurement,
  against agent at `high`.

### M4. The review's main fix, font swap and re-fit, has no mechanism

- **Where:** ux S4 "Písma" row ("choosing re-fits and re-renders in ~2 s", "Nahrát {rodina}",
  "Zmenšit písmo, aby se vešel"); arch §4.6 "Review fixes", P4 `FontRefit.php`, O25.
- **Evidence:** fitting a size to the measured line width is a scanner instrument in node (arch §4.5
  `font_rank`, P3 `instruments/font-rank.mjs`). The scanner only pulls jobs and cannot be called by the
  app. Arch says fixes are "applied … by the app" and lists a PHP `FontRefit` with no description. HR §5:
  "none of it exists in either prototype". Arch §12.3 lists the missing-font path as untested.
- **Why it matters:** missing fonts are the top quality risk the document names. The mitigation it
  shows the user is unspecified and would duplicate measuring code in PHP.
- **Proposed change:** specify one of two. (a) The scanner stores, per text, the source line widths and
  a fitted size for every project face, so a swap is a lookup; a newly uploaded font becomes a small
  "refit" job for the scanner. (b) A PHP fit with a stated method and a measured error. Add it to P0.

### M5. "Weak result" decides a refund and has no definition

- **Where:** ux S4 banner, F9, Q9 ("driven by the same metric"); arch §5.2 last row, §7, O6.
- **Evidence:** arch sets `confidence: low` only when a cap was hit. Otherwise `confidence` is the
  model's word; HR §9 item 7 shows Sonnet reporting `medium` without measuring. The image metric is
  blind on photo-backed inputs (arch E13) and the replacement score is unbuilt (X8).
- **Proposed change:** define the flag from scanner-side measurements (share of OCR lines without a
  matching text, text-region score, candidate rejections) and validate it against the designer verdicts
  in P0. Until then offer "Zahodit — nezapočítá se" on every result and drop the banner.

### M6. Scan sources: the "unguessable key" option is not unguessable, and the bucket is public

- **Where:** arch §5.3, O11 option 2; ux consent dialog.
- **Evidence:** key = `ai-scan/{projectId}/{scanId}/source.{ext}`. Both ids are in the page URL
  (ux §2.2 route `…/project/{id}/sablony/z-obrazku/{scanId}`), in the e-mail link and in browser
  history. The bucket is public-read (DI §7 risk 10). Anyone who has seen the scan URL can fetch the
  source from `img.wboost.cz` without a login, including after losing access.
- **Proposed change:** remove option 2. Either take the prefix out of the public policy (make it a P2
  requirement, verified on the box) or add a random secret segment that never appears in any URL. The
  same applies to proposal renders.

### M7. The legal gate sits at P8, but client designs go to the provider from P6; the consent copy promises more than the system does

- **Where:** arch P6 goal, P8 gate items 4–5, O12, §12.2 "feedback thumbs feed the suite"; ux S2 dialog.
- **Evidence:** "admins scan in production" (P6) means WBoost staff scanning customers' calendars and
  posters, which is the realistic phase-1 use. O12 blocks only "non-admin use". The consent copy says
  "Slouží jen k rozpoznání grafiky" and "Nikam jinam", while arch plans to use rated scans for the
  evaluation suite and the provider keeps flagged content up to 2 years (arch §6).
- **Proposed change:** move the sub-processor notice and the wording review in front of P6 for any
  picture that is not WBoost's own. Either drop the reuse of uploads for evaluation or add it to the
  consent text as an explicit opt-in.

### M8. "Admins only" does not limit the exposure of what ships before the gate

- **Where:** arch P1, P2 (S5), P4 (`create_template`, S10), P5 (toast extraction), P7 (S9), P8 rollout.
- **Evidence:**
  - S5 changes how every existing template with an empty image slot renders, on the fill page, in
    exports and over the REST API. Arch says "`docs/api/consumer-prompt.md`: no change" (P4) although
    the mfkfm consumer's exports change.
  - `create_template` goes live for every MCP token with `templates:design`. The toast extraction
    touches the calendar and the palette page for all users.
  - P1 replaces "Brzy" with a live "Požádat o přístup" for every editor months before the gate and
    promises "Ozveme se vám".
  - Nothing technical enforces the gate: once `AI_SCAN_ENABLED` is on (P6), an admin can entitle any
    project.
- **Proposed change:** list these as separate, individually revertable changes with their own
  acceptance. Document S5 for API consumers or restrict the stand-in to proposal renders. Keep "Brzy"
  until P6 passes. Add an `AI_SCAN_ADMIN_ONLY` switch that the launch gate turns off.

### M9. Over-built for v1

- **Where:** arch §4.5, §5.1, §10.
- **Evidence (count):** 10 new tables; 1 new container, image, CI workflow and registry package;
  2 credentials and 2 authenticators; 4 internal endpoints plus 4 job-bound MCP tools plus 9 agent
  tools; 2 user MCP tools and a new scope; 4 console commands and 2 crons; a generic notification
  system; a history page, a usage tab with chart and CSV, thumbs with reasons; 8 phases, 7 follow-ups,
  28 owner decisions. Phase 1 has no limits and no billing.
- **What the requirements do not need for v1:**
  - Job-bound tools on `/_mcp`. Arch §4.5 admits "a plain endpoint would be equal". Folding them into
    `/internal/scanner/{id}/…` removes the job token, the second authenticator, the registry filtering
    and the `allowed_hosts` change (S8).
  - `ai_credit_ledger`. Reserve / settle / refund can be derived from `ai_scan.status` and the monthly
    limit; add the ledger when packs exist.
  - `project_feature` + `project_feature_change` + `user_consent` as generic frameworks for one feature.
  - `user_notification` with a tagged provider interface. The requirement is "notify the user": the
    list card and one e-mail do it.
  - `ai_scan_event` as a table; a JSON column on the scan covers progress.
  - The "navýšení" request variant, CSV export, history deletion, the `*/5` reaper cron (288 container
    starts a day where the house crons are nightly; leases are already checked at claim and status read).
- **Proposed change:** cut the list above from v1 and re-add each item when its consumer exists.
  Target: 4–5 tables, one credential, no cron beyond the nightly purge.

### M10. Phase order verifies the largest risk last; dependencies contradict the waves

- **Where:** arch §10 waves, P2 "Depends on P1", P3 acceptance, P8 gate, §1.3 / F1.
- **Evidence:**
  - Waves put P1 ‖ P2 in parallel although P2 depends on P1's voter.
  - Quality on real designs, the designer verdict and billed cost are gate items of P8.
  - PDF is deferred behind all eight phases, although a print PDF is the usual form of last year's
    calendar; the brief's card copy said "fotku nebo PDF" (ux C1). Rasterising page 1 of a PDF is an
    input adapter, not a phase.
  - MCP parity (`scan_design`) arrives two phases after admins scan in production, against the house
    rule that the MCP mirror ships with the feature.
- **Proposed change:** order P0 (measure) → P2 core with a minimal admin flag → P3 → P4 with the
  template MCP actions → P6 → P1 public card and P5 → P7. Add "PDF page as picture" to P3's input
  adapter.

### M11. The placeholder flow is missing steps that the measured failures require

- **Where:** ux S4b, S4 "Vrstvy"; arch §4.6, §4.7, P7.
- **Evidence and gaps:**
  1. No way to delete, merge or convert a placeholder, although "five stars = 5 picture slots" and
     "logo ring = slot or shapes" are documented run-to-run outcomes (HR §2 A2). The user gets
     "5 obrázků čeká na doplnění" for a star rating.
  2. No fit rule. A logo box hugs the ink (IoU 0.97); an uploaded logo with other padding or ratio
     lands at another size. Contain or cover per kind is unspecified.
  3. "Později" on a fixed picture: the template then exports with a grey stand-in and nothing warns at
     export or on the fill page (ux S4b note covers the editor only).
  4. Calendars: ux says uploads "become calendar pictures on accept"; arch §4.6 uploads them "first
     through the existing asset endpoint", and calendar assets cannot be deleted (arch R9). A discarded
     scan leaves them behind, while ux §3 says discard removes "nothing else".
  5. The calendar "accepted" card needs a way to mark a layout as scanned (ux C1 "Requirement";
     DC §8 item 21: `preset` has three server-validated values). It is in no phase.
  6. Layer boxes for "Ukázat vrstvy" and hover highlighting are not in the finish contract or the
     stored proposal (arch §4.7); text heights are not in the DSL.
  7. Palette snap ("Vrátit přesné barvy") and "o řádek víc" have no field in the contract.
- **Proposed change:** add remove / merge / "make it a shape" actions and a fit mode to S4b. Add an
  export warning for unfilled fixed pictures. Hold calendar uploads on the scan until accept. Add the
  preset marker to P7. Store per-element boxes, original colours and line counts with each candidate.

### M12. Provider spend-limit and refusal responses are handled as transient outages

- **Where:** arch §7 rows "Provider overload / 429 / 5xx" and "Provider refusal", §6 "Abuse and cost
  runaway", O13.
- **Evidence:** a reached tier cap returns 429 `rate_limit_error` with no `retry-after`, and "retrying
  … fails until access resumes"; a self-set organisation or workspace limit returns **HTTP 400**
  `invalid_request_error` (<https://platform.claude.com/docs/en/api/rate-limits>). O13 makes a $100
  workspace limit the outer guard. Under §7 the first is retried three times and then shown as "Služba
  AI teď neodpovídá — zkuste to znovu" to every user until month end; the second is not in the table.
  There are no alerts at launch (§7 Observability). Spend limits cannot be set on the default workspace
  (same page).
- **Proposed change:** classify `enforced_spend_limit_reached` and the 400 "specified … usage limits"
  as `provider_budget`. That code switches the feature to "Dočasně nedostupné", e-mails the admins once
  and does not offer retry. Require a dedicated workspace and a separate key for the evaluation suite.

---

## Minors

1. **Queue against reaper.** Queue ≤ 20 at ≈ 1.7 min each is 34 min; the reaper fails anything queued
   over 30 min as `scanner_unavailable` (arch §4.9, §5.2). Lower the queue cap or tie the reaper to
   scanner liveness.
2. **`/internal/scanner/*` is public today.** The web router is `Host(wboost.cz)` and takes every path
   (`~/www/lily.srv/apps/wboost/compose.yaml`, router labels). Arch §4.5 says "path refused on the
   public router", but P6's file list names no router change, and P2–P5 deploy the endpoints first. Add
   the rule to P6 and a 404-unless-internal-host check in the app from P2.
3. **Job tools "not reachable from outside"** (P6 test) contradicts their place on the public `/_mcp`.
   Resolved by M9.
4. **Egress is not restricted.** A bridge network gives the scanner the whole internet, not only
   `api.anthropic.com` (arch §6 "Network"). State it as residual or add an egress rule.
5. **tmpfs counts toward `mem_limit`** (comment at the Gotenberg service in the same compose file). The
   2.4 GiB sum in arch §8.1 leaves out the 256 MB `/tmp`.
6. **The CPU cap does not address the real contention point.** Scans are network-bound; the shared
   resource is Gotenberg, which that compose file records as having OOM-killed a Chromium on
   2026-08-05. A4 candidates at 2480 × 3508 px, face sheets and review re-renders land there. Measure
   Gotenberg peak in P0 with an A4 input next to a calendar PDF export.
7. **Prompt injection residue.** Free-text model fields (`notes`, `approximations[].what`,
   `omitted[].what`, placeholder `name`, `sourceLooksLike`, stage notes) are shown in WBoost's voice in
   the review, the admin table and e-mails, and names become fill-field labels for other users (arch
   §4.7, ux S4). Use closed codes as the calendar contract does, cap lengths, never linkify.
8. **Accept failures are missing from arch §7.** Renderer down at accept; a font removed, a gallery
   picture trashed or the palette changed between review and accept.
9. **"Po 6 minutách" (ux F6)** ignores the automatic second attempt and the queue; a scan can take
   over 12 minutes plus waiting.
10. **HEIC in the browser.** ux S2 computes preview, size and sharpness "in the browser from the decoded
    image"; Chrome and Firefox do not decode HEIC. Specify the fallback.
11. **"Dropping a file on the card goes to S2 with the file attached" (ux T1)** crosses a full page
    load (the site runs with Turbo off). Drop it or upload first.
12. **Stale artefacts the owner will look at.** The mock still shows cut-out pictures, "1–5 min",
    three steps and no S4b (ux §8). Wireframes in ux S3 / C3 / C4 show removed stages and "[Vložit
    hlavičku jako obr.]". Label the mock on its first screen or redraw the three screens before sending.
13. **Card copy promises pictures.** "systém z ní sám vytvoří upravitelnou šablonu … texty, obrázky
    i barvy" (ux T1, kept "as fixed in the brief") against "pictures are never extracted". Change to
    "místa pro obrázky", as the page lead already does.
14. **State mismatches.** ux history pills have no `stopped` or `expired`. ux S3 requires
    `previewVersion` and `rounds`; `ai_scan` has neither (arch §5.1). No copy for "queue full".
15. **MCP consent.** `acknowledgeExternalProcessing: true` is set by an agent, and a consent row is
    then stored for a person who never saw the wording (ux §5). Require an existing web consent.
16. **MCP parity gaps against the house rule:** admin switch, access request, "Zkusit přesto", retry
    (ux §5 "Not exposed"). Either expose them or record the exception as an owner decision.
17. **Delayed e-mail on `async`.** That transport's single consumer is blocked for minutes by a
    calendar PDF export (DC §8 item 17), so "one e-mail after 2 minutes" can be much later.
18. **S5 touches more than listed.** The empty-slot stand-in is a three-surface contract (editor, fill,
    render) and the decompiler relies on the 1×1 pixel (`src/Mcp/Design/DesignDecompiler.php:2114`).
    P2 lists only the compiler and render template.
19. **S9 edits `resources/calendar/model.mjs`.** P7's own test says stored PDF exports stay valid only
    if `model/` is unchanged (DC §8 item 26). State whether this file is in the fingerprint.
20. **Citation slips.** §1.2 calls the calendar cost "effort `medium`" while §4.9 presents `high` as
    measured-safe; pass-bar "easy 10" is 7 exports + 3 synthetic-photo variants of the same designs.
21. **Opus 5.5 specifics not in the design:** refusals come from safety classifiers (`stop_reason:
    "refusal"`), and the default effort is `medium` (arch sets it explicitly — keep it in the
    definition file, since an omitted value silently lowers quality).
22. **28 owner decisions** is too many to decide well; see the last section.

---

## Owner decisions: missing, mis-framed, already answered

**Already answered by the requirements (remove from the list):**

- O5 (entitlement per project, admin-switched), O20 (MCP exposure — "everything MCP-controllable"),
  O28 (no extraction), O9 (limited concurrency), O21 (names), most of O19.

**Mis-framed:**

- O1 — does not say the hard-round author recommended the hybrid, nor that calendars have separate
  evidence (M2, M3).
- O2 — presented as container against in-image worker; the real question is whether "subscription for
  local development" may be met by plan API credits (M2).
- O3 — "terms unclear" where the research says "do not" (M1).
- O6 — a refund rule on a flag that has no definition (M5).
- O11 — offers an option that does not protect the file (M6).
- O12 — placed on P8; it binds from the first client picture (M7).
- O24 — placed on P3 / P8; it is a precondition of approval (B2, B3).

**Missing:**

- What "closely match" means in numbers, and who the rating designer is — before P1.
- Whether calendars are part of the v1 approval or decided after the template scanner is measured.
- Whether a PDF page as a picture belongs in v1.
- Whether the public "Požádat o přístup" card goes live before the feature has passed its gate.
- Whether customers' uploads may be kept for evaluation (consent wording follows from it).
- What an export does with an unfilled fixed picture.
- Acceptable queue wait, since concurrency 1 is global across all projects.
- The budget and time for a P0 round (about 150–250 scans, roughly $60–120 on a key).

---

## Checked and found sound

Code and infra claims read against the source:

| Claim | Result |
|---|---|
| E29: 21 MCP tool classes, 6 scopes, `get_context` cache 60 s, no tool creates a standalone template | correct (`src/Mcp/Tool/`, `McpScope.php`, `GetContextTool.php:68`; no `AddTemplate` dispatch under `src/Mcp`). The scope docblock "create templates/variants" is stale |
| §1.4 row 5: an MCP token is user + scopes + expiry, no project | correct (`src/Entity/McpAccessToken.php:57-87`) |
| E30: `CandidateRenderer::renderToBytes(variant, compiled, …)`; review needs `variant->template->project` and `dimension` | correct (`CandidateRenderer.php:112`, `DesignPreflight.php:78-91`) |
| E31 / E32: calendar renderer takes a stored `Calendar`; `runDocument()` exists; `numberSide` is `['start','end']` | correct (`CalendarPageRenderer.php:65`, `CalendarModelBridge.php:82`, `model.mjs:52`) |
| E33: two buses, one `async` transport | correct (`config/packages/messenger.php`) |
| E34 / E35 / E36: disabled "Brzy" card; five `ProjectModule` cases, no preview flag; seven storage prefixes | correct (the auto-memory note on `isInPreview` is stale, as arch says) |
| E37: MCP `allowed_hosts` = `wboost.cz`, `localhost`; web on port 80 | correct (`config/packages/mcp.php`, compose labels) |
| E23: D31 forbids per-container CPU limits; `online-cv` has `cpus: 2`; next decision is D87 | correct (`docs/decisions.md` D31; `apps/myspeedpuzzling/compose.yaml:1022`; D86 is the last) |
| E24: consumer command is `bash -c '… && bin/console messenger:consume …'`, grace 30 s | correct (`apps/wboost/compose.yaml`) |
| Empty image slot = 1×1 transparent pixel; fillable background without asset = compile error | correct (`DesignCompiler.php:180`, `:420-424`) |
| Prototype figures quoted in E1–E20 | match the results files where checked (HR §1, T-B, T-F; T1 headline and line 186; T2 lines 44, 57, 97, 255; CAL §1, T5) |

Provider claims checked against current official pages:

| Claim | Result |
|---|---|
| Prices Opus 5.5 $4 / $20, Sonnet 5.5 $2 / $10, Haiku 5.5 $0.10 / $0.50 | correct (model table cached 2026-11-03) |
| Spend caps Start $500 / Build $1 000 / Scale $200 000; Evaluation tier; cache reads do not count toward ITPM; Start tier 2 M ITPM for Opus 5.5 | correct (<https://platform.claude.com/docs/en/api/rate-limits>) |
| Agent SDK is licensed for products under the Commercial Terms, with API-key auth | correct (<https://code.claude.com/docs/en/agent-sdk/overview>) |
| SDK options `tools`, `strictMcpConfig`, `permissionMode: "dontAsk"`, `settingSources: []`, `persistSession: false`, `maxBudgetUsd` (client-side estimate that "can pass this value"), `maxTurns`, `effort`; result carries `total_cost_usd`, `usage`, `modelUsage` | all exist (<https://code.claude.com/docs/en/agent-sdk/typescript>) |
| SDK and `claude -p` on a plan login draw on plan limits | correct per the help-centre article (search result, Oct 2026 update); not a licence for client data or servers (M1) |

Design choices that hold up: proposal-then-accept; candidates produced only by the app's own render
path; no ids in job tools; additive migrations; a pinned scanner that survives app deploys; requeue
instead of a grace-period drain; computing cost in the app from tokens. The document is candid about
single runs and unverified items in §12.3 — the problem is that the plan does not act on that list
before asking for approval.

---

## Could not check

- Anything on the production box (no ssh used): free memory, the rendered `.env` and whether
  `ANTHROPIC_API_KEY` is really there (DI §3 infers it; the infra repo has no trace), the bucket
  policy, how compose stops the consumer.
- Whether a never-persisted `TemplateVariant` renders (X7). The renderer tags a cache entry with
  `variant->id` and passes the id to the render template (`TemplateVariantImageRenderer.php:161`,
  `:323`); not traced further.
- Whether DSL `asset` ids are resolved strictly inside the job's project.
- Whether an in-process SDK MCP server (`createSdkMcpServer`) can return image content; the page read
  shows text results only. The prototype used a stdio server, which worked.
- The 75-property structured-output limit, the 2576 px image limit, the ZDR "Covered Models" statement,
  the 342 MB binary size and the "1 GiB per agent" guidance: taken from RP, not re-read.
- The help-centre article on plan use of the SDK was seen as a search summary, not opened.
- The prototype runs themselves: no scan was re-run and no render was opened; verdicts and scores were
  read from the results files only.
- The mock (`ux-mock/index.html`) was not opened in a browser.
