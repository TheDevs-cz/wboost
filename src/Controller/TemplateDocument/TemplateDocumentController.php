<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\TemplateDocument;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Template;
use WBoost\Web\Entity\TemplateDocument;
use WBoost\Web\Query\GetTemplates;
use WBoost\Web\Services\Security\TemplateDocumentVoter;
use WBoost\Web\Services\TemplateDocument\TemplateDocumentRules;
use WBoost\Web\Services\TemplateDocument\TemplateDocumentsFeature;

/**
 * The document page (Apple-Preview style): a sidebar of page thumbnails
 * (drag to reorder, attach / add / detach / duplicate / delete) and the
 * selected page large. `?page=<templateId>` selects; `?attach=1` opens the
 * attach dialog (right after "Nový dokument").
 */
final class TemplateDocumentController extends AbstractController
{
    public function __construct(
        readonly private GetTemplates $getTemplates,
        readonly private TemplateDocumentRules $rules,
        readonly private TemplateDocumentsFeature $feature,
    ) {
    }

    #[Route(path: '/template-document/{documentId}', name: 'template_document', methods: ['GET'])]
    #[IsGranted(TemplateDocumentVoter::VIEW, 'document')]
    public function __invoke(
        #[MapEntity(id: 'documentId')]
        TemplateDocument $document,
        Request $request,
    ): Response {
        if (!$this->feature->isEnabled()) {
            throw $this->createNotFoundException();
        }

        $pages = $document->pages();
        $selected = $pages[0] ?? null;
        $selectedId = $request->query->getString('page');

        foreach ($pages as $page) {
            if ($page->template->id->toString() === $selectedId) {
                $selected = $page;
            }
        }

        $canEdit = $this->isGranted(TemplateDocumentVoter::EDIT, $document);

        return $this->render('template_document.html.twig', [
            'project' => $document->project,
            'document' => $document,
            'pages' => $pages,
            'selected_page' => $selected,
            'can_edit' => $canEdit,
            'attachable' => $canEdit ? $this->attachable($document) : [],
            'open_attach' => $request->query->getBoolean('attach'),
        ]);
    }

    /**
     * Templates of the project that could join: not grouped, not a page of
     * any document, holding a variant of the document's size.
     *
     * @return list<array{template: Template, thumbnail: null|string}>
     */
    private function attachable(TemplateDocument $document): array
    {
        $result = [];

        foreach ($this->getTemplates->allForProject($document->project->id, excludeDocumentPages: true) as $template) {
            if ($template->group !== null) {
                continue;
            }

            $variant = $this->rules->matchingVariant($document, $template);

            if ($variant !== null) {
                $result[] = ['template' => $template, 'thumbnail' => $variant->previewImagePath ?? $variant->backgroundImage];
            }
        }

        return $result;
    }
}
