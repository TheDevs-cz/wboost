<?php

declare(strict_types=1);

namespace WBoost\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\WeeklyMenu;
use WBoost\Web\Entity\WeeklyMenuCourse;
use WBoost\Web\Entity\WeeklyMenuCourseVariant;
use WBoost\Web\Entity\WeeklyMenuCourseVariantMeal;
use WBoost\Web\Entity\WeeklyMenuDay;
use WBoost\Web\Entity\WeeklyMenuDayMealType;
use WBoost\Web\Exceptions\WeeklyMenuNotFound;

readonly final class WeeklyMenuRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws WeeklyMenuNotFound
     */
    public function get(UuidInterface $menuId): WeeklyMenu
    {
        $menu = $this->entityManager->find(WeeklyMenu::class, $menuId);

        if ($menu instanceof WeeklyMenu) {
            return $menu;
        }

        throw new WeeklyMenuNotFound();
    }

    /**
     * Fetches WeeklyMenu with all nested relationships in optimized queries.
     * This avoids N+1 query problem by batch-loading all levels of the hierarchy.
     *
     * Uses separate queries per level to avoid cartesian product explosion,
     * but queries from parent→child direction to properly initialize collections.
     *
     * @throws WeeklyMenuNotFound
     */
    public function getWithFullTree(UuidInterface $menuId): WeeklyMenu
    {
        // Query 1: Fetch menu with days and project (initializes days collection)
        $menu = $this->entityManager->createQueryBuilder()
            ->select('menu', 'days', 'project')
            ->from(WeeklyMenu::class, 'menu')
            ->leftJoin('menu.days', 'days')
            ->leftJoin('menu.project', 'project')
            ->where('menu.id = :menuId')
            ->setParameter('menuId', $menuId)
            ->orderBy('days.dayOfWeek', 'ASC')
            ->getQuery()
            ->getOneOrNullResult();

        if (!$menu instanceof WeeklyMenu) {
            throw new WeeklyMenuNotFound();
        }

        $dayIds = array_map(
            static fn(WeeklyMenuDay $day) => $day->id,
            $menu->days(),
        );

        if ($dayIds === []) {
            return $menu;
        }

        // Query 2: Fetch days with their mealTypes (initializes mealTypes collections)
        $this->entityManager->createQueryBuilder()
            ->select('day', 'mealTypes')
            ->from(WeeklyMenuDay::class, 'day')
            ->leftJoin('day.mealTypes', 'mealTypes')
            ->where('day.id IN (:dayIds)')
            ->setParameter('dayIds', $dayIds)
            ->orderBy('mealTypes.position', 'ASC')
            ->getQuery()
            ->getResult();

        // Collect mealType IDs
        $mealTypeIds = [];
        foreach ($menu->days() as $day) {
            foreach ($day->mealTypes() as $mealType) {
                $mealTypeIds[] = $mealType->id;
            }
        }

        if ($mealTypeIds === []) {
            return $menu;
        }

        // Query 3: Fetch mealTypes with their courses (initializes courses collections)
        $this->entityManager->createQueryBuilder()
            ->select('mealType', 'courses')
            ->from(WeeklyMenuDayMealType::class, 'mealType')
            ->leftJoin('mealType.courses', 'courses')
            ->where('mealType.id IN (:mealTypeIds)')
            ->setParameter('mealTypeIds', $mealTypeIds)
            ->orderBy('courses.position', 'ASC')
            ->getQuery()
            ->getResult();

        // Collect course IDs
        $courseIds = [];
        foreach ($menu->days() as $day) {
            foreach ($day->mealTypes() as $mealType) {
                foreach ($mealType->courses() as $course) {
                    $courseIds[] = $course->id;
                }
            }
        }

        if ($courseIds === []) {
            return $menu;
        }

        // Query 4: Fetch courses with their variants (initializes variants collections)
        $this->entityManager->createQueryBuilder()
            ->select('course', 'variants')
            ->from(WeeklyMenuCourse::class, 'course')
            ->leftJoin('course.variants', 'variants')
            ->where('course.id IN (:courseIds)')
            ->setParameter('courseIds', $courseIds)
            ->orderBy('variants.position', 'ASC')
            ->getQuery()
            ->getResult();

        // Collect variant IDs
        $variantIds = [];
        foreach ($menu->days() as $day) {
            foreach ($day->mealTypes() as $mealType) {
                foreach ($mealType->courses() as $course) {
                    foreach ($course->variants() as $variant) {
                        $variantIds[] = $variant->id;
                    }
                }
            }
        }

        if ($variantIds === []) {
            return $menu;
        }

        // Query 5: Fetch variants with their meals (initializes meals collections)
        // Also eager-load the Meal entity with its Diet, DishType, MealVariants, and referenceMeal
        $this->entityManager->createQueryBuilder()
            ->select('variant', 'variantMeals', 'meal', 'diet', 'dishType', 'mealVariants', 'mealVariantDiet', 'referenceMeal', 'referenceMealDiet')
            ->from(WeeklyMenuCourseVariant::class, 'variant')
            ->leftJoin('variant.meals', 'variantMeals')
            ->leftJoin('variantMeals.meal', 'meal')
            ->leftJoin('meal.diet', 'diet')
            ->leftJoin('meal.dishType', 'dishType')
            ->leftJoin('meal.variants', 'mealVariants')
            ->leftJoin('mealVariants.diet', 'mealVariantDiet')
            ->leftJoin('mealVariants.referenceMeal', 'referenceMeal')
            ->leftJoin('referenceMeal.diet', 'referenceMealDiet')
            ->where('variant.id IN (:variantIds)')
            ->setParameter('variantIds', $variantIds)
            ->orderBy('variantMeals.position', 'ASC')
            ->addOrderBy('mealVariants.position', 'ASC')
            ->getQuery()
            ->getResult();

        return $menu;
    }

    public function add(WeeklyMenu $menu): void
    {
        $this->entityManager->persist($menu);
    }

    public function remove(WeeklyMenu $menu): void
    {
        $this->entityManager->remove($menu);
    }
}
