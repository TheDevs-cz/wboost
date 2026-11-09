<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Template;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use WBoost\Web\Entity\TemplateDocument;
use WBoost\Web\Entity\TemplateExportVersion;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Repository\TemplateVariantRepository;
use WBoost\Web\Services\Editor\TemplateVariantImageRendererInterface;
use WBoost\Web\Services\Template\ExportVersionSeeder;
use WBoost\Web\Tests\ContainerFixtures;
use WBoost\Web\Tests\TemplateDocumentTestData;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\Fakes\FakeTemplateVariantImageRenderer;
use WBoost\Web\Tests\TestingLogin;

/**
 * "Uživatel může posunout", phase M2 — the server pipeline on the WEB fill
 * surfaces (no move UI yet): the moves travel as `offsetValues[<id>][x|y]`
 * (Live prop on the single page) / `offsets[<variantId>][<id>][x|y]` (group
 * and document pages, per size), are clamped by the lenient resolver,
 * reach the renderer, and round-trip through export versions — re-clamped
 * to the CURRENT allowance on seed.
 *
 * Fixture: "Výsledky turnaje" with its repeatable row (max 5, default 2) —
 * the ROOT container may move ↕ (unlimited up, max 300 down), the free
 * headline ↔ (max 50 each way); the nested row and the members carry no
 * own move.
 *
 * @covers \WBoost\Web\Twig\Components\AbstractVariantFiller
 * @covers \WBoost\Web\Services\SocialNetwork\ResolveElementOffsets
 * @covers \WBoost\Web\Services\TemplateGroup\GroupFillRenderer
 * @covers \WBoost\Web\Value\DocumentFillKeys
 */
final class ElementOffsetsFillTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    private const string ROOT = ContainerFixtures::TURNAJ_ROOT;
    private const string HEADLINE = ContainerFixtures::TURNAJ_HEADLINE;
    private const string ROW = ContainerFixtures::TURNAJ_ROW_1;
    private const string SOUPER = ContainerFixtures::TURNAJ_SOUPER_1;

    public function testDownloadBakesRecordsAndSeedsBackReClamped(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);
        $variantId = TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID;
        $this->makeMovable($variantId);

        $client->request('POST', '/template-variant/' . $variantId . '/download', [
            'offsetValues' => [
                self::ROOT => ['x' => '30', 'y' => '120'],      // ↔ not allowed → 0
                self::HEADLINE => ['x' => '-80', 'y' => '0'],   // capped at 50
                self::ROW => ['x' => '0', 'y' => '10'],         // nested container: inert
                self::SOUPER => ['x' => '0', 'y' => '10'],      // member: inert
            ],
        ]);
        self::assertResponseIsSuccessful();

        $expected = [self::HEADLINE => ['x' => -50.0, 'y' => 0.0], self::ROOT => ['x' => 0.0, 'y' => 120.0]];
        self::assertSame($expected, $this->renderer($client)->offsetCalls[0]);

        $version = $this->versionFor($variantId);
        self::assertSame([$variantId => $expected], $version->fillValues->offsets);

        // The designer tightens the root to 100 px down → the seed follows.
        $this->makeMovable($variantId, rootMaxDown: 100);
        $seed = self::getContainer()->get(ExportVersionSeeder::class)->forVariant($version, $this->variant($variantId));
        self::assertSame([self::HEADLINE => ['x' => -50.0, 'y' => 0.0], self::ROOT => ['x' => 0.0, 'y' => 100.0]], $seed['offsetValues']);
    }

    public function testFillComponentTracksClampsHashesAndRendersTheMoves(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);
        $variantId = TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID;
        $this->makeMovable($variantId);

        $testComponent = $this->createLiveComponent(
            name: 'Template:VariantFiller',
            data: ['variant' => $this->variant($variantId)],
            client: $client,
        );

        $crawler = new Crawler((string) $testComponent->render());
        self::assertCount(4, $crawler->filter('input[data-offset-mirror]'), 'x + y for the root and the headline only');
        self::assertCount(1, $crawler->filter(sprintf('input[name="offsetValues[%s][y]"][data-offset-axis="y"][value="0"]', self::ROOT)));

        /** @var \WBoost\Web\Twig\Components\Template\VariantFiller $component */
        $component = $testComponent->component();
        self::assertSame([self::HEADLINE => ['x' => 0.0, 'y' => 0.0], self::ROOT => ['x' => 0.0, 'y' => 0.0]], $component->offsetValues);
        $unmoved = $component->fillStateHash();

        $testComponent->set('offsetValues.' . self::ROOT . '.y', '999');
        $testComponent->render();
        /** @var \WBoost\Web\Twig\Components\Template\VariantFiller $moved */
        $moved = $testComponent->component();
        self::assertSame(['x' => 0.0, 'y' => 300.0], $moved->offsetValues[self::ROOT], 'clamped to maxDown, back to floats');
        self::assertNotSame($unmoved, $moved->fillStateHash());

        // Every render of that pass bakes the move.
        $offsetCalls = $this->renderer($client)->offsetCalls;
        self::assertNotEmpty($offsetCalls);
        self::assertSame([self::ROOT => ['x' => 0.0, 'y' => 300.0]], $offsetCalls[count($offsetCalls) - 1]);
    }

    public function testADesignWithoutMovableElementsCarriesNoOffsetState(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $testComponent = $this->createLiveComponent(
            name: 'Template:VariantFiller',
            data: ['variant' => $this->variant(TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID)],
            client: $client,
        );

        self::assertCount(0, (new Crawler((string) $testComponent->render()))->filter('[data-offset-mirror]'));
        /** @var \WBoost\Web\Twig\Components\Template\VariantFiller $component */
        $component = $testComponent->component();
        self::assertSame([], $component->offsetValues);
        self::assertSame([], $this->renderer($client)->offsetCalls[0]);
    }

    public function testGroupMovesArePerDimension(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);
        $preset = TestDataFixture::GROUPED_PRESET_VARIANT_ID;
        $freeform = TestDataFixture::GROUPED_FREEFORM_VARIANT_ID;
        $this->makeMovable($preset);
        $this->makeMovable($freeform);

        $crawler = $client->request('GET', '/template-group/' . TestDataFixture::TEMPLATE_GROUP_1_ID . '/fill');
        self::assertResponseIsSuccessful();
        self::assertCount(8, $crawler->filter('input[data-offset-mirror][data-action="input->group-fill#changed"]'), '2 elements × x/y × 2 dimensions');
        self::assertCount(1, $crawler->filter(sprintf('input[name="offsets[%s][%s][y]"][data-variant-id="%s"]', $freeform, self::ROOT, $freeform)));

        $offsets = [
            $preset => [self::ROOT => ['x' => '0', 'y' => '40']],
            $freeform => [self::ROOT => ['x' => '0', 'y' => '70']],
        ];
        foreach ([$preset => 40.0, $freeform => 70.0] as $variantId => $y) {
            $client->request('POST', sprintf('/template-group/%s/fill-preview/%s', TestDataFixture::TEMPLATE_GROUP_1_ID, $variantId), ['offsets' => $offsets]);
            self::assertResponseIsSuccessful();
            self::assertSame([self::ROOT => ['x' => 0.0, 'y' => $y]], $this->renderer($client)->offsetCalls[0], 'each dimension bakes ITS OWN move');
        }

        $client->request('POST', '/template-group/' . TestDataFixture::TEMPLATE_GROUP_1_ID . '/export', ['offsets' => $offsets]);
        self::assertResponseIsSuccessful();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        /** @var list<TemplateExportVersion> $versions */
        $versions = $em->getRepository(TemplateExportVersion::class)->findBy(['group' => TestDataFixture::TEMPLATE_GROUP_1_ID]);
        self::assertCount(1, $versions);
        self::assertSame([
            $preset => [self::ROOT => ['x' => 0.0, 'y' => 40.0]],
            $freeform => [self::ROOT => ['x' => 0.0, 'y' => 70.0]],
        ], $versions[0]->fillValues->offsets);
    }

    public function testDocumentMovesArePerPageFillKeys(): void
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
        $rootKey = $this->key($first['template'], self::ROOT);
        self::assertCount(8, $crawler->filter('input[data-offset-mirror]'), 'per page, never shared');
        self::assertCount(1, $crawler->filter(sprintf('input[name="offsets[%s][%s][y]"]', $first['variant']->toString(), $rootKey)));

        $em->clear();
        $document = $em->find(TemplateDocument::class, $documentId);
        self::assertNotNull($document);
        $client->request('POST', '/template-document/' . $documentId . '/fill-preview/' . $document->pages()[0]->id, [
            'offsets' => [
                $first['variant']->toString() => [$rootKey => ['x' => '0', 'y' => '25']],
                $second['variant']->toString() => [$this->key($second['template'], self::ROOT) => ['x' => '0', 'y' => '99']],
            ],
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame([self::ROOT => ['x' => 0.0, 'y' => 25.0]], $this->renderer($client)->offsetCalls[0], 'translated back to the real id, own page only');
    }

    // --- helpers -----------------------------------------------------------

    /**
     * The repeatable "Výsledky turnaje" design with a movable root (↕,
     * unlimited up, `$rootMaxDown` down) and headline (↔ 50 / 50).
     */
    private function makeMovable(string $variantId, float $rootMaxDown = 300.0): void
    {
        $raw = ContainerFixtures::turnajRepeatableRaw(max: 5, default: 2);
        /** @var list<array<string, mixed>> $containers */
        $containers = $raw['canvas']['containers'];
        foreach ($containers as $i => $container) {
            if ($container['id'] === self::ROOT) {
                $containers[$i]['userMove'] = ['vertical' => true, 'horizontal' => false, 'maxUp' => null, 'maxDown' => $rootMaxDown, 'maxLeft' => null, 'maxRight' => null];
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

    private function renderer(KernelBrowser $client): FakeTemplateVariantImageRenderer
    {
        $renderer = $client->getContainer()->get(TemplateVariantImageRendererInterface::class);
        /** @phpstan-ignore staticMethod.impossibleType */
        self::assertInstanceOf(FakeTemplateVariantImageRenderer::class, $renderer);

        return $renderer;
    }

    private function versionFor(string $variantId): TemplateExportVersion
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        /** @var list<TemplateExportVersion> $versions */
        $versions = $entityManager->getRepository(TemplateExportVersion::class)->findBy(['variant' => $variantId]);
        self::assertCount(1, $versions);

        return $versions[0];
    }
}
