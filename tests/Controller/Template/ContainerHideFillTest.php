<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Template;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use WBoost\Web\Entity\TemplateExportVersion;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Repository\TemplateVariantRepository;
use WBoost\Web\Services\Editor\TemplateVariantImageRendererInterface;
use WBoost\Web\Services\Template\ExportVersionSeeder;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\Fakes\FakeTemplateVariantImageRenderer;
use WBoost\Web\Tests\TemplateDocumentTestData;
use WBoost\Web\Tests\TestingLogin;

/**
 * Whole-container hide on the WEB fill surfaces: the toggle is a
 * `hiddenValues[<containerId>]` checkbox (state hash, POST, versions for
 * free), the render hides the container's whole tree, the members' own hides
 * survive, and the seed brings a hidable container's hide back. Single,
 * group and document pages render the mirror + the panel row.
 *
 * @covers \WBoost\Web\Services\SocialNetwork\FillContainerHides
 * @covers \WBoost\Web\Twig\Components\AbstractVariantFiller
 * @covers \WBoost\Web\Services\Template\ExportVersionSeeder
 * @covers \WBoost\Web\Services\TemplateDocument\DocumentFillPage
 */
final class ContainerHideFillTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    private const string BUBBLE = 'bbbbbbbb-0000-4000-8000-0000000000b1';
    private const string FIXED = 'bbbbbbbb-0000-4000-8000-0000000000b3';
    private const string BUBBLE_IMAGE = 'bbbbbbbb-0000-4000-8000-0000000000d1';

    public function testDownloadHidesTheTreeRecordsAndSeedsTheHide(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);
        $this->addContainers(TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID);

        $client->request('POST', '/template-variant/' . TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID . '/download', [
            'textValues' => [TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_INPUT_HEADLINE_ID => 'Ahoj'],
            'hiddenValues' => [self::BUBBLE => '1', self::FIXED => '1'],
        ]);
        self::assertResponseIsSuccessful();

        $hide = $this->renderer($client)->hideCalls[0];
        self::assertSame([self::BUBBLE, self::FIXED], $hide['hiddenContainerIds']);
        // Only the HIDABLE container expands (lenient web path).
        self::assertSame(
            [TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_INPUT_HEADLINE_ID, self::BUBBLE_IMAGE],
            array_keys(array_filter($hide['effectiveHidden'])),
        );

        $version = $this->versionFor(TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID);
        self::assertContains(self::BUBBLE, $version->fillValues->hidden);

        $seed = self::getContainer()->get(ExportVersionSeeder::class)
            ->forVariant($version, $this->variant(TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID));
        self::assertTrue($seed['hiddenValues'][self::BUBBLE] ?? false);
        self::assertArrayNotHasKey(self::FIXED, $seed['hiddenValues'], 'A non-hidable container never seeds a hide.');
    }

    public function testFillComponentRendersTheToggleAndHidesOnRender(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);
        $this->addContainers(TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID);

        $testComponent = $this->createLiveComponent(
            name: 'Template:VariantFiller',
            data: ['variant' => $this->variant(TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID)],
            client: $client,
        );

        $html = (string) $testComponent->render();
        self::assertStringContainsString(sprintf('name="hiddenValues[%s]"', self::BUBBLE), $html);
        self::assertStringContainsString(sprintf('data-container-hide="%s"', self::BUBBLE), $html);
        self::assertStringNotContainsString(sprintf('data-container-hide="%s"', self::FIXED), $html);
        self::assertStringContainsString('fill-popover--container', $html);
        self::assertStringContainsString('Bublina', $html);
        self::assertStringContainsString('&quot;containerHides&quot;', $html, 'The overlay reflow gets the expansion map.');

        /** @var \WBoost\Web\Twig\Components\Template\VariantFiller $component */
        $component = $testComponent->component();
        self::assertFalse($component->hiddenValues[self::BUBBLE] ?? null, 'Seeded so Live knows the key.');
        $before = $component->fillStateHash();

        $testComponent->set('hiddenValues.' . self::BUBBLE, true);
        $testComponent->render();
        /** @var \WBoost\Web\Twig\Components\Template\VariantFiller $rehydrated */
        $rehydrated = $testComponent->component();
        self::assertTrue($rehydrated->hiddenValues[self::BUBBLE] ?? null);
        self::assertNotSame($before, $rehydrated->fillStateHash());

        $calls = $this->renderer($client)->hideCalls;
        self::assertNotEmpty($calls);
        self::assertTrue($calls[count($calls) - 1]['effectiveHidden'][self::BUBBLE_IMAGE] ?? false);
    }

    public function testGroupPageRendersOneToggleAndThePreviewHides(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);
        $this->addContainers(TestDataFixture::GROUPED_PRESET_VARIANT_ID);
        $this->addContainers(TestDataFixture::GROUPED_FREEFORM_VARIANT_ID);

        $crawler = $client->request('GET', '/template-group/' . TestDataFixture::TEMPLATE_GROUP_1_ID . '/fill');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter(sprintf('input[name="hiddenValues[%s]"][data-container-hide][data-action="change->group-fill#changed"]', self::BUBBLE)));
        self::assertCount(1, $crawler->filter(sprintf('.fill-popover--container[data-inputid="%s"]', self::BUBBLE)));

        $client->request('POST', sprintf('/template-group/%s/fill-preview/%s', TestDataFixture::TEMPLATE_GROUP_1_ID, TestDataFixture::GROUPED_FREEFORM_VARIANT_ID), [
            'hiddenValues' => [self::BUBBLE => '1'],
        ]);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->renderer($client)->hideCalls[0]['effectiveHidden'][self::BUBBLE_IMAGE] ?? false);
    }

    public function testDocumentPageKeysTheToggleByPageAndThePreviewHidesTheRealContainer(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $first = TemplateDocumentTestData::a4Template($em, 'Strana A');
        $second = TemplateDocumentTestData::a4Template($em, 'Strana B');
        $this->addContainers($first['variant']->toString(), TemplateDocumentTestData::SHARED_INPUT_ID);
        $this->addContainers($second['variant']->toString(), TemplateDocumentTestData::SHARED_INPUT_ID);
        $documentId = TemplateDocumentTestData::document($em, self::getContainer()->get(MessageBusInterface::class), [$first['template'], $second['template']]);

        $crawler = $client->request('GET', '/template-document/' . $documentId . '/fill');
        self::assertResponseIsSuccessful();
        $firstKey = $this->key($first['template'], self::BUBBLE);
        self::assertCount(2, $crawler->filter('input[data-container-hide]'), 'One toggle per page, never shared.');
        self::assertCount(1, $crawler->filter(sprintf('input[name="hiddenValues[%s]"]', $firstKey)));
        self::assertCount(0, $crawler->filter(sprintf('[data-inputid="%s"]', self::BUBBLE)), 'No real container id leaks.');

        $em->clear();
        $document = $em->find(\WBoost\Web\Entity\TemplateDocument::class, $documentId);
        self::assertNotNull($document);
        $client->request('POST', '/template-document/' . $documentId . '/fill-preview/' . $document->pages()[0]->id, [
            'hiddenValues' => [$firstKey => '1', $this->key($second['template'], self::BUBBLE) => '1'],
        ]);
        self::assertResponseIsSuccessful();
        $hide = $this->renderer($client)->hideCalls[0];
        self::assertSame([self::BUBBLE], $hide['hiddenContainerIds'], 'Translated back to the real id, own page only.');
        self::assertTrue($hide['effectiveHidden'][self::BUBBLE_IMAGE] ?? false);
    }

    /**
     * A hidable "bubble" container (a text + a decorative image) and a
     * non-hidable one; the second text member defaults to the tagline.
     */
    private function addContainers(string $variantId, null|string $textId = null): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $variant = $this->variant($variantId);

        $canvas = json_decode($variant->canvas, true);
        $canvas = is_array($canvas) ? $canvas : [];
        $objects = is_array($canvas['objects'] ?? null) ? $canvas['objects'] : [];
        $objects[] = [
            'type' => 'Image', 'inputId' => self::BUBBLE_IMAGE,
            'left' => 60, 'top' => 50, 'width' => 560, 'height' => 120,
            'scaleX' => 1, 'scaleY' => 1, 'originX' => 'left', 'originY' => 'top',
        ];
        $canvas['objects'] = $objects;
        $canvas['containers'] = [
            [
                'id' => self::BUBBLE, 'maxHeight' => 400, 'name' => 'Bublina', 'hidable' => true,
                'memberInputIds' => [$textId ?? TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_INPUT_HEADLINE_ID, self::BUBBLE_IMAGE],
            ],
            [
                'id' => self::FIXED, 'maxHeight' => 300,
                'memberInputIds' => ['x-1', 'x-2'],
            ],
        ];

        $variant->editCanvas((string) json_encode($canvas), $variant->inputs, $variant->previewImagePath, $variant->imageInputs);
        $entityManager->flush();
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
