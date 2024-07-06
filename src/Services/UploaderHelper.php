<?php

declare(strict_types=1);

namespace BrandManuals\Web\Services;

readonly final class UploaderHelper
{
    public function __construct(
        private string $uploadedAssetsBaseUrl,
    ) {
    }


    public function getPublicPath(string $path): string
    {
        return $this->uploadedAssetsBaseUrl . '/' . $path;
    }
}
