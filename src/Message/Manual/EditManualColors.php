<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Manual;

use Ramsey\Uuid\UuidInterface;

readonly final class EditManualColors
{
    public function __construct(
        public UuidInterface $manualId,
        public null|string $color1,
        public null|string $color2,
        public null|string $color3,
        public null|string $color4,
        /** @var array<string, string> */
        public array $mapping,
        /** @var array<string> */
        public array $secondaryColors,
    ) {
    }
}
