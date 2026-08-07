---
description: Show the wboost projects, templates and variants this account can reach.
argument-hint: "[project or template name to filter by]"
---

Orient the user in their wboost account.

1. Call `get_context`. Report, per project: its name, how many templates and
   variants it holds, its brand fonts and brand colours, and the canvas
   dimensions it designs at.
2. If `$ARGUMENTS` is non-empty, treat it as a filter: pick the project whose
   name matches (ask if several do), then call `find_templates` on it with
   `query: "$ARGUMENTS"` and list the matching templates with their variants —
   name, category, dimension label and pixel size, and whether the variant is
   `grouped`.
   If `$ARGUMENTS` is empty and there is exactly one project, call
   `find_templates` on it with no query and list everything. With several
   projects, list the projects only and ask which one to open.
3. End by telling the user they can ask you to fill and export any variant — and,
   if `templates:design` is granted, to author a variant's design too. Report the
   granted `scopes` whenever one is missing that limits what you can offer:
   without `templates:export` you cannot hand them a file, without
   `templates:design` you cannot design, without `gallery:write` you cannot add
   pictures.

Keep it a short readable summary, not a JSON dump. Always mention project and
template names to the user; keep the ids for your own next call.
