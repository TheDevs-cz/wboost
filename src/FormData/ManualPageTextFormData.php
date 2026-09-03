<?php

declare(strict_types=1);

namespace WBoost\Web\FormData;

final class ManualPageTextFormData
{
    public function __construct(
        public null|string $title = null,
        public null|string $description = null,
    ) {
    }
}
