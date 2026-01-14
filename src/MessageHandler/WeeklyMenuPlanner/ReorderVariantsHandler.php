<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\WeeklyMenuPlanner;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\WeeklyMenuCourseNotFound;
use WBoost\Web\Message\WeeklyMenuPlanner\ReorderVariants;
use WBoost\Web\Repository\WeeklyMenuCourseRepository;

#[AsMessageHandler]
readonly final class ReorderVariantsHandler
{
    public function __construct(
        private WeeklyMenuCourseRepository $weeklyMenuCourseRepository,
    ) {
    }

    /**
     * @throws WeeklyMenuCourseNotFound
     */
    public function __invoke(ReorderVariants $message): void
    {
        $course = $this->weeklyMenuCourseRepository->get($message->courseId);

        $variants = $course->variants();
        $variantIds = array_map(
            static fn($id) => $id->toString(),
            $message->variantIds,
        );

        foreach ($variants as $variant) {
            $position = array_search($variant->id->toString(), $variantIds, true);
            if ($position !== false) {
                $variant->sort((int) $position);
            }
        }
    }
}
