<?php

declare(strict_types=1);

namespace WBoost\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20240814141356 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE font DROP CONSTRAINT FK_D09408D2166D1F9C');
        $this->addSql('ALTER TABLE font ADD CONSTRAINT FK_D09408D2166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE manual DROP CONSTRAINT FK_10DBBEC4166D1F9C');
        $this->addSql('ALTER TABLE manual ADD CONSTRAINT FK_10DBBEC4166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE manual_mockup_page DROP CONSTRAINT FK_9E8C5129BA073D6');
        $this->addSql('ALTER TABLE manual_mockup_page ADD CONSTRAINT FK_9E8C5129BA073D6 FOREIGN KEY (manual_id) REFERENCES manual (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE password_reset_token DROP CONSTRAINT FK_6B7BA4B6A76ED395');
        $this->addSql('ALTER TABLE password_reset_token ADD CONSTRAINT FK_6B7BA4B6A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE project DROP CONSTRAINT FK_2FB3D0EE7E3C61F9');
        $this->addSql('ALTER TABLE project ADD CONSTRAINT FK_2FB3D0EE7E3C61F9 FOREIGN KEY (owner_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE password_reset_token DROP CONSTRAINT fk_6b7ba4b6a76ed395');
        $this->addSql('ALTER TABLE password_reset_token ADD CONSTRAINT fk_6b7ba4b6a76ed395 FOREIGN KEY (user_id) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE manual_mockup_page DROP CONSTRAINT fk_9e8c5129ba073d6');
        $this->addSql('ALTER TABLE manual_mockup_page ADD CONSTRAINT fk_9e8c5129ba073d6 FOREIGN KEY (manual_id) REFERENCES manual (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE project DROP CONSTRAINT fk_2fb3d0ee7e3c61f9');
        $this->addSql('ALTER TABLE project ADD CONSTRAINT fk_2fb3d0ee7e3c61f9 FOREIGN KEY (owner_id) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE font DROP CONSTRAINT fk_d09408d2166d1f9c');
        $this->addSql('ALTER TABLE font ADD CONSTRAINT fk_d09408d2166d1f9c FOREIGN KEY (project_id) REFERENCES project (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE manual DROP CONSTRAINT fk_10dbbec4166d1f9c');
        $this->addSql('ALTER TABLE manual ADD CONSTRAINT fk_10dbbec4166d1f9c FOREIGN KEY (project_id) REFERENCES project (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }
}
