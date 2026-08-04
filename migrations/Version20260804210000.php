<?php

declare(strict_types=1);

namespace WBoost\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Templates merge, final step — drops the frozen social_network_* tables
 * (their rows were copied into the template stack by Version20260804090000;
 * nothing reads or writes them since, and the ORM mapping no longer knows
 * them) and renames the flyer/custom-era Doctrine-hash indexes to the names
 * Doctrine derives from the new table names, so a migrations-built schema
 * validates against the mapping (the CI migrations-up-to-date gate).
 *
 * Pre-merge backup:
 * lily:/root/db-backups/wboost-pre-templates-merge-20260804-212358.dump
 */
final class Version20260804210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop the frozen social_network_* tables + rename hash indexes to match the template* mapping.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE social_network_template_variant');
        $this->addSql('DROP TABLE social_network_template');
        $this->addSql('DROP TABLE social_network_category');

        $this->addSql('ALTER INDEX idx_ab554d3f166d1f9c RENAME TO IDX_591A29B2166D1F9C');
        $this->addSql('ALTER INDEX idx_72b2f8e7166d1f9c RENAME TO IDX_97601F83166D1F9C');
        $this->addSql('ALTER INDEX idx_72b2f8e712469de2 RENAME TO IDX_97601F8312469DE2');
        $this->addSql('ALTER INDEX idx_8f9f07c75da0fb8 RENAME TO IDX_A2FBC4945DA0FB8');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'The social tables are gone for good — restore the pre-merge backup if you ever need them.',
        );
    }
}
