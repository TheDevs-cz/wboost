<?php

declare(strict_types=1);

namespace WBoost\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Multi-page documents: the document, its ordered pages (a template + the
 * variant of the document's size; UNIQUE template = a page of at most one
 * document) and its shared fields. Purely additive, plus widening
 * export_event.format for the 'pdf-screen' export.
 */
final class Version20261030145218 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add template_document / _page / _field; widen export_event.format to 16.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE template_document (id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, name VARCHAR(255) NOT NULL, position INT DEFAULT 0 NOT NULL, dimension_unit VARCHAR(255) NOT NULL, dimension_unit_width DOUBLE PRECISION NOT NULL, dimension_unit_height DOUBLE PRECISION NOT NULL, dimension_preset VARCHAR(255) DEFAULT NULL, dimension_bleed_mm DOUBLE PRECISION DEFAULT 0 NOT NULL, dimension_crop_marks BOOLEAN DEFAULT false NOT NULL, project_id UUID NOT NULL, category_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_873FBA0512469DE2 ON template_document (category_id)');
        $this->addSql('CREATE INDEX idx_template_document_project ON template_document (project_id)');
        $this->addSql('CREATE TABLE template_document_field (bindings JSONB DEFAULT \'[]\' NOT NULL, id UUID NOT NULL, name VARCHAR(255) NOT NULL, kind VARCHAR(8) NOT NULL, position INT NOT NULL, document_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_9745CD30C33F7837 ON template_document_field (document_id)');
        $this->addSql('CREATE TABLE template_document_page (id UUID NOT NULL, position INT NOT NULL, document_id UUID NOT NULL, template_id UUID NOT NULL, variant_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_A8F54B945DA0FB8 ON template_document_page (template_id)');
        $this->addSql('CREATE INDEX IDX_A8F54B943B69A9AF ON template_document_page (variant_id)');
        $this->addSql('CREATE INDEX idx_template_document_page_document ON template_document_page (document_id)');
        $this->addSql('ALTER TABLE template_document ADD CONSTRAINT FK_873FBA05166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE template_document ADD CONSTRAINT FK_873FBA0512469DE2 FOREIGN KEY (category_id) REFERENCES template_category (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE template_document_field ADD CONSTRAINT FK_9745CD30C33F7837 FOREIGN KEY (document_id) REFERENCES template_document (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE template_document_page ADD CONSTRAINT FK_A8F54B94C33F7837 FOREIGN KEY (document_id) REFERENCES template_document (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE template_document_page ADD CONSTRAINT FK_A8F54B945DA0FB8 FOREIGN KEY (template_id) REFERENCES template (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE template_document_page ADD CONSTRAINT FK_A8F54B943B69A9AF FOREIGN KEY (variant_id) REFERENCES template_variant (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE export_event ALTER format TYPE VARCHAR(16)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE template_document DROP CONSTRAINT FK_873FBA05166D1F9C');
        $this->addSql('ALTER TABLE template_document DROP CONSTRAINT FK_873FBA0512469DE2');
        $this->addSql('ALTER TABLE template_document_field DROP CONSTRAINT FK_9745CD30C33F7837');
        $this->addSql('ALTER TABLE template_document_page DROP CONSTRAINT FK_A8F54B94C33F7837');
        $this->addSql('ALTER TABLE template_document_page DROP CONSTRAINT FK_A8F54B945DA0FB8');
        $this->addSql('ALTER TABLE template_document_page DROP CONSTRAINT FK_A8F54B943B69A9AF');
        $this->addSql('DROP TABLE template_document');
        $this->addSql('DROP TABLE template_document_field');
        $this->addSql('DROP TABLE template_document_page');
        $this->addSql('ALTER TABLE export_event ALTER format TYPE VARCHAR(8)');
    }
}
