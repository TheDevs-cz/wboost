<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\UuidInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use WBoost\Web\Entity\ExportEvent;
use WBoost\Web\Entity\TemplateDocument;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Services\Editor\TemplateVariantImageRendererInterface;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\Fakes\FakeMetaGraphApi;
use WBoost\Web\Tests\Fakes\FakeTemplateVariantImageRenderer;
use WBoost\Web\Tests\TemplateDocumentTestData;
use WBoost\Web\Tests\TestingLogin;
use WBoost\Web\Twig\AiScanExportExtension;
use WBoost\Web\Tests\UnfilledPicturesTestData;
use WBoost\Web\Value\DocumentFillKeys;

/**
 * The web asks first (owner decision O11): an export that would show the
 * grey stand-in an AI scan left behind renders and records NOTHING until the
 * form carries `allowUnfilled=1` — on every web export path.
 *
 * @covers \WBoost\Web\Services\AiScan\Template\UnfilledExportGuard
 * @covers \WBoost\Web\Controller\Template\TemplateVariantDownloadController
 * @covers \WBoost\Web\Controller\Template\TemplateVariantPublishController
 * @covers \WBoost\Web\Controller\TemplateGroup\TemplateGroupExportController
 * @covers \WBoost\Web\Controller\TemplateGroup\TemplateGroupExportVariantController
 * @covers \WBoost\Web\Controller\TemplateDocument\TemplateDocumentExportController
 */
final class UnfilledPicturesWebExportTest extends WebTestCase
{
    use UnfilledPicturesTestData;

    private const string VARIANT = TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID;
    private const string SLOT = TestDataFixture::SOCIAL_NETWORK_VARIANT_1_IMAGE_PHOTO_ID;
    private const string DOWNLOAD = '/template-variant/' . self::VARIANT . '/download';
    private const string GROUP_EXPORT = '/template-group/' . TestDataFixture::TEMPLATE_GROUP_1_ID . '/export';

    public function testDownloadWithADecorativeStandInAsksFirstOnThePage(): void
    {
        $client = $this->client();
        self::addDecorative(self::VARIANT);

        $crawler = $client->request('POST', self::DOWNLOAD);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(
            'Šablona má 1 nedoplněný obrázek. V exportu bude na jeho místě šedý zástupný obrázek. Doplňte ho v editoru, nebo exportujte přesto.',
            trim($crawler->filter('.tp-failed-reason')->text()),
        );
        $this->assertNothingExported();

        $client->request('POST', self::DOWNLOAD, ['allowUnfilled' => '1']);

        self::assertResponseIsSuccessful();
        self::assertSame('image/png', $client->getResponse()->headers->get('Content-Type'));
        self::assertSame(1, $this->exportEvents());
    }

    public function testDownloadAnswersJsonToAClientThatAsksForIt(): void
    {
        $client = $this->client();
        self::addDecorative(self::VARIANT, 2);

        $client->request('POST', self::DOWNLOAD, server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame([
            'ok' => false,
            'code' => 'unfilled_pictures',
            'count' => 2,
            'confirmField' => 'allowUnfilled',
            'editorUrl' => '/template-variant/' . self::VARIANT . '/editor',
        ], json_decode((string) $client->getResponse()->getContent(), true));
        $this->assertNothingExported();

        // An XHR without the Accept header gets the same answer.
        $client->request('POST', self::DOWNLOAD, server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('application/json', $client->getResponse()->headers->get('Content-Type'));
    }

    public function testDownloadWithABackgroundOnTheStandInAsksOnlyWhenNoPictureIsSupplied(): void
    {
        $client = $this->client();
        self::putBackgroundOnStandIn(self::VARIANT, self::SLOT);
        self::seedAllowedImage();

        $client->request('POST', self::DOWNLOAD, ['images' => [self::SLOT => ['imageId' => '', 'scale' => '1']]]);

        self::assertResponseStatusCodeSame(409);
        $this->assertNothingExported();

        $client->request('POST', self::DOWNLOAD, ['images' => [self::SLOT => ['imageId' => TestDataFixture::FILE_IN_ALLOWED_ID]]]);

        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->exportEvents());
    }

    public function testAnOrdinaryTemplateDownloadsAsBefore(): void
    {
        $client = $this->client();
        // The project owns a stand-in, this template does not use it.
        self::standInPath(self::VARIANT);

        $client->request('POST', self::DOWNLOAD);

        self::assertResponseIsSuccessful();
        self::assertSame('image/png', $client->getResponse()->headers->get('Content-Type'));
    }

    public function testGroupZipSumsOverTheDimensionsExported(): void
    {
        $client = $this->client();
        self::addDecorative(TestDataFixture::GROUPED_PRESET_VARIANT_ID);
        self::addDecorative(TestDataFixture::GROUPED_FREEFORM_VARIANT_ID);

        $crawler = $client->request('POST', self::GROUP_EXPORT);

        self::assertResponseStatusCodeSame(409);
        self::assertStringStartsWith('Šablona má 2 nedoplněné obrázky. V exportu budou', trim($crawler->filter('.tp-failed-reason')->text()));
        $this->assertNothingExported();

        // The PDF ZIP holds the print member only — one picture, its editor.
        $client->request('POST', self::GROUP_EXPORT, ['format' => 'pdf'], server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(409);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame(1, $body['count']);
        self::assertSame('/template-variant/' . TestDataFixture::GROUPED_FREEFORM_VARIANT_ID . '/editor', $body['editorUrl']);

        $client->request('POST', self::GROUP_EXPORT, ['allowUnfilled' => '1']);

        self::assertResponseIsSuccessful();
        self::assertSame('application/zip', $client->getResponse()->headers->get('Content-Type'));
        self::assertSame(2, $this->exportEvents());
    }

    public function testGroupZipWithABackgroundOnTheStandInAsksOnlyWhenNoPictureIsSupplied(): void
    {
        $client = $this->client();
        self::putBackgroundOnStandIn(TestDataFixture::GROUPED_PRESET_VARIANT_ID, TestDataFixture::GROUP_SHARED_IMAGE_INPUT_ID);
        self::seedAllowedImage();

        $client->request('POST', self::GROUP_EXPORT);

        self::assertResponseStatusCodeSame(409);
        $this->assertNothingExported();

        $client->request('POST', self::GROUP_EXPORT, ['images' => [TestDataFixture::GROUP_SHARED_IMAGE_INPUT_ID => ['imageId' => TestDataFixture::FILE_IN_ALLOWED_ID]]]);

        self::assertResponseIsSuccessful();
        self::assertSame('application/zip', $client->getResponse()->headers->get('Content-Type'));
    }

    public function testGroupMemberDownloadAsksForItsOwnPicturesOnly(): void
    {
        $client = $this->client();
        self::addDecorative(TestDataFixture::GROUPED_PRESET_VARIANT_ID);

        // The sibling dimension owes nothing.
        $client->request('POST', self::GROUP_EXPORT . '/' . TestDataFixture::GROUPED_FREEFORM_VARIANT_ID);
        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->exportEvents());

        $client->request('POST', self::GROUP_EXPORT . '/' . TestDataFixture::GROUPED_PRESET_VARIANT_ID);
        self::assertResponseStatusCodeSame(409);
        self::assertSame([], $this->rendererFake()->calls);
        self::assertSame(1, $this->exportEvents());

        $client->request('POST', self::GROUP_EXPORT . '/' . TestDataFixture::GROUPED_PRESET_VARIANT_ID, ['allowUnfilled' => '1']);
        self::assertResponseIsSuccessful();
        self::assertSame('image/png', $client->getResponse()->headers->get('Content-Type'));
        self::assertSame(2, $this->exportEvents());
    }

    public function testDocumentSumsOverItsPagesEachAgainstItsOwnImageValues(): void
    {
        $client = $this->client();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $first = TemplateDocumentTestData::richA4Template($em, 'Úvod');
        $second = TemplateDocumentTestData::richA4Template($em, 'Ceník');
        $documentId = TemplateDocumentTestData::document($em, self::getContainer()->get(MessageBusInterface::class), [$first['template'], $second['template']]);
        self::addDecorative($first['variant']->toString());
        self::putBackgroundOnStandIn($second['variant']->toString(), TemplateDocumentTestData::IMAGE_INPUT_ID);
        $url = '/template-document/' . $documentId . '/export';
        $json = ['HTTP_ACCEPT' => 'application/json'];

        $client->request('POST', $url, ['format' => 'png'], server: $json);

        self::assertResponseStatusCodeSame(409);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame(2, $body['count']);
        self::assertSame('/template-variant/' . $first['variant'] . '/editor', $body['editorUrl']);
        $this->assertNothingExported();

        // Page 2's background hidden: only page 1's decorative picture is owed.
        $hidden = ['images' => [$this->fillKey($documentId, $second['template'], TemplateDocumentTestData::IMAGE_INPUT_ID) => ['hide' => '1']]];
        $crawler = $client->request('POST', $url, ['format' => 'png'] + $hidden);

        self::assertResponseStatusCodeSame(409);
        self::assertStringStartsWith('Šablona má 1 nedoplněný obrázek.', trim($crawler->filter('.tp-failed-reason')->text()));
        $this->assertNothingExported();

        $client->request('POST', $url, ['format' => 'png', 'allowUnfilled' => '1']);

        self::assertResponseIsSuccessful();
        self::assertSame('application/zip', $client->getResponse()->headers->get('Content-Type'));
        self::assertSame(2, $this->exportEvents());
    }

    public function testPublishingIsAnExportAndAsksInJson(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);
        self::addDecorative(TestDataFixture::GROUPED_PRESET_VARIANT_ID);
        $url = '/template-variant/' . TestDataFixture::GROUPED_PRESET_VARIANT_ID . '/publish';
        $post = ['platform' => 'facebook', 'targetId' => FakeMetaGraphApi::PAGE_WITHOUT_IG_ID, 'caption' => 'Novinka'];

        $client->request('POST', $url, $post);

        self::assertResponseStatusCodeSame(409);
        self::assertSame([
            'ok' => false,
            'code' => 'unfilled_pictures',
            'count' => 1,
            'confirmField' => 'allowUnfilled',
            'editorUrl' => '/template-variant/' . TestDataFixture::GROUPED_PRESET_VARIANT_ID . '/editor',
        ], json_decode((string) $client->getResponse()->getContent(), true));
        $this->assertNothingExported();

        $client->request('POST', $url, $post + ['allowUnfilled' => '1']);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('"ok":true', (string) $client->getResponse()->getContent());
        self::assertSame(1, $this->exportEvents());
    }

    /**
     * The fill pages arm their export form (`ai-scan-export-guard`, the dialog
     * of ux.md T4) only when `ai_scan_unfilled()` finds something on the
     * stand-in — every other template submits natively, as it always did.
     */
    public function testTheFillPagesArmTheExportGuardOnlyForATemplateWithUnfilledPictures(): void
    {
        $client = $this->client();
        $unfilled = function (): int {
            $em = self::getContainer()->get(EntityManagerInterface::class);
            $em->clear();

            return self::getContainer()->get(AiScanExportExtension::class)->unfilled([$em->find(TemplateVariant::class, TestDataFixture::GROUPED_FREEFORM_VARIANT_ID), 'not a variant']);
        };
        $page = '/template-group/' . TestDataFixture::TEMPLATE_GROUP_1_ID . '/fill';

        self::assertSame(0, $unfilled());
        $crawler = $client->request('GET', $page);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('form[data-controller~="group-fill"]'));
        self::assertCount(0, $crawler->filter('[data-controller~="ai-scan-export-guard"]'));

        self::addDecorative(TestDataFixture::GROUPED_FREEFORM_VARIANT_ID, 2);

        self::assertSame(2, $unfilled());
        $crawler = $client->request('GET', $page);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('form[data-controller~="group-fill"][data-controller~="ai-scan-export-guard"]'));
        self::assertCount(0, $crawler->filter('form form'));
    }

    private function client(): KernelBrowser
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);

        return $client;
    }

    private function fillKey(UuidInterface $documentId, UuidInterface $templateId, string $inputId): string
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $document = $em->find(TemplateDocument::class, $documentId);
        self::assertInstanceOf(TemplateDocument::class, $document);

        return DocumentFillKeys::forDocument($document)->keyFor($templateId->toString(), $inputId);
    }

    private function assertNothingExported(): void
    {
        self::assertSame([], $this->rendererFake()->calls, 'a refused export renders nothing');
        self::assertSame(0, $this->exportEvents(), 'a refused export records nothing');
    }

    private function exportEvents(): int
    {
        return self::getContainer()->get(EntityManagerInterface::class)->getRepository(ExportEvent::class)->count([]);
    }

    private function rendererFake(): FakeTemplateVariantImageRenderer
    {
        $renderer = self::getContainer()->get(TemplateVariantImageRendererInterface::class);
        /** @phpstan-ignore staticMethod.impossibleType */
        self::assertInstanceOf(FakeTemplateVariantImageRenderer::class, $renderer);

        return $renderer;
    }
}
