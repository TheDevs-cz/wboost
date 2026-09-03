<?php

declare(strict_types=1);

namespace WBoost\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The manual's page headings and descriptions were hardcoded in the template,
 * so every brand/logo manual read exactly the same. They are now overridable
 * per manual and per page; the wording that used to be in the template moved
 * to `WBoost\Web\Value\ManualPage` and is the fallback, so an empty map — what
 * every existing manual gets here — renders precisely what it rendered before.
 *
 * Keyed by `ManualPage` value, each entry `{title, description}` with either
 * half nullable. Additive, no data touched.
 */
final class Version20260903140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'manual: per-page heading and description overrides.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE manual ADD page_texts JSONB DEFAULT '{}' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE manual DROP page_texts');
    }
}
