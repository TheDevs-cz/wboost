<?php

declare(strict_types=1);

namespace WBoost\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use WBoost\Web\Services\Manual\IntroContentPageMigration;

/**
 * DATA (P1): every manual with an `intro_image` gets its intro content page
 * — layout-7, one segment: the description override as rich text (none on
 * prod 2026-11-09), the picture at its natural size; the heading (override
 * or "Úvod") becomes the page name, printed where the old title stood. The
 * plan lives in IntroContentPageMigration (unit-tested). Manuals without an
 * intro image get nothing ("no content → no page"; the editor creates the
 * page on first save). Idempotent: a manual that already has an intro page
 * is skipped. `intro_image` is kept until P4.
 */
final class Version20261109140100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Manual content pages: intro page for every manual with an intro image.';
    }

    public function up(Schema $schema): void
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT m.id, m.intro_image, m.page_texts::text AS page_texts
             FROM manual m
             WHERE m.intro_image IS NOT NULL AND m.intro_image <> ''
               AND NOT EXISTS (SELECT 1 FROM manual_content_page p WHERE p.manual_id = m.id AND p.role = 'intro')
             ORDER BY m.created_at",
        );

        foreach ($rows as $row) {
            $insert = IntroContentPageMigration::plan(
                (string) $row['id'],
                (string) $row['intro_image'],
                is_string($row['page_texts']) ? $row['page_texts'] : null,
            );

            $this->addSql(
                "INSERT INTO manual_content_page (id, manual_id, role, name, layout, segments, position, created_at, updated_at)
                 VALUES (:id, :manualId, 'intro', :name, :layout, CAST(:segments AS jsonb), 0, NOW(), NOW())
                 ON CONFLICT DO NOTHING",
                $insert,
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM manual_content_page WHERE role = 'intro'");
    }
}
