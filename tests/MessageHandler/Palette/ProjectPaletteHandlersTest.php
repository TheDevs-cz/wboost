<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\MessageHandler\Palette;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use WBoost\Web\Entity\Manual;
use WBoost\Web\Exceptions\ProjectColorHexLocked;
use WBoost\Web\Exceptions\ProjectColorInLogo;
use WBoost\Web\Exceptions\ProjectColorUsedInManuals;
use WBoost\Web\Message\Palette\AddProjectColor;
use WBoost\Web\Message\Palette\DeleteProjectColor;
use WBoost\Web\Message\Palette\EditProjectColor;
use WBoost\Web\Message\Palette\SortProjectColors;
use WBoost\Web\Query\GetProjectPalette;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Value\ManualColor;
use WBoost\Web\Value\ProjectColorField;

/**
 * Paleta barev write path (F7 P2): one palette colour edit reaches every
 * manual linking it — read model AND the R2 JSONB mirror R1 code reads.
 *
 * Fixture: Project 1's palette = red C8102E (primary, position 0) + blue
 * 004E7C (secondary, position 1), both ADDED colours of Manual 1.
 *
 * @covers \WBoost\Web\Services\Palette\ProjectPaletteWriter
 * @covers \WBoost\Web\MessageHandler\Palette\EditProjectColorHandler
 * @covers \WBoost\Web\MessageHandler\Palette\AddProjectColorHandler
 * @covers \WBoost\Web\MessageHandler\Palette\SortProjectColorsHandler
 * @covers \WBoost\Web\MessageHandler\Palette\DeleteProjectColorHandler
 */
final class ProjectPaletteHandlersTest extends KernelTestCase
{
    public function testRoleChangeMovesTheColourOnEveryLinkedManual(): void
    {
        $this->dispatch(new EditProjectColor(Uuid::fromString(TestDataFixture::PROJECT_1_COLOR_BLUE_ID), ProjectColorField::Role, 'primary'));

        $manual = $this->manual();
        self::assertSame(['C8102E', '004E7C'], self::hexes($manual->primaryColors()));
        self::assertSame([], $manual->secondaryColors());
        self::assertSame(['primary', 'primary'], array_map(static fn (ManualColor $c): ?string => $c->type?->value, $manual->customColors()));
    }

    public function testPrintDataAndHexEditsReachTheManual(): void
    {
        $blue = Uuid::fromString(TestDataFixture::PROJECT_1_COLOR_BLUE_ID);
        $this->dispatch(new EditProjectColor($blue, ProjectColorField::Pantone, '534 C'));
        $this->dispatch(new EditProjectColor($blue, ProjectColorField::Cmyk, null, ['100', '60', null, '20']));
        $this->dispatch(new EditProjectColor($blue, ProjectColorField::Hex, '1A2B3C'));
        $this->dispatch(new EditProjectColor($blue, ProjectColorField::Name, 'Firemní modrá'));

        $manual = $this->manual();
        $color = $manual->customColors()[1];
        self::assertSame('1A2B3C', $color->color->hex);
        self::assertSame('534 C', $color->pantone);
        self::assertSame(['100', '60', null, '20'], $color->cmyk);
        self::assertSame('1A2B3C', $manual->color(2)->hex);

        $row = $this->connection()->fetchAssociative('SELECT hex_key, name FROM project_color WHERE id = ?', [TestDataFixture::PROJECT_1_COLOR_BLUE_ID]);
        self::assertSame(['hex_key' => '#1a2b3c', 'name' => 'Firemní modrá'], $row);
    }

    public function testHexOfALogoColourIsLocked(): void
    {
        $this->linkFromLogo(TestDataFixture::PROJECT_1_COLOR_BLUE_ID, '004E7C');

        $this->expectHandlerException(ProjectColorHexLocked::class, fn () => $this->dispatch(new EditProjectColor(Uuid::fromString(TestDataFixture::PROJECT_1_COLOR_BLUE_ID), ProjectColorField::Hex, '111111')));
        self::assertSame('004E7C', $this->connection()->fetchOne('SELECT hex FROM project_color WHERE id = ?', [TestDataFixture::PROJECT_1_COLOR_BLUE_ID]));
    }

    public function testAddedColourIsAppendedUnlinkedAndNotASwatchYet(): void
    {
        $id = Uuid::uuid7();
        $this->dispatch(new AddProjectColor($id, Uuid::fromString(TestDataFixture::PROJECT_1_ID), '7A5CC4'));

        $row = $this->connection()->fetchAssociative('SELECT hex, hex_key, role, position FROM project_color WHERE id = ?', [$id->toString()]);
        self::assertSame(['hex' => '7A5CC4', 'hex_key' => '#7a5cc4', 'role' => null, 'position' => 2], $row);
        // Linked-only for templates / print / API until a manual picks it (P3).
        self::assertCount(2, self::getContainer()->get(GetProjectPalette::class)->forProject(Uuid::fromString(TestDataFixture::PROJECT_1_ID)));
    }

    public function testSortPermutesOnlyThePositionsTheGivenColoursHold(): void
    {
        $extra = Uuid::uuid7();
        $this->dispatch(new AddProjectColor($extra, Uuid::fromString(TestDataFixture::PROJECT_1_ID), '7A5CC4'));

        // Swap red and the new colour; blue (position 1) keeps its slot. A
        // foreign / malformed id is ignored.
        $this->dispatch(new SortProjectColors(Uuid::fromString(TestDataFixture::PROJECT_1_ID), [$extra->toString(), 'nope', Uuid::uuid7()->toString(), TestDataFixture::PROJECT_1_COLOR_RED_ID]));

        self::assertSame(
            [$extra->toString() => 0, TestDataFixture::PROJECT_1_COLOR_BLUE_ID => 1, TestDataFixture::PROJECT_1_COLOR_RED_ID => 2],
            array_map(static fn (mixed $position): int => is_numeric($position) ? (int) $position : -1, $this->connection()->fetchAllKeyValue('SELECT id, position FROM project_color WHERE project_id = ? ORDER BY position', [TestDataFixture::PROJECT_1_ID])),
        );
        // The manual's own colour order is untouched.
        self::assertSame(['C8102E', '004E7C'], self::hexes($this->manual()->customColors()));
    }

    public function testDeleteGuard(): void
    {
        $red = Uuid::fromString(TestDataFixture::PROJECT_1_COLOR_RED_ID);

        // Added to a manual: only with the explicit confirmation.
        $this->expectHandlerException(ProjectColorUsedInManuals::class, fn () => $this->dispatch(new DeleteProjectColor($red, false)));
        $this->dispatch(new DeleteProjectColor($red, true));

        $manual = $this->manual();
        self::assertSame(['004E7C'], self::hexes($manual->customColors()));
        self::assertSame('004E7C', $manual->color(1)->hex);
        self::assertFalse($this->connection()->fetchOne('SELECT 1 FROM project_color WHERE id = ?', [$red->toString()]));

        // In a logo: never.
        $this->linkFromLogo(TestDataFixture::PROJECT_1_COLOR_BLUE_ID, '004E7C');
        $this->expectHandlerException(ProjectColorInLogo::class, fn () => $this->dispatch(new DeleteProjectColor(Uuid::fromString(TestDataFixture::PROJECT_1_COLOR_BLUE_ID), true)));

        // Unused: always.
        $unused = Uuid::uuid7();
        $this->dispatch(new AddProjectColor($unused, Uuid::fromString(TestDataFixture::PROJECT_1_ID), '7A5CC4'));
        $this->dispatch(new DeleteProjectColor($unused, false));
        self::assertFalse($this->connection()->fetchOne('SELECT 1 FROM project_color WHERE id = ?', [$unused->toString()]));
    }

    private function dispatch(object $message): void
    {
        self::getContainer()->get(MessageBusInterface::class)->dispatch($message);
    }

    /**
     * @param class-string<\Throwable> $expected
     */
    private function expectHandlerException(string $expected, callable $action): void
    {
        try {
            $action();
            self::fail($expected . ' expected');
        } catch (HandlerFailedException $exception) {
            self::assertInstanceOf($expected, $exception->getPrevious());
        }
    }

    /**
     * Turns Manual 1's link of the colour into a logo link ("Z logotypu").
     */
    private function linkFromLogo(string $colorId, string $literal): void
    {
        $this->connection()->executeStatement('UPDATE manual_palette_color SET logo_hex = ? WHERE project_color_id = ? AND manual_id = ?', [$literal, $colorId, TestDataFixture::MANUAL_1_ID]);
    }

    private function manual(): Manual
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $manual = $entityManager->find(Manual::class, Uuid::fromString(TestDataFixture::MANUAL_1_ID));
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
}
