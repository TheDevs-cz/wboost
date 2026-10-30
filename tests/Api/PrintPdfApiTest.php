<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\Client;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingApiAuthentication;
use ZipArchive;

/**
 * `format: "pdf"` on the export endpoints — the print PDF for print formats
 * (mm / cm) only — and the print setup on the variant listing.
 *
 * @covers \WBoost\Web\Api\Templates\ExportProcessor
 * @covers \WBoost\Web\Api\TemplateGroups\GroupExportProcessor
 * @covers \WBoost\Web\Api\Templates\TemplatesProvider
 * @covers \WBoost\Web\Services\Template\ApiExportRenderer
 */
final class PrintPdfApiTest extends ApiTestCase
{
    public function testListingCarriesThePrintSetup(): void
    {
        [$client, $token] = $this->authenticatedClient();

        $rows = $client->request('GET', '/api/projects/' . TestDataFixture::PROJECT_1_ID . '/templates', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ])->toArray();

        $variants = [];
        foreach ($rows as $row) {
            self::assertIsArray($row);
            self::assertIsArray($row['variants'] ?? null);
            foreach ($row['variants'] as $variant) {
                self::assertIsArray($variant);
                self::assertIsString($variant['id'] ?? null);
                $variants[$variant['id']] = $variant;
            }
        }

        $a4 = $variants[TestDataFixture::GROUPED_FREEFORM_VARIANT_ID];
        self::assertTrue($a4['printFormat']);
        self::assertEquals(0, $a4['bleedMm']);
        self::assertFalse($a4['cropMarks']);
        self::assertFalse($variants[TestDataFixture::GROUPED_PRESET_VARIANT_ID]['printFormat']);
    }

    public function testVariantExportAsPdf(): void
    {
        [$client, $token] = $this->authenticatedClient();

        $response = $client->request('POST', '/api/template-variants/' . TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID . '/export', [
            'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
            'body' => json_encode(['format' => 'pdf'], JSON_THROW_ON_ERROR),
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/pdf');
        self::assertStringStartsWith('%PDF-1.3', $response->getContent());
        self::assertStringContainsString('/GTS_PDFXConformance (PDF/X-1a:2001)', $response->getContent());
    }

    public function testScreenFormatAndUnknownFormatAreStructured400s(): void
    {
        [$client, $token] = $this->authenticatedClient();
        $headers = ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'];

        $response = $client->request('POST', '/api/template-variants/' . TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID . '/export', [
            'headers' => $headers,
            'body' => json_encode(['format' => 'pdf'], JSON_THROW_ON_ERROR),
        ]);
        self::assertSame(400, $response->getStatusCode());
        self::assertSame('pdf_not_available', $response->toArray(false)['code'] ?? null);

        $response = $client->request('POST', '/api/template-variants/' . TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID . '/export', [
            'headers' => $headers,
            'body' => json_encode(['format' => 'tiff'], JSON_THROW_ON_ERROR),
        ]);
        self::assertSame(400, $response->getStatusCode());
        self::assertSame('invalid_format', $response->toArray(false)['code'] ?? null);

        $response = $client->request('POST', '/api/template-groups/' . TestDataFixture::TEMPLATE_GROUP_1_ID . '/export/' . TestDataFixture::GROUPED_PRESET_VARIANT_ID, [
            'headers' => $headers,
            'body' => json_encode(['format' => 'pdf'], JSON_THROW_ON_ERROR),
        ]);
        self::assertSame(400, $response->getStatusCode());
        self::assertSame(['code' => 'pdf_not_available', 'variantId' => TestDataFixture::GROUPED_PRESET_VARIANT_ID], array_intersect_key($response->toArray(false), ['code' => 1, 'variantId' => 1]));
    }

    public function testGroupZipOfPdfsHoldsThePrintMembersOnly(): void
    {
        [$client, $token] = $this->authenticatedClient();

        $response = $client->request('POST', '/api/template-groups/' . TestDataFixture::TEMPLATE_GROUP_1_ID . '/export', [
            'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
            'body' => json_encode(['format' => 'pdf'], JSON_THROW_ON_ERROR),
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-disposition', 'attachment; filename="group-campaign-pdf.zip"');

        $path = tempnam(sys_get_temp_dir(), 'zip-test-');
        self::assertIsString($path);
        file_put_contents($path, $response->getContent());
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path));
        self::assertSame(1, $zip->numFiles);
        self::assertSame('group-campaign-210-297-mm.pdf', $zip->getNameIndex(0));
        $zip->close();
        unlink($path);
    }

    /**
     * @return array{Client, string}
     */
    private function authenticatedClient(): array
    {
        $client = self::createClient();

        return [$client, TestingApiAuthentication::getAccessToken($client, TestDataFixture::OAUTH2_CLIENT_ID, TestDataFixture::OAUTH2_CLIENT_SECRET)];
    }
}
