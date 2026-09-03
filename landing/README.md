# `landing/` — the wboost.cz marketing site

A **standalone static site**. Plain HTML, one CSS file, one JS file, three woff2
faces and one SVG. **No PHP, no Twig, no Symfony, no Node, no build step, no
framework.** It never imports from `assets/`, never reads `templates/`, never
calls `path()` and never touches the database — you could delete the whole
Symfony app and these pages would still open in a browser.

It shares a domain with the application and nothing else. That creates exactly
two couplings, and `tests/Controller/LandingContractTest.php` (the only file in
`tests/` that knows this directory exists) puts an assertion on each:

1. the URLs the landing links **into** the app — `/registration`, `/login`, `/ai`;
2. the paths the app must never claim back — `/`, `/landing/*`, each page slug.

## Look at it

```bash
docker compose up -d landing      # http://localhost:8090
```

The bind mount makes edits live with no rebuild; the image build is
production-only. Opening `src/index.html` off the filesystem does **not** work —
the assets are root-relative (`/landing/style.css`) because the site is
multi-page and `/ochrana-osobnich-udaju` sits at a different depth than `/`.

The app links (`/registration`, `/login`, `/ai`) 404 on `:8090` in dev. Expected.

## Layout

```
landing/
  Dockerfile          FROM nginx:alpine — build context is this directory
  nginx.conf          caching + gzip only; security headers come from Traefik
  traefik-rule.txt    the router rule the page set implies (asserted by the test)
  src/                the document root, and the only thing inside the image
    index.html                     →  /
    ochrana-osobnich-udaju.html    →  /ochrana-osobnich-udaju
    obchodni-podminky.html         →  /obchodni-podminky
    robots.txt · sitemap.xml
    landing/ style.css · script.js · fonts/*.woff2 · img/logo.svg · img/og-cover.png
```

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

`~/pencil/wboost.pen` (exact geometry) · `designs/exports/sections/*.png` (the
visual target) · `designs/wboost-landing-notes.md` (every decision, plus the
open **[POTVRDIT]** list). Two artboards are designed — 1440 and 390 — and
deliberately no third: the layout is built from `min()`, `clamp()`, `auto-fit`
grids and `flex-wrap`, so the widths between are derived rather than guessed.
There are exactly **three media queries**, all at 960, and each exists because
the CONTENT changes there, never merely the geometry:

1. the nav's six anchors are replaced by the drawer toggle;
2. the hero's format rail, toast, popover and active "Datum" box are removed and
   the composition becomes the artwork plus two dashed boxes;
3. the showcase's four-format staircase becomes a swipeable strip and the indigo
   connector is dropped.

Budget: JS under 3 KB (2.1 KB) and no images beyond the logo — both met. **The
CSS misses its 40 KB target: 55 KB uncompressed, 13 KB gzipped.** The dedupe
pass was done (shared mono-label rule, shared heading rules, dead rules
removed); what is left is ~9 KB of explanatory comments and one block per
section for fifteen sections plus a dozen hand-drawn product fragments —
the fragments are the design, so the only way under 40 KB is to drop either
the comments or the artwork. Flagged rather than silently traded away.

## Still open before launch

- **Both legal pages are shells.** Nine headings and `[DOPLNIT]` placeholders,
  including `Poslední aktualizace: [DOPLNIT DATUM]`. The wording is a legal
  question, not a design one — the footer already links to them.
- The **Reference** section is built and commented out in `index.html`; reveal it
  only when there is at least one real, approved quote.
- The **light-surface wordmark lockup** (`boost` in ink) is a proposal awaiting
  sign-off; it is what the nav uses today.
