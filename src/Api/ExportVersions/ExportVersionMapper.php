<?php

declare(strict_types=1);

namespace WBoost\Web\Api\ExportVersions;

use WBoost\Web\Entity\TemplateExportVersion;

/**
 * Entity → DTO mapping shared by the export-version list, item and update
 * operations.
 */
final class ExportVersionMapper
{
    /**
     * @param array{texts: list<array{label: string, value: string}>, pictures: int, hidden: int} $summary
     */
    public static function summary(TemplateExportVersion $version, array $summary): ExportVersionResponse
    {
        return new ExportVersionResponse(
            id: $version->id->toString(),
            subject: $version->group !== null ? 'group' : 'variant',
            groupId: $version->group?->id->toString(),
            variantId: $version->variant?->id->toString(),
            name: $version->name,
            pinned: $version->isPinned(),
            pinnedAt: $version->pinnedAt,
            createdAt: $version->createdAt,
            lastExportedAt: $version->lastExportedAt,
            exportCount: $version->exportCount,
            channel: $version->channel->value,
            summary: new ExportVersionSummaryResponse($summary['texts'], $summary['pictures'], $summary['hidden']),
        );
    }

    /**
     * @param array{texts: list<array{label: string, value: string}>, pictures: int, hidden: int} $summary
     * @param array{inputs: array<string, mixed>, images: array<string, mixed>, placements: array<string, array<string, array<string, float>>>, shapeColors: array<string, string>} $fill
     */
    public static function detail(TemplateExportVersion $version, array $summary, array $fill): ExportVersionDetailResponse
    {
        $base = self::summary($version, $summary);

        return new ExportVersionDetailResponse(
            id: $base->id,
            subject: $base->subject,
            groupId: $base->groupId,
            variantId: $base->variantId,
            name: $base->name,
            pinned: $base->pinned,
            pinnedAt: $base->pinnedAt,
            createdAt: $base->createdAt,
            lastExportedAt: $base->lastExportedAt,
            exportCount: $base->exportCount,
            channel: $base->channel,
            summary: $base->summary,
            // Empty maps must serialize as JSON objects, not arrays.
            fill: [
                'inputs' => $fill['inputs'] === [] ? self::emptyMap() : $fill['inputs'],
                'images' => $fill['images'] === [] ? self::emptyMap() : $fill['images'],
                'placements' => $fill['placements'] === [] ? self::emptyMap() : $fill['placements'],
                'shapeColors' => $fill['shapeColors'] === [] ? self::emptyMap() : $fill['shapeColors'],
            ],
        );
    }

    /**
     * @return \ArrayObject<string, mixed>
     */
    private static function emptyMap(): \ArrayObject
    {
        /** @var \ArrayObject<string, mixed> $map */
        $map = new \ArrayObject();

        return $map;
    }
}
