# API Authentication

The REST API at `/api` is protected by **OAuth2** using the **`client_credentials`**
grant. It is built for **service-to-service** communication — there is no
interactive login, redirect, or consent screen.

Authentication is a **two-step** flow:

1. A service exchanges its **client_id + client_secret** for a short-lived
   **access token** at `POST /api/token`.
2. The service sends that token as `Authorization: Bearer <token>` on every
   subsequent `/api/*` request.

The client credentials themselves are **never** sent to the data endpoints —
only the bearer token is. The token is a signed **JWT (RS256)** validated
locally by the resource server (no DB round-trip per request).

> **Per-user, not per-project.** Each OAuth2 client is linked to exactly one
> application `User`. The token carries that user's UUID in its `sub` claim, and
> every endpoint scopes its results to that user (e.g. projects are filtered by
> `owner_id`). A credential therefore grants access to everything that user owns.
> There is no project-scoped credential — to isolate a project, create a user
> that owns only it. See the **API** section of the root `CLAUDE.md` for the
> resource list and per-resource details.

---

## Step 1 — Create credentials (admin, via console)

Credentials are minted with a console command. Pass the **email of the user**
the client will act on behalf of:

```bash
docker compose exec web bin/console app:oauth-client:create user@example.com --name=service-name
```

Output (the **secret is shown once and cannot be recovered** — store it now):

```
 --------------- ------------------------------------------------------------------
  Client ID       ea2e8d9bd31055a87c68f8850eb087f1
  Client Secret   dc7510aa642cb49520317e6e3f1e8d9e889f92a121fe679bc8c6ba94aa91e3f7
  Linked user     user@example.com
  Grant           client_credentials
 --------------- ------------------------------------------------------------------
```

This does two things (`src/ConsoleCommands/OAuth2/CreateOAuth2ClientConsoleCommand.php`):

- registers an OAuth2 client (random 16-byte id, 32-byte secret) restricted to
  the `client_credentials` grant, and
- writes a row in `oauth2_client_user` linking the client id → the `User`
  (`src/Entity/OAuth2ClientUser.php`).

A user may have **multiple** clients; every client maps back to a **single** user.

### List clients

```bash
docker compose exec web bin/console app:oauth-client:list
```

```
  Client ID                          Name           Linked user        Active   Grants
  ea2e8d9bd31055a87c68f8850eb087f1   service-name   user@example.com   yes      client_credentials
```

### Revoke a client

Deactivates the client **and** revokes its outstanding access tokens
(`src/ConsoleCommands/OAuth2/RevokeOAuth2ClientConsoleCommand.php`):

```bash
docker compose exec web bin/console app:oauth-client:revoke <client-id>
```

After revoke, `POST /api/token` returns `401 invalid_client` and any tokens
already issued stop validating.

---

## Step 2 — Obtain an access token

`POST /api/token` is a public endpoint (its own open firewall —
`config/packages/security.php`). Send form-encoded credentials:

```bash
curl -sX POST https://example.com/api/token \
    -d 'grant_type=client_credentials' \
    -d 'client_id=ea2e8d9bd31055a87c68f8850eb087f1' \
    -d 'client_secret=dc7510aa642cb49520317e6e3f1e8d9e889f92a121fe679bc8c6ba94aa91e3f7'
```

Response:

```json
{
    "token_type": "Bearer",
    "expires_in": 3600,
    "access_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9.eyJhdWQiOiJlYTJ..."
}
```

| Field | Meaning |
|---|---|
| `token_type` | Always `Bearer`. |
| `expires_in` | Token lifetime in seconds — **3600 (1 hour)**. |
| `access_token` | The JWT to send on subsequent requests. |

### What's inside the token

The `access_token` is an RS256 JWT. Its payload (base64url-decode the middle
segment) looks like:

```json
{
  "aud": "ea2e8d9bd31055a87c68f8850eb087f1",   // the client id
  "jti": "c4bf2114...",                          // unique token id
  "iat": 1780488379,                             // issued-at
  "nbf": 1780488379,                             // not-before
  "exp": 1780491979,                             // expiry (iat + 3600)
  "sub": "019e0700-a15b-71d2-b2f8-b38399c4b203", // linked App User UUID
  "scopes": ["api"]
}
```

The `sub` claim is set by `IssueAccessTokenWithUserListener`
(`src/Services/OAuth2/IssueAccessTokenWithUserListener.php`) from the
`oauth2_client_user` mapping. The `api` firewall reads it via `api_user_provider`
and loads the matching `User` to scope data. Clients do not need to parse the
JWT — treat it as an opaque bearer string.

---

## Step 3 — Call the API

Send the token in the `Authorization` header:

```bash
curl -s https://example.com/api/projects \
    -H 'Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9...' \
    -H 'Accept: application/json'
```

Responses are plain JSON arrays (JSON-LD / Hydra is disabled). A request with a
missing, malformed, expired, or revoked token returns **`401`**.

---

## Token expiry & "refresh"

**There are no refresh tokens.** The `refresh_token` grant is disabled
(`enable_refresh_token_grant: false` in `config/packages/league_oauth2_server.php`),
so requesting one returns:

```json
{ "error": "unsupported_grant_type", "error_description": "The authorization grant type is not supported by the authorization server." }
```

This is the standard pattern for `client_credentials`: the client already holds
long-lived credentials, so when a token expires it simply **requests a new one**
from `/api/token`. Recommended consumer behaviour:

- Cache the token in memory and reuse it until shortly before `expires_in`
  elapses (e.g. refresh at ~90% of its lifetime).
- Or lazily re-request on the first `401`, then retry the call once.

Do **not** call `/api/token` on every request.

---

## Error reference

| Situation | HTTP | Body / notes |
|---|---|---|
| Valid credentials | `200` | `{ token_type, expires_in, access_token }` |
| Wrong `client_secret` | `401` | `{ "error": "invalid_client" }` |
| Unknown / revoked / inactive client | `401` | `{ "error": "invalid_client" }` |
| `grant_type=refresh_token` (or any disabled grant) | `400` | `{ "error": "unsupported_grant_type" }` |
| Calling `/api/*` with no / bad / expired token | `401` | — |

---

## Configuration reference

| Setting | Value | Where |
|---|---|---|
| Grant type | `client_credentials` only | `config/packages/league_oauth2_server.php` |
| Access token TTL | `PT1H` (3600s) | same |
| Refresh tokens | **disabled** | same (`enable_refresh_token_grant: false`) |
| Password / auth-code / implicit grants | **disabled** | same |
| Scopes | `api` (the only and default scope) | same |
| Signing | RS256; private key signs, public key verifies | same |
| RSA keys | base64-encoded PEM **in env vars** (`OAUTH2_*`), decoded via `%env(base64:...)%` — no key files on disk | `.env` / `.env.local` |
| `/api/token`, `/api/authorize` | public (own firewall, `security: false`) | `config/packages/security.php` |
| `^/api` (everything else) | stateless, `oauth2: true`, provider `api_user_provider` | same |

---

## End-to-end example

```bash
BASE_URL=https://example.com
CLIENT_ID=ea2e8d9bd31055a87c68f8850eb087f1
CLIENT_SECRET=dc7510aa642cb49520317e6e3f1e8d9e889f92a121fe679bc8c6ba94aa91e3f7

# 1. Get a token
ACCESS_TOKEN=$(curl -sX POST "$BASE_URL/api/token" \
    -d 'grant_type=client_credentials' \
    -d "client_id=$CLIENT_ID" \
    -d "client_secret=$CLIENT_SECRET" \
  | python3 -c 'import json,sys; print(json.load(sys.stdin)["access_token"])')

# 2. Use it
curl -s "$BASE_URL/api/projects" \
    -H "Authorization: Bearer $ACCESS_TOKEN" \
    -H 'Accept: application/json'
```

> **Local dev:** replace `https://example.com` with your dev URL (default
> `http://localhost:8080`, or whatever `WEB_PORT` you've mapped). The flow is
> identical. Discover endpoints at `GET /api/docs`.

---

## Verifying the flow

The contract is covered by automated tests that drive the real `/api/token`
endpoint — `tests/Api/ProjectsTest.php` (token issuance, `sub` claim, wrong
secret → `invalid_client`, inactive client, authenticated data fetch) via the
`tests/TestingApiAuthentication.php` helper:

```bash
docker compose exec web vendor/bin/phpunit tests/Api/ProjectsTest.php
```
