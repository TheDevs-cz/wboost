<?php

declare(strict_types=1);

namespace WBoost\Web\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use WBoost\Web\Entity\FileUpload;
use WBoost\Web\Value\GalleryImageManualSite;

/**
 * Which manuals still show a gallery image — the manual half of the purge
 * guard (content pages P3). A manual references a picture by its storage
 * KEY (mockup `images`, content image `path`) and a
 * content image element also by `galleryImageId`; both are matched exactly.
 *
 * Deliberately NOT scoped to the file's project: the guard protects a
 * storage object, and the same key is reachable from any row that names it.
 */
readonly final class GetGalleryImageManualUsage
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param list<FileUpload> $files
     * @return array<string, list<GalleryImageManualSite>> sites keyed by file id; unused files are absent
     */
    public function forFiles(array $files): array
    {
        if ($files === []) {
            return [];
        }

        $paths = array_values(array_unique(array_map(static fn (FileUpload $file): string => $file->path, $files)));
        $ids = array_values(array_unique(array_map(static fn (FileUpload $file): string => $file->id->toString(), $files)));

        /** @var list<array{manual_id: string, manual_name: string, ref_path: null|string, ref_id: null|string}> $rows */
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            <<<'SQL'
                SELECT m.id AS manual_id, m.name AS manual_name, img.value #>> '{}' AS ref_path, NULL AS ref_id
                FROM manual_mockup_page mp
                JOIN manual m ON m.id = mp.manual_id
                CROSS JOIN LATERAL json_array_elements(
                    CASE WHEN json_typeof(mp.images) = 'array' THEN mp.images ELSE '[]'::json END
                ) AS img(value)
                WHERE json_typeof(img.value) = 'string' AND img.value #>> '{}' IN (:paths)

                UNION ALL

                SELECT m.id, m.name, el.value->>'path', el.value->>'galleryImageId'
                FROM manual_content_page cp
                JOIN manual m ON m.id = cp.manual_id
                CROSS JOIN LATERAL jsonb_array_elements(
                    CASE WHEN jsonb_typeof(cp.segments) = 'array' THEN cp.segments ELSE '[]'::jsonb END
                ) AS seg(value)
                CROSS JOIN LATERAL jsonb_array_elements(
                    CASE WHEN jsonb_typeof(seg.value->'elements') = 'array' THEN seg.value->'elements' ELSE '[]'::jsonb END
                ) AS el(value)
                WHERE el.value->>'type' = 'image'
                  AND (el.value->>'path' IN (:paths) OR el.value->>'galleryImageId' IN (:ids))
                SQL,
            ['paths' => $paths, 'ids' => $ids],
            ['paths' => ArrayParameterType::STRING, 'ids' => ArrayParameterType::STRING],
        );

        return self::compute($files, $rows);
    }

    /**
     * @return list<GalleryImageManualSite>
     */
    public function forFile(FileUpload $file): array
    {
        return $this->forFiles([$file])[$file->id->toString()] ?? [];
    }

    /**
     * Pure core: one site per manual, ordered by manual name.
     *
     * @param list<FileUpload> $files
     * @param list<array{manual_id: string, manual_name: string, ref_path: null|string, ref_id: null|string}> $rows
     * @return array<string, list<GalleryImageManualSite>>
     */
    public static function compute(array $files, array $rows): array
    {
        $usage = [];

        foreach ($files as $file) {
            $fileId = $file->id->toString();
            $sites = [];

            foreach ($rows as $row) {
                $matches = $row['ref_path'] === $file->path
                    || ($row['ref_id'] !== null && Uuid::isValid($row['ref_id']) && strtolower($row['ref_id']) === strtolower($fileId));

                if ($matches && !isset($sites[$row['manual_id']])) {
                    $sites[$row['manual_id']] = new GalleryImageManualSite($row['manual_id'], $row['manual_name']);
                }
            }

            if ($sites !== []) {
                usort($sites, static fn (GalleryImageManualSite $a, GalleryImageManualSite $b): int => strcmp($a->manualName, $b->manualName));
                $usage[$fileId] = $sites;
            }
        }

        return $usage;
    }
}
