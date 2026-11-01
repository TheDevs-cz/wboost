<?php

declare(strict_types=1);

namespace WBoost\Web\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\UuidInterface;

/**
 * The projects the app shell lists (sidebar count + quick switcher). One
 * light DBAL query per page instead of hydrating Project entities: the same
 * scoping as the projects page (admins: every project; everyone else: owned +
 * shared), alphabetical.
 */
readonly final class GetShellProjects
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @return list<ShellProjectRow>
     */
    public function forUser(UuidInterface $userId, bool $everyProject): array
    {
        $sql = 'SELECT p.id, p.name, p.icon FROM project p';
        $params = [];

        if (!$everyProject) {
            $sql .= ' WHERE p.owner_id = :userId OR p.id IN (SELECT ps.project_id FROM project_share ps WHERE ps.user_id = :userId)';
            $params['userId'] = $userId->toString();
        }

        /** @var list<array{id: string, name: string, icon: null|string}> $rows */
        $rows = $this->connection->executeQuery($sql . ' ORDER BY p.name', $params)->fetchAllAssociative();

        $projects = array_map(
            static fn (array $row): ShellProjectRow => new ShellProjectRow($row['id'], $row['name'], $row['icon']),
            $rows,
        );

        // Postgres orders by the database collation; sort again in Czech so
        // "Čeladná" sits after "C…", not after "Z…".
        $collator = new \Collator('cs_CZ');
        usort($projects, static fn (ShellProjectRow $a, ShellProjectRow $b): int => (int) $collator->compare($a->name, $b->name));

        return $projects;
    }
}
