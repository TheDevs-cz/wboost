<?php

declare(strict_types=1);

namespace WBoost\Web\FormData;

use Symfony\Component\Validator\Constraints\All;
use WBoost\Web\Validator\HexColorConstraint;

final class ManualColorFormData
{
    public function __construct(
        #[All([new HexColorConstraint()])]
        public string $color = '',
        public null|int $order = 0,
        public null|string $type = null,
    ) {
    }
}
