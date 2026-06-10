<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Api;

use Doctrine\ORM\EntityManagerInterface;
use WBoost\Web\Entity\SocialNetworkTemplateVariant;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingApiAuthentication;
use WBoost\Web\Value\EditorImageInput;

/**
 * @covers \WBoost\Web\Api\SocialNetworkTemplates\PlaceholderGalleryProvider
 * @covers \WBoost\Web\Api\SocialNetworkTemplates\PlaceholderGalleryImageResponse
 */
final class SocialNetworkPlaceholderGalleryTest extends ApiTestCase
{
    private function galleryUrl(string $variantId, string $inputId): string
    {
        return '/api/social-network-template-variants/' . $variantId . '/placeholders/' . $inputId . '/images';
    }

    public function testRequiresAuthentication(): void
    {
        $client = self::createClient();
        $client->request('GET', $this->galleryUrl(
            TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID,
            TestDataFixture::SOCIAL_NETWORK_VARIANT_1_IMAGE_PHOTO_ID,
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
            TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID,
            TestDataFixture::SOCIAL_NETWORK_VARIANT_1_IMAGE_PHOTO_ID,
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
        self::assertNotContains(
            TestDataFixture::FILE_IN_ROOT_ID,
            $ids,
            'A slot with an explicit allow-list must not reach gallery-root images.',
        );
        self::assertContains('Photos', $directoryNames);
    }

    public function testUnrestrictedSlotListsTheWholeGalleryIncludingRoot(): void
    {
        $client = self::createClient();
        $token = TestingApiAuthentication::getAccessToken(
            $client,
            TestDataFixture::OAUTH2_CLIENT_ID,
            TestDataFixture::OAUTH2_CLIENT_SECRET,
        );

        $this->makePhotoSlotUnrestricted();

        $response = $client->request('GET', $this->galleryUrl(
            TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID,
            TestDataFixture::SOCIAL_NETWORK_VARIANT_1_IMAGE_PHOTO_ID,
        ), ['headers' => ['Authorization' => 'Bearer ' . $token]]);

        $this->assertResponseIsSuccessful();

        $rows = [];
        foreach ($response->toArray() as $row) {
            self::assertIsArray($row);
            self::assertIsString($row['id'] ?? null);
            $rows[$row['id']] = $row;
        }

        self::assertArrayHasKey(TestDataFixture::FILE_IN_ALLOWED_ID, $rows);
        self::assertArrayHasKey(TestDataFixture::FILE_IN_OTHER_ID, $rows);
        self::assertArrayHasKey(TestDataFixture::FILE_IN_ROOT_ID, $rows);

        // Root files carry a null directoryId/directoryName (the serializer
        // omits null fields, so the keys may be absent entirely).
        self::assertNull($rows[TestDataFixture::FILE_IN_ROOT_ID]['directoryId'] ?? null);
        self::assertNull($rows[TestDataFixture::FILE_IN_ROOT_ID]['directoryName'] ?? null);
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
            TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_2_ID,
            TestDataFixture::SOCIAL_NETWORK_VARIANT_1_IMAGE_PHOTO_ID,
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
            TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID,
            '99999999-9999-4999-8999-999999999999',
        ), ['headers' => ['Authorization' => 'Bearer ' . $token]]);

        $this->assertResponseStatusCodeSame(404);
    }

    /**
     * Rewrite the fixture photo slot with an EMPTY allow-list (= unrestricted).
     * DAMA keeps the flush inside the test transaction.
     */
    private function makePhotoSlotUnrestricted(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $variant = $entityManager->find(
            SocialNetworkTemplateVariant::class,
            TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID,
        );
        self::assertInstanceOf(SocialNetworkTemplateVariant::class, $variant);

        $variant->imageInputs = [
            new EditorImageInput(TestDataFixture::SOCIAL_NETWORK_VARIANT_1_IMAGE_PHOTO_ID, 'photo', 'Your photo', true, true, true, true, []),
            new EditorImageInput(TestDataFixture::SOCIAL_NETWORK_VARIANT_1_IMAGE_LOCKED_ID, 'logo', null, false, false, false, false, [TestDataFixture::FILE_DIRECTORY_ALLOWED_ID]),
        ];
        $entityManager->flush();
    }
}
