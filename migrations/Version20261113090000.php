<?php

declare(strict_types=1);

namespace WBoost\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * AI scan: two columns on `ai_usage_event`.
 *
 * - `cache_write_1h_tokens` — the part of the cache writes made with the
 *   1-hour lifetime, priced at its own rate (2 × input; the 5-minute rate is
 *   1.25 ×). Until now every cache write was priced at the 5-minute rate.
 * - `lease_token_hash` — the lease token (hashed) of the claim that opened the
 *   row, so that a run the app has replaced can still report what it spent.
 *
 * Additive only; existing rows keep 0 / NULL.
 */
final class Version20261113090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'AI scan: ai_usage_event.cache_write_1h_tokens, ai_usage_event.lease_token_hash';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ai_usage_event ADD cache_write_1h_tokens BIGINT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE ai_usage_event ADD lease_token_hash VARCHAR(64) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_ai_usage_lease ON ai_usage_event (scan_id, lease_token_hash)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_ai_usage_lease');
        $this->addSql('ALTER TABLE ai_usage_event DROP cache_write_1h_tokens');
        $this->addSql('ALTER TABLE ai_usage_event DROP lease_token_hash');
    }
}
