<?php

declare(strict_types=1);

namespace WBoost\Web\Query;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\UuidInterface;

/**
 * Read-only export counts per template of one project since a moment, from
 * the usage log ({@see \WBoost\Web\Entity\ExportEvent}) — feeds the
 * "Nejpoužívanější" row of the Šablony listing (exports in the last 30 days).
 */
readonly final class GetTemplateExportCounts
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array<string, int> template id → number of exports
     */
    public function forProjectSince(UuidInterface $projectId, DateTimeImmutable $since): array
    {
        /** @var list<array{template_id: string, exports: int|string}> $rows */
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            <<<'SQL'
                SELECT template_id, COUNT(*) AS exports
                FROM export_event
                WHERE project_id = :projectId AND exported_at >= :since
                GROUP BY template_id
                SQL,
            [
                'projectId' => $projectId->toString(),
                'since' => $since->format('Y-m-d H:i:s'),
            ],
        );

        $counts = [];

        foreach ($rows as $row) {
            $counts[$row['template_id']] = (int) $row['exports'];
        }

        return $counts;
    }
}
