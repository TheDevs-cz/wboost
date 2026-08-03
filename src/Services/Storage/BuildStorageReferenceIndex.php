<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Storage;

use Doctrine\ORM\EntityManagerInterface;
use WBoost\Web\Value\StorageOwner;
use WBoost\Web\Value\StorageReference;
use WBoost\Web\Value\StorageReferenceIndex;

/**
 * Builds the "which storage keys are still referenced by the database" index
 * the storage scan diffs the bucket against.
 *
 * Every place the app persists a path is enumerated here — plain string columns
 * (`manual.intro_image`, `*.background_image`, `*.preview_image_path`, …), JSON
 * documents holding paths (`manual.logo`, `manual_mockup_page.images`,
 * `font.faces`) and the canvas JSONB, which embeds gallery images as full
 * public URLs. **A path missing from this list makes a live file look like an
 * orphan**, so any new writer must be added here as well.
 *
 * Raw DBAL on purpose: it must see rows the ORM would filter or fail to hydrate,
 * and it has to stay cheap enough to run over the whole database in one pass.
 */
readonly final class BuildStorageReferenceIndex
{
    /**
     * Storage-key namespaces the app writes. Used to pull paths back out of the
     * canvas JSON, where they only ever appear embedded in a full public URL
     * (which differs per environment, so the prefix is the stable anchor).
     */
    private const string CANVAS_PATH_PATTERN = '((?:file-upload|manuals|social-networks|custom-templates|emails)/[^"]+)';

    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function build(): StorageReferenceIndex
    {
        $references = [];
        $counts = [];

        foreach ($this->queries() as $referencedBy => $sql) {
            foreach ($this->entityManager->getConnection()->iterateAssociative($sql) as $row) {
                $path = $this->normalisePath($row['path'] ?? null);

                if ($path === null) {
                    continue;
                }

                $counts[$path] = ($counts[$path] ?? 0) + 1;

                // First reference wins as the displayed label; the count is
                // what tells the admin a key is shared (a copied variant reuses
                // its source's background image rather than duplicating bytes).
                if (isset($references[$path])) {
                    continue;
                }

                $references[$path] = new StorageReference($referencedBy, new StorageOwner(
                    $this->asNullableString($row['project_id'] ?? null),
                    $this->asNullableString($row['project_name'] ?? null),
                    $this->asNullableString($row['owner_id'] ?? null),
                    $this->asNullableString($row['owner_email'] ?? null),
                ));
            }
        }

        return new StorageReferenceIndex($references, $counts);
    }

    /**
     * Every reference source, keyed by the `table.column` label the report
     * shows. Each query must select `path` plus the project / owner columns.
     *
     * @return array<string, string>
     */
    private function queries(): array
    {
        $ownerJoin = 'JOIN project p ON p.id = %s JOIN "user" u ON u.id = p.owner_id';
        $ownerColumns = 'p.id AS project_id, p.name AS project_name, u.id AS owner_id, u.email AS owner_email';

        return [
            'file_upload.path' => sprintf(
                'SELECT f.path AS path, %s FROM file_upload f %s',
                $ownerColumns,
                sprintf($ownerJoin, 'f.project_id'),
            ),

            'manual.intro_image' => sprintf(
                "SELECT m.intro_image AS path, %s FROM manual m %s WHERE m.intro_image IS NOT NULL AND m.intro_image <> ''",
                $ownerColumns,
                sprintf($ownerJoin, 'm.project_id'),
            ),

            // manual.logo is a JSONB map of 5 slots, each `{filePath, detectedColors}` or null.
            'manual.logo' => sprintf(
                "SELECT slot.value->>'filePath' AS path, %s
                 FROM manual m
                 %s
                 CROSS JOIN LATERAL jsonb_each(m.logo) AS slot(key, value)
                 WHERE jsonb_typeof(slot.value) = 'object' AND slot.value->>'filePath' IS NOT NULL",
                $ownerColumns,
                sprintf($ownerJoin, 'm.project_id'),
            ),

            // manual_mockup_page.images is a JSON array of bare path strings.
            'manual_mockup_page.images' => sprintf(
                "SELECT img.value #>> '{}' AS path, %s
                 FROM manual_mockup_page mp
                 JOIN manual m ON m.id = mp.manual_id
                 %s
                 CROSS JOIN LATERAL json_array_elements(
                     CASE WHEN json_typeof(mp.images) = 'array' THEN mp.images ELSE '[]'::json END
                 ) AS img(value)",
                $ownerColumns,
                sprintf($ownerJoin, 'm.project_id'),
            ),

            'custom_template.image' => sprintf(
                "SELECT t.image AS path, %s FROM custom_template t %s WHERE t.image IS NOT NULL AND t.image <> ''",
                $ownerColumns,
                sprintf($ownerJoin, 't.project_id'),
            ),

            'custom_template_variant.background_image' => sprintf(
                "SELECT v.background_image AS path, %s
                 FROM custom_template_variant v
                 JOIN custom_template t ON t.id = v.template_id
                 %s
                 WHERE v.background_image <> ''",
                $ownerColumns,
                sprintf($ownerJoin, 't.project_id'),
            ),

            'custom_template_variant.preview_image_path' => sprintf(
                "SELECT v.preview_image_path AS path, %s
                 FROM custom_template_variant v
                 JOIN custom_template t ON t.id = v.template_id
                 %s
                 WHERE v.preview_image_path IS NOT NULL AND v.preview_image_path <> ''",
                $ownerColumns,
                sprintf($ownerJoin, 't.project_id'),
            ),

            'custom_template_variant.canvas' => sprintf(
                "SELECT DISTINCT m[1] AS path, %s
                 FROM custom_template_variant v
                 JOIN custom_template t ON t.id = v.template_id
                 %s
                 CROSS JOIN LATERAL regexp_matches(v.canvas::text || v.image_inputs::text, '%s', 'g') AS m",
                $ownerColumns,
                sprintf($ownerJoin, 't.project_id'),
                self::CANVAS_PATH_PATTERN,
            ),

            'social_network_template.image' => sprintf(
                "SELECT t.image AS path, %s FROM social_network_template t %s WHERE t.image IS NOT NULL AND t.image <> ''",
                $ownerColumns,
                sprintf($ownerJoin, 't.project_id'),
            ),

            'social_network_template_variant.background_image' => sprintf(
                "SELECT v.background_image AS path, %s
                 FROM social_network_template_variant v
                 JOIN social_network_template t ON t.id = v.template_id
                 %s
                 WHERE v.background_image <> ''",
                $ownerColumns,
                sprintf($ownerJoin, 't.project_id'),
            ),

            'social_network_template_variant.preview_image_path' => sprintf(
                "SELECT v.preview_image_path AS path, %s
                 FROM social_network_template_variant v
                 JOIN social_network_template t ON t.id = v.template_id
                 %s
                 WHERE v.preview_image_path IS NOT NULL AND v.preview_image_path <> ''",
                $ownerColumns,
                sprintf($ownerJoin, 't.project_id'),
            ),

            'social_network_template_variant.canvas' => sprintf(
                "SELECT DISTINCT m[1] AS path, %s
                 FROM social_network_template_variant v
                 JOIN social_network_template t ON t.id = v.template_id
                 %s
                 CROSS JOIN LATERAL regexp_matches(v.canvas::text || v.image_inputs::text, '%s', 'g') AS m",
                $ownerColumns,
                sprintf($ownerJoin, 't.project_id'),
                self::CANVAS_PATH_PATTERN,
            ),

            'email_signature_template.background_image' => sprintf(
                "SELECT t.background_image AS path, %s
                 FROM email_signature_template t
                 %s
                 WHERE t.background_image IS NOT NULL AND t.background_image <> ''",
                $ownerColumns,
                sprintf($ownerJoin, 't.project_id'),
            ),

            // font.faces is a JSONB array of `{name, style, weight, filePath}`.
            'font.faces' => sprintf(
                "SELECT face.value->>'filePath' AS path, %s
                 FROM font f
                 %s
                 CROSS JOIN LATERAL jsonb_array_elements(
                     CASE WHEN jsonb_typeof(f.faces) = 'array' THEN f.faces ELSE '[]'::jsonb END
                 ) AS face(value)
                 WHERE face.value->>'filePath' IS NOT NULL",
                $ownerColumns,
                sprintf($ownerJoin, 'f.project_id'),
            ),
        ];
    }

    /**
     * Paths are stored bare in most columns but arrive URL-shaped out of the
     * canvas match, and historic rows may carry a leading slash.
     */
    private function normalisePath(mixed $value): null|string
    {
        if (!is_string($value)) {
            return null;
        }

        $path = ltrim(trim($value), '/');

        return $path === '' ? null : $path;
    }

    private function asNullableString(mixed $value): null|string
    {
        return is_string($value) ? $value : null;
    }
}
