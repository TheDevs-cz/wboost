<?php

declare(strict_types=1);

namespace WBoost\Web\Query;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\CustomTemplateCategory;

readonly final class GetCustomTemplateCategories
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array<CustomTemplateCategory>
     */
    public function allForProject(UuidInterface $projectId): array
    {
        return $this->entityManager->createQueryBuilder()
            ->from(CustomTemplateCategory::class, 'category')
            ->select('category', 'template')
            ->join('category.project', 'project')
            ->leftJoin('category.templates', 'template')
            ->where('project.id = :projectId')
            ->setParameter('projectId', $projectId->toString())
            ->orderBy('category.position')
            ->addOrderBy('template.position')
            ->getQuery()
            ->getResult();
    }
}
