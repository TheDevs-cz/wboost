<?php

declare(strict_types=1);

namespace WBoost\Web\Api\TemplateDocuments;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use WBoost\Web\Api\ExportVersions\ExportVersionSummaryResponse;
use WBoost\Web\Entity\TemplateDocumentExportVersion;
use WBoost\Web\Query\GetExportVersions;
use WBoost\Web\Services\Template\ApiSubjectLoader;
use WBoost\Web\Services\TemplateDocument\DocumentExportVersionApiView;

/**
 * `GET /api/template-documents/{documentId}/export-versions` — the document's
 * history, pinned first. Anyone who may fill the document may read it.
 *
 * @implements ProviderInterface<TemplateDocumentExportVersionResponse>
 */
final readonly class TemplateDocumentExportVersionsProvider implements ProviderInterface
{
    public function __construct(
        private ApiSubjectLoader $subjectLoader,
        private GetExportVersions $getExportVersions,
        private DocumentExportVersionApiView $view,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     * @return list<TemplateDocumentExportVersionResponse>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $document = $this->subjectLoader->document($uriVariables['documentId'] ?? null);
        $versions = $this->getExportVersions->forDocument($document->id)->all();
        $summaries = $this->view->summaries($versions, $document);

        return array_map(
            static fn (TemplateDocumentExportVersion $version): TemplateDocumentExportVersionResponse => self::summary(
                $version,
                $summaries[$version->id->toString()] ?? ['texts' => [], 'pictures' => 0, 'hidden' => 0],
            ),
            $versions,
        );
    }

    /**
     * @param array{texts: list<array{label: string, value: string}>, pictures: int, hidden: int} $summary
     */
    public static function summary(TemplateDocumentExportVersion $version, array $summary): TemplateDocumentExportVersionResponse
    {
        return new TemplateDocumentExportVersionResponse(
            id: $version->id->toString(),
            documentId: $version->document->id->toString(),
            name: $version->name,
            pinned: $version->isPinned(),
            pinnedAt: $version->pinnedAt,
            draft: $version->draft,
            createdAt: $version->createdAt,
            lastExportedAt: $version->lastExportedAt,
            exportCount: $version->exportCount,
            channel: $version->channel->value,
            summary: new ExportVersionSummaryResponse($summary['texts'], $summary['pictures'], $summary['hidden']),
        );
    }
}
