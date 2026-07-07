<?php

declare(strict_types=1);

namespace WBoost\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * User activity tracking: a "last seen" timestamp on the user plus a
 * per-user-per-day hit counter (the time-series behind the admin activity
 * report). Both are populated from the moment this ships — no backfill.
 */
final class Version20260707100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user.last_activity_at + user_activity_day counter table for usage statistics.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD last_activity_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');

        $this->addSql(<<<'SQL'
            CREATE TABLE user_activity_day (
              id UUID NOT NULL,
              user_id UUID NOT NULL,
              day DATE NOT NULL,
              hits INT DEFAULT 0 NOT NULL,
              PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_user_activity_day_user_day ON user_activity_day (user_id, day)');
        $this->addSql('CREATE INDEX idx_user_activity_day_day ON user_activity_day (day)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE user_activity_day');
        $this->addSql('ALTER TABLE "user" DROP last_activity_at');
    }
}
