<?php

declare(strict_types=1);

namespace WBoost\Web\Repository;

use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\SocialNetworkCategory;
use WBoost\Web\Exceptions\SocialNetworkCategoryNotFound;
use Doctrine\ORM\EntityManagerInterface;

readonly final class SocialNetworkCategoryRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws SocialNetworkCategoryNotFound
     */
    public function get(UuidInterface $categoryId): SocialNetworkCategory
    {
        $category = $this->entityManager->find(SocialNetworkCategory::class, $categoryId);

        if ($category instanceof SocialNetworkCategory) {
            return $category;
        }

        throw new SocialNetworkCategoryNotFound();
    }

    public function add(SocialNetworkCategory $category): void
    {
        $this->entityManager->persist($category);
    }

    public function remove(SocialNetworkCategory $category): void
    {
        $this->entityManager->remove($category);
    }

    public function count(UuidInterface $projectId): int
    {
        $count = $this->entityManager->createQueryBuilder()
            ->select('COUNT(category)')
            ->from(SocialNetworkCategory::class, 'category')
            ->where('category.project = :projectId')
            ->setParameter('projectId', $projectId)
            ->getQuery()
            ->getSingleScalarResult();

        assert(is_int($count));

        return $count;
    }
}
