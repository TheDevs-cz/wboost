<?php

declare(strict_types=1);

namespace WBoost\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260119100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add single_variant_mode column to weekly_menu_course table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE weekly_menu_course ADD single_variant_mode BOOLEAN NOT NULL DEFAULT false');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE weekly_menu_course DROP single_variant_mode');
    }
}
