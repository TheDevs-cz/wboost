<?php

declare(strict_types=1);

namespace WBoost\Web\Message\WeeklyMenuPlanner;

use Ramsey\Uuid\UuidInterface;

readonly final class AddCourse
{
    public function __construct(
        public UuidInterface $dayMealTypeId,
        public UuidInterface $courseId,
        public bool $singleVariantMode = false,
        public int $variantCount = 1,
    ) {
    }
}
