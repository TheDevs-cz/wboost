<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\TemplateDocument;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\TemplateDocument;
use WBoost\Web\Entity\TemplateDocumentExportVersion;
use WBoost\Web\Query\GetExportVersions;
use WBoost\Web\Services\Security\TemplateDocumentVoter;
use WBoost\Web\Services\TemplateDocument\DocumentExportHistoryView;
use WBoost\Web\Services\TemplateDocument\SummarizeDocumentExportVersion;

/**
 * The full export history of a multi-page document — the variant / group
 * history page (`template_export_history.html.twig`) over the document's own
 * versions: every one, pinned first, inline rename + pin, "Načíst" into the
 * document fill page.
 */
final class TemplateDocumentExportHistoryController extends AbstractController
{
    public function __construct(
        readonly private GetExportVersions $getExportVersions,
        readonly private SummarizeDocumentExportVersion $summarize,
    ) {
    }

    #[Route(path: '/template-document/{documentId}/export-history', name: 'template_document_export_history', methods: ['GET'])]
    #[IsGranted(TemplateDocumentVoter::VIEW, 'document')]
    public function __invoke(
        #[MapEntity(id: 'documentId')]
        TemplateDocument $document,
    ): Response {
        $history = $this->getExportVersions->forDocument($document->id);
        $pages = count($document->pages());

        return $this->render('template_export_history.html.twig', [
            'project' => $document->project,
            'template' => null,
            'variant' => null,
            'group' => null,
            'document' => $document,
            'subject_label' => sprintf('Dokument %s (%d %s, %s)', $document->name, $pages, $pages === 1 ? 'strana' : ($pages >= 2 && $pages <= 4 ? 'strany' : 'stran'), $document->dimension->label()),
            'history' => $history,
            'history_cap' => TemplateDocumentExportVersion::MAX_VERSIONS,
            'summaries' => $this->summarize->forVersions($history->all(), $document),
            'load_route_name' => 'template_document_fill',
            'load_route_params' => ['documentId' => $document->id->toString()],
            'fill_url' => $this->generateUrl('template_document_fill', ['documentId' => $document->id]),
            'pin_route' => DocumentExportHistoryView::PIN_ROUTE,
            'rename_route' => DocumentExportHistoryView::RENAME_ROUTE,
        ]);
    }
}
