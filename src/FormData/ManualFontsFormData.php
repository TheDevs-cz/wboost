<?php

declare(strict_types=1);

namespace WBoost\Web\FormData;

use WBoost\Web\Validation\HexColorConstraint;

final class ManualFontsFormData
{
    public null|string $font = null;
    public null|string $type = null;
    #[HexColorConstraint]
    public null|string $color = null;
}
