<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\TemplateDocument;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use WBoost\Web\Entity\TemplateDocument;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Services\TemplateDocument\DocumentPageView;
use WBoost\Web\Tests\TemplateDocumentTestData;
use WBoost\Web\Value\DocumentFillKeys;

/**
 * @covers \WBoost\Web\Services\TemplateDocument\DocumentPageView
 */
final class DocumentPageViewTest extends KernelTestCase
{
    public function testEveryInputIdLocationIsRekeyedOnAnUnmanagedClone(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $page = TemplateDocumentTestData::richA4Template($em, 'Bohatá', texts: 3, images: 1);
        $documentId = TemplateDocumentTestData::document($em, self::getContainer()->get(MessageBusInterface::class), [$page['template']]);

        $document = $em->find(TemplateDocument::class, $documentId);
        self::assertInstanceOf(TemplateDocument::class, $document);
        $variant = $document->pages()[0]->variant;
        $storedCanvas = $variant->canvas;
        $storedInputs = $variant->inputs;

        $templateId = $page['template']->toString();
        $keys = DocumentFillKeys::forDocument($document);
        $key = static fn (string $inputId): string => Uuid::uuid5($templateId, $inputId)->toString();

        $clone = DocumentPageView::rekeyed($variant, $keys, $templateId);

        self::assertNotSame($variant, $clone);
        self::assertFalse($em->contains($clone), 'The clone never enters the UnitOfWork.');

        $textIds = [TemplateDocumentTestData::SHARED_INPUT_ID, '22222222-0000-0000-0001-000000000001', '22222222-0000-0000-0001-000000000002'];
        self::assertSame(array_map($key, $textIds), array_map(static fn ($input): string => $input->inputId, $clone->inputs), 'inputs[] re-keyed, ORDER kept (positional binding).');
        self::assertSame('Jméno', $clone->inputs[0]->name, 'Every other property survives the re-key.');
        self::assertSame(60, $clone->inputs[0]->maxLength);
        self::assertTrue($clone->inputs[0]->hidable);
        self::assertSame([$key(TemplateDocumentTestData::IMAGE_INPUT_ID)], array_map(static fn ($input): string => $input->inputId, $clone->imageInputs));
        self::assertTrue($clone->imageInputs[0]->allowMove);

        /** @var array{objects: list<array{inputId: string}>, containers: list<array{memberInputIds: list<string>}>} $canvas */
        $canvas = json_decode($clone->canvas, true);
        self::assertSame(
            [...array_map($key, $textIds), $key(TemplateDocumentTestData::IMAGE_INPUT_ID)],
            array_map(static fn (array $object): string => $object['inputId'], $canvas['objects']),
        );
        self::assertSame(array_map($key, array_slice($textIds, 0, 2)), $canvas['containers'][0]['memberInputIds']);

        // The managed original is untouched — even across a flush.
        self::assertSame($storedCanvas, $variant->canvas);
        self::assertEquals($storedInputs, $variant->inputs);
        $em->flush();
        $em->clear();
        $reloaded = $em->find(TemplateVariant::class, $page['variant']);
        self::assertInstanceOf(TemplateVariant::class, $reloaded);
        self::assertStringNotContainsString($key(TemplateDocumentTestData::SHARED_INPUT_ID), $reloaded->canvas);
        self::assertSame(TemplateDocumentTestData::SHARED_INPUT_ID, $reloaded->inputs[0]->inputId);
    }
}
