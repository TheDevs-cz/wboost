<?php

declare(strict_types=1);

namespace WBoost\Web\Repository;

use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\CustomTemplateCategory;
use WBoost\Web\Exceptions\CustomTemplateCategoryNotFound;
use Doctrine\ORM\EntityManagerInterface;

readonly final class CustomTemplateCategoryRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws CustomTemplateCategoryNotFound
     */
    public function get(UuidInterface $categoryId): CustomTemplateCategory
    {
        $category = $this->entityManager->find(CustomTemplateCategory::class, $categoryId);

        if ($category instanceof CustomTemplateCategory) {
            return $category;
        }

        throw new CustomTemplateCategoryNotFound();
    }

    public function add(CustomTemplateCategory $category): void
    {
        $this->entityManager->persist($category);
    }

    public function remove(CustomTemplateCategory $category): void
    {
        $this->entityManager->remove($category);
    }

    public function count(UuidInterface $projectId): int
    {
        $count = $this->entityManager->createQueryBuilder()
            ->select('COUNT(category)')
            ->from(CustomTemplateCategory::class, 'category')
            ->where('category.project = :projectId')
            ->setParameter('projectId', $projectId)
            ->getQuery()
            ->getSingleScalarResult();

        assert(is_int($count));

        return $count;
    }
}
