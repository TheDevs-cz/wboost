<?php

declare(strict_types=1);

namespace WBoost\Web\FormData;

use Symfony\Component\Validator\Constraints\All;
use Symfony\Component\Validator\Constraints\NotBlank;
use WBoost\Web\Validator\HexColorConstraint;

final class LogoColorsFormData
{
    public function __construct(
        #[NotBlank]
        #[HexColorConstraint]
        public string $background = '',

        /** @var array<string, string> */
        #[All([new NotBlank()])]
        #[All([new HexColorConstraint()])]
        public array $colors = [],
    ) {
    }
}
