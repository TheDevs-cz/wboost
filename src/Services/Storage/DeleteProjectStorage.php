<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Storage;

use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use Psr\Log\LoggerInterface;
use WBoost\Web\Value\ProjectStoragePaths;

/**
 * Removes the storage a deleted project owned.
 *
 * Failures are logged and swallowed, never rethrown: the project row is already
 * gone by the time this runs, so aborting here would not undo anything — it
 * would only turn a leaked file into a failed request. A leftover object is
 * exactly the state the app was in before this existed, and the storage report
 * (`app:storage:scan`) is what surfaces it.
 */
readonly final class DeleteProjectStorage
{
    public function __construct(
        private Filesystem $filesystem,
        private LoggerInterface $logger,
    ) {
    }

    public function delete(ProjectStoragePaths $paths): void
    {
        foreach ($paths->directories as $directory) {
            try {
                // Flysystem treats a missing directory as already deleted, so
                // this is idempotent and safe for projects with no such assets.
                $this->filesystem->deleteDirectory($directory);
            } catch (FilesystemException $error) {
                $this->logger->error('Failed to delete project storage directory.', [
                    'directory' => $directory,
                    'exception' => $error,
                ]);
            }
        }

        foreach ($paths->files as $file) {
            try {
                $this->filesystem->delete($file);
            } catch (FilesystemException $error) {
                $this->logger->error('Failed to delete project storage file.', [
                    'file' => $file,
                    'exception' => $error,
                ]);
            }
        }
    }
}
