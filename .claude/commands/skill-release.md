---
description: Release a new version of the wboost Claude skill/plugin — compute the delta since the last release, update the skill, verify it with real sessions, bump the version and changelog.
argument-hint: "[optional: what changed / the skill-release issue number]"
---

Release the wboost Claude skill (`plugin/wboost/`). Context: $ARGUMENTS

Read first: `docs/mcp/skill-maintenance.md` (the process and the checks),
`plugin/wboost/skills/wboost/SKILL.md` and its `references/`, `CLAUDE.md`
(Docker, PHPStan, commit rules — stage and commit with explicit pathspecs only).

1. **Delta.** `tag=$(git tag -l 'wboost-plugin-v*' --sort=-v:refname | head -1)`
   (none yet → use the commit that last changed `plugin/wboost/.claude-plugin/plugin.json`).
   - `git log --no-merges --oneline $tag..HEAD -- src/Mcp config/packages/mcp.php docs/architecture docs/mcp docs/api/consumer-prompt.md`
   - `git diff --stat $tag HEAD -- src/Mcp docs/architecture`
   - Manifest diff: run `docker compose exec web vendor/bin/phpunit tests/Mcp/SkillManifestTest.php`
     — its failure lists every added / withdrawn / changed tool and parameter.
   - Read the changed tool classes (`src/Mcp/Tool/*`, their docblocks are the
     tool descriptions) and the touched `docs/architecture/*.md` sections.
   Write the delta as a list: new tools, new / changed parameters, changed
   behaviour, limits or refusal codes, new workflows.
2. **Draft the skill changes.** Field-level facts go to `references/` (template
   tools → `tools.md`, filling → `filling.md`, DSL → `design.md`, documents and
   print → `documents-and-print.md`, calendars → `calendars.md`); `SKILL.md`
   gets only the *when* and any rule that makes runs succeed — keep it under
   ~250 lines, frontmatter keys `name`, `description`, `license`, `metadata`
   only, description ≤ 1024 characters. Every claim must be checkable in the
   code; fix anything the delta made wrong. Update `commands/*.md`, the
   plugin README, `docs/mcp/connect.md` (tools table) and `docs/mcp/prompts.md`
   where they mention the changed tools.
3. **Regenerate the manifest:**
   `docker compose exec -e WBOOST_WRITE_SKILL_MANIFEST=1 web vendor/bin/phpunit tests/Mcp/SkillManifestTest.php`
4. **Bump** (minor for a new tool / parameter, patch for wording): `plugin.json`,
   `.claude-plugin/marketplace.json`, `src/Mcp/Skill/SkillRelease.php`
   (`VERSION`, one-line `WHATS_NEW`), SKILL.md `metadata.version` and every
   `"skillVersion": "…"` (SKILL.md and `commands/*.md`); new top entry in
   `plugin/wboost/CHANGELOG.md` (`## x.y.z — YYYY-MM-DD`, grouped New tools /
   New parameters and behaviour / Skill).
5. **Verify with real sessions** against the local stack (web on the port in
   your compose env, e.g. http://localhost:8099; MCP PAT via
   `docker compose exec -T web bin/console app:mcp:token:create <email> --name=skill-release --scopes=templates:read,templates:export,templates:design,gallery:write,calendars:read,calendars:write`).
   Copy `plugin/wboost` to a scratch dir, point its `.mcp.json` at
   `http://localhost:<port>/_mcp` with `"headers": {"Authorization": "Bearer ${WBOOST_MCP_TOKEN}"}`, then per changed area run
   `env -u ANTHROPIC_API_KEY claude -p "<realistic task>" --plugin-dir <copy> --strict-mcp-config --mcp-config <copy>/.mcp.json --allowedTools mcp__wboost Skill Read --output-format stream-json --verbose > run.jsonl`
   (ANTHROPIC_API_KEY unset = the owner's plan). Keep it to a handful: one per
   new / changed area, plus one with the copy's version lowered to check the
   update notice. Read the transcripts; fix what the skill got wrong; rerun.
6. **Checks:** `docker compose exec web vendor/bin/phpunit tests/Mcp`, then the
   full suite and `docker compose exec web composer phpstan`;
   `claude plugin validate plugin/wboost --strict && claude plugin validate . --strict`.
7. **Commit** with explicit pathspecs (`git commit -- <paths>`), message
   `docs(skill): wboost skill x.y.z — <summary>`; push to main if the user's
   standing rules allow it, otherwise leave it for review. The push of the
   bump triggers `plugin-release.yml`, which tags `wboost-plugin-vx.y.z` and publishes the zip;
   check `gh release view wboost-plugin-vx.y.z`. Close the `skill-release` issue
   with a link to the release.

Report: the delta, what the skill now says differently, what the sessions
showed, the version and CI status.
