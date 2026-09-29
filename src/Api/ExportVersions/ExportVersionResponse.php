<?php

declare(strict_types=1);

namespace WBoost\Web\Api\ExportVersions;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use DateTimeImmutable;

/**
 * One entry of a fill surface's EXPORT HISTORY ("Historie exportů") — the
 * same shared list the web fill pages show: every distinct fill that was
 * exported (web, API, MCP, publish), deduplicated, pinned versions first
 * (most recently pinned on top), then the rest freshest first. The history
 * belongs to the fill surface: a GROUP for synchronized templates (one list
 * for all dimensions), a VARIANT otherwise.
 *
 * Load a version's fill with `GET /api/export-versions/{id}`; rename / pin it
 * with `PATCH /api/export-versions/{id}`.
 */
#[ApiResource(
    shortName: 'ExportVersion',
    operations: [
        new GetCollection(
            uriTemplate: '/template-groups/{groupId}/export-versions',
            uriVariables: [
                'groupId' => new Link(fromClass: ExportVersionResponse::class, identifiers: [], parameterName: 'groupId'),
            ],
            provider: ExportVersionsProvider::class,
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
            paginationEnabled: false,
            normalizationContext: ['skip_null_values' => false],
            name: 'api_template_group_export_versions',
        ),
        new GetCollection(
            uriTemplate: '/template-variants/{variantId}/export-versions',
            uriVariables: [
                'variantId' => new Link(fromClass: ExportVersionResponse::class, identifiers: [], parameterName: 'variantId'),
            ],
            provider: ExportVersionsProvider::class,
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
            paginationEnabled: false,
            normalizationContext: ['skip_null_values' => false],
            name: 'api_template_variant_export_versions',
        ),
    ],
)]
final readonly class ExportVersionResponse
{
    public function __construct(
        public string $id,
        /** `group` | `variant` — which fill surface the version belongs to. */
        public string $subject,
        public null|string $groupId,
        public null|string $variantId,
        /** User-given label; null = unnamed (list it under `lastExportedAt`). Max 120 chars. */
        public null|string $name,
        public bool $pinned,
        public null|DateTimeImmutable $pinnedAt,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $lastExportedAt,
        public int $exportCount,
        /** The channel of the LATEST export of this fill: web | api | mcp | facebook | instagram. */
        public string $channel,
        public ExportVersionSummaryResponse $summary,
    ) {
    }
}
