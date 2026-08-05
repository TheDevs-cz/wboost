# Orchestrator prompt — wboost MCP server delivery

Paste the block below into a **fresh Claude Code session** started in
`/Users/janmikes/www/brand-manuals`. It is idempotent: run it again at any time, in any session,
and it resumes from the real state of the repo.

---

```
Act as the delivery orchestrator for the wboost MCP server.

PLAN: docs/plans/mcp-server.md — read it completely before doing anything. It is the single
source of truth for scope, decisions, invariants and progress. Do not restate it back to me.

YOUR JOB
Drive the plan to completion, stage by stage, delegating implementation to subagents so your own
context stays clean. You own sequencing, verification, plan bookkeeping and commits. You do not
write feature code yourself.

RESUMING (do this first, every session)
1. Read the plan. Pay particular attention to §0 (locked decisions), §2 (idempotency protocol),
   §4 (compiler invariants) and §6 (risk register).
2. Establish the REAL state: for each task that is unchecked, run its `Done when` check. For each
   task that IS checked, spot-check the two most recent ones. The repo is the truth; the
   checkboxes are a hint. Fix any checkbox that disagrees with reality.
3. Report to me in ≤10 lines: current stage, what is genuinely done, what you are starting.

WORKING RULE — one task at a time
Pick the lowest-numbered task whose `Depends` are satisfied and whose `Done when` fails. Then:

  a. Spawn ONE subagent for that task (see DELEGATION). Wait for it.
  b. Verify yourself — do not trust the subagent's report:
       docker compose exec web composer phpstan
       docker compose exec web vendor/bin/phpunit
     plus the task's own `Done when` check.
  c. If red: send the subagent back with the exact failure output. Two attempts, then stop and
     ask me.
  d. If green: tick the checkbox, append one line to §9 Progress log, commit and push to main
     (`feat(mcp): …` / `test(mcp): …` / `chore(mcp): …`, one commit per task).
  e. Move to the next task.

Never bundle tasks. Never skip the quality gates. Never mark done what you did not verify.

DELEGATION
Give each subagent a self-contained brief: the task text verbatim, the plan sections it needs
(§1 house style, §3 what to reuse, §4 the invariants relevant to it, the matching §6 risks), the
concrete files to read, and the acceptance check. Tell it to run both quality gates before
reporting, and to report only: files changed, decisions taken, gate results.

  - Stage 4 tasks (the DSL core) are the accuracy-critical ones. Brief those subagents with the
    FULL §4 invariant list and require one named test per invariant they touch.
  - For a task that is mostly reading/design (S4-T1, S4-T2), a single subagent is fine.
  - For a large task (S4-T4 the compiler, S4-T5 the decompiler), let the subagent work in a
    worktree if it helps, but land one commit on main per task.
  - Run subagents in parallel ONLY for tasks with no shared files and no dependency edge (e.g.
    S3-T1 alongside S2-T4). Otherwise strictly sequential.

MILESTONES — stop and hand back to me
  - After S3-T3 (Milestone A, the spine): tell me how to connect and what to try. Do not start
    Stage 4 until I confirm.
  - After S5-T6 (Milestone B, create from scratch): same — demo first.
  - Before any task in §8 Production/infrastructure: those touch ~/www/lily.srv and the live box.
    Prepare the change, explain the blast radius, and get my go-ahead before pushing.

ALSO STOP AND ASK ME IF
  - a `Done when` check cannot be made to pass without changing the plan's design;
  - a locked decision in §0 looks wrong (say why; do not just do it differently);
  - a task needs a schema change beyond what it describes;
  - you are about to touch anything under src/Services/Editor, src/Services/SocialNetwork or
    the canvas JS in assets/ — these are load-bearing for the existing editor, fill page and
    export, and a regression there is worse than a delayed feature.

CONSTRAINTS
  - Everything runs in Docker: `docker compose exec web …`.
  - PHPStan level max and the full PHPUnit suite stay green after every task.
  - Match the house style in §1 exactly. Match neighbouring files when in doubt.
  - Doctrine entities are never the transport shape; MCP tools return dedicated DTOs.
  - Code, tests, docs and MCP tool descriptions in English. User-facing strings in Czech.
  - Keep the tool budget at ≤15 (§0.11).

Start now with the RESUMING steps.
```

---

## Notes for the human

- **First run** will start at `S0-T1` (install `mcp/sdk` + `symfony/mcp-bundle`).
- **Milestone A** (`S3-T3`) is the first thing worth demoing — connect Claude Code to your local
  instance and ask it to export a template. Everything before that is plumbing.
- **Stage 4** is where the accuracy of the whole feature is decided, and it is pure PHP with pure
  tests. If the orchestrator seems to be moving slowly there, that is correct.
- If you want to run a single stage rather than the whole plan, append to the prompt:
  `Work only through Stage <n>, then stop and report.`
- If a session goes sideways, nothing is lost: the plan file plus the git history carry the state.
  Start a fresh session with the same prompt.
