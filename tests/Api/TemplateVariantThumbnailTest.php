<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Api;

use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingApiAuthentication;

/**
 * The thumbnail endpoint at its CANONICAL path plus the two deprecated legacy
 * aliases (former custom-template and social-network module paths). The
 * fixture variant carries no preview render, so the endpoint streams its
 * background image — the documented fallback.
 *
 * @covers \WBoost\Web\Controller\Template\TemplateVariantThumbnailController
 */
final class TemplateVariantThumbnailTest extends ApiTestCase
{
    private const string PNG_1X1_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkAAIAAAoAAv/lxKUAAAAASUVORK5CYII=';

    public function testRequiresAuthentication(): void
    {
        $client = self::createClient();
        $client->request(
            'GET',
            '/api/template-variants/' . TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID . '/thumbnail',
        );

        $this->assertResponseStatusCodeSame(401);
    }

    public function testCanonicalPathStreamsTheBackgroundFallback(): void
    {
        $client = self::createClient();
        $token = TestingApiAuthentication::getAccessToken(
            $client,
            TestDataFixture::OAUTH2_CLIENT_ID,
            TestDataFixture::OAUTH2_CLIENT_SECRET,
        );

        $this->writeTestImage('fixtures/custom-template-bg-1.png');

        $response = $client->request(
            'GET',
            '/api/template-variants/' . TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID . '/thumbnail',
            ['headers' => ['Authorization' => 'Bearer ' . $token]],
        );

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'image/png');

        $bytes = base64_decode(self::PNG_1X1_BASE64, true);
        self::assertIsString($bytes);
        self::assertSame($bytes, $response->getContent());
    }

    public function testLegacyAliasPathsStreamTheSameThumbnail(): void
    {
        $client = self::createClient();
        $token = TestingApiAuthentication::getAccessToken(
            $client,
            TestDataFixture::OAUTH2_CLIENT_ID,
            TestDataFixture::OAUTH2_CLIENT_SECRET,
        );

        $this->writeTestImage('fixtures/custom-template-bg-1.png');
        $this->writeTestImage('fixtures/bg-1.png');

        // Former custom-template path, hitting a free-form variant.
        $client->request(
            'GET',
            '/api/custom-template-variants/' . TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID . '/thumbnail',
            ['headers' => ['Authorization' => 'Bearer ' . $token]],
        );
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'image/png');

        // Former social path, hitting a former-social (preset) variant.
        $client->request(
            'GET',
            '/api/social-network-template-variants/' . TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID . '/thumbnail',
            ['headers' => ['Authorization' => 'Bearer ' . $token]],
        );
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'image/png');
    }

    public function testForbiddenForVariantInOtherUsersProject(): void
    {
        $client = self::createClient();
        $token = TestingApiAuthentication::getAccessToken(
            $client,
            TestDataFixture::OAUTH2_CLIENT_ID,
            TestDataFixture::OAUTH2_CLIENT_SECRET,
        );

        $client->request(
            'GET',
            '/api/template-variants/' . TestDataFixture::CUSTOM_TEMPLATE_VARIANT_2_ID . '/thumbnail',
            ['headers' => ['Authorization' => 'Bearer ' . $token]],
        );

        $this->assertResponseStatusCodeSame(403);
    }

    private function writeTestImage(string $path): void
    {
        $bytes = base64_decode(self::PNG_1X1_BASE64, true);
        self::assertIsString($bytes);

        self::getContainer()->get('oneup_flysystem.minio_filesystem')->write($path, $bytes);
    }
}
