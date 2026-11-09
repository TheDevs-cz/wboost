<?php

declare(strict_types=1);

namespace WBoost\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use WBoost\Web\Services\Manual\ManualPagePositions;

/**
 * DATA (content pages P2): mockup pages and custom content pages now share
 * ONE position space per manual. Renumber every manual's mockup pages
 * 0…n-1 in their CURRENT order so the shared space starts gap- and tie-free
 * — prod 2026-11-09 had one tie (two pages at 6) and one manual starting at
 * 1. The read used to be `ORDER BY position` only; ties are broken by
 * `created_at, id`, which is what prod rendered for its tie and what
 * `GetManualPageList` uses. No custom content pages exist yet; the intro
 * (position 0) is not part of the space. Idempotent; down is a no-op (the
 * old order is the same order).
 */
final class Version20261109170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Manual pages: renumber mockup page positions 0..n-1 per manual (shared order with custom content pages).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(ManualPagePositions::RENUMBER_MOCKUP_PAGES_SQL);
    }

    public function down(Schema $schema): void
    {
    }
}
