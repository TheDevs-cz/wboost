<?php

declare(strict_types=1);

namespace WBoost\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Background-as-layer rework: newly created variants store their background as
 * a regular canvas object (`isBackground` layer) instead of the canvas-level
 * backgroundImage. `background_mode` discriminates the two styles ('canvas'
 * for every existing row), and `background_image` becomes nullable because a
 * layer-mode variant may have no background at all (renders transparent).
 */
final class Version20260803120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add background_mode + make background_image nullable on template variants.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE social_network_template_variant ADD background_mode VARCHAR(16) DEFAULT 'canvas' NOT NULL");
        $this->addSql('ALTER TABLE social_network_template_variant ALTER background_image DROP NOT NULL');
        $this->addSql("ALTER TABLE custom_template_variant ADD background_mode VARCHAR(16) DEFAULT 'canvas' NOT NULL");
        $this->addSql('ALTER TABLE custom_template_variant ALTER background_image DROP NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE social_network_template_variant DROP background_mode');
        $this->addSql('ALTER TABLE social_network_template_variant ALTER background_image SET NOT NULL');
        $this->addSql('ALTER TABLE custom_template_variant DROP background_mode');
        $this->addSql('ALTER TABLE custom_template_variant ALTER background_image SET NOT NULL');
    }
}
