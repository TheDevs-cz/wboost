<?php

declare(strict_types=1);

namespace WBoost\Web\FormData;

final class ManualColorsFormData
{
    public function __construct(
        /** @var non-empty-array<null|string> */
        public array $primaryColors,
        /** @var array<string> */
        public array $secondaryColors,
    ) {
    }
}
