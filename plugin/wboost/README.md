# wboost plugin for Claude Code

Connects Claude to a [wboost](https://wboost.cz) account so it can list your
projects and brand assets, fill a template variant's placeholders, preview the
result, export the finished PNG, add pictures to a project gallery and author a
variant's design.

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

## What you get

| component | what it is |
|---|---|
| MCP server `wboost` | HTTP transport to `https://wboost.cz/_mcp` — nine tools: `get_context`, `find_templates`, `describe_variant`, `list_gallery`, `render_variant`, `export_variant`, `upload_image`, `preview_design`, `set_design` |
| skill `/wboost:wboost` | how to use those tools well — ids not names, render before export, container overflow, the fill value shapes, the design DSL, and what `acknowledgeLosses` really does |
| `/wboost:projects` | what does this account hold |
| `/wboost:export` | brief → template → fill → preview → PNG |

The skill is also picked up automatically when a conversation is about wboost
templates; you do not have to invoke it by hand.

## Scope of this release

Read, render, **and author**. The tools can look at anything the account can see,
produce images from it, add pictures to a project gallery, and write a variant's
design.

**Nothing deletes.** No tool removes a template, a variant or a picture; gallery
pictures can be added but never removed, moved or renamed from a client.

Two capabilities are deliberately absent: there is no tool that reads a design
back as a document (so `set_design` authors designs rather than edits them), and
there is no tool for group design — a synchronized group's shared design is
authored in the wboost group editor.

Permissions are granted per connection on the consent screen and can only narrow
what your own account may reach, never widen it. Designing additionally requires
*owning* the project: a project shared with you grants viewing, rendering and
exporting only.

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
├── .claude-plugin/plugin.json   # manifest
├── .mcp.json                    # HTTP transport, no auth header (OAuth by discovery)
├── skills/wboost/
│   ├── SKILL.md
│   └── references/tools.md      # field-by-field response + DSL grammar reference
└── commands/
    ├── projects.md
    └── export.md
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

The skill's tool list and DSL grammar tables are asserted against the running
code by `tests/Mcp/SkillDocumentationTest.php` — a tool that lands or changes
scope, or a change to the parser's accepted keys, fails that test until the skill
is updated.

Never commit a token.
