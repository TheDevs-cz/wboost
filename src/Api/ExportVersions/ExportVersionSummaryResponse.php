<?php

declare(strict_types=1);

namespace WBoost\Web\Api\ExportVersions;

/**
 * A human digest of a version's fill — what the history lists show instead
 * of a thumbnail (versions store values, never rendered pixels).
 */
final readonly class ExportVersionSummaryResponse
{
    /**
     * @param list<array{label: string, value: string}> $texts filled texts in design order, plain, ≤ 60 chars
     */
    public function __construct(
        public array $texts,
        public int $pictures,
        public int $hidden,
    ) {
    }
}
