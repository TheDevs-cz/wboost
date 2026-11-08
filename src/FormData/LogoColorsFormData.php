<?php

declare(strict_types=1);

namespace WBoost\Web\FormData;

use Symfony\Component\Validator\Constraints\All;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Constraints\Range;
use WBoost\Web\Validation\HexColorConstraint;

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

        /**
         * "Krytí" in percent per logo colour, keyed like `$colors`.
         *
         * @var array<string, null|int>
         */
        #[All([new NotNull(), new Range(min: 0, max: 100)])]
        public array $opacities = [],
    ) {
    }
}
