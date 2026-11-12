<?php

declare(strict_types=1);

namespace WBoost\Web\Services\AiScan;

use Doctrine\DBAL\Connection;

/**
 * The open access requests as the admin's rows read them (ux.md §9.2, §9.4).
 */
final readonly class AiAccessRequests
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @return list<array{id: string, projectId: string, projectName: string, requesterName: string, requesterEmail: string, origin: string, message: null|string, createdAt: null|string}>
     */
    public function open(null|string $projectId = null): array
    {
        /** @var list<array<string, null|string>> $rows */
        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                "SELECT r.id, r.project_id, p.name AS project_name, r.requester_name, r.requester_email, r.origin, r.message, r.created_at
                   FROM ai_access_request r JOIN project p ON p.id = r.project_id
                  WHERE r.status = 'open'%s ORDER BY r.created_at",
                $projectId === null ? '' : ' AND r.project_id = :project',
            ),
            $projectId === null ? [] : ['project' => $projectId],
        );

        return array_map(static fn (array $row): array => [
            'id' => (string) $row['id'],
            'projectId' => (string) $row['project_id'],
            'projectName' => (string) $row['project_name'],
            'requesterName' => (string) $row['requester_name'],
            'requesterEmail' => (string) $row['requester_email'],
            'origin' => (string) $row['origin'],
            'message' => $row['message'],
            'createdAt' => AiScanTime::fromSql($row['created_at'])?->format(DATE_ATOM),
        ], $rows);
    }
}
