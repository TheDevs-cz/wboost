<?php

declare(strict_types=1);

namespace WBoost\Web\Api\CustomTemplates;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use ApiPlatform\OpenApi\Model\RequestBody;
use ApiPlatform\OpenApi\Model\Response as OpenApiResponse;
use ArrayObject;

#[ApiResource(
    shortName: 'CustomTemplateVariant',
    operations: [
        new Post(
            uriTemplate: '/custom-template-variants/{id}/export',
            input: ExportRequest::class,
            output: false,
            read: false,
            processor: ExportProcessor::class,
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
            name: 'api_custom_template_variant_export',
            openapi: new OpenApiOperation(
                summary: 'Render a custom template variant to PNG',
                description: <<<MD
Renders the variant's canvas to a PNG with the supplied input values applied.
The PNG size is the variant's free-form dimension rasterized to pixels
(physical units at 300 DPI) — see `variants[].width`/`height` in
`GET /api/projects/{projectId}/custom-templates`.

The shape of `inputs` is **dynamic per variant** — keys are the input UUIDs
defined on the variant (discover them via
`GET /api/projects/{projectId}/custom-templates`, then look at
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
  `GET /api/custom-template-variants/{variantId}/placeholders/{inputId}/images`;
  upload a new one via `POST` to the same path (multipart `file`).
- An adjustment the slot does not permit (move / resize / rotate) → 400.
- An `imageId` outside the slot's allowed folders, or not in this project → 400.
- Unfilled slots keep the designer's stand-in image.

## Containers (smart text areas)

Inputs listed in a variant's `containers[]` reflow vertically at render time:
a filled text that wraps to more lines pushes the members below it down,
hidden members collapse, and the flow is bounded by the container's
`maxHeight` (from its `y` downward, canvas px). When the filled content cannot
fit even after reflow, the export is rejected with **400** and body
`{ "error": "...", "code": "container_overflow", "containerId": "<uuid>",
"overflowPx": 12.5 }` — shorten the texts of that container's inputs. Member
inputs carry `containerId` + `textStyle` in the listing so a consumer can
mirror the reflow client-side (see docs/api/consumer-prompt.md).
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
                                                        'hide' => ['type' => 'boolean'],
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
                                                        'rotation' => ['type' => 'number'],
                                                        'hide' => ['type' => 'boolean'],
                                                    ],
                                                    'additionalProperties' => false,
                                                ],
                                            ],
                                        ],
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
                        description: 'Invalid request — malformed JSON, wrong types, value exceeds maxLength, an invalid rich-text value (`code`: `rich_text_not_allowed` / `invalid_rich_text` / `font_not_allowed` — body carries `allowedFonts` — / `invalid_color`), or container content overflows its max height (body carries `code: "container_overflow"`, the `containerId` and the `overflowPx`).',
                        content: new ArrayObject([
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'error' => ['type' => 'string'],
                                        'code' => ['type' => 'string', 'enum' => ['container_overflow', 'rich_text_not_allowed', 'invalid_rich_text', 'font_not_allowed', 'invalid_color']],
                                        'containerId' => ['type' => 'string', 'nullable' => true],
                                        'overflowPx' => ['type' => 'number'],
                                        'allowedFonts' => ['type' => 'array', 'items' => ['type' => 'string']],
                                    ],
                                ],
                            ],
                        ]),
                    ),
                    '401' => new OpenApiResponse(description: 'Missing or invalid bearer token.'),
                    '403' => new OpenApiResponse(description: 'Variant is not accessible to the authenticated user.'),
                    '404' => new OpenApiResponse(description: 'Variant not found.'),
                ],
            ),
        ),
    ],
)]
final readonly class CustomTemplateVariantResource
{
}
