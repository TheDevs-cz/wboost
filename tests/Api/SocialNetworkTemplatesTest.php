<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Api;

use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingApiAuthentication;

/**
 * @covers \WBoost\Web\Api\SocialNetworkTemplates\SocialNetworkTemplateResponse
 * @covers \WBoost\Web\Api\SocialNetworkTemplates\SocialNetworkTemplateVariantResponse
 * @covers \WBoost\Web\Api\SocialNetworkTemplates\SocialNetworkTemplateVariantInputResponse
 * @covers \WBoost\Web\Api\SocialNetworkTemplates\SocialNetworkTemplateVariantImageInputResponse
 * @covers \WBoost\Web\Api\SocialNetworkTemplates\SocialNetworkTemplateVariantImageInputFrameResponse
 * @covers \WBoost\Web\Api\SocialNetworkTemplates\SocialNetworkTemplatesProvider
 */
final class SocialNetworkTemplatesTest extends ApiTestCase
{
    public function testRequiresAuthentication(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/projects/' . TestDataFixture::PROJECT_1_ID . '/social-network-templates');
        $this->assertResponseStatusCodeSame(401);
    }

    public function testReturnsTemplatesForRequestedProject(): void
    {
        $client = self::createClient();
        $token = TestingApiAuthentication::getAccessToken(
            $client,
            TestDataFixture::OAUTH2_CLIENT_ID,
            TestDataFixture::OAUTH2_CLIENT_SECRET,
        );

        $response = $client->request('GET', '/api/projects/' . TestDataFixture::PROJECT_1_ID . '/social-network-templates', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json; charset=utf-8');

        $body = $response->toArray();

        $ids = [];
        foreach ($body as $row) {
            self::assertIsArray($row);
            self::assertArrayHasKey('id', $row);
            self::assertIsString($row['id']);
            $ids[] = $row['id'];
        }

        self::assertContains(TestDataFixture::SOCIAL_NETWORK_TEMPLATE_1_ID, $ids);
        self::assertNotContains(
            TestDataFixture::SOCIAL_NETWORK_TEMPLATE_2_ID,
            $ids,
            'Templates from another project must not appear.',
        );
    }

    public function testReturnsNotFoundForProjectOwnedByAnotherUser(): void
    {
        $client = self::createClient();
        $token = TestingApiAuthentication::getAccessToken(
            $client,
            TestDataFixture::OAUTH2_CLIENT_ID,
            TestDataFixture::OAUTH2_CLIENT_SECRET,
        );

        // PROJECT_2 belongs to USER_2 — querying it with USER_1's token must 404,
        // not leak the project's existence or its templates.
        $client->request('GET', '/api/projects/' . TestDataFixture::PROJECT_2_ID . '/social-network-templates', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);

        $this->assertResponseStatusCodeSame(404);
    }

    public function testReturnsNotFoundForUnknownProject(): void
    {
        $client = self::createClient();
        $token = TestingApiAuthentication::getAccessToken(
            $client,
            TestDataFixture::OAUTH2_CLIENT_ID,
            TestDataFixture::OAUTH2_CLIENT_SECRET,
        );

        $client->request('GET', '/api/projects/00000000-0000-0000-0000-0000000000ff/social-network-templates', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);

        $this->assertResponseStatusCodeSame(404);
    }

    public function testEmbedsVariantsAndInputs(): void
    {
        $client = self::createClient();
        $token = TestingApiAuthentication::getAccessToken(
            $client,
            TestDataFixture::OAUTH2_CLIENT_ID,
            TestDataFixture::OAUTH2_CLIENT_SECRET,
        );

        $response = $client->request('GET', '/api/projects/' . TestDataFixture::PROJECT_1_ID . '/social-network-templates', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);

        $this->assertResponseIsSuccessful();
        $body = $response->toArray();

        $template = self::findTemplate($body, TestDataFixture::SOCIAL_NETWORK_TEMPLATE_1_ID);

        self::assertSame('Insta Template 1', $template['name'] ?? null);
        self::assertArrayHasKey('categoryId', $template);
        self::assertNull($template['categoryId']);
        self::assertArrayHasKey('categoryName', $template);
        self::assertNull($template['categoryName']);

        self::assertIsArray($template['variants'] ?? null);
        self::assertCount(1, $template['variants']);

        $variant = $template['variants'][0];
        self::assertIsArray($variant);
        self::assertSame(TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID, $variant['id'] ?? null);
        self::assertSame('1:1', $variant['dimension'] ?? null);
        self::assertSame(1080, $variant['width'] ?? null);
        self::assertSame(1080, $variant['height'] ?? null);

        self::assertIsString($variant['exportUrl'] ?? null);
        self::assertStringContainsString(
            '/api/social-network-template-variants/' . TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID . '/export',
            $variant['exportUrl'],
        );

        self::assertIsArray($variant['inputs'] ?? null);
        self::assertCount(4, $variant['inputs']);

        $headline = $variant['inputs'][0];
        self::assertIsArray($headline);
        self::assertSame(TestDataFixture::SOCIAL_NETWORK_VARIANT_1_INPUT_HEADLINE_ID, $headline['id'] ?? null);
        self::assertArrayNotHasKey('index', $headline, 'index field must be removed in favour of id.');
        self::assertSame('headline', $headline['name'] ?? null);
        self::assertSame(30, $headline['maxLength'] ?? null);
        self::assertFalse($headline['locked'] ?? null);
        self::assertFalse($headline['uppercase'] ?? null);
        self::assertFalse($headline['hidable'] ?? null);

        $tagline = $variant['inputs'][1];
        self::assertIsArray($tagline);
        self::assertSame(TestDataFixture::SOCIAL_NETWORK_VARIANT_1_INPUT_TAGLINE_ID, $tagline['id'] ?? null);
        self::assertSame('tagline', $tagline['name'] ?? null);
        self::assertArrayHasKey('maxLength', $tagline);
        self::assertNull($tagline['maxLength']);
        self::assertTrue($tagline['uppercase'] ?? null);
        self::assertFalse($tagline['hidable'] ?? null);

        $locked = $variant['inputs'][2];
        self::assertIsArray($locked);
        self::assertSame(TestDataFixture::SOCIAL_NETWORK_VARIANT_1_INPUT_LOCKED_ID, $locked['id'] ?? null);
        self::assertArrayHasKey('name', $locked);
        self::assertNull($locked['name']);
        self::assertTrue($locked['locked'] ?? null);

        $badge = $variant['inputs'][3];
        self::assertIsArray($badge);
        self::assertSame(TestDataFixture::SOCIAL_NETWORK_VARIANT_1_INPUT_BADGE_ID, $badge['id'] ?? null);
        self::assertSame('badge', $badge['name'] ?? null);
        self::assertTrue($badge['hidable'] ?? null);
    }

    public function testEmbedsImageInputs(): void
    {
        $client = self::createClient();
        $token = TestingApiAuthentication::getAccessToken(
            $client,
            TestDataFixture::OAUTH2_CLIENT_ID,
            TestDataFixture::OAUTH2_CLIENT_SECRET,
        );

        $response = $client->request('GET', '/api/projects/' . TestDataFixture::PROJECT_1_ID . '/social-network-templates', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);

        $this->assertResponseIsSuccessful();
        $template = self::findTemplate($response->toArray(), TestDataFixture::SOCIAL_NETWORK_TEMPLATE_1_ID);

        self::assertIsArray($template['variants'] ?? null);
        $variant = $template['variants'][0];
        self::assertIsArray($variant);
        self::assertIsArray($variant['imageInputs'] ?? null);
        self::assertCount(2, $variant['imageInputs']);

        $photo = $variant['imageInputs'][0];
        self::assertIsArray($photo);
        self::assertSame(TestDataFixture::SOCIAL_NETWORK_VARIANT_1_IMAGE_PHOTO_ID, $photo['id'] ?? null);
        self::assertSame('photo', $photo['name'] ?? null);
        self::assertSame('Your photo', $photo['description'] ?? null);
        self::assertTrue($photo['allowMove'] ?? null);
        self::assertTrue($photo['allowResize'] ?? null);
        self::assertTrue($photo['allowRotate'] ?? null);
        self::assertTrue($photo['hidable'] ?? null);
        self::assertSame([TestDataFixture::FILE_DIRECTORY_ALLOWED_ID], $photo['allowedDirectoryIds'] ?? null);

        // Frame derived from the placeholder object's displayed bbox.
        self::assertIsArray($photo['frame'] ?? null);
        self::assertEqualsWithDelta(100.0, $photo['frame']['x'] ?? null, 0.001);
        self::assertEqualsWithDelta(120.0, $photo['frame']['y'] ?? null, 0.001);
        self::assertEqualsWithDelta(400.0, $photo['frame']['width'] ?? null, 0.001);
        self::assertEqualsWithDelta(300.0, $photo['frame']['height'] ?? null, 0.001);

        self::assertIsString($photo['defaultImageUrl'] ?? null);
        self::assertStringContainsString('fixtures/standin-photo.png', $photo['defaultImageUrl']);

        // The locked slot exposes its restrictive flags.
        $locked = $variant['imageInputs'][1];
        self::assertIsArray($locked);
        self::assertSame(TestDataFixture::SOCIAL_NETWORK_VARIANT_1_IMAGE_LOCKED_ID, $locked['id'] ?? null);
        self::assertFalse($locked['allowMove'] ?? null);
        self::assertFalse($locked['allowResize'] ?? null);
        self::assertFalse($locked['allowRotate'] ?? null);
        self::assertFalse($locked['hidable'] ?? null);
    }

    /**
     * @param array<int|string, mixed> $rows
     * @return array<string, mixed>
     */
    private static function findTemplate(array $rows, string $id): array
    {
        foreach ($rows as $row) {
            self::assertIsArray($row);
            if (($row['id'] ?? null) === $id) {
                /** @var array<string, mixed> $row */
                return $row;
            }
        }

        self::fail('Template with id ' . $id . ' not found in response.');
    }
}
