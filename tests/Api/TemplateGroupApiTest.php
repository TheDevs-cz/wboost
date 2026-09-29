<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\Client;
use Doctrine\ORM\EntityManagerInterface;
use WBoost\Web\Entity\ExportEvent;
use WBoost\Web\Entity\TemplateExportVersion;
use WBoost\Web\Exceptions\ContainerOverflow;
use WBoost\Web\Services\Editor\TemplateVariantImageRendererInterface;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\Fakes\FakeTemplateVariantImageRenderer;
use WBoost\Web\Tests\TestingApiAuthentication;
use ZipArchive;

/**
 * Template groups over the API: listing flags, the per-dimension preview, the
 * ZIP + single-dimension export (strict, one group version), and the export
 * history endpoints (list / load / rename / pin).
 *
 * @covers \WBoost\Web\Api\TemplateGroups\GroupExportProcessor
 * @covers \WBoost\Web\Api\TemplateGroups\GroupPreviewProcessor
 * @covers \WBoost\Web\Api\TemplateGroups\TemplateGroupResource
 * @covers \WBoost\Web\Api\Templates\PreviewProcessor
 * @covers \WBoost\Web\Api\ExportVersions\ExportVersionsProvider
 * @covers \WBoost\Web\Api\ExportVersions\ExportVersionProvider
 * @covers \WBoost\Web\Api\ExportVersions\ExportVersionUpdateProcessor
 * @covers \WBoost\Web\Services\Template\ApiExportRenderer
 * @covers \WBoost\Web\Services\Template\ExportVersionApiView
 */
final class TemplateGroupApiTest extends ApiTestCase
{
    private const string PNG_1X1_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkAAIAAAoAAv/lxKUAAAAASUVORK5CYII=';

    public function testListingFlagsTheGroupAndItsMembers(): void
    {
        [$client, $token] = $this->authenticatedClient();

        $rows = $client->request('GET', '/api/projects/' . TestDataFixture::PROJECT_1_ID . '/templates', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ])->toArray();

        $grouped = null;
        foreach ($rows as $row) {
            self::assertIsArray($row);
            if (($row['id'] ?? null) === TestDataFixture::GROUPED_TEMPLATE_ID) {
                $grouped = $row;
            } else {
                self::assertArrayHasKey('group', $row);
            }
        }

        self::assertIsArray($grouped);
        self::assertSame(['id' => TestDataFixture::TEMPLATE_GROUP_1_ID, 'name' => 'Group Campaign'], $grouped['group']);

        $membership = [];
        self::assertIsArray($grouped['variants']);
        foreach ($grouped['variants'] as $variant) {
            self::assertIsArray($variant);
            self::assertIsString($variant['id'] ?? null);
            $membership[$variant['id']] = $variant['groupMember'] ?? null;
        }

        self::assertTrue($membership[TestDataFixture::GROUPED_PRESET_VARIANT_ID]);
        self::assertTrue($membership[TestDataFixture::GROUPED_FREEFORM_VARIANT_ID]);
        self::assertFalse($membership[TestDataFixture::UNGROUPED_VARIANT_ON_GROUPED_TEMPLATE_ID]);
    }

    public function testPreviewRendersOneDimensionAsWebpAndRecordsNothing(): void
    {
        [$client, $token] = $this->authenticatedClient();
        $client->disableReboot();
        $this->seedAllowedImage();

        $response = $this->post($client, $token, '/preview/' . TestDataFixture::GROUPED_FREEFORM_VARIANT_ID, $this->fill());

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'image/webp');
        self::assertStringStartsWith('RIFF', $response->getContent());

        $call = $this->renderer()->calls[count($this->renderer()->calls) - 1];
        self::assertSame(TestDataFixture::GROUPED_FREEFORM_VARIANT_ID, $call['variantId']);
        self::assertSame('Letní kampaň', $call['texts'][TestDataFixture::GROUP_SHARED_INPUT_ID] ?? null);
        self::assertTrue($call['strictContainerOverflow']);
        // The freeform dimension has no placement entry → the shared pick, neutral.
        self::assertSame(1.0, $call['images'][TestDataFixture::GROUP_SHARED_IMAGE_INPUT_ID]['scale'] ?? null);

        self::assertSame(0, $this->countRows(TemplateExportVersion::class));
        self::assertSame(0, $this->countRows(ExportEvent::class));
    }

    public function testPreviewOfANonMemberVariantIs404(): void
    {
        [$client, $token] = $this->authenticatedClient();

        $this->post($client, $token, '/preview/' . TestDataFixture::UNGROUPED_VARIANT_ON_GROUPED_TEMPLATE_ID, ['inputs' => []]);

        $this->assertResponseStatusCodeSame(404);
    }

    public function testZipExportsEveryMemberWithItsOwnPlacementAndRecordsOneGroupVersion(): void
    {
        [$client, $token] = $this->authenticatedClient();
        $client->disableReboot();
        $this->seedAllowedImage();

        $response = $this->post($client, $token, '/export', $this->fill());

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/zip');
        $this->assertResponseHeaderSame('content-disposition', 'attachment; filename="group-campaign.zip"');

        $names = $this->zipEntryNames($response->getContent());
        sort($names);
        self::assertSame(['group-campaign-1-1.png', 'group-campaign-210-297-mm.png'], $names);

        $calls = array_slice($this->renderer()->calls, -2);
        $byVariant = [];
        foreach ($calls as $call) {
            self::assertSame('png', $call['format']);
            $byVariant[$call['variantId']] = $call;
        }

        // Placement is per dimension: the 1:1 got its own, the A4 the neutral pick.
        self::assertSame(1.5, $byVariant[TestDataFixture::GROUPED_PRESET_VARIANT_ID]['images'][TestDataFixture::GROUP_SHARED_IMAGE_INPUT_ID]['scale']);
        self::assertSame(0.25, $byVariant[TestDataFixture::GROUPED_PRESET_VARIANT_ID]['images'][TestDataFixture::GROUP_SHARED_IMAGE_INPUT_ID]['offsetXRatio']);
        self::assertSame(1.0, $byVariant[TestDataFixture::GROUPED_FREEFORM_VARIANT_ID]['images'][TestDataFixture::GROUP_SHARED_IMAGE_INPUT_ID]['scale']);

        $versions = $this->groupVersions();
        self::assertCount(1, $versions);
        self::assertSame('api', $versions[0]->channel->value);
        self::assertSame(
            ['offsetXRatio' => 0.25, 'scale' => 1.5],
            $versions[0]->fillValues->toArray()['placements'][TestDataFixture::GROUPED_PRESET_VARIANT_ID][TestDataFixture::GROUP_SHARED_IMAGE_INPUT_ID],
        );
        self::assertSame(2, $this->countRows(ExportEvent::class));

        // The single-dimension export of the same fill bumps the SAME version.
        $single = $this->post($client, $token, '/export/' . TestDataFixture::GROUPED_PRESET_VARIANT_ID, $this->fill());
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'image/png');
        $this->assertResponseHeaderSame('content-disposition', 'attachment; filename="group-campaign-1-1.png"');
        self::assertStringStartsWith("\x89PNG", $single->getContent());

        $bumped = $this->groupVersions();
        self::assertCount(1, $bumped);
        self::assertSame(2, $bumped[0]->exportCount);
    }

    public function testFailingDimensionAnswersStructured400WithVariantIdAndRecordsNothing(): void
    {
        [$client, $token] = $this->authenticatedClient();
        $client->disableReboot();

        $this->renderer()->throwContainerOverflow = new ContainerOverflow('11111111-2222-4333-8444-555555555555', 9.0);

        $response = $this->post($client, $token, '/export', ['inputs' => [TestDataFixture::GROUP_SHARED_INPUT_ID => 'x']]);

        $this->assertResponseStatusCodeSame(400);
        $body = $response->toArray(false);
        self::assertSame('container_overflow', $body['code'] ?? null);
        self::assertSame(TestDataFixture::GROUPED_PRESET_VARIANT_ID, $body['variantId'] ?? null);
        self::assertSame([], $this->groupVersions());
    }

    public function testImageErrorsCarryCodeAndSlot(): void
    {
        [$client, $token] = $this->authenticatedClient();

        $response = $this->post($client, $token, '/export', [
            'images' => [TestDataFixture::GROUP_SHARED_IMAGE_INPUT_ID => ['imageId' => TestDataFixture::FILE_TRASHED_ID]],
        ]);

        $this->assertResponseStatusCodeSame(400);
        $body = $response->toArray(false);
        self::assertSame('image_not_allowed', $body['code'] ?? null);
        self::assertSame(TestDataFixture::GROUP_SHARED_IMAGE_INPUT_ID, $body['imageInputId'] ?? null);
        self::assertArrayHasKey('variantId', $body);
    }

    public function testHistoryListsLoadsRenamesAndPinsTheGroupVersion(): void
    {
        [$client, $token] = $this->authenticatedClient();
        $client->disableReboot();
        $this->seedAllowedImage();

        $this->post($client, $token, '/export', $this->fill());
        $this->assertResponseIsSuccessful();

        $list = $client->request('GET', '/api/template-groups/' . TestDataFixture::TEMPLATE_GROUP_1_ID . '/export-versions', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ])->toArray();

        self::assertCount(1, $list);
        $row = $list[0];
        self::assertIsArray($row);
        self::assertSame('group', $row['subject']);
        self::assertSame(TestDataFixture::TEMPLATE_GROUP_1_ID, $row['groupId']);
        self::assertFalse($row['pinned']);
        self::assertNull($row['name']);
        self::assertSame('api', $row['channel']);
        self::assertSame(['texts' => [['label' => 'headline', 'value' => 'Letní kampaň']], 'pictures' => 1, 'hidden' => 0], $row['summary']);

        $versionId = $row['id'];
        self::assertIsString($versionId);

        $detail = $client->request('GET', '/api/export-versions/' . $versionId, [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ])->toArray();

        /** @var array{inputs: array<string, mixed>, images: array<string, array<string, mixed>>, placements: array<string, array<string, mixed>>} $fill */
        $fill = $detail['fill'];
        self::assertSame([TestDataFixture::GROUP_SHARED_INPUT_ID => 'Letní kampaň'], $fill['inputs']);
        self::assertSame(TestDataFixture::FILE_IN_ALLOWED_ID, $fill['images'][TestDataFixture::GROUP_SHARED_IMAGE_INPUT_ID]['imageId'] ?? null);
        self::assertIsString($fill['images'][TestDataFixture::GROUP_SHARED_IMAGE_INPUT_ID]['url'] ?? null);
        self::assertEquals(
            ['offsetXRatio' => 0.25, 'scale' => 1.5],
            $fill['placements'][TestDataFixture::GROUPED_PRESET_VARIANT_ID][TestDataFixture::GROUP_SHARED_IMAGE_INPUT_ID] ?? null,
        );

        $patched = $client->request('PATCH', '/api/export-versions/' . $versionId, [
            'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
            'body' => json_encode(['name' => '  Finální verze  ', 'pinned' => true], JSON_THROW_ON_ERROR),
        ])->toArray();

        self::assertSame('Finální verze', $patched['name']);
        self::assertTrue($patched['pinned']);

        // Absent keys stay untouched; an explicit null clears the name.
        $unpinned = $client->request('PATCH', '/api/export-versions/' . $versionId, [
            'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
            'body' => json_encode(['pinned' => false], JSON_THROW_ON_ERROR),
        ])->toArray();
        self::assertSame('Finální verze', $unpinned['name']);
        self::assertFalse($unpinned['pinned']);

        $cleared = $client->request('PATCH', '/api/export-versions/' . $versionId, [
            'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
            'body' => json_encode(['name' => null], JSON_THROW_ON_ERROR),
        ])->toArray();
        self::assertNull($cleared['name']);
    }

    public function testVariantPreviewIsWebpAndOnlyTheExportRecords(): void
    {
        [$client, $token] = $this->authenticatedClient();
        $client->disableReboot();

        $body = json_encode(['inputs' => [TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_INPUT_HEADLINE_ID => 'Preview']], JSON_THROW_ON_ERROR);
        $headers = ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'];

        $client->request('POST', '/api/template-variants/' . TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID . '/preview', ['headers' => $headers, 'body' => $body]);
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'image/webp');
        self::assertSame(0, $this->countRows(TemplateExportVersion::class));

        $client->request('POST', '/api/template-variants/' . TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID . '/export', ['headers' => $headers, 'body' => $body]);
        $this->assertResponseIsSuccessful();
        self::assertSame(1, $this->countRows(TemplateExportVersion::class));

        $list = $client->request('GET', '/api/template-variants/' . TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID . '/export-versions', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ])->toArray();
        self::assertCount(1, $list);
        self::assertIsArray($list[0]);
        self::assertSame('variant', $list[0]['subject']);

        $versionId = $list[0]['id'];
        self::assertIsString($versionId);
        $detail = $client->request('GET', '/api/export-versions/' . $versionId, [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ])->toArray();
        /** @var array{inputs: array<string, mixed>, placements: array<string, mixed>} $fill */
        $fill = $detail['fill'];
        self::assertSame([TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_INPUT_HEADLINE_ID => 'Preview'], $fill['inputs']);
        self::assertSame([], $fill['placements']);
    }

    public function testValueTooLongIsStructured(): void
    {
        [$client, $token] = $this->authenticatedClient();

        $response = $client->request('POST', '/api/template-variants/' . TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID . '/preview', [
            'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
            'body' => json_encode(['inputs' => [TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_INPUT_HEADLINE_ID => str_repeat('A', 31)]], JSON_THROW_ON_ERROR),
        ]);

        $this->assertResponseStatusCodeSame(400);
        $body = $response->toArray(false);
        self::assertSame('value_too_long', $body['code'] ?? null);
        self::assertSame(TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_INPUT_HEADLINE_ID, $body['inputId'] ?? null);
        self::assertSame(30, $body['maxLength'] ?? null);
    }

    public function testForeignVersionIsForbidden(): void
    {
        [$client, $token] = $this->authenticatedClient();

        $client->request('GET', '/api/template-groups/' . TestDataFixture::TEMPLATE_GROUP_1_ID . '/export-versions', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);
        $this->assertResponseIsSuccessful();

        $client->request('GET', '/api/export-versions/00000000-0000-0000-0000-00000000dead', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);
        $this->assertResponseStatusCodeSame(404);

        $client->request('GET', '/api/template-variants/' . TestDataFixture::CUSTOM_TEMPLATE_VARIANT_2_ID . '/export-versions', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);
        $this->assertResponseStatusCodeSame(403);
    }

    /**
     * @return array<string, mixed>
     */
    private function fill(): array
    {
        return [
            'inputs' => [TestDataFixture::GROUP_SHARED_INPUT_ID => 'Letní kampaň'],
            'images' => [TestDataFixture::GROUP_SHARED_IMAGE_INPUT_ID => TestDataFixture::FILE_IN_ALLOWED_ID],
            'placements' => [
                TestDataFixture::GROUPED_PRESET_VARIANT_ID => [
                    TestDataFixture::GROUP_SHARED_IMAGE_INPUT_ID => ['scale' => 1.5, 'offsetXRatio' => 0.25],
                ],
            ],
        ];
    }

    /**
     * @return array{Client, string}
     */
    private function authenticatedClient(): array
    {
        $client = self::createClient();
        $token = TestingApiAuthentication::getAccessToken(
            $client,
            TestDataFixture::OAUTH2_CLIENT_ID,
            TestDataFixture::OAUTH2_CLIENT_SECRET,
        );

        return [$client, $token];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(Client $client, string $token, string $path, array $body): \Symfony\Contracts\HttpClient\ResponseInterface
    {
        return $client->request('POST', '/api/template-groups/' . TestDataFixture::TEMPLATE_GROUP_1_ID . $path, [
            'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
            'body' => json_encode($body, JSON_THROW_ON_ERROR),
        ]);
    }

    /**
     * @return list<string>
     */
    private function zipEntryNames(string $bytes): array
    {
        $path = tempnam(sys_get_temp_dir(), 'zip-test-');
        self::assertIsString($path);
        file_put_contents($path, $bytes);

        $zip = new ZipArchive();
        self::assertTrue($zip->open($path));

        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            self::assertIsString($name);
            $names[] = $name;
        }

        $zip->close();
        unlink($path);

        return $names;
    }

    /**
     * @return list<TemplateExportVersion>
     */
    private function groupVersions(): array
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        /** @var list<TemplateExportVersion> $versions */
        $versions = $entityManager->getRepository(TemplateExportVersion::class)->findBy(['group' => TestDataFixture::TEMPLATE_GROUP_1_ID]);

        return $versions;
    }

    /**
     * @param class-string $entity
     */
    private function countRows(string $entity): int
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        return $entityManager->getRepository($entity)->count([]);
    }

    private function seedAllowedImage(): void
    {
        $bytes = base64_decode(self::PNG_1X1_BASE64, true);
        self::assertIsString($bytes);
        self::getContainer()->get('oneup_flysystem.minio_filesystem')->write('fixtures/in-allowed.png', $bytes);
    }

    private function renderer(): FakeTemplateVariantImageRenderer
    {
        $renderer = self::getContainer()->get(TemplateVariantImageRendererInterface::class);
        /** @phpstan-ignore staticMethod.impossibleType */
        self::assertInstanceOf(FakeTemplateVariantImageRenderer::class, $renderer);

        return $renderer;
    }
}
