<?php

declare(strict_types=1);

namespace WBoost\Web\Services\SocialNetwork;

use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\FileDirectory;
use WBoost\Web\Repository\FileDirectoryRepository;
use WBoost\Web\Value\EditorImageInput;
use WBoost\Web\Value\FileSource;

/**
 * Single source of truth for "which gallery folders may an image placeholder be
 * filled from". An empty {@see EditorImageInput::$allowedDirectoryIds} means the
 * designer left the slot UNRESTRICTED → every project-gallery folder in the
 * project is offered (the admin editor warns the designer of this when they
 * leave it empty). A non-empty list is intersected with the project's real
 * folders, so a folder deleted after the designer picked it simply drops out.
 *
 * Every fill path (web pick list, API gallery list, upload target, render-time
 * validation) resolves through here so they can never disagree about what is
 * allowed.
 */
readonly final class PlaceholderAllowedDirectories
{
    public function __construct(
        private FileDirectoryRepository $fileDirectoryRepository,
    ) {
    }

    /**
     * @return list<FileDirectory>
     */
    public function resolve(EditorImageInput $input, UuidInterface $projectId): array
    {
        $all = $this->fileDirectoryRepository->listAll($projectId, FileSource::ProjectImage);
        $effective = self::effectiveIds(
            array_map(static fn (FileDirectory $directory): string => $directory->id->toString(), $all),
            $input->allowedDirectoryIds,
        );

        return array_values(array_filter(
            $all,
            static fn (FileDirectory $directory): bool => in_array($directory->id->toString(), $effective, true),
        ));
    }

    /**
     * @return list<string>
     */
    public function resolveIds(EditorImageInput $input, UuidInterface $projectId): array
    {
        $all = $this->fileDirectoryRepository->listAll($projectId, FileSource::ProjectImage);

        return self::effectiveIds(
            array_map(static fn (FileDirectory $directory): string => $directory->id->toString(), $all),
            $input->allowedDirectoryIds,
        );
    }

    /**
     * The rule, isolated and pure: an empty allow-list expands to EVERY project
     * folder (unrestricted); otherwise it is intersected with the project's real
     * folders (order preserved), dropping ids that no longer exist.
     *
     * @param list<string> $allProjectFolderIds
     * @param list<string> $allowedDirectoryIds
     * @return list<string>
     */
    public static function effectiveIds(array $allProjectFolderIds, array $allowedDirectoryIds): array
    {
        if ($allowedDirectoryIds === []) {
            return $allProjectFolderIds;
        }

        return array_values(array_filter(
            $allProjectFolderIds,
            static fn (string $id): bool => in_array($id, $allowedDirectoryIds, true),
        ));
    }
}
