<?php

declare(strict_types=1);

namespace WBoost\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Saved work in progress ("Uložit rozpracované") lives in the export history
 * as draft versions. Additive: every existing row is an export.
 */
final class Version20261012090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'template_export_version.draft — saved work-in-progress fills.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE template_export_version ADD draft BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE template_export_version DROP draft');
    }
}
