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
 * @covers \WBoost\Web\Mcp\Tool\DocumentHistoryTool
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

    public function testHistoryListsCuratesReExportsAndSavesDrafts(): void
    {
        $this->makeDesigner();
        $a = TemplateDocumentTestData::a4Template($this->em(), 'Úvod');
        $b = TemplateDocumentTestData::a4Template($this->em(), 'Ceník');
        $document = $this->ok('manage_document', [
            'action' => 'create',
            'projectId' => TestDataFixture::PROJECT_1_ID,
            'name' => 'Nabídka',
            'dimension' => ['unit' => 'mm', 'unitWidth' => 210, 'unitHeight' => 297],
            'templateIds' => [$a['template']->toString(), $b['template']->toString()],
        ]);
        [$pageA, $pageB] = [$document['pages'][0]['pageId'], $document['pages'][1]['pageId']];

        $first = $this->call('export_document', ['documentId' => $document['id'], 'format' => 'png', 'pages' => [
            $pageA => ['inputs' => [TemplateDocumentTestData::SHARED_INPUT_ID => 'Pro Novákovy']],
            $pageB => ['inputs' => [TemplateDocumentTestData::SHARED_INPUT_ID => 'Ceník 2026']],
        ]]);
        self::assertFalse($first['isError'] ?? false, json_encode($first, JSON_THROW_ON_ERROR));

        $list = $this->history(['action' => 'list', 'documentId' => $document['id']]);
        self::assertCount(1, $list['versions']);
        self::assertSame('mcp', $list['versions'][0]['channel']);
        $versionId = $list['versions'][0]['id'];

        $got = $this->history(['action' => 'get', 'documentId' => $document['id'], 'versionId' => $versionId]);
        self::assertNotNull($got['version']);
        self::assertSame('Pro Novákovy', $got['version']['fill']['pages'][$pageA]['inputs'][TemplateDocumentTestData::SHARED_INPUT_ID] ?? null);

        $renamed = $this->history(['action' => 'rename', 'documentId' => $document['id'], 'versionId' => $versionId, 'name' => 'Novákovi']);
        self::assertSame('Novákovi', $renamed['version']['name'] ?? null);
        $pinned = $this->history(['action' => 'pin', 'documentId' => $document['id'], 'versionId' => $versionId]);
        self::assertTrue($pinned['version']['pinned'] ?? false);

        // Re-export the version with ONE change: page B keeps its stored value.
        $again = $this->call('export_document', ['documentId' => $document['id'], 'format' => 'png', 'versionId' => $versionId, 'pages' => [
            $pageA => ['inputs' => [TemplateDocumentTestData::SHARED_INPUT_ID => 'Pro Svobodovy']],
        ]]);
        self::assertFalse($again['isError'] ?? false, json_encode($again, JSON_THROW_ON_ERROR));
        $list = $this->history(['action' => 'list', 'documentId' => $document['id']]);
        self::assertCount(2, $list['versions']);
        $latest = $this->history(['action' => 'get', 'documentId' => $document['id'], 'versionId' => $list['versions'][1]['id']]);
        self::assertSame('Pro Svobodovy', $latest['version']['fill']['pages'][$pageA]['inputs'][TemplateDocumentTestData::SHARED_INPUT_ID] ?? null);
        self::assertSame('Ceník 2026', $latest['version']['fill']['pages'][$pageB]['inputs'][TemplateDocumentTestData::SHARED_INPUT_ID] ?? null);

        // A draft: saved without rendering, overwritten in place by its own id.
        $draft = $this->history(['action' => 'save_draft', 'documentId' => $document['id'], 'pages' => [$pageA => ['inputs' => [TemplateDocumentTestData::SHARED_INPUT_ID => 'Rozepsáno']]]]);
        self::assertNotNull($draft['version']);
        self::assertTrue($draft['version']['draft']);
        $draftId = $draft['version']['id'];
        self::assertStringContainsString('version=' . $draftId, $draft['version']['loadUrl']);
        $overwritten = $this->history(['action' => 'save_draft', 'documentId' => $document['id'], 'versionId' => $draftId, 'pages' => [$pageA => ['inputs' => [TemplateDocumentTestData::SHARED_INPUT_ID => 'Dopsáno']]]]);
        self::assertSame($draftId, $overwritten['version']['id'] ?? null);
        self::assertCount(3, $this->history(['action' => 'list', 'documentId' => $document['id']])['versions']);

        $foreign = $this->call('document_history', ['action' => 'get', 'documentId' => $document['id'], 'versionId' => Uuid::uuid7()->toString()]);
        self::assertTrue($foreign['isError'] ?? false);
    }

    public function testDuplicatePageAndPrintSetup(): void
    {
        $this->makeDesigner();
        $a = TemplateDocumentTestData::a4Template($this->em(), 'Úvod');
        $document = $this->ok('manage_document', [
            'action' => 'create',
            'projectId' => TestDataFixture::PROJECT_1_ID,
            'name' => 'Leták',
            'dimension' => ['unit' => 'mm', 'unitWidth' => 210, 'unitHeight' => 297],
            'templateIds' => [$a['template']->toString()],
        ]);

        $duplicated = $this->ok('manage_document', ['action' => 'duplicate_page', 'documentId' => $document['id'], 'pageId' => $document['pages'][0]['pageId']]);
        self::assertCount(2, $duplicated['pages']);
        self::assertNotSame($duplicated['pages'][0]['variantId'], $duplicated['pages'][1]['variantId']);

        $printed = $this->ok('manage_document', ['action' => 'set_print', 'documentId' => $document['id'], 'bleedMm' => 3, 'cropMarks' => true]);
        self::assertNotNull($printed['dimension']);
        self::assertSame(3, (int) $printed['dimension']['bleedMm']);
        self::assertTrue($printed['dimension']['cropMarks']);

        $missing = $this->call('manage_document', ['action' => 'set_print', 'documentId' => $document['id']]);
        self::assertTrue($missing['isError'] ?? false);

        $categorized = $this->ok('manage_document', ['action' => 'rename', 'documentId' => $document['id'], 'categoryId' => TestDataFixture::TEMPLATE_CATEGORY_1_ID]);
        self::assertSame('Leták', $categorized['name'], 'An omitted name stays.');
        self::assertSame(TestDataFixture::TEMPLATE_CATEGORY_1_ID, $categorized['categoryId']);
        self::assertNull($this->ok('manage_document', ['action' => 'rename', 'documentId' => $document['id'], 'categoryId' => ''])['categoryId']);
        self::assertTrue($this->call('manage_document', ['action' => 'rename', 'documentId' => $document['id'], 'categoryId' => Uuid::uuid7()->toString()])['isError'] ?? false);
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array{versions: list<array{id: string, channel: string}>, version: null|array{id: string, name: null|string, pinned: bool, draft: bool, loadUrl: string, fill: null|array{pages: array<string, array{inputs: array<string, mixed>}>}}}
     */
    private function history(array $arguments): array
    {
        $result = $this->call('document_history', $arguments);
        self::assertFalse($result['isError'] ?? false, json_encode($result, JSON_THROW_ON_ERROR));
        $payload = json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        /** @var array{versions: list<array{id: string, channel: string}>, version: null|array{id: string, name: null|string, pinned: bool, draft: bool, loadUrl: string, fill: null|array{pages: array<string, array{inputs: array<string, mixed>}>}}} $payload */
        return $payload;
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
     * @return array{id: string, name: string, categoryId: null|string, fillUrl: string, pages: list<array{pageId: string, templateName: string, variantId: string}>, fields: list<array{id: string, bindings: list<mixed>}>, documents: list<array{id: string}>, templates: list<array{id: string, documentId: null|string}>, dimension: null|array{bleedMm: float|int, cropMarks: bool}}
     */
    private function ok(string $tool, array $arguments): array
    {
        $result = $this->call($tool, $arguments);
        self::assertFalse($result['isError'] ?? false, json_encode($result, JSON_THROW_ON_ERROR));

        $payload = json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        $payload += ['id' => '', 'name' => '', 'categoryId' => null, 'fillUrl' => '', 'pages' => [], 'fields' => [], 'documents' => [], 'templates' => [], 'dimension' => null];

        /** @var array{id: string, name: string, categoryId: null|string, fillUrl: string, pages: list<array{pageId: string, templateName: string, variantId: string}>, fields: list<array{id: string, bindings: list<mixed>}>, documents: list<array{id: string}>, templates: list<array{id: string, documentId: null|string}>, dimension: null|array{bleedMm: float|int, cropMarks: bool}} $payload */
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
