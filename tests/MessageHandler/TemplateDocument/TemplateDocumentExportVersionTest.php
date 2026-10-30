<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\MessageHandler\TemplateDocument;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use WBoost\Web\Entity\TemplateDocument;
use WBoost\Web\Message\TemplateDocument\RecordTemplateDocumentExportVersion;
use WBoost\Web\Message\TemplateDocument\SaveTemplateDocumentFillDraft;
use WBoost\Web\Repository\TemplateDocumentExportVersionRepository;
use WBoost\Web\Services\TemplateDocument\DocumentVersionSeeder;
use WBoost\Web\Tests\TemplateDocumentTestData;
use WBoost\Web\Value\DocumentFillKeys;
use WBoost\Web\Value\DocumentFillValues;
use WBoost\Web\Value\ExportChannel;

/**
 * Document history: identical fills dedupe, a loaded draft is promoted, and a
 * stored fill seeds back into the fill page's key space per page.
 */
final class TemplateDocumentExportVersionTest extends KernelTestCase
{
    public function testDedupeDraftPromotionAndSeedRoundTrip(): void
    {
        [$document, $keys] = $this->document();
        $raw = $this->raw($document, $keys, ['Strana A', 'Strana B']);
        $fill = DocumentFillValues::fromWebForm($document, $raw);

        $this->dispatch(new RecordTemplateDocumentExportVersion($document->id, null, ExportChannel::Web, $fill));
        $this->dispatch(new RecordTemplateDocumentExportVersion($document->id, null, ExportChannel::Web, $fill));

        $versions = $this->repository()->forDocument($document->id);
        self::assertCount(1, $versions, 'The same fill twice is ONE version.');
        self::assertSame(2, $versions[0]->exportCount);

        // A draft, then exported with it loaded → promoted in place.
        $draftId = Uuid::uuid7();
        $changed = DocumentFillValues::fromWebForm($document, $this->raw($document, $keys, ['Jiný text', 'Strana B']));
        $this->dispatch(new SaveTemplateDocumentFillDraft($draftId, $document->id, null, $changed));
        $draft = $this->repository()->find($draftId);
        self::assertNotNull($draft);
        self::assertTrue($draft->draft);

        $this->dispatch(new RecordTemplateDocumentExportVersion($document->id, null, ExportChannel::Web, $changed, $draftId->toString()));
        $promoted = $this->repository()->find($draftId);
        self::assertNotNull($promoted);
        self::assertFalse($promoted->draft);
        self::assertCount(2, $this->repository()->forDocument($document->id));

        // Seeding: each page gets ITS value back, in fill-key space.
        $seed = self::getContainer()->get(DocumentVersionSeeder::class)->seed($promoted, $this->reload($document->id));
        $pages = $document->pages();
        self::assertSame('Jiný text', $seed['texts'][$keys->keyFor($pages[0]->template->id->toString(), TemplateDocumentTestData::SHARED_INPUT_ID)]);
        self::assertSame('Strana B', $seed['texts'][$keys->keyFor($pages[1]->template->id->toString(), TemplateDocumentTestData::SHARED_INPUT_ID)]);
    }

    /**
     * @return array{TemplateDocument, DocumentFillKeys}
     */
    private function document(): array
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $a = TemplateDocumentTestData::a4Template($em, 'A');
        $b = TemplateDocumentTestData::a4Template($em, 'B');
        $documentId = TemplateDocumentTestData::document($em, self::getContainer()->get(MessageBusInterface::class), [$a['template'], $b['template']]);
        $document = $this->reload($documentId);

        return [$document, DocumentFillKeys::forDocument($document)];
    }

    /**
     * @param list<string> $texts
     * @return array<string, array<array-key, mixed>>
     */
    private function raw(TemplateDocument $document, DocumentFillKeys $keys, array $texts): array
    {
        $values = [];
        foreach ($document->pages() as $index => $page) {
            $values[$keys->keyFor($page->template->id->toString(), TemplateDocumentTestData::SHARED_INPUT_ID)] = $texts[$index];
        }

        return ['textValues' => $values];
    }

    private function reload(UuidInterface $documentId): TemplateDocument
    {
        $document = self::getContainer()->get(EntityManagerInterface::class)->find(TemplateDocument::class, $documentId);
        self::assertInstanceOf(TemplateDocument::class, $document);

        return $document;
    }

    private function dispatch(object $message): void
    {
        self::getContainer()->get(MessageBusInterface::class)->dispatch($message);
    }

    private function repository(): TemplateDocumentExportVersionRepository
    {
        return self::getContainer()->get(TemplateDocumentExportVersionRepository::class);
    }
}
