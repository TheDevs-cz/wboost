<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\WeeklyMenuPlanner;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\WeeklyMenuCourseVariantNotFound;
use WBoost\Web\Message\WeeklyMenuPlanner\EditCourseVariant;
use WBoost\Web\Repository\WeeklyMenuCourseVariantRepository;

#[AsMessageHandler]
readonly final class EditCourseVariantHandler
{
    public function __construct(
        private WeeklyMenuCourseVariantRepository $weeklyMenuCourseVariantRepository,
    ) {
    }

    /**
     * @throws WeeklyMenuCourseVariantNotFound
     */
    public function __invoke(EditCourseVariant $message): void
    {
        $variant = $this->weeklyMenuCourseVariantRepository->get($message->variantId);
        $variant->edit($message->name);
    }
}
