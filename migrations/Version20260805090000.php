<?php

declare(strict_types=1);

namespace WBoost\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Custom project icon — an uploaded image that takes precedence over the
 * brand logo / initials monogram on project cards, the dashboard header and
 * the side navigation.
 */
final class Version20260805090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add project.icon (nullable storage path of a custom project icon).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE project ADD icon VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE project DROP icon');
    }
}
