<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\WeeklyMenuPlanner;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\WeeklyMenuDayMealTypeNotFound;
use WBoost\Web\Message\WeeklyMenuPlanner\ReorderCourses;
use WBoost\Web\Repository\WeeklyMenuDayMealTypeRepository;

#[AsMessageHandler]
readonly final class ReorderCoursesHandler
{
    public function __construct(
        private WeeklyMenuDayMealTypeRepository $weeklyMenuDayMealTypeRepository,
    ) {
    }

    /**
     * @throws WeeklyMenuDayMealTypeNotFound
     */
    public function __invoke(ReorderCourses $message): void
    {
        $dayMealType = $this->weeklyMenuDayMealTypeRepository->get($message->dayMealTypeId);

        $courses = $dayMealType->courses();
        $courseIds = array_map(
            static fn($id) => $id->toString(),
            $message->courseIds,
        );

        foreach ($courses as $course) {
            $position = array_search($course->id->toString(), $courseIds, true);
            if ($position !== false) {
                $course->sort((int) $position);
            }
        }
    }
}
