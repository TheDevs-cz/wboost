<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\Client;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\MessageBusInterface;
use WBoost\Web\Entity\User;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TemplateDocumentTestData;
use WBoost\Web\Tests\TestingApiAuthentication;

/**
 * Multi-page documents over the API: CRUD through the same messages as the
 * web (same invariants, structured 400s), the `document` ref on templates,
 * and the whole-document export in its three formats.
 *
 * @covers \WBoost\Web\Api\TemplateDocuments\TemplateDocumentsProvider
 * @covers \WBoost\Web\Api\TemplateDocuments\TemplateDocumentWriteProcessor
 * @covers \WBoost\Web\Api\TemplateDocuments\TemplateDocumentRenderProcessor
 * @covers \WBoost\Web\Api\TemplateDocuments\TemplateDocumentExportVersionsProvider
 * @covers \WBoost\Web\Api\TemplateDocuments\TemplateDocumentExportVersionProvider
 * @covers \WBoost\Web\Api\TemplateDocuments\TemplateDocumentExportVersionUpdateProcessor
 */
final class TemplateDocumentsApiTest extends ApiTestCase
{
    public function testCreateArrangeAndRead(): void
    {
        [$client, $headers] = $this->designerClient();
        $a = TemplateDocumentTestData::a4Template($this->em(), 'Úvod');
        $b = TemplateDocumentTestData::a4Template($this->em(), 'Ceník');

        $created = $client->request('POST', '/api/projects/' . TestDataFixture::PROJECT_1_ID . '/template-documents', [
            'headers' => $headers,
            'json' => [
                'name' => 'Cenová nabídka',
                'dimension' => ['unit' => 'mm', 'unitWidth' => 210, 'unitHeight' => 297, 'bleedMm' => 3, 'cropMarks' => true],
                'templateIds' => [$a['template']->toString(), $b['template']->toString()],
            ],
        ]);
        self::assertSame(201, $created->getStatusCode());
        $document = self::documentBody($created->toArray());
        self::assertSame('Cenová nabídka', $document['name']);
        self::assertTrue($document['printFormat']);
        self::assertCount(2, $document['pages']);
        self::assertSame($a['variant']->toString(), $document['pages'][0]['variant']['id']);
        self::assertSame(TemplateDocumentTestData::SHARED_INPUT_ID, $document['pages'][0]['variant']['inputs'][0]['id']);
        $documentId = $document['id'];
        [$pageA, $pageB] = [$document['pages'][0]['id'], $document['pages'][1]['id']];

        // Reorder + shared field.
        $client->request('PUT', '/api/template-documents/' . $documentId . '/pages', ['headers' => $headers, 'json' => ['templateIds' => [$b['template']->toString(), $a['template']->toString()]]]);
        self::assertResponseIsSuccessful();
        $fields = $client->request('PUT', '/api/template-documents/' . $documentId . '/fields', ['headers' => $headers, 'json' => ['fields' => [
            ['name' => 'Zákazník', 'kind' => 'text', 'bindings' => [['pageId' => $pageA, 'inputId' => TemplateDocumentTestData::SHARED_INPUT_ID], ['pageId' => $pageB, 'inputId' => TemplateDocumentTestData::SHARED_INPUT_ID]]],
        ]]])->toArray();
        $fields = self::documentBody($fields);
        self::assertSame('Ceník', $fields['pages'][0]['templateName']);
        self::assertCount(2, $fields['fields'][0]['bindings']);
        self::assertTrue($fields['fields'][0]['bindings'][0]['valid']);

        // Listing + the document ref on the templates listing.
        $list = $client->request('GET', '/api/projects/' . TestDataFixture::PROJECT_1_ID . '/template-documents', ['headers' => $headers])->toArray();
        self::assertSame([$documentId], array_column($list, 'id'));

        /** @var list<array{id: string, document: null|array{id: string, position: int}}> $templates */
        $templates = $client->request('GET', '/api/projects/' . TestDataFixture::PROJECT_1_ID . '/templates', ['headers' => $headers])->toArray();
        $byId = array_column($templates, null, 'id');
        $ref = $byId[$a['template']->toString()]['document'];
        self::assertNotNull($ref);
        self::assertSame($documentId, $ref['id']);
        self::assertSame(1, $ref['position']);
        self::assertNull($byId[TestDataFixture::CUSTOM_TEMPLATE_1_ID]['document']);

        // Add a blank page, rename, then dissolve.
        $added = self::documentBody($client->request('POST', '/api/template-documents/' . $documentId . '/pages', ['headers' => $headers, 'json' => ['name' => 'Kontakt']])->toArray());
        self::assertSame('Kontakt', $added['pages'][2]['templateName']);

        $renamed = self::documentBody($client->request('PATCH', '/api/template-documents/' . $documentId, ['headers' => ['Content-Type' => 'application/merge-patch+json'] + $headers, 'json' => ['name' => 'Nabídka 2026']])->toArray());
        self::assertSame('Nabídka 2026', $renamed['name']);

        self::assertSame(204, $client->request('DELETE', '/api/template-documents/' . $documentId, ['headers' => $headers])->getStatusCode());
        self::assertSame(404, $client->request('GET', '/api/template-documents/' . $documentId, ['headers' => $headers])->getStatusCode());
    }

    public function testInvariantRefusalsAreStructured(): void
    {
        [$client, $headers] = $this->designerClient();

        $response = $client->request('POST', '/api/projects/' . TestDataFixture::PROJECT_1_ID . '/template-documents', [
            'headers' => $headers,
            'json' => [
                'name' => 'Skupina nejde',
                'dimension' => ['unit' => 'mm', 'unitWidth' => 210, 'unitHeight' => 297],
                'templateIds' => [TestDataFixture::GROUPED_TEMPLATE_ID],
            ],
        ]);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('template_grouped', $response->toArray(false)['code']);
        self::assertSame(TestDataFixture::GROUPED_TEMPLATE_ID, $response->toArray(false)['templateId']);
    }

    public function testExportWithSharedFieldAndPerPageValues(): void
    {
        [$client, $headers] = $this->designerClient();
        $a = TemplateDocumentTestData::a4Template($this->em(), 'Úvod');
        $b = TemplateDocumentTestData::a4Template($this->em(), 'Ceník');
        $document = self::documentBody($client->request('POST', '/api/projects/' . TestDataFixture::PROJECT_1_ID . '/template-documents', [
            'headers' => $headers,
            'json' => ['name' => 'Nabídka', 'dimension' => ['unit' => 'mm', 'unitWidth' => 210, 'unitHeight' => 297], 'templateIds' => [$a['template']->toString(), $b['template']->toString()]],
        ])->toArray());

        foreach (['pdf' => '%PDF-1.3', 'pdf-screen' => '%PDF-1.4', 'png' => 'PK'] as $format => $magic) {
            $response = $client->request('POST', '/api/template-documents/' . $document['id'] . '/export', [
                'headers' => $headers,
                'json' => [
                    'format' => $format,
                    'pages' => [$document['pages'][0]['id'] => ['inputs' => [TemplateDocumentTestData::SHARED_INPUT_ID => 'Jen první strana']]],
                ],
            ]);
            self::assertSame(200, $response->getStatusCode(), $format);
            self::assertStringStartsWith($magic, $response->getContent(), $format);
        }

        $response = $client->request('POST', '/api/template-documents/' . $document['id'] . '/export', ['headers' => $headers, 'json' => ['format' => 'tiff']]);
        self::assertSame('invalid_format', $response->toArray(false)['code']);

        $preview = $client->request('POST', '/api/template-documents/' . $document['id'] . '/preview/' . Uuid::uuid7()->toString(), ['headers' => $headers, 'json' => []]);
        self::assertSame(404, $preview->getStatusCode(), 'A page of no document.');
    }

    public function testExportHistoryListsLoadsAndCurates(): void
    {
        [$client, $headers] = $this->designerClient();
        $a = TemplateDocumentTestData::a4Template($this->em(), 'Úvod');
        $b = TemplateDocumentTestData::a4Template($this->em(), 'Ceník');
        $document = self::documentBody($client->request('POST', '/api/projects/' . TestDataFixture::PROJECT_1_ID . '/template-documents', [
            'headers' => $headers,
            'json' => ['name' => 'Nabídka', 'dimension' => ['unit' => 'mm', 'unitWidth' => 210, 'unitHeight' => 297], 'templateIds' => [$a['template']->toString(), $b['template']->toString()]],
        ])->toArray());
        [$pageA, $pageB] = [$document['pages'][0]['id'], $document['pages'][1]['id']];
        $withField = self::documentBody($client->request('PUT', '/api/template-documents/' . $document['id'] . '/fields', ['headers' => $headers, 'json' => ['fields' => [
            ['name' => 'Zákazník', 'kind' => 'text', 'bindings' => [['pageId' => $pageA, 'inputId' => TemplateDocumentTestData::SHARED_INPUT_ID], ['pageId' => $pageB, 'inputId' => TemplateDocumentTestData::SHARED_INPUT_ID]]],
        ]]])->toArray());
        $field = $withField['fields'][0];
        self::assertFalse($field['richText'], 'The fixture inputs are plain.');
        self::assertNull($field['allowedDirectoryIds'], 'Text fields carry no folders.');

        $export = $client->request('POST', '/api/template-documents/' . $document['id'] . '/export', ['headers' => $headers, 'json' => [
            'format' => 'png',
            'fields' => [$field['id'] => 'Lucie Svobodová'],
            'pages' => [$pageB => ['inputs' => [TemplateDocumentTestData::SHARED_INPUT_ID => 'Přebito sdíleným polem']]],
        ]]);
        self::assertSame(200, $export->getStatusCode());

        /** @var list<array{id: string, draft: bool, channel: string, pinned: bool, summary: array{texts: list<array{value: string}>}}> $versions */
        $versions = $client->request('GET', '/api/template-documents/' . $document['id'] . '/export-versions', ['headers' => $headers])->toArray();
        self::assertCount(1, $versions);
        self::assertSame('api', $versions[0]['channel']);
        self::assertFalse($versions[0]['draft']);
        self::assertSame(['Lucie Svobodová'], array_values(array_unique(array_column($versions[0]['summary']['texts'], 'value'))));

        /** @var array{fill: array{fields: array<string, mixed>, pages: array<string, array{inputs: array<string, mixed>}>}} $detail */
        $detail = $client->request('GET', '/api/template-document-export-versions/' . $versions[0]['id'], ['headers' => $headers])->toArray();
        self::assertSame('Lucie Svobodová', $detail['fill']['fields'][$field['id']]);
        self::assertSame('Lucie Svobodová', $detail['fill']['pages'][$pageB]['inputs'][TemplateDocumentTestData::SHARED_INPUT_ID]);

        /** @var array{name: null|string, pinned: bool} $patched */
        $patched = $client->request('PATCH', '/api/template-document-export-versions/' . $versions[0]['id'], [
            'headers' => ['Content-Type' => 'application/json'] + $headers,
            'body' => (string) json_encode(['name' => 'Finální', 'pinned' => true]),
        ])->toArray();
        self::assertSame('Finální', $patched['name']);
        self::assertTrue($patched['pinned']);

        $empty = $client->request('PATCH', '/api/template-document-export-versions/' . $versions[0]['id'], [
            'headers' => ['Content-Type' => 'application/json'] + $headers,
            'body' => '{}',
        ]);
        self::assertSame(400, $empty->getStatusCode());
    }

    public function testNonDesignersMayExportButNotArrange(): void
    {
        $client = self::createClient();
        $token = TestingApiAuthentication::getAccessToken($client, TestDataFixture::OAUTH2_CLIENT_ID, TestDataFixture::OAUTH2_CLIENT_SECRET);
        $headers = ['Authorization' => 'Bearer ' . $token];
        $a = TemplateDocumentTestData::a4Template($this->em(), 'Úvod');
        $documentId = TemplateDocumentTestData::document($this->em(), self::getContainer()->get(MessageBusInterface::class), [$a['template']]);

        self::assertSame(403, $client->request('PUT', '/api/template-documents/' . $documentId . '/pages', ['headers' => $headers, 'json' => ['templateIds' => []]])->getStatusCode());

        self::assertSame(200, $client->request('POST', '/api/template-documents/' . $documentId . '/export', ['headers' => $headers, 'json' => ['format' => 'png']])->getStatusCode());
    }

    /**
     * USER_1 (owner of PROJECT_1, the OAuth fixture client's user) as a
     * designer — arranging documents is a designer tool.
     *
     * @return array{Client, array<string, string>}
     */
    private function designerClient(): array
    {
        $client = self::createClient();

        $user = $this->em()->find(User::class, Uuid::fromString(TestDataFixture::USER_1_ID));
        self::assertInstanceOf(User::class, $user);
        $user->changeRoles([User::ROLE_DESIGNER]);
        $this->em()->flush();

        return [$client, ['Authorization' => 'Bearer ' . TestingApiAuthentication::getAccessToken($client, TestDataFixture::OAUTH2_CLIENT_ID, TestDataFixture::OAUTH2_CLIENT_SECRET)]];
    }

    /**
     * @param array<mixed> $body
     * @return array{id: string, name: string, printFormat: bool, pages: list<array{id: string, templateName: string, variant: array{id: string, inputs: list<array{id: string}>}}>, fields: list<array{id: string, bindings: list<array{valid: bool}>, richText: bool, hidable: bool, allowedDirectoryIds: null|list<string>}>}
     */
    private static function documentBody(array $body): array
    {
        self::assertIsString($body['id'] ?? null);
        self::assertIsArray($body['pages'] ?? null);

        /** @var array{id: string, name: string, printFormat: bool, pages: list<array{id: string, templateName: string, variant: array{id: string, inputs: list<array{id: string}>}}>, fields: list<array{id: string, bindings: list<array{valid: bool}>, richText: bool, hidable: bool, allowedDirectoryIds: null|list<string>}>} $body */
        return $body;
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
