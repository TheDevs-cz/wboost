<?php

declare(strict_types=1);

namespace WBoost\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Calendars: `calendar.languages` — the extra languages of the printed month
 * / weekday / week-number names (K15b, step 1 „Jazyky názvů“), `[]` = Czech
 * only, so every stored calendar stays as it was.
 *
 * Additive only: one column with a default.
 */
final class Version20261124120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Calendars: calendar.languages';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE calendar ADD languages JSONB DEFAULT '[]' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE calendar DROP languages');
    }
}
