<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Mcp\Tool;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use WBoost\Web\Entity\User;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\Mcp\TestingMcpClient;
use WBoost\Web\Tests\TemplateDocumentTestData;

/**
 * `find_templates` documents, `manage_document` and `export_document`.
 *
 * @covers \WBoost\Web\Mcp\Tool\ManageDocumentTool
 * @covers \WBoost\Web\Mcp\Tool\ExportDocumentTool
 * @covers \WBoost\Web\Mcp\Tool\FindTemplatesTool
 */
final class DocumentToolsTest extends WebTestCase
{
    private null|KernelBrowser $browser = null;

    public function testCreateArrangeFindAndExport(): void
    {
        $this->makeDesigner();
        $a = TemplateDocumentTestData::a4Template($this->em(), 'Úvod');
        $b = TemplateDocumentTestData::a4Template($this->em(), 'Ceník');

        $created = $this->ok('manage_document', [
            'action' => 'create',
            'projectId' => TestDataFixture::PROJECT_1_ID,
            'name' => 'Nabídka',
            'dimension' => ['unit' => 'mm', 'unitWidth' => 210, 'unitHeight' => 297],
            'templateIds' => [$a['template']->toString(), $b['template']->toString()],
        ]);
        self::assertSame('Nabídka', $created['name']);
        self::assertCount(2, $created['pages']);
        self::assertStringEndsWith('/template-document/' . $created['id'] . '/fill', $created['fillUrl']);

        $withPage = $this->ok('manage_document', ['action' => 'add_page', 'documentId' => $created['id'], 'name' => 'Kontakt', 'position' => 0]);
        self::assertSame('Kontakt', $withPage['pages'][0]['templateName']);
        self::assertTrue(Uuid::isValid($withPage['pages'][0]['variantId']), 'The blank page is designable with set_design.');

        $withField = $this->ok('manage_document', ['action' => 'set_fields', 'documentId' => $created['id'], 'fields' => [
            ['name' => 'Zákazník', 'kind' => 'text', 'bindings' => [
                ['pageId' => $created['pages'][0]['pageId'], 'inputId' => TemplateDocumentTestData::SHARED_INPUT_ID],
                ['pageId' => $created['pages'][1]['pageId'], 'inputId' => TemplateDocumentTestData::SHARED_INPUT_ID],
            ]],
        ]]);
        self::assertCount(2, $withField['fields'][0]['bindings']);

        $found = $this->ok('find_templates', ['projectId' => TestDataFixture::PROJECT_1_ID]);
        self::assertSame([$created['id']], array_column($found['documents'], 'id'));
        $templates = array_column($found['templates'], null, 'id');
        self::assertSame($created['id'], $templates[$a['template']->toString()]['documentId']);

        $export = $this->call('export_document', [
            'documentId' => $created['id'],
            'fields' => [$withField['fields'][0]['id'] => 'Lucie Svobodová'],
            'format' => 'pdf-screen',
        ]);
        self::assertFalse($export['isError'] ?? false, json_encode($export, JSON_THROW_ON_ERROR));
        $summary = json_decode($export['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($summary);
        self::assertSame(3, $summary['pageCount']);
        self::assertSame('application/pdf', $export['content'][1]['resource']['mimeType']);
        self::assertStringStartsWith('%PDF-1.4', (string) base64_decode($export['content'][1]['resource']['blob'], true));
    }

    public function testRefusalsAreToolErrors(): void
    {
        $this->makeDesigner();

        $grouped = $this->call('manage_document', [
            'action' => 'create',
            'projectId' => TestDataFixture::PROJECT_1_ID,
            'name' => 'X',
            'dimension' => ['unit' => 'mm', 'unitWidth' => 210, 'unitHeight' => 297],
            'templateIds' => [TestDataFixture::GROUPED_TEMPLATE_ID],
        ]);
        self::assertTrue($grouped['isError'] ?? false);
        self::assertStringContainsString('template_grouped', $grouped['content'][0]['text']);

        $unknown = $this->call('export_document', ['documentId' => Uuid::uuid7()->toString()]);
        self::assertTrue($unknown['isError'] ?? false);
    }

    private function makeDesigner(): void
    {
        $this->browser();
        $user = $this->em()->find(User::class, Uuid::fromString(TestDataFixture::USER_1_ID));
        self::assertInstanceOf(User::class, $user);
        $user->changeRoles([User::ROLE_DESIGNER]);
        $this->em()->flush();
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array{id: string, name: string, fillUrl: string, pages: list<array{pageId: string, templateName: string, variantId: string}>, fields: list<array{id: string, bindings: list<mixed>}>, documents: list<array{id: string}>, templates: list<array{id: string, documentId: null|string}>}
     */
    private function ok(string $tool, array $arguments): array
    {
        $result = $this->call($tool, $arguments);
        self::assertFalse($result['isError'] ?? false, json_encode($result, JSON_THROW_ON_ERROR));

        $payload = json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        $payload += ['id' => '', 'name' => '', 'fillUrl' => '', 'pages' => [], 'fields' => [], 'documents' => [], 'templates' => []];

        /** @var array{id: string, name: string, fillUrl: string, pages: list<array{pageId: string, templateName: string, variantId: string}>, fields: list<array{id: string, bindings: list<mixed>}>, documents: list<array{id: string}>, templates: list<array{id: string, documentId: null|string}>} $payload */
        return $payload;
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array{isError?: bool, content: list<array{text: string, resource: array{mimeType: string, blob: string}}>}
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
        self::assertIsArray($payload['result']);

        /** @var array{isError?: bool, content: list<array{text: string, resource: array{mimeType: string, blob: string}}>} $result */
        $result = $payload['result'];

        return $result;
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
