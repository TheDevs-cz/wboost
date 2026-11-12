# Filling a template — value shapes, rows, moves, refusals

The fill vocabulary is identical for `render_variant`, `export_variant`, each
page of `export_document`, and the REST export. Contents:

- [Text](#text-inputs) · [Pictures](#pictures-images) · [Shape colours](#shape-colours-shapecolors)
- [Repeatable rows](#rows--keyed-by-repeatablerowscontainerid) · [Moving elements](#offsets--keyed-by-movableelementsid) · [Row and move refusals](#row-and-move-refusals-same-codes-as-the-rest-api)
- [A worked example](#a-worked-example)

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
"c3d4…": "<gallery image id from list_gallery or upload_image>"
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

Shape colours (`shapeColors`, keyed by `shapeInputs[].id`):

```jsonc
"e5f6…": "#c8102e"                              // repaints fill / border / both per the shape's target
```

- Without `freeColor` the colour must be one of that shape's `colorOptions`
  (`colorOptions[0]` is the designed colour); otherwise the call is refused
  naming the allowed colours.
- Slots you leave out keep the designer's stand-in picture.

`offsetX` / `offsetY` are canvas px; `offsetXRatio` / `offsetYRatio` are accepted
as an alternative, expressed as a fraction of the slot frame. A transform without
an `imageId` is refused. Omitted slots keep the designer's stand-in picture.

### `rows` — keyed by `repeatableRows[].containerId`

```jsonc
"rows": {
  "<containerId>": [                                  // one object per row — the
    { "<members[].id>": "TJ Kelč", "<crest id>": "<imageId>" },  // list length IS the count
    {},                                               // a row with the template samples
    { "<members[].id>": {"value": "FK Nový Jičín"}, "<row containerId>": {"hide": true} }
  ]
}
```

Values take the shapes of `inputs` (text members, `{"hide": true}` for a
`kind: "container"` member), `images` (image slots) and `shapeColors` (shapes).
The count must stay within `repeat.min`…`repeat.max`. A row left out of `rows`
shows its `default` count. A member of a row you send in `rows` must NOT also
be addressed through `inputs` / `images` / `shapeColors` (not even by a
`slots[].ids` id) — that is `ambiguous_row_value`. A value refused inside a
row names `rowContainerId` and `rowIndex` (0-based) next to the member id you
sent. Rows that do not fit are a container overflow, as for long texts.

### `offsets` — keyed by `movableElements[].id`

`{"<id>": {"x": 0, "y": -24}}` — canvas px from the designed position, +x right,
+y down; a missing axis is 0. Stay inside the element's `range`. A zero move is
no move (and is not recorded). One map per variant; on a document, one
per page.

### Row and move refusals (same codes as the REST API)

The message is the REST sentence, then the code in parentheses, then the REST
context as `key: value` pairs.

| code | meaning |
|---|---|
| `not_repeatable` | a `rows` key that is no `repeatableRows[].containerId` |
| `too_many_rows` / `too_few_rows` | the list is longer than `max` / shorter than `min` (`count`, `min`, `max`) |
| `unknown_row_input` | a row object key that is no `members[].id` (`rowIndex`, `inputId`) |
| `ambiguous_row_value` | that row member is also in `inputs` / `images` / `shapeColors` |
| `unknown_element` | an `offsets` key that is no element of the variant |
| `move_not_allowed` | `reason`: `not_allowed` (axis given: that axis only), `in_container` (move the container), `hidden` |
| `offset_out_of_range` | past the `range` (`axis`, `min`, `max`, `value`) |
| `invalid_value` | a malformed `rows` / `offsets` value |


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
picture, `list_gallery` on the project sits between 3 and 4 — and if the picture
they want is not in the gallery yet, `upload_image` puts it there and hands back
the id to use.

If instead there had been **no** suitable template, authoring is the other path:
`describe_variant` for the target variant's canvas size, compose a document,
`preview_design` until it looks right, then `set_design` once — reading rule 7
before that last call.
