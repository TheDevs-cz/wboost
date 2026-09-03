# WBoost landing page — implementation brief (Symfony)

> **How to use this file.** In a Claude Code session opened in this repo, say:
> *"Read docs/marketing/landing-page-implementation-prompt.md and do exactly what it says."*
>
> The design is finished and lives in `~/pencil/wboost.pen`. This file is about turning it
> into a real page in this Symfony app. The visual brief that produced the design is
> [`landing-page-pencil-prompt.md`](landing-page-pencil-prompt.md) — read §3 (tokens), §4
> (voice, links), §5 (copy) and §6 (visual direction) of it before writing any markup; the
> copy there is final and must ship verbatim.

---

## 0. Your task

Ship the public marketing landing page at **`/`**, move the app's current entry point to
**`/dashboard`**, and give the marketing page a **completely separate stylesheet** that
shares nothing with the Hyper admin theme.

Work in four commits, in this order. Each one must leave the app green
(`composer phpstan` + `vendor/bin/phpunit`).

1. **Route swap** — `/` becomes public, the old redirect moves to `/dashboard`. No design yet.
2. **Marketing shell** — standalone base template, own AssetMapper entrypoint, own CSS
   file with the design tokens, self-hosted fonts, the two logo lockups. A page that
   renders "hello" in the right typeface with nothing from the theme loaded.
3. **The page** — all 14 sections, desktop → tablet → mobile, verbatim Czech copy.
4. **Polish** — SEO/meta, robots.txt, a11y, motion, tests.

---

## 1. Where the design lives

Three sources, in decreasing authority:

| Source | Use it for |
|---|---|
| `~/pencil/wboost.pen` (Pencil MCP) | Exact geometry, spacing, colours, font sizes. `Get(nodeId, {depth: N})` reads any node; `ctx.bounds` gives resolved boxes. This is the spec. |
| `designs/exports/sections/*.png` (2×) | The visual target. One PNG per section — ids are mapped in the notes file. |
| `designs/wboost-landing-notes.md` | Every decision, the type system, the motion hints, and the complete **[POTVRDIT]** list. |

Frames: `hgMNG` Components · `gCoLB` Desktop 1440 · `VZfy3` Mobile 390.

**A useful trick, not a shortcut.** `Export(["<sectionId>"], "html-css", "/tmp/x.html")`
from the Pencil MCP gives you a machine-readable dump of every computed value in a
section. Read it to pull exact px/colour values instead of eyeballing the PNG. **Do not
ship any of it** — it is absolutely positioned, non-semantic and non-responsive. Hand-write
the real markup.

**Do not re-open the design questions.** If something looks wrong, note it and ask; do not
"improve" the layout while implementing.

---

## 2. Commit 1 — route swap

Today: `HomepageController` owns `/` (route name `homepage`) and redirects to `projects`.
Everything under `^/` requires a full login. 71 Twig files link `path('homepage')` (all
breadcrumbs labelled "Projekty") and 7 PHP controllers `redirectToRoute('homepage')`.

Do this:

1. **Rename the redirect controller.** `src/Controller/HomepageController.php` →
   `src/Controller/DashboardController.php`, `#[Route(path: '/dashboard', name: 'dashboard')]`,
   body unchanged (still `redirectToRoute('projects')`, still `#[CurrentUser] User $user`).
   Keeping a stable app entry point is deliberate: it is the post-login target, a sane
   bookmark, and the natural home for a real dashboard later.
2. **Rewrite the references.**
   - The 71 Twig breadcrumbs say "Projekty" and only ever end up at `/projects`; point them
     straight at `path('projects')` and drop the redirect hop.
   - `templates/base.html.twig` lines ~67, ~77, ~267 (the two sidebar logos and the mobile
     topbar logo) → `path('dashboard')`.
   - The 7 `redirectToRoute('homepage')` in `src/Controller/**` → `'dashboard'`.
   - Verify nothing is left: `grep -rn "homepage" src/ templates/ tests/` must come back empty.
3. **New landing route.** `src/Controller/LandingController.php`:
   `#[Route(path: '/', name: 'landing')]`, renders `marketing/landing.html.twig`, takes
   `#[CurrentUser] User|null $user` so the template can swap the CTA (see §5).
4. **`config/packages/security.php`** — three edits, all load-bearing:
   - Add `['path' => '^/$', 'roles' => [AuthenticatedVoter::PUBLIC_ACCESS]]` **above** the
     `^/` catch-all. Anchor it with `$` — `^/` alone would make the whole app public.
   - `firewalls.main.form_login.default_target_path`: `'/'` → `'/dashboard'`. Without this,
     every successful login lands on the marketing page.
   - `firewalls.main.logout.target` stays `'/'` — logging out onto the landing page is correct.
5. **Tests.** Rename `tests/Controller/HomepageControllerTest.php` →
   `DashboardControllerTest.php`, retarget both cases at `/dashboard`. Add
   `tests/Controller/LandingControllerTest.php`: anonymous `GET /` → 200 (not a redirect).

Commit 1 is done when an anonymous `GET /` returns 200 with an empty-ish template and the
app behaves exactly as before behind the login.

---

## 3. Commit 2 — the marketing shell

### 3.1 A standalone base, not an extension of `base.html.twig`

`templates/base.html.twig` loads `theme/css/app-saas.min.css`, `theme/css/icons.min.css`,
`theme/js/vendor.min.js`, `theme/js/app.min.js` and `importmap('app')` (which pulls
Bootstrap CSS, GrapesJS CSS, Stimulus, Turbo and the 4 000-line `assets/styles/app.css`)
**outside any overridable block**. Extending it is not an option.

Create `templates/marketing/base.html.twig` as its own `<!DOCTYPE html>` document:

- `<html lang="cs">`, no `data-turbo` attribute.
- Extract the ~20 favicon/`apple-touch-icon`/manifest lines from `base.html.twig` into
  `templates/_favicons.html.twig` and `include` it from **both** bases — one copy, two users.
- Blocks: `title` (full title, no " | WBoost" suffix — this base owns it), `meta_description`,
  `og`, `head_extra`, `body`.
- Load exactly one stylesheet and one script, both through AssetMapper.

Nothing from `public/theme/` may appear on this page. Verify with the browser network panel:
zero requests to `/theme/*`, zero to `/assets/*app*`.

### 3.2 AssetMapper entrypoint

```php
// importmap.php
'landing' => [
    'path' => './assets/landing/landing.js',
    'entrypoint' => true,
],
```

`assets/landing/landing.js` imports `./landing.css` and nothing else — **no** `bootstrap.js`,
no `@symfony/stimulus-bundle`, no Turbo, no Bootstrap. Importing `./bootstrap.js` would boot
Stimulus and auto-register every controller in `assets/controllers/`; that is the one mistake
that quietly drags the whole app onto this page.

The base template calls `{{ importmap('landing') }}`.

After adding the entry: `docker compose exec web bin/console cache:clear`, and if
`public/assets/` exists locally, `rm -rf public/assets` — a compiled dir silently disables
the AssetMapper dev server and your CSS edits will appear to do nothing.

### 3.3 CSS architecture

One hand-written file: `assets/landing/landing.css`. No Tailwind, no Bootstrap, no
preprocessor, no CSS framework, no utility soup.

- Every class prefixed `lp-`. The page is standalone, so this is for greppability, not
  isolation.
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

- File order: reset → tokens → type scale → layout primitives (`lp-section`,
  `lp-container`, `lp-eyebrow`, `lp-rule`) → components (`lp-btn`, `lp-chip`, `lp-tag`,
  `lp-cluster`, `lp-editable`, `lp-card`, `lp-tile`, `lp-poster`) → one block per section
  in page order → media queries grouped at the end of each block.
- Budget: **under 40 KB uncompressed**. If you are past it you are repeating yourself.

### 3.4 Fonts — self-hosted, three families

Instrument Serif 400, Nunito 400/600/700/800, IBM Plex Mono 400. **woff2 only**, downloaded
into `assets/landing/fonts/`, declared with `@font-face` and `font-display: swap`.

Do not link Google Fonts: this page sells to Czech designers and agencies, a third-party
font request is a GDPR conversation nobody needs, and self-hosting removes a render-blocking
DNS round-trip. Do not reuse `public/theme/fonts/Nunito-*` either — those are the theme's
copies, they ship no ExtraBold (the design uses Nunito 800 inside the client artwork), and
the marketing page must not depend on the theme directory.

Preload the two faces used above the fold (Instrument Serif 400, Nunito 700).

### 3.5 The logo, and the light lockup

The only asset is `assets/images/logo-lg.svg`: `w` in slate `#8491a0`, a tab in `#7075b5`,
`boost` in white — so it only works on dark.

**Inline the SVG in Twig** (`templates/marketing/_wordmark.html.twig`) rather than using
`<img>`, put a class on each path group, and drive both lockups from CSS:

```css
.lp-wordmark .lp-wordmark__w    { fill: var(--lp-slate); }
.lp-wordmark .lp-wordmark__tab  { fill: var(--lp-logo-accent); }
.lp-wordmark .lp-wordmark__word { fill: var(--lp-white); }
.lp-wordmark--ink .lp-wordmark__word { fill: var(--lp-ink); }
```

The path data and `viewBox="0 0 1099.07 298.68"` are in the SVG file; the `boost` letters
are the five paths with classes `fil2`/`fil3`. Set the size with `width` + `height:auto` on
the `<svg>`. Do not redraw anything.

The ink-on-paper lockup is **[POTVRDIT]** — flag it in the PR description.

---

## 4. Commit 3 — the page

### 4.1 Template layout

```
templates/marketing/
  base.html.twig
  landing.html.twig          ← includes the sections in order, nothing else
  _favicons.html.twig        ← shared with base.html.twig (lives at templates/ root)
  _wordmark.html.twig
  sections/
    _nav.html.twig  _hero.html.twig  _proof.html.twig  _problem.html.twig
    _how.html.twig  _showcase.html.twig  _modules.html.twig  _ai.html.twig
    _audiences.html.twig  _story.html.twig  _pricing.html.twig  _faq.html.twig
    _cta.html.twig  _footer.html.twig
  parts/
    _poster_water.html.twig    ← the "ODSTÁVKA VODY" municipal notice
    _poster_match.html.twig    ← the "DOMÁCÍ UTKÁNÍ" club graphic
    _editable.html.twig        ← the signature motif (dashed box + tag + cluster)
    _icon.html.twig            ← inline SVG icons, one consistent set
```

Sections are `<section id="…">` with the ids the nav anchors expect: `funkce`,
`jak-to-funguje`, `pro-koho`, `ai`, `pribeh`, `cena`. Use real landmarks — `<header>`,
`<main>`, `<nav>`, `<footer>` — and one `<h1>`, then `<h2>` per section.

### 4.2 The two things that carry the page

**The signature motif** appears exactly three times (hero, "Vyplnění a export" tile, footer)
and must be one partial. In CSS it is a plain `border: 1.5px dashed var(--lp-primary)` with a
2–3 px radius — **not** the baked dash paths Pencil had to use. The tag is a small indigo pill
absolutely positioned at the box's top-left, the pencil+eye cluster at its top-right. The
hero's "Datum" box is the *active* state: solid 1.5px border plus `background: #727cf51a`.

**The client artworks** are pure CSS. Build each poster once and scale it with a single
`font-size` on its root, sizing everything inside in `em`:

```css
.lp-poster        { font-size: 10px; }   /* hero, 400 px wide */
.lp-poster--thumb { font-size: 2.4px; }  /* format rail */
```

Photographs are `linear-gradient(118deg, …)` fields in the client's own brand colour — the
exact stops are in the `.pen`. **No stock photography anywhere on this page** (§6 of the
design brief). The two demo brands are deliberate: deep green for the municipality, brick for
the football club, because they are two different fictional clients.

Everything else that looks like the app — layers panel, input-properties popover, export
button group, manual page with the colour palette, chat transcript with tool-call chips,
export-history list, e-mail signature, API code block, QR grid — is flexbox + hairlines +
11–14 px type. No screenshots, no device mockups, no icon fonts. Icons are inline SVG at a
single 1.5 px stroke weight from one set (Lucide, matching the design).

### 4.3 Responsive

The design gives you 1440 and 390. Three breakpoints between them:

| Range | Behaviour |
|---|---|
| ≥ 1200 | The Desktop 1440 artboard. Content `min(1200px, 100% - 240px)`, centred. |
| 960–1199 | Same structure, content `100% - 96px`. The hero composition scales with `transform: scale()` on its wrapper, or shrinks via its `font-size` token. |
| 640–959 | Hero stacks (copy, then composition). Bento: the full-width tile stays, `740+436` stacks, the 3-column row becomes 2, the 2-column row stacks. Showcase becomes the horizontal scroll strip. AI section stacks (chat panel, then bullets). FAQ single column. Story: copy, then founders side by side. |
| < 640 | The Mobile 390 artboard. Everything single column, 20 px gutters (16 px for the AI section so the chat panel keeps its width). |

The hero composition is one `position: relative` block with absolutely positioned children.
Below 960 the format rail, the toast, the open popover and the "Datum" active box are
`display: none` — the mobile artboard keeps **two** dashed boxes (Nadpis, Fotografie) and
nothing else. Do not build two copies of the markup.

The showcase strip below 960 is `overflow-x: auto; scroll-snap-type: x mandatory` with the
`posuňte prstem →` hint; hide the hint on pointer-fine devices.

Fluid type via `clamp()` between the mobile and desktop values from the notes (H1 40→62,
section titles 34→46, the three 52 px sections 40→52, body 16→18).

Touch targets ≥ 44 px; on mobile the buttons are full width at 52 px.

### 4.4 Copy and links

All copy verbatim from §5 of the design brief, **including the typography**: non-breaking
spaces after every one-letter preposition and conjunction (v, k, s, z, a, i, o, u), en
dashes, `×` for multiplication, Czech quotes `„ “`. Write `&nbsp;` in the Twig or paste the
real U+00A0 — either is fine, but do not lose them; they are already correct in the `.pen`
and you can read them back from there.

Links: every primary CTA → `path('registration')`. *Přihlásit se* → `path('login')`.
*Dokumentace API* → `/api/docs`. *AI a MCP server* → `/ai`. Footer e-mail → `mailto:info@wantoo.cz`.
Nav → the section anchors. Use `path()`, never hard-coded URLs, for anything inside this app.

---

## 5. The logged-in visitor

`/` is now public, so a signed-in user can land on it. Do **not** redirect them away — the
founders need to be able to open their own marketing page. Instead, swap the two nav
actions when `app.user` is set:

- *Přihlásit se* + *Vyzkoušet zdarma* → a single *Přejít do aplikace* → `path('dashboard')`.
- The hero, modules, pricing and final-CTA buttons keep their labels but point at
  `path('dashboard')` too, and the "Zdarma a bez platební karty…" microcopy is hidden.

Keep it to one Twig conditional per CTA; do not fork the templates.

---

## 6. Commit 4 — polish

**SEO / meta.** `<title>` ≈ *"WBoost — brand manuály, šablony a export pro grafiky a
marketingové týmy"*. Meta description from the hero subhead. `rel="canonical"` to
`https://wboost.cz/`. Open Graph + Twitter card (`og:title`, `og:description`, `og:url`,
`og:type=website`, `og:locale=cs_CZ`, `og:image`). **The OG image does not exist yet** —
export one 1200×630 from the hero composition in Pencil and put it in `assets/landing/`,
or flag it as a follow-up. JSON-LD `Organization` (Wantoo Design s. r. o., the real address
and IČ from §5.13) + `SoftwareApplication`. Nothing invented — see the [POTVRDIT] list.

**`public/robots.txt` does not exist.** Add one: allow everything, `Disallow: /api/`,
`Disallow: /_mcp`, and a `Sitemap:` line only if you also add a sitemap. Static files in
`public/` bypass Symfony, so no security config is needed.

**Accessibility.** Skip link to `#main`. `aria-expanded` + `aria-controls` on the FAQ
buttons and the mobile menu toggle. Visible `:focus-visible` rings (2 px `#727cf566`, the
state defined in the Components frame). Contrast is already AA in the design — do not
darken or lighten anything without re-checking. Body text never below 14 px.

**Motion**, from §7 of the notes and nothing more:
- Hero dashed outlines fade in one by one, ~80 ms apart, popover last.
- Sticky nav gains its hairline on first scroll.
- The showcase headline nudges ~6 px once, on scroll into view, on all four formats together.
- FAQ height transition, `+` rotates to `−`.
- All of it behind `@media (prefers-reduced-motion: reduce) { … }`.

**JavaScript budget: under 3 KB.** Vanilla, no framework. Four things only: nav scroll class,
mobile menu toggle, FAQ accordion (`<details>`/`<summary>` styled is fine and needs almost
no JS), one `IntersectionObserver`. If you reach for a library, you have gone wrong.

**Tests** (`tests/Controller/LandingControllerTest.php`):
- anonymous `GET /` → 200
- the page contains the H1 *"Navrhněte jednou."* and at least one `href` to `/registration`
- the response does **not** reference `theme/css/app-saas.min.css` (this is the regression
  test that keeps the theme off the marketing page)
- a logged-in `GET /` → 200 and contains `/dashboard`
- `DashboardControllerTest`: anonymous `/dashboard` → redirect to `/login`; logged-in →
  redirect to `/projects`

---

## 7. Commands

Everything runs in the container:

```bash
docker compose exec web composer phpstan          # level max, must pass
docker compose exec web vendor/bin/phpunit        # must pass
docker compose exec web bin/console cache:clear   # after touching importmap.php
docker compose exec web bin/console debug:router | grep -E ' / | /dashboard '
rm -rf public/assets                              # if a compiled dir exists locally
```

Verify in a real browser at 1440, 1024, 768 and 390, logged out and logged in. Compare each
section against `designs/exports/sections/*.png` side by side. The page has no images, so
first paint should be essentially instant; check that nothing from `/theme/` or the app
importmap is requested.

---

## 8. Definition of done

- `GET /` is public and renders the full page; `GET /dashboard` behaves like the old `/`.
- Login lands on `/dashboard`, logout lands on `/`.
- No reference to `homepage` remains anywhere in `src/`, `templates/` or `tests/`.
- The marketing page loads **only** `assets/landing/*` — no theme CSS, no Bootstrap, no
  Stimulus, no Turbo, no GrapesJS.
- All 14 sections present in order, copy verbatim, at all four widths.
- CSS under 40 KB, JS under 3 KB, fonts self-hosted as woff2.
- PHPStan max and PHPUnit green.
- The PR description lists every **[POTVRDIT]** item from §10 of
  `designs/wboost-landing-notes.md` that is still open — especially the light logo lockup,
  the missing OG image, the placeholder privacy/terms links, and the customer-logo strip.

## 9. Out of scope

Analytics and any cookie/consent banner (there is none in the app today and adding one is a
separate decision), a CMS or editable copy, A/B testing, a blog, the privacy and terms pages
themselves, and any change to the app behind the login beyond the route move.
