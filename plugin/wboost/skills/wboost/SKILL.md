---
name: wboost
description: Use when working with wboost brand templates — listing the user's wboost projects, brand fonts and colours; finding a template variant; filling its text and image placeholders; previewing the filled design; or exporting the finished PNG. Covers the six wboost MCP tools (get_context, find_templates, describe_variant, list_gallery, render_variant, export_variant), the ids-never-names rule, container overflow, and the render-then-export loop.
---

# wboost templates

wboost is a brand-manual and template platform. A designer authors a **template
variant** — a canvas with text placeholders, picture slots and brand assets —
and you fill it with the user's copy and export a finished image.

This connector gives you six tools. They are **read + render only**: you can
look at anything the user's account can see, and you can produce images from it.
You cannot change a template, upload a picture, or delete anything. Authoring
new designs is not available through this connector yet — when a user asks for a
new template, point them at the wboost editor.

## The loop

```
get_context          → which projects, which brand fonts, which brand colours
  └ find_templates   → which templates and variants exist in one project
      └ describe_variant  → the input ids, their rules, the containers
          ├ list_gallery      → picture ids for image slots (only if needed)
          ├ render_variant    → cheap preview; iterate here
          └ export_variant    → the deliverable, once
```

Every step feeds the next with **ids**. Never skip `get_context` and never skip
`describe_variant` — there is no other source for the ids the fill is keyed by.

## The five rules

### 1. `get_context` first, always

It returns who the token acts as, which scopes it carries, and for every project
the user can reach: `id`, `name`, `fonts[]`, `colors[]`, `dimensions[]` and
counts.

The `fonts[]` strings are the **exact** face families the renderer registers —
e.g. `"Rubik (Rubik Bold)"`. A styled run must name one of them byte for byte;
an unknown family is rejected, never substituted. The `colors[]` are the brand
palette in lowercase `#rrggbb`. Prefer them over inventing hex values (export
accepts any hex, but the point of a brand manual is not to).

Call it once per session. Call it again only if the user says they have just
added a project, font or colour.

### 2. Ids, never names — and read the warnings

Every tool takes UUIDs. `inputs` and `images` maps are keyed by **inputId** from
`describe_variant` (`inputs[].id`, `imageInputs[].id`), not by the input's
`name`. Two inputs may legitimately share a name, so names are not addresses.

An id that matches nothing is **silently ignored** by the render — the picture
comes back looking untouched. The tools surface this as a `warnings[]` entry in
the JSON summary that precedes the image:

> These inputs ids match no text input on this variant and were ignored: …

**Read `warnings[]` on every render and export.** An empty picture with a warning
is not "the tool failed"; it is "you keyed the fill wrong". Locked inputs are
warned about the same way.

### 3. `render_variant` to iterate, `export_variant` once

|  | `render_variant` | `export_variant` |
|---|---|---|
| output | lossy WebP, ≤ 1200 px long edge | lossless PNG at the variant's real size |
| container overflow | **warned**, picture returned | **refused**, nothing produced |
| usage report | not counted | counted as a real export |
| scope | `templates:read` | `templates:export` |

An A4 variant is 2480 × 3508 px. One export per phrasing attempt is slow, burns
the context window, and pollutes the user's export statistics. So: shape the copy
with `render_variant`, look at the picture, adjust, and call `export_variant`
exactly once when it is right.

`render_variant` enforces *everything else* strictly — maxLength, the font
whitelist, per-slot image limits. A value it accepts cannot then be refused by
the export for those reasons. Overflow is the one contract it relaxes.

### 4. Container overflow is the failure you will actually hit

A **container** is a smart text area: its members reflow vertically, so a text
that wraps to more lines pushes everything below it down. The designer sets a
`maxHeight`. Content past that is an overflow.

`export_variant` refuses it:

> The texts of container `<uuid>` overflow its max height of 700 px by 42.5 px.
> Its fillable text inputs are "Nadpis" (id …), "Perex" (id …) — shorten one of
> them, hide a hidable one, or have the designer raise the container maxHeight in
> the wboost editor. Which one is too long cannot be told apart here: they share
> one vertical flow.

Two things follow:

- **The server cannot tell you which text is too long** when several share one
  flow, and it deliberately does not guess a character count — overflow is
  measured in pixels of *wrapped* text, which only the browser knows. Do not ask
  it for a target length; shorten the text you judge most compressible and
  re-render.
- **The fixes are:** shorten a text; hide an input that `describe_variant` marks
  `hidable: true` (send `{"hide": true}`); or tell the user their designer must
  raise `maxHeight` in the editor. You cannot raise it from here.

`describe_variant` reports `containers[]` and a `containerId` on each member
input, so you can see which texts share a flow *before* you fill them.

### 5. Respect what the designer locked down

From `describe_variant`, per text input:

- **`locked: true`** — cannot be written at all. Addressing it is warned about and
  ignored. Do not retry it a different way.
- **`hidable: true`** — may be blanked with `{"hide": true}`. `hidable: false`
  means `hide` is ignored.
- **`maxLength`** — **rejects, it does not truncate.** An over-long value is a
  refusal from both render and export ("Input "Nadpis" exceeds max length of 24
  characters."). Count characters before sending.
- **`uppercase: true`** — applied server-side. Send natural casing; do not
  pre-shout.
- **`sampleValue`** — the designer's default. **Omit the id** and that renders.
  Send `""` and nothing renders for it. Those are different: leave inputs you
  are not filling out of the map entirely.
- **`grouped: true`** on the variant — it belongs to a synchronized template
  group, one design maintained across several dimensions. Filling, rendering and
  exporting work exactly as normal; only *authoring* is different (it happens in
  the group editor, not per variant). Mention it if the user wants a change to
  propagate across dimensions.

## Fill value shapes

Text (`inputs`, keyed by `inputs[].id`):

```jsonc
"a1b2…": "Plain text"                          // the common case
"a1b2…": { "value": "Plain text", "hide": false }
"a1b2…": { "hide": true }                      // hidable inputs only
```

Rich text — **only** for an input `describe_variant` marks `richText: true`:

```jsonc
"a1b2…": {
  "runs": [
    { "text": "Bold bit",  "fontFamily": "Rubik (Rubik Bold)", "color": "#0a7d3f", "underline": false },
    { "text": " and rest", "fontFamily": null, "color": null, "underline": false }
  ],
  "lines": ["p"]                               // one entry per \n-separated line
}
```

- `fontFamily` must be one of `richTextOptions.fonts` on that variant (or null).
- `lines` needs `lists: true`; values are `"p"`, `"ul"`, `"ol"`, and — with
  `listCheckboxes: true` — `"cb"` (unchecked) / `"cbx"` (checked). All-`"p"` is
  the same as sending no `lines` at all.
- A non-null `checklist` object means the input *is* a checkbox list; its four
  flags say what the end user is allowed to change.
- Sending `runs` to a non-rich input, or `ul` to a non-lists input, is a refusal
  naming exactly that.

Pictures (`images`, keyed by `imageInputs[].id`):

```jsonc
"c3d4…": "<gallery image id from list_gallery>"
"c3d4…": { "imageId": "…", "scale": 1, "offsetX": 0, "offsetY": 0, "rotation": 0 }
"c3d4…": { "hide": true }
```

- A slot with `allowMove` / `allowResize` / `allowRotate` false **refuses** that
  adjustment rather than ignoring it.
- `isBackground: true` slots are always a deterministic cover of the whole canvas
  — send the id alone, no transform.
- The picture must sit in one of the slot's `directories[]` (or, when
  `includesRoot: true`, at the gallery root). `list_gallery` walks that tree one
  level at a time; omit `directoryId` for the root, which holds real pictures and
  is not merely a folder container.
- Slots you leave out keep the designer's stand-in picture.

## A worked example

> "Make me an A4 poster for the spring workshop — 14 March, at the Brno studio."

1. **`get_context`** → project `Studio Brno` (id `01915…`), fonts
   `["Rubik (Rubik)", "Rubik (Rubik Bold)"]`, colours `["#0a7d3f", "#1c1c1c"]`.
2. **`find_templates`** with that `projectId` and `query: "plakát"` → template
   *Plakát A4*, one variant, `dimension.label` `"210 × 297 mm"`,
   `width/height` 2480 × 3508, `grouped: false`.
3. **`describe_variant`** on the variant id → four inputs: `Nadpis`
   (`maxLength: 28`, `uppercase: true`), `Podtitul`, `Datum`, `Místo`
   (`hidable: true`); one image slot `Foto`; one container holding `Nadpis` +
   `Podtitul` with `maxHeight: 700`.
4. **`render_variant`** keyed by those four ids:
   `{"<nadpis-id>": "Jarní workshop", "<podtitul-id>": "…", "<datum-id>": "14. března", "<misto-id>": "Studio Brno"}`.
   The summary says `warnings: []` and the picture looks right — except the
   subtitle wraps to three lines and the warning names the container.
5. Shorten `Podtitul`, **`render_variant`** again → no warnings.
6. **`export_variant`** with the same map → 2480 × 3508 PNG. Done, once.

If step 3 had shown the photo slot as unfilled and the user wanted a specific
picture, `list_gallery` on the project sits between 3 and 4.

## Common mistakes

1. **Keying the fill by input name.** Renders the untouched design and only says
   so in `warnings[]`.
2. **Skipping `describe_variant`** and guessing ids from a previous variant. Ids
   are per variant.
3. **Exporting to look at it.** That is `render_variant`'s job; the export is
   counted in the user's usage report.
4. **Ignoring `warnings[]`** in the JSON block that precedes the image.
5. **Sending `""` for inputs you are not filling.** That blanks them. Omit the
   key instead and the designer's `sampleValue` renders.
6. **Trying to shorten a text to a character count the tool "implied".** It never
   implies one; overflow is pixels of wrapped text.
7. **Inventing a font or colour.** Use `get_context` fonts / colours and
   `richTextOptions.fonts` verbatim.
8. **Writing to a `locked` input**, or sending a transform to a slot that forbids
   it, and retrying with a variation. Both are designer decisions.
9. **Assuming an empty gallery root.** The root holds pictures; look there before
   telling the user there are none.
10. **Promising to create or edit a template.** Not available through this
    connector — direct the user to the wboost editor.

## Reference

Field-by-field response shapes, scopes, and error wordings:
`references/tools.md` next to this file.
