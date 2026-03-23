<?php

declare(strict_types=1);

namespace WBoost\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260323120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add weekly menu approval workflow fields and audit log table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE weekly_menu ADD approval_email VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE weekly_menu ADD approval_status VARCHAR(255) NOT NULL DEFAULT \'not_requested\'');
        $this->addSql('ALTER TABLE weekly_menu ADD approval_hash VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE weekly_menu ADD approval_responded_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE weekly_menu ADD approval_comment TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE weekly_menu ADD requested_by_email VARCHAR(255) DEFAULT NULL');
        $this->addSql('COMMENT ON COLUMN weekly_menu.approval_responded_at IS \'(DC2Type:datetime_immutable)\'');

        $this->addSql('CREATE TABLE weekly_menu_approval_audit_log (id UUID NOT NULL, weekly_menu_id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, event VARCHAR(255) NOT NULL, performed_by VARCHAR(255) DEFAULT NULL, comment TEXT DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_WMALOG_WEEKLY_MENU ON weekly_menu_approval_audit_log (weekly_menu_id)');
        $this->addSql('COMMENT ON COLUMN weekly_menu_approval_audit_log.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN weekly_menu_approval_audit_log.weekly_menu_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN weekly_menu_approval_audit_log.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE weekly_menu_approval_audit_log ADD CONSTRAINT FK_WMALOG_WEEKLY_MENU FOREIGN KEY (weekly_menu_id) REFERENCES weekly_menu (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE weekly_menu_approval_audit_log DROP CONSTRAINT FK_WMALOG_WEEKLY_MENU');
        $this->addSql('DROP TABLE weekly_menu_approval_audit_log');
        $this->addSql('ALTER TABLE weekly_menu DROP approval_email');
        $this->addSql('ALTER TABLE weekly_menu DROP approval_status');
        $this->addSql('ALTER TABLE weekly_menu DROP approval_hash');
        $this->addSql('ALTER TABLE weekly_menu DROP approval_responded_at');
        $this->addSql('ALTER TABLE weekly_menu DROP approval_comment');
        $this->addSql('ALTER TABLE weekly_menu DROP requested_by_email');
    }
}
