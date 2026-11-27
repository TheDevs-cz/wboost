# Meta App Review pack (WBoost app, Instagram + Facebook Page publishing)

Everything to submit the Meta app "WBoost" (app id 1999865284688599, owned by
the Wantoo Design business portfolio) for **Advanced Access**, then switch it
to Live. Technical background: `docs/architecture/social-publishing.md`,
dashboard setup: `docs/instagram-setup.md`.

## Before submitting (checklist)

- [ ] **Business Verification** of Wantoo Design: submitted 2026-11-27, "Probíhá kontrola" (about 2 working days).
- [ ] **Tech provider**: on the app dashboard, the box "Staňte se poskytovatelem technické podpory".
- [x] **Privacy policy** (`https://wboost.cz/ochrana-osobnich-udaju`) covers Meta data, including an English paragraph (2026-11-27).
- [x] **App settings → Basic**: privacy policy URL, terms URL, data deletion URL, app domain. Also add a 1024 × 1024 **app icon** and a **category** if missing.
- [ ] **Testing**: every permission needs ≥ 1 successful API call in the last 30 days (Testing page). Real publishes from wboost.cz count. Publish once to Instagram and once to Facebook within 30 days before submitting, then wait up to 24 h for the counters.
- [ ] **Screencasts** recorded (scripts below).

## Permissions to request (Advanced Access)

Request only what WBoost uses. Unused permissions are the most common reason for rejection.

| Permission | Why WBoost needs it |
|---|---|
| `instagram_basic` | Show which Instagram professional account a project is connected to (username), and find the account the client shared during login. |
| `instagram_content_publish` | Publish the image the user designed in WBoost, with their caption, to the connected account, only when they click "Publikovat". |
| `pages_show_list` | List the Facebook Pages the client shared during login, so they can pick the one their project publishes to. |
| `pages_manage_posts` | Publish the image the user designed, with their caption, to the connected Page, only when they click "Publikovat". |
| `pages_read_engagement` | Read the Page's name and the published post's permalink to show the connection and the "Zobrazit příspěvek" link. Meta requires it alongside `pages_manage_posts`. |

`instagram_manage_messages` is forced into the Instagram configuration by Meta.
Do **not** request Advanced Access for it (nor for comments, ads or insights)
unless the dashboard refuses to submit without it. In that case, describe it
honestly as "included by the configuration template, not used".

### Description text (paste per permission, adjust the first sentence)

> WBoost (wboost.cz) is a brand-design tool for marketing agencies and their
> clients. Users fill in brand templates (posters, social posts, stories) and
> can publish the finished image directly to the Instagram professional account
> or Facebook Page connected to their project. The connection is made by a
> project editor through Facebook Login for Business; the token is stored
> encrypted and used only when a user clicks "Publikovat" for a single image
> with the caption they wrote. We never post automatically and do not read
> messages, comments, followers or insights. The connection can be removed in
> WBoost (Facebook a Instagram → Odpojit) or in Meta, which notifies our
> deauthorize callback and we delete the token.

## Reviewer access

Publishing stays admins-only until approval (`*_ROLLOUT=admins`); we test as
admins and switch both rollouts to `everyone` right after approval. If the
review form insists on a working login, either give the reviewer a temporary
admin account on an empty "Meta Review" project (delete it after the review)
or switch the rollouts to `everyone` for the review period — decide then.
Testing instructions for the form: "Log in at https://wboost.cz/login →
project 'Meta Review' → Facebook a Instagram → Propojit Instagram / Propojit
Facebook → open template 'Review post' → Sdílet na sítě → Publikovat na
Instagram / Facebook." The reviewer connects their own test accounts.

## Screencast scripts (English subtitles or English narration, about 2 min each)

Meta wants to see the login, the consent screen with the permission, and the
feature that uses it. Record in a clean browser window at 1440 px. Turn the UI
language explanations into captions ("Connect Instagram" = "Propojit Instagram").

### Instagram (`instagram_basic`, `instagram_content_publish`)

1. Log in to wboost.cz, open the project, left menu **Facebook a Instagram**.
2. Click **Propojit Instagram** → Meta login → pick the business portfolio and
   the Instagram account → the screen listing the permissions → **Continue**.
3. Back in WBoost: "@account je propojen" (caption: *instagram_basic: we read the
   username to show which account is connected*).
4. Open a 4:5 template, change a text, **Sdílet na sítě → Publikovat na
   Instagram**, type a caption, **Publikovat**.
5. Success view → **Zobrazit příspěvek** → the post on Instagram (caption:
   *instagram_content_publish: one image + the user's caption, only on click*).
6. Show **Publikované příspěvky** on the project page, then **Odpojit**.

### Facebook Page (`pages_show_list`, `pages_manage_posts`, `pages_read_engagement`)

1. Same project page, click **Propojit Facebook** → Meta login → pick the Page
   → permissions screen → **Continue** (caption: *pages_show_list: the user picks
   which Page the project publishes to*).
2. Back in WBoost: the Page name shown as connected (caption:
   *pages_read_engagement: Page name + post link*).
3. Fill a template → **Sdílet na sítě → Publikovat na Facebook** → caption →
   **Publikovat** → success → **Zobrazit příspěvek** → the post on the Page
   (caption: *pages_manage_posts*).
4. Show the post history entry, then **Odpojit**.

## After approval

1. App dashboard → switch to **Live**.
2. Infisical (wboost / prod): `INSTAGRAM_ROLLOUT=everyone`, `FACEBOOK_PAGES_ROLLOUT=everyone`, redeploy.
3. Delete any temporary reviewer account.
4. Calendar: Meta's yearly **Data Use Checkup**.
