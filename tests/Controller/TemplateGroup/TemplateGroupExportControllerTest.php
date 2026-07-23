<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\TemplateGroup;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use WBoost\Web\Services\Editor\TemplateVariantImageRendererInterface;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\Fakes\FakeTemplateVariantImageRenderer;
use WBoost\Web\Tests\TestingLogin;
use ZipArchive;

/**
 * @covers \WBoost\Web\Controller\TemplateGroup\TemplateGroupExportController
 * @covers \WBoost\Web\Controller\TemplateGroup\TemplateGroupFillPreviewController
 * @covers \WBoost\Web\Services\TemplateGroup\GroupFillRenderer
 */
final class TemplateGroupExportControllerTest extends WebTestCase
{
    private function exportUrl(): string
    {
        return '/template-group/' . TestDataFixture::TEMPLATE_GROUP_1_ID . '/export';
    }

    private function previewUrl(string $variantId): string
    {
        return '/template-group/' . TestDataFixture::TEMPLATE_GROUP_1_ID . '/fill-preview/' . $variantId;
    }

    public function testExportRendersEveryMemberVariantWithTheUnifiedValuesAndReturnsZip(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);

        $client->request('POST', $this->exportUrl(), [
            'textValues' => [
                TestDataFixture::GROUP_SHARED_INPUT_ID => 'Letní kampaň',
                'ffffffff-ffff-ffff-ffff-ffffffffffff' => 'unknown id is ignored',
            ],
        ]);

        self::assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertSame('application/zip', $response->headers->get('Content-Type'));
        self::assertSame('attachment; filename="group-campaign.zip"', $response->headers->get('Content-Disposition'));

        // One PNG per member variant, named by group + dimension.
        $entries = $this->readZipEntries((string) $response->getContent());
        self::assertSame(
            ['group-campaign-1-1-1080x1080.png', 'group-campaign-210-297-mm.png'],
            array_keys($entries),
        );

        foreach ($entries as $bytes) {
            self::assertStringStartsWith("\x89PNG", $bytes, 'every ZIP entry is a PNG');
        }

        // The unified value fanned out to BOTH variants, joined by inputId.
        $calls = $this->getRendererFake()->calls;
        self::assertCount(2, $calls);
        self::assertSame(TestDataFixture::GROUPED_SOCIAL_VARIANT_ID, $calls[0]['variantId']);
        self::assertSame(TestDataFixture::GROUPED_CUSTOM_VARIANT_ID, $calls[1]['variantId']);

        foreach ($calls as $call) {
            self::assertSame([TestDataFixture::GROUP_SHARED_INPUT_ID => 'Letní kampaň'], $call['texts']);
            self::assertFalse($call['strictContainerOverflow'], 'group export renders lenient, like the web download');
        }
    }

    public function testEmptyTextValueKeepsTheDesignedText(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);

        $client->request('POST', $this->exportUrl(), [
            'textValues' => [
                TestDataFixture::GROUP_SHARED_INPUT_ID => '',
            ],
        ]);

        self::assertResponseIsSuccessful();

        foreach ($this->getRendererFake()->calls as $call) {
            self::assertSame([], $call['texts'], 'an empty unified field must NOT blank the designed text');
        }
    }

    public function testSharedPlacementFansOutToEveryDimension(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);

        $client->request('POST', $this->exportUrl(), [
            'images' => [
                TestDataFixture::GROUP_SHARED_IMAGE_INPUT_ID => [
                    'imageId' => TestDataFixture::FILE_IN_ALLOWED_ID,
                    'scale' => '1.4',
                    'offsetXRatio' => '-0.12',
                    'offsetYRatio' => '0.05',
                ],
            ],
        ]);

        self::assertResponseIsSuccessful();

        $calls = $this->getRendererFake()->calls;
        self::assertCount(2, $calls);

        // The pan travels as a FRACTION of the frame, so the identical value is
        // correct in both dimensions — the renderer resolves it against each
        // variant's own frame.
        foreach ($calls as $call) {
            $placed = $call['images'][TestDataFixture::GROUP_SHARED_IMAGE_INPUT_ID] ?? null;
            self::assertIsArray($placed);
            self::assertSame(1.4, $placed['scale']);
            self::assertSame(-0.12, $placed['offsetXRatio']);
            self::assertSame(0.05, $placed['offsetYRatio']);
        }
    }

    public function testUnlinkedDimensionUsesItsOwnPlacement(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);

        $client->request('POST', $this->exportUrl(), [
            'images' => [
                TestDataFixture::GROUP_SHARED_IMAGE_INPUT_ID => [
                    'imageId' => TestDataFixture::FILE_IN_ALLOWED_ID,
                    'scale' => '1.4',
                    'offsetXRatio' => '-0.12',
                ],
            ],
            'imagePlacements' => [
                // Only the custom-template dimension was unlinked.
                TestDataFixture::GROUPED_CUSTOM_VARIANT_ID => [
                    TestDataFixture::GROUP_SHARED_IMAGE_INPUT_ID => [
                        'scale' => '2',
                        'offsetXRatio' => '0.3',
                    ],
                ],
                // An empty entry is what a dimension that still follows the
                // shared placement posts — it must NOT count as an override.
                TestDataFixture::GROUPED_SOCIAL_VARIANT_ID => [
                    TestDataFixture::GROUP_SHARED_IMAGE_INPUT_ID => [
                        'scale' => '',
                        'offsetXRatio' => '',
                    ],
                ],
            ],
        ]);

        self::assertResponseIsSuccessful();

        $byVariant = [];
        foreach ($this->getRendererFake()->calls as $call) {
            $byVariant[$call['variantId']] = $call['images'][TestDataFixture::GROUP_SHARED_IMAGE_INPUT_ID] ?? null;
        }

        $shared = $byVariant[TestDataFixture::GROUPED_SOCIAL_VARIANT_ID];
        self::assertIsArray($shared);
        self::assertSame(1.4, $shared['scale']);
        self::assertSame(-0.12, $shared['offsetXRatio']);

        $own = $byVariant[TestDataFixture::GROUPED_CUSTOM_VARIANT_ID];
        self::assertIsArray($own);
        self::assertSame(2.0, $own['scale']);
        self::assertSame(0.3, $own['offsetXRatio']);
    }

    public function testPlacementWithoutAPictureIsDropped(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);

        // An untouched form legitimately posts neutral placement fields with no
        // imageId; carrying them through would 400 the whole export.
        $client->request('POST', $this->exportUrl(), [
            'images' => [
                TestDataFixture::GROUP_SHARED_IMAGE_INPUT_ID => [
                    'imageId' => '',
                    'scale' => '1',
                    'offsetXRatio' => '0',
                    'offsetYRatio' => '0',
                ],
            ],
        ]);

        self::assertResponseIsSuccessful();

        foreach ($this->getRendererFake()->calls as $call) {
            self::assertSame([], $call['images'], 'no picture chosen → the designed stand-in renders');
        }
    }

    public function testExportIsForbiddenForNonDesigner(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $client->request('POST', $this->exportUrl());

        self::assertResponseStatusCodeSame(403);
    }

    public function testPreviewReturnsPngForMemberVariant(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);

        $client->request('POST', $this->previewUrl(TestDataFixture::GROUPED_SOCIAL_VARIANT_ID), [
            'textValues' => [
                TestDataFixture::GROUP_SHARED_INPUT_ID => 'Náhled',
            ],
        ]);

        self::assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertSame('image/png', $response->headers->get('Content-Type'));
        self::assertStringStartsWith("\x89PNG", (string) $response->getContent());

        $calls = $this->getRendererFake()->calls;
        self::assertCount(1, $calls);
        self::assertSame(TestDataFixture::GROUPED_SOCIAL_VARIANT_ID, $calls[0]['variantId']);
        self::assertSame([TestDataFixture::GROUP_SHARED_INPUT_ID => 'Náhled'], $calls[0]['texts']);
    }

    public function testPreviewRejectsVariantOutsideTheGroup(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);

        // Lives on the grouped template but carries no group FK — exactly the
        // membership rule the group editor save enforces.
        $client->request('POST', $this->previewUrl(TestDataFixture::UNGROUPED_VARIANT_ON_GROUPED_TEMPLATE_ID));

        self::assertResponseStatusCodeSame(404);
        self::assertSame([], $this->getRendererFake()->calls, 'nothing may be rendered for a non-member variant');
    }

    /**
     * @return array<string, string> entry name → bytes, in archive order
     */
    private function readZipEntries(string $zipBytes): array
    {
        $path = tempnam(sys_get_temp_dir(), 'zip-test-');
        self::assertIsString($path);
        file_put_contents($path, $zipBytes);

        $zip = new ZipArchive();
        self::assertTrue($zip->open($path));

        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            self::assertIsString($name);
            $bytes = $zip->getFromIndex($i);
            self::assertIsString($bytes);
            $entries[$name] = $bytes;
        }

        $zip->close();
        unlink($path);

        return $entries;
    }

    private function getRendererFake(): FakeTemplateVariantImageRenderer
    {
        $renderer = self::getContainer()->get(TemplateVariantImageRendererInterface::class);
        /** @phpstan-ignore staticMethod.impossibleType */
        self::assertInstanceOf(FakeTemplateVariantImageRenderer::class, $renderer);

        return $renderer;
    }
}
