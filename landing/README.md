# `landing/` — the wboost.cz marketing site

A **standalone static site**: one marketing page (`/`, for graphic designers and
studios) plus two legal pages. Plain HTML, CSS and JS, self-hosted woff2 faces
(Biotic and three display faces used inside the hero's mock posters). **No PHP,
no Twig, no Symfony, no Node, no build step, no framework, no CDN.** It never
imports from `assets/`, never reads `templates/`, never calls `path()` — you
could delete the whole Symfony app and these pages would still open in a browser
(the lead forms would just fail gracefully with an e-mail fallback).

It shares a domain with the application and nothing else. That creates exactly
three couplings, and `tests/Controller/LandingContractTest.php` (the only file
in `tests/` that knows this directory exists) puts an assertion on each:

1. the URLs the landing links **into** the app — today only `/login`;
2. the form `action`s that post **into** the app — `POST /poptavka`
   (`src/Controller/Landing/LandingLeadController.php`);
3. the paths the app must never claim back — `/`, `/landing/*`, each page slug.

## Leads (`POST /poptavka`)

Two forms on `/`, same origin (the path is not in the landing's Traefik rule):
the three-step contact wizard (`source=grafici`) and "Napsat historku"
(`source=historka`, name and e-mail optional). Each lead is stored in
`landing_lead`, e-mailed to `LANDING_LEAD_EMAILS` (default `info@wantoo.cz`,
Reply-To = the visitor when there is an address) and listed at
`/admin/landing-leads`. The wizard's radios (`intent`, `clients`, `call_time`)
are stored as readable labels in `interests`.

Spam guards, all silent (a bot gets the normal 201 and nothing is stored or sent):
a hidden `fax` honeypot on both forms, a `dwell` time trap (`WB.sendLead` in
`landing.js` sends the ms since page load; posts under 2 s or without it are
dropped) and a link check (more than one link in the message, or any link in the
name). Plus the `landing_lead` rate limiter (20 / hour / IP, 429). Without JS
there is no `dwell`, so a `<noscript>` note points visitors to e-mail. Any change
to a field the server REQUIRES must ship landing-first.

## Look at it

```bash
docker compose up -d landing      # http://localhost:8090
```

The bind mount makes edits live with no rebuild; the image build is
production-only. Opening `src/index.html` off the filesystem does **not** work —
the assets are root-relative (`/landing/style.css`) because the site is
multi-page and `/ochrana-osobnich-udaju` sits at a different depth than `/`.

`:8090` is often taken by another project locally — `LANDING_PORT=8091 docker
compose up -d landing`. The app link (`/login`) and `POST /poptavka` 404 on the
landing port in dev. Expected.

## Layout

```
landing/
  Dockerfile          FROM nginx:alpine — build context is this directory
  nginx.conf          caching + gzip + the /klienti → / redirect; security headers come from Traefik
  traefik-rule.txt    the router rule the page set implies (asserted by the test)
  src/                the document root, and the only thing inside the image
    index.html                     →  /
    ochrana-osobnich-udaju.html    →  /ochrana-osobnich-udaju
    obchodni-podminky.html         →  /obchodni-podminky
    robots.txt · sitemap.xml
    landing/
      landing.css · landing.js     everything `/` needs (tokens, sections, hero timeline, carousel, calculator, forms)
      legal.css                    long-form layout of the legal pages, on top of landing.css
      demo/app.html                the app demo, lazy-loaded into the "Jak to vypadá" iframe
      fonts/                       biotic-*.woff2 + the hero posters' display faces
      img/                         wboost-logo.svg · hero-*.webp · avatars/*.webp · og-cover.png
```

**The demo is deliberately loaded as a blob** (`demoUrl()` in `landing.js`):
Traefik's `public-edge` sends `X-Frame-Options: DENY` on every real URL, so a
plain `<iframe src>` would be refused on prod. A blob document has no headers,
but it also cannot resolve root-relative URLs, which is why the demo keeps its
fonts inlined. `robots.txt` and a meta tag keep it out of search results.

`src/*.html` is the page set and the filesystem is its source of truth:
`index.html` serves `/`, every other `<slug>.html` serves `/<slug>` extensionless.
**`/index.html` is deliberately not routed** — it would be a second URL for the
same content, and nginx never emits it.

Favicons are the one deliberate exception to "self-contained": the pages link
`/favicon.ico` and friends root-relative, those paths are not in the landing
router's rule, and they fall through to the app that already serves them.

## Adding a page

1. `src/<slug>.html` — copy a legal page for the nav/footer chrome, give it its
   own `<title>`, meta description and canonical.
2. Add its `<loc>` to `src/sitemap.xml`.
3. Run `vendor/bin/phpunit --filter LandingContract`. Assertions 3 and 4 fail and
   print exactly what to change; commit the regenerated `traefik-rule.txt`.
4. **Infra repo first:** paste that rule into `apps/wboost-landing/compose.yaml`
   in `~/www/lily.srv` and push. The reconciler delivers it without activating.
5. App repo: add the slug to the `security.php` PUBLIC_ACCESS rule and push. That
   push builds the nginx image and triggers the landing's own deploy, which
   applies the compose.yaml step 4 already delivered.

Order matters. Reversed, the page ships into an image Traefik is not yet routing
to, and the URL 404s from the app until the next landing deploy.

## Deployment

Its own image (`ghcr.io/thedevs-cz/wboost-landing`, built by
`.github/workflows/release-landing.yml` from this directory), its own app stack
on lily (`apps/wboost-landing/` in the infra repo), claimed at the apex by a
priority-100 Traefik router in front of the app's priority-1 `Host()` router.
A change to `landing/**` alone builds and rolls only the nginx container; a
change outside it deploys only the app. See D61 and
`~/www/lily.srv/docs/wboost-landing-plan.md`.

## Design source

Claude Design artifact "Wboost: Vrať si klienty" (2026-11-10), generated into
these files once and maintained by hand since. Verified by pixel diff against the
artifact at 1440 / 820 / 390 px (identical section geometry, ≤ 0.003 % pixels).
Deliberate departures: the live-chat bubble is left out for now, dead code from
earlier variants was removed, Outfit in the demo is self-hosted instead of
Google Fonts, and `/klienti` 301s to `/` (a route with no file, see
`REDIRECTED_SLUGS` in the contract test).

## Before relying on the legal pages

Both legal pages were drafted from what the product really does, without a
lawyer. Have them reviewed, in particular retention periods, invoice due dates,
termination and the liability cap in the terms.
