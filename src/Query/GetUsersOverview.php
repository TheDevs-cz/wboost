<?php

declare(strict_types=1);

namespace WBoost\Web\Query;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

readonly final class GetUsersOverview
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return list<UserOverviewRow>
     */
    public function all(): array
    {
        $sql = <<<SQL
            SELECT u.id, u.email, u.name, u.roles, u.confirmed::int AS confirmed, u.registered_at,
              (SELECT COUNT(*) FROM project p WHERE p.owner_id = u.id) AS owned_count,
              (SELECT COUNT(*) FROM project_share ps WHERE ps.user_id = u.id) AS shared_count
            FROM "user" u
            ORDER BY u.registered_at DESC
        SQL;

        $rows = $this->entityManager->getConnection()->executeQuery($sql)->fetchAllAssociative();

        return array_map(fn (array $row): UserOverviewRow => $this->mapRow($row), $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRow(array $row): UserOverviewRow
    {
        $id = $row['id'];
        $email = $row['email'];
        $name = $row['name'];
        $rolesJson = $row['roles'];
        $confirmed = $row['confirmed'];
        $registeredAt = $row['registered_at'];
        $ownedCount = $row['owned_count'];
        $sharedCount = $row['shared_count'];

        assert(is_string($id));
        assert(is_string($email));
        assert($name === null || is_string($name));
        assert(is_string($rolesJson));
        assert(is_numeric($confirmed));
        assert(is_string($registeredAt));
        assert(is_numeric($ownedCount));
        assert(is_numeric($sharedCount));

        $decodedRoles = json_decode($rolesJson, true);
        assert(is_array($decodedRoles));

        $roles = [];
        foreach ($decodedRoles as $role) {
            if (is_string($role)) {
                $roles[] = $role;
            }
        }

        return new UserOverviewRow(
            id: $id,
            email: $email,
            name: $name,
            roles: $roles,
            confirmed: (bool) (int) $confirmed,
            registeredAt: new DateTimeImmutable($registeredAt),
            ownedCount: (int) $ownedCount,
            sharedCount: (int) $sharedCount,
        );
    }
}
