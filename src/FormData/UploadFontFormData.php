<?php

declare(strict_types=1);

namespace WBoost\Web\FormData;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;

final class UploadFontFormData
{
    #[Assert\NotNull(message: 'Vyberte soubor písma.')]
    #[Assert\File(maxSize: '10m')]
    public null|UploadedFile $file = null;
}
