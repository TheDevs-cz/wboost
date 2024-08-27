<?php

declare(strict_types=1);

namespace WBoost\Web\FormData;

use Symfony\Component\HttpFoundation\File\UploadedFile;

final class UploadProjectFileFormData
{
    public null|UploadedFile $file = null;
}
