# Connect an AI client to wboost

wboost exposes an MCP server at **`https://wboost.cz/_mcp`**. Connecting an AI
client to it lets the assistant see your projects and brand assets, fill a
template variant's placeholders and hand you the exported PNG — without you
opening the app.

This release supports **Claude Code**, and authentication is a personal access
token you paste once.

`/_mcp` also accepts an **OAuth 2.1 bearer token** issued by wboost's own
authorization server (authorization-code grant with PKCE), and both credentials
behave identically once inside — same account, same scopes, same tools. What is
still missing for claude.ai / ChatGPT connectors is *automatic client
registration*: today an operator has to register the connector's `client_id` and
redirect URI by hand, so those connectors cannot yet set themselves up.

---

## 1. Get a token

A token is a **personal access token**: it acts as you, and its scopes can only
*narrow* what your own account may reach, never widen it. Today only an operator
with shell access to the production box can mint one — there is no self-service
button yet. Ask them for one, or run it yourself if that is you:

```bash
# on the wboost box, inside the web container
bin/console app:mcp:token:create you@example.com \
  --name="Claude Code" \
  --scopes=templates:read,templates:export
```

The secret is printed **once** and looks like `wb_mcp_…`. Only its SHA-256 is
stored, so a lost token is replaced (create a new one, revoke the old), never
recovered.

Related commands:

```bash
bin/console app:mcp:token:list          # every token ever issued, with its status
bin/console app:mcp:token:revoke <id>   # kill one; idempotent
```

> Once a client can register itself, this whole step disappears: you will sign
> in to wboost in a browser and the client will get its own token. Nothing about
> the tools changes — an OAuth-authenticated client sees exactly what a personal
> access token of the same scopes sees.

### Scopes

`--scopes` is comma-separated. Pick the narrowest that does the job.

| scope | what it unlocks | notes |
|---|---|---|
| `templates:read` | `get_context`, `find_templates`, `describe_variant`, `list_gallery`, `render_variant` | the default when `--scopes` is omitted |
| `templates:export` | `export_variant` — the full-size lossless PNG | **implies** `templates:read`; exports are counted in the usage report |
| `templates:design` | *nothing in this release* | reserved for the authoring tools |
| `gallery:write` | *nothing in this release* | reserved for uploads |

A tool the token lacks the scope for is **not listed** to the client at all, and
is refused (403 `insufficient_scope`) if called anyway.

A read-only agent is `--scopes=templates:read`. An agent that should be able to
hand the user a finished file is `--scopes=templates:export` — no need to list
`templates:read` alongside it.

---

## 2. Install the plugin (recommended)

One line, from anywhere:

```bash
claude plugin marketplace add TheDevs-cz/wboost && claude plugin install wboost@wboost
```

Claude Code then prompts once for your **wboost access token**. The input is
masked and the value goes into your OS keychain — not into `settings.json`, not
into any repository.

Prefer not to be prompted (CI, a scripted setup)? Pass it:

```bash
claude plugin install wboost@wboost --config token=wb_mcp_…
```

Restart Claude Code (or start a new session) and you have:

- the MCP server `wboost` with its six tools;
- the **wboost skill**, which teaches the assistant to use them well — it fires
  automatically when a conversation is about wboost templates, or on
  `/wboost:wboost`;
- `/wboost:projects` — what does this account hold;
- `/wboost:export` — brief → template → fill → preview → PNG.

The marketplace lives in this same repository (`.claude-plugin/marketplace.json`
at the root, pointing at `plugin/wboost/`), which is public, so the command above
works for anyone.

On a slow connection you can limit what git checks out:

```bash
claude plugin marketplace add TheDevs-cz/wboost --sparse .claude-plugin plugin
```

### Updating

```bash
claude plugin marketplace update wboost
claude plugin update wboost
```

Restart the session to apply.

---

## 3. Manual connection (no plugin)

If you only want the tools — no skill, no slash commands:

```bash
claude mcp add --transport http wboost https://wboost.cz/_mcp \
  --header "Authorization: Bearer wb_mcp_…"
```

Add `--scope user` to make it available in every project instead of only the
current directory.

⚠️ This writes the token in **plaintext** into your Claude Code config. The
plugin route keeps it in the keychain instead; prefer it unless you have a reason
not to.

Without the skill the assistant has to work the tool descriptions out on its own.
They are written for that, but the skill is what encodes the judgement — render
before export, ids not names, what a container overflow means.

---

## 4. Check it works

In a Claude Code session:

```
/mcp
```

You should see a `wboost` server, connected, listing exactly the tools your
scopes allow — five for `templates:read`, six with `templates:export`.

Then ask for something real:

```
Vypiš mi moje wboost projekty.
```

The assistant should call `get_context` and come back with your projects, their
brand fonts and their brand colours. More prompts to try:
[`prompts.md`](prompts.md).

You can also confirm the server itself is up without any client:

```bash
curl -s https://wboost.cz/.well-known/oauth-protected-resource
# {"resource":"https://wboost.cz/_mcp","authorization_servers":["https://wboost.cz"],
#  "scopes_supported":["templates:read","templates:export","templates:design","gallery:write"],
#  "bearer_methods_supported":["header"]}
```

---

## Troubleshooting

**`/mcp` shows the server as failed, or every call answers 401.**
The token is wrong, revoked, expired, or belongs to a deactivated user. Check
`app:mcp:token:list` — it shows the status of every token ever issued. A 401
response carries a `WWW-Authenticate: Bearer resource_metadata="…"` header; that
header is present even when nothing was sent, so its presence only proves you
reached the right endpoint, not that the token was read.
If you installed via the plugin, re-enter the token with `/plugin` → configure.

**A tool the docs mention is missing from `/mcp`'s list.**
Your token lacks that tool's scope. `export_variant` needs `templates:export`;
with `templates:read` alone it is not listed *and* would be refused with
`insufficient_scope`. Mint a new token — scopes cannot be edited on an existing
one.

**The tool list is empty but the server is connected.**
Same cause, taken to its conclusion: a token whose scopes parse to nothing. Check
the `--scopes` you passed — an unknown scope string is refused at creation time,
so this usually means the token was hand-edited in the database.

**`Project X was not found, or this account cannot access it.`**
Exactly what it says, and it is the *same* message for "no such project" and "not
yours" — deliberately, so the API cannot be used to discover other accounts'
projects. Call `get_context` and use an id from there.

**Everything works but the assistant fills nothing** — the picture comes back
looking like the untouched design.
The fill was keyed by input *names* instead of ids. The tools report this in
`warnings[]` rather than failing. Ids come from `describe_variant`; the skill
covers this at length.

**`export_variant` refuses with a container overflow.**
Not a connection problem: the filled text is taller than the height the designer
allowed. Shorten a text, hide a `hidable` input, or have the designer raise the
container's `maxHeight` in the wboost editor. `render_variant` will show you the
overflow while you work.

**`The image renderer is busy and did not answer in time.`**
Transient — the shared render service was saturated. Nothing was changed; repeat
the call.

**A plugin change does not show up.**
Restart the session. If it still does not, the plugin cache at
`~/.claude/plugins/cache` may be stale — `claude plugin marketplace update wboost`
refreshes it.

---

## What the tools can and cannot do

Six tools, all **read + render**:

| tool | scope | what it does |
|---|---|---|
| `get_context` | `templates:read` | the account, its scopes, and every project's brand fonts, colours and dimensions |
| `find_templates` | `templates:read` | one project's templates and variant summaries |
| `describe_variant` | `templates:read` | one variant in full — input ids, their rules, image slots, containers |
| `list_gallery` | `templates:read` | one level of a project's picture library |
| `render_variant` | `templates:read` | a cheap downscaled WebP preview of a filled variant |
| `export_variant` | `templates:export` | the deliverable — full-size lossless PNG, recorded as an export |

Nothing here changes a template, uploads a picture, or deletes anything. Creating
and editing designs from an AI client is not available yet — that stays in the
wboost editor.
