<?php

declare(strict_types=1);

namespace WBoost\Web\FormData;


use Symfony\Component\Validator\Constraints\NotBlank;

final class SocialNetworkCategoryFormData
{
    #[NotBlank]
    public string $name = '';
}
