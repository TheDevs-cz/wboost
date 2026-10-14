# `landing/` — the wboost.cz marketing site

A **standalone static site**: two marketing pages (`/` for graphic designers,
`/klienti` for their clients) plus two legal pages. Plain HTML, CSS and JS, two
self-hosted woff2 faces (Montserrat, normal + italic), one SVG icon sprite and
three WebP illustrations. **No PHP, no Twig, no Symfony, no Node, no build step,
no framework, no CDN.** It never imports from `assets/`, never reads
`templates/`, never calls `path()` — you could delete the whole Symfony app and
these pages would still open in a browser (the lead forms would just fail
gracefully with an e-mail fallback).

It shares a domain with the application and nothing else. That creates exactly
three couplings, and `tests/Controller/LandingContractTest.php` (the only file
in `tests/` that knows this directory exists) puts an assertion on each:

1. the URLs the landing links **into** the app — today only `/login`;
2. the form `action`s that post **into** the app — `POST /poptavka`
   (`src/Controller/Landing/LandingLeadController.php`);
3. the paths the app must never claim back — `/`, `/landing/*`, each page slug.

## Leads (`POST /poptavka`)

The contact form on `/`, the "Vyzkoušejte si to" form on `/klienti` and the
"Máte dotaz?" chat bubble on `/klienti` all post to the app's
`LandingLeadController` (same origin — the path is not in the landing's Traefik
rule). Each lead is stored in `landing_lead`, e-mailed to `LANDING_LEAD_EMAILS`
(default `info@wantoo.cz`, Reply-To = the visitor) and listed for admins at
`/admin/landing-leads` ("Poptávky z webu"). Spam guards: a hidden `fax`
honeypot and the `landing_lead` rate limiter (20 / hour / IP). The forms submit
with `fetch()` and `Accept: application/json` (201 / 422 with field errors /
429); without JS they post natively and come back with `?odeslano` / `?chyba`.
The chat is not real-time: it holds the first message, asks for an e-mail and
then delivers it as a `source=chat` lead. On `:8091` in dev the endpoint 404s
(nginx, not the app) — test it on the app port or via request interception.

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
  nginx.conf          caching + gzip only; security headers come from Traefik
  traefik-rule.txt    the router rule the page set implies (asserted by the test)
  src/                the document root, and the only thing inside the image
    index.html                     →  /                (pro grafiky)
    klienti.html                   →  /klienti         (pro firmy, obce a organizace)
    ochrana-osobnich-udaju.html    →  /ochrana-osobnich-udaju
    obchodni-podminky.html         →  /obchodni-podminky
    robots.txt · sitemap.xml
    landing/
      site.css · site.js           shared: tokens, nav, sections, steps, forms, FAQ, footer, lead submit
      home.css · home.js           `/` only: calculator, tabs, "Co právě tvoříme"
      klienti.css · klienti.js     `/klienti` only: calculator, dialogs, tag input, chat
      rz-demo.js                   `/klienti` live poster → every-format mock-ups (verbatim port)
      icons.svg                    Lucide 0.469 sprite: <svg class="i"><use href="/landing/icons.svg#name"/></svg>
      fonts/ montserrat(-italic).woff2 (variable, Latin + Latin Ext. subset) · OFL-montserrat.txt
      img/   wboost-logo-{dark,white}.svg · hero-editor / hero-illustration / ai-mcp .webp · og-cover(-klienti).png
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

Claude Design export `wboost_system` (Jan's `~/Downloads/wboost_system`):
`ui_kits/landing/Wboost LP v3.html` → `index.html`, `ui_kits/landing/LP -
klienti.html` → `klienti.html`, tokens from `tokens/*.css`, brand rules in its
`readme.md` (Montserrat only, indigo `#737EED` action colour, mint for "live",
Czech quotes, no emoji). The port keeps every value 1:1; the export's inline
styles and `[style*=…]` media-query hooks became named classes. Verified by
pixel diff against the export at 1440 / 1024 / 390 (section geometry identical,
remaining diffs are line breaks from the added `&nbsp;` after one-letter
prepositions). Deliberate departures from the export:

- copy slips fixed (approved 2026-10-14) and the hero CTA's dead `#s8` → `#kontakt`;
- the floating "Co právě tvoříme" / chat wrappers no longer swallow clicks over
  their invisible closed panels (a real bug in the export);
- "Co právě tvoříme" uses its specified smaller phone size and "Nahrát obrázek"
  keeps icon + label on one line (both were lost to cascade order in the export);
- footers carry the legal links; forms carry a one-line privacy note;
- unreachable "Správně" detail dialogs and dead CSS/JS were dropped.

Breakpoints are the design's: 1080 (burger), 900 (one column), 860, 820, 560;
above 1260 the column is `zoom`ed 1.12 → 1.32 exactly as designed.

## Still open before launch

- **Placeholders shipped on purpose** (Jan, 2026-10-14): client-logo boxes on
  both pages, the two "Foto" circles in "Kdo za tím stojí?", the FAQ answer
  "Kolik to stojí?" (green `[DOPLNIT]`). Replace when the content exists.
- **Both legal pages are still shells** (`[DOPLNIT]`, incl. the date) — now
  linked from every footer and from the privacy note under the forms.
- The `/klienti` calculator uses 2 500 Kč / month and 4 formats per output
  (`klienti.js`, confirmed for launch).
