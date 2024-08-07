<?php

declare(strict_types=1);

namespace WBoost\Web\Repository;

use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\Manual;
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
    public function get(UuidInterface $projectId): Manual
    {
        $project = $this->entityManager->find(Manual::class, $projectId);

        if ($project instanceof Manual) {
            return $project;
        }

        throw new ProjectNotFound();
    }

    public function add(Manual $project): void
    {
        $this->entityManager->persist($project);
    }

    public function remove(Manual $project): void
    {
        $this->entityManager->remove($project);
    }
}
