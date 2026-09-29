<?php

declare(strict_types=1);

namespace WBoost\Web\Api\ExportVersions;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Patch;
use DateTimeImmutable;

/**
 * One export version WITH its fill, ready to load back into an editor.
 *
 * `fill` is in the export request's own wire shape — `inputs` (inputId →
 * string | `{value|runs,lines, hide, fontFamily}`), `images` (imageInputId →
 * `{imageId, url, scale?, offsetXRatio?, …}` | `{hide: true}`; `url` is the
 * picture's public store URL for the picker) and, for group versions,
 * `placements` (variantId → imageInputId → transform). It is seeded
 * LENIENTLY against the CURRENT design: inputs that no longer exist or got
 * locked are dropped, a rich value on a now-plain input degrades to its text,
 * fonts / pictures / transforms the slot no longer allows are dropped — so a
 * loaded fill always exports again.
 */
#[ApiResource(
    shortName: 'ExportVersionDetail',
    operations: [
        new Get(
            uriTemplate: '/export-versions/{versionId}',
            uriVariables: [
                'versionId' => new Link(fromClass: ExportVersionDetailResponse::class, identifiers: [], parameterName: 'versionId'),
            ],
            provider: ExportVersionProvider::class,
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
            normalizationContext: ['skip_null_values' => false, 'preserve_empty_objects' => true],
            name: 'api_export_version',
        ),
        new Patch(
            uriTemplate: '/export-versions/{versionId}',
            uriVariables: [
                'versionId' => new Link(fromClass: ExportVersionDetailResponse::class, identifiers: [], parameterName: 'versionId'),
            ],
            inputFormats: ['json' => ['application/json']],
            input: ExportVersionUpdateRequest::class,
            read: false,
            processor: ExportVersionUpdateProcessor::class,
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
            normalizationContext: ['skip_null_values' => false, 'preserve_empty_objects' => true],
            name: 'api_export_version_update',
        ),
    ],
)]
final readonly class ExportVersionDetailResponse
{
    /**
     * Empty maps arrive as `ArrayObject` so they serialize as JSON objects (`{}`), never `[]`.
     *
     * @param array{inputs: array<string, mixed>|\ArrayObject<string, mixed>, images: array<string, mixed>|\ArrayObject<string, mixed>, placements: array<string, array<string, array<string, float>>>|\ArrayObject<string, mixed>} $fill
     */
    public function __construct(
        public string $id,
        public string $subject,
        public null|string $groupId,
        public null|string $variantId,
        public null|string $name,
        public bool $pinned,
        public null|DateTimeImmutable $pinnedAt,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $lastExportedAt,
        public int $exportCount,
        public string $channel,
        public ExportVersionSummaryResponse $summary,
        public array $fill,
    ) {
    }
}
