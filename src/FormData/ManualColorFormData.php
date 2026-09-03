<?php

declare(strict_types=1);

namespace WBoost\Web\FormData;

use Symfony\Component\Validator\Constraints\NotBlank;
use WBoost\Web\Validation\HexColorConstraint;

final class ManualColorFormData
{
    public function __construct(
        /**
         * Nullable on purpose: a blank text/hidden input has no view transformer,
         * so Symfony maps it back as NULL (Form::viewToNorm) — a "string" property
         * would make the form submit crash instead of failing validation.
         */
        #[NotBlank(message: 'Vyplňte prosím HEX kód barvy.')]
        #[HexColorConstraint]
        public null|string $color = null,
        public null|int $order = 0,
        public null|string $type = null,
        public null|string $c = null,
        public null|string $m = null,
        public null|string $y = null,
        public null|string $k = null,
        public null|string $pantone = null,
        public null|string $ral = null,
    ) {
    }
}
