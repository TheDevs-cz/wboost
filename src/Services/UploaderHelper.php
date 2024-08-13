<?php

declare(strict_types=1);

namespace WBoost\Web\Services;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

readonly final class UploaderHelper
{
    public function __construct(
        #[Autowire('%publicAssetsBaseUrl%')]
        private string $publicAssetsBaseUrl,
    ) {
    }


    public function getPublicPath(string $path): string
    {
        return $this->publicAssetsBaseUrl . '/' . $path;
    }
}
