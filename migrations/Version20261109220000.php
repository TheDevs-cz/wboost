<?php

declare(strict_types=1);

namespace WBoost\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Manual content pages P4: drop the legacy `manual.intro_image` column. The
 * intro picture lives in the intro content page since P1 (gallery-backed
 * since P3); before the drop every non-empty value on prod was verified to
 * be referenced by its manual's intro page image element (18/18, by path and
 * by gallery row).
 *
 * down() re-adds the column and backfills it from the first image element
 * (segment order, then element order) of each manual's intro page.
 */
final class Version20261109220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop legacy manual.intro_image (content pages P4)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE manual DROP intro_image');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE manual ADD intro_image VARCHAR(255) DEFAULT NULL');
        $this->addSql(<<<'SQL'
            UPDATE manual m
            SET intro_image = first_image.path
            FROM (
                SELECT DISTINCT ON (cp.manual_id) cp.manual_id, el.value->>'path' AS path
                FROM manual_content_page cp
                CROSS JOIN LATERAL jsonb_array_elements(
                    CASE WHEN jsonb_typeof(cp.segments) = 'array' THEN cp.segments ELSE '[]'::jsonb END
                ) WITH ORDINALITY AS seg(value, seg_index)
                CROSS JOIN LATERAL jsonb_array_elements(
                    CASE WHEN jsonb_typeof(seg.value->'elements') = 'array' THEN seg.value->'elements' ELSE '[]'::jsonb END
                ) WITH ORDINALITY AS el(value, el_index)
                WHERE cp.role = 'intro'
                  AND el.value->>'type' = 'image'
                  AND COALESCE(el.value->>'path', '') <> ''
                ORDER BY cp.manual_id, seg.seg_index, el.el_index
            ) AS first_image
            WHERE m.id = first_image.manual_id
            SQL);
    }
}
