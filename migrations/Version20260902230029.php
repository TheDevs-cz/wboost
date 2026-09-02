<?php

declare(strict_types=1);

namespace WBoost\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Gallery tiles show the file name and pixel size ("pozadi.png · 1080 × 1350
 * px"), because two similar pictures of different sizes were otherwise
 * indistinguishable. Neither was recorded before: the object is named after
 * the row and nothing stored its dimensions (the MCP gallery listing re-read
 * every file header on every call).
 *
 * All three columns are nullable on purpose. `original_name` can only be known
 * for uploads from here on — there is nothing to backfill it from. `width` /
 * `height` ARE backfilled: lazily by the gallery listing as folders are opened,
 * and in one sweep by `app:gallery:backfill-image-size` (run it once after this
 * deploys). Additive, no data touched.
 */
final class Version20260902230029 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'file_upload: record the uploaded file name and the pixel size of the stored picture.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE file_upload ADD width INT DEFAULT NULL');
        $this->addSql('ALTER TABLE file_upload ADD height INT DEFAULT NULL');
        $this->addSql('ALTER TABLE file_upload ADD original_name VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE file_upload DROP width');
        $this->addSql('ALTER TABLE file_upload DROP height');
        $this->addSql('ALTER TABLE file_upload DROP original_name');
    }
}
