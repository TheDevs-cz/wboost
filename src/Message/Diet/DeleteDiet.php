<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Diet;

use Ramsey\Uuid\UuidInterface;

readonly final class DeleteDiet
{
    public function __construct(
        public UuidInterface $dietId,
    ) {
    }
}
