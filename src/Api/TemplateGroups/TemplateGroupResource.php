<?php

declare(strict_types=1);

namespace WBoost\Web\Api\TemplateGroups;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use ApiPlatform\OpenApi\Model\RequestBody;
use ApiPlatform\OpenApi\Model\Response as OpenApiResponse;
use ArrayObject;

/**
 * Template GROUPS over the API — one design in several dimensions, filled
 * ONCE (the web "Vyplnit a exportovat" page). Discover groups in
 * `GET /api/projects/{projectId}/templates` (`group` on the template,
 * `groupMember` on each variant); the group's export history lives at
 * `GET /api/template-groups/{groupId}/export-versions`.
 */
#[ApiResource(
    shortName: 'TemplateGroup',
    operations: [
        new Post(
            uriTemplate: '/template-groups/{groupId}/preview/{variantId}',
            uriVariables: [
                'groupId' => new Link(fromClass: TemplateGroupResource::class, identifiers: [], parameterName: 'groupId'),
                'variantId' => new Link(fromClass: TemplateGroupResource::class, identifiers: [], parameterName: 'variantId'),
            ],
            input: GroupExportRequest::class,
            output: false,
            read: false,
            processor: GroupPreviewProcessor::class,
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
            name: 'api_template_group_preview',
            openapi: new OpenApiOperation(
                summary: 'Live preview of one group dimension (WebP, not recorded)',
                description: TemplateGroupResource::BODY_DOC . "\n\nRenders ONE member dimension (`variantId` must be a group member) for the screen: WebP, strict validation like the export, no usage / history.",
                requestBody: new RequestBody(
                    description: 'The group fill: `inputs` + `images` (shared by every dimension) + per-dimension `placements`.',
                    content: new ArrayObject([
                        'application/json' => [
                            'schema' => [
                                'type' => 'object',
                                'properties' => [
                                    'inputs' => ['type' => 'object', 'description' => 'inputId → value, exactly as in POST /api/template-variants/{id}/export.'],
                                    'images' => ['type' => 'object', 'description' => 'imageInputId → gallery image id | { imageId, hide }.'],
                                    'placements' => [
                                        'type' => 'object',
                                        'description' => 'variantId → imageInputId → { scale, offsetXRatio, offsetYRatio, rotation }.',
                                        'additionalProperties' => ['type' => 'object', 'additionalProperties' => ['type' => 'object']],
                                    ],
                                ],
                            ],
                        ],
                    ]),
                ),
                responses: [
                    '200' => new OpenApiResponse(
                        description: 'Rendered WebP preview.',
                        content: new ArrayObject(['image/webp' => ['schema' => ['type' => 'string', 'format' => 'binary']]]),
                    ),
                    '400' => new OpenApiResponse(description: 'Structured `{error, code, ...}` body (the export codes) plus the `variantId` of the failing dimension.'),
                    '401' => new OpenApiResponse(description: 'Missing or invalid bearer token.'),
                    '403' => new OpenApiResponse(description: 'Group is not accessible to the authenticated user.'),
                    '404' => new OpenApiResponse(description: 'Group not found, or the variant is not a member of the group.'),
                    '503' => new OpenApiResponse(description: 'The renderer is overloaded (`code: "render_unavailable"`).'),
                ],
            ),
        ),
        new Post(
            uriTemplate: '/template-groups/{groupId}/export',
            uriVariables: [
                'groupId' => new Link(fromClass: TemplateGroupResource::class, identifiers: [], parameterName: 'groupId'),
            ],
            input: GroupExportRequest::class,
            output: false,
            read: false,
            processor: GroupExportProcessor::class,
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
            name: 'api_template_group_export',
            openapi: new OpenApiOperation(
                summary: 'Export every dimension of a group as one ZIP',
                description: TemplateGroupResource::BODY_DOC . "\n\nRenders every member dimension (PNG) and answers `application/zip` named `{group}.zip` with `{group}-{dimension}.png` entries. Recorded as ONE version in the group's export history.",
                requestBody: new RequestBody(
                    description: 'The group fill: `inputs` + `images` (shared by every dimension) + per-dimension `placements`.',
                    content: new ArrayObject([
                        'application/json' => [
                            'schema' => [
                                'type' => 'object',
                                'properties' => [
                                    'inputs' => ['type' => 'object', 'description' => 'inputId → value, exactly as in POST /api/template-variants/{id}/export.'],
                                    'images' => ['type' => 'object', 'description' => 'imageInputId → gallery image id | { imageId, hide }.'],
                                    'placements' => [
                                        'type' => 'object',
                                        'description' => 'variantId → imageInputId → { scale, offsetXRatio, offsetYRatio, rotation }.',
                                        'additionalProperties' => ['type' => 'object', 'additionalProperties' => ['type' => 'object']],
                                    ],
                                ],
                            ],
                        ],
                    ]),
                ),
                responses: [
                    '200' => new OpenApiResponse(
                        description: 'ZIP archive with one PNG per dimension.',
                        content: new ArrayObject(['application/zip' => ['schema' => ['type' => 'string', 'format' => 'binary']]]),
                    ),
                    '400' => new OpenApiResponse(description: 'Structured `{error, code, ...}` body (the export codes) plus the `variantId` of the failing dimension.'),
                    '401' => new OpenApiResponse(description: 'Missing or invalid bearer token.'),
                    '403' => new OpenApiResponse(description: 'Group is not accessible to the authenticated user.'),
                    '404' => new OpenApiResponse(description: 'Group not found, or the variant is not a member of the group.'),
                    '503' => new OpenApiResponse(description: 'The renderer is overloaded (`code: "render_unavailable"`).'),
                ],
            ),
        ),
        new Post(
            uriTemplate: '/template-groups/{groupId}/export/{variantId}',
            uriVariables: [
                'groupId' => new Link(fromClass: TemplateGroupResource::class, identifiers: [], parameterName: 'groupId'),
                'variantId' => new Link(fromClass: TemplateGroupResource::class, identifiers: [], parameterName: 'variantId'),
            ],
            input: GroupExportRequest::class,
            output: false,
            read: false,
            processor: GroupExportProcessor::class,
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
            name: 'api_template_group_export_variant',
            openapi: new OpenApiOperation(
                summary: 'Export one dimension of a group as PNG',
                description: TemplateGroupResource::BODY_DOC . "\n\nRenders ONE member dimension as `{group}-{dimension}.png`. Recorded as a version of the GROUP (deduplicates with a ZIP export of the same fill).",
                requestBody: new RequestBody(
                    description: 'The group fill: `inputs` + `images` (shared by every dimension) + per-dimension `placements`.',
                    content: new ArrayObject([
                        'application/json' => [
                            'schema' => [
                                'type' => 'object',
                                'properties' => [
                                    'inputs' => ['type' => 'object', 'description' => 'inputId → value, exactly as in POST /api/template-variants/{id}/export.'],
                                    'images' => ['type' => 'object', 'description' => 'imageInputId → gallery image id | { imageId, hide }.'],
                                    'placements' => [
                                        'type' => 'object',
                                        'description' => 'variantId → imageInputId → { scale, offsetXRatio, offsetYRatio, rotation }.',
                                        'additionalProperties' => ['type' => 'object', 'additionalProperties' => ['type' => 'object']],
                                    ],
                                ],
                            ],
                        ],
                    ]),
                ),
                responses: [
                    '200' => new OpenApiResponse(
                        description: 'Rendered PNG of the dimension.',
                        content: new ArrayObject(['image/png' => ['schema' => ['type' => 'string', 'format' => 'binary']]]),
                    ),
                    '400' => new OpenApiResponse(description: 'Structured `{error, code, ...}` body (the export codes) plus the `variantId` of the failing dimension.'),
                    '401' => new OpenApiResponse(description: 'Missing or invalid bearer token.'),
                    '403' => new OpenApiResponse(description: 'Group is not accessible to the authenticated user.'),
                    '404' => new OpenApiResponse(description: 'Group not found, or the variant is not a member of the group.'),
                    '503' => new OpenApiResponse(description: 'The renderer is overloaded (`code: "render_unavailable"`).'),
                ],
            ),
        ),
    ],
)]
final readonly class TemplateGroupResource
{
    public const string BODY_DOC = <<<'MD'
The body is the single-variant export body — `inputs` and `images` keyed by
input UUID (group members share their ids, so one map fills every
dimension) — plus `placements`: variantId → imageInputId →
`{ scale, offsetXRatio, offsetYRatio, rotation }`, the picture's placement in
THAT dimension (it replaces the shared pick's transform there; ignored for a
slot without a picture). Unknown ids are skipped per dimension. Errors are
the export's structured 400s, extended with the `variantId` of the dimension
that failed.
MD;
}
