<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Api;

use WBoost\Web\Services\Editor\TemplateVariantImageRendererInterface;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\Fakes\FakeTemplateVariantImageRenderer;
use WBoost\Web\Tests\TestingApiAuthentication;

/**
 * @covers \WBoost\Web\Api\FlyerTemplates\ExportProcessor
 * @covers \WBoost\Web\Api\FlyerTemplates\ExportRequest
 * @covers \WBoost\Web\Api\FlyerTemplates\FlyerTemplateVariantResource
 */
final class FlyerTemplateVariantExportTest extends ApiTestCase
{
    private const string PNG_MAGIC = "\x89PNG\r\n\x1a\n";

    public function testRequiresAuthentication(): void
    {
        $client = self::createClient();
        $client->request(
            'POST',
            '/api/flyer-template-variants/' . TestDataFixture::FLYER_TEMPLATE_VARIANT_1_ID . '/export',
            ['headers' => ['Content-Type' => 'application/json'], 'body' => '{"inputs":{}}'],
        );
        $this->assertResponseStatusCodeSame(401);
    }

    public function testReturnsForbiddenForVariantInOtherUsersProject(): void
    {
        $client = self::createClient();
        $token = TestingApiAuthentication::getAccessToken(
            $client,
            TestDataFixture::OAUTH2_CLIENT_ID,
            TestDataFixture::OAUTH2_CLIENT_SECRET,
        );

        $client->request(
            'POST',
            '/api/flyer-template-variants/' . TestDataFixture::FLYER_TEMPLATE_VARIANT_2_ID . '/export',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json',
                ],
                'body' => '{"inputs":{}}',
            ],
        );

        $this->assertResponseStatusCodeSame(403);
    }

    public function testRejectsInputExceedingMaxLength(): void
    {
        $client = self::createClient();
        $token = TestingApiAuthentication::getAccessToken(
            $client,
            TestDataFixture::OAUTH2_CLIENT_ID,
            TestDataFixture::OAUTH2_CLIENT_SECRET,
        );

        $client->request(
            'POST',
            '/api/flyer-template-variants/' . TestDataFixture::FLYER_TEMPLATE_VARIANT_1_ID . '/export',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode([
                    'inputs' => [
                        TestDataFixture::FLYER_VARIANT_1_INPUT_HEADLINE_ID => str_repeat('A', 31),
                    ],
                ], JSON_THROW_ON_ERROR),
            ],
        );

        $this->assertResponseStatusCodeSame(400);
    }

    public function testReturnsPngOnHappyPath(): void
    {
        $client = self::createClient();
        $token = TestingApiAuthentication::getAccessToken(
            $client,
            TestDataFixture::OAUTH2_CLIENT_ID,
            TestDataFixture::OAUTH2_CLIENT_SECRET,
        );

        $response = $client->request(
            'POST',
            '/api/flyer-template-variants/' . TestDataFixture::FLYER_TEMPLATE_VARIANT_1_ID . '/export',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode([
                    'inputs' => [
                        TestDataFixture::FLYER_VARIANT_1_INPUT_HEADLINE_ID => 'Hello',
                        TestDataFixture::FLYER_VARIANT_1_INPUT_TAGLINE_ID => 'world',
                    ],
                ], JSON_THROW_ON_ERROR),
            ],
        );

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'image/png');

        $body = $response->getContent();
        self::assertStringStartsWith(self::PNG_MAGIC, $body, 'Response body must be a PNG.');

        $fake = $this->getRendererFake();
        $lastCall = $fake->calls[count($fake->calls) - 1];
        self::assertSame(TestDataFixture::FLYER_TEMPLATE_VARIANT_1_ID, $lastCall['variantId']);
        // headline: shorthand string
        self::assertSame('Hello', $lastCall['texts'][TestDataFixture::FLYER_VARIANT_1_INPUT_HEADLINE_ID] ?? null);
        // tagline: uppercase=true, applied server-side
        self::assertSame('WORLD', $lastCall['texts'][TestDataFixture::FLYER_VARIANT_1_INPUT_TAGLINE_ID] ?? null);
        self::assertSame([], $lastCall['hidden']);
    }

    public function testExtendedShapeAppliesValueAndHide(): void
    {
        $client = self::createClient();
        $token = TestingApiAuthentication::getAccessToken(
            $client,
            TestDataFixture::OAUTH2_CLIENT_ID,
            TestDataFixture::OAUTH2_CLIENT_SECRET,
        );

        $client->request(
            'POST',
            '/api/flyer-template-variants/' . TestDataFixture::FLYER_TEMPLATE_VARIANT_1_ID . '/export',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode([
                    'inputs' => [
                        TestDataFixture::FLYER_VARIANT_1_INPUT_HEADLINE_ID => ['value' => 'Hello'],
                        // badge is hidable — the hide flag must be honored.
                        TestDataFixture::FLYER_VARIANT_1_INPUT_BADGE_ID => ['hide' => true],
                    ],
                ], JSON_THROW_ON_ERROR),
            ],
        );

        $this->assertResponseIsSuccessful();
        $fake = $this->getRendererFake();
        $lastCall = $fake->calls[count($fake->calls) - 1];

        self::assertSame('Hello', $lastCall['texts'][TestDataFixture::FLYER_VARIANT_1_INPUT_HEADLINE_ID] ?? null);
        self::assertTrue($lastCall['hidden'][TestDataFixture::FLYER_VARIANT_1_INPUT_BADGE_ID] ?? null);
    }

    public function testFillsImagePlaceholderFromAllowedFolder(): void
    {
        $client = self::createClient();
        $token = TestingApiAuthentication::getAccessToken(
            $client,
            TestDataFixture::OAUTH2_CLIENT_ID,
            TestDataFixture::OAUTH2_CLIENT_SECRET,
        );

        // The render path reads the picture's natural size, so the fixture file
        // must exist in the (local test) object store.
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkAAIAAAoAAv/lxKUAAAAASUVORK5CYII=', true);
        self::assertIsString($png);
        self::getContainer()->get('oneup_flysystem.minio_filesystem')->write('fixtures/in-allowed.png', $png);

        $client->request(
            'POST',
            '/api/flyer-template-variants/' . TestDataFixture::FLYER_TEMPLATE_VARIANT_1_ID . '/export',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode([
                    'images' => [
                        TestDataFixture::FLYER_VARIANT_1_IMAGE_PHOTO_ID => TestDataFixture::FILE_IN_ALLOWED_ID,
                    ],
                ], JSON_THROW_ON_ERROR),
            ],
        );

        $this->assertResponseIsSuccessful();
        $fake = $this->getRendererFake();
        $lastCall = $fake->calls[count($fake->calls) - 1];

        self::assertArrayHasKey(TestDataFixture::FLYER_VARIANT_1_IMAGE_PHOTO_ID, $lastCall['images']);
    }

    public function testRejectsImageFromForbiddenFolder(): void
    {
        $client = self::createClient();
        $token = TestingApiAuthentication::getAccessToken(
            $client,
            TestDataFixture::OAUTH2_CLIENT_ID,
            TestDataFixture::OAUTH2_CLIENT_SECRET,
        );

        $client->request(
            'POST',
            '/api/flyer-template-variants/' . TestDataFixture::FLYER_TEMPLATE_VARIANT_1_ID . '/export',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode([
                    'images' => [
                        TestDataFixture::FLYER_VARIANT_1_IMAGE_PHOTO_ID => TestDataFixture::FILE_IN_OTHER_ID,
                    ],
                ], JSON_THROW_ON_ERROR),
            ],
        );

        $this->assertResponseStatusCodeSame(400);
    }

    private function getRendererFake(): FakeTemplateVariantImageRenderer
    {
        // In the test env, the renderer interface aliases to the fake (see config/services_test.php).
        // PHPStan reads the dev container.xml where the alias points to the real impl, so we
        // suppress its "always false" warning here — at runtime under PHPUnit it IS the fake.
        $renderer = self::getContainer()->get(TemplateVariantImageRendererInterface::class);
        /** @phpstan-ignore staticMethod.impossibleType */
        self::assertInstanceOf(FakeTemplateVariantImageRenderer::class, $renderer);

        return $renderer;
    }
}
