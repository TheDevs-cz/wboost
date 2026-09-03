# WBoost landing page — implementation brief (standalone static site)

> **How to use this file.** In a Claude Code session opened in this repo, say:
> *"Read docs/marketing/landing-page-implementation-prompt.md and do exactly what it says."*
>
> The design is finished and lives in `~/pencil/wboost.pen`. The visual brief that produced
> it is [`landing-page-pencil-prompt.md`](landing-page-pencil-prompt.md) — read §3 (tokens),
> §4 (voice, links), §5 (copy) and §6 (visual direction) before writing any markup. The Czech
> copy there is final and ships verbatim.

---

## 0. Your task

Build the WBoost marketing landing page as a **standalone static site** that shares nothing
with the Symfony application, and hand `/` over to it.

### What "split" means here, precisely

`landing/` is plain HTML, CSS, JS, woff2 fonts and one SVG. **No PHP, no Twig, no Symfony,
no Node, no build step, no framework.** It never imports from `assets/`, never reads
`templates/`, never calls `path()`, never touches the database. The app never renders it and
never links to its stylesheet. You could delete the whole Symfony app and the landing page
would still open in a browser.

The two systems share exactly **one** thing: a handful of URLs the landing links to
(`/registration`, `/login`, `/api/docs`, `/ai`). That is the only coupling, and §7 puts a
test on it.

### Two axes of independence — be honest about which one you get

| | Cost | When |
|---|---|---|
| **Code independence** — nothing shared, no way for the app's CSS/JS/theme to leak in | Free. Entirely inside this repo. | Now, this task. |
| **Deploy independence** — change the landing without deploying the app | One web-server rule. The Caddyfile lives inside `ghcr.io/thedevs-cz/php:8.5-wboost` / the infra repo, **not here**, so this task cannot finish it. | §5 documents both paths; pick one with Jan. |

Do the code split now — it is the part that protects the design and costs nothing. The deploy
split is a one-line infra change afterwards, because the artifact is a plain folder either way.

**Do not move the app to a subdomain.** `app.wboost.cz` looks tempting and is the wrong call
right now: it breaks every public manual URL a designer has already sent a client
(`/nahled-manualu/{project}/{manual}` — a selling point, "veřejný odkaz, vždy aktuální"),
invalidates the MCP server URL `https://wboost.cz/_mcp` that is baked into the Claude Code
plugin marketplace entry, the RFC 9728/8414 metadata documents and every dynamically
registered OAuth client, breaks the REST API base URL that mfkfm's back-office uses in
production, and changes OAuth redirect URIs while the Meta app review is still pending. If
it ever happens it is its own migration project.

Work in five commits, each leaving the app green (`composer phpstan` + `vendor/bin/phpunit`).

---

## 1. Where the design lives

Three sources, in decreasing authority:

| Source | Use it for |
|---|---|
| `~/pencil/wboost.pen` (Pencil MCP) | Exact geometry, spacing, colours, font sizes. `Get(nodeId, {depth: N})` reads any node; `ctx.bounds` gives resolved boxes. This is the spec. |
| `designs/exports/sections/*.png` (2×) | The visual target. One PNG per section; the id → name map is in the notes. |
| `designs/wboost-landing-notes.md` | Every decision, the type system, the motion hints, the complete **[POTVRDIT]** list. |

Frames: `hgMNG` Components · `gCoLB` Desktop 1440 · `VZfy3` Mobile 390.

**A useful trick, not a shortcut.** `Export(["<sectionId>"], "html-css", "/tmp/x.html")` from
the Pencil MCP dumps every computed value in a section — read it to pull exact px and colour
values instead of eyeballing the PNG. **Ship none of it**: it is absolutely positioned,
non-semantic and non-responsive. Hand-write the real markup.

Do not reopen design questions while implementing. If something looks wrong, note it and ask.

---

## 2. Commit 1 — Symfony gives up `/`

Today `HomepageController` owns `/` (route name `homepage`) and redirects to `projects`.
71 Twig files link `path('homepage')` (all breadcrumbs labelled "Projekty") and 7 PHP
controllers `redirectToRoute('homepage')`.

1. **Rename the redirect controller.** `src/Controller/HomepageController.php` →
   `src/Controller/DashboardController.php`, `#[Route(path: '/dashboard', name: 'dashboard')]`,
   body unchanged. Keeping a stable app entry point is deliberate: it is the post-login
   target, a sane bookmark, and the natural home for a real dashboard later.
2. **Rewrite the references.**
   - The 71 Twig breadcrumbs say "Projekty" and only ever end up at `/projects` — point them
     straight at `path('projects')` and drop the redirect hop.
   - `templates/base.html.twig` (~lines 67, 77, 267: the two sidebar logos and the mobile
     topbar logo) → `path('dashboard')`.
   - The 7 `redirectToRoute('homepage')` in `src/Controller/**` → `'dashboard'`.
   - `grep -rn "homepage" src/ templates/ tests/` must come back empty.
3. **Do not add a `/` route.** Symfony has no landing controller. `/` is served as a static
   file by the web server (§5).
4. **`config/packages/security.php`** — two edits:
   - `firewalls.main.form_login.default_target_path`: `'/'` → `'/dashboard'`. Without this,
     every successful login lands on the marketing page.
   - Add `['path' => '^/$', 'roles' => [AuthenticatedVoter::PUBLIC_ACCESS]]` **above** the
     `^/` catch-all. Anchor it with `$` — `^/` alone would open the entire app. This is
     belt-and-braces: if the static file is ever missing or the serving rule is wrong, `/`
     falls through to Symfony and you get a clean 404 instead of a silent redirect to
     `/login`, which is a far more confusing thing to debug.
   - `firewalls.main.logout.target` stays `'/'` — logging out onto the landing page is right.
5. **Tests.** Rename `tests/Controller/HomepageControllerTest.php` →
   `DashboardControllerTest.php` and retarget both cases at `/dashboard` (anonymous →
   redirect to `/login`; logged in → redirect to `/projects`).

Done when the app behaves exactly as before behind the login and `/` is no longer Symfony's.

---

## 3. Commit 2 — the landing site skeleton

```
landing/
  README.md                  ← how to open it, how it is served, where the design lives
  src/
    index.html
    _lp/
      landing.css
      landing.js
      fonts/*.woff2
      img/logo.svg
      img/og-cover.png
```

`landing/src/` is the document root of the site. That layout works unchanged whether the
files are copied into `public/`, served by a static container, or uploaded to a bucket.

### 3.1 Path rules — the one thing to get right

- **Own assets: relative.** `_lp/landing.css`, `_lp/img/logo.svg`. Relative paths mean
  `open landing/src/index.html` renders the finished page straight off the filesystem with no
  server at all, which is the whole point of having no build step.
- **App links: root-relative.** `/registration`, `/login`, `/api/docs`, `/ai`. Never
  hard-code `https://wboost.cz/...` — root-relative keeps dev, prod and any future host
  working, and §7's test can verify them.
- **In-page navigation: fragments.** `#funkce`, `#jak-to-funguje`, `#pro-koho`, `#ai`,
  `#pribeh`, `#cena`. Note `#ai` and `/ai` are different things; both are correct.

### 3.2 No build step

One page, two people. Plain `index.html` + one CSS + one JS: nothing to install, nothing to
audit, nothing to rot. The cost is that the client artworks repeat in the markup (the match
graphic appears four times in the showcase, the water notice three times) — that is fine and
reviewable, and §4.2's `em`-scaling means each copy differs by one class. If the duplication
ever becomes painful, 11ty can be added later without changing a single output path.

**Budget: CSS under 40 KB uncompressed, JS under 3 KB, zero images except the logo SVG and
the OG cover.** If you are past the CSS budget you are repeating yourself.

### 3.3 CSS architecture

One hand-written file, `landing/src/_lp/landing.css`. No Tailwind, no Bootstrap, no
preprocessor, no utility soup.

- Every class prefixed `lp-` — for greppability, not isolation; there is nothing to isolate
  from.
- A small modern reset at the top (`box-sizing`, margin zeroing, `img{max-width:100%}`,
  `:focus-visible` ring).
- **Tokens as custom properties on `:root`**, named exactly as the design does:

```css
:root {
  --lp-ink:#313a46; --lp-ink-deep:#28303a; --lp-ink-soft:#3a4453;
  --lp-paper:#fafbfe; --lp-paper-warm:#f4f1ea; --lp-white:#fff;
  --lp-primary:#727cf5; --lp-primary-hover:#5b66e0; --lp-primary-tint:#eef0fe;
  --lp-slate:#8491a0; --lp-logo-accent:#7075b5;
  --lp-text-head:#313a46; --lp-text-body:#6c757d;
  --lp-border:#dee2e6; --lp-border-ink:#8491a03d;
  --lp-success:#0acf97; --lp-danger:#fa5c7c;
  --lp-demo-green:#1e4a3b; --lp-demo-brick:#9c3a24;
  --lp-font-display:"Instrument Serif",Georgia,serif;
  --lp-font-body:Nunito,system-ui,-apple-system,"Segoe UI",sans-serif;
  --lp-font-mono:"IBM Plex Mono",ui-monospace,SFMono-Regular,Menlo,monospace;
  --lp-radius-card:16px; --lp-radius-ctl:8px;
  --lp-shadow-pop:0 14px 34px rgba(49,58,70,.12);
}
```

- File order: reset → tokens → type scale → layout primitives (`lp-section`, `lp-container`,
  `lp-eyebrow`, `lp-rule`) → components (`lp-btn`, `lp-chip`, `lp-tag`, `lp-cluster`,
  `lp-editable`, `lp-card`, `lp-tile`, `lp-poster`) → one block per section in page order →
  media queries grouped at the end of each block.

### 3.4 Fonts — self-hosted, three families

Instrument Serif 400; Nunito 400/600/700/800; IBM Plex Mono 400. **woff2 only**, in
`landing/src/_lp/fonts/`, declared with `@font-face` and `font-display: swap`.

Do not link Google Fonts: this page sells to Czech designers and agencies, a third-party font
request is a GDPR conversation nobody needs, and self-hosting removes a render-blocking DNS
round-trip. Do not reuse `public/theme/fonts/Nunito-*` either — those are the app theme's
copies, they ship no ExtraBold (the design uses Nunito 800 inside the client artwork), and
reaching into the app's tree is exactly the coupling this task exists to avoid.

Preload the two faces used above the fold (Instrument Serif 400, Nunito 700).

### 3.5 The logo, and the light lockup

Copy `assets/images/logo-lg.svg` to `landing/src/_lp/img/logo.svg` — one duplicated file
keeps the site self-contained, and the wordmark has not changed in years.

Inline it in the HTML rather than using `<img>`, put a class on each path, and drive both
lockups from CSS:

```css
.lp-wordmark .lp-wordmark__w    { fill: var(--lp-slate); }
.lp-wordmark .lp-wordmark__tab  { fill: var(--lp-logo-accent); }
.lp-wordmark .lp-wordmark__word { fill: var(--lp-white); }
.lp-wordmark--ink .lp-wordmark__word { fill: var(--lp-ink); }
```

The path data and `viewBox="0 0 1099.07 298.68"` are in the file; the `boost` letters are the
five paths carrying `fil2`/`fil3`. Size with `width` + `height:auto`. Do not redraw anything.

The ink-on-paper lockup is **[POTVRDIT]** — flag it in the PR description.

---

## 4. Commit 3 — the page

### 4.1 Document structure

One `index.html`. Real landmarks — `<header>`, `<main id="main">`, `<nav>`, `<footer>` — one
`<h1>`, then `<h2>` per section. Sections are `<section id="…">` carrying the ids the nav
anchors expect. Fourteen sections in the fixed order of §5 of the design brief: nav, hero,
proof strip, problém, jak to funguje, showcase, moduly (`#funkce`), AI asistenti (`#ai`),
pro koho (`#pro-koho`), příběh (`#pribeh`), cena (`#cena`), FAQ, final CTA, footer.

### 4.2 The two things that carry the page

**The signature motif** — the dashed indigo editable outline with its field tag and pencil+eye
cluster — appears exactly three times: hero, the "Vyplnění a export" bento tile, and the
footer. In CSS it is a plain `border: 1.5px dashed var(--lp-primary)` with a 2–3 px radius —
**not** the baked dash paths Pencil had to use because it has no stroke-dash property. The tag
is a small indigo pill absolutely positioned at the box's top-left, the cluster at its
top-right. The hero's "Datum" box is the *active* state: solid 1.5 px border plus
`background:#727cf51a`.

**The client artworks** are pure CSS. Build each poster once and scale it with a single
`font-size` on its root, sizing everything inside in `em`:

```css
.lp-poster        { font-size: 10px; }   /* hero, 400 px wide */
.lp-poster--thumb { font-size: 2.4px; }  /* format rail */
```

Photographs are `linear-gradient(118deg, …)` fields in the client's own brand colour; the
exact stops are in the `.pen`. **No stock photography anywhere** (§6 of the design brief). The
two demo brands are deliberate — deep green for the municipality, brick for the football club,
because they are two different fictional clients, which is the product's premise.

Everything else that looks like the app — layers panel, input-properties popover, export
button group, manual page with the colour palette, chat transcript with tool-call chips,
export-history list, e-mail signature, API code block, QR grid — is flexbox plus hairlines
plus 11–14 px type. No screenshots, no device mockups, no icon fonts. Icons are inline SVG at
a single 1.5 px stroke weight from one set (Lucide, matching the design).

### 4.3 Responsive

The design gives you 1440 and 390. Three breakpoints between them:

| Range | Behaviour |
|---|---|
| ≥ 1200 | The Desktop 1440 artboard. Content `min(1200px, 100% - 240px)`, centred. |
| 960–1199 | Same structure, content `100% - 96px`; the hero composition shrinks via its `font-size` token. |
| 640–959 | Hero stacks (copy, then composition). Bento: the full-width tile stays, `740+436` stacks, the 3-column row becomes 2, the 2-column row stacks. Showcase becomes the horizontal scroll strip. AI section stacks. FAQ single column. Story: copy, then founders side by side. |
| < 640 | The Mobile 390 artboard. Single column, 20 px gutters (16 px for the AI section so the chat panel keeps its width). |

The hero composition is one `position: relative` block with absolutely positioned children.
Below 960 the format rail, the toast, the open popover and the "Datum" active box are
`display: none` — the mobile artboard keeps **two** dashed boxes (Nadpis, Fotografie). Do not
build two copies of the markup.

The showcase strip below 960 is `overflow-x:auto; scroll-snap-type:x mandatory` with the
`posuňte prstem →` hint; hide the hint on `(pointer: fine)`.

Fluid type via `clamp()` between the mobile and desktop values in the notes (H1 40→62, section
titles 34→46, the three 52 px sections 40→52, body 16→18). Touch targets ≥ 44 px; on mobile
the buttons are full width at 52 px.

### 4.4 Copy

Verbatim from §5 of the design brief, **including the typography**: non-breaking spaces after
every one-letter preposition and conjunction (v, k, s, z, a, i, o, u), en dashes, `×` for
multiplication, Czech quotes `„ “`. They are already correct in the `.pen` — read them back
from there rather than retyping. `&nbsp;` or the literal U+00A0, either is fine.

### 4.5 No logged-in state — accepted trade-off

A static page has no session, so the nav always shows *Přihlásit se* + *Vyzkoušet zdarma*,
even to a signed-in visitor. Sniffing a cookie in JS to swap the CTA would re-couple the two
systems for a cosmetic gain — don't. This is what most SaaS marketing pages do anyway.

---

## 5. Commit 4 — serving it

### 5.1 Development

Add a static service to `compose.yaml` so dev matches prod topology and the landing has its
own origin:

```yaml
    landing:
        image: caddy:2-alpine
        restart: unless-stopped
        command: caddy file-server --root /srv --listen :80 --browse
        volumes:
            - ./landing/src:/srv:ro
        ports:
            - "${LANDING_PORT:-8090}:80"
```

App at `:8080`, landing at `:8090`. The app links (`/registration` etc.) 404 on `:8090` —
expected in dev; that is what §7's test is for. `open landing/src/index.html` also works for
pure design work.

Remember `WEB_PORT` / `POSTGRES_PORT` already exist because the defaults collide with other
projects on this machine — `LANDING_PORT` follows the same pattern.

### 5.2 Production — two paths, pick one with Jan

**Path A — bundled into the app image (no infra change, one deploy).** Add to the `Dockerfile`,
after `asset-map:compile`:

```dockerfile
COPY landing/src/ ./public/
```

`landing/src/index.html` → `public/index.html` and `_lp/` → `public/_lp/`. Neither collides
with anything under `public/` today (verified: no `index.html`, no `_lp`). Static files under
`public/` are served by Caddy before PHP runs.

**Verify before relying on it:** the Caddyfile lives inside `ghcr.io/thedevs-cz/php:8.5-wboost`,
not in this repo, so you cannot read it here. Confirm that a request for `/` resolves to
`public/index.html` rather than falling through to `index.php` —
`docker compose exec web cat /etc/caddy/Caddyfile` (or wherever the image puts it) and check
the `try_files` / `file_server index` directives. If it falls through, the fix is one rule in
the infra repo:

```caddyfile
handle / { rewrite * /index.html; file_server }
```

This path gives code independence but **not** deploy independence: a copy change still means
a PHP deploy.

**Path B — its own origin (deploy independence).** Publish `landing/src/` to a static host
(Cloudflare Pages, a bucket, or a `caddy:2-alpine` container next to the app) and route
`wboost.cz/` to it while everything else continues to the app. The artifact is byte-identical
to Path A, so switching later is a routing change, not a rebuild.

Whichever you pick, write it down in `landing/README.md`.

---

## 6. Commit 5 — polish

**SEO / meta.** `<title>` ≈ *"WBoost — brand manuály, šablony a export pro grafiky a
marketingové týmy"*. Meta description from the hero subhead. `<html lang="cs">`.
`rel="canonical"` to `https://wboost.cz/`. Open Graph + Twitter card (`og:title`,
`og:description`, `og:url`, `og:type=website`, `og:locale=cs_CZ`, `og:image`). JSON-LD
`Organization` (Wantoo Design s. r. o. with the real address and IČ from §5.13) +
`SoftwareApplication`. Invent nothing — check §8 of the design brief first.

**The OG image does not exist.** Export a 1200×630 crop of the hero composition from Pencil
into `landing/src/_lp/img/og-cover.png`, or ship without `og:image` and flag it as a follow-up.
Do not link a placeholder.

**`public/robots.txt` already exists** (`User-agent: * / Disallow:` — allow everything) and
belongs to the app. It is fine as-is. If the landing moves to its own origin under Path B,
that origin needs its own copy.

**Accessibility.** Skip link to `#main`. `aria-expanded` + `aria-controls` on the FAQ buttons
and the mobile menu toggle (`<details>`/`<summary>` styled is fine and needs almost no JS).
Visible `:focus-visible` rings (2 px `#727cf566` — the state is drawn in the Components frame).
Contrast is already AA in the design; do not adjust colours without re-checking. Body text
never below 14 px.

**Motion**, from §7 of the notes and nothing more:
- Hero dashed outlines fade in one by one, ~80 ms apart, popover last.
- Sticky nav gains its hairline on first scroll.
- The showcase headline nudges ~6 px once, on scroll into view, on all four formats together.
- FAQ height transition, `+` rotates to `−`.
- All of it behind `@media (prefers-reduced-motion: reduce)`.

**JavaScript, under 3 KB, vanilla.** Four things only: nav scroll class, mobile menu toggle,
FAQ accordion, one `IntersectionObserver`. No library. If you reach for one, you have gone
wrong.

---

## 7. The one permitted coupling, and its test

The landing links into the app. That is the only dependency, and it is the one that will
silently rot the day someone renames a route.

Add `tests/Controller/LandingLinkTargetsTest.php` — the only file in `tests/` that knows the
landing exists:

- read `landing/src/index.html` (skip the test with a clear message if the file is absent, so
  the app's suite still runs in a checkout that has not built the landing yet);
- extract every `href` starting with `/` and **not** with `/_lp/`;
- assert each one resolves against the real router
  (`self::getContainer()->get('router')`, `UrlMatcherInterface::match()`), so a renamed or
  deleted route fails the build with the offending href in the message.

Today that set is `/registration`, `/login`, `/api/docs`, `/ai`. Do not hard-code the list —
derive it from the HTML, so a link added later is covered automatically.

That is the whole contract. No other test may read anything under `landing/`, and nothing
under `landing/` may read anything from the app.

---

## 8. Commands

```bash
docker compose up -d landing                      # http://localhost:8090
open landing/src/index.html                       # no server needed
docker compose exec web composer phpstan          # level max, must pass
docker compose exec web vendor/bin/phpunit        # must pass
docker compose exec web bin/console debug:router | grep -E ' / | /dashboard '
```

Verify in a real browser at 1440, 1024, 768 and 390. Compare each section side by side with
`designs/exports/sections/*.png`. The page has no images beyond the logo, so first paint
should be essentially instant; check the network panel shows **nothing** from `/theme/`,
`/assets/` or `/bundles/`.

---

## 9. Definition of done

- `landing/src/index.html` opens correctly straight from the filesystem, with no server.
- Nothing under `landing/` references the app's code; nothing in `src/`, `templates/` or
  `assets/` references the landing. `tests/Controller/LandingLinkTargetsTest.php` is the
  single exception and it only reads URLs.
- All 14 sections present in order, copy verbatim, at all four widths.
- CSS under 40 KB, JS under 3 KB, fonts self-hosted as woff2.
- `GET /dashboard` behaves like the old `/`; login lands on `/dashboard`; logout lands on `/`.
- No reference to `homepage` remains in `src/`, `templates/` or `tests/`.
- `/` serves the static page in production — with the chosen path from §5.2 written down in
  `landing/README.md`, including whether the Caddy rule was needed.
- PHPStan max and PHPUnit green.
- The PR description lists every still-open **[POTVRDIT]** item from §10 of
  `designs/wboost-landing-notes.md` — in particular the light logo lockup, the OG image, the
  placeholder privacy/terms links, and the customer-logo strip.

## 10. Out of scope

Analytics and any cookie/consent banner (there is none in the app today; adding one is a
separate decision). A CMS or editable copy. A/B testing. A blog. The privacy and terms pages
themselves. Any change to the app behind the login beyond the route move. Moving the app to a
subdomain — see §0.
