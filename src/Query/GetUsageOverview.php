<?php

declare(strict_types=1);

namespace WBoost\Web\Query;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Builds the admin usage report from the {@see \WBoost\Web\Entity\ExportEvent}
 * log. Pure read model — raw SQL aggregation, no entity hydration.
 *
 * Two passes are required because DISTINCT counts are not additive across
 * months: the monthly pass feeds the per-month table cells and the chart,
 * while the totals pass produces the all-time DISTINCT counts. Owner / grand
 * totals are then summed from the per-project totals, which is correct because
 * a template (and a variant) belongs to exactly one project.
 */
readonly final class GetUsageOverview
{
    /** Cap on the number of per-client chart series before the rest roll into "Ostatní". */
    private const int CHART_OWNER_LIMIT = 12;

    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function overview(): UsageOverview
    {
        $connection = $this->entityManager->getConnection();

        $monthlySql = <<<SQL
            SELECT
              owner_id,
              project_id,
              to_char(date_trunc('month', exported_at), 'YYYY-MM') AS month,
              COUNT(DISTINCT template_id) AS unique_templates,
              COUNT(DISTINCT variant_id) AS unique_variants,
              COUNT(*) AS downloads
            FROM export_event
            GROUP BY owner_id, project_id, date_trunc('month', exported_at)
        SQL;

        // Group only by id and take the latest label so a renamed project /
        // changed e-mail does not split one entity into two report rows.
        $totalsSql = <<<SQL
            SELECT
              owner_id,
              (array_agg(owner_email ORDER BY exported_at DESC))[1] AS owner_email,
              project_id,
              (array_agg(project_name ORDER BY exported_at DESC))[1] AS project_name,
              COUNT(DISTINCT template_id) AS unique_templates,
              COUNT(DISTINCT variant_id) AS unique_variants,
              COUNT(*) AS downloads,
              MAX(exported_at) AS last_export
            FROM export_event
            GROUP BY owner_id, project_id
        SQL;

        $monthlyRows = $connection->executeQuery($monthlySql)->fetchAllAssociative();
        $totalsRows = $connection->executeQuery($totalsSql)->fetchAllAssociative();

        // project_id => [ 'YYYY-MM' => UsageMonthMetrics ]
        $monthlyByProject = [];
        $monthsSet = [];

        foreach ($monthlyRows as $row) {
            $projectId = $this->asString($row['project_id']);
            $month = $this->asString($row['month']);
            $monthsSet[$month] = true;

            $monthlyByProject[$projectId][$month] = new UsageMonthMetrics(
                $this->asInt($row['unique_templates']),
                $this->asInt($row['unique_variants']),
                $this->asInt($row['downloads']),
            );
        }

        $months = array_keys($monthsSet);
        sort($months); // 'YYYY-MM' sorts chronologically as a string

        // owner_id => list<UsageProjectRow>, plus the owner's e-mail label.
        $projectsByOwner = [];
        $ownerEmails = [];

        foreach ($totalsRows as $row) {
            $ownerId = $this->asString($row['owner_id']);
            $projectId = $this->asString($row['project_id']);

            $projectsByOwner[$ownerId][] = new UsageProjectRow(
                $projectId,
                $this->asString($row['project_name']),
                $this->asInt($row['unique_templates']),
                $this->asInt($row['unique_variants']),
                $this->asInt($row['downloads']),
                new DateTimeImmutable($this->asString($row['last_export'])),
                $monthlyByProject[$projectId] ?? [],
            );
            $ownerEmails[$ownerId] = $this->asString($row['owner_email']);
        }

        $owners = [];
        $totalUniqueTemplates = 0;
        $totalUniqueVariants = 0;
        $totalDownloads = 0;
        $projectCount = 0;

        foreach ($projectsByOwner as $ownerId => $projects) {
            usort(
                $projects,
                static fn (UsageProjectRow $a, UsageProjectRow $b): int =>
                    ($b->downloads <=> $a->downloads) ?: strcasecmp($a->projectName, $b->projectName),
            );

            $ownerUniqueTemplates = 0;
            $ownerUniqueVariants = 0;
            $ownerDownloads = 0;
            $ownerLastExportAt = null;

            foreach ($projects as $project) {
                $ownerUniqueTemplates += $project->uniqueTemplates;
                $ownerUniqueVariants += $project->uniqueVariants;
                $ownerDownloads += $project->downloads;
                if ($ownerLastExportAt === null || $project->lastExportAt > $ownerLastExportAt) {
                    $ownerLastExportAt = $project->lastExportAt;
                }
            }

            // $ownerLastExportAt is non-null: every owner has at least one project.
            $owners[] = new UsageOwnerRow(
                (string) $ownerId,
                $ownerEmails[(string) $ownerId],
                $ownerUniqueTemplates,
                $ownerUniqueVariants,
                $ownerDownloads,
                $ownerLastExportAt,
                $projects,
            );

            $totalUniqueTemplates += $ownerUniqueTemplates;
            $totalUniqueVariants += $ownerUniqueVariants;
            $totalDownloads += $ownerDownloads;
            $projectCount += count($projects);
        }

        usort(
            $owners,
            static fn (UsageOwnerRow $a, UsageOwnerRow $b): int =>
                $b->downloads <=> $a->downloads ?: strcasecmp($a->ownerEmail, $b->ownerEmail),
        );

        return new UsageOverview(
            $months,
            $owners,
            $totalUniqueTemplates,
            $totalUniqueVariants,
            $totalDownloads,
            count($owners),
            $projectCount,
            $this->buildChartCategories($months),
            $this->buildChartSeries($owners, $months),
        );
    }

    /**
     * @param list<string> $months
     *
     * @return list<string>
     */
    private function buildChartCategories(array $months): array
    {
        return array_map(
            static function (string $month): string {
                [$year, $monthNumber] = explode('-', $month);

                return ((int) $monthNumber) . '/' . $year;
            },
            $months,
        );
    }

    /**
     * One stacked series of unique templates per client per month. Clients
     * beyond the cap are aggregated into a single "Ostatní" series so the
     * chart stays readable.
     *
     * @param list<UsageOwnerRow> $owners ordered by downloads desc
     * @param list<string> $months
     *
     * @return list<array{name: string, data: list<int>}>
     */
    private function buildChartSeries(array $owners, array $months): array
    {
        if ($months === []) {
            return [];
        }

        $series = [];
        $named = array_slice($owners, 0, self::CHART_OWNER_LIMIT);
        $rest = array_slice($owners, self::CHART_OWNER_LIMIT);

        foreach ($named as $owner) {
            $data = [];
            foreach ($months as $month) {
                $data[] = $owner->templatesInMonth($month);
            }

            $series[] = ['name' => $owner->ownerEmail, 'data' => $data];
        }

        if ($rest !== []) {
            $data = [];
            $hasAny = false;
            foreach ($months as $month) {
                $sum = 0;
                foreach ($rest as $owner) {
                    $sum += $owner->templatesInMonth($month);
                }
                $hasAny = $hasAny || $sum > 0;
                $data[] = $sum;
            }

            if ($hasAny) {
                $series[] = ['name' => 'Ostatní', 'data' => $data];
            }
        }

        return $series;
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
