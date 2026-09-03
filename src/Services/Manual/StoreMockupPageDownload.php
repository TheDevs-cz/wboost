<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Manual;

use League\Flysystem\Filesystem;
use Ramsey\Uuid\UuidInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use WBoost\Web\Value\MockupPageDownload;

/**
 * Writes a mockup page's downloadable attachment to storage.
 *
 * The key stays inside `manuals/{manualId}/pages/{pageId}/` — the namespace
 * DeleteProjectStorage already sweeps and ResolveStorageOwnerByPath already
 * attributes — under a `files/` sub-prefix that keeps the attachments apart
 * from the page's images. Like every other re-upload here the key is
 * timestamped rather than overwritten.
 */
readonly final class StoreMockupPageDownload
{
    public function __construct(
        private Filesystem $filesystem,
    ) {
    }

    public function __invoke(
        UploadedFile $file,
        UuidInterface $manualId,
        UuidInterface $pageId,
        string $slot,
        int $timestamp,
    ): MockupPageDownload {
        $path = sprintf(
            'manuals/%s/pages/%s/files/%s-%d.%s',
            $manualId,
            $pageId,
            $slot,
            $timestamp,
            self::extension($file),
        );

        $this->filesystem->write($path, $file->getContent());

        return MockupPageDownload::fromUpload($file, $path);
    }

    /**
     * The client's extension, not `guessExtension()`: an .ai file is a PDF by
     * content and would be stored as one, hiding what it actually is from the
     * storage inventory. Client-controlled, so it is narrowed to a plain
     * alphanumeric suffix before it can reach a key.
     */
    private static function extension(UploadedFile $file): string
    {
        $extension = strtolower($file->getClientOriginalExtension());

        if (preg_match('/^[a-z0-9]{1,10}$/', $extension) === 1) {
            return $extension;
        }

        return $file->guessExtension() ?? 'bin';
    }
}
