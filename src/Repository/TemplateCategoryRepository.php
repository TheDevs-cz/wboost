<?php

declare(strict_types=1);

namespace WBoost\Web\Repository;

use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\TemplateCategory;
use WBoost\Web\Exceptions\TemplateCategoryNotFound;
use Doctrine\ORM\EntityManagerInterface;

readonly final class TemplateCategoryRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws TemplateCategoryNotFound
     */
    public function get(UuidInterface $categoryId): TemplateCategory
    {
        $category = $this->entityManager->find(TemplateCategory::class, $categoryId);

        if ($category instanceof TemplateCategory) {
            return $category;
        }

        throw new TemplateCategoryNotFound();
    }

    public function add(TemplateCategory $category): void
    {
        $this->entityManager->persist($category);
    }

    public function remove(TemplateCategory $category): void
    {
        $this->entityManager->remove($category);
    }

    public function count(UuidInterface $projectId): int
    {
        $count = $this->entityManager->createQueryBuilder()
            ->select('COUNT(category)')
            ->from(TemplateCategory::class, 'category')
            ->where('category.project = :projectId')
            ->setParameter('projectId', $projectId)
            ->getQuery()
            ->getSingleScalarResult();

        assert(is_int($count));

        return $count;
    }
}
