<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

readonly final class ColorMapping
{
    public function __construct(
        public string $colorHex,
        public int $targetPrimaryColorNumber,
    ) {
    }
}
