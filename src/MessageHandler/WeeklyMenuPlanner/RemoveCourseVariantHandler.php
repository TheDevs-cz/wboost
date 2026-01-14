<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\WeeklyMenuPlanner;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\WeeklyMenuCourseVariantNotFound;
use WBoost\Web\Message\WeeklyMenuPlanner\RemoveCourseVariant;
use WBoost\Web\Repository\WeeklyMenuCourseVariantRepository;

#[AsMessageHandler]
readonly final class RemoveCourseVariantHandler
{
    public function __construct(
        private WeeklyMenuCourseVariantRepository $weeklyMenuCourseVariantRepository,
    ) {
    }

    /**
     * @throws WeeklyMenuCourseVariantNotFound
     */
    public function __invoke(RemoveCourseVariant $message): void
    {
        $variant = $this->weeklyMenuCourseVariantRepository->get($message->variantId);
        $variant->course->removeVariant($variant);
        $this->weeklyMenuCourseVariantRepository->remove($variant);
    }
}
