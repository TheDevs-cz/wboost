Roboto (variable, weights 100–900) — the nine subset files Google Fonts serves
for `css2?family=Roboto:wght@400;700` (version v51, fetched 2026-11-14), with
their `unicode-range`s in `faces.json` (Google's order).

Used by the weekly-menu PDF (`WeeklyMenuPdfGenerator`), which embeds them as
`data:` URLs: the headless renderer has no network access (see
`docs/architecture/templates-render.md` § "Renderer isolation").

Licence: SIL Open Font License 1.1 — Copyright 2011 The Roboto Project Authors
(https://github.com/googlefonts/roboto-3-classic).
