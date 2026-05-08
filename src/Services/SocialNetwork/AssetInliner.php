<?php

declare(strict_types=1);

namespace WBoost\Web\Services\SocialNetwork;

use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemReader;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Reads font and image binaries from the upload filesystem and produces base64
 * data URIs. Used by the Gotenberg renderer so the export HTML is fully
 * self-contained — Gotenberg's headless Chromium then needs no network access
 * to Minio (whose public URL is not reachable from inside the Gotenberg
 * container).
 */
readonly final class AssetInliner
{
    public function __construct(
        #[Autowire(service: 'oneup_flysystem.minio_filesystem')]
        private FilesystemReader $filesystem,
    ) {
    }

    public function inlineFont(string $path): null|string
    {
        return $this->inline($path, $this->fontMimeType($path));
    }

    public function inlineImage(string $path): null|string
    {
        return $this->inline($path, $this->imageMimeType($path));
    }

    private function inline(string $path, string $mimeType): null|string
    {
        try {
            $contents = $this->filesystem->read($path);
        } catch (FilesystemException) {
            return null;
        }

        return sprintf('data:%s;base64,%s', $mimeType, base64_encode($contents));
    }

    private function fontMimeType(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'woff2' => 'font/woff2',
            'woff' => 'font/woff',
            'ttf' => 'font/ttf',
            'otf' => 'font/otf',
            'eot' => 'application/vnd.ms-fontobject',
            default => 'application/octet-stream',
        };
    }

    private function imageMimeType(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            default => 'application/octet-stream',
        };
    }
}
