<?php

declare(strict_types=1);

namespace WBoost\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Export usage log records the format (PNG / print PDF). Additive: every
 * historical row was a PNG.
 */
final class Version20261030140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add export_event.format (png / pdf).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE export_event ADD format VARCHAR(8) DEFAULT \'png\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE export_event DROP format');
    }
}
