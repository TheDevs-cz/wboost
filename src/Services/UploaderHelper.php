<?php

declare(strict_types=1);

namespace BrandManuals\Web\Services;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

readonly final class UploaderHelper
{
    public function __construct(
        #[Autowire('%uploadedAssetsBaseUrl%')]
        private string $uploadedAssetsBaseUrl,
    ) {
    }


    public function getPublicPath(string $path): string
    {
        return $this->uploadedAssetsBaseUrl . '/' . $path;
    }
}
