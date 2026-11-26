# Social publishing — Instagram per project (+ the Facebook slot)

Since 2026-11-26 a **project** can be connected to one Instagram professional
account, and everybody who fills that project's templates can publish the
filled picture straight to it, with a caption. Meta setup (the manual part):
`docs/instagram-setup.md`.

## Model

- **`project_social_account`** (`Entity/ProjectSocialAccount`): one row per
  project **and platform** (`SocialPlatform`: `instagram` today). Shaped for a
  second platform on purpose: a Facebook Page connection is the planned next
  case (see "Adding Facebook" below).
  - `accountId` = the id publishing addresses (Instagram: `/me` `user_id`),
    `accountUserId` = the app-scoped id Meta's callbacks may carry
    (Instagram: `/me` `id`); deauthorize / deletion match EITHER.
  - `accessToken` encrypted with `TokenCrypto` (`SOCIAL_TOKEN_ENCRYPTION_KEY`),
    `tokenExpiresAt`, `tokenIssuedAt` (Instagram refreshes only tokens ≥ 24 h
    old), `scopes`, `needsReconnect`, `connectedBy` (`SET NULL`), `connectedAt`.
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
  `INSTAGRAM_APP_ID` / `INSTAGRAM_APP_SECRET`, and in prod also off while
  `SOCIAL_TOKEN_ENCRYPTION_KEY` is empty or still the dev key committed in
  `.env` (the image ships `.env`); `INSTAGRAM_ROLLOUT=admins`
  (default) = ROLE_ADMIN only, `everyone` = all signed-in users. Outside the
  gate the project page says "připravujeme", the fill page's Instagram item
  opens the fake door (`_social_publish_fake_door.html.twig`), connect and
  publish refuse. Switch to `everyone` only after Meta's App Review.

## Connect flow (Instagram Login = "Business Login for Instagram")

`InstagramConnectStartController` (`GET /project/{projectId}/instagram/connect`)
→ `www.instagram.com/oauth/authorize` with scopes `instagram_business_basic,
instagram_business_content_publish`, `force_reauth=true` (an agency person
logged into one IG account must not silently attach it to every project) and
a random `state` stored in the session together with the project id.

`InstagramConnectCheckController` (`GET /oauth/instagram/check`) is the ONE
redirect URI registered in the Meta app (must match exactly, so it cannot
carry the project): pops the state (single use, `hash_equals`), re-checks
`project_edit`, handles `error=access_denied`, refuses when
`instagram_business_content_publish` is missing from a returned permissions
list (a response WITHOUT the list proceeds and logs a warning),
then code → short-lived token (api.instagram.com) → long-lived 60-day token
(graph.instagram.com `ig_exchange_token`) → `/me` → `ConnectProjectInstagram`
(insert, or reconnect the existing row in place).

All Instagram HTTP goes through `Services/Instagram/InstagramApi`
(`InstagramApiInterface`, faked by `tests/Fakes/FakeInstagramApi`). Base URL
`INSTAGRAM_GRAPH_BASE_URL` (versioned, default `https://graph.instagram.com/v24.0/`);
the token endpoints are unversioned absolute URLs. Responses are read both
flat and wrapped in `data: [ … ]` (Meta's docs show both).

## Publishing

`POST /template-variant/{variantId}/publish` with `platform=instagram`
(`TemplateVariantPublishController::publishToInstagram`):

- Destination is resolved from `variant.template.project` — a posted
  `targetId` is ignored for Instagram.
- Preset-gated like before (`dimension.preset` required). `InstagramPostKind`:
  1:1 / 4:5 → **feed post** with caption (≤ 2 200 chars, checked server-side);
  **9:16 → story** (`media_type=STORIES`, no caption — Instagram stories have
  none; the modal hides the caption box and says so).
- `PublishToProjectInstagram`: same render as the PNG download → JPEG →
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

Long-lived tokens last 60 days and die for good unless refreshed.

- **Daily cron** `app:instagram:refresh-tokens` (lily.srv
  `apps/wboost/cron.d/wboost`, 03:30): refreshes every connection expiring
  within 30 days (`isDueForRefresh`), `--force` refreshes all ≥ 24 h old.
  A 190 on refresh flags the row instead of failing the run.
- **Safety net at publish time** (`ProjectInstagramConnection::accessToken`):
  a token within 7 days of expiry is refreshed before publishing; a failed
  refresh is logged and the still-valid token is used.

## Meta's callbacks (public routes, `security.php` carve-out)

Signed with the **Instagram app secret** (`Services/Meta/MetaSignedRequest`,
shared with the Facebook data-deletion callback):

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
- Fill page (`VariantFiller.html.twig`, Live props `instagramAvailable`,
  `instagramAccount`, set by `TemplateVariantExportController`): "Sdílet na
  sítě" menu → "Publikovat na Instagram" ("(story)" on 9:16) opens the
  `variant-publish` modal with the fixed destination `@handle`, caption +
  counter, success with "Zobrazit příspěvek". Not connected → the modal links
  to the project page in a NEW TAB (the unsaved fill must survive). Facebook
  items keep the personal-link behaviour / fake door.
- Web only: no API / MCP shape change (so no mfkfm port, no skill release).

## Adding Facebook (planned)

The table, page and modal are ready for it: add `SocialPlatform::Facebook`,
a connect flow (Facebook Login → pick ONE Page → store page id in
`accountId`, the Page token — non-expiring when derived from a long-lived user
token, so `isDueForRefresh` is false — and the person's id in
`accountUserId`), a publisher (`/{page-id}/photos`, see `MetaGraphApi`), the
Facebook box on `project_social.html.twig`, and route `platform=facebook` to
the project account in the publish controller. Separate env vars
(`FACEBOOK_APP_*` vs `INSTAGRAM_APP_*`) allow either one Meta app or two.

## Tests

`tests/Controller/Instagram/ProjectInstagramConnectionTest.php` (page, connect
round trip, state, permissions, disconnect, callbacks, refresh command, fill
chrome), `tests/Controller/Template/TemplateVariantPublishControllerTest.php`
(feed, story, shared member, not connected, lazy refresh, 190 flag, caption
length), `tests/Services/Instagram/InstagramPublishingTest.php` (rollout, prod key),
`tests/Services/Instagram/InstagramApiTest.php` (the real client vs Meta's
documented shapes on a MockHttpClient).
Not verifiable without a Meta app: the real OAuth dance and real publishing.
