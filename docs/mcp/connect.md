# Connect an AI client to wboost

wboost exposes an MCP server at **`https://wboost.cz/_mcp`**. Connecting an AI
client to it lets the assistant see your projects and brand assets, fill a
template variant's placeholders, hand you the exported PNG, add pictures to a
project gallery and author a variant's design — without you opening the app.

There are two ways to authenticate, and **OAuth is the one to use**:

| | OAuth 2.1 + PKCE | Personal access token (PAT) |
|---|---|---|
| who can set it up | **you**, in a browser | an operator with shell access |
| what you paste | nothing | a `wb_mcp_…` secret |
| where the credential lives | the client's own credential store | wherever you pasted it |
| you can revoke it yourself | **yes**, at [`/user-profile/connected-apps`](https://wboost.cz/user-profile/connected-apps) | ask an operator |
| good for | people | CI, scripts, headless agents |

Both behave identically once inside — same account, same scopes, same tools. The
server accepts either on the same endpoint.

wboost runs its own authorization server with **dynamic client registration**
(RFC 7591) enabled in production, so a client registers itself, sends you to a
consent screen naming the application and the permissions it wants, and gets its
own credentials. Nothing has to be registered by hand any more.

---

## 1. Install the plugin (recommended)

One line, from anywhere:

```bash
claude plugin marketplace add TheDevs-cz/wboost && claude plugin install wboost@wboost
```

**No token, no prompt.** Restart Claude Code (or start a new session) and the
first time the `wboost` server is used, Claude Code discovers wboost's
authorization server, registers itself, and opens your browser. You sign in to
wboost, read what the connector is asking for, and approve — after that the
session is connected.

If the browser step does not happen on its own, trigger it:

```bash
claude mcp login wboost
```

What you get:

- the MCP server `wboost` with its nine tools;
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

### Why the plugin does not ask for a token

It used to, and that was the wrong default: only an operator can mint a PAT, so
"one command to install" was not true for anybody else. The plugin's `.mcp.json`
now declares the server with **no `Authorization` header at all**, which is
precisely what makes the client fall back to OAuth discovery.

This is not a cosmetic difference — an `Authorization` header suppresses the
OAuth path entirely, because the client believes it is already authenticating.
So there is deliberately no "optional token" setting on the plugin: an unset one
would send an empty `Authorization: Bearer` header, and you would get a bare 401
with no browser flow and nothing explaining why. If you want to use a PAT, use
the manual connection below instead of the plugin.

---

## 2. Manual connection (no plugin)

Tools only — no skill, no slash commands.

**With OAuth** (nothing to paste):

```bash
claude mcp add --transport http wboost https://wboost.cz/_mcp
claude mcp login wboost      # if the browser does not open by itself
```

**With a personal access token:**

```bash
claude mcp add --transport http wboost https://wboost.cz/_mcp \
  --header "Authorization: Bearer wb_mcp_…"
```

Add `--scope user` to either to make it available in every project instead of
only the current directory.

⚠️ The token form writes the secret in **plaintext** into your Claude Code
config. Prefer the OAuth form unless you are scripting something headless.

Without the skill the assistant has to work the tool descriptions out on its own.
They are written for that, but the skill is what encodes the judgement — render
before export, ids not names, what a container overflow means, and what
`acknowledgeLosses` really does.

---

## 3. Get a personal access token (only if you need one)

A PAT is for the cases OAuth does not fit: CI, a scripted setup, a headless agent
with no browser. It acts as you, and its scopes can only *narrow* what your own
account may reach, never widen it. Only an operator with shell access to the
production box can mint one:

```bash
# on the wboost box, inside the web container
bin/console app:mcp:token:create you@example.com \
  --name="Claude Code" \
  --scopes=templates:read,templates:export
```

The secret is printed **once** and looks like `wb_mcp_…`. Only its SHA-256 is
stored, so a lost token is replaced (create a new one, revoke the old), never
recovered.

```bash
bin/console app:mcp:token:list          # every token ever issued, with its status
bin/console app:mcp:token:revoke <id>   # kill one; idempotent
```

---

## Scopes

The same four scopes apply to both credential types. With OAuth you approve them
on the consent screen; with a PAT they are the comma-separated `--scopes`. Pick
the narrowest that does the job.

| scope | what it unlocks | notes |
|---|---|---|
| `templates:read` | `get_context`, `find_templates`, `describe_variant`, `list_gallery`, `render_variant` | the default when `--scopes` is omitted |
| `templates:export` | `export_variant` — the full-size lossless PNG | **implies** `templates:read`; exports are counted in the usage report |
| `templates:design` | `preview_design`, `set_design` — author a variant's design | **implies** `templates:read`; also needs you to OWN the project |
| `gallery:write` | `upload_image` — add a picture to a project's gallery | implies **nothing**: grant it alongside another scope if the agent should also browse |

A tool the credential lacks the scope for is **not listed** to the client at all,
and is refused (403 `insufficient_scope`) if called anyway.

A read-only agent is `templates:read`. An agent that hands the user a finished
file is `templates:export` — no need to list `templates:read` alongside it. An
agent that designs is `templates:design`, usually with `gallery:write` so it can
add the pictures its designs reference.

Two things worth knowing about `templates:design`:

- **Scope is not permission.** Effective access is scope ∩ what the account can
  already do, and designing requires *owning* the project. A project merely
  shared with you grants viewing, rendering and exporting; `set_design` refuses
  with a message that says exactly that.
- **Grouped variants are refused.** A variant belonging to a synchronized
  template group shares one design across its dimensions, so it is authored only
  in the wboost group editor. `preview_design` still works on them.

---

## Check it works

In a Claude Code session:

```
/mcp
```

You should see a `wboost` server, connected, listing exactly the tools your
scopes allow — five for `templates:read`, six with `templates:export`, and up to
nine with `templates:design` and `gallery:write` as well. A server shown as
needing authentication is the OAuth flow waiting for you; `claude mcp login
wboost` starts it.

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

curl -s https://wboost.cz/.well-known/oauth-authorization-server
# … "registration_endpoint":"https://wboost.cz/api/register" ⇒ self-registration is live
```

There is also a Czech in-app guide at **`https://wboost.cz/ai`** that describes
this server's capabilities from the running code, and
**`/user-profile/connected-apps`** lists every application you have connected,
with a button to revoke each one.

---

## Troubleshooting

**`/mcp` shows the server as "needs authentication".**
That is the OAuth flow waiting for a browser. Run `claude mcp login wboost`. If
the browser cannot open (a remote shell, a container), use a PAT and the manual
connection instead.

**`/mcp` shows the server as failed, or every call answers 401.**
With a PAT: it is wrong, revoked, expired, or belongs to a deactivated user —
check `app:mcp:token:list`. With OAuth: the authorization may have been revoked
at `/user-profile/connected-apps`; `claude mcp login wboost` re-authorizes.
A 401 carries a `WWW-Authenticate: Bearer resource_metadata="…"` header even when
nothing was sent, so its presence only proves you reached the right endpoint, not
that a credential was read.

**401 and the browser flow never starts.**
Something is sending an `Authorization` header. A client that thinks it is
authenticating will not fall back to OAuth, so an empty or stale header is a dead
end rather than a retry. Remove the header (or drop and re-add the server with no
`--header`) and the discovery runs.

**A tool the docs mention is missing from `/mcp`'s list.**
Your credential lacks that tool's scope. `export_variant` needs
`templates:export`; `preview_design` / `set_design` need `templates:design`;
`upload_image` needs `gallery:write`. With OAuth, re-authorize and approve the
missing permission. With a PAT, mint a new one — scopes cannot be edited on an
existing token.

**The tool list is empty but the server is connected.**
Same cause, taken to its conclusion: a credential whose scopes parse to nothing.
An unknown scope string is refused at creation time, so for a PAT this usually
means the token was hand-edited in the database.

**`Project X was not found, or this account cannot access it.`**
Exactly what it says, and it is the *same* message for "no such project" and "not
yours" — deliberately, so the API cannot be used to discover other accounts'
projects. Call `get_context` and use an id from there.

**`Template variant X can be read by this account but not designed on.`**
Not a scope problem. Designing requires owning the project; sharing grants
viewing only. Ask the owner to make the change, or to transfer ownership.

**Everything works but the assistant fills nothing** — the picture comes back
looking like the untouched design.
The fill was keyed by input *names* instead of ids. The tools report this in
`warnings[]` rather than failing. Ids come from `describe_variant`; the skill
covers this at length.

**`export_variant` refuses with a container overflow.**
Not a connection problem: the filled text is taller than the height the designer
allowed. Shorten a text, hide a `hidable` input, or have the designer raise the
container's `maxHeight`. `render_variant` will show you the overflow while you
work.

**`set_design` refuses with `overwrite` issues.**
Also not a connection problem, and **not something to retry with
`acknowledgeLosses: true` reflexively.** It means the design being replaced
contains something this DSL cannot express — most often a background uploaded
through the variant form rather than the gallery — and saving would destroy it.
Read the list, prefer the repair the message names (re-upload that picture with
`upload_image` and reference it by id), and only acknowledge if losing the listed
things is genuinely intended.

**`The image renderer is busy and did not answer in time.`**
Transient — the shared render service was saturated. Nothing was changed; repeat
the call.

**A plugin change does not show up.**
Restart the session. If it still does not, the plugin cache at
`~/.claude/plugins/cache` may be stale — `claude plugin marketplace update wboost`
refreshes it.

---

## What the tools can and cannot do

Nine tools:

| tool | scope | what it does |
|---|---|---|
| `get_context` | `templates:read` | the account, its scopes, and every project's brand fonts, colours and dimensions |
| `find_templates` | `templates:read` | one project's templates and variant summaries |
| `describe_variant` | `templates:read` | one variant's fillable surface — input ids, their rules, image slots, containers |
| `list_gallery` | `templates:read` | one level of a project's picture library |
| `render_variant` | `templates:read` | a cheap downscaled WebP preview of a filled variant |
| `export_variant` | `templates:export` | the deliverable — full-size lossless PNG, recorded as an export |
| `upload_image` | `gallery:write` | add a picture to a project's gallery and get its id back |
| `preview_design` | `templates:design` | compile and draw a design document without saving anything |
| `set_design` | `templates:design` | replace a variant's design, guarded against destroying what it cannot express |

**Nothing here deletes anything** — not a template, not a variant, not a picture.
Gallery pictures can be added but never removed, moved or renamed from a client;
that stays a human job in wboost.

Two capabilities are deliberately absent: there is **no tool that reads a design
back** as a document (so `set_design` authors designs rather than edits them),
and there is **no tool for group design** (a synchronized group's shared design
is authored in the wboost group editor).
