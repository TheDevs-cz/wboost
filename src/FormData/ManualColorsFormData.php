<?php

declare(strict_types=1);

namespace WBoost\Web\FormData;

use Symfony\Component\Validator\Constraints\All;
use WBoost\Web\Validator\HexColorConstraint;

final class ManualColorsFormData
{
    public function __construct(
        /** @var non-empty-array<null|string> */
        #[All([new HexColorConstraint()])]
        public array $primaryColors,

        /** @var array<string> */
        #[All([new HexColorConstraint()])]
        public array $secondaryColors,
    ) {
    }
}
