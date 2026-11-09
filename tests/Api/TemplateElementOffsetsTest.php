<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\Client;
use PHPUnit\Framework\Attributes\DataProvider;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use WBoost\Web\Entity\TemplateDocument;
use WBoost\Web\Entity\TemplateDocumentExportVersion;
use WBoost\Web\Entity\TemplateExportVersion;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Repository\TemplateVariantRepository;
use WBoost\Web\Services\Editor\TemplateVariantImageRendererInterface;
use WBoost\Web\Services\SocialNetwork\ResolveElementOffsets;
use WBoost\Web\Services\Template\RepeatableRows;
use WBoost\Web\Tests\ContainerFixtures;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\Fakes\FakeTemplateVariantImageRenderer;
use WBoost\Web\Tests\TemplateDocumentTestData;
use WBoost\Web\Tests\TestingApiAuthentication;

/**
 * "Uživatel může posunout", phase M5 — the user's element moves over the
 * REST API: the listing describes the movable elements
 * (`variants[].movableElements[]`, the fill page's rows), every render body
 * takes `offsets` (variant: element id → {x, y}; group: per dimension;
 * document: per page) validated STRICTLY with structured 400s, the moves
 * reach the renderer and come back in the export history's `fill.offsets`.
 *
 * Fixture: "Výsledky turnaje" with its repeatable row — the ROOT container
 * may move ↕ (unlimited up, max 300 down), the free headline ↔ (max 50 each
 * way); a member (Soupeř 1) carries an inert allowance of its own.
 *
 * @covers \WBoost\Web\Api\Templates\TemplatesProvider
 * @covers \WBoost\Web\Services\Template\ApiExportRenderer
 * @covers \WBoost\Web\Services\Template\ExportVersionApiView
 * @covers \WBoost\Web\Api\TemplateGroups\GroupExportProcessor
 * @covers \WBoost\Web\Api\TemplateDocuments\TemplateDocumentRenderProcessor
 */
final class TemplateElementOffsetsTest extends ApiTestCase
{
    private const string ROOT = ContainerFixtures::TURNAJ_ROOT;
    private const string HEADLINE = ContainerFixtures::TURNAJ_HEADLINE;
    private const string SOUPER = ContainerFixtures::TURNAJ_SOUPER_1;
    private const string VARIANT = TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID;
    private const string BAR = '77777777-7777-4777-8777-777777777777';

    public function testListingDescribesTheMovableElementsWithTheServersRange(): void
    {
        $client = self::createClient();
        $token = $this->token($client);
        $this->makeMovable(self::VARIANT);

        $rows = [];
        foreach ($this->listedVariant($client, $token, self::VARIANT) as $row) {
            $rows[$row['id']] = $row;
        }

        self::assertSame([self::ROOT, self::HEADLINE], array_values(array_intersect([self::ROOT, self::HEADLINE], array_keys($rows))));
        self::assertCount(2, $rows, 'members, nested rows and unmovable elements are not listed');

        $variant = $this->variant(self::VARIANT);
        $expected = ResolveElementOffsets::movableElementsOf(RepeatableRows::expand($variant));

        $headline = $rows[self::HEADLINE];
        self::assertSame('text', $headline['kind']);
        self::assertFalse($headline['vertical']);
        self::assertTrue($headline['horizontal']);
        self::assertEquals(['up' => null, 'down' => null, 'left' => 50, 'right' => 50], $headline['limits']);
        self::assertEquals(['minX' => -50, 'maxX' => 50, 'minY' => 0, 'maxY' => 0], $headline['range']);
        self::assertSame([], $headline['memberIds']);
        self::assertEqualsWithDelta($expected[self::HEADLINE]->frame['left'], $headline['frame']['x'], 1e-6);

        $root = $rows[self::ROOT];
        self::assertSame('container', $root['kind']);
        self::assertTrue($root['vertical']);
        self::assertFalse($root['horizontal']);
        self::assertEquals(['up' => null, 'down' => 300, 'left' => null, 'right' => null], $root['limits']);
        $bounds = $expected[self::ROOT]->bounds((float) $variant->dimension->width(), (float) $variant->dimension->height());
        self::assertEqualsWithDelta($bounds['minY'], $root['range']['minY'], 1e-6, 'unlimited up = up to the artwork edge');
        self::assertEquals(300, $root['range']['maxY']);
        self::assertContains(self::SOUPER, $root['memberIds']);
        self::assertEqualsWithDelta($expected[self::ROOT]->frame['top'], $root['frame']['y'], 1e-6);
    }

    public function testADesignWithoutMovesListsNone(): void
    {
        $client = self::createClient();
        $token = $this->token($client);

        self::assertSame([], $this->listedVariant($client, $token, TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID));
    }

    public function testExportBakesTheMovesAndRecordsThemInTheVersion(): void
    {
        $client = self::createClient();
        $token = $this->token($client);
        $this->makeMovable(self::VARIANT);

        $this->post($client, $token, '/api/template-variants/' . self::VARIANT . '/export', ['offsets' => [
            self::HEADLINE => ['x' => -50, 'y' => 0],
            self::ROOT => ['y' => 120.004],
        ]]);
        $this->assertResponseIsSuccessful();

        $expected = [self::HEADLINE => ['x' => -50.0, 'y' => 0.0], self::ROOT => ['x' => 0.0, 'y' => 120.0]];
        $calls = $this->renderer($client)->offsetCalls;
        self::assertSame($expected, $calls[count($calls) - 1]);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $version = $em->getRepository(TemplateExportVersion::class)->findOneBy(['variant' => self::VARIANT]);
        self::assertInstanceOf(TemplateExportVersion::class, $version);
        self::assertSame([self::VARIANT => $expected], $version->fillValues->offsets);

        $detail = $client->request('GET', '/api/export-versions/' . $version->id->toString(), [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ])->toArray();
        self::assertIsArray($detail['fill']);
        self::assertEquals($expected, $detail['fill']['offsets']);
    }

    public function testPreviewBakesTheMovesAndAnUnmovedExportKeepsAnEmptyMap(): void
    {
        $client = self::createClient();
        $token = $this->token($client);
        $this->makeMovable(self::VARIANT);

        $this->post($client, $token, '/api/template-variants/' . self::VARIANT . '/preview', ['offsets' => [self::HEADLINE => ['x' => 20, 'y' => 0]]]);
        $this->assertResponseIsSuccessful();
        $calls = $this->renderer($client)->offsetCalls;
        self::assertSame([self::HEADLINE => ['x' => 20.0, 'y' => 0.0]], $calls[count($calls) - 1]);

        // A zero move is the designed position — no offsets at all.
        $this->post($client, $token, '/api/template-variants/' . self::VARIANT . '/export', ['offsets' => [self::HEADLINE => ['x' => 0, 'y' => 0]]]);
        $this->assertResponseIsSuccessful();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $version = $em->getRepository(TemplateExportVersion::class)->findOneBy(['variant' => self::VARIANT]);
        self::assertInstanceOf(TemplateExportVersion::class, $version);
        self::assertSame([], $version->fillValues->offsets);
        self::assertArrayNotHasKey('offsets', $version->fillValues->toArray(), 'an unmoved fill hashes as before');

        $detail = $client->request('GET', '/api/export-versions/' . $version->id->toString(), [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);
        self::assertStringContainsString('"offsets":{}', $detail->getContent(), 'an empty map is a JSON object');
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, array<string, mixed>}>
     */
    public static function refusals(): iterable
    {
        yield 'past the limit' => [
            [self::HEADLINE => ['x' => 60, 'y' => 0]],
            'offset_out_of_range',
            ['elementId' => self::HEADLINE, 'axis' => 'x', 'min' => -50, 'max' => 50, 'value' => 60],
        ];
        yield 'past the artwork (unlimited up)' => [
            [self::ROOT => ['x' => 0, 'y' => -5000]],
            'offset_out_of_range',
            ['elementId' => self::ROOT, 'axis' => 'y', 'value' => -5000],
        ];
        yield 'a disallowed axis' => [
            [self::ROOT => ['x' => 10, 'y' => 0]],
            'move_not_allowed',
            ['elementId' => self::ROOT, 'reason' => 'not_allowed', 'axis' => 'x'],
        ];
        yield 'a container member' => [
            [self::SOUPER => ['x' => 0, 'y' => 10]],
            'move_not_allowed',
            ['elementId' => self::SOUPER, 'reason' => 'in_container'],
        ];
        yield 'an unknown id' => [
            ['no-such-element' => ['x' => 0, 'y' => 10]],
            'unknown_element',
            ['elementId' => 'no-such-element'],
        ];
        yield 'a malformed value' => [
            [self::HEADLINE => ['x' => 'abc', 'y' => 0]],
            'invalid_value',
            ['elementId' => self::HEADLINE],
        ];
    }

    /**
     * @param array<string, mixed> $offsets
     * @param array<string, mixed> $context
     */
    #[DataProvider('refusals')]
    public function testInvalidMovesAreStructured400s(array $offsets, string $code, array $context): void
    {
        $client = self::createClient();
        $token = $this->token($client);
        $this->makeMovable(self::VARIANT);

        foreach (['export', 'preview'] as $endpoint) {
            $body = $this->post($client, $token, '/api/template-variants/' . self::VARIANT . '/' . $endpoint, ['offsets' => $offsets])->toArray(false);
            $this->assertResponseStatusCodeSame(400);
            self::assertSame($code, $body['code'] ?? null, $endpoint);
            foreach ($context as $key => $value) {
                self::assertEquals($value, $body[$key] ?? null, sprintf('%s: %s', $endpoint, $key));
            }
        }

        self::assertSame([], self::getContainer()->get(EntityManagerInterface::class)->getRepository(TemplateExportVersion::class)->findBy(['variant' => self::VARIANT]), 'a refused export records nothing');
    }

    public function testGroupMovesArePerDimensionAndValidatedForEveryMember(): void
    {
        $client = self::createClient();
        $token = $this->token($client);
        $preset = TestDataFixture::GROUPED_PRESET_VARIANT_ID;
        $freeform = TestDataFixture::GROUPED_FREEFORM_VARIANT_ID;
        $this->makeMovable($preset);
        $this->makeMovable($freeform);
        $group = TestDataFixture::TEMPLATE_GROUP_1_ID;

        $offsets = [
            $preset => [self::ROOT => ['x' => 0, 'y' => 40]],
            $freeform => [self::ROOT => ['x' => 0, 'y' => 70]],
        ];

        foreach ([$preset => 40.0, $freeform => 70.0] as $variantId => $y) {
            $this->post($client, $token, sprintf('/api/template-groups/%s/preview/%s', $group, $variantId), ['offsets' => $offsets]);
            $this->assertResponseIsSuccessful();
            $calls = $this->renderer($client)->offsetCalls;
            self::assertSame([self::ROOT => ['x' => 0.0, 'y' => $y]], $calls[count($calls) - 1], 'each dimension bakes ITS OWN move');
        }

        // A single-dimension export still validates the other dimension's
        // moves — the recorded version keeps them all.
        $refused = $this->post($client, $token, sprintf('/api/template-groups/%s/export/%s', $group, $preset), ['offsets' => [
            $preset => [self::ROOT => ['x' => 0, 'y' => 40]],
            $freeform => [self::ROOT => ['x' => 0, 'y' => 999]],
        ]])->toArray(false);
        $this->assertResponseStatusCodeSame(400);
        self::assertSame('offset_out_of_range', $refused['code'] ?? null);
        self::assertSame($freeform, $refused['variantId'] ?? null);
        self::assertSame(self::ROOT, $refused['elementId'] ?? null);

        $malformed = $this->post($client, $token, sprintf('/api/template-groups/%s/preview/%s', $group, $preset), ['offsets' => [$preset => 'nahoru']])->toArray(false);
        $this->assertResponseStatusCodeSame(400);
        self::assertSame('invalid_value', $malformed['code'] ?? null);

        $this->post($client, $token, sprintf('/api/template-groups/%s/export', $group), ['offsets' => $offsets + [Uuid::uuid7()->toString() => [self::ROOT => ['x' => 0, 'y' => 1]]]]);
        $this->assertResponseIsSuccessful();

        $expected = [
            $preset => [self::ROOT => ['x' => 0.0, 'y' => 40.0]],
            $freeform => [self::ROOT => ['x' => 0.0, 'y' => 70.0]],
        ];
        ksort($expected);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $version = $em->getRepository(TemplateExportVersion::class)->findOneBy(['group' => $group]);
        self::assertInstanceOf(TemplateExportVersion::class, $version);
        self::assertSame($expected, $version->fillValues->offsets, 'a non-member key is ignored');

        $detail = $client->request('GET', '/api/export-versions/' . $version->id->toString(), [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ])->toArray();
        self::assertIsArray($detail['fill']);
        self::assertEquals($expected, $detail['fill']['offsets']);
    }

    public function testDocumentMovesArePerPage(): void
    {
        $client = self::createClient();
        $token = $this->token($client);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $a = TemplateDocumentTestData::a4Template($em, 'Strana A');
        $b = TemplateDocumentTestData::a4Template($em, 'Strana B');
        $this->addMovableBar($a['variant']->toString());
        $this->addMovableBar($b['variant']->toString());
        $documentId = TemplateDocumentTestData::document($em, self::getContainer()->get(MessageBusInterface::class), [$a['template'], $b['template']]);
        $document = $em->find(TemplateDocument::class, $documentId);
        self::assertInstanceOf(TemplateDocument::class, $document);
        [$pageA, $pageB] = [$document->pages()[0]->id->toString(), $document->pages()[1]->id->toString()];

        $this->post($client, $token, sprintf('/api/template-documents/%s/preview/%s', $documentId->toString(), $pageA), ['pages' => [
            $pageA => ['offsets' => [self::BAR => ['x' => 0, 'y' => 25]]],
            $pageB => ['offsets' => [self::BAR => ['x' => 0, 'y' => 40]]],
        ]]);
        $this->assertResponseIsSuccessful();
        $calls = $this->renderer($client)->offsetCalls;
        self::assertSame([self::BAR => ['x' => 0.0, 'y' => 25.0]], $calls[count($calls) - 1], 'the page bakes its own moves only');

        $refused = $this->post($client, $token, sprintf('/api/template-documents/%s/export', $documentId->toString()), ['format' => 'png', 'pages' => [
            $pageB => ['offsets' => [self::BAR => ['x' => 0, 'y' => 51]]],
        ]])->toArray(false);
        $this->assertResponseStatusCodeSame(400);
        self::assertSame('offset_out_of_range', $refused['code'] ?? null);
        self::assertSame($pageB, $refused['pageId'] ?? null);

        $this->post($client, $token, sprintf('/api/template-documents/%s/export', $documentId->toString()), ['format' => 'png', 'pages' => [
            $pageB => ['offsets' => [self::BAR => ['x' => 0, 'y' => 40]]],
        ]]);
        $this->assertResponseIsSuccessful();

        $version = $em->getRepository(TemplateDocumentExportVersion::class)->findOneBy(['document' => $documentId->toString()]);
        self::assertInstanceOf(TemplateDocumentExportVersion::class, $version);

        $detail = $client->request('GET', '/api/template-document-export-versions/' . $version->id->toString(), [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);
        $body = $detail->toArray();
        self::assertIsArray($body['fill']);
        self::assertIsArray($body['fill']['pages']);
        self::assertIsArray($body['fill']['pages'][$pageB]);
        self::assertEquals([self::BAR => ['x' => 0, 'y' => 40]], $body['fill']['pages'][$pageB]['offsets']);
        self::assertStringContainsString('"offsets":{}', $detail->getContent(), 'page A moved nothing — an empty JSON object');
    }

    // --- helpers -----------------------------------------------------------

    /**
     * The variant's `movableElements` rows.
     *
     * @return list<array{id: string, kind: string, name: null|string, frame: array{x: float|int, y: float|int, width: float|int, height: float|int}, vertical: bool, horizontal: bool, limits: array<string, null|float|int>, range: array{minX: float|int, maxX: float|int, minY: float|int, maxY: float|int}, memberIds: list<string>}>
     */
    private function listedVariant(Client $client, string $token, string $variantId): array
    {
        $body = $client->request('GET', '/api/projects/' . TestDataFixture::PROJECT_1_ID . '/templates', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ])->toArray();

        foreach ($body as $template) {
            self::assertIsArray($template);
            self::assertIsArray($template['variants']);
            foreach ($template['variants'] as $row) {
                self::assertIsArray($row);
                if ($row['id'] === $variantId) {
                    self::assertIsArray($row['movableElements']);

                    /** @var list<array{id: string, kind: string, name: null|string, frame: array{x: float|int, y: float|int, width: float|int, height: float|int}, vertical: bool, horizontal: bool, limits: array<string, null|float|int>, range: array{minX: float|int, maxX: float|int, minY: float|int, maxY: float|int}, memberIds: list<string>}> $elements */
                    $elements = $row['movableElements'];

                    return $elements;
                }
            }
        }

        self::fail('variant not listed');
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(Client $client, string $token, string $url, array $body): ResponseInterface
    {
        return $client->request('POST', $url, [
            'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
            'body' => json_encode($body, JSON_THROW_ON_ERROR),
        ]);
    }

    /**
     * The repeatable "Výsledky turnaje" design with a movable root (↕,
     * unlimited up, 300 down) and headline (↔ 50 / 50); the member Soupeř 1
     * carries an (inert) allowance of its own.
     */
    private function makeMovable(string $variantId): void
    {
        $raw = ContainerFixtures::turnajRepeatableRaw(max: 5, default: 2);
        /** @var list<array<string, mixed>> $containers */
        $containers = $raw['canvas']['containers'];
        foreach ($containers as $i => $container) {
            if ($container['id'] === self::ROOT) {
                $containers[$i]['userMove'] = ['vertical' => true, 'horizontal' => false, 'maxUp' => null, 'maxDown' => 300, 'maxLeft' => null, 'maxRight' => null];
            }
        }
        $raw['canvas']['containers'] = $containers;

        /** @var list<array<string, mixed>> $objects */
        $objects = $raw['canvas']['objects'];
        foreach ($objects as $i => $object) {
            if (($object['inputId'] ?? null) === self::HEADLINE) {
                $objects[$i]['userMove'] = ['vertical' => false, 'horizontal' => true, 'maxUp' => null, 'maxDown' => null, 'maxLeft' => 50, 'maxRight' => 50];
            }
            if (($object['inputId'] ?? null) === self::SOUPER) {
                $objects[$i]['userMove'] = ['vertical' => true, 'horizontal' => true, 'maxUp' => null, 'maxDown' => null, 'maxLeft' => null, 'maxRight' => null];
            }
        }
        $raw['canvas']['objects'] = $objects;

        ContainerFixtures::applyRaw($this->variant($variantId), $raw);
        self::getContainer()->get(EntityManagerInterface::class)->flush();
    }

    /**
     * A plain decorative bar on an A4 page that may move ↕ up to 50 px down.
     */
    private function addMovableBar(string $variantId): void
    {
        $variant = $this->variant($variantId);
        $canvas = json_decode($variant->canvas, true);
        self::assertIsArray($canvas);
        self::assertIsArray($canvas['objects']);
        $canvas['objects'][] = [
            'type' => 'Rect', 'left' => 100, 'top' => 600, 'width' => 400, 'height' => 40,
            'originX' => 'left', 'originY' => 'top', 'fill' => '#112233', 'inputId' => self::BAR, 'name' => 'Pruh',
            'userMove' => ['vertical' => true, 'horizontal' => false, 'maxUp' => null, 'maxDown' => 50, 'maxLeft' => null, 'maxRight' => null],
        ];

        $variant->editCanvas((string) json_encode($canvas), $variant->inputs, $variant->previewImagePath, $variant->imageInputs);
        self::getContainer()->get(EntityManagerInterface::class)->flush();
    }

    private function variant(string $variantId): TemplateVariant
    {
        return self::getContainer()->get(TemplateVariantRepository::class)->get(Uuid::fromString($variantId));
    }

    private function token(Client $client): string
    {
        return TestingApiAuthentication::getAccessToken($client, TestDataFixture::OAUTH2_CLIENT_ID, TestDataFixture::OAUTH2_CLIENT_SECRET);
    }

    private function renderer(Client $client): FakeTemplateVariantImageRenderer
    {
        $renderer = $client->getContainer()->get(TemplateVariantImageRendererInterface::class);
        /** @phpstan-ignore staticMethod.impossibleType */
        self::assertInstanceOf(FakeTemplateVariantImageRenderer::class, $renderer);

        return $renderer;
    }
}
