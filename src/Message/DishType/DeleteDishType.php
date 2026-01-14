<?php

declare(strict_types=1);

namespace WBoost\Web\Message\DishType;

use Ramsey\Uuid\UuidInterface;

readonly final class DeleteDishType
{
    public function __construct(
        public UuidInterface $dishTypeId,
    ) {
    }
}
