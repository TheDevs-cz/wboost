<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use WBoost\Web\Entity\ExportEvent;
use WBoost\Web\Services\Editor\TemplateVariantImageRendererInterface;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\Fakes\FakeTemplateVariantImageRenderer;
use WBoost\Web\Tests\TestingLogin;
use WBoost\Web\Value\ExportFormat;
use ZipArchive;

/**
 * The print PDF on every web export surface: the fill form's PDF button posts
 * `format=pdf` to the SAME routes the PNG export uses.
 *
 * @covers \WBoost\Web\Controller\Template\TemplateVariantDownloadController
 * @covers \WBoost\Web\Controller\TemplateGroup\TemplateGroupExportVariantController
 * @covers \WBoost\Web\Controller\TemplateGroup\TemplateGroupExportController
 * @covers \WBoost\Web\Services\Print\PrintPdfRenderer
 */
final class PrintPdfExportTest extends WebTestCase
{
    public function testSingleVariantPdfDownload(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $client->request('POST', '/template-variant/' . TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID . '/download', [
            'format' => 'pdf',
        ]);

        self::assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertSame('application/pdf', $response->headers->get('Content-Type'));
        self::assertStringEndsWith('.pdf"', (string) $response->headers->get('Content-Disposition'));
        $this->assertPrintPdf((string) $response->getContent());

        // Raster passes, never the cached preview path: PNG over the bleed box.
        $calls = $this->rendererFake()->calls;
        self::assertNotEmpty($calls);
        foreach ($calls as $call) {
            self::assertSame('printLayer', $call['mode']);
            self::assertSame('png', $call['format']);
        }

        $event = self::getContainer()->get(EntityManagerInterface::class)->getRepository(ExportEvent::class)->findOneBy([
            'variantId' => Uuid::fromString(TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID),
        ]);
        self::assertInstanceOf(ExportEvent::class, $event);
        self::assertSame(ExportFormat::Pdf, $event->format);
    }

    public function testScreenFormatsHaveNoPdf(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        // 1080 × 1080 px social variant.
        $client->request('POST', '/template-variant/' . TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID . '/download', [
            'format' => 'pdf',
        ]);
        self::assertResponseStatusCodeSame(400);
        self::assertSame([], $this->rendererFake()->calls, 'refused before rendering');

        $client->request('POST', '/template-group/' . TestDataFixture::TEMPLATE_GROUP_1_ID . '/export/' . TestDataFixture::GROUPED_PRESET_VARIANT_ID, [
            'format' => 'pdf',
        ]);
        self::assertResponseStatusCodeSame(400);
    }

    public function testPngStaysTheDefault(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $client->request('POST', '/template-variant/' . TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID . '/download', [
            'format' => 'bogus',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame('image/png', $client->getResponse()->headers->get('Content-Type'));
    }

    public function testGroupDimensionPdfDownload(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);

        $client->request('POST', '/template-group/' . TestDataFixture::TEMPLATE_GROUP_1_ID . '/export/' . TestDataFixture::GROUPED_FREEFORM_VARIANT_ID, [
            'format' => 'pdf',
        ]);

        self::assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertSame('application/pdf', $response->headers->get('Content-Type'));
        self::assertSame('attachment; filename="group-campaign-210-297-mm.pdf"', $response->headers->get('Content-Disposition'));
        $this->assertPrintPdf((string) $response->getContent());
    }

    public function testGroupZipOfPdfs(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);

        $client->request('POST', '/template-group/' . TestDataFixture::TEMPLATE_GROUP_1_ID . '/export', [
            'format' => 'pdf',
        ]);

        self::assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertSame('application/zip', $response->headers->get('Content-Type'));
        self::assertSame('attachment; filename="group-campaign-pdf.zip"', $response->headers->get('Content-Disposition'));

        $path = tempnam(sys_get_temp_dir(), 'zip-test-');
        self::assertIsString($path);
        file_put_contents($path, (string) $response->getContent());
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path));

        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            $names[] = $name;
            $this->assertPrintPdf((string) $zip->getFromIndex($i));
        }
        $zip->close();
        unlink($path);

        // Print formats only: the 1:1 social member is left out.
        self::assertSame(['group-campaign-210-297-mm.pdf'], $names);
    }

    private function assertPrintPdf(string $bytes): void
    {
        self::assertStringStartsWith('%PDF-1.3', $bytes);
        self::assertStringContainsString('/GTS_PDFXConformance (PDF/X-1a:2001)', $bytes);
        self::assertStringContainsString('/S /GTS_PDFX', $bytes);
        self::assertStringContainsString('/TrimBox', $bytes);
        self::assertStringEndsWith("%%EOF\n", $bytes);
    }

    private function rendererFake(): FakeTemplateVariantImageRenderer
    {
        $renderer = self::getContainer()->get(TemplateVariantImageRendererInterface::class);
        /** @phpstan-ignore staticMethod.impossibleType */
        self::assertInstanceOf(FakeTemplateVariantImageRenderer::class, $renderer);

        return $renderer;
    }
}
