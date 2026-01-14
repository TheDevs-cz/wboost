<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\WeeklyMenuPlanner;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Entity\WeeklyMenuCourseVariant;
use WBoost\Web\Exceptions\WeeklyMenuCourseNotFound;
use WBoost\Web\Message\WeeklyMenuPlanner\AddCourseVariant;
use WBoost\Web\Repository\WeeklyMenuCourseRepository;
use WBoost\Web\Repository\WeeklyMenuCourseVariantRepository;

#[AsMessageHandler]
readonly final class AddCourseVariantHandler
{
    public function __construct(
        private WeeklyMenuCourseRepository $weeklyMenuCourseRepository,
        private WeeklyMenuCourseVariantRepository $weeklyMenuCourseVariantRepository,
    ) {
    }

    /**
     * @throws WeeklyMenuCourseNotFound
     */
    public function __invoke(AddCourseVariant $message): void
    {
        $course = $this->weeklyMenuCourseRepository->get($message->courseId);

        $position = count($course->variants());

        $variant = new WeeklyMenuCourseVariant(
            $message->variantId,
            $course,
            $message->name,
            $position,
        );

        $course->addVariant($variant);
        $this->weeklyMenuCourseVariantRepository->add($variant);
    }
}
