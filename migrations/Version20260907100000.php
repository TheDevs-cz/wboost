<?php

declare(strict_types=1);

namespace WBoost\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Export history curation: a version can be NAMED and PINNED.
 *
 * `name` replaces the export date wherever the version is listed; `pinned_at`
 * (null = not pinned) sorts the version first everywhere, orders the pinned
 * section (most recent pin on top) and exempts the row from the per-surface
 * history pruning — a user's "that one fill I keep coming back to" must not
 * scroll out of the history because thirty newer exports happened.
 *
 * Both columns are nullable with no default, so every existing row reads as
 * unnamed + unpinned and nothing changes for it.
 */
final class Version20260907100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'template_export_version: user-given name + pinned_at (pinned versions are never pruned).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE template_export_version ADD name VARCHAR(120) DEFAULT NULL');
        $this->addSql('ALTER TABLE template_export_version ADD pinned_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE template_export_version DROP name');
        $this->addSql('ALTER TABLE template_export_version DROP pinned_at');
    }
}
