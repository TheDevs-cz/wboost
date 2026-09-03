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

The two systems share a domain and nothing else. That creates exactly two couplings — the
URLs the landing links into the app, and the paths the app must never claim back — and §7
puts one test file on both.

### Two axes of independence — be honest about which one you get

| | How | Where |
|---|---|---|
| **Code independence** — nothing shared, no way for the app's CSS/JS/theme to leak in | Nothing imported, nothing rendered by Symfony. | This repo, this task. |
| **Deploy independence** — change the landing without deploying the app | Its own nginx image (`ghcr.io/thedevs-cz/wboost-landing`, built from `landing/`), its own app stack on lily, claimed at the apex by a priority-100 Traefik router in front of the app's priority-1 `Host()` router. | Half here (§5), half in `~/www/lily.srv/docs/wboost-landing-plan.md`. |

Both are decided (2026-09-03) — you get the full split. A change to `landing/**` builds and
rolls only the nginx container; a change outside it deploys only the app.

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
| `designs/wboost-landing-notes.md` | Every decision, the type system, the motion hints, the complete **[POTVRDIT]** list. **§6b lists the revision round — read it, it changes the nav, the footer and the final CTA.** |

Frames: `hgMNG` Components · `gCoLB` Desktop 1440 · `VZfy3` Mobile 390 ·
`icEU7` Legal 1440 · `IwrPI` Legal 390.

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
   - Add two PUBLIC_ACCESS rules **above** the `^/` catch-all, one per shape:

     ```php
     ['path' => '^/$',                                        'roles' => [AuthenticatedVoter::PUBLIC_ACCESS]],
     ['path' => '^/(ochrana-osobnich-udaju|obchodni-podminky)/?$', 'roles' => [AuthenticatedVoter::PUBLIC_ACCESS]],
     ['path' => '^/(robots\.txt|sitemap\.xml)$',                   'roles' => [AuthenticatedVoter::PUBLIC_ACCESS]],
     ```

     Anchor both with `$` — `^/` alone would open the entire app. This is belt-and-braces:
     these paths belong to nginx, but if the container is down or a router rule is wrong they
     fall through to Symfony, and a clean 404 is a far easier thing to debug than a silent
     redirect to `/login`. Extend the second rule whenever a static page is added.
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
  Dockerfile                 ← §5.1 (nginx:alpine)
  nginx.conf                 ← §5.1 (caching + gzip only)
  traefik-rule.txt           ← §7 golden file: the router rule the pages below imply
  src/
    index.html                       →  /
    ochrana-osobnich-udaju.html      →  /ochrana-osobnich-udaju
    obchodni-podminky.html           →  /obchodni-podminky
    robots.txt                       →  /robots.txt
    sitemap.xml                      →  /sitemap.xml
    landing/
      style.css
      script.js
      fonts/*.woff2
      img/logo.svg
      img/og-cover.png
```

The two legal pages are **one template used twice** (`icEU7` / `IwrPI`): nav, eyebrow, 52 px
serif H1, a "Poslední aktualizace" line, hairline, perex, nine numbered sections with
`[DOPLNIT]` placeholder paragraphs, footer. Build one page shell and swap the heading, the
section list and the body copy. The privacy skeleton is a real Czech one — keep the headings.

**This is a multi-page site, not one page.** `src/*.html` is the page set and the filesystem
is its source of truth: `index.html` serves `/`, every other `<slug>.html` serves `/<slug>`
extensionless. Shared assets live under `src/landing/` and are served at `/landing/…`.

**`/index.html` is deliberately NOT routed.** It would be a second URL serving identical
content, and nginx never emits it — the `try_files` rewrite is internal and invisible to the
client. A request for it falls through to the app and 404s, which is correct: nothing should
ever link there.

**`robots.txt` and `sitemap.xml` live here too.** They are domain-level SEO files and belong
next to the marketing content, which is where they will actually be edited. §6 covers both,
including deleting the app's `public/robots.txt` in the same commit.

The two legal pages are **required before launch** — the footer links to them today as
placeholders. Their content is a legal question, not a design one; ship the routing and the
page shell now and let Jan and Lukáš supply the text.

`landing/src/` is the document root of the site and the only thing that ends up inside the
image. That layout is host-agnostic: it works unchanged in the nginx container, from the
filesystem, or on any static host if the deployment ever moves.

### 3.1 Path rules — the one thing to get right

- **Own assets: root-relative.** `/landing/style.css`, `/landing/img/logo.svg`. Root-relative
  (not `landing/style.css`) because the site is now multi-page and `/ochrana-osobnich-udaju`
  sits at a different depth than `/` — a relative path would resolve differently per page.
  The cost is that `open landing/src/index.html` no longer works off the filesystem; use the
  dev container on `:8090` (§5.2), which is one command.
- **App links: root-relative.** `/registration`, `/login`, `/api/docs`, `/ai`. Never
  hard-code `https://wboost.cz/...` — root-relative keeps dev, prod and any future host
  working, and §7's test can verify them.
- **In-page navigation: fragments.** `#funkce`, `#jak-to-funguje`, `#pro-koho`, `#ai`,
  `#pribeh`, `#cena`. Note `#ai` and `/ai` are different things; both are correct.

### 3.2 No build step

Three pages, two people. Plain HTML + one CSS + one JS: nothing to install, nothing to audit,
nothing to rot. Two kinds of duplication are accepted:

- **Within the landing page**, the client artworks repeat (the match graphic four times in the
  showcase, the water notice three) — §4.2's `em`-scaling means each copy differs by one class.
- **Across pages**, the nav and footer are copied into the two legal pages. At three pages that
  is ~60 duplicated lines and entirely reviewable.

**Know where the line is.** Past roughly four or five pages with shared chrome, that second
duplication stops being cheap and a tiny generator (11ty) earns its place. It can be added
later without changing a single output path, so this is a threshold to watch, not a decision
to pre-empt. The legal pages are deliberately minimal — nav, a prose column, footer — so they
add almost nothing to maintain.

**Budget: CSS under 40 KB uncompressed, JS under 3 KB, zero images except the logo SVG and
the OG cover.** If you are past the CSS budget you are repeating yourself.

### 3.3 CSS architecture

One hand-written file, `landing/src/landing/style.css`, shared by every page. No Tailwind, no Bootstrap, no
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
`landing/src/landing/fonts/`, declared with `@font-face` and `font-display: swap`.

Do not link Google Fonts: this page sells to Czech designers and agencies, a third-party font
request is a GDPR conversation nobody needs, and self-hosting removes a render-blocking DNS
round-trip. Do not reuse `public/theme/fonts/Nunito-*` either — those are the app theme's
copies, they ship no ExtraBold (the design uses Nunito 800 inside the client artwork), and
reaching into the app's tree is exactly the coupling this task exists to avoid.

Preload the two faces used above the fold (Instrument Serif 400, Nunito 700).

### 3.5 The logo, and the light lockup

Copy `assets/images/logo-lg.svg` to `landing/src/landing/img/logo.svg` — one duplicated file
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

`index.html` is the landing itself; the legal pages reuse its nav and footer. Real landmarks — `<header>`, `<main id="main">`, `<nav>`, `<footer>` — one
`<h1>`, then `<h2>` per section. Sections are `<section id="…">` carrying the ids the nav
anchors expect. Fourteen sections in the fixed order of §5 of the design brief: nav, hero,
proof strip, problém, jak to funguje, showcase, moduly (`#funkce`), AI asistenti (`#ai`),
pro koho (`#pro-koho`), příběh (`#pribeh`), cena (`#cena`), FAQ, final CTA, footer.

### 4.1b The Reference section ships hidden

`JXdwa` / `k0oMjU` — three testimonial cards plus a six-slot logo row, on ink, between *Pro
koho* and *Příběh*. **Every field is an explicit placeholder** and §6 of the design brief
forbids invented testimonials, so: build the markup, then wrap the whole `<section>` in an
HTML comment (or `hidden`) and leave it that way. It is one self-contained block precisely so
this is a one-line change when real quotes arrive. Do not fill it with plausible-sounding
fake content to "see how it looks".

### 4.2 The two things that carry the page

**The signature motif** — the dashed indigo editable outline with its field tag and pencil+eye
cluster — appears exactly **twice**: the hero and the "Vyplnění a export" bento tile. It was
removed from the footer in the revision round: inside the product a dashed box means "editable
field", but to a first-time visitor it reads as a rendering bug. In CSS it is a plain `border: 1.5px dashed var(--lp-primary)` with a 2–3 px radius —
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

**Decided (2026-09-03).** The landing runs as its **own stock-nginx container**, deployed as
a separate app on lily, and is claimed at the apex by a **higher-priority Traefik router** in
front of the app. Every existing URL stays exactly where it is. The full infra plan — router
labels, cutover order, webhook wiring, verification, rollback — is
`~/www/lily.srv/docs/wboost-landing-plan.md`; **read it before doing this commit**, and do
the infra half from there.

This commit only adds the two files that make the folder buildable, plus the dev service.

### 5.1 The image

`landing/Dockerfile` — build context is `landing/`:

```dockerfile
FROM nginx:alpine
COPY nginx.conf /etc/nginx/conf.d/default.conf
COPY src/ /usr/share/nginx/html/
```

`landing/nginx.conf` — caching and compression only. Security headers come from
`sec-headers@file` inside Traefik's `public-edge@file` chain; do **not** duplicate them.

```nginx
server {
    listen 80;
    server_name _;
    root /usr/share/nginx/html;
    server_tokens off;

    gzip on;
    gzip_vary on;
    gzip_min_length 512;
    gzip_types text/html text/css application/javascript image/svg+xml;
    # woff2 is already compressed — deliberately not in gzip_types.

    # Canonicalise a trailing slash away before anything else. Traefik routes both
    # forms (the page regex ends `/?$`) so this 301 is reachable; without it the
    # slashed variant would be a duplicate URL. `landing/` is excluded — it is an
    # asset prefix, not a page.
    location ~ "^/(?!landing/)([a-z0-9-]+)/$" { return 301 /$1; }

    # Extensionless page URLs: /ochrana-osobnich-udaju -> ochrana-osobnich-udaju.html.
    # The rewrite is internal, so /index.html is never emitted as a URL.
    location / {
        add_header Cache-Control "no-cache";
        try_files $uri $uri.html =404;
    }

    # Filenames are NOT fingerprinted (no build step), so only the fonts — which
    # genuinely never change — may be cached immutably.
    location /landing/fonts/ { add_header Cache-Control "public, max-age=31536000, immutable"; }
    location /landing/       { add_header Cache-Control "public, max-age=600"; }
}
```

### 5.2 Development

Add a static service to `compose.yaml` so dev mirrors prod:

```yaml
    landing:
        image: nginx:alpine
        restart: unless-stopped
        volumes:
            - ./landing/src:/usr/share/nginx/html:ro
            - ./landing/nginx.conf:/etc/nginx/conf.d/default.conf:ro
        ports:
            - "${LANDING_PORT:-8090}:80"
```

App on `:8080`, landing on `:8090`. The bind mount means edits are live with no rebuild — the
image build is production-only. This is now the only way to view the site correctly: assets are
root-relative (§3.1), so opening a file directly off the filesystem no longer resolves them.

`WEB_PORT` / `POSTGRES_PORT` already exist because the defaults collide with other projects on
this machine; `LANDING_PORT` follows the same pattern.

The app links (`/registration`, `/login`, `/api/docs`, `/ai`) 404 on `:8090` in dev — expected.
§7's test is what proves they are real.

### 5.3 CI

Two workflow changes, both specified in full in the infra plan (§4 Phase B):

- **New** `.github/workflows/release-landing.yml` — triggers on `push` to `main` filtered to
  `paths: ['landing/**']`, calls `TheDevs-cz/ci/.github/workflows/_ship.yml@v1` with
  `app: wboost-landing`, `image: ghcr.io/thedevs-cz/wboost-landing`, `context: landing`.
- **Amend** `.github/workflows/release.yml` with a fail-open preflight job so a landing-only
  change does not rebuild and roll the PHP app.

> ⚠️ The new workflow's repo secret is **`LANDING_DEPLOY_WEBHOOK_SECRET`**, not
> `DEPLOY_WEBHOOK_SECRET` — that name is already taken by the `wboost` app's deploy hook, and
> overwriting it would break the application's deploys. Do not run
> `lily.srv/deploy/add-app.sh --apply` against this repo; it hard-codes the shared name.

### 5.3b Nav and footer changed in the revision round

- **The header carries only *Přihlásit se*, in the primary (indigo) style.** *Vyzkoušet
  zdarma* is not in the nav. The signup CTA still sits above the fold in the hero.
- **The footer has one link column and no column heading.** The *Pro vývojáře* column
  (Dokumentace API · AI a MCP server · GitHub) is gone, and with a single column left the
  *Produkt* heading went with it. Consequence for §7: `/api/docs` is no longer linked from the
  page at all, so the link test will see only `/registration`, `/login` and `/ai`. That is
  correct, not a regression — derive the list from the HTML, never hard-code it.

### 5.4 Favicons — the one deliberate exception

The landing links `/favicon.ico` and friends root-relative. Those paths are not in the landing
router's rule, so they fall through to the app, which already serves them. One shared brand,
zero duplicated bytes, and a browser's implicit `/favicon.ico` request works either way.

## 6. Commit 5 — SEO, accessibility, motion

`wboost.cz/` returns a **302 to the login form** today, so there is currently nothing
indexable at the apex at all. This page is the site's entire organic surface — treat SEO as
part of the build, not a follow-up.

### 6.1 Per page, not once

Every page gets its own `<title>`, meta description and `rel="canonical"` (absolute, apex
host, no trailing slash). Copying the landing's tags onto the legal pages is the usual
mistake and produces three duplicate-title pages.

| Page | Title | Canonical |
|---|---|---|
| `/` | *WBoost — brand manuály, šablony a export pro grafiky a marketingové týmy* | `https://wboost.cz/` |
| `/ochrana-osobnich-udaju` | *Ochrana osobních údajů — WBoost* | `https://wboost.cz/ochrana-osobnich-udaju` |
| `/obchodni-podminky` | *Obchodní podmínky — WBoost* | `https://wboost.cz/obchodni-podminky` |

The legal pages carry a real `<time>`-style "Poslední aktualizace" line. Set it to the date the
text is actually written, not the deploy date, and do not leave `[DOPLNIT DATUM]` on a live
page.

Also on every page: `<html lang="cs">`, Open Graph + Twitter card (`og:title`,
`og:description`, `og:url`, `og:type=website`, `og:locale=cs_CZ`, `og:image` — **absolute
URL**, `https://wboost.cz/landing/img/og-cover.png`). JSON-LD `Organization` +
`SoftwareApplication` on `/` only, with the real company block from §5.13 of the design brief.
Invent nothing — check §8 of that brief first.

**The OG image does not exist.** Export a 1200×630 crop of the hero composition from Pencil to
`landing/src/landing/img/og-cover.png`, or ship without `og:image` and flag it. Never link a
placeholder.

### 6.2 `sitemap.xml`

`landing/src/sitemap.xml`, served at `/sitemap.xml`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <url><loc>https://wboost.cz/</loc></url>
  <url><loc>https://wboost.cz/ochrana-osobnich-udaju</loc></url>
  <url><loc>https://wboost.cz/obchodni-podminky</loc></url>
  <url><loc>https://wboost.cz/ai</loc></url>
</urlset>
```

`<loc>` only. `changefreq` and `priority` are ignored by Google, and a hand-maintained
`lastmod` on a site with no build step drifts into a lie — an absent `lastmod` is better than a
wrong one.

`/ai` is the MCP guide and is served by the **app**, not by nginx. A sitemap lists URLs, not
files, so that is correct and deliberate: it is the one genuinely indexable page the
application has. §7's test asserts every landing page appears here; extra URLs like this are
allowed.

### 6.3 `robots.txt`

Moves from the app to `landing/src/robots.txt`. **Delete `public/robots.txt` in the same
commit** so there is exactly one. Today's version is `Disallow:` (allow everything) with no
sitemap line.

```
User-agent: *
Allow: /

# No indexable content, and every one of these either 302s to the login form,
# answers only to a bearer token, or returns a binary.
Disallow: /api/
Disallow: /_mcp
Disallow: /oauth/
Disallow: /stahnout-logo/
Disallow: /stahnout-mockup/
Disallow: /media/cache/

Sitemap: https://wboost.cz/sitemap.xml
```

The 52 authenticated app prefixes are deliberately **not** listed: they all redirect
anonymous requests to `/login`, which crawlers handle correctly, and enumerating a list that
grows with every feature would rot immediately.

### 6.4 One decision to put to Jan and Lukáš — public brand manuals in Google

`/nahled-manualu/{project}/{manual}` is public and login-free by design, and therefore
crawlable and indexable today. "Shareable by link" is not the same as "should rank for the
client's brand name", and comparable products (Frontify, Brandfolder) exclude shared links by
default. A client finding their brand manual in Google results would, at minimum, be surprised.

If they agree it should be excluded, the correct fix is **`<meta name="robots" content="noindex">`
in `templates/manual_preview.html.twig`** — one line, app-side, not part of this task. Do
**not** reach for `Disallow: /nahled-manualu/` in robots.txt: that blocks crawling, which means
the `noindex` is never seen, and already-indexed URLs can still surface as bare links. Raise
it; do not decide it.

### 6.5 Already handled elsewhere — do not "fix" these

- **`www.wboost.cz` → `wboost.cz`** is a 301 served by Cloudflare (verified 2026-09-03). The
  apex resolves straight to the box; `www` does not. Nothing to add in Traefik or nginx.
- **HTTP → HTTPS** is a permanent redirect on Traefik's `web` entrypoint (pinned at priority
  1000000, D56).

### 6.6 Performance

Nothing to tune, and that is the point: the pages are text and CSS with no images above the
fold, so the LCP element is the H1 — there is no hero image to optimise and no layout shift to
chase. Keep it that way: self-hosted woff2 with `font-display: swap`, the two above-the-fold
faces preloaded, CSS under 40 KB, JS under 3 KB.

### 6.7 Accessibility

Skip link to `#main`. `aria-expanded` + `aria-controls` on the FAQ buttons and the mobile menu
toggle (`<details>`/`<summary>` styled is fine and needs almost no JS). Visible
`:focus-visible` rings (2 px `#727cf566` — the state is drawn in the Components frame).
Contrast is already AA in the design; do not adjust colours without re-checking. Body text
never below 14 px.

### 6.8 Motion

From §7 of the design notes and nothing more:

- Hero dashed outlines fade in one by one, ~80 ms apart, popover last.
- Sticky nav gains its hairline on first scroll.
- The showcase headline nudges ~6 px once, on scroll into view, on all four formats together.
- FAQ height transition, `+` rotates to `−`.
- All of it behind `@media (prefers-reduced-motion: reduce)`.

**JavaScript, under 3 KB, vanilla.** Four things only: nav scroll class, mobile menu toggle,
FAQ accordion, one `IntersectionObserver`. No library. The legal pages load the same file and
use none of it.

## 7. The contract between the two systems — one test file, four assertions

The landing and the app are independent code, but they share a domain, and that creates
exactly two ways for them to drift apart. Both go in
`tests/Controller/LandingContractTest.php` — the **only** file in `tests/` that knows the
landing exists. Skip the whole test with a clear message if `landing/src/` is absent, so the
app's suite still runs in a checkout that has not built the landing.

**Derive everything from the filesystem.** `landing/src/*.html` is the page set:
`index.html` → `/`, every other `<slug>.html` → `/<slug>`. Nothing is hard-coded, so adding a
page is picked up automatically.

**1. Landing → app links resolve.** Extract every `href` starting with `/` that is not a
landing-owned path, and assert each resolves against the real router
(`self::getContainer()->get('router')`, `UrlMatcherInterface::match()`). Today that is
`/registration`, `/login`, `/api/docs`, `/ai`. A renamed route fails the build with the
offending href in the message.

**2. The app never shadows a landing path.** For `/`, each page URL, and a representative
`/landing/style.css`, assert the router does **not** match (expect
`ResourceNotFoundException`). This is the assertion that makes `/landing/` safe without the
underscore: the day someone adds a Symfony route under it, or re-adds a `/` route, this fails
loudly instead of silently shadowing the static site.

**3. The Traefik rule is in sync.** Generate the router rule the page set implies and assert
it equals the committed `landing/traefik-rule.txt`:

```
Host(`wboost.cz`) && (Path(`/`) || PathPrefix(`/landing/`) || Path(`/robots.txt`) || Path(`/sitemap.xml`) || PathRegexp(`^/(obchodni-podminky|ochrana-osobnich-udaju)/?$`))
```

One `PathRegexp` covers every non-root page **and** its trailing-slash variant, which nginx
then 301s to the canonical form (§5.1) — without it a slashed URL would fall through to the app
and soft-404. Traefik on the box is v3.7.5, so `PathRegexp` is available. Sort the slugs
alphabetically so the output is deterministic.

**4. Every page is in the sitemap.** Assert each `landing/src/*.html` page has a matching
`<loc>` in `landing/src/sitemap.xml`. Extra `<loc>` entries (like `/ai`, served by the app) are
allowed — this catches the real mistake, which is adding a page and forgetting the sitemap. On mismatch, fail with the **new rule
printed verbatim** and the instruction to paste it into
`apps/wboost-landing/compose.yaml` in the infra repo. That turns the one unavoidable
cross-repo duplication into a build failure with the fix in the message, instead of a
convention nobody remembers.

### Adding a static page later — the whole checklist

1. `landing/src/<slug>.html` (copy a legal page for the nav/footer chrome), with its own
   `<title>`, meta description and canonical.
2. Add its `<loc>` to `landing/src/sitemap.xml`.
3. Run the tests; assertions 3 and 4 fail and print what to change. Commit the regenerated
   `landing/traefik-rule.txt`.
4. Infra repo: paste the rule into `apps/wboost-landing/compose.yaml`, **push that first**.
5. App repo: add the slug to the `security.php` PUBLIC_ACCESS rule, push `landing/**`.

Step 4's push builds and deploys the nginx image, and that deploy applies the compose.yaml the
reconciler already delivered in step 3 — so the new route goes live on the landing's own
deploy. **No separate infra window, as long as step 3 lands before step 4.**

## 8. Commands

```bash
docker compose up -d landing                      # http://localhost:8090
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

- `docker compose up -d landing` serves all three pages correctly on `:8090`.
- Nothing under `landing/` references the app's code; nothing in `src/`, `templates/` or
  `assets/` references the landing. `tests/Controller/LandingContractTest.php` is the single
  exception and it only reads URLs and page filenames.
- `/`, `/ochrana-osobnich-udaju`, `/obchodni-podminky`, `/robots.txt` and `/sitemap.xml` serve
  from nginx; `landing/traefik-rule.txt` matches the rule deployed in
  `apps/wboost-landing/compose.yaml`; the app's `public/robots.txt` is deleted; `/index.html`
  is not routed; a trailing slash 301s to the canonical URL.
- Every page has its own title, meta description and canonical, and a `<loc>` in the sitemap.
- All 14 sections present in order, copy verbatim, at all four widths; both legal pages exist
  as shells with the shared nav and footer.
- CSS under 40 KB, JS under 3 KB, fonts self-hosted as woff2.
- `GET /dashboard` behaves like the old `/`; login lands on `/dashboard`; logout lands on `/`.
- No reference to `homepage` remains in `src/`, `templates/` or `tests/`.
- `https://wboost.cz/` serves the static page from the nginx container, and `/login`,
  `/registration`, `/api/docs`, `/ai`, `/_mcp`, `/nahled-manualu/*` and both `*.wboost.cz`
  subdomains are unchanged — verified per §6 of `~/www/lily.srv/docs/wboost-landing-plan.md`.
- PHPStan max and PHPUnit green.
- The PR description lists every still-open **[POTVRDIT]** item from §10 of
  `designs/wboost-landing-notes.md` — in particular the light logo lockup, the OG image, the
  placeholder privacy/terms links, and the customer-logo strip.

## 10. Out of scope

Analytics and any cookie/consent banner (there is none in the app today; adding one is a
separate decision). A CMS or editable copy. A/B testing. A blog. **The legal text itself** —
build the two pages as shells with the shared chrome and a prose column; Jan and Lukáš supply
the wording. Any change to the app behind the login beyond the route move. Moving the app to a
subdomain — see §0.
