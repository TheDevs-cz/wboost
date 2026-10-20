<?php

declare(strict_types=1);

namespace WBoost\Web\Api\Templates;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use ApiPlatform\OpenApi\Model\RequestBody;
use ApiPlatform\OpenApi\Model\Response as OpenApiResponse;
use ArrayObject;

#[ApiResource(
    shortName: 'TemplateVariant',
    operations: [
        new Post(
            uriTemplate: '/template-variants/{id}/export',
            input: ExportRequest::class,
            output: false,
            read: false,
            processor: ExportProcessor::class,
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
            name: 'api_template_variant_export',
            openapi: new OpenApiOperation(
                summary: 'Render a template variant to PNG',
                description: <<<MD
Renders the variant's canvas to a PNG with the supplied input values applied.
The PNG size is the variant's dimension rasterized to pixels (physical units
at 300 DPI; social presets are fixed px sizes) — see `variants[].width`/`height`
in `GET /api/projects/{projectId}/templates`.

Every successful call is an EXPORT: it is counted in usage and snapshotted
as a re-loadable version in the variant's export history
(`GET /api/template-variants/{id}/export-versions`). Live previews must use
`POST /api/template-variants/{id}/preview` instead (same body, WebP, not
recorded).

The shape of `inputs` is **dynamic per variant** — keys are the input UUIDs
defined on the variant (discover them via
`GET /api/projects/{projectId}/templates`, then look at
`variants[].inputs[].id`). Each variant's inputs may legitimately share a
`name`, so the binding is by stable UUID — never by name.

Per-input value can be either:

- a plain **string** — sets the textbox text
- an object `{ "value": "...", "hide": true|false }` — `value` sets the text,
  `hide` toggles visibility (only honored when the input definition has
  `hidable: true`; silently ignored otherwise)
- an object `{ "runs": [...], "hide": true|false }` — **rich text**, accepted
  only for inputs with `richText: true` (see below)

Server-side behavior:

- `maxLength` from the input definition is enforced (400 if exceeded; for rich
  values the concatenation of all run texts counts)
- `uppercase` from the input definition is applied automatically
- Locked inputs cannot be addressed
- Unknown input UUIDs are silently ignored

## Rich text (WYSIWYG) inputs

An input with `richText: true` accepts a formatted value as ordered **runs**:
`{ "runs": [ { "text": "...", "fontFamily": null|string, "color": null|"#rrggbb",
"underline": bool } ] }`. Null/omitted style = inherit the designed style. Run
text may contain newlines (`\n` renders as a hard line break; CRLF/CR are
canonicalized to `\n`); `runs` and `value` are mutually exclusive.

- `fontFamily` must be one of the variant's `richTextOptions.fonts[].family`
  (bold/italic are separate font FACES — switch the family, don't send weights);
  otherwise **400 `font_not_allowed`** (body carries `allowedFonts`).
- `color` accepts any well-formed hex (`#rrggbb` / `#rgb`, no alpha) —
  `richTextOptions.colors` are brand swatch suggestions, NOT a whitelist;
  malformed → **400 `invalid_color`**.
- Structurally invalid runs → **400 `invalid_rich_text`**; runs on a non-rich
  input → **400 `rich_text_not_allowed`**.

## Lists inside rich text (`inputs[].lists: true`)

A lists-enabled rich input additionally accepts per-LINE list types:
`{ "runs": [...], "lines": ["p","ul","ol",...] }` — one entry per
`\n`-separated line of the concatenated runs (`p` paragraph, `ul` bullet item,
`ol` numbered item). The export lays the value out as a block stack: items
wrap at `frame.width − listStyle.indent` with a hanging indent and a bullet
(`listStyle.bullet` / `bulletImageUrl`; `ol` renders ordinals) at the line
start; `listStyle` values are RESOLVED px. List lines on a `lists: false`
input → **400 `lists_not_allowed`**; malformed `lines` → **400
`invalid_rich_text`**.

### Checkbox lists (`inputs[].listCheckboxes: true`)

An input that also allows checkbox lists accepts two more line types: `cb`
(unchecked item) and `cbx` (checked item). Consecutive `cb`/`cbx` lines form
ONE checklist — mix the states freely. Layout is identical to `ul` items; the
line-start marker is `listStyle.checkboxImageUrl` (unchecked) /
`checkboxCheckedImageUrl` (checked), and when a state's URL is null the
export draws the default checkbox: a rounded square filled with the item's
text color, the checked one with a white check mark. `cb`/`cbx` lines on an
input with `listCheckboxes: false` → **400 `checkbox_lists_not_allowed`**.

### Checklist components (`inputs[].checklist` non-null)

An input whose `checklist` object is non-null was added by the designer as a
DEDICATED checkbox list: instead of a free-form WYSIWYG, render a fixed
per-item editor (one row per line, each with a checkbox) and honor the
capability flags — `toggle` (check/uncheck), `editText`, `addItems`,
`removeItems`. The wire format is the ordinary checkbox-list envelope; the
flags are a UI contract, except that with ALL FOUR false the input is
read-only server-side (provided overrides are ignored, the sample renders).

## Image placeholders (`images`)

`images` fills IMAGE placeholders, keyed by `variants[].imageInputs[].id`. Each
value is either the **gallery image id** (string — placed centered + object-contain
in the designer's frame) or an object
`{ "imageId": "...", "scale": 1, "offsetX": 0, "offsetY": 0, "rotation": 0 }`
(`scale` multiplies the contain-fit; `offsetX`/`offsetY` pan in canvas pixels from
the frame centre; `rotation` is degrees), or `{ "hide": true }` to blank a
`hidable` slot.

- Discover the ids, frames and allowed folders in `variants[].imageInputs[]`.
- List a slot's pickable images via
  `GET /api/template-variants/{variantId}/placeholders/{inputId}/images`;
  upload a new one via `POST` to the same path (multipart `file`).
- An adjustment the slot does not permit (move / resize / rotate) → 400.
- An `imageId` outside the slot's allowed folders, or not in this project → 400.
- Unfilled slots keep the designer's stand-in image.

### Background slots (`imageInputs[].isBackground: true`)

A slot flagged `isBackground` is the variant's background layer. Its fill is
deterministic: the picture is **cover-fitted over the whole canvas** (least
scale that covers it) and **anchored top-left** — overflow crops away
bottom-right. No transform is accepted (the limit flags are always false; send
the plain `"<imageId>"` string or `{ "imageId": "..." }`, plus `{ "hide": true }`
when `hidable`). `frame` is the full canvas rect. Unfilled → the designed
background stays; a variant may also have no background at all, in which case
the export PNG is transparent there (`backgroundImageUrl` is null for such
variants).

### Portable pan (`offsetXRatio` / `offsetYRatio`)

The pan may instead be sent as a FRACTION of the frame:
`{ "imageId": "...", "scale": 1.4, "offsetXRatio": -0.12, "offsetYRatio": 0.05 }`
— `offsetX = offsetXRatio × frame.width`, `offsetY = offsetYRatio × frame.height`.
`scale` and `rotation` are already frame-independent, so they need no second form.

Use it when ONE placement is reused across variants: the same placeholder has a
different `frame` in every dimension, so pixel offsets carry a crop from a
1080×1080 into a 1080×1920 wrongly, while the ratio keeps the intent. Send one
form or the other per axis — both for the same axis is a 400. Editors that let a
user drag a picture should store the ratio (`Δpx ÷ frame edge`) and convert to
pixels only for drawing.

## Containers (smart text areas)

Inputs listed in a variant's `containers[]` reflow vertically at render time:
a filled text that wraps to more lines pushes the flow items below it down,
hidden items collapse. Containers can NEST (`memberContainerIds` + `nested`):
a child container flows inside its parent as one item and grows freely — only
the OUTERMOST container's `maxHeight` bounds the flow (from its `y` downward,
canvas px). A non-null `gap` replaces the designed spacing between consecutive
flow items with a uniform one. TOP-LEVEL containers also COLLISION-PUSH each
other: one whose content would run into a lower, horizontally-overlapping
container pushes it down by the excess (chained; whitespace absorbs growth
first, no pull-up), keeping its `spaceAfter` clearance — which also acts as
the container's margin against the canvas bottom. When the filled content
cannot fit the outermost bound (or ends below `height − spaceAfter`) even
after reflow, the export is rejected with **400** and body `{ "error": "...",
"code": "container_overflow", "containerId": "<uuid>", "overflowPx": 12.5 }`
(`containerId` = the outermost container) — shorten the texts of that
container tree's inputs. Member inputs carry `containerId` + `textStyle` in
the listing so a consumer can mirror the reflow client-side; containers may
also hold decorative images that ride the flow server-side without being
listed as inputs, so treat the rendered preview as authoritative (see
docs/api/consumer-prompt.md).
MD,
                requestBody: new RequestBody(
                    description: 'Map of inputId UUID → value (string, `{ value, hide }`, or `{ runs, hide }` for richText inputs).',
                    content: new ArrayObject([
                        'application/json' => [
                            'schema' => [
                                'type' => 'object',
                                'properties' => [
                                    'inputs' => [
                                        'type' => 'object',
                                        'description' => "Keyed by the variant's input.id (UUID v4).",
                                        'additionalProperties' => [
                                            'oneOf' => [
                                                ['type' => 'string'],
                                                [
                                                    'type' => 'object',
                                                    'properties' => [
                                                        'value' => ['type' => 'string'],
                                                        'hide' => ['type' => 'boolean'],
                                                        'fontFamily' => ['type' => 'string', 'nullable' => true],
                                                    ],
                                                    'additionalProperties' => false,
                                                ],
                                                [
                                                    'type' => 'object',
                                                    'description' => 'Rich text — only for inputs with richText: true.',
                                                    'properties' => [
                                                        'runs' => [
                                                            'type' => 'array',
                                                            'items' => [
                                                                'type' => 'object',
                                                                'properties' => [
                                                                    'text' => ['type' => 'string'],
                                                                    'fontFamily' => ['type' => 'string', 'nullable' => true],
                                                                    'color' => ['type' => 'string', 'nullable' => true],
                                                                    'underline' => ['type' => 'boolean'],
                                                                ],
                                                                'required' => ['text'],
                                                                'additionalProperties' => false,
                                                            ],
                                                        ],
                                                        'lines' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['p', 'ul', 'ol', 'cb', 'cbx']]],
                                                        'hide' => ['type' => 'boolean'],
                                                        'fontFamily' => ['type' => 'string', 'nullable' => true],
                                                    ],
                                                    'required' => ['runs'],
                                                    'additionalProperties' => false,
                                                ],
                                            ],
                                        ],
                                    ],
                                    'images' => [
                                        'type' => 'object',
                                        'description' => "Keyed by the variant's imageInputs[].id (UUID v4).",
                                        'additionalProperties' => [
                                            'oneOf' => [
                                                ['type' => 'string'],
                                                [
                                                    'type' => 'object',
                                                    'properties' => [
                                                        'imageId' => ['type' => 'string'],
                                                        'scale' => ['type' => 'number'],
                                                        'offsetX' => ['type' => 'number'],
                                                        'offsetY' => ['type' => 'number'],
                                                        'offsetXRatio' => ['type' => 'number'],
                                                        'offsetYRatio' => ['type' => 'number'],
                                                        'rotation' => ['type' => 'number'],
                                                        'hide' => ['type' => 'boolean'],
                                                    ],
                                                    'additionalProperties' => false,
                                                ],
                                            ],
                                        ],
                                    ],
                                    'shapeColors' => [
                                        'type' => 'object',
                                        'description' => "Keyed by the variant's shapeInputs[].id: the \"#rrggbb\" to repaint that shape in (its fill, border or both — see the shape's `target`). Must be one of `colorOptions` unless the shape has `freeColor`. Empty string = designed colour.",
                                        'additionalProperties' => ['type' => 'string', 'pattern' => '^#?([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$|^$'],
                                    ],
                                ],
                            ],
                            'examples' => [
                                'shorthand' => [
                                    'summary' => 'Shorthand — string values',
                                    'value' => [
                                        'inputs' => [
                                            '01927a3b-7b02-7120-9fbd-4f1ea982bde9' => 'Discount weekend',
                                            '01927a3b-7b02-7120-9fbd-4f1ea982bdea' => 'Save up to 50%',
                                        ],
                                    ],
                                ],
                                'extended' => [
                                    'summary' => 'Extended — value + hide per input',
                                    'value' => [
                                        'inputs' => [
                                            '01927a3b-7b02-7120-9fbd-4f1ea982bde9' => ['value' => 'Discount weekend'],
                                            '01927a3b-7b02-7120-9fbd-4f1ea982bdea' => ['value' => 'Save up to 50%'],
                                            '01927a3b-7b02-7120-9fbd-4f1ea982bdeb' => ['hide' => true],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ]),
                ),
                responses: [
                    '200' => new OpenApiResponse(
                        description: 'Rendered PNG image.',
                        content: new ArrayObject([
                            'image/png' => [
                                'schema' => ['type' => 'string', 'format' => 'binary'],
                            ],
                        ]),
                    ),
                    '400' => new OpenApiResponse(
                        description: 'Invalid request. Every 400 carries a structured body `{error, code, ...context}`; see docs/api/consumer-prompt.md for the code table. Value errors name the offending `inputId` / `imageInputId`.',
                        content: new ArrayObject([
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'error' => ['type' => 'string'],
                                        'code' => ['type' => 'string', 'enum' => ['container_overflow', 'value_too_long', 'invalid_value', 'rich_text_not_allowed', 'invalid_rich_text', 'font_not_allowed', 'color_not_allowed', 'invalid_color', 'lists_not_allowed', 'checkbox_lists_not_allowed', 'invalid_image_value', 'image_transform_not_allowed', 'image_not_allowed', 'image_unreadable']],
                                        'inputId' => ['type' => 'string'],
                                        'imageInputId' => ['type' => 'string'],
                                        'containerId' => ['type' => 'string', 'nullable' => true],
                                        'overflowPx' => ['type' => 'number'],
                                        'maxLength' => ['type' => 'integer'],
                                        'transform' => ['type' => 'string'],
                                        'allowedFonts' => ['type' => 'array', 'items' => ['type' => 'string']],
                                        'allowedColors' => ['type' => 'array', 'items' => ['type' => 'string']],
                                    ],
                                ],
                            ],
                        ]),
                    ),
                    '503' => new OpenApiResponse(description: 'The renderer is overloaded (`code: "render_unavailable"`); retry shortly.'),
                    '401' => new OpenApiResponse(description: 'Missing or invalid bearer token.'),
                    '403' => new OpenApiResponse(description: 'Variant is not accessible to the authenticated user.'),
                    '404' => new OpenApiResponse(description: 'Variant not found.'),
                ],
            ),
        ),
        new Post(
            uriTemplate: '/template-variants/{id}/preview',
            input: ExportRequest::class,
            output: false,
            read: false,
            processor: PreviewProcessor::class,
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
            name: 'api_template_variant_preview',
            openapi: new OpenApiOperation(
                summary: 'Render a live preview of a template variant (WebP, not recorded)',
                description: <<<MD
The on-screen sibling of `POST /api/template-variants/{id}/export`: the SAME
request body and the SAME strict validation (identical structured 400s), but
the response is a **WebP** image and the call is **not an export** — no usage
event, no version in the variant's export history. Use it for debounced live
previews; send only the user's real download to `/export`.
MD,
                responses: [
                    '200' => new OpenApiResponse(
                        description: 'Rendered WebP preview.',
                        content: new ArrayObject([
                            'image/webp' => [
                                'schema' => ['type' => 'string', 'format' => 'binary'],
                            ],
                        ]),
                    ),
                    '400' => new OpenApiResponse(description: 'Same structured 400s as the export.'),
                    '401' => new OpenApiResponse(description: 'Missing or invalid bearer token.'),
                    '403' => new OpenApiResponse(description: 'Variant is not accessible to the authenticated user.'),
                    '404' => new OpenApiResponse(description: 'Variant not found.'),
                ],
            ),
        ),
    ],
)]
final readonly class TemplateVariantResource
{
}
