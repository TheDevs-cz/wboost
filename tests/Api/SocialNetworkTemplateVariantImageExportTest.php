<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Api;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use WBoost\Web\Entity\SocialNetworkTemplateVariant;
use WBoost\Web\Services\Editor\TemplateVariantImageRendererInterface;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\Fakes\FakeTemplateVariantImageRenderer;
use WBoost\Web\Tests\TestingApiAuthentication;
use WBoost\Web\Value\EditorImageInput;

/**
 * Image-placeholder export coverage. Validation / scoping / constraint paths
 * need only DB rows (they fail before inlining); the happy paths write a real
 * 1×1 PNG to the test object store so the resolver can inline it + read its size.
 *
 * @covers \WBoost\Web\Api\SocialNetworkTemplates\ExportProcessor
 * @covers \WBoost\Web\Api\SocialNetworkTemplates\ExportRequest
 * @covers \WBoost\Web\Services\SocialNetwork\ResolveImageOverrides
 * @covers \WBoost\Web\Value\ResolvedImageOverrides
 * @covers \WBoost\Web\Value\ResolvedImageOverride
 */
final class SocialNetworkTemplateVariantImageExportTest extends ApiTestCase
{
    private const string PNG_MAGIC = "\x89PNG\r\n\x1a\n";
    private const string PNG_1X1_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkAAIAAAoAAv/lxKUAAAAASUVORK5CYII=';

    public function testUnfilledSlotsKeepStandInImage(): void
    {
        $this->export(['images' => []]);

        $this->assertResponseIsSuccessful();
        $call = $this->lastCall();
        self::assertSame([], $call['images']);
        self::assertSame([], $call['imagesHidden']);
    }

    public function testInvalidImageIdIsRejected(): void
    {
        $this->export(['images' => [TestDataFixture::SOCIAL_NETWORK_VARIANT_1_IMAGE_PHOTO_ID => 'not-a-uuid']]);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testUnknownImageIdIsRejected(): void
    {
        $this->export(['images' => [TestDataFixture::SOCIAL_NETWORK_VARIANT_1_IMAGE_PHOTO_ID => '99999999-9999-4999-8999-999999999999']]);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testImageInNonAllowedFolderIsRejected(): void
    {
        // FILE_IN_OTHER lives in OTHER, but the photo slot only allows ALLOWED.
        $this->export(['images' => [TestDataFixture::SOCIAL_NETWORK_VARIANT_1_IMAGE_PHOTO_ID => TestDataFixture::FILE_IN_OTHER_ID]]);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testRootImageIsRejectedForRestrictedSlot(): void
    {
        // FILE_IN_ROOT sits in no folder; a slot with an explicit allow-list
        // can never reach the gallery root.
        $this->export(['images' => [TestDataFixture::SOCIAL_NETWORK_VARIANT_1_IMAGE_PHOTO_ID => TestDataFixture::FILE_IN_ROOT_ID]]);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testRootImageIsAcceptedForUnrestrictedSlot(): void
    {
        $this->writeTestImage('fixtures/in-root.png');
        $this->makePhotoSlotUnrestricted();

        // An empty allow-list opens the whole gallery, root included.
        $this->export(['images' => [TestDataFixture::SOCIAL_NETWORK_VARIANT_1_IMAGE_PHOTO_ID => TestDataFixture::FILE_IN_ROOT_ID]]);

        $this->assertResponseIsSuccessful();
        $call = $this->lastCall();
        self::assertArrayHasKey(TestDataFixture::SOCIAL_NETWORK_VARIANT_1_IMAGE_PHOTO_ID, $call['images']);
    }

    public function testResizeOnLockedSlotIsRejected(): void
    {
        $this->export(['images' => [
            TestDataFixture::SOCIAL_NETWORK_VARIANT_1_IMAGE_LOCKED_ID => [
                'imageId' => TestDataFixture::FILE_IN_ALLOWED_ID,
                'scale' => 2.0,
            ],
        ]]);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testMoveOnLockedSlotIsRejected(): void
    {
        $this->export(['images' => [
            TestDataFixture::SOCIAL_NETWORK_VARIANT_1_IMAGE_LOCKED_ID => [
                'imageId' => TestDataFixture::FILE_IN_ALLOWED_ID,
                'offsetX' => 12.0,
            ],
        ]]);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testRotateOnLockedSlotIsRejected(): void
    {
        $this->export(['images' => [
            TestDataFixture::SOCIAL_NETWORK_VARIANT_1_IMAGE_LOCKED_ID => [
                'imageId' => TestDataFixture::FILE_IN_ALLOWED_ID,
                'rotation' => 30.0,
            ],
        ]]);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testHideOnHidableSlotBlanksIt(): void
    {
        $this->export(['images' => [TestDataFixture::SOCIAL_NETWORK_VARIANT_1_IMAGE_PHOTO_ID => ['hide' => true]]]);

        $this->assertResponseIsSuccessful();
        $call = $this->lastCall();
        self::assertContains(TestDataFixture::SOCIAL_NETWORK_VARIANT_1_IMAGE_PHOTO_ID, $call['imagesHidden']);
        self::assertSame([], $call['images']);
    }

    public function testHideOnNonHidableSlotIsIgnored(): void
    {
        $this->export(['images' => [TestDataFixture::SOCIAL_NETWORK_VARIANT_1_IMAGE_LOCKED_ID => ['hide' => true]]]);

        $this->assertResponseIsSuccessful();
        $call = $this->lastCall();
        self::assertSame([], $call['imagesHidden']);
        self::assertSame([], $call['images']);
    }

    public function testPlacesChosenImageWithTransform(): void
    {
        $this->writeTestImage('fixtures/in-allowed.png');

        $response = $this->export(['images' => [
            TestDataFixture::SOCIAL_NETWORK_VARIANT_1_IMAGE_PHOTO_ID => [
                'imageId' => TestDataFixture::FILE_IN_ALLOWED_ID,
                'scale' => 2.0,
                'offsetX' => 10.0,
                'offsetY' => -5.0,
                'rotation' => 15.0,
            ],
        ]]);

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'image/png');
        self::assertStringStartsWith(self::PNG_MAGIC, $response->getContent());

        $call = $this->lastCall();
        $placed = $call['images'][TestDataFixture::SOCIAL_NETWORK_VARIANT_1_IMAGE_PHOTO_ID] ?? null;
        self::assertIsArray($placed);
        self::assertSame(2.0, $placed['scale']);
        self::assertSame(10.0, $placed['offsetX']);
        self::assertSame(-5.0, $placed['offsetY']);
        self::assertSame(15.0, $placed['rotation']);
        self::assertSame(1, $placed['naturalWidth']);
        self::assertSame(1, $placed['naturalHeight']);
    }

    public function testAcceptsThePortableRatioPan(): void
    {
        $this->writeTestImage('fixtures/in-allowed.png');

        $this->export(['images' => [
            TestDataFixture::SOCIAL_NETWORK_VARIANT_1_IMAGE_PHOTO_ID => [
                'imageId' => TestDataFixture::FILE_IN_ALLOWED_ID,
                'scale' => 1.4,
                'offsetXRatio' => -0.12,
                'offsetYRatio' => 0.05,
            ],
        ]]);

        $this->assertResponseIsSuccessful();

        $placed = $this->lastCall()['images'][TestDataFixture::SOCIAL_NETWORK_VARIANT_1_IMAGE_PHOTO_ID] ?? null;
        self::assertIsArray($placed);
        self::assertSame(1.4, $placed['scale']);
        self::assertSame(-0.12, $placed['offsetXRatio']);
        self::assertSame(0.05, $placed['offsetYRatio']);
        // The px form stays neutral — the renderer resolves the ratio against
        // this variant's own frame.
        self::assertSame(0.0, $placed['offsetX']);
        self::assertSame(0.0, $placed['offsetY']);
    }

    public function testRatioPanOnLockedSlotIsRejected(): void
    {
        $this->export(['images' => [
            TestDataFixture::SOCIAL_NETWORK_VARIANT_1_IMAGE_LOCKED_ID => [
                'imageId' => TestDataFixture::FILE_IN_ALLOWED_ID,
                'offsetXRatio' => 0.2,
            ],
        ]]);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testBothPanFormsOnTheSameAxisAreRejected(): void
    {
        $this->export(['images' => [
            TestDataFixture::SOCIAL_NETWORK_VARIANT_1_IMAGE_PHOTO_ID => [
                'imageId' => TestDataFixture::FILE_IN_ALLOWED_ID,
                'offsetY' => 10.0,
                'offsetYRatio' => 0.2,
            ],
        ]]);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testShorthandImageIdDefaultsToCenteredContain(): void
    {
        $this->writeTestImage('fixtures/in-allowed.png');

        // Shorthand string == imageId only → default placement (scale 1, no pan/rotate).
        $this->export(['images' => [
            TestDataFixture::SOCIAL_NETWORK_VARIANT_1_IMAGE_PHOTO_ID => TestDataFixture::FILE_IN_ALLOWED_ID,
        ]]);

        $this->assertResponseIsSuccessful();
        $call = $this->lastCall();
        $placed = $call['images'][TestDataFixture::SOCIAL_NETWORK_VARIANT_1_IMAGE_PHOTO_ID] ?? null;
        self::assertIsArray($placed);
        self::assertSame(1.0, $placed['scale']);
        self::assertSame(0.0, $placed['offsetX']);
        self::assertSame(0.0, $placed['offsetY']);
        self::assertSame(0.0, $placed['rotation']);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function export(array $payload): ResponseInterface
    {
        $client = self::createClient();
        $token = TestingApiAuthentication::getAccessToken(
            $client,
            TestDataFixture::OAUTH2_CLIENT_ID,
            TestDataFixture::OAUTH2_CLIENT_SECRET,
        );

        return $client->request(
            'POST',
            '/api/social-network-template-variants/' . TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID . '/export',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode($payload, JSON_THROW_ON_ERROR),
            ],
        );
    }

    private function writeTestImage(string $path): void
    {
        $bytes = base64_decode(self::PNG_1X1_BASE64, true);
        self::assertIsString($bytes);

        self::getContainer()->get('oneup_flysystem.minio_filesystem')->write($path, $bytes);
    }

    /**
     * Rewrite the fixture variant's photo slot with an EMPTY allow-list
     * (= unrestricted). DAMA keeps the flush inside the test transaction.
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

    private function getRendererFake(): FakeTemplateVariantImageRenderer
    {
        $renderer = self::getContainer()->get(TemplateVariantImageRendererInterface::class);
        /** @phpstan-ignore staticMethod.impossibleType */
        self::assertInstanceOf(FakeTemplateVariantImageRenderer::class, $renderer);

        return $renderer;
    }

    /**
     * @return array{variantId: string, texts: array<string, string>, richTexts: array<string, list<array{text: string, fontFamily: null|string, color: null|string, underline: bool}>>, hidden: array<string, bool>, images: array<string, array{scale: float, offsetX: float, offsetY: float, offsetXRatio: null|float, offsetYRatio: null|float, rotation: float, naturalWidth: int, naturalHeight: int}>, imagesHidden: list<string>, mode: string, strictContainerOverflow: bool}
     */
    private function lastCall(): array
    {
        $fake = $this->getRendererFake();
        self::assertNotEmpty($fake->calls);

        return $fake->calls[count($fake->calls) - 1];
    }
}
