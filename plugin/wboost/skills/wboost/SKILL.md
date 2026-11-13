---
name: wboost
description: Works with a wboost account through the wboost MCP tools. Brand templates — find a template variant, fill its texts, pictures, shape colours and repeatable rows, preview it, export the PNG or a print PDF (bleed, crop marks), fill and export multi-page documents, author a design with the design DSL, upload pictures and fonts. Desk calendars (Kalendáře) — create one, set its layout and styles, upload photos and logos, fill events, waste collection days, name days, captions, cover / intro / contacts / back pages, render pages, export the print PDF, hand out the client link. Use whenever the user mentions wboost, a brand template or šablona, a social post, poster or leaflet from their brand, a multi-page document, or a desk / municipal calendar (stolní kalendář).
license: MIT
metadata:
  version: "0.5.3"
---

# wboost

wboost is a brand-manual platform. A **project** holds brand fonts and colours,
a picture gallery, **templates** (designed canvases whose text, picture and
colour slots are filled in and exported) and **desk calendars**. This skill
(version 0.5.3) teaches the wboost MCP tools; their own descriptions carry the
field-level detail.

## First call of a session: the version check

Pass this skill's version on your first wboost call:

- templates: `get_context` with `{"skillVersion": "0.5.3"}`
- a calendar-only connection (no `get_context`): `list_calendars` with `{"skillVersion": "0.5.3"}`

The answer carries `skill.status`. `current`: say nothing. `outdated`: do the
user's task first, then tell them ONCE per session what is new
(`skill.whatsNew`) and how to update (`skill.message` — the Claude Code
commands or the claude.ai zip). Never stop, refuse or delay work because the
skill is old. A tool you see that this skill never mentions means the server
is newer than the skill: use it from its own description and suggest the
update.

## What you can do, and where to read more

| job | tools | scope | read |
|---|---|---|---|
| look around | `get_context`, `find_templates`, `describe_variant`, `list_gallery` | `templates:read` | [references/tools.md](references/tools.md) |
| fill + deliver | `render_variant` (preview), `export_variant` (PNG / print PDF) | `templates:read` / `templates:export` | [references/filling.md](references/filling.md) |
| multi-page documents | `export_document`, `document_history`, `manage_document` | `templates:export` / `templates:design` | [references/documents-and-print.md](references/documents-and-print.md) |
| author a design | `create_template` (a new empty template), `preview_design`, `set_design`, `upload_font`, `set_print_setup` | `templates:design` | [references/design.md](references/design.md) — read it BEFORE the first design call |
| picture of a design → template, picture of a calendar page → calendar layout (AI scan) | `scan_design` | `ai:scan` | [references/tools.md](references/tools.md) |
| add pictures | `upload_image` | `gallery:write` | [references/tools.md](references/tools.md) |
| desk calendars | `list_calendars`, `get_calendar`, `render_calendar_page`, `manage_calendar`, `set_calendar_layout`, `upload_calendar_asset`, `update_calendar_content` | `calendars:read` / `calendars:write` | [references/calendars.md](references/calendars.md) — read it BEFORE calendar work |

A tool the connection lacks the scope for is not offered at all; `get_context`
echoes the granted `scopes`. A scope only narrows what the account itself may
do: designing needs the right to edit the project (owner or admin — a project
merely shared with the account is view-only). Every tool and parameter is
listed in [references/tools.json](references/tools.json).

## Templates — the two loops

**Filling** an existing design, by far the common case:

```
get_context              → projects, exact font faces, brand colours, scopes, skill check
  └ find_templates       → a project's templates (+ documents[]) and their variants
      └ describe_variant → the input ids, their rules, containers, rows, movable elements
          ├ list_gallery / upload_image → picture ids for image slots
          ├ render_variant              → cheap WebP preview; iterate here
          └ export_variant              → the deliverable, once
```

**Authoring** a design (on an existing variant, or on a new one from
`create_template` — create only when the user asked for a new template):

```
get_context              → the font faces and colours you may use
  └ find_templates / describe_variant, or create_template → the variant and its canvas size in px
      ├ list_gallery / upload_image   → gallery ids the design references
      ├ upload_font                   → only when a needed cut is missing
      ├ preview_design                → compile + draw WITHOUT saving; iterate here
      └ set_design                    → the commit, once
```

## The rules that make runs succeed

1. **`get_context` first** (templates). Its `fonts[]` strings — e.g.
   `"Rubik (Rubik Bold)"` — are the only faces a design or styled run may name,
   byte for byte; an unknown face is an error, never substituted. Prefer the
   brand `colors[]` over invented hex values. Call it again only when the user
   says they added a project, font or colour.
2. **Ids, never names.** Every tool takes UUIDs. `inputs` / `images` /
   `shapeColors` / `rows` / `offsets` are keyed by the ids `describe_variant`
   reports. An id that matches nothing is ignored and reported in
   `warnings[]` — **read `warnings[]` after every render and export**.
3. **`render_variant` to iterate, `export_variant` once.** The render is a
   ≤ 1200 px WebP, not counted, and only WARNS about container overflow; the
   export is the full-size file, counted in the user's usage report, and
   REFUSES an overflow. `export_variant` `format: "pdf"` is the print PDF
   (PDF/X-1a, CMYK, bleed + crop marks) of an mm / cm variant.
4. **Container overflow is the failure you will hit.** Texts in one container
   share a vertical flow capped by `maxHeight`. The server cannot say which text
   is too long and gives no character target — shorten the most compressible
   text and render again, hide a `hidable` input or container
   (`{"hide": true}`), or tell the user the designer must raise `maxHeight`.
5. **Respect what the designer locked.** `locked` inputs cannot be written;
   `maxLength` refuses (never truncates) — count characters first; `uppercase`
   is applied for you; omit an input to keep its `sampleValue`, send `""` to
   blank it. Slots refuse transforms they do not allow; pictures must come from
   the slot's `directories[]`. Details: [references/filling.md](references/filling.md).
6. **Author at the variant's own size, in pixels.** `canvas.width` /
   `canvas.height` = `describe_variant` `dimension.width` / `height` (an A4 is
   2480 × 3508, never 210 × 297). Every visible text becomes a fillable input —
   give fixed copy `"input": {"locked": true}`. Give every design exactly one
   `background` element. `preview_design` until `rendered: true` with no
   errors and the picture looks right, then `set_design` once.
7. **`acknowledgeLosses` is the user's decision, never a retry.** When
   `set_design` refuses with `stage: "overwrite"`, saving would destroy what the
   issues list (often a background with no gallery id). Read the list to the
   user, prefer the repair (e.g. `upload_image` the background and reference
   it), and pass `acknowledgeLosses: true` only after they agreed.
8. **Grouped variants** (`grouped: true`) fill, render, export and preview
   normally, but `set_design` is refused — the design is shared by the group
   and edited in the wboost group editor.
9. **Print needs a bleed decision.** For mm / cm output ask the user which bleed
   ("spadávka", 0–10 mm) they want if they have not said — suggest 3 mm with
   crop marks — then `set_print_setup` (one variant) or `manage_document`
   `set_print` (a document). px formats have no bleed.
10. **Unfilled pictures.** A template made by the AI scan can still show grey
    stand-in pictures; `export_variant` / `export_document` then refuse with
    `unfilled_pictures:` (count + `editorUrl`). Pass the missing picture in
    `images`, send the user to the editor, or — only when they want the grey
    stand-ins — call again with `allowUnfilled: true`.
11. **Ask before you create or overwrite.** Nothing in this connector deletes
    anything, so every export, upload, new document page and new calendar is
    permanent; `set_design` replaces a whole canvas.

## Multi-page documents

`find_templates` also returns `documents[]`: templates bound into one ordered
document exported as ONE file. Each page has a `variantId` (describe, render
and design it like any variant). `export_document` fills shared `fields` once
and each page through `pages` keyed by `pageId`, as a screen PDF (default),
print PDF (mm / cm, ≤ 8 pages) or PNG ZIP; `document_history` lists, reloads,
renames, pins and drafts its exports; `manage_document` creates and arranges
documents. Read [references/documents-and-print.md](references/documents-and-print.md) first.

## Desk calendars (Kalendáře)

A separate module with its own scopes and no `get_context`. One calendar = one
year of week (`week`) or two-week (`fortnight`) pages generated with dates,
Czech name days and holidays, plus a design (settings + ONE layout for all
week pages) and content (events, waste collection, names, photos, captions,
cover / intro / contacts / back texts).

```
list_calendars (skillVersion)     → calendars and their ids
manage_calendar "create"          → a new calendar; pass a preset to get a layout at once
  └ get_calendar                  → settings, layout + styles, page keys, ALL content, assets, fonts
      ├ manage_calendar "configure"  → name, year, type, feature switches, print
      ├ set_calendar_layout          → preset, then PATCH boxes (mm) / order / styles (pt)
      ├ upload_calendar_asset        → file → assetId to reference
      ├ update_calendar_content      → add / replace / delete WHOLE items
      ├ render_calendar_page         → LOOK at the page after every change
      └ manage_calendar "finish" · "client_link" · "export_pdf" → poll "export_status"
```

The four rules that matter most: no pages until a layout exists (a preset
creates it); content items are written WHOLE (copy from `get_calendar`, change,
send back); content of a switched-off feature is stored but not printed
(switch it on with `configure`); and render the page after every change and act
on its `warnings[]`. Everything else — collections, shapes, limits, roles,
reason codes, PDF variants — is in [references/calendars.md](references/calendars.md).

## Honest limits

- Nothing deletes: no template, variant, document, page, picture, font,
  calendar or calendar file can be removed here.
- No tool reads a design back as a DSL document, so `set_design` is for designs
  you compose, not small edits to someone else's — send the user to the wboost
  editor for those.
- `create_template` makes a new standalone template with one variant; more
  variants (other sizes) and template groups are made on the web, and a
  document page is `manage_document` `add_page`.
- The AI scan (`scan_design`) is a paid, per-project feature with a monthly
  limit: one scan per picture the user asked for, never in a loop; its
  refusals (`not_enabled`, `consent_required`, `limit_reached`, …) are for the
  user to resolve in the web app. With `kind: "calendar"` it proposes the
  layout of a calendar, 7-day or 14-day (scan a page with as many weeks as
  the calendar has): `apply` saves it at once (the previous layout
  comes back with `restore`), so say what will be replaced before you apply.
- A calendar-only connection learns project ids from `list_calendars` alone:
  if the account has no calendar yet, ask the user for the project (or use
  `get_context` if the connection also has `templates:read`).
- Uploads are base64 and must stay under about 3 MB per call (the request cap
  is 4 MiB; wboost's own limit is 10 MB).
- `render_calendar_page`, `render_variant` and the exports return pictures you
  can look at; a long PDF export of a calendar runs in the background — poll it.

## Common mistakes

1. Keying a fill by input **name** — the render comes back untouched with a warning.
2. Guessing ids from another variant instead of calling `describe_variant`.
3. Exporting to look at something — that is `render_variant`'s job.
4. Ignoring `warnings[]` (and calendar `notes[]`).
5. Sending `""` for inputs you are not filling — that blanks them.
6. Inventing a font face or colour.
7. Authoring in millimetres or at the wrong canvas size.
8. Drawing the background as a full-canvas shape, or leaving copy un-`locked`.
9. Passing `acknowledgeLosses: true` because the previous call failed.
10. Sending one changed field of a calendar item instead of the whole item.
11. Judging a calendar render before switching on the feature its content needs.
12. Handing a calendar's client link or PDF `downloadUrl` to anyone but the user — both are secrets.
