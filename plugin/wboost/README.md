# wboost plugin for Claude Code

Connects Claude to a [wboost](https://wboost.cz) account: list your projects and
brand assets, fill, preview and export templates (PNG or print PDF), fill and
export multi-page documents, add pictures and fonts, author a variant's design,
and create, design, fill and export desk calendars (Kalendáře).

Current version: **0.3.0** — see [CHANGELOG.md](CHANGELOG.md).

## Install

```bash
claude plugin marketplace add TheDevs-cz/wboost && claude plugin install wboost@wboost
```

**Nothing to paste.** The first time the server is used, Claude Code discovers
wboost's authorization server, registers itself, and opens your browser: you sign
in, approve the permissions on a consent screen, and you are connected. If the
browser does not open by itself, run `claude mcp login wboost`.

You can review and revoke the connection at any time at
`https://wboost.cz/user-profile/connected-apps`.

Full instructions, scope meanings and troubleshooting:
[`docs/mcp/connect.md`](../../docs/mcp/connect.md).
Copy-paste prompts to try: [`docs/mcp/prompts.md`](../../docs/mcp/prompts.md).

## Update

Third-party marketplaces do not auto-update by default. Update by hand:

```bash
claude plugin marketplace update wboost && claude plugin update wboost@wboost   # then restart Claude Code
```

or turn auto-update on once: `/plugin` → Marketplaces → wboost → Enable
auto-update. Claude also tells you when your copy is outdated: the skill sends
its version to the server on the first call and the server answers whether a
newer one exists. The same skill for claude.ai (upload under Settings →
Capabilities → Skills; delete the old one first):
<https://github.com/TheDevs-cz/wboost/releases/latest/download/wboost-skill.zip>.

## What you get

| component | what it is |
|---|---|
| MCP server `wboost` | HTTP transport to `https://wboost.cz/_mcp` — the tools listed in [`skills/wboost/references/tools.json`](skills/wboost/references/tools.json) (templates, documents, design, gallery, fonts, print setup, desk calendars) |
| skill `/wboost:wboost` | how to use those tools well — the version check, ids not names, render before export, container overflow, fill value shapes, print bleed, multi-page documents, the design DSL and `acknowledgeLosses`, and the whole calendar workflow |
| `/wboost:projects` | what does this account hold |
| `/wboost:export` | brief → template → fill → preview → PNG / print PDF |
| `/wboost:calendar` | brief → desk calendar created / designed / filled → page renders |

The skill is also picked up automatically when a conversation is about wboost
templates or calendars; you do not have to invoke it by hand.

## Scope of this release

Read, render, export **and author** — templates and desk calendars.

**Nothing deletes.** No tool removes a template, variant, document, picture,
font, calendar or calendar file.

Deliberately absent: no tool reads a design back as a document (so `set_design`
authors designs rather than edits them), no tool designs a synchronized group
(the wboost group editor does), and new templates are created on the web.

Permissions are granted per connection on the consent screen and can only narrow
what your own account may reach, never widen it. Designing additionally requires
the right to edit the project: a project shared with you grants viewing,
rendering and exporting only (and, in a calendar, filling content as a client).

## Authentication

The plugin's `.mcp.json` declares the server with **no `Authorization` header**,
which is exactly what makes the client fall back to OAuth 2.1 + PKCE discovery
against `/.well-known/oauth-protected-resource` and register itself.

That is deliberate, and it is why there is no token setting on this plugin. An
`Authorization` header — including one holding an unset config placeholder, which
resolves to an empty `Bearer` — suppresses the OAuth path entirely, because the
client believes it is already authenticating. The result would be a bare 401 with
no browser flow and nothing explaining why.

Personal access tokens still work on the same endpoint; they are for CI and
headless agents, and they are used through a manual `claude mcp add --header …`
rather than through this plugin. See
[`docs/mcp/connect.md`](../../docs/mcp/connect.md).

## Layout

```
plugin/wboost/
├── .claude-plugin/plugin.json   # manifest (version = the skill release)
├── .mcp.json                    # HTTP transport, no auth header (OAuth by discovery)
├── CHANGELOG.md
├── skills/wboost/
│   ├── SKILL.md                 # lean entry point + the version check
│   └── references/
│       ├── tools.md             # template tools, field by field
│       ├── filling.md           # fill value shapes, rows, moves
│       ├── design.md            # authoring, acknowledgeLosses, the DSL key tables
│       ├── documents-and-print.md
│       ├── calendars.md         # the calendar workflow + the seven calendar tools
│       └── tools.json           # GENERATED manifest of every tool + parameters
└── commands/
    ├── projects.md
    ├── export.md
    └── calendar.md
```

The marketplace manifest lives at the repository root
(`.claude-plugin/marketplace.json`) and points here by relative path, so the repo
is both the marketplace and the plugin source.

## Development

Validate the manifests after any edit:

```bash
claude plugin validate plugin/wboost --strict
claude plugin validate . --strict            # the marketplace
```

Load it without installing:

```bash
claude --plugin-dir plugin/wboost
```

The skill is kept in step with the server by `tests/Mcp/SkillManifestTest.php`
(every tool and parameter vs `references/tools.json`, one version everywhere,
claude.ai upload rules) and `tests/Mcp/SkillDocumentationTest.php` (scope table,
DSL grammar, the worked design document). Changing anything here after a
release needs a version bump — the `skill-release-guard` CI job fails
otherwise. The routine: [`docs/mcp/skill-maintenance.md`](../../docs/mcp/skill-maintenance.md).

Never commit a token.
