<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Manual;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\FileDirectory;
use WBoost\Web\Entity\Manual;
use WBoost\Web\Entity\Project;
use WBoost\Web\Repository\FileDirectoryRepository;
use WBoost\Web\Value\FileSource;

/**
 * The gallery folders a manual's pictures live in: one root folder
 * "Manuál" per project and a sub-folder per manual (named after the manual,
 * " (2)", " (3)"… when a sibling already carries that name). The gallery
 * supports nested folders, so this is a real `Manuál / <manual>` tree.
 *
 * Both are found by `FileDirectory::$systemKey`, never by name: the user may
 * rename them (or the manual may be renamed) without the app losing track.
 * A folder the user deleted (only possible while empty) is simply created
 * again the next time it is needed. An existing user folder literally named
 * "Manuál" at the root is ADOPTED as the root instead of creating a twin.
 *
 * Persists only — the caller's bus transaction flushes.
 */
readonly final class ManualGalleryFolders
{
    public const string ROOT_KEY = 'manual-root';
    public const string ROOT_NAME = 'Manuál';

    public function __construct(
        private FileDirectoryRepository $directoryRepository,
        private ClockInterface $clock,
    ) {
    }

    public static function manualKey(UuidInterface $manualId): string
    {
        return 'manual:' . $manualId->toString();
    }

    /** The manual's folder if it exists already (no write). */
    public function find(Manual $manual): null|FileDirectory
    {
        return $this->directoryRepository->findBySystemKey($manual->project->id, FileSource::ProjectImage, self::manualKey($manual->id));
    }

    public function folderFor(Manual $manual): FileDirectory
    {
        $existing = $this->find($manual);

        if ($existing !== null) {
            return $existing;
        }

        $root = $this->rootFor($manual->project);
        $folder = new FileDirectory(
            Uuid::uuid7(),
            $manual->project,
            FileSource::ProjectImage,
            $this->uniqueName($manual->project, $root, $manual->name),
            $root,
            $this->clock->now(),
        );
        $folder->systemKey = self::manualKey($manual->id);
        $this->directoryRepository->add($folder);

        return $folder;
    }

    private function rootFor(Project $project): FileDirectory
    {
        $root = $this->directoryRepository->findBySystemKey($project->id, FileSource::ProjectImage, self::ROOT_KEY);

        if ($root !== null) {
            return $root;
        }

        foreach ($this->directoryRepository->listChildren($project->id, FileSource::ProjectImage, null) as $candidate) {
            if ($candidate->systemKey === null && mb_strtolower(trim($candidate->name)) === mb_strtolower(self::ROOT_NAME)) {
                $candidate->systemKey = self::ROOT_KEY;

                return $candidate;
            }
        }

        $root = new FileDirectory(
            Uuid::uuid7(),
            $project,
            FileSource::ProjectImage,
            self::ROOT_NAME,
            null,
            $this->clock->now(),
        );
        $root->systemKey = self::ROOT_KEY;
        $this->directoryRepository->add($root);

        return $root;
    }

    private function uniqueName(Project $project, FileDirectory $parent, string $name): string
    {
        $name = trim($name) !== '' ? mb_substr(trim($name), 0, 200) : 'Manuál bez názvu';
        $taken = [];

        foreach ($this->directoryRepository->listChildren($project->id, FileSource::ProjectImage, $parent) as $sibling) {
            $taken[mb_strtolower($sibling->name)] = true;
        }

        $candidate = $name;
        $suffix = 2;

        while (isset($taken[mb_strtolower($candidate)])) {
            $candidate = sprintf('%s (%d)', $name, $suffix++);
        }

        return $candidate;
    }
}
