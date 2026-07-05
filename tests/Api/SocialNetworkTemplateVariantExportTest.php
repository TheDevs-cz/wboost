<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Api;

use WBoost\Web\Exceptions\ContainerOverflow;
use WBoost\Web\Services\Editor\TemplateVariantImageRendererInterface;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\Fakes\FakeTemplateVariantImageRenderer;
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

    /**
     * The API export is the STRICT container-overflow path: the renderer must
     * be invoked with strictContainerOverflow=true (web fill/download stay
     * lenient) so an overfilled container fails instead of silently rendering
     * a broken PNG.
     */
    public function testExportRendersInStrictContainerOverflowMode(): void
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
                'body' => '{"inputs":{}}',
            ],
        );

        $this->assertResponseIsSuccessful();
        $fake = $this->getRendererFake();
        $lastCall = $fake->calls[count($fake->calls) - 1];
        self::assertTrue($lastCall['strictContainerOverflow']);
    }

    /**
     * Container overflow → 400 with the documented STRUCTURED body (code +
     * containerId + overflowPx) so a consumer can point the user at the
     * offending container's inputs. This is a public API contract consumed by
     * the mfkfm backoffice.
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

        $this->getRendererFake()->throwContainerOverflow = new ContainerOverflow(
            TestDataFixture::SOCIAL_NETWORK_VARIANT_1_CONTAINER_ID,
            37.512,
        );

        $response = $client->request(
            'POST',
            '/api/social-network-template-variants/' . TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID . '/export',
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
        self::assertSame(TestDataFixture::SOCIAL_NETWORK_VARIANT_1_CONTAINER_ID, $body['containerId'] ?? null);
        self::assertEqualsWithDelta(37.51, $body['overflowPx'] ?? null, 0.001);
        self::assertIsString($body['error'] ?? null);
    }

    public function testRichRunsRenderAsStyledOverride(): void
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
                        // headline has richText: true (fixture); Rubik faces are
                        // whitelisted via the canvas family "Rubik (Rubik Bold)".
                        TestDataFixture::SOCIAL_NETWORK_VARIANT_1_INPUT_HEADLINE_ID => ['runs' => [
                            ['text' => 'Hello '],
                            ['text' => 'world', 'fontFamily' => 'Rubik (Rubik Regular)', 'color' => '#C8102E', 'underline' => true],
                        ]],
                    ],
                ], JSON_THROW_ON_ERROR),
            ],
        );

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'image/png');
        self::assertStringStartsWith(self::PNG_MAGIC, $response->getContent());

        $fake = $this->getRendererFake();
        $lastCall = $fake->calls[count($fake->calls) - 1];

        // The plain concatenation always lands in texts…
        self::assertSame('Hello world', $lastCall['texts'][TestDataFixture::SOCIAL_NETWORK_VARIANT_1_INPUT_HEADLINE_ID] ?? null);
        // …and the styled runs (color normalized to lowercase) in richTexts.
        self::assertSame(
            [
                ['text' => 'Hello ', 'fontFamily' => null, 'color' => null, 'underline' => false],
                ['text' => 'world', 'fontFamily' => 'Rubik (Rubik Regular)', 'color' => '#c8102e', 'underline' => true],
            ],
            $lastCall['richTexts'][TestDataFixture::SOCIAL_NETWORK_VARIANT_1_INPUT_HEADLINE_ID] ?? null,
        );
    }

    public function testUnstyledRichRunsDegradeToPlainOverride(): void
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
                        TestDataFixture::SOCIAL_NETWORK_VARIANT_1_INPUT_HEADLINE_ID => ['runs' => [['text' => 'Plain enough']]],
                    ],
                ], JSON_THROW_ON_ERROR),
            ],
        );

        $this->assertResponseIsSuccessful();
        $fake = $this->getRendererFake();
        $lastCall = $fake->calls[count($fake->calls) - 1];

        self::assertSame('Plain enough', $lastCall['texts'][TestDataFixture::SOCIAL_NETWORK_VARIANT_1_INPUT_HEADLINE_ID] ?? null);
        self::assertSame([], $lastCall['richTexts']);
    }

    public function testRichRunsOnNonRichInputAre400WithCode(): void
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
                        // tagline is NOT richText-enabled.
                        TestDataFixture::SOCIAL_NETWORK_VARIANT_1_INPUT_TAGLINE_ID => ['runs' => [['text' => 'nope']]],
                    ],
                ], JSON_THROW_ON_ERROR),
            ],
        );

        $this->assertResponseStatusCodeSame(400);
        $body = $response->toArray(false);
        self::assertSame('rich_text_not_allowed', $body['code'] ?? null);
    }

    public function testRichFontOutsideWhitelistIs400WithAllowedFonts(): void
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
                        TestDataFixture::SOCIAL_NETWORK_VARIANT_1_INPUT_HEADLINE_ID => ['runs' => [
                            ['text' => 'x', 'fontFamily' => 'Comic Sans (Regular)'],
                        ]],
                    ],
                ], JSON_THROW_ON_ERROR),
            ],
        );

        $this->assertResponseStatusCodeSame(400);
        $body = $response->toArray(false);
        self::assertSame('font_not_allowed', $body['code'] ?? null);
        self::assertSame(['Rubik (Rubik Regular)', 'Rubik (Rubik Bold)'], $body['allowedFonts'] ?? null);
    }

    public function testRichInvalidColorIs400WithCode(): void
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
                        TestDataFixture::SOCIAL_NETWORK_VARIANT_1_INPUT_HEADLINE_ID => ['runs' => [
                            ['text' => 'x', 'color' => 'definitely-not-hex'],
                        ]],
                    ],
                ], JSON_THROW_ON_ERROR),
            ],
        );

        $this->assertResponseStatusCodeSame(400);
        $body = $response->toArray(false);
        self::assertSame('invalid_color', $body['code'] ?? null);
    }

    public function testRichRunsCombinedWithValueAre400WithCode(): void
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
                        TestDataFixture::SOCIAL_NETWORK_VARIANT_1_INPUT_HEADLINE_ID => [
                            'runs' => [['text' => 'x']],
                            'value' => 'y',
                        ],
                    ],
                ], JSON_THROW_ON_ERROR),
            ],
        );

        $this->assertResponseStatusCodeSame(400);
        $body = $response->toArray(false);
        self::assertSame('invalid_rich_text', $body['code'] ?? null);
    }

    public function testRichRunsExceedingMaxLengthAre400(): void
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
                        // headline maxLength is 30 — the CONCATENATION counts.
                        TestDataFixture::SOCIAL_NETWORK_VARIANT_1_INPUT_HEADLINE_ID => ['runs' => [
                            ['text' => str_repeat('a', 20)],
                            ['text' => str_repeat('b', 20), 'underline' => true],
                        ]],
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
