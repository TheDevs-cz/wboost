<?php

declare(strict_types=1);

namespace WBoost\Web\Query;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\Meal;
use WBoost\Web\Value\WeeklyMenuMealType;

readonly final class GetMeals
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array<Meal>
     */
    public function allForProject(UuidInterface $projectId): array
    {
        return $this->entityManager->createQueryBuilder()
            ->from(Meal::class, 'meal')
            ->select('meal', 'diet', 'dishType', 'variants', 'variantDiet', 'referenceMeal', 'referenceMealDiet')
            ->join('meal.project', 'project')
            ->leftJoin('meal.diet', 'diet')
            ->leftJoin('meal.dishType', 'dishType')
            ->leftJoin('meal.variants', 'variants')
            ->leftJoin('variants.diet', 'variantDiet')
            ->leftJoin('variants.referenceMeal', 'referenceMeal')
            ->leftJoin('referenceMeal.diet', 'referenceMealDiet')
            ->where('project.id = :projectId')
            ->setParameter('projectId', $projectId->toString())
            ->orderBy('meal.name', 'ASC')
            ->addOrderBy('variants.position', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return array<Meal>
     */
    public function filtered(
        UuidInterface $projectId,
        null|WeeklyMenuMealType $mealType = null,
        null|UuidInterface $dishTypeId = null,
        string $search = '',
    ): array {
        $qb = $this->entityManager->createQueryBuilder()
            ->from(Meal::class, 'meal')
            ->select('meal')
            ->join('meal.project', 'project')
            ->where('project.id = :projectId')
            ->setParameter('projectId', $projectId->toString());

        if ($mealType !== null) {
            $qb->andWhere('meal.mealType = :mealType')
                ->setParameter('mealType', $mealType->value);
        }

        if ($dishTypeId !== null) {
            $qb->join('meal.dishType', 'dishType')
                ->andWhere('dishType.id = :dishTypeId')
                ->setParameter('dishTypeId', $dishTypeId->toString());
        }

        if ($search !== '') {
            $qb->andWhere('LOWER(meal.name) LIKE LOWER(:search)')
                ->setParameter('search', '%' . $search . '%');
        }

        return $qb->orderBy('meal.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
