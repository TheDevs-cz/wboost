<?php

declare(strict_types=1);

namespace WBoost\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A mockup page can now carry downloadable files, so a manual reader can take
 * away the source behind a mockup (print-ready PDF, packaged assets) instead of
 * only looking at the picture. Two levels, both optional: one file for the
 * WHOLE page and one per image slot.
 *
 * `image_downloads` is positionally aligned with the existing `images` array —
 * a slot without a file keeps a null hole — hence the `'[]'` default: existing
 * pages simply have no attachments. Additive, no data touched.
 */
final class Version20260903120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'manual_mockup_page: downloadable file for the whole page and per image slot.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE manual_mockup_page ADD download_file JSONB DEFAULT NULL');
        $this->addSql("ALTER TABLE manual_mockup_page ADD image_downloads JSONB DEFAULT '[]' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE manual_mockup_page DROP download_file');
        $this->addSql('ALTER TABLE manual_mockup_page DROP image_downloads');
    }
}
