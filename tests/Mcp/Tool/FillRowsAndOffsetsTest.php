<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Mcp\Tool;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use WBoost\Web\Entity\TemplateDocument;
use WBoost\Web\Entity\TemplateExportVersion;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Repository\TemplateVariantRepository;
use WBoost\Web\Services\Editor\TemplateVariantImageRendererInterface;
use WBoost\Web\Services\Template\RepeatableRows;
use WBoost\Web\Tests\ContainerFixtures;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\Fakes\FakeTemplateVariantImageRenderer;
use WBoost\Web\Tests\Mcp\TestingMcpClient;
use WBoost\Web\Tests\TemplateDocumentTestData;

/**
 * The MCP halves of container-rows P5 and "Uživatel může posunout" M5:
 * `render_variant`, `export_variant` and `export_document` (per page) take
 * `rows` (repeatable rows — row container id → list of row objects keyed by
 * the template row's member ids, the length is the count) and `offsets`
 * (element id → `{x, y}`), both validated STRICTLY with the REST codes
 * surfaced as tool errors; exports record them in the version and
 * `document_history` hands them back in export_document's own shape (rows
 * FOLDED), which posts back as-is.
 *
 * Fixture: "Výsledky turnaje" with ONE designed row (Skóre 1 + Soupeř 1),
 * `repeat {max 5, min 1, default 2}`; the ROOT container may move ↕
 * (unlimited up, 300 down), the free headline ↔ (50 each way), and the row
 * member Soupeř 1 carries an inert allowance of its own.
 *
 * @covers \WBoost\Web\Mcp\Fill\VariantFill
 * @covers \WBoost\Web\Mcp\Fill\DocumentFillInput
 * @covers \WBoost\Web\Mcp\Tool\RenderVariantTool
 * @covers \WBoost\Web\Mcp\Tool\ExportVariantTool
 * @covers \WBoost\Web\Mcp\Tool\ExportDocumentTool
 * @covers \WBoost\Web\Mcp\Tool\DocumentHistoryTool
 */
final class FillRowsAndOffsetsTest extends WebTestCase
{
    private const string ROW = ContainerFixtures::TURNAJ_ROW_1;
    private const string ROOT = ContainerFixtures::TURNAJ_ROOT;
    private const string SKORE = ContainerFixtures::TURNAJ_SKORE_1;
    private const string SOUPER = ContainerFixtures::TURNAJ_SOUPER_1;
    private const string HEADLINE = ContainerFixtures::TURNAJ_HEADLINE;
    private const string VARIANT = TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID;
    private const string BAR = '77777777-7777-4777-8777-777777777777';

    private null|KernelBrowser $browser = null;

    // --- render_variant / export_variant ------------------------------------

    public function testRenderVariantRendersTheRowsAndTheMoves(): void
    {
        $this->makeRowsAndMoves(self::VARIANT);

        $result = $this->call('render_variant', [
            'variantId' => self::VARIANT,
            'rows' => [self::ROW => [
                [self::SKORE => '4 - 4', self::SOUPER => 'TJ Kelč'],
                [],
                [self::SOUPER => ['value' => 'FK Nový Jičín']],
            ]],
            'offsets' => [self::HEADLINE => ['x' => -50, 'y' => 0], self::ROOT => ['y' => 120.004]],
        ]);
        self::assertFalse($result['isError'], $result['text']);

        $renderer = $this->renderer();
        $call = $renderer->calls[count($renderer->calls) - 1];
        self::assertSame('TJ Kelč', $call['texts'][self::SOUPER] ?? null);
        self::assertSame('FK Nový Jičín', $call['texts'][self::slot(self::SOUPER, 2)] ?? null, 'row 3 lands on its slot id');
        $hides = $renderer->hideCalls[count($renderer->hideCalls) - 1];
        self::assertSame([self::ROW => 3], $hides['rowCounts'], 'the list length is the count');
        self::assertArrayHasKey(self::slot(self::SOUPER, 3), $hides['effectiveHidden'], 'rows past the count are hidden');
        self::assertSame(
            [self::HEADLINE => ['x' => -50.0, 'y' => 0.0], self::ROOT => ['x' => 0.0, 'y' => 120.0]],
            $renderer->offsetCalls[count($renderer->offsetCalls) - 1],
            'the moves are baked, canonical (rounded, sorted)',
        );
    }

    public function testExportVariantRecordsTheRowsAndTheMovesInTheVersion(): void
    {
        $this->makeRowsAndMoves(self::VARIANT);

        $result = $this->call('export_variant', [
            'variantId' => self::VARIANT,
            'inputs' => [self::HEADLINE => 'Finále'],
            'rows' => [self::ROW => [[self::SOUPER => 'A'], [], [self::SOUPER => 'C']]],
            'offsets' => [self::ROOT => ['x' => 0, 'y' => 40]],
        ]);
        self::assertFalse($result['isError'], $result['text']);

        $version = $this->variantVersion();
        self::assertNotNull($version);
        self::assertSame([self::ROW => 3], $version->fillValues->rows);
        self::assertSame('C', $version->fillValues->texts[self::slot(self::SOUPER, 2)] ?? null, 'stored under the slot ids, like the web fill');
        self::assertSame('Finále', $version->fillValues->texts[self::HEADLINE] ?? null);
        self::assertSame([self::VARIANT => [self::ROOT => ['x' => 0.0, 'y' => 40.0]]], $version->fillValues->offsets);
    }

    public function testWithoutRowsTheDefaultCountShowsAndAnUnmovedExportRecordsNoMoves(): void
    {
        $this->makeRowsAndMoves(self::VARIANT);

        $result = $this->call('export_variant', [
            'variantId' => self::VARIANT,
            'inputs' => [self::SOUPER => 'Jen první'],
            'offsets' => [self::HEADLINE => ['x' => 0, 'y' => 0]],
        ]);
        self::assertFalse($result['isError'], $result['text']);

        $version = $this->variantVersion();
        self::assertNotNull($version);
        self::assertSame([self::ROW => 2], $version->fillValues->rows, 'the default count, like the REST export');
        self::assertSame([], $version->fillValues->offsets, 'a zero move is the designed position');
    }

    /**
     * @return iterable<string, array{array<string, mixed>, list<string>}>
     */
    public static function refusals(): iterable
    {
        $soupers = static fn (int $count): array => array_fill(0, $count, [self::SOUPER => 'X']);

        yield 'rows: a container that is not a row' => [['rows' => [self::ROOT => [[]]]], ['(not_repeatable)', 'containerId: ' . self::ROOT]];
        yield 'rows: past max' => [['rows' => [self::ROW => $soupers(6)]], ['(too_many_rows)', 'count: 6', 'max: 5']];
        yield 'rows: below min' => [['rows' => [self::ROW => []]], ['(too_few_rows)', 'count: 0', 'min: 1']];
        yield 'rows: a key that is no member of the row' => [['rows' => [self::ROW => [[], [self::HEADLINE => 'X']]]], ['(unknown_row_input)', 'rowIndex: 1', 'inputId: ' . self::HEADLINE]];
        yield 'rows: row 1 also sent in inputs' => [['inputs' => [self::SOUPER => 'Y'], 'rows' => [self::ROW => $soupers(1)]], ['(ambiguous_row_value)', 'rowIndex: 0']];
        yield 'rows: a row list sent as an object' => [['rows' => [self::ROW => [self::SOUPER => 'X']]], ['(invalid_value)', 'containerId: ' . self::ROW]];
        yield 'rows: a too long value in row 2' => [['rows' => [self::ROW => [[], [self::SOUPER => str_repeat('x', 35)]]]], ['(value_too_long)', 'inputId: ' . self::SOUPER, 'rowContainerId: ' . self::ROW, 'rowIndex: 1']];
        yield 'offsets: past the limit' => [['offsets' => [self::HEADLINE => ['x' => 60, 'y' => 0]]], ['(offset_out_of_range)', 'elementId: ' . self::HEADLINE, 'axis: x', 'max: 50', 'value: 60']];
        yield 'offsets: a disallowed axis' => [['offsets' => [self::ROOT => ['x' => 10, 'y' => 0]]], ['(move_not_allowed)', 'reason: not_allowed', 'axis: x']];
        yield 'offsets: a container member' => [['offsets' => [self::SOUPER => ['x' => 0, 'y' => 10]]], ['(move_not_allowed)', 'reason: in_container']];
        yield 'offsets: an unknown id' => [['offsets' => ['no-such-element' => ['x' => 0, 'y' => 10]]], ['(unknown_element)', 'elementId: no-such-element']];
        yield 'offsets: a malformed value' => [['offsets' => [self::HEADLINE => ['x' => 'abc', 'y' => 0]]], ['(invalid_value)']];
    }

    /**
     * Both tools refuse identically — the codes and context of the REST 400
     * body, in the tool error's text — and a refused export records nothing.
     *
     * @param array<string, mixed> $arguments
     * @param list<string> $expected
     */
    #[DataProvider('refusals')]
    public function testInvalidRowsAndMovesAreToolErrorsWithTheRestCodes(array $arguments, array $expected): void
    {
        $this->makeRowsAndMoves(self::VARIANT);

        foreach (['render_variant', 'export_variant'] as $tool) {
            $result = $this->call($tool, ['variantId' => self::VARIANT] + $arguments);
            self::assertTrue($result['isError'], sprintf('%s accepted it: %s', $tool, $result['text']));
            foreach ($expected as $fragment) {
                self::assertStringContainsString($fragment, $result['text'], $tool);
            }
        }

        self::assertNull($this->variantVersion(), 'a refused export records nothing');
    }

    public function testTheToolsAdvertiseRowsAndOffsets(): void
    {
        $browser = $this->browser();
        $sessionId = TestingMcpClient::connect($browser, TestDataFixture::MCP_TOKEN_ACTIVE);
        TestingMcpClient::request($browser, 'tools/list', [], $sessionId, TestDataFixture::MCP_TOKEN_ACTIVE);

        /** @var array{result?: array{tools?: list<array{name: string, description: string, inputSchema: array{properties: array<string, mixed>}}>}} $payload */
        $payload = json_decode((string) $browser->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $tools = array_column($payload['result']['tools'] ?? [], null, 'name');

        foreach (['render_variant', 'export_variant'] as $name) {
            self::assertArrayHasKey($name, $tools);
            self::assertArrayHasKey('rows', $tools[$name]['inputSchema']['properties'], $name);
            self::assertArrayHasKey('offsets', $tools[$name]['inputSchema']['properties'], $name);
        }
        self::assertStringContainsString('movableElements', $tools['render_variant']['description']);
        self::assertStringContainsString('repeatableRows', $tools['describe_variant']['description'] ?? '');
    }

    // --- documents ----------------------------------------------------------

    public function testDocumentRowsAndMovesArePerPageAndTheHistoryRoundTrips(): void
    {
        $em = $this->em();
        $a = TemplateDocumentTestData::a4Template($em, 'Strana A');
        $b = TemplateDocumentTestData::a4Template($em, 'Strana B');
        foreach ([$a, $b] as $page) {
            $this->makeRowsAndMoves($page['variant']->toString());
            $this->addMovableBar($page['variant']->toString());
        }
        $documentId = TemplateDocumentTestData::document($em, self::getContainer()->get(MessageBusInterface::class), [$a['template'], $b['template']])->toString();
        $document = $this->em()->find(TemplateDocument::class, Uuid::fromString($documentId));
        self::assertInstanceOf(TemplateDocument::class, $document);
        [$pageA, $pageB] = [$document->pages()[0]->id->toString(), $document->pages()[1]->id->toString()];

        // Refusals name the page.
        $tooMany = $this->call('export_document', ['documentId' => $documentId, 'format' => 'png', 'pages' => [
            $pageB => ['rows' => [self::ROW => array_fill(0, 6, [])]],
        ]]);
        self::assertTrue($tooMany['isError']);
        self::assertStringContainsString('(too_many_rows)', $tooMany['text']);
        self::assertStringContainsString($pageB, $tooMany['text']);
        $tooFar = $this->call('export_document', ['documentId' => $documentId, 'format' => 'png', 'pages' => [
            $pageB => ['offsets' => [self::BAR => ['x' => 0, 'y' => 51]]],
        ]]);
        self::assertTrue($tooFar['isError']);
        self::assertStringContainsString('(offset_out_of_range)', $tooFar['text']);
        self::assertStringContainsString($pageB, $tooFar['text']);

        $rowsB = [[self::SOUPER => 'Jedna'], [self::SOUPER => 'Dvě'], [self::SOUPER => 'Tři']];
        $first = $this->call('export_document', ['documentId' => $documentId, 'format' => 'png', 'pages' => [
            $pageB => ['rows' => [self::ROW => $rowsB], 'offsets' => [self::BAR => ['x' => 0, 'y' => 40]]],
        ]]);
        self::assertFalse($first['isError'], $first['text']);

        $list = $this->history(['action' => 'list', 'documentId' => $documentId]);
        self::assertCount(1, $list['versions']);
        $versionId = $list['versions'][0]['id'];

        $fill = $this->versionFill($documentId, $versionId);
        self::assertSame([self::ROW => $rowsB], $fill['pages'][$pageB]['rows'] ?? null, 'rows come back folded');
        self::assertEquals([self::BAR => ['x' => 0, 'y' => 40]], $fill['pages'][$pageB]['offsets'] ?? null);
        self::assertSame([self::ROW => [[], []]], $fill['pages'][$pageA]['rows'] ?? null, 'page A shows its default two rows');
        self::assertSame([], $fill['pages'][$pageA]['offsets'] ?? null);
        self::assertArrayNotHasKey(self::slot(self::SOUPER, 2), $fill['pages'][$pageB]['inputs'] ?? [], 'row values are not repeated under slot ids');

        // The stored fill posts back as-is: re-exporting the version with
        // nothing changed is the SAME fill (deduplicated, not a new version).
        $again = $this->call('export_document', ['documentId' => $documentId, 'format' => 'png', 'versionId' => $versionId]);
        self::assertFalse($again['isError'], $again['text']);
        self::assertCount(1, $this->history(['action' => 'list', 'documentId' => $documentId])['versions']);

        // Overrides win per row container (the whole list) and per element;
        // what is not overridden keeps the stored value.
        $changed = $this->call('export_document', ['documentId' => $documentId, 'format' => 'png', 'versionId' => $versionId, 'pages' => [
            $pageA => ['rows' => [self::ROW => [[self::SOUPER => 'Áčko']]], 'offsets' => [self::BAR => ['x' => 0, 'y' => 10]]],
        ]]);
        self::assertFalse($changed['isError'], $changed['text']);
        $list = $this->history(['action' => 'list', 'documentId' => $documentId]);
        self::assertCount(2, $list['versions']);
        $latestId = $list['versions'][0]['id'] === $versionId ? $list['versions'][1]['id'] : $list['versions'][0]['id'];
        $latest = $this->versionFill($documentId, $latestId);
        self::assertSame([self::ROW => [[self::SOUPER => 'Áčko']]], $latest['pages'][$pageA]['rows'] ?? null);
        self::assertEquals([self::BAR => ['x' => 0, 'y' => 10]], $latest['pages'][$pageA]['offsets'] ?? null);
        self::assertSame([self::ROW => $rowsB], $latest['pages'][$pageB]['rows'] ?? null, 'page B kept its stored rows');
        self::assertEquals([self::BAR => ['x' => 0, 'y' => 40]], $latest['pages'][$pageB]['offsets'] ?? null);
    }

    public function testADraftStoresRowsAndMoves(): void
    {
        $em = $this->em();
        $a = TemplateDocumentTestData::a4Template($em, 'Strana A');
        $this->makeRowsAndMoves($a['variant']->toString());
        $this->addMovableBar($a['variant']->toString());
        $documentId = TemplateDocumentTestData::document($em, self::getContainer()->get(MessageBusInterface::class), [$a['template']])->toString();
        $document = $this->em()->find(TemplateDocument::class, Uuid::fromString($documentId));
        self::assertInstanceOf(TemplateDocument::class, $document);
        $pageA = $document->pages()[0]->id->toString();

        $refused = $this->call('document_history', ['action' => 'save_draft', 'documentId' => $documentId, 'pages' => [
            $pageA => ['rows' => [self::ROW => array_fill(0, 9, [])]],
        ]]);
        self::assertTrue($refused['isError'], 'rows must be translated to be stored — refused like an export');
        self::assertStringContainsString('(too_many_rows)', $refused['text']);

        $rows = [[], [self::SOUPER => 'Druhý'], [], []];
        $draft = $this->history(['action' => 'save_draft', 'documentId' => $documentId, 'pages' => [
            $pageA => ['rows' => [self::ROW => $rows], 'offsets' => [self::BAR => ['x' => 0, 'y' => 12.5]]],
        ]]);
        self::assertNotNull($draft['version']);
        self::assertTrue($draft['version']['draft']);

        $fill = $this->versionFill($documentId, $draft['version']['id']);
        self::assertSame([self::ROW => $rows], $fill['pages'][$pageA]['rows'] ?? null);
        self::assertEquals([self::BAR => ['x' => 0, 'y' => 12.5]], $fill['pages'][$pageA]['offsets'] ?? null);
    }

    // --- helpers -----------------------------------------------------------

    /**
     * The repeatable "Výsledky turnaje" design with a movable root (↕,
     * unlimited up, 300 down) and headline (↔ 50 / 50); the member Soupeř 1
     * carries an (inert) allowance of its own.
     */
    private function makeRowsAndMoves(string $variantId): void
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
        $this->em()->flush();
    }

    /**
     * A plain decorative bar that may move ↕ up to 50 px down.
     */
    private function addMovableBar(string $variantId): void
    {
        $variant = $this->variant($variantId);
        $canvas = json_decode($variant->canvas, true);
        self::assertIsArray($canvas);
        self::assertIsArray($canvas['objects']);
        $canvas['objects'][] = [
            'type' => 'Rect', 'left' => 100, 'top' => 1200, 'width' => 400, 'height' => 40,
            'originX' => 'left', 'originY' => 'top', 'fill' => '#112233', 'inputId' => self::BAR, 'name' => 'Pruh',
            'userMove' => ['vertical' => true, 'horizontal' => false, 'maxUp' => null, 'maxDown' => 50, 'maxLeft' => null, 'maxRight' => null],
        ];

        $variant->editCanvas((string) json_encode($canvas), $variant->inputs, $variant->previewImagePath, $variant->imageInputs);
        $this->em()->flush();
    }

    private static function slot(string $id, int $row): string
    {
        return RepeatableRows::slotId(self::ROW, $id, $row);
    }

    /**
     * The tool outcome: a tool error is an HTTP 200 JSON-RPC RESULT carrying
     * `isError: true` and the message in its first text block.
     *
     * @param array<string, mixed> $arguments
     * @return array{isError: bool, text: string}
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
        self::assertIsArray($payload['result']['content']);
        self::assertIsArray($payload['result']['content'][0]);
        $text = $payload['result']['content'][0]['text'] ?? '';
        self::assertIsString($text);

        return ['isError' => ($payload['result']['isError'] ?? false) === true, 'text' => $text];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array{versions: list<array{id: string}>, version: null|array{id: string, draft: bool}}
     */
    private function history(array $arguments): array
    {
        $result = $this->call('document_history', $arguments);
        self::assertFalse($result['isError'], $result['text']);
        $payload = json_decode($result['text'], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        /** @var array{versions: list<array{id: string}>, version: null|array{id: string, draft: bool}} $payload */
        return $payload;
    }

    /**
     * A document version's `fill` as `document_history` "get" answers it.
     *
     * @return array{pages: array<string, array{inputs?: array<string, mixed>, rows?: mixed, offsets?: mixed}>}
     */
    private function versionFill(string $documentId, string $versionId): array
    {
        $result = $this->call('document_history', ['action' => 'get', 'documentId' => $documentId, 'versionId' => $versionId]);
        self::assertFalse($result['isError'], $result['text']);
        $payload = json_decode($result['text'], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertIsArray($payload['version']);
        self::assertIsArray($payload['version']['fill']);
        self::assertIsArray($payload['version']['fill']['pages']);

        /** @var array{pages: array<string, array{inputs?: array<string, mixed>, rows?: mixed, offsets?: mixed}>} $fill */
        $fill = $payload['version']['fill'];

        return $fill;
    }

    private function variantVersion(): null|TemplateExportVersion
    {
        $em = $this->em();
        $em->clear();

        return $em->getRepository(TemplateExportVersion::class)->findOneBy(['variant' => self::VARIANT]);
    }

    private function variant(string $variantId): TemplateVariant
    {
        return self::getContainer()->get(TemplateVariantRepository::class)->get(Uuid::fromString($variantId));
    }

    private function renderer(): FakeTemplateVariantImageRenderer
    {
        $renderer = self::getContainer()->get(TemplateVariantImageRendererInterface::class);
        /** @phpstan-ignore staticMethod.impossibleType */
        self::assertInstanceOf(FakeTemplateVariantImageRenderer::class, $renderer);

        return $renderer;
    }

    private function browser(): KernelBrowser
    {
        return $this->browser ??= self::createClient();
    }

    private function em(): EntityManagerInterface
    {
        $this->browser();

        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
