<?php

declare(strict_types=1);

namespace WBoost\Web\FormData;

use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\NotBlank;

final class RequestAccessFormData
{
    #[NotBlank]
    #[Email]
    public string $email = '';
}
