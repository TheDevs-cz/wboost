<?php

declare(strict_types=1);

namespace WBoost\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260323162723 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE weekly_menu ALTER approval_status DROP DEFAULT');
        $this->addSql('COMMENT ON COLUMN weekly_menu.approval_responded_at IS \'\'');
        $this->addSql('COMMENT ON COLUMN weekly_menu_approval_audit_log.id IS \'\'');
        $this->addSql('COMMENT ON COLUMN weekly_menu_approval_audit_log.weekly_menu_id IS \'\'');
        $this->addSql('COMMENT ON COLUMN weekly_menu_approval_audit_log.created_at IS \'\'');
        $this->addSql('ALTER INDEX idx_wmalog_weekly_menu RENAME TO IDX_7F0C6CAEC4A7451D');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE weekly_menu ALTER approval_status SET DEFAULT \'not_requested\'');
        $this->addSql('COMMENT ON COLUMN weekly_menu.approval_responded_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN weekly_menu_approval_audit_log.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN weekly_menu_approval_audit_log.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN weekly_menu_approval_audit_log.weekly_menu_id IS \'(DC2Type:uuid)\'');
        $this->addSql('ALTER INDEX idx_7f0c6caec4a7451d RENAME TO idx_wmalog_weekly_menu');
    }
}
