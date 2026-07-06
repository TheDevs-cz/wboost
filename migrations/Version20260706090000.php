<?php

declare(strict_types=1);

namespace WBoost\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Template groups: one design fanned out to multiple dimensions across both
 * template modules. Membership is a nullable FK on templates + variants with
 * ON DELETE SET NULL, so dropping a group only un-groups its members.
 */
final class Version20260706090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Template groups: group table + nullable group FK on both template modules.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE template_group (
              id UUID NOT NULL,
              project_id UUID NOT NULL,
              created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
              name VARCHAR(255) NOT NULL,
              PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_template_group_project ON template_group (project_id)');
        $this->addSql('ALTER TABLE template_group ADD CONSTRAINT fk_template_group_project FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        foreach (['social_network_template', 'social_network_template_variant', 'custom_template', 'custom_template_variant'] as $table) {
            $this->addSql(sprintf('ALTER TABLE %s ADD group_id UUID DEFAULT NULL', $table));
            $this->addSql(sprintf('CREATE INDEX idx_%s_group ON %s (group_id)', $table, $table));
            $this->addSql(sprintf('ALTER TABLE %s ADD CONSTRAINT fk_%s_group FOREIGN KEY (group_id) REFERENCES template_group (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE', $table, $table));
        }
    }

    public function down(Schema $schema): void
    {
        foreach (['social_network_template', 'social_network_template_variant', 'custom_template', 'custom_template_variant'] as $table) {
            $this->addSql(sprintf('ALTER TABLE %s DROP CONSTRAINT fk_%s_group', $table, $table));
            $this->addSql(sprintf('ALTER TABLE %s DROP group_id', $table));
        }

        $this->addSql('DROP TABLE template_group');
    }
}
