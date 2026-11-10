<?php

declare(strict_types=1);

namespace WBoost\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The "Napsat historku" form on the landing page accepts a story without an
 * e-mail address.
 */
final class Version20261110120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Landing leads: e-mail is optional (anonymous stories)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE landing_lead ALTER email DROP NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE landing_lead SET email = '' WHERE email IS NULL");
        $this->addSql('ALTER TABLE landing_lead ALTER email SET NOT NULL');
    }
}
