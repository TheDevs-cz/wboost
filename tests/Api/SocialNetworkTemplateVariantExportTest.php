<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Api;

use WBoost\Web\Services\SocialNetwork\SocialNetworkTemplateVariantImageRendererInterface;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\Fakes\FakeSocialNetworkTemplateVariantImageRenderer;
use WBoost\Web\Tests\TestingApiAuthentication;

/**
 * @covers \WBoost\Web\Api\SocialNetworkTemplates\ExportProcessor
 * @covers \WBoost\Web\Api\SocialNetworkTemplates\ExportRequest
 * @covers \WBoost\Web\Api\SocialNetworkTemplates\SocialNetworkTemplateVariantResource
 * @covers \WBoost\Web\Services\SocialNetwork\ResolveTextOverrides
 * @covers \WBoost\Web\Value\ResolvedInputOverrides
 */
final class SocialNetworkTemplateVariantExportTest extends ApiTestCase
{
    private const string PNG_MAGIC = "\x89PNG\r\n\x1a\n";

    public function testRequiresAuthentication(): void
    {
        $client = self::createClient();
        $client->request(
            'POST',
            '/api/social-network-template-variants/' . TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID . '/export',
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
            '/api/social-network-template-variants/' . TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_2_ID . '/export',
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
            '/api/social-network-template-variants/' . TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID . '/export',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode([
                    'inputs' => [
                        TestDataFixture::SOCIAL_NETWORK_VARIANT_1_INPUT_HEADLINE_ID => str_repeat('A', 31),
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
            '/api/social-network-template-variants/' . TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID . '/export',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode([
                    'inputs' => [
                        TestDataFixture::SOCIAL_NETWORK_VARIANT_1_INPUT_HEADLINE_ID => 'Hello',
                        TestDataFixture::SOCIAL_NETWORK_VARIANT_1_INPUT_TAGLINE_ID => 'world',
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
        // headline: shorthand string, no transform
        self::assertSame('Hello', $lastCall['texts'][TestDataFixture::SOCIAL_NETWORK_VARIANT_1_INPUT_HEADLINE_ID] ?? null);
        // tagline: uppercase=true, applied server-side
        self::assertSame('WORLD', $lastCall['texts'][TestDataFixture::SOCIAL_NETWORK_VARIANT_1_INPUT_TAGLINE_ID] ?? null);
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
            '/api/social-network-template-variants/' . TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID . '/export',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode([
                    'inputs' => [
                        TestDataFixture::SOCIAL_NETWORK_VARIANT_1_INPUT_HEADLINE_ID => ['value' => 'Hello'],
                        // badge is hidable — the hide flag must be honored.
                        TestDataFixture::SOCIAL_NETWORK_VARIANT_1_INPUT_BADGE_ID => ['hide' => true],
                    ],
                ], JSON_THROW_ON_ERROR),
            ],
        );

        $this->assertResponseIsSuccessful();
        $fake = $this->getRendererFake();
        $lastCall = $fake->calls[count($fake->calls) - 1];

        self::assertSame('Hello', $lastCall['texts'][TestDataFixture::SOCIAL_NETWORK_VARIANT_1_INPUT_HEADLINE_ID] ?? null);
        // badge is hidable — hidden entry present.
        self::assertTrue($lastCall['hidden'][TestDataFixture::SOCIAL_NETWORK_VARIANT_1_INPUT_BADGE_ID] ?? null);
        // headline is NOT hidable, no hide entry.
        self::assertArrayNotHasKey(TestDataFixture::SOCIAL_NETWORK_VARIANT_1_INPUT_HEADLINE_ID, $lastCall['hidden']);
    }

    public function testHideOnNonHidableInputIsSilentlyIgnored(): void
    {
        $client = self::createClient();
        $token = TestingApiAuthentication::getAccessToken(
            $client,
            TestDataFixture::OAUTH2_CLIENT_ID,
            TestDataFixture::OAUTH2_CLIENT_SECRET,
        );

        $client->request(
            'POST',
            '/api/social-network-template-variants/' . TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID . '/export',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json',
                ],
                // headline has hidable: false. Sending hide: true must not produce a hidden entry.
                'body' => json_encode([
                    'inputs' => [
                        TestDataFixture::SOCIAL_NETWORK_VARIANT_1_INPUT_HEADLINE_ID => ['value' => 'Hi', 'hide' => true],
                    ],
                ], JSON_THROW_ON_ERROR),
            ],
        );

        $this->assertResponseIsSuccessful();
        $fake = $this->getRendererFake();
        $lastCall = $fake->calls[count($fake->calls) - 1];

        self::assertSame('Hi', $lastCall['texts'][TestDataFixture::SOCIAL_NETWORK_VARIANT_1_INPUT_HEADLINE_ID] ?? null);
        self::assertSame([], $lastCall['hidden'], 'hide must be ignored for non-hidable inputs.');
    }

    public function testRejectsNonBooleanHide(): void
    {
        $client = self::createClient();
        $token = TestingApiAuthentication::getAccessToken(
            $client,
            TestDataFixture::OAUTH2_CLIENT_ID,
            TestDataFixture::OAUTH2_CLIENT_SECRET,
        );

        $client->request(
            'POST',
            '/api/social-network-template-variants/' . TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID . '/export',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode([
                    'inputs' => [
                        TestDataFixture::SOCIAL_NETWORK_VARIANT_1_INPUT_BADGE_ID => ['hide' => 'yes'],
                    ],
                ], JSON_THROW_ON_ERROR),
            ],
        );

        $this->assertResponseStatusCodeSame(400);
    }

    public function testUnknownInputIdIsSilentlyIgnored(): void
    {
        $client = self::createClient();
        $token = TestingApiAuthentication::getAccessToken(
            $client,
            TestDataFixture::OAUTH2_CLIENT_ID,
            TestDataFixture::OAUTH2_CLIENT_SECRET,
        );

        $client->request(
            'POST',
            '/api/social-network-template-variants/' . TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID . '/export',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode([
                    'inputs' => [
                        '99999999-9999-9999-9999-999999999999' => 'no such input',
                    ],
                ], JSON_THROW_ON_ERROR),
            ],
        );

        $this->assertResponseIsSuccessful();
        $fake = $this->getRendererFake();
        $lastCall = $fake->calls[count($fake->calls) - 1];

        self::assertSame([], $lastCall['texts']);
        self::assertSame([], $lastCall['hidden']);
    }

    private function getRendererFake(): FakeSocialNetworkTemplateVariantImageRenderer
    {
        // In the test env, the renderer interface aliases to the fake (see config/services_test.php).
        // PHPStan reads the dev container.xml where the alias points to the real impl, so we
        // suppress its "always false" warning here — at runtime under PHPUnit it IS the fake.
        $renderer = self::getContainer()->get(SocialNetworkTemplateVariantImageRendererInterface::class);
        /** @phpstan-ignore staticMethod.impossibleType */
        self::assertInstanceOf(FakeSocialNetworkTemplateVariantImageRenderer::class, $renderer);

        return $renderer;
    }
}
