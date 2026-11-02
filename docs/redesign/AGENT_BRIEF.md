# Brief for screen-phase agents (P3–P9)

You are rebuilding one area of the wboost app (Symfony 7, Twig, Stimulus, Live
Components) in the **Nocturne** design of the Claude Design prototype. The
foundation (tokens, Bootstrap→Nocturne class mapping, icons, app shell) is done
— you build on it. Goal: the screen looks and behaves like the prototype
screen, responsive, both themes. Backend business logic stays unchanged.

## Read first
1. `docs/redesign/PLAN.md` (binding decisions + conventions) and the latest
   entries in `docs/redesign/JOURNAL.md` (helpers that exist, decisions made).
2. `docs/redesign/design-system.md` — §3 components, §7 design-language rules.
3. The prototype for your screens: `docs/redesign/prototype/app.js` (render
   function), `prototype/app.css` (section comments), `prototype/body.html`
   (dialog markup), and `docs/redesign/reference/screenshots/*` (Read shows images).
4. `docs/redesign/new-features.md` — "cheap now" items in your area may be
   implemented (UI-only, no schema change); everything else stays a note.
5. `CLAUDE.md` + the `docs/architecture/*.md` topic doc for your area — the
   contracts there (ids, `data-*` hooks, Live component props, tests) are load-bearing.

## Helpers that exist
- Twig: `{{ icon('name', 's16') }}` (mask icons, names = prototype `.i-*`
  without the prefix; list in `assets/styles/nocturne/icons.css`),
  `initials(name)`, `shell_projects()`.
- Page header blocks in `base.html.twig`: `eyebrow`, `title`, `title_count`,
  `page_lead`, `title_actions`, `breadcrumb` (back trail), `inner_class`,
  `mobile_primary_action`.
- Kit classes: `.stack`, `.head`, `.toolbar-card`, `.empty`, `.pill`, `.chip`,
  `.count`, `.alert` (+ `> .i` icon-circle variant), accent scopes
  `.c-success/.c-danger/.c-warning/.c-info`; Bootstrap `.btn-primary` etc. are
  already Nocturne pills. Prefer the prototype's own class names/markup for
  bespoke screen parts, implemented in your area CSS file.

## Rules
- CSS for your area goes into `assets/styles/nocturne/<area>.css` (create it,
  import it from `assets/styles/nocturne/index.css` or wherever the existing
  Nocturne files are wired — check `assets/app.js`). Touch shared Nocturne files
  only for genuine bugs, minimally, and say so in the journal (another agent may
  be editing a different area in parallel).
- Copy values from `prototype/app.css`; no new colours/radii/shadows.
- Use mask icons (`icon()`) in everything you rebuild; replace mdi/uil/ri icons
  in your area's templates.
- Keep every id, `data-*` attribute, Stimulus target/action, Live component
  contract and form field name the code or tests rely on. Grep tests for
  selectors before renaming a class.
- Missing images (local Minio lacks files) must degrade gracefully — logos and
  avatars fall back to the monogram (`initials()`), like the prototype `.sym.mono`.
- Czech copy; reuse the prototype's wording where the screen exists.
- After asset changes: `docs/redesign/tools/gate.sh --clear-assets` (takes the gate lock so it never kills another agent's test run). Never delete `var/cache/test` or the whole `var/cache`.

## Verify (all required)
- Gate: `docs/redesign/tools/gate.sh` (PHPStan + full PHPUnit, serialised with a
  lock — wait for it, do not bypass). Fix tests that asserted on markup you
  intentionally changed; never weaken a behavioural assertion.
- Visual: `node docs/redesign/tools/shoot.cjs <scratch>/shots/<phase> <paths…>`
  (390/820/1440 × dark/light; flags OVERFLOW + JSERR). Look at the PNGs, compare
  with the reference screenshots, iterate until faithful. App:
  http://localhost:18080, login `redesign@test.cz` / `Redesign123` (ROLE_ADMIN).
  Find ids with `docker compose exec -T postgres psql -U postgres -d wboost -c "…"`.
  Scratch dir: `/private/tmp/claude-501/-Users-janmikes-www-brand-manuals/9299226c-106a-49cd-874c-c4325dd94b05/scratchpad`.
- Interactions you touched (menus, dialogs, drag, Live updates): a short
  puppeteer script modelled on `shoot.cjs`. Avoid native `confirm()` buttons.

## Deliver
- Commits on `redesign/nocturne` with explicit pathspecs
  (`git commit -m "feat(redesign): …" -- <paths>`), never `git add -A`, never
  push. End messages with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- Tick your phase in `PLAN.md`; append a `JOURNAL.md` entry (what / how / why,
  files, tests changed, cheap features implemented, NOT verified, follow-ups).
  Commit those docs with your work (pathspec only your files + those two docs;
  if a docs commit races another agent, re-read and append, don't overwrite).
- Final message to the orchestrator ≤ 20 lines: commits, what changed, what is
  unverified, follow-ups.
