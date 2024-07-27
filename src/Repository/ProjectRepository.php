<?php

declare(strict_types=1);

namespace WBoost\Web\Repository;

use Ramsey\Uuid\UuidInterface;
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
    public function get(UuidInterface $projectId): Project
    {
        $project = $this->entityManager->find(Project::class, $projectId);

        if ($project instanceof Project) {
            return $project;
        }

        throw new ProjectNotFound();
    }

    public function add(Project $project): void
    {
        $this->entityManager->persist($project);
    }

    public function remove(Project $project): void
    {
        $this->entityManager->remove($project);
    }
}
