<?php

declare(strict_types=1);

namespace WBoost\Web\Query;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use WBoost\Web\Value\StorageCategory;

/**
 * Builds the admin storage report from the {@see \WBoost\Web\Entity\StorageObject}
 * inventory. Pure read model — raw SQL aggregation, no entity hydration.
 *
 * Sizes are additive (unlike the DISTINCT counts in {@see GetUsageOverview}),
 * so one grouped pass per axis is enough and the owner / grand totals are
 * summed in PHP from the per-project rows.
 */
readonly final class GetStorageOverview
{
    /** Cap on the number of per-client chart bars before the rest roll into "Ostatní". */
    private const int CHART_OWNER_LIMIT = 12;

    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function overview(): StorageOverview
    {
        $connection = $this->entityManager->getConnection();

        // Only attributable objects go into the owner ▸ project tree; the rest
        // are reported as their own "unattributed" bucket rather than being
        // silently dropped or lumped under a fake owner.
        $perProjectSql = <<<SQL
            SELECT
              owner_id,
              (array_agg(owner_email))[1] AS owner_email,
              project_id,
              (array_agg(project_name))[1] AS project_name,
              COUNT(*) AS file_count,
              COALESCE(SUM(size), 0) AS total_size,
              COUNT(*) FILTER (WHERE orphaned) AS orphan_count,
              COALESCE(SUM(size) FILTER (WHERE orphaned), 0) AS orphan_size
            FROM storage_object
            WHERE project_id IS NOT NULL AND owner_id IS NOT NULL
            GROUP BY owner_id, project_id
        SQL;

        $totalsSql = <<<SQL
            SELECT
              COUNT(*) AS file_count,
              COALESCE(SUM(size), 0) AS total_size,
              COUNT(*) FILTER (WHERE orphaned) AS orphan_count,
              COALESCE(SUM(size) FILTER (WHERE orphaned), 0) AS orphan_size,
              COUNT(*) FILTER (WHERE project_id IS NULL) AS unattributed_count,
              COALESCE(SUM(size) FILTER (WHERE project_id IS NULL), 0) AS unattributed_size,
              MAX(scanned_at) AS last_scanned_at
            FROM storage_object
        SQL;

        $categoriesSql = <<<SQL
            SELECT
              category,
              COUNT(*) AS file_count,
              COALESCE(SUM(size), 0) AS total_size,
              COUNT(*) FILTER (WHERE orphaned) AS orphan_count,
              COALESCE(SUM(size) FILTER (WHERE orphaned), 0) AS orphan_size
            FROM storage_object
            GROUP BY category
            ORDER BY COALESCE(SUM(size), 0) DESC
        SQL;

        $perProjectRows = $connection->executeQuery($perProjectSql)->fetchAllAssociative();
        $totals = $connection->executeQuery($totalsSql)->fetchAssociative();
        $categoryRows = $connection->executeQuery($categoriesSql)->fetchAllAssociative();

        /** @var array<string, list<StorageProjectRow>> $projectsByOwner */
        $projectsByOwner = [];
        $ownerEmails = [];

        foreach ($perProjectRows as $row) {
            $ownerId = $this->asString($row['owner_id']);

            $projectsByOwner[$ownerId][] = new StorageProjectRow(
                $this->asString($row['project_id']),
                $this->asString($row['project_name']),
                $this->asInt($row['file_count']),
                $this->asInt($row['total_size']),
                $this->asInt($row['orphan_count']),
                $this->asInt($row['orphan_size']),
            );
            $ownerEmails[$ownerId] = $this->asString($row['owner_email']);
        }

        $owners = [];

        foreach ($projectsByOwner as $ownerId => $projects) {
            usort(
                $projects,
                static fn (StorageProjectRow $a, StorageProjectRow $b): int =>
                    ($b->totalSize <=> $a->totalSize) ?: strcasecmp($a->projectName, $b->projectName),
            );

            $owners[] = new StorageOwnerRow(
                $ownerId,
                $ownerEmails[$ownerId],
                array_sum(array_map(static fn (StorageProjectRow $p): int => $p->fileCount, $projects)),
                array_sum(array_map(static fn (StorageProjectRow $p): int => $p->totalSize, $projects)),
                array_sum(array_map(static fn (StorageProjectRow $p): int => $p->orphanCount, $projects)),
                array_sum(array_map(static fn (StorageProjectRow $p): int => $p->orphanSize, $projects)),
                $projects,
            );
        }

        usort(
            $owners,
            static fn (StorageOwnerRow $a, StorageOwnerRow $b): int =>
                ($b->totalSize <=> $a->totalSize) ?: strcasecmp($a->ownerEmail, $b->ownerEmail),
        );

        $categories = [];

        foreach ($categoryRows as $row) {
            $category = StorageCategory::tryFrom($this->asString($row['category']));

            if ($category === null) {
                continue;
            }

            $categories[] = new StorageCategoryRow(
                $category,
                $this->asInt($row['file_count']),
                $this->asInt($row['total_size']),
                $this->asInt($row['orphan_count']),
                $this->asInt($row['orphan_size']),
            );
        }

        $lastScannedAt = $totals === false ? null : $totals['last_scanned_at'];

        return new StorageOverview(
            $owners,
            $categories,
            $totals === false ? 0 : $this->asInt($totals['file_count']),
            $totals === false ? 0 : $this->asInt($totals['total_size']),
            $totals === false ? 0 : $this->asInt($totals['orphan_count']),
            $totals === false ? 0 : $this->asInt($totals['orphan_size']),
            $totals === false ? 0 : $this->asInt($totals['unattributed_count']),
            $totals === false ? 0 : $this->asInt($totals['unattributed_size']),
            is_string($lastScannedAt) ? new DateTimeImmutable($lastScannedAt) : null,
            $this->buildChartLabels($owners),
            $this->buildChartSeries($owners),
        );
    }

    /**
     * @param list<StorageOwnerRow> $owners ordered by size desc
     * @return list<string>
     */
    private function buildChartLabels(array $owners): array
    {
        $labels = array_map(
            static fn (StorageOwnerRow $owner): string => $owner->ownerEmail,
            array_slice($owners, 0, self::CHART_OWNER_LIMIT),
        );

        if (count($owners) > self::CHART_OWNER_LIMIT) {
            $labels[] = 'Ostatní';
        }

        return $labels;
    }

    /**
     * Two stacked series per client — bytes still in use versus bytes nothing
     * references — so the chart shows both the size and the waste at a glance.
     *
     * @param list<StorageOwnerRow> $owners ordered by size desc
     * @return list<array{name: string, data: list<float>}>
     */
    private function buildChartSeries(array $owners): array
    {
        if ($owners === []) {
            return [];
        }

        $named = array_slice($owners, 0, self::CHART_OWNER_LIMIT);
        $rest = array_slice($owners, self::CHART_OWNER_LIMIT);

        $used = [];
        $orphaned = [];

        foreach ($named as $owner) {
            $used[] = $this->toMegabytes($owner->totalSize - $owner->orphanSize);
            $orphaned[] = $this->toMegabytes($owner->orphanSize);
        }

        if ($rest !== []) {
            $restTotal = array_sum(array_map(static fn (StorageOwnerRow $o): int => $o->totalSize, $rest));
            $restOrphan = array_sum(array_map(static fn (StorageOwnerRow $o): int => $o->orphanSize, $rest));

            $used[] = $this->toMegabytes($restTotal - $restOrphan);
            $orphaned[] = $this->toMegabytes($restOrphan);
        }

        return [
            ['name' => 'Používané', 'data' => $used],
            ['name' => 'Nepoužívané', 'data' => $orphaned],
        ];
    }

    private function toMegabytes(int $bytes): float
    {
        return round($bytes / 1024 / 1024, 2);
    }

    private function asString(mixed $value): string
    {
        assert(is_string($value));

        return $value;
    }

    private function asInt(mixed $value): int
    {
        assert(is_numeric($value));

        return (int) $value;
    }
}
