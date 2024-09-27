<?php

declare(strict_types=1);

namespace WBoost\Web\FormData;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

final class SocialNetworkTemplateFormData
{
    #[NotBlank(normalizer: 'trim')]
    #[Length(min: 3, max: 30)]
    public string $name = '';
    public null|UploadedFile $image = null;
    public null|string $category = null;
}
