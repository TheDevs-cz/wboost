<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\Client;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use WBoost\Web\Entity\FileDirectory;
use WBoost\Web\Entity\FileUpload;
use WBoost\Web\Entity\Project;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Services\Editor\TemplateVariantImageRendererInterface;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\Fakes\FakeTemplateVariantImageRenderer;
use WBoost\Web\Tests\TestingApiAuthentication;
use WBoost\Web\Value\EditorImageInput;
use WBoost\Web\Value\FileSource;

/**
 * Allowing a gallery folder on an image placeholder allows its WHOLE sub-tree:
 * the fixture photo slots allow "Photos", and "Photos/Logos" (NESTED) must be
 * just as usable — for picking (gallery list), validation (export) and
 * uploading — while a child-only allow-list never opens the parent.
 *
 * @covers \WBoost\Web\Services\SocialNetwork\PlaceholderAllowedDirectories
 * @covers \WBoost\Web\Services\SocialNetwork\ResolveImageOverrides
 * @covers \WBoost\Web\Services\SocialNetwork\PlaceholderImageUploader
 * @covers \WBoost\Web\Api\Templates\PlaceholderGalleryProvider
 */
final class PlaceholderSubfolderAccessTest extends ApiTestCase
{
    private const string PNG_1X1_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkAAIAAAoAAv/lxKUAAAAASUVORK5CYII=';
    private const string FILE_IN_NESTED_ID = '00000000-0000-4000-8000-0000000000a1';
    private const string DEEPER_DIRECTORY_ID = '00000000-0000-4000-8000-0000000000a2';
    private const string FILE_IN_DEEPER_ID = '00000000-0000-4000-8000-0000000000a3';

    public function testExportAcceptsAPictureFromASubfolderOfTheAllowedFolder(): void
    {
        $this->addNestedPictures();
        $this->writeTestImage('fixtures/in-nested.png');

        $this->export([TestDataFixture::SOCIAL_NETWORK_VARIANT_1_IMAGE_PHOTO_ID => self::FILE_IN_NESTED_ID]);

        $this->assertResponseIsSuccessful();
        self::assertArrayHasKey(TestDataFixture::SOCIAL_NETWORK_VARIANT_1_IMAGE_PHOTO_ID, $this->rendererFake()->calls[count($this->rendererFake()->calls) - 1]['images']);
    }

    public function testExportAcceptsAPictureTwoLevelsDown(): void
    {
        $this->addNestedPictures();
        $this->writeTestImage('fixtures/in-deeper.png');

        $this->export([TestDataFixture::SOCIAL_NETWORK_VARIANT_1_IMAGE_PHOTO_ID => self::FILE_IN_DEEPER_ID]);

        $this->assertResponseIsSuccessful();
    }

    public function testAChildOnlyAllowListDoesNotOpenTheParent(): void
    {
        $this->setSocialPhotoSlotAllowList([TestDataFixture::FILE_DIRECTORY_NESTED_ID]);

        // FILE_IN_ALLOWED sits in "Photos", the PARENT of the allowed folder.
        $this->export([TestDataFixture::SOCIAL_NETWORK_VARIANT_1_IMAGE_PHOTO_ID => TestDataFixture::FILE_IN_ALLOWED_ID]);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testGalleryListsPicturesFromSubfolders(): void
    {
        $this->addNestedPictures();

        $client = self::createClient();
        $response = $client->request('GET', $this->placeholderUrl(), ['headers' => ['Authorization' => 'Bearer ' . $this->token($client)]]);

        $this->assertResponseIsSuccessful();
        $ids = array_map(static fn (mixed $row): mixed => is_array($row) ? ($row['id'] ?? null) : null, $response->toArray());
        self::assertContains(TestDataFixture::FILE_IN_ALLOWED_ID, $ids);
        self::assertContains(self::FILE_IN_NESTED_ID, $ids);
        self::assertContains(self::FILE_IN_DEEPER_ID, $ids);
        self::assertNotContains(TestDataFixture::FILE_IN_OTHER_ID, $ids);
    }

    public function testUploadsIntoASubfolderOfTheAllowedFolder(): void
    {
        $data = $this->upload(['directoryId' => TestDataFixture::FILE_DIRECTORY_NESTED_ID], 200);

        self::assertSame(TestDataFixture::FILE_DIRECTORY_NESTED_ID, $data['directoryId'] ?? null);
    }

    public function testUploadWithoutAChoiceStillLandsInTheNamedParent(): void
    {
        // "Photos" now opens two folders (itself + Logos), but the designer
        // named exactly one — that stays the unambiguous default target.
        $data = $this->upload([], 200);

        self::assertSame(TestDataFixture::FILE_DIRECTORY_ALLOWED_ID, $data['directoryId'] ?? null);
    }

    public function testTemplatesListingResolvesTheSubtreeIntoDirectories(): void
    {
        $client = self::createClient();
        $response = $client->request('GET', '/api/projects/' . TestDataFixture::PROJECT_1_ID . '/templates', ['headers' => ['Authorization' => 'Bearer ' . $this->token($client)]]);
        $this->assertResponseIsSuccessful();

        $photo = null;
        foreach ($response->toArray() as $template) {
            self::assertIsArray($template);
            foreach (is_array($template['variants'] ?? null) ? $template['variants'] : [] as $variant) {
                self::assertIsArray($variant);
                foreach (is_array($variant['imageInputs'] ?? null) ? $variant['imageInputs'] : [] as $input) {
                    self::assertIsArray($input);
                    if (($input['id'] ?? null) === TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_IMAGE_PHOTO_ID) {
                        $photo = $input;
                    }
                }
            }
        }

        self::assertIsArray($photo);
        // The raw designer list is untouched; the resolved list is the sub-tree, parent first.
        self::assertSame([TestDataFixture::FILE_DIRECTORY_ALLOWED_ID], $photo['allowedDirectoryIds'] ?? null);
        self::assertSame(
            [
                ['id' => TestDataFixture::FILE_DIRECTORY_ALLOWED_ID, 'name' => 'Photos'],
                ['id' => TestDataFixture::FILE_DIRECTORY_NESTED_ID, 'name' => 'Logos'],
            ],
            $photo['directories'] ?? null,
        );
    }

    /**
     * A picture in "Photos/Logos" and a third level "Photos/Logos/Deeper"
     * with one more. DAMA rolls them back with the test.
     */
    private function addNestedPictures(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $project = $entityManager->find(Project::class, TestDataFixture::PROJECT_1_ID);
        $nested = $entityManager->find(FileDirectory::class, TestDataFixture::FILE_DIRECTORY_NESTED_ID);
        self::assertInstanceOf(Project::class, $project);
        self::assertInstanceOf(FileDirectory::class, $nested);
        $date = new \DateTimeImmutable('2026-01-01 00:00:00');

        $deeper = new FileDirectory(Uuid::fromString(self::DEEPER_DIRECTORY_ID), $project, FileSource::ProjectImage, 'Deeper', $nested, $date);
        $entityManager->persist($deeper);
        $entityManager->persist(new FileUpload(Uuid::fromString(self::FILE_IN_NESTED_ID), $project, $date, FileSource::ProjectImage, 'fixtures/in-nested.png', $nested));
        $entityManager->persist(new FileUpload(Uuid::fromString(self::FILE_IN_DEEPER_ID), $project, $date, FileSource::ProjectImage, 'fixtures/in-deeper.png', $deeper));
        $entityManager->flush();
    }

    /**
     * @param list<string> $allowedDirectoryIds
     */
    private function setSocialPhotoSlotAllowList(array $allowedDirectoryIds): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $variant = $entityManager->find(TemplateVariant::class, TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID);
        self::assertInstanceOf(TemplateVariant::class, $variant);

        $variant->imageInputs = [
            new EditorImageInput(TestDataFixture::SOCIAL_NETWORK_VARIANT_1_IMAGE_PHOTO_ID, 'photo', 'Your photo', true, true, true, true, $allowedDirectoryIds),
            new EditorImageInput(TestDataFixture::SOCIAL_NETWORK_VARIANT_1_IMAGE_LOCKED_ID, 'logo', null, false, false, false, false, [TestDataFixture::FILE_DIRECTORY_ALLOWED_ID]),
        ];
        $entityManager->flush();
    }

    /**
     * @param array<string, string> $images
     */
    private function export(array $images): void
    {
        $client = self::createClient();
        $client->request('POST', '/api/template-variants/' . TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID . '/export', [
            'headers' => ['Authorization' => 'Bearer ' . $this->token($client), 'Content-Type' => 'application/json'],
            'body' => json_encode(['images' => $images], JSON_THROW_ON_ERROR),
        ]);
    }

    /**
     * @param array<string, string> $parameters
     * @return array<array-key, mixed>
     */
    private function upload(array $parameters, int $expectedStatus): array
    {
        $client = self::createClient();
        $response = $client->request('POST', $this->placeholderUrl(), [
            'headers' => ['Authorization' => 'Bearer ' . $this->token($client)],
            'extra' => ['parameters' => $parameters, 'files' => ['file' => $this->pngUpload()]],
        ]);

        $this->assertResponseStatusCodeSame($expectedStatus);

        return $response->toArray();
    }

    private function placeholderUrl(): string
    {
        return '/api/template-variants/' . TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID . '/placeholders/' . TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_IMAGE_PHOTO_ID . '/images';
    }

    private function token(Client $client): string
    {
        return TestingApiAuthentication::getAccessToken($client, TestDataFixture::OAUTH2_CLIENT_ID, TestDataFixture::OAUTH2_CLIENT_SECRET);
    }

    private function writeTestImage(string $path): void
    {
        $bytes = base64_decode(self::PNG_1X1_BASE64, true);
        self::assertIsString($bytes);
        self::getContainer()->get('oneup_flysystem.minio_filesystem')->write($path, $bytes);
    }

    private function pngUpload(): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'png');
        self::assertIsString($tmp);
        $bytes = base64_decode(self::PNG_1X1_BASE64, true);
        self::assertIsString($bytes);
        file_put_contents($tmp, $bytes);

        return new UploadedFile($tmp, 'photo.png', 'image/png', null, true);
    }

    private function rendererFake(): FakeTemplateVariantImageRenderer
    {
        $renderer = self::getContainer()->get(TemplateVariantImageRendererInterface::class);
        /** @phpstan-ignore staticMethod.impossibleType */
        self::assertInstanceOf(FakeTemplateVariantImageRenderer::class, $renderer);

        return $renderer;
    }
}
