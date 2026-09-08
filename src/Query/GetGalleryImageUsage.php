<?php

declare(strict_types=1);

namespace WBoost\Web\Query;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\FileUpload;
use WBoost\Web\Value\GalleryImageUsageSite;

/**
 * Which templates still reference a gallery image — the guard behind the
 * trash bin's purge (a purged picture is gone for good: the editor loads a
 * red stand-in, the export renders nothing there; 2026-09-08).
 *
 * Nothing is stored: a canvas references a picture by its public URL, a
 * variant's `background_image` and a text input's list images by the
 * storage key, and a placeholder's `assetId` by the id — every one of those
 * spellings contains the file's UUID, so the scan is a substring match over
 * the variants' text of ONE project (a gallery image can never be referenced
 * from another project). Export versions are deliberately NOT a reference:
 * a purged pick degrades leniently when a version is re-loaded (the seeder
 * falls back to the designed stand-in), which is documented and fine.
 */
readonly final class GetGalleryImageUsage
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Sites keyed by file id, for the files of one project. Files nothing
     * references are absent from the result.
     *
     * @param list<FileUpload> $files
     * @return array<string, list<GalleryImageUsageSite>>
     */
    public function forFiles(UuidInterface $projectId, array $files): array
    {
        if ($files === []) {
            return [];
        }

        /** @var list<array{variant_id: string, template_id: string, template_name: string, haystack: string}> $rows */
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            <<<'SQL'
                SELECT tv.id AS variant_id,
                       t.id AS template_id,
                       t.name AS template_name,
                       COALESCE(tv.canvas::text, '') || ' ' || COALESCE(tv.background_image, '') || ' ' || COALESCE(tv.inputs::text, '') AS haystack
                FROM template_variant tv
                JOIN template t ON t.id = tv.template_id
                WHERE t.project_id = :projectId
                ORDER BY t.name, tv.id
                SQL,
            ['projectId' => $projectId->toString()],
        );

        return self::compute($files, $rows);
    }

    /**
     * @return list<GalleryImageUsageSite>
     */
    public function forFile(FileUpload $file): array
    {
        return $this->forFiles($file->project->id, [$file])[$file->id->toString()] ?? [];
    }

    /**
     * Pure core: one site per template (its first variant in row order),
     * keyed by file id.
     *
     * @param list<FileUpload> $files
     * @param list<array{variant_id: string, template_id: string, template_name: string, haystack: string}> $rows
     * @return array<string, list<GalleryImageUsageSite>>
     */
    public static function compute(array $files, array $rows): array
    {
        $usage = [];

        foreach ($files as $file) {
            $fileId = $file->id->toString();
            $sites = [];

            foreach ($rows as $row) {
                if (isset($sites[$row['template_id']]) || !str_contains($row['haystack'], $fileId)) {
                    continue;
                }

                $sites[$row['template_id']] = new GalleryImageUsageSite($row['template_id'], $row['template_name'], $row['variant_id']);
            }

            if ($sites !== []) {
                $usage[$fileId] = array_values($sites);
            }
        }

        return $usage;
    }
}
