<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

readonly final class FontFace
{
    public function __construct(
        public string $name,
        public int $weight,
        public string $style,
        public string $file,
    ) {
    }
}
