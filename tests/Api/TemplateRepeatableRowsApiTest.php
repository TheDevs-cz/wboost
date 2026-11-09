<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\Client;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use WBoost\Web\Entity\TemplateDocument;
use WBoost\Web\Entity\TemplateDocumentExportVersion;
use WBoost\Web\Entity\TemplateExportVersion;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Exceptions\ContainerOverflow;
use WBoost\Web\Repository\TemplateVariantRepository;
use WBoost\Web\Services\Editor\TemplateVariantImageRendererInterface;
use WBoost\Web\Services\Template\RepeatableRows;
use WBoost\Web\Tests\ContainerFixtures;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\Fakes\FakeTemplateVariantImageRenderer;
use WBoost\Web\Tests\TemplateDocumentTestData;
use WBoost\Web\Tests\TestingApiAuthentication;
use WBoost\Web\Value\CanvasContainer;

/**
 * Container-rows plan P5 (REST half) — repeatable rows over the API:
 *
 * - the listing describes every repeatable row (`variants[].repeatableRows[]`:
 *   limits, `fitsRows`, the template row's fillable members and the id of
 *   every member in every row) and `containers[].repeat` / `fitsRows`; a
 *   group template adds the shared `group.repeatableRows[]` (smallest fit);
 * - every render body takes `rows` (row id → list of row objects keyed by the
 *   TEMPLATE row's member ids, the length is the count) — variant, group
 *   (ONE shared count) and document pages — validated strictly;
 * - versions store the counts + the values under the rows' slot ids, and the
 *   history API answers them back as `fill.rows`.
 *
 * Fixture: "Výsledky turnaje" with ONE designed row (Skóre 1 + Soupeř 1 +
 * a crest image slot, the row hidable), `repeat {max 5, min 1, default 2}`.
 *
 * @covers \WBoost\Web\Services\Template\ApiRepeatableRows
 * @covers \WBoost\Web\Api\Templates\TemplatesProvider
 * @covers \WBoost\Web\Services\Template\ApiExportRenderer
 * @covers \WBoost\Web\Services\Template\ExportVersionApiView
 * @covers \WBoost\Web\Api\TemplateGroups\GroupExportProcessor
 * @covers \WBoost\Web\Api\TemplateDocuments\TemplateDocumentRenderProcessor
 */
final class TemplateRepeatableRowsApiTest extends ApiTestCase
{
    private const string ROW = ContainerFixtures::TURNAJ_ROW_1;
    private const string ROOT = ContainerFixtures::TURNAJ_ROOT;
    private const string SKORE = ContainerFixtures::TURNAJ_SKORE_1;
    private const string SOUPER = ContainerFixtures::TURNAJ_SOUPER_1;
    private const string HEADLINE = ContainerFixtures::TURNAJ_HEADLINE;
    private const string CREST = '0193a0c5-0000-7000-8000-0000000000e1';
    private const string VARIANT = TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID;
    private const string PNG_1X1_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkAAIAAAoAAv/lxKUAAAAASUVORK5CYII=';

    // --- listing -----------------------------------------------------------

    public function testListingDescribesTheRowItsMembersAndEveryRowsIds(): void
    {
        $client = self::createClient();
        $token = $this->token($client);
        $this->makeRows(self::VARIANT);

        $variant = $this->listedVariant($client, $token, self::VARIANT);

        self::assertIsArray($variant['repeatableRows']);
        self::assertCount(1, $variant['repeatableRows']);
        $row = $variant['repeatableRows'][0];
        self::assertIsArray($row);

        self::assertSame(self::ROW, $row['containerId']);
        self::assertSame(self::ROOT, $row['parentContainerId']);
        self::assertSame('Zápas', $row['name']);
        self::assertSame(['max' => 5, 'min' => 1, 'default' => 2], $row['repeat']);
        $fits = RepeatableRows::designedCapacity($this->variant(self::VARIANT), self::ROW);
        self::assertIsInt($fits);
        self::assertSame($fits, $row['fitsRows']);

        self::assertSame([
            ['id' => self::ROW, 'kind' => 'container', 'name' => 'Zápas'],
            ['id' => self::SKORE, 'kind' => 'text', 'name' => 'Skóre 1'],
            ['id' => self::SOUPER, 'kind' => 'text', 'name' => 'Soupeř 1'],
            ['id' => self::CREST, 'kind' => 'image', 'name' => 'Erb'],
        ], $row['members']);

        self::assertIsArray($row['slots']);
        self::assertCount(5, $row['slots'], 'one entry per row up to max');
        foreach ($row['slots'] as $index => $slot) {
            self::assertIsArray($slot);
            self::assertSame($index, $slot['index']);
            $expected = [];
            foreach ([self::ROW, self::SKORE, self::SOUPER, self::CREST] as $id) {
                $expected[$id] = RepeatableRows::slotId(self::ROW, $id, $index);
            }
            self::assertSame($expected[self::ROW], $slot['containerId']);
            self::assertEquals($expected, $slot['ids'], 'row ' . $index);
        }

        // The containers carry the row's limits like describe_variant does;
        // the listing stays design-time — only the template row's inputs.
        self::assertIsArray($variant['containers']);
        $containers = array_column($variant['containers'], null, 'id');
        self::assertIsArray($containers[self::ROW]);
        self::assertSame(['max' => 5, 'min' => 1, 'default' => 2], $containers[self::ROW]['repeat']);
        self::assertSame($fits, $containers[self::ROW]['fitsRows']);
        self::assertIsArray($containers[self::ROOT]);
        self::assertNull($containers[self::ROOT]['repeat']);
        self::assertNull($containers[self::ROOT]['fitsRows']);
        self::assertIsArray($variant['inputs']);
        $inputIds = array_column($variant['inputs'], 'id');
        self::assertContains(self::SOUPER, $inputIds);
        self::assertNotContains(RepeatableRows::slotId(self::ROW, self::SOUPER, 1), $inputIds, 'slot ids are never listed as inputs');
    }

    public function testADesignWithoutRowsListsNone(): void
    {
        $client = self::createClient();
        $token = $this->token($client);

        $variant = $this->listedVariant($client, $token, self::VARIANT);

        self::assertSame([], $variant['repeatableRows']);
    }

    public function testTheGroupListsOneSharedRowWithTheSmallestFit(): void
    {
        $client = self::createClient();
        $token = $this->token($client);
        $this->makeRows(TestDataFixture::GROUPED_PRESET_VARIANT_ID);
        $this->makeRows(TestDataFixture::GROUPED_FREEFORM_VARIANT_ID);

        $template = $this->listedTemplate($client, $token, TestDataFixture::GROUPED_TEMPLATE_ID);
        self::assertIsArray($template['group']);
        self::assertIsArray($template['group']['repeatableRows']);
        self::assertCount(1, $template['group']['repeatableRows']);
        $groupRow = $template['group']['repeatableRows'][0];
        self::assertIsArray($groupRow);
        self::assertSame(self::ROW, $groupRow['containerId']);
        self::assertSame(['max' => 5, 'min' => 1, 'default' => 2], $groupRow['repeat']);

        $fits = [];
        self::assertIsArray($template['variants']);
        foreach ($template['variants'] as $variant) {
            self::assertIsArray($variant);
            self::assertIsArray($variant['repeatableRows']);
            if ($variant['groupMember'] === true && isset($variant['repeatableRows'][0]) && is_array($variant['repeatableRows'][0])) {
                $fits[] = $variant['repeatableRows'][0]['fitsRows'];
            }
        }
        self::assertCount(2, $fits);
        self::assertSame(min($fits), $groupRow['fitsRows'], 'the smallest size decides');
    }

    // --- variant export / preview ------------------------------------------

    public function testExportRendersTheRowsAndTheHistoryAnswersThemAsRows(): void
    {
        $client = self::createClient();
        $token = $this->token($client);
        $this->makeRows(self::VARIANT);
        $this->writeTestImage('fixtures/in-allowed.png');

        $this->post($client, $token, '/api/template-variants/' . self::VARIANT . '/export', ['rows' => [self::ROW => [
            [self::SKORE => '4 - 4', self::SOUPER => 'TJ Kelč'],
            [],
            [self::SOUPER => ['value' => 'FK Nový Jičín'], self::CREST => TestDataFixture::FILE_IN_ALLOWED_ID],
        ]]]);
        $this->assertResponseIsSuccessful();

        $slot = static fn (string $id, int $row): string => RepeatableRows::slotId(self::ROW, $id, $row);
        $renderer = $this->renderer($client);
        $call = $renderer->calls[count($renderer->calls) - 1];
        $hides = $renderer->hideCalls[count($renderer->hideCalls) - 1];

        self::assertSame('TJ Kelč', $call['texts'][self::SOUPER] ?? null);
        self::assertSame('FK Nový Jičín', $call['texts'][$slot(self::SOUPER, 2)] ?? null);
        self::assertArrayNotHasKey($slot(self::SOUPER, 1), $call['texts'], 'an empty row object keeps the sample');
        self::assertArrayHasKey($slot(self::CREST, 2), $call['images'], 'row 3 picked its own crest');
        self::assertSame([self::ROW => 3], $hides['rowCounts']);
        self::assertArrayHasKey($slot(self::SOUPER, 3), $hides['effectiveHidden'], 'rows past the count are hidden');
        self::assertArrayNotHasKey($slot(self::SOUPER, 2), $hides['effectiveHidden']);

        $version = $this->variantVersion(self::VARIANT);
        self::assertSame([self::ROW => 3], $version->fillValues->rows);
        self::assertSame('FK Nový Jičín', $version->fillValues->texts[$slot(self::SOUPER, 2)] ?? null, 'stored under the slot ids, like the web fill');

        $fill = $this->versionFill($client, $token, $version);
        self::assertIsArray($fill['rows']);
        self::assertIsArray($fill['rows'][self::ROW]);
        self::assertCount(3, $fill['rows'][self::ROW]);
        [$first, $second, $third] = $fill['rows'][self::ROW];
        self::assertIsArray($first);
        self::assertSame('TJ Kelč', $first[self::SOUPER] ?? null);
        self::assertSame([], $second, 'an untouched row');
        self::assertIsArray($third);
        self::assertSame('FK Nový Jičín', $third[self::SOUPER] ?? null);
        self::assertIsArray($third[self::CREST] ?? null);
        self::assertSame(TestDataFixture::FILE_IN_ALLOWED_ID, $third[self::CREST]['imageId'] ?? null);

        self::assertIsArray($fill['inputs']);
        self::assertArrayNotHasKey(self::SOUPER, $fill['inputs'], 'row values are not repeated in inputs');
        self::assertArrayNotHasKey($slot(self::SOUPER, 2), $fill['inputs']);
        self::assertIsArray($fill['images']);
        self::assertArrayNotHasKey($slot(self::CREST, 2), $fill['images']);
    }

    public function testALoadedFillPostsBackAsIsAndDeduplicates(): void
    {
        $client = self::createClient();
        $token = $this->token($client);
        $this->makeRows(self::VARIANT);

        $this->post($client, $token, '/api/template-variants/' . self::VARIANT . '/export', [
            'inputs' => [self::HEADLINE => 'Finále'],
            'rows' => [self::ROW => [[self::SOUPER => 'A'], [self::SOUPER => 'B', self::ROW => ['hide' => true]]]],
        ]);
        $this->assertResponseIsSuccessful();

        $fill = $this->versionFill($client, $token, $this->variantVersion(self::VARIANT));
        self::assertSame([self::ROW => [[self::SOUPER => 'A'], [self::SOUPER => 'B', self::ROW => ['hide' => true]]]], $fill['rows']);

        $this->post($client, $token, '/api/template-variants/' . self::VARIANT . '/export', ['inputs' => $fill['inputs'], 'rows' => $fill['rows']]);
        $this->assertResponseIsSuccessful();

        $versions = self::getContainer()->get(EntityManagerInterface::class)->getRepository(TemplateExportVersion::class)->findBy(['variant' => self::VARIANT]);
        self::assertCount(1, $versions, 'the same fill hashes the same');
    }

    public function testWithoutRowsEveryRowShowsItsDefaultAndTheVersionRecordsIt(): void
    {
        $client = self::createClient();
        $token = $this->token($client);
        $this->makeRows(self::VARIANT);

        $this->post($client, $token, '/api/template-variants/' . self::VARIANT . '/preview', ['inputs' => [self::SOUPER => 'Jen první']]);
        $this->assertResponseIsSuccessful();
        $renderer = $this->renderer($client);
        $hides = $renderer->hideCalls[count($renderer->hideCalls) - 1];
        self::assertSame([], $hides['rowCounts']);
        self::assertArrayNotHasKey(RepeatableRows::slotId(self::ROW, self::SOUPER, 1), $hides['effectiveHidden'], 'default 2 rows');
        self::assertArrayHasKey(RepeatableRows::slotId(self::ROW, self::SOUPER, 2), $hides['effectiveHidden']);

        $this->post($client, $token, '/api/template-variants/' . self::VARIANT . '/export', ['inputs' => [self::SOUPER => 'Jen první']]);
        $this->assertResponseIsSuccessful();
        self::assertSame([self::ROW => 2], $this->variantVersion(self::VARIANT)->fillValues->rows, 'the default count, like the web form posts it');
    }

    public function testAVariantWithoutRowsKeepsItsVersionShape(): void
    {
        $client = self::createClient();
        $token = $this->token($client);

        $this->post($client, $token, '/api/template-variants/' . self::VARIANT . '/export', ['inputs' => [TestDataFixture::SOCIAL_NETWORK_VARIANT_1_INPUT_HEADLINE_ID => 'Ahoj']]);
        $this->assertResponseIsSuccessful();

        $version = $this->variantVersion(self::VARIANT);
        self::assertArrayNotHasKey('rows', $version->fillValues->toArray(), 'old dedup hashes hold');

        $detail = $client->request('GET', '/api/export-versions/' . $version->id->toString(), [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);
        self::assertStringContainsString('"rows":{}', $detail->getContent());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, array<string, mixed>}>
     */
    public static function refusals(): iterable
    {
        $soupers = static fn (int $count): array => array_fill(0, $count, [self::SOUPER => 'X']);

        yield 'a container that is not a row' => [['rows' => [self::ROOT => [[]]]], 'not_repeatable', ['containerId' => self::ROOT]];
        yield 'an unknown row id' => [['rows' => ['no-such-row' => [[]]]], 'not_repeatable', ['containerId' => 'no-such-row']];
        yield 'past max' => [['rows' => [self::ROW => $soupers(6)]], 'too_many_rows', ['containerId' => self::ROW, 'count' => 6, 'min' => 1, 'max' => 5]];
        yield 'below min' => [['rows' => [self::ROW => []]], 'too_few_rows', ['containerId' => self::ROW, 'count' => 0, 'min' => 1, 'max' => 5]];
        yield 'a key that is no member of the row' => [['rows' => [self::ROW => [[], [self::HEADLINE => 'X']]]], 'unknown_row_input', ['containerId' => self::ROW, 'rowIndex' => 1, 'inputId' => self::HEADLINE]];
        yield 'row 1 also sent in inputs' => [['inputs' => [self::SOUPER => 'Y'], 'rows' => [self::ROW => $soupers(1)]], 'ambiguous_row_value', ['containerId' => self::ROW, 'rowIndex' => 0, 'inputId' => self::SOUPER]];
        yield 'a later row also sent by its slot id' => [['inputs' => [RepeatableRows::slotId(self::ROW, self::SOUPER, 3) => 'Y'], 'rows' => [self::ROW => $soupers(1)]], 'ambiguous_row_value', ['containerId' => self::ROW, 'rowIndex' => 3]];
        yield 'a crest also sent in images' => [['images' => [self::CREST => ['hide' => true]], 'rows' => [self::ROW => $soupers(2)]], 'ambiguous_row_value', ['containerId' => self::ROW, 'inputId' => self::CREST]];
        yield 'rows as a list' => [['rows' => [[self::SOUPER => 'X']]], 'invalid_value', []];
        yield 'rows as a string' => [['rows' => 'tři'], 'invalid_value', []];
        yield 'a row list as an object' => [['rows' => [self::ROW => [self::SOUPER => 'X']]], 'invalid_value', ['containerId' => self::ROW]];
        yield 'a row that is no object' => [['rows' => [self::ROW => ['X']]], 'invalid_value', ['containerId' => self::ROW, 'rowIndex' => 0]];
        yield 'a too long value in row 2' => [['rows' => [self::ROW => [[], [self::SOUPER => str_repeat('x', 35)]]]], 'value_too_long', ['inputId' => self::SOUPER, 'rowContainerId' => self::ROW, 'rowIndex' => 1, 'maxLength' => 34]];
        yield 'a picture outside the crest\'s folders in row 1' => [['rows' => [self::ROW => [[self::CREST => TestDataFixture::FILE_IN_ROOT_ID]]]], 'image_not_allowed', ['imageInputId' => self::CREST, 'rowContainerId' => self::ROW, 'rowIndex' => 0]];
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed> $context
     */
    #[DataProvider('refusals')]
    public function testInvalidRowsAreStructured400s(array $body, string $code, array $context): void
    {
        $client = self::createClient();
        $token = $this->token($client);
        $this->makeRows(self::VARIANT);

        foreach (['export', 'preview'] as $endpoint) {
            $answer = $this->post($client, $token, '/api/template-variants/' . self::VARIANT . '/' . $endpoint, $body)->toArray(false);
            $this->assertResponseStatusCodeSame(400);
            self::assertSame($code, $answer['code'] ?? null, $endpoint . ': ' . json_encode($answer));
            foreach ($context as $key => $value) {
                self::assertEquals($value, $answer[$key] ?? null, sprintf('%s: %s', $endpoint, $key));
            }
        }

        self::assertSame([], self::getContainer()->get(EntityManagerInterface::class)->getRepository(TemplateExportVersion::class)->findBy(['variant' => self::VARIANT]), 'a refused export records nothing');
    }

    public function testRowsThatDoNotFitAnswerContainerOverflow(): void
    {
        $client = self::createClient();
        $token = $this->token($client);
        $this->makeRows(self::VARIANT);
        $this->renderer($client)->throwContainerOverflow = new ContainerOverflow(self::ROOT, 42.0);

        $answer = $this->post($client, $token, '/api/template-variants/' . self::VARIANT . '/export', ['rows' => [self::ROW => array_fill(0, 5, [])]])->toArray(false);

        $this->assertResponseStatusCodeSame(400);
        self::assertSame('container_overflow', $answer['code'] ?? null);
        self::assertSame(self::ROOT, $answer['containerId'] ?? null, 'reported on the root the rows run out of');
    }

    /**
     * The rows body renders exactly what the hand-numbered design (rows 1–5
     * drawn by hand, the prod "Výsledky turnaje") renders for the same
     * values: the same texts, in the same flow order, the same rows visible.
     */
    public function testARowsExportEqualsTheHandNumberedExport(): void
    {
        $client = self::createClient();
        $token = $this->token($client);
        $values = [['4 - 4', 'Fotbal TJ Kelč'], ['6 - 2', 'TJ Tatran'], ['8 - 0', 'FK Nový Jičín'], ['2 - 2', 'Jablunkov'], ['5 - 1', 'Dobratice']];

        // Hand-numbered: Skóre N / Soupeř N, one container per row.
        $hand = ContainerFixtures::raw(ContainerFixtures::TURNAJ);
        ContainerFixtures::applyRaw($this->variant(self::VARIANT), $hand);
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $inputs = [];
        foreach (self::handRows($hand) as $n => [$skore, $souper]) {
            $inputs[$skore] = $values[$n][0];
            $inputs[$souper] = $values[$n][1];
        }
        $this->post($client, $token, '/api/template-variants/' . self::VARIANT . '/preview', ['inputs' => $inputs]);
        $this->assertResponseIsSuccessful();
        $handFlow = $this->renderedRootFlow($client);

        // Repeatable: one designed row × 5.
        ContainerFixtures::applyRaw($this->variant(self::VARIANT), ContainerFixtures::turnajRepeatableRaw(max: 5, default: 1));
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $this->post($client, $token, '/api/template-variants/' . self::VARIANT . '/preview', ['rows' => [self::ROW => array_map(
            static fn (array $pair): array => [self::SKORE => $pair[0], self::SOUPER => $pair[1]],
            $values,
        )]]);
        $this->assertResponseIsSuccessful();
        $rowsFlow = $this->renderedRootFlow($client);

        self::assertCount(10, $handFlow);
        self::assertSame($handFlow, $rowsFlow);
    }

    // --- group -------------------------------------------------------------

    public function testGroupRowsAreOneSharedCountForEveryDimension(): void
    {
        $client = self::createClient();
        $token = $this->token($client);
        $preset = TestDataFixture::GROUPED_PRESET_VARIANT_ID;
        $freeform = TestDataFixture::GROUPED_FREEFORM_VARIANT_ID;
        $this->makeRows($preset);
        $this->makeRows($freeform);
        $this->writeTestImage('fixtures/in-allowed.png');
        $group = TestDataFixture::TEMPLATE_GROUP_1_ID;
        $crest2 = RepeatableRows::slotId(self::ROW, self::CREST, 1);

        $body = [
            'rows' => [self::ROW => [[self::SOUPER => 'Domácí'], [self::SOUPER => 'Hosté', self::CREST => TestDataFixture::FILE_IN_ALLOWED_ID], [self::SOUPER => 'Třetí']]],
            // A dimension's placement of row 2's crest, by its slot id.
            'placements' => [$preset => [$crest2 => ['scale' => 1.5]]],
        ];

        foreach ([$preset => 1.5, $freeform => 1.0] as $variantId => $scale) {
            $this->post($client, $token, sprintf('/api/template-groups/%s/preview/%s', $group, $variantId), $body);
            $this->assertResponseIsSuccessful();
            $renderer = $this->renderer($client);
            self::assertSame([self::ROW => 3], $renderer->hideCalls[count($renderer->hideCalls) - 1]['rowCounts']);
            $call = $renderer->calls[count($renderer->calls) - 1];
            self::assertSame('Hosté', $call['texts'][RepeatableRows::slotId(self::ROW, self::SOUPER, 1)] ?? null);
            self::assertEqualsWithDelta($scale, $call['images'][$crest2]['scale'] ?? null, 1e-9);
        }

        $refused = $this->post($client, $token, sprintf('/api/template-groups/%s/preview/%s', $group, $preset), ['rows' => ['no-such-row' => [[]]]])->toArray(false);
        $this->assertResponseStatusCodeSame(400);
        self::assertSame('not_repeatable', $refused['code'] ?? null);

        $tooMany = $this->post($client, $token, sprintf('/api/template-groups/%s/export/%s', $group, $preset), ['rows' => [self::ROW => array_fill(0, 6, [])]])->toArray(false);
        $this->assertResponseStatusCodeSame(400);
        self::assertSame('too_many_rows', $tooMany['code'] ?? null);
        self::assertContains($tooMany['variantId'] ?? null, [$preset, $freeform]);

        $this->post($client, $token, sprintf('/api/template-groups/%s/export', $group), $body);
        $this->assertResponseIsSuccessful();

        $version = self::getContainer()->get(EntityManagerInterface::class)->getRepository(TemplateExportVersion::class)->findOneBy(['group' => $group]);
        self::assertInstanceOf(TemplateExportVersion::class, $version);
        self::assertSame([self::ROW => 3], $version->fillValues->rows);

        $fill = $this->versionFill($client, $token, $version);
        self::assertIsArray($fill['rows']);
        self::assertIsArray($fill['rows'][self::ROW]);
        self::assertCount(3, $fill['rows'][self::ROW]);
        self::assertIsArray($fill['rows'][self::ROW][1]);
        self::assertSame('Hosté', $fill['rows'][self::ROW][1][self::SOUPER] ?? null);
        self::assertIsArray($fill['placements']);
        self::assertIsArray($fill['placements'][$preset] ?? null);
        self::assertArrayHasKey($crest2, $fill['placements'][$preset], 'placements stay per dimension, keyed by slot id');
    }

    // --- documents ---------------------------------------------------------

    public function testDocumentRowsArePerPage(): void
    {
        $client = self::createClient();
        $token = $this->token($client);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $a = TemplateDocumentTestData::a4Template($em, 'Strana A');
        $b = TemplateDocumentTestData::a4Template($em, 'Strana B');
        $this->makeRows($a['variant']->toString());
        $this->makeRows($b['variant']->toString());
        $documentId = TemplateDocumentTestData::document($em, self::getContainer()->get(MessageBusInterface::class), [$a['template'], $b['template']]);
        $document = $em->find(TemplateDocument::class, $documentId);
        self::assertInstanceOf(TemplateDocument::class, $document);
        [$pageA, $pageB] = [$document->pages()[0]->id->toString(), $document->pages()[1]->id->toString()];

        // The document listing presents each page's variant with its rows.
        $listed = $client->request('GET', '/api/template-documents/' . $documentId->toString(), [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ])->toArray();
        self::assertStringContainsString(self::ROW, (string) json_encode($listed['pages'] ?? null));

        $this->post($client, $token, sprintf('/api/template-documents/%s/preview/%s', $documentId->toString(), $pageA), ['pages' => [
            $pageA => ['rows' => [self::ROW => [[], [], [], []]]],
            $pageB => ['rows' => [self::ROW => [[]]]],
        ]]);
        $this->assertResponseIsSuccessful();
        $renderer = $this->renderer($client);
        self::assertSame([self::ROW => 4], $renderer->hideCalls[count($renderer->hideCalls) - 1]['rowCounts'], 'the page renders its own rows');

        $refused = $this->post($client, $token, sprintf('/api/template-documents/%s/export', $documentId->toString()), ['format' => 'png', 'pages' => [
            $pageB => ['rows' => [self::ROW => array_fill(0, 6, [])]],
        ]])->toArray(false);
        $this->assertResponseStatusCodeSame(400);
        self::assertSame('too_many_rows', $refused['code'] ?? null);
        self::assertSame($pageB, $refused['pageId'] ?? null);

        $this->post($client, $token, sprintf('/api/template-documents/%s/export', $documentId->toString()), ['format' => 'png', 'pages' => [
            $pageB => ['rows' => [self::ROW => [[self::SOUPER => 'Jedna'], [self::SOUPER => 'Dvě'], [self::SOUPER => 'Tři']]]],
        ]]);
        $this->assertResponseIsSuccessful();

        $version = $em->getRepository(TemplateDocumentExportVersion::class)->findOneBy(['document' => $documentId->toString()]);
        self::assertInstanceOf(TemplateDocumentExportVersion::class, $version);

        $body = $client->request('GET', '/api/template-document-export-versions/' . $version->id->toString(), [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ])->toArray();
        self::assertIsArray($body['fill']);
        self::assertIsArray($body['fill']['pages']);
        self::assertIsArray($body['fill']['pages'][$pageB]);
        self::assertSame([self::ROW => [[self::SOUPER => 'Jedna'], [self::SOUPER => 'Dvě'], [self::SOUPER => 'Tři']]], $body['fill']['pages'][$pageB]['rows']);
        self::assertIsArray($body['fill']['pages'][$pageA]);
        self::assertSame([self::ROW => [[], []]], $body['fill']['pages'][$pageA]['rows'], 'page A shows its default two rows');
    }

    // --- helpers -----------------------------------------------------------

    /**
     * "Výsledky turnaje" with its ONE designed row repeatable (max 5,
     * default 2), the row hidable ("Zápas") and holding a crest image slot.
     */
    private function makeRows(string $variantId): void
    {
        $raw = ContainerFixtures::turnajRepeatableRaw(max: 5, default: 2);

        /** @var list<array<string, mixed>> $containers */
        $containers = $raw['canvas']['containers'];
        foreach ($containers as $i => $container) {
            if ($container['id'] === self::ROW) {
                /** @var list<string> $members */
                $members = $container['memberInputIds'];
                $containers[$i]['memberInputIds'] = [...$members, self::CREST];
                $containers[$i]['hidable'] = true;
                $containers[$i]['name'] = 'Zápas';
            }
        }
        $raw['canvas']['containers'] = $containers;

        /** @var list<array<string, mixed>> $objects */
        $objects = $raw['canvas']['objects'];
        $objects[] = [
            'type' => 'Image', 'version' => '7.3.1', 'originX' => 'left', 'originY' => 'top',
            'left' => 190, 'top' => 352, 'width' => 50, 'height' => 50, 'scaleX' => 1, 'scaleY' => 1,
            'src' => 'data:image/png;base64,' . self::PNG_1X1_BASE64, 'crossOrigin' => null,
            'imagePlaceholder' => true, 'inputId' => self::CREST,
        ];
        $raw['canvas']['objects'] = $objects;
        $raw['imageInputs'][] = [
            'inputId' => self::CREST, 'name' => 'Erb', 'description' => null,
            'allowMove' => true, 'allowResize' => true, 'allowRotate' => false, 'hidable' => true,
            'allowedDirectoryIds' => [TestDataFixture::FILE_DIRECTORY_ALLOWED_ID], 'isBackground' => false,
        ];

        ContainerFixtures::applyRaw($this->variant($variantId), $raw);
        self::getContainer()->get(EntityManagerInterface::class)->flush();
    }

    /**
     * [Skóre N, Soupeř N] of the hand-numbered design, top to bottom.
     *
     * @param array{canvas: array<string, mixed>, inputs: list<array<string, mixed>>, imageInputs: list<array<string, mixed>>} $hand
     * @return list<array{string, string}>
     */
    private static function handRows(array $hand): array
    {
        $collection = CanvasContainer::collectionFromCanvas($hand['canvas']);
        $root = CanvasContainer::findById($collection, self::ROOT);
        self::assertNotNull($root);

        $rows = [];
        foreach ($root->memberContainerIds as $rowId) {
            $row = CanvasContainer::findById($collection, $rowId);
            self::assertNotNull($row);
            self::assertCount(2, $row->memberInputIds);
            $rows[] = [$row->memberInputIds[0], $row->memberInputIds[1]];
        }
        self::assertCount(5, $rows);

        return $rows;
    }

    /**
     * The last render's VISIBLE texts of the root container's tree, in the
     * rendered inputs' (= flow) order.
     *
     * @return list<string>
     */
    private function renderedRootFlow(Client $client): array
    {
        $renderer = $this->renderer($client);
        $call = $renderer->calls[count($renderer->calls) - 1];
        $hidden = $renderer->hideCalls[count($renderer->hideCalls) - 1]['effectiveHidden'];

        $canvas = json_decode($call['canvas'], true);
        self::assertIsArray($canvas);
        $collection = CanvasContainer::collectionFromCanvas($canvas);
        $root = CanvasContainer::findById($collection, self::ROOT);
        self::assertNotNull($root);
        $members = array_flip($root->deepMemberInputIds($collection));

        $flow = [];
        foreach ($call['inputIds'] as $inputId) {
            if (!isset($members[$inputId]) || isset($hidden[$inputId])) {
                continue;
            }
            $flow[] = $call['texts'][$inputId] ?? (string) json_encode($call['richTexts'][$inputId] ?? null);
        }

        return $flow;
    }

    /**
     * @return array<string, mixed>
     */
    private function listedVariant(Client $client, string $token, string $variantId): array
    {
        foreach ($this->listing($client, $token) as $template) {
            self::assertIsArray($template['variants']);
            foreach ($template['variants'] as $row) {
                self::assertIsArray($row);
                if ($row['id'] === $variantId) {
                    /** @var array<string, mixed> $row */
                    return $row;
                }
            }
        }

        self::fail('variant not listed');
    }

    /**
     * @return array<string, mixed>
     */
    private function listedTemplate(Client $client, string $token, string $templateId): array
    {
        foreach ($this->listing($client, $token) as $template) {
            if ($template['id'] === $templateId) {
                return $template;
            }
        }

        self::fail('template not listed');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listing(Client $client, string $token): array
    {
        $body = $client->request('GET', '/api/projects/' . TestDataFixture::PROJECT_1_ID . '/templates', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ])->toArray();

        $templates = [];
        foreach ($body as $template) {
            self::assertIsArray($template);
            /** @var array<string, mixed> $template */
            $templates[] = $template;
        }

        return $templates;
    }

    /**
     * @return array<string, mixed>
     */
    private function versionFill(Client $client, string $token, TemplateExportVersion $version): array
    {
        $detail = $client->request('GET', '/api/export-versions/' . $version->id->toString(), [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ])->toArray();
        self::assertIsArray($detail['fill']);

        /** @var array<string, mixed> $fill */
        $fill = $detail['fill'];

        return $fill;
    }

    private function variantVersion(string $variantId): TemplateExportVersion
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $version = $em->getRepository(TemplateExportVersion::class)->findOneBy(['variant' => $variantId]);
        self::assertInstanceOf(TemplateExportVersion::class, $version);

        return $version;
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

    private function writeTestImage(string $path): void
    {
        $bytes = base64_decode(self::PNG_1X1_BASE64, true);
        self::assertIsString($bytes);

        self::getContainer()->get('oneup_flysystem.minio_filesystem')->write($path, $bytes);
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
