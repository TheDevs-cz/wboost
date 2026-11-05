<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Palette;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Type;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ToManyAssociationMapping;
use Doctrine\ORM\PersistentCollection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Doctrine\ManualColorsDoctrineType;
use WBoost\Web\Entity\Manual;
use WBoost\Web\Entity\ManualPaletteColor;
use WBoost\Web\Entity\ProjectColor;
use WBoost\Web\Services\ProvideIdentity;
use WBoost\Web\Value\Color;
use WBoost\Web\Value\ManualColor;
use WBoost\Web\Value\ManualColorType;

/**
 * Release R2 (F7 P1b) write path for a manual's colours: the palette
 * (`project_color`) and the manual's links (`manual_palette_color`) are the
 * source of truth and are written FIRST; then every manual of the project is
 * MIRRORED back into the JSONB columns (`detected_colors` / `custom_colors`,
 * the shape R1 code reads), so the old container during a blue-green overlap
 * and a code-revert rollback both see current colours.
 *
 * Two entry points:
 *  - {@see editColors()} — the old per-manual colours page (still live until
 *    P3) posts the manual's whole detected + custom lists;
 *  - {@see syncLogoColors()} — D3 after a logo upload: colours that left the
 *    logo lose their logo link (the palette colour stays, unlinked), new
 *    literals are appended to the logo segment without a role, reusing a
 *    palette colour of the same hex (first by position) or appending one.
 *
 * Matching a posted colour to a palette colour (same hex key only):
 *  1. its PREVIOUS palette colour (logo row → by literal, added row → the
 *     n-th earlier added link of that hex) when the print data is unchanged;
 *  2. else that previous colour EDITED IN PLACE when no other manual links it
 *     — so a cleared Pantone stays cleared and an edit leaves no orphan;
 *  3. else the backfill's §7 rule ({@see PaletteBackfillPlanner::matchPrint()}:
 *     first colour carrying its print data, else the first compatible one,
 *     whose gaps it fills);
 *  4. else a new palette colour at the END of the palette (D5).
 * Roles (D2, one per project colour) change only when THIS write changed the
 * manual's role for the colour — the old page re-posts every type — and never
 * from a logo sync. Palette colours are never deleted here (leftovers are not
 * swatches — {@see \WBoost\Web\Query\GetProjectPalette}).
 *
 * DBAL like the R1 projector ({@see ProjectManualColors}): the link rewrite is
 * DELETE-then-INSERT, which the ORM unit of work would reorder. The project
 * row is locked FOR UPDATE; everything runs in one transaction. Afterwards
 * the managed entities of the project are brought in step
 * ({@see refreshManaged()}) so the request that wrote reads what it wrote.
 *
 * @phpstan-import-type PrintData from PaletteBackfillPlanner
 * @phpstan-import-type RawColor from PaletteBackfillPlanner
 * @phpstan-import-type ManualInput from PaletteBackfillPlanner
 * @phpstan-import-type Occurrence from PaletteBackfillPlanner
 * @phpstan-type LinkRow array{manual_id: string, project_color_id: string, logo_hex: null|string, position: int}
 */
readonly final class ManualPaletteWriter
{
    public function __construct(
        private Connection $connection,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private ProvideIdentity $provideIdentity,
    ) {
    }

    /**
     * @param array<ManualColor> $detectedColors the logo colours, in the manual's order
     * @param array<ManualColor> $customColors the added colours, in the manual's order
     */
    public function editColors(UuidInterface $manualId, array $detectedColors, array $customColors): void
    {
        $this->write($manualId, static fn (array $current): array => [
            'detected' => self::rawColors($detectedColors),
            'custom' => self::rawColors($customColors),
            'setsRoles' => true,
        ]);
    }

    /**
     * D3 — run after the new logo is flushed (reads the stored `manual.logo`).
     */
    public function syncLogoColors(UuidInterface $manualId): void
    {
        $this->write($manualId, static fn (array $current): array => [
            'detected' => $current['logo'],
            'custom' => $current['custom'],
            'setsRoles' => false,
        ]);
    }

    /**
     * Rewrites every manual's JSONB mirror of one project from its links:
     * `detected_colors` = the logo links (literal, palette role + print data),
     * `custom_colors` = the other links (palette HEX). Public for
     * `app:palette:reconcile`; runs inside the caller's transaction.
     */
    public function mirrorProject(string $projectId): void
    {
        /** @var list<string> $manualIds */
        $manualIds = $this->connection->fetchFirstColumn('SELECT id FROM manual WHERE project_id = ? ORDER BY created_at, id', [$projectId]);

        /** @var list<array{manual_id: string, logo_hex: null|string, hex: string, role: null|string, cmyk: null|string, pantone: null|string, ral: null|string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT l.manual_id, l.logo_hex, c.hex, c.role, c.cmyk, c.pantone, c.ral FROM manual_palette_color l '
            . 'JOIN project_color c ON c.id = l.project_color_id JOIN manual m ON m.id = l.manual_id '
            . 'WHERE m.project_id = ? ORDER BY l.manual_id, l.position, l.id',
            [$projectId],
        );

        /** @var array<string, list<ManualColor>> $detected */
        $detected = array_fill_keys($manualIds, []);
        /** @var array<string, list<ManualColor>> $custom */
        $custom = array_fill_keys($manualIds, []);

        foreach ($rows as $row) {
            $print = self::printOfRow($row);
            $color = new ManualColor(
                new Color($row['logo_hex'] ?? $row['hex']),
                $row['role'] === null ? null : ManualColorType::from($row['role']),
                $print['pantone'],
                $print['cmyk'],
                $print['ral'],
            );

            if ($row['logo_hex'] !== null) {
                $detected[$row['manual_id']][] = $color;
            } else {
                $custom[$row['manual_id']][] = $color;
            }
        }

        $type = Type::getType(ManualColorsDoctrineType::NAME);
        $platform = $this->connection->getDatabasePlatform();

        foreach ($manualIds as $manualId) {
            $this->connection->executeStatement(
                'UPDATE manual SET detected_colors = CAST(? AS JSONB), custom_colors = CAST(? AS JSONB) WHERE id = ?',
                [$type->convertToDatabaseValue($detected[$manualId] ?? [], $platform), $type->convertToDatabaseValue($custom[$manualId] ?? [], $platform), $manualId],
            );
        }
    }

    /**
     * Brings the project's MANAGED entities in step after a DBAL write, so
     * the rest of the request (and a test) reads what was written: palette
     * colours and links are detached (re-hydrated fresh on the next read),
     * every managed manual gets an uninitialised link collection and its
     * stored JSONB mirror. Not `EntityManager::refresh()` — it throws on the
     * readonly `createdAt`.
     */
    public function refreshManaged(string $projectId): void
    {
        $unitOfWork = $this->entityManager->getUnitOfWork();
        $identityMap = $unitOfWork->getIdentityMap();

        foreach ($identityMap[ManualPaletteColor::class] ?? [] as $link) {
            if ($link instanceof ManualPaletteColor && $link->manual->project->id->toString() === $projectId) {
                $this->entityManager->detach($link);
            }
        }

        foreach ($identityMap[ProjectColor::class] ?? [] as $color) {
            if ($color instanceof ProjectColor && $color->project->id->toString() === $projectId) {
                $this->entityManager->detach($color);
            }
        }

        $manualMetadata = $this->entityManager->getClassMetadata(Manual::class);
        $linkMetadata = $this->entityManager->getClassMetadata(ManualPaletteColor::class);
        $type = Type::getType(ManualColorsDoctrineType::NAME);
        $platform = $this->connection->getDatabasePlatform();

        foreach ($identityMap[Manual::class] ?? [] as $manual) {
            if (!$manual instanceof Manual || $manual->project->id->toString() !== $projectId) {
                continue;
            }

            $oid = spl_object_id($manual);
            $links = new PersistentCollection($this->entityManager, $linkMetadata, new ArrayCollection());
            $mapping = $manualMetadata->getAssociationMapping('paletteLinks');
            assert($mapping instanceof ToManyAssociationMapping);
            $links->setOwner($manual, $mapping);
            $links->setInitialized(false);
            $manualMetadata->setFieldValue($manual, 'paletteLinks', $links);
            $unitOfWork->setOriginalEntityProperty($oid, 'paletteLinks', $links);

            /** @var false|array{detected_colors: null|string, custom_colors: null|string} $stored */
            $stored = $this->connection->fetchAssociative('SELECT detected_colors, custom_colors FROM manual WHERE id = ?', [$manual->id->toString()]);

            if ($stored !== false) {
                foreach (['jsonbDetectedColors' => $stored['detected_colors'], 'jsonbCustomColors' => $stored['custom_colors']] as $field => $value) {
                    $colors = $type->convertToPHPValue($value, $platform) ?? [];
                    $manualMetadata->setFieldValue($manual, $field, $colors);
                    $unitOfWork->setOriginalEntityProperty($oid, $field, $colors);
                }
            }
        }
    }

    /**
     * @param callable(array{logo: list<RawColor>, custom: list<RawColor>}): array{detected: list<RawColor>, custom: list<RawColor>, setsRoles: bool} $input
     */
    private function write(UuidInterface $manualId, callable $input): void
    {
        $projectId = $this->connection->transactional(function () use ($manualId, $input): null|string {
            /** @var false|array{id: string, project_id: string, name: string, type: string, logo: null|string} $manual */
            $manual = $this->connection->fetchAssociative('SELECT id, project_id, name, type, logo FROM manual WHERE id = ?', [$manualId->toString()]);

            if ($manual === false) {
                return null;
            }

            $projectId = $manual['project_id'];
            $this->connection->executeStatement('SELECT id FROM project WHERE id = ? FOR UPDATE', [$projectId]);

            $this->writeLinks($manual, $projectId, $input);
            $this->mirrorProject($projectId);

            return $projectId;
        });

        if ($projectId !== null) {
            $this->refreshManaged($projectId);
        }
    }

    /**
     * @param array{id: string, project_id: string, name: string, type: string, logo: null|string} $manual
     * @param callable(array{logo: list<RawColor>, custom: list<RawColor>}): array{detected: list<RawColor>, custom: list<RawColor>, setsRoles: bool} $input
     */
    private function writeLinks(array $manual, string $projectId, callable $input): void
    {
        /** @var list<array{id: string, hex: string, hex_key: string, role: null|string, cmyk: null|string, pantone: null|string, ral: null|string, position: int|string}> $paletteRows */
        $paletteRows = $this->connection->fetchAllAssociative(
            'SELECT id, hex, hex_key, role, cmyk, pantone, ral, position FROM project_color WHERE project_id = ? ORDER BY position, id',
            [$projectId],
        );

        /** @var list<array{manual_id: string, project_color_id: string, logo_hex: null|string, position: int|string}> $linkRows */
        $linkRows = $this->connection->fetchAllAssociative(
            'SELECT l.manual_id, l.project_color_id, l.logo_hex, l.position FROM manual_palette_color l JOIN manual m ON m.id = l.manual_id WHERE m.project_id = ? ORDER BY l.manual_id, l.position, l.id',
            [$projectId],
        );

        // The palette, by index (palette order).
        /** @var list<string> $ids */
        $ids = [];
        /** @var list<string> $hexes */
        $hexes = [];
        /** @var list<string> $groups */
        $groups = [];
        /** @var list<null|string> $roles */
        $roles = [];
        /** @var array<int, PrintData> $prints */
        $prints = [];
        /** @var array<string, int> $indexById */
        $indexById = [];
        $nextPosition = 0;

        foreach ($paletteRows as $row) {
            $index = count($ids);
            $ids[] = $row['id'];
            $hexes[] = $row['hex'];
            $groups[] = $row['hex_key'];
            $roles[] = $row['role'];
            $prints[$index] = self::printOfRow($row);
            $indexById[$row['id']] = $index;
            $nextPosition = max($nextPosition, (int) $row['position'] + 1);
        }

        // The manual's current links, and who else uses each palette colour.
        /** @var array<int, int> $otherUse palette index → links of OTHER manuals */
        $otherUse = [];
        /** @var list<array{index: int, logoHex: null|string}> $current */
        $current = [];

        foreach ($linkRows as $row) {
            $index = $indexById[$row['project_color_id']] ?? null;

            if ($index === null) {
                continue;
            }

            if ($row['manual_id'] === $manual['id']) {
                $current[] = ['index' => $index, 'logoHex' => $row['logo_hex']];
            } else {
                $otherUse[$index] = ($otherUse[$index] ?? 0) + 1;
            }
        }

        $currentRaw = ['logo' => [], 'custom' => []];
        /** @var array<string, null|string> $rolesBefore hex key → the strongest role this manual shows it with */
        $rolesBefore = [];

        foreach ($current as $link) {
            $index = $link['index'];
            $currentRaw[$link['logoHex'] !== null ? 'logo' : 'custom'][] = [
                'color' => $link['logoHex'] ?? $hexes[$index],
                'type' => $roles[$index],
                'pantone' => $prints[$index]['pantone'],
                'ral' => $prints[$index]['ral'],
                'cmyk' => $prints[$index]['cmyk'],
            ];
            $rolesBefore[$groups[$index]] = self::strongerRole($rolesBefore[$groups[$index]] ?? null, $roles[$index], array_key_exists($groups[$index], $rolesBefore));
        }

        $wanted = $input($currentRaw);
        $segments = PaletteBackfillPlanner::segments(0, PaletteBackfillPlanner::manualFromStorage(
            $manual['id'],
            $manual['name'],
            $manual['type'] === 'brand',
            $manual['logo'],
            $wanted['detected'],
            $wanted['custom'],
        ));
        $rolesNow = PaletteBackfillPlanner::manualRoles($segments);

        // Pair every colour with the palette colour it used before.
        /** @var array<string, int> $logoBefore literal → palette index */
        $logoBefore = [];
        /** @var array<string, list<int>> $customBefore hex key → palette indexes, manual order */
        $customBefore = [];

        foreach ($current as $link) {
            if ($link['logoHex'] !== null) {
                $logoBefore[$link['logoHex']] ??= $link['index'];
            } else {
                $customBefore[$groups[$link['index']]][] = $link['index'];
            }
        }

        /** @var array<string, int> $previous occurrence id → palette index */
        $previous = [];

        foreach ($segments['logo'] as $occurrence) {
            if (isset($logoBefore[$occurrence['hex']])) {
                $previous[PaletteBackfillPlanner::occurrenceId($occurrence)] = $logoBefore[$occurrence['hex']];
            }
        }

        foreach ($segments['custom'] as $occurrence) {
            $group = PaletteBackfillPlanner::groupKey($occurrence);
            $index = isset($customBefore[$group]) ? array_shift($customBefore[$group]) : null;

            if ($index !== null) {
                $previous[PaletteBackfillPlanner::occurrenceId($occurrence)] = $index;
            }
        }

        // Only what gets a link is matched (a stale typed detected colour no
        // longer becomes a palette colour); the walk keeps the backfill's
        // append order for new colours.
        $linked = [];
        foreach ([...$segments['logo'], ...$segments['custom']] as $occurrence) {
            $linked[PaletteBackfillPlanner::occurrenceId($occurrence)] = true;
        }
        $walk = array_values(array_filter(
            PaletteBackfillPlanner::manualWalk($segments),
            static fn (array $occurrence): bool => isset($linked[PaletteBackfillPlanner::occurrenceId($occurrence)]),
        ));

        $now = $this->clock->now()->format('Y-m-d H:i:s');
        /** @var array<string, int> $chosen occurrence id → palette index */
        $chosen = [];
        /** @var array<int, true> $claimed */
        $claimed = [];
        /** @var array<int, true> $dirtyPrint */
        $dirtyPrint = [];

        // 1. Unchanged colours keep their palette colour.
        foreach ($walk as $occurrence) {
            $id = PaletteBackfillPlanner::occurrenceId($occurrence);
            $index = $previous[$id] ?? null;

            if ($index !== null && $groups[$index] === PaletteBackfillPlanner::groupKey($occurrence) && $prints[$index] === PaletteBackfillPlanner::printOf($occurrence)) {
                $chosen[$id] = $index;
                $claimed[$index] = true;
            }
        }

        // 2.–4. The rest, in walk order.
        foreach ($walk as $occurrence) {
            $id = PaletteBackfillPlanner::occurrenceId($occurrence);

            if (isset($chosen[$id])) {
                continue;
            }

            $group = PaletteBackfillPlanner::groupKey($occurrence);
            $print = PaletteBackfillPlanner::printOf($occurrence);
            $index = $previous[$id] ?? null;

            if ($index !== null && $groups[$index] === $group && !isset($otherUse[$index]) && !isset($claimed[$index])) {
                $prints[$index] = $print;
                $dirtyPrint[$index] = true;
            } else {
                $candidates = [];
                foreach ($groups as $candidate => $candidateGroup) {
                    if ($candidateGroup === $group) {
                        $candidates[$candidate] = $prints[$candidate];
                    }
                }

                $index = PaletteBackfillPlanner::matchPrint($print, $candidates);

                if ($index !== null && PaletteBackfillPlanner::gainedPrint($prints[$index], $print) !== []) {
                    $prints[$index] = PaletteBackfillPlanner::unionPrint($prints[$index], $print);
                    $dirtyPrint[$index] = true;
                }

                if ($index === null) {
                    $index = count($ids);
                    $newId = $this->provideIdentity->next()->toString();
                    $role = $wanted['setsRoles'] ? ($rolesNow[$group] ?? null) : null;
                    $ids[] = $newId;
                    $hexes[] = $occurrence['hex'];
                    $groups[] = $group;
                    $roles[] = $role;
                    $prints[$index] = $print;

                    $this->connection->executeStatement(
                        'INSERT INTO project_color (id, project_id, hex, hex_key, role, cmyk, pantone, ral, position, created_at) VALUES (?, ?, ?, ?, ?, CAST(? AS JSONB), ?, ?, ?, ?)',
                        [$newId, $projectId, $occurrence['hex'], $group, $role, self::encodeCmyk($print['cmyk']), $print['pantone'], $print['ral'], $nextPosition++, $now],
                    );
                }
            }

            $chosen[$id] = $index;
            $claimed[$index] = true;
        }

        foreach (array_keys($dirtyPrint) as $index) {
            $this->connection->executeStatement(
                'UPDATE project_color SET cmyk = CAST(? AS JSONB), pantone = ?, ral = ? WHERE id = ?',
                [self::encodeCmyk($prints[$index]['cmyk']), $prints[$index]['pantone'], $prints[$index]['ral'], $ids[$index]],
            );
        }

        if ($wanted['setsRoles']) {
            foreach (array_unique(array_values($chosen)) as $index) {
                $group = $groups[$index];
                $roleNow = $rolesNow[$group] ?? null;
                $changed = array_key_exists($group, $rolesBefore) ? $rolesBefore[$group] !== $roleNow : $roleNow !== null;

                if ($changed && $roles[$index] !== $roleNow) {
                    $this->connection->executeStatement('UPDATE project_color SET role = ? WHERE id = ?', [$roleNow, $ids[$index]]);
                    $roles[$index] = $roleNow;
                }
            }
        }

        $this->connection->executeStatement('DELETE FROM manual_palette_color WHERE manual_id = ?', [$manual['id']]);

        $position = 0;
        $seenLogoHex = [];

        foreach ([...$segments['logo'], ...$segments['custom']] as $occurrence) {
            $logoHex = null;

            if ($occurrence['source'] !== 'custom' && !isset($seenLogoHex[$occurrence['hex']])) {
                $logoHex = $occurrence['hex'];
                $seenLogoHex[$logoHex] = true;
            }

            $this->connection->executeStatement(
                'INSERT INTO manual_palette_color (id, manual_id, project_color_id, logo_hex, position, created_at) VALUES (?, ?, ?, ?, ?, ?)',
                [
                    $this->provideIdentity->next()->toString(),
                    $manual['id'],
                    $ids[$chosen[PaletteBackfillPlanner::occurrenceId($occurrence)]],
                    $logoHex,
                    $position++,
                    $now,
                ],
            );
        }
    }

    /**
     * Strongest of two roles (primary > secondary > none); `$seen` false =
     * `$a` is no vote yet.
     */
    private static function strongerRole(null|string $a, null|string $b, bool $seen): null|string
    {
        if (!$seen) {
            return $b;
        }

        $rank = static fn (null|string $role): int => match ($role) {
            PaletteBackfillPlanner::ROLE_PRIMARY => 0,
            PaletteBackfillPlanner::ROLE_SECONDARY => 1,
            default => 2,
        };

        return $rank($a) <= $rank($b) ? $a : $b;
    }

    /**
     * @param array<ManualColor> $colors
     * @return list<RawColor>
     */
    private static function rawColors(array $colors): array
    {
        return array_values(array_map(static fn (ManualColor $color): array => [
            'color' => $color->color->hex,
            'type' => $color->type?->value,
            'pantone' => $color->pantone,
            'ral' => $color->ral,
            'cmyk' => $color->cmyk,
        ], $colors));
    }

    /**
     * @param array{cmyk: null|string, pantone: null|string, ral: null|string, ...} $row
     * @return PrintData
     */
    private static function printOfRow(array $row): array
    {
        $cmyk = null;

        if ($row['cmyk'] !== null) {
            $decoded = json_decode($row['cmyk'], true);

            if (is_array($decoded)) {
                $channels = array_values($decoded);
                $channel = static fn (int $i): null|string => is_scalar($channels[$i] ?? null) ? (string) $channels[$i] : null;
                $cmyk = [$channel(0), $channel(1), $channel(2), $channel(3)];
            }
        }

        return PaletteBackfillPlanner::printOf(['cmyk' => $cmyk, 'pantone' => $row['pantone'], 'ral' => $row['ral']]);
    }

    /**
     * @param null|array{null|string, null|string, null|string, null|string} $cmyk
     */
    private static function encodeCmyk(null|array $cmyk): null|string
    {
        return $cmyk === null ? null : json_encode($cmyk, JSON_THROW_ON_ERROR);
    }
}
