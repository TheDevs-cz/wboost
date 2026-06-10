<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Api;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingApiAuthentication;

/**
 * @covers \WBoost\Web\Controller\Flyer\FlyerTemplateVariantPlaceholderUploadController
 */
final class FlyerPlaceholderUploadTest extends ApiTestCase
{
    private const string PNG_1X1_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkAAIAAAoAAv/lxKUAAAAASUVORK5CYII=';

    private function uploadUrl(string $variantId, string $inputId): string
    {
        return '/api/flyer-template-variants/' . $variantId . '/placeholders/' . $inputId . '/images';
    }

    public function testRequiresAuthentication(): void
    {
        $client = self::createClient();
        $client->request('POST', $this->uploadUrl(
            TestDataFixture::FLYER_TEMPLATE_VARIANT_1_ID,
            TestDataFixture::FLYER_VARIANT_1_IMAGE_PHOTO_ID,
        ));

        $this->assertResponseStatusCodeSame(401);
    }

    public function testUploadsIntoDefaultAllowedFolder(): void
    {
        $data = $this->upload(TestDataFixture::FLYER_VARIANT_1_IMAGE_PHOTO_ID, [], 200);

        self::assertIsString($data['id'] ?? null);
        self::assertIsString($data['url'] ?? null);
        // No directoryId requested → falls back to the slot's first allowed folder.
        self::assertSame(TestDataFixture::FILE_DIRECTORY_ALLOWED_ID, $data['directoryId'] ?? null);
    }

    public function testUploadsIntoExplicitAllowedFolder(): void
    {
        $data = $this->upload(
            TestDataFixture::FLYER_VARIANT_1_IMAGE_PHOTO_ID,
            ['directoryId' => TestDataFixture::FILE_DIRECTORY_ALLOWED_ID],
            200,
        );

        self::assertSame(TestDataFixture::FILE_DIRECTORY_ALLOWED_ID, $data['directoryId'] ?? null);
    }

    public function testRejectsFolderNotAllowedForSlot(): void
    {
        $this->upload(
            TestDataFixture::FLYER_VARIANT_1_IMAGE_PHOTO_ID,
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
            TestDataFixture::FLYER_TEMPLATE_VARIANT_1_ID,
            TestDataFixture::FLYER_VARIANT_1_IMAGE_PHOTO_ID,
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

        $response = $client->request('POST', $this->uploadUrl(TestDataFixture::FLYER_TEMPLATE_VARIANT_1_ID, $inputId), [
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
