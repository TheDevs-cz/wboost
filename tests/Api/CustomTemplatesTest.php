<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Api;

use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingApiAuthentication;

/**
 * @covers \WBoost\Web\Api\CustomTemplates\CustomTemplateResponse
 * @covers \WBoost\Web\Api\CustomTemplates\CustomTemplateVariantResponse
 * @covers \WBoost\Web\Api\CustomTemplates\CustomTemplateVariantInputResponse
 * @covers \WBoost\Web\Api\CustomTemplates\CustomTemplateVariantImageInputResponse
 * @covers \WBoost\Web\Api\CustomTemplates\CustomTemplateVariantImageInputFrameResponse
 * @covers \WBoost\Web\Api\CustomTemplates\CustomTemplatesProvider
 */
final class CustomTemplatesTest extends ApiTestCase
{
    public function testRequiresAuthentication(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/projects/' . TestDataFixture::PROJECT_1_ID . '/custom-templates');
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

        $response = $client->request('GET', '/api/projects/' . TestDataFixture::PROJECT_1_ID . '/custom-templates', [
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

        self::assertContains(TestDataFixture::CUSTOM_TEMPLATE_1_ID, $ids);
        self::assertNotContains(
            TestDataFixture::CUSTOM_TEMPLATE_2_ID,
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
        $client->request('GET', '/api/projects/' . TestDataFixture::PROJECT_2_ID . '/custom-templates', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);

        $this->assertResponseStatusCodeSame(404);
    }

    public function testEmbedsVariantsWithFreeFormDimension(): void
    {
        $client = self::createClient();
        $token = TestingApiAuthentication::getAccessToken(
            $client,
            TestDataFixture::OAUTH2_CLIENT_ID,
            TestDataFixture::OAUTH2_CLIENT_SECRET,
        );

        $response = $client->request('GET', '/api/projects/' . TestDataFixture::PROJECT_1_ID . '/custom-templates', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);

        $this->assertResponseIsSuccessful();
        $body = $response->toArray();

        $template = self::findTemplate($body, TestDataFixture::CUSTOM_TEMPLATE_1_ID);

        self::assertSame('Custom Template 1', $template['name'] ?? null);
        self::assertIsArray($template['variants'] ?? null);
        self::assertCount(1, $template['variants']);

        $variant = $template['variants'][0];
        self::assertIsArray($variant);
        self::assertSame(TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID, $variant['id'] ?? null);

        // A4 portrait in mm, rasterized at 300 DPI.
        self::assertSame('mm', $variant['unit'] ?? null);
        self::assertEqualsWithDelta(210.0, $variant['unitWidth'] ?? null, 0.001);
        self::assertEqualsWithDelta(297.0, $variant['unitHeight'] ?? null, 0.001);
        self::assertSame(2480, $variant['width'] ?? null);
        self::assertSame(3508, $variant['height'] ?? null);
        self::assertSame('210 × 297 mm', $variant['dimension'] ?? null);

        self::assertIsString($variant['exportUrl'] ?? null);
        self::assertStringContainsString(
            '/api/custom-template-variants/' . TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID . '/export',
            $variant['exportUrl'],
        );
        self::assertIsString($variant['thumbnailUrl'] ?? null);
        self::assertStringContainsString(
            '/api/custom-template-variants/' . TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID . '/thumbnail',
            $variant['thumbnailUrl'],
        );

        self::assertIsArray($variant['inputs'] ?? null);
        self::assertCount(4, $variant['inputs']);

        $headline = $variant['inputs'][0];
        self::assertIsArray($headline);
        self::assertSame(TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_INPUT_HEADLINE_ID, $headline['id'] ?? null);
        self::assertSame('headline', $headline['name'] ?? null);
        self::assertSame(30, $headline['maxLength'] ?? null);
        self::assertFalse($headline['locked'] ?? null);

        // Text frame from the i-th-Textbox positional binding (headline = 1st).
        self::assertIsArray($headline['frame'] ?? null);
        self::assertEqualsWithDelta(80.0, $headline['frame']['x'] ?? null, 0.001);
        self::assertEqualsWithDelta(60.0, $headline['frame']['y'] ?? null, 0.001);
        self::assertEqualsWithDelta(520.0, $headline['frame']['width'] ?? null, 0.001);
        self::assertEqualsWithDelta(90.0, $headline['frame']['height'] ?? null, 0.001);

        // Stacking order: one index space shared with imageInputs[].layerIndex —
        // the fixture canvas paints the two image placeholders first (0, 1),
        // then the four textboxes (2..5).
        self::assertSame(2, $headline['layerIndex'] ?? null);

        self::assertIsArray($variant['imageInputs'] ?? null);
        self::assertCount(2, $variant['imageInputs']);

        $photo = $variant['imageInputs'][0];
        self::assertIsArray($photo);
        self::assertSame(TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_IMAGE_PHOTO_ID, $photo['id'] ?? null);
        self::assertSame(0, $photo['layerIndex'] ?? null);
        self::assertTrue($photo['allowMove'] ?? null);
        self::assertSame([TestDataFixture::FILE_DIRECTORY_ALLOWED_ID], $photo['allowedDirectoryIds'] ?? null);
        self::assertSame(
            [['id' => TestDataFixture::FILE_DIRECTORY_ALLOWED_ID, 'name' => 'Photos']],
            $photo['directories'] ?? null,
        );
        self::assertFalse($photo['includesRoot'] ?? null);
        self::assertIsArray($photo['frame'] ?? null);
        self::assertEqualsWithDelta(100.0, $photo['frame']['x'] ?? null, 0.001);

        // Rich text: headline is WYSIWYG-enabled; the variant exposes the
        // options (whitelist falls back to ALL project fonts — the custom
        // canvas references no project font family — plus brand swatches).
        self::assertTrue($headline['richText'] ?? null);
        $options = $variant['richTextOptions'] ?? null;
        self::assertIsArray($options);
        $fonts = $options['fonts'] ?? null;
        self::assertIsArray($fonts);
        $families = [];
        foreach ($fonts as $font) {
            self::assertIsArray($font);
            $families[] = $font['family'] ?? null;
        }
        self::assertSame(['Rubik (Rubik Regular)', 'Rubik (Rubik Bold)'], $families);
        self::assertSame(['#c8102e', '#004e7c'], $options['colors'] ?? null);
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
