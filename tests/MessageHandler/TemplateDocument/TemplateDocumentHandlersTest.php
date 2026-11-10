<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\MessageHandler\TemplateDocument;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use WBoost\Web\Entity\Project;
use WBoost\Web\Entity\Template;
use WBoost\Web\Entity\TemplateDocument;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Exceptions\InvalidTemplateDocumentChange;
use WBoost\Web\Message\Template\DeleteTemplateVariant;
use WBoost\Web\Message\TemplateDocument\AddTemplateDocumentPage;
use WBoost\Web\Message\TemplateDocument\CreateTemplateDocument;
use WBoost\Web\Message\TemplateDocument\DeleteTemplateDocument;
use WBoost\Web\Message\TemplateDocument\EditTemplateDocumentPrintSettings;
use WBoost\Web\Message\TemplateDocument\SetTemplateDocumentFields;
use WBoost\Web\Message\TemplateDocument\SetTemplateDocumentPages;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Value\DimensionUnit;
use WBoost\Web\Value\DocumentFieldBinding;
use WBoost\Web\Value\DocumentFieldDefinition;
use WBoost\Web\Value\DocumentFieldKind;
use WBoost\Web\Value\EditorTextInput;
use WBoost\Web\Value\TemplateDimension;

/**
 * Every write of a multi-page document goes through these handlers (web, API
 * and MCP alike), so the invariants are pinned here.
 */
final class TemplateDocumentHandlersTest extends KernelTestCase
{
    private const string INPUT_ID = '11111111-0000-0000-0000-000000000001';
    private const string LOCKED_INPUT_ID = '11111111-0000-0000-0000-000000000002';

    public function testCreateAttachesPagesInOrderAndAppliesThePrintSetup(): void
    {
        $first = $this->a4Template('Strana A');
        $second = $this->a4Template('Strana B');
        $documentId = $this->createDocument([$second, $first], bleedMm: 3.0, cropMarks: true);

        $document = $this->document($documentId);
        $pages = $document->pages();

        self::assertCount(2, $pages);
        self::assertTrue($pages[0]->template->id->equals($second));
        self::assertTrue($pages[1]->template->id->equals($first));
        self::assertSame(3.0, $pages[0]->variant->dimension->bleedMm, 'An attached print page takes the document bleed.');
        self::assertTrue($pages[0]->variant->dimension->cropMarks);
    }

    public function testAGroupedTemplateCannotBeAPage(): void
    {
        $this->expectInvalid('template_grouped', fn () => $this->createDocument([Uuid::fromString(TestDataFixture::GROUPED_TEMPLATE_ID)], unit: DimensionUnit::Mm, width: 210, height: 297));
    }

    public function testATemplateOfAnotherProjectIsRefused(): void
    {
        $this->expectInvalid('template_not_found', fn () => $this->createDocument([Uuid::fromString(TestDataFixture::CUSTOM_TEMPLATE_2_ID)], unit: DimensionUnit::Px, width: 800, height: 600));
    }

    public function testATemplateWithoutAVariantOfTheDocumentSizeIsRefused(): void
    {
        $a4 = $this->a4Template('A4');

        $this->expectInvalid('no_matching_variant', fn () => $this->createDocument([$a4], unit: DimensionUnit::Mm, width: 148, height: 210));
    }

    public function testATemplateIsAPageOfOneDocumentOnly(): void
    {
        $page = $this->a4Template('Sdílená');
        $this->createDocument([$page]);

        $this->expectInvalid('template_in_other_document', fn () => $this->createDocument([$page]));
    }

    public function testTheSameTemplateTwiceIsRefused(): void
    {
        $page = $this->a4Template('Dvakrát');

        $this->expectInvalid('duplicate_page', fn () => $this->createDocument([$page, $page]));
    }

    public function testReorderingDetachingAndReattachingFlushesCleanly(): void
    {
        $a = $this->a4Template('A');
        $b = $this->a4Template('B');
        $c = $this->a4Template('C');
        $documentId = $this->createDocument([$a, $b, $c]);

        // Reorder + detach in one step (diffed — a delete-all + re-insert
        // would violate UNIQUE(template_id): INSERTs run before DELETEs).
        $this->dispatch(new SetTemplateDocumentPages($documentId, [$c, $a]));
        self::assertSame(['C', 'A'], $this->pageNames($documentId));

        // Re-attaching the detached one works too.
        $this->dispatch(new SetTemplateDocumentPages($documentId, [$b, $c, $a]));
        self::assertSame(['B', 'C', 'A'], $this->pageNames($documentId));
    }

    public function testAddPageCreatesABlankTemplateOfTheDocumentSizeAtThePosition(): void
    {
        $a = $this->a4Template('A');
        $b = $this->a4Template('B');
        $documentId = $this->createDocument([$a, $b], bleedMm: 2.0);

        $templateId = Uuid::uuid7();
        $variantId = Uuid::uuid7();
        $this->dispatch(new AddTemplateDocumentPage($documentId, Uuid::uuid7(), $templateId, $variantId, 'Nová', position: 1));

        self::assertSame(['A', 'Nová', 'B'], $this->pageNames($documentId));

        $variant = $this->em()->find(TemplateVariant::class, $variantId);
        self::assertInstanceOf(TemplateVariant::class, $variant);
        self::assertTrue($variant->dimension->hasSameSizeAs(new TemplateDimension(DimensionUnit::Mm, 210, 297)));
        self::assertSame(2.0, $variant->dimension->bleedMm);
    }

    public function testSharedFieldsValidateAndDetachDropsBindings(): void
    {
        $a = $this->a4Template('A');
        $b = $this->a4Template('B');
        $documentId = $this->createDocument([$a, $b]);
        $fieldId = Uuid::uuid7();

        // Two pages, one binding each — fine (the inputIds collide on purpose:
        // duplicated templates keep the canvas verbatim).
        $this->dispatch(new SetTemplateDocumentFields($documentId, [
            new DocumentFieldDefinition($fieldId, 'Zákazník', DocumentFieldKind::Text, [
                new DocumentFieldBinding($a->toString(), self::INPUT_ID),
                new DocumentFieldBinding($b->toString(), self::INPUT_ID),
            ]),
        ]));
        self::assertCount(2, $this->document($documentId)->fields()[0]->bindings());

        // A locked input is not bindable.
        $this->expectInvalid('invalid_binding', fn () => $this->dispatch(new SetTemplateDocumentFields($documentId, [
            new DocumentFieldDefinition($fieldId, 'Zákazník', DocumentFieldKind::Text, [new DocumentFieldBinding($a->toString(), self::LOCKED_INPUT_ID)]),
        ])));

        // Two bindings on ONE page are refused.
        $this->expectInvalid('invalid_binding', fn () => $this->dispatch(new SetTemplateDocumentFields($documentId, [
            new DocumentFieldDefinition($fieldId, 'Zákazník', DocumentFieldKind::Text, [
                new DocumentFieldBinding($a->toString(), self::INPUT_ID),
                new DocumentFieldBinding($a->toString(), self::INPUT_ID),
            ]),
        ])));

        $this->dispatch(new SetTemplateDocumentPages($documentId, [$a]));

        $bindings = $this->document($documentId)->fields()[0]->bindings();
        self::assertCount(1, $bindings, 'Detaching a page drops its bindings.');
        self::assertSame($a->toString(), $bindings[0]->templateId);

        $this->dispatch(new SetTemplateDocumentFields($documentId, []));
        self::assertSame([], $this->document($documentId)->fields());
    }

    public function testDissolvingKeepsTheTemplatesDeletingWithPagesRemovesThem(): void
    {
        $kept = $this->a4Template('Zůstane');
        $dissolved = $this->createDocument([$kept]);
        $this->dispatch(new DeleteTemplateDocument($dissolved, deletePages: false));
        self::assertNull($this->em()->find(TemplateDocument::class, $dissolved));
        self::assertNotNull($this->em()->find(Template::class, $kept));

        $gone = $this->a4Template('Zmizí');
        $deleted = $this->createDocument([$gone]);
        $this->dispatch(new DeleteTemplateDocument($deleted, deletePages: true));
        self::assertNull($this->em()->find(TemplateDocument::class, $deleted));
        self::assertNull($this->em()->find(Template::class, $gone));
    }

    public function testThePageVariantCannotBeDeleted(): void
    {
        $page = $this->a4Template('Stránka');
        $this->createDocument([$page]);
        $template = $this->em()->find(Template::class, $page);
        self::assertInstanceOf(Template::class, $template);

        $this->expectInvalid('variant_is_page', fn () => $this->dispatch(new DeleteTemplateVariant($template->variants()[0]->id)));
    }

    public function testPrintSettingsPropagateToEveryPage(): void
    {
        $a = $this->a4Template('A');
        $documentId = $this->createDocument([$a]);

        $this->dispatch(new EditTemplateDocumentPrintSettings($documentId, 5.0, true));

        $document = $this->document($documentId);
        self::assertSame(5.0, $document->dimension->bleedMm);
        self::assertSame(5.0, $document->pages()[0]->variant->dimension->bleedMm);
        self::assertTrue($document->pages()[0]->variant->dimension->cropMarks);
    }

    /**
     * @param list<UuidInterface> $templateIds
     */
    private function createDocument(
        array $templateIds,
        DimensionUnit $unit = DimensionUnit::Mm,
        float $width = 210,
        float $height = 297,
        float $bleedMm = 0.0,
        bool $cropMarks = false,
    ): UuidInterface {
        $documentId = Uuid::uuid7();
        $this->dispatch(new CreateTemplateDocument(
            $documentId,
            Uuid::fromString(TestDataFixture::PROJECT_1_ID),
            'Nabídka',
            null,
            new TemplateDimension($unit, $width, $height, null, $bleedMm, $cropMarks),
            $templateIds,
        ));

        return $documentId;
    }

    private function a4Template(string $name): UuidInterface
    {
        $project = $this->em()->find(Project::class, Uuid::fromString(TestDataFixture::PROJECT_1_ID));
        self::assertInstanceOf(Project::class, $project);

        $template = new Template(Uuid::uuid7(), $project, null, new \DateTimeImmutable(), $name, null, 0);
        $variant = new TemplateVariant(Uuid::uuid7(), $template, new TemplateDimension(DimensionUnit::Mm, 210, 297), null, new \DateTimeImmutable());
        $variant->editCanvas('{"version":"5.2.4","objects":[{"type":"Textbox"},{"type":"Textbox"}]}', [
            new EditorTextInput(self::INPUT_ID, 'Jméno', null, false, false, null, false),
            new EditorTextInput(self::LOCKED_INPUT_ID, null, null, true, false, null, false),
        ], null);

        $this->em()->persist($template);
        $this->em()->persist($variant);
        $this->em()->flush();
        $this->em()->clear();

        return $template->id;
    }

    private function dispatch(object $message): void
    {
        self::getContainer()->get(MessageBusInterface::class)->dispatch($message);
        $this->em()->clear();
    }

    private function document(UuidInterface $documentId): TemplateDocument
    {
        $document = $this->em()->find(TemplateDocument::class, $documentId);
        self::assertInstanceOf(TemplateDocument::class, $document);

        return $document;
    }

    /**
     * @return list<string>
     */
    private function pageNames(UuidInterface $documentId): array
    {
        return array_map(static fn ($page): string => $page->template->name, $this->document($documentId)->pages());
    }

    private function expectInvalid(string $reason, callable $action): void
    {
        try {
            $action();
        } catch (HandlerFailedException $failed) {
            $real = $failed->getPrevious();
            self::assertInstanceOf(InvalidTemplateDocumentChange::class, $real);
            self::assertSame($reason, $real->reason);
            $this->em()->clear();

            return;
        }

        self::fail(sprintf('Expected the change to be refused with "%s".', $reason));
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
