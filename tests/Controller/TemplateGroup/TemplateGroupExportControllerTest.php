<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\TemplateGroup;

use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Repository\TemplateVariantRepository;
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
    private const string PNG_1X1_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkAAIAAAoAAv/lxKUAAAAASUVORK5CYII=';

    private function exportUrl(): string
    {
        return '/template-group/' . TestDataFixture::TEMPLATE_GROUP_1_ID . '/export';
    }

    /**
     * The resolver inlines the chosen image, so its bytes must exist in the
     * store. Minio state is NOT rolled back between tests (only the DB is) —
     * without self-seeding these tests pass or fail depending on whether a
     * test class that writes this object happened to run first.
     */
    private function seedAllowedImage(): void
    {
        $bytes = base64_decode(self::PNG_1X1_BASE64, true);
        self::assertIsString($bytes);
        self::getContainer()->get('oneup_flysystem.minio_filesystem')->write('fixtures/in-allowed.png', $bytes);
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
            ['group-campaign-1-1.png', 'group-campaign-210-297-mm.png'],
            array_keys($entries),
        );

        foreach ($entries as $bytes) {
            self::assertStringStartsWith("\x89PNG", $bytes, 'every ZIP entry is a PNG');
        }

        // The unified value fanned out to BOTH variants, joined by inputId.
        $calls = $this->getRendererFake()->calls;
        self::assertCount(2, $calls);
        self::assertSame(TestDataFixture::GROUPED_PRESET_VARIANT_ID, $calls[0]['variantId']);
        self::assertSame(TestDataFixture::GROUPED_FREEFORM_VARIANT_ID, $calls[1]['variantId']);

        foreach ($calls as $call) {
            self::assertSame([TestDataFixture::GROUP_SHARED_INPUT_ID => 'Letní kampaň'], $call['texts']);
            self::assertFalse($call['strictContainerOverflow'], 'group export renders lenient, like the web download');
            // Positive lock, not just "the bytes happen to be PNG": if someone
            // later flips the renderer default or passes WebP through here, the
            // user's downloaded ZIP would silently become lossy. Fail loudly.
            self::assertSame('png', $call['format'], 'the downloaded ZIP must stay lossless PNG');
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
        $this->seedAllowedImage();

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
        $this->seedAllowedImage();

        $client->request('POST', $this->exportUrl(), [
            'images' => [
                TestDataFixture::GROUP_SHARED_IMAGE_INPUT_ID => [
                    'imageId' => TestDataFixture::FILE_IN_ALLOWED_ID,
                    'scale' => '1.4',
                    'offsetXRatio' => '-0.12',
                ],
            ],
            'imagePlacements' => [
                // Only the free-form dimension was unlinked.
                TestDataFixture::GROUPED_FREEFORM_VARIANT_ID => [
                    TestDataFixture::GROUP_SHARED_IMAGE_INPUT_ID => [
                        'scale' => '2',
                        'offsetXRatio' => '0.3',
                    ],
                ],
                // An empty entry is what a dimension that still follows the
                // shared placement posts — it must NOT count as an override.
                TestDataFixture::GROUPED_PRESET_VARIANT_ID => [
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

        $shared = $byVariant[TestDataFixture::GROUPED_PRESET_VARIANT_ID];
        self::assertIsArray($shared);
        self::assertSame(1.4, $shared['scale']);
        self::assertSame(-0.12, $shared['offsetXRatio']);

        $own = $byVariant[TestDataFixture::GROUPED_FREEFORM_VARIANT_ID];
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

    /**
     * The export is a POST whose response is a download, so this URL only ends
     * up in the address bar when something went wrong. Answering a later
     * reload/revisit with 405 stranded the user (and filled Sentry); the fill
     * page is where an export starts.
     */
    public function testGetSendsTheVisitorBackToTheFillPage(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);

        $client->request('GET', $this->exportUrl());

        self::assertResponseRedirects('/template-group/' . TestDataFixture::TEMPLATE_GROUP_1_ID . '/fill');
        self::assertSame([], $this->getRendererFake()->calls, 'a GET must not render anything');
    }

    /**
     * Values the renderer refuses are the user's input, not a crash: the reason
     * has to be visible on a page they can go BACK from with the form intact,
     * instead of the generic "something went wrong" screen.
     */
    public function testUnrenderableFillShowsTheReasonInsteadOfTheGenericErrorPage(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);

        $crawler = $client->request('POST', $this->exportUrl(), [
            'images' => [
                // A row whose storage object cannot be read as a picture — the
                // very failure the HEIC upload produced in production.
                TestDataFixture::GROUP_SHARED_IMAGE_INPUT_ID => [
                    'imageId' => TestDataFixture::FILE_IN_OTHER_ID,
                ],
            ],
        ]);

        self::assertResponseStatusCodeSame(400);
        self::assertStringContainsString('Export se nezdařil', $crawler->filter('body')->text());
        self::assertStringContainsString('could not be read', $crawler->filter('body')->text());
        self::assertNotSame('application/zip', $client->getResponse()->headers->get('Content-Type'));
    }

    public function testExportIsForbiddenForUnrelatedUser(): void
    {
        // Export follows the fill page's project-VIEW gate — the owner and
        // shared users may export; a user with no relation to the project may not.
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_2_EMAIL);

        $client->request('POST', $this->exportUrl());

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * The on-screen preview takes the lossy/smaller encode; the ZIP export
     * stays lossless PNG — see
     * {@see testExportRendersEveryMemberVariantWithTheUnifiedValuesAndReturnsZip()},
     * which asserts the `.png` entry names, the PNG magic bytes AND that the
     * renderer was asked for PNG. Both halves of that split matter.
     */
    public function testPreviewReturnsWebpForMemberVariant(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);

        $client->request('POST', $this->previewUrl(TestDataFixture::GROUPED_PRESET_VARIANT_ID), [
            'textValues' => [
                TestDataFixture::GROUP_SHARED_INPUT_ID => 'Náhled',
            ],
        ]);

        self::assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertSame('image/webp', $response->headers->get('Content-Type'));

        // Assert the BYTES too, not just the header: a WebP file is
        // `RIFF` + 4 size bytes + `WEBP`. Checking both is what proves the
        // header and the payload actually agree.
        $content = (string) $response->getContent();
        self::assertStringStartsWith('RIFF', $content);
        self::assertSame('WEBP', substr($content, 8, 4));

        $calls = $this->getRendererFake()->calls;
        self::assertCount(1, $calls);
        self::assertSame(TestDataFixture::GROUPED_PRESET_VARIANT_ID, $calls[0]['variantId']);
        self::assertSame([TestDataFixture::GROUP_SHARED_INPUT_ID => 'Náhled'], $calls[0]['texts']);
        self::assertSame('webp', $calls[0]['format']);
    }

    /**
     * `?base=1` — the text-echo BASE: the same preview render with this
     * dimension's echo-capable texts transparent, keyed by whatever
     * EchoCapableTextInputs resolves for the fixture canvas. A normal preview
     * must never pass a transparent set (and exports never take this path at
     * all — covered by the ZIP test's calls carrying an empty set).
     */
    public function testPreviewBaseModeRendersEchoCapableTextsTransparent(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);

        $client->request('POST', $this->previewUrl(TestDataFixture::GROUPED_PRESET_VARIANT_ID) . '?base=1', [
            'textValues' => [
                TestDataFixture::GROUP_SHARED_INPUT_ID => 'Náhled',
            ],
        ]);

        self::assertResponseIsSuccessful();

        $capable = self::getContainer()
            ->get(\WBoost\Web\Services\TemplateGroup\GroupFillPlaceholders::class)
            ->echoCapableIds($this->loadVariant(TestDataFixture::GROUPED_PRESET_VARIANT_ID));

        $calls = $this->getRendererFake()->calls;
        self::assertCount(1, $calls);
        self::assertSame($capable, $calls[0]['transparentTextInputIds'], 'base mode blanks exactly the echo-capable set');

        // And without the flag: a normal, fully painted preview.
        $client->request('POST', $this->previewUrl(TestDataFixture::GROUPED_PRESET_VARIANT_ID), [
            'textValues' => [
                TestDataFixture::GROUP_SHARED_INPUT_ID => 'Náhled',
            ],
        ]);
        $calls = $this->getRendererFake()->calls;
        self::assertSame([], $calls[count($calls) - 1]['transparentTextInputIds']);
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

    private function loadVariant(string $id): TemplateVariant
    {
        return self::getContainer()->get(TemplateVariantRepository::class)->get(Uuid::fromString($id));
    }

    private function getRendererFake(): FakeTemplateVariantImageRenderer
    {
        $renderer = self::getContainer()->get(TemplateVariantImageRendererInterface::class);
        /** @phpstan-ignore staticMethod.impossibleType */
        self::assertInstanceOf(FakeTemplateVariantImageRenderer::class, $renderer);

        return $renderer;
    }
}
