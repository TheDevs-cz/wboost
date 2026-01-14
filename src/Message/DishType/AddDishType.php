<?php

declare(strict_types=1);

namespace WBoost\Web\Message\DishType;

use Ramsey\Uuid\UuidInterface;

readonly final class AddDishType
{
    public function __construct(
        public UuidInterface $projectId,
        public UuidInterface $dishTypeId,
        public string $name,
    ) {
    }
}
