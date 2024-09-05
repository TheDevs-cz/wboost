<?php

declare(strict_types=1);

namespace WBoost\Web\FormData;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use WBoost\Web\Value\ManualType;

final class ManualFormData
{
    public string $name = '';
    public null|ManualType $type = null;
    public null|UploadedFile $introImage = null;
}
