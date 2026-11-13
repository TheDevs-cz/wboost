# AI scan — owner decisions and implementation log

Source of truth for the design: `architecture.md` (revision 4) and `ux.md`
(revision 4). This file records what the owner decided and what the
orchestrator decided on his behalf, so later sessions do not re-open it.

## Owner decisions (Jan, 2026-11-12)

| # | Decision | Words / context |
|---|---|---|
| 1 | The MCP agent approach is the proven baseline; build it into the product. | "so far we have used MCP with claude code for creating templates and to my surprise it worked quite well" |
| 2 | Triggers: calendar wizard step 2 card "Naskenovat design"; create-template modal card "Šablona z obrázku". | screenshots in the kickoff message |
| 3 | v1 input: images. PSD / IDML only if cheap; not now. | kickoff message |
| 4 | Scans are asynchronous background jobs; the user is notified. | "this will have to happen async in background and notify the user" |
| 5 | Any technology may be used; several models/agents may be combined per purpose; the goal is a reliable service with a wizard-like flow. | two messages |
| 6 | Development, prototypes and testing run on the Claude subscription (local `claude` engine, `ANTHROPIC_API_KEY` unset); production uses the API key only. Includes client example designs. | decided after the terms caveat was presented |
| 7 | Picture content is not extracted from flat images. Each distinct picture object becomes its own placeholder layer the user fills in the wizard; anything reproducible as real layers should be; as many precise separate layers as possible. | two messages |
| 8 | System improvements that the work showed missing may be proposed and built. | "build anything it was missing" |
| 9 | Infra lives in `~/www/lily.srv`. | — |
| 10 | The user must see an estimated time and how many scans are ahead. | question, answered in ux.md S3 |
| 11 | **Approval to implement and deliver to production.** | "you can deliver it all on production directly" |
| 12 | Admins may scan customers' designs before the legal wording is confirmed — those designs reach the team anyway. The legal gate applies to customer self-service access. | "the designs of customers were reaching us anyways" |
| 13 | The live "Požádat o přístup" card is the gate for non-entitled users from the moment the wizard ships (not a "Brzy" teaser until launch). | "yes požádat o přístup is okay good gate" |
| 14 | Whatever the feature needs on the host server may be installed; preferably as infrastructure-as-code in `~/www/lily.srv`. | "if anything needs to be installed on the host server - you can" |
| 15 | The `ANTHROPIC_API_KEY` already present in the WBoost secret store (Infisical `wboost` / `prod`) is the intended production key for the scanner. | "yes i confirm api key" (2026-11-13) |

## Decisions taken by the orchestrator under #11 (architecture.md §11 numbering)

The owner approved delivery without answering the open list, and had said the
choices are the orchestrator's ("you are expert"). Defaults taken, each
reversible by a setting or a later phase:

| O | Taken | Note |
|---|---|---|
| O1 | Phase 0 is folded into the build: the real scanner is built first (P2) and measured on the existing sets + resources on x86; the foreign-design round with a rating designer stays a gate for CUSTOMER access (P6), not for admin use. | inputs from a designer are still owed by the owner |
| O2 | "Match" = the two-scale verdict in architecture.md; rating designer to be named before P6. | |
| O3 | Calendars are in scope. | |
| O4 | PDF: first page as a picture is allowed if it stays cheap; otherwise follow-up. | |
| O5–O7 | Builder = Opus 5.5 agent with a mandatory measured pre-pass, Agent SDK engine, same runner for calendars; re-examined with P2's measurements. | |
| O8 | Pass bars provisional until measured with repeats. | |
| O9 | Concurrency 1; `cpus: 3` exception recorded in the infra repo; memory limit from P2's measurement. | |
| O10 | Legal texts + EU-residency question gate customer access (P6) only; admins-only use before that. | owner must confirm wording |
| O11 | Unfilled pictures: web warns, API/MCP refuse. | |
| O12–O16 | As recommended in architecture.md §11. | |
| O14 | "Premium" = enabled by us per project on request; no pricing yet. | |

Rollout rule that makes the above safe: everything ships **admins-only**
behind the per-project entitlement and the database kill switch; customer
access is a separate, explicit step.

## Not committed

`examples/`, `prototypes/`, `ux-mock/` are git-ignored: client material and
heavy run artefacts, pending the owner's decision.
