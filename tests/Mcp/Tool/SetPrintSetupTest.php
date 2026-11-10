<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Mcp\Tool;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\Mcp\TestingMcpClient;
use WBoost\Web\Tests\TemplateDocumentTestData;

/**
 * `set_print_setup` — bleed + crop marks of a standalone print variant, the
 * MCP twin of `PATCH /api/template-variants/{id}/print-setup`.
 *
 * @covers \WBoost\Web\Mcp\Tool\SetPrintSetupTool
 */
final class SetPrintSetupTest extends WebTestCase
{
    private null|KernelBrowser $browser = null;

    public function testSetsBleedAndCropMarks(): void
    {
        $set = $this->payload($this->call(TestDataFixture::MCP_TOKEN_ACTIVE, ['variantId' => TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID, 'bleedMm' => 3, 'cropMarks' => true]));
        self::assertSame(TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID, $set['variantId']);
        self::assertEquals(3, $set['dimension']['bleedMm']);
        self::assertSame(35, $set['dimension']['bleedPx']);
        self::assertTrue($set['dimension']['cropMarks']);

        $kept = $this->payload($this->call(TestDataFixture::MCP_TOKEN_ACTIVE, ['variantId' => TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID, 'cropMarks' => false]));
        self::assertEquals(3, $kept['dimension']['bleedMm'], 'An omitted value keeps its setting.');
        self::assertFalse($kept['dimension']['cropMarks']);

        $grouped = $this->payload($this->call(TestDataFixture::MCP_TOKEN_ACTIVE, ['variantId' => TestDataFixture::GROUPED_FREEFORM_VARIANT_ID, 'bleedMm' => 5]));
        self::assertEquals(5, $grouped['dimension']['bleedMm'], 'A grouped variant has its own print setup, like on the web.');
    }

    public function testRefusals(): void
    {
        $variant = TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID;

        foreach ([[['bleedMm' => 10.5], 'invalid_bleed'], [['bleedMm' => 25], 'invalid_bleed'], [['bleedMm' => -1], 'invalid_bleed'], [[], 'missing_print_setup']] as [$arguments, $code]) {
            $refused = $this->call(TestDataFixture::MCP_TOKEN_ACTIVE, ['variantId' => $variant] + $arguments);
            self::assertTrue($refused['isError'] ?? false);
            self::assertStringContainsString($code, $refused['content'][0]['text']);
        }

        $screen = $this->call(TestDataFixture::MCP_TOKEN_ACTIVE, ['variantId' => TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID, 'bleedMm' => 3]);
        self::assertTrue($screen['isError'] ?? false);
        self::assertStringContainsString('print_setup_not_available', $screen['content'][0]['text']);

        $page = TemplateDocumentTestData::a4Template($this->em(), 'Úvod');
        $documentId = TemplateDocumentTestData::document($this->em(), self::getContainer()->get(MessageBusInterface::class), [$page['template']]);
        $documentPage = $this->call(TestDataFixture::MCP_TOKEN_ACTIVE, ['variantId' => $page['variant']->toString(), 'bleedMm' => 3]);
        self::assertTrue($documentPage['isError'] ?? false);
        self::assertStringContainsString('document_page', $documentPage['content'][0]['text']);
        self::assertStringContainsString($documentId->toString(), $documentPage['content'][0]['text']);
        self::assertStringContainsString('set_print', $documentPage['content'][0]['text']);

        $unknown = $this->call(TestDataFixture::MCP_TOKEN_ACTIVE, ['variantId' => Uuid::uuid7()->toString(), 'bleedMm' => 3]);
        self::assertTrue($unknown['isError'] ?? false);
    }

    public function testNeedsTheDesignScope(): void
    {
        $browser = $this->browser();
        $sessionId = TestingMcpClient::connect($browser, TestDataFixture::MCP_TOKEN_READ_ONLY);
        TestingMcpClient::request($browser, 'tools/call', ['name' => 'set_print_setup', 'arguments' => ['variantId' => TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID, 'bleedMm' => 3]], $sessionId, TestDataFixture::MCP_TOKEN_READ_ONLY);

        $content = (string) $browser->getResponse()->getContent();
        self::assertStringContainsString('templates:design', $content);
        self::assertStringNotContainsString('"bleedPx":35', $content);
    }

    public function testDescriptionExplainsTheBleed(): void
    {
        $browser = $this->browser();
        $sessionId = TestingMcpClient::connect($browser, TestDataFixture::MCP_TOKEN_ACTIVE);
        TestingMcpClient::request($browser, 'tools/list', [], $sessionId, TestDataFixture::MCP_TOKEN_ACTIVE);

        /** @var array{result?: array{tools?: list<array{name: string, description: string}>}} $payload */
        $payload = json_decode((string) $browser->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $description = array_column($payload['result']['tools'] ?? [], 'description', 'name')['set_print_setup'] ?? '';

        self::assertStringContainsString('0–10 mm', $description);
        self::assertStringContainsString('max(bleed, 8 mm)', $description);
        self::assertStringContainsString('crop marks', $description);
    }

    /**
     * @param array{isError?: bool, content: list<array{text: string}>} $result
     * @return array{variantId: string, dimension: array{bleedMm: float|int, bleedPx: int, cropMarks: bool}}
     */
    private function payload(array $result): array
    {
        self::assertFalse($result['isError'] ?? false, json_encode($result, JSON_THROW_ON_ERROR));
        $payload = json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        /** @var array{variantId: string, dimension: array{bleedMm: float|int, bleedPx: int, cropMarks: bool}} $payload */
        return $payload;
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array{isError?: bool, content: list<array{text: string}>}
     */
    private function call(string $token, array $arguments): array
    {
        $browser = $this->browser();
        $sessionId = TestingMcpClient::connect($browser, $token);
        TestingMcpClient::request($browser, 'tools/call', ['name' => 'set_print_setup', 'arguments' => $arguments], $sessionId, $token);

        $response = $browser->getResponse();
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        /** @var array{result?: array{isError?: bool, content: list<array{text: string}>}} $payload */
        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('result', $payload, (string) $response->getContent());

        return $payload['result'];
    }

    private function browser(): KernelBrowser
    {
        return $this->browser ??= self::createClient();
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
