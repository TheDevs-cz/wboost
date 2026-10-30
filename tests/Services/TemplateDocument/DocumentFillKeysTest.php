<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\TemplateDocument;

use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use WBoost\Web\Entity\Project;
use WBoost\Web\Entity\Template;
use WBoost\Web\Entity\TemplateDocument;
use WBoost\Web\Entity\TemplateDocumentField;
use WBoost\Web\Entity\TemplateDocumentPage;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Entity\User;
use WBoost\Web\Services\TemplateDocument\DocumentApiValues;
use WBoost\Web\Value\DocumentFillKeys;
use WBoost\Web\Value\DimensionUnit;
use WBoost\Web\Value\DocumentFieldBinding;
use WBoost\Web\Value\DocumentFieldKind;
use WBoost\Web\Value\EditorImageInput;
use WBoost\Web\Value\EditorTextInput;
use WBoost\Web\Value\TemplateDimension;

/**
 * Pages that carry the SAME inputIds (duplicated templates) must never share
 * a value; only an explicit shared field joins them.
 */
final class DocumentFillKeysTest extends TestCase
{
    private const string NAME = 'aaaaaaaa-0000-0000-0000-000000000001';
    private const string TITLE = 'aaaaaaaa-0000-0000-0000-000000000002';
    private const string PHOTO = 'aaaaaaaa-0000-0000-0000-000000000003';

    public function testCollidingInputIdsGetDistinctKeysPerPage(): void
    {
        [$document, $pageA, $pageB] = $this->document();
        $keys = DocumentFillKeys::forDocument($document);

        $keyA = $keys->keyFor($pageA->template->id->toString(), self::TITLE);
        $keyB = $keys->keyFor($pageB->template->id->toString(), self::TITLE);

        self::assertNotSame($keyA, $keyB);
        self::assertTrue(Uuid::isValid($keyA), 'Fill keys stay UUID-shaped for the client.');
        self::assertSame($keyA, DocumentFillKeys::forDocument($document)->keyFor($pageA->template->id->toString(), self::TITLE), 'Deterministic.');

        $raw = ['textValues' => [$keyA => 'Strana A', $keyB => 'Strana B']];
        self::assertSame([self::TITLE => 'Strana A'], $keys->valuesForPage($pageA, $raw)['textValues']);
        self::assertSame([self::TITLE => 'Strana B'], $keys->valuesForPage($pageB, $raw)['textValues']);
    }

    public function testASharedFieldFansOutToEveryBoundInput(): void
    {
        [$document, $pageA, $pageB] = $this->document(sharedName: true);
        $keys = DocumentFillKeys::forDocument($document);
        $fieldId = $document->fields()[0]->id->toString();

        self::assertSame($fieldId, $keys->keyFor($pageA->template->id->toString(), self::NAME));
        self::assertSame($fieldId, $keys->keyFor($pageB->template->id->toString(), self::NAME));

        $raw = [
            'textValues' => [$fieldId => 'Lucie Svobodová'],
            'imagePlacements' => [
                $pageA->variant->id->toString() => [$keys->keyFor($pageA->template->id->toString(), self::PHOTO) => ['scale' => 2]],
            ],
        ];

        self::assertSame([self::NAME => 'Lucie Svobodová'], $keys->valuesForPage($pageA, $raw)['textValues']);
        self::assertSame([self::NAME => 'Lucie Svobodová'], $keys->valuesForPage($pageB, $raw)['textValues']);
        self::assertSame([$pageA->variant->id->toString() => [self::PHOTO => ['scale' => 2]]], $keys->valuesForPage($pageA, $raw)['imagePlacements']);
        self::assertSame([], $keys->valuesForPage($pageB, $raw)['imagePlacements']);
    }

    public function testApiValuesOverrideBoundInputsAndMergeSharedPictures(): void
    {
        [$document, $pageA] = $this->document(sharedName: true, sharedPhoto: true);
        $fields = $document->fields();

        $values = DocumentApiValues::forPage($document, $pageA, [
            $fields[0]->id->toString() => 'Sdílené jméno',
            $fields[1]->id->toString() => 'image-1',
        ], [
            'inputs' => [self::NAME => 'ignorováno', self::TITLE => 'Vlastní'],
            'images' => [self::PHOTO => ['scale' => 1.5]],
        ]);

        self::assertSame([self::NAME => 'Sdílené jméno', self::TITLE => 'Vlastní'], $values['inputs']);
        self::assertSame(['imageId' => 'image-1', 'scale' => 1.5], $values['images'][self::PHOTO]);
    }

    /**
     * @return array{TemplateDocument, TemplateDocumentPage, TemplateDocumentPage}
     */
    private function document(bool $sharedName = false, bool $sharedPhoto = false): array
    {
        $owner = new User(Uuid::uuid7(), 'owner@test.cz', new \DateTimeImmutable());
        $project = new Project(Uuid::uuid7(), $owner, new \DateTimeImmutable(), 'P');
        $dimension = new TemplateDimension(DimensionUnit::Mm, 210, 297);
        $document = new TemplateDocument(Uuid::uuid7(), $project, null, new \DateTimeImmutable(), 'D', $dimension, 0);

        $pages = [];
        foreach (['A', 'B'] as $position => $name) {
            $template = new Template(Uuid::uuid7(), $project, null, new \DateTimeImmutable(), $name, null, 0);
            $variant = new TemplateVariant(Uuid::uuid7(), $template, $dimension, null, new \DateTimeImmutable());
            $variant->editCanvas('{"objects":[]}', [
                new EditorTextInput(self::NAME, 'Jméno', null, false, false, null, false),
                new EditorTextInput(self::TITLE, 'Nadpis', null, false, false, null, false),
            ], null, [
                new EditorImageInput(self::PHOTO, 'Foto', null, true, true, true, false, []),
            ]);
            $page = new TemplateDocumentPage(Uuid::uuid7(), $document, $template, $variant, $position);
            $document->addPage($page);
            $pages[] = $page;
        }

        if ($sharedName) {
            $document->addField(new TemplateDocumentField(Uuid::uuid7(), $document, 'Zákazník', DocumentFieldKind::Text, 0, [
                new DocumentFieldBinding($pages[0]->template->id->toString(), self::NAME),
                new DocumentFieldBinding($pages[1]->template->id->toString(), self::NAME),
            ]));
        }

        if ($sharedPhoto) {
            $document->addField(new TemplateDocumentField(Uuid::uuid7(), $document, 'Foto', DocumentFieldKind::Image, 1, [
                new DocumentFieldBinding($pages[0]->template->id->toString(), self::PHOTO),
            ]));
        }

        return [$document, $pages[0], $pages[1]];
    }
}
