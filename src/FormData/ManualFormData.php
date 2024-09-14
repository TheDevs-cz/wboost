<?php

declare(strict_types=1);

namespace WBoost\Web\FormData;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use WBoost\Web\Value\ManualType;

final class ManualFormData
{
    #[NotBlank(normalizer: 'trim')]
    #[Length(min: 3, max: 30)]
    public string $name = '';
    public null|ManualType $type = null;
    public null|UploadedFile $introImage = null;
}
