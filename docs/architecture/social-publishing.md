# Social publishing — Instagram per project (+ the Facebook slot)

Since 2026-11-26 a **project** can be connected to one Instagram professional
account, and everybody who fills that project's templates can publish the
filled picture straight to it, with a caption. Meta setup (the manual part):
`docs/instagram-setup.md`.

**2026-11-27: moved from "Instagram API with Instagram Login" to "Instagram
API with Unified Login"** — Meta's migration guide: "Newly created apps can't
set up the Instagram API with Instagram Login", and new features ship only on
Unified Login. Nothing was ever connected in prod with the old flow.

## Model

- **`project_social_account`** (`Entity/ProjectSocialAccount`): one row per
  project **and platform** (`SocialPlatform`: `instagram` today). Shaped for a
  second platform on purpose: a Facebook Page connection is the planned next
  case (see "Adding Facebook" below).
  - `accountId` = the id publishing addresses (Instagram: `id` from
    `/me/assigned_instagram_accounts`), `accountUserId` = the token owner's
    app-scoped `/me` `id` (the Business Integration System User). Meta's
    callbacks match EITHER — which id a Unified Login callback carries is not
    documented (unverified).
  - `accessToken` encrypted with `TokenCrypto` (`SOCIAL_TOKEN_ENCRYPTION_KEY`),
    `tokenExpiresAt` (null = never expires — the configuration is set up
    that way), `tokenIssuedAt` (= connected), `scopes` (empty: permissions
    live in the Unified Login configuration), `needsReconnect`, `connectedBy`
    (`SET NULL`), `connectedAt`. `accountType` stays null (the shared-accounts
    edge doesn't return it).
  - Project deleted → row cascades. The same Instagram account may be
    connected to several projects (no uniqueness on `accountId`).
- Not to be confused with `social_account` (`SocialProvider`): the USER's
  personal Facebook link (sign-in + publishing to that user's own Facebook
  Pages, 2026-07). That path is untouched; it no longer offers Instagram.

## Who can do what

- **Connect / change / disconnect**: `project_edit` (owner + admins).
- **Publish**: anyone who can fill the variant (`TemplateVariantVoter::VIEW`)
  — the connection belongs to the project, shared members included.
- **Rollout gate** (`Services/Instagram/InstagramPublishing`): off without
  `INSTAGRAM_META_APP_ID` / `INSTAGRAM_META_APP_SECRET` /
  `INSTAGRAM_LOGIN_CONFIG_ID`, and in prod also off while
  `SOCIAL_TOKEN_ENCRYPTION_KEY` is empty or still the dev key committed in
  `.env` (the image ships `.env`); `INSTAGRAM_ROLLOUT=admins`
  (default) = ROLE_ADMIN only, `everyone` = all signed-in users. Outside the
  gate the project page says "připravujeme", the fill page's Instagram item
  opens the fake door (`_social_publish_fake_door.html.twig`), connect and
  publish refuse. Switch to `everyone` only after Meta's App Review.

## Connect flow (Unified Login = Facebook Login for Business + `config_id`)

Env: `INSTAGRAM_META_APP_ID` / `INSTAGRAM_META_APP_SECRET` = the WBoost Meta
app's own credentials (App settings → Basic — NOT an "Instagram app id";
kept apart from `FACEBOOK_APP_*`, the personal sign-in app),
`INSTAGRAM_LOGIN_CONFIG_ID` = the Unified Login configuration (its Login
URL's `config_id`; permissions + token expiry are chosen there, so no
`scope` parameter is ever sent).

`InstagramConnectStartController` (`GET /project/{projectId}/instagram/connect`)
→ `www.facebook.com/dialog/oauth?client_id&config_id&redirect_uri&response_type=code&state&prompt=login`
(`prompt=login`: an agency person logged into Facebook must not silently
continue as that account) with a random `state` stored in the session
together with the project id.

`InstagramConnectCheckController` (`GET /oauth/instagram/check`) is the ONE
redirect URL saved in the configuration (must match exactly, so it cannot
carry the project): pops the state (single use, `hash_equals`), re-checks
`project_edit`, handles `error=access_denied`, then code →
`GET graph.facebook.com/v27.0/oauth/access_token` (Meta app id + secret +
redirect_uri) → a Business Integration System User token (belongs to the
client's business portfolio; `expires_in` absent = never expires) →
`GET /me/assigned_instagram_accounts?fields=id,username,name`:

- none → flash „Meta nesdílela žádný instagramový účet…“, nothing stored;
- one → `ConnectProjectInstagram` (insert, or reconnect the existing row in
  place);
- several → the pending grant (token ENCRYPTED, accounts, owner id) goes to
  the session and `InstagramConnectPickController`
  (`GET|POST /project/{projectId}/instagram/pick`, `project_edit`, CSRF
  `project_instagram_pick`, `templates/project_instagram_pick.html.twig`, one
  form per account) connects the chosen one; single use.

`GET /me?fields=id` (token owner id → `accountUserId`) is informational: a
failure there is logged, never blocks connecting.

All Instagram HTTP goes through `Services/Instagram/InstagramApi`
(`InstagramApiInterface`, faked by `tests/Fakes/FakeInstagramApi`). Base URL
`INSTAGRAM_GRAPH_BASE_URL` (versioned, default
`https://graph.facebook.com/v27.0/`). Every token-bearing call also sends
`appsecret_proof` (HMAC-SHA256 of the token with the app secret), so the
app's "Require app secret" switch may be on. Single-object responses are read
both flat and wrapped in `data: [ … ]`.

## Publishing

`POST /template-variant/{variantId}/publish` with `platform=instagram`
(`TemplateVariantPublishController::publishToInstagram`):

- Destination is resolved from `variant.template.project` — a posted
  `targetId` is ignored for Instagram.
- **Every format publishes** (2026-11-27). `TemplateDimension::instagramPostKind()`:
  a preset decides, otherwise an exact 9:16 px size is a story and anything
  else a feed post; `fitsInstagramAsIs()` false → the dialog shows a hint and
  `InstagramImageFit` pads the JPEG with white to the nearest accepted ratio
  (4:5 / 1.91:1) and scales it to ≤ 1440 px wide. Facebook takes any size. `InstagramPostKind`:
  1:1 / 4:5 → **feed post** with caption (≤ 2 200 chars, checked server-side);
  **9:16 → story** (`media_type=STORIES`, no caption — Instagram stories have
  none; the modal hides the caption box and says so).
- `PublishToProjectInstagram`: same render as the PNG download → `InstagramImageFit` (JPEG, padding, ≤ 1440 px) →
  temp object `social-publish/<uuid>.jpg` on the PUBLIC bucket (Instagram
  downloads it itself, so `UPLOADS_BASE_URL` must be internet-reachable —
  impossible on localhost) → container → poll `status_code` (12 × 1.5 s) →
  `media_publish` → `permalink` (cosmetic, failure ignored) → temp object
  deleted in `finally`.
- Synchronous on purpose (actionable errors; a retrying async transport could
  double-post). The fill page's busy button prevents double clicks.
- Recorded like every export: `ExportEvent` + export version on
  `ExportChannel::Instagram`.
- Errors (`InstagramApi::mapError`): 190 → `InstagramTokenExpired` (row flagged
  `needsReconnect`, 409 + reconnect link), 10 / 200-299 →
  `InstagramPermissionMissing` (409), 4/17/32/613 / 2207042 → rate limit (429),
  36003 / 2207009 → unsupported ratio, container ERROR / timeout → 502 with a
  Czech message. Meta's quota: 50–100 API posts per account per 24 h.

## Token lifetime

The Unified Login configuration is set to **never-expiring** tokens
(docs/instagram-setup.md step 3), so there is no refresh code. A token still
dies when the client removes the app from their portfolio or moves the
Instagram account to another portfolio → 190 → reconnect.

- **Nightly check** `app:instagram:check-tokens` (alias
  `app:instagram:refresh-tokens` — the name the lily.srv cron line
  `apps/wboost/cron.d/wboost`, 03:30, still uses): for every connection not
  yet flagged, a token past a stored `tokenExpiresAt` or one Meta rejects
  (`/me/assigned_instagram_accounts` → 190) or one that can't be decrypted
  flags `needsReconnect`; other API errors fail the run (Sentry monitor) and
  retry tomorrow.
- If a configuration with EXPIRING tokens is ever chosen, the stored
  `tokenExpiresAt` makes the connection unusable after expiry (reconnect);
  renewing would need the BISU token management API
  (`/{client-business-id}/system_user_access_tokens`) — not built.

## Meta's callbacks (public routes, `security.php` carve-out)

Signed with the **Meta app secret** (`INSTAGRAM_META_APP_SECRET`;
`Services/Meta/MetaSignedRequest`, shared with the Facebook data-deletion
callback). Configured in App settings → Basic (data deletion callback) and,
if the field exists, Facebook Login for Business → Settings (deauthorize).
Meta's Unified Login docs don't describe these callbacks — the `user_id`
they carry is matched against both stored ids:

- `POST /oauth/instagram/deauthorize` — the person removed WBoost in Instagram
  → all project rows with that id are deleted.
- `POST /oauth/instagram/data-deletion` — same + `{url, confirmation_code}`;
  status page `GET /oauth/instagram/data-deletion/status`.

## UI

- Left nav "Facebook a Instagram" (last project item, every project) →
  `project_social` (`/project/{projectId}/social`, `ProjectSocialController`,
  `templates/project_social.html.twig`): Instagram box (status, connect /
  change / disconnect for editors, "Propojí správce projektu" for members,
  "připravujeme" outside the rollout) + the Facebook box ("připravujeme").
  `project_instagram_pick` = the account picker after a login that shared
  several Instagram accounts.
- Fill page (`VariantFiller.html.twig`, Live props `instagramAvailable`,
  `instagramAccount`, set by `TemplateVariantExportController`): "Sdílet na
  sítě" menu → "Publikovat na Instagram" ("(story)" on 9:16) opens the
  `variant-publish` modal with the fixed destination `@handle`, caption +
  counter, success view (big tick, „Zobrazit příspěvek“, caption cleared). Not connected → the modal links
  to the project page in a NEW TAB (the unsaved fill must survive). Facebook
  items keep the personal-link behaviour / fake door.
- Web only: no API / MCP shape change (so no mfkfm port, no skill release).

## Adding Facebook (planned)

The table, page and modal are ready for it: add `SocialPlatform::Facebook`,
a connect flow (most likely Facebook Login for Business on the SAME Meta app
with a second configuration that asks for a Page — the WBoost app already
uses FLB for Instagram; pick ONE Page → store page id in `accountId`, a
non-expiring Page / system-user token, and the owner id in
`accountUserId`), a publisher (`/{page-id}/photos`, see `MetaGraphApi`), the
Facebook box on `project_social.html.twig`, and route `platform=facebook` to
the project account in the publish controller. `FACEBOOK_APP_*` (personal
sign-in app) and `INSTAGRAM_META_APP_*` are separate env vars, so either one
Meta app or two works.

## Tests

`tests/Controller/Instagram/ProjectInstagramConnectionTest.php` (page, connect
round trip, state, no shared account, account picker, disconnect, callbacks,
check command, fill chrome),
`tests/Controller/Template/TemplateVariantPublishControllerTest.php` (feed,
story, shared member, not connected, expired token, 190 flag, caption
length), `tests/Services/Instagram/InstagramPublishingTest.php` (rollout,
config id, prod key), `tests/Services/Instagram/InstagramApiTest.php` (the
real client vs Meta's documented shapes on a MockHttpClient, incl.
`appsecret_proof`).
Not verifiable without a Meta app: the real login, whether the code exchange
wants `redirect_uri` (sent; Facebook Login normally requires it), the token
expiry options the dashboard offers, which id the callbacks carry, and real
publishing.
