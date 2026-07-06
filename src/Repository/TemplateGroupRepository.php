<?php

declare(strict_types=1);

namespace WBoost\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\TemplateGroup;
use WBoost\Web\Exceptions\TemplateGroupNotFound;

readonly final class TemplateGroupRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws TemplateGroupNotFound
     */
    public function get(UuidInterface $groupId): TemplateGroup
    {
        $group = $this->entityManager->find(TemplateGroup::class, $groupId);

        if ($group instanceof TemplateGroup) {
            return $group;
        }

        throw new TemplateGroupNotFound();
    }

    public function add(TemplateGroup $group): void
    {
        $this->entityManager->persist($group);
    }

    public function remove(TemplateGroup $group): void
    {
        $this->entityManager->remove($group);
    }

    public function count(UuidInterface $projectId): int
    {
        $count = $this->entityManager->createQueryBuilder()
            ->select('COUNT(templateGroup)')
            ->from(TemplateGroup::class, 'templateGroup')
            ->where('templateGroup.project = :projectId')
            ->setParameter('projectId', $projectId)
            ->getQuery()
            ->getSingleScalarResult();

        assert(is_int($count));

        return $count;
    }
}
