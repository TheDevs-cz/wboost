# Redesign "Nocturne" — replacing the Hyper theme

Shipped: built on `redesign/nocturne`, merged to `main` 2026-11-03 (PR #8). Follow-up fixes go on `main`.

Goal: replicate the visual design (UI + UX) of the Claude Design prototype
"Wboost Prototyp" across the whole wboost app (not the landing pages), and
throw away the Hyper Bootstrap theme. Backend business logic stays as is;
new features seen in the prototype are only noted (`new-features.md`) unless
they are trivially cheap to add.

## Reference material (read before touching UI)

| Path | What |
|---|---|
| `prototype/wboost-prototype.html` | The full built prototype (single file, as served by the artifact frame). Open locally in a browser — projects come from the artifact DB so the dashboard is empty offline; everything else is seeded. |
| `prototype/app.css` | All prototype CSS (Nocturne tokens in `:root`, light theme override, every component). **Source of truth for exact values.** |
| `prototype/body.html` | Static markup (shell, sidebar, `<dialog>`s). |
| `prototype/app.js` | Vanilla JS: hash router + render functions per screen (see `design-system.md` for the map). |
| `reference/screenshots/*.jpg` | Screens captured from the live prototype (desktop 1558 px, light theme, phone 430 px). |
| `design-system.md` | Implementation-ready design-system spec: tokens, layout, components, breakpoints, design-language rules. |
| `inventory.md` | Current wboost UI inventory: routes, templates, Hyper dependencies, contracts that must not break. |
| `PLAN.md` | Phased implementation plan + progress log (the orchestration state for long sessions). |
| `new-features.md` | Business logic/features the prototype shows that the backend does not have yet. |

Live prototype: https://claude.ai/artifact/MfGLZ9csA4N8D98CKxBVsZ (hash routes
`#projekty`, `#uzivatele`, `#statistiky`, `#projekt-{id}`,
`#projekt-{id}/manualy|sablony|galerie|fonty|barvy`, `#projekt-{id}/sablony/{tid}`,
`#projekt-{id}/sablony/{tid}/vyplnit`).

## Tools

- `tools/shoot.cjs` — logs in and screenshots paths at 390/820/1440 × dark/light, flags horizontal overflow and JS errors. Defaults to the throwaway admin `redesign@test.cz` / `Redesign123`, which is deleted after each session — recreate it first (see `.claude/skills/verify/SKILL.md`: `app:user:register` + set `ROLE_ADMIN`), and delete it again afterwards. Needs `puppeteer-core` (`PUPPETEER_DIR`) and local Chrome.
- `tools/gate.sh` — PHPStan + full PHPUnit behind a lock (safe with parallel agents); `--clear-assets` clears dev cache + compiled assets under the same lock.
