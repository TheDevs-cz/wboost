---
description: Find a wboost template, fill it with the given copy, preview it, and export the PNG.
argument-hint: "<template name or brief, e.g. plakát A4 pro jarní workshop 14. 3.>"
---

Produce a finished wboost export from this brief: **$ARGUMENTS**

Follow the wboost skill's loop and do not skip a step:

1. `get_context` — pick the project. If several could match the brief, ask.
2. `find_templates` with a `query` drawn from the brief. Show the candidates and
   let the user choose if it is not obvious; a template with several variants
   means several dimensions, so confirm which one they want.
3. `describe_variant` on the chosen variant. Read the inputs, their `maxLength`,
   which are `locked` / `hidable`, and which share a container.
4. Draft the copy from the brief, in the language the brief is written in. Where
   the brief does not say, keep the designer's `sampleValue` by omitting that
   input entirely — do not send `""`.
5. `render_variant`, keyed by `inputs[].id`. Read `warnings[]` and look at the
   picture. Fix overflow and any warning, then render again. Iterate here.
6. When it is right, `export_variant` **once** with the same map, and tell the
   user the PNG's size and dimensions.

If a container overflows, shorten the copy yourself and re-render rather than
asking the user to — only escalate if the text cannot be shortened without
losing something they asked for, in which case explain that the designer must
raise the container's `maxHeight` in the wboost editor.

If the token lacks `templates:export`, stop after step 5 and say so.
