<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Manual;

use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Value\ManualColor;

readonly final class EditManualColors
{
    public function __construct(
        public UuidInterface $manualId,
        /** @var array<ManualColor> */
        public array $detectedColors,
        /** @var array<ManualColor> */
        public array $customColors,
    ) {
    }
}
