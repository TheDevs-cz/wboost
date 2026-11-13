<?php

declare(strict_types=1);

namespace WBoost\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * AI scan: operating e-mails to admins.
 *
 * - `ai_scan_alert` — one row per operating condition (scanner not answering,
 *   provider blocked, circuit breaker, spend ceiling, …): since when it lasts,
 *   when it was announced, when the last mail went out, when it ended. The
 *   rows are created by the code that first evaluates a condition.
 * - `ai_scan_runtime.breaker_tripped_at` — when the circuit breaker set the
 *   mode to `paused` (a pause set by hand has none).
 *
 * Additive only.
 */
final class Version20261113150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'AI scan: ai_scan_alert, ai_scan_runtime.breaker_tripped_at';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE ai_scan_alert (code VARCHAR(32) NOT NULL, active_since TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, opened_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, last_sent_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, cleared_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (code))');
        $this->addSql('ALTER TABLE ai_scan_runtime ADD breaker_tripped_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE ai_scan_alert');
        $this->addSql('ALTER TABLE ai_scan_runtime DROP breaker_tripped_at');
    }
}
