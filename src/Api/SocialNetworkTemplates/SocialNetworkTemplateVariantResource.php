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
defined on the variant (discover them via `GET /api/social-network-templates`,
then look at `variants[].inputs[].id`). Each variant's inputs may legitimately
share a `name`, so the binding is by stable UUID — never by name.

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
