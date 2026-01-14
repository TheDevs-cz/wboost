<?php

declare(strict_types=1);

namespace WBoost\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\WeeklyMenuCourse;
use WBoost\Web\Exceptions\WeeklyMenuCourseNotFound;

readonly final class WeeklyMenuCourseRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws WeeklyMenuCourseNotFound
     */
    public function get(UuidInterface $courseId): WeeklyMenuCourse
    {
        $course = $this->entityManager->find(WeeklyMenuCourse::class, $courseId);

        if ($course instanceof WeeklyMenuCourse) {
            return $course;
        }

        throw new WeeklyMenuCourseNotFound();
    }

    public function add(WeeklyMenuCourse $course): void
    {
        $this->entityManager->persist($course);
    }

    public function remove(WeeklyMenuCourse $course): void
    {
        $this->entityManager->remove($course);
    }
}
