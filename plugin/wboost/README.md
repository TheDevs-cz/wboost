# wboost plugin for Claude Code

Connects Claude to a [wboost](https://wboost.cz) account so it can list your
projects and brand assets, fill a template variant's placeholders, preview the
result and export the finished PNG.

## Install

```bash
claude plugin marketplace add TheDevs-cz/wboost && claude plugin install wboost@wboost
```

Claude Code prompts once for your **wboost access token** (masked, stored in your
OS keychain — never in this repo or in `settings.json`). To skip the prompt, pass
it: `claude plugin install wboost@wboost --config token=wb_mcp_…`.

A token starts with `wb_mcp_` and is issued by a wboost operator:

```bash
bin/console app:mcp:token:create you@example.com \
  --name="Claude Code" --scopes=templates:read,templates:export
```

Full instructions, scope meanings and troubleshooting:
[`docs/mcp/connect.md`](../../docs/mcp/connect.md).
Copy-paste prompts to try: [`docs/mcp/prompts.md`](../../docs/mcp/prompts.md).

## What you get

| component | what it is |
|---|---|
| MCP server `wboost` | HTTP transport to `https://wboost.cz/_mcp` — six tools: `get_context`, `find_templates`, `describe_variant`, `list_gallery`, `render_variant`, `export_variant` |
| skill `/wboost:wboost` | how to use those tools well — ids not names, render before export, container overflow, the fill value shapes |
| `/wboost:projects` | what does this account hold |
| `/wboost:export` | brief → template → fill → preview → PNG |

The skill is also picked up automatically when a conversation is about wboost
templates; you do not have to invoke it by hand.

## Scope of this release

Read and render only. The tools can look at anything the account can see and
produce images from it — they cannot change a template, upload a picture, or
delete anything. Authoring designs from an AI client is not available yet;
templates are created in the wboost editor.

The token acts as **you**: scopes can only narrow what your own account may
reach, never widen it.

## Layout

```
plugin/wboost/
├── .claude-plugin/plugin.json   # manifest + the userConfig token prompt
├── .mcp.json                    # HTTP transport, Authorization: Bearer ${user_config.token}
├── skills/wboost/
│   ├── SKILL.md
│   └── references/tools.md      # field-by-field response reference
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

Never commit a token. The manifest only ever references `${user_config.token}`.
