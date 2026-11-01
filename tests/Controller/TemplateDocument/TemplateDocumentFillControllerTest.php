<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\TemplateDocument;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Messenger\MessageBusInterface;
use WBoost\Web\Entity\TemplateDocument;
use WBoost\Web\Message\TemplateDocument\SetTemplateDocumentFields;
use WBoost\Web\Services\Editor\TemplateVariantImageRendererInterface;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\Fakes\FakeTemplateVariantImageRenderer;
use WBoost\Web\Tests\TemplateDocumentTestData;
use WBoost\Web\Tests\TestingLogin;
use WBoost\Web\Value\DocumentFieldBinding;
use WBoost\Web\Value\DocumentFieldDefinition;
use WBoost\Web\Value\DocumentFieldKind;

/**
 * The document fill page + its per-page preview: FILL KEYS keep colliding
 * inputIds of different pages apart, a shared field is one mirror, the
 * stored canvases are never re-keyed, and a preview renders only its own
 * page's values.
 *
 * @covers \WBoost\Web\Controller\TemplateDocument\TemplateDocumentFillController
 * @covers \WBoost\Web\Controller\TemplateDocument\TemplateDocumentFillPreviewController
 * @covers \WBoost\Web\Services\TemplateDocument\DocumentFillPage
 * @covers \WBoost\Web\Services\TemplateDocument\DocumentPageView
 */
final class TemplateDocumentFillControllerTest extends WebTestCase
{
    public function testCollidingInputIdsGetTwoDistinctMirrors(): void
    {
        $client = $this->adminClient();
        $first = TemplateDocumentTestData::a4Template($this->em(), 'Strana A');
        $second = TemplateDocumentTestData::a4Template($this->em(), 'Strana B');
        $documentId = TemplateDocumentTestData::document($this->em(), $this->bus(), [$first['template'], $second['template']]);

        $crawler = $client->request('GET', '/template-document/' . $documentId . '/fill');

        self::assertResponseIsSuccessful();
        $mirrors = $crawler->filter('input[data-text-mirror]');
        self::assertCount(2, $mirrors, 'Same inputId on two pages = two independent fields.');
        self::assertSame(
            [
                'textValues[' . $this->key($first['template']) . ']',
                'textValues[' . $this->key($second['template']) . ']',
            ],
            $mirrors->each(static fn (Crawler $node): string => (string) $node->attr('name')),
        );
        self::assertCount(0, $crawler->filter('[data-inputid="' . TemplateDocumentTestData::SHARED_INPUT_ID . '"]'), 'No real inputId leaks into the page.');

        // One surface per page, each with ITS key's box only.
        self::assertCount(2, $crawler->filter('.fill-surface'));
        self::assertCount(1, $crawler->filter('.fill-surface')->eq(0)->filter('.fill-box[data-inputid="' . $this->key($first['template']) . '"]'));
        self::assertCount(0, $crawler->filter('.fill-surface')->eq(0)->filter('.fill-box[data-inputid="' . $this->key($second['template']) . '"]'));
        self::assertCount(2, $crawler->filter('.fill-popover[data-inputid]'));

        // Lazy previews + per-page endpoints.
        self::assertSame('true', $crawler->filter('form.document-fill')->attr('data-group-fill-lazy-value'));
        $document = $this->document($documentId);
        self::assertSame(
            '/template-document/' . $documentId . '/fill-preview/' . $document->pages()[0]->id,
            $crawler->filter('img[data-group-fill-target="preview"]')->eq(0)->attr('data-preview-endpoint'),
        );
        self::assertSame('/template-document/' . $documentId . '/export', $crawler->filter('form.document-fill')->attr('action'));
        self::assertCount(1, $crawler->filter('button[name="format"][value="pdf"].btn-primary'), 'A4 = print document: the print PDF is the primary export.');
        self::assertCount(1, $crawler->filter('button[name="format"][value="pdf-screen"]'));
        self::assertCount(1, $crawler->filter('button[name="format"][value="png"]'));
    }

    public function testASharedFieldIsOneMirrorAndOnePopoverEditedFromEveryPage(): void
    {
        $client = $this->adminClient();
        $first = TemplateDocumentTestData::a4Template($this->em(), 'Strana A');
        $second = TemplateDocumentTestData::a4Template($this->em(), 'Strana B');
        $documentId = TemplateDocumentTestData::document($this->em(), $this->bus(), [$first['template'], $second['template']]);
        $fieldId = $this->sharedField($documentId, 'Klient', DocumentFieldKind::Text, [$first['template'], $second['template']], TemplateDocumentTestData::SHARED_INPUT_ID);

        $crawler = $client->request('GET', '/template-document/' . $documentId . '/fill');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('input[data-text-mirror]'));
        self::assertCount(1, $crawler->filter('input[name="textValues[' . $fieldId . ']"]'));
        self::assertCount(1, $crawler->filter('.fill-popover[data-inputid="' . $fieldId . '"]'), 'ONE popover for the shared field …');
        self::assertCount(2, $crawler->filter('.fill-box[data-inputid="' . $fieldId . '"]'), '… opened from a box on every bound page.');
        self::assertStringContainsString('Společné: Klient', $crawler->filter('.fill-popover[data-inputid="' . $fieldId . '"]')->text());
        self::assertStringContainsString('Společná pole', $crawler->filter('.fill-popovers')->text());
    }

    public function testRichAndShapeSharedFieldsRenderOneEditorEach(): void
    {
        $client = $this->adminClient();
        $first = TemplateDocumentTestData::kindA4Template($this->em(), 'Strana A', ['richText' => true]);
        $second = TemplateDocumentTestData::kindA4Template($this->em(), 'Strana B', ['richText' => true]);
        $documentId = TemplateDocumentTestData::document($this->em(), $this->bus(), [$first['template'], $second['template']]);
        $textId = Uuid::uuid7();
        $shapeId = Uuid::uuid7();
        $this->bus()->dispatch(new SetTemplateDocumentFields($documentId, [
            new DocumentFieldDefinition($textId, 'Klient', DocumentFieldKind::Text, [
                new DocumentFieldBinding($first['template']->toString(), TemplateDocumentTestData::SHARED_INPUT_ID),
                new DocumentFieldBinding($second['template']->toString(), TemplateDocumentTestData::SHARED_INPUT_ID),
            ]),
            new DocumentFieldDefinition($shapeId, 'Barva pruhu', DocumentFieldKind::Shape, [
                new DocumentFieldBinding($first['template']->toString(), TemplateDocumentTestData::SHAPE_INPUT_ID),
                new DocumentFieldBinding($second['template']->toString(), TemplateDocumentTestData::SHAPE_INPUT_ID),
            ]),
        ]));
        $this->em()->clear();

        $crawler = $client->request('GET', '/template-document/' . $documentId . '/fill');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.fill-popover[data-inputid="' . $textId . '"]'));
        self::assertCount(1, $crawler->filter('.fill-popover[data-inputid="' . $shapeId . '"]'), 'One colour row for both shapes.');
        self::assertCount(1, $crawler->filter('[name="shapeColorValues[' . $shapeId . ']"]'));
        self::assertStringContainsString('Společné: Barva pruhu', $crawler->filter('.fill-popovers')->text());
    }

    public function testRenderingTheFillPageLeavesTheStoredCanvasesByteIdentical(): void
    {
        $client = $this->adminClient();
        $first = TemplateDocumentTestData::richA4Template($this->em(), 'Bohatá A', texts: 3, images: 1);
        $second = TemplateDocumentTestData::richA4Template($this->em(), 'Bohatá B', texts: 3, images: 1);
        $documentId = TemplateDocumentTestData::document($this->em(), $this->bus(), [$first['template'], $second['template']]);
        $this->sharedField($documentId, 'Fotka', DocumentFieldKind::Image, [$first['template'], $second['template']], TemplateDocumentTestData::IMAGE_INPUT_ID);

        $before = $this->storedVariants([$first['variant'], $second['variant']]);
        $crawler = $client->request('GET', '/template-document/' . $documentId . '/fill');
        self::assertResponseIsSuccessful();

        self::assertSame($before, $this->storedVariants([$first['variant'], $second['variant']]), 'Fill keys must never reach the stored canvas / inputs.');

        // …while the page itself is fully re-keyed: the container members and
        // the image frames travel in fill-key space.
        /** @var array{containers: list<array{memberInputIds: list<string>}>} $layout */
        $layout = json_decode((string) $crawler->filter('.fill-surface')->eq(0)->attr('data-layout'), true);
        self::assertSame(
            [$this->key($first['template']), $this->key($first['template'], '22222222-0000-0000-0001-000000000001')],
            $layout['containers'][0]['memberInputIds'],
        );
        /** @var list<array{frames: array<string, mixed>}> $variants */
        $variants = json_decode((string) $crawler->filter('form.document-fill')->attr('data-group-fill-variants-value'), true);
        $fieldId = $this->document($documentId)->fields()[0]->id->toString();
        self::assertArrayHasKey($fieldId, $variants[0]['frames'], 'The shared image is placeable on every page …');
        self::assertArrayHasKey($fieldId, $variants[1]['frames']);
        self::assertCount(4, $crawler->filter('input[name^="imagePlacements[' . $first['variant'] . '][' . $fieldId . ']"]'), '… with per-page placement fields.');
        self::assertCount(1, $crawler->filter('input[name="images[' . $fieldId . '][imageId]"]'), 'ONE pick for the shared image.');
    }

    public function testPreviewRendersOnlyItsOwnPageValues(): void
    {
        $client = $this->adminClient();
        $first = TemplateDocumentTestData::a4Template($this->em(), 'Strana A');
        $second = TemplateDocumentTestData::a4Template($this->em(), 'Strana B');
        $documentId = TemplateDocumentTestData::document($this->em(), $this->bus(), [$first['template'], $second['template']]);
        $pages = $this->document($documentId)->pages();

        $client->request('POST', '/template-document/' . $documentId . '/fill-preview/' . $pages[0]->id, [
            'textValues' => [
                $this->key($first['template']) => 'Jen strana 1',
                $this->key($second['template']) => 'Jen strana 2',
            ],
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame('image/webp', $client->getResponse()->headers->get('Content-Type'));
        $calls = $this->renderer()->calls;
        self::assertCount(1, $calls);
        self::assertSame($first['variant']->toString(), $calls[0]['variantId'], 'The REAL variant renders …');
        self::assertSame([TemplateDocumentTestData::SHARED_INPUT_ID => 'Jen strana 1'], $calls[0]['texts'], '… with its real inputIds and only its own values.');
    }

    public function testPreviewFansASharedValueOutToEveryBoundPage(): void
    {
        $client = $this->adminClient();
        $first = TemplateDocumentTestData::a4Template($this->em(), 'Strana A');
        $second = TemplateDocumentTestData::a4Template($this->em(), 'Strana B');
        $documentId = TemplateDocumentTestData::document($this->em(), $this->bus(), [$first['template'], $second['template']]);
        $fieldId = $this->sharedField($documentId, 'Klient', DocumentFieldKind::Text, [$first['template'], $second['template']], TemplateDocumentTestData::SHARED_INPUT_ID);
        $pages = $this->document($documentId)->pages();

        $client->request('POST', '/template-document/' . $documentId . '/fill-preview/' . $pages[1]->id, [
            'textValues' => [$fieldId => 'ACME s.r.o.'],
        ]);

        self::assertResponseIsSuccessful();
        $calls = $this->renderer()->calls;
        self::assertCount(1, $calls);
        self::assertSame($second['variant']->toString(), $calls[0]['variantId']);
        self::assertSame([TemplateDocumentTestData::SHARED_INPUT_ID => 'ACME s.r.o.'], $calls[0]['texts']);
    }

    public function testPreviewOfAPageOutsideTheDocumentIs404(): void
    {
        $client = $this->adminClient();
        $first = TemplateDocumentTestData::a4Template($this->em(), 'Strana A');
        $foreign = TemplateDocumentTestData::a4Template($this->em(), 'Cizí');
        $documentId = TemplateDocumentTestData::document($this->em(), $this->bus(), [$first['template']]);
        $otherDocumentId = TemplateDocumentTestData::document($this->em(), $this->bus(), [$foreign['template']], 'Jiný');
        $foreignPage = $this->document($otherDocumentId)->pages()[0];

        foreach ([$foreignPage->id->toString(), $first['template']->toString(), $first['variant']->toString(), 'not-a-uuid'] as $pageId) {
            $client->request('POST', '/template-document/' . $documentId . '/fill-preview/' . $pageId, ['textValues' => []]);
            self::assertResponseStatusCodeSame(404, $pageId);
        }

        self::assertSame([], $this->renderer()->calls, 'Nothing renders for a page of another document.');
    }

    public function testAnyProjectViewerFillsButOutsidersDoNot(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);
        $first = TemplateDocumentTestData::a4Template($this->em(), 'Strana A');
        $documentId = TemplateDocumentTestData::document($this->em(), $this->bus(), [$first['template']]);

        // The project owner without the designer role fills and exports.
        $client->request('GET', '/template-document/' . $documentId . '/fill');
        self::assertResponseIsSuccessful();

        TestingLogin::logInAsUser($client, TestDataFixture::USER_2_EMAIL);
        $client->request('GET', '/template-document/' . $documentId . '/fill');
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * PHP drops form fields past `max_input_vars` SILENTLY — the export would
     * render defaults with no error. A full 30-page document of realistic
     * pages passes PHP's default 1000, so the app ships a raised limit
     * (.docker/php/wboost.ini, loaded in every environment); this pins the
     * form against it.
     */
    public function testAThirtyPageFormFitsTheShippedMaxInputVars(): void
    {
        $client = $this->adminClient();
        $templateIds = [];
        for ($i = 1; $i <= TemplateDocument::MAX_PAGES; $i++) {
            $templateIds[] = TemplateDocumentTestData::richA4Template($this->em(), 'Strana ' . $i, texts: 12, images: 2)['template'];
        }
        $documentId = TemplateDocumentTestData::document($this->em(), $this->bus(), $templateIds);

        $crawler = $client->request('GET', '/template-document/' . $documentId . '/fill');
        self::assertResponseIsSuccessful();

        // Every field the export POST carries (the form's own controls with
        // a name; buttons only send the pressed one).
        $fields = $crawler->filter('form.document-fill input[name], form.document-fill select[name], form.document-fill textarea[name]')->count() + 1;
        $configured = parse_ini_file(dirname(__DIR__, 3) . '/.docker/php/wboost.ini');
        self::assertIsArray($configured);
        self::assertIsNumeric($configured['max_input_vars'] ?? null);
        $limit = (int) $configured['max_input_vars'];

        self::assertGreaterThan(1000, $fields, 'This fixture is the case the raised limit exists for (PHP default: 1000).');
        self::assertLessThan($limit, $fields, sprintf('A 30-page form posts %d fields; max_input_vars ships as %d.', $fields, $limit));
    }

    private function key(UuidInterface $templateId, string $inputId = TemplateDocumentTestData::SHARED_INPUT_ID): string
    {
        return Uuid::uuid5($templateId->toString(), $inputId)->toString();
    }

    /**
     * @param list<UuidInterface> $templateIds
     */
    private function sharedField(UuidInterface $documentId, string $name, DocumentFieldKind $kind, array $templateIds, string $inputId): string
    {
        $fieldId = Uuid::uuid7();
        $this->bus()->dispatch(new SetTemplateDocumentFields($documentId, [
            new DocumentFieldDefinition(
                $fieldId,
                $name,
                $kind,
                array_map(static fn (UuidInterface $templateId): DocumentFieldBinding => new DocumentFieldBinding($templateId->toString(), $inputId), $templateIds),
            ),
        ]));
        $this->em()->flush();
        $this->em()->clear();

        return $fieldId->toString();
    }

    /**
     * @param list<UuidInterface> $variantIds
     *
     * @return list<array<array-key, mixed>>
     */
    private function storedVariants(array $variantIds): array
    {
        $connection = self::getContainer()->get(Connection::class);

        return array_map(
            static fn (UuidInterface $id): array => (array) $connection->fetchAssociative(
                'SELECT canvas::text AS canvas, inputs::text AS inputs, image_inputs::text AS image_inputs, preview_image_path FROM template_variant WHERE id = ?',
                [$id->toString()],
            ),
            $variantIds,
        );
    }

    private function document(UuidInterface $documentId): TemplateDocument
    {
        $this->em()->clear();
        $document = $this->em()->find(TemplateDocument::class, $documentId);
        self::assertInstanceOf(TemplateDocument::class, $document);

        return $document;
    }

    private function renderer(): FakeTemplateVariantImageRenderer
    {
        $renderer = self::getContainer()->get(TemplateVariantImageRendererInterface::class);
        /** @phpstan-ignore staticMethod.impossibleType */
        self::assertInstanceOf(FakeTemplateVariantImageRenderer::class, $renderer);

        return $renderer;
    }

    private function adminClient(): KernelBrowser
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);

        return $client;
    }

    private function bus(): MessageBusInterface
    {
        return self::getContainer()->get(MessageBusInterface::class);
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
