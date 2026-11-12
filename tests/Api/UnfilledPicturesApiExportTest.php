<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\Client;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use WBoost\Web\Entity\ExportEvent;
use WBoost\Web\Services\Editor\TemplateVariantImageRendererInterface;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\Fakes\FakeTemplateVariantImageRenderer;
use WBoost\Web\Tests\TemplateDocumentTestData;
use WBoost\Web\Tests\TestingApiAuthentication;
use WBoost\Web\Tests\UnfilledPicturesTestData;

/**
 * The REST API refuses (owner decision O11): an export that would show the
 * grey stand-in an AI scan left behind answers the structured 400
 * `unfilled_pictures` — nothing rendered, nothing recorded — unless the body
 * carries `allowUnfilled: true`. Previews are not exports.
 *
 * @covers \WBoost\Web\Services\AiScan\Template\UnfilledExportGuard
 * @covers \WBoost\Web\Api\Templates\ExportProcessor
 * @covers \WBoost\Web\Api\TemplateGroups\GroupExportProcessor
 * @covers \WBoost\Web\Api\TemplateDocuments\TemplateDocumentRenderProcessor
 */
final class UnfilledPicturesApiExportTest extends ApiTestCase
{
    use UnfilledPicturesTestData;

    private const string VARIANT = TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID;
    private const string SLOT = TestDataFixture::SOCIAL_NETWORK_VARIANT_1_IMAGE_PHOTO_ID;
    private const string VARIANT_URL = '/api/template-variants/' . self::VARIANT;
    private const string GROUP_URL = '/api/template-groups/' . TestDataFixture::TEMPLATE_GROUP_1_ID;

    public function testVariantExportWithADecorativeStandInIsRefusedUnlessAllowed(): void
    {
        [$client, $token] = $this->authenticatedClient();
        self::addDecorative(self::VARIANT, 2);

        $response = $this->post($client, $token, self::VARIANT_URL . '/export', []);

        self::assertSame(400, $response->getStatusCode());
        $body = $response->toArray(false);
        self::assertSame('unfilled_pictures', $body['code']);
        self::assertSame(2, $body['count']);
        self::assertSame(self::VARIANT, $body['variantId']);
        self::assertSame('http://localhost/template-variant/' . self::VARIANT . '/editor', $body['editorUrl']);
        self::assertSame(
            'The export would show 2 grey stand-in pictures left by an AI scan. Fill them in the editor, or send "allowUnfilled": true to export anyway.',
            $body['error'],
        );
        $this->assertNothingExported();

        // A preview is not an export.
        self::assertSame(200, $this->post($client, $token, self::VARIANT_URL . '/preview', [])->getStatusCode());
        self::assertSame(0, $this->exportEvents());

        $allowed = $this->post($client, $token, self::VARIANT_URL . '/export', ['allowUnfilled' => true]);

        self::assertSame(200, $allowed->getStatusCode());
        self::assertSame('image/png', $allowed->getHeaders()['content-type'][0]);
        self::assertSame(1, $this->exportEvents());
    }

    public function testVariantExportWithABackgroundOnTheStandInIsRefusedOnlyWithoutAPicture(): void
    {
        [$client, $token] = $this->authenticatedClient();
        self::putBackgroundOnStandIn(self::VARIANT, self::SLOT);
        self::seedAllowedImage();

        $response = $this->post($client, $token, self::VARIANT_URL . '/export', ['inputs' => []]);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('unfilled_pictures', $response->toArray(false)['code']);
        self::assertSame(1, $response->toArray(false)['count']);
        $this->assertNothingExported();

        self::assertSame(200, $this->post($client, $token, self::VARIANT_URL . '/export', ['images' => [self::SLOT => TestDataFixture::FILE_IN_ALLOWED_ID]])->getStatusCode());
        self::assertSame(200, $this->post($client, $token, self::VARIANT_URL . '/export', ['images' => [self::SLOT => ['hide' => true]]])->getStatusCode());
        self::assertSame(2, $this->exportEvents());
    }

    public function testAnOrdinaryVariantExportsAsBefore(): void
    {
        [$client, $token] = $this->authenticatedClient();
        // The project owns a stand-in, this template does not use it.
        self::standInPath(self::VARIANT);

        self::assertSame(200, $this->post($client, $token, self::VARIANT_URL . '/export', [])->getStatusCode());
        self::assertSame(200, $this->post($client, $token, self::VARIANT_URL . '/export', ['allowUnfilled' => false])->getStatusCode());
    }

    public function testGroupExportSumsOverTheDimensionsExported(): void
    {
        [$client, $token] = $this->authenticatedClient();
        self::addDecorative(TestDataFixture::GROUPED_PRESET_VARIANT_ID);
        self::addDecorative(TestDataFixture::GROUPED_FREEFORM_VARIANT_ID);

        $zip = $this->post($client, $token, self::GROUP_URL . '/export', []);

        self::assertSame(400, $zip->getStatusCode());
        self::assertSame('unfilled_pictures', $zip->toArray(false)['code']);
        self::assertSame(2, $zip->toArray(false)['count']);
        $this->assertNothingExported();

        $single = $this->post($client, $token, self::GROUP_URL . '/export/' . TestDataFixture::GROUPED_FREEFORM_VARIANT_ID, []);

        self::assertSame(400, $single->getStatusCode());
        self::assertSame(1, $single->toArray(false)['count']);
        self::assertSame(TestDataFixture::GROUPED_FREEFORM_VARIANT_ID, $single->toArray(false)['variantId']);
        $this->assertNothingExported();

        // A preview is not an export.
        self::assertSame(200, $this->post($client, $token, self::GROUP_URL . '/preview/' . TestDataFixture::GROUPED_PRESET_VARIANT_ID, [])->getStatusCode());

        $allowed = $this->post($client, $token, self::GROUP_URL . '/export', ['allowUnfilled' => true]);

        self::assertSame(200, $allowed->getStatusCode());
        self::assertSame('application/zip', $allowed->getHeaders()['content-type'][0]);
        self::assertSame(2, $this->exportEvents());
    }

    public function testGroupExportWithABackgroundOnTheStandInIsRefusedOnlyWithoutAPicture(): void
    {
        [$client, $token] = $this->authenticatedClient();
        self::putBackgroundOnStandIn(TestDataFixture::GROUPED_PRESET_VARIANT_ID, TestDataFixture::GROUP_SHARED_IMAGE_INPUT_ID);
        self::seedAllowedImage();

        $refused = $this->post($client, $token, self::GROUP_URL . '/export', []);

        self::assertSame(400, $refused->getStatusCode());
        self::assertSame('unfilled_pictures', $refused->toArray(false)['code']);
        self::assertSame(TestDataFixture::GROUPED_PRESET_VARIANT_ID, $refused->toArray(false)['variantId']);
        $this->assertNothingExported();

        $filled = $this->post($client, $token, self::GROUP_URL . '/export', ['images' => [TestDataFixture::GROUP_SHARED_IMAGE_INPUT_ID => TestDataFixture::FILE_IN_ALLOWED_ID]]);

        self::assertSame(200, $filled->getStatusCode());
    }

    public function testDocumentExportSumsOverItsPagesAndThePagePreviewIsUntouched(): void
    {
        [$client, $token] = $this->authenticatedClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $first = TemplateDocumentTestData::richA4Template($em, 'Úvod');
        $second = TemplateDocumentTestData::richA4Template($em, 'Ceník');
        $documentId = TemplateDocumentTestData::document($em, self::getContainer()->get(MessageBusInterface::class), [$first['template'], $second['template']]);
        self::addDecorative($first['variant']->toString());
        self::putBackgroundOnStandIn($second['variant']->toString(), TemplateDocumentTestData::IMAGE_INPUT_ID);
        $url = '/api/template-documents/' . $documentId . '/export';

        $refused = $this->post($client, $token, $url, ['format' => 'png']);

        self::assertSame(400, $refused->getStatusCode());
        $body = $refused->toArray(false);
        self::assertSame('unfilled_pictures', $body['code']);
        self::assertSame(2, $body['count']);
        self::assertSame($first['variant']->toString(), $body['variantId']);
        $this->assertNothingExported();

        // Page 2's background hidden — page 1's decorative picture stays owed.
        $hidden = ['pages' => [$second['template']->toString() => ['images' => [TemplateDocumentTestData::IMAGE_INPUT_ID => ['hide' => true]]]]];
        $partly = $this->post($client, $token, $url, ['format' => 'png'] + $hidden);

        self::assertSame(400, $partly->getStatusCode());
        self::assertSame(1, $partly->toArray(false)['count']);
        $this->assertNothingExported();

        $allowed = $this->post($client, $token, $url, ['format' => 'png', 'allowUnfilled' => true]);

        self::assertSame(200, $allowed->getStatusCode());
        self::assertSame(2, $this->exportEvents());
    }

    public function testAnOrdinaryDocumentExportsAsBefore(): void
    {
        [$client, $token] = $this->authenticatedClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $page = TemplateDocumentTestData::richA4Template($em, 'Úvod');
        $documentId = TemplateDocumentTestData::document($em, self::getContainer()->get(MessageBusInterface::class), [$page['template']]);
        self::standInPath($page['variant']->toString());

        self::assertSame(200, $this->post($client, $token, '/api/template-documents/' . $documentId . '/export', ['format' => 'png'])->getStatusCode());
    }

    /**
     * @return array{Client, string}
     */
    private function authenticatedClient(): array
    {
        $client = self::createClient();

        return [$client, TestingApiAuthentication::getAccessToken($client, TestDataFixture::OAUTH2_CLIENT_ID, TestDataFixture::OAUTH2_CLIENT_SECRET)];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(Client $client, string $token, string $path, array $body): ResponseInterface
    {
        return $client->request('POST', $path, [
            'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
            'body' => json_encode((object) $body, JSON_THROW_ON_ERROR),
        ]);
    }

    private function assertNothingExported(): void
    {
        $renderer = self::getContainer()->get(TemplateVariantImageRendererInterface::class);
        /** @phpstan-ignore staticMethod.impossibleType */
        self::assertInstanceOf(FakeTemplateVariantImageRenderer::class, $renderer);

        self::assertSame([], $renderer->calls, 'a refused export renders nothing');
        self::assertSame(0, $this->exportEvents(), 'a refused export records nothing');
    }

    private function exportEvents(): int
    {
        return self::getContainer()->get(EntityManagerInterface::class)->getRepository(ExportEvent::class)->count([]);
    }
}
