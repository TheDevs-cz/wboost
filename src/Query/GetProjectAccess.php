<?php

declare(strict_types=1);

namespace WBoost\Web\Query;

use Doctrine\DBAL\Connection;

/**
 * Who can open which project (owner + project_share rows) in one light DBAL
 * query — the admin users list filters by it ("Projekt ▾") and the user
 * profile page lists a user's projects. Read-only presentation data.
 */
readonly final class GetProjectAccess
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @return list<ProjectAccessRow> owned projects first, then by project name (Czech collation)
     */
    public function rows(null|string $userId = null): array
    {
        $sql = <<<SQL
            SELECT * FROM (
                SELECT p.owner_id AS user_id, p.id AS project_id, p.name, p.icon,
                       COALESCE(o.name, o.email) AS owner_name, 1 AS is_owner
                FROM project p
                JOIN "user" o ON o.id = p.owner_id
                UNION ALL
                SELECT ps.user_id, p.id, p.name, p.icon,
                       COALESCE(o.name, o.email), 0
                FROM project_share ps
                JOIN project p ON p.id = ps.project_id
                JOIN "user" o ON o.id = p.owner_id
            ) access
        SQL;

        $params = [];
        if ($userId !== null) {
            $sql .= ' WHERE access.user_id = :userId';
            $params['userId'] = $userId;
        }

        /** @var list<array{user_id: string, project_id: string, name: string, icon: null|string, owner_name: string, is_owner: int|string}> $rows */
        $rows = $this->connection->executeQuery($sql, $params)->fetchAllAssociative();

        $access = array_map(
            static fn (array $row): ProjectAccessRow => new ProjectAccessRow(
                userId: $row['user_id'],
                projectId: $row['project_id'],
                projectName: $row['name'],
                iconPath: $row['icon'],
                ownerName: $row['owner_name'],
                isOwner: (int) $row['is_owner'] === 1,
            ),
            $rows,
        );

        $collator = new \Collator('cs_CZ');
        usort($access, static fn (ProjectAccessRow $a, ProjectAccessRow $b): int => ($b->isOwner <=> $a->isOwner)
            ?: (int) $collator->compare($a->projectName, $b->projectName));

        return $access;
    }

    /**
     * @return array<string, array<string, bool>> userId => projectId => isOwner
     */
    public function byUser(): array
    {
        $map = [];

        foreach ($this->rows() as $row) {
            $map[$row->userId][$row->projectId] = $row->isOwner;
        }

        return $map;
    }
}
