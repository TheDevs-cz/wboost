<?php

declare(strict_types=1);

namespace WBoost\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Per-CARD logo width overrides, keyed by the slot id the manual template
 * gives each logo card (`<page>.<logoVariant>.<colorVariant|base>`).
 *
 * This is the new top of the width cascade: card override, then the logo
 * variant's "Šířka loga v manuálu" (`manual.logo -> <variant> -> displayWidth`,
 * unchanged), then no override. An empty map — what every existing manual gets
 * here — leaves the variant-level behaviour exactly as it was.
 */
final class Version20260903160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'manual: per-logo-card width overrides.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE manual ADD logo_slot_widths JSON DEFAULT '{}' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE manual DROP logo_slot_widths');
    }
}
