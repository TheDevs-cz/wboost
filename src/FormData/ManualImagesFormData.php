<?php

declare(strict_types=1);

namespace WBoost\Web\FormData;

use Symfony\Component\HttpFoundation\File\UploadedFile;

final class ManualImagesFormData
{
    public null|UploadedFile $logoHorizontal = null;
    public null|UploadedFile $logoVertical = null;
    public null|UploadedFile $logoHorizontalWithClaim = null;
    public null|UploadedFile $logoVerticalWithClaim = null;
    public null|UploadedFile $logoSymbol = null;
}
