<?php

declare(strict_types=1);

namespace WBoost\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * AI scan for calendars (P5): `calendar.layout_origin` — where the calendar's
 * layout came from when that is not a preset (`scan`); null otherwise. Set
 * when a scanned layout is applied, cleared when a save changes the layout
 * choice. It is not part of the layout document.
 *
 * Additive only: one nullable column.
 */
final class Version20261113190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'AI scan: calendar.layout_origin';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE calendar ADD layout_origin VARCHAR(16) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE calendar DROP layout_origin');
    }
}
