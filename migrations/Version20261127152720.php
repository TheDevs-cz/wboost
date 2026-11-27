<?php

declare(strict_types=1);

namespace WBoost\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Additive: the social post history.
 */
final class Version20261127152720 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'social_post: posts published to a project social account (Publikované příspěvky)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE social_post (id UUID NOT NULL, template_id UUID DEFAULT NULL, template_name VARCHAR(255) NOT NULL, platform VARCHAR(255) NOT NULL, kind VARCHAR(32) NOT NULL, account_id VARCHAR(255) NOT NULL, account_name VARCHAR(255) NOT NULL, external_id VARCHAR(255) NOT NULL, permalink TEXT DEFAULT NULL, caption TEXT NOT NULL, published_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, fill_values_hash VARCHAR(64) DEFAULT NULL, response JSON NOT NULL, project_id UUID NOT NULL, template_variant_id UUID DEFAULT NULL, published_by_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_159BBFE9166D1F9C ON social_post (project_id)');
        $this->addSql('CREATE INDEX IDX_159BBFE919A2EC60 ON social_post (template_variant_id)');
        $this->addSql('CREATE INDEX IDX_159BBFE95B075477 ON social_post (published_by_id)');
        $this->addSql('CREATE INDEX idx_social_post_project_published ON social_post (project_id, published_at)');
        $this->addSql('CREATE INDEX idx_social_post_variant_hash ON social_post (template_variant_id, fill_values_hash)');
        $this->addSql('ALTER TABLE social_post ADD CONSTRAINT FK_159BBFE9166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE social_post ADD CONSTRAINT FK_159BBFE919A2EC60 FOREIGN KEY (template_variant_id) REFERENCES template_variant (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE social_post ADD CONSTRAINT FK_159BBFE95B075477 FOREIGN KEY (published_by_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE social_post DROP CONSTRAINT FK_159BBFE9166D1F9C');
        $this->addSql('ALTER TABLE social_post DROP CONSTRAINT FK_159BBFE919A2EC60');
        $this->addSql('ALTER TABLE social_post DROP CONSTRAINT FK_159BBFE95B075477');
        $this->addSql('DROP TABLE social_post');
    }
}
