<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Mcp\Tool;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use WBoost\Web\Entity\ExportEvent;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\Mcp\TestingMcpClient;
use WBoost\Web\Tests\TemplateDocumentTestData;
use WBoost\Web\Tests\UnfilledPicturesTestData;

/**
 * MCP refuses (owner decision O11): `export_variant` / `export_document` do
 * not hand over a file showing the grey stand-in an AI scan left behind
 * unless the agent passes `allowUnfilled: true`. The refusal is the tool's
 * own answer (`isError`), starts with the code and says what to do.
 *
 * @covers \WBoost\Web\Services\AiScan\Template\UnfilledExportGuard
 * @covers \WBoost\Web\Mcp\Tool\ExportVariantTool
 * @covers \WBoost\Web\Mcp\Tool\ExportDocumentTool
 */
final class ExportUnfilledPicturesTest extends WebTestCase
{
    use UnfilledPicturesTestData;

    private const string VARIANT = TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID;
    private const string SLOT = TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_IMAGE_PHOTO_ID;

    private null|KernelBrowser $browser = null;

    public function testExportVariantWithDecorativeStandInsIsRefusedUnlessAllowed(): void
    {
        $this->browser();
        self::addDecorative(self::VARIANT, 2);

        $refused = $this->call('export_variant', ['variantId' => self::VARIANT]);

        self::assertTrue($refused['isError']);
        self::assertStringStartsWith('unfilled_pictures: this export would show 2 grey stand-in pictures left by an AI scan', $refused['text']);
        self::assertStringContainsString('editorUrl: http://localhost/template-variant/' . self::VARIANT . '/editor', $refused['text']);
        self::assertStringContainsString('allowUnfilled: true', $refused['text']);
        self::assertSame(0, $this->exportEvents(), 'a refused export records nothing');

        $allowed = $this->call('export_variant', ['variantId' => self::VARIANT, 'allowUnfilled' => true]);

        self::assertFalse($allowed['isError'], $allowed['text']);
        self::assertSame(2, $allowed['blocks'], 'a summary and the picture');
        self::assertSame(1, $this->exportEvents());
    }

    public function testExportVariantWithABackgroundOnTheStandInIsRefusedOnlyWithoutAPicture(): void
    {
        $this->browser();
        self::putBackgroundOnStandIn(self::VARIANT, self::SLOT);
        self::seedAllowedImage();

        $refused = $this->call('export_variant', ['variantId' => self::VARIANT]);

        self::assertTrue($refused['isError']);
        self::assertStringStartsWith('unfilled_pictures: this export would show 1 grey stand-in picture left by an AI scan', $refused['text']);
        self::assertSame(0, $this->exportEvents());

        $filled = $this->call('export_variant', ['variantId' => self::VARIANT, 'images' => [self::SLOT => TestDataFixture::FILE_IN_ALLOWED_ID]]);

        self::assertFalse($filled['isError'], $filled['text']);
        self::assertSame(1, $this->exportEvents());
    }

    public function testAnOrdinaryVariantExportsAsBefore(): void
    {
        $this->browser();
        // The project owns a stand-in, this template does not use it.
        self::standInPath(self::VARIANT);

        $result = $this->call('export_variant', ['variantId' => self::VARIANT]);

        self::assertFalse($result['isError'], $result['text']);
    }

    public function testExportDocumentSumsOverItsPages(): void
    {
        $this->browser();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $first = TemplateDocumentTestData::richA4Template($em, 'Úvod');
        $second = TemplateDocumentTestData::richA4Template($em, 'Ceník');
        $documentId = TemplateDocumentTestData::document($em, self::getContainer()->get(MessageBusInterface::class), [$first['template'], $second['template']])->toString();
        self::addDecorative($first['variant']->toString());
        self::putBackgroundOnStandIn($second['variant']->toString(), TemplateDocumentTestData::IMAGE_INPUT_ID);

        $refused = $this->call('export_document', ['documentId' => $documentId, 'format' => 'png']);

        self::assertTrue($refused['isError']);
        self::assertStringStartsWith('unfilled_pictures: this export would show 2 grey stand-in pictures left by an AI scan', $refused['text']);
        self::assertStringContainsString('editorUrl: http://localhost/template-variant/' . $first['variant'] . '/editor', $refused['text']);
        self::assertSame(0, $this->exportEvents());

        $allowed = $this->call('export_document', ['documentId' => $documentId, 'format' => 'png', 'allowUnfilled' => true]);

        self::assertFalse($allowed['isError'], $allowed['text']);
        self::assertSame(2, $this->exportEvents());
    }

    public function testAnOrdinaryDocumentExportsAsBefore(): void
    {
        $this->browser();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $page = TemplateDocumentTestData::richA4Template($em, 'Úvod');
        $documentId = TemplateDocumentTestData::document($em, self::getContainer()->get(MessageBusInterface::class), [$page['template']])->toString();
        self::standInPath($page['variant']->toString());

        $result = $this->call('export_document', ['documentId' => $documentId, 'format' => 'png']);

        self::assertFalse($result['isError'], $result['text']);
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array{isError: bool, text: string, blocks: int}
     */
    private function call(string $tool, array $arguments): array
    {
        $browser = $this->browser();
        $sessionId = TestingMcpClient::connect($browser, TestDataFixture::MCP_TOKEN_ACTIVE);
        TestingMcpClient::request($browser, 'tools/call', ['name' => $tool, 'arguments' => $arguments], $sessionId, TestDataFixture::MCP_TOKEN_ACTIVE);

        $response = $browser->getResponse();
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertArrayNotHasKey('error', $payload, (string) $response->getContent());

        $result = $payload['result'];
        self::assertIsArray($result);
        $content = $result['content'];
        self::assertIsArray($content);
        self::assertIsArray($content[0]);
        $text = $content[0]['text'];
        self::assertIsString($text);

        return ['isError' => ($result['isError'] ?? false) === true, 'text' => $text, 'blocks' => count($content)];
    }

    private function exportEvents(): int
    {
        return self::getContainer()->get(EntityManagerInterface::class)->getRepository(ExportEvent::class)->count([]);
    }

    private function browser(): KernelBrowser
    {
        return $this->browser ??= self::createClient();
    }
}
