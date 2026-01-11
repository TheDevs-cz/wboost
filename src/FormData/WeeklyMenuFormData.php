<?php

declare(strict_types=1);

namespace WBoost\Web\FormData;

use Symfony\Component\Validator\Constraints\GreaterThanOrEqual;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotNull;

final class WeeklyMenuFormData
{
    #[NotBlank(normalizer: 'trim')]
    #[Length(min: 3, max: 255)]
    public string $name = '';

    #[NotNull]
    public null|\DateTimeImmutable $validFrom = null;

    #[NotNull]
    #[GreaterThanOrEqual(propertyPath: 'validFrom')]
    public null|\DateTimeImmutable $validTo = null;

    #[Length(max: 255)]
    public null|string $createdBy = null;

    #[Length(max: 255)]
    public null|string $approvedBy = null;
}
