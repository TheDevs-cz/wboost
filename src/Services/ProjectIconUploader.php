<?php

declare(strict_types=1);

namespace WBoost\Web\Services;

use League\Flysystem\Filesystem;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\UuidInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Writes custom project icons under `projects/{projectId}/…` — a namespace
 * registered in BuildStorageReferenceIndex, CollectProjectStoragePaths and
 * StorageCategory; keep the four in sync when touching the key layout.
 */
readonly final class ProjectIconUploader
{
    public function __construct(
        private Filesystem $filesystem,
        private ClockInterface $clock,
    ) {
    }

    public function upload(UuidInterface $projectId, UploadedFile $icon): string
    {
        $timestamp = $this->clock->now()->getTimestamp();
        $extension = $icon->guessExtension();
        $path = "projects/$projectId/icon-$timestamp.$extension";

        $this->filesystem->write($path, $icon->getContent());

        return $path;
    }

    /**
     * An icon is only ever referenced by its own project.icon column, so the
     * replaced file can be removed instead of abandoned as an orphan. Failures
     * are swallowed — the column no longer points at the file either way.
     */
    public function delete(null|string $path): void
    {
        if ($path === null) {
            return;
        }

        try {
            $this->filesystem->delete($path);
        } catch (\Throwable) {
        }
    }
}
