<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use WBoost\Web\Services\UploaderHelper;

final class UploadTwigExtension extends AbstractExtension
{
    public function __construct(
        readonly private UploaderHelper $uploaderHelper,
    ) {
    }

    /**
     * @return array<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('uploaded_asset', [$this->uploaderHelper, 'getPublicPath']),
        ];
    }
}
