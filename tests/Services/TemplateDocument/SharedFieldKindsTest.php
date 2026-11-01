<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\TemplateDocument;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use WBoost\Web\Entity\TemplateDocument;
use WBoost\Web\Exceptions\InvalidTemplateDocumentChange;
use WBoost\Web\Message\TemplateDocument\SetTemplateDocumentFields;
use WBoost\Web\Services\TemplateDocument\DocumentApiValues;
use WBoost\Web\Services\TemplateDocument\DocumentFillPage;
use WBoost\Web\Tests\TemplateDocumentTestData;
use WBoost\Web\Value\DocumentFieldBinding;
use WBoost\Web\Value\DocumentFieldDefinition;
use WBoost\Web\Value\DocumentFieldKind;

/**
 * A shared field can be any single input: the one shared editor offers the
 * richest value EVERY binding accepts (plain / rich / list / checklist),
 * fonts + colours narrowed to the common ones, and a recolourable shape
 * shares one colour.
 *
 * @covers \WBoost\Web\Services\TemplateDocument\DocumentFillPage
 * @covers \WBoost\Web\Services\TemplateDocument\TemplateDocumentRules
 * @covers \WBoost\Web\Value\DocumentTextMode
 */
final class SharedFieldKindsTest extends KernelTestCase
{
    public function testTwoRichInputsShareTheRichEditorWithTheCommonColours(): void
    {
        $text = $this->sharedText(
            ['richText' => true, 'allowedColors' => ['#111111', '#222222']],
            ['richText' => true, 'allowedColors' => ['#222222', '#333333']],
        );

        self::assertTrue($text['richText']);
        self::assertFalse($text['lists']);
        self::assertSame('rich', $text['textMode']);
        self::assertSame(['#222222'], $text['colorOptions']);
    }

    public function testListsSurviveOnlyWhenEveryBindingHasThem(): void
    {
        self::assertTrue($this->sharedText(['richText' => true, 'lists' => true], ['richText' => true, 'lists' => true])['lists']);

        $mixed = $this->sharedText(['richText' => true, 'lists' => true], ['richText' => true]);
        self::assertTrue($mixed['richText']);
        self::assertFalse($mixed['lists']);
    }

    public function testOnePlainBindingMakesTheFieldPlain(): void
    {
        $text = $this->sharedText(['richText' => true], []);

        self::assertFalse($text['richText']);
        self::assertSame('plain', $text['textMode']);
        self::assertNull($text['runs']);
    }

    public function testChecklistsShareAChecklistWithTheCommonCapabilities(): void
    {
        $text = $this->sharedText(
            ['richText' => true, 'lists' => true, 'listCheckboxes' => true, 'checklist' => true, 'checklistAdd' => false],
            ['richText' => true, 'lists' => true, 'listCheckboxes' => true, 'checklist' => true],
        );

        self::assertTrue($text['checklist']);
        self::assertFalse($text['checklistAdd'], 'One binding lacks it, so the shared field does not offer it.');
        self::assertTrue($text['checklistRemove']);
    }

    public function testAChecklistBindsOnlyToChecklists(): void
    {
        $a = TemplateDocumentTestData::kindA4Template($this->em(), 'A', ['richText' => true, 'lists' => true, 'listCheckboxes' => true, 'checklist' => true]);
        $b = TemplateDocumentTestData::kindA4Template($this->em(), 'B', []);
        $documentId = TemplateDocumentTestData::document($this->em(), $this->bus(), [$a['template'], $b['template']]);

        try {
            $this->bind($documentId, DocumentFieldKind::Text, [$a['template'], $b['template']], TemplateDocumentTestData::SHARED_INPUT_ID);
            self::fail('A checklist bound to a plain input must be refused.');
        } catch (HandlerFailedException $failed) {
            $real = $failed->getPrevious();
            self::assertInstanceOf(InvalidTemplateDocumentChange::class, $real);
            self::assertSame('invalid_binding', $real->reason);
        }
    }

    public function testShapesShareOneColourRowWithTheCommonColours(): void
    {
        $a = TemplateDocumentTestData::kindA4Template($this->em(), 'A', [], ['#111111', '#222222']);
        $b = TemplateDocumentTestData::kindA4Template($this->em(), 'B', [], ['#222222', '#333333']);
        $documentId = TemplateDocumentTestData::document($this->em(), $this->bus(), [$a['template'], $b['template']]);
        $fieldId = $this->bind($documentId, DocumentFieldKind::Shape, [$a['template'], $b['template']], TemplateDocumentTestData::SHAPE_INPUT_ID);

        $document = $this->document($documentId);
        $fill = self::getContainer()->get(DocumentFillPage::class)->build($document);
        $rows = array_values(array_filter($fill['shape_placeholders'], static fn (array $row): bool => $row['inputId'] === $fieldId));

        self::assertCount(1, $rows);
        self::assertSame('Společné: Pole', $rows[0]['name']);
        // Both pages' designed colour (#ff0000) + the one admin colour they share.
        self::assertSame(['#ff0000', '#222222'], $rows[0]['options']);

        // The API fans the colour out into every bound page.
        foreach ($document->pages() as $page) {
            $values = DocumentApiValues::forPage($document, $page, [$fieldId => '#222222'], []);
            self::assertSame('#222222', $values['shapeColors'][TemplateDocumentTestData::SHAPE_INPUT_ID] ?? null);
        }
    }

    /**
     * @param array{richText?: bool, lists?: bool, listCheckboxes?: bool, checklist?: bool, checklistAdd?: bool, allowedColors?: null|list<string>} $first
     * @param array{richText?: bool, lists?: bool, listCheckboxes?: bool, checklist?: bool, checklistAdd?: bool, allowedColors?: null|list<string>} $second
     * @return array<string, mixed>
     */
    private function sharedText(array $first, array $second): array
    {
        $a = TemplateDocumentTestData::kindA4Template($this->em(), 'A', $first);
        $b = TemplateDocumentTestData::kindA4Template($this->em(), 'B', $second);
        $documentId = TemplateDocumentTestData::document($this->em(), $this->bus(), [$a['template'], $b['template']]);
        $fieldId = $this->bind($documentId, DocumentFieldKind::Text, [$a['template'], $b['template']], TemplateDocumentTestData::SHARED_INPUT_ID);

        $fill = self::getContainer()->get(DocumentFillPage::class)->build($this->document($documentId));
        $texts = array_values(array_filter($fill['text_placeholders'], static fn (array $ph): bool => $ph['inputId'] === $fieldId));
        self::assertCount(1, $texts, 'One shared editor.');

        return $texts[0];
    }

    /**
     * @param list<UuidInterface> $templateIds
     */
    private function bind(UuidInterface $documentId, DocumentFieldKind $kind, array $templateIds, string $inputId): string
    {
        $fieldId = Uuid::uuid7();
        $this->bus()->dispatch(new SetTemplateDocumentFields($documentId, [
            new DocumentFieldDefinition($fieldId, 'Pole', $kind, array_map(
                static fn (UuidInterface $templateId): DocumentFieldBinding => new DocumentFieldBinding($templateId->toString(), $inputId),
                $templateIds,
            )),
        ]));
        $this->em()->clear();

        return $fieldId->toString();
    }

    private function document(UuidInterface $documentId): TemplateDocument
    {
        $document = $this->em()->find(TemplateDocument::class, $documentId);
        self::assertInstanceOf(TemplateDocument::class, $document);

        return $document;
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    private function bus(): MessageBusInterface
    {
        return self::getContainer()->get(MessageBusInterface::class);
    }
}
