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
 * while the totals pass produces the all-time DISTINCT counts. Every number is
 * scoped to the (owner, project) pair: owner_id is the project's owner at export
 * time, so a project handed over to another account shows up under both clients,
 * each counting only its own exports. Owner totals are summed from that owner's
 * project totals (a template / variant belongs to exactly one project); grand
 * totals come from one more DISTINCT pass so handed-over projects count once.
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
        // changed e-mail does not split one entity into two report rows. The
        // owner's display name comes from the live user row (presentation only;
        // NULL when the account has no name or no longer exists).
        $totalsSql = <<<SQL
            SELECT
              e.owner_id,
              (array_agg(e.owner_email ORDER BY e.exported_at DESC))[1] AS owner_email,
              MAX(u.name) AS owner_name,
              e.project_id,
              (array_agg(e.project_name ORDER BY e.exported_at DESC))[1] AS project_name,
              COUNT(DISTINCT e.template_id) AS unique_templates,
              COUNT(DISTINCT e.variant_id) AS unique_variants,
              COUNT(*) AS downloads,
              MAX(e.exported_at) AS last_export
            FROM export_event e
            LEFT JOIN "user" u ON u.id = e.owner_id
            GROUP BY e.owner_id, e.project_id
        SQL;

        $monthlyRows = $connection->executeQuery($monthlySql)->fetchAllAssociative();
        $totalsRows = $connection->executeQuery($totalsSql)->fetchAllAssociative();

        // owner_id|project_id => [ 'YYYY-MM' => UsageMonthMetrics ]. Keyed by
        // the PAIR, like the totals: owner_id is the project's owner at export
        // time, so a project handed over to another account appears under both
        // clients — each row must only count its own client's exports.
        $monthlyByProject = [];
        $monthsSet = [];

        foreach ($monthlyRows as $row) {
            $key = $this->asString($row['owner_id']) . '|' . $this->asString($row['project_id']);
            $month = $this->asString($row['month']);
            $monthsSet[$month] = true;

            $monthlyByProject[$key][$month] = new UsageMonthMetrics(
                $this->asInt($row['unique_templates']),
                $this->asInt($row['unique_variants']),
                $this->asInt($row['downloads']),
            );
        }

        $months = array_keys($monthsSet);
        sort($months); // 'YYYY-MM' sorts chronologically as a string

        // owner_id => list<UsageProjectRow>, plus the owner's e-mail / name labels.
        $projectsByOwner = [];
        $ownerEmails = [];
        $ownerNames = [];

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
                $monthlyByProject[$ownerId . '|' . $projectId] ?? [],
            );
            $ownerEmails[$ownerId] = $this->asString($row['owner_email']);
            $ownerName = is_string($row['owner_name']) ? trim($row['owner_name']) : '';
            $ownerNames[$ownerId] = $ownerName !== '' ? $ownerName : null;
        }

        $owners = [];

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
                $ownerNames[(string) $ownerId] ?? null,
                $ownerUniqueTemplates,
                $ownerUniqueVariants,
                $ownerDownloads,
                $ownerLastExportAt,
                $projects,
            );
        }

        // Grand totals straight from the log: summing the owner rows would count
        // a handed-over project (and its templates) once per owner.
        $grand = $connection->executeQuery(<<<SQL
            SELECT
              COUNT(DISTINCT template_id) AS unique_templates,
              COUNT(DISTINCT variant_id) AS unique_variants,
              COUNT(*) AS downloads,
              COUNT(DISTINCT project_id) AS projects
            FROM export_event
        SQL)->fetchAssociative();
        assert(is_array($grand));

        usort(
            $owners,
            static fn (UsageOwnerRow $a, UsageOwnerRow $b): int =>
                $b->downloads <=> $a->downloads ?: strcasecmp($a->ownerEmail, $b->ownerEmail),
        );

        return new UsageOverview(
            $months,
            $owners,
            $this->asInt($grand['unique_templates']),
            $this->asInt($grand['unique_variants']),
            $this->asInt($grand['downloads']),
            count($owners),
            $this->asInt($grand['projects']),
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
