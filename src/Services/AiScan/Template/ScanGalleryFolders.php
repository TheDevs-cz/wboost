<?php

declare(strict_types=1);

namespace WBoost\Web\Services\AiScan\Template;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use WBoost\Web\Entity\AiScan;
use WBoost\Web\Entity\FileDirectory;
use WBoost\Web\Entity\Project;
use WBoost\Web\Repository\FileDirectoryRepository;
use WBoost\Web\Value\FileSource;

/**
 * Where pictures uploaded for a proposal live in the gallery: the folder
 * `AI sken / {název}` (ux.md T4). Both folders are found by
 * `FileDirectory::$systemKey`, never by name — the user may rename them.
 * Ordinary gallery folders in every other respect.
 */
final readonly class ScanGalleryFolders
{
    public const string ROOT_KEY = 'ai-scan';
    public const string ROOT_NAME = 'AI sken';

    public function __construct(
        private FileDirectoryRepository $directories,
        private ClockInterface $clock,
    ) {
    }

    public static function scanKey(AiScan $scan): string
    {
        return 'ai-scan:' . $scan->id->toString();
    }

    public function find(AiScan $scan): null|FileDirectory
    {
        return $this->directories->findBySystemKey($scan->project->id, FileSource::ProjectImage, self::scanKey($scan));
    }

    public function folderFor(AiScan $scan, string $name): FileDirectory
    {
        $existing = $this->find($scan);

        if ($existing !== null) {
            return $existing;
        }

        $root = $this->rootFor($scan->project);
        $folder = new FileDirectory(Uuid::uuid7(), $scan->project, FileSource::ProjectImage, $this->uniqueName($scan->project, $root, $name), $root, $this->clock->now());
        $folder->systemKey = self::scanKey($scan);
        $this->directories->add($folder);

        return $folder;
    }

    /** The folder takes the template's final name once the template exists. */
    public function rename(AiScan $scan, string $name): void
    {
        $folder = $this->find($scan);

        if ($folder === null || $folder->parent === null || mb_strtolower($folder->name) === mb_strtolower(trim($name))) {
            return;
        }

        $folder->rename($this->uniqueName($scan->project, $folder->parent, $name));
    }

    private function rootFor(Project $project): FileDirectory
    {
        $root = $this->directories->findBySystemKey($project->id, FileSource::ProjectImage, self::ROOT_KEY);

        if ($root !== null) {
            return $root;
        }

        $root = new FileDirectory(Uuid::uuid7(), $project, FileSource::ProjectImage, self::ROOT_NAME, null, $this->clock->now());
        $root->systemKey = self::ROOT_KEY;
        $this->directories->add($root);

        return $root;
    }

    private function uniqueName(Project $project, FileDirectory $parent, string $name): string
    {
        $name = trim($name) !== '' ? mb_substr(trim($name), 0, 200) : 'Sken bez názvu';
        $taken = [];

        foreach ($this->directories->listChildren($project->id, FileSource::ProjectImage, $parent) as $sibling) {
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
