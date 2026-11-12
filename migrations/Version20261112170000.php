<?php

declare(strict_types=1);

namespace WBoost\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * AI scan, phase P1 "job core" (docs/architecture/ai-scan.md): the job table,
 * usage per run, the credit ledger, the one-row runtime state, and the
 * entitlement / consent columns on `project` / `user`.
 *
 * Additive only, no backfill. The feature is INERT after it: the runtime row
 * is inserted with mode `off` and `admin_only`, and `project.ai_scan_enabled`
 * defaults to false for every project.
 */
final class Version20261112170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'AI scan P1: ai_scan, ai_usage_event, ai_credit_ledger, ai_scan_runtime; project / user columns';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE ai_credit_ledger (id UUID NOT NULL, project_id UUID NOT NULL, scan_id UUID DEFAULT NULL, try INT DEFAULT 0 NOT NULL, period VARCHAR(7) NOT NULL, delta INT NOT NULL, reason VARCHAR(16) NOT NULL, cost_micro_usd BIGINT DEFAULT 0 NOT NULL, created_by UUID DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_ai_ledger_project_period ON ai_credit_ledger (project_id, period)');
        $this->addSql('CREATE INDEX idx_ai_ledger_created_by ON ai_credit_ledger (created_by, created_at)');
        $this->addSql('CREATE INDEX idx_ai_ledger_project_created ON ai_credit_ledger (project_id, created_at)');
        $this->addSql('CREATE UNIQUE INDEX uniq_ai_ledger_scan_try_reason ON ai_credit_ledger (scan_id, try, reason)');
        $this->addSql('CREATE TABLE ai_scan (status VARCHAR(16) NOT NULL, stage VARCHAR(16) DEFAULT NULL, note VARCHAR(32) DEFAULT NULL, note_params JSONB DEFAULT NULL, stage_started_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, round INT DEFAULT 0 NOT NULL, preview_seq INT DEFAULT 0 NOT NULL, events JSONB DEFAULT \'[]\' NOT NULL, try INT DEFAULT 1 NOT NULL, intake_tries INT DEFAULT 0 NOT NULL, intake_losses INT DEFAULT 0 NOT NULL, approximate BOOLEAN DEFAULT false NOT NULL, auto_restart INT DEFAULT 0 NOT NULL, run INT DEFAULT 0 NOT NULL, beat_seq INT DEFAULT 0 NOT NULL, lease_token_hash VARCHAR(64) DEFAULT NULL, lease_expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, claimed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, waiting_since TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, started_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, finished_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, error_code VARCHAR(32) DEFAULT NULL, failure_count INT DEFAULT 0 NOT NULL, pre_check JSONB DEFAULT NULL, candidates JSONB DEFAULT \'[]\' NOT NULL, kept_candidate INT DEFAULT NULL, proposal JSONB DEFAULT NULL, report JSONB DEFAULT NULL, fixes JSONB DEFAULT NULL, pictures JSONB DEFAULT NULL, restore JSONB DEFAULT NULL, unfinished BOOLEAN DEFAULT false NOT NULL, input_tokens BIGINT DEFAULT 0 NOT NULL, output_tokens BIGINT DEFAULT 0 NOT NULL, cache_read_tokens BIGINT DEFAULT 0 NOT NULL, cache_write_tokens BIGINT DEFAULT 0 NOT NULL, cost_micro_usd BIGINT DEFAULT 0 NOT NULL, turns INT DEFAULT 0 NOT NULL, renders INT DEFAULT 0 NOT NULL, duration_ms BIGINT DEFAULT 0 NOT NULL, resources JSONB DEFAULT NULL, model VARCHAR(64) DEFAULT NULL, definition_version VARCHAR(32) DEFAULT NULL, scanner_version VARCHAR(32) DEFAULT NULL, seen_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, notified_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, reminded_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, rating SMALLINT DEFAULT NULL, rating_note TEXT DEFAULT NULL, id UUID NOT NULL, request_id VARCHAR(64) NOT NULL, kind VARCHAR(16) NOT NULL, requester_email VARCHAR(180) NOT NULL, channel VARCHAR(16) NOT NULL, storage_secret VARCHAR(32) NOT NULL, source_sha256 VARCHAR(64) NOT NULL, source_meta JSONB NOT NULL, options JSONB NOT NULL, consent_version VARCHAR(32) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, result_template_id UUID DEFAULT NULL, result_variant_id UUID DEFAULT NULL, project_id UUID NOT NULL, calendar_id UUID DEFAULT NULL, requested_by_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_C0E2F215134E9BF7 ON ai_scan (result_template_id)');
        $this->addSql('CREATE INDEX IDX_C0E2F215C332A370 ON ai_scan (result_variant_id)');
        $this->addSql('CREATE INDEX IDX_C0E2F215166D1F9C ON ai_scan (project_id)');
        $this->addSql('CREATE INDEX IDX_C0E2F215A40A2C8 ON ai_scan (calendar_id)');
        $this->addSql('CREATE INDEX IDX_C0E2F2154DA1E751 ON ai_scan (requested_by_id)');
        $this->addSql('CREATE INDEX idx_ai_scan_queue ON ai_scan (status, waiting_since)');
        $this->addSql('CREATE INDEX idx_ai_scan_project_status ON ai_scan (project_id, status)');
        $this->addSql('CREATE INDEX idx_ai_scan_project_source ON ai_scan (project_id, source_sha256)');
        $this->addSql('CREATE INDEX idx_ai_scan_expires ON ai_scan (expires_at)');
        $this->addSql('CREATE UNIQUE INDEX uniq_ai_scan_request ON ai_scan (requested_by_id, request_id)');
        $this->addSql('CREATE TABLE ai_scan_runtime (id SMALLINT NOT NULL, mode VARCHAR(8) DEFAULT \'off\' NOT NULL, admin_only BOOLEAN DEFAULT true NOT NULL, max_running INT DEFAULT 1 NOT NULL, daily_ceiling_micro_usd BIGINT DEFAULT 20000000 NOT NULL, project_daily_micro_usd BIGINT DEFAULT 5000000 NOT NULL, daily_starts_user INT DEFAULT 10 NOT NULL, daily_starts_project INT DEFAULT 20 NOT NULL, provider_state VARCHAR(8) DEFAULT \'ok\' NOT NULL, provider_checked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, provider_error VARCHAR(64) DEFAULT NULL, scanner_seen_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, scanner_version VARCHAR(32) DEFAULT NULL, scanner_too_old BOOLEAN DEFAULT false NOT NULL, consecutive_failures INT DEFAULT 0 NOT NULL, failure_class VARCHAR(32) DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE ai_usage_event (closed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, provider VARCHAR(32) DEFAULT \'anthropic\' NOT NULL, model VARCHAR(64) DEFAULT \'\' NOT NULL, input_tokens BIGINT DEFAULT 0 NOT NULL, output_tokens BIGINT DEFAULT 0 NOT NULL, cache_read_tokens BIGINT DEFAULT 0 NOT NULL, cache_write_tokens BIGINT DEFAULT 0 NOT NULL, cost_micro_usd BIGINT DEFAULT 0 NOT NULL, price_version VARCHAR(32) DEFAULT NULL, engine_cost_micro_usd BIGINT DEFAULT 0 NOT NULL, outcome VARCHAR(24) DEFAULT NULL, error_code VARCHAR(32) DEFAULT NULL, duration_ms BIGINT DEFAULT 0 NOT NULL, turns INT DEFAULT 0 NOT NULL, id UUID NOT NULL, scan_id UUID NOT NULL, run INT NOT NULL, step VARCHAR(16) NOT NULL, occurred_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, project_id UUID NOT NULL, project_name VARCHAR(255) NOT NULL, owner_id UUID DEFAULT NULL, owner_email VARCHAR(180) NOT NULL, user_id UUID DEFAULT NULL, user_email VARCHAR(180) NOT NULL, channel VARCHAR(16) NOT NULL, kind VARCHAR(16) NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_ai_usage_occurred ON ai_usage_event (occurred_at)');
        $this->addSql('CREATE INDEX idx_ai_usage_project ON ai_usage_event (project_id, occurred_at)');
        $this->addSql('CREATE UNIQUE INDEX uniq_ai_usage_scan_run_step ON ai_usage_event (scan_id, run, step)');
        $this->addSql('ALTER TABLE ai_scan ADD CONSTRAINT FK_C0E2F215134E9BF7 FOREIGN KEY (result_template_id) REFERENCES template (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE ai_scan ADD CONSTRAINT FK_C0E2F215C332A370 FOREIGN KEY (result_variant_id) REFERENCES template_variant (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE ai_scan ADD CONSTRAINT FK_C0E2F215166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE ai_scan ADD CONSTRAINT FK_C0E2F215A40A2C8 FOREIGN KEY (calendar_id) REFERENCES calendar (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE ai_scan ADD CONSTRAINT FK_C0E2F2154DA1E751 FOREIGN KEY (requested_by_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE project ADD ai_scan_enabled BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE project ADD ai_scan_monthly_limit INT DEFAULT 10');
        $this->addSql('ALTER TABLE project ADD ai_scan_enabled_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE project ADD ai_scan_enabled_by_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE project ADD CONSTRAINT FK_2FB3D0EE67BF9B3E FOREIGN KEY (ai_scan_enabled_by_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_2FB3D0EE67BF9B3E ON project (ai_scan_enabled_by_id)');
        $this->addSql('ALTER TABLE "user" ADD ai_scan_consent_version VARCHAR(32) DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD ai_scan_consent_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        // The one runtime row, with the safe column defaults (off, admins only).
        $this->addSql('INSERT INTO ai_scan_runtime (id) VALUES (1)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ai_scan DROP CONSTRAINT FK_C0E2F215134E9BF7');
        $this->addSql('ALTER TABLE ai_scan DROP CONSTRAINT FK_C0E2F215C332A370');
        $this->addSql('ALTER TABLE ai_scan DROP CONSTRAINT FK_C0E2F215166D1F9C');
        $this->addSql('ALTER TABLE ai_scan DROP CONSTRAINT FK_C0E2F215A40A2C8');
        $this->addSql('ALTER TABLE ai_scan DROP CONSTRAINT FK_C0E2F2154DA1E751');
        $this->addSql('DROP TABLE ai_credit_ledger');
        $this->addSql('DROP TABLE ai_scan');
        $this->addSql('DROP TABLE ai_scan_runtime');
        $this->addSql('DROP TABLE ai_usage_event');
        $this->addSql('ALTER TABLE project DROP CONSTRAINT FK_2FB3D0EE67BF9B3E');
        $this->addSql('DROP INDEX IDX_2FB3D0EE67BF9B3E');
        $this->addSql('ALTER TABLE project DROP ai_scan_enabled');
        $this->addSql('ALTER TABLE project DROP ai_scan_monthly_limit');
        $this->addSql('ALTER TABLE project DROP ai_scan_enabled_at');
        $this->addSql('ALTER TABLE project DROP ai_scan_enabled_by_id');
        $this->addSql('ALTER TABLE "user" DROP ai_scan_consent_version');
        $this->addSql('ALTER TABLE "user" DROP ai_scan_consent_at');
    }
}
