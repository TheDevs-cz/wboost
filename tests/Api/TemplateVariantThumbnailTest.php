<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Api;

use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingApiAuthentication;

/**
 * The thumbnail endpoint at its canonical path (the former module alias paths
 * are gone). The
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

    public function testLegacyAliasPathsAreGone(): void
    {
        $client = self::createClient();
        $token = TestingApiAuthentication::getAccessToken(
            $client,
            TestDataFixture::OAUTH2_CLIENT_ID,
            TestDataFixture::OAUTH2_CLIENT_SECRET,
        );

        foreach (['custom-template-variants', 'social-network-template-variants'] as $segment) {
            $client->request(
                'GET',
                '/api/' . $segment . '/' . TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID . '/thumbnail',
                ['headers' => ['Authorization' => 'Bearer ' . $token]],
            );
            $this->assertResponseStatusCodeSame(404);
        }
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
