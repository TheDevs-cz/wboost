<?php

declare(strict_types=1);

namespace WBoost\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Letáky (flyers): a free-form-dimension clone of the social network template
 * module. Variants carry a designer-chosen dimension (px / mm / cm, embedded
 * as dimension_*) instead of the fixed TemplateDimension enum.
 *
 * The project image gallery is also promoted from social-networks-only to
 * project-wide: the FileSource enum value 'social_network_image' becomes
 * 'project_image', so both modules share one folder tree + upload pool.
 */
final class Version20260610131650 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Flyer templates/variants/categories + project-wide gallery FileSource rename.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE flyer_category (id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, name VARCHAR(255) NOT NULL, position INT DEFAULT 0 NOT NULL, project_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_95B44248166D1F9C ON flyer_category (project_id)');
        $this->addSql('CREATE TABLE flyer_template (id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, name VARCHAR(255) NOT NULL, image VARCHAR(255) DEFAULT NULL, position INT DEFAULT 0 NOT NULL, project_id UUID NOT NULL, category_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_498440A166D1F9C ON flyer_template (project_id)');
        $this->addSql('CREATE INDEX IDX_498440A12469DE2 ON flyer_template (category_id)');
        $this->addSql('CREATE TABLE flyer_template_variant (canvas JSONB NOT NULL, preview_image_path VARCHAR(255) DEFAULT NULL, inputs JSONB NOT NULL, image_inputs JSONB NOT NULL, id UUID NOT NULL, background_image VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, dimension_unit VARCHAR(255) NOT NULL, dimension_unit_width DOUBLE PRECISION NOT NULL, dimension_unit_height DOUBLE PRECISION NOT NULL, template_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_A03A219C5DA0FB8 ON flyer_template_variant (template_id)');
        $this->addSql('ALTER TABLE flyer_category ADD CONSTRAINT FK_95B44248166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE flyer_template ADD CONSTRAINT FK_498440A166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE flyer_template ADD CONSTRAINT FK_498440A12469DE2 FOREIGN KEY (category_id) REFERENCES flyer_category (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE flyer_template_variant ADD CONSTRAINT FK_A03A219C5DA0FB8 FOREIGN KEY (template_id) REFERENCES flyer_template (id) ON DELETE CASCADE NOT DEFERRABLE');

        // Gallery becomes project-wide: rename the FileSource enum value.
        $this->addSql("UPDATE file_upload SET source = 'project_image' WHERE source = 'social_network_image'");
        $this->addSql("UPDATE file_directory SET source = 'project_image' WHERE source = 'social_network_image'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE file_upload SET source = 'social_network_image' WHERE source = 'project_image'");
        $this->addSql("UPDATE file_directory SET source = 'social_network_image' WHERE source = 'project_image'");

        $this->addSql('ALTER TABLE flyer_category DROP CONSTRAINT FK_95B44248166D1F9C');
        $this->addSql('ALTER TABLE flyer_template DROP CONSTRAINT FK_498440A166D1F9C');
        $this->addSql('ALTER TABLE flyer_template DROP CONSTRAINT FK_498440A12469DE2');
        $this->addSql('ALTER TABLE flyer_template_variant DROP CONSTRAINT FK_A03A219C5DA0FB8');
        $this->addSql('DROP TABLE flyer_category');
        $this->addSql('DROP TABLE flyer_template');
        $this->addSql('DROP TABLE flyer_template_variant');
    }
}
