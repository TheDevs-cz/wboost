<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Palette;

use Doctrine\DBAL\Connection;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ToManyAssociationMapping;
use Doctrine\ORM\PersistentCollection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Exceptions\ManualPaletteColorFromLogo;
use WBoost\Web\Exceptions\ManualPaletteColorNotFound;
use WBoost\Web\Entity\Manual;
use WBoost\Web\Entity\ManualPaletteColor;
use WBoost\Web\Entity\ProjectColor;
use WBoost\Web\Services\ProvideIdentity;
use WBoost\Web\Value\Color;

/**
 * Write path for a manual's colours (F7): the palette (`project_color`) and
 * the manual's links (`manual_palette_color`) are the only storage — since
 * P5a nothing is mirrored back into the retired JSONB columns.
 *
 * Entry points:
 *  - {@see syncLogoColors()} — D3 after a logo upload: colours that left the
 *    logo lose their logo link (the palette colour stays, "Nepoužitá" when
 *    nothing else links it), new literals are appended to the logo segment
 *    without a role, reusing a palette colour of the same hex key (first by
 *    palette position) or appending a new one at the END of the palette (D5);
 *  - {@see addColors()}, {@see removeColor()}, {@see sortColors()} — the
 *    "Barvy v manuálech" box of Paleta barev (F7 P3): pick palette colours,
 *    drop an added one, reorder; they only touch the manual's links.
 * Roles (D2, one per project colour) and print data are never changed here,
 * and palette colours are never deleted here (unlinked leftovers are not
 * swatches — {@see \WBoost\Web\Query\GetProjectPalette}).
 *
 * DBAL, not the ORM unit of work (which would reorder the link deletes and
 * inserts). The project row is locked FOR UPDATE; everything runs in one
 * transaction. Afterwards the managed entities of the project are brought in
 * step ({@see refreshManaged()}) so the request that wrote reads what it wrote.
 */
readonly final class ManualPaletteWriter
{
    /** The image order of {@see \WBoost\Web\Value\Logo::getDetectedColors()}. */
    private const array LOGO_VARIANTS = ['horizontal', 'horizontalWithClaim', 'symbol', 'vertical', 'verticalWithClaim'];

    public function __construct(
        private Connection $connection,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private ProvideIdentity $provideIdentity,
    ) {
    }

    /**
     * D3 — run after the new logo is flushed (reads the stored `manual.logo`).
     *
     * The logo segment becomes: the logo links whose literal is still in the
     * logo (their order and palette colour kept), then the new literals in
     * logo order. A literal is compared EXACTLY (`#` stripped, case kept —
     * recolouring `str_ireplace`s that spelling on the SVG, so `FFF` and
     * `ffffff` are two literals). The added colours follow, in their order.
     * Untouched links keep their ids; positions are renumbered 0…n.
     */
    public function syncLogoColors(UuidInterface $manualId): void
    {
        $projectId = $this->connection->transactional(function () use ($manualId): null|string {
            $projectId = $this->lockedManualProject($manualId);

            if ($projectId === null) {
                return null;
            }

            $literals = self::logoLiterals($this->connection->fetchOne('SELECT logo FROM manual WHERE id = ?', [$manualId->toString()]));

            /** @var list<array{id: string, logo_hex: null|string, position: int|string}> $links */
            $links = $this->connection->fetchAllAssociative(
                'SELECT id, logo_hex, position FROM manual_palette_color WHERE manual_id = ? ORDER BY position, id',
                [$manualId->toString()],
            );

            /** @var list<array{id: null|string, literal: null|string, position: null|int}> $logoSegment */
            $logoSegment = [];
            /** @var list<array{id: null|string, literal: null|string, position: null|int}> $added */
            $added = [];
            /** @var list<string> $keptLiterals */
            $keptLiterals = [];

            foreach ($links as $link) {
                $entry = ['id' => $link['id'], 'literal' => null, 'position' => (int) $link['position']];

                if ($link['logo_hex'] === null) {
                    $added[] = $entry;
                } elseif (in_array($link['logo_hex'], $literals, true)) {
                    $logoSegment[] = $entry;
                    $keptLiterals[] = $link['logo_hex'];
                } else {
                    // Left the logo: the link goes, the palette colour stays.
                    $this->connection->executeStatement('DELETE FROM manual_palette_color WHERE id = ?', [$link['id']]);
                }
            }

            foreach ($literals as $literal) {
                if (!in_array($literal, $keptLiterals, true)) {
                    $logoSegment[] = ['id' => null, 'literal' => $literal, 'position' => null];
                }
            }

            $now = $this->clock->now()->format('Y-m-d H:i:s');

            foreach ([...$logoSegment, ...$added] as $position => $entry) {
                if ($entry['id'] !== null) {
                    if ($entry['position'] !== $position) {
                        $this->connection->executeStatement('UPDATE manual_palette_color SET position = ? WHERE id = ?', [$position, $entry['id']]);
                    }

                    continue;
                }

                assert($entry['literal'] !== null);

                $this->connection->executeStatement(
                    'INSERT INTO manual_palette_color (id, manual_id, project_color_id, logo_hex, position, created_at) VALUES (?, ?, ?, ?, ?, ?)',
                    [
                        $this->provideIdentity->next()->toString(),
                        $manualId->toString(),
                        $this->paletteColorForLiteral($projectId, $entry['literal'], $now),
                        $entry['literal'],
                        $position,
                        $now,
                    ],
                );
            }

            return $projectId;
        });

        if ($projectId !== null) {
            $this->refreshManaged($projectId);
        }
    }

    /**
     * "Barvy v manuálech" → "Přidat z palety" (F7 P3): links the given
     * palette colours to the manual as ADDED colours, appended after its
     * current last colour in the given order. Colours of another project,
     * unknown ids and colours the manual already links are skipped. Roles and
     * print data stay the palette's (D2). Once linked, a colour is a template
     * swatch ({@see \WBoost\Web\Query\GetProjectPalette} is linked-only).
     *
     * @param array<string> $colorIds palette colour ids
     * @return int how many colours were added
     */
    public function addColors(UuidInterface $manualId, array $colorIds): int
    {
        $result = $this->connection->transactional(function () use ($manualId, $colorIds): null|array {
            $projectId = $this->lockedManualProject($manualId);

            if ($projectId === null) {
                return null;
            }

            /** @var list<string> $palette */
            $palette = $this->connection->fetchFirstColumn('SELECT id FROM project_color WHERE project_id = ?', [$projectId]);
            /** @var list<string> $linked */
            $linked = $this->connection->fetchFirstColumn('SELECT project_color_id FROM manual_palette_color WHERE manual_id = ?', [$manualId->toString()]);

            $allowed = array_fill_keys($palette, true);
            $skip = array_fill_keys($linked, true);
            $position = $this->connection->fetchOne('SELECT COALESCE(MAX(position) + 1, 0) FROM manual_palette_color WHERE manual_id = ?', [$manualId->toString()]);
            $position = is_numeric($position) ? (int) $position : 0;
            $now = $this->clock->now()->format('Y-m-d H:i:s');
            $added = 0;

            foreach ($colorIds as $colorId) {
                $colorId = strtolower($colorId);

                if (!isset($allowed[$colorId]) || isset($skip[$colorId])) {
                    continue;
                }

                $this->connection->executeStatement(
                    'INSERT INTO manual_palette_color (id, manual_id, project_color_id, logo_hex, position, created_at) VALUES (?, ?, ?, NULL, ?, ?)',
                    [$this->provideIdentity->next()->toString(), $manualId->toString(), $colorId, $position++, $now],
                );
                $skip[$colorId] = true;
                $added++;
            }

            return ['projectId' => $projectId, 'added' => $added];
        });

        if ($result === null) {
            return 0;
        }

        $this->refreshManaged($result['projectId']);

        return $result['added'];
    }

    /**
     * "Odebrat z manuálu" (F7 P3): drops one ADDED colour of the manual; the
     * palette colour stays (it may become "Nepoužitá"). A logo colour is
     * refused — it comes from the logo and leaves with a new one (D3). The
     * later colours move up one place (`position` keeps its gap; the order is
     * what counts).
     *
     * @throws ManualPaletteColorNotFound
     * @throws ManualPaletteColorFromLogo
     */
    public function removeColor(UuidInterface $manualId, UuidInterface $linkId): void
    {
        $projectId = $this->connection->transactional(function () use ($manualId, $linkId): string {
            $projectId = $this->lockedManualProject($manualId) ?? throw new ManualPaletteColorNotFound();

            /** @var false|array{logo_hex: null|string} $link */
            $link = $this->connection->fetchAssociative(
                'SELECT logo_hex FROM manual_palette_color WHERE id = ? AND manual_id = ?',
                [$linkId->toString(), $manualId->toString()],
            );

            if ($link === false) {
                throw new ManualPaletteColorNotFound();
            }

            if ($link['logo_hex'] !== null) {
                throw new ManualPaletteColorFromLogo();
            }

            $this->connection->executeStatement('DELETE FROM manual_palette_color WHERE id = ?', [$linkId->toString()]);

            return $projectId;
        });

        $this->refreshManaged($projectId);
    }

    /**
     * Reorders the manual's colours (F7 P3 — drag or "Posunout výš / níž").
     * The logo colours and the added colours are each permuted only among
     * the positions they already hold, so the logo segment stays first
     * (`color(n)` = logo ++ added) whatever is posted. Ids of other manuals
     * and unknown ids are ignored. This order is what `color(n)` indexes —
     * the default logo recolouring uses colours 1–3 — and the order of the
     * manual's colour pages; the palette order is untouched.
     *
     * @param array<string> $linkIds `manual_palette_color` ids, new order
     */
    public function sortColors(UuidInterface $manualId, array $linkIds): void
    {
        $projectId = $this->connection->transactional(function () use ($manualId, $linkIds): null|string {
            $projectId = $this->lockedManualProject($manualId);

            if ($projectId === null) {
                return null;
            }

            /** @var list<array{id: string, logo_hex: null|string, position: int|string}> $rows */
            $rows = $this->connection->fetchAllAssociative('SELECT id, logo_hex, position FROM manual_palette_color WHERE manual_id = ?', [$manualId->toString()]);

            /** @var array<string, array{logo: bool, position: int}> $links */
            $links = [];
            foreach ($rows as $row) {
                $links[$row['id']] = ['logo' => $row['logo_hex'] !== null, 'position' => (int) $row['position']];
            }

            /** @var array{logo: list<string>, added: list<string>} $segments */
            $segments = ['logo' => [], 'added' => []];
            $seen = [];
            foreach ($linkIds as $linkId) {
                $linkId = strtolower($linkId);

                if (isset($links[$linkId]) && !isset($seen[$linkId])) {
                    $segments[$links[$linkId]['logo'] ? 'logo' : 'added'][] = $linkId;
                    $seen[$linkId] = true;
                }
            }

            foreach ($segments as $ids) {
                $slots = array_map(static fn (string $id): int => $links[$id]['position'], $ids);
                sort($slots);

                foreach ($ids as $index => $id) {
                    if ($links[$id]['position'] !== $slots[$index]) {
                        $this->connection->executeStatement('UPDATE manual_palette_color SET position = ? WHERE id = ?', [$slots[$index], $id]);
                    }
                }
            }

            return $projectId;
        });

        if ($projectId !== null) {
            $this->refreshManaged($projectId);
        }
    }

    /**
     * Brings the project's MANAGED entities in step after a DBAL write, so
     * the rest of the request (and a test) reads what was written: palette
     * colours and links are detached (re-hydrated fresh on the next read),
     * every managed manual gets an uninitialised link collection. Not
     * `EntityManager::refresh()` — it throws on the readonly `createdAt`.
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
        }
    }

    /**
     * The manual's project id with the project row locked FOR UPDATE (the
     * lock every palette writer takes), null when the manual does not exist.
     */
    private function lockedManualProject(UuidInterface $manualId): null|string
    {
        $projectId = $this->connection->fetchOne('SELECT project_id FROM manual WHERE id = ?', [$manualId->toString()]);

        if (!is_string($projectId)) {
            return null;
        }

        $this->connection->executeStatement('SELECT id FROM project WHERE id = ? FOR UPDATE', [$projectId]);

        return $projectId;
    }

    /**
     * The palette colour a NEW logo literal links to: the first of the
     * project with the same hex key (palette order), else a new one at the
     * end of the palette — no role, no print data (D3).
     */
    private function paletteColorForLiteral(string $projectId, string $literal, string $now): string
    {
        $hexKey = PaletteColorInput::hexKey($literal);
        $existing = $this->connection->fetchOne(
            'SELECT id FROM project_color WHERE project_id = ? AND hex_key = ? ORDER BY position, id LIMIT 1',
            [$projectId, $hexKey],
        );

        if (is_string($existing)) {
            return $existing;
        }

        $id = $this->provideIdentity->next()->toString();
        $position = $this->connection->fetchOne('SELECT COALESCE(MAX(position) + 1, 0) FROM project_color WHERE project_id = ?', [$projectId]);

        $this->connection->executeStatement(
            'INSERT INTO project_color (id, project_id, hex, hex_key, role, cmyk, pantone, ral, name, position, created_at) VALUES (?, ?, ?, ?, NULL, NULL, NULL, NULL, NULL, ?, ?)',
            [$id, $projectId, $literal, $hexKey, is_numeric($position) ? (int) $position : 0, $now],
        );

        return $id;
    }

    /**
     * The logo's colour literals as {@see \WBoost\Web\Value\Logo::getDetectedColors()}
     * lists them (image order, first occurrence), `#` stripped, case kept.
     * A value that is not a hex (`DetectImageColors` can report `rgb(…)`) is
     * skipped — it cannot be a palette colour.
     *
     * @return list<string>
     */
    private static function logoLiterals(mixed $logo): array
    {
        if (is_string($logo)) {
            $logo = json_decode($logo, true);
        }

        $literals = [];

        foreach (self::LOGO_VARIANTS as $variant) {
            $image = is_array($logo) ? ($logo[$variant] ?? null) : null;

            if (!is_array($image) || !is_array($image['detectedColors'] ?? null)) {
                continue;
            }

            foreach ($image['detectedColors'] as $color) {
                if (!is_string($color) || !Color::isValidHex($color)) {
                    continue;
                }

                $literal = ltrim($color, '#');

                if (!in_array($literal, $literals, true)) {
                    $literals[] = $literal;
                }
            }
        }

        return $literals;
    }
}
