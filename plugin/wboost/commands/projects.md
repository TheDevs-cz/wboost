---
description: Show the wboost projects, templates, documents, variants and desk calendars this account can reach.
argument-hint: "[project or template name to filter by]"
---

Orient the user in their wboost account.

1. Call `get_context` with `{"skillVersion": "0.4.0"}` (a calendar-only
   connection has no `get_context`: call `list_calendars` with the same
   argument instead and list the calendars). Report, per project: its name, how many templates and
   variants it holds, its brand fonts and brand colours, and the canvas
   dimensions it designs at.
2. If `$ARGUMENTS` is non-empty, treat it as a filter: pick the project whose
   name matches (ask if several do), then call `find_templates` on it with
   `query: "$ARGUMENTS"` and list the matching templates with their variants —
   name, category, dimension label and pixel size, and whether the variant is
   `grouped` — plus any multi-page `documents[]`. If the connection has
   `calendars:read`, also call `list_calendars` (with the project id) and list
   its calendars: name, year, week / fortnight, finished or not.
   If `$ARGUMENTS` is empty and there is exactly one project, call
   `find_templates` on it with no query and list everything. With several
   projects, list the projects only and ask which one to open.
3. End by telling the user they can ask you to fill and export any variant — and,
   if `templates:design` is granted, to author a variant's design too. Report the
   granted `scopes` whenever one is missing that limits what you can offer:
   without `templates:export` you cannot hand them a file, without
   `templates:design` you cannot design, without `gallery:write` you cannot add
   pictures, without `calendars:write` you cannot create or fill calendars.
   If `skill.status` is not `current`, add its `message` once at the end.

Keep it a short readable summary, not a JSON dump. Always mention project and
template names to the user; keep the ids for your own next call.
