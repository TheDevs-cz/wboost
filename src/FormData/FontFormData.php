<?php

declare(strict_types=1);

namespace WBoost\Web\FormData;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;

final class FontFormData
{
    /** @var list<UploadedFile> */
    #[Assert\Count(min: 1, minMessage: 'Vyberte alespoň jeden soubor písma.')]
    #[Assert\All([new Assert\File(maxSize: '10m')])]
    public array $fonts = [];
}
