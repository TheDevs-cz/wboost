<?php

declare(strict_types=1);

namespace WBoost\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Fill field order: the admin-arranged order of the fill pages' "Všechna
 * pole" panel, as a list of inputIds per template (empty = natural order).
 */
final class Version20261011150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'template.fill_field_order — admin-arranged order of the fill panel fields.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE template ADD fill_field_order JSONB DEFAULT \'[]\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE template DROP fill_field_order');
    }
}
