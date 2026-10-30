<?php

declare(strict_types=1);

namespace WBoost\Web\Api\TemplateDocuments;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use WBoost\Web\Api\Templates\TemplatesProvider;
use WBoost\Web\Entity\TemplateDocument;
use WBoost\Web\Exceptions\ProjectNotFound;
use WBoost\Web\Repository\ProjectRepository;
use WBoost\Web\Services\Security\ProjectVoter;
use WBoost\Web\Services\Template\ApiSubjectLoader;
use WBoost\Web\Services\TemplateDocument\TemplateDocumentRules;
use WBoost\Web\Value\DocumentFieldKind;

/**
 * `GET /api/projects/{projectId}/template-documents` and
 * `GET /api/template-documents/{documentId}`.
 *
 * @implements ProviderInterface<TemplateDocumentResponse>
 */
final readonly class TemplateDocumentsProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private EntityManagerInterface $entityManager,
        private ProjectRepository $projectRepository,
        private ApiSubjectLoader $subjectLoader,
        private TemplatesProvider $templatesProvider,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     * @return TemplateDocumentResponse|list<TemplateDocumentResponse>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): TemplateDocumentResponse|array
    {
        if (!$operation instanceof CollectionOperationInterface) {
            return $this->present($this->subjectLoader->document($uriVariables['documentId'] ?? null));
        }


        $projectId = $uriVariables['projectId'] ?? null;

        if (!is_string($projectId) || !Uuid::isValid($projectId)) {
            throw new BadRequestHttpException('Invalid project id.');
        }

        try {
            $project = $this->projectRepository->get(Uuid::fromString($projectId));
        } catch (ProjectNotFound) {
            throw new NotFoundHttpException();
        }

        if (!$this->security->isGranted(ProjectVoter::VIEW, $project)) {
            throw new NotFoundHttpException();
        }

        /** @var list<TemplateDocument> $documents */
        $documents = $this->entityManager->createQueryBuilder()
            ->select('d')
            ->from(TemplateDocument::class, 'd')
            ->where('d.project = :project')
            ->setParameter('project', $project->id->toString())
            ->orderBy('d.position', 'ASC')
            ->addOrderBy('d.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        return array_map($this->present(...), $documents);
    }

    public function present(TemplateDocument $document): TemplateDocumentResponse
    {
        $pages = $document->pages();
        $pageByTemplate = [];

        foreach ($pages as $page) {
            $pageByTemplate[$page->template->id->toString()] = $page;
        }

        $fields = [];
        foreach ($document->fields() as $field) {
            $bindings = [];
            $maxLength = null;

            foreach ($field->bindings() as $binding) {
                $page = $pageByTemplate[$binding->templateId] ?? null;

                if ($page === null) {
                    continue;
                }

                $valid = TemplateDocumentRules::isBindable($page->variant, $binding->inputId, $field->kind);
                $bindings[] = new TemplateDocumentFieldBindingResponse($page->id->toString(), $binding->templateId, $binding->inputId, $valid);

                if ($valid && $field->kind === DocumentFieldKind::Text) {
                    foreach ($page->variant->inputs as $input) {
                        if ($input->inputId === $binding->inputId && $input->maxLength !== null) {
                            $maxLength = $maxLength === null ? $input->maxLength : min($maxLength, $input->maxLength);
                        }
                    }
                }
            }

            $fields[] = new TemplateDocumentFieldResponse($field->id->toString(), $field->name, $field->kind->value, $bindings, $maxLength);
        }

        $dimension = $document->dimension;

        return new TemplateDocumentResponse(
            id: $document->id->toString(),
            projectId: $document->project->id->toString(),
            name: $document->name,
            position: $document->position,
            categoryId: $document->category?->id->toString(),
            categoryName: $document->category?->name,
            createdAt: $document->createdAt,
            dimension: $dimension->label(),
            preset: $dimension->preset?->value,
            unit: $dimension->unit->value,
            unitWidth: $dimension->unitWidth,
            unitHeight: $dimension->unitHeight,
            width: $dimension->width(),
            height: $dimension->height(),
            printFormat: $dimension->isPrintFormat(),
            bleedMm: $dimension->bleedMm,
            cropMarks: $dimension->cropMarks,
            pages: array_map(
                fn ($page): TemplateDocumentPageResponse => new TemplateDocumentPageResponse(
                    $page->id->toString(),
                    $page->position,
                    $page->template->id->toString(),
                    $page->template->name,
                    $this->templatesProvider->buildVariant($page->variant),
                ),
                $pages,
            ),
            fields: $fields,
        );
    }
}
