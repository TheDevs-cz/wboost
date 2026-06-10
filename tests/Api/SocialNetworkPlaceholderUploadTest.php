<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Api;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use WBoost\Web\Entity\SocialNetworkTemplateVariant;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingApiAuthentication;
use WBoost\Web\Value\EditorImageInput;

/**
 * @covers \WBoost\Web\Controller\SocialNetwork\SocialNetworkTemplateVariantPlaceholderUploadController
 */
final class SocialNetworkPlaceholderUploadTest extends ApiTestCase
{
    private const string PNG_1X1_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkAAIAAAoAAv/lxKUAAAAASUVORK5CYII=';

    private function uploadUrl(string $variantId, string $inputId): string
    {
        return '/api/social-network-template-variants/' . $variantId . '/placeholders/' . $inputId . '/images';
    }

    public function testRequiresAuthentication(): void
    {
        $client = self::createClient();
        $client->request('POST', $this->uploadUrl(
            TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID,
            TestDataFixture::SOCIAL_NETWORK_VARIANT_1_IMAGE_PHOTO_ID,
        ));

        $this->assertResponseStatusCodeSame(401);
    }

    public function testUploadsIntoSingleAllowedFolderWithoutChoice(): void
    {
        $data = $this->upload(TestDataFixture::SOCIAL_NETWORK_VARIANT_1_IMAGE_PHOTO_ID, [], 200);

        self::assertIsString($data['id'] ?? null);
        self::assertIsString($data['url'] ?? null);
        // No directoryId requested + exactly ONE allowed folder → unambiguous.
        self::assertSame(TestDataFixture::FILE_DIRECTORY_ALLOWED_ID, $data['directoryId'] ?? null);
    }

    public function testSeveralAllowedFoldersRequireAnExplicitChoice(): void
    {
        // The upload target is the uploader's choice — never an arbitrary
        // first-folder fallback.
        $this->setPhotoSlotAllowList([
            TestDataFixture::FILE_DIRECTORY_ALLOWED_ID,
            TestDataFixture::FILE_DIRECTORY_OTHER_ID,
        ]);

        $this->upload(TestDataFixture::SOCIAL_NETWORK_VARIANT_1_IMAGE_PHOTO_ID, [], 400);
    }

    public function testUnrestrictedSlotDefaultsToGalleryRoot(): void
    {
        // Empty allow-list = the whole gallery is open; with no choice made the
        // file lands in the gallery root, never in some arbitrary folder.
        $this->setPhotoSlotAllowList([]);

        $data = $this->upload(TestDataFixture::SOCIAL_NETWORK_VARIANT_1_IMAGE_PHOTO_ID, [], 200);

        self::assertIsString($data['id'] ?? null);
        self::assertArrayHasKey('directoryId', $data);
        self::assertNull($data['directoryId']);
    }

    public function testUnrestrictedSlotUploadsIntoAnyChosenFolder(): void
    {
        $this->setPhotoSlotAllowList([]);

        $data = $this->upload(
            TestDataFixture::SOCIAL_NETWORK_VARIANT_1_IMAGE_PHOTO_ID,
            ['directoryId' => TestDataFixture::FILE_DIRECTORY_OTHER_ID],
            200,
        );

        self::assertSame(TestDataFixture::FILE_DIRECTORY_OTHER_ID, $data['directoryId'] ?? null);
    }

    public function testUploadsIntoExplicitAllowedFolder(): void
    {
        $data = $this->upload(
            TestDataFixture::SOCIAL_NETWORK_VARIANT_1_IMAGE_PHOTO_ID,
            ['directoryId' => TestDataFixture::FILE_DIRECTORY_ALLOWED_ID],
            200,
        );

        self::assertSame(TestDataFixture::FILE_DIRECTORY_ALLOWED_ID, $data['directoryId'] ?? null);
    }

    public function testRejectsFolderNotAllowedForSlot(): void
    {
        $this->upload(
            TestDataFixture::SOCIAL_NETWORK_VARIANT_1_IMAGE_PHOTO_ID,
            ['directoryId' => TestDataFixture::FILE_DIRECTORY_OTHER_ID],
            403,
        );
    }

    public function testRejectsMissingFile(): void
    {
        $client = self::createClient();
        $token = TestingApiAuthentication::getAccessToken(
            $client,
            TestDataFixture::OAUTH2_CLIENT_ID,
            TestDataFixture::OAUTH2_CLIENT_SECRET,
        );

        $client->request('POST', $this->uploadUrl(
            TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID,
            TestDataFixture::SOCIAL_NETWORK_VARIANT_1_IMAGE_PHOTO_ID,
        ), [
            'headers' => ['Authorization' => 'Bearer ' . $token],
            'extra' => ['parameters' => []],
        ]);

        $this->assertResponseStatusCodeSame(400);
    }

    /**
     * @param array<string, string> $parameters
     * @return array<array-key, mixed>
     */
    private function upload(string $inputId, array $parameters, int $expectedStatus): array
    {
        $client = self::createClient();
        $token = TestingApiAuthentication::getAccessToken(
            $client,
            TestDataFixture::OAUTH2_CLIENT_ID,
            TestDataFixture::OAUTH2_CLIENT_SECRET,
        );

        $response = $client->request('POST', $this->uploadUrl(TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID, $inputId), [
            'headers' => ['Authorization' => 'Bearer ' . $token],
            'extra' => [
                'parameters' => $parameters,
                'files' => ['file' => $this->pngUpload()],
            ],
        ]);

        $this->assertResponseStatusCodeSame($expectedStatus);

        if ($expectedStatus !== 200) {
            return [];
        }

        return $response->toArray();
    }

    /**
     * Rewrite the fixture photo slot's folder allow-list. DAMA keeps the flush
     * inside the test transaction.
     *
     * @param list<string> $allowedDirectoryIds
     */
    private function setPhotoSlotAllowList(array $allowedDirectoryIds): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $variant = $entityManager->find(
            SocialNetworkTemplateVariant::class,
            TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID,
        );
        self::assertInstanceOf(SocialNetworkTemplateVariant::class, $variant);

        $variant->imageInputs = [
            new EditorImageInput(TestDataFixture::SOCIAL_NETWORK_VARIANT_1_IMAGE_PHOTO_ID, 'photo', 'Your photo', true, true, true, true, $allowedDirectoryIds),
            new EditorImageInput(TestDataFixture::SOCIAL_NETWORK_VARIANT_1_IMAGE_LOCKED_ID, 'logo', null, false, false, false, false, [TestDataFixture::FILE_DIRECTORY_ALLOWED_ID]),
        ];
        $entityManager->flush();
    }

    private function pngUpload(): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'png');
        self::assertIsString($tmp);

        $bytes = base64_decode(self::PNG_1X1_BASE64, true);
        self::assertIsString($bytes);
        file_put_contents($tmp, $bytes);

        // test mode (5th arg) bypasses is_uploaded_file().
        return new UploadedFile($tmp, 'photo.png', 'image/png', null, true);
    }
}
