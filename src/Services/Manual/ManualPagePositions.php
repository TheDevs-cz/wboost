<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Manual;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\UuidInterface;

/**
 * Mockup pages and custom content pages share ONE position space per manual
 * (the intro is outside it). A new page of either kind goes to the end.
 */
readonly final class ManualPagePositions
{
    /**
     * One-off for `Version20261109170000` (tested in ManualPagePositionsTest):
     * every manual's mockup pages renumbered 0…n-1 in their current order,
     * ties broken by creation time then id (= `GetManualPageList`).
     */
    public const string RENUMBER_MOCKUP_PAGES_SQL = 'UPDATE manual_mockup_page p
         SET position = r.rn
         FROM (
             SELECT id, (ROW_NUMBER() OVER (PARTITION BY manual_id ORDER BY position, created_at, id) - 1)::int AS rn
             FROM manual_mockup_page
         ) r
         WHERE r.id = p.id AND p.position <> r.rn';

    public function __construct(
        private Connection $connection,
    ) {
    }

    public function next(UuidInterface $manualId): int
    {
        $max = $this->connection->fetchOne(
            "SELECT GREATEST(
                (SELECT MAX(position) FROM manual_mockup_page WHERE manual_id = :manual),
                (SELECT MAX(position) FROM manual_content_page WHERE manual_id = :manual AND role = 'custom')
            )",
            ['manual' => $manualId->toString()],
        );

        return is_numeric($max) ? (int) $max + 1 : 0;
    }
}
