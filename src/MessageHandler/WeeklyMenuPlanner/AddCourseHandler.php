<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\WeeklyMenuPlanner;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Entity\WeeklyMenuCourse;
use WBoost\Web\Exceptions\WeeklyMenuDayMealTypeNotFound;
use WBoost\Web\Message\WeeklyMenuPlanner\AddCourse;
use WBoost\Web\Repository\WeeklyMenuCourseRepository;
use WBoost\Web\Repository\WeeklyMenuDayMealTypeRepository;

#[AsMessageHandler]
readonly final class AddCourseHandler
{
    public function __construct(
        private WeeklyMenuDayMealTypeRepository $weeklyMenuDayMealTypeRepository,
        private WeeklyMenuCourseRepository $weeklyMenuCourseRepository,
    ) {
    }

    /**
     * @throws WeeklyMenuDayMealTypeNotFound
     */
    public function __invoke(AddCourse $message): void
    {
        $dayMealType = $this->weeklyMenuDayMealTypeRepository->get($message->dayMealTypeId);

        $position = count($dayMealType->courses());

        $course = new WeeklyMenuCourse(
            $message->courseId,
            $dayMealType,
            $position,
            $message->singleVariantMode,
        );

        $dayMealType->addCourse($course);
        $this->weeklyMenuCourseRepository->add($course);
    }
}
