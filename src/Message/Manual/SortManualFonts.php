<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Manual;

readonly final class SortManualFonts
{
    public function __construct(
        /** @var array<string> */
        public array $manualFonts,
    ) {
    }
}
