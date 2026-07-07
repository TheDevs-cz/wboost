# What's new: `layerIndex` — placeholder stacking order (API delta)

> For integrators who already consume the templates listing. This is a **purely
> additive, backward-compatible** change: one new nullable field on entries you
> already receive. Nothing about the export request/response changed.

Both templates listings —
`GET /api/projects/{projectId}/social-network-templates` and
`GET /api/projects/{projectId}/custom-templates` — now expose the **stacking
order** of every placeholder, so a consumer can rebuild the design's layer
stack (e.g. for a Photoshop-style "Layers" panel next to the preview).

## The field

Each `variants[].inputs[]` (text) and `variants[].imageInputs[]` (image) entry
gains:

```jsonc
"layerIndex": 2   // nullable int — stacking position on the variant canvas
```

Semantics:

- The value is the object's index in the canvas paint order: **`0` = backmost,
  higher = painted on top**.
- Text and image entries share **one index space** — merge `inputs[]` and
  `imageInputs[]` and sort the combined list by `layerIndex` to get the true
  stack (sort **descending** for a topmost-first layers panel).
- Values may have **gaps**: decorative design objects (not fillable, so not
  listed) occupy positions too. Only the relative order is meaningful — never
  treat the value as an array index.
- `null` when the placeholder's object cannot be located on the canvas (same
  condition under which `frame` is `null`); put such entries at the end of the
  list.
- The stack is **fixed by the designer** — the export accepts no z-order
  overrides. The field is informational, for display and navigation
  (hover row → highlight the `frame` box, click row → open the same editor the
  box click opens).

## Suggested UI

See "Layers list (navigation aid)" in [`consumer-prompt.md`](consumer-prompt.md)
for the recommended hover/click behavior and the frame→screen scale math it
reuses.
