<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Manual;

use Ramsey\Uuid\UuidInterface;

readonly final class EditManualColors
{
    public function __construct(
        public UuidInterface $manualId,
        /** @var non-empty-array<null|string> */
        public array $primaryColors,
        /** @var array<string> */
        public array $secondaryColors,
        /** @var array<string|int, string> */
        public array $mapping,
    ) {
    }
}
