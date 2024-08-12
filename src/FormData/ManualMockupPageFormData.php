<?php

declare(strict_types=1);

namespace WBoost\Web\FormData;

use Symfony\Component\HttpFoundation\File\UploadedFile;

final class ManualMockupPageFormData
{
    public null|string $name = null;

    /** @var array<UploadedFile|null> */
    public array $images = [];
}
