<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

readonly final class ManualColor
{
    public function __construct(
        public Color $color,
        public null|ManualColorType $type,
    ) {
    }
}
