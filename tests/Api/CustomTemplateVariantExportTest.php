<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Api;

use WBoost\Web\Exceptions\ContainerOverflow;
use WBoost\Web\Services\Editor\TemplateVariantImageRendererInterface;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\Fakes\FakeTemplateVariantImageRenderer;
use WBoost\Web\Tests\TestingApiAuthentication;

/**
 * @covers \WBoost\Web\Api\CustomTemplates\ExportProcessor
 * @covers \WBoost\Web\Api\CustomTemplates\ExportRequest
 * @covers \WBoost\Web\Api\CustomTemplates\CustomTemplateVariantResource
 */
final class CustomTemplateVariantExportTest extends ApiTestCase
{
    private const string PNG_MAGIC = "\x89PNG\r\n\x1a\n";

    public function testRequiresAuthentication(): void
    {
        $client = self::createClient();
        $client->request(
            'POST',
            '/api/custom-template-variants/' . TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID . '/export',
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
            '/api/custom-template-variants/' . TestDataFixture::CUSTOM_TEMPLATE_VARIANT_2_ID . '/export',
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
            '/api/custom-template-variants/' . TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID . '/export',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode([
                    'inputs' => [
                        TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_INPUT_HEADLINE_ID => str_repeat('A', 31),
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
            '/api/custom-template-variants/' . TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID . '/export',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode([
                    'inputs' => [
                        TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_INPUT_HEADLINE_ID => 'Hello',
                        TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_INPUT_TAGLINE_ID => 'world',
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
        self::assertSame(TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID, $lastCall['variantId']);
        // headline: shorthand string
        self::assertSame('Hello', $lastCall['texts'][TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_INPUT_HEADLINE_ID] ?? null);
        // tagline: uppercase=true, applied server-side
        self::assertSame('WORLD', $lastCall['texts'][TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_INPUT_TAGLINE_ID] ?? null);
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
            '/api/custom-template-variants/' . TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID . '/export',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode([
                    'inputs' => [
                        TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_INPUT_HEADLINE_ID => ['value' => 'Hello'],
                        // badge is hidable — the hide flag must be honored.
                        TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_INPUT_BADGE_ID => ['hide' => true],
                    ],
                ], JSON_THROW_ON_ERROR),
            ],
        );

        $this->assertResponseIsSuccessful();
        $fake = $this->getRendererFake();
        $lastCall = $fake->calls[count($fake->calls) - 1];

        self::assertSame('Hello', $lastCall['texts'][TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_INPUT_HEADLINE_ID] ?? null);
        self::assertTrue($lastCall['hidden'][TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_INPUT_BADGE_ID] ?? null);
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
            '/api/custom-template-variants/' . TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID . '/export',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode([
                    'images' => [
                        TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_IMAGE_PHOTO_ID => TestDataFixture::FILE_IN_ALLOWED_ID,
                    ],
                ], JSON_THROW_ON_ERROR),
            ],
        );

        $this->assertResponseIsSuccessful();
        $fake = $this->getRendererFake();
        $lastCall = $fake->calls[count($fake->calls) - 1];

        self::assertArrayHasKey(TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_IMAGE_PHOTO_ID, $lastCall['images']);
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
            '/api/custom-template-variants/' . TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID . '/export',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode([
                    'images' => [
                        TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_IMAGE_PHOTO_ID => TestDataFixture::FILE_IN_OTHER_ID,
                    ],
                ], JSON_THROW_ON_ERROR),
            ],
        );

        $this->assertResponseStatusCodeSame(400);
    }

    /**
     * Mirror of the social-module contract: custom-template export is the
     * STRICT container-overflow path and returns the structured 400.
     */
    public function testContainerOverflowReturnsStructured400(): void
    {
        $client = self::createClient();
        // Keep one kernel across the token + export requests: the overflow is
        // pre-armed on the fake renderer instance, which a kernel reboot
        // between requests would silently replace.
        $client->disableReboot();
        $token = TestingApiAuthentication::getAccessToken(
            $client,
            TestDataFixture::OAUTH2_CLIENT_ID,
            TestDataFixture::OAUTH2_CLIENT_SECRET,
        );

        $this->getRendererFake()->throwContainerOverflow = new ContainerOverflow('11111111-2222-4333-8444-555555555555', 12.0);

        $response = $client->request(
            'POST',
            '/api/custom-template-variants/' . TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID . '/export',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json',
                ],
                'body' => '{"inputs":{}}',
            ],
        );

        $this->assertResponseStatusCodeSame(400);
        $body = $response->toArray(false);
        self::assertSame('container_overflow', $body['code'] ?? null);
        self::assertSame('11111111-2222-4333-8444-555555555555', $body['containerId'] ?? null);
        self::assertEqualsWithDelta(12.0, $body['overflowPx'] ?? null, 0.001);
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
