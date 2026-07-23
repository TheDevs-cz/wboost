<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\TemplateGroup;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use WBoost\Web\Entity\SocialNetworkTemplateVariant;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;
use WBoost\Web\Value\EditorImageInput;

/**
 * @covers \WBoost\Web\Controller\TemplateGroup\TemplateGroupPlaceholderUploadController
 */
final class TemplateGroupPlaceholderUploadControllerTest extends WebTestCase
{
    private const string PNG_1X1_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkAAIAAAoAAv/lxKUAAAAASUVORK5CYII=';

    private function uploadUrl(string $inputId): string
    {
        return '/template-group/' . TestDataFixture::TEMPLATE_GROUP_1_ID . '/placeholders/' . $inputId . '/upload';
    }

    public function testUnrestrictedSlotUploadsIntoGalleryRoot(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);

        $this->postFile($client, TestDataFixture::GROUP_SHARED_IMAGE_INPUT_ID);

        self::assertResponseIsSuccessful();

        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertIsString($data['id'] ?? null);
        self::assertIsString($data['url'] ?? null);
        // Empty allow-list = whole gallery open; with no folder chosen the file
        // lands in the root, never in an arbitrary folder.
        self::assertArrayHasKey('directoryId', $data);
        self::assertNull($data['directoryId']);
    }

    public function testUploadsIntoAnExplicitlyChosenFolder(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);

        $this->postFile($client, TestDataFixture::GROUP_SHARED_IMAGE_INPUT_ID, [
            'directoryId' => TestDataFixture::FILE_DIRECTORY_OTHER_ID,
        ]);

        self::assertResponseIsSuccessful();

        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertSame(TestDataFixture::FILE_DIRECTORY_OTHER_ID, $data['directoryId'] ?? null);
    }

    public function testRejectsFolderNotAllowedForTheSlot(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);

        $this->restrictSharedSlotTo([TestDataFixture::FILE_DIRECTORY_ALLOWED_ID]);

        $this->postFile($client, TestDataFixture::GROUP_SHARED_IMAGE_INPUT_ID, [
            'directoryId' => TestDataFixture::FILE_DIRECTORY_OTHER_ID,
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testRejectsMissingFile(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);

        $client->request('POST', $this->uploadUrl(TestDataFixture::GROUP_SHARED_IMAGE_INPUT_ID));

        self::assertResponseStatusCodeSame(400);
    }

    public function testUnknownPlaceholderIsNotFound(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);

        $this->postFile($client, '00000000-0000-0000-0000-0000000000ff');

        self::assertResponseStatusCodeSame(404);
    }

    public function testIsForbiddenForNonDesigner(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $this->postFile($client, TestDataFixture::GROUP_SHARED_IMAGE_INPUT_ID);

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @param array<string, string> $parameters
     */
    private function postFile(KernelBrowser $client, string $inputId, array $parameters = []): void
    {
        $client->request('POST', $this->uploadUrl($inputId), $parameters, ['file' => $this->pngUpload()]);
    }

    /**
     * @param list<string> $allowedDirectoryIds
     */
    private function restrictSharedSlotTo(array $allowedDirectoryIds): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $variant = $entityManager->find(SocialNetworkTemplateVariant::class, TestDataFixture::GROUPED_SOCIAL_VARIANT_ID);
        self::assertInstanceOf(SocialNetworkTemplateVariant::class, $variant);

        $variant->imageInputs = [
            new EditorImageInput(TestDataFixture::GROUP_SHARED_IMAGE_INPUT_ID, 'photo', null, true, true, false, true, $allowedDirectoryIds),
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
