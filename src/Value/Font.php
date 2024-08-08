<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

readonly final class Font
{
    public function __construct(
        public string $file,
        public int $weight,
        public string $style,
    ) {
    }
}
