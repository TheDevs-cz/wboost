<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\Client;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use Symfony\Contracts\HttpClient\ResponseInterface;
use WBoost\Web\Entity\ExportEvent;
use WBoost\Web\Entity\TemplateDocumentExportVersion;
use WBoost\Web\Entity\TemplateExportVersion;
use WBoost\Web\Entity\User;
use WBoost\Web\Services\Editor\TemplateVariantImageRendererInterface;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\Fakes\FakeTemplateVariantImageRenderer;
use WBoost\Web\Tests\TemplateDocumentTestData;
use WBoost\Web\Tests\TestingApiAuthentication;

/**
 * A fill value that cannot be stored — the character U+0000, which a JSONB
 * column refuses (Postgres 22P05) — is a structured 400 `invalid_value` on
 * every render of the API, previews included, before anything is drawn.
 * Until 2026-11-14 such an export answered 200 with the picture while its
 * version was silently NOT recorded (the insert failed, the failure was
 * swallowed and logged).
 *
 * What never reaches the application is pinned here as well: an unpaired
 * surrogate escape, a raw control character and bytes that are not UTF-8
 * make the body not-JSON, and the request decoder answers 400 itself.
 * Other control characters are legitimate in a pasted text and are kept.
 *
 * @covers \WBoost\Web\Services\Template\FillValueGuard
 * @covers \WBoost\Web\Services\Template\ApiRepeatableRows
 * @covers \WBoost\Web\Api\Templates\ExportProcessor
 * @covers \WBoost\Web\Api\Templates\PreviewProcessor
 * @covers \WBoost\Web\Api\TemplateGroups\GroupExportProcessor
 * @covers \WBoost\Web\Api\TemplateGroups\GroupPreviewProcessor
 * @covers \WBoost\Web\Api\TemplateDocuments\TemplateDocumentRenderProcessor
 */
final class FillUnstorableCharactersApiTest extends ApiTestCase
{
    private const string HEADLINE = TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_INPUT_HEADLINE_ID;
    private const string VARIANT_URL = '/api/template-variants/' . TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID;
    private const string GROUP_URL = '/api/template-groups/' . TestDataFixture::TEMPLATE_GROUP_1_ID;

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function nulBodies(): iterable
    {
        yield 'a plain text' => ['{"inputs":{"' . self::HEADLINE . '":"a\u0000b"}}', 'inputs.' . self::HEADLINE];
        yield 'the value of an object' => ['{"inputs":{"' . self::HEADLINE . '":{"value":"a\u0000b"}}}', 'inputs.' . self::HEADLINE . '.value'];
        yield 'a rich run' => ['{"inputs":{"' . self::HEADLINE . '":{"runs":[{"text":"a\u0000b"}]}}}', 'inputs.' . self::HEADLINE . '.runs[0].text'];
        yield 'the key of an input nobody carries' => ['{"inputs":{"x\u0000y":"a"}}', 'inputs.x y'];
        yield 'an image id' => ['{"images":{"11111111-1111-4111-8111-111111111111":"\u0000"}}', 'images.11111111-1111-4111-8111-111111111111'];
        yield 'a shape colour' => ['{"shapeColors":{"11111111-1111-4111-8111-111111111111":"#fff\u0000"}}', 'shapeColors.11111111-1111-4111-8111-111111111111'];
    }

    #[DataProvider('nulBodies')]
    public function testVariantExportAndPreviewRefuseANulWithAStructured400(string $body, string $path): void
    {
        [$client, $token] = $this->authenticatedClient();
        $client->disableReboot();

        foreach (['/preview', '/export'] as $operation) {
            $response = $this->post($client, $token, self::VARIANT_URL . $operation, $body);

            self::assertSame(400, $response->getStatusCode(), $operation);
            $error = $response->toArray(false);
            self::assertSame('invalid_value', $error['code'] ?? null, $operation);
            self::assertIsString($error['error'] ?? null);
            self::assertStringContainsString($path . ' ', $error['error'], 'the message names where the character sits');
            self::assertStringContainsString('U+0000', $error['error']);
            self::assertStringNotContainsString("\0", $error['error']);
        }

        self::assertSame([], $this->renderer()->calls, 'refused before anything is drawn');
        self::assertSame(0, $this->countRows(TemplateExportVersion::class));
        self::assertSame(0, $this->countRows(ExportEvent::class));
    }

    public function testANulInARepeatableRowValueIsRefusedToo(): void
    {
        [$client, $token] = $this->authenticatedClient();

        // The id is no row of the variant — the character is found first, in
        // one walk over the whole body.
        $response = $this->post($client, $token, self::VARIANT_URL . '/export', '{"rows":{"11111111-1111-4111-8111-111111111111":[{"x":"a\u0000"}]}}');

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('invalid_value', $response->toArray(false)['code'] ?? null);
    }

    public function testGroupPreviewAndExportsRefuseANul(): void
    {
        [$client, $token] = $this->authenticatedClient();
        $client->disableReboot();

        $body = '{"inputs":{"' . TestDataFixture::GROUP_SHARED_INPUT_ID . '":"Letní\u0000kampaň"}}';

        foreach ([
            '/preview/' . TestDataFixture::GROUPED_FREEFORM_VARIANT_ID,
            '/export',
            '/export/' . TestDataFixture::GROUPED_FREEFORM_VARIANT_ID,
        ] as $operation) {
            $response = $this->post($client, $token, self::GROUP_URL . $operation, $body);

            self::assertSame(400, $response->getStatusCode(), $operation);
            self::assertSame('invalid_value', $response->toArray(false)['code'] ?? null, $operation);
        }

        self::assertSame([], $this->renderer()->calls);
        self::assertSame(0, $this->countRows(TemplateExportVersion::class));
        self::assertSame(0, $this->countRows(ExportEvent::class));
    }

    public function testDocumentPreviewAndExportRefuseANul(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = $em->find(User::class, Uuid::fromString(TestDataFixture::USER_1_ID));
        self::assertInstanceOf(User::class, $user);
        $user->changeRoles([User::ROLE_DESIGNER]);
        $em->flush();
        $token = TestingApiAuthentication::getAccessToken($client, TestDataFixture::OAUTH2_CLIENT_ID, TestDataFixture::OAUTH2_CLIENT_SECRET);

        $a = TemplateDocumentTestData::a4Template($em, 'Úvod');
        $document = $client->request('POST', '/api/projects/' . TestDataFixture::PROJECT_1_ID . '/template-documents', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
            'json' => ['name' => 'Nabídka', 'dimension' => ['unit' => 'mm', 'unitWidth' => 210, 'unitHeight' => 297], 'templateIds' => [$a['template']->toString()]],
        ])->toArray();
        self::assertIsString($document['id']);
        self::assertIsArray($document['pages']);
        self::assertIsArray($document['pages'][0]);
        $pageId = $document['pages'][0]['id'];
        self::assertIsString($pageId);

        $body = '{"pages":{"' . $pageId . '":{"inputs":{"' . TemplateDocumentTestData::SHARED_INPUT_ID . '":"a\u0000b"}}}}';

        foreach (['/preview/' . $pageId, '/export'] as $operation) {
            $response = $this->post($client, $token, '/api/template-documents/' . $document['id'] . $operation, $body);

            self::assertSame(400, $response->getStatusCode(), $operation);
            self::assertSame('invalid_value', $response->toArray(false)['code'] ?? null, $operation);
        }

        self::assertSame(0, $this->countRows(TemplateDocumentExportVersion::class));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notJsonBodies(): iterable
    {
        yield 'an unpaired surrogate escape' => ['{"inputs":{"' . self::HEADLINE . '":"a\ud800b"}}'];
        yield 'an unpaired surrogate escape in a key' => ['{"inputs":{"\udc00":"a"}}'];
        yield 'a raw NUL byte' => ['{"inputs":{"' . self::HEADLINE . '":"a' . "\0" . 'b"}}'];
        yield 'bytes that are not UTF-8' => ['{"inputs":{"' . self::HEADLINE . '":"a' . "\xff" . 'b"}}'];
    }

    /**
     * Not ours to refuse: such a body is not JSON, the request decoder
     * answers 400 (API Platform's problem body, no `code`) before any
     * processor runs.
     */
    #[DataProvider('notJsonBodies')]
    public function testABodyThatIsNotJsonIsA400OfTheDecoder(string $body): void
    {
        [$client, $token] = $this->authenticatedClient();
        $client->disableReboot();

        foreach ([self::VARIANT_URL . '/preview', self::VARIANT_URL . '/export', self::GROUP_URL . '/export'] as $url) {
            $response = $this->post($client, $token, $url, $body);

            self::assertSame(400, $response->getStatusCode(), $url);
            self::assertArrayNotHasKey('code', $response->toArray(false), $url);
        }

        self::assertSame([], $this->renderer()->calls);
        self::assertSame(0, $this->countRows(TemplateExportVersion::class));
    }

    /**
     * A vertical tab is an office suite's soft line break, U+0085 a next
     * line: pasted texts hold them, JSONB stores them — they stay.
     */
    public function testOtherControlCharactersAreKeptAndRecorded(): void
    {
        [$client, $token] = $this->authenticatedClient();
        $client->disableReboot();

        $response = $this->post($client, $token, self::VARIANT_URL . '/export', '{"inputs":{"' . self::HEADLINE . '":"a\u000bb\u0085c"}}');

        self::assertSame(200, $response->getStatusCode());

        $versions = self::getContainer()->get(EntityManagerInterface::class)->getRepository(TemplateExportVersion::class)->findAll();
        self::assertCount(1, $versions);
        self::assertSame("a\u{0B}b\u{85}c", $versions[0]->fillValues->texts[self::HEADLINE] ?? null);
    }

    /**
     * @return array{Client, string}
     */
    private function authenticatedClient(): array
    {
        $client = self::createClient();

        return [$client, TestingApiAuthentication::getAccessToken($client, TestDataFixture::OAUTH2_CLIENT_ID, TestDataFixture::OAUTH2_CLIENT_SECRET)];
    }

    private function post(Client $client, string $token, string $url, string $body): ResponseInterface
    {
        return $client->request('POST', $url, [
            'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
            'body' => $body,
        ]);
    }

    /**
     * @param class-string $entity
     */
    private function countRows(string $entity): int
    {
        return self::getContainer()->get(EntityManagerInterface::class)->getRepository($entity)->count([]);
    }

    private function renderer(): FakeTemplateVariantImageRenderer
    {
        $renderer = self::getContainer()->get(TemplateVariantImageRendererInterface::class);
        /** @phpstan-ignore staticMethod.impossibleType */
        self::assertInstanceOf(FakeTemplateVariantImageRenderer::class, $renderer);

        return $renderer;
    }
}
