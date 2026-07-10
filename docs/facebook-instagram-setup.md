# Facebook + Instagram integration — Meta setup guide

The code side (sign-in with Facebook, account linking, direct publishing of
social-network template variants to Facebook Pages / Instagram) is fully
implemented. What it needs to come alive is a **Meta app** and its credentials.
This is the end-to-end checklist.

## What the integration does

- **Sign in with Facebook** (`/oauth/facebook/login`): logs in users whose
  Facebook identity is linked, auto-links by verified e-mail match, and files a
  standard registration request (admin-approved) for unknown e-mails — social
  sign-up does NOT bypass the invite gate.
- **Connect Facebook** (profile page → „Propojené účty“): requests the
  publishing scopes and stores an encrypted 60-day long-lived token
  (`social_account` table).
- **Publish** (fill page → „Facebook“ / „Instagram“ buttons): posts the rendered
  variant as a Page photo (direct PNG upload) or an Instagram feed photo
  (JPEG via temporary public Minio URL — Meta downloads it).
- **Data-deletion callback** (`POST /oauth/facebook/data-deletion`): required by
  Meta; deletes the person's `social_account` row on request.

Platform constraints to be aware of:

- Posting to **personal profiles is impossible** (Meta removed it in 2018) —
  Facebook publishing targets **Pages** the user manages.
- Instagram publishing requires a **professional account (Business/Creator)
  linked to a Facebook Page**, and feed photos only accept aspect ratios
  between 4:5 and 1.91:1 (9:16 story formats are rejected — the app shows a
  Czech error).
- Instagram rate limit: 100 API-published posts per account per rolling 24 h.

## 1. Prerequisites

1. A personal Facebook account (developer accounts hang off personal ones).
2. A **Meta Business portfolio** at <https://business.facebook.com> — needed for
   Business Verification later. Have company documents (výpis s IČO) and a
   company-domain e-mail ready.
3. Test assets: a Facebook Page you manage + an Instagram account switched to
   **professional** (IG app → Settings → *Business tools*) and **linked to that
   Page** (Page settings → *Linked accounts*).

## 2. Create the developer account + app

4. Register at <https://developers.facebook.com> ("Get Started"), verify
   phone/e-mail.
5. **Create App** → choose the business / Page-management use-case path (the
   dashboard reshuffles often; the goal is a **Business-type app**). Name it
   `wboost`, and connect it to the Business portfolio from step 2.
6. Add products/use cases so the app has:
   - **Facebook Login** (Web),
   - **Instagram → "Instagram API with Facebook Login"** setup — NOT
     "…with Instagram Login": an app gets only one Instagram setup, and we need
     the Facebook-Page route (one connection covers both networks; IG-login
     would also never return an e-mail).
7. **Facebook Login → Settings → Valid OAuth Redirect URIs**:
   ```
   https://wboost.cz/oauth/facebook/check
   https://wboost.cz/oauth/facebook/connect/check
   ```
   (Localhost redirects work automatically while the app is in Development
   Mode — no need to register `http://localhost:8081/...`.)
   Keep *Enforce HTTPS*, *Client OAuth Login* and *Web OAuth Login* enabled.
8. **Settings → Basic**: fill Privacy Policy URL, set **Data Deletion
   Callback URL** to
   ```
   https://wboost.cz/oauth/facebook/data-deletion
   ```
   choose an app icon + category, and copy the **App ID / App Secret**.

## 3. Wire the credentials

Dev (`.env` or `.env.local`):

```
FACEBOOK_APP_ID=<app id>
FACEBOOK_APP_SECRET=<app secret>
```

Prod (Infisical → deployment env): the same two variables, plus a fresh
`SOCIAL_TOKEN_ENCRYPTION_KEY` (`openssl rand -base64 32`) — it encrypts stored
Facebook tokens; rotating it just forces users to reconnect. Defaults for
`META_GRAPH_BASE_URL` (`https://graph.facebook.com/v23.0/` — bump the version
here) are committed in `.env`.

Deploy order doesn't matter: `config/services.php` ships container-level
defaults for all four vars, so a prod deploy made BEFORE the Infisical values
exist boots fine — the Facebook buttons just fail at facebook.com until real
credentials land.

**Prod prerequisite for Instagram**: `UPLOADS_BASE_URL` must be reachable from
the public internet (Meta downloads the temporary JPEG from
`…/social-publish/<uuid>.jpg`). It already serves preview images to browsers in
prod, so this should hold — verify once with `curl` from outside.

## 4. Develop & demo in Development Mode (works immediately)

9. **App Roles** → add the team's Facebook accounts as Developers/Testers.
   In Dev Mode, ALL permissions work for these accounts without any review —
   the whole flow (login → connect → publish to a test Page/IG) can be
   exercised and demoed right away.

## 5. Going live (public users)

10. **Business Verification**: Business portfolio → Security Centre → Start
    verification (documents, domain, phone). Takes days.
11. **App Review → Permissions and Features**: request **Advanced Access** for
    `pages_show_list`, `pages_read_engagement`, `pages_manage_posts`,
    `instagram_basic`, `instagram_content_publish` (confirm `email` +
    `public_profile`, normally auto-granted). Provide a screencast of the wboost
    flow (connect → pick Page/IG → publish a filled template) and demo
    credentials. Expect 2–4 weeks and possibly one round of feedback.
12. Switch the app from Development to **Live** mode. Meta runs recurring
    (annual) data-use checkups — calendar it.

*Fallback:* if Meta's dashboard refuses to combine consumer login with the
business use cases on one app, create a second consumer-type app used ONLY for
sign-in (`email`/`public_profile` need no review) and point the `facebook_login`
client in `config/packages/knpu_oauth2_client.yaml` at it — the two-client
config makes this a config-only change.

## Error handling reference (what users see)

| Situation | Behaviour |
|---|---|
| Token expired/revoked (Graph 190) | `social_account.needs_reconnect` is set; UI shows „Znovu propojit“ on the profile + reconnect CTA in the publish modal |
| Missing permission (10 / 200-299 / 803) | 409 + prompt to reconnect and grant everything |
| IG rate limit (4/17/32/613, subcode 2207042) | 429 „denní limit“ |
| Unsupported aspect ratio (36003 / 2207009) | Czech explanation of the 4:5–1.91:1 rule |
| IG container ERROR/timeout | „Instagram nedokázal obrázek zpracovat“ |

Graph calls all flow through `Services/Meta/MetaGraphApi` (faked in tests via
`tests/Fakes/FakeMetaGraphApi`).
