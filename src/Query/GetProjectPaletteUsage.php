<?php

declare(strict_types=1);

namespace WBoost\Web\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\UuidInterface;

/**
 * "Použito v:" on the Paleta barev cards (F7 P2): per palette colour, the
 * manuals that link it — manuals in `created_at, id` order (= `GetManuals`),
 * one entry per manual, `fromLogo` when any of its links is a logo link
 * (the colour is then "Z logotypu" and its HEX is locked). One query.
 */
readonly final class GetProjectPaletteUsage
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @return array<string, list<PaletteColorUsageRow>> palette colour id → manuals; unused colours are absent
     */
    public function forProject(UuidInterface $projectId): array
    {
        /** @var list<array{project_color_id: string, manual_id: string, manual_name: string, from_logo: bool|string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT l.project_color_id, m.id AS manual_id, m.name AS manual_name, BOOL_OR(l.logo_hex IS NOT NULL) AS from_logo
                FROM manual_palette_color l
                JOIN manual m ON m.id = l.manual_id
                WHERE m.project_id = ?
                GROUP BY l.project_color_id, m.id, m.name, m.created_at
                ORDER BY m.created_at, m.id
                SQL,
            [$projectId->toString()],
        );

        $usage = [];

        foreach ($rows as $row) {
            $usage[$row['project_color_id']][] = new PaletteColorUsageRow(
                manualId: $row['manual_id'],
                manualName: $row['manual_name'],
                fromLogo: $row['from_logo'] === true || $row['from_logo'] === 't' || $row['from_logo'] === '1',
            );
        }

        return $usage;
    }
}
