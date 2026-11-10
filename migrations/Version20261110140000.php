<?php

declare(strict_types=1);

namespace WBoost\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Manual content pages: optional whole-page background picture
 * (`{path, galleryImageId, fit}`, null = none). Additive, nullable.
 */
final class Version20261110140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Manual content pages: background picture';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE manual_content_page ADD background JSONB DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE manual_content_page DROP background');
    }
}
