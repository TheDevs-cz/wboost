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
use WBoost\Web\Services\Template\RepeatableRows;
use WBoost\Web\Services\TemplateDocument\TemplateDocumentRules;
use WBoost\Web\Tests\ContainerFixtures;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\Fakes\FakeTemplateVariantImageRenderer;
use WBoost\Web\Tests\TemplateDocumentTestData;
use WBoost\Web\Tests\TestingLogin;
use WBoost\Web\Value\DocumentFieldKind;

/**
 * Repeatable rows on the WEB fill surfaces (container-rows plan P2 — the
 * server-side row model, no row UI yet): the page works on the variant
 * expanded to `max` rows, the row count is a `rowCounts[<rowId>]` field
 * (Live prop on the single page, a plain field on the group / document
 * pages) the render turns into hidden unused rows, new rows start from the
 * template row's sample text, and the count travels into export versions and
 * back — while the STORED design never sees a materialised row.
 *
 * Fixture: "Výsledky turnaje" re-authored with ONE repeatable row (max 5,
 * default 2) written onto real fixture variants.
 *
 * @covers \WBoost\Web\Twig\Components\AbstractVariantFiller
 * @covers \WBoost\Web\Services\Template\RepeatableRows
 * @covers \WBoost\Web\Services\TemplateGroup\GroupFillRenderer
 * @covers \WBoost\Web\Services\TemplateDocument\DocumentFillPage
 */
final class RepeatableRowsFillTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    private const string ROW = ContainerFixtures::TURNAJ_ROW_1;
    private const string SOUPER = ContainerFixtures::TURNAJ_SOUPER_1;
    private const string SKORE = '5a191f3b-0ba9-4a95-b09f-d9cb1bab8297';

    public function testDownloadHidesUnusedRowsRecordsTheCountAndSeedsItBack(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);
        $stored = $this->makeRepeatable(TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID);

        $client->request('POST', '/template-variant/' . TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID . '/download', [
            'textValues' => [$this->slot(self::SOUPER, 2) => 'TJ Kelč', $this->slot(self::SOUPER, 4) => 'unused row'],
            'rowCounts' => [self::ROW => '3'],
        ]);
        self::assertResponseIsSuccessful();

        $call = $this->renderer($client)->calls[0];
        $hide = $this->renderer($client)->hideCalls[0];
        self::assertSame('TJ Kelč', $call['texts'][$this->slot(self::SOUPER, 2)] ?? null);
        self::assertSame('Fotbal TJ Kelč', $call['texts'][$this->slot(self::SOUPER, 1)] ?? null, 'An untouched new row starts from the template row\'s sample.');
        self::assertSame([self::ROW => 3], $hide['rowCounts']);
        self::assertSame(
            [$this->slot(self::SKORE, 3), $this->slot(self::SOUPER, 3), $this->slot(self::SKORE, 4), $this->slot(self::SOUPER, 4)],
            array_keys(array_filter($hide['effectiveHidden'])),
        );

        $version = $this->versionFor(TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID);
        self::assertSame([self::ROW => 3], $version->fillValues->rows);

        $seed = self::getContainer()->get(ExportVersionSeeder::class)
            ->forVariant($version, $this->variant(TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID));
        self::assertSame([self::ROW => 3], $seed['rowCounts']);
        self::assertSame('TJ Kelč', $seed['textValues'][$this->slot(self::SOUPER, 2)] ?? null, 'Row values seed under their derived ids.');

        $this->assertStoredDesignUntouched(TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID, $stored);
    }

    public function testFillComponentSeedsEveryRowTracksTheCountAndHashesIt(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);
        $stored = $this->makeRepeatable(TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID);

        $testComponent = $this->createLiveComponent(
            name: 'Template:VariantFiller',
            data: ['variant' => $this->variant(TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID)],
            client: $client,
        );

        $html = (string) $testComponent->render();
        $crawler = new Crawler($html);
        self::assertCount(1, $crawler->filter(sprintf('input[name="rowCounts[%s]"][data-row-count-mirror="%s"][value="2"]', self::ROW, self::ROW)));
        // Every materialised row has its own mirror, seeded with the sample.
        self::assertCount(1, $crawler->filter(sprintf('[data-text-mirror="%s"]', $this->slot(self::SOUPER, 4))));
        self::assertStringContainsString('&quot;repeatRows&quot;', $html, 'The overlay reflow gets the row slots.');

        /** @var \WBoost\Web\Twig\Components\Template\VariantFiller $component */
        $component = $testComponent->component();
        self::assertSame([self::ROW => 2], $component->rowCounts);
        self::assertSame('Fotbal TJ Kelč', $component->textValues[$this->slot(self::SOUPER, 3)] ?? null);
        $before = $component->fillStateHash();

        // The browser's view of the mirrors hashes to the server stamp (R line included).
        self::assertSame($this->serverHash($html), $this->browserHash($html));

        $testComponent->set('rowCounts.' . self::ROW, '4');
        $rerendered = (string) $testComponent->render();
        /** @var \WBoost\Web\Twig\Components\Template\VariantFiller $rehydrated */
        $rehydrated = $testComponent->component();
        self::assertSame([self::ROW => 4], $rehydrated->rowCounts, 'Normalised back to an int.');
        self::assertNotSame($before, $rehydrated->fillStateHash());
        self::assertSame($this->serverHash($rerendered), $this->browserHash($rerendered));

        $calls = $this->renderer($client)->hideCalls;
        $last = $calls[count($calls) - 1];
        self::assertSame([self::ROW => 4], $last['rowCounts']);
        self::assertTrue($last['effectiveHidden'][$this->slot(self::SOUPER, 4)] ?? false);
        self::assertFalse($last['effectiveHidden'][$this->slot(self::SOUPER, 3)] ?? false);

        // An out-of-range count is clamped to [min, max].
        $testComponent->set('rowCounts.' . self::ROW, '99');
        $testComponent->render();
        /** @var \WBoost\Web\Twig\Components\Template\VariantFiller $clamped */
        $clamped = $testComponent->component();
        self::assertSame([self::ROW => 5], $clamped->rowCounts);

        $this->assertStoredDesignUntouched(TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID, $stored);
    }

    public function testADesignWithoutRowsRendersNoRowStateAtAll(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $testComponent = $this->createLiveComponent(
            name: 'Template:VariantFiller',
            data: ['variant' => $this->variant(TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID)],
            client: $client,
        );
        $html = (string) $testComponent->render();

        self::assertStringNotContainsString('data-row-count-mirror', $html);
        self::assertStringNotContainsString('repeatRows', $html);
        /** @var \WBoost\Web\Twig\Components\Template\VariantFiller $component */
        $component = $testComponent->component();
        self::assertSame([], $component->rowCounts, 'No R: lines — every pre-rows state hash is unchanged.');
    }

    public function testGroupSharesOneCountAcrossDimensions(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);
        $this->makeRepeatable(TestDataFixture::GROUPED_PRESET_VARIANT_ID);
        $this->makeRepeatable(TestDataFixture::GROUPED_FREEFORM_VARIANT_ID);

        $crawler = $client->request('GET', '/template-group/' . TestDataFixture::TEMPLATE_GROUP_1_ID . '/fill');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter(sprintf('input[name="rowCounts[%s]"][value="2"][data-action="input->group-fill#changed"]', self::ROW)), 'ONE shared count.');
        self::assertCount(1, $crawler->filter(sprintf('input[name="textValues[%s]"]', $this->slot(self::SOUPER, 4))), 'Row values are shared inputs too.');

        foreach ([TestDataFixture::GROUPED_PRESET_VARIANT_ID, TestDataFixture::GROUPED_FREEFORM_VARIANT_ID] as $variantId) {
            $client->request('POST', sprintf('/template-group/%s/fill-preview/%s', TestDataFixture::TEMPLATE_GROUP_1_ID, $variantId), [
                'rowCounts' => [self::ROW => '1'],
                'textValues' => [$this->slot(self::SOUPER, 1) => 'hidden anyway'],
            ]);
            self::assertResponseIsSuccessful();
            $hide = $this->renderer($client)->hideCalls[0]; // the kernel reboots per request
            self::assertTrue($hide['effectiveHidden'][$this->slot(self::SOUPER, 1)] ?? false, 'count 1 hides row 2 in every dimension');
        }
    }

    public function testDocumentRowsArePerPageFillKeysAndNeverBindable(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $first = TemplateDocumentTestData::a4Template($em, 'Strana A');
        $second = TemplateDocumentTestData::a4Template($em, 'Strana B');
        $this->makeRepeatable($first['variant']->toString());
        $this->makeRepeatable($second['variant']->toString());
        $documentId = TemplateDocumentTestData::document($em, self::getContainer()->get(MessageBusInterface::class), [$first['template'], $second['template']]);

        $crawler = $client->request('GET', '/template-document/' . $documentId . '/fill');
        self::assertResponseIsSuccessful();
        $firstRowKey = $this->key($first['template'], self::ROW);
        $secondRowKey = $this->key($second['template'], self::ROW);
        self::assertCount(2, $crawler->filter('input[data-row-count-mirror]'), 'One count per page, never shared.');
        self::assertCount(1, $crawler->filter(sprintf('input[name="rowCounts[%s]"][value="2"]', $firstRowKey)));
        self::assertCount(1, $crawler->filter(sprintf('[data-text-mirror="%s"]', $this->key($first['template'], $this->slot(self::SOUPER, 3)))), 'Derived row ids get fill keys too.');

        $em->clear();
        $document = $em->find(TemplateDocument::class, $documentId);
        self::assertNotNull($document);
        $client->request('POST', '/template-document/' . $documentId . '/fill-preview/' . $document->pages()[0]->id, [
            'rowCounts' => [$firstRowKey => '4', $secondRowKey => '1'],
        ]);
        self::assertResponseIsSuccessful();
        $hide = $this->renderer($client)->hideCalls[0];
        self::assertSame([self::ROW => 4], $hide['rowCounts'], 'Translated back to the real row id, own page only.');

        // A row member is never offered for / accepted into a shared field.
        $page = $document->pages()[0];
        self::assertFalse(TemplateDocumentRules::isBindable($page->variant, self::SOUPER, DocumentFieldKind::Text));
        self::assertTrue(TemplateDocumentRules::isBindable($page->variant, ContainerFixtures::TURNAJ_SESTAVA, DocumentFieldKind::Text));
    }

    // --- helpers -----------------------------------------------------------

    /**
     * Write the repeatable "Výsledky turnaje" design onto a fixture variant;
     * returns the stored canvas JSON.
     */
    private function makeRepeatable(string $variantId): string
    {
        $variant = $this->variant($variantId);
        ContainerFixtures::applyRaw($variant, ContainerFixtures::turnajRepeatableRaw(max: 5, default: 2));
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        return $variant->canvas;
    }

    private function assertStoredDesignUntouched(string $variantId, string $stored): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $reloaded = $this->variant($variantId);
        self::assertEquals(json_decode($stored, true), json_decode($reloaded->canvas, true), 'The expanded clone must never be persisted.');
        self::assertStringNotContainsString('repeatSlots', $reloaded->canvas);
        self::assertCount(13 - 8, $reloaded->inputs, 'Only the designed row is stored.');
    }

    private function slot(string $id, int $slot): string
    {
        return RepeatableRows::slotId(self::ROW, $id, $slot);
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

    private function serverHash(string $rendered): string
    {
        $source = (new Crawler($rendered))->filter('#variant-backdrop-source, [data-variant-fill-overlay-target="previewSource"]');
        self::assertCount(1, $source);

        return (string) $source->attr('data-state-hash');
    }

    /**
     * assets/controllers/fill_state_hash.js over the browser-visible mirrors
     * (FillMirrorStateHashTest's port, plus the `R:` group).
     */
    private function browserHash(string $rendered): string
    {
        $crawler = new Crawler($rendered);
        $parts = [];
        foreach ([['[data-text-mirror]', 'data-text-mirror', 'T'], ['[data-hide-mirror]', 'data-hide-mirror', 'H'], ['[data-font-mirror]', 'data-font-mirror', 'F'], ['[data-shape-color-mirror]', 'data-shape-color-mirror', 'S'], ['[data-row-count-mirror]', 'data-row-count-mirror', 'R']] as [$selector, $attribute, $prefix]) {
            $rows = [];
            foreach ($crawler->filter($selector) as $node) {
                $mirror = new Crawler($node);
                $rows[(string) $mirror->attr($attribute)] = $prefix === 'H'
                    ? ($mirror->attr('checked') !== null ? '1' : '0')
                    : (strtolower($mirror->nodeName()) === 'textarea' ? $mirror->text('', false) : (string) $mirror->attr('value'));
            }
            ksort($rows, SORT_STRING);
            foreach ($rows as $id => $value) {
                $parts[] = $prefix . ':' . $id . '=' . $value;
            }
        }

        $hash = 5381;
        $bytes = implode("\n", $parts);
        for ($i = 0, $length = strlen($bytes); $i < $length; $i++) {
            $hash = (($hash * 33) & 0xFFFFFFFF) ^ ord($bytes[$i]);
        }

        return (string) $hash;
    }
}
