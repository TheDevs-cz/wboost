<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\TemplateDocument;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\UuidInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use WBoost\Web\Entity\TemplateDocument;
use WBoost\Web\Services\Editor\TemplateVariantImageRendererInterface;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\Fakes\FakeTemplateVariantImageRenderer;
use WBoost\Web\Tests\TemplateDocumentTestData;
use WBoost\Web\Tests\TestingLogin;
use WBoost\Web\Value\DocumentFillKeys;

/**
 * One file for the whole document, in three formats — and every page gets
 * ITS OWN value even though both pages carry the same inputId.
 */
final class TemplateDocumentExportControllerTest extends WebTestCase
{
    public function testPrintPdfHasOnePagePerDocumentPageWithItsOwnValues(): void
    {
        [$client, $documentId, $variants] = $this->twoPageDocument();

        $client->request('POST', '/template-document/' . $documentId . '/export', ['format' => 'pdf'] + $this->fill($documentId, ['Strana jedna', 'Strana dvě']));

        self::assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertSame('application/pdf', $response->headers->get('Content-Type'));
        self::assertSame('attachment; filename="cenova-nabidka.pdf"', $response->headers->get('Content-Disposition'));
        $pdf = (string) $response->getContent();
        self::assertStringContainsString('/GTS_PDFXConformance (PDF/X-1a:2001)', $pdf);
        self::assertStringContainsString('/Count 2', $pdf);

        $texts = [];
        foreach ($this->rendererFake()->calls as $call) {
            $texts[$call['variantId']] = $call['texts'][TemplateDocumentTestData::SHARED_INPUT_ID] ?? null;
        }
        self::assertSame('Strana jedna', $texts[$variants[0]->toString()]);
        self::assertSame('Strana dvě', $texts[$variants[1]->toString()]);
    }

    public function testScreenPdfAndPngZip(): void
    {
        [$client, $documentId] = $this->twoPageDocument();

        $client->request('POST', '/template-document/' . $documentId . '/export', ['format' => 'pdf-screen'] + $this->fill($documentId, ['A', 'B']));
        self::assertResponseIsSuccessful();
        $pdf = (string) $client->getResponse()->getContent();
        self::assertStringStartsWith('%PDF-1.4', $pdf);
        self::assertStringContainsString('/Count 2', $pdf);
        self::assertStringNotContainsString('GTS_PDFX', $pdf);

        $client->request('POST', '/template-document/' . $documentId . '/export', ['format' => 'png'] + $this->fill($documentId, ['A', 'B']));
        self::assertResponseIsSuccessful();
        self::assertSame('application/zip', $client->getResponse()->headers->get('Content-Type'));

        $path = tempnam(sys_get_temp_dir(), 'doc-zip-');
        self::assertIsString($path);
        file_put_contents($path, (string) $client->getResponse()->getContent());
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path));
        self::assertSame(['01-uvod.png', '02-cenik.png'], [$zip->getNameIndex(0), $zip->getNameIndex(1)]);
        $zip->close();
        unlink($path);
    }

    /**
     * @return array{KernelBrowser, UuidInterface, list<UuidInterface>}
     */
    private function twoPageDocument(): array
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $first = TemplateDocumentTestData::a4Template($em, 'Úvod');
        $second = TemplateDocumentTestData::a4Template($em, 'Ceník');
        $documentId = TemplateDocumentTestData::document($em, self::getContainer()->get(MessageBusInterface::class), [$first['template'], $second['template']], 'Cenová nabídka');

        return [$client, $documentId, [$first['variant'], $second['variant']]];
    }

    /**
     * The fill page's form in FILL-KEY space: one text per page.
     *
     * @param list<string> $texts
     *
     * @return array<string, mixed>
     */
    private function fill(UuidInterface $documentId, array $texts): array
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $document = $em->find(TemplateDocument::class, $documentId);
        self::assertInstanceOf(TemplateDocument::class, $document);
        $keys = DocumentFillKeys::forDocument($document);

        $values = [];
        foreach ($document->pages() as $index => $page) {
            $values[$keys->keyFor($page->template->id->toString(), TemplateDocumentTestData::SHARED_INPUT_ID)] = $texts[$index];
        }

        return ['textValues' => $values];
    }

    private function rendererFake(): FakeTemplateVariantImageRenderer
    {
        $renderer = self::getContainer()->get(TemplateVariantImageRendererInterface::class);
        /** @phpstan-ignore staticMethod.impossibleType */
        self::assertInstanceOf(FakeTemplateVariantImageRenderer::class, $renderer);

        return $renderer;
    }
}
