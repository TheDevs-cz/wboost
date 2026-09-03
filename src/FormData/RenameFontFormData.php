<?php

declare(strict_types=1);

namespace WBoost\Web\FormData;

use Symfony\Component\Validator\Constraints as Assert;

final class RenameFontFormData
{
    #[Assert\NotBlank(message: 'Zadejte název písma.')]
    #[Assert\Length(max: 120)]
    public null|string $name = null;
}
