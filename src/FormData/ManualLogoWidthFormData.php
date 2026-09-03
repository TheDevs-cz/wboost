<?php

declare(strict_types=1);

namespace WBoost\Web\FormData;

final class ManualLogoWidthFormData
{
    public function __construct(
        public null|int $displayWidth = null,
    ) {
    }
}
