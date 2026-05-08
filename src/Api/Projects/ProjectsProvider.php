<?php

declare(strict_types=1);

namespace WBoost\Web\Api\Projects;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use WBoost\Web\Entity\User;

/**
 * @implements ProviderInterface<ProjectResponse>
 */
final readonly class ProjectsProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private Connection $connection,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     * @return list<ProjectResponse>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new AuthenticationException();
        }

        /** @var list<array{id: string, name: string, slug: string, created_at: string, manuals_count: int|string, shared_with_count: int|string}> $rows */
        $rows = $this->connection->createQueryBuilder()
            ->select(
                'p.id AS id',
                'p.name AS name',
                'p.slug AS slug',
                'p.created_at AS created_at',
                '(SELECT COUNT(m.id) FROM manual m WHERE m.project_id = p.id) AS manuals_count',
                'COALESCE(jsonb_array_length(p.sharing::jsonb), 0) AS shared_with_count',
            )
            ->from('project', 'p')
            ->where('p.owner_id = :owner_id')
            ->orderBy('p.created_at', 'DESC')
            ->setParameter('owner_id', $user->id->toString())
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(
            static fn (array $row): ProjectResponse => new ProjectResponse(
                id: $row['id'],
                name: $row['name'],
                slug: $row['slug'],
                createdAt: new DateTimeImmutable($row['created_at']),
                manualsCount: (int) $row['manuals_count'],
                sharedWithCount: (int) $row['shared_with_count'],
            ),
            $rows,
        );
    }
}
