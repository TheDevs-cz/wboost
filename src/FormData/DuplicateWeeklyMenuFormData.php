<?php

declare(strict_types=1);

namespace WBoost\Web\FormData;

use Symfony\Component\Validator\Constraints\GreaterThanOrEqual;
use Symfony\Component\Validator\Constraints\NotNull;

final class DuplicateWeeklyMenuFormData
{
    #[NotNull]
    public null|\DateTimeImmutable $validFrom = null;

    #[NotNull]
    #[GreaterThanOrEqual(propertyPath: 'validFrom')]
    public null|\DateTimeImmutable $validTo = null;
}
