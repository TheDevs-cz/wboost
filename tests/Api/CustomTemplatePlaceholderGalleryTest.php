<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Api;

use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingApiAuthentication;

/**
 * @covers \WBoost\Web\Api\CustomTemplates\PlaceholderGalleryProvider
 * @covers \WBoost\Web\Api\CustomTemplates\PlaceholderGalleryImageResponse
 */
final class CustomTemplatePlaceholderGalleryTest extends ApiTestCase
{
    private function galleryUrl(string $variantId, string $inputId): string
    {
        return '/api/custom-template-variants/' . $variantId . '/placeholders/' . $inputId . '/images';
    }

    public function testRequiresAuthentication(): void
    {
        $client = self::createClient();
        $client->request('GET', $this->galleryUrl(
            TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID,
            TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_IMAGE_PHOTO_ID,
        ));

        $this->assertResponseStatusCodeSame(401);
    }

    public function testListsOnlyImagesFromAllowedFolders(): void
    {
        $client = self::createClient();
        $token = TestingApiAuthentication::getAccessToken(
            $client,
            TestDataFixture::OAUTH2_CLIENT_ID,
            TestDataFixture::OAUTH2_CLIENT_SECRET,
        );

        $response = $client->request('GET', $this->galleryUrl(
            TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID,
            TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_IMAGE_PHOTO_ID,
        ), ['headers' => ['Authorization' => 'Bearer ' . $token]]);

        $this->assertResponseIsSuccessful();

        $ids = [];
        $directoryNames = [];
        foreach ($response->toArray() as $row) {
            self::assertIsArray($row);
            self::assertIsString($row['id'] ?? null);
            self::assertIsString($row['url'] ?? null);
            $ids[] = $row['id'];
            $directoryNames[] = $row['directoryName'] ?? null;
        }

        self::assertContains(TestDataFixture::FILE_IN_ALLOWED_ID, $ids);
        self::assertNotContains(
            TestDataFixture::FILE_IN_OTHER_ID,
            $ids,
            'Images from a folder the slot does not allow must not appear.',
        );
        self::assertContains('Photos', $directoryNames);
    }

    public function testForbidsVariantInOtherUsersProject(): void
    {
        $client = self::createClient();
        $token = TestingApiAuthentication::getAccessToken(
            $client,
            TestDataFixture::OAUTH2_CLIENT_ID,
            TestDataFixture::OAUTH2_CLIENT_SECRET,
        );

        $client->request('GET', $this->galleryUrl(
            TestDataFixture::CUSTOM_TEMPLATE_VARIANT_2_ID,
            TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_IMAGE_PHOTO_ID,
        ), ['headers' => ['Authorization' => 'Bearer ' . $token]]);

        $this->assertResponseStatusCodeSame(403);
    }

    public function testNotFoundForUnknownPlaceholder(): void
    {
        $client = self::createClient();
        $token = TestingApiAuthentication::getAccessToken(
            $client,
            TestDataFixture::OAUTH2_CLIENT_ID,
            TestDataFixture::OAUTH2_CLIENT_SECRET,
        );

        $client->request('GET', $this->galleryUrl(
            TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID,
            '99999999-9999-4999-8999-999999999999',
        ), ['headers' => ['Authorization' => 'Bearer ' . $token]]);

        $this->assertResponseStatusCodeSame(404);
    }
}
