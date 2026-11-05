<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\Palette;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use WBoost\Web\Entity\Manual;
use WBoost\Web\Entity\Project;
use WBoost\Web\Message\Manual\EditManualColors;
use WBoost\Web\Message\Manual\UpdateManualImages;
use WBoost\Web\MessageHandler\Manual\EditManualColorsHandler;
use WBoost\Web\MessageHandler\Manual\UpdateManualImagesHandler;
use WBoost\Web\Repository\ManualPaletteColorRepository;
use WBoost\Web\Repository\ProjectColorRepository;
use WBoost\Web\Services\Palette\PaletteBackfillSql;
use WBoost\Web\Services\Palette\VerifyPalette;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Value\Color;
use WBoost\Web\Value\Logo;
use WBoost\Web\Value\ManualColor;
use WBoost\Web\Value\ManualColorType;
use WBoost\Web\Value\ManualType;
use WBoost\Web\Value\PaletteParityDiff;
use WBoost\Web\Value\SvgImage;

/**
 * F7 P1a: the backfill migration over a realistic project (fixtures + manuals
 * built here) and the write-path sync — both judged by the same
 * {@see VerifyPalette} the prod check runs.
 *
 * @covers \WBoost\Web\Services\Palette\PaletteBackfillSql
 * @covers \WBoost\Web\Services\Palette\ProjectManualColors
 * @covers \WBoost\Web\Services\Palette\VerifyPalette
 */
final class PaletteSyncTest extends KernelTestCase
{
    private const string MANUAL_BRAND_ID = '0f7a0000-0000-7000-8000-000000000001';
    private const string MANUAL_SECOND_ID = '0f7a0000-0000-7000-8000-000000000002';
    private const string MANUAL_THIRD_ID = '0f7a0000-0000-7000-8000-000000000003';

    private const array CMYK_NAVY = ['100', '80', '35', '25'];

    private EntityManagerInterface $entityManager;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->connection = self::getContainer()->get(Connection::class);

        $project = $this->entityManager->find(Project::class, TestDataFixture::PROJECT_1_ID);
        self::assertInstanceOf(Project::class, $project);

        // Brand manual: logo colours, typed detected colours with print data, custom colours.
        $this->manual($project, self::MANUAL_BRAND_ID, '2026-02-01', ManualType::Brand, 'Brand',
            logo: ['#17375f', '#a2c62c', '#FFF'],
            detected: [
                new ManualColor(new Color('17375f'), ManualColorType::Primary, '534 C', self::CMYK_NAVY),
                new ManualColor(new Color('a2c62c'), ManualColorType::Secondary, null, ['45', '0', '95', '0']),
            ],
            custom: [new ManualColor(new Color('C6C6C6'), null, null, ['0', '0', '0', '30'])],
        );

        // Second: same navy as a SECONDARY (role conflict → tie → primary),
        // without the Pantone (fill the gap), and a case variant of the grey.
        $this->manual($project, self::MANUAL_SECOND_ID, '2026-02-02', ManualType::Logo, 'Second',
            logo: ['#17375f'],
            detected: [new ManualColor(new Color('17375f'), ManualColorType::Secondary, null, self::CMYK_NAVY)],
            custom: [new ManualColor(new Color('c6c6c6'), null, null, ['0', '0', '0', '30'])],
        );

        // Third: a conflicting CMYK for the green (split duplicate, first wins in print).
        $this->manual($project, self::MANUAL_THIRD_ID, '2026-02-03', ManualType::Logo, 'Third',
            logo: [],
            detected: [],
            custom: [new ManualColor(new Color('a2c62c'), ManualColorType::Secondary, null, ['45', '0', '90', '0'])],
        );

        $this->entityManager->flush();
        $this->entityManager->clear();
    }

    public function testBackfillReproducesTheJsonbReadModel(): void
    {
        $this->backfill();

        $diffs = $this->diffs();
        self::assertSame([], self::unexpected($diffs));

        // Exactly the owner-accepted kinds this data provokes.
        $kinds = array_values(array_unique(array_map(static fn (PaletteParityDiff $diff): string => $diff->kind, $diffs)));
        sort($kinds);
        self::assertSame(['custom_hex_spelling', 'print_gap_filled', 'role', 'role_pages'], $kinds);

        // The ORM side reads what the migration wrote, in palette order.
        $palette = self::getContainer()->get(ProjectColorRepository::class)->forProject(Uuid::fromString(TestDataFixture::PROJECT_1_ID));
        self::assertSame(
            ['C8102E', '17375f', '004E7C', 'a2c62c', 'a2c62c', 'FFF', 'C6C6C6'],
            array_map(static fn ($color): string => $color->hex, $palette),
        );
        self::assertSame(ManualColorType::Primary, $palette[1]->role, 'navy: primary vs secondary tie → primary');
        self::assertSame('534 C', $palette[1]->pantone, 'fill the gap: Second\'s navy gains the Pantone');

        $links = self::getContainer()->get(ManualPaletteColorRepository::class)->forManual(Uuid::fromString(self::MANUAL_BRAND_ID));
        self::assertSame(['17375f', 'a2c62c', 'FFF', null], array_map(static fn ($link): null|string => $link->logoHex, $links));
        self::assertSame([0, 1, 2, 3], array_map(static fn ($link): int => $link->position, $links));
    }

    public function testBackfillIsIdempotent(): void
    {
        $this->backfill();
        $before = $this->tableHash();

        $this->backfill();
        self::assertSame($before, $this->tableHash(), 'a replay inserts nothing');

        // A project that got a palette at runtime is skipped as a whole; its
        // planned links pointing at never-inserted palette ids are dropped.
        $this->connection->executeStatement('DELETE FROM manual_palette_color WHERE manual_id = ?', [self::MANUAL_THIRD_ID]);
        $this->backfill();
        self::assertSame(0, $this->rowCount('SELECT COUNT(*) FROM manual_palette_color WHERE manual_id = ?', [self::MANUAL_THIRD_ID]), 'links only come with a freshly inserted palette — reconcile restores these');
    }

    public function testEditColorsReprojectsLinksAndNeverDeletesPaletteColours(): void
    {
        $this->backfill();
        $paletteBefore = $this->paletteHexes();

        // Third drops the green and adds a new primary colour.
        $this->editColors(self::MANUAL_THIRD_ID, [], [new ManualColor(new Color('#D03F50'), ManualColorType::Primary, null, ['5', '85', '55', '10'])]);

        self::assertSame([...$paletteBefore, 'D03F50'], $this->paletteHexes(), 'new colour appended, the unused green kept');
        self::assertSame('primary', $this->connection->fetchOne("SELECT role FROM project_color WHERE project_id = ? AND hex = 'D03F50'", [TestDataFixture::PROJECT_1_ID]));
        self::assertSame(['D03F50'], $this->linkedHexes(self::MANUAL_THIRD_ID));

        $diffs = $this->diffs();
        self::assertSame([], self::unexpected($diffs));
        // Third's new primary is a swatch BEFORE the secondaries today; in the palette it is last (D5).
        self::assertContains('palette_order', array_map(static fn (PaletteParityDiff $diff): string => $diff->kind, $diffs));
    }

    public function testRoleIsLastWriteWinsButAnUntouchedSaveKeepsIt(): void
    {
        $this->backfill();
        $navyRole = fn (): mixed => $this->connection->fetchOne("SELECT role FROM project_color WHERE project_id = ? AND hex_key = '#17375f'", [TestDataFixture::PROJECT_1_ID]);
        self::assertSame('primary', $navyRole());

        // Saving Second unchanged (the old colours page posts every type) must not flip the project role.
        $second = $this->manualEntity(self::MANUAL_SECOND_ID);
        $this->editColors(self::MANUAL_SECOND_ID, $second->detectedColors(), $second->customColors);
        self::assertSame('primary', $navyRole());

        // Brand retypes navy as secondary → the project colour follows (D2, last write wins).
        $brand = $this->manualEntity(self::MANUAL_BRAND_ID);
        $detected = $brand->detectedColors();
        $detected[0] = new ManualColor($detected[0]->color, ManualColorType::Secondary, $detected[0]->pantone, $detected[0]->cmyk);
        $this->editColors(self::MANUAL_BRAND_ID, $detected, $brand->customColors);
        self::assertSame('secondary', $navyRole());

        self::assertSame([], self::unexpected($this->diffs()));
    }

    public function testEditColorsFillsTheGapOfTheSharedColour(): void
    {
        $this->backfill();

        $brand = $this->manualEntity(self::MANUAL_BRAND_ID);
        $custom = [new ManualColor(new Color('C6C6C6'), null, '420C', ['0', '0', '0', '30'], 'RAL 7035')];
        $this->editColors(self::MANUAL_BRAND_ID, $brand->detectedColors(), $custom);

        /** @var array{pantone: null|string, ral: null|string} $grey */
        $grey = $this->connection->fetchAssociative("SELECT pantone, ral FROM project_color WHERE project_id = ? AND hex_key = '#c6c6c6'", [TestDataFixture::PROJECT_1_ID]);
        self::assertSame(['pantone' => '420C', 'ral' => 'RAL 7035'], $grey);
        self::assertSame(1, $this->rowCount("SELECT COUNT(*) FROM project_color WHERE project_id = ? AND hex_key = '#c6c6c6'", [TestDataFixture::PROJECT_1_ID]));

        // Second now "gains" them in the palette read model — an accepted difference.
        $diffs = $this->diffs();
        self::assertSame([], self::unexpected($diffs));
        self::assertNotEmpty(array_filter($diffs, static fn (PaletteParityDiff $diff): bool => $diff->kind === 'print_gap_filled' && $diff->manual === 'Second' && str_contains($diff->detail, 'RAL 7035')));
    }

    public function testLogoUploadAddsNewColoursWithoutARole(): void
    {
        $this->backfill();

        $svg = sys_get_temp_dir() . '/palette-logo-' . bin2hex(random_bytes(4)) . '.svg';
        file_put_contents($svg, '<svg xmlns="http://www.w3.org/2000/svg"><rect fill="#17375f"/><rect fill="#123ABC"/></svg>');

        try {
            $handler = self::getContainer()->get(UpdateManualImagesHandler::class);
            $handler(new UpdateManualImages(
                Uuid::fromString(self::MANUAL_THIRD_ID),
                new UploadedFile($svg, 'logo.svg', 'image/svg+xml', null, true), null, null, null, false,
                null, null, null, null, false,
                null, null, null, null, false,
                null, null, null, null, false,
                null, null, null, null, false,
            ));
        } finally {
            @unlink($svg);
        }

        // Navy reuses the project colour (role untouched), #123ABC is appended without a role.
        self::assertSame('primary', $this->connection->fetchOne("SELECT role FROM project_color WHERE project_id = ? AND hex_key = '#17375f'", [TestDataFixture::PROJECT_1_ID]));
        self::assertNull($this->connection->fetchOne("SELECT role FROM project_color WHERE project_id = ? AND hex_key = '#123abc'", [TestDataFixture::PROJECT_1_ID]));
        $hexes = $this->paletteHexes();
        self::assertSame('123ABC', end($hexes));

        /** @var list<array{logo_hex: null|string}> $links */
        $links = $this->connection->fetchAllAssociative('SELECT logo_hex FROM manual_palette_color WHERE manual_id = ? ORDER BY position', [self::MANUAL_THIRD_ID]);
        self::assertSame(['123ABC', '17375f', null], array_column($links, 'logo_hex'), 'logo segment (DetectImageColors order) first, then the added green');

        self::assertSame([], self::unexpected($this->diffs()));
    }

    public function testVerifyFlagsLinksThatDoNotMatchTheJsonb(): void
    {
        $this->backfill();
        $this->connection->executeStatement('DELETE FROM manual_palette_color WHERE manual_id = ?', [self::MANUAL_SECOND_ID]);

        $kinds = array_values(array_unique(array_map(static fn (PaletteParityDiff $diff): string => $diff->kind, self::unexpected($this->diffs()))));
        sort($kinds);
        self::assertSame(['custom_count', 'detected_count', 'role_pages_unexplained'], $kinds);
    }

    private function backfill(): void
    {
        foreach (PaletteBackfillSql::statements($this->connection) as $statement) {
            $this->connection->executeStatement($statement['sql'], $statement['params']);
        }
    }

    /**
     * @return list<PaletteParityDiff>
     */
    private function diffs(): array
    {
        $this->entityManager->clear();
        $diffs = [];

        foreach ($this->entityManager->getRepository(Project::class)->findAll() as $project) {
            array_push($diffs, ...self::getContainer()->get(VerifyPalette::class)->project($project)['diffs']);
        }

        return $diffs;
    }

    /**
     * @param list<PaletteParityDiff> $diffs
     * @return list<PaletteParityDiff>
     */
    private static function unexpected(array $diffs): array
    {
        return array_values(array_filter($diffs, static fn (PaletteParityDiff $diff): bool => !$diff->accepted()));
    }

    /**
     * @param array<ManualColor> $detected
     * @param array<ManualColor> $custom
     */
    private function editColors(string $manualId, array $detected, array $custom): void
    {
        $handler = self::getContainer()->get(EditManualColorsHandler::class);
        $handler(new EditManualColors(Uuid::fromString($manualId), array_values($detected), array_values($custom)));
        $this->entityManager->flush();
        $this->entityManager->clear();
    }

    private function manualEntity(string $manualId): Manual
    {
        $manual = $this->entityManager->find(Manual::class, $manualId);
        self::assertInstanceOf(Manual::class, $manual);

        return $manual;
    }

    /**
     * @return list<string>
     */
    private function paletteHexes(): array
    {
        /** @var list<string> $hexes */
        $hexes = $this->connection->fetchFirstColumn('SELECT hex FROM project_color WHERE project_id = ? ORDER BY position, id', [TestDataFixture::PROJECT_1_ID]);

        return $hexes;
    }

    /**
     * @return list<string>
     */
    private function linkedHexes(string $manualId): array
    {
        /** @var list<string> $hexes */
        $hexes = $this->connection->fetchFirstColumn(
            'SELECT COALESCE(l.logo_hex, c.hex) FROM manual_palette_color l JOIN project_color c ON c.id = l.project_color_id WHERE l.manual_id = ? ORDER BY l.position',
            [$manualId],
        );

        return $hexes;
    }

    private function tableHash(): string
    {
        $hash = $this->connection->fetchOne(
            "SELECT md5(string_agg(x, ',' ORDER BY x)) FROM ("
            . "SELECT concat_ws('|', id, project_id, hex, hex_key, role, cmyk::text, pantone, ral, position) x FROM project_color "
            . "UNION ALL SELECT concat_ws('|', id, manual_id, project_color_id, logo_hex, position) FROM manual_palette_color) t",
        );
        self::assertIsString($hash);

        return $hash;
    }

    /**
     * @param list<string> $params
     */
    private function rowCount(string $sql, array $params = []): int
    {
        $count = $this->connection->fetchOne($sql, $params);
        self::assertIsInt($count);

        return $count;
    }

    /**
     * @param list<string> $logo
     * @param list<ManualColor> $detected
     * @param list<ManualColor> $custom
     */
    private function manual(Project $project, string $id, string $date, ManualType $type, string $name, array $logo, array $detected, array $custom): UuidInterface
    {
        $manual = new Manual(Uuid::fromString($id), $project, new DateTimeImmutable($date), $type, $name, null);
        $manual->editLogo(new Logo(
            horizontal: $logo === [] ? null : new SvgImage('fixtures/palette/' . $id . '.svg', $logo),
            vertical: null,
            horizontalWithClaim: null,
            verticalWithClaim: null,
            symbol: null,
        ));
        $manual->editColors($detected, $custom);
        $this->entityManager->persist($manual);

        return $manual->id;
    }
}
