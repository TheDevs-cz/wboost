<?php

declare(strict_types=1);

namespace WBoost\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * AI scan: the operating e-mail "a project is paused for refusals".
 *
 * `ai_scan_project_pause` — one row per (project, day in Europe/Prague): the
 * claim of the one mail the admins get when the provider refused three
 * pictures of a project in a day. The pause itself is not stored; it is
 * counted from `ai_usage_event` at every start. The rows are inserted by
 * `app:ai-scan:watch`.
 *
 * Additive only: one new table.
 */
final class Version20261113233000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'AI scan: ai_scan_project_pause';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE ai_scan_project_pause (project_id UUID NOT NULL, day VARCHAR(10) NOT NULL, refusals INT DEFAULT 0 NOT NULL, announced_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (project_id, day))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE ai_scan_project_pause');
    }
}
