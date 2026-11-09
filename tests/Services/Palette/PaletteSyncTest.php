<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\Palette;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use WBoost\Web\Entity\Manual;
use WBoost\Web\Entity\ManualPaletteColor;
use WBoost\Web\Entity\Project;
use WBoost\Web\Entity\ProjectColor;
use WBoost\Web\Message\Manual\DeleteManual;
use WBoost\Web\Message\Manual\UpdateManualImages;
use WBoost\Web\Message\Project\DeleteProject;
use WBoost\Web\MessageHandler\Manual\DeleteManualHandler;
use WBoost\Web\MessageHandler\Manual\UpdateManualImagesHandler;
use WBoost\Web\MessageHandler\Project\DeleteProjectHandler;
use WBoost\Web\Query\GetProjectAvatars;
use WBoost\Web\Query\GetProjectPalette;
use WBoost\Web\Services\Palette\ManualPaletteWriter;
use WBoost\Web\Services\Print\BrandCmykPalette;
use WBoost\Web\Services\SocialNetwork\ResolveRichTextOptions;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Value\CmykColor;
use WBoost\Web\Value\Color;
use WBoost\Web\Value\Logo;
use WBoost\Web\Value\ManualColor;
use WBoost\Web\Value\ManualColorType;
use WBoost\Web\Value\ManualType;
use WBoost\Web\Value\RichText;
use WBoost\Web\Value\SvgImage;

/**
 * F7 palette links over a realistic project: D3 (a logo upload syncs the
 * manual's logo links), the project-wide consumers reading the palette, the
 * linked-only swatch rule, the writer refreshing managed entities, and the
 * delete cascades. Since P5a the links are the only colour storage (no JSONB
 * mirror, no parity with the retired JSONB read model).
 *
 * Project 1 palette, in order: red C8102E (primary) + blue 004E7C (secondary)
 * from the fixtures (Manual 1 adds both), then navy 17375f (primary, 534 C),
 * green a2c62c Y95 (secondary), green a2c62c Y90 (secondary — a split
 * duplicate), white FFF, grey C6C6C6 (K30).
 *  - Brand: logo 17375f, a2c62c, FFF → navy, green Y95, white; adds grey.
 *  - Second: logo 17375f → navy; adds grey.
 *  - Third: no logo; adds green Y90.
 *
 * @covers \WBoost\Web\Services\Palette\ManualPaletteWriter
 * @covers \WBoost\Web\Entity\Manual
 */
final class PaletteSyncTest extends KernelTestCase
{
    private const string MANUAL_BRAND_ID = '0f7a0000-0000-7000-8000-000000000001';
    private const string MANUAL_SECOND_ID = '0f7a0000-0000-7000-8000-000000000002';
    private const string MANUAL_THIRD_ID = '0f7a0000-0000-7000-8000-000000000003';

    private const string NAVY_ID = '0f7b0000-0000-7000-8000-000000000001';
    private const string GREEN_ID = '0f7b0000-0000-7000-8000-000000000002';
    private const string GREEN_Y90_ID = '0f7b0000-0000-7000-8000-000000000003';
    private const string WHITE_ID = '0f7b0000-0000-7000-8000-000000000004';
    private const string GREY_ID = '0f7b0000-0000-7000-8000-000000000005';

    private EntityManagerInterface $entityManager;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->connection = self::getContainer()->get(Connection::class);

        $project = $this->entityManager->find(Project::class, TestDataFixture::PROJECT_1_ID);
        self::assertInstanceOf(Project::class, $project);
        $date = new DateTimeImmutable('2026-02-01');

        $palette = [];
        foreach ([
            [self::NAVY_ID, '17375f', ManualColorType::Primary, ['100', '80', '35', '25'], '534 C'],
            [self::GREEN_ID, 'a2c62c', ManualColorType::Secondary, ['45', '0', '95', '0'], null],
            [self::GREEN_Y90_ID, 'a2c62c', ManualColorType::Secondary, ['45', '0', '90', '0'], null],
            [self::WHITE_ID, 'FFF', null, null, null],
            [self::GREY_ID, 'C6C6C6', null, ['0', '0', '0', '30'], null],
        ] as $index => [$id, $hex, $role, $cmyk, $pantone]) {
            $palette[$id] = new ProjectColor(Uuid::fromString($id), $project, $hex, (string) RichText::normalizeHexColor($hex), $role, $cmyk, $pantone, null, 2 + $index, $date);
            $this->entityManager->persist($palette[$id]);
        }

        $this->manual($project, self::MANUAL_BRAND_ID, '2026-02-01', ManualType::Brand, 'Brand', ['#17375f', '#a2c62c', '#FFF'], [
            [self::NAVY_ID, '17375f'], [self::GREEN_ID, 'a2c62c'], [self::WHITE_ID, 'FFF'], [self::GREY_ID, null],
        ], $palette);
        $this->manual($project, self::MANUAL_SECOND_ID, '2026-02-02', ManualType::Logo, 'Second', ['#17375f'], [
            [self::NAVY_ID, '17375f'], [self::GREY_ID, null],
        ], $palette);
        $this->manual($project, self::MANUAL_THIRD_ID, '2026-02-03', ManualType::Logo, 'Third', [], [
            [self::GREEN_Y90_ID, null],
        ], $palette);

        $this->entityManager->flush();
        $this->entityManager->clear();
    }

    /**
     * D3: a new logo colour reuses the project colour of the same hex key
     * (role untouched) or is appended to the palette without a role; the
     * logo segment comes first, in logo (DetectImageColors) order.
     */
    public function testLogoUploadAddsNewColoursWithoutARole(): void
    {
        $paletteBefore = $this->paletteHexes();

        $this->uploadLogo(self::MANUAL_THIRD_ID, '<svg xmlns="http://www.w3.org/2000/svg"><rect fill="#17375f"/><rect fill="#123ABC"/></svg>');

        self::assertSame('primary', $this->connection->fetchOne('SELECT role FROM project_color WHERE id = ?', [self::NAVY_ID]));
        self::assertSame([...$paletteBefore, '123ABC'], $this->paletteHexes(), 'only #123ABC is new, at the end');
        self::assertNull($this->connection->fetchOne("SELECT role FROM project_color WHERE project_id = ? AND hex_key = '#123abc'", [TestDataFixture::PROJECT_1_ID]));

        /** @var list<array{logo_hex: null|string, project_color_id: string, position: int}> $links */
        $links = $this->connection->fetchAllAssociative('SELECT logo_hex, project_color_id, position FROM manual_palette_color WHERE manual_id = ? ORDER BY position', [self::MANUAL_THIRD_ID]);
        self::assertSame(['123ABC', '17375f', null], array_column($links, 'logo_hex'), 'logo segment first, then the added green');
        self::assertSame(self::NAVY_ID, $links[1]['project_color_id']);
        self::assertSame(self::GREEN_Y90_ID, $links[2]['project_color_id']);
        self::assertSame([0, 1, 2], array_column($links, 'position'));

        $third = $this->manualEntity(self::MANUAL_THIRD_ID);
        self::assertSame(['123ABC', '17375f', 'a2c62c'], array_map(static fn (int $n): string => $third->color($n)->hex, [1, 2, 3]));
        self::assertSame(['17375f'], self::hexes($third->primaryColors()));
    }

    /**
     * D3: a colour that leaves the logo loses its link but stays in the
     * palette; the remaining links keep their ids and palette colours.
     */
    public function testLogoReuploadUnlinksColoursThatLeftTheLogo(): void
    {
        $paletteBefore = $this->paletteHexes();
        $navyLink = $this->linkIds(self::MANUAL_BRAND_ID)[0];

        $this->uploadLogo(self::MANUAL_BRAND_ID, '<svg xmlns="http://www.w3.org/2000/svg"><rect fill="#17375f"/></svg>');

        self::assertSame($paletteBefore, $this->paletteHexes());
        $brand = $this->manualEntity(self::MANUAL_BRAND_ID);
        self::assertSame(['17375f'], self::hexes($brand->detectedColors()));
        self::assertSame('534 C', $brand->detectedColors()[0]->pantone, 'the remaining logo colour keeps its palette colour');
        self::assertSame(['C6C6C6'], self::hexes($brand->customColors()));
        self::assertSame($navyLink, $this->linkIds(self::MANUAL_BRAND_ID)[0]);
        self::assertSame([0, 1], $this->linkPositions(self::MANUAL_BRAND_ID));

        // Green Y95 and white are now unused: kept in the palette, no swatch.
        self::assertNotContains('#ffffff', $this->swatches());
    }

    /**
     * Re-uploading a logo with the same colours changes nothing — even when
     * the new file lists them in another order (the manual's order stays).
     */
    public function testReuploadWithTheSameColoursKeepsEveryLink(): void
    {
        $before = $this->linkRows(self::MANUAL_BRAND_ID);

        $this->storeLogo(self::MANUAL_BRAND_ID, ['#FFF', '#a2c62c', '#17375f']);
        self::getContainer()->get(ManualPaletteWriter::class)->syncLogoColors(Uuid::fromString(self::MANUAL_BRAND_ID));

        self::assertSame($before, $this->linkRows(self::MANUAL_BRAND_ID));
    }

    /**
     * A literal is compared exactly (recolouring replaces that spelling on
     * the SVG): `FFF` → `ffffff` is a new logo link (reusing the white of the
     * same hex key) and the old one goes. Non-hex detections are ignored.
     */
    public function testALiteralSpelledDifferentlyIsReplacedAndNonHexIsIgnored(): void
    {
        $this->storeLogo(self::MANUAL_BRAND_ID, ['#17375f', '#a2c62c', '#ffffff', 'rgb(1, 2, 3)', '#ffffff']);
        self::getContainer()->get(ManualPaletteWriter::class)->syncLogoColors(Uuid::fromString(self::MANUAL_BRAND_ID));

        /** @var list<array{logo_hex: null|string, project_color_id: string}> $links */
        $links = $this->connection->fetchAllAssociative('SELECT logo_hex, project_color_id FROM manual_palette_color WHERE manual_id = ? ORDER BY position', [self::MANUAL_BRAND_ID]);
        self::assertSame(['17375f', 'a2c62c', 'ffffff', null], array_column($links, 'logo_hex'));
        self::assertSame([self::NAVY_ID, self::GREEN_ID, self::WHITE_ID, self::GREY_ID], array_column($links, 'project_color_id'));
        self::assertSame('ffffff', $this->manualEntity(self::MANUAL_BRAND_ID)->color(3)->hex);
    }

    /**
     * Swatches, print CMYK and the avatar read the palette (palette order,
     * linked colours only, first CMYK of a hex wins).
     */
    public function testProjectWideConsumersReadThePalette(): void
    {
        $projectId = Uuid::fromString(TestDataFixture::PROJECT_1_ID);

        self::assertSame(['#c8102e', '#004e7c', '#17375f', '#a2c62c', '#ffffff', '#c6c6c6'], $this->swatches());

        $cmyk = self::getContainer()->get(BrandCmykPalette::class)->forProject($projectId);
        self::assertEquals(CmykColor::fromManualColor(new ManualColor(new Color('a2c62c'), null, null, ['45', '0', '95', '0'])), $cmyk['#a2c62c'], 'split duplicate: the first in palette order wins');

        $project = $this->entityManager->find(Project::class, TestDataFixture::PROJECT_1_ID);
        self::assertInstanceOf(Project::class, $project);
        // Brand manual first: its first non-white primary is the navy.
        self::assertSame('17375f', self::getContainer()->get(GetProjectAvatars::class)->forProject($project)->backgroundHex);
    }

    /**
     * Linked-only palette: a colour no manual links any more stays in the
     * palette (never deleted by a manual write) but is no swatch and no
     * print CMYK.
     */
    public function testAnUnlinkedPaletteColourIsNoSwatch(): void
    {
        $projectId = Uuid::fromString(TestDataFixture::PROJECT_1_ID);
        $writer = self::getContainer()->get(ManualPaletteWriter::class);
        $this->connection->executeStatement("INSERT INTO project_color (id, project_id, hex, hex_key, role, cmyk, position, created_at) VALUES ('0f7b0000-0000-7000-8000-0000000000ff', ?, 'D03F50', '#d03f50', NULL, '[\"5\", \"85\", \"55\", \"10\"]', 20, NOW())", [TestDataFixture::PROJECT_1_ID]);
        self::assertNotContains('#d03f50', $this->swatches(), 'not linked yet');

        self::assertSame(1, $writer->addColors(Uuid::fromString(self::MANUAL_THIRD_ID), ['0f7b0000-0000-7000-8000-0000000000ff']));
        self::assertContains('#d03f50', $this->swatches());
        self::assertArrayHasKey('#d03f50', self::getContainer()->get(BrandCmykPalette::class)->forProject($projectId));

        $link = $this->connection->fetchOne("SELECT id FROM manual_palette_color WHERE project_color_id = '0f7b0000-0000-7000-8000-0000000000ff'");
        self::assertIsString($link);
        $writer->removeColor(Uuid::fromString(self::MANUAL_THIRD_ID), Uuid::fromString($link));

        self::assertContains('D03F50', $this->paletteHexes(), 'never deleted');
        self::assertNotContains('D03F50', array_map(static fn (ProjectColor $color): string => $color->hex, self::getContainer()->get(GetProjectPalette::class)->forProject($projectId)));
        self::assertNotContains('#d03f50', $this->swatches());
        self::assertArrayNotHasKey('#d03f50', self::getContainer()->get(BrandCmykPalette::class)->forProject($projectId));
    }

    /**
     * The writer works over DBAL; the request that wrote must still read the
     * new colours from the entities it already holds.
     */
    public function testTheWritingRequestReadsWhatItWrote(): void
    {
        $brand = $this->manualEntity(self::MANUAL_BRAND_ID);
        self::assertSame(4, $brand->colorsCount());

        self::getContainer()->get(ManualPaletteWriter::class)->addColors($brand->id, [TestDataFixture::PROJECT_1_COLOR_RED_ID]);

        self::assertSame(5, $brand->colorsCount());
        self::assertSame('C8102E', $brand->color(5)->hex);
        self::assertSame(['17375f', 'C8102E'], self::hexes($brand->primaryColors()));
    }

    /**
     * Deleting a manual takes its links (the palette stays); deleting the
     * project takes everything — through the ORM, with the EAGER links loaded.
     * P1a's NO ACTION link → palette FK broke the project delete whenever
     * Postgres fired the palette cascade first (Version20261105193000).
     */
    public function testDeletingAManualOrTheProjectCascadesThePalette(): void
    {
        $paletteBefore = $this->paletteHexes();
        self::assertSame(4, $this->manualEntity(self::MANUAL_BRAND_ID)->colorsCount(), 'links loaded before the delete');

        self::getContainer()->get(DeleteManualHandler::class)(new DeleteManual(Uuid::fromString(self::MANUAL_BRAND_ID)));
        $this->entityManager->flush();
        $this->entityManager->clear();

        self::assertSame(0, $this->rowCount('SELECT COUNT(*) FROM manual_palette_color WHERE manual_id = ?', [self::MANUAL_BRAND_ID]));
        self::assertSame($paletteBefore, $this->paletteHexes(), 'palette colours survive a manual delete');
        $second = $this->manualEntity(self::MANUAL_SECOND_ID);
        self::assertSame(['17375f', 'C6C6C6'], self::hexes([...$second->detectedColors(), ...$second->customColors()]), 'other manuals keep their links');

        self::getContainer()->get(DeleteProjectHandler::class)(new DeleteProject(Uuid::fromString(TestDataFixture::PROJECT_1_ID)));

        self::assertSame(0, $this->rowCount('SELECT COUNT(*) FROM project_color WHERE project_id = ?', [TestDataFixture::PROJECT_1_ID]));
        self::assertSame(0, $this->rowCount('SELECT COUNT(*) FROM manual_palette_color WHERE manual_id IN (?, ?)', [self::MANUAL_SECOND_ID, self::MANUAL_THIRD_ID]));
    }

    private function uploadLogo(string $manualId, string $svgContent): void
    {
        $svg = sys_get_temp_dir() . '/palette-logo-' . bin2hex(random_bytes(4)) . '.svg';
        file_put_contents($svg, $svgContent);

        try {
            $handler = self::getContainer()->get(UpdateManualImagesHandler::class);
            $handler(new UpdateManualImages(
                Uuid::fromString($manualId),
                new UploadedFile($svg, 'logo.svg', 'image/svg+xml', null, true), null, null, null, false,
                null, null, null, null, false,
                null, null, null, null, false,
                null, null, null, null, false,
                null, null, null, null, false,
            ));
        } finally {
            @unlink($svg);
        }

        $this->entityManager->clear();
    }

    /**
     * Stores a horizontal logo with the given detected colours (no upload).
     *
     * @param list<string> $detectedColors
     */
    private function storeLogo(string $manualId, array $detectedColors): void
    {
        $manual = $this->manualEntity($manualId);
        $manual->editLogo(self::logo($manualId, $detectedColors));
        $this->entityManager->flush();
        $this->entityManager->clear();
    }

    /**
     * @param list<string> $detectedColors
     */
    private static function logo(string $manualId, array $detectedColors): Logo
    {
        return new Logo(
            horizontal: $detectedColors === [] ? null : new SvgImage('fixtures/palette/' . $manualId . '.svg', $detectedColors),
            vertical: null,
            horizontalWithClaim: null,
            verticalWithClaim: null,
            symbol: null,
        );
    }

    /**
     * @return list<string>
     */
    private function swatches(): array
    {
        $this->entityManager->clear();

        return self::getContainer()->get(ResolveRichTextOptions::class)->forProject(Uuid::fromString(TestDataFixture::PROJECT_1_ID))->colors;
    }

    private function manualEntity(string $manualId): Manual
    {
        $manual = $this->entityManager->find(Manual::class, $manualId);
        self::assertInstanceOf(Manual::class, $manual);

        return $manual;
    }

    /**
     * @param array<ManualColor> $colors
     * @return list<string>
     */
    private static function hexes(array $colors): array
    {
        return array_values(array_map(static fn (ManualColor $color): string => $color->color->hex, $colors));
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
    private function linkIds(string $manualId): array
    {
        /** @var list<string> $ids */
        $ids = $this->connection->fetchFirstColumn('SELECT id FROM manual_palette_color WHERE manual_id = ? ORDER BY position', [$manualId]);

        return $ids;
    }

    /**
     * @return list<int>
     */
    private function linkPositions(string $manualId): array
    {
        /** @var list<int> $positions */
        $positions = $this->connection->fetchFirstColumn('SELECT position FROM manual_palette_color WHERE manual_id = ? ORDER BY position', [$manualId]);

        return $positions;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function linkRows(string $manualId): array
    {
        return $this->connection->fetchAllAssociative('SELECT id, project_color_id, logo_hex, position FROM manual_palette_color WHERE manual_id = ? ORDER BY position', [$manualId]);
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
     * @param list<array{string, null|string}> $links palette colour id + logo literal (null = added)
     * @param array<string, ProjectColor> $palette
     */
    private function manual(Project $project, string $id, string $date, ManualType $type, string $name, array $logo, array $links, array $palette): void
    {
        $createdAt = new DateTimeImmutable($date);
        $manual = new Manual(Uuid::fromString($id), $project, $createdAt, $type, $name);
        $manual->editLogo(self::logo($id, $logo));
        $this->entityManager->persist($manual);

        foreach ($links as $position => [$colorId, $logoHex]) {
            $this->entityManager->persist(new ManualPaletteColor(Uuid::uuid7(), $manual, $palette[$colorId], $logoHex, $position, $createdAt));
        }
    }
}
