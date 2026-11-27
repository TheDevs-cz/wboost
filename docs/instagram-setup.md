# Instagram publishing per project: Meta setup guide

The code is done: per-project Instagram connection, publishing from the fill
page, a nightly token check and Meta's callbacks
(`docs/architecture/social-publishing.md`). It switches on once a **Meta
app** exists and its credentials are in production. These are the manual
steps.

> **2026-11-27:** Meta no longer lets NEW apps set up "API setup with
> Instagram login". New apps use **"API setup with Unified login"**, and
> WBoost now uses that. Its tab only appears once the app is owned by a
> business portfolio.

## How it works (why this is OAuth)

WBoost publishes on behalf of the client, so the client must **grant
permission**. That is OAuth 2.0 via Meta's *"Instagram API with Unified
Login"*:

1. In a project, an editor clicks **Propojit Instagram**.
2. Meta's login opens. The person logs in with **Instagram or Facebook**,
   picks the client's **business portfolio** and the **Instagram account**
   the project will publish to, and approves the permissions.
3. Meta sends them back to WBoost with a one-time code. WBoost swaps it for
   an access token that belongs to the client's business portfolio (we
   configure it to never expire) and stores it encrypted with the project.
   If the person shared several Instagram accounts, WBoost asks which one
   the project uses.
4. From then on, anyone working in the project can publish via **Sdílet na
   sítě → Publikovat na Instagram** and write the caption. A 9:16 template is
   posted as a story.

No password ever reaches WBoost. The client can revoke access anytime (in
their business portfolio / Instagram settings), and WBoost then asks for a
reconnect.

**Constraints Meta sets:**

- Only **professional** Instagram accounts (Business or Creator). A personal
  account can be switched for free: Instagram app → Settings → *Account type
  and tools* → *Switch to professional account*.
- No Facebook Page is needed.
- The client needs a business portfolio (Meta lets them create one during
  the login).
- Up to ~50–100 API posts per account per 24 h.
- Until Meta's **App Review** approves the app, only Instagram accounts added
  as **testers** can connect. That's why the feature is admins-only by
  default (`INSTAGRAM_ROLLOUT=admins`).

---

## Step 1: Accounts you need (once)

1. **Facebook account** for the developer login (your personal one is fine).
2. **A business portfolio for WBoost** at <https://business.facebook.com>,
   in the name of Wantoo Design s. r. o. It must **own the app** — without it
   the Unified login tab doesn't appear. It doesn't have to be verified for
   testing; verification is needed before going live (step 8).
3. **A test Instagram account** that is *professional* and **public**. A new
   throwaway account (e.g. `wboost.test`) works.

## Step 2: Create the Meta app

1. <https://developers.facebook.com/apps> → **Create app**.
2. App name **WBoost**, contact e-mail: the company one (e.g. info@wantoo.cz).
3. **Use case**: filter *Content management* (or *All*) → **Manage messaging
   & content on Instagram**. Only that one.
4. **Business**: pick WBoost's business portfolio (create one via the link if
   needed). If you skipped this, connect it later in **App settings → Basic**
   (or the banner on the Unified login tab) — the tab needs it.
5. Finish.

## Step 3: Set up Unified login

**Use cases → Manage messaging & content on Instagram → Customize → API
setup with Unified login.**

1. **Configure your app → Create new configuration**:
   - **Token expiration: Never** (if offered). WBoost does not refresh
     tokens; a configuration with expiring tokens would make projects
     reconnect every ~60 days.
   - **Permissions** (*Edit permissions* in the preview): keep
     `instagram_basic` and `instagram_content_publish`. Meta may force
     `instagram_manage_messages`; if it can't be removed, leave it (WBoost
     never reads messages — say so in App Review). Remove the comments and ad
     permissions (`instagram_manage_comments`, `ads_management`, `ads_read`)
     if Meta allows; untick the ad account asset if offered.
   - **Use this configuration**.
2. **Redirect URL**, exactly:
   ```
   https://wboost.cz/oauth/instagram/check
   ```
   Save.
3. Copy the **Login URL** and send it to Claude (or just its `config_id=…`
   value). Claude only needs the `config_id`.

## Step 4: App settings → Basic

1. Copy the **App ID** and **App secret** (click *Show*). These ARE the right
   ones now (the Meta app's own, not any "Instagram app ID").
2. Fill in (needed for testing and for review):
   - **Privacy Policy URL**: `https://wboost.cz/ochrana-osobnich-udaju`
   - **Terms of Service URL**: `https://wboost.cz/obchodni-podminky`
   - **User data deletion**: *Data deletion callback URL* →
     `https://wboost.cz/oauth/instagram/data-deletion`
   - **App icon** (1024×1024, WBoost logo), **Category** (e.g. *Business and
     pages*), **App domains**: `wboost.cz`
   - Save.
3. If **Facebook Login for Business → Settings** shows a **Deauthorize
   callback URL** field, enter `https://wboost.cz/oauth/instagram/deauthorize`.

> The privacy policy should mention that WBoost stores an access token to the
> client's connected Instagram account in order to publish posts they create,
> and that the connection can be removed in WBoost or in Meta's settings.
> App Review checks this.

## Step 5: Add the tester

1. On the Unified login tab (or **App roles → Roles**): **Add account** →
   invite the test Instagram account as **Instagram Tester** (up to 50).
2. Accept the invite **in Instagram on the web**: Settings → Website
   permissions → Apps and websites → **Tester invites** → Accept.
3. The Facebook account you log in with must have **full control of the
   business portfolio** that owns the test Instagram account (Meta's rule for
   testing). Easiest: during the login, create or pick your own portfolio and
   add the test Instagram account to it.

## Step 6: Put the credentials into production (Infisical)

Send Claude these three values; Claude stores them in Infisical (wboost,
prod) and redeploys:

| Variable | Value |
|---|---|
| `INSTAGRAM_META_APP_ID` | App ID from step 4 |
| `INSTAGRAM_META_APP_SECRET` | App secret from step 4 |
| `INSTAGRAM_LOGIN_CONFIG_ID` | `config_id` from the Login URL (step 3) |
| `INSTAGRAM_ROLLOUT` | optional; default `admins`. Set `everyone` after step 8 |

✅ **`SOCIAL_TOKEN_ENCRYPTION_KEY` is already set** (generated on the box and
stored in Infisical on 2026-11-26, never printed). Don't rotate it casually:
connected projects would have to reconnect.

Without these values nothing breaks: the page just keeps saying
"připravujeme".

The nightly cron (03:30, `app:instagram:refresh-tokens` in
`~/www/lily.srv/apps/wboost/cron.d/wboost`, now an alias of
`app:instagram:check-tokens`) flags connections whose token Meta stopped
accepting.

## Step 7: Try it (admin account, tester Instagram)

1. Log in to wboost.cz as an admin → open a project → left menu **Facebook a
   Instagram** → **Propojit Instagram**.
2. Meta's login opens: log in, choose the business portfolio and the
   **tester** Instagram account, allow everything. You land back on the
   project page with "@account je propojen" (or a picker when you shared
   several accounts).
3. Open a template in an Instagram format (1:1 / 4:5) → fill it in → **Sdílet
   na sítě → Publikovat na Instagram** → write a caption → **Publikovat**.
   After a few seconds: "Hotovo" + "Zobrazit příspěvek".
4. Try a 9:16 template: it is published as a **story**.
5. Try **Odpojit**, then connect again.

If it fails, tell Claude what message you saw. Every error is logged to
Sentry with details.

## Step 8: Go live for all clients

1. **Business Verification** of WBoost's portfolio: business.facebook.com →
   *Security Centre* → *Start verification* (company documents with IČO,
   domain or phone check). Takes a few days. Meta requires it before the app
   can go live with advanced access. The dashboard may also ask you to
   **become a tech provider** — do that then.
2. **App Review**: *App Review → Permissions and features* → request
   **Advanced Access** for:
   - `instagram_basic`
   - `instagram_content_publish`
   - (`instagram_manage_messages` only if the configuration forces it —
     explain it is unused)

   Each needs a description and a **screencast**. Script (about 2 minutes,
   English UI or subtitles):
   1. Log in to WBoost, open a project, go to *Facebook a Instagram*.
   2. Click *Propojit Instagram*, show Meta's login with the business
      portfolio + Instagram account selection and the permissions, approve.
   3. Back in WBoost, the connected account `@handle` is shown
      (`instagram_basic`: we read the shared accounts' username to show which
      account is connected).
   4. Open a template, fill it in, click *Sdílet na sítě → Publikovat na
      Instagram*, write a caption, publish.
   5. Open the post on Instagram via *Zobrazit příspěvek*
      (`instagram_content_publish`: we publish images the user created and
      captioned, only when they click Publish).
   6. Show *Odpojit*.

   Description text you can use: *"WBoost is a brand-design tool. Users fill
   in brand templates and publish the resulting image to the Instagram
   professional account connected to their project. We read the shared
   account's username to display the connection, and publish a single image
   with the user-written caption only when the user clicks Publish. We never
   post automatically and never read messages or comments."*

   Add a **test user** for the reviewer (a WBoost login with a project and a
   filled Instagram template). Plan for 1–4 weeks, maybe one round of
   feedback.
3. After approval, switch the app to **Live**.
4. Set `INSTAGRAM_ROLLOUT=everyone` in Infisical and redeploy. Every client
   now sees the connect button.
5. Meta runs a yearly data-use checkup. Put it in the calendar.

## Local development note

Publishing can't work locally: Instagram downloads the JPEG from
`UPLOADS_BASE_URL` itself, and local Minio isn't reachable from the internet.
Test the real flow on production with an admin account plus a tester
Instagram account. Local tests use `FakeInstagramApi`, and
`InstagramApiTest` checks the wire format.

## Troubleshooting

| What you see | Cause / fix |
|---|---|
| Meta says the redirect URL is invalid | The Redirect URL in the configuration (step 3) must be exactly `https://wboost.cz/oauth/instagram/check` |
| The Unified login tab only shows "Business portfolio" | The app isn't owned by a business portfolio yet (step 2.4) |
| Can't log in / "insufficient developer role" | The Instagram account isn't an accepted tester (step 5), or your Facebook account lacks full control of its portfolio |
| „Meta nesdílela žádný instagramový účet…“ | No Instagram account was ticked during the login; connect again and select one |
| „Instagram nepovolil publikování…“ | Content-publish permission missing from the configuration or unticked; check step 3, reconnect |
| „Propojení s Instagramem vypršelo…“ | Token revoked / account moved to another portfolio; *Znovu propojit* on the project page |
| „Instagram nedokázal obrázek zpracovat“ | Instagram couldn't download the JPEG; check that `https://img.wboost.cz/…` (UPLOADS_BASE_URL) is reachable from the internet |
| „denní limit“ | Meta's per-account quota (about 50–100 posts per 24 h) |
