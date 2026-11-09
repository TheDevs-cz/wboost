<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Template;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Repository\TemplateVariantRepository;
use WBoost\Web\Services\SocialNetwork\FillMovableElements;
use WBoost\Web\Tests\ContainerFixtures;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;
use WBoost\Web\Value\DimensionUnit;

/**
 * "Uživatel může posunout", phase M3 — the single fill page's move-tool
 * markup: the stage ships the SERVER's movable rows (frame, bounds, members,
 * echo flag) in `data-movable`, every movable element gets exactly one ✥ /
 * ↕ / ↔ tool, a move-mode chip and "Poloha" rows; the offset mirrors
 * debounce (an arrow-key burst = one render). A design without movable
 * elements renders none of it.
 *
 * Fixture: "Výsledky turnaje" — the ROOT container ↕ (unlimited up, max 300
 * down), the free headline ↔ (50 each way); the nested row and a member
 * carry inert allowances.
 *
 * @covers \WBoost\Web\Services\SocialNetwork\FillMovableElements
 * @covers \WBoost\Web\Twig\Components\AbstractVariantFiller
 */
final class ElementMoveToolFillTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    private const string ROOT = ContainerFixtures::TURNAJ_ROOT;
    private const string HEADLINE = ContainerFixtures::TURNAJ_HEADLINE;
    private const string ROW = ContainerFixtures::TURNAJ_ROW_1;
    private const string SOUPER = ContainerFixtures::TURNAJ_SOUPER_1;

    public function testTheStageShipsTheServerRowsAndEveryMovableElementGetsOneTool(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);
        $this->makeMovable();

        $html = (string) $this->createLiveComponent(
            name: 'Template:VariantFiller',
            data: ['variant' => $this->variant()],
            client: $client,
        )->render();
        $crawler = new Crawler($html);

        $stage = $crawler->filter('[data-movable]');
        self::assertCount(1, $stage);
        self::assertSame('px', $stage->attr('data-unit'));
        /** @var array<string, array<string, mixed>> $rows */
        $rows = json_decode((string) $stage->attr('data-movable'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame([self::HEADLINE, self::ROOT], array_keys($rows), 'members and the nested row are not movable');

        self::assertSame('container', $rows[self::ROOT]['kind']);
        self::assertIsArray($rows[self::ROOT]['members']);
        self::assertContains(self::SOUPER, $rows[self::ROOT]['members'], 'the deep tree, row slots included');
        self::assertIsArray($rows[self::ROOT]['bounds']);
        self::assertEquals(300, $rows[self::ROOT]['bounds']['maxY']);
        self::assertEquals(0, $rows[self::ROOT]['bounds']['maxX'], '↔ not allowed');
        self::assertIsArray($rows[self::ROOT]['frame']);
        self::assertIsNumeric($rows[self::ROOT]['frame']['y']);
        self::assertIsNumeric($rows[self::ROOT]['bounds']['minY']);
        self::assertEqualsWithDelta(-$rows[self::ROOT]['frame']['y'], $rows[self::ROOT]['bounds']['minY'], 0.01, 'unlimited up = up to the artwork edge');

        self::assertSame('text', $rows[self::HEADLINE]['kind']);
        self::assertEquals(['minX' => -50, 'maxX' => 50, 'minY' => 0, 'maxY' => 0], $rows[self::HEADLINE]['bounds']);
        self::assertIsArray($rows[self::HEADLINE]['hints']);
        self::assertSame("doleva až 50\u{00A0}px · doprava až 50\u{00A0}px", $rows[self::HEADLINE]['hints']['horizontal']);
        self::assertNull($rows[self::HEADLINE]['hints']['vertical']);

        foreach ([self::ROOT => 'i-move-v', self::HEADLINE => 'i-move-h'] as $id => $glyph) {
            $tools = $crawler->filter(sprintf('[data-move-tool="%s"]', $id));
            self::assertCount(1, $tools, 'one tool per element on the one surface');
            self::assertCount(1, $tools->filter('.' . $glyph), 'the single-axis glyph');
            self::assertCount(1, $crawler->filter(sprintf('[data-move-chip="%s"]', $id)));
            self::assertGreaterThanOrEqual(2, $crawler->filter(sprintf('[data-move-position="%s"]', $id))->count(), 'chip + its popover / sheet / panel card');
        }
        self::assertCount(0, $crawler->filter(sprintf('[data-move-tool="%s"], [data-move-tool="%s"]', self::ROW, self::SOUPER)));
        self::assertCount(0, $crawler->filter(sprintf('[data-move-position="%s"] [data-move-axis="x"]', self::ROOT)), 'no ↔ field for a ↕-only element');

        // An arrow-key burst writes the mirror per key — the debounce makes it one render.
        self::assertStringContainsString('debounce(400)|offsetValues[' . self::ROOT . '][y]', $html);
    }

    public function testADesignWithoutMovableElementsRendersNoMoveChrome(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $crawler = new Crawler((string) $this->createLiveComponent(
            name: 'Template:VariantFiller',
            data: ['variant' => $this->variant()],
            client: $client,
        )->render());

        self::assertCount(0, $crawler->filter('[data-movable]'));
        self::assertCount(0, $crawler->filter('[data-move-tool], [data-move-chip], [data-move-position], [data-move-box]'));
    }

    public function testLimitsAreCaptionedInTheVariantUnit(): void
    {
        self::assertSame("12\u{00A0}px", FillMovableElements::format(12.4, DimensionUnit::Px));
        self::assertSame("10\u{00A0}mm", FillMovableElements::format(118.11, DimensionUnit::Mm));
        self::assertSame("2,5\u{00A0}mm", FillMovableElements::format(29.53, DimensionUnit::Mm));
        self::assertSame("1,27\u{00A0}cm", FillMovableElements::format(150.0, DimensionUnit::Cm));
    }

    private function makeMovable(): void
    {
        $raw = ContainerFixtures::turnajRepeatableRaw(max: 5, default: 2);
        /** @var list<array<string, mixed>> $containers */
        $containers = $raw['canvas']['containers'];
        foreach ($containers as $i => $container) {
            if ($container['id'] === self::ROOT) {
                $containers[$i]['userMove'] = ['vertical' => true, 'horizontal' => false, 'maxUp' => null, 'maxDown' => 300, 'maxLeft' => null, 'maxRight' => null];
            }
            if ($container['id'] === self::ROW) {
                $containers[$i]['userMove'] = ['vertical' => true, 'horizontal' => true, 'maxUp' => null, 'maxDown' => null, 'maxLeft' => null, 'maxRight' => null];
            }
        }
        $raw['canvas']['containers'] = $containers;

        /** @var list<array<string, mixed>> $objects */
        $objects = $raw['canvas']['objects'];
        foreach ($objects as $i => $object) {
            if (($object['inputId'] ?? null) === self::HEADLINE) {
                $objects[$i]['userMove'] = ['vertical' => false, 'horizontal' => true, 'maxUp' => null, 'maxDown' => null, 'maxLeft' => 50, 'maxRight' => 50];
            }
            if (($object['inputId'] ?? null) === self::SOUPER) {
                $objects[$i]['userMove'] = ['vertical' => true, 'horizontal' => true, 'maxUp' => null, 'maxDown' => null, 'maxLeft' => null, 'maxRight' => null];
            }
        }
        $raw['canvas']['objects'] = $objects;

        ContainerFixtures::applyRaw($this->variant(), $raw);
        self::getContainer()->get(EntityManagerInterface::class)->flush();
    }

    private function variant(): TemplateVariant
    {
        return self::getContainer()->get(TemplateVariantRepository::class)
            ->get(Uuid::fromString(TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID));
    }
}
