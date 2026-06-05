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

    /**
     * Inverse of {@see getPublicPath()}: recover the storage path from a public
     * URL this helper produced, or null when the URL points elsewhere (an
     * external image, an already-inlined data: URI, etc.). Used by the renderer
     * to inline canvas images by storage path without reaching Minio.
     */
    public function getPathFromPublicUrl(string $url): null|string
    {
        $prefix = $this->publicAssetsBaseUrl . '/';

        if (!str_starts_with($url, $prefix)) {
            return null;
        }

        $path = substr($url, strlen($prefix));

        return $path !== '' ? $path : null;
    }
}
