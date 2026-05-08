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

The shape of `inputs` is **dynamic per variant** — keys are the input names defined
on the variant (discover them via `GET /api/social-network-templates`, then look at
`variants[].inputs[].name`).

Per-input value can be either:

- a plain **string** — sets the textbox text
- an object `{ "value": "...", "hide": true|false }` — `value` sets the text,
  `hide` toggles visibility (only honored when the input definition has
  `hidable: true`; silently ignored otherwise)

Server-side behavior:

- `maxLength` from the input definition is enforced (400 if exceeded)
- `uppercase` from the input definition is applied automatically
- Locked inputs and inputs with `name: null` cannot be addressed
- Unknown input names are silently ignored
MD,
                requestBody: new RequestBody(
                    description: 'Map of input name → value (string or `{ value, hide }` object).',
                    content: new ArrayObject([
                        'application/json' => [
                            'schema' => [
                                'type' => 'object',
                                'properties' => [
                                    'inputs' => [
                                        'type' => 'object',
                                        'description' => 'Keyed by the variant\'s input.name.',
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
                                ],
                            ],
                            'examples' => [
                                'shorthand' => [
                                    'summary' => 'Shorthand — string values',
                                    'value' => [
                                        'inputs' => [
                                            'headline' => 'Discount weekend',
                                            'tagline' => 'Save up to 50%',
                                        ],
                                    ],
                                ],
                                'extended' => [
                                    'summary' => 'Extended — value + hide per input',
                                    'value' => [
                                        'inputs' => [
                                            'headline' => ['value' => 'Discount weekend'],
                                            'tagline' => ['value' => 'Save up to 50%'],
                                            'badge' => ['hide' => true],
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
