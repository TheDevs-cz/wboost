---
description: Create, design or fill a wboost desk calendar (Kalendáře) from a brief, and show the pages.
argument-hint: "<what to do, e.g. stolní kalendář 2027 pro obec Chotěbuz, týdenní, foto vlevo — nebo: doplň svozy odpadu>"
---

Work on a wboost desk calendar from this brief: **$ARGUMENTS**

Read `${CLAUDE_PLUGIN_ROOT}/skills/wboost/references/calendars.md` first (the
wboost skill's calendar reference), then:

1. `list_calendars` with `{"skillVersion": "0.5.0"}` (add `projectId` when you
   already know the project — an account can hold many calendars). If the brief names an
   existing calendar, pick it (ask if several match); if it asks for a new one,
   find the project (ask the user when no calendar shows it yet).
2. New calendar: `manage_calendar` `create` with the name, year, `type`
   (`week` → preset `photoLeft` / `photoRight`, `fortnight` → `twoSided`) and
   the preset, so it has pages at once. Switch on the `features` the content
   will need (`events`, `waste`, `specialPages`, …) in the same call.
3. `get_calendar` — read `role`, `features`, `layout`, `pages[].key`, the
   current `content` and `fonts`. A `client` role may only fill content.
4. Make the change the brief asks for: styles and boxes with
   `set_calendar_layout` (patch only what changes; pt, mm, `#RRGGBB`, fonts
   from `fonts[].family`), pictures with `upload_calendar_asset` then reference
   the `assetId`, content with `update_calendar_content` (WHOLE items, dates
   `YYYY-MM-DD`, new ids of 16 random letters and digits).
5. `render_calendar_page` on the pages you touched — at least `cover` and one
   week page (`w01`), plus `sp:intro` / `sp:contacts` / `back` when you filled
   them. Look at each picture, read `warnings[]` and the earlier `notes[]`, fix,
   render again.
6. Report what changed (the `recorded[]` history lines are a good summary) and
   give the user the calendar `url`. Only on request: `manage_calendar`
   `finish`, `client_link` (a secret — the user only) or `export_pdf` (poll
   `export_status`, then hand over `downloadUrl`).

Never delete or overwrite content the brief did not ask about; comments are
permanent. If `skill.status` was not `current`, mention the update once at the
end.
