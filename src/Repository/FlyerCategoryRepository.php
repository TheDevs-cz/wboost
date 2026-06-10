<?php

declare(strict_types=1);

namespace WBoost\Web\Repository;

use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\FlyerCategory;
use WBoost\Web\Exceptions\FlyerCategoryNotFound;
use Doctrine\ORM\EntityManagerInterface;

readonly final class FlyerCategoryRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws FlyerCategoryNotFound
     */
    public function get(UuidInterface $categoryId): FlyerCategory
    {
        $category = $this->entityManager->find(FlyerCategory::class, $categoryId);

        if ($category instanceof FlyerCategory) {
            return $category;
        }

        throw new FlyerCategoryNotFound();
    }

    public function add(FlyerCategory $category): void
    {
        $this->entityManager->persist($category);
    }

    public function remove(FlyerCategory $category): void
    {
        $this->entityManager->remove($category);
    }

    public function count(UuidInterface $projectId): int
    {
        $count = $this->entityManager->createQueryBuilder()
            ->select('COUNT(category)')
            ->from(FlyerCategory::class, 'category')
            ->where('category.project = :projectId')
            ->setParameter('projectId', $projectId)
            ->getQuery()
            ->getSingleScalarResult();

        assert(is_int($count));

        return $count;
    }
}
