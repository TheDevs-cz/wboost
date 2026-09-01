<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\TemplateGroup;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use WBoost\Web\Entity\TemplateExportVersion;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;

/**
 * Export versioning on the GROUP surface: the ZIP export and the
 * per-dimension download snapshot the same group fill form onto ONE
 * group-scoped version (deduplicating against each other), and
 * `?version=<id>` seeds the server-rendered fill form back — texts, hide
 * flags, the picked picture and the per-dimension placements.
 *
 * @covers \WBoost\Web\Controller\TemplateGroup\TemplateGroupExportController
 * @covers \WBoost\Web\Controller\TemplateGroup\TemplateGroupExportVariantController
 * @covers \WBoost\Web\Controller\TemplateGroup\TemplateGroupFillController
 * @covers \WBoost\Web\Services\Template\ExportVersionSeeder
 */
final class TemplateGroupExportVersionTest extends WebTestCase
{
    private const string PNG_1X1_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkAAIAAAoAAv/lxKUAAAAASUVORK5CYII=';

    public function testZipAndPerDimensionExportShareOneGroupVersionAndTheFillPageSeedsFromIt(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);
        $this->seedAllowedImage();

        $payload = [
            'textValues' => [TestDataFixture::GROUP_SHARED_INPUT_ID => 'Letní kampaň'],
            'images' => [TestDataFixture::GROUP_SHARED_IMAGE_INPUT_ID => ['imageId' => TestDataFixture::FILE_IN_ALLOWED_ID]],
            'imagePlacements' => [
                TestDataFixture::GROUPED_PRESET_VARIANT_ID => [
                    TestDataFixture::GROUP_SHARED_IMAGE_INPUT_ID => ['scale' => '1.5', 'offsetXRatio' => '0.25'],
                ],
            ],
        ];

        $client->request('POST', '/template-group/' . TestDataFixture::TEMPLATE_GROUP_1_ID . '/export', $payload);
        self::assertResponseIsSuccessful();

        $version = $this->soleGroupVersion();
        self::assertNull($version->variant);
        self::assertSame('Letní kampaň', $version->fillValues->toArray()['texts'][TestDataFixture::GROUP_SHARED_INPUT_ID]);
        self::assertSame(
            ['imageId' => TestDataFixture::FILE_IN_ALLOWED_ID],
            $version->fillValues->toArray()['images'][TestDataFixture::GROUP_SHARED_IMAGE_INPUT_ID],
        );
        self::assertSame(
            ['offsetXRatio' => 0.25, 'scale' => 1.5],
            $version->fillValues->toArray()['placements'][TestDataFixture::GROUPED_PRESET_VARIANT_ID][TestDataFixture::GROUP_SHARED_IMAGE_INPUT_ID],
        );

        // The per-dimension download posts the SAME form → same group version.
        $client->request(
            'POST',
            '/template-group/' . TestDataFixture::TEMPLATE_GROUP_1_ID . '/export/' . TestDataFixture::GROUPED_PRESET_VARIANT_ID,
            $payload,
        );
        self::assertResponseIsSuccessful();

        $bumped = $this->soleGroupVersion();
        self::assertSame($version->id->toString(), $bumped->id->toString());
        self::assertSame(2, $bumped->exportCount);

        // The fill page seeds the form back from the version.
        $crawler = $client->request(
            'GET',
            '/template-group/' . TestDataFixture::TEMPLATE_GROUP_1_ID . '/fill?version=' . $version->id->toString(),
        );
        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('Formulář je předvyplněný', $content);
        self::assertSame(
            'Letní kampaň',
            $crawler->filter(sprintf('input[name="textValues[%s]"]', TestDataFixture::GROUP_SHARED_INPUT_ID))->attr('value'),
        );
        self::assertSame(
            TestDataFixture::FILE_IN_ALLOWED_ID,
            $crawler->filter(sprintf('input[name="images[%s][imageId]"]', TestDataFixture::GROUP_SHARED_IMAGE_INPUT_ID))->attr('value'),
        );
        // The JS seed payload carries the picture + the per-dimension placement.
        $seedJson = $crawler->filter('form[data-controller="group-fill"]')->attr('data-group-fill-seed-value');
        self::assertIsString($seedJson);
        /** @var array{pictures: array<string, array{imageId: string, url: string}>, placements: array<string, array<string, array<string, float>>>} $seed */
        $seed = json_decode($seedJson, true);
        self::assertSame(TestDataFixture::FILE_IN_ALLOWED_ID, $seed['pictures'][TestDataFixture::GROUP_SHARED_IMAGE_INPUT_ID]['imageId']);
        self::assertSame(1.5, $seed['placements'][TestDataFixture::GROUPED_PRESET_VARIANT_ID][TestDataFixture::GROUP_SHARED_IMAGE_INPUT_ID]['scale']);

        // Without the param the form is pristine.
        $crawler = $client->request('GET', '/template-group/' . TestDataFixture::TEMPLATE_GROUP_1_ID . '/fill');
        self::assertResponseIsSuccessful();
        self::assertSame(
            '',
            $crawler->filter(sprintf('input[name="textValues[%s]"]', TestDataFixture::GROUP_SHARED_INPUT_ID))->attr('value'),
        );
    }

    /**
     * The resolver inlines the chosen image, so its bytes must exist in the
     * store (Minio state is not rolled back between tests — self-seed).
     */
    private function seedAllowedImage(): void
    {
        $bytes = base64_decode(self::PNG_1X1_BASE64, true);
        self::assertIsString($bytes);
        self::getContainer()->get('oneup_flysystem.minio_filesystem')->write('fixtures/in-allowed.png', $bytes);
    }

    private function soleGroupVersion(): TemplateExportVersion
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        /** @var list<TemplateExportVersion> $versions */
        $versions = $entityManager->getRepository(TemplateExportVersion::class)->findBy(['group' => TestDataFixture::TEMPLATE_GROUP_1_ID]);

        self::assertCount(1, $versions);

        return $versions[0];
    }
}
