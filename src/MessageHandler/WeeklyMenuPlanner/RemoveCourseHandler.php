<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\WeeklyMenuPlanner;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\WeeklyMenuCourseNotFound;
use WBoost\Web\Message\WeeklyMenuPlanner\RemoveCourse;
use WBoost\Web\Repository\WeeklyMenuCourseRepository;

#[AsMessageHandler]
readonly final class RemoveCourseHandler
{
    public function __construct(
        private WeeklyMenuCourseRepository $weeklyMenuCourseRepository,
    ) {
    }

    /**
     * @throws WeeklyMenuCourseNotFound
     */
    public function __invoke(RemoveCourse $message): void
    {
        $course = $this->weeklyMenuCourseRepository->get($message->courseId);
        $course->dayMealType->removeCourse($course);
        $this->weeklyMenuCourseRepository->remove($course);
    }
}
