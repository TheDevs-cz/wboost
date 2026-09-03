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
 * documents holding paths (`manual.logo`, `manual_mockup_page.images`, its
 * `download_file` / `image_downloads` attachments, `font.faces`) and the canvas
 * JSONB, which embeds gallery images as full public URLs. **A path missing
 * from this list makes a live file look like an orphan**, so any new writer
 * must be added here as well.
 *
 * Raw DBAL on purpose: it must see rows the ORM would filter or fail to hydrate,
 * and it has to stay cheap enough to run over the whole database in one pass.
 */
readonly final class BuildStorageReferenceIndex
{
    /**
     * Storage-key namespaces the app writes. `social-networks` stays even
     * though the social module is gone: canvases merged into the unified
     * template tables still embed `social-networks/…` URLs, and those files
     * live on under their original keys.
     */
    private const string PATH_PREFIXES = '(?:file-upload|manuals|social-networks|custom-templates|emails|projects)';

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
     * Pulls paths back out of the columns that EMBED them in a full public URL
     * instead of storing the bare key — the canvas JSON and the e-mail
     * signature HTML. The base URL differs per environment, so the namespace
     * prefix is the stable anchor.
     *
     * The delimiter class covers the JSON string terminator (`"`) AND the HTML
     * attribute terminators (`'`, `<`, `>`, `)`, whitespace): an `<img src>` or
     * a CSS `url(...)` in signature markup is not quoted like a JSON value.
     *
     * The single quote is doubled because the result is interpolated into a
     * single-quoted SQL literal — get that wrong and the quote terminates the
     * literal instead of joining the character class.
     */
    private function embeddedPathPattern(): string
    {
        return '(' . self::PATH_PREFIXES . "/[^\"''<>)[:space:]\\\\]+)";
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

            'project.icon' => sprintf(
                "SELECT pr.icon AS path, %s FROM project pr %s WHERE pr.icon IS NOT NULL AND pr.icon <> ''",
                $ownerColumns,
                sprintf($ownerJoin, 'pr.id'),
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

            // The files an admin attaches to a mockup page for download: one
            // JSONB object for the whole page, one nullable entry per image
            // slot. Both carry the key under `path`.
            'manual_mockup_page.download_file' => sprintf(
                "SELECT mp.download_file->>'path' AS path, %s
                 FROM manual_mockup_page mp
                 JOIN manual m ON m.id = mp.manual_id
                 %s
                 WHERE jsonb_typeof(mp.download_file) = 'object'
                   AND mp.download_file->>'path' IS NOT NULL",
                $ownerColumns,
                sprintf($ownerJoin, 'm.project_id'),
            ),

            'manual_mockup_page.image_downloads' => sprintf(
                "SELECT dl.value->>'path' AS path, %s
                 FROM manual_mockup_page mp
                 JOIN manual m ON m.id = mp.manual_id
                 %s
                 CROSS JOIN LATERAL jsonb_array_elements(
                     CASE WHEN jsonb_typeof(mp.image_downloads) = 'array' THEN mp.image_downloads ELSE '[]'::jsonb END
                 ) AS dl(value)
                 WHERE jsonb_typeof(dl.value) = 'object' AND dl.value->>'path' IS NOT NULL",
                $ownerColumns,
                sprintf($ownerJoin, 'm.project_id'),
            ),

            'template.image' => sprintf(
                "SELECT t.image AS path, %s FROM template t %s WHERE t.image IS NOT NULL AND t.image <> ''",
                $ownerColumns,
                sprintf($ownerJoin, 't.project_id'),
            ),

            'template_variant.background_image' => sprintf(
                "SELECT v.background_image AS path, %s
                 FROM template_variant v
                 JOIN template t ON t.id = v.template_id
                 %s
                 WHERE v.background_image <> ''",
                $ownerColumns,
                sprintf($ownerJoin, 't.project_id'),
            ),

            'template_variant.preview_image_path' => sprintf(
                "SELECT v.preview_image_path AS path, %s
                 FROM template_variant v
                 JOIN template t ON t.id = v.template_id
                 %s
                 WHERE v.preview_image_path IS NOT NULL AND v.preview_image_path <> ''",
                $ownerColumns,
                sprintf($ownerJoin, 't.project_id'),
            ),

            'template_variant.canvas' => sprintf(
                "SELECT DISTINCT m[1] AS path, %s
                 FROM template_variant v
                 JOIN template t ON t.id = v.template_id
                 %s
                 CROSS JOIN LATERAL regexp_matches(v.canvas::text || v.image_inputs::text, '%s', 'g') AS m",
                $ownerColumns,
                sprintf($ownerJoin, 't.project_id'),
                $this->embeddedPathPattern(),
            ),

            'email_signature_template.background_image' => sprintf(
                "SELECT t.background_image AS path, %s
                 FROM email_signature_template t
                 %s
                 WHERE t.background_image IS NOT NULL AND t.background_image <> ''",
                $ownerColumns,
                sprintf($ownerJoin, 't.project_id'),
            ),

            // The signature MARKUP embeds images as full public URLs — an
            // `<img src>` the designer pasted in, not a path column. Missing
            // these made a live signature background look like an orphan
            // (caught on prod by diffing the scan against a full pg_dump).
            'email_signature_template.code' => sprintf(
                "SELECT DISTINCT m[1] AS path, %s
                 FROM email_signature_template t
                 %s
                 CROSS JOIN LATERAL regexp_matches(t.code || t.text_inputs::text, '%s', 'g') AS m",
                $ownerColumns,
                sprintf($ownerJoin, 't.project_id'),
                $this->embeddedPathPattern(),
            ),

            'email_signature_variant.code' => sprintf(
                "SELECT DISTINCT m[1] AS path, %s
                 FROM email_signature_variant v
                 JOIN email_signature_template t ON t.id = v.template_id
                 %s
                 CROSS JOIN LATERAL regexp_matches(v.code || v.text_inputs::text, '%s', 'g') AS m",
                $ownerColumns,
                sprintf($ownerJoin, 't.project_id'),
                $this->embeddedPathPattern(),
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
