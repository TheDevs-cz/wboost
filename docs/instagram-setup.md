# Instagram publishing per project: Meta setup guide

The code is done: per-project Instagram connection, publishing from the fill
page, token refresh and Meta's callbacks (`docs/architecture/social-publishing.md`).
It switches on once a **Meta app** exists and its credentials are in production.
These are the manual steps.

## How it works (why this is OAuth)

WBoost publishes on behalf of the client, so the client must **grant
permission**. That is OAuth 2.0 via Meta's *"Instagram API with Instagram
Login"* (Business Login for Instagram):

1. In a project, an editor clicks **Propojit Instagram**.
2. Instagram opens, the person logs in with the account the project will
   publish to, and allows *"basic info"* + *"publish content"*.
3. Instagram sends them back to WBoost with a one-time code. WBoost swaps it
   for an access token (valid 60 days, renewed automatically every night) and
   stores it encrypted with the project.
4. From then on, anyone working in the project can publish via **Sdílet na
   sítě → Publikovat na Instagram** and write the caption. A 9:16 template is
   posted as a story.

No password ever reaches WBoost. The client can revoke access anytime in
Instagram (Settings → Apps and websites), and WBoost drops the connection
automatically.

**Constraints Meta sets:**

- Only **professional** Instagram accounts (Business or Creator). A personal
  account can be switched for free: Instagram app → Settings → *Account type
  and tools* → *Switch to professional account*.
- No Facebook Page is needed for this route.
- Up to ~50–100 API posts per account per 24 h.
- Until Meta's **App Review** approves the app, only Instagram accounts added
  as **testers** can connect. That's why the feature is admins-only by
  default (`INSTAGRAM_ROLLOUT=admins`).

---

## Step 1: Accounts you need (once)

1. **Facebook account** for the developer login (your personal one is fine).
2. **Meta Business portfolio** at <https://business.facebook.com> for the
   company (Wantoo Design s. r. o.). It is needed for Business Verification in
   step 7. If one exists already, use it.
3. **A test Instagram account** that is *professional* and **public** (Meta
   requires public for testers). A real client account you manage, or WBoost's
   own account, works.

## Step 2: Create the Meta app

1. Go to <https://developers.facebook.com/apps> → **Create app**.
2. App name **WBoost**, contact e-mail: the company one (e.g. info@wantoo.cz).
3. **Use case**: pick the Instagram one. It's currently labelled *"Manage
   messaging & content on Instagram"*; Meta renames these often, so pick
   whichever mentions Instagram content / publishing.
4. **Business**: connect the Business portfolio from step 1.
5. Finish. If the dashboard asks for an *app type*, pick **Business**. A
   non-Business app cannot use this API and would have to be recreated.

> Facebook publishing later: it can be added to this same app (Facebook Login
> use case) or to a second app. The code reads separate variables
> (`FACEBOOK_APP_*` vs `INSTAGRAM_APP_*`), so either works.

## Step 3: Set up Instagram Login

In the app: **Instagram → API setup with Instagram login** (left menu, or under
*Use cases → Customize*).

1. Section **"3. Set up Instagram business login"** → **Business login
   settings**:
   - **OAuth redirect URIs**, exactly:
     ```
     https://wboost.cz/oauth/instagram/check
     ```
   - **Deauthorize callback URL**:
     ```
     https://wboost.cz/oauth/instagram/deauthorize
     ```
   - **Data deletion request URL**:
     ```
     https://wboost.cz/oauth/instagram/data-deletion
     ```
   - Save.
2. On the same page, copy the **Instagram App ID** and **Instagram app
   secret** (click *Show*).
   ⚠️ These are **not** the Facebook *App ID / App Secret* from *App settings →
   Basic*. Use the Instagram ones shown in this section.
3. **Permissions**: make sure `instagram_business_basic` and
   `instagram_business_content_publish` are listed for the use case (*Use
   cases → Customize → Permissions*; add them if missing). You can ignore the
   messaging and comments permissions.

## Step 4: App settings → Basic

Fill in (needed for testing and for review):

- **Privacy Policy URL**: `https://wboost.cz/ochrana-osobnich-udaju`
- **Terms of Service URL**: `https://wboost.cz/obchodni-podminky`
- **User data deletion**: choose *Data deletion callback URL* →
  `https://wboost.cz/oauth/instagram/data-deletion`
- **App icon** (1024×1024, WBoost logo), **Category** (e.g. *Business and
  pages*), **App domains**: `wboost.cz`
- Save.

> The privacy policy should mention that WBoost stores an access token to the
> client's connected Instagram account in order to publish posts they create,
> and that the connection can be removed in WBoost or in Instagram. App Review
> checks this.

## Step 5: Add yourself as a tester

1. **App roles → Roles → Add people** → role **Instagram Tester** → enter the
   Instagram username of the test account from step 1. On some dashboards this
   lives under *Instagram → API setup with Instagram login → Add account*.
2. Accept the invite **in Instagram**: on the web, open
   <https://www.instagram.com/accounts/manage_access/> → *Tester invites* →
   **Accept**. (Mobile app: Settings → Website permissions / Apps and websites
   → Tester invites.)
3. Repeat for every account you want to try before App Review (one per client
   pilot, for example).

## Step 6: Put the credentials into production (Infisical)

Add these to the wboost production secrets in Infisical (they render into
`/srv/wboost/.env` on lily on the next deploy):

| Variable | Value |
|---|---|
| `INSTAGRAM_APP_ID` | Instagram App ID from step 3 |
| `INSTAGRAM_APP_SECRET` | Instagram app secret from step 3 |
| `SOCIAL_TOKEN_ENCRYPTION_KEY` | **new random key**: `openssl rand -base64 32` |
| `INSTAGRAM_ROLLOUT` | optional; default `admins`. Set `everyone` after step 8 |

⚠️ **`SOCIAL_TOKEN_ENCRYPTION_KEY` is required.** Production doesn't have it
today (checked 2026-11-26), so it would fall back to the publicly committed
development key from `.env`. Generate it once and never commit it. Changing it
later only means connected projects have to reconnect.

Then redeploy (push to main, or tell Claude "deploy"). Without these values
nothing breaks: the page just keeps saying "připravujeme".

The cron job `app:instagram:refresh-tokens` (nightly 03:30, renews tokens) is
already in `~/www/lily.srv/apps/wboost/cron.d/wboost`. Optionally create a
Sentry cron monitor `wboost-instagram-refresh-tokens`; `sentry-cli` creates it
on the first run.

## Step 7: Try it (admin account, tester Instagram)

1. Log in to wboost.cz as an admin → open a project → left menu **Facebook a
   Instagram** → **Propojit Instagram**.
2. Instagram opens: log in with the **tester** account and allow everything.
   You land back on the project page with "@account je propojen".
3. Open a template in an Instagram format (1:1 / 4:5) → fill it in → **Sdílet
   na sítě → Publikovat na Instagram** → write a caption → **Publikovat**.
   After a few seconds: "Hotovo" + "Zobrazit příspěvek".
4. Try a 9:16 template: it is published as a **story**.
5. Try **Odpojit**, then connect again.

If it fails, tell Claude what message you saw. Every error is logged to
Sentry with details.

## Step 8: Go live for all clients

1. **Business Verification**: Business portfolio → *Security Centre* → *Start
   verification* (company documents with IČO, domain or phone check). Takes a
   few days. Meta requires it before the app can go live with advanced access.
2. **App Review**: in the app → *App Review → Permissions and features* →
   request **Advanced Access** for:
   - `instagram_business_basic`
   - `instagram_business_content_publish`

   Each needs a description and a **screencast**. Script (record once, about
   2 minutes, in English UI or with subtitles):
   1. Log in to WBoost, open a project, go to *Facebook a Instagram*.
   2. Click *Propojit Instagram*, show Instagram's consent screen with both
      permissions, approve.
   3. Back in WBoost, the connected account `@handle` is shown
      (`instagram_business_basic`: we read the username to show which account
      is connected).
   4. Open a template, fill it in, click *Sdílet na sítě → Publikovat na
      Instagram*, write a caption, publish.
   5. Open the post on Instagram via *Zobrazit příspěvek*
      (`instagram_business_content_publish`: we publish images the user
      created and captioned, only when they click Publish).
   6. Show *Odpojit*.

   Description text you can use: *"WBoost is a brand-design tool. Users fill
   in brand templates and publish the resulting image to the Instagram
   professional account connected to their project. We read the account's
   username to display the connection, and publish a single image with the
   user-written caption only when the user clicks Publish. We never post
   automatically."*

   Add a **test user** for the reviewer (a WBoost login with a project and a
   filled Instagram template). Plan for 1–4 weeks, maybe one round of
   feedback.
3. After approval, switch the app to **Live** (toggle at the top of the
   dashboard).
4. Set `INSTAGRAM_ROLLOUT=everyone` in Infisical and redeploy. Every client
   now sees the connect button.
5. Meta runs a yearly data-use checkup. Put it in the calendar.

## Local development note

Meta must reach both the redirect URI (HTTPS, registered exactly) and the
picture (Instagram downloads the JPEG from `UPLOADS_BASE_URL`). A laptop with
Minio on localhost can do neither, so real publishing is tested on
production with an admin account plus a tester Instagram account. Local tests
use `FakeInstagramApi`.

## Troubleshooting

| What you see | Cause / fix |
|---|---|
| Instagram says *"Invalid redirect_uri"* | The URI in step 3 must be exactly `https://wboost.cz/oauth/instagram/check` |
| *"Insufficient developer role"* / can't log in | The Instagram account isn't an accepted tester (step 5), or the app isn't approved yet |
| „Instagram nepovolil publikování…“ | Content-publish permission unchecked in the consent screen; reconnect and allow everything |
| „Propojení s Instagramem vypršelo…“ | Token revoked / expired; *Znovu propojit* on the project page |
| „Instagram nedokázal obrázek zpracovat“ | Instagram couldn't download the JPEG; check that `https://img.wboost.cz/…` (UPLOADS_BASE_URL) is reachable from the internet |
| „denní limit“ | Meta's per-account quota (about 50–100 posts per 24 h) |
