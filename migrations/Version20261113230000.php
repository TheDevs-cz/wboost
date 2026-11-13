<?php

declare(strict_types=1);

namespace WBoost\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * AI scan, the waiting screen: `ai_scan.stage_log` — when each stage of the
 * current try started and ended and the note it ended with (the real
 * per-stage durations) — and `ai_scan.text_boxes` — where the run found lines
 * of text ("Tyto texty jsme našli").
 *
 * Additive only: two nullable columns.
 */
final class Version20261113230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'AI scan: ai_scan.stage_log, ai_scan.text_boxes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ai_scan ADD stage_log JSONB DEFAULT NULL');
        $this->addSql('ALTER TABLE ai_scan ADD text_boxes JSONB DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ai_scan DROP stage_log');
        $this->addSql('ALTER TABLE ai_scan DROP text_boxes');
    }
}
