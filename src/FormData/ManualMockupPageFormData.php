<?php

declare(strict_types=1);

namespace WBoost\Web\FormData;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use WBoost\Web\Value\MockupPageLayout;

final class ManualMockupPageFormData
{
    public null|string $name = null;

    public null|MockupPageLayout $layout = null;

    /**
     * Always holds an input for every possible slot so the layout can be
     * switched client-side without losing already picked files. Controllers
     * slice the list to the chosen layout's slot count before dispatching.
     *
     * @var array<UploadedFile|null>
     */
    public array $images;

    /**
     * '1' flags a slot whose existing image should be removed (edit only).
     *
     * @var array<string>
     */
    public array $removeImages;

    public function __construct()
    {
        $this->images = array_fill(0, MockupPageLayout::maxUploadInputsCount(), null);
        $this->removeImages = array_fill(0, MockupPageLayout::maxUploadInputsCount(), '0');
    }
}
