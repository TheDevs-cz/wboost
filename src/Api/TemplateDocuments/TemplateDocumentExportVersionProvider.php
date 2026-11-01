<?php

declare(strict_types=1);

namespace WBoost\Web\Api\TemplateDocuments;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use WBoost\Web\Entity\TemplateDocumentExportVersion;
use WBoost\Web\Repository\TemplateDocumentExportVersionRepository;
use WBoost\Web\Services\Security\TemplateDocumentExportVersionVoter;
use WBoost\Web\Services\TemplateDocument\DocumentExportVersionApiView;

/**
 * `GET /api/template-document-export-versions/{versionId}` — one version with
 * its fill. Also the loader + presenter the PATCH reuses.
 *
 * @implements ProviderInterface<TemplateDocumentExportVersionDetailResponse>
 */
final readonly class TemplateDocumentExportVersionProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private TemplateDocumentExportVersionRepository $versionRepository,
        private DocumentExportVersionApiView $view,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): TemplateDocumentExportVersionDetailResponse
    {
        return $this->present($this->load($uriVariables['versionId'] ?? null));
    }

    public function load(mixed $versionId): TemplateDocumentExportVersion
    {
        if (!is_string($versionId) || !Uuid::isValid($versionId)) {
            throw new BadRequestHttpException('Invalid document export version id.');
        }

        $version = $this->versionRepository->find(Uuid::fromString($versionId));

        if ($version === null) {
            throw new NotFoundHttpException();
        }

        if (!$this->security->isGranted(TemplateDocumentExportVersionVoter::MANAGE, $version)) {
            throw new AccessDeniedHttpException();
        }

        return $version;
    }

    public function present(TemplateDocumentExportVersion $version): TemplateDocumentExportVersionDetailResponse
    {
        $document = $version->document;
        $base = TemplateDocumentExportVersionsProvider::summary(
            $version,
            $this->view->summaries([$version], $document)[$version->id->toString()] ?? ['texts' => [], 'pictures' => 0, 'hidden' => 0],
        );

        return new TemplateDocumentExportVersionDetailResponse(
            id: $base->id,
            documentId: $base->documentId,
            name: $base->name,
            pinned: $base->pinned,
            pinnedAt: $base->pinnedAt,
            draft: $base->draft,
            createdAt: $base->createdAt,
            lastExportedAt: $base->lastExportedAt,
            exportCount: $base->exportCount,
            channel: $base->channel,
            summary: $base->summary,
            fill: $this->view->fill($version, $document),
        );
    }
}
