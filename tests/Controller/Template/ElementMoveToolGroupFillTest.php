<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Template;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Messenger\MessageBusInterface;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Repository\TemplateVariantRepository;
use WBoost\Web\Tests\ContainerFixtures;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TemplateDocumentTestData;
use WBoost\Web\Tests\TestingLogin;

/**
 * "Uživatel může posunout", phase M4 — the move tool on the GROUP and the
 * DOCUMENT fill pages. A move belongs to ONE size / page: every fill
 * surface ships its own server rows (`data-movable` + `data-unit`), every
 * tool / chip / "Poloha" control names its surface (`data-move-variant`),
 * the per-size mirrors re-render only their own size
 * (`group-fill#offsetChanged`), and the page loads the shared bake
 * (`editor/element_move.js`). The document page speaks fill keys.
 *
 * Fixture: "Výsledky turnaje" — the ROOT container ↕ (unlimited up, max 300
 * down), the free headline ↔ (50 each way); the nested row and a member
 * carry inert allowances.
 *
 * @covers \WBoost\Web\Controller\TemplateGroup\TemplateGroupFillController
 * @covers \WBoost\Web\Services\TemplateDocument\DocumentFillPage
 * @covers \WBoost\Web\Services\SocialNetwork\FillMovableElements
 */
final class ElementMoveToolGroupFillTest extends WebTestCase
{
    private const string ROOT = ContainerFixtures::TURNAJ_ROOT;
    private const string HEADLINE = ContainerFixtures::TURNAJ_HEADLINE;
    private const string ROW = ContainerFixtures::TURNAJ_ROW_1;
    private const string SOUPER = ContainerFixtures::TURNAJ_SOUPER_1;

    public function testEveryDimensionOfTheGroupGetsItsOwnMoveTool(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);
        $preset = TestDataFixture::GROUPED_PRESET_VARIANT_ID;
        $freeform = TestDataFixture::GROUPED_FREEFORM_VARIANT_ID;
        $this->makeMovable($preset);
        $this->makeMovable($freeform);

        $crawler = $client->request('GET', '/template-group/' . TestDataFixture::TEMPLATE_GROUP_1_ID . '/fill');
        self::assertResponseIsSuccessful();

        self::assertCount(1, $crawler->filter('script[src*="editor/element_move"]'), 'the shared bake the overlay + echo use');
        self::assertCount(2, $crawler->filter('.fill-surface[data-movable]'), 'one surface per dimension');

        foreach ([$preset, $freeform] as $variantId) {
            $surface = $crawler->filter(sprintf('.fill-surface[data-variant-id="%s"]', $variantId));
            self::assertCount(1, $surface);
            self::assertSame($this->variant($variantId)->dimension->unit->value, $surface->attr('data-unit'));
            $rows = $this->rows($surface);
            self::assertSame([self::HEADLINE, self::ROOT], array_keys($rows), 'members and the nested row are not movable');
            self::assertSame('container', $rows[self::ROOT]['kind']);
            self::assertIsArray($rows[self::ROOT]['members']);
            self::assertContains(self::SOUPER, $rows[self::ROOT]['members']);

            foreach ([self::ROOT, self::HEADLINE] as $id) {
                self::assertCount(1, $surface->filter(sprintf('[data-move-tool="%s"][data-move-variant="%s"]', $id, $variantId)), 'one tool per element on this surface, naming it');
                $chip = $crawler->filter(sprintf('[data-move-chip="%s"][data-move-variant="%s"]', $id, $variantId));
                self::assertCount(1, $chip, 'one move-mode chip per element per dimension');
                self::assertCount(1, $chip->filter(sprintf('[data-move-position="%s"][data-move-variant="%s"]', $id, $variantId)));
                self::assertGreaterThan(0, $chip->filter(sprintf('[data-move-input="%s"][data-move-variant="%s"]', $id, $variantId))->count());
                self::assertGreaterThan(0, $chip->filter(sprintf('[data-move-step="%s"][data-move-variant="%s"]', $id, $variantId))->count(), 'the phone steppers');
                self::assertCount(1, $chip->filter(sprintf('[data-move-reset="%s"][data-move-variant="%s"]', $id, $variantId)));
                self::assertCount(1, $chip->filter('.fill-move-chip__scope'), 'the chip says the move is for this size only');
            }
            self::assertCount(0, $surface->filter(sprintf('[data-move-tool="%s"], [data-move-tool="%s"]', self::ROW, self::SOUPER)));
        }

        self::assertCount(0, $crawler->filter('[data-move-tool]:not([data-move-variant])'), 'no unscoped tool on a per-size page');
        self::assertCount(0, $crawler->filter('.fill-popovers [data-move-position]'), 'the shared popovers carry no per-size "Poloha"');

        // A move re-renders only its own dimension.
        self::assertCount(8, $crawler->filter('input[data-offset-mirror][data-variant-id][data-action="input->group-fill#offsetChanged"]'), '2 elements × x/y × 2 dimensions');
        self::assertCount(0, $crawler->filter('input[data-offset-mirror][data-action="input->group-fill#changed"]'));
    }

    public function testAGroupWithoutMovableElementsRendersNoMoveChrome(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $crawler = $client->request('GET', '/template-group/' . TestDataFixture::TEMPLATE_GROUP_1_ID . '/fill');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-movable]'));
        self::assertCount(0, $crawler->filter('[data-move-tool], [data-move-chip], [data-move-position], [data-move-box], input[data-offset-mirror]'));
    }

    public function testEveryDocumentPageGetsItsOwnMoveToolInFillKeys(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $first = TemplateDocumentTestData::a4Template($em, 'Strana A');
        $second = TemplateDocumentTestData::a4Template($em, 'Strana B');
        $this->makeMovable($first['variant']->toString());
        $this->makeMovable($second['variant']->toString());
        $documentId = TemplateDocumentTestData::document($em, self::getContainer()->get(MessageBusInterface::class), [$first['template'], $second['template']]);

        $crawler = $client->request('GET', '/template-document/' . $documentId . '/fill');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('script[src*="editor/element_move"]'));
        self::assertCount(2, $crawler->filter('.fill-surface[data-movable]'), 'one surface per page');

        foreach ([$first, $second] as $page) {
            $variantId = $page['variant']->toString();
            $rootKey = $this->key($page['template'], self::ROOT);
            $headlineKey = $this->key($page['template'], self::HEADLINE);

            $surface = $crawler->filter(sprintf('.fill-surface[data-variant-id="%s"]', $variantId));
            self::assertSame('mm', $surface->attr('data-unit'));
            $rows = $this->rows($surface);
            $expected = [$rootKey, $headlineKey];
            sort($expected);
            self::assertSame($expected, array_keys($rows), 'fill keys, like every other surface payload');
            self::assertSame($rootKey, $rows[$rootKey]['id']);
            self::assertIsArray($rows[$rootKey]['members']);
            self::assertContains($this->key($page['template'], self::SOUPER), $rows[$rootKey]['members'], 'container members in fill keys too');
            self::assertIsArray($rows[$headlineKey]['hints']);
            self::assertIsString($rows[$headlineKey]['hints']['horizontal']);
            self::assertStringContainsString('mm', $rows[$headlineKey]['hints']['horizontal'], 'captions in the page unit');

            foreach ([$rootKey, $headlineKey] as $key) {
                self::assertCount(1, $surface->filter(sprintf('[data-move-tool="%s"][data-move-variant="%s"]', $key, $variantId)));
                self::assertCount(1, $crawler->filter(sprintf('[data-move-chip="%s"][data-move-variant="%s"]', $key, $variantId)));
                self::assertCount(1, $crawler->filter(sprintf('input[name="offsets[%s][%s][y]"][data-action="input->group-fill#offsetChanged"]', $variantId, $key)));
            }
        }
    }

    // --- helpers -----------------------------------------------------------

    /**
     * @return array<string, array<string, mixed>>
     */
    private function rows(Crawler $surface): array
    {
        /** @var array<string, array<string, mixed>> $rows */
        $rows = json_decode((string) $surface->attr('data-movable'), true, flags: JSON_THROW_ON_ERROR);

        return $rows;
    }

    private function makeMovable(string $variantId): void
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

        ContainerFixtures::applyRaw($this->variant($variantId), $raw);
        self::getContainer()->get(EntityManagerInterface::class)->flush();
    }

    private function key(UuidInterface $templateId, string $id): string
    {
        return Uuid::uuid5($templateId->toString(), $id)->toString();
    }

    private function variant(string $variantId): TemplateVariant
    {
        return self::getContainer()->get(TemplateVariantRepository::class)->get(Uuid::fromString($variantId));
    }
}
