# Keeping the wboost skill current

The MCP tools live on the server and change with every deploy. The **wboost
skill** (`plugin/wboost/skills/wboost/`, shipped as the Claude Code plugin
`wboost@wboost` and as a zip for claude.ai) is a copy on each user's machine:
it goes stale silently, and stale instructions are worse than none — an agent
told a tool does not exist will not try it. Three mechanisms keep it current.

## a) Drift cannot ship (CI)

| check | where | fails when |
|---|---|---|
| tool manifest | `tests/Mcp/SkillManifestTest.php` | a registered tool, its scope or any input parameter (name, type, enum, required) differs from `references/tools.json`; a tool has no `## \`name(…)\`` section in `references/*.md` or is not named in `SKILL.md` |
| one version | same test | `SkillRelease::VERSION`, `plugin.json`, `marketplace.json`, SKILL.md `metadata.version` + its `{"skillVersion": "…"}` line, `tools.json` and the top `CHANGELOG.md` entry disagree |
| uploadable | same test | SKILL.md frontmatter has a key claude.ai rejects, the description is over 1024 characters, the body reaches 500 lines, or a `references/…` link is dead |
| server instructions | same test | `config/packages/mcp.php` instructions exceed 4096 characters (Claude Code cuts the rest) or do not open with the skill line |
| prose facts | `tests/Mcp/SkillDocumentationTest.php` | scope table, DSL key tables / enums (in `references/design.md`), the worked design document no longer parses |
| released = frozen | `skill-release-guard` job in `.github/workflows/test.yml` | anything under `plugin/wboost/` or `.claude-plugin/` differs from the `wboost-plugin-v<version>` tag of the CURRENT version — i.e. the plugin changed after release without a bump |

So **changing an MCP tool without updating the skill and bumping the version
fails CI**: the manifest test forces the skill edit + regenerated
`tools.json`, and the regenerated `tools.json` (under `plugin/`) trips the
release guard until the version is bumped. Rewording a tool's description does
not touch the manifest — it reaches clients straight from the server.

Regenerate the manifest after a deliberate change:

```bash
docker compose exec -e WBOOST_WRITE_SKILL_MANIFEST=1 web vendor/bin/phpunit tests/Mcp/SkillManifestTest.php
```

### Adding or changing a tool — the checklist

1. Teach it: a `## \`tool(args…)\`` section in the right `references/*.md`
   (`tools.md` for template tools, `calendars.md` for calendar tools), a row in
   the scope table of `references/tools.md` (new tool), and a mention with the
   *when* in `SKILL.md` (the jobs table + a rule if it changes how runs
   succeed). Keep SKILL.md short; detail goes to references.
2. Regenerate `references/tools.json` (command above).
3. Bump the version in one go — `plugin/wboost/.claude-plugin/plugin.json`,
   `.claude-plugin/marketplace.json`, `src/Mcp/Skill/SkillRelease.php`
   (`VERSION` + a one-line `WHATS_NEW`), SKILL.md (`metadata.version` and every
   `"skillVersion": "…"`, also in `commands/*.md`) — and add a `## x.y.z — date`
   entry on top of `plugin/wboost/CHANGELOG.md`. New tool / parameter = minor,
   wording-only = patch.
4. Run both skill tests, then the full suite and PHPStan.
5. Optional but recommended: a headless session with the plugin against the
   local stack (see the `/skill-release` routine).

## b) Users find out (and what cannot be automatic)

| route | how updates arrive | what we built |
|---|---|---|
| Claude Code plugin | Only when the user updates: third-party marketplaces have auto-update **off** by default, and an update is keyed on the plugin `version` (same version = no update even with new commits). `claude plugin marketplace update wboost && claude plugin update wboost@wboost`, restart. Or `/plugin` → Marketplaces → wboost → *Enable auto-update* once. | the version check below; the README / connect.md document the toggle |
| claude.ai uploaded skill | Never: an uploaded skill is a copy; the documented route is delete + re-upload. (Skills uploaded via the API do not reach claude.ai, so we cannot push.) | a stable zip: `https://github.com/TheDevs-cz/wboost/releases/latest/download/wboost-skill.zip`, built by `.github/workflows/plugin-release.yml` on every new version |
| no skill at all | — | the server instructions + `skill.status: unknown` tell Claude to mention once how to install it |

**The version check.** `SKILL.md` states its version and tells Claude to send
it as `skillVersion` on its first `get_context` (or, calendar-only,
`list_calendars`) call. Both answer a `skill` object from
`SkillRelease::check()`: `current` (silence), `outdated` (`message` with what
is new + both update routes — Claude tells the user once per session, after
finishing the task, never blocking it) or `unknown` (no version sent: load the
wboost skill if one is available; only if none, mention how to install). The
server `instructions` open with the same rule, so a client whose skill is too
old to send a version — or that has none — still learns the current version.
A piggyback on the first call costs no extra round trip and needs no scope
(a separate tool would need a scope, and a tool without one is denied to
everybody by design — `McpToolGate`). Gallery-only tokens see neither tool and
get only the instructions line.

Sources (checked 2026-11-12): plugin versions and update keys —
code.claude.com/docs/en/plugins/manifest-reference and …/plugins/host-marketplace;
auto-update defaults and the toggle — code.claude.com/docs/en/plugins/loading;
uploadable frontmatter keys — code.claude.com/docs/en/skills ("outside Claude
Code"); skill authoring limits — platform.claude.com/docs/en/agents-and-tools/agent-skills/best-practices;
claude.ai upload / re-upload — support.claude.com/en/articles/12512180;
4096-character instructions cut — code.claude.com/docs/en/mcp.

## c) Regular proposals

- **Weekly detector** — `.github/workflows/skill-drift.yml` (Mondays, or run it
  by hand): lists commits touching `src/Mcp`, `config/packages/mcp.php`,
  `docs/architecture`, `docs/mcp` and the API contract since the last
  `wboost-plugin-v*` tag and opens (or refreshes) ONE issue labelled
  `skill-release`. Close it when nothing needs teaching.
- **The routine** — `/skill-release` (`.claude/commands/skill-release.md`): a
  Claude session or subagent with no other context computes the delta, drafts
  the skill changes, regenerates the manifest, runs the verification sessions,
  bumps version + changelog and commits for review. Pushing to main then tags
  and publishes the release (`plugin-release.yml`).
- **Not automatic yet, and why:** a fully unattended run needs (1) an Anthropic
  credential in GitHub secrets (`ANTHROPIC_API_KEY` or a Claude Code OAuth token
  for `anthropics/claude-code-action`), (2) the whole stack in CI (Postgres,
  Minio, Gotenberg, seeded fonts / gallery / an MCP token) for the verification
  sessions, and (3) a human merge — the skill is instructions loaded into every
  user's agent, so a reviewed PR is the safety net. With (1) and (2) the
  drift workflow could invoke `/skill-release` and open a PR instead of an
  issue; alternatively a scheduled Claude Code cloud routine (`/schedule`) can
  run the command against a checkout weekly.
