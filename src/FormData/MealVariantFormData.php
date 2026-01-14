<?php

declare(strict_types=1);

namespace WBoost\Web\FormData;

use Ramsey\Uuid\UuidInterface;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotNull;

final class MealVariantFormData
{
    public null|UuidInterface $id = null;

    #[NotBlank(normalizer: 'trim')]
    #[Length(min: 1, max: 255)]
    public string $name = '';

    #[NotNull]
    public null|UuidInterface $dietId = null;
}
