<?php

declare(strict_types=1);

namespace WBoost\Web\Api\SocialNetworkTemplates;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use ApiPlatform\OpenApi\Model\RequestBody;
use ApiPlatform\OpenApi\Model\Response as OpenApiResponse;
use ArrayObject;

#[ApiResource(
    shortName: 'SocialNetworkTemplateVariant',
    operations: [
        new Post(
            uriTemplate: '/social-network-template-variants/{id}/export',
            input: ExportRequest::class,
            output: false,
            read: false,
            processor: ExportProcessor::class,
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
            name: 'api_social_network_template_variant_export',
            openapi: new OpenApiOperation(
                summary: 'Render a social network template variant to PNG',
                description: <<<MD
Renders the variant's canvas to a PNG with the supplied input values applied.

The shape of `inputs` is **dynamic per variant** — keys are the input UUIDs
defined on the variant (discover them via
`GET /api/projects/{projectId}/social-network-templates`, then look at
`variants[].inputs[].id`). Each variant's inputs may legitimately share a
`name`, so the binding is by stable UUID — never by name.

Per-input value can be either:

- a plain **string** — sets the textbox text
- an object `{ "value": "...", "hide": true|false }` — `value` sets the text,
  `hide` toggles visibility (only honored when the input definition has
  `hidable: true`; silently ignored otherwise)

Server-side behavior:

- `maxLength` from the input definition is enforced (400 if exceeded)
- `uppercase` from the input definition is applied automatically
- Locked inputs cannot be addressed
- Unknown input UUIDs are silently ignored

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
  `GET /api/social-network-template-variants/{variantId}/placeholders/{inputId}/images`;
  upload a new one via `POST` to the same path (multipart `file`).
- An adjustment the slot does not permit (move / resize / rotate) → 400.
- An `imageId` outside the slot's allowed folders, or not in this project → 400.
- Unfilled slots keep the designer's stand-in image.
MD,
                requestBody: new RequestBody(
                    description: 'Map of inputId UUID → value (string or `{ value, hide }` object).',
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
                    '400' => new OpenApiResponse(description: 'Invalid request — malformed JSON, wrong types, or value exceeds maxLength.'),
                    '401' => new OpenApiResponse(description: 'Missing or invalid bearer token.'),
                    '403' => new OpenApiResponse(description: 'Variant is not accessible to the authenticated user.'),
                    '404' => new OpenApiResponse(description: 'Variant not found.'),
                ],
            ),
        ),
    ],
)]
final readonly class SocialNetworkTemplateVariantResource
{
}
