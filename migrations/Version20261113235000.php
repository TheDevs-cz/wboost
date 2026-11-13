<?php

declare(strict_types=1);

namespace WBoost\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * AI scan: `ai_scan.releases` — how many times the scanner handed the build
 * of the current try back (rule 7). A release consumes no automatic restart
 * and restarts the queue timeout, so it needs a bound of its own
 * (`AiScanLimits::MAX_BUILD_RELEASES`): one release more fails the scan as
 * `interrupted`, refunded.
 *
 * Additive only: one column with a default.
 */
final class Version20261113235000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'AI scan: ai_scan.releases';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ai_scan ADD releases INT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ai_scan DROP releases');
    }
}
