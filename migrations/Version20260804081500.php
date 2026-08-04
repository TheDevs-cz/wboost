<?php

declare(strict_types=1);

namespace WBoost\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Templates merge, step 2 — a variant dimension can carry a social-network
 * preset marker ('1:1' / '4:5' / '9:16'). NULL = free-form px/mm/cm
 * dimension, i.e. every existing row.
 */
final class Version20260804081500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add template_variant.dimension_preset (nullable social-format marker).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE template_variant ADD dimension_preset VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE template_variant DROP dimension_preset');
    }
}
