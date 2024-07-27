<?php

declare(strict_types=1);

namespace WBoost\Web\Repository;

use WBoost\Web\Entity\Project;
use WBoost\Web\Exceptions\ProjectNotFound;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

readonly final class ProjectRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws ProjectNotFound
     */
    public function get(string $projectId): Project
    {
        if (!Uuid::isValid($projectId)) {
            throw new ProjectNotFound();
        }

        $project = $this->entityManager->find(Project::class, $projectId);

        if ($project === null) {
            throw new ProjectNotFound();
        }

        return $project;
    }

    /**
     * @return array<Project>
     */
    public function all(): array
    {
        return $this->entityManager->createQueryBuilder()
            ->from(Project::class, 'p')
            ->select('p')
            ->getQuery()
            ->getResult();
    }

    public function save(Project $project): void
    {
        $this->entityManager->persist($project);
    }

    public function remove(Project $project): void
    {
        $this->entityManager->remove($project);
    }
}
