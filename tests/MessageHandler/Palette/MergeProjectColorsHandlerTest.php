<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\MessageHandler\Palette;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use WBoost\Web\Entity\Manual;
use WBoost\Web\Entity\Project;
use WBoost\Web\Exceptions\ProjectColorsNotDuplicates;
use WBoost\Web\Message\Palette\MergeProjectColors;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Value\ManualColor;
use WBoost\Web\Value\ManualType;

/**
 * "Sloučit" (F7 P4) write path: fill the gap, link repoint, double-link
 * collapse, logo links keep their literal, refusals.
 *
 * Fixture: Project 1's palette = red C8102E (primary, position 0) + blue
 * 004E7C (secondary, position 1, no print data), both ADDED colours of
 * Manual 1 (positions 0, 1). Each test adds a same-HEX duplicate of blue.
 *
 * @covers \WBoost\Web\Services\Palette\ProjectPaletteWriter
 * @covers \WBoost\Web\MessageHandler\Palette\MergeProjectColorsHandler
 */
final class MergeProjectColorsHandlerTest extends KernelTestCase
{
    private const string DUPLICATE_ID = '0f7c0000-0000-7000-8000-0000000000d1';
    private const string MANUAL_B_ID = '0f7c0000-0000-7000-8000-0000000000e1';

    public function testFillsTheSurvivorsGapsNeverOverwritesAndTakesTheEarlierPosition(): void
    {
        // Survivor blue: Pantone filled, no CMYK / RAL / name. Duplicate:
        // other Pantone (must NOT win), CMYK + RAL + name (fill the gaps).
        $this->connection()->executeStatement("UPDATE project_color SET pantone = '2945 C' WHERE id = ?", [TestDataFixture::PROJECT_1_COLOR_BLUE_ID]);
        $this->insertDuplicate('004e7c', position: 0, cmyk: '["100", "40", null, "10"]', pantone: '301 C', ral: 'RAL 5005', name: 'Firemní modrá', role: 'primary');
        $this->connection()->executeStatement('UPDATE project_color SET position = 2 WHERE id = ?', [TestDataFixture::PROJECT_1_COLOR_RED_ID]);

        $this->merge(TestDataFixture::PROJECT_1_COLOR_BLUE_ID, [self::DUPLICATE_ID]);

        $row = $this->connection()->fetchAssociative('SELECT hex, role, cmyk, pantone, ral, name, position FROM project_color WHERE id = ?', [TestDataFixture::PROJECT_1_COLOR_BLUE_ID]);
        self::assertIsArray($row);
        self::assertSame('004E7C', $row['hex']);
        self::assertSame('secondary', $row['role']);
        self::assertSame(['100', '40', null, '10'], json_decode(self::str($row['cmyk']), true));
        self::assertSame('2945 C', $row['pantone']);
        self::assertSame('RAL 5005', $row['ral']);
        self::assertSame('Firemní modrá', $row['name']);
        self::assertSame(0, self::intOf($row['position']));
        self::assertFalse($this->connection()->fetchOne('SELECT 1 FROM project_color WHERE id = ?', [self::DUPLICATE_ID]));

        // Manual 1 already showed blue: it gains the filled gaps.
        $manual = $this->manual(TestDataFixture::MANUAL_1_ID);
        self::assertSame(['C8102E', '004E7C'], self::hexes($manual->customColors()));
        self::assertSame(['100', '40', null, '10'], $manual->customColors()[1]->cmyk);
        self::assertSame('RAL 5005', $manual->customColors()[1]->ral);
        self::assertSame('2945 C', $manual->customColors()[1]->pantone);
    }

    public function testRepointsTheLosersLinks(): void
    {
        $this->insertDuplicate('004e7c', position: 5, cmyk: '["100", "40", "0", "10"]', role: 'primary');
        $this->createManual();
        $this->link(self::MANUAL_B_ID, self::DUPLICATE_ID, null, 0);

        $this->merge(TestDataFixture::PROJECT_1_COLOR_BLUE_ID, [self::DUPLICATE_ID]);

        self::assertSame(
            [TestDataFixture::PROJECT_1_COLOR_BLUE_ID],
            $this->connection()->fetchFirstColumn('SELECT project_color_id FROM manual_palette_color WHERE manual_id = ?', [self::MANUAL_B_ID]),
        );

        // Manual B now shows the survivor: palette HEX spelling, its role, merged print data.
        $manual = $this->manual(self::MANUAL_B_ID);
        $color = $manual->customColors()[0];
        self::assertSame('004E7C', $color->color->hex);
        self::assertSame('secondary', $color->type?->value);
        self::assertSame(['100', '40', '0', '10'], $color->cmyk);

        // Manual 1 untouched apart from the gained CMYK.
        self::assertSame(['C8102E', '004E7C'], self::hexes($this->manual(TestDataFixture::MANUAL_1_ID)->customColors()));
    }

    public function testAManualLinkingBothKeepsOneLinkAtTheEarlierPosition(): void
    {
        // Manual 1: duplicate at position 0, red 1, blue 2.
        $this->insertDuplicate('004e7c', position: 5);
        $this->connection()->executeStatement('UPDATE manual_palette_color SET position = position + 1 WHERE manual_id = ?', [TestDataFixture::MANUAL_1_ID]);
        $this->link(TestDataFixture::MANUAL_1_ID, self::DUPLICATE_ID, null, 0);
        self::assertSame(['004e7c', 'C8102E', '004E7C'], self::hexes($this->manual(TestDataFixture::MANUAL_1_ID)->customColors()));

        $this->merge(TestDataFixture::PROJECT_1_COLOR_BLUE_ID, [self::DUPLICATE_ID]);

        /** @var list<array{project_color_id: string, position: int|string}> $links */
        $links = $this->connection()->fetchAllAssociative('SELECT project_color_id, position FROM manual_palette_color WHERE manual_id = ? ORDER BY position', [TestDataFixture::MANUAL_1_ID]);
        self::assertSame([TestDataFixture::PROJECT_1_COLOR_BLUE_ID, TestDataFixture::PROJECT_1_COLOR_RED_ID], array_column($links, 'project_color_id'));
        self::assertSame(0, self::intOf($links[0]['position']));

        $manual = $this->manual(TestDataFixture::MANUAL_1_ID);
        self::assertSame(['004E7C', 'C8102E'], self::hexes($manual->customColors()));
        self::assertSame('004E7C', $manual->color(1)->hex);
    }

    public function testALogoLinkKeepsItsLiteralAndTheAddedTwinIsDropped(): void
    {
        // Manual 1's logo carries the duplicate as the literal "004e7c" (position 0);
        // blue is also an added colour of Manual 1.
        $this->insertDuplicate('004e7c', position: 5, pantone: '301 C');
        $this->connection()->executeStatement('UPDATE manual_palette_color SET position = position + 1 WHERE manual_id = ?', [TestDataFixture::MANUAL_1_ID]);
        $this->link(TestDataFixture::MANUAL_1_ID, self::DUPLICATE_ID, '004e7c', 0);

        $this->merge(TestDataFixture::PROJECT_1_COLOR_BLUE_ID, [self::DUPLICATE_ID]);

        /** @var list<array{project_color_id: string, logo_hex: null|string}> $links */
        $links = $this->connection()->fetchAllAssociative('SELECT project_color_id, logo_hex FROM manual_palette_color WHERE manual_id = ? ORDER BY position', [TestDataFixture::MANUAL_1_ID]);
        self::assertSame([
            ['project_color_id' => TestDataFixture::PROJECT_1_COLOR_BLUE_ID, 'logo_hex' => '004e7c'],
            ['project_color_id' => TestDataFixture::PROJECT_1_COLOR_RED_ID, 'logo_hex' => null],
        ], $links);

        $manual = $this->manual(TestDataFixture::MANUAL_1_ID);
        // The logo colour keeps the SVG literal (recolouring key), with the merged print data.
        self::assertSame(['004e7c'], self::hexes($manual->detectedColors()));
        self::assertSame('301 C', $manual->detectedColors()[0]->pantone);
        self::assertSame(['C8102E'], self::hexes($manual->customColors()));
    }

    public function testTwoLogoLiteralsOfOneHexBothStay(): void
    {
        $this->insertDuplicate('004e7c', position: 5);
        $this->connection()->executeStatement('UPDATE manual_palette_color SET logo_hex = ? WHERE project_color_id = ?', ['004E7C', TestDataFixture::PROJECT_1_COLOR_BLUE_ID]);
        $this->link(TestDataFixture::MANUAL_1_ID, self::DUPLICATE_ID, '004e7c', 2);

        $this->merge(self::DUPLICATE_ID, [TestDataFixture::PROJECT_1_COLOR_BLUE_ID]);

        self::assertSame(
            ['004E7C', '004e7c'],
            $this->connection()->fetchFirstColumn('SELECT logo_hex FROM manual_palette_color WHERE manual_id = ? AND project_color_id = ? ORDER BY position', [TestDataFixture::MANUAL_1_ID, self::DUPLICATE_ID]),
        );
        // The survivor (the later colour) moved to blue's earlier palette place.
        self::assertSame(1, self::intOf($this->connection()->fetchOne('SELECT position FROM project_color WHERE id = ?', [self::DUPLICATE_ID])));
    }

    public function testMergingNonDuplicatesIsRefusedAndChangesNothing(): void
    {
        $before = $this->paletteState();

        // Another HEX.
        $this->expectRefusal(fn () => $this->merge(TestDataFixture::PROJECT_1_COLOR_BLUE_ID, [TestDataFixture::PROJECT_1_COLOR_RED_ID]));
        // Nothing to merge (only the survivor itself).
        $this->expectRefusal(fn () => $this->merge(TestDataFixture::PROJECT_1_COLOR_BLUE_ID, [TestDataFixture::PROJECT_1_COLOR_BLUE_ID]));
        $this->expectRefusal(fn () => $this->merge(TestDataFixture::PROJECT_1_COLOR_BLUE_ID, []));

        // Same HEX in another project.
        $this->connection()->executeStatement(
            "INSERT INTO project_color (id, project_id, hex, hex_key, position, created_at) VALUES (?, ?, '004E7C', '#004e7c', 0, NOW())",
            [self::DUPLICATE_ID, TestDataFixture::PROJECT_2_ID],
        );
        $this->expectRefusal(fn () => $this->merge(TestDataFixture::PROJECT_1_COLOR_BLUE_ID, [self::DUPLICATE_ID]));

        self::assertSame($before, $this->paletteState());
        self::assertSame('1', self::scalar($this->connection()->fetchOne('SELECT COUNT(*) FROM project_color WHERE id = ?', [self::DUPLICATE_ID])));
    }

    /**
     * @param list<string> $loserIds
     */
    private function merge(string $survivorId, array $loserIds): void
    {
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new MergeProjectColors(
            Uuid::fromString($survivorId),
            array_map(static fn (string $id): UuidInterface => Uuid::fromString($id), $loserIds),
        ));
    }

    private function expectRefusal(callable $action): void
    {
        try {
            $action();
            self::fail(ProjectColorsNotDuplicates::class . ' expected');
        } catch (HandlerFailedException $exception) {
            self::assertInstanceOf(ProjectColorsNotDuplicates::class, $exception->getPrevious());
        }
    }

    private function insertDuplicate(string $hex, int $position, null|string $cmyk = null, null|string $pantone = null, null|string $ral = null, null|string $name = null, null|string $role = null): void
    {
        $this->connection()->executeStatement(
            'INSERT INTO project_color (id, project_id, hex, hex_key, role, cmyk, pantone, ral, name, position, created_at) VALUES (?, ?, ?, ?, ?, CAST(? AS JSONB), ?, ?, ?, ?, NOW())',
            [self::DUPLICATE_ID, TestDataFixture::PROJECT_1_ID, $hex, '#' . strtolower($hex), $role, $cmyk, $pantone, $ral, $name, $position],
        );
    }

    private function link(string $manualId, string $colorId, null|string $logoHex, int $position): void
    {
        $this->connection()->executeStatement(
            'INSERT INTO manual_palette_color (id, manual_id, project_color_id, logo_hex, position, created_at) VALUES (?, ?, ?, ?, ?, NOW())',
            [Uuid::uuid7()->toString(), $manualId, $colorId, $logoHex, $position],
        );
    }

    private function createManual(): Manual
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $project = $entityManager->find(Project::class, Uuid::fromString(TestDataFixture::PROJECT_1_ID));
        self::assertInstanceOf(Project::class, $project);
        $manual = new Manual(Uuid::fromString(self::MANUAL_B_ID), $project, new DateTimeImmutable('2024-02-01'), ManualType::Brand, 'Manual B');
        $entityManager->persist($manual);
        $entityManager->flush();

        return $manual;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function paletteState(): array
    {
        return [
            ...$this->connection()->fetchAllAssociative('SELECT * FROM project_color WHERE project_id = ? ORDER BY id', [TestDataFixture::PROJECT_1_ID]),
            ...$this->connection()->fetchAllAssociative('SELECT l.* FROM manual_palette_color l JOIN manual m ON m.id = l.manual_id WHERE m.project_id = ? ORDER BY l.id', [TestDataFixture::PROJECT_1_ID]),
        ];
    }

    private function manual(string $id): Manual
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $manual = $entityManager->find(Manual::class, Uuid::fromString($id));
        self::assertInstanceOf(Manual::class, $manual);

        return $manual;
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }

    /**
     * @param array<ManualColor> $colors
     * @return list<string>
     */
    private static function hexes(array $colors): array
    {
        return array_values(array_map(static fn (ManualColor $color): string => $color->color->hex, $colors));
    }

    private static function intOf(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : -1;
    }

    private static function str(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private static function scalar(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
