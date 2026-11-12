<?php

declare(strict_types=1);

namespace WBoost\Web\Services\AiScan\Template;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use WBoost\Web\Repository\FileUploadRepository;
use WBoost\Web\Value\FileSource;

/**
 * Pictures uploaded FOR a proposal (`pictures.uploads`) go to the gallery's
 * Koš when the proposal is thrown away or expires (ux.md S4 "Zahodit", §7).
 * The bin keeps them for its usual retention, so nothing is lost at once.
 * Pictures picked from the gallery were there before and stay.
 */
final readonly class ScanUploads
{
    public function __construct(
        private FileUploadRepository $files,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Marks the files trashed on the managed entities; the caller's unit of
     * work flushes.
     *
     * @param null|array<array-key, mixed> $pictures `ai_scan.pictures`
     */
    public function trash(null|array $pictures, string $projectId): int
    {
        $trashed = 0;

        foreach (is_array($pictures['uploads'] ?? null) ? $pictures['uploads'] : [] as $fileId) {
            if (!is_string($fileId) || !Uuid::isValid($fileId)) {
                continue;
            }

            $file = $this->files->find(Uuid::fromString($fileId));

            if ($file === null || $file->isTrashed() || $file->source !== FileSource::ProjectImage || $file->project->id->toString() !== $projectId) {
                continue;
            }

            $file->moveToTrash($this->clock->now());
            $trashed++;
        }

        return $trashed;
    }
}
