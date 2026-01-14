<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Diet;

use Ramsey\Uuid\UuidInterface;

readonly final class AddDiet
{
    /**
     * @param array<string> $codes
     */
    public function __construct(
        public UuidInterface $projectId,
        public UuidInterface $dietId,
        public string $name,
        public array $codes,
    ) {
    }
}
