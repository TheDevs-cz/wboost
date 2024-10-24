<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

readonly final class ManualColor
{
    /** @var null|array{null|string, null|string, null|string, null|string} */
    public null|array $cmyk;

    /** @param null|array{null|string, null|string, null|string, null|string} $cmyk */
    public function __construct(
        public Color $color,
        public null|ManualColorType $type,
        public null|string $pantone,
        null|array $cmyk,
    ) {
        if ($cmyk === [null, null, null, null]) {
            $this->cmyk = null;
        } else {
            $this->cmyk = $cmyk;
        }
    }
}
