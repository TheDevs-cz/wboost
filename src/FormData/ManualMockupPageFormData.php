<?php

declare(strict_types=1);

namespace WBoost\Web\FormData;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use WBoost\Web\Value\MockupPageLayout;

final class ManualMockupPageFormData
{
    public null|string $name = null;

    /** @var array<UploadedFile|null> */
    public array $images;

    public function __construct(
        null|MockupPageLayout $layout,
    ) {
        $this->images = array_fill(0, $layout?->getUploadInputsCount() ?? 0, null);
    }
}
