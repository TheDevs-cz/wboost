<?php

declare(strict_types=1);

namespace WBoost\Web\Message\WeeklyMenuPlanner;

use Ramsey\Uuid\UuidInterface;

readonly final class ReorderVariants
{
    /**
     * @param array<UuidInterface> $variantIds
     */
    public function __construct(
        public UuidInterface $courseId,
        public array $variantIds,
    ) {
    }
}
