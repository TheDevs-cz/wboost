<?php

declare(strict_types=1);

namespace WBoost\Web\Message\WeeklyMenuPlanner;

use Ramsey\Uuid\UuidInterface;

readonly final class ReorderCourses
{
    /**
     * @param array<UuidInterface> $courseIds
     */
    public function __construct(
        public UuidInterface $dayMealTypeId,
        public array $courseIds,
    ) {
    }
}
