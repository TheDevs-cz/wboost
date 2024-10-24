<?php

declare(strict_types=1);

namespace WBoost\Web\FormData;

use WBoost\Web\Validation\HexColorConstraint;

final class ManualColorFormData
{
    public function __construct(
        #[HexColorConstraint]
        public string $color = '',
        public null|int $order = 0,
        public null|string $type = null,
        public null|string $c = null,
        public null|string $m = null,
        public null|string $y = null,
        public null|string $k = null,
        public null|string $pantone = null,
    ) {
    }
}
