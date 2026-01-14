<?php

declare(strict_types=1);

namespace WBoost\Web\FormData;

use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

final class DietFormData
{
    #[NotBlank(normalizer: 'trim')]
    #[Length(min: 2, max: 255)]
    public string $name = '';

    /** @var array<string> */
    public array $codes = [];
}
